<?php
/**
 * R2 connectivity / health probe (plan §9 "r2_health_check.php").
 *
 * Checks: 1) HeadBucket, 2) listing, 3) head + streaming SHA-256 of an
 * existing managed object. Read-only — it never writes to the document
 * bucket (write/delete round-trip lives in scripts/r2_prefix_test.php).
 *
 * Prints statuses and exception class names ONLY — never credentials,
 * never signed URLs, never full exception dumps (plan §8).
 *
 * Exit codes: 0 = healthy, 1 = operational storage failure (throttled /
 * unavailable / server error), 2 = configuration problem (missing R2_* vars,
 * wrong bucket, rejected credentials).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../includes/env_loader.php';
loadEnv(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../includes/r2_storage.php';

$redacted = static function (StorageException $e): string {
    // Class + (already-sanitized) message; R2Storage::toStorageError strips
    // URLs, and config errors carry variable names only.
    return get_class($e) . ': ' . $e->getMessage();
};

try {
    $r2 = R2Storage::default();

    // --- 1) HeadBucket (bucket-level read) ---
    $r2->headBucket();
    echo "[OK] HeadBucket (bucket reachable, credentials accepted)\n";
} catch (StorageAuthError $e) {
    $msg = $e->getMessage();
    $notConfigured = str_contains($msg, 'missing environment variables');
    echo '[' . ($notConfigured ? 'SKIP' : 'FAIL') . '] ' . $redacted($e) . "\n";
    if ($notConfigured) {
        echo "Result: NOT CONFIGURED — set the R2_* variables in .env (names in .env.example) and re-run.\n";
    } elseif (str_contains($msg, 'bucket not found')) {
        echo "Result: CONFIG ERROR — R2_BUCKET_NAME wrong or the bucket does not exist.\n";
    } else {
        echo "Result: AUTH FAILURE — check R2_ACCESS_KEY_ID / R2_SECRET_ACCESS_KEY / R2_ACCOUNT_ID.\n";
    }
    exit(2);
} catch (StorageException $e) {
    echo '[FAIL] ' . $redacted($e) . "\n";
    echo 'Result: UNHEALTHY — bucket unreachable or credentials lack Object Read&Write on this bucket. Class: ' . get_class($e) . "\n";
    exit(1);
}

// --- 2) listing + 3) read + SHA-256 of a real managed object ---
// Read-only by design: the structure contract allows only the 10 document
// prefixes, and a periodic probe must not write anything that looks like a
// document. Write/delete permission is covered by scripts/r2_prefix_test.php,
// which cleans up after itself.
$exitCode = 0;

try {
    $keys = iterator_to_array($r2->listKeys(), false);
    echo '[OK] list (' . count($keys) . " object(s))\n";

    if (!$keys) {
        echo "[OK] bucket empty — nothing to read-probe (still reachable)\n";
    } else {
        sort($keys);
        $key = $keys[0];
        $info = $r2->head($key);
        if ($info === null) {
            throw new StorageUnknownError('head failed for an object returned by list');
        }
        echo "[OK] head ({$key}, " . (int) $info['size'] . " bytes)\n";

        $stream = $r2->getStream($key);
        $size = (int) $info['size'];
        $actual = hash('sha256', '');
        $hash = hash_init('sha256');
        $read = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(1048576);
            if ($chunk === '' || $chunk === null) {
                break;
            }
            hash_update($hash, $chunk);
            $read += strlen($chunk);
        }
        $stream->close();
        $actual = hash_final($hash);
        if ($read !== $size) {
            throw new StorageUnknownError("read $read bytes, object is $size bytes — truncated transfer");
        }
        echo "[OK] get + SHA-256 ($read bytes read cleanly)\n";
    }
} catch (StorageException $e) {
    echo '[FAIL] ' . $redacted($e) . "\n";
    $exitCode = 1;
}

echo $exitCode === 0
    ? "Result: HEALTHY — bucket listed and object read verified.\n"
    : "Result: UNHEALTHY — see failure class above.\n";
exit($exitCode);
