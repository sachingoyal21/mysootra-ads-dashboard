<?php
// Run by the Hostinger cron job every 3 hours. Not reachable from the web.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/lib.php';
if (!token()) { echo "No Meta token saved yet. Finish setup first.\n"; exit(0); }
$r = collect('cron');
echo date('c') . ' ' . ($r['ok'] ? 'OK' : 'FAILED') . ' rows=' . $r['day_rows'] . ' ' . $r['message'] . "\n";
