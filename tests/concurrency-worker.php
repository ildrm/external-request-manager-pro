<?php
/** Worker invoked by concurrency.php, exclusively against the guarded test DB. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || ($args[0] ?? '') !== DB_NAME ||
    strpos($wpdb->prefix, 'ermtest_') !== 0) {
    throw new RuntimeException('Disposable test database required.');
}
require_once dirname(__DIR__) . '/external-request-manager.php';
update_option('erm_pro_enable_notifications', false);
if (($args[1] ?? '') === 'log') {
    for ($i = 0; $i < 25; ++$i) {
        if (!ERM_Request_Logger::log_request('concurrent.example.test', 'https://concurrent.example.test/' . $i)) {
            throw new RuntimeException('Concurrent logging failed');
        }
    }
    echo 'logged=25';
} elseif (($args[1] ?? '') === 'rate') {
    $accepted = 0;
    for ($i = 0; $i < 10; ++$i) {
        if (!ERM_Request_Logger::check_rate_limit('concurrent-rate.example.test')) { ++$accepted; }
    }
    echo 'accepted=' . $accepted;
} else {
    throw new RuntimeException('Unknown worker mode');
}
