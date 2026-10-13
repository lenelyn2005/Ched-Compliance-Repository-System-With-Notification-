<?php
/**
 * CHED Compliance — moveDocumentFile() failure/success contract (CLI).
 *
 * Verifies that a failed physical move does NOT silently update the DB path,
 * that it is logged (file_move_failed), and that a normal move still works.
 *
 * Run: php scripts/move_failure_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('CHED_APP', true);
require __DIR__ . '/../config.php';

$tag = 'MOVEFAIL_' . date('YmdHis');
$fail = 0;
function chk($name, $ok, $detail = '') {
    global $fail;
    if (!$ok) { $fail++; }
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $name  $detail\n";
}

$relOk      = "pending/program_head/{$tag}_ok.pdf";
$relMissing = "pending/program_head/{$tag}_missing.pdf";
$absOk      = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relOk);

@mkdir(dirname($absOk), 0777, true);
file_put_contents($absOk, "%PDF-1.4 move-fail test $tag");

$ins = $pdo->prepare("INSERT INTO documents (teacher_requirement_id, department_id, category, document_type, title, filename, file_path, uploaded_by, status, version, upload_date) VALUES (NULL, 1, ?, 'Doc', ?, ?, ?, 1, 'pending_admin', 1, NOW())");
$ins->execute([$tag, "{$tag}_missing", "{$tag}_missing.pdf", $relMissing]);
$idMissing = (int)$pdo->lastInsertId();
$ins->execute([$tag, "{$tag}_ok", "{$tag}_ok.pdf", $relOk]);
$idOk = (int)$pdo->lastInsertId();

// M1: source missing -> false, DB path unchanged, logged.
$r1 = moveDocumentFile($pdo, $idMissing, 'documents', 'approved_admin');
$p1 = $pdo->query("SELECT file_path FROM documents WHERE id=$idMissing")->fetchColumn();
$logged = (int)$pdo->query("SELECT COUNT(*) FROM audit_trail WHERE action='file_move_failed' AND entity_id=$idMissing")->fetchColumn();
chk('M1. missing source returns false', $r1 === false, 'ret=' . var_export($r1, true));
chk('M1. DB path unchanged on failure', $p1 === $relMissing, "path=$p1");
chk('M1. failure logged (file_move_failed)', $logged >= 1, "rows=$logged");

// M2: normal move -> string, DB path updated, file relocated.
$r2 = moveDocumentFile($pdo, $idOk, 'documents', 'approved_admin');
$p2 = $pdo->query("SELECT file_path FROM documents WHERE id=$idOk")->fetchColumn();
$newAbs = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, (string)$p2);
chk('M2. valid move returns path', is_string($r2) && $r2 !== '', 'ret=' . var_export($r2, true));
chk('M2. DB path updated', $p2 !== $relOk, "path=$p2");
chk('M2. relocated file exists', is_file($newAbs), "abs=$newAbs");
chk('M2. old path gone', !file_exists($absOk), '');

// M3: adminReview on a pending_admin row whose file is missing must NOT
// report success; the controller-style transaction must roll the status back.
$pdo->beginTransaction();
$res3 = adminReview($pdo, $idMissing, 1, 'approve');
if ($res3) { $pdo->commit(); } else { $pdo->rollBack(); }
$st3 = $pdo->query("SELECT status FROM documents WHERE id=$idMissing")->fetchColumn();
chk('M3. adminReview returns false when move fails', $res3 === false, 'ret=' . var_export($res3, true));
chk('M3. status rolled back to pending_admin', $st3 === 'pending_admin', "status=$st3");

// cleanup
$pdo->exec("DELETE FROM documents WHERE category='$tag'");
$pdo->exec("DELETE FROM audit_trail WHERE action='file_move_failed' AND entity_id IN ($idMissing,$idOk)");
foreach ([$absOk, $newAbs] as $p) { if ($p && file_exists($p)) { @unlink($p); } }
$left = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE category='$tag'")->fetchColumn();
echo "[i] cleanup complete (residual=$left)\n";

echo "\n=== " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($fail failures) ===\n";
exit($fail === 0 ? 0 : 1);
