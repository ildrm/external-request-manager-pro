<?php
/**
 * Run using WP-CLI eval-file in a disposable WordPress installation.
 * The explicit database-name argument and table prefix guard destructive tests.
 */
if (!defined('WP_CLI') || !WP_CLI || ($args[0] ?? '') !== DB_NAME ||
    strpos($GLOBALS['wpdb']->prefix, 'ermtest_') !== 0) {
    throw new RuntimeException('Use a disposable database, prefix ermtest_, and pass its exact name as the first argument.');
}
global $wpdb, $passed, $failed, $admin;
if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
require_once dirname(__DIR__) . '/external-request-manager.php';
ERM_Request_Logger::init();
ERM_Settings::register_settings();
$admin = get_user_by('login', 'review-admin');
wp_set_current_user($admin->ID);
$wpdb->suppress_errors(true);
update_option('erm_pro_enable_notifications', false);
update_option('erm_pro_track_response', true);
update_option('timezone_string', 'Asia/Tehran');

$passed = 0;
$failed = 0;
function verify($condition, $message = 'Assertion failed') {
    if (!$condition) { throw new RuntimeException($message); }
}
function scenario($name, $callback) {
    global $passed, $failed;
    try {
        $callback();
        ++$passed;
        WP_CLI::log('PASS ' . $name);
    } catch (Throwable $error) {
        ++$failed;
        WP_CLI::log('FAIL ' . $name . ': ' . $error->getMessage());
    }
}
function reset_logs() {
    global $wpdb;
    foreach ([ERM_PRO_TABLE_REQUESTS, ERM_PRO_TABLE_DELETED] as $table) {
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . $table);
    }
}
function seed($host = 'api.example.test', $method = 'GET') {
    return ERM_Request_Logger::log_request($host, 'https://' . $host . '/path', $method, 80);
}
function capture_ajax($method, $post) {
    $_POST = $post;
    $_REQUEST = ['nonce' => wp_create_nonce('erm_nonce')];
    $handler = function() { return function() { throw new RuntimeException('erm-test-json-exit'); }; };
    add_filter('wp_die_handler', $handler, PHP_INT_MAX);
    add_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
    ob_start();
    try {
        call_user_func(['ERM_AJAX', $method]);
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'erm-test-json-exit') {
            ob_end_clean();
            throw $error;
        }
    } finally {
        remove_filter('wp_die_handler', $handler, PHP_INT_MAX);
        remove_filter('wp_die_ajax_handler', $handler, PHP_INT_MAX);
    }
    return json_decode(ob_get_clean(), true);
}

scenario('fresh schema has all columns and unique aggregation key', function() {
    verify(ERM_Database::upgrade());
    global $wpdb;
    $table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
    verify(in_array('last_request_token', $wpdb->get_col("SHOW COLUMNS FROM $table"), true));
    verify(get_option('erm_pro_db_version') === ERM_PRO_DB_VERSION);
});
scenario('reactivation preserves IDs, counts, blocks, and rate rules', function() {
    reset_logs();
    $id = seed();
    seed();
    ERM_Database::update_request_blocked($id, true);
    ERM_Database::update_rate_limit($id, 60, 3);
    $before = ERM_Database::get_request_detail($id);
    ERM_Database::install();
    ERM_Database::install();
    $after = ERM_Database::get_request_detail($id);
    verify($before == $after);
});
scenario('manual upgrade adds missing columns without moving logs', function() {
    global $wpdb;
    $table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
    $count = ERM_Database::count_by_status();
    $wpdb->query("ALTER TABLE $table DROP COLUMN response_body, DROP COLUMN last_request_token");
    update_option('erm_pro_db_version', '1.0.0');
    verify(ERM_Database::upgrade());
    verify(ERM_Database::count_by_status() === $count);
});
scenario('failed upgrade does not advance schema version', function() {
    global $wpdb;
    $table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
    $wpdb->query("ALTER TABLE $table DROP COLUMN response_body");
    update_option('erm_pro_db_version', '1.0.0');
    $fault = function($sql) {
        return strpos($sql, 'ADD COLUMN response_body') !== false ? 'SELECT * FROM erm_nonexistent_test_table' : $sql;
    };
    add_filter('query', $fault);
    verify(ERM_Database::upgrade() === false);
    verify(get_option('erm_pro_db_version') === '1.0.0');
    remove_filter('query', $fault);
    verify(ERM_Database::upgrade());
});
scenario('legacy engines are converted before transactional deletion', function() {
    global $wpdb;
    $table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
    $wpdb->query("ALTER TABLE $table ENGINE=MyISAM");
    verify(ERM_Database::upgrade());
    $engine = $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
    ));
    verify(strtoupper($engine) === 'INNODB');
});
scenario('host block applies to all methods and normalized hostnames', function() {
    reset_logs();
    $get = seed();
    $post = seed('api.example.test', 'POST');
    ERM_Database::update_request_blocked($post, true);
    verify(ERM_Request_Logger::is_blocked('API.EXAMPLE.TEST.'));
    verify((int) ERM_Database::get_request_detail($get)->is_blocked === 1);
    $args = ERM_Request_Logger::track_request_args(['method' => 'HEAD'], 'https://api.example.test/');
    $response = ERM_Request_Logger::intercept_request(false, $args, 'https://API.EXAMPLE.TEST./');
    verify(is_wp_error($response) && $response->get_error_code() === 'erm_blocked');
    $head = ERM_Database::get_requests(['search' => 'api.example.test']);
    verify($head['total'] === 3);
    ERM_Database::update_request_blocked($get, false);
    verify(!ERM_Request_Logger::is_blocked('api.example.test'));
});
scenario('legacy trailing-dot host policies apply to canonical hosts and all methods', function() {
    reset_logs();
    global $wpdb;
    $legacy = seed('legacy-policy.example.test');
    $wpdb->update($wpdb->prefix . ERM_PRO_TABLE_REQUESTS,
        ['host' => 'legacy-policy.example.test.', 'is_blocked' => 1], ['id' => $legacy]);
    verify(ERM_Request_Logger::is_blocked('LEGACY-POLICY.EXAMPLE.TEST'));
    $canonical = seed('legacy-policy.example.test', 'POST');
    verify((int) ERM_Database::get_request_detail($canonical)->is_blocked === 1);
    verify(ERM_Database::update_request_blocked($canonical, false) === 2);
    verify(!ERM_Request_Logger::is_blocked('legacy-policy.example.test.'));
    verify(ERM_Database::update_rate_limit($legacy, 60, 2) === 2);
    verify(!ERM_Request_Logger::check_rate_limit('legacy-policy.example.test.'));
    verify(!ERM_Request_Logger::check_rate_limit('legacy-policy.example.test'));
    verify(ERM_Request_Logger::check_rate_limit('legacy-policy.example.test.'));
    ERM_Database::update_rate_limit($canonical, 0);
    verify(get_transient('erm_rate_limit_' . md5('legacy-policy.example.test')) === false);
});
scenario('earlier short-circuit responses and errors remain unchanged', function() {
    reset_logs();
    $response = ['body' => 'cache'];
    verify(ERM_Request_Logger::intercept_request($response, ['method' => 'GET'], 'https://api.example.test/') === $response);
    $error = new WP_Error('cache-error');
    verify(ERM_Request_Logger::intercept_request($error, [], 'https://api.example.test/') === $error);
    verify(ERM_Database::count_by_status()['total'] === 0);
});
scenario('local IPv4, IPv6 and normalized site hosts are ignored', function() {
    reset_logs();
    foreach (['http://LOCALHOST/', 'http://127.0.0.1/', 'http://[::1]/', home_url('/')] as $url) {
        verify(ERM_Request_Logger::intercept_request(false, ['method' => 'GET'], $url) === false);
    }
    verify(ERM_Database::count_by_status()['total'] === 0);
});
scenario('rate window honors calls and never permanently blocks', function() {
    reset_logs();
    $id = seed();
    ERM_Database::update_rate_limit($id, 60, 2);
    verify(!ERM_Request_Logger::check_rate_limit('api.example.test'));
    verify(!ERM_Request_Logger::check_rate_limit('api.example.test'));
    verify(ERM_Request_Logger::check_rate_limit('api.example.test'));
    verify(!ERM_Request_Logger::is_blocked('api.example.test'));
    set_transient('erm_rate_limit_' . md5('api.example.test'), ['start' => microtime(true) - 61, 'count' => 2], 60);
    verify(!ERM_Request_Logger::check_rate_limit('api.example.test'));
    ERM_Database::update_rate_limit($id, 0);
    verify(get_transient('erm_rate_limit_' . md5('api.example.test')) === false);
});
scenario('HTTP API records real POST response code, body, and timing', function() {
    reset_logs();
    update_option('erm_pro_max_response_body_length', 100);
    $response = wp_remote_post('http://127.0.0.2:33078/?code=201&body=post-response',
        ['body' => ['field' => 'value'], 'timeout' => 3]);
    verify(!is_wp_error($response), is_wp_error($response) ? $response->get_error_message() : '');
    $row = ERM_Database::get_requests()['data'][0];
    $detail = ERM_Database::get_request_detail($row->id);
    verify($detail->request_method === 'POST');
    verify((int) $detail->response_code === 201);
    verify($detail->response_body === 'post-response');
    verify((float) $detail->response_time > 0);
});
scenario('HTTP errors with no status clear stale response data', function() {
    reset_logs();
    wp_remote_get('http://127.0.0.2:33078/?body=old', ['timeout' => 3]);
    $response = wp_remote_get('http://127.0.0.2:9/', ['timeout' => 1]);
    verify(is_wp_error($response));
    $detail = ERM_Database::get_request_detail(ERM_Database::get_requests()['data'][0]->id);
    verify($detail->response_code === null);
    verify($detail->response_body !== 'old');
});
scenario('nested response cannot overwrite a newer aggregate request', function() {
    reset_logs();
    $nested = function($response, $context, $class, $args, $url) {
        if (strpos($url, 'body=outer') !== false) {
            wp_remote_get('http://127.0.0.2:33078/?body=inner', ['timeout' => 3]);
        }
    };
    add_action('http_api_debug', $nested, 5, 5);
    wp_remote_get('http://127.0.0.2:33078/?body=outer', ['timeout' => 3]);
    remove_action('http_api_debug', $nested, 5);
    $detail = ERM_Database::get_request_detail(ERM_Database::get_requests()['data'][0]->id);
    verify(strpos($detail->url_example, 'body=inner') !== false);
    verify($detail->response_body === 'inner');
    verify((int) $detail->request_count === 2);
});
scenario('body byte limit handles multibyte content and tiny limits', function() {
    foreach ([1, 2, 3, 4, 7, 10, 20] as $length) {
        $body = ERM_Request_Logger::truncate_body(str_repeat('é🙂', 50), $length);
        verify(strlen($body) <= $length);
        verify(wp_check_invalid_utf8($body) === $body);
    }
});
scenario('zero byte limit clears a previous stored body and zero text is retained', function() {
    reset_logs();
    update_option('erm_pro_max_response_body_length', 100);
    // The bundled Requests parser treats the HTTP body '0' as empty.
    // Verify preservation when WordPress delivers it to our response hook.
    $url = 'http://127.0.0.2:33078/?body=0';
    $args = ERM_Request_Logger::track_request_args(['method' => 'GET'], $url);
    ERM_Request_Logger::intercept_request(false, $args, $url);
    ERM_Request_Logger::capture_response(['body' => '0', 'response' => ['code' => 200]], 'response', '', $args, $url);
    $id = ERM_Database::get_requests()['data'][0]->id;
    verify(ERM_Database::get_request_detail($id)->response_body === '0', 'Stored zero body: ' . var_export(ERM_Database::get_request_detail($id)->response_body, true));
    update_option('erm_pro_max_response_body_length', 0);
    wp_remote_get('http://127.0.0.2:33078/?body=private', ['timeout' => 3]);
    verify(ERM_Database::get_request_detail($id)->response_body === null, 'Stored disabled body: ' . var_export(ERM_Database::get_request_detail($id)->response_body, true));
});
scenario('URL credentials and common secrets are redacted before storage', function() {
    reset_logs();
    $id = ERM_Request_Logger::log_request('api.example.test',
        'https://user:password@api.example.test/path?api_key=secret&safe=ok&access_token=private#fragment');
    $url = ERM_Database::get_request_detail($id)->url_example;
    verify(strpos($url, 'user:password') === false && strpos($url, 'private') === false);
    verify(strpos($url, 'safe=ok') !== false && strpos($url, '#fragment') === false);
});
scenario('URL history is bounded, dense, unique, and enforces reduced limits', function() {
    reset_logs();
    update_option('erm_pro_track_all_urls', true);
    update_option('erm_pro_max_urls_logged', 3);
    for ($i = 1; $i <= 5; ++$i) {
        $id = ERM_Request_Logger::log_request('urls.example.test', 'https://urls.example.test/' . $i);
    }
    verify(count(explode("\n", ERM_Database::get_request_detail($id)->urls_log)) === 3);
    update_option('erm_pro_max_urls_logged', 1);
    ERM_Request_Logger::log_request('urls.example.test', 'https://urls.example.test/5');
    verify(ERM_Database::get_request_detail($id)->urls_log === 'https://urls.example.test/5');
    update_option('erm_pro_track_all_urls', false);
});
scenario('deletion audits blocked state without unblocking first', function() {
    reset_logs();
    $id = seed();
    ERM_Database::update_request_blocked($id, true);
    verify(ERM_Database::bulk_action([$id, $id], 'delete') === 1);
    global $wpdb;
    $row = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . ERM_PRO_TABLE_DELETED);
    verify((int) $row->was_blocked === 1);
    verify((int) $row->deleted_by_user === get_current_user_id());
    verify(!ERM_Database::get_request_detail($id));
});
scenario('audit insert failure preserves the log and its rules', function() {
    reset_logs();
    $id = seed();
    ERM_Database::update_request_blocked($id, true);
    $fault = function($sql) {
        return strpos($sql, 'INSERT INTO') === 0 && strpos($sql, ERM_PRO_TABLE_DELETED) !== false ?
            'INSERT INTO erm_nonexistent_test_table VALUES (1)' : $sql;
    };
    add_filter('query', $fault);
    $result = ERM_Database::delete_request($id);
    remove_filter('query', $fault);
    verify($result === false);
    verify((int) ERM_Database::get_request_detail($id)->is_blocked === 1);
});
scenario('delete failure rolls back the audit record', function() {
    reset_logs();
    $id = seed();
    $fault = function($sql) {
        return strpos($sql, 'DELETE FROM') === 0 && strpos($sql, ERM_PRO_TABLE_REQUESTS) !== false ?
            'DELETE FROM erm_nonexistent_test_table' : $sql;
    };
    add_filter('query', $fault);
    $result = ERM_Database::delete_request($id);
    remove_filter('query', $fault);
    global $wpdb;
    verify($result === false && ERM_Database::get_request_detail($id));
    verify((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . ERM_PRO_TABLE_DELETED) === 0);
});
scenario('retention preserves policy rows and expires audit records in site time', function() {
    reset_logs();
    global $wpdb;
    $table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
    $blocked = seed('blocked.example.test');
    $rate = seed('rate.example.test');
    $allowed = seed('allowed.example.test');
    ERM_Database::update_request_blocked($blocked, true);
    ERM_Database::update_rate_limit($rate, 60);
    $old = gmdate('Y-m-d H:i:s', current_time('timestamp') - 40 * DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare("UPDATE $table SET last_timestamp = %s", $old));
    verify(ERM_Database::cleanup_old_logs(30) === 1);
    verify(ERM_Database::get_request_detail($blocked) && ERM_Database::get_request_detail($rate));
    verify(!ERM_Database::get_request_detail($allowed));
    $audit = $wpdb->prefix . ERM_PRO_TABLE_DELETED;
    $wpdb->query($wpdb->prepare("UPDATE $audit SET deleted_timestamp = %s", $old));
    verify(ERM_Database::cleanup_old_logs(30) === 0);
    verify((int) $wpdb->get_var("SELECT COUNT(*) FROM $audit") === 0);
});
scenario('clear-all also removes legacy soft-deleted rules', function() {
    reset_logs();
    $id = seed();
    ERM_Database::update_request_blocked($id, true);
    ERM_Database::delete_request($id, false);
    verify(ERM_Database::clear_all_logs() === 1);
    verify(ERM_Database::bulk_action([$id], 'restore') === 0);
});
scenario('query sorting is allowlisted and pagination cannot divide by zero', function() {
    reset_logs();
    seed();
    $result = ERM_Database::get_requests(['orderby' => 'id; DROP TABLE bad', 'order' => 'DESC; --',
        'per_page' => 0, 'paged' => -1, 'search' => '0']);
    verify($result['per_page'] === 5 && $result['paged'] === 1);
    verify(ERM_Database::count_by_status()['total'] === 1);
});
scenario('public listing API tolerates malformed array arguments without warnings', function() {
    reset_logs();
    seed();
    set_error_handler(function($severity, $message) { throw new RuntimeException($message); });
    try {
        $result = ERM_Database::get_requests(['search' => 'api', 'search_by' => [],
            'orderby' => [], 'order' => [], 'filter' => [], 'per_page' => [], 'paged' => []]);
        verify($result['total'] === 1 && $result['per_page'] === 5 && $result['paged'] === 1);
    } finally {
        restore_error_handler();
    }
});
scenario('column sanitization retains essential controls and drops malformed values', function() {
    verify(ERM_Settings::sanitize_columns(['count', [], 'unknown', 'count']) === ['host', 'actions', 'count']);
    verify(ERM_Settings::sanitize_per_page(0) === 5);
    verify(ERM_Settings::sanitize_retention_days(999999) === 3650);
});
scenario('AJAX rejects subscribers and malformed IDs', function() {
    $user = get_user_by('login', 'review-subscriber');
    if (!$user) { $user = get_user_by('id', wp_create_user('review-subscriber', 'isolated-test-only', 'subscriber@example.test')); }
    wp_set_current_user($user->ID);
    verify(capture_ajax('clear_logs', ['mode' => 'all'])['success'] === false);
    global $admin;
    wp_set_current_user($admin->ID);
    verify(capture_ajax('bulk_action', ['bulk_action' => 'delete', 'ids' => [['nested']]])['success'] === false);
    verify(capture_ajax('update_rate_limit', ['id' => '99999999', 'interval' => '1'])['success'] === false);
});
scenario('AJAX reports actual deduplicated deletion count', function() {
    reset_logs();
    $id = seed();
    $response = capture_ajax('bulk_action', ['bulk_action' => 'delete', 'ids' => [(string) $id, (string) $id, '999999']]);
    verify($response['success'] && $response['data']['count'] === 1);
});
scenario('AJAX reports audit failures instead of false success', function() {
    reset_logs();
    $id = seed();
    $fault = function($sql) {
        return strpos($sql, 'INSERT INTO') === 0 && strpos($sql, ERM_PRO_TABLE_DELETED) !== false ?
            'INSERT INTO erm_nonexistent_test_table VALUES (1)' : $sql;
    };
    add_filter('query', $fault);
    $response = capture_ajax('bulk_action', ['bulk_action' => 'delete', 'ids' => [(string) $id]]);
    remove_filter('query', $fault);
    verify(!$response['success'] && ERM_Database::get_request_detail($id));
});
scenario('AJAX URL list is a JSON array and response zero text survives', function() {
    reset_logs();
    update_option('erm_pro_track_all_urls', true);
    $id = seed();
    global $wpdb;
    $wpdb->update($wpdb->prefix . ERM_PRO_TABLE_REQUESTS, ['urls_log' => "https://example.test/\n\nhttps://example.test/2", 'response_body' => '0'], ['id' => $id]);
    $response = capture_ajax('get_detail', ['id' => (string) $id]);
    verify($response['data']['response_data'] === '0');
    verify(array_keys($response['data']['urls_list']) === [0, 1]);
});
scenario('all admin templates render without warnings', function() {
    $_GET = [];
    $old = set_error_handler(function($severity, $message) { throw new RuntimeException($message); });
    ob_start();
    try {
        ERM_Admin_Pages::dashboard_page();
        ERM_Admin_Pages::settings_page();
        ERM_Admin_Pages::deleted_page();
        $html = ob_get_contents();
        verify(strpos($html, 'role="dialog"') !== false);
        verify(strpos($html, 'mb_strlen') === false);
    } finally {
        ob_end_clean();
        restore_error_handler();
    }
});


scenario('redirects to blocked hosts are stopped before transport', function() {
    reset_logs();
    $id = seed('blocked-redirect.example.test');
    ERM_Database::update_request_blocked($id, true);
    $url = 'http://127.0.0.2:33078/?redirect=' . rawurlencode('https://blocked-redirect.example.test/');
    $result = wp_remote_get($url, ['timeout' => 3]);
    verify(is_wp_error($result) && strpos($result->get_error_message(), 'blocked') !== false);
});
scenario('redirects honor target-host rate policies', function() {
    reset_logs();
    $id = seed('limited-redirect.example.test');
    ERM_Database::update_rate_limit($id, 60);
    verify(!ERM_Request_Logger::check_rate_limit('limited-redirect.example.test'));
    $url = 'http://127.0.0.2:33078/?redirect=' . rawurlencode('https://limited-redirect.example.test/');
    $result = wp_remote_get($url, ['timeout' => 3]);
    verify(is_wp_error($result) && strpos($result->get_error_message(), 'rate limit') !== false);
});
scenario('same-host redirects finish within a single accepted rate-limited call', function() {
    reset_logs();
    $id = seed('127.0.0.2');
    ERM_Database::update_rate_limit($id, 60);
    $url = 'http://127.0.0.2:33078/?redirect=' . rawurlencode('http://127.0.0.2:33078/?body=redirected');
    $result = wp_remote_get($url, ['timeout' => 3]);
    verify(!is_wp_error($result));
    verify(wp_remote_retrieve_body($result) === 'redirected');
});

reset_logs();
WP_CLI::log(sprintf('%d passed; %d failed', $passed, $failed));
if ($failed) { WP_CLI::halt(1); }
