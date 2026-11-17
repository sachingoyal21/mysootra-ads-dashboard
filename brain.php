<?php
// AI decisions. Code works out the facts, Claude makes the judgement, the team makes the decision.
// The team's final decisions are saved and fed back into the next run, so the calls get closer to how the team thinks.
// Nothing here ever changes an ad, a budget or a status in Meta.

const BRAIN_MODEL = 'claude-opus-5-5';
const BRAIN_RUNS_PER_DAY = 12;
const BRAIN_GAP_MINUTES = 5;
// US dollars per million tokens, used only to show what a run cost.
const BRAIN_PRICE = ['in' => 4.0, 'out' => 20.0];
const PILLARS = ['Ghar', 'Rishte', 'Mann', 'Career', 'Festival and devotional', 'Other'];
const HOOKS = ['Fear', 'Curiosity', 'Worry', 'Timely', 'Other'];
const AD_ACTIONS = ['scale', 'hold', 'cut', 'stop', 'wait'];

class BrainError extends Exception {}

// ---------- Key and team PIN, kept in a private file ----------

function brain_secret_path(): string {
    return getenv('MS_BRAIN') ?: __DIR__ . '/private/brain.php';
}

function brain_secret(): array {
    $f = brain_secret_path();
    $s = is_file($f) ? (require $f) : [];
    return is_array($s) ? $s : [];
}

function brain_secret_save(array $s): bool {
    $ok = @file_put_contents(brain_secret_path(), "<?php\nreturn " . var_export($s, true) . ";\n", LOCK_EX) !== false;
    if ($ok) @chmod(brain_secret_path(), 0600);
    return $ok;
}

// Checks the team PIN. Ten wrong tries in an hour lock it for the rest of that hour.
function pin_ok(string $pin): bool {
    $hash = brain_secret()['pin_hash'] ?? '';
    if ($hash === '') return false;
    $fails = array_values(array_filter((array)kv_get('pin_fails', []), fn($t) => $t > time() - 3600));
    if (count($fails) >= 10) throw new BrainError('Too many wrong PIN tries. Wait an hour.');
    if (password_verify($pin, $hash)) return true;
    $fails[] = time();
    kv_set('pin_fails', $fails);
    return false;
}

// ---------- Playbook: how the team wants calls made, in plain words ----------

function brain_playbook_default(): string {
    return implode("\n", [
        'Goal. Get Instagram followers at the lowest cost inside a fixed budget, and learn which content works so the team can make more of it.',
        'Main number. Cost per follower that Meta credits to the ad, checked against how much the page really grew. Cost per profile visit is a side signal only. Cheap visits with few follows is a bad ad. Costly visits with cheap follows is a good ad.',
        'Scaling. One clearly strong day is enough to raise an ad. Raise by 30 to 50 percent, not double. The cheapest follower source gets money first. The budget sits on the ad set, so a raise goes to every ad in it.',
        'Stopping. Two days of costly followers that are getting worse is enough to stop. Do not wait for a spend minimum. Never stop a good ad on one bad day.',
        'Reference levels, not hard rules. Under about ₹1.20 a follower is good. Above about ₹2.00 is costly.',
        'Budget. The month budget is a target to spend, not a ceiling. Do not leave money unspent while a cheap follower source can take more.',
        'New ads. The team launches about one new ad a day at ₹150 to ₹200. Say which organic post to boost next and why.',
        'Content. Faceless content only. Every ad is also a content test. Say what today\'s numbers suggest about which topics and hooks turn viewers into followers.',
        'Festival ads end on the festival day.',
        'Before launch the only ask is to follow the page. From 1 December the goal changes to app installs.',
    ]);
}

function brain_playbook(): string {
    $p = kv_get('brain_playbook');
    return is_string($p) && trim($p) !== '' ? $p : brain_playbook_default();
}

// ---------- Fact sheet ----------

function r2($v): float { return round((float)$v, 2); }
function per($spend, $n): ?float { return $n > 0 ? round($spend / $n, 2) : null; }

function targeting_summary(?string $json): string {
    $t = $json ? json_decode($json, true) : null;
    if (!is_array($t)) return 'Not read yet';
    $parts = [];
    $geo = $t['geo_locations']['countries'] ?? [];
    if ($geo) $parts[] = 'Countries ' . implode(', ', $geo);
    if (isset($t['age_min']) || isset($t['age_max'])) $parts[] = 'Age ' . ($t['age_min'] ?? '18') . ' to ' . ($t['age_max'] ?? '65+');
    $names = fn($list) => implode(', ', array_slice(array_values(array_filter(array_map(fn($x) => (string)($x['name'] ?? ''), (array)$list))), 0, 6));
    $interests = [];
    foreach (($t['flexible_spec'] ?? []) as $spec) foreach ($spec as $group) if (is_array($group)) foreach ($group as $x) if (!empty($x['name'])) $interests[] = $x['name'];
    if ($interests) $parts[] = 'Interests ' . implode(', ', array_slice(array_unique($interests), 0, 8));
    if (!empty($t['custom_audiences'])) $parts[] = 'Includes ' . $names($t['custom_audiences']);
    if (!empty($t['excluded_custom_audiences'])) $parts[] = 'Excludes ' . $names($t['excluded_custom_audiences']);
    if (!$interests && empty($t['custom_audiences'])) $parts[] = 'Broad';
    $adv = $t['targeting_automation']['advantage_audience'] ?? null;
    if ($adv !== null) $parts[] = 'Advantage+ audience ' . ($adv ? 'on' : 'off');
    return implode('. ', $parts);
}

function shortcode(string $url): string {
    return preg_match('#/(?:p|reel|reels|tv)/([^/?]+)#', $url, $m) ? $m[1] : '';
}

function brain_facts(): array {
    $pdo = db();
    $today = date('Y-m-d');
    $yday = date('Y-m-d', strtotime('-1 day'));
    $d7 = date('Y-m-d', strtotime('-7 days'));
    $d3 = date('Y-m-d', strtotime('-3 days'));
    $from = $pdo->query('SELECT MIN(day) FROM ad_daily WHERE follows >= 20')->fetchColumn() ?: null; // first day Meta credited follows to ads

    // Account.
    $st = $pdo->prepare('SELECT COALESCE(SUM(spend),0) FROM ad_daily WHERE day BETWEEN ? AND ?');
    $st->execute([PLAN['start'], PLAN['end']]);
    $spent = (float)$st->fetchColumn();
    $daysLeft = max(1, (int)floor((strtotime(PLAN['end']) - strtotime($today)) / 86400) + 1);
    $folNow = (int)($pdo->query('SELECT followers FROM ig_followers ORDER BY day DESC LIMIT 1')->fetchColumn() ?: 0);
    $counts = [];
    foreach ($pdo->query('SELECT day, followers FROM ig_followers') as $r) $counts[$r['day']] = (int)$r['followers'];
    $igNew = [];
    foreach ($pdo->query('SELECT day, new_followers FROM ig_daily') as $r) $igNew[$r['day']] = (int)$r['new_followers'];
    $daily = [];
    $st = $pdo->prepare('SELECT day, SUM(spend) AS spend, SUM(visits) AS visits, SUM(follows) AS follows FROM ad_daily WHERE day >= ? GROUP BY day ORDER BY day');
    $st->execute([$d7]);
    foreach ($st as $r) {
        $prev = date('Y-m-d', strtotime($r['day'] . ' -1 day'));
        $growth = $igNew[$r['day']] ?? ((isset($counts[$r['day']], $counts[$prev])) ? $counts[$r['day']] - $counts[$prev] : null);
        $daily[] = ['date' => $r['day'], 'spend' => r2($r['spend']), 'follows_credited_to_ads' => (int)$r['follows'], 'page_growth_all_sources' => $growth, 'is_today_so_far' => $r['day'] === $today];
    }
    $toGo = max(0, FOLLOWER_GOAL - $folNow);
    $left = max(0, PLAN['budget'] - $spent);

    // Ad sets, with budget changes read from the budget saved at every pull.
    $changes = [];
    $st = $pdo->prepare("SELECT entity_id, pulled_at, daily_budget FROM snapshots WHERE kind='adset' AND pulled_at >= ? ORDER BY entity_id, pulled_at");
    $st->execute([date('Y-m-d 00:00:00', strtotime('-10 days'))]);
    $lastB = [];
    foreach ($st as $r) {
        $id = (string)$r['entity_id'];
        $b = (float)$r['daily_budget'];
        if (isset($lastB[$id]) && abs($lastB[$id] - $b) > 0.5) $changes[$id][] = ['date' => substr($r['pulled_at'], 0, 10), 'from' => (int)$lastB[$id], 'to' => (int)$b];
        $lastB[$id] = $b;
    }
    $sets = [];
    foreach ($pdo->query('SELECT * FROM adsets') as $r) $sets[(string)$r['adset_id']] = $r;

    // Posts, to link an ad to the post it boosts.
    // Most ads here are made as new ads, not boosts of the post, so Meta gives no id that ties them together. The caption is the fallback link.
    $capKey = function (string $t): string {
        $t = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        return function_exists('mb_substr') ? mb_substr($t, 0, 45) : substr($t, 0, 45);
    };
    $media = [];
    $byCode = [];
    $byCap = [];
    foreach ($pdo->query('SELECT * FROM ig_media ORDER BY posted_at DESC') as $m) {
        $media[(string)$m['media_id']] = $m;
        $c = shortcode((string)$m['permalink']);
        if ($c !== '') $byCode[$c] = (string)$m['media_id'];
        $k = $capKey((string)$m['caption']);
        if (strlen($k) >= 25 && !isset($byCap[$k])) $byCap[$k] = (string)$m['media_id'];
    }
    $tags = [];
    foreach ($pdo->query('SELECT * FROM content_tags') as $t) $tags[$t['item_kind'] . ':' . $t['item_id']] = ['pillar' => $t['pillar'], 'hook' => $t['hook'], 'topic' => $t['topic']];
    $week = [];
    foreach ((array)kv_get('ads_week', []) as $w) $week[(string)$w['id']] = $w;

    // Ads. Live ones in full. Ads that stopped in the last 7 days in short.
    $rows = [];
    $st = $pdo->prepare('SELECT * FROM ad_daily WHERE day >= ? ORDER BY day');
    $st->execute([date('Y-m-d', strtotime('-8 days'))]);
    foreach ($st as $r) $rows[(string)$r['ad_id']][] = $r;
    $life = [];
    if ($from) {
        $st = $pdo->prepare('SELECT ad_id, SUM(spend) AS spend, SUM(visits) AS visits, SUM(follows) AS follows, COUNT(*) AS days FROM ad_daily WHERE day >= ? AND day < ? AND spend > 0 GROUP BY ad_id');
        $st->execute([$from, $today]);
        foreach ($st as $r) $life[(string)$r['ad_id']] = $r;
    }
    $first = [];
    foreach ($pdo->query('SELECT ad_id, MIN(day) AS d, COUNT(*) AS n FROM ad_daily WHERE spend > 0 GROUP BY ad_id') as $r) $first[(string)$r['ad_id']] = $r;
    $sum = function (array $list, string $a, string $b) {
        $o = ['spend' => 0.0, 'visits' => 0, 'follows' => 0, 'days' => 0];
        foreach ($list as $r) if ($r['day'] >= $a && $r['day'] <= $b) { $o['spend'] += (float)$r['spend']; $o['visits'] += (int)$r['visits']; $o['follows'] += (int)$r['follows']; $o['days']++; }
        return ['days' => $o['days'], 'spend' => r2($o['spend']), 'follows' => $o['follows'], 'visits' => $o['visits'], 'cost_per_follower' => per($o['spend'], $o['follows']), 'cost_per_visit' => per($o['spend'], $o['visits'])];
    };
    $live = [];
    $stopped = [];
    $boosted = [];
    foreach ($pdo->query('SELECT * FROM ads') as $a) {
        $id = (string)$a['ad_id'];
        $list = $rows[$id] ?? [];
        $set = $sets[(string)$a['adset_id']] ?? null;
        $isLive = $a['status'] === 'ACTIVE' && $set && $set['status'] === 'ACTIVE';
        if (!$isLive && !$list) continue;
        $cr = !empty($a['creative_json']) ? json_decode($a['creative_json'], true) : [];
        $mid = (string)($cr['source_instagram_media_id'] ?? '');
        if (!isset($media[$mid])) $mid = $byCode[shortcode((string)($cr['instagram_permalink_url'] ?? ''))] ?? '';
        $adText = trim(preg_replace('/\s+/u', ' ', (string)($cr['body'] ?? '')));
        if ($mid === '' && $adText !== '') $mid = $byCap[$capKey($adText)] ?? '';
        $post = $media[$mid] ?? null;
        if ($post && $isLive) $boosted[$mid] = true;
        $lf = $life[$id] ?? null;
        $base = [
            'ad_id' => $id, 'name' => short_ad_name($id, (string)$a['name']), 'name_in_ads_manager' => (string)$a['name'],
            'adset_id' => (string)$a['adset_id'], 'adset_name' => $set['name'] ?? '',
            'first_day_with_spend' => $first[$id]['d'] ?? null, 'days_with_spend' => (int)($first[$id]['n'] ?? 0),
            'post' => $post ? ['media_id' => $mid, 'caption' => $post['caption'], 'type' => $post['product_type'] === 'REELS' ? 'Reel' : 'Post', 'posted' => substr((string)$post['posted_at'], 0, 10)] : null,
            'ad_text' => $adText !== '' ? (function_exists('mb_substr') ? mb_substr($adText, 0, 160) : substr($adText, 0, 160)) : null,
            'known_tags' => $tags['ad:' . $id] ?? null,
            'since_meta_reported_follows' => $lf ? ['days' => (int)$lf['days'], 'spend' => r2($lf['spend']), 'follows' => (int)$lf['follows'], 'visits' => (int)$lf['visits'], 'cost_per_follower' => per($lf['spend'], $lf['follows']), 'cost_per_visit' => per($lf['spend'], $lf['visits'])] : null,
        ];
        if ($isLive) {
            $days = [];
            foreach ($list as $r) if ($r['day'] >= $d7) {
                $days[] = ['date' => $r['day'], 'spend' => r2($r['spend']), 'follows' => (int)$r['follows'], 'visits' => (int)$r['visits'], 'cost_per_follower' => per($r['spend'], $r['follows']), 'cost_per_visit' => per($r['spend'], $r['visits']), 'is_today_so_far' => $r['day'] === $today];
            }
            $live[] = $base + [
                'daily' => $days,
                'last_3_finished_days' => $sum($list, $d3, $yday),
                'yesterday' => $sum($list, $yday, $yday),
                'today_so_far' => $sum($list, $today, $today),
                'times_seen_per_person_7_days' => isset($week[$id]) ? round((float)$week[$id]['frequency'], 2) : null,
            ];
        } elseif ($list && end($list)['day'] >= $d7) {
            $stopped[] = ['ad_id' => $id, 'name' => $base['name'], 'adset_name' => $base['adset_name'], 'last_day_with_spend' => end($list)['day'], 'known_tags' => $base['known_tags'], 'post' => $base['post'], 'since_meta_reported_follows' => $base['since_meta_reported_follows']];
        }
    }
    $setFacts = [];
    $liveBudget = 0;
    foreach ($sets as $id => $s) {
        $id = (string)$id; // PHP turns numeric array keys into integers
        $mine = array_values(array_filter($live, fn($a) => $a['adset_id'] === $id));
        if ($s['status'] !== 'ACTIVE' || !$mine) continue;
        $liveBudget += (float)$s['daily_budget'];
        $setFacts[] = ['adset_id' => $id, 'name' => $s['name'], 'daily_budget' => (int)$s['daily_budget'], 'budget_changes_last_10_days' => $changes[$id] ?? [],
            'audience' => targeting_summary($s['targeting_json'] ?? null), 'live_ads' => array_map(fn($a) => $a['name'], $mine)];
    }

    // Organic posts from the last 21 days.
    $prevSnap = [];
    $st = $pdo->prepare('SELECT media_id, views FROM ig_media_daily WHERE day = ?');
    $st->execute([$yday]);
    foreach ($st as $r) $prevSnap[(string)$r['media_id']] = $r['views'];
    $posts = [];
    foreach ($media as $mid => $m) {
        $mid = (string)$mid;
        if ($m['posted_at'] < date('Y-m-d', strtotime('-21 days'))) continue;
        $reach = (int)($m['reach'] ?? 0);
        $posts[] = ['media_id' => $mid, 'caption' => $m['caption'], 'type' => $m['product_type'] === 'REELS' ? 'Reel' : 'Post', 'posted' => substr((string)$m['posted_at'], 0, 10),
            'views' => $m['views'] === null ? null : (int)$m['views'], 'reach' => $m['reach'] === null ? null : $reach, 'likes' => (int)$m['likes'], 'comments' => (int)$m['comments'],
            'shares' => $m['shares'] === null ? null : (int)$m['shares'], 'saves' => $m['saved'] === null ? null : (int)$m['saved'], 'reposts' => ($m['reposts'] ?? null) === null ? null : (int)$m['reposts'],
            'avg_watch_seconds' => $m['avg_watch_ms'] ? round($m['avg_watch_ms'] / 1000, 1) : null,
            'shares_per_1000_reached' => $reach > 0 && $m['shares'] !== null ? round(1000 * $m['shares'] / $reach, 1) : null,
            'views_gained_today' => isset($prevSnap[$mid]) && $m['views'] !== null && $prevSnap[$mid] !== null ? (int)$m['views'] - (int)$prevSnap[$mid] : null,
            'being_boosted_now' => isset($boosted[$mid]), 'known_tags' => $tags['media:' . $mid] ?? null];
        if (count($posts) >= 25) break;
    }

    // What the team decided before, and how it turned out.
    $log = [];
    foreach ($pdo->query('SELECT * FROM brain_decisions ORDER BY decided_at DESC LIMIT 30') as $d) {
        $log[] = ['date' => $d['day'], 'kind' => $d['kind'], 'item' => $d['item_name'], 'ai_said' => trim($d['ai_action'] . ($d['ai_budget'] ? ' to ₹' . $d['ai_budget'] : '')),
            'team_did' => trim($d['final_action'] . ($d['final_budget'] ? ' to ₹' . $d['final_budget'] : '')), 'agreed' => (bool)$d['agreed'], 'team_reason' => (string)$d['reason'],
            'result' => $d['outcome_json'] ? (json_decode($d['outcome_json'], true)['note'] ?? null) : null];
    }

    return [
        'now' => date('Y-m-d H:i') . ' IST', 'today' => $today,
        'account' => [
            'month_budget' => PLAN['budget'], 'spent_this_month' => r2($spent), 'budget_left' => r2($left), 'days_left_including_today' => $daysLeft,
            'budget_per_day_to_spend_it_all' => (int)round($left / $daysLeft), 'live_daily_budgets_total' => (int)$liveBudget,
            'followers_now' => $folNow, 'follower_goal' => FOLLOWER_GOAL, 'followers_to_go' => $toGo,
            'cost_per_follower_if_ads_did_it_all' => $toGo > 0 ? round($left / $toGo, 2) : null,
            'meta_started_crediting_follows_on' => $from, 'last_days' => $daily,
        ],
        'ad_sets' => $setFacts, 'live_ads' => $live, 'ads_stopped_in_last_7_days' => $stopped, 'organic_posts' => $posts, 'team_decision_log' => $log,
    ];
}

// ---------- The call to Claude ----------

function brain_schema(): array {
    $str = ['type' => 'string'];
    $list = fn($items) => ['type' => 'array', 'items' => $items];
    $obj = fn($props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    $tag = ['pillar' => ['type' => 'string', 'enum' => PILLARS], 'hook' => ['type' => 'string', 'enum' => HOOKS], 'topic' => $str];
    return $obj([
        'headline' => $str,
        'summary' => $str,
        'warnings' => $list($str),
        'ads' => $list($obj([
            'ad_id' => $str,
            'action' => ['type' => 'string', 'enum' => AD_ACTIONS],
            'adset_daily_budget_after' => ['type' => 'integer'],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'reasons' => $list($str),
            'what_would_change_this' => $str,
        ] + $tag)),
        'boost_next' => $list($obj(['media_id' => $str, 'why' => $str, 'daily_budget' => ['type' => 'integer'], 'audience' => $str])),
        'audience_changes' => $list($obj(['adset_id' => $str, 'change' => $str, 'why' => $str])),
        'content_learnings' => $list($str),
        'post_tags' => $list($obj(['media_id' => $str] + $tag)),
        'stopped_ad_tags' => $list($obj(['ad_id' => $str] + $tag)),
    ]);
}

function brain_system(): string {
    return implode("\n", [
        'You are the ad decision analyst for MySootra, an astrology and devotional app in India that is growing its Instagram page before launch.',
        'Each day you get a fact sheet as JSON. Decide what the team should do with each live ad, which post to boost next, which audience changes to make, and what the numbers say about content.',
        '',
        'How the team wants calls made:',
        brain_playbook(),
        '',
        'Rules for your answer:',
        '- Use only numbers that appear in the fact sheet. Do not work out new numbers other than a proposed budget. Quote rupee amounts exactly as given.',
        '- Give exactly one entry in "ads" for every ad in live_ads, using its ad_id.',
        '- action is one of scale, hold, cut, stop, wait. Use wait only when there is too little data to judge, and say what you are waiting for.',
        '- adset_daily_budget_after is the daily budget you propose for that ad\'s ad set, in whole rupees. Use 0 when you propose no budget change or the action is stop. Ads in the same ad set must carry the same number.',
        '- Keep the plan inside the money. Compare the total of live daily budgets after your changes with budget_per_day_to_spend_it_all and say in summary if it is over or under.',
        '- reasons are 2 to 4 short plain sentences. Lead with cost per follower. Mention the trend across days.',
        '- today_so_far is a part day. Do not judge an ad on it alone.',
        '- Meta\'s follower credit can run higher than real page growth. Compare follows_credited_to_ads with page_growth_all_sources and add a warning when the gap is large or when one ad\'s numbers look unusual.',
        '- boost_next has up to 3 organic posts that are not being boosted now, by media_id. Prefer posts whose topic matches what is winning in ads and that show strong shares, saves or views for their age. Give a daily budget and an audience.',
        '- audience_changes has up to 3 entries. Use an existing adset_id, or "new" for a new ad set. Only suggest a change the numbers support.',
        '- content_learnings has 2 to 4 sentences on which topics and hooks turn viewers into followers. Say when the data is thin.',
        '- Tag every live ad, every organic post in post_tags, and every ad in ads_stopped_in_last_7_days in stopped_ad_tags, with pillar, hook and a topic of 2 to 4 words. Keep known_tags as they are unless clearly wrong.',
        '- Read team_decision_log. Where the team overrode an earlier call, learn from their reason and from the result. Do not repeat a call the team has already rejected unless the numbers have changed, and say what changed.',
        '- Write for a busy team. Short sentences, everyday words, no jargon, no em dashes.',
    ]);
}

function claude_post(array $body, string $key): array {
    $mock = getenv('MS_CLAUDE_MOCK');
    if ($mock) {
        $fn = require $mock;
        $j = $fn($body);
        if (isset($j['error'])) throw new BrainError('Claude error. ' . ($j['error']['message'] ?? 'Unknown error'));
        return $j;
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 280, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new BrainError('Could not reach Claude. ' . $err);
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new BrainError('Claude sent an answer that could not be read.');
    if (isset($j['error'])) throw new BrainError('Claude error. ' . ($j['error']['message'] ?? 'Unknown error'));
    return $j;
}

function brain_call(array $facts): array {
    $key = brain_secret()['key'] ?? '';
    if ($key === '' && !getenv('MS_CLAUDE_MOCK')) throw new BrainError('The Claude API key is not saved yet.');
    $model = cfg()['brain_model'] ?? BRAIN_MODEL;
    $body = ['model' => $model, 'max_tokens' => 8000, 'system' => brain_system(),
        'messages' => [['role' => 'user', 'content' => "Fact sheet for today:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
        'output_config' => ['format' => ['type' => 'json_schema', 'schema' => brain_schema()]]];
    $resp = claude_post($body, $key);
    if (($resp['stop_reason'] ?? '') === 'max_tokens') throw new BrainError('The answer was cut off before it finished. Try again.');
    if (($resp['stop_reason'] ?? '') === 'refusal') throw new BrainError('Claude declined to answer this run.');
    $text = '';
    foreach (($resp['content'] ?? []) as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    $out = json_decode($text, true);
    if (!is_array($out)) throw new BrainError('The answer was not in the expected shape.');
    $in = (int)($resp['usage']['input_tokens'] ?? 0);
    $o = (int)($resp['usage']['output_tokens'] ?? 0);
    return ['output' => $out, 'model' => (string)($resp['model'] ?? $model), 'tokens_in' => $in, 'tokens_out' => $o, 'cost_usd' => round($in * BRAIN_PRICE['in'] / 1e6 + $o * BRAIN_PRICE['out'] / 1e6, 4)];
}

// ---------- Guardrails: nothing reaches the screen unchecked ----------

function fact_numbers($v, array &$bag): void {
    if (is_array($v)) { foreach ($v as $x) fact_numbers($x, $bag); return; }
    if (is_int($v) || is_float($v)) $bag[] = (float)$v;
}

function brain_guard(array $out, array $facts): array {
    $liveById = [];
    foreach ($facts['live_ads'] as $a) $liveById[$a['ad_id']] = $a;
    $setById = [];
    foreach ($facts['ad_sets'] as $s) $setById[$s['adset_id']] = $s;
    $pick = function ($v, array $allowed, string $default) {
        foreach ($allowed as $x) if (strcasecmp((string)$v, $x) === 0) return $x;
        return $default;
    };
    // Any number in the fact sheet may be quoted, since a reason often compares one ad with another.
    $accountNums = [];
    fact_numbers($facts, $accountNums);
    // Levels written in the playbook may be quoted too.
    if (preg_match_all('/₹\s?([\d,]+(?:\.\d+)?)/u', brain_playbook(), $pm)) foreach ($pm[1] as $n) $accountNums[] = (float)str_replace(',', '', $n);

    $ads = [];
    foreach ((array)($out['ads'] ?? []) as $c) {
        $id = (string)($c['ad_id'] ?? '');
        if (!isset($liveById[$id]) || isset($ads[$id])) continue; // not a live ad, or said twice
        $a = $liveById[$id];
        $set = $setById[$a['adset_id']] ?? null;
        $now = (int)($set['daily_budget'] ?? 0);
        $flags = [];
        $action = $pick($c['action'] ?? '', AD_ACTIONS, 'hold');
        $after = (int)($c['adset_daily_budget_after'] ?? 0);
        if ($action === 'stop' || $action === 'hold' || $action === 'wait') $after = 0;
        if ($action === 'scale') {
            if ($after <= $now) { $flags[] = 'It said scale but gave no higher budget.'; $after = 0; }
            elseif ($now > 0 && $after > 2 * $now) { $flags[] = 'The raise was more than double, so it is capped at double.'; $after = 2 * $now; }
        }
        if ($action === 'cut' && ($after <= 0 || $after >= $now)) { $flags[] = 'It said cut but gave no lower budget.'; $after = 0; }
        if ($after > 0 && $after < 100) { $flags[] = 'The budget was under ₹100, so it is set to ₹100.'; $after = 100; }
        // Every rupee amount in the reasons must be a number from the fact sheet.
        $nums = $accountNums;
        if ($after) { $nums[] = (float)$after; $nums[] = (float)abs($after - $now); }
        $reasons = array_values(array_filter(array_map('strval', (array)($c['reasons'] ?? []))));
        if (preg_match_all('/₹\s?([\d,]+(?:\.\d+)?)/u', implode(' ', $reasons) . ' ' . ($c['what_would_change_this'] ?? ''), $m)) {
            foreach (array_unique($m[1]) as $n) {
                $x = (float)str_replace(',', '', $n);
                $found = false;
                foreach ($nums as $f) if (abs($f - $x) <= max(0.011, abs($f) * 0.005)) { $found = true; break; }
                if (!$found) $flags[] = '₹' . $n . ' is not a number from the data. Check it before acting.';
            }
        }
        $ads[$id] = ['ad_id' => $id, 'name' => $a['name'], 'adset_id' => $a['adset_id'], 'adset_name' => $a['adset_name'], 'action' => $action,
            'budget_now' => $now, 'budget_after' => $after, 'confidence' => $pick($c['confidence'] ?? '', ['high', 'medium', 'low'], 'low'),
            'reasons' => $reasons, 'watch' => (string)($c['what_would_change_this'] ?? ''), 'flags' => $flags,
            'pillar' => $pick($c['pillar'] ?? '', PILLARS, 'Other'), 'hook' => $pick($c['hook'] ?? '', HOOKS, 'Other'), 'topic' => substr(trim((string)($c['topic'] ?? '')), 0, 80),
            'numbers' => ['last_3_days' => $a['last_3_finished_days'], 'yesterday' => $a['yesterday'], 'today' => $a['today_so_far'], 'days_with_spend' => $a['days_with_spend']]];
    }
    foreach ($liveById as $id => $a) {
        $id = (string)$id;
        if (isset($ads[$id])) continue;
        $ads[$id] = ['ad_id' => $id, 'name' => $a['name'], 'adset_id' => $a['adset_id'], 'adset_name' => $a['adset_name'], 'action' => 'wait', 'budget_now' => (int)($setById[$a['adset_id']]['daily_budget'] ?? 0), 'budget_after' => 0,
            'confidence' => 'low', 'reasons' => [], 'watch' => '', 'flags' => ['The AI gave no call for this ad. Run it again.'], 'pillar' => 'Other', 'hook' => 'Other', 'topic' => '',
            'numbers' => ['last_3_days' => $a['last_3_finished_days'], 'yesterday' => $a['yesterday'], 'today' => $a['today_so_far'], 'days_with_spend' => $a['days_with_spend']]];
    }
    // One budget per ad set. If two ads in a set disagree, take the lower and say so.
    $perSet = [];
    foreach ($ads as $c) if ($c['budget_after'] > 0) $perSet[$c['adset_id']][] = $c['budget_after'];
    foreach ($ads as &$c) {
        $vals = array_unique($perSet[$c['adset_id']] ?? []);
        if (count($vals) > 1 && $c['budget_after'] > 0) { $c['flags'][] = 'Ads in this ad set were given different budgets. The lower one is used.'; $c['budget_after'] = min($vals); }
    }
    unset($c);
    // Work out the money in code, not from the answer.
    $after = 0;
    foreach ($setById as $sid => $s) {
        $sid = (string)$sid;
        $mine = array_filter($ads, fn($c) => $c['adset_id'] === $sid);
        if ($mine && !array_filter($mine, fn($c) => $c['action'] !== 'stop')) continue; // every ad in it is stopped
        $prop = array_values(array_filter(array_map(fn($c) => $c['budget_after'], $mine)));
        $after += $prop ? min($prop) : $s['daily_budget'];
    }
    $postsById = [];
    foreach ($facts['organic_posts'] as $p) $postsById[$p['media_id']] = $p;
    $boost = [];
    foreach ((array)($out['boost_next'] ?? []) as $b) {
        $mid = (string)($b['media_id'] ?? '');
        if (!isset($postsById[$mid]) || $postsById[$mid]['being_boosted_now'] || isset($boost[$mid]) || count($boost) >= 3) continue;
        $p = $postsById[$mid];
        $budget = max(100, min(500, (int)($b['daily_budget'] ?? 150)));
        $boost[$mid] = ['media_id' => $mid, 'name' => short_post_name(['media_id' => $mid, 'caption' => $p['caption'], 'product_type' => $p['type'] === 'Reel' ? 'REELS' : 'FEED', 'media_type' => '', 'posted_at' => $p['posted']]),
            'posted' => $p['posted'], 'views' => $p['views'], 'shares' => $p['shares'], 'saves' => $p['saves'], 'why' => (string)($b['why'] ?? ''), 'daily_budget' => $budget, 'audience' => (string)($b['audience'] ?? '')];
        $after += $budget;
    }
    $aud = [];
    foreach ((array)($out['audience_changes'] ?? []) as $i => $x) {
        $sid = (string)($x['adset_id'] ?? '');
        if ($sid !== 'new' && !isset($setById[$sid])) continue;
        if (count($aud) >= 3) break;
        $aud[] = ['key' => $sid . '-' . ($i + 1), 'adset_id' => $sid, 'adset_name' => $sid === 'new' ? 'New ad set' : $setById[$sid]['name'], 'change' => (string)($x['change'] ?? ''), 'why' => (string)($x['why'] ?? '')];
    }
    $tags = [];
    foreach ((array)($out['post_tags'] ?? []) as $t) {
        $mid = (string)($t['media_id'] ?? '');
        if (isset($postsById[$mid])) $tags[$mid] = ['pillar' => $pick($t['pillar'] ?? '', PILLARS, 'Other'), 'hook' => $pick($t['hook'] ?? '', HOOKS, 'Other'), 'topic' => substr(trim((string)($t['topic'] ?? '')), 0, 80)];
    }
    $stoppedIds = array_column($facts['ads_stopped_in_last_7_days'], 'ad_id');
    $adTags = [];
    foreach ((array)($out['stopped_ad_tags'] ?? []) as $t) {
        $id = (string)($t['ad_id'] ?? '');
        if (in_array($id, $stoppedIds, true)) $adTags[$id] = ['pillar' => $pick($t['pillar'] ?? '', PILLARS, 'Other'), 'hook' => $pick($t['hook'] ?? '', HOOKS, 'Other'), 'topic' => substr(trim((string)($t['topic'] ?? '')), 0, 80)];
    }
    $acc = $facts['account'];
    return [
        'headline' => (string)($out['headline'] ?? ''), 'summary' => (string)($out['summary'] ?? ''),
        'warnings' => array_values(array_filter(array_map('strval', (array)($out['warnings'] ?? [])))),
        'ads' => array_values($ads), 'boost_next' => array_values($boost), 'audience_changes' => $aud,
        'content_learnings' => array_values(array_filter(array_map('strval', (array)($out['content_learnings'] ?? [])))),
        'post_tags' => $tags, 'stopped_ad_tags' => $adTags,
        'money' => ['budget_now' => $acc['live_daily_budgets_total'], 'budget_after' => (int)$after, 'per_day_to_spend_it_all' => $acc['budget_per_day_to_spend_it_all'],
            'budget_left' => $acc['budget_left'], 'days_left' => $acc['days_left_including_today'], 'followers_now' => $acc['followers_now'], 'followers_to_go' => $acc['followers_to_go']],
    ];
}

// ---------- Run, save, read ----------

// A run has two halves. brain_start does the quick checks and saves a row. brain_finish does the slow part: fresh numbers, the call to Claude, the checks on the answer.
// The web page gets its reply after the first half, so a slow answer from Claude cannot be cut off by the browser or the web server.
function brain_start(string $source = 'button'): int {
    migrate();
    $pdo = db();
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT COUNT(*) FROM brain_runs WHERE day = ?');
    $st->execute([$today]);
    if ((int)$st->fetchColumn() >= BRAIN_RUNS_PER_DAY) throw new BrainError('The AI has already run ' . BRAIN_RUNS_PER_DAY . ' times today. That is the daily limit.');
    $last = $pdo->query('SELECT run_at, ok, message FROM brain_runs ORDER BY id DESC LIMIT 1')->fetch();
    if ($last && $last['message'] === 'Running' && strtotime($last['run_at']) > time() - 360) throw new BrainError('A run is already in progress.');
    if ($source !== 'cron' && $last && (int)$last['ok'] === 1 && strtotime($last['run_at']) > time() - BRAIN_GAP_MINUTES * 60) throw new BrainError('The AI ran a few minutes ago. Wait ' . BRAIN_GAP_MINUTES . ' minutes between runs.');
    if (empty(brain_secret()['key']) && !getenv('MS_CLAUDE_MOCK')) throw new BrainError('The Claude API key is not saved yet.');
    $pdo->prepare("INSERT INTO brain_runs (run_at, day, source, model, ok, message) VALUES (?, ?, ?, '', 0, 'Running')")->execute([date('Y-m-d H:i:s'), $today, $source]);
    return (int)$pdo->lastInsertId();
}

function brain_finish(int $runId): void {
    @set_time_limit(340);
    @ignore_user_abort(true);
    $pdo = db();
    $save = function (array $f) use ($pdo, $runId) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        $pdo->prepare("UPDATE brain_runs SET $set WHERE id = ?")->execute(array_merge(array_values($f), [$runId]));
    };
    // If PHP itself dies, say why instead of leaving the row on Running.
    $done = false;
    register_shutdown_function(function () use (&$done, $save) {
        if ($done) return;
        $e = error_get_last();
        try { $save(['message' => 'The server stopped the run. ' . ($e ? $e['message'] : 'No reason was given.')]); } catch (Throwable $x) { /* nothing more can be done */ }
    });
    $t0 = microtime(true);
    try {
        // Fresh numbers first.
        $pull = last_pull(true);
        if (token() && (!$pull || strtotime($pull['finished_at'] ?: $pull['started_at']) < time() - 30 * 60)) collect('brain');
        brain_outcomes();
        $facts = brain_facts();
        $save(['facts_json' => json_encode($facts)]);
        if (!$facts['live_ads']) throw new BrainError('There are no live ads to judge right now.');
        $res = brain_call($facts);
        $clean = brain_guard($res['output'], $facts);
        $clean['seconds'] = (int)round(microtime(true) - $t0);
        $now = date('Y-m-d H:i:s');
        foreach ($clean['ads'] as $c) upsert('content_tags', ['item_kind' => 'ad', 'item_id' => $c['ad_id'], 'pillar' => $c['pillar'], 'hook' => $c['hook'], 'topic' => $c['topic'], 'updated_at' => $now], ['item_kind', 'item_id']);
        foreach ($clean['stopped_ad_tags'] as $aid => $t) upsert('content_tags', ['item_kind' => 'ad', 'item_id' => (string)$aid, 'pillar' => $t['pillar'], 'hook' => $t['hook'], 'topic' => $t['topic'], 'updated_at' => $now], ['item_kind', 'item_id']);
        foreach ($clean['post_tags'] as $mid => $t) upsert('content_tags', ['item_kind' => 'media', 'item_id' => (string)$mid, 'pillar' => $t['pillar'], 'hook' => $t['hook'], 'topic' => $t['topic'], 'updated_at' => $now], ['item_kind', 'item_id']);
        $save(['model' => $res['model'], 'ok' => 1, 'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'cost_usd' => $res['cost_usd'], 'output_json' => json_encode($clean), 'message' => '']);
    } catch (Throwable $e) {
        $save(['message' => $e->getMessage() . ' (after ' . (int)round(microtime(true) - $t0) . ' seconds)']);
    }
    $done = true;
}

// Used by the daily cron job and by tests: both halves in one go.
function brain_run(string $source = 'button'): array {
    $id = brain_start($source);
    brain_finish($id);
    $st = db()->prepare('SELECT ok, message FROM brain_runs WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!(int)$r['ok']) throw new BrainError((string)$r['message']);
    return ['run_id' => $id];
}

// Three finished days after a decision, records how the ad did before and after it.
function brain_outcomes(): void {
    $pdo = db();
    // Days before Meta credited follows to ads cannot be compared, so the "before" window never reaches back past that date.
    $from = $pdo->query('SELECT MIN(day) FROM ad_daily WHERE follows >= 20')->fetchColumn() ?: '2000-01-01';
    $sum = function (string $ad, string $a, string $b) use ($pdo) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(spend),0) AS spend, COALESCE(SUM(follows),0) AS follows, COUNT(*) AS days FROM ad_daily WHERE ad_id = ? AND day BETWEEN ? AND ? AND spend > 0');
        $st->execute([$ad, $a, $b]);
        $r = $st->fetch();
        return ['days' => (int)$r['days'], 'spend' => r2($r['spend']), 'follows' => (int)$r['follows'], 'cost_per_follower' => per($r['spend'], $r['follows'])];
    };
    $st = $pdo->prepare("SELECT * FROM brain_decisions WHERE kind = 'ad' AND outcome_json IS NULL AND day <= ?");
    $st->execute([date('Y-m-d', strtotime('-4 days'))]);
    foreach ($st->fetchAll() as $d) {
        $before = $sum($d['item_id'], max($from, date('Y-m-d', strtotime($d['day'] . ' -2 days'))), $d['day']);
        $after = $sum($d['item_id'], date('Y-m-d', strtotime($d['day'] . ' +1 day')), date('Y-m-d', strtotime($d['day'] . ' +3 days')));
        $b = $before['cost_per_follower'];
        $a = $after['cost_per_follower'];
        if ($after['days'] === 0) $note = 'The ad did not run in the next 3 days.' . ($b !== null ? ' It was at ₹' . number_format($b, 2) . ' a follower.' : '');
        elseif ($a === null || $b === null) $note = 'Too few follows to compare.';
        elseif ($a <= $b * 0.9) $note = 'Followers got cheaper. ₹' . number_format($b, 2) . ' before, ₹' . number_format($a, 2) . ' after.';
        elseif ($a <= $b * 1.15) $note = 'Follower cost held. ₹' . number_format($b, 2) . ' before, ₹' . number_format($a, 2) . ' after.';
        else $note = 'Followers got costlier. ₹' . number_format($b, 2) . ' before, ₹' . number_format($a, 2) . ' after.';
        $good = $after['days'] === 0 ? null : ($a !== null && $b !== null ? $a <= $b * 1.15 : null);
        $pdo->prepare('UPDATE brain_decisions SET outcome_json = ?, outcome_at = ? WHERE id = ?')->execute([json_encode(['before' => $before, 'after' => $after, 'note' => $note, 'good' => $good]), date('Y-m-d H:i:s'), $d['id']]);
    }
}

function brain_decide(array $in): void {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM brain_runs WHERE id = ? AND ok = 1');
    $st->execute([(int)($in['run_id'] ?? 0)]);
    $run = $st->fetch();
    if (!$run) throw new BrainError('That run was not found.');
    $out = json_decode($run['output_json'], true);
    $kind = (string)($in['kind'] ?? '');
    $id = (string)($in['item_id'] ?? '');
    $item = null;
    if ($kind === 'ad') foreach ($out['ads'] as $c) if ($c['ad_id'] === $id) $item = ['name' => $c['name'], 'action' => $c['action'], 'budget' => $c['budget_after'], 'ctx' => $c['numbers']];
    if ($kind === 'boost') foreach ($out['boost_next'] as $c) if ($c['media_id'] === $id) $item = ['name' => $c['name'], 'action' => 'boost', 'budget' => $c['daily_budget'], 'ctx' => ['views' => $c['views'], 'shares' => $c['shares']]];
    if ($kind === 'audience') foreach ($out['audience_changes'] as $c) if ($c['key'] === $id) $item = ['name' => $c['adset_name'] . '. ' . $c['change'], 'action' => 'change', 'budget' => 0, 'ctx' => []];
    if (!$item) throw new BrainError('That call was not found in the run.');
    $allowed = $kind === 'ad' ? AD_ACTIONS : [$item['action'], 'skip'];
    $final = strtolower(trim((string)($in['final_action'] ?? '')));
    if (!in_array($final, $allowed, true)) throw new BrainError('Pick one of ' . implode(', ', $allowed) . '.');
    $budget = max(0, min(100000, (int)($in['final_budget'] ?? 0)));
    if (in_array($final, ['stop', 'hold', 'wait', 'skip'], true)) $budget = 0;
    $agreed = $final === $item['action'] && ($budget === (int)$item['budget'] || $budget === 0 && !in_array($final, ['scale', 'cut'], true));
    $reason = trim((string)($in['reason'] ?? ''));
    if (!$agreed && $reason === '') throw new BrainError('Add a short reason when the team decides differently. The AI learns from it.');
    $pdo->prepare('DELETE FROM brain_decisions WHERE run_id = ? AND kind = ? AND item_id = ?')->execute([$run['id'], $kind, $id]);
    $pdo->prepare('INSERT INTO brain_decisions (run_id, day, kind, item_id, item_name, ai_action, ai_budget, final_action, final_budget, agreed, reason, decided_by, decided_at, context_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$run['id'], $run['day'], $kind, $id, substr($item['name'], 0, 250), $item['action'], (int)$item['budget'], $final, $budget, $agreed ? 1 : 0, substr($reason, 0, 1000),
            substr(trim((string)($in['by'] ?? '')) ?: 'Team', 0, 60), date('Y-m-d H:i:s'), json_encode($item['ctx'])]);
}

// Results grouped by kind of content. Built from saved tags and saved numbers, so it grows as months pass.
function brain_content_table(): array {
    $pdo = db();
    $from = $pdo->query('SELECT MIN(day) FROM ad_daily WHERE follows >= 20')->fetchColumn() ?: '2100-01-01';
    $g = [];
    $blank = ['ads' => 0, 'spend' => 0.0, 'follows' => 0, 'posts' => 0, 'views' => 0, 'shares' => 0];
    $st = $pdo->prepare("SELECT t.pillar, t.hook, COUNT(DISTINCT d.ad_id) AS ads, SUM(d.spend) AS spend, SUM(d.follows) AS follows FROM content_tags t JOIN ad_daily d ON d.ad_id = t.item_id WHERE t.item_kind = 'ad' AND d.day >= ? GROUP BY t.pillar, t.hook");
    $st->execute([$from]);
    foreach ($st as $r) { $k = $r['pillar'] . '|' . $r['hook']; $g[$k] = ($g[$k] ?? $blank); $g[$k]['ads'] = (int)$r['ads']; $g[$k]['spend'] = (float)$r['spend']; $g[$k]['follows'] = (int)$r['follows']; }
    foreach ($pdo->query("SELECT t.pillar, t.hook, COUNT(*) AS posts, SUM(COALESCE(m.views,0)) AS views, SUM(COALESCE(m.shares,0)) AS shares FROM content_tags t JOIN ig_media m ON m.media_id = t.item_id WHERE t.item_kind = 'media' GROUP BY t.pillar, t.hook") as $r) {
        $k = $r['pillar'] . '|' . $r['hook']; $g[$k] = ($g[$k] ?? $blank); $g[$k]['posts'] = (int)$r['posts']; $g[$k]['views'] = (int)$r['views']; $g[$k]['shares'] = (int)$r['shares'];
    }
    $out = [];
    foreach ($g as $k => $v) {
        [$pillar, $hook] = explode('|', $k);
        $out[] = ['pillar' => $pillar, 'hook' => $hook, 'ads' => $v['ads'], 'spend' => r2($v['spend']), 'follows' => $v['follows'], 'cost_per_follower' => per($v['spend'], $v['follows']),
            'posts' => $v['posts'], 'views_per_post' => $v['posts'] ? (int)round($v['views'] / $v['posts']) : null, 'shares_per_post' => $v['posts'] ? round($v['shares'] / $v['posts'], 1) : null];
    }
    usort($out, fn($a, $b) => [$a['cost_per_follower'] ?? 9999, -$a['follows']] <=> [$b['cost_per_follower'] ?? 9999, -$b['follows']]);
    return $out;
}

function brain_state(): array {
    $pdo = db();
    $sec = brain_secret();
    $run = $pdo->query('SELECT id, run_at, day, source, model, tokens_in, tokens_out, cost_usd, output_json FROM brain_runs WHERE ok = 1 ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    $startedAt = $pdo->query("SELECT run_at FROM brain_runs WHERE message = 'Running' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
    $fail = $pdo->query('SELECT run_at, message FROM brain_runs ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    $running = false;
    if ($fail && $fail['message'] === 'Running') {
        if (strtotime($fail['run_at']) > time() - 360) { $running = true; $fail = null; }
        else $fail['message'] = 'The run was cut off before Claude answered. The server stopped it or the connection dropped. Press Run now again.';
    }
    $decided = [];
    if ($run) {
        $st = $pdo->prepare('SELECT kind, item_id, final_action, final_budget, agreed, reason, decided_by, decided_at FROM brain_decisions WHERE run_id = ?');
        $st->execute([$run['id']]);
        foreach ($st as $d) $decided[$d['kind'] . ':' . $d['item_id']] = $d;
    }
    $log = [];
    foreach ($pdo->query('SELECT day, kind, item_name, ai_action, ai_budget, final_action, final_budget, agreed, reason, decided_by, outcome_json FROM brain_decisions ORDER BY decided_at DESC LIMIT 40') as $d) {
        $o = $d['outcome_json'] ? json_decode($d['outcome_json'], true) : null;
        $log[] = ['day' => $d['day'], 'kind' => $d['kind'], 'item' => $d['item_name'], 'ai_action' => $d['ai_action'], 'ai_budget' => (int)$d['ai_budget'], 'final_action' => $d['final_action'], 'final_budget' => (int)$d['final_budget'],
            'agreed' => (bool)$d['agreed'], 'reason' => $d['reason'], 'by' => $d['decided_by'], 'result' => $o['note'] ?? null, 'good' => $o['good'] ?? null];
    }
    $s = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(agreed),0) AS agreed FROM brain_decisions')->fetch();
    $good = 0; $bad = 0;
    foreach ($pdo->query('SELECT outcome_json FROM brain_decisions WHERE outcome_json IS NOT NULL') as $d) { $o = json_decode($d['outcome_json'], true); if (($o['good'] ?? null) === true) $good++; elseif (($o['good'] ?? null) === false) $bad++; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM brain_runs WHERE day = ?');
    $st->execute([date('Y-m-d')]);
    return [
        'has_key' => !empty($sec['key']) || (bool)getenv('MS_CLAUDE_MOCK'), 'has_pin' => !empty($sec['pin_hash']), 'running' => $running, 'running_seconds' => $running && $startedAt ? time() - strtotime($startedAt) : null,
        'run' => $run ? ['id' => (int)$run['id'], 'run_at' => $run['run_at'], 'day' => $run['day'], 'source' => $run['source'], 'model' => $run['model'], 'cost_usd' => (float)$run['cost_usd'], 'tokens_in' => (int)$run['tokens_in'], 'tokens_out' => (int)$run['tokens_out'], 'output' => json_decode($run['output_json'], true)] : null,
        'last_error' => $fail && $fail['message'] !== '' && (!$run || $fail['run_at'] > $run['run_at']) ? ['at' => $fail['run_at'], 'message' => $fail['message']] : null,
        'decided' => $decided, 'log' => $log,
        'score' => ['decisions' => (int)$s['n'], 'agreed' => (int)$s['agreed'], 'turned_out_well' => $good, 'turned_out_badly' => $bad],
        'content' => brain_content_table(), 'playbook' => brain_playbook(), 'runs_today' => (int)$st->fetchColumn(), 'runs_per_day' => BRAIN_RUNS_PER_DAY,
        'actions' => AD_ACTIONS,
    ];
}
