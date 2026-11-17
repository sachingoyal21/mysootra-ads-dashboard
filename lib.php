<?php
// MySootra Ads Command Center. Shared code for the collector, the API and setup.
// This app only READS from Meta. It never changes an ad, a budget or a status.

date_default_timezone_set('Asia/Kolkata');

// Raise this number when the way numbers are read changes. The next pull then reloads all history.
const COLLECTOR_VERSION = 3;
// Raise this number when a table gets a new column.
const SCHEMA_VERSION = 2;
// How many recent Instagram posts are read and saved at every pull.
const MEDIA_LIMIT = 60;
// Ad budget plan. ₹25,000 for the whole of November 2026.
const PLAN = ['start' => '2026-11-01', 'end' => '2026-11-30', 'budget' => 25000];
// Follower target for launch day.
const FOLLOWER_GOAL = 30000;
// Extra Meta fields. results carries profile visits. instagram_profile_follow carries follows credited to ads.
const OPTIONAL_FIELDS = ['results', 'instagram_profile_follow'];

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $path = getenv('MS_CONFIG') ?: __DIR__ . '/private/config.php';
        $c = require $path;
    }
    return $c;
}

function token_path(): string {
    return getenv('MS_TOKEN') ?: __DIR__ . '/private/token.php';
}

function token(): ?string {
    $f = token_path();
    if (!is_file($f)) return null;
    $t = require $f;
    return is_string($t) && $t !== '' ? $t : null;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg()['db'];
        $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function is_mysql(): bool {
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function migrate(): void {
    $auto = is_mysql() ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = is_mysql() ? 'MEDIUMTEXT' : 'TEXT';
    $tail = is_mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $sql = [
        "CREATE TABLE IF NOT EXISTS ad_daily (
            day DATE NOT NULL, ad_id VARCHAR(32) NOT NULL, ad_name VARCHAR(255), adset_id VARCHAR(32), adset_name VARCHAR(255),
            campaign_id VARCHAR(32), campaign_name VARCHAR(255),
            spend DECIMAL(12,2) NOT NULL DEFAULT 0, impressions INT NOT NULL DEFAULT 0, reach INT NOT NULL DEFAULT 0,
            visits INT NOT NULL DEFAULT 0, follows INT NOT NULL DEFAULT 0,
            actions_json $text, extra_json $text, updated_at DATETIME NOT NULL,
            PRIMARY KEY (day, ad_id))$tail",
        "CREATE TABLE IF NOT EXISTS ads (
            ad_id VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(255), status VARCHAR(40), created_time VARCHAR(40),
            adset_id VARCHAR(32), campaign_id VARCHAR(32), updated_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS adsets (
            adset_id VARCHAR(32) NOT NULL PRIMARY KEY, name VARCHAR(255), status VARCHAR(40), daily_budget DECIMAL(12,2) NOT NULL DEFAULT 0,
            campaign_id VARCHAR(32), updated_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS snapshots (
            id $auto, pulled_at DATETIME NOT NULL, kind VARCHAR(10) NOT NULL, entity_id VARCHAR(32) NOT NULL,
            status VARCHAR(40), daily_budget DECIMAL(12,2))$tail",
        "CREATE TABLE IF NOT EXISTS ig_followers (
            day DATE NOT NULL PRIMARY KEY, followers INT NOT NULL, username VARCHAR(100), updated_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS ig_followers_log (
            id $auto, pulled_at DATETIME NOT NULL, followers INT NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS pulls (
            id $auto, started_at DATETIME NOT NULL, finished_at DATETIME, ok INT NOT NULL DEFAULT 0,
            source VARCHAR(20), day_rows INT NOT NULL DEFAULT 0, message $text)$tail",
        "CREATE TABLE IF NOT EXISTS ig_daily (
            day DATE NOT NULL PRIMARY KEY, new_followers INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS ig_media (
            media_id VARCHAR(40) NOT NULL PRIMARY KEY, product_type VARCHAR(20), media_type VARCHAR(30), permalink VARCHAR(255),
            posted_at DATETIME, caption VARCHAR(255), likes INT NOT NULL DEFAULT 0, comments INT NOT NULL DEFAULT 0,
            reach INT, views INT, saved INT, shares INT, interactions INT, follows INT, profile_visits INT, avg_watch_ms INT,
            updated_at DATETIME NOT NULL)$tail",
        "CREATE TABLE IF NOT EXISTS kv (
            k VARCHAR(64) NOT NULL PRIMARY KEY, v $text, updated_at DATETIME NOT NULL)$tail",
        // One row per post per day. The last pull of the day wins, so each row is the post's lifetime numbers at the end of that day.
        "CREATE TABLE IF NOT EXISTS ig_media_daily (
            day DATE NOT NULL, media_id VARCHAR(40) NOT NULL, likes INT, comments INT, reach INT, views INT, saved INT, shares INT,
            reposts INT, follows INT, updated_at DATETIME NOT NULL,
            PRIMARY KEY (day, media_id))$tail",
        // Daily reports that were generated once and are served again without rebuilding.
        "CREATE TABLE IF NOT EXISTS reports (
            day DATE NOT NULL PRIMARY KEY, generated_at DATETIME NOT NULL, fingerprint VARCHAR(64) NOT NULL, data_json $text)$tail",
    ];
    foreach ($sql as $q) db()->exec($q);
    if ((int)kv_get('schema_version', 1) < SCHEMA_VERSION) {
        foreach ([['ad_daily', 'clicks', 'INT NOT NULL DEFAULT 0'], ['ig_media', 'reposts', 'INT']] as [$t, $c, $def]) {
            try { db()->exec("ALTER TABLE $t ADD COLUMN $c $def"); } catch (Throwable $e) { /* the column is already there */ }
        }
        kv_set('schema_version', SCHEMA_VERSION);
    }
}

function upsert(string $table, array $row, array $keys): void {
    $cols = array_keys($row);
    $place = implode(',', array_fill(0, count($cols), '?'));
    $set = [];
    foreach ($cols as $c) {
        if (in_array($c, $keys, true)) continue;
        $set[] = is_mysql() ? "$c=VALUES($c)" : "$c=excluded.$c";
    }
    $q = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($place) ";
    $q .= is_mysql() ? 'ON DUPLICATE KEY UPDATE ' : 'ON CONFLICT(' . implode(',', $keys) . ') DO UPDATE SET ';
    $q .= implode(',', $set);
    db()->prepare($q)->execute(array_values($row));
}

function kv_set(string $k, $v): void {
    upsert('kv', ['k' => $k, 'v' => json_encode($v), 'updated_at' => date('Y-m-d H:i:s')], ['k']);
}

function kv_get(string $k, $default = null) {
    $st = db()->prepare('SELECT v FROM kv WHERE k=?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

// ---------- Meta Graph API (read only) ----------

class GraphError extends Exception {}

function http_get_json(string $url): array {
    $mock = getenv('MS_GRAPH_MOCK');
    if ($mock) {
        $fn = require $mock;
        $j = $fn($url);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new GraphError('Could not reach Meta. ' . $err);
        $j = json_decode($body, true);
    }
    if (!is_array($j)) throw new GraphError('Meta sent an answer that could not be read.');
    if (isset($j['error'])) {
        $e = $j['error'];
        throw new GraphError('Meta error ' . ($e['code'] ?? '') . '. ' . ($e['message'] ?? 'Unknown error'));
    }
    return $j;
}

function graph(string $path, array $params = [], ?string $tok = null): array {
    $params['access_token'] = $tok ?? token();
    if (!$params['access_token']) throw new GraphError('No Meta token is saved yet.');
    $url = 'https://graph.facebook.com/' . cfg()['api_version'] . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    return http_get_json($url);
}

function graph_all(string $path, array $params = []): array {
    $out = [];
    $j = graph($path, $params);
    for ($i = 0; $i < 60; $i++) {
        foreach (($j['data'] ?? []) as $r) $out[] = $r;
        $next = $j['paging']['next'] ?? null;
        if (!$next) break;
        $j = http_get_json($next);
    }
    return $out;
}

function insights(string $level, array $params, string $fields): array {
    $acct = 'act_' . cfg()['ad_account_id'];
    return graph_all("$acct/insights", $params + ['level' => $level, 'fields' => $fields, 'limit' => 500]);
}

function range_param(string $since, string $until): string {
    return json_encode(['since' => $since, 'until' => $until]);
}

// Turns any Meta metric shape (number, {value}, list of actions, list of results) into one number.
function metric_total($v, ?string $pattern = null, bool $useMax = false): float {
    if ($v === null) return 0.0;
    if (!is_array($v)) return (float)$v;
    if (isset($v['value'])) return (float)$v['value'];
    $vals = [];
    foreach ($v as $x) {
        if (!is_array($x)) continue;
        $label = (string)($x['action_type'] ?? $x['indicator'] ?? '');
        if ($pattern !== null && !preg_match($pattern, $label)) continue;
        if (isset($x['values'][0]['value'])) $vals[] = (float)$x['values'][0]['value'];
        elseif (isset($x['value'])) $vals[] = (float)$x['value'];
    }
    if (!$vals) return 0.0;
    return $useMax ? max($vals) : array_sum($vals);
}

function has_label($v, string $pattern): bool {
    if (!is_array($v)) return false;
    foreach ($v as $x) {
        if (is_array($x) && preg_match($pattern, (string)($x['action_type'] ?? $x['indicator'] ?? ''))) return true;
    }
    return false;
}

function pick_visits(array $actions, array $extra): int {
    $p = '/profile_visit/i';
    if (isset($extra['results'])) {
        if (has_label($extra['results'], $p)) return (int)round(metric_total($extra['results'], $p));
    }
    if (has_label($actions, $p)) return (int)round(metric_total($actions, $p, true));
    if (isset($extra['results'])) return (int)round(metric_total($extra['results']));
    return 0;
}

function pick_follows(array $actions, array $extra): int {
    foreach ($extra as $k => $v) {
        if (preg_match('/follow/i', $k)) return (int)round(metric_total($v));
    }
    $p = '/(?<!un)follow/i';
    return has_label($actions, $p) ? (int)round(metric_total($actions, $p, true)) : 0;
}

// ---------- Collector ----------

function collect(string $source = 'cron'): array {
    @set_time_limit(280);
    migrate();
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $pdo = db();
    $pdo->prepare('INSERT INTO pulls (started_at, source) VALUES (?, ?)')->execute([$now, $source]);
    $pullId = (int)$pdo->lastInsertId();
    $notes = [];
    $dayRows = 0;
    $ok = 0;
    try {
        $acct = 'act_' . cfg()['ad_account_id'];

        // 1. Ad sets and ads, with status and budget.
        $sets = graph_all("$acct/adsets", ['fields' => 'id,name,effective_status,daily_budget,campaign_id', 'limit' => 200]);
        foreach ($sets as $s) {
            $budget = isset($s['daily_budget']) ? ((float)$s['daily_budget']) / 100 : 0;
            upsert('adsets', ['adset_id' => $s['id'], 'name' => $s['name'] ?? '', 'status' => $s['effective_status'] ?? '', 'daily_budget' => $budget, 'campaign_id' => $s['campaign_id'] ?? '', 'updated_at' => $now], ['adset_id']);
            $pdo->prepare('INSERT INTO snapshots (pulled_at, kind, entity_id, status, daily_budget) VALUES (?,?,?,?,?)')->execute([$now, 'adset', $s['id'], $s['effective_status'] ?? '', $budget]);
        }
        $ads = graph_all("$acct/ads", ['fields' => 'id,name,effective_status,created_time,adset_id,campaign_id', 'limit' => 200]);
        $known = [];
        foreach ($ads as $a) {
            $known[$a['id']] = true;
            upsert('ads', ['ad_id' => $a['id'], 'name' => $a['name'] ?? '', 'status' => $a['effective_status'] ?? '', 'created_time' => $a['created_time'] ?? '', 'adset_id' => $a['adset_id'] ?? '', 'campaign_id' => $a['campaign_id'] ?? '', 'updated_at' => $now], ['ad_id']);
            $pdo->prepare('INSERT INTO snapshots (pulled_at, kind, entity_id, status, daily_budget) VALUES (?,?,?,?,NULL)')->execute([$now, 'ad', $a['id'], $a['effective_status'] ?? '']);
        }

        // 2. Daily numbers per ad. First run loads all history, later runs refresh the last 7 days.
        $have = (int)$pdo->query('SELECT COUNT(*) FROM ad_daily')->fetchColumn();
        $full = $have === 0 || (int)kv_get('collector_version', 1) !== COLLECTOR_VERSION;
        $since = $full ? cfg()['history_start'] : date('Y-m-d', strtotime('-7 days'));
        $chunks = [];
        for ($s = strtotime($since); $s <= strtotime($today); $s += 30 * 86400) {
            $chunks[] = [date('Y-m-d', $s), date('Y-m-d', min(strtotime($today), $s + 29 * 86400))];
        }
        $optional = OPTIONAL_FIELDS;
        $fieldErrors = [];
        foreach ($chunks as [$a, $b]) {
            $p = ['time_increment' => 1, 'time_range' => range_param($a, $b)];
            $rows = insights('ad', $p, 'ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,spend,impressions,reach,clicks,actions');
            $extra = [];
            foreach ($optional as $f) {
                if (isset($fieldErrors[$f])) continue;
                try {
                    foreach (insights('ad', $p, 'ad_id,' . $f) as $r) {
                        if (isset($r[$f])) $extra[$r['date_start'] . '|' . $r['ad_id']][$f] = $r[$f];
                    }
                } catch (GraphError $e) {
                    $fieldErrors[$f] = $e->getMessage();
                }
            }
            foreach ($rows as $r) {
                $key = $r['date_start'] . '|' . $r['ad_id'];
                $actions = $r['actions'] ?? [];
                $ex = $extra[$key] ?? [];
                upsert('ad_daily', [
                    'day' => $r['date_start'], 'ad_id' => $r['ad_id'], 'ad_name' => $r['ad_name'] ?? '',
                    'adset_id' => $r['adset_id'] ?? '', 'adset_name' => $r['adset_name'] ?? '',
                    'campaign_id' => $r['campaign_id'] ?? '', 'campaign_name' => $r['campaign_name'] ?? '',
                    'spend' => (float)($r['spend'] ?? 0), 'impressions' => (int)($r['impressions'] ?? 0), 'reach' => (int)($r['reach'] ?? 0), 'clicks' => (int)($r['clicks'] ?? 0),
                    'visits' => pick_visits($actions, $ex), 'follows' => pick_follows($actions, $ex),
                    'actions_json' => json_encode($actions), 'extra_json' => json_encode($ex), 'updated_at' => $now,
                ], ['day', 'ad_id']);
                $dayRows++;
                if (!isset($known[$r['ad_id']])) {
                    $known[$r['ad_id']] = true;
                    $st = $pdo->prepare('SELECT COUNT(*) FROM ads WHERE ad_id=?');
                    $st->execute([$r['ad_id']]);
                    if (!(int)$st->fetchColumn()) {
                        upsert('ads', ['ad_id' => $r['ad_id'], 'name' => $r['ad_name'] ?? '', 'status' => 'ARCHIVED', 'created_time' => '', 'adset_id' => $r['adset_id'] ?? '', 'campaign_id' => $r['campaign_id'] ?? '', 'updated_at' => $now], ['ad_id']);
                    }
                }
            }
        }
        kv_set('field_errors', $fieldErrors);
        if (!$fieldErrors) kv_set('collector_version', COLLECTOR_VERSION);
        if ($fieldErrors) $notes[] = 'Some optional Meta fields were not available: ' . implode(', ', array_keys($fieldErrors)) . '.';

        // 3. Numbers that cannot be added up from days (unique people).
        try {
            $week = insights('ad', ['date_preset' => 'last_7d'], 'ad_id,reach,frequency');
            kv_set('ads_week', array_map(fn($r) => ['id' => $r['ad_id'], 'reach' => (int)($r['reach'] ?? 0), 'frequency' => (float)($r['frequency'] ?? 0)], $week));
            $month = insights('account', ['date_preset' => 'this_month'], 'reach,frequency');
            kv_set('month_total', [['reach' => (int)($month[0]['reach'] ?? 0), 'frequency' => (float)($month[0]['frequency'] ?? 0)]]);
        } catch (GraphError $e) {
            $notes[] = 'Reach numbers failed. ' . $e->getMessage();
        }

        // 4. Instagram follower count for the page.
        $ig = null;
        try {
            $ig = find_instagram();
            if ($ig) {
                upsert('ig_followers', ['day' => $today, 'followers' => $ig['followers'], 'username' => $ig['username'], 'updated_at' => $now], ['day']);
                $pdo->prepare('INSERT INTO ig_followers_log (pulled_at, followers) VALUES (?,?)')->execute([$now, $ig['followers']]);
                kv_set('ig_error', null);
            } else {
                kv_set('ig_error', 'No Instagram account is visible to this token.');
                $notes[] = 'Instagram follower count is not available to this token yet.';
            }
        } catch (GraphError $e) {
            kv_set('ig_error', $e->getMessage());
            $notes[] = 'Instagram follower count failed. ' . $e->getMessage();
        }
        // 5. Organic Instagram numbers: new followers by day, account totals and each recent post.
        if (!empty($ig['id'])) {
            try {
                $notes = array_merge($notes, collect_organic($ig['id'], $now));
            } catch (Throwable $e) {
                $notes[] = 'Organic numbers failed. ' . $e->getMessage();
            }
        }
        $ok = 1;
    } catch (Throwable $e) {
        $notes[] = 'Pull failed. ' . $e->getMessage();
    }
    $msg = implode(' ', $notes);
    $pdo->prepare('UPDATE pulls SET finished_at=?, ok=?, day_rows=?, message=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $ok, $dayRows, $msg, $pullId]);
    return ['ok' => (bool)$ok, 'day_rows' => $dayRows, 'message' => $msg];
}

function find_instagram(): ?array {
    $want = strtolower(cfg()['ig_username_contains'] ?? '');
    $found = [];
    $tries = [
        ['act_' . cfg()['ad_account_id'] . '/instagram_accounts', ['fields' => 'id,username,followers_count', 'limit' => 50], null],
        ['me/accounts', ['fields' => 'instagram_business_account{id,username,followers_count}', 'limit' => 50], 'instagram_business_account'],
    ];
    $lastError = null;
    foreach ($tries as [$path, $params, $nest]) {
        try {
            foreach (graph_all($path, $params) as $r) {
                $x = $nest ? ($r[$nest] ?? null) : $r;
                if (is_array($x) && isset($x['followers_count'])) $found[] = $x;
            }
        } catch (GraphError $e) {
            $lastError = $e;
        }
        if ($found) break;
    }
    if (!$found) {
        if ($lastError) throw $lastError;
        return null;
    }
    foreach ($found as $x) {
        if ($want !== '' && strpos(strtolower($x['username'] ?? ''), $want) !== false) return ['id' => $x['id'] ?? '', 'followers' => (int)$x['followers_count'], 'username' => $x['username'] ?? ''];
    }
    return ['id' => $found[0]['id'] ?? '', 'followers' => (int)$found[0]['followers_count'], 'username' => $found[0]['username'] ?? ''];
}

function insight_values(array $resp): array {
    $out = [];
    foreach (($resp['data'] ?? []) as $m) {
        $v = $m['total_value']['value'] ?? ($m['values'][0]['value'] ?? null);
        if ($v !== null && !is_array($v)) $out[$m['name']] = (int)$v;
    }
    return $out;
}

function collect_organic(string $igId, string $now): array {
    $notes = [];
    $pdo = db();
    // New followers for each day. Meta keeps about 30 days and is up to 2 days late.
    try {
        $r = graph("$igId/insights", ['metric' => 'follower_count', 'period' => 'day', 'since' => strtotime('-29 days'), 'until' => time()]);
        $vals = $r['data'][0]['values'] ?? [];
        $n = count($vals);
        foreach ($vals as $i => $v) {
            if (!isset($v['end_time'])) continue;
            $val = (int)($v['value'] ?? 0);
            if ($val === 0 && $i >= $n - 2) continue; // not ready yet
            $day = gmdate('Y-m-d', strtotime($v['end_time']) - 86400);
            upsert('ig_daily', ['day' => $day, 'new_followers' => $val, 'updated_at' => $now], ['day']);
        }
    } catch (GraphError $e) {
        $notes[] = 'Daily new followers failed. ' . $e->getMessage();
    }
    // Account totals for the last 7 days and for this month.
    $windows = ['week' => strtotime('-7 days'), 'month' => strtotime(date('Y-m-01 00:00:00'))];
    $acct = [];
    foreach ($windows as $name => $since) {
        if (time() - $since < 86400) $since = time() - 86400;
        foreach (['reach', 'views', 'profile_views', 'accounts_engaged', 'total_interactions'] as $metric) {
            try {
                $r = graph("$igId/insights", ['metric' => $metric, 'period' => 'day', 'metric_type' => 'total_value', 'since' => $since, 'until' => time()]);
                $acct[$name][$metric] = (int)($r['data'][0]['total_value']['value'] ?? 0);
            } catch (GraphError $e) {
                $acct[$name][$metric] = null;
            }
        }
        try {
            $r = graph("$igId/insights", ['metric' => 'follows_and_unfollows', 'period' => 'day', 'metric_type' => 'total_value', 'breakdown' => 'follow_type', 'since' => $since, 'until' => time()]);
            foreach (($r['data'][0]['total_value']['breakdowns'][0]['results'] ?? []) as $x) {
                $k = ($x['dimension_values'][0] ?? '') === 'FOLLOWER' ? 'follows' : 'unfollows';
                $acct[$name][$k] = (int)($x['value'] ?? 0);
            }
        } catch (GraphError $e) {
            $acct[$name]['follows'] = null;
        }
    }
    kv_set('ig_account', $acct);
    // Recent posts with their numbers. Each post is also saved under today's date, so a day's change can be worked out later.
    $media = graph("$igId/media", ['fields' => 'id,caption,media_type,media_product_type,timestamp,like_count,comments_count,permalink', 'limit' => MEDIA_LIMIT])['data'] ?? [];
    $failed = 0;
    $today = date('Y-m-d');
    $repostsOk = true; $repostMisses = 0;
    foreach ($media as $m) {
        $isReel = ($m['media_product_type'] ?? '') === 'REELS';
        $base = 'reach,views,saved,shares,total_interactions';
        $more = $isReel ? ',ig_reels_avg_watch_time' : ',follows,profile_visits';
        // Ask for the fullest set first. If Meta refuses a metric, fall back to a smaller set.
        $sets = $repostsOk ? [$base . ',reposts' . $more, $base . $more, $base . ',reposts', $base] : [$base . $more, $base];
        $sets[] = 'reach,saved,total_interactions';
        $v = []; $used = '';
        foreach ($sets as $metrics) {
            try { $v = insight_values(graph($m['id'] . '/insights', ['metric' => $metrics])); $used = $metrics; break; } catch (GraphError $e) { $v = []; }
        }
        if (!$v) $failed++;
        if ($repostsOk && $v && strpos($used, 'reposts') === false) {
            if (++$repostMisses >= 3) { $repostsOk = false; $notes[] = 'Meta did not give reposts for posts.'; }
        }
        $cap = trim(preg_replace('/\s+/u', ' ', (string)($m['caption'] ?? '')));
        if (function_exists('mb_substr')) $cap = mb_substr($cap, 0, 90); else $cap = substr($cap, 0, 90);
        $likes = (int)($m['like_count'] ?? 0); $comments = (int)($m['comments_count'] ?? 0);
        upsert('ig_media', [
            'media_id' => $m['id'], 'product_type' => $m['media_product_type'] ?? '', 'media_type' => $m['media_type'] ?? '',
            'permalink' => $m['permalink'] ?? '', 'posted_at' => date('Y-m-d H:i:s', strtotime($m['timestamp'] ?? 'now')), 'caption' => $cap,
            'likes' => $likes, 'comments' => $comments,
            'reach' => $v['reach'] ?? null, 'views' => $v['views'] ?? null, 'saved' => $v['saved'] ?? null, 'shares' => $v['shares'] ?? null,
            'interactions' => $v['total_interactions'] ?? null, 'follows' => $v['follows'] ?? null, 'profile_visits' => $v['profile_visits'] ?? null,
            'avg_watch_ms' => $v['ig_reels_avg_watch_time'] ?? null, 'reposts' => $v['reposts'] ?? null, 'updated_at' => $now,
        ], ['media_id']);
        upsert('ig_media_daily', [
            'day' => $today, 'media_id' => $m['id'], 'likes' => $likes, 'comments' => $comments,
            'reach' => $v['reach'] ?? null, 'views' => $v['views'] ?? null, 'saved' => $v['saved'] ?? null, 'shares' => $v['shares'] ?? null,
            'reposts' => $v['reposts'] ?? null, 'follows' => $v['follows'] ?? null, 'updated_at' => $now,
        ], ['day', 'media_id']);
    }
    if ($failed) $notes[] = "$failed posts returned no insight numbers.";
    return $notes;
}

function last_pull(bool $onlyOk = false): ?array {
    $q = 'SELECT started_at, finished_at, ok, source, day_rows, message FROM pulls ' . ($onlyOk ? 'WHERE ok=1 ' : '') . 'ORDER BY id DESC LIMIT 1';
    $r = db()->query($q)->fetch();
    return $r ?: null;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($data);
}
