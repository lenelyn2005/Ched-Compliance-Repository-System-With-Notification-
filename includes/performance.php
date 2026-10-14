<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// Central per-response CSP nonce + policy (include-only).
require_once __DIR__ . '/csp.php';
/**
 * CHED Compliance Document Repository System – Performance & Security Module
 * 
 * @version 2.1
 * @author  System Refactor
 * @license MIT
 */

// ============================================================================
//  Configuration (Lazy Define)
// ============================================================================

if (!defined('PERF_CACHE_DIR')) {
    define('PERF_CACHE_DIR', dirname(__DIR__) . '/system/storage/cache');
}
if (!defined('PERF_CACHE_TTL')) {
    define('PERF_CACHE_TTL', 3600);
}

// ============================================================================
//  HTTP Headers & Compression
// ============================================================================

$perf_security_headers_sent = false;

function perf_headers(): void
{
    global $perf_security_headers_sent;
    if ($perf_security_headers_sent || PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $perf_security_headers_sent = true;
    
    // Per-response nonce CSP (no 'unsafe-inline' for scripts). Report-Only until
    // CSP_ENFORCE=true in .env; see includes/csp.php.
    header(csp_header_name() . ': ' . csp_header_value());
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}

function perf_gzip_start(): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || ob_get_level() > 0) {
        return;
    }

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        return;
    }
    
    if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
        ini_set('zlib.output_compression', 'On');
        ini_set('zlib.output_compression_level', '5');
        ob_start('ob_gzhandler');
    }
}

// ============================================================================
//  Database (Lazy PDO Connection + Optimized Queries)
// ============================================================================

$perf_pdo_instance = null;

function perf_db(): ?PDO
{
    global $perf_pdo_instance;
    
    if ($perf_pdo_instance !== null) {
        return $perf_pdo_instance;
    }
    
    if (!defined('DB_HOST') || !defined('DB_NAME')) {
        return null;
    }

    // Fail closed: no root fallback, no empty-password connection.
    $dbUser = defined('DB_USER') ? (string)DB_USER : '';
    $dbPass = defined('DB_PASS') ? (string)DB_PASS : '';
    if ($dbUser === '' || $dbUser === 'root' || $dbPass === '') {
        error_log('[Performance] Refusing insecure DB credentials (user must be a dedicated non-root account with a password).');
        return null;
    }

    try {
        $perf_pdo_instance = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4;collation=utf8mb4_unicode_ci',
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_TIMEOUT            => 5
            ]
        );
    } catch (PDOException $e) {
        error_log('[Performance] DB connection failed: ' . $e->getMessage());
        return null;
    }
    
    return $perf_pdo_instance;
}

function db_query(string $sql, array $params = [], int $ttl = 300): array|false
{
    $db = perf_db();
    if (!$db) {
        return false;
    }
    
    $isSelect = stripos(trim($sql), 'SELECT') === 0;
    if ($isSelect && $ttl > 0 && cache_available()) {
        $cacheKey = 'db_' . md5($sql . json_encode($params));
        $cached = cache_get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
        
        if ($isSelect && $ttl > 0 && $result !== false && cache_available()) {
            cache_set($cacheKey, $result, $ttl);
        }
        return $result;
    } catch (PDOException $e) {
        error_log('[Performance] Query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return false;
    }
}

// ============================================================================
//  Caching System (Lazy Init + Atomic JSON + DirectoryIterator)
// ============================================================================

$perf_cache_initialized = null;
$perf_cache_available = null;

function cache_init(): bool
{
    global $perf_cache_initialized, $perf_cache_available;
    
    if ($perf_cache_initialized !== null) {
        return $perf_cache_available;
    }
    
    $perf_cache_initialized = true;
    
    if (!is_dir(PERF_CACHE_DIR)) {
        $created = @mkdir(PERF_CACHE_DIR, 0755, true);
        if (!$created) {
            $perf_cache_available = false;
            return false;
        }
    }
    
    $htaccess = PERF_CACHE_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n");
    }
    
    $perf_cache_available = true;

    // Probabilistic GC (~1 in 20 requests): purge expired files so the dir
    // never needs manual cleanup or a cron job.
    if (random_int(1, 20) === 1) {
        cache_gc();
    }

    return true;
}

function cache_gc(): void
{
    $now = time();
    foreach (new FilesystemIterator(PERF_CACHE_DIR) as $f) {
        if ($f->getExtension() !== 'json') {
            continue;
        }
        $data = json_decode((string)@file_get_contents($f->getPathname()), true);
        // Unlink expired or corrupt entries (same rule as cache_get).
        if (!is_array($data) || !isset($data['expiry']) || $data['expiry'] < $now) {
            @unlink($f->getPathname());
        }
    }
}

function cache_available(): bool
{
    global $perf_cache_available;
    
    if ($perf_cache_available === null) {
        cache_init();
    }
    
    return $perf_cache_available;
}

function cache_set(string $key, $value, int $ttl = PERF_CACHE_TTL): bool
{
    if (!cache_available()) {
        return false;
    }
    
    $file = PERF_CACHE_DIR . '/' . md5($key) . '.json';
    $temp = $file . '.' . uniqid('', true) . '.tmp';
    
    $data = [
        'expiry' => time() + $ttl,
        'value'  => $value
    ];
    
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        error_log('[Performance] Cache encode failed for key: ' . $key);
        return false;
    }
    
    if (@file_put_contents($temp, $encoded, LOCK_EX) !== false) {
        if (@rename($temp, $file)) {
            @chmod($file, 0644);
            return true;
        }
        @unlink($temp);
    }
    
    return false;
}

function cache_get(string $key, $default = null)
{
    if (!cache_available()) {
        return $default;
    }
    
    $file = PERF_CACHE_DIR . '/' . md5($key) . '.json';
    
    if (!is_file($file)) {
        return $default;
    }
    
    $content = @file_get_contents($file);
    if ($content === false || $content === '') {
        return $default;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['expiry'], $data['value'])) {
        @unlink($file);
        return $default;
    }
    
    if ($data['expiry'] < time()) {
        @unlink($file);
        return $default;
    }
    
    return $data['value'];
}