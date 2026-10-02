<?php
/** WordPress admin pages, assets, and notices. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP 5.0 does not support identifier placeholders. Table names use the trusted WP prefix and fixed constants; SQL fragments are internal or allowlisted, and values use prepare().
class ERM_Admin_Pages {
	private static $page_hooks = array();
	public static function init() {
		add_action( 'admin_menu', array( self::class, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( self::class, 'show_notices' ) );
	}

	public static function register_menus() {
		self::$page_hooks[] = add_menu_page(
			__( 'External Requests Manager', 'erm-pro' ),
			__( 'Ext. Requests', 'erm-pro' ),
			'manage_options',
			'erm-pro-logs',
			array( self::class, 'dashboard_page' ),
			'dashicons-shield-alt',
			82
		);
		foreach ( array(
			'logs'     => 'Dashboard',
			'settings' => 'Settings',
			'deleted'  => 'Deleted',
		) as $slug => $title ) {
			$titles             = array(
				'logs'     => __( 'Dashboard', 'erm-pro' ),
				'settings' => __( 'Settings', 'erm-pro' ),
				'deleted'  => __( 'Deleted', 'erm-pro' ),
			);
			self::$page_hooks[] = add_submenu_page(
				'erm-pro-logs',
				$titles[ $slug ],
				$titles[ $slug ],
				'manage_options',
				'erm-pro-' . $slug,
				array( self::class, $slug === 'logs' ? 'dashboard_page' : $slug . '_page' )
			);
		}
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $hook_suffix, self::$page_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'erm-pro-admin', ERM_PRO_URL . 'assets/css/admin.css', array(), ERM_PRO_VERSION . '.' . filemtime( ERM_PRO_DIR . 'assets/css/admin.css' ) );
		wp_enqueue_script( 'erm-pro-admin', ERM_PRO_URL . 'assets/js/admin.js', array( 'jquery' ), ERM_PRO_VERSION . '.' . filemtime( ERM_PRO_DIR . 'assets/js/admin.js' ), true );
		wp_localize_script(
			'erm-pro-admin',
			'ermProData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'erm_nonce' ),
				'messages' => array(
					'confirmDelete'             => __( 'Delete the selected logs and their rules? This action cannot be undone.', 'erm-pro' ),
					'confirmClearAll'           => __( 'Permanently delete ALL logs and unblock all hosts? This action cannot be undone.', 'erm-pro' ),
					'confirmClearExceptBlocked' => __( 'Permanently delete allowed logs and their rate limits while keeping blocked entries? This action cannot be undone.', 'erm-pro' ),
					'selectAction'              => __( 'Please select an action', 'erm-pro' ),
					'selectItem'                => __( 'Please select at least one item', 'erm-pro' ),
					'error'                     => __( 'The request failed. Refresh the page and try again.', 'erm-pro' ),
					'running'                   => __( 'Running…', 'erm-pro' ),
					'failed'                    => __( 'Failed', 'erm-pro' ),
					'invalidRateLimit'          => __( 'Enter a valid interval and number of calls.', 'erm-pro' ),
				),
				'labels'   => array(
					'host'     => __( 'Host', 'erm-pro' ),
					'url'      => __( 'URL', 'erm-pro' ),
					'method'   => __( 'Method', 'erm-pro' ),
					'source'   => __( 'Source', 'erm-pro' ),
					'count'    => __( 'Request Count', 'erm-pro' ),
					'status'   => __( 'Status', 'erm-pro' ),
					'first'    => __( 'First Seen', 'erm-pro' ),
					'last'     => __( 'Last Seen', 'erm-pro' ),
					'file'     => __( 'Source File', 'erm-pro' ),
					'size'     => __( 'Request Size', 'erm-pro' ),
					'code'     => __( 'Response Code', 'erm-pro' ),
					'time'     => __( 'Response Time', 'erm-pro' ),
					'body'     => __( 'Stored Response Data', 'erm-pro' ),
					'urls'     => __( 'Logged URLs', 'erm-pro' ),
					'noUrls'   => __( 'No URLs are available', 'erm-pro' ),
					'download' => __( 'Download Stored Response', 'erm-pro' ),
					'block'    => __( 'Block', 'erm-pro' ),
					'unblock'  => __( 'Unblock', 'erm-pro' ),
				),
			)
		);
	}

	private static function query( $key, $default = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin filters do not mutate data.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ?
			sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : $default;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function show_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Also show migration notices away from the plugin screen.
		if ( get_option( 'erm_pro_db_version', '' ) !== ERM_PRO_DB_VERSION ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Database schema is out of date for External Request Manager Pro.', 'erm-pro' ) . ' ' .
				'<a href="' . esc_url( admin_url( 'admin.php?page=erm-pro-settings' ) ) . '">' .
				esc_html__( 'Run DB Updater', 'erm-pro' ) . '</a></p></div>';
		}
		if ( ! in_array( self::query( 'page' ), array( 'erm-pro-logs', 'erm-pro-settings', 'erm-pro-deleted' ), true ) ||
			! get_option( 'erm_pro_enable_notifications', true ) ) {
			return;
		}
		$notes = get_transient( 'erm_pro_notifications' );
		if ( is_array( $notes ) ) {
			foreach ( $notes as $note ) {
				echo '<div class="notice notice-info is-dismissible"><p>' .
					esc_html( $note['message'] ?? '' ) . '</p></div>';
			}
			delete_transient( 'erm_pro_notifications' );
		}
	}

	public static function dashboard_page() {
		self::authorize();
		$filter          = self::query( 'filter', 'all' );
		$filter          = in_array( $filter, array( 'all', 'blocked', 'allowed' ), true ) ? $filter : 'all';
		$search          = self::query( 's' );
		$search_by       = self::query( 'search_by' );
		$results         = ERM_Database::get_requests(
			array(
				'filter'    => $filter,
				'search'    => $search,
				'search_by' => $search_by,
				'paged'     => max( 1, (int) self::query( 'paged', '1' ) ),
			)
		);
		$per_page        = $results['per_page'];
		$paged           = $results['paged'];
		$counts          = ERM_Database::count_by_status();
		$display_columns = ERM_Settings::sanitize_columns( get_option( 'erm_pro_display_columns' ) );
		require ERM_PRO_DIR . 'templates/dashboard.php';
	}

	public static function settings_page() {
		self::authorize();
		require ERM_PRO_DIR . 'templates/settings.php';
	}

	public static function deleted_page() {
		self::authorize();
		$per_page = ERM_Settings::sanitize_per_page( get_option( 'erm_pro_per_page', 25 ) );
		global $wpdb;
		$table   = $wpdb->prefix . ERM_PRO_TABLE_DELETED;
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$paged   = min( max( 1, (int) self::query( 'paged', '1' ) ), max( 1, (int) ceil( $total / $per_page ) ) );
		$data    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table ORDER BY deleted_timestamp DESC, id DESC LIMIT %d OFFSET %d",
				$per_page,
				( $paged - 1 ) * $per_page
			)
		);
		$results = array(
			'total'    => $total,
			'data'     => $data,
			'paged'    => $paged,
			'per_page' => $per_page,
		);
		require ERM_PRO_DIR . 'templates/deleted.php';
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'erm-pro' ) );
		}
	}
}
