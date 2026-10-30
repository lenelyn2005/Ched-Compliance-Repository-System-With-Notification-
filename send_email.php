<?php
/**
 * send_email.php - Email helper (clean architecture)
 * PHPMailer is the ONLY transport. No mail() fallback.
 * Queue: email_queue table → process_email_queue.php → PHPMailer → Gmail SMTP
 */

if (!defined('CHED_APP')) define('CHED_APP', true);

/**
 * Log email errors to dedicated log file (never log secrets)
 */
function logEmailError(string $to, string $subject, string $error): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/email_errors.log';
    // Strip any accidental secret leakage
    $safeError = preg_replace('/(pass|password|secret).*$/i', '[REDACTED]', $error);
    $entry = date('Y-m-d H:i:s') . " | TO: $to | SUBJECT: $subject | ERROR: $safeError | STATE: FAILED\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Log email success to track sent emails
 */
function logEmailSuccess(string $to, string $subject, string $method): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/email_success.log';
    $hash = substr(md5($to), 0, 8);
    $entry = date('Y-m-d H:i:s') . " | TO: user-$hash | SUBJECT: $subject | METHOD: $method | STATE: SENT\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Build a recipient-personalized email body from an in-app notification.
 * Root cause fix: createNotification() used to email the raw in-app message,
 * so a Program Head could receive text written about a teacher ("Teacher X
 * submitted...") with no greeting — looking like mail for someone else.
 * One wrapper here fixes every caller at once; no per-caller templates.
 */
function buildRecipientEmailBody(array $user, string $title, string $message): string
{
    $roleLabels = ['teacher' => 'Faculty', 'staff' => 'Staff', 'admin' => 'Administrator', 'department' => phRoleName('department'), 'unit_head' => phRoleName('unit_head')];
    $role = $roleLabels[$user['role'] ?? ''] ?? 'User';
    $name = trim($user['name'] ?? '');
    $greeting = $name !== '' ? "Hello {$name} ({$role})" : "Hello {$role}";
    $msg = htmlspecialchars($message);
    return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;">
  <tr><td style="background:#1a365d;padding:24px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:18px;font-weight:600;">CHED Compliance System</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="margin:0 0 16px;font-size:14px;color:#1a365d;font-weight:600;">' . htmlspecialchars($greeting) . ',</p>
    <div style="font-size:14px;line-height:1.7;color:#334155;">' . $msg . '</div>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 24px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;">
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
}

/**
 * Open one shared PHPMailer SMTP connection for a whole queue batch.
 * Root cause fix for slowness: the old loop opened a NEW SMTP connection
 * (DNS + TLS + Gmail auth ≈ seconds) per email. One connection with
 * SMTPKeepAlive serves the entire batch.
 * Returns null when SMTP is unconfigured/missing so callers fail fast.
 */
function mailerConnect(): ?object
{
    if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS)) {
        return null;
    }
    $mailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    if (!file_exists($mailerPath)) {
        return null;
    }
    require_once $mailerPath;
    require_once dirname($mailerPath) . '/SMTP.php';
    require_once dirname($mailerPath) . '/Exception.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        // No forced AuthType: PHPMailer auto-negotiates (Gmail prefers LOGIN;
        // forcing PLAIN cost extra round-trips and could fail outright).
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        if ((int)SMTP_PORT === 465) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 10;
        $mail->SMTPKeepAlive = true;
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->isHTML(true);
        if (!$mail->smtpConnect()) {
            return null;
        }
        return $mail;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Process a batch of pending emails from the email_queue table.
 * Shared by the CLI worker (process_email_queue.php) and the automatic
 * in-request worker spawn so behaviour stays consistent.
 *
 * @return array{0:int,int,int} counts of [sent, retried, failed]
 */
function processEmailQueueBatch(int $limit = 20, int $maxAttempts = 5): array
{
    global $pdo;
    if (!isset($pdo) || !$pdo) {
        if (function_exists('perf_db')) {
            $pdo = perf_db();
        }
    }
    if (!$pdo) {
        return ['sent' => 0, 'retried' => 0, 'failed' => 0];
    }

    // Requeue rows left in 'processing' by a crashed worker
    try {
        $pdo->prepare(
            "UPDATE email_queue SET status = 'pending', processed_at = NULL
             WHERE status = 'processing' AND processed_at < (NOW() - INTERVAL 30 MINUTE)"
        )->execute();
    } catch (Exception $e) {
        error_log('[CHED Compliance] Stale email requeue failed: ' . $e->getMessage());
    }

    // Claim a batch atomically: only rows owned by this transaction are
    // processed. Rows that were just retried are skipped until 60s of backoff
    // pass (processed_at gate), so a Gmail/network outage doesn't get hammered
    // by a tight retry loop or burn through attempts in seconds.
    $batch = [];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->query(
            "SELECT id, recipient, subject, body, attempts, user_id, notification_id FROM email_queue
             WHERE status = 'pending'
               AND (processed_at IS NULL OR processed_at < (NOW() - INTERVAL 60 SECOND))
             ORDER BY id ASC
             LIMIT " . (int)$limit . " FOR UPDATE"
        );
        $batch = $stmt->fetchAll();

        $claim = $pdo->prepare("UPDATE email_queue SET status = 'processing', processed_at = NOW() WHERE id = ?");
        foreach ($batch as $row) {
            $claim->execute([(int)$row['id']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CHED Compliance] Failed to claim email batch: ' . $e->getMessage());
        return ['sent' => 0, 'retried' => 0, 'failed' => 0];
    }

    $sentStmt = $pdo->prepare("UPDATE email_queue SET status = 'sent', processed_at = NOW() WHERE id = ?");
    $failStmt = $pdo->prepare(
        "UPDATE email_queue SET status = ?, attempts = attempts + 1, error_message = ?, processed_at = NOW() WHERE id = ?"
    );

    $sent = 0;
    $retried = 0;
    $failed = 0;

    // One SMTP connection for the whole batch (falls back to per-mail
    // connects inside sendEmail() when the shared connection is unavailable).
    $sharedMailer = mailerConnect();

    foreach ($batch as $row) {
        $id = (int)$row['id'];
        $attempts = (int)$row['attempts'] + 1;

        $GLOBALS['email_last_error'] = null;
        // Rebuild body from in-app notification if IDs present (avoids plaintext storage)
        $body = $row['body'];
        if (!empty($row['user_id']) && !empty($row['notification_id'])) {
            $notif = $pdo->prepare("SELECT title, message FROM in_app_notifications WHERE id = ? AND user_id = ?");
            $notif->execute([(int)$row['notification_id'], (int)$row['user_id']]);
            $n = $notif->fetch();
            if ($n) {
                $userStmt = $pdo->prepare("SELECT name, role, email FROM users WHERE id = ?");
                $userStmt->execute([(int)$row['user_id']]);
                $u = $userStmt->fetch();
                if ($u) {
                    $body = buildRecipientEmailBody($u, $n['title'], $n['message']);
                }
            }
        }
        $ok = sendEmail($row['recipient'], $row['subject'], $body, false, $sharedMailer);

        if ($ok) {
            $sentStmt->execute([$id]);
            $sent++;
        } else {
            $error = $GLOBALS['email_last_error'] ?? 'Unknown send failure';
            if ($attempts >= $maxAttempts) {
                $failStmt->execute(['failed', $error, $id]);
                $failed++;
            } else {
                $failStmt->execute(['pending', $error, $id]);
                $retried++;
            }
        }
    }

    if ($sharedMailer) {
        try {
            $sharedMailer->smtpClose();
        } catch (Exception $e) {
        }
    }

    return ['sent' => $sent, 'retried' => $retried, 'failed' => $failed];
}

/**
 * Spawn the email queue worker (process_email_queue.php) in the background
 * so queued emails are delivered without requiring cron / Task Scheduler.
 * Debounced by a lock file to avoid spawning a process on every request.
 *
 * @param int $minIntervalSeconds Minimum seconds between automatic spawns
 * @return bool True if a worker was (or was attempted to be) spawned
 */
function spawnEmailWorker(int $minIntervalSeconds = 2): bool
{
    // Deployment safety valve: set EMAIL_DISABLE_SPAWN=1 in .env on servers
    // where background process spawning is unreliable/disabled (shared
    // hosting). The queue still records emails and a scheduled worker
    // (cron / Task Scheduler: php process_email_queue.php) delivers them.
    if (getenv('EMAIL_DISABLE_SPAWN')) {
        return false;
    }
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $lockFile = $logDir . '/email_worker_spawn.lock';
    $outLog = $logDir . '/email_worker.log';
    $worker = __DIR__ . '/process_email_queue.php';

    // Debounce: only spawn once per interval. An exclusive lock makes this
    // race-safe so concurrent requests cannot spawn duplicate workers.
    $fh = @fopen($lockFile, 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    $mtime = @filemtime($lockFile);
    if ($mtime && (time() - $mtime) < $minIntervalSeconds) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return false;
    }
    @touch($lockFile);
    flock($fh, LOCK_UN);
    fclose($fh);

    // Detached background process on Windows (XAMPP). The worker runs to
    // completion independently of the web request that spawned it.
    // Prefer php-win.exe (GUI subsystem) — it NEVER opens a console, so no
    // black php.exe pop-up under Apache. Falls back to php.exe with
    // create_no_window as belt-and-braces for non-XAMPP installs.
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    if ($isWindows) {
        $binary = PHP_BINARY;
        $winBinary = dirname($binary) . DIRECTORY_SEPARATOR . 'php-win.exe';
        if (is_file($winBinary)) {
            $binary = $winBinary;
        }
    } else {
        // Under Apache mod_php PHP_BINARY is empty, so resolve the CLI binary.
        $binary = getenv('PHP_CLI_BINARY') ?: PHP_BINDIR . '/php';
    }
    $descriptors = [
        0 => ['file', $isWindows ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $outLog, 'a'],
        2 => ['file', $outLog, 'a'],
    ];
    $proc = @proc_open([$binary, $worker], $descriptors, $pipes, __DIR__, null, ['create_no_window' => true]);
    if (is_resource($proc)) {
        // Deliberately no proc_close: it would block until the worker exits.
        // Leaving the resource lets PHP free it at request shutdown while the
        // worker keeps running detached (fire-and-forget).
        return true;
    }

    return false;
}

/**
 * Send email — queue or direct via PHPMailer only
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body
 * @param bool $queueOnly If true, INSERT into email_queue (worker delivers); if false, send now via PHPMailer
 * @param object|null $sharedMailer Reuse an existing PHPMailer SMTP connection (batch mode)
 * @param int|null $userId Recipient user_id (stored in queue, body rebuilt at send time)
 * @param int|null $notificationId Related in_app_notifications.id (stored in queue)
 * @return bool Success status
 */
function sendEmail(string $to, string $subject, string $body, bool $queueOnly = true, ?object $sharedMailer = null, ?int $userId = null, ?int $notificationId = null): bool
{
    // Validate recipient early (prevent header injection)
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['email_last_error'] = "Invalid recipient email: $to";
        logEmailError($to, $subject, $GLOBALS['email_last_error']);
        return false;
    }

    // Queue-only mode: reliable DB queue, async worker
    if ($queueOnly) {
        global $pdo;
        if (!isset($pdo) || !$pdo) {
            if (function_exists('perf_db')) {
                $pdo = perf_db();
            }
        }
        if (isset($pdo) && $pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO email_queue (recipient, subject, body, status, attempts, created_at, user_id, notification_id) VALUES (?, ?, ?, 'pending', 0, NOW(), ?, ?)");
                $stmt->execute([$to, $subject, $body, $userId, $notificationId]);
                error_log('[CHED Compliance] Email QUEUED to=' . $to . ' subject=' . $subject);
                spawnEmailWorker();
                return true;
            } catch (Exception $e) {
                $GLOBALS['email_last_error'] = "DB Queue Error: " . $e->getMessage();
                logEmailError($to, $subject, $GLOBALS['email_last_error']);
                return false;
            }
        }
        $GLOBALS['email_last_error'] = "No DB connection for queue";
        logEmailError($to, $subject, $GLOBALS['email_last_error']);
        return false;
    }

    // Direct send via PHPMailer only — no mail() fallback
    if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASS)) {
        $GLOBALS['email_last_error'] = "SMTP not configured (check .env SMTP_USER/SMTP_PASS)";
        logEmailError($to, $subject, $GLOBALS['email_last_error']);
        return false;
    }
    $mailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
    if (!file_exists($mailerPath)) {
        $GLOBALS['email_last_error'] = "PHPMailer not found at $mailerPath — run composer install";
        logEmailError($to, $subject, $GLOBALS['email_last_error']);
        return false;
    }
    require_once $mailerPath;
    require_once dirname($mailerPath) . '/SMTP.php';
    require_once dirname($mailerPath) . '/Exception.php';

    $maxRetries = 1;
    $mail = $sharedMailer ?? null;
    for ($retry = 0; $retry <= $maxRetries; $retry++) {
        if ($mail === null) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        }
        $useOwnMailer = ($sharedMailer === null || $mail !== $sharedMailer);
        try {
            if ($useOwnMailer) {
                // Configure/connect here only when no shared connection exists
                // (or the shared one dropped and this is a fresh retry instance).
                $mail->isSMTP();
                $mail->SMTPDebug = 0;
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                // No forced AuthType: auto-negotiates (Gmail prefers LOGIN;
                // forcing PLAIN added round-trips and could fail outright).
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                if ((int)SMTP_PORT === 465) {
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }
                $mail->Port       = SMTP_PORT;
                $mail->Timeout    = 10;
                $mail->SMTPKeepAlive = true;
                $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
                $mail->isHTML(true);
                $mail->smtpConnect();
            }
            // Compose merely adds a new message on the reused connection; it does
            // NOT open a new socket because SMTPKeepAlive is set.
            $mail->clearAddresses();
            $mail->addAddress($to);
            $mail->Subject    = $subject;
            $mail->Body       = $body;
            $mail->send();
            logEmailSuccess($to, $subject, 'PHPMailer');
            return true;
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            if ($retry < $maxRetries && (
                stripos($errorMsg, 'connection') !== false ||
                stripos($errorMsg, 'timeout') !== false ||
                stripos($errorMsg, 'Could not connect') !== false
            )) {
                // A shared connection may have dropped; reconnect fresh for the retry.
                $mail = null;
                continue;
            }
            $GLOBALS['email_last_error'] = $errorMsg;
            logEmailError($to, $subject, $errorMsg);
            break;
        }
    }
    return false;
}