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
requireCsrfToken();

$submissionId = (int)($_POST['submission_id'] ?? 0);
$action = $_POST['action'] ?? '';
$comment = trim($_POST['comment'] ?? '');

if (!$submissionId || !in_array($action, ['approve', 'reject', 'modify'])) {
    header('Location: program_head_pending.php?error=' . urlencode('Invalid request.'));
    exit;
}

if ($action === 'reject' && empty($comment)) {
    header('Location: program_head_pending.php?error=' . urlencode('A reason is required when rejecting.'));
    exit;
}

$check = $pdo->prepare("
    SELECT pd.id, pd.file_path, pd.title FROM ph_documents pd
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    WHERE pd.id = ? AND phr.created_by = ?
");
$check->execute([$submissionId, $phId]);
$doc = $check->fetch();
if (!$doc) {
    header('Location: program_head_pending.php?error=' . urlencode('Submission not found or access denied.'));
    exit;
}

// Check if file exists — only genuine absence may delete rows (plan §9).
$filePath = $doc['file_path'] ?? null;
try {
    $fileGone = empty($filePath) || !storageObjectExists((string) $filePath);
} catch (StorageException $e) {
    error_log('[CHED] PH review blocked, storage unavailable: ' . get_class($e));
    header('Location: program_head_pending.php?error=' . urlencode('Storage temporarily unavailable. Please try again.'));
    exit;
}
if ($fileGone) {
    // Also delete any ph_repository entries referencing this document
    $pdo->prepare("DELETE FROM ph_repository WHERE ph_document_id = ?")->execute([$submissionId]);
    // Delete the record since file is missing
    $pdo->prepare("DELETE FROM ph_documents WHERE id = ?")->execute([$submissionId]);
    logAudit($pdo, 'cleanup_missing_file', "Deleted ph_document with missing file during " . phRoleShort() . " review: {$doc['title']}", 'ph_documents', $submissionId);
    header('Location: program_head_pending.php?error=' . urlencode('Document file is missing and has been removed.'));
    exit;
}

// Pass department ID for access control
$success = programHeadReviewPH($pdo, $submissionId, $phId, $action, $comment, $deptId);

if ($success) {
    $msg = $action === 'approve' 
        ? 'Submission approved and stored in your Repository (not sent to Admin).' 
        : 'Submission rejected and returned to faculty member.';
    header('Location: program_head_pending.php?msg=' . urlencode($msg));
} else {
    header('Location: program_head_pending.php?error=' . urlencode('Failed to process. Please try again or access was denied.'));
}
exit;

