<?php
/** HTTP API interception and response tracking. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP 5.0 does not support identifier placeholders. Table names use the trusted WP prefix and fixed constants; SQL fragments are internal or allowlisted, and values use prepare().
class ERM_Request_Logger {
	private static $pending = array();

	public static function init() {
		add_filter( 'http_request_args', array( self::class, 'track_request_args' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', array( self::class, 'intercept_request' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( self::class, 'capture_response' ), 10, 5 );
		add_action( 'requests-requests.before_redirect', array( self::class, 'guard_redirect' ), 10, 5 );
		add_action( 'wp_loaded', array( self::class, 'setup_cleanup' ) );
		add_action( 'erm_pro_daily_cleanup', array( self::class, 'cleanup' ) );
	}


	/** Enforce host policy before the Requests transport follows a redirect. */
	public static function guard_redirect( $location, $headers, $data, $options, $response ) {
		$host = self::external_host( $location );
		if ( ! $host ) {
			return;
		}
		$policy        = ERM_Database::get_host_policy( $host );
		$blocked       = (bool) apply_filters( 'erm_pro_is_blocked', ! empty( $policy->is_blocked ), $host, $location );
		$previous_host = self::normalize_host( wp_parse_url( $response->url, PHP_URL_HOST ) );
		$limited       = ! $blocked && $previous_host !== $host && self::check_rate_limit( $host, $policy );
		if ( $blocked || $limited ) {
			$message = $blocked ? __( 'Redirect to blocked external host prevented.', 'erm-pro' ) :
				__( 'Redirect request rate limit exceeded.', 'erm-pro' );
			// Throw the transport exception WordPress converts to WP_Error.
			if ( class_exists( '\WpOrg\Requests\Exception' ) ) {
				throw new \WpOrg\Requests\Exception( $message, $blocked ? 'erm_blocked' : 'erm_rate_limited' );
			}
			throw new \Requests_Exception( $message, $blocked ? 'erm_blocked' : 'erm_rate_limited' );
		}
	}
	public static function track_request_args( $args, $url ) {
		// The ID travels with parsed_args, so nested requests cannot steal timing.
		$args['_erm_request_id'] = wp_generate_uuid4();
		return $args;
	}

	public static function normalize_host( $host ) {
		return strtolower( rtrim( (string) $host, '.' ) );
	}

	private static function external_host( $url ) {
		$host  = self::normalize_host( wp_parse_url( $url, PHP_URL_HOST ) );
		$local = array(
			self::normalize_host( wp_parse_url( home_url(), PHP_URL_HOST ) ),
			self::normalize_host( wp_parse_url( site_url(), PHP_URL_HOST ) ),
			'localhost',
			'127.0.0.1',
			'::1',
			'[::1]',
		);
		return $host && ! in_array( $host, $local, true ) ? $host : false;
	}

	public static function intercept_request( $preempt, $args, $url ) {
		if ( $preempt !== false ) {
			return $preempt;
		}
		$host = self::external_host( $url );
		if ( ! $host ) {
			return $preempt;
		}
		$method = strtoupper( $args['method'] ?? 'GET' );
		$source = self::get_request_source();
		$policy = ERM_Database::get_host_policy( $host );
		$id     = self::log_request( $host, $url, $method, self::calculate_request_size( $args ), $source, $policy, $args );
		$token  = $args['_erm_request_id'] ?? '';
		if ( $id && $token && get_option( 'erm_pro_track_response', true ) ) {
			global $wpdb;
			self::$pending[ $token ] = array(
				'id'     => $id,
				'start'  => microtime( true ),
				'prefix' => $wpdb->prefix,
			);
		}

		$blocked = (bool) apply_filters( 'erm_pro_is_blocked', ! empty( $policy->is_blocked ), $host, $url );
		if ( $blocked ) {
			$error = new WP_Error(
				'erm_blocked',
				__( 'External request blocked by External Request Manager.', 'erm-pro' ),
				array( 'status' => 403 )
			);
		} elseif ( self::check_rate_limit( $host, $policy ) ) {
			$error = new WP_Error(
				'erm_rate_limited',
				__( 'Request rate limit exceeded. Try again after the configured interval.', 'erm-pro' ),
				array( 'status' => 429 )
			);
		} else {
			return $preempt;
		}
		// Short-circuited requests do not fire http_api_debug in WordPress.
		self::capture_response( $error, 'response', '', $args, $url );
		return $error;
	}

	public static function capture_response( $response, $context, $class, $args, $url ) {
		if ( $context !== 'response' || ! is_array( $args ) ) {
			return;
		}
		$token = $args['_erm_request_id'] ?? '';
		if ( ! $token || ! isset( self::$pending[ $token ] ) ) {
			return;
		}
		$request = self::$pending[ $token ];
		unset( self::$pending[ $token ] );
		if ( ! get_option( 'erm_pro_track_response', true ) ) {
			return;
		}
		$code = null;
		$body = '';
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			$code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : null;
			$body = $response->get_error_message();
		} elseif ( is_array( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			$code = $code ? (int) $code : null;
			$body = wp_remote_retrieve_body( $response );
		}
		$max  = max( 0, min( 1048576, (int) get_option( 'erm_pro_max_response_body_length', 0 ) ) );
		$body = $max ? self::truncate_body( (string) $body, $max ) : null;
		global $wpdb;
		$table = $request['prefix'] . ERM_PRO_TABLE_REQUESTS;
		$wpdb->update(
			$table,
			array(
				'response_code' => $code,
				'response_time' => max( 0, microtime( true ) - $request['start'] ),
				'response_body' => $body,
			),
			array(
				'id'                 => $request['id'],
				'last_request_token' => $token,
			),
			array( '%d', '%f', '%s' ),
			array( '%d', '%s' )
		);
	}

	public static function truncate_body( $body, $max ) {
		$body = wp_check_invalid_utf8( $body, true );
		if ( strlen( $body ) <= $max ) {
			return $body;
		}
		// The marker also counts toward the byte limit.
		$marker = $max >= 4 ? "\n..." : '';
		$length = max( 0, $max - strlen( $marker ) );
		$body   = function_exists( 'mb_strcut' ) ? mb_strcut( $body, 0, $length, 'UTF-8' ) : substr( $body, 0, $length );
		return wp_check_invalid_utf8( $body, true ) . $marker;
	}

	public static function is_blocked( $host ) {
		$policy = ERM_Database::get_host_policy( self::normalize_host( $host ) );
		return ! empty( $policy->is_blocked );
	}

	public static function check_rate_limit( $host, $policy = null ) {
		$host     = self::normalize_host( $host );
		$policy   = $policy ?: ERM_Database::get_host_policy( $host );
		$interval = isset( $policy->rate_limit_interval ) ? (int) $policy->rate_limit_interval : 0;
		if ( $interval <= 0 ) {
			return false;
		}
		$calls = max( 1, (int) ( $policy->rate_limit_calls ?? 1 ) );
		global $wpdb;
		$lock = 'erm_rate_' . md5( $wpdb->prefix . $host );
		// Serialize the transient read/modify/write across PHP workers.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) !== 1 ) {
			return true;
		}
		try {
			$key = 'erm_rate_limit_' . md5( $host );
			// Other PHP workers may have updated these options since this process cached them.
			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_delete( '_transient_' . $key, 'options' );
				wp_cache_delete( '_transient_timeout_' . $key, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
			$state = get_transient( $key );
			$now   = microtime( true );
			if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) ||
				$now - $state['start'] >= $interval || $state['start'] > $now ) {
				$state = array(
					'start' => $now,
					'count' => 0,
				);
			}
			if ( $state['count'] >= $calls ) {
				return true;
			}
			++$state['count'];
			return ! set_transient( $key, $state, max( 1, (int) ceil( $interval - ( $now - $state['start'] ) ) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function log_request( $host, $url, $method = 'GET', $request_size = 0, $source = array(), $policy = null, $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		if ( get_option( 'erm_pro_db_version', '' ) !== ERM_PRO_DB_VERSION ) {
			return false;
		}
		$token = $args['_erm_request_id'] ?? wp_generate_uuid4();
		$host  = self::normalize_host( $host );
		$lock  = 'erm_log_' . md5( $wpdb->prefix . $host );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) !== 1 ) {
			return false;
		}
		try {
			$method   = substr( strtoupper( $method ), 0, 20 );
			$url      = erm_pro_redact_url( $url );
			$policy   = $policy ?: ERM_Database::get_host_policy( $host );
			$now      = current_time( 'mysql' );
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, urls_log FROM $table WHERE host = %s AND request_method = %s",
					$host,
					$method
				)
			);
			$urls     = array();
			if ( get_option( 'erm_pro_track_all_urls', false ) ) {
				$urls = $existing && $existing->urls_log ? explode( "\n", $existing->urls_log ) : array();
				$urls = array_values( array_unique( array_merge( $urls, array( $url ) ) ) );
				$urls = array_slice( $urls, -max( 1, min( 100, (int) get_option( 'erm_pro_max_urls_logged', 10 ) ) ) );
			}
			$data = apply_filters(
				'erm_pro_before_log',
				array(
					'host'           => $host,
					'request_method' => $method,
					'url_example'    => $url,
					'urls_log'       => implode( "\n", $urls ),
					'request_size'   => max( 0, (int) $request_size ),
					'source_file'    => substr( $source['file'] ?? '', 0, 500 ),
					'source_plugin'  => substr( $source['plugin'] ?? '', 0, 255 ),
					'source_theme'   => substr( $source['theme'] ?? '', 0, 255 ),
				),
				$host,
				$url,
				$args
			);
			if ( ! is_array( $data ) ) {
				return false;
			}
			// Atomic upsert prevents lost counts and duplicate-key failures.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $table (host, request_method, url_example, urls_log, request_size,
             source_file, source_plugin, source_theme, first_timestamp, last_timestamp,
             is_blocked, rate_limit_interval, rate_limit_calls, last_request_token)
             VALUES (%s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), request_count = request_count + 1,
             last_timestamp = VALUES(last_timestamp), url_example = VALUES(url_example),
             urls_log = VALUES(urls_log), request_size = VALUES(request_size), is_deleted = 0,
             source_file = COALESCE(NULLIF(source_file, ''), VALUES(source_file)),
             source_plugin = COALESCE(NULLIF(source_plugin, ''), VALUES(source_plugin)),
             source_theme = COALESCE(NULLIF(source_theme, ''), VALUES(source_theme)),
             response_code = NULL, response_time = NULL, response_body = NULL,
             last_request_token = VALUES(last_request_token)",
					$host,
					$method,
					erm_pro_redact_url( $data['url_example'] ?? $url ),
					implode( "\n", $urls ),
					max( 0, (int) ( $data['request_size'] ?? $request_size ) ),
					$data['source_file'] ?? '',
					$data['source_plugin'] ?? '',
					$data['source_theme'] ?? '',
					$now,
					$now,
					(int) ( $policy->is_blocked ?? 0 ),
					(int) ( $policy->rate_limit_interval ?? 0 ),
					(int) ( $policy->rate_limit_calls ?? 0 ),
					$token
				)
			);
			if ( $result === false ) {
				return false;
			}
			$id = (int) $wpdb->insert_id;
			if ( $result === 1 && get_option( 'erm_pro_enable_notifications', true ) ) {
				self::send_notification( $host, $source );
			}
			do_action( 'erm_pro_after_log', $id, $host, $url );
			return $id;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function send_notification( $host, $source ) {
		$name    = $source['plugin'] ?? ( $source['theme'] ?? __( 'WordPress Core', 'erm-pro' ) );
		$notes   = get_transient( 'erm_pro_notifications' );
		$notes   = is_array( $notes ) ? $notes : array();
		$notes[] = array(
			'host'    => $host,
			'message' => sprintf(
				/* translators: 1: External host. 2: Plugin, theme or core source name. */
				__( 'New external request detected: %1$s (from %2$s)', 'erm-pro' ),
				$host,
				$name
			),
		);
		set_transient( 'erm_pro_notifications', array_slice( $notes, -10 ), HOUR_IN_SECONDS );
	}

	private static function get_request_source() {
		$roots = array(
			'plugin'  => defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( WPMU_PLUGIN_DIR ) : '',
			'regular' => wp_normalize_path( WP_PLUGIN_DIR ),
			'theme'   => wp_normalize_path( get_theme_root() ),
		);
		$own   = trailingslashit( wp_normalize_path( ERM_PRO_DIR ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Inspect a bounded call stack to attribute the HTTP request; no arguments are captured.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 50 ) as $call ) {
			$file = isset( $call['file'] ) ? wp_normalize_path( $call['file'] ) : '';
			if ( ! $file || strpos( $file, $own ) === 0 ) {
				continue;
			}
			foreach ( $roots as $type => $root ) {
				if ( $root && strpos( $file, trailingslashit( $root ) ) === 0 ) {
					$relative = substr( $file, strlen( trailingslashit( $root ) ) );
					$name     = explode( '/', $relative )[0];
					return array(
						'file' => $file,
						$type === 'theme' ? 'theme' : 'plugin' =>
							$name . ( $type === 'plugin' ? ' (MU Plugin)' : '' ),
					);
				}
			}
			$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
			if ( strpos( $file, $content ) === 0 && basename( $file ) !== 'index.php' ) {
				return array(
					'file'   => $file,
					'plugin' => __( 'Custom Code (wp-content)', 'erm-pro' ),
				);
			}
		}
		return array();
	}

	private static function calculate_request_size( $args ) {
		$size = 20;
		if ( isset( $args['body'] ) ) {
			$size += is_array( $args['body'] ) ? strlen( http_build_query( $args['body'] ) ) :
				( is_string( $args['body'] ) ? strlen( $args['body'] ) : 0 );
		}
		$headers = $args['headers'] ?? array();
		if ( is_string( $headers ) ) {
			$size += strlen( $headers );
		} elseif ( is_array( $headers ) || $headers instanceof Traversable ) {
			foreach ( $headers as $name => $value ) {
				foreach ( (array) $value as $part ) {
					if ( is_scalar( $part ) ) {
						$size += strlen( (string) $name ) + strlen( (string) $part ) + 4;
					}
				}
			}
		}
		return $size;
	}

	public static function setup_cleanup() {
		if ( ! wp_next_scheduled( 'erm_pro_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'erm_pro_daily_cleanup' );
		}
	}

	public static function cleanup() {
		$days = (int) get_option( 'erm_pro_retention_days', 30 );
		if ( get_option( 'erm_pro_auto_clean', true ) && $days > 0 ) {
			ERM_Database::cleanup_old_logs( $days );
		}
	}
}
