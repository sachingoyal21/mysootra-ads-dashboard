<?php
// Daily report. Builds the same sheet the team used to fill by hand, saves it, and writes it out as an Excel file.
// Everything here reads our own database. The one call to Meta asks for lifetime reach per ad. Nothing is ever changed in Meta.

const REPORT_DAYS_BACK = 7;
// Marks a number that was never saved, so it cannot be shown.
const NC = 'nc';

function report_window(): array {
    return ['min' => date('Y-m-d', strtotime('-' . REPORT_DAYS_BACK . ' days')), 'max' => date('Y-m-d', strtotime('-1 day'))];
}

function report_day_ok(string $day): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return false;
    $w = report_window();
    return $day >= $w['min'] && $day <= $w['max'];
}

function day_label(string $day): string {
    $t = strtotime($day);
    $n = (int)date('j', $t);
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix . ' ' . date('M', $t);
}

function report_names(): array {
    static $n = null;
    if ($n === null) {
        $f = __DIR__ . '/names.php';
        $n = is_file($f) ? (require $f) : [];
    }
    return $n + ['ads' => [], 'posts' => []];
}

function short_ad_name(string $id, string $name): string {
    $map = report_names()['ads'];
    if (isset($map[$id])) return $map[$id];
    if (isset($map[$name])) return $map[$name];
    $s = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', str_replace(['_', '/'], ' ', $name));
    $s = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(ad|ads)\b/i', '', $s)));
    return $s !== '' ? $s : $name;
}

function short_post_name(array $m): string {
    $map = report_names()['posts'];
    if (isset($map[$m['media_id']])) return $map[$m['media_id']];
    $type = $m['product_type'] === 'REELS' ? 'Reel' : ($m['media_type'] === 'CAROUSEL_ALBUM' ? 'Carousel' : 'Post');
    $cap = preg_replace('/[#@]\S+/u', ' ', (string)$m['caption']);
    $cap = preg_replace('/[^\p{L}\p{N}\s\'\-]/u', ' ', $cap);
    $words = array_values(array_filter(preg_split('/\s+/u', trim($cap))));
    if (!$words) return $type . ' of ' . date('j M', strtotime($m['posted_at']));
    return implode(' ', array_slice($words, 0, 5)) . ' (' . $type . ')';
}

// Everything the report needs that is already in our database.
function report_local(string $day): array {
    $prev = date('Y-m-d', strtotime($day . ' -1 day'));
    $pdo = db();

    $snap = function (string $d) use ($pdo): array {
        $st = $pdo->prepare('SELECT s.*, m.caption, m.posted_at, m.product_type, m.media_type FROM ig_media_daily s JOIN ig_media m ON m.media_id = s.media_id WHERE s.day = ? ORDER BY m.posted_at DESC');
        $st->execute([$d]);
        $out = [];
        foreach ($st as $r) $out[$r['media_id']] = $r;
        return $out;
    };
    $posts = function (string $upTo) use ($pdo): array {
        $st = $pdo->prepare('SELECT * FROM ig_media WHERE posted_at <= ? ORDER BY posted_at DESC');
        $st->execute([$upTo . ' 23:59:59']);
        return $st->fetchAll();
    };
    $num = fn($v) => $v === null ? null : (int)$v;
    $metrics = ['views', 'likes', 'comments', 'shares', 'reposts', 'follows'];
    $organicRow = function (array $m, ?array $vals): array {
        $r = ['kind' => 'organic', 'name' => short_post_name($m), 'bold' => false, 'amount' => null, 'visits' => null, 'clicks' => null];
        foreach (['views', 'likes', 'comments', 'shares', 'reposts'] as $k) $r[$k] = $vals === null ? NC : $vals[$k];
        $r['follower_gain'] = $vals === null ? null : $vals['follows'];
        return $r;
    };

    $snapDay = $snap($day);
    $snapPrev = $snap($prev);
    $capturedDay = $snapDay && $snapPrev;
    $capturedTill = (bool)$snapPrev;

    $dayRows = [];
    if ($snapDay) {
        foreach ($snapDay as $id => $cur) {
            $base = $snapPrev[$id] ?? null;
            $isNew = substr((string)$cur['posted_at'], 0, 10) === $day;
            if ($base === null && !$isNew) { $dayRows[] = $organicRow($cur, null); continue; }
            $vals = [];
            foreach ($metrics as $k) {
                $c = $num($cur[$k]);
                $b = $base === null ? 0 : $num($base[$k]);
                $vals[$k] = ($c === null || $b === null) ? null : $c - $b;
            }
            $dayRows[] = $organicRow($cur, $vals);
        }
    } else {
        foreach ($posts($day) as $m) $dayRows[] = $organicRow($m, null);
    }

    $tillRows = [];
    if ($snapPrev) {
        foreach ($snapPrev as $cur) {
            $vals = [];
            foreach ($metrics as $k) $vals[$k] = $num($cur[$k]);
            $tillRows[] = $organicRow($cur, $vals);
        }
    } else {
        foreach ($posts($prev) as $m) $tillRows[] = $organicRow($m, null);
    }

    // Paid ads.
    $adNames = [];
    foreach ($pdo->query('SELECT ad_id, name FROM ads') as $r) $adNames[(string)$r['ad_id']] = (string)$r['name'];
    $first = [];
    foreach ($pdo->query('SELECT ad_id, MIN(day) AS d FROM ad_daily WHERE spend > 0 GROUP BY ad_id') as $r) $first[(string)$r['ad_id']] = $r['d'];
    $byFirst = fn($a, $b) => [$first[$a['ad_id']] ?? '', $a['ad_id']] <=> [$first[$b['ad_id']] ?? '', $b['ad_id']];

    $st = $pdo->prepare('SELECT ad_id, ad_name, spend, reach, visits, clicks, follows FROM ad_daily WHERE day = ? AND spend > 0');
    $st->execute([$day]);
    $running = [];
    $paidDay = [];
    foreach ($st as $r) {
        $id = (string)$r['ad_id'];
        $running[$id] = true;
        $paidDay[] = ['kind' => 'paid', 'ad_id' => $id, 'name' => short_ad_name($id, $adNames[$id] ?? (string)$r['ad_name']) . ' (Paid)', 'bold' => false,
            'amount' => round((float)$r['spend'], 2), 'views' => (int)$r['reach'], 'visits' => (int)$r['visits'], 'clicks' => (int)$r['clicks'],
            'likes' => null, 'comments' => null, 'shares' => null, 'reposts' => null, 'follower_gain' => (int)$r['follows']];
    }
    usort($paidDay, $byFirst);

    $st = $pdo->prepare('SELECT ad_id, MAX(ad_name) AS ad_name, SUM(spend) AS spend, SUM(reach) AS reach, SUM(visits) AS visits, SUM(clicks) AS clicks, SUM(follows) AS follows
                         FROM ad_daily WHERE day <= ? GROUP BY ad_id HAVING SUM(spend) >= 1');
    $st->execute([$prev]);
    $ended = [];
    $live = [];
    foreach ($st as $r) {
        $id = (string)$r['ad_id'];
        $on = isset($running[$id]);
        $row = ['kind' => 'paid', 'ad_id' => $id, 'name' => short_ad_name($id, $adNames[$id] ?? (string)$r['ad_name']) . ($on ? ' (Paid)' : ' (AD END)'), 'bold' => !$on,
            'amount' => round((float)$r['spend'], 2), 'views' => null, 'reach_sum' => (int)$r['reach'], 'visits' => (int)$r['visits'], 'clicks' => (int)$r['clicks'],
            'likes' => null, 'comments' => null, 'shares' => null, 'reposts' => null, 'follower_gain' => (int)$r['follows']];
        if ($on) $live[] = $row; else $ended[] = $row;
    }
    usort($ended, $byFirst);
    usort($live, $byFirst);

    // Followers on the page.
    $fol = [];
    $st = $pdo->prepare('SELECT day, followers FROM ig_followers WHERE day IN (?, ?)');
    $st->execute([$day, $prev]);
    foreach ($st as $r) $fol[$r['day']] = (int)$r['followers'];
    $now = $fol[$day] ?? null;
    $before = $fol[$prev] ?? null;
    $gain = ($now !== null && $before !== null) ? $now - $before : null;

    return [
        'day' => $day, 'prev_day' => $prev,
        'title_day' => day_label($day), 'title_till' => 'Till ' . day_label($prev),
        'organic_captured_day' => $capturedDay, 'organic_captured_till' => $capturedTill,
        'snapshot_start' => $pdo->query('SELECT MIN(day) FROM ig_media_daily')->fetchColumn() ?: null,
        'day_rows' => array_merge($dayRows, $paidDay),
        'till_rows' => array_merge($tillRows, $ended, $live),
        'followers' => ['prev' => $before, 'now' => $now, 'gain' => $gain],
    ];
}

function report_fingerprint(array $local): string {
    return hash('sha256', json_encode([$local['day_rows'], $local['till_rows'], $local['followers']]));
}

function report_totals(array $rows): array {
    $t = ['amount' => 0.0, 'views' => 0, 'visits' => 0, 'clicks' => 0, 'follower_gain' => 0];
    foreach ($rows as $r) {
        if ($r['kind'] !== 'paid') continue;
        foreach ($t as $k => $_) $t[$k] += is_numeric($r[$k]) ? $r[$k] : 0;
    }
    $t['amount'] = round($t['amount'], 2);
    return $t;
}

// Builds the full report. Asks Meta once for the unique reach of every ad up to the day before.
function report_build(string $day): array {
    $rep = report_local($day);
    $fp = report_fingerprint($rep);
    $life = [];
    $hasPaid = false;
    foreach ($rep['till_rows'] as $r) if ($r['kind'] === 'paid') $hasPaid = true;
    $notes = [];
    if ($hasPaid) {
        foreach (insights('ad', ['time_range' => range_param(cfg()['history_start'], $rep['prev_day'])], 'ad_id,reach') as $r) {
            $life[(string)$r['ad_id']] = (int)($r['reach'] ?? 0);
        }
    }
    $missing = 0;
    foreach ($rep['till_rows'] as &$r) {
        if ($r['kind'] !== 'paid') continue;
        if (isset($life[$r['ad_id']])) $r['views'] = $life[$r['ad_id']];
        else { $r['views'] = $r['reach_sum']; $missing++; }
    }
    unset($r);
    if ($missing) $notes[] = "Meta gave no lifetime reach for $missing " . ($missing === 1 ? 'ad' : 'ads') . '. Their running Unique View is the sum of daily reach.';

    $f = $rep['followers'];
    $rep['followers']['text_till'] = $f['prev'] === null ? 'n/c' : (string)$f['prev'];
    $rep['followers']['text_day'] = $f['now'] === null ? 'n/c'
        : ($f['gain'] === null ? (string)$f['now'] : $f['prev'] . ($f['gain'] < 0 ? '-' : '+') . abs($f['gain']) . '=' . $f['now']);
    $rep['day_total'] = report_totals($rep['day_rows']);
    $rep['till_total'] = report_totals($rep['till_rows']);

    array_unshift($notes,
        'Organic Unique View is views from Instagram. Paid Unique View is reach from Meta Ads, the same as the manual sheet.',
        'Clicks are all clicks on the ad. Total rows add up paid rows only.',
        'Follower Gain is what Meta credits to each ad. Meta does not give it for organic Reels. The page total is in the Followers column.',
        'Organic numbers for a day are the change between the last pull of that day and the last pull of the day before.'
    );
    if (!$rep['organic_captured_day'] || !$rep['organic_captured_till']) {
        $notes[] = 'n/c means not captured. Daily saving of organic post numbers started on ' . ($rep['snapshot_start'] ? day_label($rep['snapshot_start']) : 'a later date') . ', and Meta does not give past days.';
    }
    $rep['notes'] = $notes;
    $rep['generated_at'] = date('Y-m-d H:i:s');
    $rep['fingerprint'] = $fp;
    return $rep;
}

function report_save(array $rep): void {
    upsert('reports', ['day' => $rep['day'], 'generated_at' => $rep['generated_at'], 'fingerprint' => $rep['fingerprint'], 'data_json' => json_encode($rep)], ['day']);
}

function report_get(string $day): ?array {
    $st = db()->prepare('SELECT data_json FROM reports WHERE day = ?');
    $st->execute([$day]);
    $j = $st->fetchColumn();
    $r = $j ? json_decode($j, true) : null;
    return is_array($r) ? $r : null;
}

// True when the numbers in our database no longer match the saved report.
function report_changed(array $saved): bool {
    return report_fingerprint(report_local($saved['day'])) !== ($saved['fingerprint'] ?? '');
}

function report_list(): array {
    $out = [];
    foreach (db()->query('SELECT day, generated_at, data_json FROM reports ORDER BY day DESC') as $r) {
        $rep = json_decode($r['data_json'], true);
        $out[] = ['day' => $r['day'], 'label' => day_label($r['day']), 'generated_at' => $r['generated_at'], 'changed' => is_array($rep) ? report_changed($rep) : true];
    }
    return $out;
}

// ---------- Excel file ----------

function xml_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function col_letter(int $i): string {
    return chr(65 + $i);
}

// Small registry of cell styles. Returns the style number for a mix of font, fill, number format and alignment.
function xl_style(array &$reg, bool $bold, int $fill, int $fmt, string $align, bool $border = true, bool $wrap = false): int {
    $key = implode('|', [(int)$bold, $fill, $fmt, $align, (int)$border, (int)$wrap]);
    if (!isset($reg[$key])) $reg[$key] = count($reg) + 1;
    return $reg[$key];
}

function report_xlsx(array $rep): string {
    $reg = [];
    $rows = [];   // each row is a list of [value, style]
    $merges = [];
    $heads = ['Post Name', 'Amount', 'Unique View', 'Profile Visits', 'Clicks', 'Like', 'comment', 'share', 'Repost', 'Follower Gain', 'Followers'];
    $last = count($heads) - 1;
    $FMT_INT = 3; $FMT_INR = 164;
    $cell = function ($v, bool $bold, int $fill, bool $money = false, string $align = 'center') use (&$reg, $FMT_INT, $FMT_INR) {
        if ($v === NC) return ['n/c', xl_style($reg, $bold, $fill, 0, 'center')];
        if ($v === null) return ['-', xl_style($reg, $bold, $fill, 0, 'center')];
        if ($v === '') return ['', xl_style($reg, $bold, $fill, 0, $align)];
        if (is_string($v)) return [$v, xl_style($reg, $bold, $fill, 0, $align)];
        return [$v + 0, xl_style($reg, $bold, $fill, $money ? $FMT_INR : $FMT_INT, 'center')];
    };
    $block = function (string $title, array $data, array $total, string $followers) use (&$rows, &$merges, &$reg, $heads, $last, $cell) {
        $r = count($rows) + 1;
        $line = [['', xl_style($reg, false, 0, 0, 'left', false)]];
        for ($i = 1; $i <= $last; $i++) $line[] = [$i === 1 ? $title : '', xl_style($reg, false, 2, 0, 'center')];
        $rows[] = $line;
        $merges[] = 'B' . $r . ':' . col_letter($last) . $r;
        $line = [];
        foreach ($heads as $i => $h) $line[] = [$h, xl_style($reg, true, 1, 0, $i === 0 ? 'left' : 'center')];
        $rows[] = $line;
        $firstData = count($rows) + 1;
        foreach ($data as $d) {
            $b = !empty($d['bold']);
            $paid = $d['kind'] === 'paid';
            $blank = fn($v) => ($paid && $v === null) ? '' : $v;
            $rows[] = [
                $cell((string)$d['name'], $b, 0, false, 'left'),
                $cell($d['amount'], $b, 0, true), $cell($d['views'], $b, 0), $cell($d['visits'], $b, 0), $cell($d['clicks'], $b, 0),
                $cell($blank($d['likes']), $b, 0), $cell($blank($d['comments']), $b, 0), $cell($blank($d['shares']), $b, 0), $cell($blank($d['reposts']), $b, 0),
                $cell($d['follower_gain'], $b, 0),
                ['', xl_style($reg, false, 0, 0, 'center', true, true)],
            ];
        }
        $lastData = count($rows);
        if ($lastData >= $firstData) {
            $rows[$firstData - 1][$last][0] = $followers;
            if ($lastData > $firstData) $merges[] = col_letter($last) . $firstData . ':' . col_letter($last) . $lastData;
        }
        $rows[] = [
            $cell('Total', true, 2), $cell($total['amount'], true, 2, true), $cell($total['views'], true, 2), $cell($total['visits'], true, 2), $cell($total['clicks'], true, 2),
            $cell('', true, 2), $cell('', true, 2), $cell('', true, 2), $cell('', true, 2), $cell($total['follower_gain'], true, 2), $cell('', true, 2),
        ];
    };
    $block($rep['title_day'], $rep['day_rows'], $rep['day_total'], $rep['followers']['text_day']);
    $block($rep['title_till'], $rep['till_rows'], $rep['till_total'], $rep['followers']['text_till']);
    $rows[] = [];
    $noteStyle = xl_style($reg, false, 0, 0, 'left', false);
    foreach (array_merge($rep['notes'] ?? [], ['Report saved on ' . date('j M Y, g:i a', strtotime($rep['generated_at'])) . '. Source: Meta Ads and Instagram, read by the MySootra dashboard.']) as $n) $rows[] = [[$n, $noteStyle]];

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>';
    foreach ([46, 12, 13, 14, 10, 9, 11, 9, 9, 14, 20] as $i => $w) $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $sheet .= '</cols><sheetData>';
    foreach ($rows as $ri => $line) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach ($line as $ci => [$v, $s]) {
            $ref = col_letter($ci) . ($ri + 1);
            if ($v === '') $sheet .= '<c r="' . $ref . '" s="' . $s . '"/>';
            elseif (is_string($v)) $sheet .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . xml_esc($v) . '</t></is></c>';
            else $sheet .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData>';
    if ($merges) {
        $sheet .= '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $m) $sheet .= '<mergeCell ref="' . $m . '"/>';
        $sheet .= '</mergeCells>';
    }
    $sheet .= '<pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';

    $xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
    foreach (array_keys($reg) as $key) {
        [$bold, $fill, $fmt, $align, $border, $wrap] = explode('|', $key);
        $xfs .= '<xf numFmtId="' . $fmt . '" fontId="' . $bold . '" fillId="' . ($fill ? $fill + 1 : 0) . '" borderId="' . $border . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="' . $align . '" vertical="center"' . ($wrap ? ' wrapText="1"' : '') . '/></xf>';
    }
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;₹ &quot;#,##0.00"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . (count($reg) + 1) . '">' . $xfs . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $ns . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $ns . '">'
            . '<sheets><sheet name="' . xml_esc($rep['title_day']) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $ns . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $ns . '/styles" Target="styles.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
        'xl/styles.xml' => $styles,
    ];
    return zip_bytes($files);
}

// Minimal zip writer, so the server needs no extra PHP extension.
function zip_bytes(array $files): string {
    $out = '';
    $dir = '';
    $n = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $deflate = function_exists('gzdeflate');
        $body = $deflate ? gzdeflate($data, 6) : $data;
        $method = $deflate ? 8 : 0;
        $head = pack('vvvvvVVVvv', 20, 0x0800, $method, 0, 0x21, $crc, strlen($body), $size, strlen($name), 0);
        $dir .= "PK\x01\x02" . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, strlen($out)) . $name;
        $out .= "PK\x03\x04" . $head . $name . $body;
        $n++;
    }
    return $out . $dir . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($dir), strlen($out), 0);
}
