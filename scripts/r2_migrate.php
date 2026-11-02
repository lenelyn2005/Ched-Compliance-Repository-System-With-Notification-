<?php
/**
 * R2 migration tool — uploads C:\ched_secure_storage\documents\ to the private
 * Cloudflare R2 bucket under object keys that are the DB file_path values,
 * verbatim (plan §8). Never deletes or moves a local file.
 *
 * Usage (CLI only):
 *   php scripts/r2_migrate.php                 # dry run — report only, no writes
 *   php scripts/r2_migrate.php --apply         # upload, then verify every object
 *   php scripts/r2_migrate.php --verify        # re-verify already-migrated objects
 *   php scripts/r2_migrate.php --reconcile     # bucket keys vs DB paths vs ledger
 *
 * Options:
 *   --force            upload even when the DB hash disagrees with local bytes
 *   --backfill         write the computed SHA-256 into NULL file_hash columns
 *   --include-orphans  also upload files no DB row references (off by default)
 *
 * Integrity policy: SHA-256 everywhere. Every upload is verified by re-reading
 * the object from the bucket and recomputing its hash — size alone is never
 * accepted as proof (item 3 of the owner's instructions).
 *
 * Never prints R2 credentials. Exit 0 = clean, 1 = blockers/failures.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/config.php';

$opts = getopt('', ['dry-run', 'apply', 'verify', 'reconcile', 'force', 'backfill', 'include-orphans']);
$mode = isset($opts['reconcile']) ? 'reconcile'
    : (isset($opts['apply']) ? 'apply'
        : (isset($opts['verify']) ? 'verify' : 'dry-run'));
$force     = isset($opts['force']);
$backfill  = isset($opts['backfill']);
$withOrph  = isset($opts['include-orphans']);

$LEDGER = __DIR__ . '/storage/r2_migration_ledger.jsonl';
$TABLES = ['documents', 'ph_documents', 'ph_repository', 'ph_requirements'];
// Folders that are never document storage (item: no .htaccess, no preview cache).
$EXCLUDE_TOP = ['temp'];

$t0 = microtime(true);
printf("R2 migration — mode=%s%s\n", $mode, $force ? ' --force' : '');
printf("started: %s\n\n", date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function ledgerLoad(string $path): array
{
    $out = [];
    if (!is_file($path)) {
        return $out;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rec = json_decode($line, true);
        if (is_array($rec) && isset($rec['key'])) {
            $out[$rec['key']] = $rec; // last write wins
        }
    }
    return $out;
}

function ledgerAppend(string $path, array $rec): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
}

function mimeFor(string $abs, string $ext): string
{
    $map = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'txt' => 'text/plain',
    ];
    if (isset($map[$ext])) {
        return $map[$ext];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = @finfo->file($abs);
    return $mime ?: 'application/octet-stream';
}

/** Recompute an object's SHA-256 by streaming it back out of the bucket. */
function objectSha256(string $key): ?string
{
    try {
        return R2Storage::sha256Stream(R2Storage::default()->getStream($key));
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// inventory — every distinct file_path across the four tables
// ---------------------------------------------------------------------------

echo "== inventory ==\n";
$refs = [];      // path => [ ['table'=>, 'id'=>, 'hash'=>?string], ... ]
$rowsTotal = 0;
foreach ($TABLES as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    $hasHash = in_array('file_hash', $cols, true);
    $rows = $pdo->query("SELECT id, file_path" . ($hasHash ? ', file_hash' : '') . " FROM `$t` WHERE file_path IS NOT NULL AND file_path <> ''")->fetchAll();
    $rowsTotal += count($rows);
    foreach ($rows as $r) {
        $rel = str_replace('\\', '/', ltrim((string) $r['file_path'], '/'));
        $refs[$rel][] = ['table' => $t, 'id' => (int) $r['id'], 'hash' => $hasHash ? ($r['file_hash'] ?: null) : null];
    }
}
printf("rows with file_path: %d across %d tables → %d distinct paths\n", $rowsTotal, count($TABLES), count($refs));

$items = [];
$stats = [
    'READY' => 0, 'MISSING_SOURCE' => 0, 'MISSING_HASH' => 0, 'HASH_MISMATCH' => 0,
    'HASH_CONFLICT_ACROSS_ROWS' => 0, 'DUPLICATE_REFERENCE' => 0, 'BAD_KEY' => 0,
];
$mismatchList = [];
$missingSrc   = [];
$missingHash  = [];
$dupeRefs     = [];
$conflicts    = [];
$badKeys      = [];

foreach ($refs as $rel => $rws) {
    $item = ['path' => $rel, 'refs' => $rws, 'size' => null, 'local_hash' => null, 'db_hash' => null, 'verdict' => 'READY'];

    try {
        R2Storage::objectKeyFor($rel); // structure + traversal contract
    } catch (Throwable $e) {
        $item['verdict'] = 'BAD_KEY';
        $badKeys[] = $rel . ' — ' . $e->getMessage();
        $stats['BAD_KEY']++;
        $items[] = $item;
        continue;
    }

    $abs = securePath($rel);
    if (!$abs || !is_file($abs)) {
        $item['verdict'] = 'MISSING_SOURCE';
        $missingSrc[] = $rel;
        $stats['MISSING_SOURCE']++;
        $items[] = $item;
        continue;
    }
    $item['size']       = (int) filesize($abs);
    $item['local_hash'] = hash_file('sha256', $abs);

    // DB hash: every referencing row must agree.
    $hashes = array_values(array_unique(array_filter(array_map(fn($x) => $x['hash'], $rws))));
    if (count($hashes) > 1) {
        $item['verdict'] = 'HASH_CONFLICT_ACROSS_ROWS';
        $conflicts[$rel] = $hashes;
        $stats['HASH_CONFLICT_ACROSS_ROWS']++;
    } elseif (count($hashes) === 1) {
        $item['db_hash'] = $hashes[0];
        if (!hash_equals($hashes[0], $item['local_hash'])) {
            $item['verdict'] = 'HASH_MISMATCH';
            $mismatchList[$rel] = ['local' => $item['local_hash'], 'db' => $hashes[0]];
            $stats['HASH_MISMATCH']++;
        }
    } else {
        $item['verdict'] = 'MISSING_HASH';
        $missingHash[] = $rel;
        $stats['MISSING_HASH']++;
    }

    if (count($rws) > 1) {
        $stats['DUPLICATE_REFERENCE']++;
        $dupeRefs[$rel] = array_map(fn($x) => $x['table'] . '#' . $x['id'], $rws);
    }
    if ($item['verdict'] === 'READY' || $item['verdict'] === 'MISSING_HASH') {
        $stats['READY']++;
    }
    $items[] = $item;
}

printf("distinct paths: %d | ready: %d | missing source: %d | missing hash: %d | mismatch: %d | hash conflict: %d | duplicate-reference: %d | bad key: %d\n\n",
    count($items), $stats['READY'], $stats['MISSING_SOURCE'], $stats['MISSING_HASH'],
    $stats['HASH_MISMATCH'], $stats['HASH_CONFLICT_ACROSS_ROWS'], $stats['DUPLICATE_REFERENCE'], $stats['BAD_KEY']);

// ---------------------------------------------------------------------------
// local files no row references (orphans) + excluded material
// ---------------------------------------------------------------------------

$referenced = [];
foreach (array_keys($refs) as $p) {
    $referenced[strtolower($p)] = true;
}

$orphans = [];
$excludedHtaccess = [];
$scanRoot = rtrim(UPLOAD_PATH, '\\/');
$stack = [''];
$localFiles = 0;
while ($stack) {
    $relDir = array_pop($stack);
    $absDir = $relDir === '' ? $scanRoot : $scanRoot . '/' . $relDir;
    foreach (scandir($absDir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $rel = $relDir === '' ? $e : $relDir . '/' . $e;
        if (is_dir($absDir . '/' . $e)) {
            if (!in_array(explode('/', $rel, 2)[0], $EXCLUDE_TOP, true)) {
                $stack[] = $rel;
            }
            continue;
        }
        $localFiles++;
        if ($e === '.htaccess') {
            $excludedHtaccess[] = $rel;
            continue;
        }
        if (!isset($referenced[strtolower($rel)])) {
            $orphans[$rel] = (int) filesize($absDir . '/' . $e);
        }
    }
}
printf("local files scanned: %d\n", $localFiles);
printf("excluded .htaccess sentinels: %d (Apache-only, never migrated)\n", count($excludedHtaccess));
printf("excluded preview cache: temp/ (stays server-local)\n");
printf("orphan files (no DB row references them): %d\n", count($orphans));
foreach ($orphans as $p => $s) {
    printf("    %s  (%.2f MB)\n", $p, $s / 1048576);
}

// ---------------------------------------------------------------------------
// reconcile mode — bucket contents vs DB vs ledger
// ---------------------------------------------------------------------------

if ($mode === 'reconcile') {
    echo "\n== reconcile ==\n";
    try {
        $r2 = R2Storage::default();
        $r2->headBucket();
        $bucketKeys = [];
        foreach ($r2->listKeys() as $k) {
            $bucketKeys[$k] = true;
        }
        printf("objects in bucket: %d\n", count($bucketKeys));

        $expected = [];
        foreach ($items as $it) {
            if ($it['verdict'] !== 'MISSING_SOURCE' && $it['verdict'] !== 'BAD_KEY') {
                $expected[$it['path']] = true;
            }
        }
        $missing = array_diff_key($expected, $bucketKeys);
        $extra   = array_diff_key($bucketKeys, $expected);
        printf("expected from DB: %d | missing in bucket: %d | in bucket but unreferenced: %d\n",
            count($expected), count($missing), count($extra));
        foreach (array_keys($missing) as $k) {
            printf("    MISSING  %s\n", $k);
        }
        foreach (array_keys($extra) as $k) {
            printf("    EXTRA    %s\n", $k);
        }
        $ledger = ledgerLoad($LEDGER);
        printf("ledger records: %d\n", count($ledger));
        $exit = (count($missing) === 0 && count($extra) === 0) ? 0 : 1;
        printf("\nreconcile: %s (%.1fs)\n", $exit === 0 ? 'CLEAN' : 'DIFFERENCES FOUND', microtime(true) - $t0);
        exit($exit);
    } catch (Throwable $e) {
        echo 'reconcile failed: ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// dry-run reporting (also printed before --apply)
// ---------------------------------------------------------------------------

if ($mode === 'dry-run') {
    echo "\n== dry-run plan ==\n";
    $totalBytes = 0;
    $todo = 0;
    foreach ($items as $it) {
        if (in_array($it['verdict'], ['READY', 'MISSING_HASH'], true)) {
            $todo++;
            $totalBytes += (int) $it['size'];
        }
    }
    printf("objects to upload: %d  (%.2f MB)\n", $todo, $totalBytes / 1048576);
    foreach ($items as $it) {
        if (!in_array($it['verdict'], ['READY', 'MISSING_HASH'], true)) {
            continue;
        }
        printf("  %7.2f MB  %s%s\n", $it['size'] / 1048576, $it['path'],
            $it['verdict'] === 'MISSING_HASH' ? '[no DB hash — will backfill with computed value if --backfill] ' : '',
            count($it['refs']) > 1 ? '[referenced by ' . count($it['refs']) . ' rows — uploaded once]' : '');
    }

    if ($missingSrc) {
        echo "\nMISSING_SOURCE — DB rows whose file is not on disk (blocker for those rows):\n";
        foreach ($missingSrc as $p) {
            echo "    $p\n";
        }
    }
    if ($missingHash) {
        echo "\nMISSING_HASH — file_hash is NULL/empty (hash computed locally, will be stored as object metadata):\n";
        foreach ($missingHash as $p) {
            echo "    $p\n";
        }
    }
    if ($mismatchList) {
        echo "\nHASH_MISMATCH — local bytes disagree with the DB hash (NOT uploaded unless --force):\n";
        foreach ($mismatchList as $p => $v) {
            echo "    $p\n      local=$v[local]\n      db   =$v[db]\n";
        }
    }
    if ($conflicts) {
        echo "\nHASH_CONFLICT_ACROSS_ROWS — one path, several different DB hashes:\n";
        foreach ($conflicts as $p => $hs) {
            echo "    $p\n      " . implode("\n      ", $hs) . "\n";
        }
    }
    if ($badKeys) {
        echo "\nBAD_KEY — paths the storage contract rejects:\n";
        foreach ($badKeys as $p) {
            echo "    $p\n";
        }
    }
    if ($dupeRefs) {
        echo "\nDUPLICATE_REFERENCE — one object referenced by several rows (uploaded once, reused):\n";
        foreach ($dupeRefs as $p => $owners) {
            echo "    $p\n      " . implode(', ', $owners) . "\n";
        }
    }

    $blockers = $stats['MISSING_SOURCE'] + $stats['HASH_MISMATCH'] + $stats['HASH_CONFLICT_ACROSS_ROWS'] + $stats['BAD_KEY'];
    printf("\nblockers (rows that cannot be migrated safely): %d\n", $blockers);
    printf("dry run complete — nothing was uploaded (%.1fs)\n", microtime(true) - $t0);
    exit($blockers > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// apply / verify — upload (apply only) then verify by re-reading the object
// ---------------------------------------------------------------------------

printf("== %s ==\n", $mode === 'apply' ? 'upload + verify' : 'verify only');
$ledger  = ledgerLoad($LEDGER);
$r2      = R2Storage::default();
$results = ['UPLOADED_OK' => 0, 'VERIFIED_OK' => 0, 'SKIPPED_ALREADY' => 0, 'SKIPPED_MISSING_SOURCE' => 0,
    'SKIPPED_HASH_MISMATCH' => 0, 'FAILED_RETRYABLE' => 0, 'FAILED_PERMANENT' => 0, 'ORPHAN_SKIPPED' => 0];
$failed = [];

foreach ($items as $it) {
    $key = $it['path'];

    if ($it['verdict'] === 'MISSING_SOURCE') {
        $results['SKIPPED_MISSING_SOURCE']++;
        ledgerAppend($LEDGER, ['ts' => date('c'), 'key' => $key, 'result' => 'SKIPPED_MISSING_SOURCE', 'mode' => $mode]);
        continue;
    }
    if ($it['verdict'] === 'BAD_KEY') {
        $results['FAILED_PERMANENT']++;
        $failed[] = $key . ' (bad key)';
        continue;
    }
    if (in_array($it['verdict'], ['HASH_MISMATCH', 'HASH_CONFLICT_ACROSS_ROWS'], true) && !$force) {
        $results['SKIPPED_HASH_MISMATCH']++;
        ledgerAppend($LEDGER, ['ts' => date('c'), 'key' => $key, 'result' => 'SKIPPED_HASH_MISMATCH', 'mode' => $mode,
            'local_sha256' => $it['local_hash'], 'db_sha256' => $it['db_hash']]);
        continue;
    }

    try {
        $exists = $r2->head($key) !== null;
    } catch (Throwable $e) {
        $results['FAILED_RETRYABLE']++;
        $failed[] = $key . ' (head failed: ' . get_class($e) . ')';
        continue;
    }

    // --apply: upload what is not there yet (resume-safe)
    if ($mode === 'apply' && !$exists) {
        $abs  = securePath($key);
        $body = @fopen($abs, 'rb');
        if ($body === false) {
            $results['FAILED_RETRYABLE']++;
            $failed[] = $key . ' (cannot open source)';
            continue;
        }
        try {
            $r2->put($key, $body, mimeFor($abs, strtolower(pathinfo($abs, PATHINFO_EXTENSION))), [
                'sha256' => $it['local_hash'],
                'size'   => (string) $it['size'],
                'source' => 'r2_migrate',
            ]);
            $results['UPLOADED_OK']++;
        } catch (Throwable $e) {
            $results['FAILED_RETRYABLE']++;
            $failed[] = $key . ' (upload failed: ' . get_class($e) . ')';
            ledgerAppend($LEDGER, ['ts' => date('c'), 'key' => $key, 'result' => 'FAILED_RETRYABLE', 'mode' => $mode, 'error' => get_class($e)]);
            continue;
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    } elseif ($mode === 'apply' && $exists) {
        $results['SKIPPED_ALREADY']++;
    }

    // Verify: size from head(), SHA-256 recomputed from the object stream.
    try {
        $info = $r2->head($key);
        if ($info === null) {
            throw new StorageObjectNotFound('object vanished after upload');
        }
        if ((int) $info['size'] !== (int) $it['size']) {
            throw new RuntimeException('size mismatch: bucket=' . $info['size'] . ' local=' . $it['size']);
        }
        $remoteHash = objectSha256($key);
        if ($remoteHash === null || !hash_equals($it['local_hash'], $remoteHash)) {
            throw new RuntimeException('sha256 mismatch: bucket=' . ($remoteHash ?? 'unreadable') . ' local=' . $it['local_hash']);
        }
        if ($it['db_hash'] !== null && !hash_equals($it['db_hash'], $remoteHash)) {
            throw new RuntimeException('sha256 mismatch vs DB file_hash');
        }
        $results['VERIFIED_OK']++;
        ledgerAppend($LEDGER, ['ts' => date('c'), 'key' => $key, 'result' => 'UPLOADED_OK', 'mode' => $mode,
            'size' => (int) $it['size'], 'sha256' => $remoteHash, 'db_sha256' => $it['db_hash'],
            'refs' => array_map(fn($x) => $x['table'] . '#' . $x['id'], $it['refs'])]);

        // Optional backfill of a NULL file_hash (data only — no schema change).
        if ($backfill && $it['db_hash'] === null) {
            foreach ($it['refs'] as $r) {
                if ($r['hash'] === null && in_array($r['table'], ['documents', 'ph_documents', 'ph_repository'], true)) {
                    $pdo->prepare("UPDATE `{$r['table']}` SET file_hash = ? WHERE id = ? AND (file_hash IS NULL OR file_hash = '')")
                        ->execute([$remoteHash, $r['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        $results['FAILED_RETRYABLE']++;
        $failed[] = $key . ' (' . $e->getMessage() . ')';
        ledgerAppend($LEDGER, ['ts' => date('c'), 'key' => $key, 'result' => 'FAILED_RETRYABLE', 'mode' => $mode, 'error' => $e->getMessage()]);
    }
}

// Orphans are reported, never uploaded silently (item 4 of the owner's brief).
if ($withOrph) {
    printf("\n== orphans (explicitly requested) ==\n");
    foreach ($orphans as $p => $s) {
        $abs = securePath($p);
        $h = hash_file('sha256', $abs);
        try {
            $body = fopen($abs, 'rb');
            $r2->put($p, $body, mimeFor($abs, strtolower(pathinfo($abs, PATHINFO_EXTENSION))), ['sha256' => $h, 'size' => (string) $s, 'source' => 'r2_migrate_orphan']);
            if (is_resource($body)) {
                fclose($body);
            }
            $remote = objectSha256($p);
            $ok = $remote !== null && hash_equals($h, $remote);
            printf("  %-8s %s (%.2f MB)\n", $ok ? 'OK' : 'FAILED', $p, $s / 1048576);
            ledgerAppend($LEDGER, ['ts' => date('c'), 'key' => $p, 'result' => $ok ? 'UPLOADED_OK' : 'FAILED_RETRYABLE', 'mode' => 'orphan', 'sha256' => $h]);
        } catch (Throwable $e) {
            printf("  FAILED   %s (%s)\n", $p, get_class($e));
        }
    }
} else {
    $results['ORPHAN_SKIPPED'] = count($orphans);
}

echo "\n== summary ==\n";
foreach ($results as $k => $v) {
    if ($v > 0) {
        printf("  %-24s %d\n", $k, $v);
    }
}
if ($failed) {
    echo "\nfailures (all retryable — rerun --apply to resume):\n";
    foreach ($failed as $f) {
        echo "    $f\n";
    }
}
printf("\nledger: %s\n", $LEDGER);
printf("elapsed: %.1fs\n", microtime(true) - $t0);
exit(count($failed) > 0 ? 1 : 0);
