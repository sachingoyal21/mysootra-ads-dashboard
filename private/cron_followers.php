<?php
// Run by a Hostinger cron job at minutes 29 and 59 of every hour. Not reachable from the web.
// It does its work once a day, at 11:59 pm India time, and saves the page follower count.
// That reading closes the day, so the page's day matches Meta's day, which runs midnight to midnight.
// Minutes 29 and 59 are both listed because India is 5 hours 30 minutes ahead of UTC. One of them is 11:59 pm here whatever clock the server keeps.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/lib.php';
if (date('H:i') < '23:55') exit(0);
if (!token()) { echo "No Meta token saved yet. Finish setup first.\n"; exit(0); }
$r = collect_followers();
echo date('c') . ' ' . ($r['ok'] ? 'OK followers=' . $r['followers'] : 'FAILED ' . $r['message']) . "\n";
