<?php
/**
 * Database schema and operations. Request rows aggregate by host and method;
 * blocking and rate-limit policies apply to the entire host.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP 5.0 does not support identifier placeholders. Table names use the trusted WP prefix and fixed constants; SQL fragments are internal or allowlisted, and values use prepare().
class ERM_Database {
	public static function install( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				$result = self::install_site();
				restore_current_blog();
				if ( ! $result ) {
					wp_die( esc_html__( 'Could not install External Request Manager tables.', 'erm-pro' ) );
				}
			}
			return;
		}
		if ( ! self::install_site() ) {
			wp_die( esc_html__( 'Could not install External Request Manager tables.', 'erm-pro' ) );
		}
	}

	private static function install_site() {
		if ( ! self::upgrade() ) {
			return false;
		}
		add_option( 'erm_pro_per_page', 25 );
		add_option( 'erm_pro_display_columns', array( 'host', 'count', 'status', 'last_request', 'actions' ) );
		return true;
	}

	public static function initialize_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( ERM_PRO_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install_site();
			restore_current_blog();
		}
	}

	public static function upgrade() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// dbDelta modifies the existing tables in place, including on reactivation.
		foreach ( self::schemas() as $table => $schema ) {
			dbDelta( $schema );
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
			preg_match_all( '/^            ([a-z_]+) /m', $schema, $matches );
			if ( $wpdb->last_error || array_diff( $matches[1], $columns ) ) {
				return false;
			}
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			if ( strtoupper( (string) $engine ) !== 'INNODB' && $wpdb->query( "ALTER TABLE $table ENGINE=InnoDB" ) === false ) {
				return false;
			}
			// A unique key is needed for atomic request aggregation.
			if ( $table === $wpdb->prefix . ERM_PRO_TABLE_REQUESTS ) {
				$indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'host_method'" );
				if ( count( $indexes ) !== 2 || (int) $indexes[0]->Non_unique !== 0 ||
					$indexes[0]->Column_name !== 'host' || $indexes[1]->Column_name !== 'request_method' ) {
					return false;
				}
			}
		}
		update_option( 'erm_pro_db_version', ERM_PRO_DB_VERSION );
		update_option( 'erm_pro_version', ERM_PRO_VERSION );
		return true;
	}

	private static function schemas() {
		global $wpdb;
		$requests = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$deleted  = $wpdb->prefix . ERM_PRO_TABLE_DELETED;
		$charset  = $wpdb->get_charset_collate();
		// IF NOT EXISTS makes dbDelta parse "IF" as the table name.
		return array(
			$requests => "CREATE TABLE $requests (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            host varchar(255) NOT NULL,
            request_method varchar(20) DEFAULT 'GET',
            url_example text NOT NULL,
            urls_log longtext,
            response_code int DEFAULT NULL,
            request_size bigint DEFAULT 0,
            response_time float DEFAULT 0,
            response_body longtext DEFAULT NULL,
            last_request_token varchar(36) DEFAULT NULL,
            source_file varchar(500) DEFAULT NULL,
            source_plugin varchar(255) DEFAULT NULL,
            source_theme varchar(255) DEFAULT NULL,
            request_count bigint(20) unsigned NOT NULL DEFAULT 1,
            first_timestamp datetime NOT NULL,
            last_timestamp datetime NOT NULL,
            is_blocked tinyint(1) unsigned NOT NULL DEFAULT 0,
            is_deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
            rate_limit_interval int DEFAULT 0,
            rate_limit_calls int DEFAULT 0,
            notes text,
            custom_action varchar(100),
            PRIMARY KEY  (id),
            UNIQUE KEY host_method (host, request_method),
            KEY is_blocked (is_blocked),
            KEY is_deleted (is_deleted),
            KEY last_timestamp (last_timestamp),
            KEY request_count (request_count),
            KEY host (host)
        ) ENGINE=InnoDB $charset;",
			$deleted  => "CREATE TABLE $deleted (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            host varchar(255) NOT NULL,
            url_example text,
            was_blocked tinyint(1) unsigned NOT NULL DEFAULT 0,
            deleted_timestamp datetime NOT NULL,
            deleted_by_user bigint(20) unsigned,
            PRIMARY KEY  (id),
            KEY host (host),
            KEY deleted_timestamp (deleted_timestamp)
        ) ENGINE=InnoDB $charset;",
		);
	}

	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				wp_clear_scheduled_hook( 'erm_pro_daily_cleanup' );
				restore_current_blog();
			}
			return;
		}
		wp_clear_scheduled_hook( 'erm_pro_daily_cleanup' );
	}

	public static function get_requests( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$args  = wp_parse_args(
			$args,
			array(
				'filter'    => 'all',
				'search'    => '',
				'search_by' => '',
				'per_page'  => get_option( 'erm_pro_per_page', 25 ),
				'paged'     => 1,
				'orderby'   => 'request_count',
				'order'     => 'DESC',
			)
		);
		// Public callers may supply malformed values as well as admin query strings.
		foreach ( array( 'filter', 'search', 'search_by', 'per_page', 'paged', 'orderby', 'order' ) as $key ) {
			if ( ! is_scalar( $args[ $key ] ) ) {
				$args[ $key ] = '';
			}
		}
		$sortable = array(
			'id',
			'host',
			'request_method',
			'request_count',
			'request_size',
			'is_blocked',
			'first_timestamp',
			'last_timestamp',
		);
		$orderby  = in_array( $args['orderby'], $sortable, true ) ? $args['orderby'] : 'request_count';
		$order    = strtoupper( (string) $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$per_page = max( 5, min( 200, (int) $args['per_page'] ) );
		$paged    = max( 1, (int) $args['paged'] );
		$where    = array( 'is_deleted = 0' );
		if ( $args['filter'] === 'blocked' || $args['filter'] === 'allowed' ) {
			$where[] = 'is_blocked = ' . ( $args['filter'] === 'blocked' ? '1' : '0' );
		}
		if ( is_scalar( $args['search'] ) && (string) $args['search'] !== '' ) {
			$search     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$searchable = array(
				'host'   => 'host',
				'url'    => 'url_example',
				'plugin' => 'source_plugin',
				'theme'  => 'source_theme',
			);
			if ( isset( $searchable[ $args['search_by'] ] ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column comes exclusively from the fixed searchable map.
				$where[] = $wpdb->prepare( $searchable[ $args['search_by'] ] . ' LIKE %s', $search );
			} else {
				$where[] = $wpdb->prepare(
					'(host LIKE %s OR url_example LIKE %s OR source_plugin LIKE %s OR source_theme LIKE %s)',
					$search,
					$search,
					$search,
					$search
				);
			}
		}
		$where = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$paged = min( $paged, max( 1, (int) ceil( $total / $per_page ) ) );
		// Bodies and URL histories are fetched only by the detail endpoint.
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, host, request_method, url_example, source_plugin, source_theme,
             request_count, request_size, first_timestamp, last_timestamp, is_blocked,
             rate_limit_interval FROM $table WHERE $where
             ORDER BY $orderby $order, id $order LIMIT %d OFFSET %d",
				$per_page,
				( $paged - 1 ) * $per_page
			)
		);
		return array(
			'total'    => $total,
			'data'     => $data,
			'paged'    => $paged,
			'per_page' => $per_page,
		);
	}

	public static function get_request_detail( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND is_deleted = 0", $id ) );
	}

	public static function get_host_policy( $host ) {
		global $wpdb;
		$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$hosts = self::host_variants( $host );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MAX(is_blocked) AS is_blocked, MAX(rate_limit_interval) AS rate_limit_interval,
             MAX(rate_limit_calls) AS rate_limit_calls FROM $table WHERE host IN (%s, %s) AND is_deleted = 0",
				$hosts[0],
				$hosts[1]
			)
		);
	}

	/** Preserve policies stored under the legacy absolute DNS form. */
	private static function host_variants( $host ) {
		$host = strtolower( rtrim( (string) $host, '.' ) );
		return array( $host, $host . '.' );
	}

	private static function clear_rate_limit( $host ) {
		foreach ( self::host_variants( $host ) as $variant ) {
			delete_transient( 'erm_rate_limit_' . md5( $variant ) );
		}
	}

	public static function count_by_status() {
		global $wpdb;
		$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$row   = $wpdb->get_row(
			"SELECT COUNT(*) AS total, SUM(is_blocked = 1) AS blocked,
            SUM(is_blocked = 0) AS allowed FROM $table WHERE is_deleted = 0"
		);
		return array(
			'total'   => (int) ( $row->total ?? 0 ),
			'blocked' => (int) ( $row->blocked ?? 0 ),
			'allowed' => (int) ( $row->allowed ?? 0 ),
		);
	}

	public static function update_request_blocked( $id, $blocked ) {
		return self::bulk_action( array( $id ), $blocked ? 'block' : 'unblock' );
	}

	public static function update_rate_limit( $id, $interval, $calls = 1 ) {
		global $wpdb;
		$request = self::get_request_detail( $id );
		if ( ! $request ) {
			return false;
		}
		$table    = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$interval = max( 0, min( DAY_IN_SECONDS * 365, (int) $interval ) );
		$calls    = $interval ? max( 1, min( 100000, (int) $calls ) ) : 0;
		$hosts    = self::host_variants( $request->host );
		$result   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET rate_limit_interval = %d, rate_limit_calls = %d
                 WHERE host IN (%s, %s) AND is_deleted = 0",
				$interval,
				$calls,
				$hosts[0],
				$hosts[1]
			)
		);
		if ( $result !== false ) {
			self::clear_rate_limit( $request->host );
		}
		return $result;
	}

	public static function delete_request( $id, $hard_delete = true ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		if ( ! $hard_delete ) {
			$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
			return $wpdb->update( $table, array( 'is_deleted' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		}
		return self::delete_matching( "id = $id" );
	}

	public static function bulk_action( $ids, $action ) {
		global $wpdb;
		if ( ! is_array( $ids ) ) {
			return false;
		}
		$ids = array_values(
			array_unique(
				array_filter(
					array_map(
						function( $id ) {
							return is_scalar( $id ) && ctype_digit( (string) $id ) ? (int) $id : 0;
						},
						$ids
					),
					function( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( ! $ids ) {
			return false;
		}
		$table    = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$ids_list = implode( ',', $ids );
		if ( $action === 'delete' ) {
			return self::delete_matching( "id IN ($ids_list)" );
		}
		if ( $action === 'restore' ) {
			return $wpdb->query( "UPDATE $table SET is_deleted = 0 WHERE id IN ($ids_list) AND is_deleted = 1" );
		}
		if ( ! in_array( $action, array( 'block', 'unblock' ), true ) ) {
			return false;
		}
		$hosts = $wpdb->get_col( "SELECT DISTINCT host FROM $table WHERE id IN ($ids_list) AND is_deleted = 0" );
		if ( $wpdb->last_error ) {
			return false;
		}
		if ( ! $hosts ) {
			return 0;
		}
		$hosts        = array_values( array_unique( array_merge( ...array_map( array( self::class, 'host_variants' ), $hosts ) ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $hosts ), '%s' ) );
		$blocked      = $action === 'block' ? 1 : 0;
		$result       = $wpdb->query(
			$wpdb->prepare(
				/* phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %s placeholder per host is generated immediately above. */
				"UPDATE $table SET is_blocked = $blocked WHERE host IN ($placeholders) AND is_deleted = 0",
				$hosts
			)
		);
		if ( $result !== false ) {
			foreach ( $hosts as $host ) {
				self::clear_rate_limit( $host );
			}
		}
		return $result;
	}

	/**
	 * Audit and remove exactly the locked rows. Never delete after an audit failure.
	 * Transactions are atomic on InnoDB; legacy nontransactional tables still stop
	 * before deletion if the audit insert fails.
	 */
	private static function delete_matching( $where ) {
		global $wpdb;
		$table   = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
		$deleted = $wpdb->prefix . ERM_PRO_TABLE_DELETED;
		if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
			return false;
		}
		$rows = $wpdb->get_results( "SELECT id, host FROM $table WHERE $where FOR UPDATE" );
		if ( $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( ! $rows ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		$ids   = implode(
			',',
			array_map(
				function( $row ) {
					return (int) $row->id;
				},
				$rows
			)
		);
		$audit = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $deleted (host, url_example, was_blocked, deleted_timestamp, deleted_by_user)
             SELECT host, url_example, is_blocked, %s, %d FROM $table WHERE id IN ($ids)",
				current_time( 'mysql' ),
				get_current_user_id()
			)
		);
		if ( $audit === false || $audit !== count( $rows ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$result = $wpdb->query( "DELETE FROM $table WHERE id IN ($ids)" );
		if ( $result === false || $result !== count( $rows ) || $wpdb->query( 'COMMIT' ) === false ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		foreach ( $rows as $row ) {
			self::clear_rate_limit( $row->host );
		}
		return $result;
	}

	public static function clear_all_logs( $except_blocked = false ) {
		$result = self::delete_matching( $except_blocked ? 'is_blocked = 0' : '1 = 1' );
		if ( $result !== false ) {
			do_action( 'erm_pro_after_clear', $except_blocked ? 'except_blocked' : 'all' );
		}
		return $result;
	}

	public static function cleanup_old_logs( $days = 30 ) {
		if ( (int) $days < 1 ) {
			return false;
		}
		global $wpdb;
		$cutoff = ( new DateTimeImmutable( current_time( 'mysql' ), new DateTimeZone( 'UTC' ) ) )
			->modify( '-' . (int) $days . ' days' )->format( 'Y-m-d H:i:s' );
		// Policy rows must survive retention, including legacy soft-deleted rules.
		$where  = $wpdb->prepare( 'last_timestamp < %s AND is_blocked = 0 AND rate_limit_interval = 0', $cutoff );
		$result = self::delete_matching( $where );
		if ( $result !== false ) {
			$deleted = $wpdb->prefix . ERM_PRO_TABLE_DELETED;
			$wpdb->query( $wpdb->prepare( "DELETE FROM $deleted WHERE deleted_timestamp < %s", $cutoff ) );
			do_action( 'erm_pro_cleanup', $result );
		}
		return $result;
	}

	public static function permanently_delete_old_logs( $days = 30 ) {
		return self::cleanup_old_logs( $days );
	}
}
