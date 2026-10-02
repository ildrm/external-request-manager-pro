<?php
/** Syntax checks under whichever PHP runtime invokes this script. */
$root = dirname(__DIR__);
$files = array_merge(glob($root . '/*.php'), glob($root . '/includes/*.php'),
    glob($root . '/templates/*.php'), glob($root . '/tests/*.php'), glob($root . '/tests/fixtures/*.php'));
foreach ($files as $file) {
    passthru(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file), $status);
    if ($status) { exit($status); }
}
