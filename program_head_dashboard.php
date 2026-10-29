<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();

// Define CSRF token BEFORE it's used in HTML forms
$csrfToken = getCsrfToken();

// ============================================================================
// AJAX HANDLERS (Mark notifications as seen) 
// ============================================================================
// Skip AJAX handler if it's a POST form submission (resubmit)
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['department', 'unit_head'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $phId = (int) $_SESSION['user_id'];

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page and try again.']);
        exit;
    }

    switch ($_POST['action'] ?? '') {
        case 'mark_pending_review':
            $stmt = $pdo->prepare("UPDATE users SET last_seen_pending_review = NOW() WHERE id = ?");
            $stmt->execute([$phId]);
            echo json_encode(['success' => true]);
            break;
        case 'mark_requirements':
            $stmt = $pdo->prepare("UPDATE users SET last_seen_requirements = NOW() WHERE id = ?");
            $stmt->execute([$phId]);
            echo json_encode(['success' => true]);
            break;
        case 'mark_unassigned_requirements':
            $_SESSION['last_seen_unassigned_requirements'] = date('Y-m-d H:i:s');
            echo json_encode(['success' => true]);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit;
}

// ============================================================================
// HANDLE RESUBMISSION OF REJECTED DIRECT UPLOADS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resubmit_document') {
    // Only program heads can resubmit
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['department', 'unit_head'], true)) {
        $_SESSION['error'] = 'Unauthorized access.';
        header('Location: program_head_dashboard.php');
        exit;
    }

    // CSRF validation
    requireCsrfToken();

    $phId = (int) $_SESSION['user_id'];
    $deptId = (int) $_SESSION['department_id'];
    $docId = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
    $docType = $_POST['doc_type'] ?? 'document';

    // Validate input
    $title = trim($_POST['resubmit_title'] ?? '');
    $description = trim($_POST['resubmit_description'] ?? '');
    $resubmitMode = ($_POST['resubmit_mode'] ?? 'self') === 'assign' ? 'assign' : 'self';

    if (empty($title)) {
        $_SESSION['error'] = 'Title is required.';
        header('Location: program_head_dashboard.php');
        exit;
    }

    if ($resubmitMode === 'self' && empty($_FILES['resubmit_file']['name'])) {
        $_SESSION['error'] = 'Title and file are required.';
        header('Location: program_head_dashboard.php');
        exit;
    }

    if ($resubmitMode === 'assign' && empty($_POST['teacher_ids'])) {
        $_SESSION['error'] = 'Select at least one faculty member to assign.';
        header('Location: program_head_dashboard.php');
        exit;
    }

    // Source-aware authorization FIRST: never store or process a resubmit
    // for a document that is not the uploader's rejected original.
    $stmt = $pdo->prepare("SELECT d.*, d.source_type AS doc_source_type, d.origin_source AS doc_origin_source FROM documents d WHERE d.id = ? AND d.uploaded_by = ? AND d.status = 'rejected_admin' AND d.archived = 0");
    $stmt->execute([$docId, $phId]);
    $originalDoc = $stmt->fetch();
    if (!$originalDoc) {
        $_SESSION['error'] = 'Document not found or not authorized. Please ensure the document was rejected by Admin and belongs to you.';
        header('Location: program_head_dashboard.php');
        exit;
    }
    $teacherReqId = (int)($originalDoc['teacher_requirement_id'] ?? 0);

    // Handle file upload (self mode only - assign mode needs no file)
    $file = null; $ext = null; $filePath = null;
    if ($resubmitMode === 'self') {
    $file = $_FILES['resubmit_file'];

    // Full 7-layer pipeline from config.php: validation, content hash,
    // duplicate check under a per-document lock, safe move under a
    // system-generated name (never the uploader's filename). excludeId=0
    // so an identical copy of the original is also blocked.
    $stored = validateAndStoreUpload(
        $pdo,
        $file,
        $title,
        'ph_direct',
        'updoc:resubmit:' . $docId,
        fn(PDO $pdo, string $hash) => $teacherReqId > 0
            ? findDuplicateAssignmentDoc($pdo, 'documents', 'teacher_requirement_id', $teacherReqId, $hash)
            : findDuplicateDirectUpload($pdo, $deptId, $title, $hash)
    );
    if (is_string($stored)) {
        $_SESSION['error'] = $stored;
        header('Location: program_head_dashboard.php');
        exit;
    }
    $ext = $stored['ext'];
    $newFilename = $stored['filename'];
    $filePath = 'ph_direct/' . $newFilename;
    }

    try {
        $pdo->beginTransaction();

        // Authorization and the duplicate-byte guard (with lock) already ran
        // above via validateAndStoreUpload() before anything was stored.

        $actualSource = $originalDoc['doc_source_type'] ?? '';
        $actualOrigin = $originalDoc['doc_origin_source'] ?? '';

        // Source 1: Quick Direct Upload — only self upload allowed
        if ($actualSource === 'quick_direct' || ($originalDoc['document_type'] === 'Direct Upload' && empty($originalDoc['teacher_requirement_id']) && empty($actualOrigin))) {
            if ($resubmitMode === 'assign') {
                throw new Exception('Quick Direct Upload rejected documents can only be resubmitted via Upload Personally, not assigned.');
            }
            // Verify this is actually a direct upload (no repo link, no teacher req)
            $repoCheck = $pdo->prepare("SELECT id FROM ph_repository WHERE document_id = ?");
            $repoCheck->execute([$docId]);
            if ($repoCheck->fetch()) {
                throw new Exception('Document source mismatch: found repository link but marked as Quick Direct Upload.');
            }
        }
        // Source 2: Forwarded from Repository — both self and assign allowed; verify repo link for assign
        elseif ($actualSource === 'repo_forward' || $actualOrigin === 'repository') {
            if ($resubmitMode === 'assign') {
                $repoCheck = $pdo->prepare("SELECT id FROM ph_repository WHERE document_id = ? AND department_id = ? AND program_head_id = ?");
                $repoCheck->execute([$docId, $deptId, $phId]);
                if (!$repoCheck->fetch()) {
                    throw new Exception('Assign to faculty is only available for Forwarded from Repository documents with a valid repository link.');
                }
            }
        }
        // Source 3: Upload Personally — both self and assign allowed; verify ph_requirement relationship for assign
        elseif ($actualSource === 'personal_upload' || !empty($originalDoc['teacher_requirement_id'])) {
            // For assign mode, verify the document links back to a ph_requirement owned by this PH
            if ($resubmitMode === 'assign') {
                $teacherReqId = (int)($originalDoc['teacher_requirement_id'] ?? 0);
                if ($teacherReqId > 0) {
                    $phReqCheck = $pdo->prepare("SELECT phr.id FROM ph_requirements phr JOIN teacher_ph_requirements tpr ON phr.id = tpr.ph_requirement_id WHERE tpr.id = ? AND phr.created_by = ?");
                    $phReqCheck->execute([$teacherReqId, $phId]);
                    if (!$phReqCheck->fetch()) {
                        throw new Exception('Assign to faculty requires a valid ' . currentRoleName() . ' requirement relationship for this rejected upload.');
                    }
                } else {
                    throw new Exception('Upload Personally rejected document does not have a valid requirement link for assignment.');
                }
            }
        }
        else {
            if ($resubmitMode === 'assign') {
                throw new Exception('Resubmission mode "assign" is not allowed for this document source.');
            }
        }

            // --- ASSIGN TO FACULTY MODE (Repository or Upload Personally) ---
            if ($resubmitMode === 'assign') {
                // Authorization already verified the source relationship above.
                // Just confirm the originalDoc was properly validated.
                $teacherIds = array_values(array_unique(array_map('intval', (array)($_POST['teacher_ids'] ?? []))));
                $teacherIds = array_filter($teacherIds, fn($v) => $v > 0);
                if (empty($teacherIds)) {
                    throw new Exception('Select at least one faculty member to assign.');
                }
                // Validate teachers belong to this department
                $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
                $tCheck = $pdo->prepare("SELECT id, name FROM users WHERE role IN ('teacher','staff') AND department_id = ? AND id IN ($placeholders)");
                $tCheck->execute(array_merge([$deptId], $teacherIds));
                $validTeachers = $tCheck->fetchAll();
                if (empty($validTeachers)) {
                    throw new Exception('Selected faculty members are invalid.');
                }
                $deadline = !empty($_POST['resubmit_deadline']) ? $_POST['resubmit_deadline'] : null;
                if ($deadline && strtotime($deadline) < strtotime(date('Y-m-d'))) {
                    throw new Exception('Deadline must be a future date.');
                }
                $adminNote = $originalDoc['admin_comment'] ? " (Admin rejection reason: {$originalDoc['admin_comment']})" : '';
                $reqDesc = trim($description . $adminNote);

                $pdo->prepare("INSERT INTO ph_requirements (department_id, created_by, title, description, filename, file_path, created_at, deadline) VALUES (?, ?, ?, ?, NULL, NULL, NOW(), ?)")
                    ->execute([$deptId, $phId, $title, $reqDesc, $deadline]);
                $newReqId = (int)$pdo->lastInsertId();

                $assignedNames = [];
                $assignStmt = $pdo->prepare("INSERT INTO teacher_ph_requirements (teacher_id, ph_requirement_id, assigned_at, requirement_type) VALUES (?, ?, NOW(), 'program_head_requirement')");
                foreach ($validTeachers as $t) {
                    $assignStmt->execute([(int)$t['id'], $newReqId]);
                    $assignedNames[] = $t['name'];
                }
                $pdo->prepare("UPDATE ph_requirements SET assigned = 1, assigned_at = NOW() WHERE id = ?")->execute([$newReqId]);
                $pdo->prepare("UPDATE documents SET archived = 1 WHERE id = ?")->execute([$docId]);

                logAudit($pdo, 'resubmit_assign_faculty', phRoleShort() . " assigned rejected upload #$docId as requirement #$newReqId to: " . implode(', ', $assignedNames), 'ph_requirement', $newReqId);

                $pdo->commit();

                // Notify AFTER commit: the old in-transaction call was rolled back on
                // failure, and sendTemplateToTeacher(..., defer=true) never re-sent.
                foreach ($validTeachers as $t) {
                    createNotification($pdo, (int)$t['id'], 'New ' . phRoleShort() . ' Requirement Assigned', "You were assigned: \"$title\" (correction of rejected upload). Reason: " . ($originalDoc['admin_comment'] ?: 'See dashboard'), 'info', $newReqId, 'ph_requirement');
                }

                $_SESSION['msg'] = 'Rejected document assigned to ' . count($assignedNames) . ' faculty member(s): ' . implode(', ', $assignedNames) . '. Original upload archived.';
                header('Location: program_head_dashboard.php');
                exit;
            }

            // Determine new version
            $newVersion = $originalDoc['version'] + 1;

            // Always auto-forward to admin for resubmitted documents (direct uploads from PH)
            $status = 'pending_admin';

            // Insert new version with appropriate status
            $insertStmt = $pdo->prepare("
                INSERT INTO documents (
                    title, description, filename, file_path, version, status,
                    uploaded_by, department_id, document_type, category,
                    upload_date, teacher_requirement_id, program_head_comment,
                    admin_comment, reviewed_by_program_head, reviewed_at_program_head,
                    reviewed_by_admin, reviewed_at_admin, archived, source_type, origin_source
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $title,
                $description,
                $file['name'],
                $filePath,
                $newVersion,
                $status,
                $phId,
                $deptId,
                $originalDoc['document_type'],
                $originalDoc['category'],
                $originalDoc['teacher_requirement_id'],
                $originalDoc['program_head_comment'],
                null, // Clear admin comment for resubmission
                $originalDoc['reviewed_by_program_head'],
                $originalDoc['reviewed_at_program_head'],
                null, // Clear admin review info
                null,
                0, // archived - new documents are not archived
                $originalDoc['doc_source_type'] ?? null,
                $originalDoc['doc_origin_source'] ?? null
            ]);

            $newDocId = $pdo->lastInsertId();
            if ($resubmitMode === 'self' && $filePath !== null && $ext !== null) {
                finalizeStorageFilename($pdo, (int)$newDocId, 'documents', $title, $ext);
            }

            // Update the original document to mark it as superseded
            $updateStmt = $pdo->prepare("UPDATE documents SET archived = 1 WHERE id = ?");
            $updateStmt->execute([$docId]);

            // Log the resubmission
            logAudit($pdo, 'resubmit_document', "Resubmitted document: $title (v$newVersion)", 'documents', $newDocId);

            // Notify admin since resubmitted documents always go to admin
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
            foreach ($admins as $admin) {
                createNotification(
                    $pdo,
                    $admin['id'],
                    'Document Resubmitted by ' . currentRoleName(),
                    currentRoleName() . " resubmitted a rejected document: \"$title\" (v$newVersion) for your review.",
                    'info',
                    $newDocId,
                    'document'
                );
            }

        $pdo->commit();

        $_SESSION['msg'] = 'Document resubmitted successfully! The document has been forwarded to Admin for review.';
        header('Location: program_head_dashboard.php');
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        // Clean up the stored object if the database operation failed
        if (!empty($filePath)) {
            storageDeleteRel($filePath);
        }
        $_SESSION['error'] = 'Failed to resubmit document: ' . $e->getMessage();
        header('Location: program_head_dashboard.php');
        exit;
    }
}

// ============================================================================
// HANDLE DELETE OF REJECTED DIRECT UPLOADS
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_rejected_document') {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['department', 'unit_head'], true)) {
        $_SESSION['error'] = 'Unauthorized access.';
        header('Location: program_head_dashboard.php');
        exit;
    }

    requireCsrfToken();

    $phId  = (int) $_SESSION['user_id'];
    $docId = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, title FROM documents WHERE id = ? AND uploaded_by = ? AND status = 'rejected_admin' AND archived = 0");
        $stmt->execute([$docId, $phId]);
        $doc = $stmt->fetch();

        if (!$doc) {
            throw new Exception('Document not found or not authorized.');
        }

        // Soft delete: archive so the audit trail and history are preserved
        $pdo->prepare("UPDATE documents SET archived = 1 WHERE id = ?")->execute([$docId]);

        logAudit($pdo, 'delete_rejected_document', phRoleShort() . " deleted rejected document #{$docId}: {$doc['title']}", 'documents', $docId);

        $pdo->commit();
        $_SESSION['msg'] = 'Rejected document deleted successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Failed to delete document: ' . $e->getMessage();
    }

    header('Location: program_head_dashboard.php');
    exit;
}

// ============================================================================
// DATA QUERIES (optimized & strictly filtered)
// ============================================================================

$deptId = (int) $_SESSION['department_id'];
$phId = (int) $_SESSION['user_id'];

// --- Notification timestamps for this program head (stored in session) ---
if (!isset($_SESSION['last_seen_pending_review'])) {
    $_SESSION['last_seen_pending_review'] = date('Y-m-d H:i:s');
}
if (!isset($_SESSION['last_seen_requirements'])) {
    $_SESSION['last_seen_requirements'] = date('Y-m-d H:i:s');
}

$lastSeenPending = $_SESSION['last_seen_pending_review'];
$lastSeenRequirements = $_SESSION['last_seen_requirements'];

// --- Count new pending submissions (unseen by program head) ---
$newPendingCount = 0;

$sql = "
    SELECT COUNT(*) 
    FROM documents d
    JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    JOIN department_requirements dr ON tr.requirement_id = dr.id
    WHERE dr.department_id = ? 
      AND d.status = 'pending_program_head'
";
$params = [$deptId];
if ($lastSeenPending) {
    $sql .= " AND d.upload_date > ?";
    $params[] = $lastSeenPending;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$newPendingCount += (int) $stmt->fetchColumn();

$sql = "
    SELECT COUNT(*) 
    FROM ph_documents pd
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    WHERE phr.department_id = ? 
      AND pd.status = 'pending_program_head'
";
$params = [$deptId];
if ($lastSeenPending) {
    $sql .= " AND pd.upload_date > ?";
    $params[] = $lastSeenPending;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$newPendingCount += (int) $stmt->fetchColumn();

// --- Count new admin-assigned requirements (unseen by program head) ---
$newRequirementsCount = 0;
$sql = "SELECT COUNT(*) FROM department_requirements WHERE department_id = ?";
$params = [$deptId];
if ($lastSeenRequirements) {
    $sql .= " AND created_at > ?";
    $params[] = $lastSeenRequirements;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$newRequirementsCount = (int) $stmt->fetchColumn();

// ============================================================================
// EXISTING COUNTS (unchanged, keep for other parts of the dashboard)
// ============================================================================

// Faculty Count — split by role so the dashboard reports each group separately
$stmt = $pdo->prepare("SELECT role, COUNT(*) AS total FROM users WHERE role IN ('teacher','staff') AND department_id = ? GROUP BY role");
$stmt->execute([$deptId]);
$roleCounts = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'role');
$facultyCount = (int)($roleCounts['teacher'] ?? 0);
$staffCount = (int)($roleCounts['staff'] ?? 0);

// Pending Review Count – all pending (used for stats display, not badge)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents d
    JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    JOIN department_requirements dr ON tr.requirement_id = dr.id
    WHERE dr.department_id = ? AND d.status = 'pending_program_head'
");
$stmt->execute([$deptId]);
$pendingReviewCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM ph_documents pd
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    WHERE phr.department_id = ? AND pd.status = 'pending_program_head'
");
$stmt->execute([$deptId]);
$pendingReviewCount += (int) $stmt->fetchColumn();

// Forwarded to Admin Count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents d
    JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    JOIN department_requirements dr ON tr.requirement_id = dr.id
    WHERE dr.department_id = ? AND d.status = 'pending_admin'
");
$stmt->execute([$deptId]);
$forwardedCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM ph_documents pd
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    WHERE phr.department_id = ? AND pd.status = 'pending_admin'
");
$stmt->execute([$deptId]);
$forwardedCount += (int) $stmt->fetchColumn();

// PH direct uploads (no teacher_requirement_id) — excluded by the JOIN above
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents d
    WHERE d.uploaded_by = ? AND d.teacher_requirement_id IS NULL AND d.status = 'pending_admin'
");
$stmt->execute([$phId]);
$forwardedCount += (int) $stmt->fetchColumn();

// Approved Count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents d
    JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    JOIN department_requirements dr ON tr.requirement_id = dr.id
    WHERE dr.department_id = ? AND d.status = 'approved_admin'
");
$stmt->execute([$deptId]);
$approvedCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM ph_documents pd
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    WHERE phr.department_id = ? AND pd.status IN ('approved_program_head', 'approved_admin')
");
$stmt->execute([$deptId]);
$approvedCount += (int) $stmt->fetchColumn();

// PH direct uploads (no teacher_requirement_id) — excluded by the JOIN above
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents d
    WHERE d.uploaded_by = ? AND d.teacher_requirement_id IS NULL AND d.status = 'approved_admin'
");
$stmt->execute([$phId]);
$approvedCount += (int) $stmt->fetchColumn();

// Total Requirements – Admin-assigned only
$stmt = $pdo->prepare("SELECT COUNT(*) FROM department_requirements WHERE department_id = ?");
$stmt->execute([$deptId]);
$totalReqs = (int) $stmt->fetchColumn();

// ============================================================================
// UNIFIED REJECTED REQUIREMENTS — all 3 sources
// ============================================================================
// Single query that covers all 3 rejection sources, identified by:
//   Source 2 (Forwarded from Repository): ph_repository.document_id = d.id
//   Source 3 (Upload Personally): d.teacher_requirement_id IS NOT NULL
//   Source 1 (Quick Direct Upload): everything else, split by document_type below
$rejectedAllQuery = "
    SELECT d.id, d.title AS doc_title, d.filename, d.file_path,
           d.admin_comment AS rejection_reason, d.reviewed_at_admin AS rejected_at,
           d.document_type AS doc_document_type, d.category AS doc_category,
           d.source_type AS doc_source_type, d.origin_source AS doc_origin_source,
           d.teacher_requirement_id, d.description AS doc_description,
           d.version, d.uploaded_by,
           tr.id AS tr_id, tr.requirement_type AS tr_type, tr.teacher_id AS tr_teacher_id,
           dr.id AS req_id, dr.category AS req_category, dr.document_type AS req_document_type,
           dr.mandatory AS req_mandatory, dr.expiration_date AS req_expiration,
           pr.id AS repo_id, pr.title AS repo_title, pr.description AS repo_description,
           pr.folder_id AS repo_folder_id, pr.forwarded_at AS repo_forwarded_at
    FROM documents d
    LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    LEFT JOIN department_requirements dr ON tr.requirement_id = dr.id AND dr.department_id = ?
    LEFT JOIN ph_repository pr ON d.id = pr.document_id AND pr.department_id = ? AND pr.program_head_id = ?
    WHERE d.uploaded_by = ? AND d.status = 'rejected_admin' AND d.archived = 0
    AND d.file_path IS NOT NULL AND d.file_path != ''
    GROUP BY d.id
    ORDER BY d.reviewed_at_admin DESC
";
$rejectedAllStmt = $pdo->prepare($rejectedAllQuery);
$rejectedAllStmt->execute([$deptId, $deptId, $phId, $phId]);
$rejectedAll = $rejectedAllStmt->fetchAll();

// Filter out documents with missing files
$rejectedAll = array_values(array_filter($rejectedAll, function ($doc) {
    $filePath = $doc['file_path'] ?? null;
    if (empty($filePath)) {
        return false;
    }
    return storageFilePresent($filePath);
}));

// Identify source for each rejected item — source-aware using DB fields
foreach ($rejectedAll as &$item) {
    $sourceType = $item['doc_source_type'] ?? '';
    $originSource = $item['doc_origin_source'] ?? '';

    // Source 2: Forwarded from Repository (DB fields: source_type='repo_forward' or origin_source='repository', or linked via ph_repository)
    if ($sourceType === 'repo_forward' || $originSource === 'repository' || !empty($item['repo_id'])) {
        $item['source'] = 'repository';
        $item['source_label'] = 'Forwarded from Repository';
        $item['source_badge_class'] = 'badge-info';
        $item['source_detail'] = !empty($item['repo_title']) ? $item['repo_title'] : 'Repository document';
        $item['source_folder'] = '';
        if (!empty($item['repo_folder_id'])) {
            $fp = $pdo->prepare("SELECT folder_name, parent_folder_id FROM ph_repo_folders WHERE id = ?");
            $fp->execute([$item['repo_folder_id']]);
            $folder = $fp->fetch();
            if ($folder) {
                $path = [];
                $cid = $folder['id'];
                while ($cid) {
                    $fp2 = $pdo->prepare("SELECT folder_name, parent_folder_id FROM ph_repo_folders WHERE id = ?");
                    $fp2->execute([$cid]);
                    $f = $fp2->fetch();
                    if (!$f) break;
                    array_unshift($path, $f['folder_name']);
                    $cid = $f['parent_folder_id'];
                }
                $item['source_folder'] = implode(' > ', $path);
            }
        }
    }
    // Source 3: Upload Personally (personal upload via ph_requirements / teacher assignment link)
    elseif ($sourceType === 'personal_upload' || !empty($item['tr_id'])) {
        $item['source'] = 'teacher_assigned';
        $item['source_label'] = 'Requirement Upload';
        $item['source_badge_class'] = 'badge-warning';
        $item['source_detail'] = !empty($item['req_category']) ? ($item['req_category'] . ' - ' . $item['req_document_type']) : 'Requirement upload';
        $item['source_folder'] = '';
    }
    // Source 1: Quick Direct Upload, or the PH's own upload against an Admin requirement
    else {
        // No repo link and no teacher requirement. Only document_type='Direct Upload' is
        // a Quick Direct Upload (same test the resubmit authorizer uses, line 163) —
        // anything else is the PH uploading personally against an Admin requirement,
        // so it must not be badged "Direct Upload".
        $isQuickDirect = ($item['doc_document_type'] ?? '') === 'Direct Upload';
        $item['source'] = $isQuickDirect ? 'direct_upload' : 'admin_requirement';
        // Empty label = no badge rendered; admin-requirement uploads get none.
        $item['source_label'] = $isQuickDirect ? 'Direct Upload' : '';
        $item['source_badge_class'] = $isQuickDirect ? 'badge-neutral' : 'badge-warning';
        $item['source_detail'] = $isQuickDirect
            ? 'Direct upload by ' . currentRoleName()
            : trim(($item['doc_category'] ?? '') . ' - ' . ($item['doc_document_type'] ?? ''), ' -');
        $item['source_folder'] = '';
    }
}
unset($item);

// Teacher Progress
$allTeacherProgress = getAllTeacherProgressForProgramHead($pdo, $phId, $deptId);
$teachers = [];
$teachersByRole = ['Faculty' => [], 'Staff' => []];
foreach ($allTeacherProgress as $teacherId => $teacherData) {
    $teachers[] = $teacherData;
    $teachersByRole[($teacherData['role'] ?? 'teacher') === 'staff' ? 'Staff' : 'Faculty'][] = $teacherData;
}

// All dept teachers for assign-to-faculty choice (rejected repo uploads)
$assignTeachersStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE role IN ('teacher','staff') AND department_id = ? ORDER BY name");
$assignTeachersStmt->execute([$deptId]);
$assignTeachers = $assignTeachersStmt->fetchAll();

// ============================================================================
// OTHER QUICK ACTION BADGES (unchanged)
// ============================================================================

// Unassigned requirements count
$unassignedRequirementsCount = 0;
if (!isset($_SESSION['last_seen_unassigned_requirements'])) {
    $stmt = $pdo->prepare("
        SELECT dr.id 
        FROM department_requirements dr
        LEFT JOIN teacher_requirements tr ON dr.id = tr.requirement_id
        LEFT JOIN documents d ON d.department_id = dr.department_id AND d.category = dr.category AND d.document_type = dr.document_type
        WHERE dr.department_id = ? 
          AND tr.id IS NULL 
          AND d.id IS NULL
    ");
    $stmt->execute([$deptId]);
    $unassignedAdminCount = $stmt->rowCount();

    $stmt = $pdo->prepare("
        SELECT phr.id 
        FROM ph_requirements phr
        LEFT JOIN teacher_ph_requirements tpr ON phr.id = tpr.ph_requirement_id
        LEFT JOIN ph_documents pd ON pd.teacher_ph_requirement_id = tpr.id
        WHERE phr.department_id = ? 
          AND tpr.id IS NULL 
          AND pd.id IS NULL
    ");
    $stmt->execute([$deptId]);
    $unassignedPhCount = $stmt->rowCount();
    $unassignedRequirementsCount = $unassignedAdminCount + $unassignedPhCount;
}

// ============================================================================
// EXISTING QUERIES FOR DISPLAY TABLES (unchanged)
// ============================================================================

$requirements = $pdo->prepare("
    SELECT dr.id, dr.category, dr.document_type, dr.mandatory, dr.expiration_date,
           CASE 
               WHEN dr.expiration_date IS NOT NULL AND dr.expiration_date < CURDATE() 
               THEN 1 
               ELSE 0 
           END as is_expired,
           CASE
               WHEN dr.expiration_date IS NOT NULL AND dr.expiration_date >= CURDATE()
                AND dr.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)
               THEN 1
               ELSE 0
           END as is_expiring
    FROM department_requirements dr
    WHERE dr.department_id = ?
    ORDER BY dr.category, dr.document_type
");
$requirements->execute([$deptId]);
$requirements = $requirements->fetchAll();

$phRequirements = $pdo->prepare("
    SELECT pr.*, pr.deadline,
           CASE WHEN pr.deadline IS NOT NULL AND pr.deadline < CURDATE() THEN 1 ELSE 0 END as is_expired,
           CASE WHEN pr.deadline IS NOT NULL AND pr.deadline >= CURDATE() AND pr.deadline <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) THEN 1 ELSE 0 END as is_expiring
    FROM ph_requirements pr
    WHERE pr.created_by = ? AND pr.department_id = ?
    ORDER BY pr.created_at DESC
");
$phRequirements->execute([$phId, $deptId]);
$phRequirements = $phRequirements->fetchAll();

$flashMsg = $_GET['msg'] ?? ($_SESSION['msg'] ?? '');
$flashError = $_GET['error'] ?? ($_SESSION['error'] ?? '');
unset($_SESSION['msg'], $_SESSION['error']);

$pageTitle = currentRoleName() . ' Dashboard';

// Determine base upload URL for file viewing
$uploadBaseUrl = defined('UPLOAD_URL') ? rtrim(UPLOAD_URL, '/') . '/' : 'uploads/';

// SVG Icon Helper
function render_dashboard_icon(string $name): string {
    $icons = [
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'clock' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
        'check' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 14h.01"/><path d="M12 14h.01"/><path d="M9 17h.01"/><path d="M12 17h.01"/></svg>',
        'alert' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        'teacher' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'folder' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>',
        'link' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
        'eye' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
        'refresh' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
        'reupload' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14a2 2 0 0 0 2-2v-3"/><path d="M3 16v3a2 2 0 0 0 2 2"/></svg>',
        'trash' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>',
        'building' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
        'pin' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="17" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>',
        'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" x2="19" y1="12" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
        'chevron-down' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
        'plus' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>',
        'file' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
    ];
    return $icons[$name] ?? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/></svg>';
}

// ============================================================================
// ENHANCED UI/UX STYLES (Modern, Clean, Responsive)
// ============================================================================
ob_start();
?>
<style>
    /* --------------------------------------------
       GLOBAL & VARIABLES
    -------------------------------------------- */
    @import url('offline/inter-font.css');

    :root {
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --primary-light: #eff6ff;
        --success: #10b981;
        --success-light: #d1fae5;
        --warning: #f59e0b;
        --warning-light: #fef3c7;
        --danger: #ef4444;
        --danger-light: #fee2e2;
        --info: #3b82f6;
        --info-light: #dbeafe;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-400: #9ca3af;
        --gray-500: #6b7280;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;
        --white: #ffffff;
        --border: #e5e7eb;
        --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --radius-xl: 1.5rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        font-family: 'Inter', sans-serif;
        color: var(--gray-800);
        font-size: 0.9375rem;
        line-height: 1.5;
    }

    /* Page title — single clear hierarchy entry point */
    .page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        padding: 0.25rem 0.25rem 0;
    }

    .page-head h1 {
        font-size: 1.375rem;
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.02em;
        margin: 0;
        line-height: 1.25;
    }

    .page-head p {
        margin: 0.25rem 0 0;
        font-size: 0.875rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    .page-head .page-meta {
        font-size: 0.8125rem;
        color: var(--gray-500);
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 9999px;
        padding: 0.4rem 0.85rem;
        white-space: nowrap;
    }

    /* Grouped sections (e.g. the two lower requirement lists) */
    .dashboard-section {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .dashboard-section + .dashboard-section {
        margin-top: 0;
    }

    .section-heading {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.25rem 1rem;
        margin: 0;
        padding: 0 0.25rem;
    }

    .section-heading .section-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.01em;
        margin: 0;
    }

    .section-heading .section-title svg {
        color: var(--gray-400);
        flex-shrink: 0;
        width: 18px;
        height: 18px;
    }

    .section-heading .section-sub {
        font-size: 0.8125rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    /* Two-column helper (teacher + quick actions only) */
    .two-col-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        align-items: start;
    }

    /* --------------------------------------------
       STATS GRID (KPI Cards)
    -------------------------------------------- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem;
    }

    .stat-card {
        background: var(--white);
        border-radius: 12px;
        padding: 1rem 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        transition: all 0.2s ease;
        border: 1px solid #e5e7eb;
        position: relative;
        overflow: hidden;
    }

    .stat-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--stat-color, var(--primary));
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        border-color: var(--stat-color);
    }

    .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
        background: var(--stat-bg, #f3f4f6);
        color: var(--stat-color, #3b82f6);
    }

    .stat-icon svg {
        width: 20px;
        height: 20px;
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.05);
    }

    .stat-info h3 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gray-900);
        line-height: 1;
        margin: 0 0 0.25rem 0;
    }

    .stat-info p {
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--gray-500);
        margin: 0;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-card.department { --stat-color: #3b82f6; --stat-bg: #dbeafe; }
    .stat-card.teacher { --stat-color: #8b5cf6; --stat-bg: #ede9fe; }
    .stat-card.staff { --stat-color: #0d9488; --stat-bg: #ccfbf1; }
    .stat-card.pending { --stat-color: #f59e0b; --stat-bg: #fef3c7; }
    .stat-card.approved { --stat-color: #22c55e; --stat-bg: #dcfce7; }
    .stat-card.rejected { --stat-color: #ef4444; --stat-bg: #fee2e2; }
    .stat-card.submitted { --stat-color: #64748b; --stat-bg: #f1f5f9; }

    /* --------------------------------------------
       QUICK ACTIONS — uniform white list, quiet icons
    -------------------------------------------- */
    .quick-actions-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.625rem;
        width: 100%;
    }

    .action-card {
        position: relative;
        background: var(--white);
        border-radius: 12px;
        padding: 0.8rem 0.9rem;
        color: var(--gray-800);
        text-decoration: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-xs);
        cursor: pointer;
        overflow: hidden;
    }

    .action-card .action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 9px;
        flex-shrink: 0;
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .action-card[data-action="pending-review"] .action-icon { background: #fef3c7; color: #b45309; }
    .action-card[data-action="rejected-direct"] .action-icon { background: #ecfdf5; color: #047857; }
    .action-card[data-action="my-requirements"] .action-icon { background: #eff6ff; color: #1d4ed8; }
    .action-card[data-action="teacher-pending"] .action-icon { background: #f5f3ff; color: #6d28d9; }
    .action-card[data-action="unassigned-requirements"] .action-icon { background: #f1f5f9; color: #475569; }

    .action-card .action-icon svg {
        width: 1.15rem;
        height: 1.15rem;
        display: block;
    }

    .action-card .action-body {
        display: flex;
        flex-direction: column;
        min-width: 0;
        flex: 1;
    }

    .action-card .action-title {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gray-900);
        line-height: 1.25;
    }

    .action-card .action-desc {
        font-size: 0.78rem;
        color: var(--gray-500);
        line-height: 1.35;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .action-card .action-arrow {
        display: inline-flex;
        flex-shrink: 0;
        color: var(--gray-300);
        transition: transform 0.15s ease, color 0.15s ease;
    }

    .action-card:hover {
        border-color: var(--gray-300);
        box-shadow: var(--shadow-sm);
    }

    .action-card:hover .action-arrow {
        transform: translateX(2px);
        color: var(--gray-500);
    }

    .notification-badge {
        position: absolute;
        top: 0.6rem;
        right: 0.6rem;
        background: var(--danger);
        color: white;
        border-radius: 9999px;
        padding: 0.1rem 0.45rem;
        font-size: 0.6875rem;
        font-weight: 700;
        min-width: 1.25rem;
        text-align: center;
        line-height: 1.3;
        box-shadow: var(--shadow-sm);
    }

    /* --------------------------------------------
       CARDS — single clean container style
    -------------------------------------------- */
    .card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: box-shadow 0.15s ease;
        box-shadow: var(--shadow-xs);
    }

    .card:hover {
        box-shadow: var(--shadow-sm);
    }

    .card-plain {
        background: transparent;
        border: none;
        box-shadow: none;
    }

    .card-plain:hover {
        box-shadow: none;
    }

    .card-plain .card-header {
        background: transparent;
        border-bottom: none;
        padding: 0 0.25rem 0.75rem;
    }

    .card-header {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
        background: var(--white);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.625rem 1rem;
    }

    .card-header h3 {
        font-size: 0.95rem;
        font-weight: 650;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        letter-spacing: -0.01em;
    }

    .card-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--gray-400);
        flex-shrink: 0;
    }

    .card-header .card-sub {
        font-size: 0.8rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    .card-content {
        padding: 0;
    }

    .card-content.no-padding {
        padding: 0;
    }

    .card-content.padded {
        padding: 1.25rem;
    }

    /* --------------------------------------------
       TABLES — compact, scannable
    -------------------------------------------- */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }

    .data-table th {
        text-align: left;
        padding: 0.65rem 1.25rem;
        font-size: 0.7rem;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-500);
        background: #f8fafc;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .data-table td {
        padding: 0.8rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
        color: var(--gray-700);
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    .data-table tr:hover td {
        background: #f8fafc;
    }

    .cell-title {
        font-weight: 600;
        color: var(--gray-900);
        font-size: 0.875rem;
        line-height: 1.35;
    }

    .cell-sub {
        font-size: 0.78rem;
        color: var(--gray-500);
        margin-top: 0.15rem;
        line-height: 1.35;
    }

    /* Badges — text-only, quiet */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.73rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
    }

    .badge svg {
        width: 12px;
        height: 12px;
    }

    .badge-success {
        background: #ecfdf5;
        color: #047857;
    }

    .badge-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .badge-info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .badge-progress {
        background: var(--primary-light);
        color: var(--primary-dark);
    }

    .badge-neutral {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    /* Progress Bar */
    .progress-bar {
        background: var(--gray-100);
        border-radius: 9999px;
        height: 8px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 9999px;
        transition: width 0.3s ease;
    }

    .progress-fill.green {
        background: var(--success);
    }

    .progress-fill.yellow {
        background: var(--warning);
    }

    .progress-fill.red {
        background: var(--danger);
    }

    /* Teacher Progress Overview */
    .progress-group + .progress-group {
        border-top: 1px solid var(--gray-200);
    }
    .progress-stack {
        display: flex;
        width: 100%;
        height: 10px;
        border-radius: 9999px;
        overflow: hidden;
        background: var(--gray-100);
    }
    .progress-stack span {
        height: 100%;
        transition: width 0.3s ease;
    }
    .progress-stack .seg-approved { background: var(--success); }
    .progress-stack .seg-inprogress { background: var(--primary); }
    .progress-stack .seg-pending { background: var(--warning); }
    .progress-stack .seg-rejected { background: var(--danger); }

    .completion-banner {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-weight: 700;
        font-size: 0.85rem;
        border-radius: 9999px;
        padding: 0.25rem 0.75rem;
        white-space: nowrap;
    }
    .completion-banner.banner-green { background: var(--success-light); color: #0b6b4c; }
    .completion-banner.banner-yellow { background: var(--warning-light); color: #b45309; }
    .completion-banner.banner-red { background: var(--danger-light); color: #b91c1c; }

    .teacher-progress-cell {
        min-width: 260px;
    }

    /* --------------------------------------------
       BUTTONS (Modern, consistent)
    -------------------------------------------- */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.5;
        white-space: nowrap;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8125rem;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-sm);
    }

    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
        border-color: var(--gray-200);
    }
    .btn-secondary:hover {
        background: var(--gray-200);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }
    .btn-success:hover {
        background: #0e9f6e;
        transform: translateY(-1px);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }
    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    .btn-outline {
        background: transparent;
        border-color: var(--gray-300);
        color: var(--gray-700);
    }
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }

    .btn-warning {
        background: var(--warning);
        color: white;
    }
    .btn-warning:hover {
        background: #d97706;
        transform: translateY(-1px);
    }

    /* --------------------------------------------
       FORMS & INPUTS
    -------------------------------------------- */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gray-700);
    }

    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: 0.9375rem;
        transition: var(--transition);
        background: var(--white);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    textarea.form-control {
        resize: vertical;
    }

    /* --------------------------------------------
       MODALS (Smooth overlay)
    -------------------------------------------- */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .modal-content {
        background: var(--white);
        border-radius: var(--radius-lg);
        width: 100%;
        max-width: 600px;
        box-shadow: var(--shadow-xl);
        animation: slideUp 0.2s ease;
    }

    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--gray-900);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--gray-400);
        transition: var(--transition);
        line-height: 1;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
    }

    .close-btn:hover {
        color: var(--gray-600);
        background: var(--gray-100);
    }

    /* --------------------------------------------
       WORKFLOW CHOICE (Manage Requirement modal)
    -------------------------------------------- */
    .modal-req-name {
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--gray-700);
        margin-bottom: 0.5rem;
    }

    .req-rejection-text {
        color: #b91c1c;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .modal-section-title {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--gray-700);
        margin-bottom: 0.5rem;
    }

    .workflow-choice {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin: 1rem 0;
    }

    .choice-card {
        border: 2px solid var(--gray-200);
        border-radius: 16px;
        padding: 1.7rem 1.25rem;
        text-align: center;
        cursor: pointer;
        background: var(--white);
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
    }

    .choice-card:hover {
        border-color: #bfdbfe;
        transform: translateY(-3px);
        box-shadow: 0 14px 28px -16px rgba(37, 99, 235, 0.3);
    }

    .choice-card.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .choice-icon {
        display: flex;
        justify-content: center;
        margin-bottom: 0.6rem;
    }

    .choice-icon svg {
        width: 40px;
        height: 40px;
        padding: 0.65rem;
        border-radius: 14px;
        background: var(--primary-light);
        color: var(--primary);
        transition: background 0.2s ease, color 0.2s ease;
        margin: 0;
        box-sizing: content-box;
    }

    .choice-card.selected .choice-icon svg {
        background: var(--primary);
        color: #ffffff;
    }

    .choice-title {
        font-weight: 800;
        font-size: 1.02rem;
        display: block;
        color: var(--gray-900);
        margin-bottom: 0.3rem;
    }

    .choice-desc {
        font-size: 0.8rem;
        color: var(--gray-500);
        line-height: 1.45;
    }

    .modal-actions {
        display: flex;
        gap: 0.6rem;
        justify-content: flex-end;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }

    /* --------------------------------------------
       FLASH MESSAGES (Toast style)
    -------------------------------------------- */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-md);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.9375rem;
        border-left: 5px solid;
        margin-bottom: 0;
        animation: slideInRight 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .alert .alert-icon {
        display: inline-flex;
        flex-shrink: 0;
    }

    .alert .alert-icon svg {
        width: 20px;
        height: 20px;
    }

    .alert .alert-text {
        flex: 1;
        min-width: 0;
    }

    .alert .alert-close {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1.25rem;
        line-height: 1;
        color: inherit;
        opacity: 0.55;
        padding: 0 0.25rem;
        border-radius: 6px;
        transition: opacity 0.15s ease;
    }

    .alert .alert-close:hover {
        opacity: 1;
    }

    .alert .alert-close:focus-visible {
        outline: 2px solid currentColor;
        outline-offset: 2px;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(20px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .alert-success {
        background: var(--success-light);
        color: #0b6b4c;
        border-left-color: var(--success);
    }

    .alert-danger {
        background: var(--danger-light);
        color: #b91c1c;
        border-left-color: var(--danger);
    }

    /* --------------------------------------------
       EMPTY STATES
    -------------------------------------------- */
    .empty-state {
        text-align: center;
        padding: 2.5rem 1.5rem;
        color: var(--gray-500);
    }

    .empty-state-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 0.75rem;
        border-radius: 16px;
        background: var(--gray-100);
        color: var(--gray-400);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 1;
    }

    .empty-state-icon svg {
        width: 26px;
        height: 26px;
    }

    .empty-state p {
        margin: 0 0 0.25rem;
        font-weight: 600;
        color: var(--gray-600);
    }

    .empty-state small {
        display: block;
        font-size: 0.8125rem;
        color: var(--gray-500);
    }

    .empty-state .empty-action {
        display: inline-flex;
        margin-top: 1rem;
    }

    /* --------------------------------------------
       UTILITIES + LAYOUT GRIDS
    -------------------------------------------- */
    .teacher-quick-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
    }

    .flex {
        display: flex;
    }
    .flex-1 {
        flex: 1 1 0%;
    }
    .items-center {
        align-items: center;
    }
    .gap-2 {
        gap: 0.5rem;
    }
    .gap-4 {
        gap: 1rem;
    }
    .flex-wrap {
        flex-wrap: wrap;
    }
    .justify-end {
        justify-content: flex-end;
    }
    .justify-between {
        justify-content: space-between;
    }
    .font-medium {
        font-weight: 500;
    }
    .font-semibold {
        font-weight: 600;
    }
    .text-sm {
        font-size: 0.875rem;
    }
    .text-gray-500 {
        color: var(--gray-500);
    }
    .text-danger {
        color: var(--danger);
    }
    .ml-2 {
        margin-left: 0.5rem;
    }
    .mt-2 {
        margin-top: 0.5rem;
    }
    .mb-4 {
        margin-bottom: 1rem;
    }

    /* --------------------------------------------
       RESPONSIVE — keep hierarchy at every width
    -------------------------------------------- */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 992px) {
        .teacher-quick-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.5rem;
            gap: 1rem;
        }
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .page-head h1 {
            font-size: 1.2rem;
        }
        .workflow-choice {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .data-table th, .data-table td {
            padding: 0.75rem 1rem;
        }
        .card-header {
            padding: 1rem;
        }
    }

    /* Teacher row — avatar + clean progress */
    .teacher-cell {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
    }

    .teacher-avatar {
        width: 36px;
        height: 36px;
        border-radius: 9999px;
        background: var(--primary-light);
        color: var(--primary-dark);
        font-weight: 700;
        font-size: 0.82rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .teacher-meta {
        min-width: 0;
    }

    .teacher-progress-cell {
        min-width: 200px;
    }

    /* Rejection reason highlight — single quiet style */
    .rejection-reason {
        background: #fef2f2;
        padding: 0.5rem 0.7rem;
        border-radius: 8px;
        font-size: 0.8rem;
        line-height: 1.4;
        border-left: 3px solid var(--danger);
        color: #b91c1c;
        max-width: 340px;
    }

    /* --------------------------------------------
       Rejected Requirements — admin "Expired" card design
    -------------------------------------------- */
    .rejected-section {
        background: var(--white);
        border: 1px solid #fecaca;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: var(--shadow-xs);
    }

    .rejected-topbar {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 1rem 1.15rem;
        background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
        border-bottom: 1px solid #fecaca;
    }

    .rejected-topbar .rejected-icon {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25);
    }
    .rejected-topbar .rejected-icon svg { width: 20px; height: 20px; }

    .rejected-topbar .rejected-meta {
        display: flex;
        flex-direction: column;
        min-width: 0;
        gap: 0.1rem;
        flex: 1;
    }
    .rejected-topbar .rejected-meta h3 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.015em;
        line-height: 1.25;
    }
    .rejected-topbar .rejected-meta p {
        margin: 0;
        font-size: 0.75rem;
        color: var(--gray-500);
        font-weight: 400;
        line-height: 1.3;
    }

    .rejected-topbar .rejected-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 26px;
        padding: 0 0.6rem;
        background: var(--danger);
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        border-radius: 9999px;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
        font-variant-numeric: tabular-nums;
        flex-shrink: 0;
    }

    .rejected-toggle-btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--white);
        border: 1px solid var(--gray-200);
        border-radius: 9px;
        color: var(--gray-500);
        cursor: pointer;
        transition: all 0.15s;
        padding: 0;
        box-shadow: var(--shadow-xs);
        flex-shrink: 0;
    }
    .rejected-toggle-btn:hover { background: var(--gray-50); color: var(--gray-700); border-color: var(--gray-300); }
    .rejected-toggle-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .rejected-toggle-btn svg { width: 16px; height: 16px; transition: transform 0.25s ease; }
    .rejected-section.collapsed .rejected-toggle-btn svg { transform: rotate(-90deg); }

    .rejected-body {
        max-height: 8000px;
        opacity: 1;
        transition: max-height 0.35s ease, opacity 0.25s ease;
        overflow: hidden;
    }
    .rejected-section.collapsed .rejected-body { max-height: 0; opacity: 0; }

    /* Column header */
    .rejected-col-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 160px 160px;
        gap: 1rem;
        align-items: center;
        padding: 0.5rem 1.15rem 0.5rem 2.75rem;
        background: var(--gray-50);
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--gray-400);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        border-bottom: 1px solid var(--gray-200);
    }
    .rejected-col-header .col-date { text-align: right; }
    .rejected-col-header .col-actions { text-align: right; }

    /* Item row */
    .rejected-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 160px 160px;
        gap: 1rem;
        align-items: center;
        padding: 0.85rem 1.15rem 0.85rem 2.75rem;
        border-top: 1px solid var(--gray-100);
        transition: background 0.15s;
        position: relative;
    }
    .rejected-item:first-of-type { border-top: none; }
    .rejected-item::before {
        content: '';
        position: absolute;
        left: 1.15rem;
        top: 50%;
        transform: translateY(-50%);
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--danger);
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12);
    }
    .rejected-item:hover { background: #fef8f8; }

    .rejected-item .item-info {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        min-width: 0;
    }
    .rejected-item .item-doctype {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gray-900);
        line-height: 1.35;
        overflow-wrap: break-word;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }
    .rejected-item .item-sub {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
        font-size: 0.75rem;
        color: var(--gray-500);
        line-height: 1.35;
    }

    .rejected-item .item-date {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        white-space: nowrap;
        gap: 0.25rem;
    }
    .rejected-item .item-date .date {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-weight: 700;
        font-size: 0.78rem;
        color: #b91c1c;
        background: var(--danger-light);
        border: 1px solid #fecaca;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-variant-numeric: tabular-nums;
        line-height: 1.3;
    }
    .rejected-item .item-date .time-ago {
        font-size: 0.7rem;
        color: var(--gray-500);
        font-variant-numeric: tabular-nums;
    }

    .rejected-item .item-actions {
        display: flex;
        gap: 0.4rem;
        justify-content: flex-end;
        align-items: center;
        flex-wrap: wrap;
    }

    .rejected-item .item-actions .btn-sm {
        padding: 0.4rem 0.75rem;
        font-size: 0.78rem;
        border-radius: 7px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.15s;
        cursor: pointer;
        white-space: nowrap;
        border: 1px solid transparent;
        line-height: 1.2;
        font-family: inherit;
    }
    .rejected-item .item-actions .btn-sm svg { width: 13px; height: 13px; }
    .rejected-item .item-actions .btn-resubmit {
        background: var(--primary);
        color: #fff;
        box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);
        /* icon-only, same box as .btn-ghost so the three actions line up */
        width: 32px;
        padding: 0;
        height: 32px;
    }
    .rejected-item .item-actions .btn-resubmit:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
    }
    .rejected-item .item-actions .btn-ghost {
        background: var(--white);
        color: var(--gray-500);
        border-color: var(--gray-200);
        width: 32px;
        padding: 0;
        height: 32px;
    }
    .rejected-item .item-actions .btn-ghost:hover {
        background: var(--danger-light);
        color: var(--danger);
        border-color: #fecaca;
    }
    .rejected-item .item-actions .btn-delete {
        background: var(--white);
        color: var(--gray-500);
        border-color: var(--gray-200);
        width: 32px;
        padding: 0;
        height: 32px;
    }
    .rejected-item .item-actions .btn-delete:hover {
        background: var(--danger-light);
        color: var(--danger);
        border-color: #fecaca;
        transform: translateY(-1px);
    }
    .rejected-item .item-actions .btn-delete:focus-visible {
        outline: 2px solid var(--danger);
        outline-offset: 2px;
    }

    /* ---- Tablet (≤ 900px) ---- */
    @media (max-width: 900px) {
        .rejected-col-header .col-date,
        .rejected-col-header .col-actions { display: none; }
        .rejected-col-header { grid-template-columns: 1fr; }

        .rejected-item {
            grid-template-columns: minmax(0, 1fr) auto;
            grid-template-areas:
                "info actions"
                "date actions";
            gap: 0.4rem 1rem;
            padding: 0.85rem 1.15rem 0.85rem 2.5rem;
            align-items: center;
        }
        .rejected-item::before { left: 1rem; }
        .rejected-item .item-info { grid-area: info; }
        .rejected-item .item-date {
            grid-area: date;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 0.5rem;
        }
        .rejected-item .item-actions {
            grid-area: actions;
            align-self: center;
        }
    }

    /* ---- Mobile (≤ 560px) ---- */
    @media (max-width: 560px) {
        .rejected-topbar { padding: 0.85rem 0.95rem; gap: 0.6rem; }
        .rejected-topbar .rejected-icon { width: 36px; height: 36px; border-radius: 10px; }
        .rejected-topbar .rejected-icon svg { width: 18px; height: 18px; }
        .rejected-topbar .rejected-meta h3 { font-size: 0.875rem; }
        .rejected-topbar .rejected-meta p { font-size: 0.7rem; }

        .rejected-item {
            grid-template-columns: 1fr;
            grid-template-areas:
                "info"
                "date"
                "actions";
            gap: 0.6rem;
            padding: 0.9rem 0.95rem 0.9rem 2.4rem;
        }
        .rejected-item::before { left: 0.95rem; }
        .rejected-item .item-date {
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 0.5rem;
        }
        .rejected-item .item-actions {
            justify-content: flex-start;
            align-self: auto;
        }
    }

    /* Overdue / expired row emphasis (keeps hover visible) */
    .row-overdue td {
        background: #fef2f2;
    }
    .data-table tr.row-overdue:hover td {
        background: #fee2e2;
    }

    /* Focus visibility (WCAG 2.2) for interactive elements */
    .action-card:focus-visible,
    .btn:focus-visible,
    .close-btn:focus-visible,
    .stat-card:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Respect reduced motion */
    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
            scroll-behavior: auto !important;
        }
        .notification-badge {
            animation: none;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<main class="dashboard-container">
    <!-- Page header — one clear title for the whole page -->
  
    <!-- Flash Messages -->

    <?php if ($flashError): ?>
        <div class="alert alert-danger" role="alert">
            <span class="alert-icon"><?= render_dashboard_icon('x-circle') ?></span>
            <span class="alert-text"><?= htmlspecialchars($flashError) ?></span>
            <button type="button" class="alert-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <?php include 'includes/success_toast.php'; ?>

    <!-- Stats Overview (KPI Cards) -->
    <div class="stats-grid">
        <div class="stat-card teacher" title="Teacher accounts in your department">
            <div class="stat-icon"><?= render_dashboard_icon('teacher') ?></div>
            <div class="stat-info">
                <h3><?= $facultyCount ?></h3>
                <p>Faculty</p>
            </div>
        </div>
        <div class="stat-card staff" title="Staff accounts in your department">
            <div class="stat-icon"><?= render_dashboard_icon('users') ?></div>
            <div class="stat-info">
                <h3><?= $staffCount ?></h3>
                <p>Staff</p>
            </div>
        </div>
        <div class="stat-card pending" title="Submissions awaiting your review">
            <div class="stat-icon"><?= render_dashboard_icon('clock') ?></div>
            <div class="stat-info">
                <h3><?= $pendingReviewCount ?></h3>
                <p>Pending Review</p>
            </div>
        </div>
        <div class="stat-card submitted" title="Documents forwarded to Admin">
            <div class="stat-icon"><?= render_dashboard_icon('upload') ?></div>
            <div class="stat-info">
                <h3><?= $forwardedCount ?></h3>
                <p>Forwarded to Admin</p>
            </div>
        </div>
        <div class="stat-card approved" title="Documents approved">
            <div class="stat-icon"><?= render_dashboard_icon('check') ?></div>
            <div class="stat-info">
                <h3><?= $approvedCount ?></h3>
                <p>Approved</p>
            </div>
        </div>
        <div class="stat-card department" title="Total requirements assigned by Admin">
            <div class="stat-icon"><?= render_dashboard_icon('clipboard') ?></div>
            <div class="stat-info">
                <h3><?= $totalReqs ?></h3>
                <p>Total Requirements</p>
            </div>
        </div>
    </div>

    <!-- ==========================================================
     REJECTED REQUIREMENTS — unified section for all 3 sources
     ========================================================== -->
<?php if (!empty($rejectedAll)): ?>
    <div class="rejected-section" id="rejectedCard">
        <div class="rejected-topbar">
            <div class="rejected-icon">
                <?= render_dashboard_icon('alert') ?>
            </div>
            <div class="rejected-meta">
                <h3>Rejected Requirements</h3>
                <p>Sent back by Admin &mdash; review the reason and resubmit</p>
            </div>
            <span class="rejected-badge" id="rejectedCount"><?= count($rejectedAll) ?></span>
            <button type="button" class="rejected-toggle-btn" id="rejectedToggle" aria-expanded="true" aria-controls="rejectedBody" title="Collapse section" aria-label="Collapse Rejected Requirements section">
                <?= render_dashboard_icon('chevron-down') ?>
            </button>
        </div>
        <div class="rejected-body" id="rejectedBody">
            <div class="rejected-col-header">
                <span>Document</span>
                <span class="col-date">Rejected Date</span>
                <span class="col-actions">Actions</span>
            </div>
            <?php foreach ($rejectedAll as $item): ?>
            <div class="rejected-item" data-id="<?= (int)$item['id'] ?>">
                <div class="item-info">
                    <span class="item-doctype">
                        <span title="<?= htmlspecialchars($item['doc_title'] ?? '') ?>"><?= sanitize($item['doc_title'] ?? 'Unknown') ?></span>
                        <?php if (isset($item['version'])): ?>
                            <span class="badge badge-neutral">v<?= (int)$item['version'] ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="item-sub">
                        <span><?= sanitize($item['doc_category'] ?? '—') ?></span>
                        <?php if ($item['source_label'] !== ''): ?>
                            <span class="badge <?= sanitize($item['source_badge_class']) ?>"><?= sanitize($item['source_label']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="item-date">
                    <span class="date"><?= !empty($item['rejected_at']) ? date('M j, Y', strtotime($item['rejected_at'])) : '—' ?></span>
                    <span class="time-ago"><?= !empty($item['rejected_at']) ? timeAgo($item['rejected_at']) : '' ?></span>
                </div>
                <div class="item-actions">
                    <?php if (!empty($item['rejection_reason'])): ?>
                        <button type="button" class="btn btn-sm btn-ghost"
                            data-csp-action="openReasonModal" data-csp-args="<?= htmlspecialchars(json_encode([$item['rejection_reason'] ?? '']), ENT_QUOTES, 'UTF-8') ?>"
                            title="View rejection reason"
                            aria-label="View rejection reason for <?= htmlspecialchars($item['doc_title'] ?? '') ?>">
                            <?= render_dashboard_icon('alert') ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-ghost view-btn"
                        data-id="<?= (int)$item['id'] ?>" data-type="document" data-title="<?= htmlspecialchars($item['doc_title'] ?? '') ?>"
                        data-filename="<?= htmlspecialchars($item['filename'] ?? '') ?>" title="View document"
                        aria-label="View <?= htmlspecialchars($item['doc_title'] ?? '') ?>">
                        <?= render_dashboard_icon('eye') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-resubmit"
                        data-csp-action="openReqModal" data-csp-args="<?= htmlspecialchars(json_encode([(int)$item['id'], $item['doc_title'] ?? '', ($item['source'] === 'teacher_assigned' || $item['source'] === 'repository') ? 1 : 0]), ENT_QUOTES, 'UTF-8') ?>"
                        title="Resubmit document"
                        aria-label="Resubmit <?= htmlspecialchars($item['doc_title'] ?? '') ?>">
                        <?= render_dashboard_icon('reupload') ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-delete"
                        data-csp-action="confirmDelete" data-csp-args="<?= htmlspecialchars(json_encode([(int)$item['id'], $item['doc_title'] ?? '']), ENT_QUOTES, 'UTF-8') ?>"
                        title="Delete document"
                        aria-label="Delete <?= htmlspecialchars($item['doc_title'] ?? '') ?>">
                        <?= render_dashboard_icon('trash') ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

    <!-- Teacher Progress + Quick Actions -->
    <div class="teacher-quick-grid">
        <!-- Teacher Progress Overview -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('teacher') ?> Progress Overview</h3>
                <a href="program_head_teachers.php" class="btn btn-sm btn-outline">View All</a>
            </div>
            <div class="card-content no-padding">
                <?php if (empty($teachers)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?= render_dashboard_icon('users') ?></div>
                        <p>No Members found in your department.</p>
                        <small>Once faculty accounts are added, their submission progress will appear here.</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($teachersByRole as $groupLabel => $groupList):
                        if (empty($groupList)) continue; ?>
                        <div class="progress-group">
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr><th><?= sanitize($groupLabel) ?></th><th>Completion</th><th>Status Breakdown</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groupList as $t):
                                    $p = $t['progress'];
                                    $pct = $p['percentage'];
                                    $total = $p['total'];
                                    $approved = $p['approved'];
                                    $inProgress = $p['submitted'];
                                    $rejected = $p['rejected'];
                                    $pending = max(0, $total - $approved - $inProgress - $rejected);
                                    $segApproved = $total > 0 ? round($approved / $total * 100) : 0;
                                    $segInProgress = $total > 0 ? round($inProgress / $total * 100) : 0;
                                    $segPending = $total > 0 ? round($pending / $total * 100) : 0;
                                    $segRejected = $total > 0 ? round($rejected / $total * 100) : 0;

                                    if ($total === 0) {
                                        $bannerClass = 'banner-yellow';
                                        $bannerLabel = 'No assignments yet';
                                    } elseif ($pct >= 80) {
                                        $bannerClass = 'banner-green';
                                        $bannerLabel = $pct . '% Complete';
                                    } elseif ($pct >= 50) {
                                        $bannerClass = 'banner-yellow';
                                        $bannerLabel = $pct . '% Complete';
                                    } else {
                                        $bannerClass = 'banner-red';
                                        $bannerLabel = $pct . '% Complete';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="teacher-cell">
                                            <div class="teacher-avatar" aria-hidden="true"><?= strtoupper(substr(trim($t['name']), 0, 1)) ?></div>
                                            <div class="teacher-meta">
                                                <div class="cell-title"><?= sanitize($t['name']) ?></div>
                                                <div class="cell-sub"><?= sanitize($t['email']) ?></div>
                                                <div class="cell-sub"><?= $total ?> requirement<?= $total !== 1 ? 's' : '' ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="teacher-progress-cell">
                                            <div class="flex items-center gap-2" style="margin-bottom:0.6rem;">
                                                <div class="progress-stack flex-1" title="<?= $approved ?> approved · <?= $inProgress ?> in progress · <?= $pending ?> pending · <?= $rejected ?> rejected">
                                                    <span class="seg-approved" style="width: <?= $segApproved ?>%"></span>
                                                    <span class="seg-inprogress" style="width: <?= $segInProgress ?>%"></span>
                                                    <span class="seg-pending" style="width: <?= $segPending ?>%"></span>
                                                    <span class="seg-rejected" style="width: <?= $segRejected ?>%"></span>
                                                </div>
                                                <span class="completion-banner <?= $bannerClass ?>"><?= $bannerLabel ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex gap-2 flex-wrap">
                                            <?php if ($approved > 0): ?><span class="badge badge-success"><?= $approved ?> approved</span><?php endif; ?>
                                            <?php if ($inProgress > 0): ?><span class="badge badge-progress"><?= $inProgress ?> in progress</span><?php endif; ?>
                                            <?php if ($pending > 0): ?><span class="badge badge-warning"><?= $pending ?> pending</span><?php endif; ?>
                                            <?php if ($rejected > 0): ?><span class="badge badge-danger"><?= $rejected ?> rejected</span><?php endif; ?>
                                            <?php if ($total === 0): ?><span class="badge badge-neutral">No data</span><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card card-plain">
            <div class="card-header">
                <h3><?= render_dashboard_icon('clipboard') ?> Quick Actions</h3>
            </div>
            <div class="quick-actions-grid">
                <a href="program_head_pending.php" class="action-card" data-action="pending-review">
                    <?php if ($newPendingCount > 0): ?>
                        <span class="notification-badge"><?= $newPendingCount ?></span>
                    <?php endif; ?>
                    <div class="action-icon"><?= render_dashboard_icon('clock') ?></div>
                    <div class="action-body">
                        <div class="action-title">Pending Reviews</div>
                        <div class="action-desc">Review faculty submissions</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>

                <a href="program_head_create_assign.php" class="action-card" data-action="rejected-direct">
                    <div class="action-icon"><?= render_dashboard_icon('upload') ?></div>
                    <div class="action-body">
                        <div class="action-title">Direct Upload</div>
                        <div class="action-desc">Upload documents directly</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>

                <a href="program_head_requirements.php" class="action-card" data-action="my-requirements">
                    <?php if ($newRequirementsCount > 0): ?>
                        <span class="notification-badge"><?= $newRequirementsCount ?></span>
                    <?php endif; ?>
                    <div class="action-icon"><?= render_dashboard_icon('clipboard') ?></div>
                    <div class="action-body">
                        <div class="action-title">My Requirements</div>
                        <div class="action-desc">Manage requirements</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>

                <a href="program_head_teachers.php" class="action-card" data-action="teacher-pending">
                    <div class="action-icon"><?= render_dashboard_icon('teacher') ?></div>
                    <div class="action-body">
                        <div class="action-title">Manage Faculty</div>
                        <div class="action-desc">View & assign tasks</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>

                <a href="program_head_assign.php" class="action-card" data-action="unassigned-requirements">
                    <?php if ($unassignedRequirementsCount > 0): ?>
                        <span class="notification-badge"><?= $unassignedRequirementsCount ?></span>
                    <?php endif; ?>
                    <div class="action-icon"><?= render_dashboard_icon('link') ?></div>
                    <div class="action-body">
                        <div class="action-title">Assign Tasks</div>
                        <div class="action-desc">Link requirements to faculty</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>
            </div>
        </div>
    </div>

    <!-- Requirements Overview -->
    <div class="dashboard-section">
        <div class="section-heading">
            <h2 class="section-title"><?= render_dashboard_icon('clipboard') ?> Requirements Overview</h2>

        </div>

        <!-- My Created Requirements — separate row -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('pin') ?> My Created Requirements</h3>
                <a href="program_head_create_assign.php" class="btn btn-sm btn-outline"><?= render_dashboard_icon('plus') ?> Create New</a>
            </div>
        <div class="card-content no-padding">
            <?php if (empty($phRequirements)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><?= render_dashboard_icon('clipboard') ?></div>
                    <p>No requirements created yet.</p>
                    <small>Create a requirement to assign to your faculty.</small>
                    <a href="program_head_create_assign.php" class="btn btn-sm btn-primary empty-action"><?= render_dashboard_icon('plus') ?> Create New</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Title</th><th>Created</th><th>Deadline</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($phRequirements, 0, 5) as $req): ?>
                            <tr class="<?= $req['is_expired'] ? 'row-overdue' : '' ?>">
                                <td><span class="cell-title"><?= sanitize($req['title']) ?></span><?php if ($req['is_expired']): ?> <span class="badge badge-danger ml-2">Expired</span><?php elseif (!empty($req['is_expiring'])): ?> <span class="badge badge-warning ml-2">Expiring</span><?php endif; ?></td>
                                <td style="white-space:nowrap;"><?= !empty($req['created_at']) ? date('M j, Y', strtotime($req['created_at'])) : '—' ?></td>
                                <td style="white-space:nowrap;"><?= $req['deadline'] ? date('M j, Y', strtotime($req['deadline'])) : '—' ?><?php if (!empty($req['deadline']) && !$req['is_expired'] && empty($req['is_expiring'])): ?> <span class="badge badge-success ml-2">Active</span><?php endif; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Admin-Assigned Requirements — separate row -->
    <div class="card">
        <div class="card-header">
            <h3><?= render_dashboard_icon('building') ?> Admin-Assigned Requirements</h3>
            <a href="program_head_requirements.php?dept=<?= $deptId ?>" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-content no-padding">
            <?php if (empty($requirements)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><?= render_dashboard_icon('clipboard') ?></div>
                    <p>No requirements assigned by Admin yet.</p>
                    <small>Admin-assigned requirements will appear here once they are published.</small>
                </div>
            <?php else: ?>
                <div class="table-responsive" style="max-height:26rem; overflow-y:auto;">
                    <table class="data-table">
                        <thead><tr><th>Category</th><th>Document Type</th><th>Expiration</th></tr></thead>
                        <tbody>
                            <?php foreach ($requirements as $req): ?>
                            <tr class="<?= $req['is_expired'] ? 'row-overdue' : '' ?>">
                                <td class="cell-title"><?= sanitize($req['category']) ?></td>
                                <td><?= sanitize($req['document_type']) ?></td>
                                <td style="white-space:nowrap;"><?php if (!empty($req['expiration_date'])): ?><?= date('M j, Y', strtotime($req['expiration_date'])) ?><?php if ($req['is_expired']): ?> <span class="badge badge-danger ml-2">Expired</span><?php elseif (!empty($req['is_expiring'])): ?> <span class="badge badge-warning ml-2">Expiring</span><?php else: ?> <span class="badge badge-success ml-2">Active</span><?php endif; ?><?php else: ?><span class="text-gray-500">—</span><?php endif; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div>
</main>

<!-- Rejection Reason Modal -->
<div id="reasonModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="reasonModalTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="reasonModalTitle"><?= render_dashboard_icon('x-circle') ?> Rejection Reason</h3>
            <button type="button" class="close-btn" data-modal="reasonModal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="reasonModalText" class="req-rejection-text"></div>
        </div>
    </div>
</div>

<!-- Manage Requirement Modal (same UI as program_head_requirements.php) -->
<div id="reqModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Manage Requirement</h3>
            <button type="button" class="close-btn" data-modal="reqModal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p id="modalReqName" class="modal-req-name"></p>

            <div id="workflowSelection">
                <p class="modal-section-title">How would you like to handle this requirement?</p>
                <div class="workflow-choice">
                    <div class="choice-card" id="choicePH" data-csp-action="selectPath" data-csp-args='["ph"]'>
                        <span class="choice-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <span class="choice-title">Upload Personally</span>
                        <span class="choice-desc">You will upload the document yourself.</span>
                    </div>
                    <div class="choice-card" id="choiceTeacher" data-csp-action="selectPath" data-csp-args='["teacher"]'>
                        <span class="choice-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </span>
                        <span class="choice-title">Assign to Faculty</span>
                        <span class="choice-desc">Delegate this task to a faculty member.</span>
                    </div>
                </div>
            </div>

            <div id="uploadSection" style="display:none">
                <form method="post" action="program_head_dashboard.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="resubmit_document">
                    <input type="hidden" name="doc_id" id="upload_doc_id">
                    <input type="hidden" name="doc_type" value="document">
                    <input type="hidden" name="resubmit_mode" value="self">
                    <input type="hidden" name="resubmit_title" id="upload_resubmit_title">
                    <div class="form-group">
                        <label>File <span class="text-danger">*</span></label>
                        <input type="file" name="resubmit_file" class="form-control"
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx" required>
                    </div>
                    <div class="form-group">
                        <label>Comment</label>
                        <textarea name="resubmit_description" class="form-control" rows="2" placeholder="Notes for Admin..."></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-csp-action="resetReqModal">Back</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>

            <div id="assignSection" style="display:none">
                <form method="post" action="program_head_dashboard.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="resubmit_document">
                    <input type="hidden" name="doc_id" id="assign_doc_id">
                    <input type="hidden" name="doc_type" value="document">
                    <input type="hidden" name="resubmit_mode" value="assign">
                    <input type="hidden" name="resubmit_title" id="assign_resubmit_title">
                    <div class="form-group">
                        <label>Select Faculty <span class="text-danger">*</span></label>
                        <select name="teacher_ids" class="form-control" required>
                            <option value="">-- Select Faculty --</option>
                            <?php if (!empty($assignTeachers)): ?>
                                <?php foreach ($assignTeachers as $at): ?>
                                    <option value="<?= (int)$at['id'] ?>"><?= sanitize($at['name']) ?> (<?= sanitize($at['email'] ?? '') ?>)</option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Deadline</label>
                        <div class="deadline-wrap" style="position:relative">
                            <input type="text" name="resubmit_deadline" class="form-control req-deadline" placeholder="MM/DD/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}">
                            <input type="date" class="req-deadline-picker" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer">
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-csp-action="resetReqModal">Back</button>
                        <button type="submit" class="btn btn-success">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const uploadBaseUrl = '<?= $uploadBaseUrl ?>';

    // --- Modal helpers (direct style.display, footer-proof with !important) ---
    function closeModal(el) {
        if (!el) return;
        el.style.setProperty('display', 'none', 'important');
        el.setAttribute('aria-hidden', 'true');
    }

    function openModal(el) {
        if (!el) return;
        el.style.setProperty('display', 'flex', 'important');
        el.setAttribute('aria-hidden', 'false');
    }

    function openReqModal(docId, docTitle, hasRepo) {
        docId = parseInt(docId, 10) || 0;
        docTitle = String(docTitle || '');
        hasRepo = !!parseInt(hasRepo || 0, 10);

        var modal = document.getElementById('reqModal');
        if (!modal) return;

        // Populate data (null-safe — every element guarded)
        var nameEl = document.getElementById('modalReqName');
        if (nameEl) nameEl.textContent = docTitle;

        var uploadTitle = document.getElementById('upload_resubmit_title');
        if (uploadTitle) uploadTitle.value = docTitle;
        var assignTitle = document.getElementById('assign_resubmit_title');
        if (assignTitle) assignTitle.value = docTitle;

        var titleEl = document.getElementById('modalTitle');
        if (titleEl) titleEl.textContent = 'Manage Requirement';

        var uploadId = document.getElementById('upload_doc_id');
        if (uploadId) uploadId.value = docId;

        var assignId = document.getElementById('assign_doc_id');
        if (assignId) assignId.value = docId;

        // Reset all sections, then open
        resetReqModal();
        if (!hasRepo) selectPath('ph');
        openModal(modal);
    }

    function openReasonModal(reason) {
        var modal = document.getElementById('reasonModal');
        if (!modal) return;
        var text = document.getElementById('reasonModalText');
        if (text) text.textContent = String(reason || '');
        openModal(modal);
    }

    function confirmDelete(docId, docTitle) {
        docTitle = String(docTitle || 'this document');
        if (!confirm('Delete "' + docTitle + '"?\n\nThis will archive the rejected document. This action cannot be undone.')) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'program_head_dashboard.php';

        var fields = {
            csrf_token: <?= json_encode($csrfToken) ?>,
            action: 'delete_rejected_document',
            doc_id: docId
        };
        for (var k in fields) {
            if (!Object.prototype.hasOwnProperty.call(fields, k)) continue;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            input.value = fields[k];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    }

    function selectPath(path) {
        var ws = document.getElementById('workflowSelection');
        var us = document.getElementById('uploadSection');
        var as = document.getElementById('assignSection');
        var cph = document.getElementById('choicePH');
        var ctc = document.getElementById('choiceTeacher');
        if (ws) ws.style.display = 'none';
        if (us) us.style.display = 'none';
        if (as) as.style.display = 'none';
        if (cph) cph.classList.remove('selected');
        if (ctc) ctc.classList.remove('selected');
        if (path === 'ph') {
            if (us) us.style.display = 'block';
            if (cph) cph.classList.add('selected');
        } else {
            if (as) as.style.display = 'block';
            if (ctc) ctc.classList.add('selected');
        }
    }

    function resetReqModal() {
        var ws = document.getElementById('workflowSelection');
        var us = document.getElementById('uploadSection');
        var as = document.getElementById('assignSection');
        var cph = document.getElementById('choicePH');
        var ctc = document.getElementById('choiceTeacher');
        if (ws) ws.style.display = 'block';
        if (us) us.style.display = 'none';
        if (as) as.style.display = 'none';
        if (cph) cph.classList.remove('selected');
        if (ctc) ctc.classList.remove('selected');
        var f = document.querySelector('#uploadSection input[type="file"]');
        if (f) f.value = '';
        var t = document.querySelector('#uploadSection textarea');
        if (t) t.value = '';
        var s = document.querySelector('#assignSection select');
        if (s) s.value = '';
        var d = document.querySelector('#assignSection .req-deadline');
        if (d) d.value = '';
    }

    // Close modal: X button, backdrop click, Escape key
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.close-btn[data-modal]');
        var target = btn ? document.getElementById(btn.dataset.modal) : (e.target.classList.contains('modal') ? e.target : null);
        // footer.php's global viewer closes itself (.show toggling); our display:none !important
        // would permanently trap it hidden, so never touch it here.
        if (target && target.id !== 'universalDocumentModal') closeModal(target);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var open = document.getElementById('reqModal');
            if (open && open.style.getPropertyValue('display') === 'flex') closeModal(open);
            var open3 = document.getElementById('reasonModal');
            if (open3 && open3.style.getPropertyValue('display') === 'flex') closeModal(open3);
        }
    });

    // Dismiss flash alerts
    document.querySelectorAll('.alert .alert-close').forEach(btn => {
        btn.addEventListener('click', function () {
            this.closest('.alert').remove();
        });
    });

    // Collapsible Rejected Requirements section (state remembered per browser)
    (function() {
        const card = document.getElementById('rejectedCard');
        const toggle = document.getElementById('rejectedToggle');
        if (!card || !toggle) return;

        const storageKey = 'ph_rejected_collapsed';

        function setCollapsed(collapsed) {
            card.classList.toggle('collapsed', collapsed);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', (collapsed ? 'Expand' : 'Collapse') + ' Rejected Requirements section');
            toggle.setAttribute('title', collapsed ? 'Expand section' : 'Collapse section');
            try {
                if (collapsed) localStorage.setItem(storageKey, '1');
                else localStorage.removeItem(storageKey);
            } catch (e) {}
        }

        toggle.addEventListener('click', function() {
            setCollapsed(!card.classList.contains('collapsed'));
        });

        try {
            if (localStorage.getItem(storageKey) === '1') setCollapsed(true);
        } catch (e) {}
    })();

    // Deadline date picker (matches program_head_requirements.php behavior)
    document.querySelectorAll('.deadline-wrap').forEach(function(wrap) {
        var input = wrap.querySelector('.req-deadline');
        var picker = wrap.querySelector('.req-deadline-picker');
        if (!input || !picker) return;
        picker.addEventListener('click', function() {
            var toDb = function(v) { var p = v.split('/'); return p.length === 3 ? p[2] + '-' + p[0] + '-' + p[1] : ''; };
            picker.value = input.value && input.value.includes('/') ? toDb(input.value) : '';
            picker.showPicker();
        });
        picker.addEventListener('change', function() {
            var p = picker.value.split('-');
            input.value = p.length === 3 ? p[1] + '/' + p[2] + '/' + p[0] : picker.value;
        });
    });

    // AJAX for quick action badges
    document.addEventListener('DOMContentLoaded', function () {
        const actionMap = {
            'pending-review': 'mark_pending_review',
            'my-requirements': 'mark_requirements',
            'unassigned-requirements': 'mark_unassigned_requirements'
        };
        document.querySelectorAll('.action-card[data-action]').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const action = this.dataset.action;
                const linkUrl = this.href;
                if (actionMap[action]) {
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: 'action=' + actionMap[action] + '&csrf_token=' + encodeURIComponent(<?= json_encode($csrfToken) ?>)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const badge = this.querySelector('.notification-badge');
                            if (badge) badge.remove();
                        }
                        window.location.href = linkUrl;
                    })
                    .catch(() => window.location.href = linkUrl);
                } else {
                    window.location.href = linkUrl;
                }
            });
        });

    });

    </script>

<?php include 'includes/footer.php'; ?>