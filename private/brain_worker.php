<?php
// Run by a Hostinger cron job every minute. Not reachable from the web.
// A run started from the page is only queued there, because this server stops any web request after 2 minutes
// and Claude can take longer. This script picks the queued run up and finishes it.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/report.php';
require dirname(__DIR__) . '/brain.php';
@set_time_limit(0);
try {
    migrate();
    kv_set('brain_worker_seen', time());
    $id = brain_claim();
    // The daily run before the 5 pm meeting. Off until the team switches it on.
    if (!$id && kv_get('brain_daily_on', false) && date('H:i') >= '16:30' && date('H:i') < '16:40') {
        $st = db()->prepare("SELECT COUNT(*) FROM brain_runs WHERE day = ? AND source = 'daily'");
        $st->execute([date('Y-m-d')]);
        if (!(int)$st->fetchColumn()) { brain_start('daily'); $id = brain_claim(); }
    }
    if (!$id) exit(0);
    brain_finish($id);
    echo date('c') . ' finished run ' . $id . "\n";
} catch (Throwable $e) {
    echo date('c') . ' FAILED ' . $e->getMessage() . "\n";
}
