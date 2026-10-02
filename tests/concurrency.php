<?php
/** Separate PHP workers exercise database aggregation and quota serialization. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || ($args[0] ?? '') !== DB_NAME ||
    strpos($wpdb->prefix, 'ermtest_') !== 0) {
    throw new RuntimeException('Disposable test database required.');
}
require_once dirname(__DIR__) . '/external-request-manager.php';
if (!ERM_Database::upgrade()) { throw new RuntimeException('Schema setup failed'); }
function run_workers($mode) {
    $workers = [];
    $cli = Phar::running(false);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' eval-file ' .
        escapeshellarg(__DIR__ . '/concurrency-worker.php') . ' ' . escapeshellarg(DB_NAME) . ' ' .
        escapeshellarg($mode) . ' --path=' . escapeshellarg(ABSPATH) . ' --url=http://erm-review.test';
    for ($i = 0; $i < 4; ++$i) {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Worker launch failed'); }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $total = 0;
    foreach ($workers as $worker) {
        $output = stream_get_contents($worker[1][1]);
        $errors = stream_get_contents($worker[1][2]);
        fclose($worker[1][1]);
        fclose($worker[1][2]);
        if (proc_close($worker[0]) !== 0) { throw new RuntimeException('Worker failed: ' . $errors . $output); }
        if (!preg_match('/(?:logged|accepted)=(\d+)/', $output, $match)) {
            throw new RuntimeException('Unexpected worker output: ' . $output);
        }
        $total += (int) $match[1];
    }
    return $total;
}
$table = $wpdb->prefix . ERM_PRO_TABLE_REQUESTS;
$wpdb->delete($table, ['host' => 'concurrent.example.test']);
if (run_workers('log') !== 100) { throw new RuntimeException('Worker count mismatch'); }
$count = (int) $wpdb->get_var($wpdb->prepare("SELECT request_count FROM $table WHERE host = %s", 'concurrent.example.test'));
if ($count !== 100) { throw new RuntimeException('Concurrent request increments were lost: ' . $count); }
WP_CLI::log('PASS four PHP workers aggregate all 100 requests without lost counts');
$id = ERM_Request_Logger::log_request('concurrent-rate.example.test', 'https://concurrent-rate.example.test/');
ERM_Database::update_rate_limit($id, 60, 3);
if (run_workers('rate') !== 3) { throw new RuntimeException('Concurrent workers exceeded the quota'); }
WP_CLI::log('PASS four PHP workers share a quota of exactly three accepted calls across 40 attempts');
$wpdb->delete($table, ['host' => 'concurrent.example.test']);
$wpdb->delete($table, ['host' => 'concurrent-rate.example.test']);
