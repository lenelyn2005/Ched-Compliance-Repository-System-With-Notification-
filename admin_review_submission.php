<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    requireCsrfToken();

    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $redirect = $_POST['redirect'] ?? 'admin_submissions.php';
    if (!preg_match('/^[a-z0-9_]+\.php$/', $redirect)) {
        $redirect = 'admin_submissions.php';
    }
    $docSource = $_POST['doc_source'] ?? 'document';

    if (!$submissionId || !$action) {
        header('Location: ' . $redirect . '?error=' . urlencode('Invalid request.'));
        exit;
    }

    if (!in_array($action, ['approve', 'reject'])) {
        header('Location: ' . $redirect . '?error=' . urlencode('Invalid action.'));
        exit;
    }

    $current_user_id = $_SESSION['user_id'];
    $current_time = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        if ($docSource === 'ph_document') {
            // Handle PH document review - use adminReviewPH function to trigger notifications
            $stmt = $pdo->prepare("SELECT * FROM ph_documents WHERE id = ?");
            $stmt->execute([$submissionId]);
            $document = $stmt->fetch();

            if (!$document) {
                $pdo->rollBack();
                header('Location: ' . $redirect . '?error=' . urlencode('Submission not found.'));
                exit;
            }

            // Check if file exists
            $filePath = $document['file_path'] ?? null;
            $fullPath = !empty($filePath) ? securePath($filePath) : null;
            if (empty($filePath) || $fullPath === false || !file_exists($fullPath) || !is_file($fullPath)) {
                // Delete the record since file is missing
                $pdo->prepare("DELETE FROM ph_documents WHERE id = ?")->execute([$submissionId]);
                logAudit($pdo, 'cleanup_missing_file', "Deleted ph_document with missing file during review: {$document['title']}", 'ph_documents', $submissionId);
                $pdo->commit();
                header('Location: ' . $redirect . '?error=' . urlencode('Document file is missing and has been removed.'));
                exit;
            }

            // Check if status allows admin review
            if (!in_array($document['status'], ['pending_admin'])) {
                $pdo->rollBack();
                header('Location: ' . $redirect . '?error=' . urlencode('This submission is not awaiting Admin review.'));
                exit;
            }

            // ── Duplicate detection (approved-only, same PH requirement) ──
            // Refuses a second approval of the exact same file (SHA-256) in
            // the same PH requirement. Non-approved docs never trigger this;
            // a fresh PH requirement is always allowed.
            if ($action === 'approve') {
                $hash = ensureDocumentHash($pdo, 'ph_documents', $submissionId);
                if ($hash !== null && !empty($document['teacher_ph_requirement_id'])) {
                    $dupeCheck = $pdo->prepare("
                        SELECT id FROM ph_documents
                         WHERE id <> ?
                           AND archived = 0
                           AND status = 'approved_admin'
                           AND file_hash = ?
                           AND teacher_ph_requirement_id = ?
                         LIMIT 1
                    ");
                    $dupeCheck->execute([$submissionId, $hash, (int)$document['teacher_ph_requirement_id']]);
                    if ($dupeCheck->fetch()) {
                        $pdo->rollBack();
                        logAudit(
                            $pdo,
                            'admin_review_duplicate',
                            "Duplicate PH approval blocked at controller: doc #{$submissionId} has the same SHA-256 as an already-approved doc in the same PH requirement.",
                            'ph_document',
                            $submissionId
                        );
                        header('Location: ' . $redirect . '?error=' . urlencode('Document already exists.'));
                        exit;
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────

            // Use adminReviewPH function to handle review and send notifications/emails
            $result = adminReviewPH($pdo, $submissionId, $current_user_id, $action, $comment);

            if (!$result) {
                throw new Exception("Failed to update PH document");
            }

        } else {
            // Handle regular document review - use adminReview function to trigger notifications
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$submissionId]);
            $document = $stmt->fetch();

            if (!$document) {
                $pdo->rollBack();
                header('Location: ' . $redirect . '?error=' . urlencode('Submission not found.'));
                exit;
            }

            // Check if file exists
            $filePath = $document['file_path'] ?? null;
            $fullPath = !empty($filePath) ? securePath($filePath) : null;
            if (empty($filePath) || $fullPath === false || !file_exists($fullPath) || !is_file($fullPath)) {
                // Delete the record since file is missing
                $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$submissionId]);
                logAudit($pdo, 'cleanup_missing_file', "Deleted document with missing file during review: {$document['title']}", 'documents', $submissionId);
                $pdo->commit();
                header('Location: ' . $redirect . '?error=' . urlencode('Document file is missing and has been removed.'));
                exit;
            }

            if ($document['status'] === 'rejected_program_head') {
                $pdo->rollBack();
                header('Location: ' . $redirect . '?error=' . urlencode('This submission was rejected by the Program Head and cannot be overridden.'));
                exit;
            }

            // Check if status allows admin review
            if (!in_array($document['status'], ['pending_admin'])) {
                $pdo->rollBack();
                header('Location: ' . $redirect . '?error=' . urlencode('This submission is not awaiting Admin review.'));
                exit;
            }

            // ── Duplicate detection (approved-only, same cycle) ─────────
            // Refuses a second approval of the exact same file (SHA-256) in
            // the SAME requirement cycle. Pending / rejected docs never
            // trigger this; a NEW cycle (different cycle_started_at) is
            // always allowed even if a previous cycle had the same file.
            if ($action === 'approve') {
                $hash = ensureDocumentHash($pdo, 'documents', $submissionId);
                if ($hash !== null) {
                    $cycleStart = getRequirementCycleStart($pdo, [
                        'department_id'          => $document['department_id'] ?? null,
                        'category'               => $document['category'] ?? null,
                        'document_type'          => $document['document_type'] ?? null,
                        'teacher_requirement_id' => $document['teacher_requirement_id'] ?? null,
                    ]);
                    $dupe = findDuplicateApprovedDocument(
                        $pdo,
                        $hash,
                        $document['department_id'] !== null ? (int)$document['department_id'] : null,
                        $document['category'] ?? null,
                        $document['document_type'] ?? null,
                        $cycleStart,
                        $submissionId
                    );
                    if ($dupe) {
                        $pdo->rollBack();
                        logAudit(
                            $pdo,
                            'admin_review_duplicate',
                            "Duplicate approval blocked at controller: doc #{$submissionId} has the same SHA-256 as already-approved doc #{$dupe['id']} in the same cycle.",
                            'document',
                            $submissionId
                        );
                        header('Location: ' . $redirect . '?error=' . urlencode('Document already exists.'));
                        exit;
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────

            // Use adminReview function to handle review and send notifications/emails
            $result = adminReview($pdo, $submissionId, $current_user_id, $action, $comment);

            if (!$result) {
                throw new Exception("Failed to update document");
            }
        }

        $pdo->commit();

        $msg = $action === 'approve' ? 'Document approved successfully.' : 'Document rejected.';
        header('Location: ' . sanitize($redirect) . '?msg=' . urlencode($msg));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Admin review error: " . $e->getMessage());
        header('Location: ' . sanitize($redirect) . '?error=' . urlencode('Error processing request: ' . $e->getMessage()));
        exit;
    }
}

header('Location: admin_submissions.php');
exit;