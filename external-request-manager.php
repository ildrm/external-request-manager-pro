<?php
/**
 * Plugin Name: External Request Manager Pro
 * Plugin URI: https://github.com/YusufBahrami/external-request-manager-pro
 * Description: Monitor, block, rate-limit, and review external WordPress HTTP requests.
 * Version: 2.5.3
 * Author: Yusuf Bahrami
 * Author URI: https://wcoq.com/
 * License: GPL-2.0+
 * Text Domain: erm-pro
 * Domain Path: /languages
 * GitHub Plugin URI: YusufBahrami/external-request-manager-pro
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERM_PRO_VERSION', '2.5.3' );
define( 'ERM_PRO_DB_VERSION', '1.2.0' );
define( 'ERM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ERM_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'ERM_PRO_FILE', __FILE__ );
define( 'ERM_PRO_TABLE_REQUESTS', 'external_requests' );
define( 'ERM_PRO_TABLE_DELETED', 'external_requests_deleted' );
define( 'ERM_PRO_OPTION_GROUP', 'erm_pro_settings' );

require_once ERM_PRO_DIR . 'includes/helpers.php';
require_once ERM_PRO_DIR . 'includes/class-database.php';
require_once ERM_PRO_DIR . 'includes/class-request-logger.php';
require_once ERM_PRO_DIR . 'includes/class-admin-pages.php';
require_once ERM_PRO_DIR . 'includes/class-settings.php';
require_once ERM_PRO_DIR . 'includes/class-ajax.php';

register_activation_hook( ERM_PRO_FILE, array( 'ERM_Database', 'install' ) );
register_deactivation_hook( ERM_PRO_FILE, array( 'ERM_Database', 'deactivate' ) );
if ( version_compare( $GLOBALS['wp_version'], '5.1', '>=' ) ) {
	add_action( 'wp_initialize_site', array( 'ERM_Database', 'initialize_site' ), 200 );
} else {
	add_action(
		'wpmu_new_blog',
		function( $site_id ) {
			ERM_Database::initialize_site( get_site( $site_id ) );
		},
		200
	);
}

add_action(
	'plugins_loaded',
	function() {
		if ( is_admin() ) {
			ERM_Admin_Pages::init();
			ERM_Settings::init();
			ERM_AJAX::init();
		}
		ERM_Request_Logger::init();
	}
);

add_action(
	'init',
	function() {
		load_plugin_textdomain( 'erm-pro', false, dirname( plugin_basename( ERM_PRO_FILE ) ) . '/languages' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( ERM_PRO_FILE ),
	function( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=erm-pro-logs' ) ) . '">' .
			esc_html__( 'Dashboard', 'erm-pro' ) . '</a>'
		);
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=erm-pro-settings' ) ) . '">' .
		esc_html__( 'Settings', 'erm-pro' ) . '</a>';
		return $links;
	}
);
