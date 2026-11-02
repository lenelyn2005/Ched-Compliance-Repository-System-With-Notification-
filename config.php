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

// Trusted reverse proxies for getClientIP(). Must be defined before any call
// to getClientIP() — the session-kill enforcement block below runs first.
$TRUSTED_PROXIES = [];

// Load environment variables (.env) BEFORE any SMTP/DB config is read.
require_once __DIR__ . '/includes/env_loader.php';
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    loadEnv($envPath);
} else {
    // Also try cwd if running from different directory
    $cwdEnv = getcwd() . DIRECTORY_SEPARATOR . '.env';
    if (file_exists($cwdEnv)) {
        loadEnv($cwdEnv);
    }
}

// Performance optimization - GZIP compression before any output
require_once __DIR__ . '/includes/performance.php';
perf_gzip_start();

// Centralized storage service (R2 migration, Stage 2). Declarations only —
// the R2 SDK loads lazily on first use, so local-mode requests pay nothing.
require_once __DIR__ . '/includes/r2_storage.php';

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
// Managed MySQL (e.g. Aiven) uses a non-default port and requires TLS.
// DB_SSL_CA = path to the provider's CA certificate (ca.pem). When set, the
// connection is encrypted and the server certificate is verified.
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_SSL_CA', trim((string) (getenv('DB_SSL_CA') ?: '')));
define('DB_SSL_VERIFY', filter_var(getenv('DB_SSL_VERIFY') ?: 'true', FILTER_VALIDATE_BOOLEAN));
// Optional session sql_mode override. Aiven's default includes ANSI
// (ANSI_QUOTES, ONLY_FULL_GROUP_BY), which breaks queries written for
// XAMPP/MariaDB. Leave empty to keep the server default.
define('DB_SQL_MODE', trim((string) (getenv('DB_SQL_MODE') ?: '')));

// File storage settings — files stored outside public web root
define('SECURE_STORAGE_PATH', getenv('SECURE_STORAGE_PATH') ?: 'C:\\ched_secure_storage\\documents\\');
// TEMP_PATH — local scratch only (previews, ZIP staging, cache). Never under
// C:\ched_secure_storage: that tree is being retired, and getPreviewCacheDir()
// would otherwise recreate it after the tree is removed. Override with
// TEMP_PATH in .env if you want a different location.
define('TEMP_PATH', getenv('TEMP_PATH')
    ?: rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'ched_temp' . DIRECTORY_SEPARATOR);
if (!is_dir(TEMP_PATH)) {
    @mkdir(TEMP_PATH, 0755, true);
}
define('UPLOAD_PATH', SECURE_STORAGE_PATH); // alias — all existing code references UPLOAD_PATH

// Storage driver — Cloudflare R2 is the exclusive permanent storage backend.
// Local disk is never used as permanent document storage. Operations fail typed
// at the storage call site; boot verifies config once (cached 15 min) but does
// not gate unrelated pages on R2 availability.
define('APP_ENV', strtolower(trim((string) (getenv('APP_ENV') ?: 'development'))));
define('STORAGE_DRIVER', 'r2');

function failStorageConfig(string $reason): never
{
    error_log('[CHED] Storage configuration failure: ' . $reason);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Storage configuration error: ' . $reason . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    // Variable names only, never values; the specific reason is kept out of production responses.
    echo APP_ENV === 'production'
        ? 'Service configuration error.'
        : 'Configuration error: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    exit(1);
}

$missingR2Config = [];
foreach (['R2_BUCKET_NAME', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY'] as $r2var) {
    if (trim((string) getenv($r2var)) === '') {
        $missingR2Config[] = $r2var;
    }
}
if (trim((string) getenv('R2_ENDPOINT')) === '' && trim((string) getenv('R2_ACCOUNT_ID')) === '') {
    $missingR2Config[] = 'R2_ENDPOINT (or R2_ACCOUNT_ID)';
}
if ($missingR2Config) {
    failStorageConfig('R2 configuration incomplete or missing environment variables: ' . implode(', ', $missingR2Config) . '. Configure them in .env — never commit their values.');
}

// Storage driver init is NOT a per-request health gate: an R2 outage must not
// take down login or any page that does not need storage. The boot check only
// confirms the client/bucket config works and writes an ok-marker (15 min) so
// normal navigation pays zero R2 latency here; real object operations still
// fail loudly (typed) on their own. On failure the marker is cleared and the
// failure is negative-cached for 60 s so an outage does not re-probe R2 on
// every request; storage-requiring endpoints report the outage per operation.
function r2BootCheck(?callable $probe = null): void
{
    if (!defined('PERF_CACHE_DIR')) {
        define('PERF_CACHE_DIR', __DIR__ . '/system/storage/cache');
    }
    $marker = PERF_CACHE_DIR . '/r2_boot_ok';
    if (@filemtime($marker) !== false && (time() - @filemtime($marker)) < 900) {
        return;
    }
    $failMarker = PERF_CACHE_DIR . '/r2_boot_fail';
    if (@filemtime($failMarker) !== false && (time() - @filemtime($failMarker)) < 60) {
        return;
    }
    try {
        // Real boot probe; tests inject a throwing closure and a temp cache dir.
        ($probe ?? static fn (): ?array => R2Storage::default()->headBucket())();
        @mkdir(PERF_CACHE_DIR, 0755, true);
        @file_put_contents($marker, (string) time());
        @unlink($failMarker);
    } catch (Throwable $e) {
        @unlink($marker);
        @mkdir(PERF_CACHE_DIR, 0755, true);
        @file_put_contents($failMarker, (string) time());
        // Log sanitized diagnostic details server-side; boot continues so
        // unrelated pages stay up and storage ops fail typed where used.
        $san = R2Storage::toStorageError($e)->getMessage();
        error_log('[CHED] R2 initial check failed (boot continues): ' . get_class($e) . ' ' . $san);
    }
}
r2BootCheck();

// System settings — .env overridable, defaults below. No DB override table.
$MAX_FILE_SIZE      = (int) (getenv('MAX_FILE_SIZE') ?: 100 * 1024 * 1024); // 100MB
$ALLOWED_EXTENSIONS = array_filter(array_map('trim', explode(',', getenv('ALLOWED_EXTENSIONS')
    ?: 'pdf, doc, docx, jpg, jpeg, png, gif, webp, xls, xlsx, ppt, pptx')));
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
// Function: getSmtpConfig
function getSmtpConfig(): array
{
    return [
        'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => (int)(getenv('SMTP_PORT') ?: 587),
        'user' => getenv('SMTP_USER') ?: '',
        'pass' => getenv('SMTP_PASS') ?: '',
        'from' => getenv('SMTP_FROM') ?: (getenv('SMTP_USER') ?: 'noreply@localhost'),
        'from_name' => getenv('SMTP_FROM_NAME') ?: 'CHED Compliance System'
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

// Configurable inactivity threshold for online/offline presence (seconds).
$onlineInactivityThreshold = (int)(getenv('ONLINE_INACTIVITY_THRESHOLD') ?: 1800);

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
        // Anti session-fixation: strict mode rejects client-supplied session
        // IDs that don't exist server-side. CAVEAT: use_strict_mode is
        // PHP_INI_PERDIR, so ALSO set it in php.ini — the ini_set below only
        // takes effect where the SAPI allows it.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', 1800);
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

    $sessionLifetime = 1800; // 30 min inactivity timeout

    // Single-active-session: a token whose last real activity is older than this
    // is abandoned and can be taken over. Matched to the 30-min idle timeout so a
    // session the app would already have logged out is never a blocking "ghost".
    // ponytail: TTL zero, no ghost session block; upgrade when concurrent login guard needed
    define('ACTIVE_SESSION_TTL', 0);

    // ✅ FIX: This check now runs for EVERY page (including login.php) so idle
    // logged-in users are always logged out after 30 minutes of inactivity.
    // login.php no longer has its own duplicate timeout check.
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $sessionLifetime)) {
        // Clear DB session token so presence status shows Offline immediately.
        if (!empty($_SESSION['user_id']) && !empty($_SESSION['session_token'])) {
            try {
                $pdo->prepare('UPDATE users SET active_session_token = NULL, active_session_last_seen = NULL WHERE id = ? AND active_session_token = ?')
                    ->execute([(int) $_SESSION['user_id'], $_SESSION['session_token']]);
            } catch (Throwable $e) {
                error_log('[CHED Compliance] Session expiration DB clear failed: ' . $e->getMessage());
            }
        }
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
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

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
$GLOBALS['users_is_active_col'] = false;
if (PHP_SAPI !== 'cli') {
    try {
        $pdo->query('SELECT active_session_token, active_session_last_seen FROM users WHERE id = 0');
        $GLOBALS['active_session_cols'] = true;
    } catch (Throwable $e) {
        error_log('[CHED Compliance] users.active_session_* columns missing — restore schema from final.database.sql (single-session guard dormant).');
    }
    // Same probe style as above: without migrations/super_admin.sql the
    // deactivation checks below stay dormant and every page behaves as before.
    try {
        $pdo->query('SELECT is_active FROM users WHERE id = 0');
        $GLOBALS['users_is_active_col'] = true;
    } catch (Throwable $e) {
        error_log('[CHED Compliance] users.is_active column missing — apply migrations/super_admin.sql (account deactivation dormant).');
    }
}

try {
    $tzOffset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $quotedTz = $pdo->quote($tzOffset);
    $pdo->exec("SET time_zone = " . $quotedTz);
} catch (Throwable $e) {
    error_log('[CHED Compliance] Failed to set MySQL session time_zone: ' . $e->getMessage());
}

// ===========================================================
// LOCKOUT / SESSION ENFORCEMENT — single choke point
// ===========================================================
// Every authenticated request re-checks users.locked_until against the DB.
// Session variables are never trusted for lockout state: a lock activated in
// another tab, window, or device must kill this session too.
if (PHP_SAPI !== 'cli' && !empty($_SESSION['user_id'])) {
    try {
        $enfCols = !empty($GLOBALS['users_is_active_col']) ? ', is_active' : '';
        $enfStmt = $pdo->prepare('SELECT locked_until, active_session_token, active_session_last_seen' . $enfCols . ' FROM users WHERE id = ?');
        $enfStmt->execute([(int) $_SESSION['user_id']]);
        $enfRow        = $enfStmt->fetch(PDO::FETCH_ASSOC); // false = no such user
        $userMissing   = ($enfRow === false);
        $lockedUntil   = $userMissing ? false : $enfRow['locked_until'];
        // locked_until is a Unix timestamp after the login.php migration; accept legacy DATETIME too.
        $lockedTs      = ($lockedUntil === false || $lockedUntil === null)
            ? 0
            : (is_numeric($lockedUntil) ? (int)$lockedUntil : (int)strtotime($lockedUntil));
        $lockRemaining = ($lockedTs > time()) ? $lockedTs - time() : 0;

        // Deactivation is account state, not session state: the Super Admin flipping
        // is_active=0 must kill a live session here, exactly like a lockout does.
        $deactivated = !empty($GLOBALS['users_is_active_col']) && !$userMissing
            && (int) ($enfRow['is_active'] ?? 1) === 0;

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

        if ($userMissing || $lockRemaining > 0 || $tokenReplaced || $deactivated) {
            $killedUid = (int) $_SESSION['user_id'];
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
            } elseif ($deactivated) {
                $killAction = 'session_killed_deactivated';
                $killParam  = 'inactive=1';
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
        error_log('[CHED Compliance] Lockout enforcement query failed: ' . $e->getMessage());
    }
}

// ===========================================================
// SYSTEM SETTINGS
// ===========================================================
define('MAX_FILE_SIZE', $MAX_FILE_SIZE);
define('ALLOWED_EXTENSIONS', $ALLOWED_EXTENSIONS);
define('ALLOWED_MIMES', $ALLOWED_MIMES);
define('ALLOWED_EXTENSIONS_LABEL', $ALLOWED_EXTENSIONS_LABEL);
define('EXPIRY_WARNING_DAYS', $EXPIRY_WARNING_DAYS);
define('ONLINE_INACTIVITY_THRESHOLD', $onlineInactivityThreshold);

// ===========================================================
// ROLE CHECKS
// ===========================================================

// Function: isAdmin
function isAdmin(): bool
{
    return isset($_SESSION['admin_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
}

// Function: isDepartment
function isDepartment(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['department', 'unit_head'], true);
}

// Function: phRoleName
function phRoleName(?string $role): string
{
    return $role === 'unit_head' ? 'Unit Head' : 'Program Head';
}

// Function: currentRoleName
function currentRoleName(): string
{
    return phRoleName($_SESSION['role'] ?? '');
}

// Function: phRoleShortFor
function phRoleShortFor(?string $role): string
{
    return $role === 'unit_head' ? 'UH' : 'PH';
}

// Function: phRoleShort
function phRoleShort(): string
{
    return phRoleShortFor($_SESSION['role'] ?? '');
}

// Function: facultyRoleName
function facultyRoleName(?string $role): string
{
    return $role === 'staff' ? 'Staff' : 'Teacher';
}

// Function: isTeacher
function isTeacher(): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['teacher', 'staff'], true);
}

// Function: creatableRoles
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

// Function: allowedRoleFor
function allowedRoleFor(?string $actorRole, ?string $requested): ?string
{
    return in_array($requested, creatableRoles($actorRole), true) ? $requested : null;
}

// Function: refreshSessionRoleFromDb
// Memoized per request: requireAdmin(), superadmin(), requireDepartment() ...
// each call this; several pages mix gates. One identical user query per
// request is enough — role cannot legitimately change mid-request.
function refreshSessionRoleFromDb(): void
{
    static $refreshed = false;
    if ($refreshed) {
        return;
    }
    $refreshed = true;
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

// Function: requireAdmin
function requireAdmin(): void
{
    refreshSessionRoleFromDb();
    $isSuper = (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin');
    if (!isAdmin() && !$isSuper) {
        header('Location: login.php');
        exit;
    }
    if (!isset($_SESSION['admin_id']) && !$isSuper) {
        $_SESSION['admin_id'] = $_SESSION['user_id'];
    }
}

// Function: superadmin
// Centralized Super Admin authorization check for pages and endpoints.
// Reuses the same role verification as requireSuperAdmin().
function superadmin(): bool
{
    refreshSessionRoleFromDb();
    return (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin');
}

// Function: requireSuperAdmin
// The Super Admin is a separate, highly privileged role whose only job is to
// create and manage Admin accounts. It never satisfies isAdmin(): a super
// admin session has no admin_id and role !== 'admin', so every requireAdmin()
// page already refuses it — and every other role gate refuses everyone else.
function requireSuperAdmin(): void
{
    refreshSessionRoleFromDb();
    if (($_SESSION['role'] ?? '') === 'super_admin') {
        return;
    }
    // AJAX/Fetch callers get JSON instead of an HTML redirect body.
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized.', 'redirect' => 'login.php']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// Function: requireDepartment
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

// Function: requireTeacher
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

// Function: sanitize
function sanitize(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Function: timeAgo
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

// Function: generateCsrfToken
function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function: verifyCsrfToken
function verifyCsrfToken(string $token): bool
{
  return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Function: requireCsrfToken
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
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

// Function: getCsrfToken
function getCsrfToken(): string
{
    return $_SESSION['csrf_token'] ?? generateCsrfToken();
}

// Function: csrfField
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . getCsrfToken() . '">';
}

// ===========================================================
// EXPIRATION MONITORING - single source of truth (2-day rule)
// ===========================================================

// Function: classifyExpiryStatus
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

// Function: expiryStatusLabel
function expiryStatusLabel(string $status): string
{
    return ['active' => 'Active', 'expiring' => 'Expiring', 'expired' => 'Expired'][$status] ?? ucfirst($status);
}

// ===========================================================
// AUDIT
// ===========================================================

// Function: getClientIP
function getClientIP()
{
    global $TRUSTED_PROXIES;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // (array) cast stays defensive: $TRUSTED_PROXIES is defined at the top of
    // this file, but a partial include could still leave it unset.
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
    $userId    = $_SESSION['user_id'] ?? null;
    $timestamp = date('Y-m-d H:i:s');
    if (function_exists('getClientIP')) {
        $description .= ' [ip=' . getClientIP() . ']';
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_trail '
            . '(user_id, action, description, entity_type, entity_id, created_at, trail_scope) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (['admin', 'super_admin'] as $scope) {
            $stmt->execute([$userId, $action, $description, $entityType, $entityId, $timestamp, $scope]);
        }
    } catch (Exception $e) {
        error_log('[CHED Compliance] Audit log error: ' . $e->getMessage());
    }
}

// Function: isDangerousMime
function isDangerousMime(string $mime): bool
{
    return (bool) preg_match('/(?:php|executable|dosexec|msdos|msdownload|sharedlib|shellscript|html|bat)/i', $mime);
}

// Human-readable label for a byte ceiling (e.g. 10485760 -> "10MB").
// Function: megabytesLabel
function megabytesLabel(int $bytes): string
{
    return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . 'MB';
}

function maxFileSizeLabel(): string
{
    return megabytesLabel(MAX_FILE_SIZE);
}

// Images uploaded as documents are capped lower than other document types.
if (!defined('IMAGE_MAX_BYTES')) {
    define('IMAGE_MAX_BYTES', 10 * 1024 * 1024); // 10 MB
}

// Function: isImageExtension
function isImageExtension(string $ext): bool
{
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

// Effective size ceiling for an upload: 10 MB for images, MAX_FILE_SIZE otherwise.
// Function: maxFileSizeBytesFor
function maxFileSizeBytesFor(string $ext): int
{
    return isImageExtension($ext) ? IMAGE_MAX_BYTES : MAX_FILE_SIZE;
}

// Function: maxFileSizeLabelFor
function maxFileSizeLabelFor(string $ext): string
{
    return megabytesLabel(maxFileSizeBytesFor($ext));
}

// upload_max_filesize/post_max_size, not the app limit.
// Function: uploadErrorMessage
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

// Function: shouldSendEmailForNotification
function shouldSendEmailForNotification(string $title, string $type): bool
{
    global $NOTIFICATION_EMAIL_ENABLED;
    if (array_key_exists($title, $NOTIFICATION_EMAIL_ENABLED)) {
        return (bool)$NOTIFICATION_EMAIL_ENABLED[$title];
    }
    if (in_array($type, ['danger', 'success', 'warning'], true)) return true;
    return true;
}

// Function: createNotification
function createNotification(PDO $pdo, int $userId, string $title, string $message, string $type = 'info', ?int $docId = null, ?string $docType = null): void
{
    try {
        if (empty($title) || $title === 'Unknown' || empty($message)) {
            error_log('[CHED Compliance] Invalid notification - empty title or message');
            return;
        }

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

        triggerWebSocketNotification($notificationId, $userId);

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

// Function: getUnreadNotificationCount
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

// Function: markNotificationsRead
function markNotificationsRead(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare('UPDATE in_app_notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
    } catch (Exception $e) {
    }
}

// Function: markNotificationAsRead
function markNotificationAsRead(PDO $pdo, int $notificationId, int $userId): void
{
    try {
        $stmt = $pdo->prepare('UPDATE in_app_notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
    } catch (Exception $e) {
    }
}

// Function: markSingleNotificationRead
function markSingleNotificationRead(PDO $pdo, int $notificationId, int $userId): void
{
    markNotificationAsRead($pdo, $notificationId, $userId);
}

// Function: fetchValidNotifications
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

// Function: fetchRecentValidNotifications
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

// Function: checkDuplicateNotification
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

// Function: deleteInvalidNotifications
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
// WEBSOCKET AUTHENTICATION & DURABLE OUTBOX
// ===========================================================

// shortcut: durable delivery uses file-based outbox with file-lock; upgrade to transactional outbox table or message queue.
function generateWsTicket(int $userId): string
{
    $secret = getenv('WS_SECRET') ?: 'fallback-secret-change-me';
    $ttl = (int)(getenv('WS_TOKEN_TTL') ?: 120);
    $nonce = bin2hex(random_bytes(16));
    $payload = $userId . '|' . (time() + $ttl) . '|' . $nonce;
    $sig = hash_hmac('sha256', $secret, $payload);
    return $payload . ':' . $sig;
}

function verifyWsTicket(string $ticket): ?int
{
    $secret = getenv('WS_SECRET') ?: 'fallback-secret-change-me';
    $parts = explode(':', $ticket);
    if (count($parts) !== 2) return null;
    list($payload, $sig) = $parts;
    if (!hash_equals(hash_hmac('sha256', $secret, $payload), $sig)) return null;
    $payloadParts = explode('|', $payload);
    if (count($payloadParts) !== 3) return null;
    list($userIdStr, $expiryStr, $nonce) = $payloadParts;
    $userId = (int)$userIdStr;
    if ($userId <= 0) return null;
    if ((int)$expiryStr < time()) return null;
    // shortcut: replay prevention uses temporary file tracking; upgrade to DB outbox table for concurrent replay guard.
    $replayFile = __DIR__ . '/websocket/used_tickets.json';
    $used = [];
    if (file_exists($replayFile)) {
        $used = json_decode(file_get_contents($replayFile), true) ?: [];
    }
    if (isset($used[$nonce])) return null;
    $used[$nonce] = time();
    // Clean old entries older than 300 seconds
    $used = array_filter($used, fn($t) => ($t + 300) > time());
    @file_put_contents($replayFile, json_encode($used), LOCK_EX);
    return $userId;
}

function triggerWebSocketNotification(int $notificationId, int $userId): void
{
    $outboxFile = __DIR__ . '/websocket/pending.json';
    $items = [];
    if (file_exists($outboxFile)) {
        $items = json_decode(file_get_contents($outboxFile), true) ?: [];
    }
    $items[] = [
        'notification_id' => $notificationId,
        'user_id' => $userId,
        'created_at' => time(),
    ];
    @file_put_contents($outboxFile, json_encode($items), LOCK_EX);

    // Poke the internal loopback listener for instant delivery (fire-and-forget, 100ms max).
    // If the server is down, the fallback 1s timer + reconnect DB fetch still recover it.
    // shortcut: loopback TCP poke; upgrade to Unix socket / signal if port changes.
    $internalPort = (int)(getenv('WS_INTERNAL_PORT') ?: 8090);
    $sock = @stream_socket_client('tcp://127.0.0.1:' . $internalPort, $errno, $errstr, 0.1);
    if ($sock) {
        @fwrite($sock, 'poke');
        @fclose($sock);
    }
}

// ===========================================================
// REQUIREMENTS
// ===========================================================

// Function: getTeacherProgress
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

// Function: getTeacherRequirementsOptimized
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
        return storageFilePresent($filePath);
    }));
}

// Function: getTeacherPHRequirementsOptimized
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
        return storageFilePresent($filePath);
    }));
}

// Function: getAllTeacherProgressForProgramHead
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

// Function: uploadTeacherPHSubmission
function uploadTeacherPHSubmission(PDO $pdo, int $teacherPHRequirementId, int $teacherId, array $file, ?string $comment = ''): bool|string
{
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

    // All 7 security layers now live in validateAndStoreUpload() — one
    // implementation shared by every upload path.
    $subdir = getFileStorageDir('pending_program_head');
    $stored = validateAndStoreUpload(
        $pdo, $file, (string)$req['title'], $subdir,
        'updoc:phdoc:req:' . $teacherPHRequirementId,
        fn(PDO $pdo, string $hash) => findDuplicateAssignmentDoc($pdo, 'ph_documents', 'teacher_ph_requirement_id', $teacherPHRequirementId, $hash)
    );
    if (is_string($stored)) {
        return $stored;
    }
    $ext = $stored['ext'];
    $uniqueFile = $stored['filename'];

    $stmt = $pdo->prepare("SELECT MAX(version) FROM ph_documents WHERE teacher_ph_requirement_id = ?");
    $stmt->execute([$teacherPHRequirementId]);
    $maxVersion = (int) $stmt->fetchColumn();
    $newVersion = $maxVersion + 1;

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
    storageDeleteRel($filePath);
    return "Database error.";
}

// Function: sendTemplateToTeacher
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

// Function: getPendingSubmissionCount
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

// Function: getPendingPHSubmissions
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
        return storageFilePresent($filePath);
    }));
}

// Function: getAllPendingForProgramHead
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
        return storageFilePresent($filePath);
    }));

    return $allSubs;
}

// Function: programHeadReviewPH
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
// Function: uploadPHDocumentDirect
function uploadPHDocumentDirect(PDO $pdo, int $phId, ?int $deptId, array $file, string $title, bool $autoForward = false, ?string $description = ''): int|string
{
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

    $origName = basename($file['name']);
    // All 7 security layers now live in validateAndStoreUpload().
    $stored = validateAndStoreUpload(
        $pdo, $file, $title, $subdir,
        'updoc:doc:direct:' . ($deptId !== null ? (int)$deptId : 'null') . ':' . md5($title),
        fn(PDO $pdo, string $hash) => findDuplicateDirectUpload($pdo, $deptId !== null ? (int)$deptId : null, $title, $hash)
    );
    if (is_string($stored)) {
        return $stored;
    }
    $ext = $stored['ext'];
    $filename = $stored['filename'];
    $filePath = $subdir . '/' . $filename;

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
        storageDeleteRel($filePath);
        return 'Failed to save to database.';
    }

    if ($docSuccess) {
        $docId = (int) $pdo->lastInsertId();
        finalizeStorageFilename($pdo, $docId, 'documents', $title, $ext);
        ensureDocumentHash($pdo, 'documents', $docId);

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
// Function: getPendingForProgramHead
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
              WHERE IF(d.teacher_requirement_id IS NOT NULL, d2.teacher_requirement_id = d.teacher_requirement_id,
                  IF(d.document_type = 'Direct Upload', d2.id = d.id,
                      d2.department_id = d.department_id AND d2.category = d.category AND d2.document_type = d.document_type AND d2.teacher_requirement_id IS NULL))
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
        return storageFilePresent($filePath);
    }));

    return $results;
}

// Function: getTeachersInDepartment
function getTeachersInDepartment(PDO $pdo, int $deptId): array
{
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE role IN ('teacher','staff') AND department_id = ? ORDER BY name");
    $stmt->execute([$deptId]);
    return $stmt->fetchAll();
}

// Function: assignRequirementToTeacher
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

// Function: syncExpirationStatuses
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

// Function: refreshRequirementExpiry
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

// Function: refreshPhRequirementExpiry
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

// Function: autoArchiveExpiredDocuments
function autoArchiveExpiredDocuments(PDO $pdo): void
{
    syncExpirationStatuses($pdo);
}

// Function: autoExpireRequirements
function autoExpireRequirements(PDO $pdo, ?int $triggeredByUserId = null): void
{
    syncExpirationStatuses($pdo, $triggeredByUserId);
}

// Function: maybeAutoSyncExpiration
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

// Function: getExpiredRequirements
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
// Function: getPendingForAdmin
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
        return storageFilePresent($filePath);
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
        return storageFilePresent($filePath);
    }));

    $combined = array_merge($docs, $phDocs);
    usort($combined, function ($a, $b) {
        return strtotime($b['upload_date']) - strtotime($a['upload_date']);
    });

    return $combined;
}

// Function: transitionDocumentStatus
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

// Function: programHeadReview
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

// Function: adminReview
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

    // Verify R2 storage is healthy before changing any database state.
    // A broken or missing configuration must never create an inconsistent
    // approval (plan §9, §6.2).
    try {
        $r2 = R2Storage::default();
        $oldPath = $docInfo['file_path'] ?? null;
        if ($oldPath !== null && $oldPath !== '') {
            // Confirm the source object is reachable before committing DB state.
            $oldInfo = $r2->head((string)$oldPath);
            if ($oldInfo === null) {
                error_log("[CHED] adminReview: source file missing before approval for doc #{$submissionId}: {$oldPath}");
                return false;
            }
        }
    } catch (StorageException $e) {
        error_log('[CHED] adminReview blocked by storage failure for doc #' . $submissionId . ': ' . get_class($e) . ' - ' . $e->getMessage());
        return false;
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

// Function: adminReviewPH
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

// Function: deletePendingDocsForRequirement
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

// Function: uploadTeacherSubmission
function uploadTeacherSubmission(PDO $pdo, int $teacherRequirementId, int $teacherId, array $file, ?string $comment = ''): bool|string
{
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

    // All 7 security layers now live in validateAndStoreUpload().
    $subdir = getFileStorageDir('pending_program_head');
    $stored = validateAndStoreUpload(
        $pdo, $file, $title, $subdir,
        'updoc:doc:req:' . $teacherRequirementId,
        fn(PDO $pdo, string $hash) => findDuplicateAssignmentDoc($pdo, 'documents', 'teacher_requirement_id', $teacherRequirementId, $hash)
    );
    if (is_string($stored)) {
        return $stored;
    }
    $ext = $stored['ext'];
    $uniqueFile = $stored['filename'];

    $stmt = $pdo->prepare("SELECT MAX(version) FROM documents WHERE teacher_requirement_id = ?");
    $stmt->execute([$teacherRequirementId]);
    $maxVersion = (int) $stmt->fetchColumn();
    $newVersion = $maxVersion + 1;

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
    storageDeleteRel($filePath);
    return "Database error.";
}

// Function: uploadDepartmentSubmission
function uploadDepartmentSubmission(PDO $pdo, int $deptRequirementId, int $userId, array $file, ?string $comment = '', ?int $expectedDeptId = null): bool|string
{
    $stmt = $pdo->prepare("SELECT department_id, category, document_type, expiration_date, cycle_started_at FROM department_requirements WHERE id = ?");
    $stmt->execute([$deptRequirementId]);
    $req = $stmt->fetch();

    if (!$req) {
        return "Requirement not found.";
    }
    if ($expectedDeptId !== null && (int)$req['department_id'] !== $expectedDeptId) {
        return "Requirement not found.";
    }

    $title = $req['category'] . ' - ' . $req['document_type'];
    $subdir = getFileStorageDir('pending_admin');
    $stored = validateAndStoreUpload(
        $pdo, $file, $title, $subdir,
        'updoc:doc:dept:' . (int)$req['department_id'] . ':' . $req['category'] . ':' . $req['document_type'],
        fn(PDO $pdo, string $hash) => findDuplicateDepartmentDoc(
            $pdo,
            (int)$req['department_id'],
            (string)$req['category'],
            (string)$req['document_type'],
            ($req['cycle_started_at'] === null || $req['cycle_started_at'] === '') ? null : (string)$req['cycle_started_at'],
            $hash
        )
    );
    if (is_string($stored)) {
        return $stored;
    }
    $ext = $stored['ext'];
    $uniqueFile = $stored['filename'];

    $stmt = $pdo->prepare("
        SELECT MAX(version) FROM documents 
        WHERE department_id = ? AND category = ? AND document_type = ?
    ");
    $stmt->execute([$req['department_id'], $req['category'], $req['document_type']]);
    $maxVersion = (int) $stmt->fetchColumn();
    $newVersion = $maxVersion + 1;

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
    storageDeleteRel($filePath);
    return "Database error.";
}

// ===========================================================
// USER HELPERS
// ===========================================================

// Function: getUserById
function getUserById(PDO $pdo, ?int $userId): ?array
{
    if (!$userId)
        return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

// Function: getProgramHeadForDepartment
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

// Function: getSubmissionDetails
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

// Function: securePath
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

// Driver-aware delete of a stored document given its DB-relative path, for the
// upload-rollback paths: the row write failed, so nothing must be left behind.
// A missing object already counts as success in both drivers.
// ponytail: R2 delete failures here are logged, not rethrown — a rollback that
// cannot delete leaves an orphan key instead of failing the request, and orphan
// keys are report-only (plan §10 step 3). Upgrade to rethrow + ledger entry if
// orphan keys ever accumulate.
function storageDeleteRel(?string $relativePath): void
{
    if (empty($relativePath)) return;
    try {
        R2Storage::default()->delete($relativePath);
    } catch (StorageException | InvalidArgumentException $e) {
        error_log('[CHED] r2 delete failed on rollback: ' . get_class($e));
    }
}

// ===========================================================
// READ PATHS — existence, temp staging, streaming (plan §6.1)
// ===========================================================

// Does a stored document exist? False ONLY for genuine absence: a 404 in r2, a
// missing file on disk. Any other storage failure throws (outage, bad creds,
// throttling) so a caller can never read "storage is broken" as "document was
// deleted" — that confusion is what mass-deletes rows in the review flows (§9).
function storageObjectExists(string $relativePath): bool
{
    if ($relativePath === '') return false;
    try {
        return R2Storage::default()->exists($relativePath);
    } catch (InvalidArgumentException $e) {
        error_log('[CHED] storageObjectExists: invalid key — ' . $e->getMessage());
        throw new StorageUnknownError('invalid storage key', 0, $e);
    }
}

// Per-row existence filter for LISTING queries (plan §6.1). In r2 mode this is
// always true: calling head() per row is N network round-trips per page load
// (~190-1200 ms each, measured), and migration verifies every object against
// file_hash, so a listed row's object is there. Retrieval endpoints do the
// authoritative 404 check when a user actually opens the file.
// ponytail: ceiling is an object deleted out-of-band after migration — it stays
// listed until clicked, then 404s in the download endpoint. Upgrade to a
// per-request memoized head() cache if that ever becomes visible in practice.
function storageFilePresent(string $relativePath): bool
{
    return true;
}

// Size in bytes of a stored document, or null when it genuinely does not exist.
// Any other storage failure throws typed, so callers can answer 503 instead of
// 404 (plan §9).
function storageObjectSize(string $relativePath): ?int
{
    try {
        $info = R2Storage::default()->head($relativePath);
        return $info === null ? null : (int) $info['size'];
    } catch (InvalidArgumentException $e) {
        error_log('[CHED] storageObjectSize: invalid key — ' . $e->getMessage());
        throw new StorageUnknownError('invalid storage key', 0, $e);
    }
}

// Server-side object move (r2 only): copy, verify size, then delete the source.
// Returns false when the source is genuinely absent — the caller decides what
// that means — and throws typed on any other storage failure, so a failed move
// is loud and never looks like "the file was already gone".
// ponytail: verification is size-only — SHA-256 would re-stream the whole
// object; upgrade if integrity ever matters more than throughput here.
function storageMoveObject(string $sourceRel, string $destRel): bool
{
    $r2 = R2Storage::default();

    try {
        $src = $r2->head($sourceRel);
    } catch (InvalidArgumentException $e) {
        error_log('[CHED] storageMoveObject: invalid source key — ' . $e->getMessage());
        throw new StorageUnknownError('invalid source storage key', 0, $e);
    }
    if ($src === null) return false;
    if ($destRel === $sourceRel) return true; // never copy-onto-self and then delete

    try {
        $r2->copyTo($sourceRel, $destRel);
        $dst = $r2->head($destRel);
        if ($dst === null || (int) $dst['size'] !== (int) $src['size']) {
            throw new StorageUnknownError('move verification failed: size mismatch or destination missing');
        }
    } catch (\Throwable $e) {
        // A failed move must not leave a partial-size destination behind.
        try { $r2->delete($destRel); } catch (\Throwable) {}
        throw $e instanceof StorageException
            ? $e
            : new StorageUnknownError('move failed', 0, $e);
    }

    $r2->delete($sourceRel); // best-effort; a leftover key is report-only
    return true;
}

// Stage a stored document to a local temp file — the only way ZipArchive and
// PhpOffice can consume an object. Returns the temp path (the CALLER unlinks
// it) or null when the document genuinely does not exist; outages throw.
// ponytail: the local driver also copies to temp rather than handing back the
// real path, so no caller can accidentally unlink a live document. Ceiling: one
// extra copy per staged file; upgrade to a read-only handle if staging volume
// ever matters.
function storageStageLocalCopy(string $relativePath, string $extension = ''): ?string
{
    $dest = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
        . 'ched_stage_' . bin2hex(random_bytes(8)) . $extension;
    try {
        R2Storage::default()->downloadTo($relativePath, $dest); // partials self-clean
    } catch (StorageObjectNotFound $e) {
        return null;
    } catch (InvalidArgumentException $e) {
        error_log('[CHED] storageStageLocalCopy: invalid key — ' . $e->getMessage());
        @unlink($dest);
        throw new StorageUnknownError('invalid storage key', 0, $e);
    }
    return $dest;
}

// Emit a stored document's bytes to the output buffer. Assumes presence was
// already confirmed; a mid-stream storage failure truncates the transfer, which
// the client surfaces as a failed download.
function storageEmitBody(string $relativePath): void
{
    try {
        $body = R2Storage::default()->getStream($relativePath);
    } catch (InvalidArgumentException $e) {
        error_log('[CHED] storageEmitBody: invalid key — ' . $e->getMessage());
        throw new StorageUnknownError('invalid storage key', 0, $e);
    }
    try {
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    } finally {
        $body->close();
    }
}

// Send a stored document as a browser download. Headers stay byte-identical to
// the pre-migration ones so existing download assertions keep passing.
function storageStreamDownload(string $relativePath, string $downloadName): void
{
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($downloadName)) . '"');
    // shortcut: Content-Length skipped to eliminate duplicate R2 HEAD; upgrade when range support added.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    storageEmitBody($relativePath);
}

// ===========================================================
// DUPLICATE DETECTION — SHA-256, approved-only, same cycle
// ===========================================================

// Function: computeFileSha256
function computeFileSha256(?string $relativePath): ?string
{
    if (empty($relativePath)) return null;
    try {
        $r2 = R2Storage::default();
        $info = $r2->head($relativePath);
        if ($info === null) return null;
        $meta = (string) ($info['metadata']['sha256'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/i', $meta)) return strtolower($meta);
        return R2Storage::sha256Stream($r2->getStream($relativePath));
    } catch (StorageException | InvalidArgumentException $e) {
        error_log('[CHED] r2 computeFileSha256 failed: ' . get_class($e));
        return null;
    }
}

// Function: ensureDocumentHash
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

// Function: getRequirementCycleStart
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

// Function: findDuplicateApprovedDocument
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

// Function: hashUploadedTempFile
function hashUploadedTempFile(array $file): ?string
{
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_file($tmp)) return null;
    $h = @hash_file('sha256', $tmp);
    return $h ?: null;
}

// Function: acquireUploadLock
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

// Function: releaseUploadLock
function releaseUploadLock(PDO $pdo, string $scopeKey): void
{
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$scopeKey]);
    } catch (Throwable $e) {
        error_log('[CHED] releaseUploadLock failed: ' . $e->getMessage());
    }
}

// Function: findDuplicateAssignmentDoc
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

// Function: findDuplicateDepartmentDoc
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

// Function: findDuplicateDirectUpload
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

// Function: findDuplicateRepoDoc
function findDuplicateRepoDoc(PDO $pdo, ?int $deptId, int $phId, string $title, string $hash): ?int
{
    if ($hash === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM ph_repository WHERE department_id <=> ? AND program_head_id = ? AND title = ? AND file_hash = ? LIMIT 1");
    $stmt->execute([$deptId, $phId, $title, $hash]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// ===========================================================
// 7 security layers, one implementation
// ===========================================================

// Layers 1-7: upload error -> ext allow-list -> size cap -> MIME sniff
// (fail closed) -> dangerous-MIME gate -> content hash + duplicate check
// under a lock -> move_uploaded_file under a system-generated name.
// Returns ['ext' => ..., 'hash' => ..., 'filename' => ...] on success, or an
// error message string (same convention as uploadXxxSubmission()).
// $dupCheck: fn(PDO $pdo, string $hash): ?int — id of an existing identical
// document, or null when clean.
// Function: validateAndStoreUpload
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
    // Layer 5 — whitelist enforced strictly: ANY detected MIME not on
    // ALLOWED_MIMES is rejected, not just those matching the danger patterns.
    if (!in_array($mime, ALLOWED_MIMES, true) || isDangerousMime($mime)) {
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
    // Layer 7 — physical storage. Layers 1-6 and every duplicate/authorization
    // check run on the tmp file before this point and are unchanged; only the
    // destination differs by driver.
    $subdir = trim(str_replace('\\', '/', $subdir), '/');
    $filename = allocateStorageFilename($subdir, $title, $ext);
    if (STORAGE_DRIVER === 'r2') {
        // move_uploaded_file() cannot be used against an object store, so the
        // upload-origin guarantee it provides is asserted explicitly instead.
        if (!is_uploaded_file($file['tmp_name'])) {
            return "Failed to save file.";
        }
        $fh = @fopen($file['tmp_name'], 'rb');
        if ($fh === false) {
            return "Failed to save file.";
        }
        try {
            R2Storage::default()->put($subdir . '/' . $filename, $fh, $mime, ['sha256' => $hash]);
        } catch (StorageException $e) {
            // Invalid keys (InvalidArgumentException) deliberately propagate:
            // that is a programming fault and must fail loudly, never as a
            // generic upload error.
            error_log('[CHED] r2 put failed for upload: ' . get_class($e));
            return "Failed to save file. Please try again.";
        } finally {
            if (is_resource($fh)) fclose($fh);
        }
    } else {
        $dirAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
        if (!move_uploaded_file($file['tmp_name'], $dirAbs . $filename)) {
            @unlink($dirAbs . $filename);
            return "Failed to save file. Check upload directory permissions.";
        }
    }
    return ['ext' => $ext, 'hash' => $hash, 'filename' => $filename];
}

// ===========================================================
// SYSTEM-CONTROLLED STORAGE FILENAMES
// ===========================================================

// Function: sanitizeFilenameTitle
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

// Function: buildStorageFilename
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

// Function: allocateStorageFilename
// $subdir is storage-relative (no leading/trailing separator). The candidate
// name is reserved by exclusive-create on local; R2 keys are immutable so the
// reservation is a HeadObject check instead.
// ponytail: two racing uploads can still pick the same R2 key (there is no
// atomic create to reserve it) — accepted, because the DB unique indexes and
// the SHA-256 duplicate gates are the real guard. Upgrade to versioned keys if
// it ever bites.
function allocateStorageFilename(string $subdir, string $title, string $ext, ?string $uniqueSuffix = null): string
{
    $subdir = trim(str_replace('\\', '/', $subdir), '/');
    $isLocal = STORAGE_DRIVER !== 'r2';
    $dirAbs = $isLocal ? UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR : '';
    if ($isLocal && !is_dir($dirAbs)) {
        @mkdir($dirAbs, 0755, true);
    }
    // True when this candidate name is still free — and reserved, on local.
    $isFree = function (string $name) use ($isLocal, $dirAbs, $subdir): bool {
        if (!$isLocal) {
            return !R2Storage::default()->exists($subdir . '/' . $name);
        }
        $fh = @fopen(rtrim($dirAbs, '\\/') . DIRECTORY_SEPARATOR . $name, 'x');
        if ($fh === false) return false;
        fclose($fh);
        return true;
    };

    $name = buildStorageFilename($title, $ext, $uniqueSuffix);
    for ($i = 0; $i < 30; $i++) {
        if ($isFree($name)) {
            return $name;
        }
        $extra = ($uniqueSuffix !== null && $uniqueSuffix !== '' ? $uniqueSuffix . '_' : '')
            . bin2hex(random_bytes(4));
        $name = buildStorageFilename($title, $ext, $extra);
    }
    return buildStorageFilename($title, $ext, 'f' . time() . bin2hex(random_bytes(3)));
}

// Function: finalizeStorageFilename
function finalizeStorageFilename(PDO $pdo, int $rowId, string $table, string $title, string $ext): void
{
    static $allowed = ['documents', 'ph_documents', 'ph_repository', 'ph_requirements'];
    if (!in_array($table, $allowed, true) || $rowId < 1) return;

    $stmt = $pdo->prepare("SELECT file_path FROM `$table` WHERE id = ?");
    $stmt->execute([$rowId]);
    $rel = str_replace('\\', '/', (string)($stmt->fetchColumn() ?: ''));
    if ($rel === '') return;

    $newName = buildStorageFilename($title, $ext);
    if ($newName === basename($rel)) return;
    $parentRel = dirname($rel);
    $newRel = ($parentRel === '.' || $parentRel === '' ? '' : $parentRel . '/') . $newName;
    if ($newRel === $rel) return;

    try {
        $r2 = R2Storage::default();
        if ($r2->exists($newRel)) return;
        $r2->copyTo($rel, $newRel);
    } catch (StorageException | InvalidArgumentException $e) {
        error_log('[CHED] finalizeStorageFilename r2 copy failed: ' . get_class($e));
        return;
    }
    try {
        $pdo->prepare("UPDATE `$table` SET file_path = ? WHERE id = ?")->execute([$newRel, $rowId]);
    } catch (PDOException $e) {
        try {
            R2Storage::default()->delete($newRel);
        } catch (Throwable $ignored) {
        }
        error_log('[CHED] finalizeStorageFilename DB update failed: ' . $e->getMessage());
        return;
    }
    try {
        R2Storage::default()->delete($rel);
    } catch (Throwable $e) {
        error_log('[CHED] finalizeStorageFilename old-object delete failed: ' . get_class($e));
    }
}

// Function: copyStoredFile
function copyStoredFile(string $sourceRel, string $targetSubdir, string $title, string $ext): ?string
{
    $sub = trim(str_replace('\\', '/', $targetSubdir), '/');
    if ($sub === '') return null;
    try {
        $r2 = R2Storage::default();
        $name = allocateStorageFilename($sub, $title, $ext);
        $r2->copyTo($sourceRel, $sub . '/' . $name);
        return $sub . '/' . $name;
    } catch (StorageException | InvalidArgumentException $e) {
        error_log('[CHED] copyStoredFile failed: ' . $sourceRel . ' (' . get_class($e) . ')');
        return null;
    }
}

// Function: getPreviewCacheDir
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

// Function: deptSlug
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

// Function: createDepartmentFolders
function createDepartmentFolders(int $departmentId, PDO $pdo): void
{
    // R2 is exclusive permanent storage — no local directory creation.
    return;
}

// Function: ensureDepartmentFolders
function ensureDepartmentFolders(PDO $pdo, array $folders = ['admin_repository', 'admin_archive', 'ph_repository']): void
{
    // R2 is exclusive permanent storage — no local directory creation.
    return;
}

// ===========================================================
// admin_repository MIRROR OF department_folders
// ===========================================================

// Function: sanitizeFolderName
function sanitizeFolderName(string $name): string
{
    $name = preg_replace('#[<>:"/\\\\|?*\x00-\x1F\x7F]#', '_', trim($name));
    $name = rtrim($name, '. ');
    return ($name === '' || $name === '.' || $name === '..') ? '_' : $name;
}

// Function: folderPathSegments
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

// Function: storageSubdir
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

// Function: documentFolderId
function documentFolderId(?PDO $pdo, string $table, int $docId): ?int
{
    if ($pdo === null || $table !== 'documents' || $docId < 1) return null;
    try {
        $stmt = $pdo->prepare('SELECT folder_id, teacher_requirement_id, department_id, category, document_type FROM documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) return null;

        // shortcut: admin-set folder override (drag-and-drop move) always wins over
        // requirement-derived folder; 0 = root, NULL = follow the requirement row.
        // upgrade: enforce unique DB constraint if folder/requirement rules tighten.
        if ($doc['folder_id'] !== null && $doc['folder_id'] !== '') {
            $folderId = (int)$doc['folder_id'];
            return $folderId < 1 ? 0 : $folderId; // normalize 0 / positive; callers treat <1 as root
        }

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

// Function: mirrorDepartmentFolders
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

// Function: pruneUnknownDirs
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

// Function: phRootSlug
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

// Function: phRepoSubdir
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

// Function: mirrorPhFolders
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

// Function: relocateStoragePath
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

// Function: phDocumentOwner
function phDocumentOwner(PDO $pdo, array $row): ?int
{
    foreach (['uploaded_by', 'reviewed_by_program_head'] as $col) {
        $v = $row[$col] ?? null;
        if ($v !== null && (int)$v > 0 && phRootSlug($pdo, (int)$v) !== null) return (int)$v;
    }
    return null;
}

// Function: syncPhRepository
function syncPhRepository(?PDO $pdo): void
{
    if ($pdo === null) return;
    if (STORAGE_DRIVER === 'r2') {
        // R2 has no local tree, but the bucket must still mirror the system folder
        // structure, so reconcile ph_repository/ph_documents keys against the
        // folder-derived targets. A created/renamed/moved PH repo folder shows up
        // here as a key move. ponytail: ceiling is the same per-row object scan as
        // the dept reconcile; upgrade to batched prefix listings if PH doc volume
        // ever makes this hot.
        try {
            $phIds = array_map('intval', $pdo->query("SELECT id FROM users WHERE role IN ('department','unit_head')")->fetchAll(PDO::FETCH_COLUMN));
            $slugs = [];
            foreach ($phIds as $phId) {
                $slug = phRootSlug($pdo, (int)$phId);
                if ($slug !== null) $slugs[(int)$phId] = $slug;
            }

            $phTables = ['ph_repository', 'ph_documents', 'documents'];
            $moved = [];

            $phRepoRows = $pdo->query("SELECT file_path, program_head_id, folder_id FROM ph_repository
                                       WHERE file_path IS NOT NULL AND file_path LIKE 'ph_repository/%'");
            foreach ($phRepoRows as $r) {
                $phId = (int)$r['program_head_id'];
                if (!isset($slugs[$phId])) continue;
                $current = str_replace('\\', '/', (string)$r['file_path']);
                $target = phRepoSubdir($pdo, $phId, $r['folder_id'] !== null ? (int)$r['folder_id'] : null);
                if ($target === null) continue;
                $newRel = $target . '/' . basename($current);
                if ($current === $newRel) continue;
                try {
                    $movedOk = storageMoveObject($current, $newRel);
                } catch (StorageException | InvalidArgumentException $e) {
                    error_log('[CHED] syncPhRepository r2 key move failed for key ' . $current . ': ' . get_class($e));
                    continue;
                }
                if ($movedOk || R2Storage::default()->head($newRel) !== null) {
                    foreach ($phTables as $t) {
                        $pdo->prepare("UPDATE `$t` SET file_path = ? WHERE file_path = ?")->execute([$newRel, $current]);
                    }
                    $moved[$current] = true;
                }
            }

            $phDocRows = $pdo->query("SELECT file_path, uploaded_by, reviewed_by_program_head FROM ph_documents
                                      WHERE file_path IS NOT NULL AND file_path LIKE 'ph_repository/%'");
            foreach ($phDocRows as $r) {
                $current = str_replace('\\', '/', (string)$r['file_path']);
                if (isset($moved[$current])) continue;
                $phId = phDocumentOwner($pdo, $r);
                if ($phId === null) continue;
                $target = phRepoSubdir($pdo, $phId);
                if ($target === null) continue;
                $newRel = $target . '/' . basename($current);
                if ($current === $newRel) continue;
                try {
                    $movedOk = storageMoveObject($current, $newRel);
                } catch (StorageException | InvalidArgumentException $e) {
                    error_log('[CHED] syncPhRepository r2 key move failed for key ' . $current . ': ' . get_class($e));
                    continue;
                }
                if ($movedOk || R2Storage::default()->head($newRel) !== null) {
                    foreach ($phTables as $t) {
                        $pdo->prepare("UPDATE `$t` SET file_path = ? WHERE file_path = ?")->execute([$newRel, $current]);
                    }
                    $moved[$current] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('[CHED] syncPhRepository r2 reconcile failed: ' . $e->getMessage());
        }
        return;
    }
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

// Function: syncStorageMirrors
function syncStorageMirrors(?PDO $pdo): void
{
    if ($pdo === null) return;
    if (STORAGE_DRIVER === 'r2') {
        // R2 has no local tree, but the bucket must still mirror the system folder
        // structure, so reconcile object keys against the folder-derived targets. A
        // created/renamed/moved folder shows up here as a key move: keys are immutable
        // objects, so rename means copy, verify, delete old key, update file_path.
        // ponytail: ceiling is a per-row object scan per sync invocation; upgrade to
        // batched prefix listings if reconcile ever scans large doc sets per page load.
        try {
            $deptIds = array_map('intval', $pdo->query('SELECT id FROM departments')->fetchAll(PDO::FETCH_COLUMN));
            $knownDepts = array_flip($deptIds);
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
                        $targetRel = storageSubdir($v['root'], $pdo, $deptId, documentFolderId($pdo, 'documents', (int)$doc['id'])) . '/' . basename($currentRel);
                        if ($targetRel === $currentRel) continue;

                        try {
                            $moved = storageMoveObject($currentRel, $targetRel);
                        } catch (StorageException | InvalidArgumentException $e) {
                            error_log('[CHED] syncStorageMirrors r2 key move failed for document #' . $doc['id'] . ': ' . get_class($e));
                            continue;
                        }
                        if ($moved || R2Storage::default()->head($targetRel) !== null) {
                            $pdo->prepare('UPDATE documents SET file_path = ? WHERE id = ?')->execute([$targetRel, (int)$doc['id']]);
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[CHED] syncStorageMirrors docs (' . $v['root'] . ') failed: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[CHED] syncStorageMirrors r2 reconcile failed: ' . $e->getMessage());
        }
        syncPhRepository($pdo);
        return;
    }
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

// Function: getFileStorageDir
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
    if (STORAGE_DRIVER !== 'r2') {
        // R2 has key prefixes, not directories — there is nothing to create.
        $dir = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $subdir) . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    return $subdir;
}

// Function: moveDocumentFile
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

    if (STORAGE_DRIVER === 'r2') {
        // Copy → verify size → update DB → delete old key. The row is only
        // updated after the copy is confirmed, so a failed move leaves the
        // document readable at its old key (plan §6.2).
        if ($newRelative === $oldPath) return (string) $oldPath; // never copy-onto-self then delete
        try {
            $r2 = R2Storage::default();
            $oldInfo = $r2->head($oldPath);
            if ($oldInfo === null) {
                error_log("[CHED] moveDocumentFile: source object missing for {$table}#{$docId}");
                logAudit($pdo, 'file_move_failed', "source object missing for {$table}#{$docId}", $table, $docId);
                return false;
            }
            $r2->copyTo($oldPath, $newRelative);
            $newInfo = $r2->head($newRelative);
            if ($newInfo === null || (int) $newInfo['size'] !== (int) $oldInfo['size']) {
                throw new StorageUnknownError('copy verification failed: size mismatch or destination missing');
            }
            $upd = $pdo->prepare("UPDATE $table SET file_path = ? WHERE id = ?");
            $upd->execute([$newRelative, $docId]);
            if ($upd->rowCount() === 0) {
                $r2->delete($newRelative); // row vanished under us — leave no orphan key
                return false;
            }
            $r2->delete($oldPath); // best-effort; a leftover key is report-only
            return $newRelative;
        } catch (StorageException | InvalidArgumentException $e) {
            error_log("[CHED] moveDocumentFile: r2 move failed for {$table}#{$docId}: " . get_class($e));
            logAudit($pdo, 'file_move_failed', "r2 move failed for {$table}#{$docId}: " . get_class($e), $table, $docId);
            return false;
        }
    }

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
// HTML SANITIZATION — Document Preview Security
// ===========================================================
// shortcut: built-in allowlist sanitizer; upgrade to HTMLPurifier for full
// CSS/style preservation and stricter attribute filtering.
// Dependency: add "ezyang/htmlpurifier": "^4.18" to composer.json for upgrade.
function sanitizeDocumentHtml(string $html): string
{
    $config = HTMLPurifier_Config::createDefault();
    $config->set('HTML.Allowed', 'p,b,strong,i,em,u,s,strike,a[href],img[src],table,thead,tbody,tfoot,tr,th,td,ul,ol,li,blockquote,pre,code,hr,br,div,span,h1,h2,h3,h4,h5,h6');
    $config->set('HTML.AllowedAttributes', '*.class,a.href,img.src,img.alt,img.title,img.width,img.height,a.title');
    $config->set('CSS.AllowedProperties', 'font-family,font-size,color,background-color,text-align,width,height,border,border-collapse,padding,margin');
    $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
    $purifier = new HTMLPurifier($config);
    return $purifier->purify($html);
}

// ===========================================================
// UPLOAD DIR SETUP (filesystem only — no DB schema)
// ===========================================================

// Directory bootstrap is local-driver only: under R2 the document store lives
// entirely in the bucket, so no local directories (or .htaccess) are needed —
// and creating them would falsely imply local storage is still in use.
if (STORAGE_DRIVER !== 'r2') {
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
}

// ===========================================================
// AUTO-ARCHIVE EXPIRED DOCUMENTS
// ===========================================================

// Function: archivedDocCondition
function archivedDocCondition(): string
{
    return "(d.archived = 1 AND d.status = 'approved_admin')";
}

// Function: autoArchiveDocuments
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
            if (STORAGE_DRIVER !== 'r2') {
                $newAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRel);
                if (!is_dir(dirname($newAbs))) @mkdir(dirname($newAbs), 0755, true);
            }

            if (STORAGE_DRIVER === 'r2') {
                try {
                    $hasFile = storageMoveObject((string) $fresh['file_path'], $newRel);
                } catch (StorageException $e) {
                    error_log('[CHED] autoArchive move failed for document #' . $doc['id'] . ': ' . get_class($e));
                    $pdo->rollBack();
                    continue;
                }
            } else {
                $oldAbs = securePath($fresh['file_path']);
                $hasFile = $oldAbs && file_exists($oldAbs);
                if ($hasFile && !@rename($oldAbs, $newAbs)) {
                    error_log('[CHED] autoArchive move failed for document #' . $doc['id'] . ': ' . $oldAbs);
                    $pdo->rollBack();
                    continue;
                }
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
            if (STORAGE_DRIVER !== 'r2') {
                $newAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRel);
                if (!is_dir(dirname($newAbs))) @mkdir(dirname($newAbs), 0755, true);
            }

            if (STORAGE_DRIVER === 'r2') {
                try {
                    $hasFile = storageMoveObject((string) $fresh['file_path'], $newRel);
                } catch (StorageException $e) {
                    error_log('[CHED] autoArchive move failed for PH document #' . $doc['id'] . ': ' . get_class($e));
                    $pdo->rollBack();
                    continue;
                }
            } else {
                $oldAbs = securePath($fresh['file_path']);
                $hasFile = $oldAbs && file_exists($oldAbs);
                if ($hasFile && !@rename($oldAbs, $newAbs)) {
                    error_log('[CHED] autoArchive move failed for PH document #' . $doc['id'] . ': ' . $oldAbs);
                    $pdo->rollBack();
                    continue;
                }
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

// Function: cleanupMissingFiles
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
        if (STORAGE_DRIVER === 'r2') {
            // Bucket reachability replaces "the storage dir exists and still has
            // files in it". An unreachable bucket must look like UNUSABLE
            // storage, so nothing is ever classified as a ghost and no row is
            // deleted during an outage (plan §9). Probed once per sweep.
            static $bucketReachable = null;
            if ($bucketReachable === null) {
                try {
                    R2Storage::default()->headBucket();
                    $bucketReachable = true;
                } catch (StorageException $e) {
                    error_log('[CHED] cleanupMissingFiles: r2 unreachable, refusing to classify ghosts: ' . get_class($e));
                    $bucketReachable = false;
                }
            }
            return $bucketReachable;
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
        if (STORAGE_DRIVER === 'r2') {
            // exists() is false only on a genuine 404 and throws on any other
            // storage failure; the throw aborts the sweep before any row is
            // deleted, since classification always precedes deletion below.
            return R2Storage::default()->exists($filePath);
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

        // Safety guard: a wrong SECURE_STORAGE_PATH makes every stored file
        // "disappear", so every row would look like a ghost. Refuse to
        // mass-delete — log the anomaly and stop.
        $ghosts = array_values(array_filter($docs, fn($doc) => $isGhost($doc['file_path'])));
        if (count($ghosts) > 100) {
            $msg = 'cleanupMissingFiles: ' . count($ghosts) . ' documents look like ghosts — refusing to delete. Check SECURE_STORAGE_PATH.';
            error_log('[CHED Compliance] ' . $msg);
            $results['errors'][] = $msg;
            return $results;
        }

        foreach ($ghosts as $doc) {
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
    } catch (Exception $e) {
        $results['errors'][] = "Error querying documents: " . $e->getMessage();
    }

    try {
        $stmt = $pdo->query("SELECT id, file_path, title FROM ph_documents WHERE file_path IS NOT NULL");
        $docs = $stmt->fetchAll();

        // Safety guard: same as above — a misconfigured SECURE_STORAGE_PATH
        // must not mass-delete ph_documents (stops before the orphan sweep too).
        $ghosts = array_values(array_filter($docs, fn($doc) => $isGhost($doc['file_path'])));
        if (count($ghosts) > 100) {
            $msg = 'cleanupMissingFiles: ' . count($ghosts) . ' ph_documents look like ghosts — refusing to delete. Check SECURE_STORAGE_PATH.';
            error_log('[CHED Compliance] ' . $msg);
            $results['errors'][] = $msg;
            return $results;
        }

        foreach ($ghosts as $doc) {
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
// Function: fileCacheGet
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
// Function: fileCacheSet
function fileCacheSet($key, $value, $ttl = 300)
{
    $cacheFile = sys_get_temp_dir() . '/cache_' . md5($key) . '.json';
    // LOCK_EX: concurrent requests writing the same key must not interleave
    // and corrupt the JSON. (touch() removed — file_put_contents sets mtime.)
    file_put_contents($cacheFile, json_encode($value), LOCK_EX);
}
// Function: fileCacheDelete
function fileCacheDelete($key)
{
    $cacheFile = sys_get_temp_dir() . '/cache_' . md5($key) . '.json';
    if (file_exists($cacheFile)) @unlink($cacheFile);
}

// ===========================================================
// AJAX HELPERS — lightweight JSON response for live polling
// ===========================================================
// Function: isAjaxRequest
function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Function: sendJson
function sendJson(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Function: getTeacherTasksHtml
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

// Function: getAdminDashboardStats
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