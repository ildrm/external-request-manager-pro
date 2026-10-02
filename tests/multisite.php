<?php
/** Network lifecycle regressions for a disposable multisite WordPress install. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !is_multisite() || ($args[0] ?? '') !== DB_NAME ||
    strpos($wpdb->prefix, 'ermtest_') !== 0) {
    throw new RuntimeException('Use a disposable multisite database with prefix ermtest_ and pass its exact name.');
}
require_once dirname(__DIR__) . '/external-request-manager.php';
$plugin = plugin_basename(ERM_PRO_FILE);
$prior = get_site_option('active_sitewide_plugins', []);
$initial_blog = get_current_blog_id();
$domain = wp_parse_url(network_home_url(), PHP_URL_HOST);
$suffix = wp_generate_uuid4();
try {
    // Create an existing site while the plugin is inactive, so activation is
    // actually responsible for installing its schema.
    $inactive = $prior;
    unset($inactive[$plugin]);
    update_site_option('active_sitewide_plugins', $inactive);
    $site_id = wp_insert_site(['domain' => $domain, 'path' => '/erm-existing-' . $suffix . '/']);
    if (is_wp_error($site_id)) { throw new RuntimeException($site_id->get_error_message()); }
    switch_to_blog($site_id);
    try {
        if (get_option('erm_pro_db_version')) { throw new RuntimeException('Existing-site fixture already had plugin tables'); }
    } finally {
        restore_current_blog();
    }
    update_site_option('active_sitewide_plugins', array_merge($prior, [$plugin => time()]));
    ERM_Database::install(true);
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $id) {
        switch_to_blog($id);
        try {
            if (get_option('erm_pro_db_version') !== ERM_PRO_DB_VERSION) { throw new RuntimeException('Network activation skipped a blog'); }
            ERM_Request_Logger::setup_cleanup();
        } finally {
            restore_current_blog();
        }
    }
    WP_CLI::log('PASS network activation installs tables for every existing site');
    $third = wp_insert_site(['domain' => $domain, 'path' => '/erm-new-' . $suffix . '/']);
    if (is_wp_error($third)) { throw new RuntimeException($third->get_error_message()); }
    switch_to_blog($third);
    try {
        if (get_option('erm_pro_db_version') !== ERM_PRO_DB_VERSION) { throw new RuntimeException('New site was not initialized'); }
    } finally {
        restore_current_blog();
    }
    WP_CLI::log('PASS network-active plugin initializes newly created sites');
    switch_to_blog($site_id);
    try {
        $id = ERM_Request_Logger::log_request('network-only.example.test', 'https://network-only.example.test/');
        ERM_Database::update_request_blocked($id, true);
    } finally {
        restore_current_blog();
    }
    if (ERM_Request_Logger::is_blocked('network-only.example.test')) { throw new RuntimeException('Policy leaked between sites'); }
    WP_CLI::log('PASS host rules remain isolated between blogs');
    ERM_Database::deactivate(true);
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $id) {
        switch_to_blog($id);
        try {
            if (wp_next_scheduled('erm_pro_daily_cleanup')) { throw new RuntimeException('Network deactivation left a cleanup job'); }
        } finally {
            restore_current_blog();
        }
    }
    if (get_current_blog_id() !== $initial_blog) { throw new RuntimeException('Blog context not restored'); }
    WP_CLI::log('PASS network deactivation removes all cleanup jobs and restores blog context');
} finally {
    update_site_option('active_sitewide_plugins', $prior);
}
