<?php
$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

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

define('MAX_ATTEMPTS', 3);
define('LOCKOUT_WINDOW', 300);
define('DEVICE_MAX_ATTEMPTS', 3);
define('DEVICE_LOCKOUT_WINDOW', 300);
define('IP_RATE_LIMIT', 20);
define('IP_RATE_WINDOW', 300);

define('DUMMY_HASH', '$2y$10$hAauvCQCpvMg17hjvvvLQe9Xo18J//UltTXORydNvcAIlhCX5SfHm');

require_once 'config.php';

header('X-Frame-Options: DENY');

function getDeviceId(): string {
    $cookieName = 'chd_device_id';
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

    if (!empty($_COOKIE[$cookieName]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[$cookieName])) {
        return $_COOKIE[$cookieName];
    }

    $id = bin2hex(random_bytes(16));

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

function buildSecurityAlertBody(string $ip, string $ua): string {
    $when = date('F j, Y \a\t g:i:s A') . ' (Asia/Manila)';
    $ua   = trim(mb_substr($ua !== '' ? $ua : 'unknown', 0, 255));
    return 'Someone has been trying to sign in to a CHED Compliance account, and it has been '
         . 'temporarily LOCKED after too many failed login attempts.' . "\n\n"
         . "Date & time: {$when}\n"
         . "IP address: {$ip}\n"
         . "Browser / device: {$ua}\n\n"
         . 'No action is needed unless this activity is unfamiliar — the locked account can sign in '
         . 'again once the lock expires. For security, this alert never contains passwords, codes, '
         . 'or account details.';
}

function sendLoginSecurityAlert(PDO $pdo, string $ip, string $ua): void {
    try {
        $body   = buildSecurityAlertBody($ip, $ua);
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($admins as $admin) {
            if (!securityAlertThrottleAllows($pdo, (int) $admin['id'])) {
                continue;
            }
            createNotification($pdo, (int) $admin['id'], 'Security Alert: Failed Login Attempts', $body, 'danger');
        }
    } catch (Throwable $e) {
        error_log('[CHED Compliance] Login security alert failed: ' . $e->getMessage());
    }
}

function attemptLogin(PDO $pdo, string $email, string $password, string $ip, string $deviceId): array {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->beginTransaction();

        $activeCols = !empty($GLOBALS['active_session_cols'])
            ? ', active_session_token, active_session_last_seen'
            : '';
        $isActiveCol = !empty($GLOBALS['users_is_active_col']) ? ', is_active' : '';
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password, role, department_id,
                    failed_login_attempts, locked_until' . $activeCols . $isActiveCol . '
               FROM users
              WHERE email = ?
              FOR UPDATE'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            recordAttempt($pdo, $email, $ip, $deviceId, false);
            $pdo->commit();
            password_verify($password, DUMMY_HASH);
            return ['status' => 'failed'];
        }

        $remaining = $user['locked_until'] ? ((int)$user['locked_until'] - time()) : 0;
        if ($remaining > 0) {
            recordAttempt($pdo, $email, $ip, $deviceId, false);
            $pdo->commit();
            return ['status' => 'locked', 'remaining' => $remaining, 'counted' => false, 'user_id' => (int) $user['id']];
        }

        if (password_verify($password, $user['password'])) {
            if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                recordAttempt($pdo, $email, $ip, $deviceId, false);
                $pdo->commit();
                return ['status' => 'deactivated'];
            }
            if (!empty($GLOBALS['active_session_cols'])) {
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
if (strlen($email) > 254) { $email = ''; }
$error      = '';
$lock_remaining = 0;
$lock_total     = 0;

if (random_int(1, 100) === 1) {
    $cleanup_stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempt_time < ?');
    $cleanup_stmt->execute([time() - 3600]);

    $device_cleanup_stmt = $pdo->prepare('DELETE FROM device_lockouts WHERE locked_until < ?');
    $device_cleanup_stmt->execute([time() - 86400]);
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':      header('Location: admin_dashboard.php'); exit;
        case 'super_admin': header('Location: super_admin_dashboard.php'); exit;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $devicePreLock = checkDeviceLockout($pdo, $device_id);
    if ($devicePreLock > 0) {
        $lock_remaining = $devicePreLock;
        $lock_total     = DEVICE_LOCKOUT_WINDOW;
        $error = lockMessage($devicePreLock);
    } elseif (!empty($_SESSION['locked_email'])) {
        $hintStmt = $pdo->prepare('SELECT locked_until FROM users WHERE email = ?');
        $hintStmt->execute([$_SESSION['locked_email']]);
        $hintUntil = $hintStmt->fetchColumn();
        $hintRemaining = $hintUntil ? ((int)$hintUntil - time()) : 0;
        if ($hintRemaining > 0) {
            $lock_remaining = $hintRemaining;
            $lock_total     = LOCKOUT_WINDOW;
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

        if (isset($_SESSION['locked_email']) && $_SESSION['locked_email'] !== $email) {
            unset($_SESSION['locked_email']);
        }

        $deviceLockRemaining = checkDeviceLockout($pdo, $device_id);

        if ($deviceLockRemaining > 0) {
            $lock_remaining = $deviceLockRemaining;
            $lock_total     = DEVICE_LOCKOUT_WINDOW;
            $error = lockMessage($deviceLockRemaining);
        }
        elseif (checkIPRateLimit($pdo, $ip)) {
            $error = 'Too many requests from this network. Please wait a few minutes.';
        }
        elseif (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        }
        else {
            $auth = attemptLogin($pdo, $email, $password, $ip, $device_id);

            if ($auth['status'] === 'locked') {
                $lock_remaining = $auth['remaining'];
                $lock_total     = LOCKOUT_WINDOW;
                $error = accountLockMessage($lock_remaining);
                $_SESSION['locked_email'] = $email;

                if (!empty($auth['counted'])) {
                    logAudit($pdo, 'login_failed', 'Account locked: ' . $email . ' from IP: ' . $ip);
                    $until = time() + DEVICE_LOCKOUT_WINDOW;
                    activateDeviceLockout($pdo, $device_id, $until, $ip, $user_agent);
                }

                try {
                    sendLoginSecurityAlert($pdo, $ip, $user_agent);
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
                    case 'super_admin': header('Location: super_admin_dashboard.php'); break;
                    case 'department':
                    case 'unit_head':  header('Location: program_head_dashboard.php'); break;
                    case 'teacher':
                    case 'staff':      header('Location: teacher_dashboard.php'); break;
                    default:           header('Location: login.php');
                }
                exit;
            }
            elseif ($auth['status'] === 'deactivated') {
                logAudit($pdo, 'login_denied_deactivated', 'Deactivated account sign-in blocked: ' . $email . ' from IP: ' . $ip);
                $error = 'This account has been deactivated. Contact the administrator.';
            }
            elseif ($auth['status'] === 'already_active') {
                logAudit($pdo, 'login_denied_active_session', 'Duplicate login blocked: ' . $email . ' from IP: ' . $ip);
                $error = 'This account is already logged in on another browser or device. Log out there and try again.';
            }
            else {
                logAudit($pdo, 'login_failed', 'Failed login attempt for: ' . $email . ' from IP: ' . $ip);
                $deviceFailures = countDeviceFailures($pdo, $device_id, DEVICE_LOCKOUT_WINDOW);
                if ($deviceFailures >= DEVICE_MAX_ATTEMPTS) {
                    $until = time() + DEVICE_LOCKOUT_WINDOW;
                    activateDeviceLockout($pdo, $device_id, $until, $ip, $user_agent);
                    $lock_remaining = DEVICE_LOCKOUT_WINDOW;
                    $lock_total     = DEVICE_LOCKOUT_WINDOW;
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

$unlock_at_ms = (int) ((time() + $lock_remaining) * 1000);

if ($lock_total <= 0) {
    $lock_total = $lock_remaining > 0 ? $lock_remaining : 1;
}
$lock_initial_pct = $lock_remaining > 0
    ? (int) round(min(100, max(0, ($lock_remaining / $lock_total) * 100)))
    : 0;

// Auto-open the login modal so the user sees POST errors immediately.
$auto_open_login = ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="USAT College Sagay City Inc. — Compliance & Document Management System. Secure portal for CHED compliance submissions, accreditation documents, and institutional reporting.">
    <meta name="theme-color" content="#070d18">
    <title>USAT College Sagay City Inc. — Compliance &amp; Document Management System</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <script nonce="<?= csp_nonce() ?>">document.documentElement.classList.add('js');</script>

    <style>
        /* ============================================================
           1. TOKENS & RESET
           ============================================================ */
        :root {
            --primary: #5b4eff;
            --primary-dark: #4338ca;
            --primary-deep: #3730a3;
            --primary-light: #eef2ff;
            --accent: #22d3ee;
            --accent-light: #7dd3fc;
            --accent-soft: #e0f7fd;

            --navy-950: #05090f;
            --navy-900: #070d18;
            --navy-800: #0b1424;
            --navy-700: #152238;
            --navy-600: #1e2f4d;

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

            --radius-sm: 10px;
            --radius: 16px;
            --radius-lg: 22px;
            --radius-xl: 28px;

            --shadow-sm: 0 1px 2px rgba(16, 24, 40, .06);
            --shadow-md: 0 10px 24px -10px rgba(16, 24, 40, .25);
            --shadow-lg: 0 24px 48px -18px rgba(16, 24, 40, .32);
            --shadow-xl: 0 40px 80px -24px rgba(2, 8, 23, .65);

            --transition: all .22s cubic-bezier(.4, 0, .2, 1);
            --font: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: var(--font);
            background: #f7f8fc;
            color: var(--gray-800);
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        body.modal-open { overflow: hidden; }

        img { max-width: 100%; display: block; }
        a { color: inherit; text-decoration: none; }
        button { font-family: inherit; }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        section { scroll-margin-top: 92px; }

        /* ============================================================
           2. NAVBAR
           ============================================================ */
        .navbar {
            position: fixed;
            inset: 0 0 auto 0;
            z-index: 100;
            padding: 14px 0;
            transition: background .3s ease, box-shadow .3s ease, padding .3s ease,
                        border-color .3s ease, backdrop-filter .3s ease;
            border-bottom: 1px solid transparent;
        }

        .navbar.scrolled {
            padding: 9px 0;
            background: rgba(7, 13, 24, .82);
            backdrop-filter: blur(16px) saturate(160%);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            border-bottom-color: rgba(255, 255, 255, .08);
            box-shadow: 0 12px 32px -18px rgba(0, 0, 0, .9);
        }

        .nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .nav-brand img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: contain;
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(34, 211, 238, .4);
            box-shadow: 0 0 18px rgba(34, 211, 238, .22);
            padding: 2px;
            flex-shrink: 0;
        }

        .nav-brand .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            min-width: 0;
        }

        .nav-brand .brand-text strong {
            font-size: .88rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-brand .brand-text small {
            font-size: .58rem;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: rgba(125, 211, 252, .85);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-links a.nav-link {
            padding: 9px 14px;
            font-size: .82rem;
            font-weight: 600;
            color: rgba(255, 255, 255, .74);
            border-radius: 999px;
            transition: var(--transition);
        }

        .nav-links a.nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, .08);
        }

        .nav-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 10px 24px -10px rgba(91, 78, 255, .9);
            transition: var(--transition);
            white-space: nowrap;
            border: none;
            cursor: pointer;
        }

        .nav-cta:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 32px -12px rgba(91, 78, 255, 1);
            background: linear-gradient(135deg, #6a5eff 0%, var(--primary-deep) 100%);
        }

        /* ============================================================
           3. HERO
           ============================================================ */
        .hero {
            position: relative;
            padding: 132px 0 96px;
            overflow: hidden;
            background:
                radial-gradient(1100px 620px at 12% -12%, rgba(91, 78, 255, .42) 0%, transparent 62%),
                radial-gradient(880px 520px at 88% 4%, rgba(34, 211, 238, .24) 0%, transparent 62%),
                linear-gradient(180deg, var(--navy-950) 0%, var(--navy-900) 42%, #0a1526 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url('picture/ched.png') center / cover no-repeat;
            opacity: .12;
            filter: blur(2px) saturate(1.25);
            transform: scale(1.06);
            pointer-events: none;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .045) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: radial-gradient(ellipse 90% 70% at 50% 0%, #000 20%, transparent 78%);
            -webkit-mask-image: radial-gradient(ellipse 90% 70% at 50% 0%, #000 20%, transparent 78%);
            pointer-events: none;
        }

        .hero-grid {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 0;
            align-items: center;
            justify-items: center;
            text-align: center;
        }

        .hero-copy { max-width: 760px; }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            margin-bottom: 22px;
            border-radius: 999px;
            border: 1px solid rgba(34, 211, 238, .42);
            background: rgba(34, 211, 238, .1);
            color: var(--accent-light);
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .hero-eyebrow .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 10px var(--accent);
            animation: pulseDot 2.2s ease-in-out infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: .45; transform: scale(.8); }
        }

        .hero h1 {
            font-size: clamp(2.05rem, 4.6vw, 3.35rem);
            font-weight: 900;
            line-height: 1.1;
            letter-spacing: -.028em;
            color: #fff;
            margin-bottom: 20px;
        }

        .hero h1 .accent {
            background: linear-gradient(100deg, #a5f3fc 0%, #22d3ee 45%, #5b4eff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero-sub {
            font-size: 1.02rem;
            line-height: 1.72;
            color: rgba(226, 232, 240, .78);
            margin-bottom: 30px;
            margin-left: auto;
            margin-right: auto;
            max-width: 620px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin-bottom: 34px;
        }

        .btn-primary,
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 13px 26px;
            border-radius: 12px;
            font-size: .9rem;
            font-weight: 700;
            letter-spacing: .01em;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 16px 34px -14px rgba(91, 78, 255, 1);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 42px -16px rgba(91, 78, 255, 1);
        }

        .btn-ghost {
            color: rgba(255, 255, 255, .88);
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .16);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, .11);
            border-color: rgba(34, 211, 238, .55);
            color: #fff;
            transform: translateY(-2px);
        }

        .hero-points {
            list-style: none;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 10px 22px;
            margin: 0 auto 36px;
            max-width: 620px;
            text-align: left;
        }

        .hero-points li {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            font-size: .82rem;
            font-weight: 500;
            color: rgba(226, 232, 240, .74);
            line-height: 1.5;
        }

        .hero-points li i {
            color: var(--accent);
            font-size: .78rem;
            margin-top: 5px;
            flex-shrink: 0;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            padding-top: 28px;
            border-top: 1px solid rgba(255, 255, 255, .1);
            max-width: 620px;
            margin: 0 auto;
        }

        .hero-stat .value {
            font-size: 1.32rem;
            font-weight: 900;
            letter-spacing: -.02em;
            color: #fff;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }

        .hero-stat .value span { color: var(--accent); }

        .hero-stat .label {
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .13em;
            text-transform: uppercase;
            color: rgba(148, 163, 184, .85);
            margin-top: 3px;
            line-height: 1.4;
        }

        /* ============================================================
           4. LOGIN MODAL
           ============================================================ */
        .login-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(5, 9, 15, .78);
            backdrop-filter: blur(12px) saturate(140%);
            -webkit-backdrop-filter: blur(12px) saturate(140%);
            opacity: 0;
            visibility: hidden;
            transition: opacity .32s ease, visibility .32s ease;
            overflow-y: auto;
        }

        .login-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .login-card {
            position: relative;
            width: 100%;
            max-width: 420px;
            margin: auto;
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 34px 30px 26px;
            box-shadow: var(--shadow-xl), 0 0 0 1px rgba(255, 255, 255, .07);
            overflow: hidden;
            transform: translateY(24px) scale(.96);
            transition: transform .35s cubic-bezier(.22, 1, .36, 1);
        }

        .login-modal-overlay.active .login-card {
            transform: translateY(0) scale(1);
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--accent) 55%, var(--primary) 100%);
        }

        .login-card-head { margin-bottom: 20px; }

        .login-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: .58rem;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: var(--primary);
            background: var(--primary-light);
            border: 1px solid #ddd9ff;
            margin-bottom: 12px;
        }

        .login-badge i { font-size: .64rem; }

        .login-card h2 {
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -.025em;
            color: var(--gray-900);
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .login-card-head p {
            font-size: .86rem;
            font-weight: 500;
            color: var(--gray-500);
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 13px;
            margin-bottom: 16px;
            border-radius: 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid #ef4444;
            color: #b91c1c;
            font-size: .8rem;
            line-height: 1.45;
            font-weight: 500;
            animation: shake .42s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(6px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }

        .alert i { color: #ef4444; font-size: .95rem; flex-shrink: 0; }

        .form-group { margin-bottom: 15px; }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: .79rem;
            font-weight: 600;
            color: var(--gray-700);
            letter-spacing: .01em;
        }

        .form-group label i {
            margin-right: 6px;
            color: var(--gray-400);
            font-size: .8rem;
        }

        .input-wrapper { position: relative; }

        .form-control {
            width: 100%;
            padding: 11px 42px 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: var(--font);
            font-size: .9rem;
            color: var(--gray-800);
            background: #fff;
            transition: var(--transition);
            outline: none;
        }

        .form-control::placeholder { color: var(--gray-400); font-weight: 400; }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(91, 78, 255, .15);
        }

        .form-control:disabled {
            background: var(--gray-100);
            color: var(--gray-400);
            border-color: var(--gray-200);
            cursor: not-allowed;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            padding: 6px;
            border: none;
            border-radius: 7px;
            background: transparent;
            color: var(--gray-400);
            font-size: .95rem;
            line-height: 1;
            cursor: pointer;
            transition: color .2s, background .2s;
        }

        .password-toggle:hover:not(:disabled) {
            color: var(--primary);
            background: var(--primary-light);
        }

        .password-toggle:disabled { opacity: .45; cursor: not-allowed; }

        .btn-submit {
            position: relative;
            width: 100%;
            margin-top: 8px;
            padding: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: none;
            border-radius: 11px;
            font-size: .93rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 8px 20px -8px rgba(91, 78, 255, .85);
            cursor: pointer;
            overflow: hidden;
            transition: var(--transition);
        }

        .btn-submit::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, .2), transparent 60%);
            pointer-events: none;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 14px 30px -10px rgba(91, 78, 255, 1);
        }

        .btn-submit:active:not(:disabled) { transform: scale(.985); }

        .btn-submit:disabled {
            opacity: .5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .login-card-foot {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--gray-100);
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
        }

        .security-note {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            width: 100%;
            padding: 10px 13px;
            border-radius: 10px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
        }

        .security-note > i {
            color: var(--primary);
            font-size: .88rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .security-note p {
            font-size: .68rem;
            font-weight: 600;
            color: var(--gray-600);
            line-height: 1.55;
        }

        .security-note p span {
            display: block;
            margin-top: 2px;
            font-weight: 500;
            color: var(--gray-400);
        }

        .login-copyright {
            font-size: .62rem;
            font-weight: 600;
            letter-spacing: .04em;
            color: var(--gray-400);
            text-align: center;
        }

        /* ============================================================
           5. SECTIONS (shared)
           ============================================================ */
        .section { padding: 92px 0; }

        .section-alt { background: #fff; }

        .section-head {
            max-width: 700px;
            margin: 0 auto 52px;
            text-align: center;
        }

        .section-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 13px;
            margin-bottom: 14px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 800;
            letter-spacing: .17em;
            text-transform: uppercase;
            color: var(--primary);
            background: var(--primary-light);
            border: 1px solid #ddd9ff;
        }

        .section-head h2 {
            font-size: clamp(1.65rem, 3.2vw, 2.4rem);
            font-weight: 900;
            letter-spacing: -.028em;
            line-height: 1.18;
            color: var(--gray-900);
            margin-bottom: 12px;
        }

        .section-head p {
            font-size: .96rem;
            line-height: 1.7;
            color: var(--gray-500);
        }

        /* ============================================================
           6. ABOUT US
           ============================================================ */
        .about-section {
            position: relative;
            background: linear-gradient(180deg, #f7f8fc 0%, #ffffff 100%);
            overflow: hidden;
        }

        .about-section::before {
            content: '';
            position: absolute;
            top: -180px;
            right: -180px;
            width: 520px;
            height: 520px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(91, 78, 255, .08) 0%, transparent 70%);
            pointer-events: none;
        }

        .about-section::after {
            content: '';
            position: absolute;
            bottom: -180px;
            left: -180px;
            width: 480px;
            height: 480px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(34, 211, 238, .08) 0%, transparent 70%);
            pointer-events: none;
        }

        .about-grid {
            position: relative;
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 60px;
            align-items: center;
        }

        .about-content .section-eyebrow {
            margin-bottom: 16px;
        }

        .about-content h2 {
            font-size: clamp(1.75rem, 3.4vw, 2.45rem);
            font-weight: 900;
            letter-spacing: -.028em;
            line-height: 1.18;
            color: var(--gray-900);
            margin-bottom: 16px;
        }

        .about-lead {
            font-size: 1.05rem;
            line-height: 1.7;
            color: var(--gray-700);
            font-weight: 500;
            margin-bottom: 14px;
        }

        .about-content > p.about-body {
            font-size: .92rem;
            line-height: 1.75;
            color: var(--gray-500);
            margin-bottom: 26px;
        }

        .about-highlights {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .about-highlight {
            display: flex;
            gap: 12px;
            padding: 18px;
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .about-highlight:hover {
            border-color: #dcd8ff;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .about-highlight > i {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: .95rem;
            color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent-soft) 100%);
            border: 1px solid #e6e2ff;
        }

        .about-highlight strong {
            display: block;
            font-size: .88rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 3px;
            letter-spacing: -.005em;
        }

        .about-highlight span {
            font-size: .76rem;
            line-height: 1.55;
            color: var(--gray-500);
        }

        .about-visual {
            display: flex;
            justify-content: center;
        }

        .about-card {
            position: relative;
            width: 100%;
            max-width: 380px;
            padding: 44px 32px 32px;
            text-align: center;
            background: #fff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .about-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--accent) 55%, var(--primary) 100%);
        }

        .about-card img {
            width: 96px;
            height: 96px;
            margin: 0 auto 20px;
            border-radius: 50%;
            object-fit: cover;
            padding: 3px;
            background: #fff;
            border: 2px solid var(--primary-light);
            box-shadow: 0 12px 26px -10px rgba(91, 78, 255, .4);
        }

        .about-card h3 {
            font-size: 1.25rem;
            font-weight: 900;
            color: var(--gray-900);
            letter-spacing: -.02em;
            margin-bottom: 4px;
            line-height: 1.25;
        }

        .about-card > p {
            font-size: .82rem;
            color: var(--gray-500);
            margin-bottom: 22px;
        }

        .about-badges {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .about-badges span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: .76rem;
            font-weight: 700;
            color: var(--gray-700);
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            transition: var(--transition);
        }

        .about-badges span:hover {
            background: var(--primary-light);
            border-color: #ddd9ff;
            color: var(--primary-dark);
        }

        .about-badges span i {
            color: var(--primary);
            font-size: .82rem;
        }

        /* ============================================================
           7. FEATURES
           ============================================================ */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
            gap: 22px;
        }

        .feature-card {
            position: relative;
            padding: 28px 26px;
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            transition: var(--transition);
            overflow: hidden;
        }

        .feature-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .35s ease;
        }

        .feature-card:hover {
            transform: translateY(-6px);
            border-color: #dcd8ff;
            box-shadow: var(--shadow-lg);
        }

        .feature-card:hover::after { transform: scaleX(1); }

        .feature-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            border-radius: 14px;
            font-size: 1.15rem;
            color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--accent-soft) 100%);
            border: 1px solid #e6e2ff;
        }

        .feature-card h3 {
            font-size: 1.02rem;
            font-weight: 800;
            letter-spacing: -.012em;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .feature-card p {
            font-size: .85rem;
            line-height: 1.65;
            color: var(--gray-500);
        }

        /* ============================================================
           8. HOW IT WORKS
           ============================================================ */
        .how {
            background:
                radial-gradient(900px 460px at 50% -20%, rgba(91, 78, 255, .16) 0%, transparent 65%),
                linear-gradient(180deg, #0a1220 0%, var(--navy-900) 100%);
            position: relative;
            overflow: hidden;
        }

        .how::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px);
            background-size: 64px 64px;
            pointer-events: none;
        }

        .how .section-head h2 { color: #fff; }
        .how .section-head p  { color: rgba(203, 213, 225, .72); }

        .how .section-eyebrow {
            color: var(--accent-light);
            background: rgba(34, 211, 238, .1);
            border-color: rgba(34, 211, 238, .4);
        }

        .steps {
            position: relative;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 22px;
        }

        .step {
            position: relative;
            padding: 30px 26px;
            border-radius: var(--radius-lg);
            background: rgba(255, 255, 255, .045);
            border: 1px solid rgba(255, 255, 255, .1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: var(--transition);
        }

        .step:hover {
            background: rgba(255, 255, 255, .075);
            border-color: rgba(34, 211, 238, .45);
            transform: translateY(-5px);
        }

        .step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin-bottom: 16px;
            border-radius: 12px;
            font-size: .95rem;
            font-weight: 900;
            color: var(--navy-900);
            background: linear-gradient(135deg, var(--accent-light) 0%, var(--accent) 100%);
            box-shadow: 0 0 26px rgba(34, 211, 238, .38);
        }

        .step h3 {
            font-size: 1rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -.01em;
            margin-bottom: 7px;
        }

        .step p {
            font-size: .84rem;
            line-height: 1.65;
            color: rgba(203, 213, 225, .72);
        }

        /* ============================================================
           9. ROLES
           ============================================================ */
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 22px;
        }

        .role-card {
            padding: 32px 28px;
            border-radius: var(--radius-lg);
            background: #fff;
            border: 1px solid var(--gray-200);
            transition: var(--transition);
            text-align: left;
        }

        .role-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: #dcd8ff;
        }

        .role-card .role-icon {
            width: 52px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            border-radius: 50%;
            font-size: 1.2rem;
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 12px 26px -12px rgba(91, 78, 255, .95);
        }

        .role-card:nth-child(2) .role-icon {
            background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
            box-shadow: 0 12px 26px -12px rgba(8, 145, 178, .95);
        }

        .role-card:nth-child(3) .role-icon {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            box-shadow: 0 12px 26px -12px rgba(124, 58, 237, .95);
        }

        .role-card h3 {
            font-size: 1.03rem;
            font-weight: 800;
            letter-spacing: -.012em;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .role-card p {
            font-size: .85rem;
            line-height: 1.65;
            color: var(--gray-500);
        }

        .role-card .role-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 16px;
        }

        .role-card .role-tags span {
            padding: 4px 11px;
            border-radius: 999px;
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--gray-600);
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
        }

        /* ============================================================
           10. SECURITY BAND
           ============================================================ */
        .security-band {
            position: relative;
            padding: 84px 0;
            overflow: hidden;
            background:
                radial-gradient(800px 420px at 80% 0%, rgba(34, 211, 238, .18) 0%, transparent 62%),
                radial-gradient(700px 400px at 10% 100%, rgba(91, 78, 255, .3) 0%, transparent 62%),
                linear-gradient(160deg, var(--navy-800) 0%, var(--navy-950) 100%);
        }

        .security-band .section-head { margin-bottom: 44px; }
        .security-band .section-head h2 { color: #fff; }
        .security-band .section-head p  { color: rgba(203, 213, 225, .72); }

        .security-band .section-eyebrow {
            color: var(--accent-light);
            background: rgba(34, 211, 238, .1);
            border-color: rgba(34, 211, 238, .4);
        }

        .security-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
        }

        .security-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 20px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, .045);
            border: 1px solid rgba(255, 255, 255, .1);
            transition: var(--transition);
        }

        .security-item:hover {
            background: rgba(255, 255, 255, .08);
            border-color: rgba(34, 211, 238, .45);
        }

        .security-item > i {
            font-size: 1rem;
            color: var(--accent);
            margin-top: 4px;
            flex-shrink: 0;
            width: 20px;
            text-align: center;
        }

        .security-item strong {
            display: block;
            font-size: .87rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 3px;
            letter-spacing: -.005em;
        }

        .security-item span {
            font-size: .78rem;
            line-height: 1.6;
            color: rgba(203, 213, 225, .68);
        }

        /* ============================================================
           11. CTA + FOOTER
           ============================================================ */
        .cta {
            padding: 76px 0;
            background: linear-gradient(135deg, var(--primary-deep) 0%, var(--primary) 55%, #0891b2 100%);
            position: relative;
            overflow: hidden;
        }

        .cta::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(255, 255, 255, .18) 0%, transparent 42%),
                radial-gradient(circle at 85% 80%, rgba(255, 255, 255, .14) 0%, transparent 42%);
            pointer-events: none;
        }

        .cta-inner {
            position: relative;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 26px;
        }

        .cta-text h2 {
            font-size: clamp(1.5rem, 3vw, 2.1rem);
            font-weight: 900;
            letter-spacing: -.028em;
            line-height: 1.2;
            color: #fff;
            margin-bottom: 8px;
        }

        .cta-text p {
            font-size: .93rem;
            line-height: 1.65;
            color: rgba(255, 255, 255, .82);
            max-width: 540px;
        }

        .btn-light {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 14px 30px;
            border-radius: 12px;
            font-size: .9rem;
            font-weight: 800;
            color: var(--primary-deep);
            background: #fff;
            box-shadow: 0 16px 34px -14px rgba(0, 0, 0, .6);
            transition: var(--transition);
            white-space: nowrap;
            border: none;
            cursor: pointer;
        }

        .btn-light:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 44px -16px rgba(0, 0, 0, .7);
        }

        .footer {
            padding: 62px 0 0;
            background: var(--navy-950);
            color: rgba(203, 213, 225, .7);
            border-top: 1px solid rgba(255, 255, 255, .07);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1.6fr 1fr 1fr 1.2fr;
            gap: 40px;
            padding-bottom: 46px;
        }

        .footer-brand .footer-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .footer-brand .footer-logo img {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: contain;
            padding: 2px;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(34, 211, 238, .35);
        }

        .footer-brand .footer-logo strong {
            display: block;
            font-size: .84rem;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #fff;
            line-height: 1.3;
        }

        .footer-brand .footer-logo small {
            font-size: .56rem;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: rgba(125, 211, 252, .8);
        }

        .footer-brand p {
            font-size: .82rem;
            line-height: 1.68;
            color: rgba(148, 163, 184, .82);
            max-width: 320px;
        }

        .footer-col h4 {
            font-size: .63rem;
            font-weight: 800;
            letter-spacing: .17em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .55);
            margin-bottom: 14px;
        }

        .footer-col ul { list-style: none; }

        .footer-col li { margin-bottom: 9px; }

        .footer-col a,
        .footer-col .footer-link {
            font-size: .82rem;
            font-weight: 500;
            color: rgba(203, 213, 225, .74);
            transition: color .2s;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            text-align: left;
            font-family: inherit;
        }

        .footer-col a:hover,
        .footer-col .footer-link:hover { color: var(--accent-light); }

        .footer-col .footer-note {
            font-size: .78rem;
            line-height: 1.65;
            color: rgba(148, 163, 184, .78);
        }

        .footer-col .footer-note strong {
            color: rgba(226, 232, 240, .92);
            font-weight: 700;
        }
        /* Footer — contact & social */
.footer-grid {
    grid-template-columns: 1.5fr 1fr 1fr 1.5fr; /* give contact column more room */
}

.footer-col ul.footer-contact {
    list-style: none;
    margin: 0 0 14px;
}

.footer-col ul.footer-contact li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 14px;
    font-size: .82rem;
    line-height: 1.5;
    color: rgba(203, 213, 225, .74);
}

.footer-col ul.footer-contact li > i {
    color: var(--accent);
    font-size: .88rem;
    margin-top: 4px;
    flex-shrink: 0;
    width: 14px;
    text-align: center;
}

.footer-col ul.footer-contact a {
    color: rgba(203, 213, 225, .74);
    transition: color .2s;
    display: block;
}

.footer-col ul.footer-contact a:hover {
    color: var(--accent-light);
}

.footer-col ul.footer-contact a strong {
    display: block;
    color: rgba(226, 232, 240, .92);
    font-weight: 700;
    font-size: .82rem;
    margin-bottom: 2px;
    line-height: 1.35;
}

.footer-col ul.footer-contact a small {
    display: block;
    font-size: .72rem;
    font-weight: 500;
    color: rgba(148, 163, 184, .72);
    line-height: 1.5;
    transition: color .2s;
}

.footer-col ul.footer-contact a:hover small {
    color: var(--accent-light);
}

/* "Follow us on Facebook" button */
.footer-social {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-radius: 10px;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .01em;
    color: #fff !important;
    background: linear-gradient(135deg, #1877f2 0%, #0d5fcc 100%);
    box-shadow: 0 8px 18px -8px rgba(24, 119, 242, .7);
    transition: var(--transition);
    margin-top: 2px;
}

.footer-social:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 24px -10px rgba(24, 119, 242, .95);
    background: linear-gradient(135deg, #2585f5 0%, #1068d8 100%);
}

.footer-social i {
    font-size: .95rem;
}

@media (max-width: 1040px) {
    .footer-grid { grid-template-columns: 1fr 1fr; gap: 34px; }
}

        .footer-bottom {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 0;
            border-top: 1px solid rgba(255, 255, 255, .07);
            font-size: .72rem;
            color: rgba(148, 163, 184, .68);
        }

        .footer-bottom strong { color: rgba(226, 232, 240, .9); font-weight: 700; }

        .footer-bottom .footer-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--accent-light);
            background: rgba(34, 211, 238, .09);
            border: 1px solid rgba(34, 211, 238, .32);
        }

        /* ============================================================
           12. LOCK MODAL (on top of login modal)
           ============================================================ */
        .lock-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(7, 13, 24, .74);
            backdrop-filter: blur(9px);
            -webkit-backdrop-filter: blur(9px);
            animation: overlayIn .3s ease-out;
        }

        @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }

        .lock-modal {
            position: relative;
            width: 100%;
            max-width: 390px;
            padding: 32px 26px 26px;
            text-align: center;
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: 0 40px 90px -24px rgba(0, 0, 0, .8);
            overflow: hidden;
            animation: modalIn .45s cubic-bezier(.34, 1.56, .64, 1);
        }

        .lock-modal::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent), #dc2626, var(--accent));
        }

        @keyframes modalIn {
            0%   { opacity: 0; transform: scale(.88) translateY(18px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }

        .lock-modal-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.7rem;
            color: #dc2626;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            animation: pulseLock 2s ease-in-out infinite;
        }

        @keyframes pulseLock {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, .45); }
            50%      { box-shadow: 0 0 0 14px rgba(239, 68, 68, 0); }
        }

        .lock-modal h2 {
            font-size: 1.16rem;
            font-weight: 800;
            letter-spacing: -.02em;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .lock-modal p.lock-desc {
            font-size: .82rem;
            line-height: 1.55;
            color: var(--gray-500);
            margin-bottom: 18px;
        }

        .lock-timer-box {
            padding: 15px 12px 12px;
            margin-bottom: 18px;
            border-radius: 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .lock-timer {
            font-size: 2.35rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .08em;
            color: #dc2626;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
        }

        .lock-timer-label {
            margin-top: 6px;
            font-size: .6rem;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: #b91c1c;
            opacity: .82;
        }

        .lock-progress {
            height: 5px;
            margin-bottom: 18px;
            border-radius: 999px;
            background: #fee2e2;
            overflow: hidden;
        }

        .lock-progress-bar {
            height: 100%;
            width: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #ef4444, #dc2626);
            transition: width 1s linear;
        }

        .lock-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: .7rem;
            line-height: 1.5;
            color: var(--gray-400);
        }

        /* ============================================================
           13. REVEAL ANIMATIONS
           ============================================================ */
        .js .reveal {
            opacity: 0;
            transform: translateY(26px);
            transition: opacity .7s cubic-bezier(.22, 1, .36, 1),
                        transform .7s cubic-bezier(.22, 1, .36, 1);
            will-change: opacity, transform;
        }

        .js .reveal.in {
            opacity: 1;
            transform: translateY(0);
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
            }
            .js .reveal { opacity: 1; transform: none; }
            .login-card { transform: none !important; }
        }

        /* ============================================================
           14. RESPONSIVE
           ============================================================ */
        @media (max-width: 1040px) {
            .footer-grid { grid-template-columns: 1fr 1fr; gap: 34px; }
            .about-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }
            .about-visual { order: -1; }
        }

        @media (max-width: 860px) {
            .nav-links a.nav-link { display: none; }
            .section { padding: 72px 0; }
            .hero { padding: 116px 0 76px; }
            .section-head { margin-bottom: 40px; }
            .cta-inner { flex-direction: column; text-align: center; }
            .cta-text p { margin: 0 auto; }
        }

        @media (max-width: 600px) {
            .container { padding: 0 18px; }
            .nav-brand .brand-text strong { font-size: .78rem; }
            .nav-brand .brand-text small { font-size: .52rem; letter-spacing: .13em; }
            .nav-cta { padding: 9px 16px; font-size: .76rem; }
            .nav-brand img { width: 36px; height: 36px; }

            .hero { padding: 104px 0 64px; }
            .hero h1 { font-size: clamp(1.85rem, 8vw, 2.3rem); }
            .hero-sub { font-size: .93rem; }
            .hero-points { grid-template-columns: 1fr; gap: 9px; }
            .hero-stats { grid-template-columns: repeat(2, 1fr); gap: 18px 12px; }
            .hero-stat .value { font-size: 1.18rem; }
            .hero-actions .btn-primary,
            .hero-actions .btn-ghost { flex: 1 1 100%; }

            .login-modal-overlay {
                padding: 16px;
                align-items: flex-start;
                padding-top: 40px;
            }
            .login-card { padding: 28px 20px 22px; border-radius: 18px; }
            .login-card h2 { font-size: 1.4rem; }

            .about-highlights { grid-template-columns: 1fr; }
            .about-card { padding: 36px 24px 26px; }

            .feature-card { padding: 24px 20px; }
            .step { padding: 24px 20px; }
            .role-card { padding: 26px 22px; }

            .security-item { padding: 16px; }

            .footer { padding-top: 48px; }
            .footer-grid { grid-template-columns: 1fr; gap: 28px; padding-bottom: 34px; }
            .footer-bottom { flex-direction: column; text-align: center; justify-content: center; }

            .lock-modal { padding: 26px 18px 20px; }
            .lock-timer { font-size: 2rem; }
            .lock-modal-icon { width: 58px; height: 58px; font-size: 1.45rem; }
        }
    </style>

    <noscript>
        <style>
            /* No-JS fallback: force the login modal open so users can still log in */
            .login-modal-overlay { opacity: 1 !important; visibility: visible !important; }
            body { overflow: hidden !important; }
        </style>
    </noscript>
</head>
<body>

<!-- ============================================================
     NAVBAR
     ============================================================ -->
<nav class="navbar" id="navbar">
    <div class="container nav-inner">
        <a href="#top" class="nav-brand">
            <img src="picture/love.jpg" alt="USAT College Sagay City Inc. logo">
            <span class="brand-text">
                <strong>USAT College Sagay City Inc.</strong>
                <small>Compliance Portal</small>
            </span>
        </a>

        <div class="nav-links">
            <a href="#about" class="nav-link">About</a>
            <a href="#features" class="nav-link">Features</a>
            <a href="#how" class="nav-link">How it works</a>
            <a href="#roles" class="nav-link">Roles</a>
            <a href="#security" class="nav-link">Security</a>
            <button type="button" class="nav-cta js-open-login">
                <i class="fas fa-right-to-bracket"></i> Sign In
            </button>
        </div>
    </div>
</nav>

<!-- ============================================================
     HERO
     ============================================================ -->
<header class="hero" id="top">
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="hero-eyebrow">
                <span class="dot"></span> Official Institutional Portal
            </span>

            <h1>
                Compliance &amp; document management,
                <span class="accent">simplified.</span>
            </h1>

            <p class="hero-sub">
                One secure portal for CHED compliance submissions, accreditation
                documents, and institutional reporting — purpose-built for the
                administrators, program heads, and faculty of USAT College Sagay City Inc.
            </p>

            <div class="hero-actions">
                <button type="button" class="btn-primary js-open-login">
                    <i class="fas fa-right-to-bracket"></i> Sign in to your portal
                </button>
                <a href="#about" class="btn-ghost">
                    <i class="fas fa-circle-info"></i> About the institution
                </a>
            </div>

            <ul class="hero-points">
                <li><i class="fas fa-circle-check"></i> Centralized compliance document repository</li>
                <li><i class="fas fa-circle-check"></i> Real-time submission tracking &amp; deadlines</li>
                <li><i class="fas fa-circle-check"></i> Role-based access for every department</li>
                <li><i class="fas fa-circle-check"></i> Complete audit trail on every action</li>
            </ul>

            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="value">100<span>%</span></div>
                    <div class="label">Hashed passwords</div>
                </div>
                <div class="hero-stat">
                    <div class="value">24<span>/7</span></div>
                    <div class="label">Activity monitoring</div>
                </div>
                <div class="hero-stat">
                    <div class="value">3</div>
                    <div class="label">Attempts before lockout</div>
                </div>
                <div class="hero-stat">
                    <div class="value">5<span> min</span></div>
                    <div class="label">Auto lockout recovery</div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ============================================================
     LOGIN MODAL (opens on any .js-open-login trigger)
     - Closes on backdrop click OR Esc key
     ============================================================ -->
<div class="login-modal-overlay"
     id="loginOverlay"
     aria-hidden="true"
     data-auto-open="<?= $auto_open_login ? '1' : '0' ?>">
    <div class="login-card" id="login" role="dialog" aria-modal="true" aria-labelledby="loginTitle">

        <div class="login-card-head">
            <span class="login-badge"><i class="fas fa-user-shield"></i> Secure Portal Access</span>
            <h2 id="loginTitle">Welcome back</h2>
            <p>Sign in to access your compliance dashboard</p>
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
                           placeholder="name@usat.edu.ph" required
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

        <div class="login-card-foot">
            <div class="security-note">
                <i class="fas fa-shield-halved"></i>
                <p>
                    Authorized access only. All activities are monitored and logged.
                    <span>Contact the System Administrator for credentials.</span>
                </p>
            </div>
            <div class="login-copyright">
                &copy; 2026 <strong>USAT College Sagay City Inc.</strong> &middot; All rights reserved.
            </div>
        </div>

    </div><!-- /.login-card -->
</div><!-- /.login-modal-overlay -->

<!-- ============================================================
     ABOUT US
     ============================================================ -->
<section class="section about-section" id="about">
    <div class="container">
        <div class="about-grid">

            <div class="about-content reveal">
                <span class="section-eyebrow">
                    <i class="fas fa-building-columns"></i> About the Institution
                </span>
                <h2>USAT College Sagay City Inc.</h2>

                <p class="about-lead">
                    A leading higher education institution in Negros Occidental,
                    committed to academic excellence, innovation, and community service.
                </p>

                <p class="about-body">
                    The Compliance &amp; Document Management System was developed to
                    streamline the institution's partnership with the Commission on
                    Higher Education (CHED). It centralizes submissions, accreditation
                    documents, and institutional reports — eliminating scattered files,
                    manual follow-ups, and lost email threads. Every department,
                    program head, and faculty member can work from one trusted source
                    of truth, secured end-to-end.
                </p>

                <div class="about-highlights">
                    <div class="about-highlight">
                        <i class="fas fa-bullseye"></i>
                        <div>
                            <strong>Our Mission</strong>
                            <span>Deliver quality education that produces globally competitive, values-driven graduates.</span>
                        </div>
                    </div>
                    <div class="about-highlight">
                        <i class="fas fa-eye"></i>
                        <div>
                            <strong>Our Vision</strong>
                            <span>A premier institution for academic excellence and social transformation in the region.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="about-visual reveal">
                <div class="about-card">
                    <img src="picture/love.jpg" alt="USAT College Sagay City Inc. logo">
                    <h3>USAT College<br>Sagay City Inc.</h3>
                    <p>Sagay City, Negros Occidental</p>
                    <div class="about-badges">
                        <span><i class="fas fa-award"></i> CHED-Recognized Programs</span>
                        <span><i class="fas fa-graduation-cap"></i> Accredited Curriculum</span>
                        <span><i class="fas fa-shield-halved"></i> Secure Compliance Portal</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ============================================================
     FEATURES
     ============================================================ -->
<section class="section section-alt" id="features">
    <div class="container">

        <div class="section-head reveal">
            <span class="section-eyebrow"><i class="fas fa-layer-group"></i> What's inside</span>
            <h2>Everything your compliance office needs</h2>
            <p>
                Built around the actual workflow of CHED submissions and institutional
                accreditation — so nothing gets lost in email threads or spreadsheets.
            </p>
        </div>

        <div class="features-grid">

            <article class="feature-card reveal">
                <div class="feature-icon"><i class="fas fa-folder-open"></i></div>
                <h3>Centralized document hub</h3>
                <p>Store, organize, and retrieve compliance documents by department, program, and reporting period — no more scattered folders.</p>
            </article>

            <article class="feature-card reveal">
                <div class="feature-icon"><i class="fas fa-list-check"></i></div>
                <h3>Submission tracking</h3>
                <p>Follow every requirement from draft to approved. See exactly what is pending, who owns it, and when it is due.</p>
            </article>

            <article class="feature-card reveal">
                <div class="feature-icon"><i class="fas fa-users-gear"></i></div>
                <h3>Role-based access</h3>
                <p>Administrators, program heads, and faculty each see only what their role requires — enforced server-side on every request.</p>
            </article>

            <article class="feature-card reveal">
                <div class="feature-icon"><i class="fas fa-bell"></i></div>
                <h3>In-app notifications</h3>
                <p>Deadline reminders, status changes, and security alerts delivered straight into your dashboard.</p>
            </article>

            <article class="feature-card reveal">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Institutional reporting</h3>
                <p>Generate accurate, up-to-date compliance summaries for management review and accreditation visits.</p>
            </article>

            <article class="feature-card reveal">
                <div class="feature-icon"><i class="fas fa-clock-rotate-left"></i></div>
                <h3>Full audit trail</h3>
                <p>Every sign-in, edit, and approval is timestamped and recorded — ready for inspection at any time.</p>
            </article>

        </div>
    </div>
</section>

<!-- ============================================================
     HOW IT WORKS
     ============================================================ -->
<section class="section how" id="how">
    <div class="container">

        <div class="section-head reveal">
            <span class="section-eyebrow"><i class="fas fa-route"></i> How it works</span>
            <h2>Three steps to get in</h2>
            <p>No setup on your end — your administrator provisions the account.</p>
        </div>

        <div class="steps">

            <div class="step reveal">
                <div class="step-num">1</div>
                <h3>Receive your credentials</h3>
                <p>Your department administrator creates your account and issues a temporary password through a secure channel.</p>
            </div>

            <div class="step reveal">
                <div class="step-num">2</div>
                <h3>Sign in securely</h3>
                <p>Log in from the portal. Your session is regenerated, encrypted, and bound to a single active device.</p>
            </div>

            <div class="step reveal">
                <div class="step-num">3</div>
                <h3>Work on your compliance</h3>
                <p>Upload documents, track submissions, and respond to requirements — all from one dashboard.</p>
            </div>

        </div>
    </div>
</section>

<!-- ============================================================
     ROLES
     ============================================================ -->
<section class="section" id="roles">
    <div class="container">

        <div class="section-head reveal">
            <span class="section-eyebrow"><i class="fas fa-user-tag"></i> Authorized roles</span>
            <h2>Built for every level of the institution</h2>
            <p>Each role gets a dashboard tailored to what they actually need to do.</p>
        </div>

        <div class="roles-grid">

            <article class="role-card reveal">
                <div class="role-icon"><i class="fas fa-user-shield"></i></div>
                <h3>Administrators</h3>
                <p>Full oversight of accounts, compliance records, submissions, audit logs, and institutional reporting.</p>
                <div class="role-tags">
                    <span>User management</span>
                    <span>Audit logs</span>
                    <span>Reports</span>
                </div>
            </article>

            <article class="role-card reveal">
                <div class="role-icon"><i class="fas fa-user-tie"></i></div>
                <h3>Program Heads &amp; Unit Heads</h3>
                <p>Manage your department's documents, assign requirements, and track submission progress in real time.</p>
                <div class="role-tags">
                    <span>Department docs</span>
                    <span>Assignments</span>
                    <span>Progress</span>
                </div>
            </article>

            <article class="role-card reveal">
                <div class="role-icon"><i class="fas fa-graduation-cap"></i></div>
                <h3>Faculty &amp; Staff</h3>
                <p>Upload your required documents, monitor their review status, and respond to requests from your program head.</p>
                <div class="role-tags">
                    <span>Uploads</span>
                    <span>Status</span>
                    <span>Requests</span>
                </div>
            </article>

        </div>
    </div>
</section>

<!-- ============================================================
     SECURITY BAND
     ============================================================ -->
<section class="security-band" id="security">
    <div class="container">

        <div class="section-head reveal">
            <span class="section-eyebrow"><i class="fas fa-fingerprint"></i> Security first</span>
            <h2>Your documents are protected at every layer</h2>
            <p>The portal is engineered with defense in depth — not bolted on afterwards.</p>
        </div>

        <div class="security-grid">

            <div class="security-item reveal">
                <i class="fas fa-key"></i>
                <div>
                    <strong>Bcrypt password hashing</strong>
                    <span>Passwords are never stored in plain text and are re-hashed automatically when standards change.</span>
                </div>
            </div>

            <div class="security-item reveal">
                <i class="fas fa-shield-halved"></i>
                <div>
                    <strong>CSRF protection</strong>
                    <span>Every form submission is validated against a per-session token.</span>
                </div>
            </div>

            <div class="security-item reveal">
                <i class="fas fa-rotate"></i>
                <div>
                    <strong>Session hardening</strong>
                    <span>Session IDs are regenerated periodically and on every successful sign-in.</span>
                </div>
            </div>

            <div class="security-item reveal">
                <i class="fas fa-mobile-screen-button"></i>
                <div>
                    <strong>Device lockout</strong>
                    <span>Repeated failures from one device trigger a temporary, automatic lockout.</span>
                </div>
            </div>

            <div class="security-item reveal">
                <i class="fas fa-gauge-high"></i>
                <div>
                    <strong>IP rate limiting</strong>
                    <span>Mass-attack patterns are throttled before they can reach the authentication layer.</span>
                </div>
            </div>

            <div class="security-item reveal">
                <i class="fas fa-scroll"></i>
                <div>
                    <strong>Audit logging</strong>
                    <span>Sign-ins, failures, and administrative actions are all recorded with timestamps.</span>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ============================================================
     CTA
     ============================================================ -->
<section class="cta">
    <div class="container cta-inner">
        <div class="cta-text reveal">
            <h2>Ready to get started?</h2>
            <p>Sign in with the credentials issued by your system administrator to access your compliance dashboard.</p>
        </div>
        <button type="button" class="btn-light reveal js-open-login">
            <i class="fas fa-right-to-bracket"></i> Go to Sign In
        </button>
    </div>
</section>

<!-- ============================================================
     FOOTER
     ============================================================ -->
<footer class="footer">
    <div class="container">

        <div class="footer-grid">

            <!-- Brand -->
            <div class="footer-brand">
                <div class="footer-logo">
                    <img src="picture/love.jpg" alt="USAT College Sagay City Inc. logo">
                    <div>
                        <strong>USAT College<br>Sagay City Inc.</strong>
                        <small>Compliance Portal</small>
                    </div>
                </div>
                <p>
                    Compliance &amp; Document Management System — a secure institutional
                    platform for CHED submissions, accreditation records, and reporting.
                </p>
            </div>

            <!-- Explore -->
            <div class="footer-col">
                <h4>Explore</h4>
                <ul>
                    <li><a href="#about">About us</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#how">How it works</a></li>
                    <li><a href="#roles">Authorized roles</a></li>
                    <li><a href="#security">Security</a></li>
                </ul>
            </div>

            <!-- Access -->
            <div class="footer-col">
                <h4>Access</h4>
                <ul>
                    <li><button type="button" class="footer-link js-open-login">Sign in</button></li>
                    <li><a href="#roles">Administrators</a></li>
                    <li><a href="#roles">Program heads</a></li>
                    <li><a href="#roles">Faculty &amp; staff</a></li>
                </ul>
            </div>

            <!-- Get In Touch -->
            <div class="footer-col">
                <h4>Get In Touch With Us</h4>

                <ul class="footer-contact">
                    <li>
                        <i class="fas fa-location-dot"></i>
                        <a href="https://www.google.com/maps/place/USAT+College+Sagay+City,+Inc.+-+Main+Campus/@10.8979783,123.4155311,17z/data=!4m14!1m7!3m6!1s0x33a8dd4ace0ccd07:0x6419c716f10e65b9!2sUSAT+College+Sagay+City,+Inc.+-+Main+Campus!8m2!3d10.897973!4d123.418106!16s%2Fg%2F11b6hykjgw!3m5!1s0x33a8dd4ace0ccd07:0x6419c716f10e65b9!8m2!3d10.897973!4d123.418106!16s%2Fg%2F11b6hykjgw?entry=ttu&g_ep=EgoyMDI2MTAyOC4wIKXMDSoASAFQAw%3D%3D"
                           target="_blank" rel="noopener noreferrer">
                            <strong>USAT College — Main Campus</strong>
                            <small>Sagay City, Negros Occidental<br>Open location site map →</small>
                        </a>
                    </li>

                    <li>
                        <i class="fas fa-phone"></i>
                        <a href="tel:+63344618178">
                            <strong>Tel: (034) 461-8178</strong>
                            <small>Call our main campus line</small>
                        </a>
                    </li>
                </ul>

                <!-- Facebook CTA -->
                <a class="footer-social"
                   href="https://web.facebook.com/profile.php?id=100063698389605"
                   target="_blank" rel="noopener noreferrer"
                   aria-label="Follow USAT College Sagay City on Facebook">
                    <i class="fab fa-facebook-f"></i>
                    Follow us on Facebook
                </a>
            </div>

        </div>

        <div class="footer-bottom">
            <div>&copy; 2026 <strong>USAT College Sagay City Inc.</strong> &middot; All rights reserved.</div>
            <div class="footer-badge"><i class="fas fa-lock"></i> Authorized access only</div>
        </div>

    </div>
</footer>

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
    /* ============================================================
       LOGIN MODAL CONTROLLER
       - Opens when any .js-open-login trigger is clicked
       - Auto-opens if PHP flagged it (POST with error)
       - Closes on backdrop click (outside the login-card) or Esc
       - Focus trap while open
       ============================================================ */
    (function () {
        const overlay = document.getElementById('loginOverlay');
        if (!overlay) return;

        const card = overlay.querySelector('.login-card');
        const autoOpen = overlay.dataset.autoOpen === '1';

        function openModal() {
            if (overlay.classList.contains('active')) return;
            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            window.scrollTo({ top: 0, behavior: 'instant' });

            setTimeout(function () {
                const first = overlay.querySelector(
                    'input:not([disabled]), button:not([disabled]), [href]'
                );
                if (first) first.focus();
            }, 380);
        }

        function closeModal() {
            if (!overlay.classList.contains('active')) return;
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        if (autoOpen) {
            requestAnimationFrame(function () {
                requestAnimationFrame(openModal);
            });
        }

        // Every "Sign In" trigger opens the modal
        document.querySelectorAll('.js-open-login').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        });

        // Close when the user clicks OUTSIDE the login-card (on the backdrop)
        overlay.addEventListener('click', function (e) {
            if (!card.contains(e.target)) {
                closeModal();
            }
        });

        // Close with the Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('active')) {
                closeModal();
            }
        });

        // Focus trap — keyboard users stay inside the modal while open
        overlay.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;
            const focusables = overlay.querySelectorAll(
                'input:not([disabled]), button:not([disabled]), [href], ' +
                'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );
            if (!focusables.length) return;

            const first = focusables[0];
            const last  = focusables[focusables.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
    })();

    /* ============================================================
       Password visibility toggle
       ============================================================ */
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
    // Server sends seconds remaining (0 = not locked); the unlock moment is
    // computed on the browser's own clock, so server/client clock skew can
    // never disable the form when nobody is locked.
    const LOCK_REMAINING = <?= (int) $lock_remaining ?>;
    const UNLOCK_AT_MS   = LOCK_REMAINING > 0 ? Date.now() + LOCK_REMAINING * 1000 : 0;
    /* ============================================================
       Lockout countdown
       ============================================================ */
    const LOCK_TOTAL    = <?= (int) $lock_total ?>;
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
                const denom = LOCK_TOTAL > 0 ? LOCK_TOTAL : 1;
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

    /* ============================================================
       Navbar scroll state
       ============================================================ */
    (function () {
        const nav = document.getElementById('navbar');
        if (!nav) return;

        function onScroll() {
            nav.classList.toggle('scrolled', window.scrollY > 24);
        }

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    })();

    /* ============================================================
       Scroll-reveal
       ============================================================ */
    (function () {
        const items = document.querySelectorAll('.reveal');
        if (!items.length) return;

        if (!('IntersectionObserver' in window)) {
            items.forEach(el => el.classList.add('in'));
            return;
        }

        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        items.forEach(function (el, i) {
            el.style.transitionDelay = (Math.min(i, 6) * 60) + 'ms';
            observer.observe(el);
        });
    })();
</script>

</body>
</html>