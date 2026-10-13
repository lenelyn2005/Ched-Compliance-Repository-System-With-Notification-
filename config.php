<?php
// Direct-access guard: config.php is an include-only file. When requested
// directly the caller is config.php itself, so refuse and never run the
// env loading / DB connect / auto-sync side effects below.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// Inclusion marker for include-only consumers (e.g. send_email.php).
if (!defined('CHED_APP')) {
    define('CHED_APP', true);
}

// Load environment variables (.env) BEFORE any SMTP/DB config is read.
require_once __DIR__ . '/includes/env_loader.php';
loadEnv(__DIR__ . '/.env');

// Performance optimization - GZIP compression before any output
require_once __DIR__ . '/includes/performance.php';
perf_gzip_start();

// Set timezone to match MySQL server timezone (Asia/Manila = UTC+8)
date_default_timezone_set('Asia/Manila');

// Error reporting: hide in production (APP_DEBUG=false), show when APP_DEBUG=true
$appDebug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
error_reporting(E_ALL);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');

// Database configuration — credentials come from .env only. Never fall back to
// root or to an empty password; perf_db() fails closed when DB_PASS is empty.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'ched_compliance');
define('DB_USER', getenv('DB_USER') ?: 'ched_app');
define('DB_PASS', getenv('DB_PASS') ?: '');

// File storage settings — files stored outside public web root
define('SECURE_STORAGE_PATH', getenv('SECURE_STORAGE_PATH') ?: 'C:\\ched_secure_storage\\documents\\');
define('TEMP_PATH', getenv('TEMP_PATH') ?: 'C:\\ched_secure_storage\\temp\\');
define('UPLOAD_PATH', SECURE_STORAGE_PATH); // alias — all existing code references UPLOAD_PATH

// System settings — .env overridable, defaults below. No DB override table.
$MAX_FILE_SIZE      = (int) (getenv('MAX_FILE_SIZE') ?: 100 * 1024 * 1024); // 100MB
$ALLOWED_EXTENSIONS = array_filter(array_map('trim', explode(',', getenv('ALLOWED_EXTENSIONS')
    ?: 'pdf, doc, docx, jpg, jpeg, png, gif, webp, xls, xlsx, ppt, pptx')));
// ponytail: ALLOWED_MIMES stays in code — it must match ALLOWED_EXTENSIONS or uploads silently break;
// upgrade to an extension→mime map when admins need custom types
$ALLOWED_MIMES      = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];
$ALLOWED_EXTENSIONS_LABEL = getenv('ALLOWED_EXTENSIONS_LABEL') ?: 'PDF, DOC/DOCX, JPG/PNG/GIF/WEBP, XLS/XLSX, PPT/PPTX';
$EXPIRY_WARNING_DAYS = (int) (getenv('EXPIRY_WARNING_DAYS') ?: 2);
$NOTIFICATION_EMAIL_ENABLED = [
    'New Requirement Assigned' => true,
    'New Requirement' => true,
    'Requirement Updated' => true,
    'New Department Requirement' => true,
    'Requirement Removed' => true,
    'Requirement Expired' => true,
    'New PH Requirement Submission' => true,
    'New Document Submission' => true,
    'New Department Document' => true,
    'New Submission for Admin Review (Auto-Forwarded)' => true,
    'Admin Review Complete: APPROVED' => true,
    'Admin Review Complete: RETURNED BY ADMIN' => true,
    'Requirement Fully Approved' => true,
    'Requirement Returned by Admin' => true,
    'PH Requirement Approved' => true,
    'PH Requirement Returned' => true,
    'PH Requirement Fully Approved' => true,
    'PH Requirement Returned by Admin' => true,
    'Document Returned by Admin for Correction' => true,
    'Document Restored' => false,
    'Welcome to CHED Compliance!' => true,
    // In-app only — login security alerts must never hit email.
    'Security Alert: Failed Login Attempts' => false,
];

// SMTP settings for PHPMailer — single source: .env → env_loader → getSmtpConfig → PHPMailer
// No hardcoded password fallback. If .env is missing, SMTP will fail fast and log clearly.
function getSmtpConfig(): array
{
    return [
        'host' => getenv('SMTP_HOST') ?: getSystemSetting('smtp_host_default', 'smtp.gmail.com'),
        'port' => (int)(getenv('SMTP_PORT') ?: getSystemSetting('smtp_port_default', 587)),
        'user' => getenv('SMTP_USER') ?: '',
        'pass' => getenv('SMTP_PASS') ?: '',
        'from' => getenv('SMTP_FROM') ?: (getenv('SMTP_USER') ?: 'noreply@localhost'),
        'from_name' => getenv('SMTP_FROM_NAME') ?: getSystemSetting('smtp_from_name_default', 'CHED Compliance System')
    ];
}
$smtp = getSmtpConfig();
define('SMTP_HOST', $smtp['host']);
define('SMTP_PORT', $smtp['port']);
define('SMTP_USER', $smtp['user']);
define('SMTP_PASS', $smtp['pass']);
define('SMTP_FROM', $smtp['from']);
define('SMTP_FROM_NAME', $smtp['from_name']);
// Validate SMTP env is present in production (log once, don't expose secret)
if (empty(SMTP_USER) || empty(SMTP_PASS)) {
    error_log('[CHED Compliance] WARNING: SMTP_USER or SMTP_PASS empty — emails will fail. Check .env');
}

// Session configuration and security
if (PHP_SAPI !== 'cli') {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // REMOTE_ADDR (TCP peer), not HTTP_HOST: a spoofed `Host: localhost` must
    // not turn off the Secure flag on the session cookie in production.
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

    if (session_status() === PHP_SESSION_NONE) {
        // Inside the guard: login.php starts its session BEFORE including this
        // file, and a session ini set while active is ignored with a warning
        // (the old config.php:131-134 spam). Before session_start() is the only
        // place gc_maxlifetime can take effect.
        ini_set('session.gc_maxlifetime', 3600);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps && !$isLocal,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    $sessionLifetime = 300; // 5 min inactivity timeout

    // Single-active-session: a token whose last real activity is older than this
    // is abandoned and can be taken over. Matched to the 5-min idle timeout so a
    // session the app would already have logged out is never a blocking "ghost".
    define('ACTIVE_SESSION_TTL', 300);

    // ✅ FIX: This check now runs for EVERY page (including login.php) so idle
    // logged-in users are always logged out after 5 minutes of inactivity.
    // login.php no longer has its own duplicate timeout check.
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $sessionLifetime)) {
        $_SESSION = [];
        session_destroy();
        if (
            basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'login.php'
            && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'logout.php'
        ) {
            header('Location: login.php?expired=1');
            exit;
        }
    }
    // Background pollers (notifications, dashboard refresh) hit the server
    // every second; counting them as activity would reset the idle clock
    // forever. Only real page loads count as user activity.
    if (isset($_SESSION['user_id']) && !isAjaxRequest()) {
        $_SESSION['last_activity'] = time();
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - (int)$_SESSION['last_regeneration'] > 300) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Every dynamic page is uncacheable: protected content must never be
    // restorable from back/forward cache after logout or lockout.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    perf_headers();
}

// Use perf_db() from performance.php for connection - enables caching
$pdo = perf_db();
if (!$pdo) {
    $errorMsg = 'Database connection failed. Please ensure MySQL is running and the database is created.';
    error_log("[CHED Compliance] Database connection error");
    die($errorMsg);
}

// Single-active-session guard — columns come from final.database.sql (formerly
// migrations/2026_single_active_session.sql). Probe once per request so the guard
// is DORMANT (login behaves exactly as before) until the columns exist;
// log loudly so a stale schema can't go unnoticed.
$GLOBALS['active_session_cols'] = false;
if (PHP_SAPI !== 'cli') {
    try {
        $pdo->query('SELECT active_session_token, active_session_last_seen FROM users WHERE id = 0');
        $GLOBALS['active_session_cols'] = true;
    } catch (Throwable $e) {
        error_log('[CHED Compliance] users.active_session_* columns missing — restore schema from final.database.sql (single-session guard dormant).');
    }
}

// ✅ FIX: Sync MySQL session timezone with PHP's.
// Without this, PHP (Asia/Manila = UTC+8) and MySQL (usually UTC) mismatch
// causes lockouts to be 8 hours long (or 8 hours short) and NOW() comparisons
// in checkLoginIpLockout() to be wrong.
try {
    $tzOffset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $pdo->exec("SET time_zone = '{$tzOffset}'");
} catch (Throwable $e) {
    error_log('[CHED Compliance] Failed to set MySQL session time_zone: ' . $e->getMessage());
}

// ===========================================================
// LOCKOUT / SESSION ENFORCEMENT — single choke point
// ===========================================================
// Every authenticated request re-checks users.locked_until against the DB.
// Session variables are never trusted for lockout state: a lock activated in
// another tab, window, or device must kill this session too.
// ponytail: revoke-by-recheck, not a session-id registry — a locked account's
// other sessions die on their NEXT request, not the instant the lock flips.
// Add a server-side sessions table with mass invalidation if instant
// multi-tab revocation is ever required.
if (PHP_SAPI !== 'cli' && !empty($_SESSION['user_id'])) {
    try {
        $enfStmt = $pdo->prepare('SELECT locked_until, active_session_token, active_session_last_seen FROM users WHERE id = ?');
        $enfStmt->execute([(int) $_SESSION['user_id']]);
        $enfRow        = $enfStmt->fetch(PDO::FETCH_ASSOC); // false = no such user
        $userMissing   = ($enfRow === false);
        $lockedUntil   = $userMissing ? false : $enfRow['locked_until'];
        // locked_until is a Unix timestamp after the login.php migration; accept legacy DATETIME too.
        $lockedTs      = ($lockedUntil === false || $lockedUntil === null)
            ? 0
            : (is_numeric($lockedUntil) ? (int)$lockedUntil : (int)strtotime($lockedUntil));
        $lockRemaining = ($lockedTs > time()) ? $lockedTs - time() : 0;

        // Single active session: whoever holds the account's current token IS the
        // live session. This session's token differing (or vanishing while the
        // account holds a token) means a newer login in another browser/device
        // displaced it — kill it. Same-browser tabs share the session cookie, so
        // they share the token and stay valid.
        $tokenReplaced = false;
        $myToken       = (string) ($_SESSION['session_token'] ?? '');
        if ($GLOBALS['active_session_cols'] && !$userMissing && !empty($enfRow['active_session_token'])
            && ($myToken === '' || !hash_equals((string) $enfRow['active_session_token'], $myToken))) {
            $tokenReplaced = true;
        }

        if ($userMissing || $lockRemaining > 0 || $tokenReplaced) {
            $killedUid = (int) $_SESSION['user_id'];
            // A displaced session must NOT clear the token: it no longer owns the
            // account. Lockout/missing-user kills DO clear it so the user can log
            // back in as soon as they are allowed to.
            if ($GLOBALS['active_session_cols'] && !$tokenReplaced && $myToken !== '') {
                try {
                    $pdo->prepare('UPDATE users SET active_session_token = NULL, active_session_last_seen = NULL WHERE id = ? AND active_session_token = ?')
                        ->execute([$killedUid, $myToken]);
                } catch (Throwable $e) {
                    error_log('[CHED Compliance] session-kill token clear failed: ' . $e->getMessage());
                }
            }
            // Audit BEFORE tearing the session down so logAudit captures the real
            // user_id and client IP. Best-effort and wrapped: logAudit can throw
            // (TypeError extends Error) and must never abort the kill.
            if ($tokenReplaced) {
                $killAction = 'session_killed_duplicate';
                $killParam  = 'session=1';
            } elseif ($lockRemaining > 0) {
                $killAction = 'session_killed_lockout';
                $killParam  = 'locked=1';
            } else {
                $killAction = 'session_killed_missing_user';
                $killParam  = 'expired=1';
            }
            try {
                logAudit($pdo, $killAction, 'Session terminated server-side for user_id=' . $killedUid);
            } catch (Throwable $ae) {
                error_log('[CHED Compliance] session-kill audit failed: ' . $ae->getMessage());
            }
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (ini_get('session.use_cookies')) {
                    $cp = session_get_cookie_params();
                    setcookie(session_name(), '', [
                        'expires'  => time() - 42000,
                        'path'     => $cp['path'],
                        'domain'   => $cp['domain'],
                        'secure'   => $cp['secure'],
                        'httponly' => $cp['httponly'],
                        'samesite' => $cp['samesite'] ?? 'Strict',
                    ]);
                }
                session_destroy();
            }
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
            if ($isAjax) {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Session expired or account locked.', 'redirect' => 'login.php']);
                exit;
            }
            header('Location: login.php' . ($killParam !== '' ? '?' . $killParam : ''));
            exit;
        } elseif ($GLOBALS['active_session_cols'] && $myToken !== '') {
            // Heartbeat the token's last-seen so a genuinely live session stays
            // "fresh" (denies takeover) while an abandoned one frees after TTL.
            // Only real page loads count, mirroring the idle clock — background
            // pollers must not keep a token alive forever. Throttled to 60s.
            $lastSeen = (int) ($enfRow['active_session_last_seen'] ?? 0);
            if (!isAjaxRequest() && (time() - $lastSeen >= 60)) {
                try {
                    $pdo->prepare('UPDATE users SET active_session_last_seen = ? WHERE id = ? AND active_session_token = ?')
                        ->execute([time(), (int) $_SESSION['user_id'], $myToken]);
                } catch (Throwable $e) {
                    error_log('[CHED Compliance] session heartbeat failed: ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        // Fail closed for auth errors would break the whole app on a transient DB
        // blip; log loudly instead. Lockout still enforced at login time.
        error_log('[CHED Compliance] Lockout enforcement query failed: ' . $e->getMessage());
    }
}

// ===========================================================
// SYSTEM SETTINGS
// ===========================================================
// No DB-backed settings table (never had a writer). Values are set here or via .env.
// ponytail: settings need a code edit to change, add a settings table + admin UI when operators must tune them at runtime
function getSystemSetting(string $key, $default = null)
{
    return $default;
}

define('MAX_FILE_SIZE', $MAX_FILE_SIZE);
define('ALLOWED_EXTENSIONS', $ALLOWED_EXTENSIONS);
define('ALLOWED_MIMES', $ALLOWED_MIMES);
define('ALLOWED_EXTENSIONS_LABEL', $ALLOWED_EXTENSIONS_LABEL);
define('EXPIRY_WARNING_DAYS', $EXPIRY_WARNING_DAYS);

// ===========================================================
// ROLE CHECKS
// ===========================================================

function isAdmin(): bool
{
    return isset($_SESSION['admin_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
}

function isDepartment(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['department', 'unit_head'], true);
}

function phRoleName(?string $role): string
{
    return $role === 'unit_head' ? 'Unit Head' : 'Program Head';
}

function currentRoleName(): string
{
    return phRoleName($_SESSION['role'] ?? '');
}

function phRoleShortFor(?string $role): string
{
    return $role === 'unit_head' ? 'UH' : 'PH';
}

function phRoleShort(): string
{
    return phRoleShortFor($_SESSION['role'] ?? '');
}

function facultyRoleName(?string $role): string
{
    return $role === 'staff' ? 'Staff' : 'Teacher';
}

function isTeacher(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['teacher', 'staff'], true);
}

function creatableRoles(?string $actorRole): array
{
    switch ($actorRole) {
        case 'admin':
            return ['department', 'unit_head'];
        case 'department':
        case 'unit_head':
            return ['teacher', 'staff'];
        default:
            return [];
    }
}

function allowedRoleFor(?string $actorRole, ?string $requested): ?string
{
    return in_array($requested, creatableRoles($actorRole), true) ? $requested : null;
}

function refreshSessionRoleFromDb(): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT role, department_id FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            $_SESSION = [];
            session_destroy();
            header('Location: login.php');
            exit;
        }
        $_SESSION['role'] = $row['role'];
        $_SESSION['department_id'] = $row['department_id'];
        if ($row['role'] === 'admin') {
            $_SESSION['admin_id'] = (int)$_SESSION['user_id'];
        } else {
            unset($_SESSION['admin_id']);
        }
    } catch (Throwable $e) {
        // Fail open deliberately: this runs on every authenticated page via
        // require*(); denying here would mass-logout everyone on a transient DB
        // blip. Lockout enforcement (config.php top) already fails closed.
        error_log('[CHED Compliance] refreshSessionRoleFromDb failed: ' . $e->getMessage());
    }
}

function requireAdmin(): void
{
    refreshSessionRoleFromDb();
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }
    if (!isset($_SESSION['admin_id'])) {
        $_SESSION['admin_id'] = $_SESSION['user_id'];
    }
}

function requireDepartment(): void
{
    refreshSessionRoleFromDb();
    if (!isDepartment()) {
        header('Location: login.php');
        exit;
    }
    try {
        global $pdo;
        $stmt = $pdo->prepare(
            "SELECT u.department_id AS dept_id
               FROM users u
              WHERE u.id = ?"
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row && !empty($row['dept_id'])) {
            $_SESSION['department_id'] = (int) $row['dept_id'];
        } else {
            $_SESSION['department_id'] = 0;
        }
    } catch (Throwable $e) {
        $_SESSION['department_id'] = 0;
    }
}

function requireTeacher(): void
{
    refreshSessionRoleFromDb();
    if (!isTeacher()) {
        header('Location: login.php');
        exit;
    }
    try {
        global $pdo;
        $stmt = $pdo->prepare(
            "SELECT u.department_id AS dept_id, d.id AS dept_exists
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.id = ?"
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row && !empty($row['dept_id']) && !empty($row['dept_exists'])) {
            $_SESSION['department_id'] = (int) $row['dept_id'];
        } else {
            $_SESSION['department_id'] = 0;
        }
    } catch (Throwable $e) {
        $_SESSION['department_id'] = 0;
    }
}

// ===========================================================
// UTILITY
// ===========================================================

function sanitize(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function timeAgo($timestamp): string
{
    if (empty($timestamp)) {
        return 'Unknown';
    }
    if (!is_numeric($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    if ($timestamp === false) {
        return 'Invalid date';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'just now';
    }
    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute'
    ];
    foreach ($units as $unit => $label) {
        if ($diff < $unit) continue;
        $numberOfUnits = floor($diff / $unit);
        return $numberOfUnits . ' ' . $label . ($numberOfUnits > 1 ? 's' : '') . ' ago';
    }
    return date('M j, Y', $timestamp);
}

function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
            exit;
        }
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

function getCsrfToken(): string
{
    return $_SESSION['csrf_token'] ?? generateCsrfToken();
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . getCsrfToken() . '">';
}

// ===========================================================
// EXPIRATION MONITORING - single source of truth (2-day rule)
// ===========================================================

function classifyExpiryStatus(?string $expiryDate): string
{
    global $EXPIRY_WARNING_DAYS;
    $d = substr(trim((string)($expiryDate ?? '')), 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return 'active';
    }
    $today = date('Y-m-d');
    if ($today >= $d) {
        return 'expired';
    }
    if ($today >= date('Y-m-d', strtotime($d . ' -' . $EXPIRY_WARNING_DAYS . ' days'))) {
        return 'expiring';
    }
    return 'active';
}

function expiryStatusLabel(string $status): string
{
    return ['active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired'][$status] ?? ucfirst($status);
}

// ===========================================================
// AUDIT
// ===========================================================

$TRUSTED_PROXIES = [];

function getClientIP()
{
    global $TRUSTED_PROXIES;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // (array) cast: getClientIP may run from config's own early enforcement block,
    // before the $TRUSTED_PROXIES assignment below executes (order-of-include).
    if (in_array($remote, (array) $TRUSTED_PROXIES, true)) {
        $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $remote = $forwarded;
        }
    }
    return $remote === '::1' ? '127.0.0.1' : $remote;
}

function logAudit(PDO $pdo, string $action, string $description = '', ?string $entityType = null, ?int $entityId = null): void
{
    $userId = $_SESSION['user_id'] ?? null;
    $timestamp = date('Y-m-d H:i:s');
    if (function_exists('getClientIP')) {
        $description .= ' [ip=' . getClientIP() . ']';
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_trail (user_id, action, description, entity_type, entity_id, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $description, $entityType, $entityId, $timestamp]);
    } catch (Exception $e) {
        error_log('[CHED Compliance] Audit log error: ' . $e->getMessage());
    }
}

function isDangerousMime(string $mime): bool
{
    return (bool) preg_match('/(?:php|executable|dosexec|msdos|msdownload|sharedlib|shellscript|html|bat)/i', $mime);
}

// Human-readable label for a byte ceiling (e.g. 10485760 -> "10MB").
function megabytesLabel(int $bytes): string
{
    return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . 'MB';
}

// General document size ceiling derived from MAX_FILE_SIZE so the message can
// never drift from the enforced limit (fixes the old hardcoded "10MB"/"100MB").
function maxFileSizeLabel(): string
{
    return megabytesLabel(MAX_FILE_SIZE);
}

// Images uploaded as documents are capped lower than other document types.
if (!defined('IMAGE_MAX_BYTES')) {
    define('IMAGE_MAX_BYTES', 10 * 1024 * 1024); // 10 MB
}

function isImageExtension(string $ext): bool
{
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

// Effective size ceiling for an upload: 10 MB for images, MAX_FILE_SIZE otherwise.
function maxFileSizeBytesFor(string $ext): int
{
    return isImageExtension($ext) ? IMAGE_MAX_BYTES : MAX_FILE_SIZE;
}

function maxFileSizeLabelFor(string $ext): string
{
    return megabytesLabel(maxFileSizeBytesFor($ext));
}

// Map PHP's $_FILES['error'] codes to a message a user can act on. Error 1
// (UPLOAD_ERR_INI_SIZE) is what users hit when a file exceeds php.ini's
// upload_max_filesize/post_max_size, not the app limit.
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'File is too large — the server accepts at most ' . maxFileSizeLabel() . ' per file.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form\'s maximum size (' . maxFileSizeLabel() . ').',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by a server extension.',
        default               => "File upload failed (error code $code).",
    };
}

// ===========================================================
// IN-APP NOTIFICATIONS
// ===========================================================

function shouldSendEmailForNotification(string $title, string $type): bool
{
    global $NOTIFICATION_EMAIL_ENABLED;
    if (array_key_exists($title, $NOTIFICATION_EMAIL_ENABLED)) {
        return (bool)$NOTIFICATION_EMAIL_ENABLED[$title];
    }
    if (in_array($type, ['danger', 'success', 'warning'], true)) return true;
    return true;
}

function adminNotifPref(string $key, bool $default = true): bool
{
    $prefs = getSystemSetting('admin_notif_prefs', null);
    if (!is_array($prefs) || !array_key_exists($key, $prefs)) {
        return $default;
    }
    return (bool)$prefs[$key];
}

function adminNotifCategoryAllows(string $title, string $type): bool
{
    if (stripos($title, 'Security Alert') !== false || $type === 'danger') {
        return true;
    }
    $t = $title;
    if (preg_match('/Requirement (Assigned|Updated|Expired|Removed)|New (Department )?Requirement|New Requirement/i', $t)) {
        return adminNotifPref('assignment');
    }
    if (preg_match('/Submission|Document (Uploaded|Forwarded|Resubmitted|Restored)|New Document|New PH Document|Welcome/i', $t)) {
        return adminNotifPref('submission');
    }
    if (preg_match('/Approved|Returned|Rejected|Review Complete|Fully Approved/i', $t)) {
        return adminNotifPref('approval');
    }
    if (preg_match('/Expired|Expiring/i', $t)) {
        return adminNotifPref('expiration');
    }
    return true;
}

function createNotification(PDO $pdo, int $userId, string $title, string $message, string $type = 'info', ?int $docId = null, ?string $docType = null): void
{
    try {
        if (empty($title) || $title === 'Unknown' || empty($message)) {
            error_log('[CHED Compliance] Invalid notification - empty title or message');
            return;
        }

        $isAdminRecip = false;
        try {
            $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $roleStmt->execute([$userId]);
            $isAdminRecip = $roleStmt->fetchColumn() === 'admin';
        } catch (Exception $e) {
            $isAdminRecip = false;
        }
        $allowAdminInApp = true;
        $allowAdminEmail = true;
        if ($isAdminRecip) {
            $allowAdminInApp = adminNotifPref('in_app', true) && adminNotifCategoryAllows($title, $type);
            $allowAdminEmail = adminNotifPref('email', true) && adminNotifCategoryAllows($title, $type);
            if (!$allowAdminInApp && !$allowAdminEmail) {
                error_log('[CHED Compliance] Admin notif prefs skipped: ' . $title);
                return;
            }
        }

        if ($allowAdminInApp) {
            if (checkDuplicateNotification($pdo, $userId, $title, $message, $docId, $docType)) {
                error_log('[CHED Compliance] Duplicate notification skipped: ' . $title);
                return;
            }

            $rateCheck = $pdo->prepare("SELECT COUNT(*) FROM in_app_notifications WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $rateCheck->execute([$userId]);
            if ((int)$rateCheck->fetchColumn() >= 30) {
                error_log('[CHED Compliance] Rate limit hit for user_id=' . $userId);
                return;
            }

            $stmt = $pdo->prepare('INSERT INTO in_app_notifications (user_id, title, message, type, related_document_id, related_doc_type, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            $result = $stmt->execute([$userId, $title, $message, $type, $docId, $docType]);

            if (!$result) {
                error_log('[CHED Compliance] Notification insert failed for user_id: ' . $userId . ', title: ' . $title);
                return;
            }

            $notificationId = (int)$pdo->lastInsertId();
            error_log('[CHED Compliance] QUEUED notification id=' . $notificationId . ' user_id=' . $userId . ' title=' . $title . ' type=' . $type);
        } else {
            $notificationId = 0;
            error_log('[CHED Compliance] Admin in-app pref off; email-only for title=' . $title);
        }

        if ($isAdminRecip && !$allowAdminEmail) {
            error_log('[CHED Compliance] Admin email pref off for title=' . $title);
            return;
        }
        if (!shouldSendEmailForNotification($title, $type)) {
            error_log('[CHED Compliance] Email skipped (type disabled) for title=' . $title);
            return;
        }
        $user = getUserById($pdo, $userId);
        if ($user && !empty($user['email'])) {
            if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                error_log('[CHED Compliance] Invalid email for user_id=' . $userId);
                return;
            }
            require_once __DIR__ . '/send_email.php';
            $emailBody = buildRecipientEmailBody($user, $title, $message);
            $queued = sendEmail($user['email'], $title, $emailBody, true, null, $userId, $notificationId);
            error_log('[CHED Compliance] Email QUEUED for ' . $user['email'] . ' title=' . $title . ' result=' . ($queued ? 'QUEUED' : 'FAILED'));
        } else {
            error_log('[CHED Compliance] No email — user_id: ' . $userId . ' found:' . ($user ? 'yes' : 'no'));
        }
    } catch (Exception $e) {
        error_log('[CHED Compliance] Notification exception: ' . $e->getMessage());
    }
}

function getUnreadNotificationCount(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM in_app_notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function markNotificationsRead(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare('UPDATE in_app_notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    } catch (Exception $e) {
    }
}

function markNotificationAsRead(PDO $pdo, int $notificationId, int $userId): void
{
    try {
        $stmt = $pdo->prepare('UPDATE in_app_notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    } catch (Exception $e) {
    }
}

function markSingleNotificationRead(PDO $pdo, int $notificationId, int $userId): void
{
    markNotificationAsRead($pdo, $notificationId, $userId);
}

function fetchValidNotifications(PDO $pdo, int $userId, int $afterId = 0): array
{
    $sql = "
        SELECT
            n.id,
            n.user_id,
            n.title,
            n.message,
            n.type,
            n.is_read,
            n.related_document_id,
            n.related_doc_type,
            n.created_at,
            COALESCE(d.title, pd.title, phr.title, 'Document') as doc_title,
            COALESCE(d.upload_date, pd.upload_date, phr.created_at) as doc_upload_date,
            COALESCE(d.filename, pd.filename, phr.filename) as doc_filename,
            COALESCE(n.related_doc_type,
                CASE
                    WHEN pd.id IS NOT NULL THEN 'ph_document'
                    WHEN phr.id IS NOT NULL THEN 'ph_requirement'
                    ELSE 'document'
                END) as doc_type
        FROM in_app_notifications n
        LEFT JOIN documents d ON (n.related_doc_type IS NULL OR n.related_doc_type = 'document') AND n.related_document_id = d.id AND d.id IS NOT NULL
        LEFT JOIN ph_documents pd ON n.related_doc_type = 'ph_document' AND n.related_document_id = pd.id AND pd.id IS NOT NULL
        LEFT JOIN ph_requirements phr ON n.related_doc_type = 'ph_requirement' AND n.related_document_id = phr.id AND phr.id IS NOT NULL
        WHERE n.user_id = ?
          AND n.title IS NOT NULL
          AND n.title != ''
          AND n.title != 'Unknown'
          AND n.message IS NOT NULL
          AND n.message != ''
    ";
    $params = [$userId];
    if ($afterId > 0) {
        $sql .= " AND n.id > ?";
        $params[] = $afterId;
    }
    $sql .= " ORDER BY n.created_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchRecentValidNotifications(PDO $pdo, int $userId, int $limit = 6): array
{
    $stmt = $pdo->prepare("
        SELECT
            n.id,
            n.user_id,
            n.title,
            n.message,
            n.type,
            n.is_read,
            n.related_document_id,
            n.related_doc_type,
            n.created_at,
            COALESCE(d.title, pd.title, phr.title, 'Document') as doc_title,
            COALESCE(d.filename, pd.filename, phr.filename) as doc_filename,
            COALESCE(n.related_doc_type,
                CASE
                    WHEN pd.id IS NOT NULL THEN 'ph_document'
                    WHEN phr.id IS NOT NULL THEN 'ph_requirement'
                    ELSE 'document'
                END) as doc_type
        FROM in_app_notifications n
        LEFT JOIN documents d ON (n.related_doc_type IS NULL OR n.related_doc_type = 'document') AND n.related_document_id = d.id AND d.id IS NOT NULL
        LEFT JOIN ph_documents pd ON n.related_doc_type = 'ph_document' AND n.related_document_id = pd.id AND pd.id IS NOT NULL
        LEFT JOIN ph_requirements phr ON n.related_doc_type = 'ph_requirement' AND n.related_document_id = phr.id AND phr.id IS NOT NULL
        WHERE n.user_id = ?
          AND n.title IS NOT NULL
          AND n.title != ''
          AND n.title != 'Unknown'
          AND n.message IS NOT NULL
          AND n.message != ''
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function checkDuplicateNotification(PDO $pdo, int $userId, string $title, string $message, ?int $relatedDocumentId, ?string $relatedDocType = null): bool
{
    if ($relatedDocumentId === null) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM in_app_notifications 
            WHERE user_id = ? 
              AND title = ? 
              AND message = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$userId, $title, $message]);
        return (int)$stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM in_app_notifications 
        WHERE user_id = ? 
          AND title = ? 
          AND message = ?
          AND (related_document_id <=> ?)
          AND (related_doc_type <=> ?)
          AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$userId, $title, $message, $relatedDocumentId, $relatedDocType]);
    return (int)$stmt->fetchColumn() > 0;
}

function deleteInvalidNotifications(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("
        DELETE FROM in_app_notifications 
        WHERE user_id = ? 
          AND (title IS NULL OR title = '' OR title = 'Unknown' OR message IS NULL OR message = '')
    ");
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

// ===========================================================
// REQUIREMENTS
// ===========================================================

function getTeacherProgress(PDO $pdo, int $teacherId): array
{
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM teacher_requirements WHERE teacher_id = ?) as dept_total,
            (SELECT COUNT(*) FROM teacher_ph_requirements WHERE teacher_id = ?) as ph_total
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$teacherId, $teacherId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalCount = (int)$totals['dept_total'] + (int)$totals['ph_total'];

    if ($totalCount === 0) {
        return ['total' => 0, 'approved' => 0, 'submitted' => 0, 'pending' => 0, 'rejected' => 0, 'percentage' => 0, 'completed_areas' => []];
    }

    $progressSql = "
        SELECT 
            SUM(CASE WHEN d1.status = 'approved_admin' THEN 1 ELSE 0 END) as approved_dept,
            SUM(CASE WHEN d1.status IN ('pending_program_head', 'pending_admin') THEN 1 ELSE 0 END) as submitted_dept,
            SUM(CASE WHEN d1.status IN ('rejected_program_head', 'rejected_admin') THEN 1 ELSE 0 END) as rejected_dept
        FROM documents d1
        INNER JOIN teacher_requirements tr ON d1.teacher_requirement_id = tr.id
        INNER JOIN (
            SELECT teacher_requirement_id, MAX(version) as max_ver
            FROM documents GROUP BY teacher_requirement_id
        ) latest ON d1.teacher_requirement_id = latest.teacher_requirement_id AND d1.version = latest.max_ver
        WHERE tr.teacher_id = ?
    ";
    $stmt = $pdo->prepare($progressSql);
    $stmt->execute([$teacherId]);
    $deptStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $progressPH = "
        SELECT 
            SUM(CASE WHEN pd1.status IN ('approved_program_head', 'approved_admin') THEN 1 ELSE 0 END) as approved_ph,
            SUM(CASE WHEN pd1.status = 'pending_program_head' THEN 1 ELSE 0 END) as submitted_ph,
            SUM(CASE WHEN pd1.status = 'rejected_program_head' THEN 1 ELSE 0 END) as rejected_ph
        FROM ph_documents pd1
        INNER JOIN teacher_ph_requirements tpr ON pd1.teacher_ph_requirement_id = tpr.id
        INNER JOIN (
            SELECT teacher_ph_requirement_id, MAX(version) as max_ver
            FROM ph_documents GROUP BY teacher_ph_requirement_id
        ) latest ON pd1.teacher_ph_requirement_id = latest.teacher_ph_requirement_id AND pd1.version = latest.max_ver
        WHERE tpr.teacher_id = ?
    ";
    $stmt = $pdo->prepare($progressPH);
    $stmt->execute([$teacherId]);
    $phStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $approvedCount = (int)($deptStats['approved_dept'] ?? 0) + (int)($phStats['approved_ph'] ?? 0);
    $submittedCount = (int)($deptStats['submitted_dept'] ?? 0) + (int)($phStats['submitted_ph'] ?? 0);
    $rejectedCount = (int)($deptStats['rejected_dept'] ?? 0) + (int)($phStats['rejected_ph'] ?? 0);

    $completedAreas = $pdo->prepare("
        SELECT DISTINCT dr.category, dr.document_type
        FROM documents d
        JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        JOIN department_requirements dr ON tr.requirement_id = dr.id
        JOIN (
            SELECT teacher_requirement_id, MAX(version) as max_ver
            FROM documents GROUP BY teacher_requirement_id
        ) latest ON d.teacher_requirement_id = latest.teacher_requirement_id AND d.version = latest.max_ver
        WHERE tr.teacher_id = ? AND d.status = 'approved_admin'
        ORDER BY dr.category, dr.document_type
    ");
    $completedAreas->execute([$teacherId]);
    $completedAreasList = $completedAreas->fetchAll(PDO::FETCH_ASSOC);

    $completedAreasPH = $pdo->prepare("
        SELECT DISTINCT 'PH' AS category, phr.title AS document_type
        FROM ph_documents pd
        JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
        JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
        JOIN (
            SELECT teacher_ph_requirement_id, MAX(version) as max_ver
            FROM ph_documents GROUP BY teacher_ph_requirement_id
        ) latest ON pd.teacher_ph_requirement_id = latest.teacher_ph_requirement_id AND pd.version = latest.max_ver
        WHERE tpr.teacher_id = ? AND pd.status IN ('approved_program_head', 'approved_admin')
        ORDER BY phr.title
    ");
    $completedAreasPH->execute([$teacherId]);
    $completedAreasPHList = $completedAreasPH->fetchAll(PDO::FETCH_ASSOC);

    $completedAreasList = array_merge($completedAreasList, $completedAreasPHList);

    $percentage = round(($approvedCount / $totalCount) * 100);
    return [
        'total' => $totalCount,
        'approved' => $approvedCount,
        'submitted' => $submittedCount,
        'rejected' => $rejectedCount,
        'pending' => $totalCount - $approvedCount - $submittedCount - $rejectedCount,
        'percentage' => $percentage,
        'completed_areas' => $completedAreasList
    ];
}

// ===========================================================
// OPTIMIZED FUNCTIONS (Performance Fixes)
// ===========================================================

function getTeacherRequirementsOptimized(PDO $pdo, int $teacherId): array
{
    $sql = "
        SELECT tr.*,
               COALESCE(dr.category, 'Deleted Requirement') AS category,
               COALESCE(dr.document_type, 'Deleted Requirement') AS document_type,
               COALESCE(dr.mandatory, 1) AS mandatory,
               dr.expiration_date,
               dr.description,
               latest_doc.id AS submission_id,
               latest_doc.status AS current_status,
               latest_doc.version AS latest_version,
               latest_doc.upload_date AS last_uploaded,
               latest_doc.program_head_comment AS latest_ph_comment,
               latest_doc.admin_comment AS latest_admin_comment,
               latest_doc.filename AS latest_filename,
               latest_doc.file_path AS submission_file_path,
               tr.requirement_type
        FROM teacher_requirements tr
        LEFT JOIN department_requirements dr ON tr.requirement_id = dr.id
        LEFT JOIN (
            SELECT d1.* FROM documents d1
            INNER JOIN (
                SELECT teacher_requirement_id, MAX(version) as max_version
                FROM documents
                GROUP BY teacher_requirement_id
            ) d2 ON d1.teacher_requirement_id = d2.teacher_requirement_id 
                AND d1.version = d2.max_version
        ) latest_doc ON tr.id = latest_doc.teacher_requirement_id
        WHERE tr.teacher_id = ?
        ORDER BY dr.category, dr.document_type
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$teacherId]);
    $results = $stmt->fetchAll();

    return array_values(array_filter($results, function ($req) {
        $filePath = $req['submission_file_path'] ?? null;
        if (empty($filePath)) return true;
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));
}

function getTeacherPHRequirementsOptimized(PDO $pdo, int $teacherId): array
{
    $sql = "
        SELECT tpr.*,
               phr.id AS ph_requirement_id,
               phr.created_by,
               phr.title,
               phr.description,
               phr.deadline,
               phr.filename AS ref_filename,
               phr.file_path AS ref_file_path,
               latest_pd.id AS submission_id,
               latest_pd.status AS current_status,
               latest_pd.version AS latest_version,
               latest_pd.upload_date AS last_uploaded,
               latest_pd.program_head_comment AS latest_ph_comment,
               latest_pd.admin_comment AS latest_admin_comment,
               latest_pd.filename AS latest_filename,
               latest_pd.file_path AS submission_file_path,
               tpr.requirement_type
        FROM teacher_ph_requirements tpr
        JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
        LEFT JOIN (
            SELECT pd1.* FROM ph_documents pd1
            INNER JOIN (
                SELECT teacher_ph_requirement_id, MAX(version) as max_version
                FROM ph_documents
                GROUP BY teacher_ph_requirement_id
            ) pd2 ON pd1.teacher_ph_requirement_id = pd2.teacher_ph_requirement_id 
                AND pd1.version = pd2.max_version
        ) latest_pd ON tpr.id = latest_pd.teacher_ph_requirement_id
        WHERE tpr.teacher_id = ?
        ORDER BY phr.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$teacherId]);
    $results = $stmt->fetchAll();

    return array_values(array_filter($results, function ($req) {
        $filePath = $req['submission_file_path'] ?? null;
        if (empty($filePath)) return true;
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));
}

function getAllTeacherProgressForProgramHead(PDO $pdo, int $phId, int $deptId): array
{
    $teacherQuery = "
        SELECT DISTINCT u.id AS teacher_id, u.name, u.email, u.role
        FROM users u
        LEFT JOIN teacher_requirements tr ON u.id = tr.teacher_id AND tr.assigned_by = ?
        LEFT JOIN teacher_ph_requirements tpr ON u.id = tpr.teacher_id
        WHERE u.role IN ('teacher','staff') AND u.department_id = ?
          AND (tr.id IS NOT NULL OR tpr.id IS NOT NULL)
        ORDER BY u.name
    ";

    $stmt = $pdo->prepare($teacherQuery);
    $stmt->execute([$phId, $deptId]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($teachers)) {
        return [];
    }

    $teacherIds = array_column($teachers, 'teacher_id');

    $deptQuery = "
        SELECT tr.teacher_id,
               COUNT(DISTINCT tr.id) AS total,
               COUNT(DISTINCT CASE WHEN doc_agg.status = 'approved_admin' THEN tr.id END) AS approved,
               COUNT(DISTINCT CASE WHEN doc_agg.status IN ('pending_program_head', 'pending_admin') THEN tr.id END) AS submitted,
               COUNT(DISTINCT CASE WHEN doc_agg.status IN ('rejected_program_head', 'rejected_admin') THEN tr.id END) AS rejected
        FROM teacher_requirements tr
        LEFT JOIN (
            SELECT d.teacher_requirement_id, d.status
            FROM documents d
            INNER JOIN (
                SELECT teacher_requirement_id, MAX(version) as max_ver
                FROM documents
                GROUP BY teacher_requirement_id
            ) latest ON d.teacher_requirement_id = latest.teacher_requirement_id AND d.version = latest.max_ver
        ) doc_agg ON tr.id = doc_agg.teacher_requirement_id
        WHERE tr.assigned_by = ? AND tr.teacher_id IN (" . implode(',', array_fill(0, count($teacherIds), '?')) . ")
        GROUP BY tr.teacher_id
    ";

    $deptParams = array_merge([$phId], $teacherIds);
    $stmt = $pdo->prepare($deptQuery);
    $stmt->execute($deptParams);
    $deptResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deptProgress = [];
    foreach ($deptResults as $row) {
        $deptProgress[$row['teacher_id']] = [
            'total' => (int)$row['total'],
            'approved' => (int)$row['approved'],
            'submitted' => (int)$row['submitted'],
            'rejected' => (int)$row['rejected']
        ];
    }

    $phQuery = "
        SELECT tpr.teacher_id,
               COUNT(DISTINCT tpr.id) AS total,
               COUNT(DISTINCT CASE WHEN pd_agg.status IN ('approved_program_head', 'approved_admin') THEN tpr.id END) AS approved,
               COUNT(DISTINCT CASE WHEN pd_agg.status IN ('pending_program_head', 'pending_admin') THEN tpr.id END) AS submitted,
               COUNT(DISTINCT CASE WHEN pd_agg.status IN ('rejected_program_head', 'rejected_admin') THEN tpr.id END) AS rejected
        FROM teacher_ph_requirements tpr
        LEFT JOIN (
            SELECT pd.teacher_ph_requirement_id, pd.status
            FROM ph_documents pd
            INNER JOIN (
                SELECT teacher_ph_requirement_id, MAX(version) as max_ver
                FROM ph_documents
                GROUP BY teacher_ph_requirement_id
            ) latest ON pd.teacher_ph_requirement_id = latest.teacher_ph_requirement_id AND pd.version = latest.max_ver
        ) pd_agg ON tpr.id = pd_agg.teacher_ph_requirement_id
        WHERE tpr.teacher_id IN (" . implode(',', array_fill(0, count($teacherIds), '?')) . ")
        GROUP BY tpr.teacher_id
    ";

    $stmt = $pdo->prepare($phQuery);
    $stmt->execute($teacherIds);
    $phResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $phProgress = [];
    foreach ($phResults as $row) {
        $phProgress[$row['teacher_id']] = [
            'total' => (int)$row['total'],
            'approved' => (int)$row['approved'],
            'submitted' => (int)$row['submitted'],
            'rejected' => (int)$row['rejected']
        ];
    }

    $progressData = [];
    foreach ($teachers as $teacher) {
        $teacherId = $teacher['teacher_id'];
        $dept = $deptProgress[$teacherId] ?? ['total' => 0, 'approved' => 0, 'submitted' => 0, 'rejected' => 0];
        $ph = $phProgress[$teacherId] ?? ['total' => 0, 'approved' => 0, 'submitted' => 0, 'rejected' => 0];

        $total = $dept['total'] + $ph['total'];
        $approved = $dept['approved'] + $ph['approved'];
        $submitted = $dept['submitted'] + $ph['submitted'];
        $rejected = $dept['rejected'] + $ph['rejected'];
        $percentage = $total > 0 ? round(($approved / $total) * 100) : 0;

        $progressData[$teacherId] = [
            'id' => (int)$teacherId,
            'name' => $teacher['name'],
            'email' => $teacher['email'],
            'role' => $teacher['role'] ?? 'teacher',
            'progress' => [
                'total' => $total,
                'approved' => $approved,
                'submitted' => $submitted,
                'pending' => max(0, $total - $approved - $submitted - $rejected),
                'rejected' => $rejected,
                'percentage' => $percentage
            ]
        ];
    }

    return $progressData;
}

function uploadTeacherPHSubmission(PDO $pdo, int $teacherPHRequirementId, int $teacherId, array $file, ?string $comment = ''): bool|string
{
    if (!isset($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }

    $stmt = $pdo->prepare("
        SELECT phr.title, phr.created_by, phr.deadline, d.id AS dept_id
        FROM teacher_ph_requirements tpr
        JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
        JOIN users u ON tpr.teacher_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE tpr.id = ?
    ");
    $stmt->execute([$teacherPHRequirementId]);
    $req = $stmt->fetch();
    if (!$req) {
        return "Requirement not found.";
    }

    if (!empty($req['deadline'])) {
        $deadlineDate = strtotime($req['deadline']);
        $today = strtotime(date('Y-m-d'));
        if ($deadlineDate < $today) {
            return "Deadline has passed for requirement: {$req['title']}. The deadline was " . date('M d, Y', $deadlineDate);
        }
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return "Invalid file type. Allowed: " . ALLOWED_EXTENSIONS_LABEL . ".";
    }
    if ($file['size'] > maxFileSizeBytesFor($ext)) {
        return "File size must not exceed " . maxFileSizeLabelFor($ext) . ".";
    }
    // C-1 fail closed: a false $mime would slip past the allow-list via the
    // extension fallback map (isDangerousMime(false) === false).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) finfo_close($finfo);
    if ($mime === false) {
        return "Could not read the uploaded file to verify its type. Please try again.";
    }
    if (!in_array($mime, ALLOWED_MIMES)) {
        if (isDangerousMime($mime)) {
            return "File content does not match its extension.";
        }
        $mimeByExt = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if (!isset($mimeByExt[$ext])) {
            return "Invalid file type. Allowed: " . ALLOWED_EXTENSIONS_LABEL . ".";
        }
    }

    $hash = hashUploadedTempFile($file);
    if ($hash === null) {
        return 'Could not read the uploaded file to verify its contents. Please try again.';
    }
    $scopeKey = 'updoc:phdoc:req:' . $teacherPHRequirementId;
    $locked = acquireUploadLock($pdo, $scopeKey);
    if ($locked) {
        register_shutdown_function(function () use ($pdo, $scopeKey) { releaseUploadLock($pdo, $scopeKey); });
    }
    if ($hash !== null) {
        $dupId = findDuplicateAssignmentDoc($pdo, 'ph_documents', 'teacher_ph_requirement_id', $teacherPHRequirementId, $hash);
        if ($dupId) {
            logAudit($pdo, 'upload_duplicate_blocked', "Blocked duplicate PH submission for requirement $teacherPHRequirementId (matches doc #$dupId)", 'ph_document', $dupId);
            return 'This exact file has already been submitted for this requirement. Edit the document and upload it again.';
        }
    }

    $stmt = $pdo->prepare("SELECT MAX(version) FROM ph_documents WHERE teacher_ph_requirement_id = ?");
    $stmt->execute([$teacherPHRequirementId]);
    $maxVersion = (int) $stmt->fetchColumn();
    $newVersion = $maxVersion + 1;

    $subdir = getFileStorageDir('pending_program_head');
    $dirAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
    $uniqueFile = allocateStorageFilename($dirAbs, (string)$req['title'], $ext);
    $filepath = $dirAbs . $uniqueFile;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        @unlink($filepath);
        return "Failed to save file. Check upload directory permissions.";
    }

    $stmt = $pdo->prepare("
        INSERT INTO ph_documents (
            teacher_ph_requirement_id, department_id, title, filename, file_path,
            uploaded_by, status, version, upload_date, description, expiry_date, validity_status
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending_program_head', ?, NOW(), ?, ?, ?)
    ");
    $filePath = $subdir . '/' . $uniqueFile;
    $success = $stmt->execute([
        $teacherPHRequirementId,
        $req['dept_id'],
        $req['title'],
        $file['name'],
        $filePath,
        $teacherId,
        $newVersion,
        $comment ?? '',
        $req['deadline'] ?? null,
        classifyExpiryStatus($req['deadline'] ?? null)
    ]);

    if ($success) {
        $docId = (int) $pdo->lastInsertId();
        finalizeStorageFilename($pdo, $docId, 'ph_documents', (string)$req['title'], $ext);
        ensureDocumentHash($pdo, 'ph_documents', $docId);
        if ($locked) releaseUploadLock($pdo, $scopeKey);
        logAudit($pdo, 'teacher_ph_upload', "Teacher uploaded version $newVersion for PH requirement ID $teacherPHRequirementId", 'ph_document', $docId);

        $programHead = getUserById($pdo, $req['created_by']);
        if ($programHead) {
            $teacher = getUserById($pdo, $teacherId);
            $phShort = phRoleShortFor($programHead['role'] ?? null);
            createNotification(
                $pdo,
                $programHead['id'],
                "New {$phShort} Requirement Submission",
                facultyRoleName($teacher['role'] ?? '') . " {$teacher['name']} submitted '{$req['title']}' (v$newVersion). Awaiting your review.",
                'info',
                $docId,
                'ph_document'
            );
        }
        return true;
    }
    if (file_exists($filepath)) unlink($filepath);
    return "Database error.";
}

function sendTemplateToTeacher(PDO $pdo, int $teacherId, int $phRequirementId, string $requirementTitle, bool $deferNotifications = false): bool
{
    static $templateCache = [];
    if (!isset($templateCache[$phRequirementId])) {
        $stmt = $pdo->prepare("SELECT filename, file_path, title FROM ph_requirements WHERE id = ?");
        $stmt->execute([$phRequirementId]);
        $templateCache[$phRequirementId] = $stmt->fetch() ?: [];
    }
    $req = $templateCache[$phRequirementId];

    $hasTemplate = !empty($req['filename']) && !empty($req['file_path']);

    $templateMsg = $hasTemplate
        ? " A template file is available for this requirement - you can download it when submitting your document."
        : "";

    if (!$deferNotifications) {
        createNotification(
            $pdo,
            $teacherId,
            'New Requirement Assigned',
            "You have been assigned a new requirement by your " . currentRoleName() . ": \"{$requirementTitle}\".{$templateMsg}",
            'info'
        );
    }

    logAudit($pdo, 'send_template_notification', "Notified teacher ID $teacherId about requirement ID $phRequirementId" . ($hasTemplate ? ' (with template)' : ''), 'teacher_ph_requirements', 0);
    return true;
}

function getPendingSubmissionCount(PDO $pdo): int
{
    $count = 0;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM documents d
        WHERE d.status = 'pending_admin'
          AND d.archived = 0
          AND d.version = (
              SELECT MAX(d2.version)
              FROM documents d2
              WHERE IF(d.teacher_requirement_id IS NOT NULL,
                  d2.teacher_requirement_id = d.teacher_requirement_id,
                  IF(d.document_type = 'Direct Upload',
                      d2.id = d.id,
                      d2.department_id = d.department_id
                      AND d2.category = d.category
                      AND d2.document_type = d.document_type
                      AND d2.teacher_requirement_id IS NULL))
          )
          AND d.file_path IS NOT NULL AND d.file_path != ''
    ");
    $stmt->execute();
    $count += (int)$stmt->fetchColumn();

    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) FROM ph_documents pd
        WHERE pd.status = 'pending_admin'
          AND pd.archived = 0
          AND pd.version = (
              SELECT MAX(pd2.version)
              FROM ph_documents pd2
              WHERE IF(pd.teacher_ph_requirement_id IS NOT NULL,
                  pd2.teacher_ph_requirement_id = pd.teacher_ph_requirement_id,
                  pd2.id = pd.id)
          )
          AND pd.file_path IS NOT NULL AND pd.file_path != ''
    ");
    $stmt2->execute();
    $count += (int)$stmt2->fetchColumn();

    return $count;
}

function getPendingPHSubmissions(PDO $pdo, int $phId): array
{
    $sql = "
        SELECT pd.id, pd.teacher_ph_requirement_id, pd.department_id, pd.title,
               pd.filename, pd.file_path, pd.uploaded_by, pd.status, pd.version,
               pd.upload_date, pd.description AS teacher_comment, pd.program_head_comment,
               pd.reviewed_by_program_head, pd.reviewed_at_program_head,
               u.name AS teacher_name,
               u.email AS teacher_email,
               u.role AS teacher_role,
               phr.title AS requirement_title,
               'program_head_requirement' AS requirement_type
        FROM ph_documents pd
        JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
        JOIN users u ON tpr.teacher_id = u.id
        JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
        WHERE phr.created_by = ? AND pd.status = 'pending_program_head'
          AND pd.version = (
              SELECT MAX(pd2.version)
              FROM ph_documents pd2
              WHERE pd2.teacher_ph_requirement_id = pd.teacher_ph_requirement_id
          )
        ORDER BY pd.upload_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$phId]);
    $results = $stmt->fetchAll();

    return array_values(array_filter($results, function ($doc) {
        $filePath = $doc['file_path'] ?? null;
        if (empty($filePath)) {
            return false;
        }
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));
}

function getAllPendingForProgramHead(PDO $pdo, int $deptId, int $phId): array
{
    $headShort = phRoleShort();

    $deptSubs = getPendingForProgramHead($pdo, $deptId);
    foreach ($deptSubs as &$sub) {
        $sub['submission_type'] = 'department';
        $reqType = $sub['requirement_type'] ?? 'admin_requirement';
        if ($reqType === 'admin_requirement') {
            $sub['source_label'] = 'Admin Requirement';
            $sub['source_badge_class'] = 'badge-warning';
        } else {
            $sub['source_label'] = "$headShort Requirement";
            $sub['source_badge_class'] = 'badge-info';
        }
    }
    unset($sub);

    $phSubs = getPendingPHSubmissions($pdo, $phId);
    foreach ($phSubs as &$sub) {
        $sub['submission_type'] = 'ph';
        $sub['category'] = $headShort;
        $sub['document_type'] = $sub['requirement_title'];
        $sub['source_label'] = "$headShort Requirement";
        $sub['source_badge_class'] = 'badge-info';
    }
    unset($sub);

    $allSubs = array_merge($deptSubs, $phSubs);
    usort($allSubs, function ($a, $b) {
        return strtotime($b['upload_date']) - strtotime($a['upload_date']);
    });

    $allSubs = array_values(array_filter($allSubs, function ($sub) {
        $filePath = $sub['file_path'] ?? null;
        if (empty($filePath)) {
            return false;
        }
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));

    return $allSubs;
}

function programHeadReviewPH(PDO $pdo, int $submissionId, int $programHeadId, string $action, ?string $comment = '', ?int $departmentId = null): bool
{
    if ($departmentId !== null) {
        $verifyStmt = $pdo->prepare("
            SELECT pd.id FROM ph_documents pd
            JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            WHERE pd.id = ? AND phr.created_by = ?
        ");
        $verifyStmt->execute([$submissionId, $programHeadId]);
        if (!$verifyStmt->fetch()) {
            logAudit($pdo, 'program_head_ph_review_denied', "Access denied: PH Document $submissionId was not created by PH $programHeadId", 'ph_document', $submissionId);
            return false;
        }
    }

    if ($action === 'modify') {
        $stmt = $pdo->prepare("UPDATE ph_documents SET program_head_comment = ? WHERE id = ?");
        $res = $stmt->execute([$comment, $submissionId]);
        if ($res) {
            logAudit($pdo, 'ph_modify', "Updated comment for PH submission $submissionId", 'ph_document', $submissionId);
            if (trim((string)$comment) !== '') {
                $tStmt = $pdo->prepare("SELECT tpr.teacher_id FROM ph_documents pd JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id WHERE pd.id = ?");
                $tStmt->execute([$submissionId]);
                $tid = $tStmt->fetchColumn();
                if ($tid) {
                    createNotification($pdo, (int)$tid, currentRoleName() . ' Left a Comment', 'Your ' . currentRoleName() . ' left a comment: ' . mb_substr((string)$comment, 0, 200), 'info', $submissionId, 'ph_document');
                }
            }
        }
        return $res;
    }

    $stateStmt = $pdo->prepare("SELECT status FROM ph_documents WHERE id = ?");
    $stateStmt->execute([$submissionId]);
    if ($stateStmt->fetchColumn() !== 'pending_program_head') {
        logAudit($pdo, 'program_head_review_denied', "Access denied: PH document $submissionId is not pending " . phRoleShort() . " review", 'ph_document', $submissionId);
        return false;
    }

    $status = ($action === 'approve') ? 'approved_program_head' : 'rejected_program_head';
    $success = transitionDocumentStatus($pdo, 'ph_documents', $submissionId, $status, [
        'reviewed_by' => $programHeadId,
        'comment'     => $comment,
    ]);

    if ($success) {
        if ($action === 'approve') {
            if (moveDocumentFile($pdo, $submissionId, 'ph_documents', 'approved_program_head') === false) {
                transitionDocumentStatus($pdo, 'ph_documents', $submissionId, 'pending_program_head');
                return false;
            }
        }

        logAudit($pdo, 'ph_' . $action, "PH submission $submissionId {$action}d - Status: $status (PH-created requirement, stays in Repository)", 'ph_document', $submissionId);

        $subStmt = $pdo->prepare("
            SELECT pd.*, u.name AS teacher_name, u.email AS teacher_email, tpr.id AS tpr_id, tpr.teacher_id, COALESCE(phr.title, pd.title) AS title
            FROM ph_documents pd
            LEFT JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            LEFT JOIN users u ON tpr.teacher_id = u.id
            LEFT JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            WHERE pd.id = ?
        ");
        $subStmt->execute([$submissionId]);
        $sub = $subStmt->fetch();

        if ($sub) {
            if ($action === 'approve') {
                $dupCheck = $pdo->prepare("SELECT id FROM ph_repository WHERE ph_document_id = ? LIMIT 1");
                $dupCheck->execute([$submissionId]);
                if (!$dupCheck->fetch()) {
                    $repoRel = copyStoredFile(
                        (string)$sub['file_path'],
                        dirname((string)$sub['file_path']),
                        (string)$sub['title'],
                        strtolower(pathinfo((string)$sub['file_path'], PATHINFO_EXTENSION))
                    );
                    if ($repoRel !== null) {
                        $repoStmt = $pdo->prepare("INSERT INTO ph_repository (department_id, program_head_id, ph_document_id, title, filename, file_path, description, forwarded_to_admin, forwarded_at, created_at, file_hash) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, NOW(), ?)");
                        $repoStmt->execute([$sub['department_id'], $programHeadId, $submissionId, $sub['title'], $sub['filename'], $repoRel, $sub['description'], $sub['file_hash'] ?? null]);
                    } else {
                        error_log('[CHED] repository copy failed for ph_document ' . $submissionId . ', no repository entry created');
                    }
                }

                createNotification(
                    $pdo,
                    $sub['teacher_id'],
                    currentRoleName() . ' Requirement Approved',
                    "Your submission for '{$sub['title']}' has been approved by the " . currentRoleName() . " and stored in the department repository.",
                    'success',
                    $submissionId,
                    'ph_document'
                );
            } else {
                createNotification(
                    $pdo,
                    $sub['teacher_id'],
                    currentRoleName() . ' Requirement Returned',
                    "Your submission for '{$sub['title']}' needs revision. Comment: " . ($comment ?: 'None'),
                    'danger',
                    $submissionId,
                    'ph_document'
                );
            }
        }
    }
    return $success;
}

// ===========================================================
// PH Direct Upload Function
// ===========================================================
function uploadPHDocumentDirect(PDO $pdo, int $phId, ?int $deptId, array $file, string $title, bool $autoForward = false, ?string $description = ''): int|string
{
    if (!isset($file['name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }

    $dupe = $pdo->prepare("
        SELECT id FROM documents
        WHERE title = ? AND archived = 0
          AND (department_id <=> ?)
        LIMIT 1
    ");
    $dupe->execute([$title, $deptId !== null ? (int)$deptId : null]);
    if ($dupe->fetch()) {
        return 'A document titled "' . $title . '" already exists. Enter a different title.';
    }

    $subdir = getFileStorageDir('pending_program_head');
    $uploadDir = UPLOAD_PATH . $subdir . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return 'Invalid file type. Allowed: PDF, DOC, DOCX.';
    }
    if ($file['size'] > maxFileSizeBytesFor($ext)) {
        return 'File too large (max ' . maxFileSizeLabelFor($ext) . ').';
    }
    // C-1 fail closed: a false $mime would slip past the allow-list via the
    // extension fallback map (isDangerousMime(false) === false).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) finfo_close($finfo);
    if ($mime === false) {
        return "Could not read the uploaded file to verify its type. Please try again.";
    }
    if (!in_array($mime, ALLOWED_MIMES)) {
        if (isDangerousMime($mime)) {
            return "File content does not match its extension.";
        }
        $mimeByExt = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if (!isset($mimeByExt[$ext])) {
            return "Invalid file type. Allowed: " . ALLOWED_EXTENSIONS_LABEL . ".";
        }
    }

    $hash = hashUploadedTempFile($file);
    if ($hash === null) {
        return 'Could not read the uploaded file to verify its contents. Please try again.';
    }
    $scopeKey = 'updoc:doc:direct:' . ($deptId !== null ? (int)$deptId : 'null') . ':' . md5($title);
    $locked = acquireUploadLock($pdo, $scopeKey);
    if ($locked) {
        register_shutdown_function(function () use ($pdo, $scopeKey) { releaseUploadLock($pdo, $scopeKey); });
    }
    if ($hash !== null) {
        $dupId = findDuplicateDirectUpload($pdo, $deptId !== null ? (int)$deptId : null, $title, $hash);
        if ($dupId) {
            logAudit($pdo, 'upload_duplicate_blocked', "Blocked duplicate direct upload '$title' (matches doc #$dupId)", 'document', $dupId);
            return 'This exact file has already been uploaded for this requirement. Edit the document and upload it again.';
        }
    }

    $filename = allocateStorageFilename($uploadDir, $title, $ext);
    $filePath = $subdir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        @unlink($uploadDir . $filename);
        return 'Failed to save uploaded file.';
    }

    $initialStatus = $autoForward ? 'pending_admin' : 'pending_program_head';
    $docStmt = $pdo->prepare("
        INSERT INTO documents (
            department_id, category, document_type, title, filename, file_path,
            uploaded_by, teacher_requirement_id, status, version, upload_date, description,
            source_type, origin_source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NOW(), ?, 'quick_direct', 'quick_direct')
    ");

    $category = 'PH';
    $docType = 'Direct Upload';
    if (!$deptId || $deptId <= 0) {
        $stmtU = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
        $stmtU->execute([$phId]);
        $deptId = (int) $stmtU->fetchColumn() ?: null;
    }
    $docDept = ($deptId && $deptId > 0) ? (int) $deptId : null;
    $docSuccess = $docStmt->execute([
        $docDept,
        $category,
        $docType,
        $title,
        $origName,
        $filePath,
        $phId,
        $initialStatus,
        1,
        $description
    ]);

    if (!$docSuccess) {
        unlink($uploadDir . $filename);
        return 'Failed to save to database.';
    }

    if ($docSuccess) {
        $docId = (int) $pdo->lastInsertId();
        finalizeStorageFilename($pdo, $docId, 'documents', $title, $ext);
        ensureDocumentHash($pdo, 'documents', $docId);
        if ($locked) releaseUploadLock($pdo, $scopeKey);

        if ($autoForward) {
            $notificationMsg = currentRoleName() . " uploaded and forwarded a document for Admin review: \"$title\"";
            $logAction = 'head_direct_upload_forwarded';
        } else {
            $notificationMsg = currentRoleName() . " uploaded a document for Admin review: \"$title\"";
            $logAction = 'head_direct_upload';
        }

        logAudit($pdo, $logAction, currentRoleName() . " direct upload: $title (Doc ID: $docId)", 'document', $docId);

        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($admins as $admin) {
            createNotification(
                $pdo,
                $admin['id'],
                'Document Uploaded by ' . currentRoleName(),
                $notificationMsg,
                'info',
                $docId,
                'document'
            );
        }
    }

    return $docId;
}

// ===========================================================
function getPendingForProgramHead(PDO $pdo, int $deptId, bool $includeAdminRejected = false): array
{
    $statusFilter = $includeAdminRejected ? "IN ('pending_program_head', 'rejected_admin')" : "= 'pending_program_head'";

    static $hasReqTypeColCache = null;
    if ($hasReqTypeColCache === null) {
        $colCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'teacher_requirements' AND column_name = 'requirement_type'");
        $hasReqTypeColCache = (bool)$colCheck->fetchColumn();
    }
    $hasReqTypeCol = $hasReqTypeColCache;

    $reqTypeSelect = $hasReqTypeCol ? "COALESCE(tr.requirement_type, 'admin_requirement') AS requirement_type" : "'admin_requirement' AS requirement_type";

    $sql = "
        SELECT d.*,
               u.name AS teacher_name,
               u.email AS teacher_email,
               u.role AS teacher_role,
               d.category,
               d.document_type,
               d.teacher_requirement_id,
               d.description AS teacher_comment,
               {$reqTypeSelect}
        FROM documents d
        LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        LEFT JOIN users u ON tr.teacher_id = u.id
        WHERE d.department_id = ? AND d.status $statusFilter
          AND d.version = (
              SELECT MAX(d2.version) 
              FROM documents d2 
              WHERE d2.teacher_requirement_id = d.teacher_requirement_id
          )
        ORDER BY d.upload_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deptId]);
    $results = $stmt->fetchAll();

    $results = array_values(array_filter($results, function ($doc) {
        $filePath = $doc['file_path'] ?? null;
        if (empty($filePath)) {
            return false;
        }
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));

    return $results;
}

function getTeachersInDepartment(PDO $pdo, int $deptId): array
{
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE role IN ('teacher','staff') AND department_id = ? ORDER BY name");
    $stmt->execute([$deptId]);
    return $stmt->fetchAll();
}

function assignRequirementToTeacher(PDO $pdo, int $teacherId, int $requirementId, int $assignedBy, ?string $deadline = null, string $requirementType = 'admin_requirement', bool $deferNotifications = false, ?bool $hasReqTypeCol = null): array
{
    try {
        static $cachedHasCol = null;
        if ($hasReqTypeCol === null) {
            if ($cachedHasCol === null) {
                $colCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'teacher_requirements' AND column_name = 'requirement_type'");
                $cachedHasCol = (bool)$colCheck->fetchColumn();
            }
            $hasReqTypeCol = $cachedHasCol;
        }

        $semester = 'First Semester';

        if ($hasReqTypeCol) {
            $checkStmt = $pdo->prepare("SELECT id FROM teacher_requirements WHERE teacher_id = ? AND requirement_id = ? LIMIT 1");
            $checkStmt->execute([$teacherId, $requirementId]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                return ['success' => true, 'inserted' => false, 'id' => (int)$existingId, 'message' => 'Requirement already assigned to this faculty member.'];
            }

            $stmt = $pdo->prepare("INSERT INTO teacher_requirements (teacher_id, requirement_id, assigned_by, deadline, requirement_type, semester) VALUES (?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([$teacherId, $requirementId, $assignedBy, $deadline, $requirementType, $semester]);
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM teacher_requirements WHERE teacher_id = ? AND requirement_id = ? LIMIT 1");
            $checkStmt->execute([$teacherId, $requirementId]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                return ['success' => true, 'inserted' => false, 'id' => (int)$existingId, 'message' => 'Requirement already assigned to this faculty member.'];
            }

            $stmt = $pdo->prepare("INSERT INTO teacher_requirements (teacher_id, requirement_id, assigned_by, deadline, semester) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([$teacherId, $requirementId, $assignedBy, $deadline, $semester]);
        }
        $insertedId = (int)$pdo->lastInsertId();

        if ($result && $insertedId) {
            static $cachedReqs = [];
            if (!isset($cachedReqs[$requirementId])) {
                $req = $pdo->prepare("SELECT category, document_type FROM department_requirements WHERE id = ?");
                $req->execute([$requirementId]);
                $cachedReqs[$requirementId] = $req->fetch() ?: [];
            }
            $reqData = $cachedReqs[$requirementId];

            $sourceLabel = $requirementType === 'admin_requirement' ? 'Admin' : 'PH';

            if (!$deferNotifications) {
                createNotification(
                    $pdo,
                    $teacherId,
                    'New Requirement Assigned',
                    "You have been assigned: {$reqData['category']} - {$reqData['document_type']} (From $sourceLabel)",
                    'info'
                );
                if ($requirementType === 'admin_requirement') {
                    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
                    foreach ($admins as $admin) {
                        createNotification(
                            $pdo,
                            $admin['id'],
                            'Admin Requirement Assigned to Faculty',
                            "An admin requirement ({$reqData['category']} - {$reqData['document_type']}) was assigned to a faculty member.",
                            'info'
                        );
                    }
                }
            }
            logAudit($pdo, 'assign_requirement', "Assigned {$reqData['category']} - {$reqData['document_type']} to teacher ID $teacherId (Type: $requirementType, Semester: $semester)", 'teacher_requirement', (int) $insertedId);
        }

        return ['success' => $result, 'inserted' => $insertedId > 0, 'id' => $insertedId > 0 ? $insertedId : (isset($existingId) ? (int)$existingId : 0)];
    } catch (PDOException $e) {
        logAudit($pdo, 'assign_error', "Failed to assign requirement: " . $e->getMessage(), 'teacher_requirements', 0);
        return ['success' => false, 'inserted' => false, 'error' => $e->getMessage()];
    }
}

// ===========================================================
// ARCHIVING
// ===========================================================

function syncExpirationStatuses(PDO $pdo, ?int $triggeredByUserId = null): array
{
    $counts = [
        'requirements_expired' => 0,
        'requirements_expiring' => 0,
        'requirements_active' => 0,
        'documents' => 0,
        'ph_documents' => 0,
    ];
    global $EXPIRY_WARNING_DAYS;
    $days = $EXPIRY_WARNING_DAYS;

    static $hasPhExpiry = null;
    if ($hasPhExpiry === null) {
        try {
            $hasPhExpiry = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ph_documents' AND column_name = 'expiry_date'")->fetchColumn();
        } catch (Exception $e) {
            $hasPhExpiry = false;
        }
    }

    try {
        $pdo->exec("
            UPDATE documents d
            JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
            JOIN department_requirements dr ON tr.requirement_id = dr.id
            SET d.expiry_date = dr.expiration_date
            WHERE NOT (d.expiry_date <=> dr.expiration_date)
        ");

        $pdo->exec("
            UPDATE documents d
            JOIN department_requirements dr ON d.department_id = dr.department_id
                AND d.category = dr.category
                AND d.document_type = dr.document_type
            SET d.expiry_date = dr.expiration_date
            WHERE d.teacher_requirement_id IS NULL
            AND NOT (d.expiry_date <=> dr.expiration_date)
        ");

        if ($hasPhExpiry) {
            $pdo->exec("
                UPDATE ph_documents pd
                JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
                JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
                SET pd.expiry_date = phr.deadline
                WHERE NOT (pd.expiry_date <=> phr.deadline)
            ");
        }

        $docTables = ['documents'];
        if ($hasPhExpiry) {
            $docTables[] = 'ph_documents';
        }
        foreach ($docTables as $table) {
            $key = ($table === 'documents') ? 'documents' : 'ph_documents';
            $counts[$key] += (int)$pdo->exec("
                UPDATE `$table`
                SET validity_status = 'expired'
                WHERE archived = 0
                AND expiry_date IS NOT NULL
                AND expiry_date <= CURDATE()
                AND (validity_status IS NULL OR validity_status != 'expired')
            ");
            $counts[$key] += (int)$pdo->exec("
                UPDATE `$table`
                SET validity_status = 'expiring'
                WHERE archived = 0
                AND expiry_date IS NOT NULL
                AND expiry_date > CURDATE()
                AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY)
                AND (validity_status IS NULL OR validity_status != 'expiring')
            ");
            $counts[$key] += (int)$pdo->exec("
                UPDATE `$table`
                SET validity_status = 'active'
                WHERE archived = 0
                AND (expiry_date IS NULL OR expiry_date > DATE_ADD(CURDATE(), INTERVAL $days DAY))
                AND (validity_status IS NULL OR validity_status != 'active')
            ");
        }

        $roleOk = true;
        if ($triggeredByUserId !== null) {
            $triggerUser = getUserById($pdo, $triggeredByUserId);
            $roleOk = ($triggerUser && ($triggerUser['role'] ?? null) === 'admin');
        }

        $stmt = $pdo->query("
            SELECT dr.id, dr.category, dr.document_type, d.department_name, dr.department_id
            FROM department_requirements dr
            JOIN departments d ON dr.department_id = d.id
            WHERE dr.expiration_date IS NOT NULL
              AND dr.expiration_date <= CURDATE()
              AND dr.status != 'expired'
        ");
        $newlyExpired = $stmt->fetchAll();

        $counts['requirements_expired'] = (int)$pdo->exec("UPDATE department_requirements SET status = 'expired' WHERE expiration_date IS NOT NULL AND expiration_date <= CURDATE() AND status != 'expired'");
        $counts['requirements_expiring'] = (int)$pdo->exec("UPDATE department_requirements SET status = 'expiring' WHERE expiration_date IS NOT NULL AND expiration_date > CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY) AND status != 'expiring'");
        $counts['requirements_active'] = (int)$pdo->exec("UPDATE department_requirements SET status = 'active' WHERE (expiration_date IS NULL OR expiration_date > DATE_ADD(CURDATE(), INTERVAL $days DAY)) AND status != 'active'");

        if ($roleOk && !empty($newlyExpired)) {
            $adminUsers = $pdo->query("SELECT id, email FROM users WHERE role = 'admin'")->fetchAll();
            foreach ($newlyExpired as $req) {
                foreach ($adminUsers as $admin) {
                    createNotification(
                        $pdo,
                        $admin['id'],
                        'Requirement Expired',
                        "The requirement '{$req['category']} - {$req['document_type']}' for {$req['department_name']} has expired and needs to be updated.",
                        'danger'
                    );
                }
                $phStmt = $pdo->prepare("
                    SELECT DISTINCT u.id, u.role FROM teacher_requirements tr
                    JOIN users u ON u.id = tr.assigned_by AND u.role IN ('department','unit_head')
                    WHERE tr.requirement_id = ?
                ");
                $phStmt->execute([$req['id']]);
                $assigners = $phStmt->fetchAll();
                foreach ($assigners as $ph) {
                    createNotification(
                        $pdo,
                        (int)$ph['id'],
                        'Requirement Expired',
                        "The requirement you assigned '{$req['category']} - {$req['document_type']}' ({$req['department_name']}) has expired and needs to be updated.",
                        'danger'
                    );
                }
                $headTitle = phRoleName($assigners[0]['role'] ?? 'department');
                $tStmt = $pdo->prepare("SELECT DISTINCT teacher_id FROM teacher_requirements WHERE requirement_id = ?");
                $tStmt->execute([$req['id']]);
                foreach ($tStmt->fetchAll(PDO::FETCH_COLUMN) as $tid) {
                    createNotification(
                        $pdo,
                        (int)$tid,
                        'Requirement Expired',
                        "The requirement assigned to you '{$req['category']} - {$req['document_type']}' has expired. Contact your {$headTitle} for the updated version.",
                        'danger'
                    );
                }
            }
        }
    } catch (Exception $e) {
        error_log('[CHED Compliance] syncExpirationStatuses failed: ' . $e->getMessage());
    }

    return $counts;
}

function refreshRequirementExpiry(PDO $pdo, int $requirementId): void
{
    global $EXPIRY_WARNING_DAYS;
    try {
        $stmt = $pdo->prepare("SELECT expiration_date, department_id, category, document_type FROM department_requirements WHERE id = ?");
        $stmt->execute([$requirementId]);
        $req = $stmt->fetch();
        if (!$req) {
            return;
        }
        $expiry = $req['expiration_date'] ?? null;
        $status = classifyExpiryStatus($expiry);
        $pdo->prepare("UPDATE department_requirements SET status = ? WHERE id = ?")->execute([$status, $requirementId]);

        $pdo->prepare("
            UPDATE documents d
            JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
            SET d.expiry_date = ?,
                d.validity_status = CASE
                    WHEN ? IS NULL THEN 'active'
                    WHEN ? <= CURDATE() THEN 'expired'
                    WHEN ? <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'expiring'
                    ELSE 'active' END
            WHERE tr.requirement_id = ?
        ")->execute([$expiry, $expiry, $expiry, $expiry, $EXPIRY_WARNING_DAYS, $requirementId]);

        $pdo->prepare("
            UPDATE documents d
            JOIN department_requirements dr ON d.department_id = dr.department_id
                AND d.category = dr.category
                AND d.document_type = dr.document_type
            SET d.expiry_date = dr.expiration_date,
                d.validity_status = CASE
                    WHEN dr.expiration_date IS NULL THEN 'active'
                    WHEN dr.expiration_date <= CURDATE() THEN 'expired'
                    WHEN dr.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'expiring'
                    ELSE 'active' END
            WHERE d.teacher_requirement_id IS NULL AND dr.id = ?
        ")->execute([$EXPIRY_WARNING_DAYS, $requirementId]);
    } catch (Exception $e) {
        error_log('[CHED Compliance] refreshRequirementExpiry failed: ' . $e->getMessage());
    }
}

function refreshPhRequirementExpiry(PDO $pdo, int $phRequirementId): void
{
    global $EXPIRY_WARNING_DAYS;
    try {
        $stmt = $pdo->prepare("SELECT deadline FROM ph_requirements WHERE id = ?");
        $stmt->execute([$phRequirementId]);
        $deadline = $stmt->fetchColumn();
        if ($deadline === false) {
            return;
        }
        $colCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ph_documents' AND column_name = 'expiry_date'");
        if (!$colCheck->fetchColumn()) {
            return;
        }
        $pdo->prepare("
            UPDATE ph_documents pd
            JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            SET pd.expiry_date = ?,
                pd.validity_status = CASE
                    WHEN ? IS NULL THEN 'active'
                    WHEN ? <= CURDATE() THEN 'expired'
                    WHEN ? <= DATE_ADD(CURDATE(), INTERVAL ? DAY) THEN 'expiring'
                    ELSE 'active' END
            WHERE tpr.ph_requirement_id = ?
        ")->execute([$deadline, $deadline, $deadline, $deadline, $EXPIRY_WARNING_DAYS, $phRequirementId]);
    } catch (Exception $e) {
        error_log('[CHED Compliance] refreshPhRequirementExpiry failed: ' . $e->getMessage());
    }
}

function autoArchiveExpiredDocuments(PDO $pdo): void
{
    syncExpirationStatuses($pdo);
}

function autoExpireRequirements(PDO $pdo, ?int $triggeredByUserId = null): void
{
    syncExpirationStatuses($pdo, $triggeredByUserId);
}

function maybeAutoSyncExpiration(PDO $pdo, int $intervalSeconds = 3600): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    try {
        $guard = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ched_expiry_sync.lock';
        if (file_exists($guard) && (time() - (int)@filemtime($guard)) < $intervalSeconds) {
            return;
        }
        @touch($guard);
        syncExpirationStatuses($pdo);
    } catch (Throwable $e) {
        error_log('[CHED Compliance] maybeAutoSyncExpiration failed: ' . $e->getMessage());
    }
}

function getExpiredRequirements(PDO $pdo, ?int $adminId = null): array
{
    if ($adminId) {
        $stmt = $pdo->prepare("
            SELECT dr.*, d.department_name, d.department_code
            FROM department_requirements dr
            JOIN departments d ON dr.department_id = d.id
            LEFT JOIN admin_dashboard_dismissals dism ON dism.requirement_id = dr.id AND dism.admin_id = ?
            WHERE dr.status = 'expired' AND dism.id IS NULL
            ORDER BY dr.expiration_date ASC
        ");
        $stmt->execute([$adminId]);
    } else {
        $stmt = $pdo->query("SELECT dr.*, d.department_name, d.department_code FROM department_requirements dr JOIN departments d ON dr.department_id = d.id WHERE dr.status = 'expired' ORDER BY dr.expiration_date ASC");
    }
    return $stmt->fetchAll();
}

// ===========================================================
// ADMIN FUNCTIONS
// ===========================================================
function getPendingForAdmin(PDO $pdo): array
{
    $sql = "
        SELECT d.*,
               dept.department_name,
               d.category,
               d.document_type,
               ph.name AS program_head_name,
               uploader.name as uploader_name,
               'document' AS doc_source
        FROM documents d
        JOIN departments dept ON d.department_id = dept.id
        LEFT JOIN users ph ON d.reviewed_by_program_head = ph.id
        LEFT JOIN users uploader ON d.uploaded_by = uploader.id
        WHERE d.status IN ('pending_admin', 'pending_program_head')
          AND d.version = (
              SELECT MAX(d2.version) 
              FROM documents d2 
              WHERE d2.teacher_requirement_id = d.teacher_requirement_id
                OR (d2.department_id = d.department_id AND d2.category = d.category AND d2.document_type = d.document_type AND d2.teacher_requirement_id IS NULL AND d.teacher_requirement_id IS NULL)
          )
        ORDER BY d.upload_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $docs = $stmt->fetchAll();

    $docs = array_values(array_filter($docs, function ($doc) {
        $filePath = $doc['file_path'] ?? null;
        if (empty($filePath)) {
            return false;
        }
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));

    $phSql = "
        SELECT pd.*,
               dept.department_name,
               'PH' AS category,
               phr.title AS document_type,
               ph.name AS program_head_name,
               u.name as uploader_name,
               'ph_document' AS doc_source
        FROM ph_documents pd
        JOIN departments dept ON pd.department_id = dept.id
        JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
        JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
        LEFT JOIN users ph ON pd.reviewed_by_program_head = ph.id
        LEFT JOIN users u ON pd.uploaded_by = u.id
        WHERE pd.status IN ('pending_admin', 'pending_program_head')
          AND pd.version = (
              SELECT MAX(pd2.version) 
              FROM ph_documents pd2 
              WHERE pd2.teacher_ph_requirement_id = pd.teacher_ph_requirement_id
          )
        ORDER BY pd.upload_date DESC
    ";
    $phStmt = $pdo->prepare($phSql);
    $phStmt->execute();
    $phDocs = $phStmt->fetchAll();

    $phDocs = array_values(array_filter($phDocs, function ($doc) {
        $filePath = $doc['file_path'] ?? null;
        if (empty($filePath)) {
            return false;
        }
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    }));

    $combined = array_merge($docs, $phDocs);
    usort($combined, function ($a, $b) {
        return strtotime($b['upload_date']) - strtotime($a['upload_date']);
    });

    return $combined;
}

function transitionDocumentStatus(PDO $pdo, string $table, int $docId, string $newStatus, array $opts = []): bool
{
    $allowed = ['documents', 'ph_documents'];
    if (!in_array($table, $allowed, true)) {
        error_log("[transitionDocumentStatus] Invalid table: $table");
        return false;
    }

    $sets   = ['status = ?'];
    $params = [$newStatus];

    $activeStatuses = ['pending_program_head', 'pending_admin', 'approved_program_head', 'approved_admin'];
    if (in_array($newStatus, $activeStatuses, true)) {
        $sets[] = 'archived = 0';
    }

    $reviewer = $opts['reviewed_by'] ?? null;
    $comment  = $opts['comment'] ?? null;

    if ($reviewer !== null) {
        $isReject = str_starts_with($newStatus, 'rejected_');
        $isAdmin  = in_array($newStatus, ['approved_admin', 'rejected_admin'], true);

        if ($isAdmin) {
            $sets[] = 'reviewed_by_admin = ?';
            $params[] = $reviewer;
            $sets[] = 'reviewed_at_admin = NOW()';
            if ($comment !== null) {
                $sets[] = 'admin_comment = ?';
                $params[] = $comment;
            }
        } else {
            $sets[] = 'reviewed_by_program_head = ?';
            $params[] = $reviewer;
            $sets[] = 'reviewed_at_program_head = NOW()';
            if ($comment !== null) {
                $sets[] = 'program_head_comment = ?';
                $params[] = $comment;
            }
        }
    }

    foreach (($opts['extra'] ?? []) as $col => $val) {
        $sets[] = "$col = ?";
        $params[] = $val;
    }

    $params[] = $docId;
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function programHeadReview(PDO $pdo, int $submissionId, int $programHeadId, string $action, ?string $comment = '', ?int $departmentId = null): bool
{
    if ($departmentId !== null) {
        $verifyStmt = $pdo->prepare("
            SELECT d.id FROM documents d
            WHERE d.id = ? AND d.department_id = ?
        ");
        $verifyStmt->execute([$submissionId, $departmentId]);
        if (!$verifyStmt->fetch()) {
            logAudit($pdo, 'program_head_review_denied', "Access denied: Document $submissionId does not belong to department $departmentId", 'document', $submissionId);
            return false;
        }
    }

    if ($action === 'modify') {
        $stmt = $pdo->prepare("UPDATE documents SET program_head_comment = ? WHERE id = ?");
        $res = $stmt->execute([$comment, $submissionId]);
        if ($res) {
            logAudit($pdo, 'program_head_modify', "Updated comment for submission $submissionId", 'document', $submissionId);
            if (trim((string)$comment) !== '') {
                $tStmt = $pdo->prepare("SELECT tr.teacher_id FROM documents d JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id WHERE d.id = ?");
                $tStmt->execute([$submissionId]);
                $tid = $tStmt->fetchColumn();
                if ($tid) {
                    createNotification($pdo, (int)$tid, currentRoleName() . ' Left a Comment', 'Your ' . currentRoleName() . ' left a comment: ' . mb_substr((string)$comment, 0, 200), 'info', $submissionId, 'document');
                }
            }
        }
        return $res;
    }

    $stateStmt = $pdo->prepare("SELECT status FROM documents WHERE id = ?");
    $stateStmt->execute([$submissionId]);
    if ($stateStmt->fetchColumn() !== 'pending_program_head') {
        logAudit($pdo, 'program_head_review_denied', "Access denied: document $submissionId is not pending program head review", 'document', $submissionId);
        return false;
    }

    $isTeacherSubmission = $pdo->prepare("SELECT teacher_requirement_id FROM documents WHERE id = ?");
    $isTeacherSubmission->execute([$submissionId]);
    $teacherReqId = $isTeacherSubmission->fetchColumn();

    if ($teacherReqId) {
        $status = ($action === 'approve') ? 'pending_admin' : 'rejected_program_head';
    } else {
        $status = ($action === 'approve') ? 'approved_admin' : 'rejected_admin';
    }
    $success = transitionDocumentStatus($pdo, 'documents', $submissionId, $status, [
        'reviewed_by' => $programHeadId,
        'comment'     => $comment,
    ]);

    if ($success) {
        if (moveDocumentFile($pdo, $submissionId, 'documents', $status) === false) {
            transitionDocumentStatus($pdo, 'documents', $submissionId, 'pending_program_head');
            return false;
        }

        logAudit($pdo, 'program_head_' . $action, "Submission $submissionId {$action}d - Status: $status" . ($comment ? ": $comment" : ''), 'document', $submissionId);

        $sub = getSubmissionDetails($pdo, $submissionId);
        if ($sub) {
            $ph = getUserById($pdo, $programHeadId);
            if ($action === 'approve') {
                if ($sub['teacher_id']) {
                    createNotification(
                        $pdo,
                        $sub['teacher_id'],
                        'Submission Approved by ' . currentRoleName(),
                        "Your submission for {$sub['category']} - {$sub['document_type']} has been approved and auto-sent to Admin for final review.",
                        'success',
                        $submissionId,
                        'document'
                    );
                }
            } else {
                if ($sub['teacher_id']) {
                    createNotification(
                        $pdo,
                        $sub['teacher_id'],
                        'Submission Returned by ' . currentRoleName(),
                        "Your submission for {$sub['category']} - {$sub['document_type']} needs revision. Comment: " . ($comment ?: 'None'),
                        'danger',
                        $submissionId,
                        'document'
                    );
                }
            }

            if ($action === 'approve' && $teacherReqId) {
                $adminUsers = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
                foreach ($adminUsers as $admin) {
                    createNotification(
                        $pdo,
                        $admin['id'],
                        'New Submission for Admin Review (Auto-Forwarded)',
                        "A teacher submission for {$sub['category']} - {$sub['document_type']} has been auto-forwarded by the " . currentRoleName() . " for your final review.",
                        'info',
                        $submissionId,
                        'document'
                    );
                }
            }
        }
    }
    return $success;
}

function adminReview(PDO $pdo, int $submissionId, int $adminId, string $action, ?string $comment = ''): bool
{
    $stmt = $pdo->prepare("SELECT status, department_id, teacher_requirement_id, category, document_type, uploaded_by, file_path FROM documents WHERE id = ?");
    $stmt->execute([$submissionId]);
    $docInfo = $stmt->fetch();
    $currentStatus = $docInfo['status'] ?? null;
    $deptId = $docInfo['department_id'] ?? null;
    $teacherReqId = $docInfo['teacher_requirement_id'] ?? null;

    if ($currentStatus === 'rejected_program_head') {
        return false;
    }

    if (!in_array($currentStatus, ['pending_admin'], true)) {
        logAudit($pdo, 'admin_review_denied', "Access denied: document $submissionId is not awaiting admin review (status: " . ($currentStatus ?? 'missing') . ")", 'document', $submissionId);
        return false;
    }

    if ($action === 'approve' && $currentStatus === 'pending_admin') {
        try {
            $hash = ensureDocumentHash($pdo, 'documents', $submissionId);
            if ($hash !== null) {
                $cycleStart = getRequirementCycleStart($pdo, [
                    'department_id'          => $deptId,
                    'category'               => $docInfo['category'] ?? null,
                    'document_type'          => $docInfo['document_type'] ?? null,
                    'teacher_requirement_id' => $teacherReqId,
                ]);
                $dupe = findDuplicateApprovedDocument(
                    $pdo,
                    $hash,
                    $deptId !== null ? (int)$deptId : null,
                    $docInfo['category'] ?? null,
                    $docInfo['document_type'] ?? null,
                    $cycleStart,
                    $submissionId
                );
                if ($dupe) {
                    logAudit(
                        $pdo,
                        'admin_review_duplicate',
                        "Duplicate approval blocked: doc #{$submissionId} has the same SHA-256 as already-approved doc #{$dupe['id']} in the same cycle.",
                        'document',
                        $submissionId
                    );
                    return false;
                }
            }
        } catch (Throwable $e) {
            error_log('[CHED] adminReview duplicate check error: ' . $e->getMessage());
        }
    }

    $repoCheck = $pdo->prepare("SELECT id, program_head_id, title FROM ph_repository WHERE document_id = ?");
    $repoCheck->execute([$submissionId]);
    $repoEntry = $repoCheck->fetch();
    $isDirectUpload = (bool)$repoEntry
        || (($docInfo['document_type'] ?? '') === 'Direct Upload' && empty($docInfo['teacher_requirement_id']));

    if ($action === 'reject') {
        $status = 'rejected_admin';
    } else {
        $status = 'approved_admin';
    }

    $success = transitionDocumentStatus($pdo, 'documents', $submissionId, $status, [
        'reviewed_by' => $adminId,
        'comment'     => $comment,
    ]);

    if ($success) {
        $fileSource = (bool)$repoEntry ? 'ph' : null;
        $movedRel = moveDocumentFile($pdo, $submissionId, 'documents', $status, $fileSource);
        if ($movedRel === false) {
            return false;
        }

        logAudit($pdo, 'admin_' . $action, "Submission $submissionId {$action}d - Status: $status" . ($comment ? ": $comment" : ''), 'document', $submissionId);

        if ($action === 'approve' && $repoEntry) {
            $pdo->prepare("UPDATE ph_repository SET forwarded_to_admin = 1, forwarded_at = NOW() WHERE id = ?")
                ->execute([$repoEntry['id']]);
            logAudit($pdo, 'ph_forwarded_to_admin', "PH document {$repoEntry['title']} forwarded to admin after approval", 'ph_repository', $repoEntry['id']);
        }

        if ($isDirectUpload && ($action === 'reject' || $action === 'approve')) {
            $phId = $repoEntry ? ($repoEntry['program_head_id'] ?? null) : ($docInfo['uploaded_by'] ?? null);
            $docTitle = $repoEntry ? ($repoEntry['title'] ?? null) : null;
            if (!$docTitle) {
                $t = $pdo->prepare("SELECT title FROM documents WHERE id = ?");
                $t->execute([$submissionId]);
                $docTitle = $t->fetchColumn() ?: 'Document';
            }

            if ($action === 'reject') {
                if ($repoEntry) {
                    $pdo->prepare("UPDATE ph_repository SET forwarded_to_admin = 0, forwarded_at = NULL WHERE id = ?")
                        ->execute([$repoEntry['id']]);
                }
                if ($phId) {
                    createNotification(
                        $pdo,
                        (int)$phId,
                        'Document Returned by Admin for Correction',
                        "Your uploaded document \"{$docTitle}\" was returned by Admin for correction. Please review the comments and upload a corrected version.",
                        'warning',
                        $submissionId,
                        'document'
                    );
                }
            } elseif ($phId) {
                createNotification(
                    $pdo,
                    (int)$phId,
                    'Document Approved by Admin',
                    "Your uploaded document \"{$docTitle}\" was APPROVED by the Administrator.",
                    'success',
                    $submissionId,
                    'document'
                );
            }
        }

        $sub = getSubmissionDetails($pdo, $submissionId);
        if ($sub) {
            if (!$isDirectUpload) {
                $notifType = $action === 'approve' ? 'success' : 'danger';

                $ph = getProgramHeadForDepartment($pdo, $sub['department_id']);
                if ($ph) {
                    $statusText = ($action === 'approve' ? 'APPROVED' : 'RETURNED BY ADMIN');
                    createNotification(
                        $pdo,
                        $ph['id'],
                        "Admin Review Complete: $statusText",
                        "Requirement ({$sub['category']} - {$sub['document_type']}) was {$statusText} by Admin.",
                        $notifType,
                        $submissionId,
                        'document'
                    );
                }
            }

            if ($sub['teacher_id']) {
                if ($action === 'approve') {
                    $notifTitle = 'Requirement Fully Approved';
                    $notifMsg = "Your submission for {$sub['category']} - {$sub['document_type']} has been fully approved by the Administrator.";
                    $notifType = 'success';
                } else {
                    $notifTitle = 'Requirement Returned by Admin';
                    $notifMsg = "Your submission for {$sub['category']} - {$sub['document_type']} was returned by Admin for revision. Comment: " . ($comment ?: 'None');
                    $notifType = 'danger';
                }
                createNotification($pdo, $sub['teacher_id'], $notifTitle, $notifMsg, $notifType, $submissionId, 'document');
            }
        }
    }

    return $success;
}

function adminReviewPH(PDO $pdo, int $submissionId, int $adminId, string $action, ?string $comment = ''): bool
{
    $stmt = $pdo->prepare("SELECT status, department_id, teacher_ph_requirement_id FROM ph_documents WHERE id = ?");
    $stmt->execute([$submissionId]);
    $docInfo = $stmt->fetch();
    $currentStatus = $docInfo['status'] ?? null;
    $deptId = $docInfo['department_id'] ?? null;
    $tprId = $docInfo['teacher_ph_requirement_id'] ?? null;

    if ($currentStatus !== 'pending_admin') {
        return false;
    }

    if ($action === 'approve') {
        try {
            $hash = ensureDocumentHash($pdo, 'ph_documents', $submissionId);
            if ($hash !== null && !empty($tprId)) {
                $dupe = $pdo->prepare("
                    SELECT id FROM ph_documents
                     WHERE id <> ?
                       AND archived = 0
                       AND status = 'approved_admin'
                       AND file_hash = ?
                       AND teacher_ph_requirement_id = ?
                     LIMIT 1
                ");
                $dupe->execute([$submissionId, $hash, (int)$tprId]);
                if ($dupe->fetch()) {
                    logAudit(
                        $pdo,
                        'admin_review_duplicate',
                        "Duplicate PH approval blocked: doc #{$submissionId} has the same SHA-256 as an already-approved doc in the same PH requirement.",
                        'ph_document',
                        $submissionId
                    );
                    return false;
                }
            }
        } catch (Throwable $e) {
            error_log('[CHED] adminReviewPH duplicate check error: ' . $e->getMessage());
        }
    }

    $status = ($action === 'reject') ? 'rejected_admin' : 'approved_admin';
    $success = transitionDocumentStatus($pdo, 'ph_documents', $submissionId, $status, [
        'reviewed_by' => $adminId,
        'comment'     => $comment,
    ]);

    if ($success) {
        $repoCheck = $pdo->prepare("SELECT id, program_head_id, title, file_path FROM ph_repository WHERE ph_document_id = ?");
        $repoCheck->execute([$submissionId]);
        $repoEntry = $repoCheck->fetch();

        $movedRel = moveDocumentFile($pdo, $submissionId, 'ph_documents', $status, 'ph');
        if ($movedRel === false) {
            return false;
        }

        logAudit($pdo, 'admin_ph_' . $action, "PH Document $submissionId {$action}d - Status: $status" . ($comment ? ": $comment" : ''), 'ph_document', $submissionId);

        if ($action === 'approve' && $repoEntry) {
            $pdo->prepare("UPDATE ph_repository SET forwarded_to_admin = 1, forwarded_at = NOW() WHERE id = ?")
                ->execute([$repoEntry['id']]);
        }

        if ($action === 'reject' && $repoEntry) {
            $pdo->prepare("UPDATE ph_repository SET forwarded_to_admin = 0, forwarded_at = NULL WHERE id = ?")
                ->execute([$repoEntry['id']]);
        }

        $subStmt = $pdo->prepare("
            SELECT pd.*, u.name AS teacher_name, u.email AS teacher_email,
                   tpr.teacher_id, phr.title, phr.department_id, phr.created_by
            FROM ph_documents pd
            JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            JOIN users u ON tpr.teacher_id = u.id
            JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            WHERE pd.id = ?
        ");
        $subStmt->execute([$submissionId]);
        $sub = $subStmt->fetch();

        if ($sub) {
            $phId = $sub['created_by'] ?? $repoEntry['program_head_id'] ?? null;
            if ($phId) {
                $statusText = ($action === 'approve' ? 'APPROVED' : 'RETURNED BY ADMIN');
                $notifMsg = $action === 'approve'
                    ? "Your PH Requirement '{$sub['title']}' was APPROVED by Admin."
                    : "Your PH Requirement '{$sub['title']}' was returned by Admin for correction. Please review the comment and resubmit: " . ($comment ?: 'No comment');
                createNotification(
                    $pdo,
                    $phId,
                    'Admin Review Complete: ' . $statusText,
                    $notifMsg,
                    $action === 'approve' ? 'success' : 'warning',
                    $submissionId,
                    'ph_document'
                );
            }

            if ($sub['teacher_id']) {
                $notifType = $action === 'approve' ? 'success' : 'danger';
                $notifTitle = $action === 'approve' ? 'PH Requirement Fully Approved' : 'PH Requirement Returned by Admin';
                $notifMsg = $action === 'approve'
                    ? "Your submission for '{$sub['title']}' has been approved by the Administrator."
                    : "Your submission for '{$sub['title']}' was returned by Admin for revision. Comment: " . ($comment ?: 'None');

                createNotification($pdo, $sub['teacher_id'], $notifTitle, $notifMsg, $notifType, $submissionId, 'ph_document');
            }
        }
    }
    return $success;
}

function deletePendingDocsForRequirement(PDO $pdo, int $reqId, int $deptId): void
{
    $stmt = $pdo->prepare("SELECT category, document_type FROM department_requirements WHERE id = ?");
    $stmt->execute([$reqId]);
    $req = $stmt->fetch();
    if (!$req) return;

    $st = $pdo->prepare("
        SELECT d.id, d.file_path FROM documents d
        LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        WHERE d.department_id = ?
          AND d.archived = 0
          AND d.status NOT IN ('approved_admin', 'approved_program_head', 'rejected_admin')
          AND NOT (d.status = 'pending_program_head' AND d.reviewed_at_admin IS NOT NULL)
          AND (tr.requirement_id = ? OR (d.teacher_requirement_id IS NULL AND d.category = ? AND d.document_type = ?))
    ");
    $st->execute([$deptId, $reqId, $req['category'], $req['document_type']]);
    foreach ($st->fetchAll() as $doc) {
        $pdo->prepare("DELETE FROM ph_repository WHERE document_id = ?")->execute([$doc['id']]);
        $pdo->prepare("UPDATE documents SET archived = 1 WHERE id = ?")->execute([$doc['id']]);
    }
}

// ===========================================================
// UPLOAD
// ===========================================================

function uploadTeacherSubmission(PDO $pdo, int $teacherRequirementId, int $teacherId, array $file, ?string $comment = ''): bool|string
{
    if (!isset($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return "Invalid file type. Allowed: " . ALLOWED_EXTENSIONS_LABEL . ".";
    }
    if ($file['size'] > maxFileSizeBytesFor($ext)) {
        return "File size must not exceed " . maxFileSizeLabelFor($ext) . ".";
    }
    // C-1 fail closed: a false $mime would slip past the allow-list via the
    // extension fallback map (isDangerousMime(false) === false).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) finfo_close($finfo);
    if ($mime === false) {
        return "Could not read the uploaded file to verify its type. Please try again.";
    }
    if (!in_array($mime, ALLOWED_MIMES)) {
        if (isDangerousMime($mime)) {
            return "File content does not match its extension.";
        }
        $mimeByExt = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if (!isset($mimeByExt[$ext])) {
            return "Invalid file type. Allowed: " . ALLOWED_EXTENSIONS_LABEL . ".";
        }
    }

    $stmt = $pdo->prepare("
        SELECT dr.department_id, dr.category, dr.document_type, dr.expiration_date, COALESCE(tr.requirement_type, 'admin_requirement') AS requirement_type
        FROM teacher_requirements tr
        JOIN department_requirements dr ON tr.requirement_id = dr.id
        WHERE tr.id = ?
    ");
    $stmt->execute([$teacherRequirementId]);
    $req = $stmt->fetch();

    if (!$req) {
        logAudit($pdo, 'upload_failed', "Teacher upload failed - requirement not found. ID: $teacherRequirementId", 'documents', 0);
        return "Requirement not found.";
    }

    logAudit($pdo, 'upload_debug', "UploadTeacherSubmission - req_id: $teacherRequirementId, type: " . ($req['requirement_type'] ?? 'unknown'), 'documents', 0);

    $sourceLabel = ($req['requirement_type'] ?? 'admin_requirement') === 'admin_requirement' ? 'Admin' : 'PH';
    $title = $req['category'] . ' - ' . $req['document_type'];

    $hash = hashUploadedTempFile($file);
    if ($hash === null) {
        return 'Could not read the uploaded file to verify its contents. Please try again.';
    }
    $scopeKey = 'updoc:doc:req:' . $teacherRequirementId;
    $locked = acquireUploadLock($pdo, $scopeKey);
    if ($locked) {
        register_shutdown_function(function () use ($pdo, $scopeKey) { releaseUploadLock($pdo, $scopeKey); });
    }
    if ($hash !== null) {
        $dupId = findDuplicateAssignmentDoc($pdo, 'documents', 'teacher_requirement_id', $teacherRequirementId, $hash);
        if ($dupId) {
            logAudit($pdo, 'upload_duplicate_blocked', "Blocked duplicate submission for teacher requirement $teacherRequirementId (matches doc #$dupId)", 'document', $dupId);
            return 'This exact file has already been submitted for this requirement. Edit the document and upload it again.';
        }
    }

    $stmt = $pdo->prepare("SELECT MAX(version) FROM documents WHERE teacher_requirement_id = ?");
    $stmt->execute([$teacherRequirementId]);
    $maxVersion = (int) $stmt->fetchColumn();
    $newVersion = $maxVersion + 1;

    $subdir = getFileStorageDir('pending_program_head');
    $dirAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
    $uniqueFile = allocateStorageFilename($dirAbs, $title, $ext);
    $filepath = $dirAbs . $uniqueFile;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        @unlink($filepath);
        return "Failed to save file. Check upload directory permissions.";
    }

    $stmt = $pdo->prepare("
        INSERT INTO documents (
            department_id, category, document_type, title, filename, file_path,
            uploaded_by, teacher_requirement_id, status, version, upload_date, description, expiry_date, validity_status,
            source_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_program_head', ?, NOW(), ?, ?, ?, 'personal_upload')
    ");
    $filePath = $subdir . '/' . $uniqueFile;
    $success = $stmt->execute([
        $req['department_id'],
        $req['category'],
        $req['document_type'],
        $title,
        $file['name'],
        $filePath,
        $teacherId,
        $teacherRequirementId,
        $newVersion,
        $comment ?? '',
        $req['expiration_date'],
        classifyExpiryStatus($req['expiration_date'] ?? null)
    ]);

    if ($success) {
        $docId = (int) $pdo->lastInsertId();
        finalizeStorageFilename($pdo, $docId, 'documents', $title, $ext);
        ensureDocumentHash($pdo, 'documents', $docId);
        if ($locked) releaseUploadLock($pdo, $scopeKey);
        logAudit($pdo, 'teacher_upload', "Teacher uploaded version $newVersion for requirement ID $teacherRequirementId (Type: {$req['requirement_type']}, DocID: $docId)", 'document', $docId);

        $programHead = getProgramHeadForDepartment($pdo, $req['department_id']);
        if ($programHead) {
            $teacher = getUserById($pdo, $teacherId);
            createNotification(
                $pdo,
                $programHead['id'],
                'New Document Submission',
                facultyRoleName($teacher['role'] ?? '') . " {$teacher['name']} submitted {$req['category']} - {$req['document_type']} (v$newVersion, From $sourceLabel). Awaiting your review.",
                'info',
                $docId,
                'document'
            );
        }
        return true;
    }
    if (file_exists($filepath)) unlink($filepath);
    return "Database error.";
}

function uploadDepartmentSubmission(PDO $pdo, int $deptRequirementId, int $userId, array $file, ?string $comment = '', ?int $expectedDeptId = null): bool|string
{
    if (!isset($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return "Only PDF, DOC, and DOCX files are allowed.";
    }
    if ($file['size'] > maxFileSizeBytesFor($ext)) {
        return "File size must not exceed " . maxFileSizeLabelFor($ext) . ".";
    }
    // C-1 fail closed: a false $mime would slip past the allow-list via the
    // extension fallback map (isDangerousMime(false) === false).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) finfo_close($finfo);
    if ($mime === false) {
        return "Could not read the uploaded file to verify its type. Please try again.";
    }
    if (!in_array($mime, ALLOWED_MIMES)) {
        return "Invalid file type. Only PDF, DOC, and DOCX are allowed.";
    }

    $stmt = $pdo->prepare("SELECT department_id, category, document_type, expiration_date, cycle_started_at FROM department_requirements WHERE id = ?");
    $stmt->execute([$deptRequirementId]);
    $req = $stmt->fetch();

    if (!$req) {
        return "Requirement not found.";
    }
    if ($expectedDeptId !== null && (int)$req['department_id'] !== $expectedDeptId) {
        return "Requirement not found.";
    }

    $hash = hashUploadedTempFile($file);
    if ($hash === null) {
        return 'Could not read the uploaded file to verify its contents. Please try again.';
    }
    $scopeKey = 'updoc:doc:dept:' . (int)$req['department_id'] . ':' . $req['category'] . ':' . $req['document_type'];
    $locked = acquireUploadLock($pdo, $scopeKey);
    if ($locked) {
        register_shutdown_function(function () use ($pdo, $scopeKey) { releaseUploadLock($pdo, $scopeKey); });
    }
    if ($hash !== null) {
        $dupId = findDuplicateDepartmentDoc(
            $pdo,
            (int)$req['department_id'],
            (string)$req['category'],
            (string)$req['document_type'],
            ($req['cycle_started_at'] === null || $req['cycle_started_at'] === '') ? null : (string)$req['cycle_started_at'],
            $hash
        );
        if ($dupId) {
            logAudit($pdo, 'upload_duplicate_blocked', "Blocked duplicate department submission for req $deptRequirementId (matches doc #$dupId)", 'document', $dupId);
            return 'This exact file has already been submitted for this requirement in the current cycle. Edit the document and upload it again.';
        }
    }

    $stmt = $pdo->prepare("
        SELECT MAX(version) FROM documents 
        WHERE department_id = ? AND category = ? AND document_type = ?
    ");
    $stmt->execute([$req['department_id'], $req['category'], $req['document_type']]);
    $maxVersion = (int) $stmt->fetchColumn();
    $newVersion = $maxVersion + 1;

    $title = $req['category'] . ' - ' . $req['document_type'];

    $subdir = getFileStorageDir('pending_admin');
    $dirAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
    $uniqueFile = allocateStorageFilename($dirAbs, $title, $ext);
    $filepath = $dirAbs . $uniqueFile;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        @unlink($filepath);
        return "Failed to save file. Check upload directory permissions.";
    }

    $stmt = $pdo->prepare("
        INSERT INTO documents (
            department_id, category, document_type, title, filename, file_path,
            uploaded_by, teacher_requirement_id, status, version, upload_date, description, expiry_date, validity_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'pending_admin', ?, NOW(), ?, ?, ?)
    ");
    $filePath = $subdir . '/' . $uniqueFile;
    $success = $stmt->execute([
        $req['department_id'],
        $req['category'],
        $req['document_type'],
        $title,
        $file['name'],
        $filePath,
        $userId,
        $newVersion,
        $comment ?? '',
        $req['expiration_date'],
        classifyExpiryStatus($req['expiration_date'] ?? null)
    ]);

    if ($success) {
        $docId = (int) $pdo->lastInsertId();
        finalizeStorageFilename($pdo, $docId, 'documents', $title, $ext);
        ensureDocumentHash($pdo, 'documents', $docId);
        if ($locked) releaseUploadLock($pdo, $scopeKey);
        logAudit($pdo, 'program_head_upload', currentRoleName() . " uploaded version $newVersion for requirement ID $deptRequirementId", 'document', $docId);

        $pdo->prepare("
            UPDATE documents SET archived = 1
            WHERE id != ?
              AND department_id = ?
              AND category = ?
              AND document_type = ?
              AND teacher_requirement_id IS NULL
              AND status IN ('rejected_admin', 'rejected_program_head')
              AND archived = 0
        ")->execute([$docId, $req['department_id'], $req['category'], $req['document_type']]);

        $adminUsers = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
        foreach ($adminUsers as $admin) {
            createNotification(
                $pdo,
                $admin['id'],
                'New Department Document',
                "PH uploaded {$req['category']} - {$req['document_type']}. Awaiting your review.",
                'info',
                $docId,
                'document'
            );
        }

        return true;
    }
    if (file_exists($filepath)) unlink($filepath);
    return "Database error.";
}

// ===========================================================
// USER HELPERS
// ===========================================================

function getUserById(PDO $pdo, ?int $userId): ?array
{
    if (!$userId)
        return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function getProgramHeadForDepartment(PDO $pdo, int $deptId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, email, role FROM users
          WHERE department_id = ? AND role IN ('department','unit_head')
          ORDER BY (role = 'department') DESC, id ASC
          LIMIT 1"
    );
    $stmt->execute([$deptId]);
    return $stmt->fetch() ?: null;
}

// ===========================================================
// SUBMISSION DETAILS
// ===========================================================

function getSubmissionDetails(PDO $pdo, int $submissionId): ?array
{
    $sql = "
        SELECT d.*,
               u.name AS teacher_name,
               u.id AS teacher_id,
               u.email AS teacher_email,
               d.category,
               d.document_type,
               d.department_id,
               dept.department_name
        FROM documents d
        LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        LEFT JOIN users u ON tr.teacher_id = u.id
        JOIN departments dept ON d.department_id = dept.id
        WHERE d.id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$submissionId]);
    return $stmt->fetch() ?: null;
}

// ===========================================================
// SECURE FILE RESOLUTION
// ===========================================================

function securePath(string $relativePath)
{
    $base = rtrim(SECURE_STORAGE_PATH, '\\/') . DIRECTORY_SEPARATOR;
    $requested = $base . $relativePath;
    $real = realpath($requested);
    if ($real === false) return false;
    $baseReal = realpath($base);
    if ($baseReal === false) return false;
    if ($real !== $baseReal && strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0) return false;
    return $real;
}

// ===========================================================
// DUPLICATE DETECTION — SHA-256, approved-only, same cycle
// ===========================================================

function computeFileSha256(?string $relativePath): ?string
{
    if (empty($relativePath)) return null;
    $abs = securePath($relativePath);
    if (!$abs || !is_file($abs)) return null;
    $hash = @hash_file('sha256', $abs);
    return $hash ?: null;
}

function ensureDocumentHash(PDO $pdo, string $table, int $docId): ?string
{
    if (!in_array($table, ['documents', 'ph_documents'], true) || $docId < 1) return null;

    $stmt = $pdo->prepare("SELECT file_path, file_hash FROM `$table` WHERE id = ?");
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if (!empty($row['file_hash'])) return (string)$row['file_hash'];

    $hash = computeFileSha256($row['file_path'] ?? null);
    if ($hash === null) return null;

    $pdo->prepare("UPDATE `$table` SET file_hash = ? WHERE id = ? AND (file_hash IS NULL OR file_hash = '')")
        ->execute([$hash, $docId]);
    return $hash;
}

function getRequirementCycleStart(PDO $pdo, array $docRow): ?string
{
    $deptId = $docRow['department_id'] ?? null;
    $cat    = $docRow['category'] ?? null;
    $dt     = $docRow['document_type'] ?? null;
    $trId   = $docRow['teacher_requirement_id'] ?? null;

    if (!empty($trId)) {
        $stmt = $pdo->prepare("
            SELECT dr.cycle_started_at
              FROM teacher_requirements tr
              JOIN department_requirements dr ON dr.id = tr.requirement_id
             WHERE tr.id = ?
        ");
        $stmt->execute([(int)$trId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? null : (string)$v;
    }

    if ($deptId === null || $cat === null || $dt === null) return null;

    $stmt = $pdo->prepare("
        SELECT cycle_started_at FROM department_requirements
         WHERE department_id = ? AND category = ? AND document_type = ?
         LIMIT 1
    ");
    $stmt->execute([(int)$deptId, $cat, $dt]);
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null || $v === '') ? null : (string)$v;
}

function findDuplicateApprovedDocument(
    PDO $pdo,
    string $hash,
    ?int $deptId,
    ?string $category,
    ?string $docType,
    ?string $cycleStart,
    int $excludeId = 0
): ?array {
    if ($hash === '' || $hash === null) return null;

    if ($cycleStart === null) {
        $cycleSql = "AND (
            EXISTS (
                SELECT 1 FROM teacher_requirements tr
                  JOIN department_requirements dr ON dr.id = tr.requirement_id
                 WHERE tr.id = d.teacher_requirement_id
                   AND dr.department_id <=> ?
                   AND dr.category <=> ?
                   AND dr.document_type <=> ?
                   AND dr.cycle_started_at IS NULL
            )
            OR (d.teacher_requirement_id IS NULL AND EXISTS (
                SELECT 1 FROM department_requirements dr
                 WHERE dr.department_id <=> d.department_id
                   AND dr.category <=> d.category
                   AND dr.document_type <=> d.document_type
                   AND dr.cycle_started_at IS NULL
            ))
        )";
        $cycleParams = [$deptId, $category, $docType];
    } else {
        $cycleSql = "AND (
            EXISTS (
                SELECT 1 FROM teacher_requirements tr
                  JOIN department_requirements dr ON dr.id = tr.requirement_id
                 WHERE tr.id = d.teacher_requirement_id
                   AND dr.department_id <=> ?
                   AND dr.category <=> ?
                   AND dr.document_type <=> ?
                   AND dr.cycle_started_at = ?
            )
            OR (d.teacher_requirement_id IS NULL AND EXISTS (
                SELECT 1 FROM department_requirements dr
                 WHERE dr.department_id <=> d.department_id
                   AND dr.category <=> d.category
                   AND dr.document_type <=> d.document_type
                   AND dr.cycle_started_at = ?
            ))
        )";
        $cycleParams = [$deptId, $category, $docType, $cycleStart, $cycleStart];
    }

    $sql = "
        SELECT d.id, d.title, d.file_path, d.department_id, d.category, d.document_type
          FROM documents d
         WHERE d.archived = 0
           AND d.status = 'approved_admin'
           AND d.file_hash = ?
           AND d.id <> ?
           AND (d.department_id <=> ?)
           AND (d.category <=> ?)
           AND (d.document_type <=> ?)
           $cycleSql
         LIMIT 1
    ";
    $params = array_merge([$hash, $excludeId, $deptId, $category, $docType], $cycleParams);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[CHED] findDuplicateApprovedDocument failed: ' . $e->getMessage());
        return null;
    }
}

// ===========================================================
// UPLOAD-TIME EXACT-DUPLICATE GUARD
// ===========================================================

function hashUploadedTempFile(array $file): ?string
{
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_file($tmp)) return null;
    $h = @hash_file('sha256', $tmp);
    return $h ?: null;
}

function acquireUploadLock(PDO $pdo, string $scopeKey, int $timeout = 5): bool
{
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$scopeKey, $timeout]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('[CHED] acquireUploadLock failed: ' . $e->getMessage());
        return false;
    }
}

function releaseUploadLock(PDO $pdo, string $scopeKey): void
{
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$scopeKey]);
    } catch (Throwable $e) {
        error_log('[CHED] releaseUploadLock failed: ' . $e->getMessage());
    }
}

function findDuplicateAssignmentDoc(PDO $pdo, string $table, string $fkColumn, int $assignmentId, string $hash, int $excludeId = 0): ?int
{
    if ($hash === '') return null;
    if (!in_array($table, ['documents', 'ph_documents'], true)) return null;
    if (!in_array($fkColumn, ['teacher_requirement_id', 'teacher_ph_requirement_id'], true)) return null;
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$fkColumn` = ? AND file_hash = ? AND id <> ? LIMIT 1");
    $stmt->execute([$assignmentId, $hash, $excludeId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function findDuplicateDepartmentDoc(PDO $pdo, int $deptId, string $category, string $docType, ?string $cycleStart, string $hash, int $excludeId = 0): ?int
{
    if ($hash === '') return null;
    if ($cycleStart === null) {
        $cycleSql = "AND EXISTS (SELECT 1 FROM department_requirements dr
            WHERE dr.department_id <=> d.department_id
              AND dr.category <=> d.category
              AND dr.document_type <=> d.document_type
              AND dr.cycle_started_at IS NULL)";
        $params = [$deptId, $category, $docType, $hash, $excludeId];
    } else {
        $cycleSql = "AND EXISTS (SELECT 1 FROM department_requirements dr
            WHERE dr.department_id <=> d.department_id
              AND dr.category <=> d.category
              AND dr.document_type <=> d.document_type
              AND dr.cycle_started_at = ?)";
        $params = [$deptId, $category, $docType, $hash, $excludeId, $cycleStart];
    }
    $sql = "SELECT d.id FROM documents d
            WHERE d.teacher_requirement_id IS NULL
              AND d.department_id <=> ?
              AND d.category <=> ?
              AND d.document_type <=> ?
              AND d.file_hash = ?
              AND d.id <> ?
              $cycleSql
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function findDuplicateDirectUpload(PDO $pdo, ?int $deptId, string $title, string $hash, int $excludeId = 0): ?int
{
    if ($hash === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM documents
        WHERE file_hash = ? AND title = ? AND (department_id <=> ?)
          AND source_type = 'quick_direct' AND id <> ? LIMIT 1");
    $stmt->execute([$hash, $title, $deptId, $excludeId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function findDuplicateRepoDoc(PDO $pdo, ?int $deptId, int $phId, string $title, string $hash): ?int
{
    if ($hash === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM ph_repository WHERE department_id <=> ? AND program_head_id = ? AND title = ? AND file_hash = ? LIMIT 1");
    $stmt->execute([$deptId, $phId, $title, $hash]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// ===========================================================
// SHARED UPLOAD PIPELINE — the 7 security layers, one implementation
// ===========================================================

// Layers 1-7: upload error -> ext allow-list -> size cap -> MIME sniff
// (fail closed) -> dangerous-MIME gate -> content hash + duplicate check
// under a lock -> move_uploaded_file under a system-generated name.
// Returns ['ext' => ..., 'hash' => ..., 'filename' => ...] on success, or an
// error message string (same convention as uploadXxxSubmission()).
// $dupCheck: fn(PDO $pdo, string $hash): ?int — id of an existing identical
// document, or null when clean.
// ponytail: dedupe policy differs per upload path (table/keys/title), so it
// stays a caller-supplied callback; only the plumbing is shared. The old
// per-ext mime fallback map is omitted — every key of it was already in
// ALLOWED_EXTENSIONS (layer 2), so it could never change an outcome, and it
// rejected real docx/pptx (finfo reports application/zip).
function validateAndStoreUpload(PDO $pdo, array $file, string $title, string $subdir, ?string $lockKey = null, ?callable $dupCheck = null): array|string
{
    // Layer 1
    if (!isset($file['name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }
    // Layer 2
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return "Invalid file type. Allowed: " . ALLOWED_EXTENSIONS_LABEL . ".";
    }
    // Layer 3
    if ($file['size'] > maxFileSizeBytesFor($ext)) {
        return "File size must not exceed " . maxFileSizeLabelFor($ext) . ".";
    }
    // Layer 4 — C-1 fail closed: a false $mime would slip past the gates below.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo !== false) finfo_close($finfo);
    if ($mime === false) {
        return "Could not read the uploaded file to verify its type. Please try again.";
    }
    // Layer 5
    if (!in_array($mime, ALLOWED_MIMES) && isDangerousMime($mime)) {
        return "File content does not match its extension.";
    }
    // Layer 6 — hash BEFORE the move in layer 7 (the tmp file is gone after it).
    $hash = hashUploadedTempFile($file);
    if ($hash === null) {
        return 'Could not read the uploaded file to verify its contents. Please try again.';
    }
    if ($lockKey !== null && acquireUploadLock($pdo, $lockKey)) {
        register_shutdown_function(fn() => releaseUploadLock($pdo, $lockKey));
    }
    if ($dupCheck !== null) {
        $dupId = $dupCheck($pdo, $hash);
        if ($dupId !== null) {
            logAudit($pdo, 'upload_duplicate_blocked', "Blocked duplicate upload [$lockKey] — matches doc #$dupId", 'upload', $dupId);
            return 'This exact file has already been uploaded. Please upload a changed version.';
        }
    }
    // Layer 7
    $dirAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
    $filename = allocateStorageFilename($dirAbs, $title, $ext);
    if (!move_uploaded_file($file['tmp_name'], $dirAbs . $filename)) {
        @unlink($dirAbs . $filename);
        return "Failed to save file. Check upload directory permissions.";
    }
    return ['ext' => $ext, 'hash' => $hash, 'filename' => $filename];
}

// ===========================================================
// SYSTEM-CONTROLLED STORAGE FILENAMES
// ===========================================================

function sanitizeFilenameTitle(string $title): string
{
    $title = str_replace("\0", '', $title);
    $title = preg_replace('/[\x00-\x1F\x7F]/', '', $title);
    $title = preg_replace('/[<>:"\/\\\\|?*]/', '_', $title);
    $title = preg_replace('/[^A-Za-z0-9 _\-]/', '_', $title);
    $title = preg_replace('/[_ ]+/', '_', $title);
    $title = trim($title, '._ ');
    if ($title === '') $title = 'document';
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $title)) {
        $title .= '_file';
    }
    if (strlen($title) > 150) $title = substr($title, 0, 150);
    return $title;
}

function buildStorageFilename(string $title, string $ext, ?string $uniqueSuffix = null): string
{
    $ext = strtolower(trim($ext));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        $ext = 'bin';
    }
    $stem = sanitizeFilenameTitle($title);
    if ($uniqueSuffix !== null && $uniqueSuffix !== '') {
        $suffix = preg_replace('/[^A-Za-z0-9_\-]+/', '', $uniqueSuffix);
        if (strlen($suffix) > 40) $suffix = substr($suffix, 0, 40);
        if ($suffix !== '') $stem .= '_' . $suffix;
    }
    return $stem . '.' . $ext;
}

function allocateStorageFilename(string $dirAbs, string $title, string $ext, ?string $uniqueSuffix = null): string
{
    if (!is_dir($dirAbs)) {
        @mkdir($dirAbs, 0755, true);
    }
    $dirAbs = rtrim($dirAbs, '\\/');
    $name = buildStorageFilename($title, $ext, $uniqueSuffix);
    for ($i = 0; $i < 30; $i++) {
        $fh = @fopen($dirAbs . DIRECTORY_SEPARATOR . $name, 'x');
        if ($fh !== false) {
            fclose($fh);
            return $name;
        }
        $extra = ($uniqueSuffix !== null && $uniqueSuffix !== '' ? $uniqueSuffix . '_' : '')
            . bin2hex(random_bytes(4));
        $name = buildStorageFilename($title, $ext, $extra);
    }
    return buildStorageFilename($title, $ext, 'f' . time() . bin2hex(random_bytes(3)));
}

function finalizeStorageFilename(PDO $pdo, int $rowId, string $table, string $title, string $ext): void
{
    static $allowed = ['documents', 'ph_documents', 'ph_repository', 'ph_requirements'];
    if (!in_array($table, $allowed, true) || $rowId < 1) return;

    $stmt = $pdo->prepare("SELECT file_path FROM `$table` WHERE id = ?");
    $stmt->execute([$rowId]);
    $rel = (string)($stmt->fetchColumn() ?: '');
    if ($rel === '') return;

    $oldAbs = securePath($rel);
    if ($oldAbs === false || !is_file($oldAbs)) return;

    $newName = buildStorageFilename($title, $ext);
    if ($newName === basename($oldAbs)) return;

    $newAbs = dirname($oldAbs) . DIRECTORY_SEPARATOR . $newName;
    if (file_exists($newAbs)) return;
    if (!@rename($oldAbs, $newAbs)) return;

    $parentRel = str_replace('\\', '/', dirname($rel));
    $newRel = ($parentRel === '.' || $parentRel === '' ? '' : $parentRel . '/') . $newName;
    try {
        $pdo->prepare("UPDATE `$table` SET file_path = ? WHERE id = ?")->execute([$newRel, $rowId]);
    } catch (PDOException $e) {
        @rename($newAbs, $oldAbs);
        error_log('[CHED] finalizeStorageFilename DB update failed: ' . $e->getMessage());
    }
}

function copyStoredFile(string $sourceRel, string $targetSubdir, string $title, string $ext): ?string
{
    $src = securePath($sourceRel);
    if ($src === false || !is_file($src)) return null;
    $sub = trim(str_replace('\\', '/', $targetSubdir), '/');
    if ($sub === '') return null;
    $dirAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $sub) . DIRECTORY_SEPARATOR;
    $name = allocateStorageFilename($dirAbs, $title, $ext);
    if (!@copy($src, $dirAbs . $name)) {
        @unlink($dirAbs . $name);
        error_log('[CHED] copyStoredFile failed: ' . $sourceRel);
        return null;
    }
    return $sub . '/' . $name;
}

function getPreviewCacheDir()
{
    $dir = TEMP_PATH . 'preview_cache' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

// ===========================================================
// DOCUMENT STORAGE ROUTING
// ===========================================================

function deptSlug(int $departmentId, ?PDO $pdo = null): string
{
    static $cache = [];
    if (isset($cache[$departmentId])) {
        return $cache[$departmentId];
    }
    $slugs = [
        13 => 'bsis',
        8  => 'bstm',
    ];
    if (isset($slugs[$departmentId])) {
        return $cache[$departmentId] = $slugs[$departmentId];
    }
    if ($pdo) {
        $stmt = $pdo->prepare('SELECT department_code, department_name FROM departments WHERE id = ?');
        $stmt->execute([$departmentId]);
        $row = $stmt->fetch();
        if ($row) {
            $key = $row['department_code'] ?: $row['department_name'];
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($key)));
            $slug = trim($slug, '_');
            if ($slug !== '') {
                return $cache[$departmentId] = $slug;
            }
        }
    }
    return $cache[$departmentId] = 'dept_' . $departmentId;
}

function createDepartmentFolders(int $departmentId, PDO $pdo): void
{
    $slug = deptSlug($departmentId, $pdo);
    foreach (['admin_repository', 'admin_archive', 'ph_repository'] as $folder) {
        $dir = UPLOAD_PATH . $folder . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function ensureDepartmentFolders(PDO $pdo, array $folders = ['admin_repository', 'admin_archive', 'ph_repository']): void
{
    foreach ($pdo->query('SELECT id FROM departments') as $row) {
        $slug = deptSlug((int)$row['id'], $pdo);
        foreach ($folders as $folder) {
            $dir = UPLOAD_PATH . $folder . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}

// ===========================================================
// admin_repository MIRROR OF department_folders
// ===========================================================

function sanitizeFolderName(string $name): string
{
    $name = preg_replace('#[<>:"/\\\\|?*\x00-\x1F\x7F]#', '_', trim($name));
    $name = rtrim($name, '. ');
    return ($name === '' || $name === '.' || $name === '..') ? '_' : $name;
}

function folderPathSegments(?PDO $pdo, ?int $folderId, string $table = 'department_folders'): array
{
    if ($pdo === null || $folderId === null || $folderId < 1) return [];
    if (!in_array($table, ['department_folders', 'ph_repo_folders'], true)) return [];
    $segs = [];
    $seen = [];
    for ($i = 0; $folderId > 0 && $i < 32 && !isset($seen[$folderId]); $i++) {
        $seen[$folderId] = true;
        $stmt = $pdo->prepare("SELECT folder_name, parent_folder_id FROM `$table` WHERE id = ?");
        $stmt->execute([$folderId]);
        $f = $stmt->fetch();
        if (!$f) break;
        if (strtolower(trim((string)$f['folder_name'])) !== 'root' && trim((string)$f['folder_name']) !== '') {
            array_unshift($segs, sanitizeFolderName((string)$f['folder_name']));
        }
        $folderId = $f['parent_folder_id'] ? (int)$f['parent_folder_id'] : 0;
    }
    return $segs;
}

function storageSubdir(string $root, ?PDO $pdo, ?int $departmentId, ?int $folderId = null): string
{
    $dir = $root;
    if ($departmentId !== null && $departmentId > 0) {
        $dir .= '/' . deptSlug($departmentId, $pdo);
    }
    foreach (folderPathSegments($pdo, $folderId) as $seg) {
        $dir .= '/' . $seg;
    }
    return $dir;
}

function documentFolderId(?PDO $pdo, string $table, int $docId): ?int
{
    if ($pdo === null || $table !== 'documents' || $docId < 1) return null;
    try {
        $stmt = $pdo->prepare('SELECT teacher_requirement_id, department_id, category, document_type FROM documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) return null;

        if (!empty($doc['teacher_requirement_id'])) {
            $t = $pdo->prepare('SELECT dr.folder_id FROM teacher_requirements tr JOIN department_requirements dr ON dr.id = tr.requirement_id WHERE tr.id = ?');
            $t->execute([(int)$doc['teacher_requirement_id']]);
            $r = $t->fetch();
            return ($r && $r['folder_id'] !== null) ? (int)$r['folder_id'] : null;
        }

        if ($doc['department_id'] === null || $doc['category'] === null || $doc['document_type'] === null) return null;
        $q = $pdo->prepare('SELECT folder_id FROM department_requirements
                             WHERE department_id = ? AND category = ? AND document_type = ?
                             ORDER BY id DESC LIMIT 1');
        $q->execute([$doc['department_id'], $doc['category'], $doc['document_type']]);
        $r = $q->fetch();
        return ($r && $r['folder_id'] !== null) ? (int)$r['folder_id'] : null;
    } catch (Throwable $e) {
        error_log('[CHED] documentFolderId failed: ' . $e->getMessage());
        return null;
    }
}

function mirrorDepartmentFolders(PDO $pdo, int $departmentId, string $parentRel, ?int $parentId, array &$keep): void
{
    $sql = 'SELECT id, folder_name FROM department_folders WHERE department_id = ? AND parent_folder_id '
        . ($parentId === null ? 'IS NULL' : '= ?')
        . " AND LOWER(folder_name) <> 'root' ORDER BY folder_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parentId === null ? [$departmentId] : [$departmentId, $parentId]);
    foreach ($stmt->fetchAll() as $f) {
        $rel = $parentRel . '/' . sanitizeFolderName((string)$f['folder_name']);
        $keep[$rel] = true;
        $abs = rtrim(UPLOAD_PATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir($abs)) {
            @mkdir($abs, 0755, true);
        }
        mirrorDepartmentFolders($pdo, $departmentId, $rel, (int)$f['id'], $keep);
    }
}

function pruneUnknownDirs(string $abs, string $rel, array $keep): void
{
    $entries = @scandir($abs);
    if ($entries === false) return;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $childAbs = $abs . DIRECTORY_SEPARATOR . $e;
        if (!is_dir($childAbs)) continue;
        $childRel = $rel . '/' . $e;
        pruneUnknownDirs($childAbs, $childRel, $keep);
        if (!isset($keep[$childRel])) {
            @rmdir($childAbs);
        }
    }
}

function phRootSlug(?PDO $pdo, int $phId): ?string
{
    if ($pdo === null || $phId < 1) return null;
    try {
        $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$phId]);
        $name = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[CHED] phRootSlug failed: ' . $e->getMessage());
        return null;
    }
    if ($name === false || $name === null) return null;
    $slug = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim((string)$name))), '_');
    return $slug !== '' ? $slug : 'ph_' . $phId;
}

function phRepoSubdir(?PDO $pdo, int $phId, ?int $folderId = null): ?string
{
    $slug = phRootSlug($pdo, $phId);
    if ($slug === null) return null;
    $dir = 'ph_repository/' . $slug;
    foreach (folderPathSegments($pdo, $folderId, 'ph_repo_folders') as $seg) {
        $dir .= '/' . $seg;
    }
    return $dir;
}

function mirrorPhFolders(PDO $pdo, int $phId, string $parentRel, ?int $parentId, array &$keep): void
{
    $sql = 'SELECT id, folder_name FROM ph_repo_folders WHERE program_head_id = ? AND parent_folder_id '
        . ($parentId === null ? 'IS NULL' : '= ?')
        . " AND LOWER(folder_name) <> 'root' ORDER BY folder_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parentId === null ? [$phId] : [$phId, $parentId]);
    foreach ($stmt->fetchAll() as $f) {
        $rel = $parentRel . '/' . sanitizeFolderName((string)$f['folder_name']);
        $keep[$rel] = true;
        $abs = rtrim(UPLOAD_PATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir($abs)) {
            @mkdir($abs, 0755, true);
        }
        mirrorPhFolders($pdo, $phId, $rel, (int)$f['id'], $keep);
    }
}

function relocateStoragePath(PDO $pdo, string $base, string $currentRel, string $targetRel, array $tables): bool
{
    if ($currentRel === $targetRel) return true;
    $dstAbs = $base . str_replace('/', DIRECTORY_SEPARATOR, $targetRel);
    $src = securePath($currentRel);
    if ($src !== false && is_file($src)) {
        if (file_exists($dstAbs)) {
            error_log('[CHED] relocateStoragePath target already exists, left in place: ' . $currentRel);
            return false;
        }
        if (!is_dir(dirname($dstAbs))) {
            @mkdir(dirname($dstAbs), 0755, true);
        }
        if (!@rename($src, $dstAbs)) {
            error_log('[CHED] relocateStoragePath move failed: ' . $currentRel);
            return false;
        }
    } elseif (!is_file($dstAbs)) {
        return false;
    }
    foreach ($tables as $t) {
        $pdo->prepare("UPDATE `$t` SET file_path = ? WHERE file_path = ?")->execute([$targetRel, $currentRel]);
    }
    return true;
}

function phDocumentOwner(PDO $pdo, array $row): ?int
{
    foreach (['uploaded_by', 'reviewed_by_program_head'] as $col) {
        $v = $row[$col] ?? null;
        if ($v !== null && (int)$v > 0 && phRootSlug($pdo, (int)$v) !== null) return (int)$v;
    }
    return null;
}

function syncPhRepository(?PDO $pdo): void
{
    if ($pdo === null) return;
    $base = rtrim(UPLOAD_PATH, '\\/') . DIRECTORY_SEPARATOR;
    $phRootAbs = $base . 'ph_repository' . DIRECTORY_SEPARATOR;

    try {
        $phIds = array_map('intval', $pdo->query("SELECT id FROM users WHERE role IN ('department','unit_head')")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[CHED] syncPhRepository PH list failed: ' . $e->getMessage());
        return;
    }

    $keep = ['ph_repository' => true];
    if (!is_dir($phRootAbs)) {
        @mkdir($phRootAbs, 0755, true);
    }
    $slugs = [];
    foreach ($phIds as $phId) {
        $slug = phRootSlug($pdo, $phId);
        if ($slug === null) continue;
        $slugs[$phId] = $slug;
        $rel = 'ph_repository/' . $slug;
        $keep[$rel] = true;
        $abs = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir($abs)) {
            @mkdir($abs, 0755, true);
        }
        try {
            mirrorPhFolders($pdo, $phId, $rel, null, $keep);
        } catch (Throwable $e) {
            error_log('[CHED] syncPhRepository folders failed: ' . $e->getMessage());
        }
    }

    $tables = ['ph_repository', 'ph_documents', 'documents'];
    $moved = [];

    try {
        $rows = $pdo->query("SELECT program_head_id, folder_id, file_path FROM ph_repository
                             WHERE file_path IS NOT NULL AND file_path LIKE 'ph_repository/%'");
        foreach ($rows as $r) {
            $phId = (int)$r['program_head_id'];
            if (!isset($slugs[$phId])) continue;
            $current = str_replace('\\', '/', (string)$r['file_path']);
            $target = phRepoSubdir($pdo, $phId, $r['folder_id'] !== null ? (int)$r['folder_id'] : null);
            if ($target === null) continue;
            if (relocateStoragePath($pdo, $base, $current, $target . '/' . basename($current), $tables)) {
                $moved[$current] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[CHED] syncPhRepository rows failed: ' . $e->getMessage());
    }

    try {
        $rows = $pdo->query("SELECT file_path, uploaded_by, reviewed_by_program_head FROM ph_documents
                             WHERE file_path IS NOT NULL AND file_path LIKE 'ph_repository/%'");
        foreach ($rows as $r) {
            $current = str_replace('\\', '/', (string)$r['file_path']);
            if (isset($moved[$current])) continue;
            $phId = phDocumentOwner($pdo, $r);
            if ($phId === null) continue;
            $target = phRepoSubdir($pdo, $phId);
            if ($target === null) continue;
            if (relocateStoragePath($pdo, $base, $current, $target . '/' . basename($current), $tables)) {
                $moved[$current] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[CHED] syncPhRepository ph_documents failed: ' . $e->getMessage());
    }

    pruneUnknownDirs($phRootAbs, 'ph_repository', $keep);
}

function syncStorageMirrors(?PDO $pdo): void
{
    if ($pdo === null) return;
    $base = rtrim(UPLOAD_PATH, '\\/') . DIRECTORY_SEPARATOR;

    try {
        $deptIds = array_map('intval', $pdo->query('SELECT id FROM departments')->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[CHED] syncStorageMirrors dept list failed: ' . $e->getMessage());
        return;
    }
    $knownDepts = array_flip($deptIds);

    $keep = [
        'admin_repository'            => true,
        'admin_archive'               => true,
        'admin_archive/my_repository' => true,
    ];

    foreach (['admin_repository', 'admin_archive'] as $root) {
        $rootAbs = $base . str_replace('/', DIRECTORY_SEPARATOR, $root) . DIRECTORY_SEPARATOR;
        if (!is_dir($rootAbs)) {
            @mkdir($rootAbs, 0755, true);
        }
        foreach ($deptIds as $deptId) {
            $rel = $root . '/' . deptSlug($deptId, $pdo);
            $keep[$rel] = true;
            $abs = $base . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir($abs)) {
                @mkdir($abs, 0755, true);
            }
            try {
                mirrorDepartmentFolders($pdo, $deptId, $rel, null, $keep);
            } catch (Throwable $e) {
                error_log('[CHED] syncStorageMirrors folders failed: ' . $e->getMessage());
            }
        }
        pruneUnknownDirs($rootAbs, $root, $keep);
    }

    $views = [
        ['root' => 'admin_repository', 'flag' => 'archived = 0'],
        ['root' => 'admin_archive',    'flag' => 'archived = 1'],
    ];
    foreach ($views as $v) {
        try {
            $docs = $pdo->query("SELECT id, department_id, file_path FROM documents
                                  WHERE status = 'approved_admin' AND {$v['flag']}
                                    AND file_path IS NOT NULL AND file_path <> ''");
            foreach ($docs as $doc) {
                $deptId = (int)$doc['department_id'];
                if (!isset($knownDepts[$deptId])) continue;

                $currentRel = str_replace('\\', '/', (string)$doc['file_path']);
                $targetRel = storageSubdir($v['root'], $pdo, $deptId, documentFolderId($pdo, 'documents', (int)$doc['id']))
                    . '/' . basename($currentRel);
                if ($targetRel === $currentRel) continue;

                $dstAbs = $base . str_replace('/', DIRECTORY_SEPARATOR, $targetRel);
                $src = securePath($currentRel);
                if ($src !== false && is_file($src)) {
                    if (file_exists($dstAbs)) {
                        error_log('[CHED] syncStorageMirrors target already exists, left in place: ' . $currentRel);
                        continue;
                    }
                    if (!is_dir(dirname($dstAbs))) {
                        @mkdir(dirname($dstAbs), 0755, true);
                    }
                    if (!@rename($src, $dstAbs)) {
                        error_log('[CHED] syncStorageMirrors move failed: ' . $currentRel);
                        continue;
                    }
                } elseif (!is_file($dstAbs)) {
                    continue;
                }
                $pdo->prepare('UPDATE documents SET file_path = ? WHERE id = ?')->execute([$targetRel, (int)$doc['id']]);
            }
        } catch (Throwable $e) {
            error_log('[CHED] syncStorageMirrors docs (' . $v['root'] . ') failed: ' . $e->getMessage());
        }
    }

    syncPhRepository($pdo);
}

function getFileStorageDir(string $status, ?string $source = null, ?int $departmentId = null, ?PDO $pdo = null, ?int $folderId = null, ?int $phId = null): string
{
    $subdir = match ($status) {
        'pending_program_head' => 'pending/faculty',
        'pending_admin' => 'pending/program_head',
        'approved_program_head' => ($phId !== null && $pdo !== null ? (phRepoSubdir($pdo, $phId) ?? 'ph_repository') : 'ph_repository'),
        'approved_admin' => $departmentId ? storageSubdir('admin_repository', $pdo, $departmentId, $folderId) : 'admin_repository',
        'rejected_program_head' => ($source === 'ph') ? 'rejected/program_head' : 'rejected/faculty',
        'rejected_admin' => ($source === 'ph') ? 'rejected/program_head' : 'rejected/faculty',
        default => 'pending/faculty',
    };
    $dir = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $subdir;
}

function moveDocumentFile(PDO $pdo, int $docId, string $table, string $newStatus, ?string $source = null): string|false
{
    $stmt = $pdo->prepare("SELECT file_path, department_id FROM $table WHERE id = ?");
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    $oldPath = $row['file_path'] ?? null;
    if (!$oldPath) return false;

    $departmentId = $row['department_id'] ?? null;
    $folderId = ($newStatus === 'approved_admin') ? documentFolderId($pdo, $table, $docId) : null;
    $phId = null;
    if ($newStatus === 'approved_program_head') {
        $own = $pdo->prepare('SELECT uploaded_by, reviewed_by_program_head FROM ' . $table . ' WHERE id = ?');
        $own->execute([$docId]);
        $ownRow = $own->fetch();
        $phId = $ownRow ? phDocumentOwner($pdo, $ownRow) : null;
        if ($phId === null) return false;
    }
    $newSubdir = getFileStorageDir($newStatus, $source, $departmentId !== null ? (int)$departmentId : null, $pdo, $folderId, $phId);
    $filename = basename($oldPath);
    $newRelative = $newSubdir . '/' . $filename;

    $oldFull = securePath($oldPath);
    $newFull = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelative);
    if (!is_dir(dirname($newFull))) {
        mkdir(dirname($newFull), 0755, true);
    }

    if ($oldFull && file_exists($oldFull)) {
        if (!@rename($oldFull, $newFull)) {
            error_log("[CHED] moveDocumentFile: rename failed {$oldFull} -> {$newFull}");
            logAudit($pdo, 'file_move_failed', "rename failed for {$table}#{$docId}: {$oldFull} -> {$newFull}", $table, $docId);
            return false;
        }
        $pdo->prepare("UPDATE $table SET file_path = ? WHERE id = ?")->execute([$newRelative, $docId]);
        return $newRelative;
    }
    error_log("[CHED] moveDocumentFile: source file missing for {$table}#{$docId}");
    logAudit($pdo, 'file_move_failed', "source file missing for {$table}#{$docId}", $table, $docId);
    return false;
}

// ===========================================================
// UPLOAD DIR SETUP (filesystem only — no DB schema)
// ===========================================================

if (!is_dir(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}
if (!is_writable(UPLOAD_PATH)) {
    error_log('[CHED] Upload directory is not writable: ' . UPLOAD_PATH);
}
if (!file_exists(UPLOAD_PATH . '.htaccess')) {
    @file_put_contents(UPLOAD_PATH . '.htaccess', "Options -Indexes\nDeny from all\n");
}

foreach (['pending/faculty', 'pending/program_head', 'admin_repository', 'ph_repository', 'rejected/faculty', 'rejected/program_head', 'admin_archive', 'templates'] as $sub) {
    $dir = UPLOAD_PATH . $sub . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ===========================================================
// AUTO-ARCHIVE EXPIRED DOCUMENTS
// ===========================================================

function archivedDocCondition(): string
{
    return "(d.archived = 1 AND d.status = 'approved_admin')";
}

function autoArchiveDocuments(PDO $pdo): int
{
    $count = 0;

    $sql = "SELECT d.id, d.file_path, d.title, dept.id AS department_id, dept.department_name
              FROM documents d
              JOIN departments dept ON d.department_id = dept.id
             WHERE d.expiry_date IS NOT NULL
               AND d.expiry_date <= CURDATE()
               AND d.archived = 0
               AND d.status = 'approved_admin'";
    $expired = $pdo->query($sql)->fetchAll();

    foreach ($expired as $doc) {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare("SELECT id, file_path, file_hash FROM documents
                                    WHERE id = ? AND archived = 0 AND status = 'approved_admin'
                                    FOR UPDATE");
            $lock->execute([$doc['id']]);
            $fresh = $lock->fetch();
            if (!$fresh) {
                $pdo->rollBack();
                continue;
            }

            $hash = $fresh['file_hash'] ?: computeFileSha256($fresh['file_path']);

            $filename = basename($fresh['file_path']);
            $newRel = storageSubdir('admin_archive', $pdo, (int)$doc['department_id'], documentFolderId($pdo, 'documents', (int)$doc['id'])) . '/' . $filename;
            $newAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRel);
            if (!is_dir(dirname($newAbs))) @mkdir(dirname($newAbs), 0755, true);

            $oldAbs = securePath($fresh['file_path']);
            $hasFile = $oldAbs && file_exists($oldAbs);
            if ($hasFile && !@rename($oldAbs, $newAbs)) {
                error_log('[CHED] autoArchive move failed for document #' . $doc['id'] . ': ' . $oldAbs);
                $pdo->rollBack();
                continue;
            }

            $upd = $pdo->prepare("UPDATE documents
                                     SET file_path = ?, archived = 1, file_hash = COALESCE(file_hash, ?)
                                   WHERE id = ? AND archived = 0");
            $upd->execute([$hasFile ? $newRel : $fresh['file_path'], $hash, $doc['id']]);

            if ($upd->rowCount() > 0) {
                $count++;
                logAudit($pdo, 'archive', "Archived: {$doc['title']} ({$doc['department_name']})", 'document', $doc['id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CHED] autoArchive (documents) failed for #' . $doc['id'] . ': ' . $e->getMessage());
        }
    }

    $sqlPh = "SELECT pd.id, pd.file_path, pd.title, dept.id AS department_id, dept.department_name
                FROM ph_documents pd
                JOIN departments dept ON pd.department_id = dept.id
               WHERE pd.expiry_date IS NOT NULL
                 AND pd.expiry_date <= CURDATE()
                 AND pd.archived = 0
                 AND pd.status = 'approved_admin'";
    $expiredPh = $pdo->query($sqlPh)->fetchAll();

    foreach ($expiredPh as $doc) {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare("SELECT id, file_path, file_hash FROM ph_documents
                                    WHERE id = ? AND archived = 0 AND status = 'approved_admin'
                                    FOR UPDATE");
            $lock->execute([$doc['id']]);
            $fresh = $lock->fetch();
            if (!$fresh) {
                $pdo->rollBack();
                continue;
            }

            $hash = $fresh['file_hash'] ?: computeFileSha256($fresh['file_path']);

            $filename = basename($fresh['file_path']);
            $newRel = storageSubdir('admin_archive', $pdo, (int)$doc['department_id']) . '/' . $filename;
            $newAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRel);
            if (!is_dir(dirname($newAbs))) @mkdir(dirname($newAbs), 0755, true);

            $oldAbs = securePath($fresh['file_path']);
            $hasFile = $oldAbs && file_exists($oldAbs);
            if ($hasFile && !@rename($oldAbs, $newAbs)) {
                error_log('[CHED] autoArchive move failed for PH document #' . $doc['id'] . ': ' . $oldAbs);
                $pdo->rollBack();
                continue;
            }

            $upd = $pdo->prepare("UPDATE ph_documents
                                     SET file_path = ?, archived = 1, file_hash = COALESCE(file_hash, ?)
                                   WHERE id = ? AND archived = 0");
            $upd->execute([$hasFile ? $newRel : $fresh['file_path'], $hash, $doc['id']]);

            if ($upd->rowCount() > 0) {
                $count++;
                logAudit($pdo, 'archive', "Archived PH doc: {$doc['title']} ({$doc['department_name']})", 'ph_document', $doc['id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CHED] autoArchive (ph_documents) failed for #' . $doc['id'] . ': ' . $e->getMessage());
        }
    }

    return $count;
}

// ===========================================================
// MISSING FILE CLEANUP
// ===========================================================

function cleanupMissingFiles(PDO $pdo): array
{
    $results = [
        'documents_deleted' => 0,
        'ph_documents_deleted' => 0,
        'errors' => []
    ];

    $storageUsable = function (?string $filePath): bool {
        if (empty($filePath)) {
            return false;
        }
        $rel = str_replace('\\', '/', trim($filePath, '\\/'));
        $dir = rtrim(SECURE_STORAGE_PATH, '\\/') . DIRECTORY_SEPARATOR . dirname($rel);
        if ($dir === rtrim(SECURE_STORAGE_PATH, '\\/') || !is_dir($dir)) {
            return false;
        }
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                return true;
            }
        }
        return false;
    };

    $fileExists = function (?string $filePath): bool {
        if (empty($filePath)) {
            return false;
        }
        $fullPath = securePath($filePath);
        return $fullPath && file_exists($fullPath) && is_file($fullPath);
    };

    $isGhost = function (?string $filePath) use ($storageUsable, $fileExists): bool {
        return $storageUsable($filePath) && !$fileExists($filePath);
    };

    try {
        $stmt = $pdo->query("SELECT id, file_path, title FROM documents WHERE file_path IS NOT NULL");
        $docs = $stmt->fetchAll();

        foreach ($docs as $doc) {
            if ($isGhost($doc['file_path'])) {
                try {
                    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$doc['id']]);
                    $results['documents_deleted']++;
                    logAudit(
                        $pdo,
                        'cleanup_missing_file',
                        "Deleted document with missing file: {$doc['title']} (file_path: {$doc['file_path']})",
                        'documents',
                        $doc['id']
                    );
                } catch (Exception $e) {
                    $results['errors'][] = "Failed to delete document #{$doc['id']}: " . $e->getMessage();
                }
            }
        }
    } catch (Exception $e) {
        $results['errors'][] = "Error querying documents: " . $e->getMessage();
    }

    try {
        $stmt = $pdo->query("SELECT id, file_path, title FROM ph_documents WHERE file_path IS NOT NULL");
        $docs = $stmt->fetchAll();

        foreach ($docs as $doc) {
            if ($isGhost($doc['file_path'])) {
                try {
                    $pdo->prepare("DELETE FROM ph_repository WHERE ph_document_id = ?")->execute([$doc['id']]);
                    $pdo->prepare("DELETE FROM ph_documents WHERE id = ?")->execute([$doc['id']]);
                    $results['ph_documents_deleted']++;
                    logAudit(
                        $pdo,
                        'cleanup_missing_file',
                        "Deleted ph_document with missing file: {$doc['title']} (file_path: {$doc['file_path']})",
                        'ph_documents',
                        $doc['id']
                    );
                } catch (Exception $e) {
                    $results['errors'][] = "Failed to delete ph_document #{$doc['id']}: " . $e->getMessage();
                }
            }
        }
    } catch (Exception $e) {
        $results['errors'][] = "Error querying ph_documents: " . $e->getMessage();
    }

    try {
        $deletedRepo = $pdo->exec("DELETE FROM ph_repository WHERE ph_document_id IS NOT NULL AND ph_document_id NOT IN (SELECT id FROM ph_documents)");
        if ($deletedRepo > 0) {
            logAudit($pdo, 'cleanup_orphaned', "Deleted $deletedRepo orphaned ph_repository entries", 'ph_repository', 0);
        }
    } catch (Exception $e) {
        $results['errors'][] = "Error cleaning ph_repository: " . $e->getMessage();
    }

    return $results;
}

// ===========================================================
// OPPORTUNISTIC EXPIRATION SYNC (web requests only, hourly)
// ===========================================================
maybeAutoSyncExpiration($pdo);

// ===========================================================
// Simple file-based cache helpers (shared across pages)
// ===========================================================
function fileCacheGet($key, $ttl = 300)
{
    $cacheFile = sys_get_temp_dir() . '/cache_' . md5($key) . '.json';
    if (file_exists($cacheFile)) {
        if ((time() - filemtime($cacheFile)) >= $ttl) { @unlink($cacheFile); return false; }
        $data = file_get_contents($cacheFile);
        return $data !== false ? json_decode($data, true) : false;
    }
    return false;
}
function fileCacheSet($key, $value, $ttl = 300)
{
    $cacheFile = sys_get_temp_dir() . '/cache_' . md5($key) . '.json';
    file_put_contents($cacheFile, json_encode($value));
    touch($cacheFile);
}
function fileCacheDelete($key)
{
    $cacheFile = sys_get_temp_dir() . '/cache_' . md5($key) . '.json';
    if (file_exists($cacheFile)) @unlink($cacheFile);
}

// ===========================================================
// AJAX HELPERS — lightweight JSON response for live polling
// ===========================================================
function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function sendJson(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getTeacherTasksHtml(PDO $pdo, int $teacherId): array
{
    $requirements = getTeacherRequirementsOptimized($pdo, $teacherId);
    $phRequirements = getTeacherPHRequirementsOptimized($pdo, $teacherId);

    foreach ($phRequirements as &$phReq) {
        $phReq['is_ph_requirement'] = true;
        $phReq['source'] = 'ph_document';
        $phReq['category'] = 'PH';
        $phReq['document_type'] = $phReq['title'];
        $phReq['mandatory'] = 1;
        $phReq['expiration_date'] = $phReq['deadline'] ?? null;
        $phReq['latest_admin_comment'] = $phReq['latest_admin_comment'] ?? null;
        $phReq['latest_ph_comment'] = $phReq['latest_ph_comment'] ?? null;
        $phReq['latest_filename'] = $phReq['latest_filename'] ?? null;
        $phReq['template_filename'] = $phReq['ref_filename'] ?? null;
        $phReq['template_file_path'] = $phReq['ref_file_path'] ?? null;
        $phReq['source_label'] = 'PH';
        $phReq['source_label_class'] = 'badge-info';
    }
    unset($phReq);

    foreach ($requirements as &$req) {
        $req['source'] = 'document';
        $req['is_ph_requirement'] = false;
        $req['template_filename'] = null;
        $req['template_file_path'] = null;
        if (isset($req['requirement_type']) && $req['requirement_type'] === 'program_head_requirement') {
            $req['source_label'] = 'PH';
            $req['source_label_class'] = 'badge-info';
        } else {
            $req['source_label'] = 'Admin';
            $req['source_label_class'] = 'badge-warning';
        }
    }
    unset($req);

    $allRequirements = array_merge($requirements, $phRequirements);

    $sections = ['not_started' => [], 'pending' => [], 'rejected' => [], 'completed' => []];
    foreach ($allRequirements as $req) {
        $status = $req['current_status'] ?? '';
        if (in_array($status, ['approved_program_head', 'approved_admin'])) {
            $sections['completed'][] = $req;
        } elseif (in_array($status, ['rejected_program_head', 'rejected_admin'])) {
            $sections['rejected'][] = $req;
        } elseif (in_array($status, ['pending_program_head', 'pending_admin'])) {
            $sections['pending'][] = $req;
        } else {
            $sections['not_started'][] = $req;
        }
    }

    return $sections;
}

function getAdminDashboardStats(PDO $pdo): array
{
    return [
        'totalDepts' => (int)(db_query("SELECT COUNT(*) FROM departments")[0]['COUNT(*)'] ?? 0),
        'totalProgramHeads' => (int)(db_query("SELECT COUNT(*) FROM users WHERE role = 'department'")[0]['COUNT(*)'] ?? 0),
        'totalUnitHeads' => (int)(db_query("SELECT COUNT(*) FROM users WHERE role = 'unit_head'")[0]['COUNT(*)'] ?? 0),
        'pendingAdmin' => getPendingSubmissionCount($pdo),
        'approvedTotal' => (int)(db_query("SELECT COUNT(*) FROM documents WHERE status = 'approved_admin'")[0]['COUNT(*)'] ?? 0)
            + (int)(db_query("SELECT COUNT(*) FROM ph_documents WHERE status = 'approved_admin'")[0]['COUNT(*)'] ?? 0),
        'rejectedTotal' => (int)(db_query("SELECT COUNT(*) FROM documents WHERE status IN ('rejected_admin','rejected_program_head')")[0]['COUNT(*)'] ?? 0)
            + (int)(db_query("SELECT COUNT(*) FROM ph_documents WHERE status IN ('rejected_admin','rejected_program_head')")[0]['COUNT(*)'] ?? 0),
    ];
}