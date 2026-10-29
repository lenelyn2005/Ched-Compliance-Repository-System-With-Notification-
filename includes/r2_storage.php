<?php
/**
 * Centralized document storage service — Cloudflare R2 via the AWS S3 SDK.
 * CHED Compliance Repository. Plan: R2_STORAGE_IMPLEMENTATION_PLAN.md §7.
 *
 * Stage 2: declarations only. Call sites are wired in later stages; until the
 * driver switch routes them here, application behavior is unchanged.
 *
 * Error contract (§7.1) — callers branch on the exception class, never on raw
 * HTTP status codes:
 *   StorageObjectNotFound  genuine 404 — the ONLY class that may trigger
 *                          "missing document" logic (row deletion, filters).
 *   StorageAuthError       invalid/missing credentials (401, signature, token).
 *   StoragePermissionError 403 AccessDenied (extends AuthError) — an object may
 *                          EXIST; never treat as missing.
 *   StorageThrottled       429 / SlowDown / 503 — retry with backoff.
 *   StorageTimeoutError    connect/read timeout (extends Unavailable).
 *   StorageServerError     other 5xx (extends Unavailable).
 *   StorageUnavailable     connection refused / DNS / endpoint problems.
 *   StorageUnknownError    unclassifiable (extends Unavailable — the fail-safe
 *                          default: operations fail closed, no row deletion).
 * Production callers must not echo exception messages to end users and must
 * log only sanitized summaries (class + aws code + status), never full
 * exception dumps (§8).
 *
 * ETags are deliberately NOT exposed: an S3/R2 ETag is not a content hash for
 * multipart or server-side copies. Use the SHA-256 helpers instead.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------------
// Storage exceptions (§7.1). Hierarchy keeps family catches working while
// giving each distinct failure mode its own type.
// ---------------------------------------------------------------------------
class StorageException extends \Exception {}
class StorageObjectNotFound extends StorageException {}
class StorageAuthError extends StorageException {}
class StoragePermissionError extends StorageAuthError {}
class StorageThrottled extends StorageException {}
class StorageUnavailable extends StorageException {}
class StorageTimeoutError extends StorageUnavailable {}
class StorageServerError extends StorageUnavailable {}
class StorageUnknownError extends StorageUnavailable {}

final class R2Storage
{
    private ?\Aws\S3\S3Client $client = null;

    /** Optional injected client (tests); production uses default() from env. */
    public function __construct(?\Aws\S3\S3Client $client = null)
    {
        $this->client = $client;
    }

    public static function default(): self
    {
        static $instance = null;
        return $instance ??= new self();
    }

    // ------------------------------------------------------------------
    // Configuration (environment only — never hardcoded, never logged)
    // ------------------------------------------------------------------

    private static function env(string $key): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return (string) $v;
        }
        return $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    /** @return array{endpoint:string,region:string,bucket:string,key:string,secret:string} */
    private function config(): array
    {
        $accountId = trim((string) (self::env('R2_ACCOUNT_ID') ?? ''));
        $endpoint  = trim((string) (self::env('R2_ENDPOINT') ?? ''));
        if ($endpoint === '' && $accountId !== '') {
            $endpoint = 'https://' . $accountId . '.r2.cloudflarestorage.com';
        }
        return [
            'endpoint' => $endpoint,
            'region'   => trim((string) (self::env('R2_REGION') ?? '')) ?: 'auto',
            'bucket'   => trim((string) (self::env('R2_BUCKET_NAME') ?? '')),
            'key'      => trim((string) (self::env('R2_ACCESS_KEY_ID') ?? '')),
            'secret'   => (string) (self::env('R2_SECRET_ACCESS_KEY') ?? ''),
        ];
    }

    private function client(): \Aws\S3\S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $cfg = $this->config();
        $missing = [];
        if ($cfg['endpoint'] === '') { $missing[] = 'R2_ENDPOINT (or R2_ACCOUNT_ID)'; }
        if ($cfg['bucket']   === '') { $missing[] = 'R2_BUCKET_NAME'; }
        if ($cfg['key']      === '') { $missing[] = 'R2_ACCESS_KEY_ID'; }
        if ($cfg['secret']   === '') { $missing[] = 'R2_SECRET_ACCESS_KEY'; }
        if ($missing) {
            // Variable names only — never values.
            throw new StorageAuthError('R2 configuration incomplete — missing environment variables: ' . implode(', ', $missing));
        }
        // Autoload the SDK lazily: local-mode requests never pay for it.
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $this->client = new \Aws\S3\S3Client([
            'version'                 => 'latest',
            'region'                  => $cfg['region'],
            'endpoint'                => $cfg['endpoint'],
            'use_path_style_endpoint' => true,   // R2-recommended; safe for dotted bucket names
            'signature_version'       => 'v4',
            'credentials'             => ['key' => $cfg['key'], 'secret' => $cfg['secret']],
            'http'                    => ['connect_timeout' => 5, 'timeout' => 60], // §7.1
        ]);
        return $this->client;
    }

    private function bucket(): string
    {
        $b = trim((string) (self::env('R2_BUCKET_NAME') ?? ''));
        if ($b === '') {
            throw new StorageAuthError('R2 configuration incomplete — missing environment variables: R2_BUCKET_NAME');
        }
        return $b;
    }

    // ------------------------------------------------------------------
    // Error translation (§7.1) — public for unit tests
    // ------------------------------------------------------------------

    public static function toStorageError(\Throwable $e): StorageException
    {
        if ($e instanceof StorageException) {
            return $e;
        }

        $status = null;
        $code   = null;
        $raw    = '';
        if ($e instanceof \Aws\Exception\AwsException) {
            $code = $e->getAwsErrorCode();
            $raw  = (string) $e->getAwsErrorMessage();
            $resp = $e->getResponse();
            if (is_object($resp) && method_exists($resp, 'getStatusCode')) {
                $status = (int) $resp->getStatusCode();
            }
        }
        if ($raw === '') {
            $raw = (string) $e->getMessage();
        }
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            $raw .= ' ' . $p->getMessage();
        }

        // Sanitized message: drop URLs (endpoint embeds the account id), keep
        // aws code, HTTP status and any cURL errno; truncate defensively.
        $curl = preg_match('/cURL error \d+/', $raw, $m) ? $m[0] : '';
        $msg  = trim(sprintf(
            '[%s]%s%s%s — %s',
            get_class($e),
            $code !== null ? " aws={$code}" : '',
            $status !== null ? " http={$status}" : '',
            $curl !== '' ? " {$curl}" : '',
            mb_substr(preg_replace('/https?:\/\/\S+/', '<endpoint>', preg_replace('/\s+/', ' ', $raw)), 0, 200)
        ));

        // 1) Genuine not-found — positive identification only (§7.1 rule b).
        if ($status === 404 || in_array($code, ['NoSuchKey', 'NotFound', 'ResourceNotFoundException'], true)) {
            return new StorageObjectNotFound($msg, 0, $e);
        }
        // 2) Credential problems (401 / signature / token) — checked before the
        //    generic 403 branch because SignatureDoesNotMatch arrives as 403.
        if ($status === 401 || in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidToken', 'ExpiredToken', 'TokenRefreshRequired', 'AuthFailure'], true)) {
            return new StorageAuthError($msg, 0, $e);
        }
        // 3) Permission denial — 403. An object may exist behind a 403; this
        //    type exists precisely so it can never be mistaken for missing.
        if ($status === 403 || in_array($code, ['AccessDenied', 'InvalidAccessDenied', 'AccessForbidden', 'AllAccessDisabled'], true)) {
            return new StoragePermissionError($msg, 0, $e);
        }
        // 4) Throttling (§7.1: 429 / SlowDown / 503).
        if ($status === 429 || $status === 503 || in_array($code, ['SlowDown', 'Throttling', 'ThrottlingException', 'RequestLimitExceeded'], true)) {
            return new StorageThrottled($msg, 0, $e);
        }
        // 5) Timeout vs. other transport trouble.
        if (preg_match('/timed? ?out|timeout|cURL error 28/i', $raw)) {
            return new StorageTimeoutError($msg, 0, $e);
        }
        if (preg_match('/cURL error (6|7|20|35|52|56)|Could not resolve|Connection (refused|reset|aborted)|getaddrinfo|EndpointConnection|SSL|certificate/i', $raw)) {
            return new StorageUnavailable($msg, 0, $e);
        }
        // 6) Remaining server-side failures.
        if ($status !== null && $status >= 500) {
            return new StorageServerError($msg, 0, $e);
        }
        // 7) Unclassifiable → fail-safe family (never "not found").
        return new StorageUnknownError($msg, 0, $e);
    }

    // ------------------------------------------------------------------
    // Key validation (§7.1 objectKeyFor, §8 traversal defense)
    // ------------------------------------------------------------------

    /**
     * The ten managed top-level prefixes. This mirrors the real local tree
     * C:\ched_secure_storage\documents\ exactly: object keys are DB file_path
     * values, so replicating that structure means every key's first segment is
     * one of these and nothing else.
     *
     * Note on R2 semantics: R2 has no real folders — a "folder" in the console is
     * just the text before the first '/' in a key. A prefix with no objects under
     * it therefore cannot exist in the bucket at all (currently pending_admin/,
     * pending_program_head/ and ph_requirements/ hold no files). They stay in
     * this list so legacy rows are never blocked; they are NOT created as
     * placeholder objects, which would invent structure and pollute the bucket.
     */
    public const ROOT_PREFIXES = [
        'admin_archive',
        'admin_repository',
        'pending',
        'pending_admin',
        'pending_program_head',
        'ph_direct',
        'ph_repository',
        'ph_requirements',
        'rejected',
        'templates',
    ];

    /**
     * Validate a DB-derived relative path used as an object key.
     * Throws InvalidArgumentException on anything unsafe — a rejected key is a
     * data/programming fault and must fail loudly, never be sanitized silently.
     */
    public static function objectKeyFor(?string $filePath): string
    {
        $key = (string) $filePath;
        if ($key === '') {
            throw new InvalidArgumentException('storage key must not be empty');
        }
        if (strlen($key) > 1024) {
            throw new InvalidArgumentException('storage key exceeds 1024 bytes');
        }
        if (strpos($key, "\0") !== false) {
            throw new InvalidArgumentException('storage key contains NUL byte');
        }
        if (strpos($key, '\\') !== false) {
            throw new InvalidArgumentException('storage key contains backslash');
        }
        if ($key[0] === '/') {
            throw new InvalidArgumentException('storage key must be relative (no leading slash)');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('storage key contains empty/dot/traversal segment');
            }
        }
        // Structure contract: every document key lives under one of the ten
        // managed roots. A foreign root means the path was built outside
        // getFileStorageDir()/storageSubdir() and would strand an object where
        // no code path can ever find it again.
        $root = explode('/', $key, 2)[0];
        if (!in_array($root, self::ROOT_PREFIXES, true)) {
            throw new InvalidArgumentException('storage key root "' . $root . '" is not a managed prefix');
        }
        return $key;
    }

    // ------------------------------------------------------------------
    // Object operations (§7.1)
    // ------------------------------------------------------------------

    /**
     * Upload with post-put verification (§7.5 rule 1: an upload is only
     * "successful" once the object is confirmed to exist with the right size).
     * $body: string contents or an open resource (streamed — not buffered).
     * Deliberately untyped: PHP has no `resource` parameter type, so
     * `string|\resource` would resolve to "string or class named resource" and
     * reject every real stream — the contract is enforced explicitly instead.
     */
    public function put(string $key, $body, string $mime, array $meta = []): void
    {
        if (!is_string($body) && !is_resource($body)) {
            throw new InvalidArgumentException('R2Storage::put() body must be a string or a stream resource');
        }
        $key = self::objectKeyFor($key);
        $expectedSize = null;
        if (is_string($body)) {
            $expectedSize = strlen($body);
        } elseif (is_resource($body)) {
            $st = @fstat($body);
            if (is_array($st) && isset($st['size'])) {
                $pos = @ftell($body);
                $expectedSize = $st['size'] - ($pos === false ? 0 : $pos);
            }
        }
        try {
            $params = [
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'Body' => $body,
                'ContentType' => $mime,
            ];
            if ($meta !== []) {
                // S3 metadata values must be strings.
                $params['Metadata'] = array_map('strval', $meta);
            }
            $this->client()->putObject($params);
            $info = $this->head($key); // verify — never trust the put response alone
            if ($info === null) {
                throw new StorageUnknownError("put verification failed: object vanished after upload ({$key})");
            }
            if ($expectedSize !== null && $info['size'] !== $expectedSize) {
                throw new StorageUnknownError("put verification failed: size mismatch expected={$expectedSize} got={$info['size']} ({$key})");
            }
        } catch (\Throwable $e) {
            throw self::toStorageError($e);
        }
    }

    /** Metadata for a key, or null on a genuine 404. Throws typed on any other failure. */
    public function head(string $key): ?array
    {
        $key = self::objectKeyFor($key);
        try {
            $r = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            return [
                'size'         => (int) ($r['ContentLength'] ?? 0),
                'content_type' => (string) ($r['ContentType'] ?? ''),
                'metadata'     => (array) ($r['Metadata'] ?? []),
            ];
        } catch (\Throwable $e) {
            $s = self::toStorageError($e);
            if ($s instanceof StorageObjectNotFound) {
                return null;
            }
            throw $s;
        }
    }

    public function exists(string $key): bool
    {
        return $this->head($key) !== null;
    }

    /**
     * Bucket-level probe (health check, §9): proves endpoint reachability and
     * credential acceptance without touching any document object.
     * Returns nothing on success; throws typed on failure.
     */
    public function headBucket(): void
    {
        try {
            $this->client()->headBucket(['Bucket' => $this->bucket()]);
        } catch (\Throwable $e) {
            $s = self::toStorageError($e);
            if ($s instanceof StorageObjectNotFound) {
                // HeadBucket reports 404 for "bucket does not exist" — that is a
                // configuration failure here, not a missing document.
                throw new StorageAuthError('R2 bucket not found or not accessible: ' . $this->bucket(), 0, $e);
            }
            throw $s;
        }
    }

    /**
     * Streaming body for proxying to the client (§7.1). Read with
     * ->read()/->eof() loops; do NOT ->getContents() large objects (memory).
     */
    public function getStream(string $key): \Psr\Http\Message\StreamInterface
    {
        $key = self::objectKeyFor($key);
        try {
            $r = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            return $r['Body'];
        } catch (\Throwable $e) {
            throw self::toStorageError($e);
        }
    }

    /**
     * Stage an object to a local temp file (previews/ZIPs, §7.2). The
     * destination is removed on any failure — never left as a partial file.
     */
    public function downloadTo(string $key, string $destPath): void
    {
        $body = $this->getStream($key);
        $fh = @fopen($destPath, 'wb');
        if ($fh === false) {
            $body->close();
            throw new StorageUnknownError('cannot open staging file for writing: ' . $destPath);
        }
        try {
            $src = $body->detach();
            if (!is_resource($src)) {
                throw new StorageUnknownError('staging stream detached unexpectedly');
            }
            $copied = stream_copy_to_stream($src, $fh);
            if ($copied === false) {
                throw new StorageUnknownError('staging stream copy failed');
            }
        } catch (\Throwable $e) {
            throw self::toStorageError($e);
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
            $body->close();
            if (isset($e) && $e instanceof \Throwable && is_file($destPath)) {
                @unlink($destPath); // no partial staging files survive a failure
            }
        }
    }

    /** Server-side copy (never downloads). */
    public function copyTo(string $srcKey, string $dstKey): void
    {
        $src = self::objectKeyFor($srcKey);
        $dst = self::objectKeyFor($dstKey);
        try {
            $this->client()->copyObject([
                'Bucket' => $this->bucket(),
                'Key' => $dst,
                'CopySource' => $this->bucket() . '/' . implode('/', array_map('rawurlencode', explode('/', $src))),
            ]);
        } catch (\Throwable $e) {
            throw self::toStorageError($e);
        }
    }

    /** Idempotent delete: a genuine 404 counts as success; all other failures throw typed. */
    public function delete(string $key): void
    {
        $key = self::objectKeyFor($key);
        try {
            $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        } catch (\Throwable $e) {
            $s = self::toStorageError($e);
            if ($s instanceof StorageObjectNotFound) {
                return;
            }
            throw $s;
        }
    }

    /** Paginated key listing — migration/verification only, never for UI rendering (§7.2). */
    public function listKeys(string $prefix = ''): \Generator
    {
        $params = ['Bucket' => $this->bucket()];
        if ($prefix !== '') {
            $params['Prefix'] = $prefix;
        }
        do {
            try {
                $r = $this->client()->listObjectsV2($params);
            } catch (\Throwable $e) {
                throw self::toStorageError($e);
            }
            foreach (($r['Contents'] ?? []) as $obj) {
                yield (string) $obj['Key'];
            }
            $token = !empty($r['IsTruncated']) ? (string) ($r['NextContinuationToken'] ?? '') : '';
            if ($token !== '') {
                $params['ContinuationToken'] = $token;
            }
        } while ($token !== '');
    }

    // ------------------------------------------------------------------
    // SHA-256 integrity (§7.1 ETag caveat — ETags are never content hashes)
    // ------------------------------------------------------------------

    /** @param resource|\Psr\Http\Message\StreamInterface $stream */
    public static function sha256Stream($stream): string
    {
        if ($stream instanceof \Psr\Http\Message\StreamInterface) {
            $ctx = hash_init('sha256');
            while (!$stream->eof()) {
                $chunk = $stream->read(1024 * 1024);
                if ($chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
            }
            return hash_final($ctx);
        }
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('sha256Stream expects a resource or PSR-7 stream');
        }
        return hash('sha256', stream_get_contents($stream) ?: '');
    }

    /** Full-stream re-computation compared against an expected SHA-256 hex digest. */
    public function verifySha256(string $key, string $expectedSha256): bool
    {
        $body = $this->getStream($key);
        try {
            $actual = self::sha256Stream($body);
        } finally {
            $body->close();
        }
        return hash_equals(strtolower($expectedSha256), strtolower($actual));
    }
}