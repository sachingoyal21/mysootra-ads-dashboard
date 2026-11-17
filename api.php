<?php
// Read-only JSON for the dashboard. The only write it can trigger is a fresh pull from Meta into our own database.
require __DIR__ . '/lib.php';
require __DIR__ . '/report.php';
require __DIR__ . '/brain.php';

$a = $_GET['a'] ?? 'data';

try {
    migrate();
    if ($a === 'refresh') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { json_out(['error' => 'Use POST.'], 405); exit; }
        if (!token()) { json_out(['error' => 'Setup is not finished.'], 409); exit; }
        $last = last_pull();
        $lastOk = last_pull(true);
        $gap = cfg()['refresh_gap_minutes'] ?? 10;
        $current = (int)kv_get('collector_version', 1) === COLLECTOR_VERSION;
        // A pull that started in the last 5 minutes and has not finished is still running. Do not start a second one.
        if ($last && empty($last['finished_at']) && strtotime($last['started_at']) > time() - 300) {
            json_out(['running' => true, 'started_seconds_ago' => time() - strtotime($last['started_at']), 'message' => 'A pull is already running.']);
            exit;
        }
        // The wait only counts from the last pull that worked. A failed or dead attempt can be retried at once.
        if ($current && $lastOk) {
            $ago = time() - strtotime($lastOk['finished_at'] ?: $lastOk['started_at']);
            if ($ago < $gap * 60) {
                $mins = max(1, (int)round($ago / 60));
                json_out(['skipped' => true, 'minutes_ago' => $mins, 'message' => "Data was pulled $mins " . ($mins === 1 ? 'minute' : 'minutes') . " ago. The next pull is allowed $gap minutes after the last one.", 'last_pull' => $lastOk]);
                exit;
            }
        }
        $r = collect('button');
        json_out($r + ['last_pull' => last_pull()]);
        exit;
    }
    if ($a === 'brain_check') {
        // Health check for the engine. Builds the fact sheet and reports its size. It does not call Claude and shows no key.
        $t0 = microtime(true);
        $f = brain_facts();
        json_out(['ok' => true, 'seconds' => round(microtime(true) - $t0, 2), 'fact_sheet_bytes' => strlen(json_encode($f)), 'live_ads' => count($f['live_ads']), 'ad_sets' => count($f['ad_sets']),
            'stopped_ads' => count($f['ads_stopped_in_last_7_days']), 'organic_posts' => count($f['organic_posts']), 'ads_linked_to_a_post' => count(array_filter($f['live_ads'], fn($x) => $x['post'] !== null)),
            'audiences' => array_map(fn($x) => $x['audience'], $f['ad_sets']), 'account' => $f['account'], 'php_time_limit' => ini_get('max_execution_time'), 'curl' => function_exists('curl_init'), 'sapi' => PHP_SAPI, 'can_finish_request' => function_exists('litespeed_finish_request') ? 'litespeed' : (function_exists('fastcgi_finish_request') ? 'fastcgi' : 'no'),
            'recent_runs' => db()->query('SELECT id, run_at, source, model, ok, tokens_in, tokens_out, cost_usd, message FROM brain_runs ORDER BY id DESC LIMIT 6')->fetchAll(),
            'progress' => kv_get('brain_progress'), 'connection_test' => kv_get('brain_test'), 'worker_seen_seconds_ago' => kv_get('brain_worker_seen') ? time() - (int)kv_get('brain_worker_seen') : null,
            'creative_samples' => db()->query("SELECT name, creative_json FROM ads WHERE status = 'ACTIVE' LIMIT 4")->fetchAll(), 'post_link_samples' => db()->query('SELECT media_id, permalink FROM ig_media ORDER BY posted_at DESC LIMIT 4')->fetchAll()]);
        exit;
    }
    if ($a === 'brain') {
        // AI decisions: the latest run, what the team decided, the log and the scorecard.
        json_out(brain_state());
        exit;
    }
    if ($a === 'brain_run' || $a === 'brain_decide' || $a === 'brain_playbook' || $a === 'brain_test') {
        // These three write to our own database or spend API money, so they need the team PIN.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { json_out(['error' => 'Use POST.'], 405); exit; }
        try {
            if (empty(brain_secret()['pin_hash'])) { json_out(['error' => 'The team PIN is not set yet. Finish the AI setup first.'], 409); exit; }
            if (!pin_ok((string)($_SERVER['HTTP_X_TEAM_PIN'] ?? ''))) { json_out(['error' => 'That PIN is not right.', 'pin' => true], 403); exit; }
            $in = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($in)) $in = [];
            if ($a === 'brain_run') {
                // Only queues the run. The cron worker does the slow part, because this server stops a web request after 2 minutes.
                if ((int)kv_get('brain_worker_seen', 0) < time() - 180) throw new BrainError('The background worker on the server is not running, so the run cannot start. Tell Claude this exact line.');
                brain_start('button');
            }
            elseif ($a === 'brain_test') {
                // At most one connection test a minute.
                if ((int)kv_get('brain_test_at', 0) > time() - 60) throw new BrainError('Wait a minute between connection tests.');
                kv_set('brain_test_at', time());
                @set_time_limit(340);
                brain_test();
            }
            elseif ($a === 'brain_decide') brain_decide($in);
            else {
                $text = trim((string)($in['playbook'] ?? ''));
                if (strlen($text) > 6000) throw new BrainError('Keep the playbook under 6,000 characters.');
                kv_set('brain_playbook', $text);
            }
            json_out(brain_state());
        } catch (BrainError $e) {
            json_out(['error' => $e->getMessage()], 400);
        }
        exit;
    }
    if ($a === 'reports') {
        // Daily reports: which dates can be picked and which reports are already saved.
        json_out(['today' => date('Y-m-d'), 'window' => report_window(), 'has_token' => (bool)token(),
            'snapshot_start' => db()->query('SELECT MIN(day) FROM ig_media_daily')->fetchColumn() ?: null, 'saved' => report_list()]);
        exit;
    }
    if ($a === 'report') {
        $day = (string)($_GET['date'] ?? '');
        $rep = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? report_get($day) : null;
        if (!$rep) { json_out(['error' => 'No saved report for this date.'], 404); exit; }
        json_out(['report' => $rep, 'changed' => report_changed($rep), 'from_saved' => true]);
        exit;
    }
    if ($a === 'report_generate') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { json_out(['error' => 'Use POST.'], 405); exit; }
        $day = (string)($_GET['date'] ?? '');
        if (!report_day_ok($day)) { $w = report_window(); json_out(['error' => 'Pick a date from ' . day_label($w['min']) . ' to ' . day_label($w['max']) . '. Today cannot be picked because the day is still running.'], 400); exit; }
        $saved = report_get($day);
        if ($saved && empty($_GET['force'])) { json_out(['report' => $saved, 'changed' => report_changed($saved), 'from_saved' => true]); exit; }
        if (!token()) { json_out(['error' => 'Setup is not finished.'], 409); exit; }
        try {
            $rep = report_build($day);
        } catch (GraphError $e) {
            json_out(['error' => 'Meta did not answer the reach request. ' . $e->getMessage()], 502); exit;
        }
        report_save($rep);
        json_out(['report' => $rep, 'changed' => false, 'from_saved' => false]);
        exit;
    }
    if ($a === 'report_xlsx') {
        $day = (string)($_GET['date'] ?? '');
        $rep = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? report_get($day) : null;
        if (!$rep) { json_out(['error' => 'No saved report for this date. Generate it first.'], 404); exit; }
        $bytes = report_xlsx($rep);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="MySootra daily report ' . date('j M Y', strtotime($day)) . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        echo $bytes;
        exit;
    }
    if ($a === 'v2') {
        // Extra numbers for the new console: daily series, organic posts, account totals and paid engagement.
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $from = date('Y-m-d', strtotime('-45 days'));
        $days = [];
        $st = db()->prepare('SELECT day, SUM(spend) AS spend, SUM(visits) AS visits, SUM(follows) AS follows, SUM(impressions) AS impressions FROM ad_daily WHERE day >= ? GROUP BY day ORDER BY day');
        $st->execute([$from]);
        foreach ($st as $r) $days[$r['day']] = ['date' => $r['day'], 'spend' => (float)$r['spend'], 'visits' => (int)$r['visits'], 'paid_follows' => (int)$r['follows'], 'impressions' => (int)$r['impressions'], 'new_followers' => null];
        $st = db()->prepare('SELECT day, new_followers FROM ig_daily WHERE day >= ? ORDER BY day');
        $st->execute([$from]);
        foreach ($st as $r) {
            if (!isset($days[$r['day']])) $days[$r['day']] = ['date' => $r['day'], 'spend' => 0, 'visits' => 0, 'paid_follows' => 0, 'impressions' => 0, 'new_followers' => null];
            $days[$r['day']]['new_followers'] = (int)$r['new_followers'];
        }
        ksort($days);
        $reportedFrom = null;
        foreach ($days as $d) { if ($d['paid_follows'] >= 50) { $reportedFrom = $d['date']; break; } }
        $map = ['post_reaction' => 'reactions', 'comment' => 'comments', 'onsite_conversion.post_save' => 'saves', 'video_view' => 'video_views', 'link_click' => 'link_clicks'];
        $zero = ['reactions' => 0, 'comments' => 0, 'saves' => 0, 'video_views' => 0, 'link_clicks' => 0];
        $eng = ['week' => $zero, 'month' => $zero];
        $adEng = [];
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $st = db()->prepare('SELECT day, ad_id, spend, follows, actions_json FROM ad_daily WHERE day >= ?');
        $st->execute([min($monthStart, $weekStart)]);
        foreach ($st as $r) {
            $id = (string)$r['ad_id'];
            if (!isset($adEng[$id])) $adEng[$id] = $zero + ['spend_since' => 0.0, 'follows_since' => 0];
            if ($reportedFrom && $r['day'] >= $reportedFrom) { $adEng[$id]['spend_since'] += (float)$r['spend']; $adEng[$id]['follows_since'] += (int)$r['follows']; }
            foreach ((json_decode($r['actions_json'] ?: '[]', true) ?: []) as $x) {
                $k = $map[$x['action_type'] ?? ''] ?? null;
                if (!$k) continue;
                $val = (int)($x['value'] ?? 0);
                if ($r['day'] >= $monthStart) { $eng['month'][$k] += $val; $adEng[$id][$k] += $val; }
                if ($r['day'] >= $weekStart) $eng['week'][$k] += $val;
            }
        }
        $media = [];
        foreach (db()->query('SELECT * FROM ig_media ORDER BY posted_at DESC LIMIT 40') as $r) {
            $n = fn($k) => $r[$k] === null ? null : (int)$r[$k];
            $media[] = ['id' => $r['media_id'], 'type' => $r['product_type'] === 'REELS' ? 'Reel' : ($r['media_type'] === 'CAROUSEL_ALBUM' ? 'Carousel' : ($r['media_type'] === 'VIDEO' ? 'Video' : 'Post')),
                'link' => $r['permalink'], 'posted_at' => $r['posted_at'], 'caption' => $r['caption'], 'likes' => (int)$r['likes'], 'comments' => (int)$r['comments'],
                'reach' => $n('reach'), 'views' => $n('views'), 'saved' => $n('saved'), 'shares' => $n('shares'), 'interactions' => $n('interactions'),
                'follows' => $n('follows'), 'profile_visits' => $n('profile_visits'), 'avg_watch_ms' => $n('avg_watch_ms')];
        }
        $f = db()->query('SELECT followers, username FROM ig_followers ORDER BY day DESC LIMIT 1')->fetch();
        json_out([
            'generated_at' => date('c'), 'today' => $today, 'last_pull' => last_pull(true), 'last_attempt' => last_pull(), 'has_token' => (bool)token(),
            'followers_now' => $f ? (int)$f['followers'] : null, 'username' => $f ? $f['username'] : null,
            'goal' => FOLLOWER_GOAL, 'launch' => '2026-12-01', 'plan' => PLAN,
            'follows_reported_from' => $reportedFrom,
            'follower_counts' => array_map(fn($r) => ['date' => $r['day'], 'followers' => (int)$r['followers']], db()->query('SELECT day, followers FROM ig_followers ORDER BY day')->fetchAll()),
            'daily' => array_values($days), 'ig_account' => kv_get('ig_account', new stdClass()), 'media' => $media,
            'paid_engagement' => $eng, 'ad_engagement' => $adEng,
        ]);
        exit;
    }
    if ($a === 'igprobe') {
        // Checking tool. Asks Meta which organic post numbers this token can read. Read only.
        $lastProbe = (int)kv_get('probe_at', 0);
        if ($lastProbe > time() - 60) { json_out(['error' => 'Wait a minute between checks.'], 429); exit; }
        kv_set('probe_at', time());
        $out = [];
        $ig = find_instagram();
        if (!$ig || !$ig['id']) { json_out(['error' => 'No Instagram account is visible to this token.']); exit; }
        $out['account'] = ['username' => $ig['username'], 'followers' => $ig['followers']];
        try {
            $media = graph($ig['id'] . '/media', ['fields' => 'id,media_type,media_product_type,timestamp,like_count,comments_count,permalink', 'limit' => 12])['data'] ?? [];
            $out['media_count_returned'] = count($media);
            $out['media_sample'] = array_slice($media, 0, 4);
            $seen = [];
            foreach ($media as $m) {
                $kind = ($m['media_product_type'] ?? '?') . '/' . ($m['media_type'] ?? '?');
                if (isset($seen[$kind])) continue;
                $seen[$kind] = true;
                foreach (['reach', 'views', 'saved', 'shares', 'total_interactions', 'likes', 'comments', 'follows', 'profile_visits', 'ig_reels_avg_watch_time', 'reposts'] as $metric) {
                    try {
                        $r = graph($m['id'] . '/insights', ['metric' => $metric]);
                        $out['post_insights'][$kind][$metric] = $r['data'][0]['values'][0]['value'] ?? ($r['data'] ?? null);
                    } catch (Throwable $e) {
                        $out['post_insights'][$kind][$metric] = 'ERROR ' . $e->getMessage();
                    }
                }
            }
        } catch (Throwable $e) {
            $out['media_error'] = $e->getMessage();
        }
        $since = strtotime('-7 days'); $until = time();
        foreach ([['follows_and_unfollows', ['breakdown' => 'follow_type']], ['reach', []], ['views', []], ['profile_views', []], ['accounts_engaged', []], ['total_interactions', []]] as [$metric, $more]) {
            try {
                $r = graph($ig['id'] . '/insights', ['metric' => $metric, 'period' => 'day', 'metric_type' => 'total_value', 'since' => $since, 'until' => $until] + $more);
                $out['account_insights_last_7d'][$metric] = $r['data'][0]['total_value'] ?? ($r['data'] ?? null);
            } catch (Throwable $e) {
                $out['account_insights_last_7d'][$metric] = 'ERROR ' . $e->getMessage();
            }
        }
        try {
            $r = graph($ig['id'] . '/insights', ['metric' => 'follower_count', 'period' => 'day', 'since' => strtotime('-20 days'), 'until' => $until]);
            $out['follower_count_daily'] = $r['data'][0]['values'] ?? ($r['data'] ?? null);
        } catch (Throwable $e) {
            $out['follower_count_daily'] = 'ERROR ' . $e->getMessage();
        }
        json_out($out);
        exit;
    }
    if ($a === 'diag') {
        $since = date('Y-m-d', strtotime('-7 days'));
        $st = db()->prepare('SELECT day, actions_json, extra_json, spend, visits, follows FROM ad_daily WHERE day >= ?');
        $st->execute([$since]);
        $types = [];
        $days = [];
        foreach ($st as $r) {
            foreach ((json_decode($r['actions_json'] ?: '[]', true) ?: []) as $x) {
                $t = 'actions.' . ($x['action_type'] ?? '?');
                $types[$t] = ($types[$t] ?? 0) + (float)($x['value'] ?? 0);
            }
            foreach ((json_decode($r['extra_json'] ?: '[]', true) ?: []) as $k => $v) {
                if (is_array($v) && !isset($v['value'])) {
                    foreach ($v as $x) {
                        $t = $k . '.' . ($x['indicator'] ?? $x['action_type'] ?? '?');
                        $types[$t] = ($types[$t] ?? 0) + (float)($x['values'][0]['value'] ?? $x['value'] ?? 0);
                    }
                } else {
                    $types[$k] = ($types[$k] ?? 0) + metric_total($v);
                }
            }
            $d = &$days[$r['day']];
            $d = $d ?? ['spend' => 0, 'visits' => 0, 'follows' => 0];
            $d['spend'] += (float)$r['spend']; $d['visits'] += (int)$r['visits']; $d['follows'] += (int)$r['follows'];
            unset($d);
        }
        ksort($days); ksort($types);
        $counts = [];
        foreach (['ad_daily', 'ads', 'adsets', 'ig_followers', 'ig_media', 'ig_media_daily', 'reports', 'brain_runs', 'brain_decisions', 'content_tags', 'pulls'] as $t) $counts[$t] = (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        json_out(['has_token' => (bool)token(), 'last_pull' => last_pull(), 'counts' => $counts, 'field_errors' => kv_get('field_errors'), 'ig_error' => kv_get('ig_error'), 'by_day_last_7' => $days, 'metric_totals_last_7' => $types, 'first_day' => db()->query('SELECT MIN(day) FROM ad_daily')->fetchColumn()]);
        exit;
    }

    // Default: everything the dashboard needs, in the same row shape the page already understands.
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $plan = PLAN;
    $perAd = function (?string $from) {
        $where = $from ? 'WHERE day >= ?' : '';
        $q = "SELECT a.ad_id AS id, a.name, a.status, a.adset_id, COALESCE(s.name, t.adset_name, '') AS adset_name,
                     COALESCE(t.spend,0) AS spend, COALESCE(t.impressions,0) AS impressions, COALESCE(t.visits,0) AS visits, COALESCE(t.follows,0) AS follows
              FROM ads a
              LEFT JOIN adsets s ON s.adset_id = a.adset_id
              LEFT JOIN (SELECT ad_id, MAX(adset_name) AS adset_name, SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(visits) AS visits, SUM(follows) AS follows
                         FROM ad_daily $where GROUP BY ad_id) t ON t.ad_id = a.ad_id";
        $st = db()->prepare($q);
        $st->execute($from ? [$from] : []);
        $out = [];
        foreach ($st as $r) {
            $spend = (float)$r['spend']; $imp = (int)$r['impressions'];
            $out[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'status' => (string)$r['status'], 'date' => '',
                'adset_id' => (string)$r['adset_id'], 'adset_name' => (string)$r['adset_name'],
                'spend' => $spend, 'impressions' => $imp, 'visits' => (int)$r['visits'], 'follows' => (int)$r['follows'],
                'cpm' => $imp > 0 ? $spend / $imp * 1000 : 0, 'reach' => 0, 'frequency' => 0, 'daily_budget' => 0];
        }
        return $out;
    };

    $st = db()->prepare('SELECT ad_id, day, adset_id, spend, impressions, visits, follows FROM ad_daily WHERE day >= ? AND day < ? ORDER BY day');
    $st->execute([date('Y-m-d', strtotime('-7 days')), $today]);
    $adsDaily = [];
    foreach ($st as $r) $adsDaily[] = ['id' => (string)$r['ad_id'], 'date' => $r['day'], 'adset_id' => (string)$r['adset_id'], 'spend' => (float)$r['spend'], 'impressions' => (int)$r['impressions'], 'visits' => (int)$r['visits'], 'follows' => (int)$r['follows']];

    // Longer history per ad, finished days only. The decision rules need it to know an ad's age and its own best run.
    $st = db()->prepare('SELECT ad_id, day, spend, impressions, visits, follows FROM ad_daily WHERE day >= ? AND day < ? AND spend > 0 ORDER BY day');
    $st->execute([date('Y-m-d', strtotime('-45 days')), $today]);
    $adsHist = [];
    foreach ($st as $r) $adsHist[] = ['id' => (string)$r['ad_id'], 'date' => $r['day'], 'spend' => (float)$r['spend'], 'impressions' => (int)$r['impressions'], 'visits' => (int)$r['visits'], 'follows' => (int)$r['follows']];

    $st = db()->prepare('SELECT day, SUM(spend) AS spend, SUM(visits) AS visits, SUM(follows) AS follows, SUM(impressions) AS impressions FROM ad_daily WHERE day >= ? GROUP BY day ORDER BY day');
    $st->execute([$monthStart]);
    $monthDays = [];
    foreach ($st as $r) $monthDays[] = ['date' => $r['day'], 'spend' => (float)$r['spend'], 'visits' => (int)$r['visits'], 'follows' => (int)$r['follows'], 'impressions' => (int)$r['impressions']];

    $st = db()->prepare('SELECT COALESCE(SUM(spend),0) FROM ad_daily WHERE day >= ? AND day <= ?');
    $st->execute([$plan['start'], $plan['end']]);
    $planSpend = (float)$st->fetchColumn();

    // When each ad set's budget was last raised, read from the budget saved at every pull. Needed for the 2-day wait between raises.
    $raisedOn = []; $prevBudget = [];
    foreach (db()->query("SELECT pulled_at, entity_id, daily_budget FROM snapshots WHERE kind='adset' AND daily_budget IS NOT NULL ORDER BY pulled_at, id") as $r) {
        $k = (string)$r['entity_id']; $b = (float)$r['daily_budget'];
        if (isset($prevBudget[$k]) && $b > $prevBudget[$k] + 0.5) $raisedOn[$k] = ['date' => substr($r['pulled_at'], 0, 10), 'from' => $prevBudget[$k], 'to' => $b];
        $prevBudget[$k] = $b;
    }
    $adsets = [];
    foreach (db()->query('SELECT adset_id, name, status, daily_budget FROM adsets') as $r) {
        $adsets[] = ['id' => (string)$r['adset_id'], 'name' => (string)$r['name'], 'status' => (string)$r['status'], 'daily_budget' => (float)$r['daily_budget'], 'spend' => 0, 'impressions' => 0, 'visits' => 0, 'follows' => 0, 'raised' => $raisedOn[(string)$r['adset_id']] ?? null];
    }

    $followers = [];
    foreach (db()->query('SELECT day, followers FROM ig_followers ORDER BY day') as $r) $followers[] = ['date' => $r['day'], 'followers' => (int)$r['followers']];

    json_out([
        'generated_at' => date('c'),
        'last_pull' => last_pull(true),
        'last_attempt' => last_pull(),
        'has_token' => (bool)token(),
        'plan' => $plan,
        'ig_error' => kv_get('ig_error'),
        'datasets' => [
            'ads_all' => $perAd(null),
            'ads_daily' => $adsDaily,
            'ads_hist' => $adsHist,
            'ads_today' => array_map(fn($r) => ['id' => (string)$r['ad_id'], 'spend' => (float)$r['spend'], 'visits' => (int)$r['visits'], 'follows' => (int)$r['follows']], (function () use ($today) { $st = db()->prepare('SELECT ad_id, spend, visits, follows FROM ad_daily WHERE day = ?'); $st->execute([$today]); return $st->fetchAll(); })()),
            'ads_week' => kv_get('ads_week', []),
            'adsets' => $adsets,
            'budget_spend' => [['spend' => $planSpend]],
            'month_ads' => $perAd($monthStart),
            'month_days' => $monthDays,
            'month_total' => kv_get('month_total', [['reach' => 0]]),
            'ig_followers' => $followers,
        ],
    ]);
} catch (Throwable $e) {
    json_out(['error' => 'The server could not read the data. ' . $e->getMessage()], 500);
}
