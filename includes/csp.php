<?php
/**
 * CHED Compliance Repository - CSP nonce helper (include-only).
 *
 * Generates ONE cryptographically-secure nonce per HTTP response and exposes
 * the Content-Security-Policy value built around it. Inline <script> elements
 * echo the SAME nonce via csp_nonce(). Inline event-handler attributes
 * (onclick=...) are NOT covered by a script nonce and must be migrated to
 * addEventListener (see includes/csp-events.js).
 *
 * Modes:
 *   - Report-Only (default): the header is sent as
 *     `Content-Security-Policy-Report-Only`, so violations are reported but
 *     nothing is blocked. Used to stage the migration safely.
 *   - Enforce: set CSP_ENFORCE=true in .env. Then the strict policy is emitted
 *     as `Content-Security-Policy` and 'unsafe-inline' is gone from script-src.
 *
 * The nonce is request-scoped (PHP process per request) and never logged.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

if (!defined('CHED_CSP_NONCE')) {
    define('CSP_NONCE_BYTES', 16);
    define('CSP_NONCE', rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='));
}

/** The per-response nonce. Echo into permitted inline <script nonce="...">. */
function csp_nonce(): string
{
    return CSP_NONCE;
}

/** Returns true when the strict policy should be enforced (not Report-Only). */
function csp_enforced(): bool
{
    return filter_var(getenv('CSP_ENFORCE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
}

/**
 * Build the Content-Security-Policy directive list.
 *
 * script-src uses a per-response nonce and NO 'unsafe-inline'. External script
 * hosts (pdf.js on cdnjs, chart.js on jsdelivr) are allow-listed because
 * nonces do not apply to cross-origin scripts.
 */
function csp_directives(): array
{
    $nonce = csp_nonce();
    $wsHost = getenv('WS_HOST') ?: 'localhost';
    $wsPort = (getenv('WS_PORT') ?: '8080');
    // shortcut: allows both schemes for configured endpoint; upgrade when endpoint is HTTPS-only.
    $wsDev = 'ws://' . $wsHost . ':' . $wsPort;
    $wsProd = 'wss://' . $wsHost . ':' . $wsPort;
    // Same-origin WebSocket behind a proxy path (Render): allow wss://APP_HOST.
    $appHost = trim((string) (getenv('APP_HOST') ?: ''));
    if ((getenv('WS_PUBLIC_PATH') ?: '') !== '' && $appHost !== '') {
        $wsDev = 'wss://' . $appHost;
        $wsProd = 'wss://' . $appHost;
    }
    return [
        "default-src 'self'",
        "script-src 'self' 'nonce-$nonce' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.googleapis.com",
        "font-src 'self' data: https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "worker-src 'self' blob: https://cdnjs.cloudflare.com",
        "connect-src 'self' https://cdn.jsdelivr.net $wsDev $wsProd",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ];
}

function csp_header_value(): string
{
    return implode('; ', csp_directives()) . ';';
}

function csp_header_name(): string
{
    return csp_enforced() ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
}
