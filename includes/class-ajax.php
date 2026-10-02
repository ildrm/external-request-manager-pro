<?php
/** Administrator-only AJAX operations. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification -- Every public endpoint calls authorize() before reading input; authorize() verifies the nonce and manage_options.
class ERM_AJAX {
	public static function init() {
		foreach ( array(
			'toggle_block',
			'bulk_action',
			'get_detail',
			'clear_logs',
			'update_rate_limit',
			'restore_deleted',
			'run_db_upgrade',
		) as $action ) {
			add_action( 'wp_ajax_erm_' . $action, array( self::class, $action ) );
		}
	}

	private static function authorize() {
		check_ajax_referer( 'erm_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'erm-pro' ) ), 403 );
		}
	}

	private static function input( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ?
			wp_unslash( (string) $_POST[ $key ] ) : $default;
	}

	private static function request() {
		$id = self::input( 'id' );
		if ( ! ctype_digit( $id ) || (int) $id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request', 'erm-pro' ) ), 400 );
		}
		$request = ERM_Database::get_request_detail( (int) $id );
		if ( ! $request ) {
			wp_send_json_error( array( 'message' => __( 'Request not found', 'erm-pro' ) ), 404 );
		}
		return $request;
	}

	private static function mutation_result( $result, $message ) {
		if ( $result === false ) {
			wp_send_json_error( array( 'message' => __( 'Database operation failed. No success was recorded.', 'erm-pro' ) ), 500 );
		}
		wp_send_json_success(
			array(
				'message' => $message,
				'count'   => (int) $result,
				'counts'  => ERM_Database::count_by_status(),
			)
		);
	}

	public static function toggle_block() {
		self::authorize();
		$request = self::request();
		$blocked = ! $request->is_blocked;
		$result  = ERM_Database::update_request_blocked( $request->id, $blocked );
		if ( $result === false ) {
			self::mutation_result( false, '' );
		}
		wp_send_json_success(
			array(
				'message' => $blocked ? __( 'Blocked', 'erm-pro' ) : __( 'Allowed', 'erm-pro' ),
				'status'  => $blocked,
				'counts'  => ERM_Database::count_by_status(),
			)
		);
	}

	public static function bulk_action() {
		self::authorize();
		$action = self::input( 'bulk_action' );
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		if ( ! in_array( $action, array( 'block', 'unblock', 'delete', 'restore' ), true ) || ! $ids || count( $ids ) > 1000 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid action or selection', 'erm-pro' ) ), 400 );
		}
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) {
				wp_send_json_error( array( 'message' => __( 'Invalid request ID', 'erm-pro' ) ), 400 );
			}
		}
		$result = ERM_Database::bulk_action( $ids, $action );
		self::mutation_result(
			$result,
			sprintf(
				$action === 'delete' ? /* translators: %d: Number of rows actually deleted. */
				__( '%d items deleted', 'erm-pro' ) : /* translators: %d: Number of rows actually updated. */
				__( '%d items updated', 'erm-pro' ),
				(int) $result
			)
		);
	}

	public static function get_detail() {
		self::authorize();
		$request    = self::request();
		$status     = $request->is_blocked ? __( 'Blocked', 'erm-pro' ) :
			( $request->rate_limit_interval > 0 ? __( 'Rate Limited', 'erm-pro' ) : __( 'Allowed', 'erm-pro' ) );
		$source     = ! empty( $request->source_plugin ) ? sprintf( /* translators: %s: Plugin directory name. */
			__( 'Plugin: %s', 'erm-pro' ),
			$request->source_plugin
		) :
			( ! empty( $request->source_theme ) ? sprintf( /* translators: %s: Theme directory name. */
				__( 'Theme: %s', 'erm-pro' ),
				$request->source_theme
			) : __( 'WordPress Core', 'erm-pro' ) );
		$track_urls = (bool) get_option( 'erm_pro_track_all_urls', false );
		$urls       = $track_urls && $request->urls_log ?
			array_values( array_filter( array_map( 'trim', explode( "\n", $request->urls_log ) ) ) ) : array();
		wp_send_json_success(
			array(
				'id'                  => (int) $request->id,
				'host'                => $request->host,
				'url'                 => erm_pro_redact_url( $request->url_example ),
				'method'              => $request->request_method,
				'count'               => (int) $request->request_count,
				'first_request'       => $request->first_timestamp,
				'last_request'        => $request->last_timestamp,
				'status'              => $status,
				'source'              => $source,
				'source_file'         => $request->source_file ?: '-',
				'request_size'        => erm_pro_format_bytes( $request->request_size ),
				'response_code'       => $request->response_code === null ? '-' : (int) $request->response_code,
				'response_time'       => $request->response_time === null ? '-' :
					sprintf( /* translators: %f: Elapsed response time in seconds. */
						__( '%f seconds', 'erm-pro' ),
						$request->response_time
					),
				'response_data'       => $request->response_body === null ? '' : $request->response_body,
				'rate_limit_interval' => (int) $request->rate_limit_interval,
				'rate_limit_calls'    => max( 1, (int) $request->rate_limit_calls ),
				'is_blocked'          => (bool) $request->is_blocked,
				'urls_list'           => array_map( 'erm_pro_redact_url', $urls ),
				'track_all_urls'      => $track_urls,
			)
		);
	}

	public static function clear_logs() {
		self::authorize();
		$mode = self::input( 'mode', 'all' );
		if ( ! in_array( $mode, array( 'all', 'except_blocked' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid mode', 'erm-pro' ) ), 400 );
		}
		self::mutation_result(
			ERM_Database::clear_all_logs( $mode === 'except_blocked' ),
			__( 'Logs cleared', 'erm-pro' )
		);
	}

	public static function update_rate_limit() {
		self::authorize();
		$request  = self::request();
		$interval = self::input( 'interval', '0' );
		$calls    = self::input( 'calls', '1' );
		if ( ! ctype_digit( $interval ) || ! ctype_digit( $calls ) || (int) $interval > DAY_IN_SECONDS * 365 ||
			(int) $calls > 100000 || ( (int) $interval > 0 && (int) $calls < 1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid rate limit', 'erm-pro' ) ), 400 );
		}
		self::mutation_result(
			ERM_Database::update_rate_limit( $request->id, (int) $interval, (int) $calls ),
			__( 'Rate limit updated', 'erm-pro' )
		);
	}

	public static function restore_deleted() {
		self::authorize();
		$id = self::input( 'id' );
		if ( ! ctype_digit( $id ) || (int) $id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request ID', 'erm-pro' ) ), 400 );
		}
		$result = ERM_Database::bulk_action( array( (int) $id ), 'restore' );
		if ( $result === 0 ) {
			wp_send_json_error( array( 'message' => __( 'Only legacy soft-deleted logs can be restored. Audit entries cannot be restored.', 'erm-pro' ) ), 404 );
		}
		self::mutation_result( $result, __( 'Item restored', 'erm-pro' ) );
	}

	public static function run_db_upgrade() {
		self::authorize();
		if ( ! ERM_Database::upgrade() ) {
			wp_send_json_error( array( 'message' => __( 'Upgrade failed. The installed schema version was not advanced.', 'erm-pro' ) ), 500 );
		}
		wp_send_json_success(
			array(
				'message'    => __( 'Database upgraded successfully.', 'erm-pro' ),
				'db_version' => get_option( 'erm_pro_db_version' ),
			)
		);
	}
}
