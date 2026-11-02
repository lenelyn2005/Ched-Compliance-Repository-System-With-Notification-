<?php
/**
 * Storage fixture CLI for the test suites — reads/writes through whichever
 * driver .env selects, so a suite can seed bytes and assert existence without
 * assuming the document lives on local disk.
 *
 *   php scripts/storage_cli.php driver            -> "r2" | "local"
 *   php scripts/storage_cli.php exists <relKey>   -> "EXISTS" | "MISSING" | "UNAVAILABLE"
 *   php scripts/storage_cli.php put <srcFile> <relKey> -> "PUT <relKey>"
 *   php scripts/storage_cli.php del <relKey>      -> "DEL <relKey>"
 *
 * Always exits 0 (the suites parse stdout, not exit codes) so a non-zero
 * status can never be mistaken for a test failure. Never prints credentials.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/config.php';

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'driver':
        echo STORAGE_DRIVER, "\n";
        break;

    case 'exists':
        $rel = (string) ($argv[2] ?? '');
        if ($rel === '') {
            echo "MISSING\n";
            break;
        }
        try {
            echo storageObjectExists($rel) ? "EXISTS\n" : "MISSING\n";
        } catch (StorageException $e) {
            // Outage must never look like "the document is gone".
            echo 'UNAVAILABLE ' . get_class($e) . "\n";
        }
        break;

    case 'put':
        $src = (string) ($argv[2] ?? '');
        $rel = str_replace('\\', '/', trim((string) ($argv[3] ?? ''), '/'));
        if ($rel === '' || !is_file($src)) {
            echo "PUT-FAILED bad-args\n";
            break;
        }
        if (STORAGE_DRIVER === 'r2') {
            try {
                R2Storage::objectKeyFor($rel);
                $fh = @fopen($src, 'rb');
                if ($fh === false) {
                    echo "PUT-FAILED open\n";
                    break;
                }
                try {
                    R2Storage::default()->put($rel, $fh, 'application/octet-stream', ['sha256' => hash_file('sha256', $src), 'source' => 'test_fixture']);
                } finally {
                    if (is_resource($fh)) {
                        fclose($fh);
                    }
                }
                echo "PUT $rel\n";
            } catch (Throwable $e) {
                echo 'PUT-FAILED ' . get_class($e) . "\n";
            }
        } else {
            $dst = rtrim(UPLOAD_PATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir(dirname($dst))) {
                @mkdir(dirname($dst), 0755, true);
            }
            echo (@copy($src, $dst) ? "PUT $rel\n" : "PUT-FAILED copy\n");
        }
        break;

    case 'del':
        $rel = str_replace('\\', '/', trim((string) ($argv[2] ?? ''), '/'));
        if ($rel === '') {
            echo "DEL noop\n";
            break;
        }
        if (STORAGE_DRIVER === 'r2') {
            try {
                R2Storage::default()->delete($rel); // idempotent: a 404 already counts as deleted
                echo "DEL $rel\n";
            } catch (StorageException $e) {
                echo 'DEL-FAILED ' . get_class($e) . "\n";
            }
        } else {
            $abs = securePath($rel);
            if ($abs && is_file($abs)) {
                @unlink($abs);
            }
            echo "DEL $rel\n";
        }
        break;

    case 'list':
        // Prints every stored path whose full key contains $argv[2] (case-insensitive).
        $needle = strtolower((string) ($argv[2] ?? ''));
        if (STORAGE_DRIVER === 'r2') {
            foreach (R2Storage::default()->listKeys() as $k) {
                if ($needle === '' || str_contains(strtolower($k), $needle)) {
                    echo $k, "\n";
                }
            }
        } else {
            $base = rtrim(UPLOAD_PATH, '\\/') . '/';
            $stack = [''];
            while ($stack) {
                $dir = array_pop($stack);
                $abs = $dir === '' ? rtrim(UPLOAD_PATH, '\\/') : str_replace('/', DIRECTORY_SEPARATOR, rtrim(UPLOAD_PATH, '\\/') . '/' . $dir);
                foreach (scandir($abs) ?: [] as $e) {
                    if ($e === '.' || $e === '..') {
                        continue;
                    }
                    $rel = $dir === '' ? $e : $dir . '/' . $e;
                    if (is_dir($abs . DIRECTORY_SEPARATOR . $e)) {
                        $stack[] = $rel;
                    } elseif ($needle === '' || str_contains(strtolower($rel), $needle)) {
                        echo $rel, "\n";
                    }
                }
            }
        }
        break;

    case 'sweep':
        // Runs the ghost-document sweep and reports how many rows it removed.
        // During an outage this must always be 0 (plan §9).
        try {
            $res = cleanupMissingFiles($pdo);
            echo 'deleted=', (int) ($res['documents_deleted'] ?? 0) + (int) ($res['ph_documents_deleted'] ?? 0), "\n";
        } catch (Throwable $e) {
            echo 'sweep-aborted ', get_class($e), "\n";
        }
        break;

    default:
        echo "usage: storage_cli.php driver|exists|put|del|list|sweep\n";
}
exit(0);
