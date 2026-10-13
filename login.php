<?php
// ============================================================
// DB MIGRATION — run ONCE before deploying this version.
// Lock expiry columns change from DATETIME to Unix timestamps:
// -- UPDATE device_lockouts SET locked_until = UNIX_TIMESTAMP(locked_until)
// --   WHERE locked_until IS NOT NULL;
// -- UPDATE users SET locked_until = UNIX_TIMESTAMP(locked_until)
// --   WHERE locked_until IS NOT NULL;
// -- ALTER TABLE device_lockouts MODIFY locked_until INT UNSIGNED NOT NULL;
// -- ALTER TABLE users MODIFY locked_until INT UNSIGNED NULL;
// ============================================================


// ponytail: "local" is the TCP peer (REMOTE_ADDR), never HTTP_HOST — a crafted
// `Host: localhost` must not disable the HTTPS redirect or the Secure flag.
// Upgrade: behind a TLS-terminating proxy, $is_https needs X-Forwarded-Proto
// trusted from a proxy IP (mirror getClientIP's $TRUSTED_PROXIES pattern).
$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

// ponytail: the HTTP->HTTPS redirect only fires when APP_HOST is set and the
// incoming Host matches it. We never build a Location from the raw Host header
// (that is an open redirect). With APP_HOST unset there is NO force-HTTPS
// redirect — set APP_HOST=<yourdomain> in .env to re-enable it. The redirect
// target is the trusted APP_HOST value, not the request's Host.
$app_host = getenv('APP_HOST') ?: '';
if (!$is_local && !$is_https && $app_host !== ''
    && strcasecmp($_SERVER['HTTP_HOST'] ?? '', $app_host) === 0) {
    header('Location: https://' . $app_host . $_SERVER['REQUEST_URI']);
    exit;
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!$is_local && $is_https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// ============================================================
// 2. SESSION CONFIGURATION
// ============================================================

$cookie_secure = $is_https && !$is_local;
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $cookie_secure,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// ============================================================
// 3. CONSTANTS & INCLUDES
// ============================================================
define('MAX_ATTEMPTS', 3);          // per-account
define('LOCKOUT_WINDOW', 300);       // 5 minutes

define('DEVICE_MAX_ATTEMPTS', 3);    // per-device
define('DEVICE_LOCKOUT_WINDOW', 300);

define('IP_RATE_LIMIT', 20);         // mass-attack guard (per IP)
define('IP_RATE_WINDOW', 300);

// Decoy bcrypt hash — unknown emails take the same time as real ones.
// (Generated locally with password_hash(), cost 10 to match PASSWORD_DEFAULT.)
define('DUMMY_HASH', '$2y$10$hAauvCQCpvMg17hjvvvLQe9Xo18J//UltTXORydNvcAIlhCX5SfHm');

require_once 'config.php';

// perf_headers() (from config.php) resets this to SAMEORIGIN; login must never be frameable.
header('X-Frame-Options: DENY');

// ============================================================
// DEVICE ID (persistent cookie — identifies the physical device)
// ============================================================

function getDeviceId(): string {
    $cookieName = 'chd_device_id';
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // REMOTE_ADDR, not HTTP_HOST: same reasoning as the block at the top of this file.
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

    // Reuse existing valid cookie
    if (!empty($_COOKIE[$cookieName]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[$cookieName])) {
        return $_COOKIE[$cookieName];
    }

    // Generate new 128-bit random ID
    $id = bin2hex(random_bytes(16));

    // Set cookie for 1 year — persists across browser restarts
    if (!headers_sent()) {
        setcookie($cookieName, $id, [
            'expires'  => time() + (365 * 24 * 3600),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps && !$isLocal,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[$cookieName] = $id;

    return $id;
}

// ============================================================
// LOCKOUT HELPERS
// ============================================================

function checkDeviceLockout(PDO $pdo, string $deviceId): int {
    $stmt = $pdo->prepare(
        'SELECT locked_until FROM device_lockouts WHERE device_id = ? AND locked_until > ?'
    );
    $stmt->execute([$deviceId, time()]);
    $until = $stmt->fetchColumn();

    if ($until !== false) {
        return max(0, (int)$until - time());
    }

    // Cleanup expired row for this device
    $pdo->prepare('DELETE FROM device_lockouts WHERE device_id = ? AND locked_until <= ?')
        ->execute([$deviceId, time()]);

    return 0;
}

function activateDeviceLockout(PDO $pdo, string $deviceId, int $untilUnix, string $ip, string $ua): void {
    $pdo->prepare(
        'INSERT INTO device_lockouts (device_id, locked_until, ip_address, user_agent)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            locked_until = GREATEST(locked_until, VALUES(locked_until)),
            ip_address   = VALUES(ip_address),
            user_agent   = VALUES(user_agent)'
    )->execute([$deviceId, $untilUnix, $ip, mb_substr($ua, 0, 255)]);
}

function countDeviceFailures(PDO $pdo, string $deviceId, int $window = DEVICE_LOCKOUT_WINDOW): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE device_id = ? AND success = 0 AND attempt_time > ?'
    );
    $stmt->execute([$deviceId, time() - $window]);
    return (int) $stmt->fetchColumn();
}

function checkIPRateLimit(PDO $pdo, string $ip): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time > ?'
    );
    $stmt->execute([$ip, time() - IP_RATE_WINDOW]);
    return (int) $stmt->fetchColumn() >= IP_RATE_LIMIT;
}

function recordAttempt(PDO $pdo, string $email, string $ip, ?string $deviceId, bool $success): void {
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (email, ip_address, device_id, attempt_time, success)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$email, $ip, $deviceId, time(), $success ? 1 : 0]);
}

function lockMessage(int $seconds): string {
    $minutes = max(1, (int) ceil($seconds / 60));
    return "Too many failed login attempts from this device. It has been temporarily locked. "
         . "Please wait {$minutes} minute" . ($minutes > 1 ? 's' : '') . " before trying again.";
}

function accountLockMessage(int $seconds): string {
    $minutes = max(1, (int) ceil($seconds / 60));
    return "Too many failed login attempts for this account. It has been temporarily locked. "
         . "Please wait {$minutes} minute" . ($minutes > 1 ? 's' : '') . " before trying again.";
}

// ============================================================
// SECURITY ALERTS (failed logins / lockouts)
// ============================================================
// Emails are ONLY ever sent to the registered address resolved from the users
// row by id — never to the address typed into the form (that could be a
// victim's inbox under attacker control, or an arbitrary address).

// ponytail: throttle keyed on in_app_notifications (1 per user per 15 min).
// Move to a dedicated security_alert_log table if per-channel limits are needed.
function securityAlertThrottleAllows(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM in_app_notifications
          WHERE user_id = ?
            AND title = 'Security Alert: Failed Login Attempts'
            AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() === 0;
}

function buildSecurityAlertBody(string $ip, string $ua, bool $locked): string {
    $when  = date('F j, Y \a\t g:i:s A') . ' (Asia/Manila)';
    $ua    = trim(mb_substr($ua !== '' ? $ua : 'unknown', 0, 255));
    $intro = $locked
        ? 'Your CHED Compliance account has been temporarily LOCKED after too many failed login attempts.'
        : 'Someone failed to log in to your CHED Compliance account.';
    return $intro . "\n\n"
         . "Date & time: {$when}\n"
         . "IP address: {$ip}\n"
         . "Browser / device: {$ua}\n\n"
         . 'If this was you, wait for the lock to expire and sign in again. If not, someone may be '
         . 'attempting to access your account — change your password immediately and contact the '
         . 'system administrator. For your security, this message never contains passwords or codes.';
}

function sendLoginSecurityAlert(PDO $pdo, int $userId, string $ip, string $ua, bool $locked): void {
    try {
        createNotification(
            $pdo,
            $userId,
            'Security Alert: Failed Login Attempts',
            buildSecurityAlertBody($ip, $ua, $locked),
            'danger'
        );
    } catch (Throwable $e) {
        // Alert delivery must NEVER affect authentication or lockout enforcement.
        error_log('[CHED Compliance] Login security alert failed: ' . $e->getMessage());
    }
}

// ============================================================
// ATTEMPT LOGIN (per-account lockout — atomic)
// ============================================================

function attemptLogin(PDO $pdo, string $email, string $password, string $ip, string $deviceId): array {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->beginTransaction();

        $activeCols = !empty($GLOBALS['active_session_cols'])
            ? ', active_session_token, active_session_last_seen'
            : '';
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password, role, department_id,
                    failed_login_attempts, locked_until' . $activeCols . '
               FROM users
              WHERE email = ?
              FOR UPDATE'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            recordAttempt($pdo, $email, $ip, $deviceId, false);
            $pdo->commit();
            // Constant-time response: burn a verify against a decoy hash so
            // unknown-email requests are indistinguishable from real ones.
            password_verify($password, DUMMY_HASH);
            return ['status' => 'failed'];
        }

        $remaining = $user['locked_until'] ? ((int)$user['locked_until'] - time()) : 0;
        if ($remaining > 0) {
            // Record continued attempts during an active lockout (feeds the IP
            // rate-limit and device-failure counters); alert separately, throttled.
            recordAttempt($pdo, $email, $ip, $deviceId, false);
            $pdo->commit();
            return ['status' => 'locked', 'remaining' => $remaining, 'counted' => false, 'user_id' => (int) $user['id']];
        }

        if (password_verify($password, $user['password'])) {
            if (!empty($GLOBALS['active_session_cols'])) {
                // One live session per account: if the account already holds a
                // FRESH token, another browser/device owns that session — deny.
                // TTL matches the 5-min idle timeout, so a token last seen longer
                // ago is genuinely dead (the app would have idle-logged it out)
                // and can be taken over.
                $activeToken = $user['active_session_token'];
                $lastSeen    = (int) ($user['active_session_last_seen'] ?? 0);
                if ($activeToken !== null && (time() - $lastSeen) < ACTIVE_SESSION_TTL) {
                    $pdo->commit();
                    return ['status' => 'already_active', 'user_id' => (int) $user['id']];
                }
            }
            $sessionToken = bin2hex(random_bytes(32));
            $setCols   = 'failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW()';
            $params    = [];
            if (!empty($GLOBALS['active_session_cols'])) {
                $setCols .= ', active_session_token = ?, active_session_last_seen = ?';
                $params   = [$sessionToken, time()];
            }
            $params[] = $user['id'];
            $pdo->prepare("UPDATE users SET {$setCols} WHERE id = ?")->execute($params);
            recordAttempt($pdo, $email, $ip, $deviceId, true);
            $pdo->commit();
            return ['status' => 'ok', 'user' => $user, 'session_token' => $sessionToken];
        }

        recordAttempt($pdo, $email, $ip, $deviceId, false);

        $count = ($user['locked_until'] && (int)$user['locked_until'] <= time())
            ? 1
            : ((int) $user['failed_login_attempts']) + 1;
        $until = $count >= MAX_ATTEMPTS ? (time() + LOCKOUT_WINDOW) : null;
        $pdo->prepare(
            'UPDATE users SET failed_login_attempts = ?, locked_until = ?, last_failed_login_at = NOW() WHERE id = ?'
        )->execute([$count, $until, $user['id']]);

        $pdo->commit();

        if ($count >= MAX_ATTEMPTS) {
            return ['status' => 'locked', 'remaining' => LOCKOUT_WINDOW, 'counted' => true, 'user_id' => (int) $user['id']];
        }
        return ['status' => 'failed'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ============================================================
// 4. STATE
// ============================================================

$ip         = getClientIP();
$device_id  = getDeviceId();
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$email      = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
if (strlen($email) > 254) { $email = ''; }   // RFC 5321 max; longer is never a real address
$error      = '';
$lock_remaining = 0;
$lock_total     = 0;   // ← denominator for the progress bar; set alongside $lock_remaining

// ponytail: cleanup runs on ~1% of GETs so bots hitting /login.php don't get
// free write amplification. Rows are tiny; delayed pruning is harmless.
// Upgrade: move to a cron/task-scheduler sweep if the table ever grows large.
if (random_int(1, 100) === 1) {
    $cleanup_stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempt_time < ?');
    $cleanup_stmt->execute([time() - 3600]);

    // Prune lockout rows long expired (active ones are cleaned per-device too)
    $device_cleanup_stmt = $pdo->prepare('DELETE FROM device_lockouts WHERE locked_until < ?');
    $device_cleanup_stmt->execute([time() - 86400]);
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':      header('Location: admin_dashboard.php'); exit;
        case 'department':
        case 'unit_head':  header('Location: program_head_dashboard.php'); exit;
        case 'teacher':
        case 'staff':      header('Location: teacher_dashboard.php'); exit;
        default:           header('Location: login.php'); exit;
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Pre-check on GET (show lockout modal if device already locked)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $devicePreLock = checkDeviceLockout($pdo, $device_id);
    if ($devicePreLock > 0) {
        $lock_remaining = $devicePreLock;
        $lock_total     = DEVICE_LOCKOUT_WINDOW;      // device lock → device window
        $error = lockMessage($devicePreLock);
    } elseif (!empty($_SESSION['locked_email'])) {
        $hintStmt = $pdo->prepare('SELECT locked_until FROM users WHERE email = ?');
        $hintStmt->execute([$_SESSION['locked_email']]);
        $hintUntil = $hintStmt->fetchColumn();
        $hintRemaining = $hintUntil ? ((int)$hintUntil - time()) : 0;
        if ($hintRemaining > 0) {
            $lock_remaining = $hintRemaining;
            $lock_total     = LOCKOUT_WINDOW;         // account lock → account window
            $error = accountLockMessage($hintRemaining);
        } else {
            unset($_SESSION['locked_email']);
        }
    }
}

// ============================================================
// 5. HANDLE POST REQUEST
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $password = is_string($_POST['password'] ?? '') ? $_POST['password'] : '';

        // A lockout hint for a previous email must not leak into this attempt.
        if (isset($_SESSION['locked_email']) && $_SESSION['locked_email'] !== $email) {
            unset($_SESSION['locked_email']);
        }

        // ─── Layer 1: Device lockout (checked FIRST) ─────────────
        $deviceLockRemaining = checkDeviceLockout($pdo, $device_id);

        if ($deviceLockRemaining > 0) {
            $lock_remaining = $deviceLockRemaining;
            $lock_total     = DEVICE_LOCKOUT_WINDOW;      // device lock → device window
            $error = lockMessage($deviceLockRemaining);
        }
        // ─── Layer 2: IP rate-limit (mass-attack guard) ──────────
        elseif (checkIPRateLimit($pdo, $ip)) {
            $error = 'Too many requests from this network. Please wait a few minutes.';
        }
        // ─── Input validation ────────────────────────────────────
        elseif (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        }
        // ─── Layer 3: Account authentication ─────────────────────
        else {
            $auth = attemptLogin($pdo, $email, $password, $ip, $device_id);

                if ($auth['status'] === 'locked') {
                $lock_remaining = $auth['remaining'];
                $lock_total     = LOCKOUT_WINDOW;         // account lock → account window
                $error = accountLockMessage($lock_remaining);
                $_SESSION['locked_email'] = $email;

                if (!empty($auth['counted'])) {
                    logAudit($pdo, 'login_failed', 'Account locked: ' . $email . ' from IP: ' . $ip);

                    // Also lock the device (same 5-min window)
                    $until = time() + DEVICE_LOCKOUT_WINDOW;
                    activateDeviceLockout($pdo, $device_id, $until, $ip, $user_agent);
                }

                // Throttled security alert on lockout — must never break authentication.
                try {
                    if (securityAlertThrottleAllows($pdo, (int) $auth['user_id'])) {
                        sendLoginSecurityAlert($pdo, (int) $auth['user_id'], $ip, $user_agent, true);
                    }
                } catch (Throwable $e) {
                    error_log('[CHED Compliance] Login security alert skipped: ' . $e->getMessage());
                }
            }
            elseif ($auth['status'] === 'ok') {
                $user = $auth['user'];
                unset($_SESSION['locked_email']);

                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $updateStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $updateStmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }

                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['session_token'] = $auth['session_token'];

                $_SESSION['user_id']       = $user['id'];
                $_SESSION['user_name']     = $user['name'];
                $_SESSION['email']         = $user['email'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['department_id'] = $user['department_id'];
                $_SESSION['last_activity'] = time();
                $_SESSION['last_regeneration'] = time();

                if ($user['role'] === 'admin') {
                    $_SESSION['admin_id'] = $user['id'];
                } elseif (in_array($user['role'], ['department', 'unit_head'], true)) {
                    $_SESSION['department_user_id'] = $user['id'];
                }

                logAudit($pdo, 'login', 'User logged in: ' . $user['email']);

                switch ($user['role']) {
                    case 'admin':      header('Location: admin_dashboard.php'); break;
                    case 'department':
                    case 'unit_head':  header('Location: program_head_dashboard.php'); break;
                    case 'teacher':
                    case 'staff':      header('Location: teacher_dashboard.php'); break;
                    default:           header('Location: login.php');
                }
                exit;
            }
            elseif ($auth['status'] === 'already_active') {
                logAudit($pdo, 'login_denied_active_session', 'Duplicate login blocked: ' . $email . ' from IP: ' . $ip);
                $error = 'This account is already logged in on another browser or device. Log out there and try again.';
            }
            else {
                logAudit($pdo, 'login_failed', 'Failed login attempt for: ' . $email . ' from IP: ' . $ip);
                // ponytail: alert fires only on LOCKOUT (3+ failures), not per typo —
                // raises attacker cost per alert and kills false-positive emails.

                $deviceFailures = countDeviceFailures($pdo, $device_id, DEVICE_LOCKOUT_WINDOW);
                if ($deviceFailures >= DEVICE_MAX_ATTEMPTS) {
                    $until = time() + DEVICE_LOCKOUT_WINDOW;
                    activateDeviceLockout($pdo, $device_id, $until, $ip, $user_agent);
                    $lock_remaining = DEVICE_LOCKOUT_WINDOW;
                    $lock_total     = DEVICE_LOCKOUT_WINDOW;   // device lock → device window
                    $error = lockMessage(DEVICE_LOCKOUT_WINDOW);
                } else {
                    $error = 'Invalid email or password.';
                }
            }
        }
    }
}

$is_locked     = ($lock_remaining > 0);
$lock_minutes  = (int) floor($lock_remaining / 60);
$lock_seconds  = $lock_remaining % 60;
$lock_display  = sprintf('%02d:%02d', $lock_minutes, $lock_seconds);

// Absolute unlock time in ms, so the client never has to decrement.
$unlock_at_ms = (int) ((time() + $lock_remaining) * 1000);

// Correct denominator for the progress bar (matches whichever lock actually fired)
if ($lock_total <= 0) {
    $lock_total = $lock_remaining > 0 ? $lock_remaining : 1;   // safety fallback
}
$lock_initial_pct = $lock_remaining > 0
    ? (int) round(min(100, max(0, ($lock_remaining / $lock_total) * 100)))
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - USAT College Sagay City Inc.</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ===== GLOBAL RESET & VARIABLES ===== */
        :root {
            --primary: #5b4eff;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --accent: #22d3ee;
            --accent-light: #7dd3fc;
            --navy-900: #0b1424;
            --navy-800: #152238;
            --navy-700: #1e2f4d;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --white: #ffffff;
            --border: #cbd5e1;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            color: var(--gray-800);
            -webkit-font-smoothing: antialiased;
            position: relative;
            overflow-x: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 16px;
            background: #0f172a;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: url('picture/ched.png') center / cover no-repeat;
            filter: blur(1px) brightness(1.05) saturate(1.15);
            transform: scale(1.03);
            z-index: 0;
            opacity: 0.95;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.25) 0%, rgba(15, 23, 42, 0.15) 100%);
            z-index: 0;
            pointer-events: none;
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 800px;
            min-height: 480px;
            display: flex;
            border-radius: 22px;
            overflow: hidden;
            box-shadow:
                0 30px 60px -15px rgba(0, 0, 0, 0.75),
                0 0 0 1px rgba(255, 255, 255, 0.08);
            animation: containerIn 0.6s ease-out;
            background: #ffffff;
        }

        @keyframes containerIn {
            0%   { opacity: 0; transform: translateY(24px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .brand-panel {
            flex: 0 0 42%;
            background: linear-gradient(160deg, #1b2b45 0%, #152238 45%, #0b1424 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 44px 28px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 50% 22%, rgba(91, 78, 255, 0.22) 0%, transparent 62%),
                radial-gradient(circle at 85% 90%, rgba(34, 211, 238, 0.12) 0%, transparent 55%),
                linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 26px 26px, 26px 26px;
            pointer-events: none;
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg,
                rgba(34, 211, 238, 0) 0%,
                rgba(34, 211, 238, 0.85) 45%,
                rgba(34, 211, 238, 0) 100%);
            pointer-events: none;
        }

        .brand-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .logo-ring {
            position: relative;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 22px;
            background: radial-gradient(circle at 50% 38%,
                rgba(34, 211, 238, 0.20) 0%,
                rgba(255, 255, 255, 0.04) 62%,
                rgba(0, 0, 0, 0.25) 100%);
            border: 1px solid rgba(34, 211, 238, 0.38);
            box-shadow:
                0 0 45px rgba(34, 211, 238, 0.18),
                inset 0 0 32px rgba(0, 0, 0, 0.45);
        }

        .logo-ring::before {
            content: '';
            position: absolute;
            inset: -9px;
            border-radius: 50%;
            border: 1px dashed rgba(34, 211, 238, 0.30);
            animation: ringSpin 26s linear infinite;
            pointer-events: none;
        }

        @keyframes ringSpin { to { transform: rotate(360deg); } }

        .brand-logo {
            width: 92px;
            height: 92px;
            object-fit: contain;
            position: relative;
            z-index: 1;
            border-radius: 50%;
            animation: logoFloat 3.5s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% {
                transform: translateY(0) scale(1);
                filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.55))
                        drop-shadow(0 0 0 rgba(34, 211, 238, 0));
            }
            50% {
                transform: translateY(-10px) scale(1.045);
                filter: drop-shadow(0 20px 30px rgba(0, 0, 0, 0.6))
                        drop-shadow(0 0 26px rgba(34, 211, 238, 0.6));
            }
        }

        .school-name {
            font-size: 1.32rem;
            font-weight: 900;
            line-height: 1.28;
            letter-spacing: 0.035em;
            text-transform: uppercase;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
            background: linear-gradient(180deg, #ffffff 0%, #a5f3fc 62%, #22d3ee 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.55));
        }

        .school-divider {
            width: 64px;
            height: 4px;
            border-radius: 999px;
            background: linear-gradient(90deg, #67e8f9, #22d3ee);
            box-shadow: 0 0 18px rgba(34, 211, 238, 0.85);
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .school-tagline {
            font-size: 0.66rem;
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.68);
            line-height: 1.75;
            position: relative;
            z-index: 1;
            margin-bottom: 18px;
        }

        .school-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 15px;
            border-radius: 999px;
            border: 1px solid rgba(34, 211, 238, 0.45);
            background: rgba(34, 211, 238, 0.13);
            color: var(--accent-light);
            font-size: 0.6rem;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            position: relative;
            z-index: 1;
            box-shadow: 0 0 22px rgba(34, 211, 238, 0.12) inset;
        }

        .school-badge i { font-size: 0.68rem; color: var(--accent); }

        .brand-location {
            margin-top: 12px;
            font-size: 0.58rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.4);
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .brand-location i { color: rgb(239, 242, 39); margin-right: 5px; }

        .brand-roles {
            margin-top: 22px;
            width: 100%;
            max-width: 270px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 9px;
            position: relative;
            z-index: 1;
        }

        .brand-roles .role-label {
            font-size: 0.56rem;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.45);
        }

        .brand-roles .pill-row {
            display: flex;
            justify-content: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .brand-roles .pill {
            background: rgba(255, 255, 255, 0.07);
            padding: 4px 13px;
            border-radius: 8px;
            font-size: 0.6rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.78);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: 1px solid rgba(34, 211, 238, 0.35);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            transition: var(--transition);
            white-space: nowrap;
        }

        .brand-roles .pill:hover {
            background: rgba(34, 211, 238, 0.16);
            border-color: rgba(34, 211, 238, 0.75);
            color: #7dd3fc;
        }

        .form-panel {
            flex: 1;
            background: #ffffff;
            padding: 42px 46px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .form-header { margin-bottom: 26px; }

        .form-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--primary);
            background: var(--primary-light);
            border: 1px solid #ddd9ff;
            padding: 5px 11px;
            border-radius: 7px;
            margin-bottom: 12px;
        }

        .form-eyebrow i { font-size: 0.62rem; }

        .form-header h2 {
            font-size: 1.68rem;
            font-weight: 800;
            color: var(--gray-900);
            letter-spacing: -0.02em;
            margin-bottom: 5px;
        }

        .form-header p {
            color: var(--gray-500);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #ef4444;
            border-radius: 10px;
            padding: 10px 14px;
            color: #b91c1c;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(6px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }

        .alert i { color: #ef4444; font-size: 1rem; flex-shrink: 0; }

        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 6px;
            letter-spacing: 0.01em;
        }

        .form-group label i {
            margin-right: 6px;
            color: var(--gray-400);
            font-size: 0.85rem;
        }

        .input-wrapper { position: relative; }

        .form-control {
            width: 100%;
            padding: 11px 42px 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            color: var(--gray-800);
            background: #ffffff;
            transition: var(--transition);
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(91, 78, 255, 0.15);
        }

        .form-control::placeholder { color: var(--gray-400); font-weight: 400; }

        .form-control:disabled {
            background: var(--gray-100);
            color: var(--gray-400);
            cursor: not-allowed;
            border-color: var(--gray-200);
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 1rem;
            padding: 4px;
            transition: color 0.2s;
            border-radius: 6px;
            line-height: 1;
        }

        .password-toggle:hover:not(:disabled) { color: var(--primary); }
        .password-toggle:disabled { cursor: not-allowed; opacity: 0.5; }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #5b4eff 0%, #4338ca 100%);
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--white);
            cursor: pointer;
            transition: var(--transition);
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(91, 78, 255, 0.32);
            position: relative;
            overflow: hidden;
        }

        .btn-submit::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.18), transparent);
            pointer-events: none;
        }

        .btn-submit:hover:not(:disabled) {
            background: linear-gradient(135deg, #4a3ce0 0%, #3730a3 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(91, 78, 255, 0.42);
        }

        .btn-submit:active:not(:disabled) { transform: scale(0.98); }
        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .form-footer {
            text-align: center;
            margin-top: 22px;
            border-top: 1px solid var(--gray-100);
            padding-top: 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }

        .security-note {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: 9px;
            width: 100%;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 10px 13px;
            text-align: left;
        }

        .security-note > i {
            color: black;
            font-size: 0.88rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .security-note p {
            margin: 0;
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--gray-600);
            line-height: 1.55;
            letter-spacing: 0.01em;
        }

        .security-note p span {
            display: block;
            font-weight: 500;
            color: var(--gray-400);
            margin-top: 2px;
        }

        .copyright {
            font-size: 0.62rem;
            letter-spacing: 0.05em;
            color: var(--gray-400);
            font-weight: 600;
        }

        .copyright strong { color: var(--gray-600); font-weight: 800; }

        .lock-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 999;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: overlayIn 0.3s ease-out;
        }

        @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }

        .lock-modal {
            width: 100%;
            max-width: 380px;
            background: #ffffff;
            border-radius: 18px;
            padding: 30px 24px 24px;
            text-align: center;
            box-shadow: 0 30px 70px -20px rgba(0, 0, 0, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.6);
            position: relative;
            overflow: hidden;
            animation: modalIn 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .lock-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #22d3ee, #dc2626, #22d3ee);
        }

        @keyframes modalIn {
            0%   { opacity: 0; transform: scale(0.88) translateY(18px); }
            100% { opacity: 1; transform: scale(1)    translateY(0); }
        }

        .lock-modal-icon {
            width: 66px;
            height: 66px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #dc2626;
            animation: pulseLock 2s ease-in-out infinite;
        }

        @keyframes pulseLock {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.45); }
            50%      { box-shadow: 0 0 0 14px rgba(239, 68, 68, 0); }
        }

        .lock-modal h2 {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--gray-900);
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }

        .lock-modal p.lock-desc {
            font-size: 0.82rem;
            color: var(--gray-500);
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .lock-modal .lock-timer-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 14px 12px 12px;
            margin-bottom: 18px;
        }

        .lock-modal .lock-timer {
            font-size: 2.3rem;
            font-weight: 800;
            color: #dc2626;
            letter-spacing: 0.08em;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
        }

        .lock-modal .lock-timer-label {
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: #b91c1c;
            opacity: 0.8;
            font-weight: 700;
            margin-top: 6px;
        }

        .lock-modal .lock-progress {
            height: 5px;
            background: #fee2e2;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 18px;
        }

        .lock-modal .lock-progress-bar {
            height: 100%;
            width: 100%;
            background: linear-gradient(90deg, #ef4444, #dc2626);
            border-radius: 999px;
            transition: width 1s linear;
        }

        .lock-modal .lock-note {
            font-size: 0.7rem;
            color: var(--gray-400);
            line-height: 1.5;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        @media (max-width: 780px) {
            .login-container { flex-direction: column; max-width: 430px; min-height: auto; border-radius: 18px; }
            .brand-panel { padding: 34px 24px 30px; flex: none; }
            .brand-panel::after {
                top: auto; bottom: 0; right: 0;
                width: 100%; height: 4px;
                background: linear-gradient(90deg,
                    rgba(34, 211, 238, 0) 0%,
                    rgba(34, 211, 238, 0.85) 50%,
                    rgba(34, 211, 238, 0) 100%);
            }
            .logo-ring { width: 112px; height: 112px; margin-bottom: 16px; }
            .brand-logo { width: 74px; height: 74px; }
            .school-name { font-size: 1.18rem; }
            .school-tagline { margin-bottom: 14px; }
            .form-panel { padding: 32px 26px; }
            .form-header h2 { font-size: 1.45rem; }
            .brand-roles { margin-top: 18px; }
        }

        @media (max-width: 480px) {
            body { padding: 12px; }
            .brand-panel { padding: 28px 18px 24px; }
            .logo-ring { width: 96px; height: 96px; }
            .brand-logo { width: 62px; height: 62px; }
            .school-name { font-size: 1.03rem; }
            .school-divider { width: 50px; }
            .school-tagline { font-size: 0.6rem; }
            .form-panel { padding: 26px 18px; }
            .form-header h2 { font-size: 1.3rem; }
            .form-eyebrow { font-size: 0.54rem; }
            .brand-roles .pill { font-size: 0.54rem; padding: 4px 10px; }
            .security-note p { font-size: 0.64rem; }
            .lock-modal { padding: 26px 18px 20px; border-radius: 14px; }
            .lock-modal .lock-timer { font-size: 2rem; }
            .lock-modal-icon { width: 58px; height: 58px; font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="login-container">

    <!-- ============================================================
         LEFT PANEL – Branding with ANIMATED LOGO
         ============================================================ -->
    <aside class="brand-panel">
        <div class="brand-content">

            <div class="logo-ring">
                <img src="picture/love.jpg" alt="USAT College Sagay City Inc. Logo" class="brand-logo">
            </div>

            <h1 class="school-name">USAT College<br>Sagay City Inc.</h1>

            <div class="school-divider" aria-hidden="true"></div>

            <p class="school-tagline">Compliance &amp; Document<br>Management System</p>

            <div class="brand-location">
                <i class="fas fa-location-dot"></i> Sagay City, Negros Occidental
            </div>

            <div class="brand-roles">
                <span class="role-label">Authorized Roles</span>
                <div class="pill-row">
                    <span class="pill">Admin</span>
                    <span class="pill">Program Head / Unit Head</span>
                    <span class="pill">Faculty / Staff</span>
                </div>
            </div>

        </div>
    </aside>

    <!-- ============================================================
         RIGHT PANEL – Login Form
         ============================================================ -->
    <div class="form-panel">

        <div class="form-header">
            <h2>Welcome Back</h2>
            <p>Sign in to access your compliance portal dashboard</p>
        </div>

        <?php if (!$is_locked && $error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                <div class="input-wrapper">
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= htmlspecialchars($email) ?>"
                           placeholder="name@usat.edu.ph" required autofocus
                           autocomplete="username"
                           <?= $is_locked ? 'disabled' : '' ?>>
                </div>
            </div>

            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <div class="input-wrapper">
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="••••••••" required
                           autocomplete="current-password"
                           <?= $is_locked ? 'disabled' : '' ?>>
                    <button type="button" class="password-toggle" id="togglePassword"
                            aria-label="Toggle password visibility"
                            title="Show password"
                            <?= $is_locked ? 'disabled' : '' ?>>
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn" <?= $is_locked ? 'disabled' : '' ?>>
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>

        <div class="form-footer">
            <div class="security-note">
                <i class="fas fa-user-shield"></i>
                <p>
                    Authorized access only. All activities are monitored.
                    <span>Contact the System Administrator for credentials.</span>
                </p>
            </div>

            <div class="copyright">
                &copy; 2026 <strong>USAT College Sagay City Inc.</strong> &middot; All rights reserved.
            </div>
        </div>

    </div><!-- /.form-panel -->

</div><!-- /.login-container -->

<?php if ($is_locked): ?>
<div class="lock-modal-overlay" id="lockModalOverlay" role="dialog" aria-modal="true" aria-labelledby="lockModalTitle">
    <div class="lock-modal">
        <div class="lock-modal-icon">
            <i class="fas fa-lock"></i>
        </div>

        <h2 id="lockModalTitle">Login Temporarily Locked</h2>
        <p class="lock-desc">
            <?= htmlspecialchars($error) ?>
        </p>

        <div class="lock-timer-box">
            <div class="lock-timer" id="lockTimer"><?= htmlspecialchars($lock_display) ?></div>
            <div class="lock-timer-label">Time remaining</div>
        </div>

        <div class="lock-progress">
            <div class="lock-progress-bar" id="lockProgressBar"
                 style="width: <?= (int) $lock_initial_pct ?>%;"></div>
        </div>

        <div class="lock-note">
            <i class="fas fa-info-circle"></i>
            <span>This page will automatically unlock when the timer reaches&nbsp;00:00.</span>
        </div>
    </div>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput  = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const isHidden = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isHidden ? 'text' : 'password');

            const icon = this.querySelector('i');
            if (isHidden) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                this.setAttribute('title', 'Hide password');
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                this.setAttribute('title', 'Show password');
            }
        });
    }

    const LOCK_TOTAL    = <?= (int) $lock_total ?>;   // correct window for the lock that actually fired
    const UNLOCK_AT_MS  = <?= (int) $unlock_at_ms ?>;

    function formatLockTime(totalSeconds) {
        if (totalSeconds < 0) totalSeconds = 0;
        const m = Math.floor(totalSeconds / 60);
        const s = totalSeconds % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function secondsUntilUnlock() {
        return Math.max(0, Math.ceil((UNLOCK_AT_MS - Date.now()) / 1000));
    }

    if (UNLOCK_AT_MS > Date.now()) {
        const timerEl   = document.getElementById('lockTimer');
        const barEl     = document.getElementById('lockProgressBar');
        const formEl    = document.getElementById('loginForm');
        const submitEl  = document.getElementById('submitBtn');
        const overlayEl = document.getElementById('lockModalOverlay');

        if (formEl) {
            formEl.querySelectorAll('input, button').forEach(el => { el.disabled = true; });
        }
        if (submitEl) submitEl.disabled = true;

        function render() {
            const s = secondsUntilUnlock();

            if (timerEl) timerEl.textContent = formatLockTime(s);

            if (barEl) {
                const denom = LOCK_TOTAL > 0 ? LOCK_TOTAL : 1;   // never divide by zero
                const pct = Math.max(0, Math.min(100, (s / denom) * 100));
                barEl.style.width = pct + '%';
            }

            if (s <= 0) {
                clearInterval(tick);
                setTimeout(function () {
                    if (overlayEl) {
                        overlayEl.style.transition = 'opacity 0.3s ease';
                        overlayEl.style.opacity = '0';
                    }
                    setTimeout(function () { window.location.reload(); }, 300);
                }, 700);
            }
        }

        render();
        const tick = setInterval(render, 1000);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) render();
        });
    }

    window.addEventListener('pageshow', function (e) {
        if (e.persisted) window.location.reload();
    });

    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
</script>

</body>
</html>