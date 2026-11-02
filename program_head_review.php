<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();

$deptId = (int)$_SESSION['department_id'];
$phId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: program_head_pending.php');
    exit;
}

// CSRF verification
requireCsrfToken();

$submissionId = (int)($_POST['submission_id'] ?? 0);
$action = $_POST['action'] ?? '';
$comment = trim($_POST['comment'] ?? '');
$redirect = $_POST['redirect'] ?? 'program_head_pending.php';
if (!preg_match('/^[a-z0-9_]+\.php$/', $redirect)) {
    $redirect = 'program_head_pending.php';
}

if (!$submissionId || !in_array($action, ['approve', 'reject', 'modify'])) {
    header('Location: program_head_pending.php?error=' . urlencode('Invalid request.'));
    exit;
}

if ($action === 'reject' && empty($comment)) {
    header('Location: ' . $redirect . '?error=' . urlencode('A reason is required when rejecting.'));
    exit;
}

// Verify the submission belongs to this department and file exists
$check = $pdo->prepare("
    SELECT d.id, d.file_path, d.title FROM documents d
    WHERE d.id = ? AND d.department_id = ?
");
$check->execute([$submissionId, $deptId]);
$doc = $check->fetch();
if (!$doc) {
    header('Location: program_head_pending.php?error=' . urlencode('Submission not found or access denied.'));
    exit;
}

// Check if file exists — only genuine absence may delete the row (plan §9).
$filePath = $doc['file_path'] ?? null;
try {
    $fileGone = empty($filePath) || !storageObjectExists((string) $filePath);
} catch (StorageException $e) {
    error_log('[CHED] PH review blocked, storage unavailable: ' . get_class($e));
    header('Location: program_head_pending.php?error=' . urlencode('Storage temporarily unavailable. Please try again.'));
    exit;
}
if ($fileGone) {
    // Delete the record since file is missing
    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$submissionId]);
    logAudit($pdo, 'cleanup_missing_file', "Deleted document with missing file during " . phRoleShort() . " review: {$doc['title']}", 'documents', $submissionId);
    header('Location: program_head_pending.php?error=' . urlencode('Document file is missing and has been removed.'));
    exit;
}

// Pass department ID for access control
$success = programHeadReview($pdo, $submissionId, $phId, $action, $comment, $deptId);

if ($success) {
    $msg = $action === 'approve' ? 'Submission approved and forwarded to Admin.' : 'Submission rejected and returned to faculty member.';
    header('Location: ' . sanitize($redirect) . '?msg=' . urlencode($msg));
} else {
    header('Location: ' . sanitize($redirect) . '?error=' . urlencode('Failed to process. Please try again or access was denied.'));
}
exit;

