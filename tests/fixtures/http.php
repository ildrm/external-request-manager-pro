<?php
/** Loopback-only HTTP test fixture; not part of the plugin runtime. */
header('Content-Type: text/plain; charset=utf-8');
if (isset($_GET['redirect'])) {
    header('Location: ' . $_GET['redirect'], true, 302);
    exit;
}
if (isset($_GET['code'])) { http_response_code((int) $_GET['code']); }
echo isset($_GET['body']) ? $_GET['body'] : 'fixture-response';
