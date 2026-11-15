<?php
// One-time page to save the Meta token on the server. The token is never shown again and never sent to the browser.
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$msg = '';
$good = false;
$lockFile = __DIR__ . '/private/setup_attempts.json';

function attempts_left(string $f): int {
    $a = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $a = array_values(array_filter($a, fn($t) => $t > time() - 3600));
    return max(0, 8 - count($a));
}
function note_attempt(string $f): void {
    $a = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $a[] = time();
    @file_put_contents($f, json_encode(array_slice($a, -30)));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $tok = trim((string)($_POST['token'] ?? ''));
    if (attempts_left($lockFile) <= 0) {
        $msg = 'Too many tries. Wait an hour and try again.';
    } elseif (!password_verify($code, cfg()['setup_code_hash'])) {
        note_attempt($lockFile);
        $msg = 'That setup code is not right.';
    } elseif (!preg_match('/^[A-Za-z0-9_\-|.]{40,600}$/', $tok)) {
        $msg = 'That does not look like a Meta token. Paste the full token with no spaces.';
    } else {
        try {
            $acct = graph('act_' . cfg()['ad_account_id'], ['fields' => 'name,account_status'], $tok);
            $body = "<?php\nreturn " . var_export($tok, true) . ";\n";
            if (@file_put_contents(token_path(), $body, LOCK_EX) === false) {
                $msg = 'The server could not save the token. Tell Claude this exact line.';
            } else {
                @chmod(token_path(), 0600);
                $r = collect('setup');
                $good = true;
                $msg = 'Token saved. It can read the ad account "' . htmlspecialchars((string)($acct['name'] ?? '')) . '". '
                    . ($r['ok'] ? 'First pull is done and saved ' . (int)$r['day_rows'] . ' daily rows. ' : 'But the first pull had a problem. ')
                    . htmlspecialchars($r['message']);
            }
        } catch (Throwable $e) {
            note_attempt($lockFile);
            $msg = 'Meta did not accept this token for the ad account. ' . htmlspecialchars($e->getMessage());
        }
    }
}
$has = (bool)token();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Setup</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f5f2;color:#1f1e1c;margin:0;padding:32px 16px}
main{max-width:520px;margin:0 auto;background:#fff;border:1px solid #e3e0d8;border-radius:10px;padding:24px}
h1{font-size:20px;margin:0 0 8px}p{font-size:14px;line-height:1.5;color:#55524b}
label{display:block;font-size:13px;font-weight:600;margin:16px 0 6px}
input,textarea{width:100%;box-sizing:border-box;font:inherit;font-size:14px;padding:10px;border:1px solid #cfcbc0;border-radius:8px}
textarea{min-height:110px;font-family:ui-monospace,Menlo,monospace;font-size:12px}
button{margin-top:18px;font:inherit;font-weight:600;padding:10px 16px;border:0;border-radius:8px;background:#1f1e1c;color:#fff;cursor:pointer}
.note{padding:10px 12px;border-radius:8px;font-size:14px;margin:12px 0;background:#fdecea;color:#8a1c12}
.note.ok{background:#e8f5ec;color:#1d6b38}a{color:#1f1e1c}
</style></head><body><main>
<h1>MySootra ads dashboard setup</h1>
<p><?= $has ? 'A Meta token is already saved on this server. Use this form only to replace it.' : 'Paste the Meta token here once. It is saved on the server and is never shown again.' ?></p>
<?php if ($msg): ?><div class="note <?= $good ? 'ok' : '' ?>"><?= $msg ?></div><?php endif; ?>
<?php if ($good): ?><p><a href="./">Open the dashboard</a></p><?php else: ?>
<form method="post" autocomplete="off">
<label for="code">Setup code</label><input id="code" name="code" required autocomplete="off">
<label for="token">Meta token</label><textarea id="token" name="token" required spellcheck="false"></textarea>
<button type="submit">Save token and pull data</button>
</form>
<?php endif; ?>
</main></body></html>
