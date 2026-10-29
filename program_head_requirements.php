<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();


$deptId = (int)$_SESSION['department_id'];
$userId = (int)$_SESSION['user_id'];
$currentFolderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

$error = '';
$success = '';

function isRequirementComplete(array $req): bool
{
    $totalAssigned   = (int)($req['total_assigned'] ?? 0);
    $directApproved  = (int)($req['direct_approved_count'] ?? 0);
    $directPending   = (int)($req['direct_pending_count'] ?? 0);
    $directRejected  = (int)($req['direct_rejected_count'] ?? 0);
    $latestStatuses  = array_filter(array_map('trim', explode(',', (string)($req['latest_statuses'] ?? ''))));

    if ($totalAssigned > 0) {
        // Everyone assigned must have approved, nothing in review
        $allApproved = !empty($latestStatuses)
            && !in_array('pending_program_head', $latestStatuses, true)
            && !in_array('pending_admin', $latestStatuses, true)
            && !in_array('rejected_program_head', $latestStatuses, true)
            && !in_array('rejected_admin', $latestStatuses, true);

        return count($latestStatuses) >= $totalAssigned
            && $allApproved
            && $directPending === 0
            && $directRejected === 0;
    }

  
    return $directApproved > 0 && $directPending === 0 && $directRejected === 0;
}

function resolvePhReqState(?string $docStatus, $trId, bool $isComplete = false, int $directRejected = 0, int $directPending = 0): array
{
    // Admin sees this as "Complete" → PH sees it as "Approved".
    // Read-only from the PH side: no reassign, no personal upload.
    if ($isComplete) {
        return [
            'key' => 'approved',
            'label' => 'Approved',
            'class' => 'badge-approved',
            'can_unassign' => false,
            'can_upload' => false,
            'can_assign' => false,
            'blocked' => true,
        ];
    }

    $assigned = !empty($trId);
    $rejected = in_array($docStatus, ['rejected_program_head', 'rejected_admin'], true);

    if ($rejected) {
        return [
            'key' => 'rejected',
            'label' => 'Rejected',
            'class' => 'badge-rejected',
            'can_unassign' => $assigned,
            'can_upload' => true,
            'can_assign' => !$assigned,
            'blocked' => false,
        ];
    }
    if ($docStatus === 'pending_program_head') {
        return [
            'key' => 'pending_ph',
            'label' => 'Pending ' . phRoleShort() . ' Review',
            'class' => 'badge-pending-ph',
            'can_unassign' => false,
            'can_upload' => false,
            'can_assign' => false,
            'blocked' => true,
        ];
    }
    if ($docStatus === 'pending_admin') {
        return [
            'key' => 'pending_admin',
            'label' => 'Pending Admin Review',
            'class' => 'badge-pending-admin',
            'can_unassign' => false,
            'can_upload' => false,
            'can_assign' => false,
            'blocked' => true,
        ];
    }
    if ($directRejected > 0) {
        return [
            'key' => 'rejected',
            'label' => 'Rejected',
            'class' => 'badge-rejected',
            'can_unassign' => $assigned,
            'can_upload' => true,
            'can_assign' => !$assigned,
            'blocked' => false,
        ];
    }
    if ($directPending > 0 && $docStatus !== 'pending_program_head') {
        return [
            'key' => 'pending_admin',
            'label' => 'Pending Admin Review',
            'class' => 'badge-pending-admin',
            'can_unassign' => false,
            'can_upload' => false,
            'can_assign' => false,
            'blocked' => true,
        ];
    }
    if ($docStatus === 'approved_admin' || $docStatus === 'approved_program_head') {
        return [
            'key' => 'approved',
            'label' => 'Approved',
            'class' => 'badge-approved',
            'can_unassign' => false,
            'can_upload' => false,
            'can_assign' => false,
            'blocked' => true,
        ];
    }
    if ($assigned) {
        return [
            'key' => 'assigned',
            'label' => 'Assigned',
            'class' => 'badge-assigned',
            'can_unassign' => true,
            'can_upload' => true,
            'can_assign' => false,
            'blocked' => false,
        ];
    }
    return [
        'key' => 'not_uploaded',
        'label' => 'Not Uploaded',
        'class' => 'badge-pending',
        'can_unassign' => false,
        'can_upload' => true,
        'can_assign' => true,
        'blocked' => false,
    ];
}

// Latest non-archived doc for a requirement (dept scope enforced by caller).
function getLatestReqDoc(PDO $pdo, int $deptId, string $category, string $docType, ?int $teacherRequirementId = null): ?array
{
    if ($teacherRequirementId !== null) {
        $stmt = $pdo->prepare(
            "SELECT id, status, teacher_requirement_id FROM documents
             WHERE teacher_requirement_id = ? AND archived = 0
               AND file_path IS NOT NULL AND file_path != ''
             ORDER BY version DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$teacherRequirementId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, status, teacher_requirement_id FROM documents
             WHERE department_id = ? AND category = ? AND document_type = ?
               AND archived = 0 AND file_path IS NOT NULL AND file_path != ''
             ORDER BY version DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$deptId, $category, $docType]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function getFolderPathSegments($pdo, $folderId)
{
    $segments = [];
    $currentId = $folderId;
    while ($currentId) {
        $stmt = $pdo->prepare("SELECT id, folder_name, parent_folder_id FROM department_folders WHERE id = ?");
        $stmt->execute([$currentId]);
        $folder = $stmt->fetch();
        if (!$folder) break;
        $segments[] = ['id' => (int) $folder['id'], 'name' => $folder['folder_name']];
        $currentId = $folder['parent_folder_id'];
    }
    return array_reverse($segments);
}

// ============================================================================
// FORM HANDLING
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $reqId = (int)($_POST['requirement_id'] ?? 0);

    if ($action === 'assign') {
        $teacherId = (int)$_POST['teacher_id'];
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        if ($deadline) { $dt = DateTime::createFromFormat('m/d/Y', $deadline); $deadline = $dt ? $dt->format('Y-m-d') : $deadline; }

        if (!$teacherId || !$reqId) {
            $error = "Please select a faculty member.";
        } else {
            // Ownership: requirement + teacher must belong to this PH's department.
            $reqData = $pdo->prepare("SELECT id, category, document_type FROM department_requirements WHERE id = ? AND department_id = ?");
            $reqData->execute([$reqId, $deptId]);
            $rd = $reqData->fetch();
            if (!$rd) {
                $error = "Requirement not found.";
            } else {
                $tCheck = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role IN ('teacher','staff') AND department_id = ?");
                $tCheck->execute([$teacherId, $deptId]);
                if (!$tCheck->fetch()) {
                    $error = "Invalid faculty member.";
                } else {
                    $check = $pdo->prepare("SELECT 1 FROM teacher_requirements WHERE requirement_id = ?");
                    $check->execute([$reqId]);
                    if ($check->fetch()) {
                        $error = "Already assigned to a faculty member.";
                    } else {
                        // Block assign while any active doc is in review or approved.
                        $latest = getLatestReqDoc($pdo, $deptId, $rd['category'], $rd['document_type']);
                        $latestStatus = $latest['status'] ?? null;
                        if (in_array($latestStatus, ['pending_program_head', 'pending_admin', 'approved_admin', 'approved_program_head'], true)) {
                            $error = "Cannot assign while this requirement is under review or approved.";
                        } else {
                            // Rejected personal upload is superseded by assignment: archive it.
                            if ($latest && $latest['teacher_requirement_id'] === null
                                && in_array($latestStatus, ['rejected_admin', 'rejected_program_head'], true)) {
                                $pdo->prepare("UPDATE documents SET archived = 1 WHERE id = ?")->execute([(int)$latest['id']]);
                            }
                            // Rejected teacher doc without assignment row is fine; with assignment, already blocked above.
                            $result = assignRequirementToTeacher($pdo, $teacherId, $reqId, $userId, $deadline);
                            if (!empty($result['success'])) {
                                $success = "Requirement assigned to faculty member.";
                            } else {
                                $error = "Failed to assign requirement.";
                            }
                        }
                    }
                }
            }
        }
    }
    
    elseif ($action === 'upload_personal') {
        $comment = trim($_POST['comment'] ?? '');
        if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please select a valid file.";
        } else {
            $reqData = $pdo->prepare("SELECT id, category, document_type FROM department_requirements WHERE id = ? AND department_id = ?");
            $reqData->execute([$reqId, $deptId]);
            $rd = $reqData->fetch();
            if (!$rd) {
                $error = "Requirement not found.";
            } else {
                $trRow = $pdo->prepare("SELECT tr.id FROM teacher_requirements tr WHERE tr.requirement_id = ?");
                $trRow->execute([$reqId]);
                $tr = $trRow->fetch();
                $latest = getLatestReqDoc($pdo, $deptId, $rd['category'], $rd['document_type']);
                $latestStatus = $latest['status'] ?? null;
                $state = resolvePhReqState($latestStatus, $tr['id'] ?? null);

                if (!$state['can_upload']) {
                    $error = "Cannot upload while this requirement is pending review or approved.";
                } else {
                    // Active teacher submission blocks personal upload.
                    // Assigned (no submission) or Rejected faculty: personal upload supersedes assignment.
                    if ($tr) {
                        $tDoc = getLatestReqDoc($pdo, $deptId, $rd['category'], $rd['document_type'], (int)$tr['id']);
                        $tStatus = $tDoc['status'] ?? null;
                        if ($tStatus !== null && !in_array($tStatus, ['rejected_program_head', 'rejected_admin'], true)) {
                            $error = "Cannot upload: This requirement has an active faculty submission.";
                        } else {
                            $pdo->prepare("DELETE FROM teacher_requirements WHERE id = ?")->execute([(int)$tr['id']]);
                            logAudit($pdo, 'unassign', "Auto-unassigned tr.id={$tr['id']} — " . phRoleShort() . " uploaded personally", 'teacher_requirement', (int)$tr['id']);
                        }
                    }
                    if (!$error) {
                        $result = uploadDepartmentSubmission($pdo, $reqId, $userId, $_FILES['document_file'], $comment, $deptId);
                        if ($result === true) {
                            $success = "Document uploaded successfully and forwarded to Admin.";
                        } else {
                            $error = $result;
                        }
                    }
                }
            }
        }
    }
    
    elseif ($action === 'unassign') {
        $trId = (int)$_POST['tr_id'];
        // Ownership: assignment must belong to a requirement in this department.
        $uStmt = $pdo->prepare("
            SELECT tr.id, tr.teacher_id, dr.department_id, dr.category, dr.document_type
            FROM teacher_requirements tr
            JOIN department_requirements dr ON dr.id = tr.requirement_id
            WHERE tr.id = ? AND dr.department_id = ?
        ");
        $uStmt->execute([$trId, $deptId]);
        $u = $uStmt->fetch();
        if (!$u) {
            $error = "Assignment not found.";
        } else {
            // Block only when the teacher has an active (non-rejected) submission.
            $activeDoc = $pdo->prepare("
                SELECT status FROM documents
                WHERE teacher_requirement_id = ? AND archived = 0
                  AND file_path IS NOT NULL AND file_path != ''
                ORDER BY version DESC, id DESC LIMIT 1
            ");
            $activeDoc->execute([$trId]);
            $docStatus = $activeDoc->fetchColumn();
            if ($docStatus !== false && !in_array($docStatus, ['rejected_program_head', 'rejected_admin'], true)) {
                $error = "Cannot remove assignment while a submission is under review.";
            } else {
                $pdo->prepare("DELETE FROM teacher_requirements WHERE id = ?")->execute([$trId]);
                logAudit($pdo, 'unassign', "Unassigned requirement (tr.id=$trId)", 'teacher_requirement', $trId);
                createNotification($pdo, (int)$u['teacher_id'], 'Requirement Unassigned', "You were unassigned from: {$u['category']} - {$u['document_type']}.", 'warning');
                $success = "Assignment removed. You can now upload personally or re-assign.";
            }
        }
    }
    
    elseif ($action === 'remove_personal') {
        $reqData = $pdo->prepare("SELECT id, category, document_type FROM department_requirements WHERE id = ? AND department_id = ?");
        $reqData->execute([$reqId, $deptId]);
        $rd = $reqData->fetch();
        if (!$rd) {
            $error = "Requirement not found.";
        } else {
            $stmt = $pdo->prepare("SELECT id, status, file_path FROM documents WHERE department_id = ? AND category = ? AND document_type = ? AND teacher_requirement_id IS NULL AND archived = 0 ORDER BY version DESC LIMIT 1");
            $stmt->execute([$deptId, $rd['category'], $rd['document_type']]);
            $doc = $stmt->fetch();

            if ($doc) {
                $docStatus = $doc['status'];
                $docId = (int)$doc['id'];

                if (in_array($docStatus, ['approved_admin', 'approved_program_head'], true)) {
                    $error = "Cannot remove: Document has already been approved by Admin.";
                }
                elseif (in_array($docStatus, ['pending_admin', 'pending_program_head'], true)) {
                    $error = "Cannot remove while the document is awaiting review.";
                }
                elseif ($docStatus === 'rejected_admin' || $docStatus === 'rejected_program_head') {
                    // Soft delete (§12): flag archived, keep row + stored file recoverable.
                    $pdo->prepare("DELETE FROM ph_repository WHERE document_id = ?")->execute([$docId]);
                    $pdo->prepare("UPDATE documents SET archived = 1 WHERE id = ?")->execute([$docId]);
                    logAudit($pdo, 'remove_rejected_upload', "Soft-removed rejected personal upload for requirement $reqId", 'document', $docId);
                    $success = "Rejected document removed (archived, files preserved).";
                }
                else {
                    $error = "Cannot remove this document.";
                }
            } else {
                $error = "No personal upload found.";
            }
        }
    }
    
    elseif ($action === 'delete_requirement') {
        $reqId = (int)$_POST['requirement_id'];
        
        $checkApproved = $pdo->prepare("
            SELECT d.id FROM documents d
            JOIN department_requirements dr ON d.category = dr.category AND d.document_type = dr.document_type
            WHERE dr.id = ? AND d.department_id = ? AND d.status = 'approved_admin'
        ");
        $checkApproved->execute([$reqId, $deptId]);
        if ($checkApproved->fetch()) {
            $error = "Cannot delete: This requirement has an approved document.";
        } else {
            $infoStmt = $pdo->prepare("SELECT category, document_type FROM department_requirements WHERE id = ? AND department_id = ?");
            $infoStmt->execute([$reqId, $deptId]);
            $reqInfo = $infoStmt->fetch();
            if (!$reqInfo) {
                $error = "Requirement not found.";
            } else {
            $wipeStmt = $pdo->prepare("SELECT teacher_id FROM teacher_requirements WHERE requirement_id = ?");
            $wipeStmt->execute([$reqId]);
            $assignedTeachers = $wipeStmt->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            try {
                deletePendingDocsForRequirement($pdo, $reqId, $deptId);
                $pdo->prepare("DELETE FROM teacher_requirements WHERE requirement_id = ?")->execute([$reqId]);
                $pdo->prepare("DELETE FROM department_requirements WHERE id = ?")->execute([$reqId]);
                $pdo->commit();
                foreach ($assignedTeachers as $tid) {
                    createNotification($pdo, (int)$tid, 'Requirement Removed', "The requirement assigned to you was deleted by your " . currentRoleName() . ": {$reqInfo['category']} - {$reqInfo['document_type']}.", 'danger');
                }
                $success = "Requirement deleted. Approved documents remain in the repository.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Database error: " . $e->getMessage();
            }
            }
        }
    }
}

// ============================================================================
// DATA QUERIES
// ============================================================================

$deptName = '';
$stmt = $pdo->prepare("SELECT department_name FROM departments WHERE id = ?");
$stmt->execute([$deptId]);
$deptName = $stmt->fetchColumn();

$folderPathSegments = $currentFolderId ? getFolderPathSegments($pdo, $currentFolderId) : [];

if ($currentFolderId) {
    $stmt = $pdo->prepare("SELECT id, folder_name as name, created_at, 'folder' as type FROM department_folders WHERE department_id = ? AND parent_folder_id = ? AND LOWER(folder_name) <> 'root' ORDER BY folder_name");
    $stmt->execute([$deptId, $currentFolderId]);
} else {
    $stmt = $pdo->prepare("SELECT id, folder_name as name, created_at, 'folder' as type FROM department_folders WHERE department_id = ? AND parent_folder_id IS NULL AND LOWER(folder_name) <> 'root' ORDER BY folder_name");
    $stmt->execute([$deptId]);
}
$folders = $stmt->fetchAll();

$reqSql = "
    SELECT dr.*, 
           tr.id as tr_id, 
           tr.teacher_id, 
           tr.deadline, 
           u.name as teacher_name,
           doc.status as current_status,
           doc.id as doc_id,
           doc.teacher_requirement_id as doc_tr_id,
           doc.file_path,
           (SELECT COUNT(DISTINCT id) FROM teacher_requirements WHERE requirement_id = dr.id) as total_assigned,
           (SELECT COUNT(*) FROM documents d2
            WHERE d2.department_id = dr.department_id
            AND d2.category = dr.category
            AND d2.document_type = dr.document_type
            AND d2.status = 'approved_admin'
            AND d2.teacher_requirement_id IS NULL
            AND d2.archived = 0
            AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_approved_count,
           (SELECT COUNT(*) FROM documents d2
            WHERE d2.department_id = dr.department_id
            AND d2.category = dr.category
            AND d2.document_type = dr.document_type
            AND d2.status IN ('pending_program_head', 'pending_admin')
            AND d2.teacher_requirement_id IS NULL
            AND d2.archived = 0
            AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_pending_count,
           (SELECT COUNT(*) FROM documents d2
            WHERE d2.department_id = dr.department_id
            AND d2.category = dr.category
            AND d2.document_type = dr.document_type
            AND d2.status IN ('rejected_program_head', 'rejected_admin')
            AND d2.teacher_requirement_id IS NULL
            AND d2.archived = 0
            AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_rejected_count,
           (SELECT GROUP_CONCAT(latest.status) FROM (
               SELECT d3.status, d3.teacher_requirement_id, d3.upload_date
               FROM documents d3
               INNER JOIN (
                   SELECT teacher_requirement_id, MAX(version) AS max_ver
                   FROM documents
                   WHERE teacher_requirement_id IS NOT NULL
                   GROUP BY teacher_requirement_id
               ) lm ON d3.teacher_requirement_id = lm.teacher_requirement_id AND d3.version = lm.max_ver
           ) latest
           WHERE latest.teacher_requirement_id IN (SELECT tr2.id FROM teacher_requirements tr2 WHERE tr2.requirement_id = dr.id)
             AND (dr.cycle_started_at IS NULL OR latest.upload_date >= dr.cycle_started_at)) as latest_statuses
    FROM department_requirements dr
    LEFT JOIN teacher_requirements tr ON dr.id = tr.requirement_id
    LEFT JOIN users u ON tr.teacher_id = u.id
    LEFT JOIN documents doc ON doc.id = (
        SELECT d2.id FROM documents d2
        WHERE d2.department_id = dr.department_id
          AND d2.category = dr.category
          AND d2.document_type = dr.document_type
          AND d2.file_path IS NOT NULL AND d2.file_path != ''
          AND d2.archived = 0
        ORDER BY d2.version DESC, d2.id DESC
        LIMIT 1
    )
    WHERE dr.department_id = ? AND " . ($currentFolderId ? 'dr.folder_id = ?' : '(dr.folder_id IS NULL OR dr.folder_id = 0)') . "
    ORDER BY dr.category, dr.document_type
";
$reqParams = [$deptId];
if ($currentFolderId) $reqParams[] = $currentFolderId;
$reqStmt = $pdo->prepare($reqSql);
$reqStmt->execute($reqParams);
$requirements = $reqStmt->fetchAll();

$contents = [];
foreach ($folders as $f) {
    $contents[] = array_merge($f, ['is_folder' => true]);
}
foreach ($requirements as $r) {
    $contents[] = array_merge($r, ['is_folder' => false]);
}
usort($contents, function($a, $b) {
    if ($a['is_folder'] != $b['is_folder']) return $a['is_folder'] ? -1 : 1;
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$stats = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'not_uploaded' => 0];
foreach ($requirements as $r) {
    if (isRequirementComplete($r)) { $stats['approved']++; continue; }
    $st = $r['current_status'] ?? null;
    if ($st === 'approved_admin' || $st === 'approved_program_head') $stats['approved']++;
    elseif (in_array($st, ['pending_program_head', 'pending_admin'])) $stats['pending']++;
    elseif (in_array($st, ['rejected_program_head', 'rejected_admin'])) $stats['rejected']++;
    else $stats['not_uploaded']++;
}

$teachers = getTeachersInDepartment($pdo, $deptId);

$pageTitle = currentRoleName() . ' Requirements';

// ============================================================================
// EXTRA STYLES
// ============================================================================
$extraStyles = "
<style>
    /* ==============================
       LAYOUT & CONTAINER
       ============================== */
    body {
        background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --primary-light: #eff6ff;
        --danger: #ef4444;
        --warning: #f59e0b;
        --success: #10b981;
        --border: #e5e7eb;
        --white: #ffffff;
    }
    :focus-visible { outline: 3px solid var(--primary); outline-offset: 2px; }

    .content-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        min-width: 0;
        width: 100%;
        padding: 0.5rem;
        box-sizing: border-box;
    }
    .dashboard-container {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        min-width: 0;
        width: 100%;
        max-width: 1600px;
        margin: 0 auto;
        gap: 1.15rem;
    }

    /* ==============================
       PAGE HERO
       ============================== */
    .page-hero {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
        border-radius: 12px;
        padding: 2rem;
        color: white;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 14px -4px rgba(37, 99, 235, 0.4);
    }
    .page-hero h1 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.01em;
    }
    .page-hero p {
        margin: 0.375rem 0 0;
        opacity: 0.85;
        font-size: 0.9rem;
    }
    .page-hero-badge {
        background: rgba(255,255,255,0.2);
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-size: 0.85rem;
        font-weight: 700;
        border: 1px solid rgba(255,255,255,0.3);
        position: relative;
        z-index: 1;
        transition: background 0.15s ease, transform 0.15s ease;
    }
    .page-hero-badge:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-1px);
    }

    /* ==============================
       STATS GRID
       ============================== */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.9rem;
        width: 100%;
    }
    .stat-card {
        position: relative;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.05rem 1.15rem;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        overflow: hidden;
        min-width: 0;
    }
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: var(--accent);
        opacity: 0.9;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 6px 16px -6px rgba(15, 23, 42, 0.15);
    }
    .stat-icon {
        width: 44px; height: 44px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: var(--accent-bg);
        color: var(--accent);
    }
    .stat-info {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
        min-width: 0;
    }
    .stat-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
        letter-spacing: -0.02em;
    }
    .stat-approved     { --accent: #10b981; --accent-bg: #d1fae5; }
    .stat-pending      { --accent: #f59e0b; --accent-bg: #fef3c7; }
    .stat-rejected     { --accent: #ef4444; --accent-bg: #fee2e2; }
    .stat-not-uploaded { --accent: #6366f1; --accent-bg: #eef2ff; }

    /* ==============================
       MAIN CARD
       ============================== */
    .main-card {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 0;
        background: var(--white);
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
        width: 100%;
    }
    .card-header {
        padding: 1.15rem 1.4rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
    }
    .card-header-left {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        flex-wrap: wrap;
        min-width: 0;
    }
    .card-header-left h3 {
        font-size: 0.95rem;
        font-weight: 800;
        letter-spacing: -0.01em;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        color: #0f172a;
    }
    .card-header-left h3::before {
        content: '';
        width: 3px;
        height: 16px;
        background: var(--primary);
        border-radius: 999px;
    }
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.78rem;
        color: #64748b;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 0.32rem 0.8rem;
        border-radius: 9999px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        line-height: 1;
        flex-wrap: wrap;
        min-width: 0;
    }
    .breadcrumb a {
        color: #475569;
        text-decoration: none;
        font-weight: 600;
        transition: color 0.15s ease;
    }
    .breadcrumb a:hover { color: var(--primary); text-decoration: underline; }
    .breadcrumb > span { color: #94a3b8; font-weight: 500; }
    .card-header-right {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-size: 0.82rem;
        color: #64748b;
    }
    .count-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 0.32rem 0.85rem;
        border-radius: 9999px;
        font-size: 0.76rem;
        font-weight: 700;
        color: #475569;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .count-chip::before {
        content: '';
        width: 7px; height: 7px;
        border-radius: 50%;
        background: var(--primary);
    }

    /* ==============================
       TABLE
       ============================== */
    .card-body {
        flex: 1;
        min-height: 0;
        overflow-x: auto;
        overflow-y: auto;
    }
    .card-body.no-padding { padding: 0 !important; }
    .table-responsive { overflow-x: auto; }

    .data-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
    }
    .data-table thead th {
        background: #f8fafc;
        padding: 0.85rem 1.1rem;
        text-align: left;
        font-weight: 700;
        font-size: 0.7rem;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: #64748b;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 3;
    }
    .data-table td {
        padding: 0.95rem 1.1rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .data-table tbody tr { transition: background 0.15s ease; }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table tbody tr:hover { background: #f8fafc; }
    .data-table th:last-child,
    .data-table td:last-child { text-align: right; }

    .data-table tbody tr { animation: rowIn 0.35s ease backwards; }
    .data-table tbody tr:nth-child(1) { animation-delay: 0.02s; }
    .data-table tbody tr:nth-child(2) { animation-delay: 0.05s; }
    .data-table tbody tr:nth-child(3) { animation-delay: 0.08s; }
    .data-table tbody tr:nth-child(4) { animation-delay: 0.11s; }
    .data-table tbody tr:nth-child(5) { animation-delay: 0.14s; }
    .data-table tbody tr:nth-child(6) { animation-delay: 0.17s; }
    .data-table tbody tr:nth-child(7) { animation-delay: 0.2s; }
    .data-table tbody tr:nth-child(8) { animation-delay: 0.23s; }
    .data-table tbody tr:nth-child(9) { animation-delay: 0.26s; }
    .data-table tbody tr:nth-child(10) { animation-delay: 0.29s; }
    .data-table tbody tr:nth-child(n + 11) { animation-delay: 0.32s; }
    @keyframes rowIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .data-table tr.folder-row { cursor: pointer; }
    .data-table tr.folder-row:hover { background: #f1f5f9; }
    .name-wrap { display: flex; align-items: center; gap: 0.55rem; }
    .folder-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px; height: 32px;
        border-radius: 8px;
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        color: #92400e;
        border: 1px solid #fde68a;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        flex-shrink: 0;
    }
    .folder-link {
        font-weight: 700;
        color: #0f172a;
        text-decoration: none;
        transition: color 0.15s ease;
    }
    .folder-link:hover { color: var(--primary); text-decoration: underline; }

    .req-category { font-weight: 700; color: #0f172a; }
    .req-doc { font-size: 0.78rem; color: #64748b; display: block; margin-top: 0.15rem; }

    /* ==============================
       BADGES
       ============================== */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.3rem 0.7rem;
        border-radius: 9999px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        white-space: nowrap;
        border: 1px solid transparent;
        line-height: 1.2;
    }
    .badge-pending       { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
    .badge-pending-ph    { background: #fef3c7; color: #b45309; border-color: #fde68a; }
    .badge-pending-admin { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .badge-approved      { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .badge-rejected      { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
    .badge-assigned      { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
    .badge-required      { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .badge-optional      { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
    .badge-folder        { background: transparent; color: #64748b; border-color: #e2e8f0; }
    .text-danger { color: #dc2626; font-weight: 600; }

    /* ==============================
       BUTTONS
       ============================== */
    .btn-group {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.45rem;
        flex-wrap: wrap;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        padding: 0.5rem 0.95rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease;
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.2;
        white-space: nowrap;
    }
    .btn:active { transform: translateY(0) scale(0.97); }
    .btn-sm { padding: 0.42rem 0.78rem; font-size: 0.75rem; }
    .btn-primary {
        background: var(--primary);
        border-color: var(--primary);
        color: #ffffff;
        box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2);
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 6px 14px -4px rgba(37, 99, 235, 0.45);
    }
    .btn-success {
        background: #10b981;
        color: #ffffff;
    }
    .btn-success:hover {
        background: #059669;
        transform: translateY(-1px);
    }
    .btn-danger {
        background: var(--danger);
        color: #ffffff;
    }
    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }
    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }
    .btn-secondary:hover {
        background: #e2e8f0;
    }

    /* ==============================
       EMPTY STATE
       ============================== */
    .empty-state {
        text-align: center;
        padding: 4.5rem 1.5rem;
        color: #94a3b8;
    }
    .empty-state-icon {
        width: 84px; height: 84px;
        margin: 0 auto 1.1rem;
        border-radius: 20px;
        background: var(--primary-light);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.4rem;
        color: var(--primary);
    }
    .empty-state p { margin: 0; font-weight: 500; font-size: 0.95rem; color: #64748b; }

    /* ==============================
       ALERTS
       ============================== */
    .alert {
        padding: 0.9rem 1.2rem;
        border-radius: 8px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.7rem;
        font-size: 0.86rem;
        border: 1px solid transparent;
        border-left-width: 4px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
        animation: alertIn 0.25s ease;
    }
    @keyframes alertIn {
        from { opacity: 0; transform: translateY(-6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .alert-success {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
        border-left-color: #10b981;
    }
    .alert-danger {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
        border-left-color: #ef4444;
    }

    /* ==============================
       MODAL
       ============================== */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(6px);
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }
    .modal.show { display: flex; }
    .modal-content {
        background: #ffffff;
        border-radius: 12px;
        max-width: 520px;
        width: 100%;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 24px 80px -24px rgba(15, 23, 42, 0.35);
        overflow: hidden;
        animation: modalIn 0.22s ease;
    }
    @keyframes modalIn {
        from { opacity: 0; transform: translateY(18px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.3rem 1.5rem;
        border-bottom: 1px solid var(--border);
        flex-shrink: 0;
        background: linear-gradient(180deg, #ffffff, #f8fafb);
    }
    .modal-header h3 {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 800;
        letter-spacing: -0.01em;
        color: #0f172a;
    }
    .modal-header h3::before {
        content: '';
        width: 3px;
        height: 18px;
        background: var(--primary);
        border-radius: 9999px;
        display: inline-block;
        margin-right: 0.55rem;
    }
    .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-shrink: 0;
        background: #f8fafc;
        border-radius: 0 0 12px 12px;
    }
    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #94a3b8;
        width: 34px; height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        transition: background 0.15s ease, color 0.15s ease;
        line-height: 1;
        padding: 0 0 0.15rem;
    }
    .close-btn:hover { background: #e2e8f0; color: #0f172a; }

    .modal-req-name {
        font-weight: 700;
        font-size: 1.05rem;
        color: var(--primary-dark);
        margin: 0 0 1.25rem;
    }
    .modal-section-title { margin: 0 0 1rem; font-weight: 600; color: #334155; }

    /* ==============================
       FORMS
       ============================== */
    .form-group { margin-bottom: 1.25rem; }
    .form-group label {
        display: block;
        font-weight: 700;
        font-size: 0.8rem;
        color: #334155;
        margin-bottom: 0.5rem;
    }
    .form-control {
        width: 100%;
        padding: 0.65rem 0.9rem;
        border: 1.5px solid #e2e8f0;
        border-radius: 8px;
        font-family: inherit;
        font-size: 0.9rem;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        background: #ffffff;
        color: #0f172a;
    }
    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        background: #ffffff;
    }
    .form-control::placeholder { color: #94a3b8; }
    textarea.form-control { resize: vertical; min-height: 74px; }

    .modal-actions {
        display: flex;
        gap: 0.6rem;
        justify-content: flex-end;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }

    /* ==============================
       WORKFLOW CHOICE
       ============================== */
    .workflow-choice {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin: 1rem 0;
    }
    .choice-card {
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.7rem 1.25rem;
        text-align: center;
        cursor: pointer;
        background: #ffffff;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
    }
    .choice-card:hover {
        border-color: #bfdbfe;
        transform: translateY(-3px);
        box-shadow: 0 10px 24px -12px rgba(37, 99, 235, 0.35);
    }
    .choice-card.selected {
        border-color: var(--primary);
        background: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .choice-icon { display: flex; justify-content: center; margin-bottom: 0.6rem; }
    .choice-icon svg {
        width: 40px; height: 40px;
        padding: 0.65rem;
        border-radius: 14px;
        background: var(--primary-light);
        color: var(--primary);
        transition: background 0.2s ease, color 0.2s ease;
        margin: 0;
        box-sizing: content-box;
    }
    .choice-card.selected .choice-icon svg { background: var(--primary); color: #ffffff; }
    .choice-title {
        font-weight: 800;
        font-size: 1.02rem;
        display: block;
        color: #0f172a;
        margin-bottom: 0.3rem;
    }
    .choice-desc { font-size: 0.8rem; color: #64748b; line-height: 1.45; }

    .active-info-box {
        margin-bottom: 1.5rem;
        padding: 1rem 1.15rem;
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 0.9rem;
        color: #334155;
    }

    .hidden { display: none !important; }

    /* ==============================
       RESPONSIVE
       ============================== */
    @media (max-width: 1100px) {
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 768px) {
        .content-area { padding: 0.9rem 0.75rem 1rem; }
        .page-hero { flex-direction: column; text-align: center; padding: 1.5rem; gap: 1rem; }
        .page-hero-badge { margin-left: 0; }
        .card-header { flex-direction: column; align-items: stretch; }
        .card-header-right { justify-content: flex-start; }
        .data-table th, .data-table td { padding: 0.7rem 0.8rem; font-size: 0.8rem; }
        .workflow-choice { grid-template-columns: 1fr; }
        .btn-group { justify-content: flex-start; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .stat-card { padding: 0.8rem 0.85rem; gap: 0.6rem; }
        .stat-icon { width: 36px; height: 36px; }
        .stat-value { font-size: 1.2rem; }
        .stat-label { font-size: 0.6rem; }
        .data-table th, .data-table td { padding: 0.55rem 0.6rem; font-size: 0.75rem; }
        .breadcrumb { font-size: 0.72rem; }
        .modal-content { margin: 0.5rem; }
    }

    @media (prefers-reduced-motion: reduce) {
        .data-table tbody tr,
        .alert { animation: none; }
        .choice-card { transform: none !important; }
    }
</style>
";

include 'includes/header.php';
?>

<div class="content-area">
    <div class="dashboard-container">

        <?php if ($error): ?>
            <div class="alert alert-danger"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <?= sanitize($error) ?></div>
        <?php endif; ?>

        <?php include 'includes/success_toast.php'; ?>

        <!-- Main Card -->
        <div class="main-card">
            <div class="card-header">
                <div class="card-header-left">
                    
                    <div class="breadcrumb">
                        <a href="program_head_requirements.php"><?= sanitize($deptName) ?></a>
                        <?php
                        $count = count($folderPathSegments);
                        foreach ($folderPathSegments as $i => $seg):
                            $isLast = ($i === $count - 1);
                            echo '<span>›</span>';
                            if ($isLast) {
                                echo '<span>' . sanitize($seg['name']) . '</span>';
                            } else {
                                $url = 'program_head_requirements.php?folder=' . $seg['id'];
                                echo '<a href="' . $url . '">' . sanitize($seg['name']) . '</a>';
                            }
                        endforeach;
                        ?>
                    </div>
                </div>
                <div class="card-header-right">
                    <span class="count-chip"><?= count($contents) ?> items</span>
                </div>
            </div>

            <div class="card-body no-padding">
                <?php if (empty($contents)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
                        <p>This folder is empty.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Deadline</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contents as $item): ?>
                                    <?php if ($item['is_folder']): ?>
                                        <tr class="folder-row" data-row-id="req_folder:<?= (int)$item['id'] ?>" data-href="program_head_requirements.php?folder=<?= $item['id'] ?>">
                                            <td>
                                                <div class="name-wrap">
                                                    <span class="folder-chip"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 4H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg></span>
                                                    <a class="folder-link" href="program_head_requirements.php?folder=<?= $item['id'] ?>">
                                                        <?= sanitize($item['name']) ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td><span class="badge badge-folder">Folder</span></td>
                                            <td>—</td>
                                            <td>—</td>
                                            <td></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php
                                            $req = $item;
                                            // Aggregate "Complete" check → shows as "Approved" for the PH
                                            $isComplete = isRequirementComplete($req);
                                            $state = resolvePhReqState($req['current_status'] ?? null, $req['tr_id'] ?? null, $isComplete, (int)($req['direct_rejected_count'] ?? 0), (int)($req['direct_pending_count'] ?? 0));
                                            $sl = [$state['label'], $state['class']];
                                            $expired = $req['expiration_date'] && strtotime($req['expiration_date']) < time();
                                            $isAssigned = !empty($req['tr_id']);
                                            $showManageBtn = !$state['blocked'];
                                            $buttonLabel = 'Manage';
                                        ?>
                                        <tr data-row-id="requirement:<?= (int)$req['id'] ?>">
                                            <td>
                                                <div>
                                                    <strong class="req-category"><?= sanitize($req['category']) ?></strong>
                                                    <span class="req-doc"><?= sanitize($req['document_type']) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge <?= $req['mandatory'] ? 'badge-required' : 'badge-optional' ?>">
                                                    <?= $req['mandatory'] ? 'Required' : 'Optional' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($req['expiration_date']): ?>
                                                    <span class="<?= $expired ? 'text-danger' : '' ?>">
                                                        <?= date('M j, Y', strtotime($req['expiration_date'])) ?>
                                                    </span>
                                                    <?php if ($expired): ?>
                                                        <span class="badge badge-rejected" style="margin-left:0.5rem;">Expired</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?= $sl[1] ?>"><?= $sl[0] ?></span></td>
                                            <td>
                                                <div class="btn-group">
                                                    <?php if ($showManageBtn): ?>
                                                        <button class="btn btn-sm btn-primary action-btn"
                                                                data-id="<?= $req['id'] ?>"
                                                                data-name="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>"
                                                                data-state="<?= $state['key'] ?>"
                                                                data-assigned="<?= $isAssigned ? '1' : '0' ?>"
                                                                data-trid="<?= $req['tr_id'] ?? '' ?>"
                                                                data-can-unassign="<?= $state['can_unassign'] ? '1' : '0' ?>"
                                                                data-can-upload="<?= $state['can_upload'] ? '1' : '0' ?>"
                                                                data-can-assign="<?= $state['can_assign'] ? '1' : '0' ?>"
                                                                data-rejected="<?= $state['key'] === 'rejected' ? '1' : '0' ?>">
                                                            <?= $buttonLabel ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!empty(trim((string)($req['description'] ?? '')))): ?>
                                                        <button type="button" class="btn btn-sm btn-secondary desc-btn"
                                                                data-name="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>"
                                                                data-desc="<?= sanitize($req['description']) ?>">
                                                            Description
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($req['doc_id']): ?>
                                                        <button type="button" class="btn btn-sm btn-secondary view-btn"
                                                                data-id="<?= $req['doc_id'] ?>"
                                                                data-type="document"
                                                                data-filename="<?= sanitize($req['filename'] ?? '') ?>"
                                                                data-title="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>"
                                                                style="cursor:pointer;">
                                                            View
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.dashboard-container -->
</div><!-- /.content-area -->

<!-- ============================================================================
     MODAL
     ============================================================================ -->
<div id="reqModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Manage Requirement</h3>
            <button class="close-btn" data-modal="reqModal" aria-label="Close modal">&times;</button>
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

            <div id="uploadSection" class="hidden">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="upload_personal">
                    <input type="hidden" name="requirement_id" id="upload_req_id">
                    <div class="form-group">
                        <label>File</label>
                        <input type="file" name="document_file" class="form-control"
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx" required>
                    </div>
                    <div class="form-group">
                        <label>Comment </label>
                        <textarea name="comment" class="form-control" rows="2" placeholder="Notes for Admin..."></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-csp-action="resetModal">Back</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>

            <div id="assignSection" class="hidden">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="requirement_id" id="assign_req_id">
                    <div class="form-group">
                                <label>Select Faculty</label>
                        <select name="teacher_id" class="form-control" required>
                            <option value="">-- Select Faculty --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?> (<?= sanitize($t['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Deadline </label>
                        <div class="deadline-wrap" style="position:relative">
                            <input type="text" name="deadline" class="form-control req-deadline" placeholder="MM/DD/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}">
                            <input type="date" class="req-deadline-picker" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer">
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-csp-action="resetModal">Back</button>
                        <button type="submit" class="btn btn-success">Assign</button>
                    </div>
                </form>
            </div>

            <div id="activeStateSection" class="hidden" style="text-align:center; padding:1rem 0;">
                <div id="activeInfo" class="active-info-box"></div>
                <div class="modal-actions" style="justify-content:center; margin-top:0;">
                    <form method="POST" id="resetForm" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="requirement_id" id="active_req_id">
                        <input type="hidden" name="tr_id" id="active_tr_id">
                        <input type="hidden" name="action" id="active_action">
                        <button type="submit"
                                id="resetBtn"
                                class="btn btn-danger">
                            Remove
                        </button>
                    </form>
                    <button type="button"
                            id="activeUploadBtn"
                            class="btn btn-primary hidden"
                            data-csp-action="openUploadFromActive">
                        Upload Personally
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Description modal -->
<div id="descModal" class="modal">
    <div class="modal-content" style="max-width: 480px;">
        <div class="modal-header">
            <h3>Description</h3>
            <button class="close-btn" data-modal="descModal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p id="descReqName" class="modal-req-name"></p>
            <p id="descBody" style="margin:0; color:#334155; font-size:0.95rem; white-space:pre-wrap;"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal="descModal">Close</button>
        </div>
    </div>
</div>

<!-- Confirm modal (replaces native confirm) -->
<div id="confirmModal" class="modal" style="z-index: 3000;">
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header">
            <h3 id="confirmTitle">Confirm</h3>
            <button class="close-btn" data-modal="confirmModal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p id="confirmMessage" style="margin:0; color:#334155; font-size:0.95rem;"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="confirmCancelBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const modal_req = document.getElementById('reqModal');
    const selection = document.getElementById('workflowSelection');
    const uploadSection = document.getElementById('uploadSection');
    const assignSection = document.getElementById('assignSection');
    const activeSection = document.getElementById('activeStateSection');

    function openUploadFromActive() {
        activeSection.classList.add('hidden');
        selection.classList.add('hidden');
        assignSection.classList.add('hidden');
        uploadSection.classList.remove('hidden');
    }

    function openManageModal(btn) {
        resetModal();

        const id = btn.dataset.id;
        const name = btn.dataset.name;
        const state = btn.dataset.state || '';
        const assigned = btn.dataset.assigned === '1';
        const trid = btn.dataset.trid;
        const canUnassign = btn.dataset.canUnassign === '1';
        const canUpload = btn.dataset.canUpload === '1';
        const canAssign = btn.dataset.canAssign === '1';

        document.getElementById('modalReqName').textContent = name;
        document.getElementById('upload_req_id').value = id;
        document.getElementById('assign_req_id').value = id;
        document.getElementById('active_req_id').value = id;
        document.getElementById('active_tr_id').value = trid || '';

        const uploadBtn = document.getElementById('activeUploadBtn');
        const resetBtn = document.getElementById('resetBtn');
        const resetForm = document.getElementById('resetForm');
        const activeInfo = document.getElementById('activeInfo');
        const activeAction = document.getElementById('active_action');

        // Pending PH / Pending Admin / Approved: Manage is not shown (blocked).
        // Defensive: if reached, show read-only info with no actions.
        if (state === 'pending_ph' || state === 'pending_admin' || state === 'approved') {
            selection.classList.add('hidden');
            uploadSection.classList.add('hidden');
            assignSection.classList.add('hidden');
            activeSection.classList.remove('hidden');
            const labels = {
                pending_ph: 'Pending <?= phRoleShort() ?> Review - awaiting your review in the pending queue.',
                pending_admin: 'Pending Admin Review — submitted; no changes allowed.',
                approved: 'Approved — requirement is complete.'
            };
            activeInfo.innerHTML = '<strong>Status:</strong> ' + labels[state];
            resetForm.classList.add('hidden');
            uploadBtn.classList.add('hidden');
            document.getElementById('modalTitle').textContent = 'Manage Requirement';
        }
        // Assigned or Rejected with assignment: offer remove + optional personal upload.
        else if (canUnassign && assigned) {
            selection.classList.add('hidden');
            uploadSection.classList.add('hidden');
            assignSection.classList.add('hidden');
            activeSection.classList.remove('hidden');
            resetForm.classList.remove('hidden');
            activeAction.value = 'unassign';
            resetBtn.innerHTML = 'Remove Assignment';
            resetBtn.className = 'btn btn-danger';
            resetBtn.type = 'submit';
            if (state === 'rejected') {
                activeInfo.innerHTML = '<strong>Status:</strong> Rejected.<br><small>Remove the assignment to re-assign, or upload a corrected document yourself.</small>';
            } else {
                activeInfo.innerHTML = '<strong>Status:</strong> Assigned to a faculty member.<br><small>You can remove the assignment or upload the document personally.</small>';
            }
            if (canUpload) {
                uploadBtn.classList.remove('hidden');
            } else {
                uploadBtn.classList.add('hidden');
            }
            document.getElementById('modalTitle').textContent = 'Manage Requirement';
        }
        // Unassigned (not_uploaded or rejected personal): workflow choice.
        else {
            selection.classList.remove('hidden');
            uploadSection.classList.add('hidden');
            assignSection.classList.add('hidden');
            activeSection.classList.add('hidden');
            // Hide cards the state does not permit.
            document.getElementById('choicePH').style.display = canUpload ? '' : 'none';
            document.getElementById('choiceTeacher').style.display = canAssign ? '' : 'none';
            document.getElementById('modalTitle').textContent = 'Manage Requirement';
        }

        modal_req.classList.add('show');
        modal_req.style.display = 'flex';
    }

    document.querySelectorAll('.action-btn').forEach(btn => {
        btn.addEventListener('click', function() { openManageModal(this); });
    });

    function openDescModal(btn) {
        document.getElementById('descReqName').textContent = btn.dataset.name || '';
        document.getElementById('descBody').textContent = btn.dataset.desc || '';
        const m = document.getElementById('descModal');
        m.classList.add('show');
        m.style.display = 'flex';
    }
    document.querySelectorAll('.desc-btn').forEach(btn => {
        btn.addEventListener('click', function() { openDescModal(this); });
    });

    function selectPath(path) {
        selection.classList.add('hidden');
        if (path === 'ph') {
            uploadSection.classList.remove('hidden');
            document.getElementById('choicePH').classList.add('selected');
            document.getElementById('choiceTeacher').classList.remove('selected');
        } else {
            assignSection.classList.remove('hidden');
            document.getElementById('choiceTeacher').classList.add('selected');
            document.getElementById('choicePH').classList.remove('selected');
        }
    }

    function resetModal() {
        selection.classList.remove('hidden');
        uploadSection.classList.add('hidden');
        assignSection.classList.add('hidden');
        activeSection.classList.add('hidden');
        document.getElementById('choicePH').classList.remove('selected');
        document.getElementById('choiceTeacher').classList.remove('selected');
        document.getElementById('choicePH').style.display = '';
        document.getElementById('choiceTeacher').style.display = '';
        document.getElementById('resetForm').classList.remove('hidden');
        document.getElementById('activeUploadBtn').classList.add('hidden');
        document.querySelector('#uploadSection input[type="file"]').value = '';
        document.querySelector('#uploadSection textarea').value = '';
        document.querySelector('#assignSection select').value = '';
        const deadlineInput = document.querySelector('#assignSection .req-deadline');
        if (deadlineInput) deadlineInput.value = '';
    }

    document.querySelectorAll('.close-btn[data-modal="reqModal"]').forEach(b => {
        b.addEventListener('click', () => {
            modal_req.classList.remove('show');
            setTimeout(() => modal_req.style.display = 'none', 300);
        });
    });

    document.querySelectorAll('[data-modal="descModal"]').forEach(b => {
        b.addEventListener('click', () => {
            const m = document.getElementById('descModal');
            m.classList.remove('show');
            m.style.display = 'none';
        });
    });

    // Confirm modal — intercept destructive form submit
    let pendingConfirmForm = null;
    const confirmModal = document.getElementById('confirmModal');

    function hideManageModal() {
        modal_req.classList.remove('show');
        modal_req.style.display = 'none';
    }

    function openConfirmModal(form, message) {
        pendingConfirmForm = form;
        document.getElementById('confirmMessage').textContent = message;
        hideManageModal();
        confirmModal.classList.add('show');
        confirmModal.style.display = 'flex';
        document.getElementById('confirmCancelBtn').focus();
    }

    function closeConfirmModal() {
        confirmModal.classList.remove('show');
        setTimeout(() => { confirmModal.style.display = 'none'; }, 300);
        pendingConfirmForm = null;
    }

    document.getElementById('resetForm').addEventListener('submit', function (e) {
        if (this.dataset.confirmed === '1') {
            this.dataset.confirmed = '0';
            return;
        }
        e.preventDefault();
        openConfirmModal(this, 'This will remove the current assignment/upload. Continue?');
    });

    document.getElementById('confirmOkBtn').addEventListener('click', () => {
        const form = pendingConfirmForm;
        closeConfirmModal();
        if (form) {
            form.dataset.confirmed = '1';
            form.requestSubmit();
        }
    });
    // Cancel / X / backdrop → only close Confirm; Manage stays closed
    document.getElementById('confirmCancelBtn').addEventListener('click', closeConfirmModal);
    document.querySelectorAll('.close-btn[data-modal="confirmModal"]').forEach(b => {
        b.addEventListener('click', closeConfirmModal);
    });
    confirmModal.addEventListener('click', (e) => {
        if (e.target === confirmModal) closeConfirmModal();
    });

    window.addEventListener('click', (e) => {
        if (e.target === modal_req) {
            modal_req.classList.remove('show');
            setTimeout(() => modal_req.style.display = 'none', 300);
        }
    });

    document.querySelectorAll('.folder-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('a')) return;
            const href = this.dataset.href;
            if (href) window.location.href = href;
        });
    });

    document.querySelectorAll('.deadline-wrap').forEach(wrap => {
        const input = wrap.querySelector('.req-deadline');
        const picker = wrap.querySelector('.req-deadline-picker');
        if (!input || !picker) return;
        picker.addEventListener('click', () => {
            const toDb = v => { const p = v.split('/'); return p.length === 3 ? p[2] + '-' + p[0] + '-' + p[1] : ''; };
            picker.value = input.value && input.value.includes('/') ? toDb(input.value) : '';
            picker.showPicker();
        });
        picker.addEventListener('change', () => {
            const p = picker.value.split('-');
            input.value = p.length === 3 ? p[1] + '/' + p[2] + '/' + p[0] : picker.value;
        });
    });
</script>

<script nonce="<?= csp_nonce() ?>">
    // ponytail: full-page reload instead of 1s AJAX poll (single server-rendered query, nothing to drift); skips reload when a modal is open, 30s interval
    setInterval(function () { if (!document.querySelector(".modal.show")) location.reload(); }, 30000);
</script>

<?php include 'includes/footer.php'; ?>





