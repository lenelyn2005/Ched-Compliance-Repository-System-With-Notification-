<?php
/**
 * CHED Compliance — legacy file_hash backfill (CLI).
 *
 * Purpose
 *   Older document / repository rows may have file_hash = NULL (created before
 *   SHA-256 duplicate detection existed). This tool computes and stores the
 *   SHA-256 of the on-disk file for such rows, so the DB-level duplicate
 *   prevention (scoped UNIQUE indexes) becomes effective for them too.
 *
 * Safety
 *   - DRY-RUN by default. Pass --apply to actually write.
 *   - Only rows with file_hash IS NULL are considered (idempotent re-run safe).
 *   - The physical path must resolve via securePath() (inside secure storage,
 *     no traversal) AND exist AND be a regular file. Otherwise the row is left
 *     untouched and reported as UNRESOLVED (missing/invalid file) — never deleted.
 *   - If storing the hash would violate a scoped UNIQUE index (i.e. an
 *     identical-content duplicate already exists in the same scope), the row is
 *     left untouched and reported as DUPLICATE_CONTENT. Nothing is deleted.
 *
 * Usage
 *   php scripts/backfill_file_hashes.php            # dry run, report only
 *   php scripts/backfill_file_hashes.php --apply    # write hashes
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('CHED_APP', true);
require __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);

/** table => [scope columns for duplicate reporting] */
$tables = [
    'documents'      => ['teacher_requirement_id', 'department_id', 'category', 'document_type'],
    'ph_documents'   => ['teacher_ph_requirement_id'],
    'ph_repository'  => ['program_head_id', 'title'],
];

$summary = [];
$totalUpdated = 0; $totalNullSeen = 0; $totalUnresolved = 0; $totalDup = 0; $totalDupGroups = 0;

foreach ($tables as $table => $scopeCols) {
    $rows = $pdo->query("SELECT id, file_path FROM `$table` WHERE file_hash IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $seen = count($rows);
    $updated = 0; $unresolved = 0; $dup = 0; $dupGroups = [];

    $upd = $pdo->prepare("UPDATE `$table` SET file_hash = ? WHERE id = ? AND file_hash IS NULL");

    foreach ($rows as $r) {
        $rel = (string)($r['file_path'] ?? '');
        $abs = $rel !== '' ? securePath($rel) : false;

        if ($abs === false || !is_file($abs)) {
            $unresolved++;
            echo "[UNRESOLVED] $table#{$r['id']} file missing/invalid: " . ($rel === '' ? '(empty)' : $rel) . "\n";
            continue;
        }

        $hash = @hash_file('sha256', $abs);
        if ($hash === false) {
            $unresolved++;
            echo "[UNRESOLVED] $table#{$r['id']} unreadable: $rel\n";
            continue;
        }

        try {
            if ($apply) {
                $upd->execute([$hash, $r['id']]);
                if ($upd->rowCount() === 0) { continue; } // raced / already set
            }
            $updated++;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $dup++;
                $dupGroups[] = $rel;
                echo "[DUPLICATE_CONTENT] $table#{$r['id']}: identical bytes already hashed in same scope; left NULL. ($rel)\n";
                continue;
            }
            throw $e;
        }
    }

    $summary[$table] = ['null_seen' => $seen, 'updated' => $updated, 'unresolved' => $unresolved, 'duplicate_content' => $dup];
    $totalUpdated += $updated; $totalNullSeen += $seen; $totalUnresolved += $unresolved; $totalDup += $dup;
}

// Duplicate content already-present (non-null hashes sharing a scope key).
echo "\n=== Duplicate content among existing (non-NULL) hashes ===\n";
foreach ($tables as $table => $scopeCols) {
    $sk = implode(', ', array_map(fn($c) => "`$c`", $scopeCols));
    $sql = "SELECT $sk, file_hash, COUNT(*) c FROM `$table`
            WHERE file_hash IS NOT NULL
            GROUP BY $sk, file_hash HAVING c > 1";
    $dups = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!$dups) { echo "  $table: none\n"; continue; }
    $totalDupGroups += count($dups);
    foreach ($dups as $d) {
        echo "  $table: scope(" . json_encode(array_intersect_key($d, array_flip($scopeCols))) . ") hash=" . substr($d['file_hash'], 0, 12) . "… x{$d['c']}\n";
    }
}

echo "\n=== SUMMARY (" . ($apply ? 'APPLIED' : 'DRY-RUN') . ") ===\n";
foreach ($summary as $t => $s) {
    echo sprintf("  %-14s null_seen=%d updated=%d unresolved=%d duplicate_content=%d\n",
        $t, (int)$s['null_seen'], $s['updated'], $s['unresolved'], $s['duplicate_content']);
}
echo sprintf("  TOTAL          null_seen=%d updated=%d unresolved=%d duplicate_content=%d dup_groups_existing=%d\n",
    $totalNullSeen, $totalUpdated, $totalUnresolved, $totalDup, $totalDupGroups);
echo $apply ? "Apply complete.\n" : "Dry-run only. Re-run with --apply to write.\n";
