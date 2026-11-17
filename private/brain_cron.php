<?php
// Run by a Hostinger cron job once a day at 4:30 pm IST, before the 5 pm meeting. Not reachable from the web.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/report.php';
require dirname(__DIR__) . '/brain.php';
try {
    $r = brain_run('cron');
    echo date('c') . ' OK run ' . $r['run_id'] . "\n";
} catch (Throwable $e) {
    echo date('c') . ' FAILED ' . $e->getMessage() . "\n";
}
