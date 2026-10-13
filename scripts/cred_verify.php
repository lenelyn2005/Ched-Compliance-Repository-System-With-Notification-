<?php
/**
 * CHED Compliance — credential & notification verification (CLI).
 * Prints NO secret values (only presence/length/masked/booleans).
 *
 * Verifies:
 *   1. DB connects with the configured (non-root, non-empty-password) account.
 *   2. SMTP/WS credentials are present and not known placeholders.
 *   3. SMTP authentication succeeds with the current credentials.
 *   4. A real email can be delivered (self-send to SMTP_USER) via PHPMailer.
 *
 * Run: php scripts/cred_verify.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('CHED_APP', true);
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../send_email.php';

$fail = 0;
function chk($name, $ok, $detail = '') {
    global $fail;
    if (!$ok) { $fail++; }
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $name  $detail\n";
}
function masked(string $s): string {
    if ($s === '') return '(empty)';
    if (str_contains($s, '@')) { $p = explode('@', $s); return substr($p[0], 0, 2) . '***@' . ($p[1] ?? ''); }
    return substr($s, 0, 2) . str_repeat('*', max(0, strlen($s) - 2));
}
function isPlaceholder(string $s): bool {
    return $s === '' || stripos($s, 'change_me') !== false || stripos($s, 'changeme') !== false
        || stripos($s, 'your_') !== false || stripos($s, 'placeholder') !== false || $s === '__PASSWORD__';
}

echo "== Credential shape ==\n";
$dbUser = defined('DB_USER') ? DB_USER : '';
$dbPass = defined('DB_PASS') ? DB_PASS : '';
echo "  DB_USER=" . masked($dbUser) . "  DB_PASS=" . (strlen($dbPass) > 0 ? 'set(len=' . strlen($dbPass) . ')' : '(empty)') . "\n";
chk('DB account is not root', strtolower((string)$dbUser) !== 'root');
chk('DB password is set (non-empty)', $dbPass !== '');
chk('DB password is not a placeholder', !isPlaceholder($dbPass));

$smtpUser = defined('SMTP_USER') ? SMTP_USER : '';
$smtpPass = defined('SMTP_PASS') ? SMTP_PASS : '';
echo "  SMTP_HOST=" . (defined('SMTP_HOST') ? SMTP_HOST : '') . "  SMTP_PORT=" . (defined('SMTP_PORT') ? SMTP_PORT : '') . "\n";
echo "  SMTP_USER=" . masked($dbUser === '' ? '' : (string)$dbUser) . " / user=" . masked((string)$dbUser) . "\n";
echo "  SMTP_USER(field)=" . masked((string)$dbUser) . "\n";
$smtpUserVal = defined('SMTP_USER') ? (string)SMTP_USER : '';
$smtpPassVal = defined('SMTP_PASS') ? (string)SMTP_PASS : '';
echo "  SMTP_USER=" . masked($smtpUser) . "  SMTP_PASS=" . (strlen($smtpPass) > 0 ? 'set(len=' . strlen($smtpPass) . ')' : '(empty)') . "\n";
chk('SMTP host set', defined('SMTP_HOST') && SMTP_HOST !== '');
chk('SMTP user set', $smtpUser !== '');
chk('SMTP password set and not placeholder', !isPlaceholder($smtpPass));

$ws  = getenv('WS_SECRET') ?: '';
$wsi = getenv('WS_INTERNAL_SECRET') ?: '';
echo "  WS_SECRET=" . (strlen($ws) > 0 ? 'set(len=' . strlen($ws) . ')' : '(empty)')
   . "  WS_INTERNAL_SECRET=" . (strlen($wsi) > 0 ? 'set(len=' . strlen($wsi) . ')' : '(empty)') . "\n";
chk('WS_SECRET set and not placeholder', !isPlaceholder($ws));
chk('WS_INTERNAL_SECRET set and not placeholder', !isPlaceholder($wsi));
chk('WS_SECRET != WS_INTERNAL_SECRET', $ws !== '' && $ws !== $wsi);

echo "\n== DB authentication ==\n";
$pdo = function_exists('perf_db') ? perf_db() : null;
chk('perf_db() connects', $pdo instanceof PDO, '');
if ($pdo instanceof PDO) {
    $who = (string)$pdo->query("SELECT CURRENT_USER()")->fetchColumn();
    echo "  connected as: " . masked($who) . "\n";
}

echo "\n== SMTP authentication (network) ==\n";
$mailer = mailerConnect();
chk('SMTP auth (mailerConnect)', $mailer !== null, $mailer === null ? 'auth/connect failed or network blocked' : 'authenticated');

if ($mailer !== null) {
    echo "\n== Notification delivery (self-send) ==\n";
    $ok = sendEmail(SMTP_USER, 'CHED credential verification', '<p>Credential verification self-send. Safe to ignore.</p>', false, $mailer);
    chk('email delivered via replacement SMTP creds', $ok, $ok ? 'sent' : ($GLOBALS['email_last_error'] ?? 'unknown'));
    try { $mailer->smtpClose(); } catch (Throwable $e) {}
} else {
    echo "  [i] Skipped live send (SMTP auth did not succeed here).\n";
}

echo "\n=== " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($fail failures) ===\n";
exit($fail === 0 ? 0 : 1);
