<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();
maybeAutoSyncExpiration($pdo);

register_shutdown_function(static function () use ($pdo): void {
    syncStorageMirrors($pdo);
});

// ------------------------------------------------------------------
// Handle POST actions (Folder & Requirement CRUD)
// ------------------------------------------------------------------
$error = '';
$success = '';

$cacheKey = 'departments_list';
$departments = fileCacheGet($cacheKey, 3600);
if ($departments === false) {
    $departments = $pdo->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetchAll();
    fileCacheSet($cacheKey, $departments, 3600);
}

$deptId = isset($_GET['dept']) ? (int)$_GET['dept'] : null;
$currentFolderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    // ---------- Create / Edit Requirement ----------
    if ($action === 'add_requirement' || $action === 'edit_requirement') {
        $deptId = (int)$_POST['department_id'];
        if ($action === 'add_requirement') {
            $folderId = !empty($_POST['folder_context_id']) ? (int)$_POST['folder_context_id'] : null;
        } else {
            $folderId = null;
            if (isset($_POST['folder_id']) && $_POST['folder_id'] !== '') {
                $folderId = (int)$_POST['folder_id'];
            } elseif (!empty($_POST['id'])) {
                $curF = $pdo->prepare('SELECT folder_id FROM department_requirements WHERE id = ?');
                $curF->execute([(int)$_POST['id']]);
                $v = $curF->fetchColumn();
                $folderId = $v ? (int)$v : null;
            }
        }
        $category = trim($_POST['category'] ?? '');
        $docType = trim($_POST['document_type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $mandatory = 1;
        $expiryDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;

        $failEdit = function (string $msg) use ($deptId, $folderId, $pdo) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (isAjaxRequest()) {
                sendJson(['success' => false, 'error' => $msg]);
            }
            header('Location: admin_department_requirements.php?dept=' . $deptId . '&folder=' . ($folderId ?? 0)
                . '&error=' . urlencode($msg));
            exit;
        };

        if (!$deptId || empty($category) || empty($docType)) {
            $error = 'Department, category, and document type are required.';
            if (isAjaxRequest()) {
                sendJson(['success' => false, 'error' => $error]);
            }
        } else {
            if ($folderId) {
                $fChk = $pdo->prepare('SELECT department_id FROM department_folders WHERE id = ?');
                $fChk->execute([$folderId]);
                $fChk = $fChk->fetch();
                if (!$fChk || (int)$fChk['department_id'] !== $deptId) {
                    $failEdit('Selected folder does not belong to this department.');
                }
            }
            try {
                $pdo->beginTransaction();
                if ($id) {
                    $existingReq = $pdo->prepare('SELECT id, department_id, expiration_date, category, document_type FROM department_requirements WHERE id = ?');
                    $existingReq->execute([$id]);
                    $existingReq = $existingReq->fetch();
                    if (!$existingReq) {
                        $failEdit('Requirement not found.');
                    }
                    if ((int)$existingReq['department_id'] !== $deptId) {
                        $failEdit('This requirement does not belong to this department.');
                    }
                    $isExpiredReq = classifyExpiryStatus($existingReq['expiration_date'] ?? null) === 'expired';

                    if ($isExpiredReq) {
                        $doneStmt = $pdo->prepare("
                            SELECT 1 FROM documents d
                            WHERE d.archived = 0 AND d.status = 'approved_admin'
                              AND ( d.teacher_requirement_id IN (SELECT tr.id FROM teacher_requirements tr WHERE tr.requirement_id = ?)
                                    OR (d.teacher_requirement_id IS NULL AND d.department_id = ?
                                        AND d.category = ? AND d.document_type = ?) )
                            LIMIT 1
                        ");
                        $doneStmt->execute([$id, $deptId, $existingReq['category'], $existingReq['document_type']]);
                        $isComplete = (bool)$doneStmt->fetchColumn();

                        if ($isComplete) {
                            $titleChanged = ($category !== (string)$existingReq['category'])
                                         || ($docType !== (string)$existingReq['document_type']);
                            if (!$titleChanged) {
                                $failEdit('This requirement is already Complete. A new cycle would create a duplicate document title — change the Category or Document Title to continue.');
                            }
                        }

                        $pdo->prepare('UPDATE department_requirements SET department_id=?, folder_id=?, category=?, document_type=?, description=?, mandatory=?, expiration_date=?, status=?, cycle_started_at=NOW(), admin_updated_at=NULL WHERE id=?')
                            ->execute([$deptId, $folderId, $category, $docType, $description, $mandatory, $expiryDate, 'active', $id]);
                        logAudit($pdo, 'edit', "Requirement reactivated as new cycle: $category - $docType - PH to assign", 'department_requirement', $id);
                        $ph = getProgramHeadForDepartment($pdo, $deptId);
                        if ($ph) {
                            createNotification($pdo, $ph['id'], 'New Requirement', "New requirement cycle started: $category - $docType. Please review and assign.", 'info');
                        }
                        $success = 'Requirement updated successfully.';
                    } else {
                        $pdo->prepare('UPDATE department_requirements SET department_id=?, folder_id=?, category=?, document_type=?, description=?, mandatory=?, expiration_date=?, admin_updated_at=NOW() WHERE id=?')
                            ->execute([$deptId, $folderId, $category, $docType, $description, $mandatory, $expiryDate, $id]);

                        if ($category !== (string)$existingReq['category'] || $docType !== (string)$existingReq['document_type']) {
                            $pdo->prepare("
                                UPDATE documents SET title = ?, category = ?, document_type = ?
                                WHERE archived = 0 AND (
                                    teacher_requirement_id IN (SELECT id FROM teacher_requirements WHERE requirement_id = ?)
                                    OR (teacher_requirement_id IS NULL AND department_id = ?
                                        AND category = ? AND document_type = ?)
                                )
                            ")->execute([
                                $category . ' - ' . $docType, $category, $docType,
                                $id, $deptId, $existingReq['category'], $existingReq['document_type'],
                            ]);
                        }
                        logAudit($pdo, 'edit', "Requirement updated: $category - $docType - PH must re-action", 'department_requirement', $id);
                        $ph = getProgramHeadForDepartment($pdo, $deptId);
                        if ($ph) {
                            createNotification($pdo, $ph['id'], 'Requirement Updated', "Admin updated: $category - $docType. Please review and re-upload or assign.", 'warning');
                        }
                        $success = 'Requirement updated successfully.';
                    }
                } else {
                    $pdo->prepare('INSERT INTO department_requirements (department_id, folder_id, category, document_type, description, mandatory, expiration_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$deptId, $folderId, $category, $docType, $description, $mandatory, $expiryDate, 'active']);
                    $newId = (int)$pdo->lastInsertId();
                    logAudit($pdo, 'create', "Requirement created: $category - $docType", 'department_requirement', $newId);
                    $ph = getProgramHeadForDepartment($pdo, $deptId);
                    if ($ph) {
                        createNotification($pdo, $ph['id'], 'New Department Requirement', "New requirement added: $category - $docType.", 'info');
                    }
                    $success = 'Requirement created successfully.';
                }
                $pdo->commit();
                $redirectUrl = 'admin_department_requirements.php?dept=' . $deptId . '&folder=' . ($folderId ?? 0) . '&success=' . urlencode($success);
                $refreshId = $id ?: ($newId ?? 0);
                if ($refreshId) {
                    refreshRequirementExpiry($pdo, (int)$refreshId);
                }
                if (isAjaxRequest()) {
                    sendJson(['success' => true, 'message' => $success, 'redirect' => $redirectUrl]);
                }
                header('Location: ' . $redirectUrl);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[CHED] dept requirements save failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
                if (isAjaxRequest()) {
                    sendJson(['success' => false, 'error' => $error]);
                }
            }
        }
    }

    // ---------- Delete Requirement ----------
    elseif ($action === 'delete_requirement') {
        $id = (int)$_POST['id'];
        $reqStmt = $pdo->prepare('SELECT department_id, category, document_type FROM department_requirements WHERE id = ?');
        $reqStmt->execute([$id]);
        $req = $reqStmt->fetch();
        if ($req) {
            $wipeStmt = $pdo->prepare('SELECT teacher_id FROM teacher_requirements WHERE requirement_id = ?');
            $wipeStmt->execute([$id]);
            $assignedTeachers = $wipeStmt->fetchAll(PDO::FETCH_COLUMN);
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM department_requirements WHERE id=?')->execute([$id]);
                logAudit($pdo, 'delete', "Requirement deleted id=$id", 'department_requirement', $id);
                $ph = getProgramHeadForDepartment($pdo, $req['department_id']);
                if ($ph) {
                    createNotification($pdo, $ph['id'], 'Requirement Removed', "Admin deleted the requirement: {$req['category']} - {$req['document_type']}.", 'danger');
                }
                foreach ($assignedTeachers as $tid) {
                    createNotification($pdo, (int)$tid, 'Requirement Removed', "The requirement assigned to you was deleted by Admin: {$req['category']} - {$req['document_type']}.", 'danger');
                }
                $pdo->commit();
                $success = 'Requirement deleted successfully.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[CHED] delete_requirement failed: ' . $e->getMessage());
                $error = 'Database error. Requirement was not deleted.';
            }
        } else {
            $error = 'Requirement not found.';
        }
        header('Location: admin_department_requirements.php?dept=' . $deptId . '&folder=' . $currentFolderId . '&'
            . ($error ? 'error' : 'success') . '=' . urlencode($error ?: $success));
        exit;
    }

    // ---------- Create Folder ----------
    elseif ($action === 'create_folder') {
        $deptId = (int)$_POST['department_id'];
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $folderName = trim($_POST['folder_name'] ?? '');
        $badParent = false;
        if ($parentId) {
            $pChk = $pdo->prepare('SELECT department_id FROM department_folders WHERE id = ?');
            $pChk->execute([$parentId]);
            $pChk = $pChk->fetch();
            $badParent = !$pChk || (int)$pChk['department_id'] !== $deptId;
        }
        if (empty($folderName)) {
            $error = 'Folder name is required.';
        } elseif (preg_match('#[/\\\\\x00-\x1f]#', $folderName) || $folderName === '.' || $folderName === '..') {
            $error = 'Folder name cannot contain slashes or control characters.';
        } elseif ($badParent) {
            $error = 'Parent folder does not belong to this department.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO department_folders (department_id, parent_folder_id, folder_name) VALUES (?, ?, ?)');
                $stmt->execute([$deptId, $parentId, $folderName]);
                logAudit($pdo, 'create', "Folder created: $folderName", 'department_folders', $pdo->lastInsertId());
                $success = 'Folder created successfully.';
                header('Location: admin_department_requirements.php?dept=' . $deptId . '&folder=' . ($parentId ?? 0) . '&success=' . urlencode($success));
                exit;
            } catch (PDOException $e) {
                error_log('[CHED] create_folder failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    }

    // ---------- Edit Folder ----------
    elseif ($action === 'edit_folder') {
        $folderId = (int)$_POST['folder_id'];
        $folderName = trim($_POST['folder_name'] ?? '');
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : $deptId;
        if (empty($folderName)) {
            $error = 'Folder name is required.';
        } elseif (preg_match('#[/\\\\\x00-\x1f]#', $folderName) || $folderName === '.' || $folderName === '..') {
            $error = 'Folder name cannot contain slashes or control characters.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, parent_folder_id, department_id FROM department_folders WHERE id = ?');
                $stmt->execute([$folderId]);
                $folder = $stmt->fetch();
                if ($folder && (int)$folder['department_id'] === $deptId) {
                    $pdo->prepare('UPDATE department_folders SET folder_name = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$folderName, $folderId]);
                    logAudit($pdo, 'edit', "Folder renamed to: $folderName", 'department_folders', $folderId);
                    $success = 'Folder renamed successfully.';
                    header('Location: admin_department_requirements.php?dept=' . $deptId . '&folder=' . $currentFolderId . '&success=' . urlencode($success));
                    exit;
                }
                $error = 'Folder not found.';
            } catch (PDOException $e) {
                error_log('[CHED] edit_folder failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    }

    // ---------- Delete Folder ----------
    elseif ($action === 'delete_folder') {
        $folderId = (int)$_POST['folder_id'];
        $stmt = $pdo->prepare('SELECT department_id FROM department_folders WHERE id = ?');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch();
        if ($folder && (int)$folder['department_id'] === $deptId) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM department_folders WHERE id=?')->execute([$folderId]);
                logAudit($pdo, 'delete', "Folder deleted id=$folderId", 'department_folders', $folderId);
                $pdo->commit();
                $success = 'Folder deleted successfully.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[CHED] delete_folder failed: ' . $e->getMessage());
                $error = 'Database error. Folder was not deleted.';
            }
        } else {
            $error = 'Folder not found.';
        }
        header('Location: admin_department_requirements.php?dept=' . $deptId . '&folder=' . $currentFolderId . '&'
            . ($error ? 'error' : 'success') . '=' . urlencode($error ?: $success));
        exit;
    }

    // ---------- Move Requirement (Drag & Drop) ----------
    elseif ($action === 'move_requirement') {
        header('Content-Type: application/json');
        $reqId = (int)($_POST['requirement_id'] ?? 0);
        $targetFolderId = isset($_POST['target_folder_id']) && $_POST['target_folder_id'] !== '' ? (int)$_POST['target_folder_id'] : null;

        if (!$reqId) { echo json_encode(['ok' => false, 'error' => 'Invalid requirement ID.']); exit; }

        $stmt = $pdo->prepare('SELECT id, department_id, folder_id, category, document_type FROM department_requirements WHERE id = ?');
        $stmt->execute([$reqId]);
        $req = $stmt->fetch();
        if (!$req) { echo json_encode(['ok' => false, 'error' => 'Requirement not found.']); exit; }

        if ($targetFolderId) {
            $fstmt = $pdo->prepare('SELECT id, department_id FROM department_folders WHERE id = ?');
            $fstmt->execute([$targetFolderId]);
            $targetFolder = $fstmt->fetch();
            if (!$targetFolder || (int)$targetFolder['department_id'] !== (int)$req['department_id']) {
                echo json_encode(['ok' => false, 'error' => 'Target folder not found or belongs to a different department.']);
                exit;
            }
        }

        $currentFid = $req['folder_id'] ? (int)$req['folder_id'] : null;
        if ($currentFid === $targetFolderId) {
            echo json_encode(['ok' => true, 'noop' => true, 'message' => 'Requirement is already in this folder.']);
            exit;
        }

        $pdo->prepare('UPDATE department_requirements SET folder_id = ?, admin_updated_at = NOW() WHERE id = ?')
            ->execute([$targetFolderId, $reqId]);
        logAudit($pdo, 'move', "Requirement moved to folder " . ($targetFolderId ?? 'root') . ": {$req['category']} - {$req['document_type']}", 'department_requirement', $reqId);

        echo json_encode(['ok' => true, 'message' => 'Requirement moved successfully.']);
        exit;
    }

    // ---------- Move Selected ----------
    elseif ($action === 'move_selected') {
        header('Content-Type: application/json');
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : (json_decode($idsRaw, true) ?: explode(',', $idsRaw));
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        $targetFolderId = (isset($_POST['target_folder_id']) && $_POST['target_folder_id'] !== '') ? (int)$_POST['target_folder_id'] : null;

        if (empty($ids)) { echo json_encode(['ok' => false, 'error' => 'No items to move.']); exit; }

        $destDeptId = 0;
        $destName = '';
        if ($targetFolderId) {
            $fStmt = $pdo->prepare('SELECT id, department_id, folder_name FROM department_folders WHERE id = ?');
            $fStmt->execute([$targetFolderId]);
            $tf = $fStmt->fetch();
            if (!$tf) { echo json_encode(['ok' => false, 'error' => 'Destination folder no longer exists.']); exit; }
            $destDeptId = (int)$tf['department_id'];
            $destName = 'folder "' . $tf['folder_name'] . '"';
        } else {
            $destDeptId = isset($_POST['target_dept_id']) ? (int)$_POST['target_dept_id'] : 0;
            if (!$destDeptId) { echo json_encode(['ok' => false, 'error' => 'Destination department is required.']); exit; }
            $dStmt = $pdo->prepare('SELECT department_name FROM departments WHERE id = ?');
            $dStmt->execute([$destDeptId]);
            $deptRow = $dStmt->fetch();
            if (!$deptRow) { echo json_encode(['ok' => false, 'error' => 'Destination department no longer exists.']); exit; }
            $destName = 'department "' . $deptRow['department_name'] . '"';
        }

        $moved = 0; $skipped = 0; $errors = [];
        try {
            $pdo->beginTransaction();
            foreach ($ids as $rid) {
                $stmt = $pdo->prepare('SELECT id, department_id, folder_id, category, document_type FROM department_requirements WHERE id = ?');
                $stmt->execute([$rid]);
                $req = $stmt->fetch();
                if (!$req) { $errors[] = "ID $rid not found"; continue; }
                $currDept = (int)$req['department_id'];
                $currFolder = $req['folder_id'] ? (int)$req['folder_id'] : null;
                if ($currDept === $destDeptId && $currFolder === $targetFolderId) { $skipped++; continue; }
                $pdo->prepare('UPDATE department_requirements SET department_id = ?, folder_id = ?, admin_updated_at = NOW() WHERE id = ?')
                    ->execute([$destDeptId, $targetFolderId, $rid]);
                logAudit($pdo, 'move', "Requirement moved to $destName: {$req['category']} - {$req['document_type']}", 'department_requirement', $rid);
                $moved++;
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CHED] move_selected failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Database error. Move failed.']);
            exit;
        }

        $msg = "$moved requirement(s) moved to $destName.";
        if ($skipped) $msg .= " $skipped already in the destination.";
        echo json_encode(['ok' => true, 'moved' => $moved, 'skipped' => $skipped, 'message' => $msg, 'errors' => $errors]);
        exit;
    }

    // ---------- Bulk Delete ----------
    elseif ($action === 'bulk_delete') {
        header('Content-Type: application/json');
        $idsRaw = $_POST['ids'] ?? '';
        $ids = is_array($idsRaw) ? $idsRaw : (json_decode($idsRaw, true) ?: explode(',', $idsRaw));
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (empty($ids)) { echo json_encode(['ok' => false, 'error' => 'No selection.']); exit; }
        $deleted = 0;
        try {
            $pdo->beginTransaction();
            foreach ($ids as $rid) {
                $stmt = $pdo->prepare('SELECT department_id, category, document_type FROM department_requirements WHERE id=?');
                $stmt->execute([$rid]);
                $req = $stmt->fetch();
                if (!$req) continue;
                $wipeStmt = $pdo->prepare('SELECT teacher_id FROM teacher_requirements WHERE requirement_id = ?');
                $wipeStmt->execute([$rid]);
                $assignedTeachers = $wipeStmt->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare('DELETE FROM department_requirements WHERE id=?')->execute([$rid]);
                logAudit($pdo, 'delete', "Requirement bulk deleted id=$rid", 'department_requirement', $rid);
                $ph = getProgramHeadForDepartment($pdo, $req['department_id']);
                if ($ph) createNotification($pdo, $ph['id'], 'Requirement Removed', "Admin deleted: {$req['category']} - {$req['document_type']}.", 'danger');
                foreach ($assignedTeachers as $tid) {
                    createNotification($pdo, (int)$tid, 'Requirement Removed', "The requirement assigned to you was deleted by Admin: {$req['category']} - {$req['document_type']}.", 'danger');
                }
                $deleted++;
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CHED] bulk_delete failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Database error. Delete failed.']);
            exit;
        }
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'message' => "$deleted item(s) deleted."]);
        exit;
    }
}

if (isset($_GET['success'])) $success = $_GET['success'];
if (isset($_GET['error'])) $error = $_GET['error'];

// ------------------------------------------------------------------
// Fetch data for current department
// ------------------------------------------------------------------
$deptStats = [];
foreach ($departments as $d) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM department_requirements WHERE department_id = ?");
    $stmt->execute([$d['id']]);
    $reqCount = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM department_folders WHERE department_id = ?");
    $stmt->execute([$d['id']]);
    $folderCount = $stmt->fetchColumn();
    $deptStats[$d['id']] = ['requirements' => $reqCount, 'folders' => $folderCount];
}

$folderPath = [];
$contents = [];
$deptName = '';
$locationBreadcrumb = '';

if ($deptId) {
    foreach ($departments as $d) {
        if ($d['id'] == $deptId) { $deptName = $d['department_name']; break; }
    }
    if ($currentFolderId) {
        $stmt = $pdo->prepare('SELECT id, parent_folder_id, folder_name FROM department_folders WHERE id = ?');
        $stmt->execute([$currentFolderId]);
        $folder = $stmt->fetch();
        while ($folder) {
            array_unshift($folderPath, ['id' => $folder['id'], 'name' => $folder['folder_name']]);
            if ($folder['parent_folder_id']) {
                $stmt = $pdo->prepare('SELECT id, parent_folder_id, folder_name FROM department_folders WHERE id = ?');
                $stmt->execute([$folder['parent_folder_id']]);
                $folder = $stmt->fetch();
            } else {
                break;
            }
        }
    }

    if ($currentFolderId) {
        $stmt = $pdo->prepare('SELECT id, folder_name as name, created_at, "folder" as type FROM department_folders WHERE department_id = ? AND parent_folder_id = ? AND LOWER(folder_name) <> \'root\' ORDER BY folder_name');
        $stmt->execute([$deptId, $currentFolderId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, folder_name as name, created_at, "folder" as type FROM department_folders WHERE department_id = ? AND parent_folder_id IS NULL AND LOWER(folder_name) <> \'root\' ORDER BY folder_name');
        $stmt->execute([$deptId]);
    }
    $foldersInFolder = $stmt->fetchAll();

    $reqSql = "SELECT dr.*, d.department_name,
        COUNT(DISTINCT tr.id) as total_assigned,
        COUNT(DISTINCT CASE WHEN docs.status = 'approved_admin' THEN docs.id END) as approved_count,
        COUNT(DISTINCT docs.id) as file_count,
        (SELECT COUNT(*) FROM documents d2 
         WHERE d2.department_id = dr.department_id 
         AND d2.category = dr.category 
         AND d2.document_type = dr.document_type 
         AND d2.status = 'approved_admin' 
         AND d2.teacher_requirement_id IS NULL
         AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_approved_count,
        (SELECT COUNT(*) FROM documents d2 
         WHERE d2.department_id = dr.department_id 
         AND d2.category = dr.category 
         AND d2.document_type = dr.document_type 
         AND d2.status IN ('pending_program_head', 'pending_admin') 
         AND d2.teacher_requirement_id IS NULL
         AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_pending_count,
        (SELECT COUNT(*) FROM documents d2 
         WHERE d2.department_id = dr.department_id 
         AND d2.category = dr.category 
         AND d2.document_type = dr.document_type 
         AND d2.status IN ('rejected_program_head', 'rejected_admin') 
         AND d2.teacher_requirement_id IS NULL
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
    LEFT JOIN departments d ON dr.department_id = d.id
    LEFT JOIN teacher_requirements tr ON tr.requirement_id = dr.id
    LEFT JOIN documents docs ON docs.teacher_requirement_id = tr.id
        AND (dr.cycle_started_at IS NULL OR docs.upload_date >= dr.cycle_started_at)
    WHERE dr.department_id = ? AND " . ($currentFolderId ? 'dr.folder_id = ?' : '(dr.folder_id IS NULL OR dr.folder_id = 0)');
    $reqParams = [$deptId];
    if ($currentFolderId) $reqParams[] = $currentFolderId;
    $reqSql .= " GROUP BY dr.id, d.department_name ORDER BY dr.document_type";
    $reqStmt = $pdo->prepare($reqSql);
    $reqStmt->execute($reqParams);
    $requirementsInFolder = $reqStmt->fetchAll();

    foreach ($foldersInFolder as $f) {
        $contents[] = array_merge($f, ['is_folder' => true, 'created_by' => 'Admin']);
    }
    foreach ($requirementsInFolder as $r) {
        $contents[] = array_merge($r, ['is_folder' => false, 'name' => $r['document_type']]);
    }
    usort($contents, function($a, $b) {
        if ($a['is_folder'] != $b['is_folder']) return $a['is_folder'] ? -1 : 1;
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    $locationBreadcrumb = htmlspecialchars($deptName);
    if (!empty($folderPath)) {
        $folderNames = array_map(function($seg) { return $seg['name']; }, $folderPath);
        $locationBreadcrumb .= ' &gt; ' . implode(' &gt; ', array_map('htmlspecialchars', $folderNames));
    }
}

// Real file explorer icons — pure SVG, no background
function explorerIcon(string $kind, int $size = 0): string
{
    $svg = [
        'folder' => '<path d="M3.6 6A2 2 0 0 1 5.6 4h4l1.8 2.4H19a2 2 0 0 1 2 2v9.3a2 2 0 0 1-2 2H5.6a2 2 0 0 1-2-2V6z" fill="#F6C344"/><path d="M3.6 9.3h17.8v7.4a2 2 0 0 1-2 2H5.6a2 2 0 0 1-2-2V9.3z" fill="#FFDC7F"/>',
        'doc' => '<path d="M6 3.5h7.5L18 8v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1z" fill="#fff" stroke="#94A3B8" stroke-width="1.2" stroke-linejoin="round"/><path d="M13.5 3.5V8H18z" fill="#E2E8F0"/><path d="M8.5 12.5h7M8.5 15h5" stroke="#94A3B8" stroke-width="1.4" stroke-linecap="round"/>',
    ];
    $sizeAttr = $size ? " width=\"$size\" height=\"$size\"" : '';
    return '<svg' . $sizeAttr . ' viewBox="0 0 24 24" fill="none" aria-hidden="true">' . $svg[$kind] . '</svg>';
}

// Pre-open the edit modal (server-side) so there's no flicker
$editModalActive = (isset($_GET['edit']) && $deptId);

$editData = null;
if ($editModalActive) {
    $editReq = $pdo->prepare('SELECT * FROM department_requirements WHERE id = ?');
    $editReq->execute([(int)$_GET['edit']]);
    $editReq = $editReq->fetch();
    if ($editReq) {
        $editData = [
            'id'             => (int)$editReq['id'],
            'department_id'  => (int)$editReq['department_id'],
            'category'       => $editReq['category'],
            'document_type'  => $editReq['document_type'],
            'description'    => $editReq['description'] ?? '',
            'expiration_date'=> $editReq['expiration_date'] ?: '',
            'folder_id'      => $editReq['folder_id'] ? (int)$editReq['folder_id'] : null,
        ];
    } else {
        $editModalActive = false;
    }
}

ob_start();
$pageTitle = 'Department Requirements';
?>
<style>
    @import url('offline/inter-font.css');
    :root {
        --primary: #2f63e0;
        --primary-dark: #2153c1;
        --primary-soft: #5b7ef5;
        --primary-light: #edf2fe;
        --primary-tint: #f5f8ff;
        --success: #10b981;
        --success-light: #d1fae5;
        --warning: #f59e0b;
        --warning-light: #fef3c7;
        --danger: #ef4444;
        --danger-light: #fee2e2;
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
        --border-light: #eef1f6;
        --shadow-xs: 0 1px 2px 0 rgba(15, 23, 42, 0.04);
        --shadow-sm: 0 1px 3px 0 rgba(15, 23, 42, 0.06), 0 1px 2px -1px rgba(15, 23, 42, 0.04);
        --shadow-md: 0 4px 8px -2px rgba(15, 23, 42, 0.08), 0 2px 4px -2px rgba(15, 23, 42, 0.05);
        --shadow-lg: 0 12px 24px -8px rgba(15, 23, 42, 0.12), 0 4px 8px -4px rgba(15, 23, 42, 0.06);
        --radius-sm: 8px;
        --radius-md: 12px;
        --radius-lg: 16px;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        --transition-fast: all 0.14s cubic-bezier(0.4, 0, 0.2, 1);
        --explorer-row-h: 52px;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 0.9375rem;
        line-height: 1.5;
        color: var(--gray-800);
        background: #f1f5f9;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    <?php if ($editModalActive): ?>
    html, body { overflow: hidden !important; }
    <?php endif; ?>

    :focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 6px; }

    ::-webkit-scrollbar { width: 10px; height: 10px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; border: 3px solid transparent; background-clip: content-box; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }

    .content-area {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        padding: 1.25rem 1.5rem !important;
        background: transparent;
    }

    .search-bar {
        display: flex;
        gap: 8px;
        align-items: center;
        background: var(--white);
        border-radius: var(--radius-md);
        padding: 0.35rem 0.35rem 0.35rem 0.75rem;
        box-shadow: var(--shadow-xs);
        border: 1px solid var(--border-light);
    }
    .search-bar input {
        flex: 1;
        padding: 0.5rem 0.6rem;
        border: 1px solid transparent;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-family: inherit;
        color: var(--gray-800);
        background: var(--gray-50);
        transition: var(--transition);
        max-width: 420px;
    }
    .search-bar input:focus {
        outline: none;
        border-color: var(--primary);
        background-color: var(--white);
        box-shadow: 0 0 0 3px rgba(47, 99, 224, 0.15);
    }
    .search-bar input::placeholder { color: var(--gray-400); }
    .search-bar .count-badge {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--primary-dark);
        background: var(--primary-light);
        padding: 0.3rem 0.7rem;
        border-radius: 9999px;
        white-space: nowrap;
        border: 1px solid #dbe7fe;
    }

    .dept-intro {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        padding: 1.05rem 1.25rem;
        box-shadow: var(--shadow-xs);
    }
    .dept-intro-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.01em;
        margin: 0 0 0.15rem 0;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        font-family: inherit;
    }
    .dept-intro-title svg { width: 26px; height: 26px; }
    .dept-intro-sub { font-size: 0.85rem; color: var(--gray-500); margin: 0; }

    .dept-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 1rem;
    }
    .dept-card {
        background: var(--white);
        border-radius: 14px;
        border: 1px solid var(--border);
        padding: 1.15rem 1.15rem 1rem;
        transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.22s ease, border-color 0.22s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        box-shadow: var(--shadow-xs);
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 148px;
        overflow: hidden;
    }
    .dept-card::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(47, 99, 224, 0.045) 0%, transparent 55%);
        opacity: 0;
        transition: opacity 0.22s ease;
        pointer-events: none;
    }
    .dept-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--primary) 0%, var(--primary-soft) 100%);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .dept-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: #c7d2fe;
    }
    .dept-card:hover::before { opacity: 1; }
    .dept-card:hover::after { transform: scaleX(1); }

    .dept-card .folder-icon-big {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        margin-bottom: 0.85rem;
        transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        z-index: 1;
    }
    .dept-card:hover .folder-icon-big { transform: scale(1.08) rotate(-3deg); }
    .dept-card .folder-icon-big svg { width: 48px; height: 48px; }

    .dept-card h3 {
        font-size: 0.95rem;
        font-weight: 700;
        margin: 0 0 0.2rem 0;
        color: var(--gray-900);
        line-height: 1.35;
        letter-spacing: -0.01em;
        position: relative;
        z-index: 1;
    }
    .dept-card .dept-code {
        font-size: 0.68rem;
        color: var(--gray-500);
        font-weight: 700;
        margin-bottom: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        position: relative;
        z-index: 1;
        display: inline-flex;
        padding: 0.15rem 0.5rem;
        background: var(--gray-100);
        border: 1px solid var(--border-light);
        border-radius: 9999px;
        align-self: flex-start;
    }
    .dept-stats {
        display: flex;
        gap: 0.5rem;
        margin-top: auto;
        padding-top: 0.85rem;
        border-top: 1px solid var(--gray-100);
        position: relative;
        z-index: 1;
    }
    .dept-stats .stat-pill {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        background: var(--gray-50);
        border: 1px solid var(--border-light);
        padding: 0.4rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.73rem;
        transition: var(--transition-fast);
    }
    .dept-stats .stat-pill:hover { border-color: #c7d2fe; background: var(--primary-tint); }
    .dept-stats .stat-pill svg { width: 15px; height: 15px; flex-shrink: 0; }
    .dept-stats .stat-pill .stat-number { font-weight: 700; color: var(--gray-900); font-variant-numeric: tabular-nums; }
    .dept-stats .stat-pill .stat-label { color: var(--gray-500); font-weight: 500; }

    .dept-card .arrow {
        position: absolute;
        top: 0.85rem;
        right: 0.85rem;
        width: 26px;
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 9999px;
        opacity: 0;
        transform: translateX(-4px);
        transition: var(--transition);
        font-size: 0.8rem;
        color: var(--primary);
        box-shadow: var(--shadow-xs);
        z-index: 2;
        font-weight: 700;
    }
    .dept-card:hover .arrow { opacity: 1; transform: translateX(0); }

    .explorer-container {
        flex: 1;
        background: var(--white);
        border-radius: var(--radius-md);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        min-height: 0;
        display: flex;
        flex-direction: column;
        width: 100%;
        max-width: 1500px;
        margin: 0 auto;
    }
    .content-panel {
        padding: 0;
        overflow-y: auto;
        flex: 1;
        background: var(--white);
    }

    .breadcrumb {
        font-size: 0.875rem;
        color: var(--gray-500);
        display: flex;
        align-items: center;
        gap: 0.3rem;
        flex-wrap: wrap;
        background: linear-gradient(180deg, #fbfcff 0%, #f7f9fd 100%);
        border-bottom: 1px solid var(--border-light);
        padding: 0.7rem 1.25rem;
    }
    .breadcrumb > svg { flex-shrink: 0; width: 22px; height: 22px; margin-right: 0.25rem; }
    .breadcrumb .bc-chip {
        color: var(--gray-600);
        text-decoration: none;
        font-weight: 500;
        padding: 0.3rem 0.6rem;
        border-radius: 7px;
        display: inline-flex;
        align-items: center;
        transition: var(--transition-fast);
    }
    .breadcrumb .bc-chip:hover { color: var(--primary); background: #e8eefc; }
    .breadcrumb .bc-current {
        color: var(--gray-800);
        font-weight: 600;
        padding: 0.3rem 0.6rem;
        border-radius: 7px;
        background: var(--white);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-xs);
    }
    .breadcrumb .sep { color: var(--gray-400); font-weight: 400; font-size: 0.9rem; padding: 0 0.1rem; }

    .toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.6rem;
        background: var(--white);
        border-bottom: 1px solid var(--border-light);
        padding: 0.65rem 1.25rem;
    }
    .toolbar .btn-group { display: flex; gap: 0.5rem; }
    .toolbar-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0 0.95rem;
        height: 36px;
        border-radius: var(--radius-sm);
        font-weight: 600;
        font-size: 0.85rem;
        font-family: inherit;
        cursor: pointer;
        border: 1px solid transparent;
        transition: var(--transition-fast);
        line-height: 1;
        white-space: nowrap;
        text-decoration: none;
    }
    .btn-sm { padding: 0 0.8rem; font-size: 0.8rem; height: 32px; }
    .btn-primary {
        background: linear-gradient(135deg, var(--primary) 0%, #3b6ee8 100%);
        color: var(--white);
        border-color: var(--primary);
        box-shadow: 0 1px 2px rgba(47, 99, 224, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.15);
    }
    .btn-primary:hover {
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
        border-color: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px -2px rgba(47, 99, 224, 0.35);
    }
    .btn-secondary {
        background: var(--white);
        color: var(--gray-700);
        border-color: var(--border);
        box-shadow: var(--shadow-xs);
    }
    .btn-secondary:hover {
        background: var(--gray-50);
        color: var(--gray-900);
        border-color: var(--gray-300);
        transform: translateY(-1px);
    }
    .btn-danger {
        background: var(--white);
        color: var(--danger);
        border-color: var(--border);
        box-shadow: var(--shadow-xs);
    }
    .btn-danger:hover { background: var(--danger-light); border-color: var(--danger); }
    .btn-danger-solid {
        background: var(--danger);
        color: #fff;
        border-color: var(--danger);
        box-shadow: 0 1px 2px rgba(239, 68, 68, 0.25);
    }
    .btn-danger-solid:hover {
        background: #dc2626;
        border-color: #dc2626;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px -2px rgba(239, 68, 68, 0.35);
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        border-spacing: 0;
        font-size: 0.875rem;
        background: var(--white);
    }
    .data-table thead th {
        text-align: left;
        padding: 0.75rem 1.15rem;
        border-bottom: 1px solid var(--border);
        font-weight: 700;
        color: var(--gray-500);
        text-transform: uppercase;
        font-size: 0.68rem;
        letter-spacing: 0.08em;
        background: linear-gradient(180deg, #fbfcff 0%, #f5f8fd 100%);
        position: sticky;
        top: 0;
        z-index: 1;
        white-space: nowrap;
    }
    .data-table tbody tr {
        transition: background-color 0.15s ease;
        height: var(--explorer-row-h);
    }
    .data-table tbody tr.folder-row { cursor: pointer; }
    .data-table tbody tr:nth-child(even) { background: #fcfdff; }
    .data-table tbody tr:hover { background: var(--primary-tint); }
    .data-table tbody tr.folder-row:hover { background: #e8eefc; }
    .data-table tbody tr:not(:last-child) td { border-bottom: 1px solid var(--border-light); }
    .data-table td {
        padding: 0.7rem 1.15rem;
        vertical-align: middle;
        color: var(--gray-700);
        font-size: 0.875rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .data-table td.name-cell { white-space: normal; overflow-wrap: break-word; min-width: 240px; }
    .data-table .item-name {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
        color: var(--gray-800);
        min-width: 0;
    }
    .data-table .item-name a { text-decoration: none; color: inherit; transition: color 0.15s ease; }
    .data-table .item-name a:hover { color: var(--primary); }

    .data-table .folder-tag-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        flex-shrink: 0;
        transition: var(--transition-fast);
    }
    .data-table tr:hover .folder-tag-icon { transform: scale(1.1); }
    .data-table .folder-tag-icon svg { width: 24px; height: 24px; }

    .data-table .item-sub { display: block; font-size: 0.78rem; font-weight: 400; color: var(--gray-500); margin-top: 0.15rem; }
    .data-table .type-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--gray-600);
        background: var(--gray-100);
        border: 1px solid var(--border-light);
    }
    .data-table .type-badge.folder {
        background: linear-gradient(135deg, #eef4ff 0%, #e0eaff 100%);
        color: var(--primary-dark);
        border-color: #d5e1fb;
    }
    .data-table .req-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.22rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        border: 1px solid transparent;
        line-height: 1.4;
        white-space: nowrap;
    }
    .data-table .req-status-badge.complete    { background: var(--success-light); color: #047857; border-color: #a7f3d0; }
    .data-table .req-status-badge.review      { background: var(--info-light);    color: #1d4ed8; border-color: #bfdbfe; }
    .data-table .req-status-badge.pending-ph  { background: var(--warning-light); color: #92400e; border-color: #fde68a; }
    .data-table .req-status-badge.correction  { background: var(--danger-light);  color: #b91c1c; border-color: #fecaca; }
    .data-table .req-status-badge.not-started { background: var(--gray-100);      color: var(--gray-500); border-color: var(--gray-200); }
    .data-table .req-status-badge svg { width: 11px; height: 11px; flex-shrink: 0; }
    .data-table .item-name-text { display: flex; flex-direction: column; min-width: 0; }

    .data-table .actions {
        display: flex;
        gap: 0.25rem;
        justify-content: flex-end;
        align-items: center;
    }
    .data-table .actions .btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--white);
        border: 1px solid transparent;
        cursor: pointer;
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        transition: var(--transition-fast);
        color: var(--gray-500);
        text-decoration: none;
    }
    .data-table .actions .btn-icon:hover { background: var(--gray-100); color: var(--gray-800); border-color: var(--border-light); transform: translateY(-1px); }
    .data-table .actions .btn-icon.danger:hover { background: var(--danger-light); color: var(--danger); border-color: #fecaca; }
    .data-table .actions .btn-icon.edit:hover,
    .data-table .actions .btn-icon.js-edit-folder:hover { background: var(--primary-light); color: var(--primary-dark); border-color: #dbe7fe; }

    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 4.5rem 1.5rem;
        color: var(--gray-500);
        background: var(--white);
    }
    .empty-state .empty-icon {
        width: 92px;
        height: 92px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.2rem;
    }
    .empty-state .empty-icon svg { width: 76px; height: 76px; }
    .empty-state h3 { margin: 0 0 0.4rem; font-size: 1.05rem; color: var(--gray-800); font-weight: 700; }
    .empty-state p { margin: 0 0 1.4rem; font-size: 0.875rem; color: var(--gray-500); max-width: 400px; line-height: 1.6; }
    .empty-state .empty-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center; }

    /* ===== Modals ===== */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, .5);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .modal-overlay.active { display: flex; animation: modalFadeIn .18s ease; }
    .modal-overlay.pre-opened,
    .modal-overlay.pre-opened .modal-box { animation: none !important; }
    @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes modalPop {
        from { transform: translateY(8px) scale(.98); opacity: 0; }
        to   { transform: translateY(0) scale(1); opacity: 1; }
    }

    .modal-overlay .modal-box {
        background: var(--white);
        border-radius: 14px;
        width: 100%;
        max-width: 520px;
        box-shadow: 0 20px 50px rgba(15, 23, 42, .22), 0 6px 18px rgba(15, 23, 42, .10);
        animation: modalPop .22s ease-out;
        border: 1px solid var(--border);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 2rem);
    }
    .modal-overlay .modal-box.wide { max-width: 620px; }
    .modal-overlay .modal-box.modal-sm { max-width: 400px; }

    .modal-box .modal-header {
        padding: .9rem 1.15rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        background: linear-gradient(180deg, #ffffff 0%, #fafbfd 100%);
    }
    .modal-box .modal-header h2,
    .modal-box .modal-header h3 {
        margin: 0;
        font-size: .95rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: .5rem;
        letter-spacing: -.01em;
    }
    .modal-box .modal-header h2 svg,
    .modal-box .modal-header h3 svg { flex-shrink: 0; }
    .modal-box .modal-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: 1px solid transparent;
        cursor: pointer;
        width: 28px;
        height: 28px;
        border-radius: 8px;
        color: var(--gray-500);
        transition: var(--transition-fast);
        line-height: 1;
    }
    .modal-box .modal-close:hover { background: var(--gray-100); color: var(--gray-800); }
    .modal-box .modal-body {
        padding: 1.1rem 1.15rem;
        overflow-y: auto;
        flex: 1;
        min-height: 0;
        background: #fff;
    }
    .modal-box > form { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
    .modal-box .form-group { margin-bottom: .9rem; }
    .modal-box .form-group:last-child { margin-bottom: 0; }
    .modal-box .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: .35rem;
        font-size: .78rem;
        color: var(--gray-700);
    }
    .modal-box .form-group .required::after { content: " *"; color: var(--danger); }
    .modal-box .form-control {
        width: 100%;
        padding: .55rem .8rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .875rem;
        font-family: inherit;
        background: var(--white);
        color: var(--gray-800);
        transition: var(--transition-fast);
        min-height: 38px;
    }
    .modal-box .form-control:hover { border-color: var(--gray-300); }
    .modal-box .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(47, 99, 224, 0.15); outline: none; }
    .modal-box .form-control::placeholder { color: var(--gray-400); }
    .modal-box textarea.form-control { resize: vertical; min-height: 60px; max-height: 120px; }

    .modal-error-alert {
        display: flex;
        align-items: flex-start;
        gap: .55rem;
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
        border-left: 3px solid var(--danger);
        border-radius: var(--radius-sm);
        padding: .6rem .8rem;
        font-size: .8rem;
        font-weight: 500;
        margin-bottom: .9rem;
        line-height: 1.45;
    }
    .modal-error-alert svg { flex-shrink: 0; margin-top: .1rem; }

    .modal-box .modal-footer {
        padding: .8rem 1.15rem;
        border-top: 1px solid var(--border);
        display: flex;
        gap: .55rem;
        justify-content: flex-end;
        flex-shrink: 0;
        background: #fafbfd;
    }
    .modal-box .modal-footer .btn { min-width: 88px; justify-content: center; }

    .btn-success {
        background: var(--success);
        color: #fff;
        border-color: var(--success);
        box-shadow: 0 1px 2px rgba(16, 185, 129, .2);
    }
    .btn-success:hover { background: #059669; border-color: #059669; transform: translateY(-1px); }

    /* Confirm modal */
    .confirm-body { padding: 1.15rem 1.15rem .35rem; text-align: center; }
    .confirm-icon {
        width: 44px; height: 44px; margin: 0 auto .65rem;
        display: flex; align-items: center; justify-content: center;
        border-radius: 9999px;
    }
    .confirm-icon.danger { background: var(--danger-light); color: var(--danger); }
    .confirm-body h3 {
        font-size: .95rem; font-weight: 700; color: var(--gray-900);
        margin: 0 0 .3rem; letter-spacing: -.01em;
    }
    .confirm-body p {
        font-size: .82rem; color: var(--gray-600); line-height: 1.5;
        margin: 0; word-break: break-word;
    }
    .confirm-footer {
        display: flex; gap: .55rem; justify-content: center;
        padding: .85rem 1.15rem 1.05rem;
    }
    .confirm-footer .btn { min-width: 96px; justify-content: center; }

    /* Success toast — top banner, no backdrop, page stays usable */
    .modal-overlay#successModal {
        background: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        pointer-events: none;
        align-items: flex-start;
        justify-content: center;
        padding: 0;
    }
    .modal-overlay#successModal.active { animation: none; }
    .modal-overlay#successModal .modal-box.toast-box {
        pointer-events: auto;
        width: auto;
        max-width: calc(100vw - 2rem);
        margin-top: 16px;
        border-radius: 10px;
        border: 1px solid #bbf7d0;
        border-left: 4px solid #16a34a;
        background: #f0fdf4;
        box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
        animation: toastDrop .26s cubic-bezier(.2, .9, .3, 1);
        overflow: visible;
    }
    @keyframes toastDrop {
        from { transform: translateY(-18px); opacity: 0; }
        to   { transform: translateY(0); opacity: 1; }
    }
    .toast-box .toast-body {
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: .7rem .8rem .7rem .9rem;
        text-align: left;
        font-size: .88rem;
        font-weight: 600;
        color: #14532d;
        line-height: 1.4;
        background: transparent;
    }
    .toast-box .toast-body > svg { flex-shrink: 0; color: #16a34a; }
    .toast-box .toast-dismiss {
        margin-left: auto;
        flex-shrink: 0;
        border: 0;
        background: transparent;
        color: #4d7c5f;
        cursor: pointer;
        padding: .2rem;
        border-radius: 6px;
        display: flex;
    }
    .toast-box .toast-dismiss:hover { background: rgba(22, 163, 74, .12); color: #166534; }

    /* Form sections */
    .location-display {
        background: linear-gradient(180deg, #fbfcff 0%, #f7f9fd 100%);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: .55rem .8rem;
        font-size: .78rem;
        color: var(--gray-500);
        margin-bottom: .9rem;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .location-display strong { color: var(--gray-700); font-weight: 600; }
    .location-display .folder-path { color: var(--gray-700); font-weight: 500; }
    .form-section {
        background: linear-gradient(180deg, #fbfcff 0%, #f7f9fd 100%);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-sm);
        padding: .75rem .85rem;
        margin-bottom: .9rem;
    }
    .form-section-title {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: .78rem;
        font-weight: 600;
        color: var(--gray-800);
        margin-bottom: .55rem;
    }
    .form-section-title svg { color: var(--primary); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    @media (max-width: 560px) { .form-row { grid-template-columns: 1fr; } }

    .date-field { position: relative; display: flex; align-items: stretch; gap: .5rem; }
    .date-field .form-control { flex: 1; font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
    .date-field .date-native-hidden {
        position: absolute; width: 1px; height: 1px;
        padding: 0; margin: 0; border: 0; opacity: 0;
        pointer-events: none; left: 0; bottom: 0; overflow: hidden;
    }
    .date-field .date-pick-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 38px; flex-shrink: 0;
        background: var(--white); color: var(--gray-600);
        border: 1px solid var(--border); border-radius: var(--radius-sm);
        cursor: pointer; transition: var(--transition-fast); box-shadow: var(--shadow-xs);
    }
    .date-field .date-pick-btn:hover { background: var(--primary-light); color: var(--primary-dark); border-color: #dbe7fe; }
    .date-field .date-pick-btn svg { width: 15px; height: 15px; }

    .content-area > .alert { margin: 0; }
    .alert {
        padding: .8rem 1rem;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: .65rem;
        font-size: .875rem;
        border: 1px solid;
        box-shadow: var(--shadow-sm);
    }
    .alert-danger {
        background: linear-gradient(135deg, #fef2f2 0%, #fff7f7 100%);
        color: #b91c1c;
        border-color: #fecaca;
        border-left-color: var(--danger);
    }
    .alert-success {
        background: linear-gradient(135deg, #ecfdf5 0%, #f6fffb 100%);
        color: #065f46;
        border-color: #a7f3d0;
        border-left-color: var(--success);
    }

    .data-table th.sel-col, .data-table td.sel-col { width: 42px; text-align: center; padding: 0.5rem 0.6rem; }
    .data-table th.sel-col input, .data-table td.sel-col input { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; vertical-align: middle; }
    .data-table tr.row-selected { background: var(--primary-light) !important; }
    .data-table tr.is-cut { opacity: 0.5; }

    .explorer-ribbon {
        display: flex; align-items: center; gap: 0.15rem;
        background: linear-gradient(180deg, #fbfcff 0%, #f5f8fd 100%);
        border: 1px solid var(--border-light);
        border-radius: 10px;
        padding: 0.2rem;
        box-shadow: var(--shadow-xs);
    }
    .explorer-ribbon .ribbon-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
        min-width: 36px; height: 28px; padding: 0 0.65rem;
        background: transparent; border: 1px solid transparent; border-radius: 8px;
        color: var(--gray-600); font-size: 0.78rem; font-weight: 600; font-family: inherit;
        cursor: pointer; white-space: nowrap; transition: var(--transition-fast);
    }
    .explorer-ribbon .ribbon-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
    .explorer-ribbon .ribbon-btn:hover:not(:disabled) { background: var(--white); color: var(--primary); border-color: var(--border); box-shadow: var(--shadow-xs); }
    .explorer-ribbon .ribbon-btn:disabled { opacity: 0.4; cursor: default; }
    .explorer-ribbon .ribbon-sep { width: 1px; height: 18px; background: var(--border); margin: 0 0.2rem; }
    .clipboard-hint { font-size: 0.75rem; color: var(--gray-500); padding: 0 0.5rem; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .data-table tr.dragging { opacity: 0.45; background: var(--primary-light) !important; }
    .data-table tr.folder-row.drag-over { background: #dbe7fe !important; box-shadow: inset 0 0 0 2px var(--primary); border-radius: 6px; }
    .drag-ghost {
        position: fixed; pointer-events: none; z-index: 100000;
        background: var(--white); border: 1px solid var(--primary);
        border-radius: var(--radius-sm); padding: 0.4rem 0.7rem;
        font-size: 0.8rem; font-weight: 600; color: var(--primary);
        box-shadow: var(--shadow-md); white-space: nowrap;
    }
    .drag-move-toast {
        position: fixed; bottom: 16px; right: 16px;
        background: var(--gray-800); color: white;
        padding: 0.65rem 0.95rem; border-radius: var(--radius-sm);
        font-size: 0.85rem; font-weight: 500;
        box-shadow: var(--shadow-lg); z-index: 99998;
        animation: toastIn 0.2s ease;
    }
    @keyframes toastIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    @media (max-width: 900px) {
        .content-area { padding: 1rem !important; }
        .explorer-container { max-width: none; }
        .dept-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
    }
    @media (max-width: 768px) {
        .dept-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.75rem; }
        .search-bar input { max-width: 100%; }
    }
    @media (max-width: 480px) {
        .content-area { padding: 0.75rem !important; }
        .breadcrumb { font-size: 0.8rem; padding: 0.6rem 0.75rem; }
        .toolbar { padding: 0.5rem 0.75rem; }
        .toolbar .btn-group { flex-wrap: wrap; width: 100%; }
        .toolbar .btn-group .btn { flex: 1; justify-content: center; min-width: 110px; }
        .data-table td, .data-table th { padding: 0.5rem 0.75rem; font-size: 0.81rem; }
        .dept-grid { grid-template-columns: 1fr 1fr; gap: 0.65rem; }
        .dept-card { padding: 0.9rem; min-height: 128px; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if (!$deptId): ?>
    <div class="dept-intro">
        <div>
            <h2 class="dept-intro-title"><?= explorerIcon('folder') ?> Select Department</h2>
            <p class="dept-intro-sub">Choose a department to manage its folders and requirements.</p>
        </div>
        <div class="search-bar">
            <input type="text" id="deptSearch" placeholder="Search departments...">
            <span class="count-badge" id="deptCount"><?= count($departments) ?> departments</span>
        </div>
    </div>

    <div class="dept-grid" id="deptGrid">
        <?php foreach ($departments as $d):
            $stats = $deptStats[$d['id']] ?? ['requirements' => 0, 'folders' => 0];
            $code = '';
            if (preg_match('/\(([^)]+)\)/', $d['department_name'], $matches)) {
                $code = $matches[1];
            }
            $displayName = preg_replace('/\s*\([^)]+\)\s*/', '', $d['department_name']);
        ?>
            <a href="?dept=<?= $d['id'] ?>" class="dept-card" data-department="<?= sanitize(strtolower($displayName . ' ' . $code)) ?>">
                <span class="arrow">→</span>
                <div class="folder-icon-big"><?= explorerIcon('folder') ?></div>
                <h3><?= htmlspecialchars($displayName) ?></h3>
                <?php if ($code): ?>
                    <div class="dept-code"><?= htmlspecialchars($code) ?></div>
                <?php endif; ?>
                <div class="dept-stats">
                    <span class="stat-pill">
                        <?= explorerIcon('doc') ?>
                        <span class="stat-number"><?= $stats['requirements'] ?></span>
                        <span class="stat-label">Reqs</span>
                    </span>
                    <span class="stat-pill folders">
                        <?= explorerIcon('folder') ?>
                        <span class="stat-number"><?= $stats['folders'] ?></span>
                        <span class="stat-label">Folders</span>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <script nonce="<?= csp_nonce() ?>">
        document.getElementById('deptSearch').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            const cards = document.querySelectorAll('.dept-card');
            let visible = 0;
            cards.forEach(card => {
                const match = (card.dataset.department || '').includes(query);
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            document.getElementById('deptCount').textContent = visible + ' departments';
        });
    </script>

<?php else: ?>
    <?php if (empty($deptName)) {
        foreach ($departments as $d) {
            if ($d['id'] == $deptId) { $deptName = $d['department_name']; break; }
        }
    } ?>
    <div class="explorer-container">
        <div class="content-panel">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <?= explorerIcon('folder') ?>
                <a href="?dept=<?= $deptId ?>" class="bc-chip"><?= htmlspecialchars($deptName) ?></a>
                <?php if (!empty($folderPath)): ?>
                    <?php foreach ($folderPath as $idx => $seg): ?>
                        <span class="sep" aria-hidden="true">›</span>
                        <?php if ($idx < count($folderPath) - 1): ?>
                            <a href="?dept=<?= $deptId ?>&folder=<?= $seg['id'] ?>" class="bc-chip"><?= htmlspecialchars($seg['name']) ?></a>
                        <?php else: ?>
                            <span class="bc-current"><?= htmlspecialchars($seg['name']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>

            <div class="toolbar">
                <div class="toolbar-left">
                    <a href="?dept=" class="btn btn-sm btn-secondary" title="Back to all departments">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                    </a>
                    <div class="explorer-ribbon" id="explorerRibbon" role="toolbar" aria-label="Explorer commands">
                        <button type="button" class="ribbon-btn" id="btnCopy" disabled title="Copy — stage selected requirement(s) for moving">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="5" width="7" height="7" rx="1"/><path d="M4 9V4a1 1 0 0 1 1-1h5"/></svg>
                            Copy
                        </button>
                        <span class="ribbon-sep" aria-hidden="true"></span>
                        <button type="button" class="ribbon-btn" id="btnMove" disabled title="Move — place the copied requirement(s) in this folder">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l3 5-3 5M15 8H7"/><path d="M7 4H4.5A2.5 2.5 0 0 0 2 6.5v3A2.5 2.5 0 0 0 4.5 12H7"/></svg>
                            Move
                        </button>
                        <span id="clipboardHint" class="clipboard-hint" style="display:none;"></span>
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-sm btn-secondary" data-csp-action="openFolderModal">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                        Create Folder
                    </button>
                    <button class="btn btn-sm btn-primary" data-csp-action="openRequirementModal">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Create Requirement
                    </button>
                </div>
            </div>

            <?php if (empty($contents)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><?= explorerIcon('folder') ?></div>
                    <h3>This folder is empty</h3>
                    <p>Nothing here yet. Create a folder to organize requirements, or add the first one to get started.</p>
                    <div class="empty-actions">
                        <button type="button" class="btn btn-sm btn-secondary" data-csp-action="openFolderModal">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                            Create Folder
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" data-csp-action="openRequirementModal">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Create Requirement
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="sel-col"><input type="checkbox" id="selAll" title="Select all"></th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Deadline</th>
                            <th>Date Created</th>
                            <th>Created By</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contents as $item): ?>
                            <?php if ($item['is_folder']): ?>
                                <tr class="folder-row" data-row-id="req_folder:<?= (int)$item['id'] ?>" data-href="?dept=<?= $deptId ?>&folder=<?= $item['id'] ?>" data-folder-id="<?= $item['id'] ?>">
                                    <td class="sel-col"><input type="checkbox" class="row-check" data-kind="folder" data-id="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>" data-csp-stop></td>
                                    <td class="name-cell">
                                        <div class="item-name">
                                            <span class="folder-tag-icon"><?= explorerIcon('folder') ?></span>
                                            <a href="?dept=<?= $deptId ?>&folder=<?= $item['id'] ?>"><?= htmlspecialchars($item['name']) ?></a>
                                        </div>
                                    </td>
                                    <td><span class="type-badge folder">Folder</span></td>
                                    <td><span style="color:var(--gray-400);">—</span></td>
                                    <td><span style="color:var(--gray-400);">—</span></td>
                                    <td><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?></td>
                                    <td>Admin</td>
                                    <td class="actions">
                                        <button type="button" class="btn-icon js-edit-folder" title="Edit Folder" data-id="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <form method="POST" class="js-delete-form" data-confirm-msg="Are you sure you want to delete this folder and all of its contents? This action cannot be undone." data-confirm-title="Delete Folder?" data-confirm-btn="Delete Folder" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_folder">
                                            <input type="hidden" name="folder_id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="btn-icon danger" title="Delete Folder">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                    $req = $item;
                                    $totalAssigned = (int)($req['total_assigned'] ?? 0);
                                    $directApprovedCount = (int)($req['direct_approved_count'] ?? 0);
                                    $expiryState = classifyExpiryStatus($req['expiration_date'] ?? null);
                                    $isExpired = ($expiryState === 'expired');

                                    $latestStatuses = array_filter(array_map('trim', explode(',', (string)($req['latest_statuses'] ?? ''))));
                                    $directPending = (int)($req['direct_pending_count'] ?? 0);
                                    $directRejected = (int)($req['direct_rejected_count'] ?? 0);
                                    $statusDelivery = null;

                                    $allApproved = !empty($latestStatuses)
                                        && !in_array('pending_program_head', $latestStatuses, true)
                                        && !in_array('pending_admin', $latestStatuses, true)
                                        && !in_array('rejected_program_head', $latestStatuses, true)
                                        && !in_array('rejected_admin', $latestStatuses, true);
                                    $allAssignedApproved = ($totalAssigned > 0) && count($latestStatuses) >= $totalAssigned && $allApproved && $directPending === 0 && $directRejected === 0;
                                    $directOnlyApproved = ($totalAssigned === 0) && $directApprovedCount > 0 && $directPending === 0 && $directRejected === 0;

                                    $hasAnyApproved = $directApprovedCount > 0 || (!empty($latestStatuses) && in_array('approved_admin', $latestStatuses, true));
                                    if ($hasAnyApproved) {
                                        $statusDelivery = 'complete';
                                    } elseif ($allAssignedApproved || $directOnlyApproved) {
                                        $statusDelivery = 'complete';
                                    } elseif ($directRejected > 0 || in_array('rejected_admin', $latestStatuses, true) || in_array('rejected_program_head', $latestStatuses, true)) {
                                        $statusDelivery = 'correction';
                                    } elseif ($directPending > 0 || in_array('pending_admin', $latestStatuses, true) || in_array('pending_program_head', $latestStatuses, true)) {
                                        $statusDelivery = in_array('pending_admin', $latestStatuses, true) || $directPending > 0 ? 'review' : 'pending-ph';
                                    }

                                    $freshCycle = !empty($req['cycle_started_at']);
                                    $statusDelivery = $statusDelivery ?? (!$freshCycle && ($totalAssigned > 0 || !empty($latestStatuses) || ($req['file_count'] ?? 0) > 0) ? 'pending-ph' : 'not-started');

                                    $statusBadgeConfig = [
                                        'complete'    => ['label' => 'Complete', 'class' => 'complete',    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'],
                                        'review'      => ['label' => 'In Review', 'class' => 'review',      'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
                                        'pending-ph'  => ['label' => 'Pending PH', 'class' => 'pending-ph', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'],
                                        'correction'  => ['label' => 'Needs Correction', 'class' => 'correction', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.898 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'],
                                        'not-started' => ['label' => 'Not Started', 'class' => 'not-started', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>'],
                                    ];
                                    $statusDelivery = $statusBadgeConfig[$statusDelivery] ?? $statusBadgeConfig['not-started'];
                                ?>
                                <tr data-row-id="requirement:<?= (int)$req['id'] ?>" data-req-id="<?= $req['id'] ?>" draggable="true" data-kind="requirement">
                                    <td class="sel-col"><input type="checkbox" class="row-check" data-kind="requirement" data-id="<?= (int)$req['id'] ?>" data-name="<?= htmlspecialchars($req['document_type'], ENT_QUOTES) ?>"></td>
                                    <td class="name-cell">
                                        <div class="item-name">
                                            <?= explorerIcon('doc', 18) ?>
                                            <div class="item-name-text">
                                                <span style="display:inline-flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                                    <?= htmlspecialchars($req['document_type']) ?>
                                                </span>
                                                <span class="item-sub" style="display:inline;">
                                                    <span style="display:inline-flex;align-items:center;gap:0.5rem;">
                                                        <?= htmlspecialchars($req['category']) ?>
                                                        <span class="req-status-badge <?= $statusDelivery['class'] ?>">
                                                            <?= $statusDelivery['icon'] ?><?= $statusDelivery['label'] ?>
                                                        </span>
                                                    </span>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="type-badge">Requirement</span></td>
                                    <td>
                                        <?php if ($isExpired): ?>
                                            <span class="type-badge" style="background:#FEE2E2;color:#B91C1C;border-color:#FECACA;">Expired</span>
                                        <?php elseif ($expiryState === 'expiring'): ?>
                                            <span class="type-badge" style="background:#FEF3C7;color:#92400E;border-color:#FDE68A;">Expiring</span>
                                        <?php else: ?>
                                            <span class="type-badge" style="background:#ECFDF5;color:#065F46;border-color:#A7F3D0;">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($req['expiration_date'])): ?>
                                            <span style="color:var(--gray-700);"><?= date('m/d/Y', strtotime($req['expiration_date'])) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--gray-400);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($req['created_at'] ?? 'now')) ?></td>
                                    <td>Admin</td>
                                    <td class="actions">
                                        <a href="?edit=<?= $req['id'] ?>&dept=<?= $deptId ?>&folder=<?= $currentFolderId ?>" class="btn-icon" title="Edit">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </a>
                                        <form method="POST" class="js-delete-form" data-confirm-msg="Are you sure you want to delete this requirement? This action cannot be undone." data-confirm-title="Delete Requirement?" data-confirm-btn="Delete Requirement" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_requirement">
                                            <input type="hidden" name="id" value="<?= $req['id'] ?>">
                                            <button type="submit" class="btn-icon danger" title="Delete">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script nonce="<?= csp_nonce() ?>">
        window.__justDropped = false;
        document.querySelectorAll('.folder-row').forEach(row => {
            row.addEventListener('click', function(e) {
                if (window.__justDropped) { window.__justDropped = false; return; }
                if (e.target.closest('.actions') || e.target.closest('form')) return;
                if (this.dataset.href) window.location.href = this.dataset.href;
            });
        });

        (function() {
            const csrfToken = <?= json_encode(getCsrfToken()) ?>;
            let draggedReqId = null;
            let draggedRow = null;

            document.querySelectorAll('tr[data-req-id]').forEach(row => {
                row.addEventListener('dragstart', function(e) {
                    draggedReqId = this.dataset.reqId;
                    draggedRow = this;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', draggedReqId);
                    const name = this.querySelector('.item-name-text span:first-child');
                    if (name) {
                        const ghost = document.createElement('div');
                        ghost.className = 'drag-ghost';
                        ghost.textContent = name.textContent.trim();
                        document.body.appendChild(ghost);
                        e.dataTransfer.setDragImage(ghost, 0, 0);
                        setTimeout(() => ghost.remove(), 0);
                    }
                });
                row.addEventListener('dragend', function() {
                    this.classList.remove('dragging');
                    draggedReqId = null;
                    draggedRow = null;
                    document.querySelectorAll('.folder-row.drag-over').forEach(f => f.classList.remove('drag-over'));
                });
            });

            function doMove(targetFolderId) {
                const row = draggedRow, reqId = draggedReqId;
                if (!reqId || !row) return;
                const formData = new FormData();
                formData.append('action', 'move_requirement');
                formData.append('csrf_token', csrfToken);
                formData.append('requirement_id', reqId);
                formData.append('target_folder_id', targetFolderId);

                fetch('admin_department_requirements.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.ok && !data.noop) {
                        window.__justDropped = true;
                        row.remove();
                        showToast(data.message || 'Moved.');
                        const tbody = document.querySelector('.data-table tbody');
                        if (tbody && tbody.children.length === 0) window.location.reload();
                    } else if (data.noop) {
                        showToast('Already in this location.');
                    } else {
                        showToast(data.error || 'Move failed.', true);
                    }
                })
                .catch(() => showToast('Network error.', true));
            }

            document.querySelectorAll('tr.folder-row').forEach(folderRow => {
                folderRow.addEventListener('dragover', function(e) {
                    if (!draggedReqId) return;
                    e.preventDefault();
                    e.stopPropagation();
                    e.dataTransfer.dropEffect = 'move';
                    this.classList.add('drag-over');
                });
                folderRow.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
                folderRow.addEventListener('drop', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.classList.remove('drag-over');
                    const fid = this.dataset.folderId;
                    if (draggedReqId && fid) doMove(fid);
                });
            });

            const breadcrumb = document.querySelector('.breadcrumb');
            if (breadcrumb) {
                breadcrumb.addEventListener('dragover', function(e) {
                    if (!draggedReqId) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    this.style.background = '#dbeafe';
                    this.style.boxShadow = 'inset 0 0 0 2px var(--primary)';
                });
                breadcrumb.addEventListener('dragleave', function() {
                    this.style.background = '';
                    this.style.boxShadow = '';
                });
                breadcrumb.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.background = '';
                    this.style.boxShadow = '';
                    if (draggedReqId) doMove('');
                });
            }

            function showToast(msg, isErr) {
                const t = document.createElement('div');
                t.className = 'drag-move-toast';
                if (isErr) t.style.background = '#b91c1c';
                t.textContent = msg;
                document.body.appendChild(t);
                setTimeout(() => t.remove(), 2500);
            }
            window.__explorerShowToast = showToast;
        })();

        (function(){
            const csrfToken = <?= json_encode(getCsrfToken()) ?>;
            const deptId = <?= json_encode((int)($deptId ?? 0)) ?>;
            const curFolderId = <?= json_encode($currentFolderId ? (int)$currentFolderId : null) ?>;
            const MOVE_KEY = 'ched_move_items';
            const btnCopy = document.getElementById('btnCopy');
            const btnMove = document.getElementById('btnMove');
            const selAll = document.getElementById('selAll');
            const hint = document.getElementById('clipboardHint');
            if (!btnCopy || !btnMove) return;

            function getMovePayload(){ try { const v=sessionStorage.getItem(MOVE_KEY); return v?JSON.parse(v):null; } catch(e){ return null; } }
            function setMovePayload(data){ sessionStorage.setItem(MOVE_KEY, JSON.stringify(data)); }
            function clearMovePayload(){ sessionStorage.removeItem(MOVE_KEY); }
            function getSelectedRows(){ return Array.from(document.querySelectorAll('.row-check:checked')); }
            function getSelectedReqIds(){ return getSelectedRows().filter(c=>c.dataset.kind==='requirement').map(c=>parseInt(c.dataset.id,10)).filter(Boolean); }

            function updateToolbar(){
                const reqIds = getSelectedReqIds();
                const hasAnySel = getSelectedRows().length>0;
                const mv = getMovePayload();
                const hasStaged = !!(mv && mv.ids && mv.ids.length);
                btnCopy.disabled = !reqIds.length;
                btnMove.disabled = !hasStaged;
                if (hasStaged) {
                    hint.textContent = 'Copied ' + mv.ids.length + ' item(s) for moving ';
                    hint.style.display='inline';
                } else {
                    hint.style.display='none';
                }
                if (selAll) {
                    const allChecks = document.querySelectorAll('.row-check');
                    selAll.checked = allChecks.length>0 && Array.from(allChecks).every(c=>c.checked);
                    selAll.indeterminate = !selAll.checked && hasAnySel;
                }
            }
            function syncRowHighlight(){
                document.querySelectorAll('tr[data-req-id], tr.folder-row').forEach(tr=>{
                    const cbx = tr.querySelector('.row-check');
                    tr.classList.toggle('row-selected', !!(cbx && cbx.checked));
                });
                const mv = getMovePayload();
                const dimIds = mv && mv.ids ? mv.ids : [];
                document.querySelectorAll('tr[data-req-id]').forEach(tr=>{
                    const id = parseInt(tr.dataset.reqId,10);
                    tr.classList.toggle('is-cut', dimIds.includes(id));
                });
            }
            function onSelectionChange(){ syncRowHighlight(); updateToolbar(); }

            document.querySelectorAll('.row-check').forEach(cbx=>{
                cbx.addEventListener('change', onSelectionChange);
                const tr = cbx.closest('tr');
                if (tr && tr.dataset.reqId) {
                    tr.addEventListener('click', function(e){
                        if (e.target.closest('a') || e.target.closest('button') || e.target.closest('form') || e.target.tagName==='INPUT') return;
                        cbx.checked = !cbx.checked;
                        onSelectionChange();
                    });
                }
            });
            if (selAll) selAll.addEventListener('change', function(){
                document.querySelectorAll('.row-check').forEach(c=>{ c.checked = selAll.checked; });
                onSelectionChange();
            });

            function stageForMove(){
                const checks = getSelectedRows();
                if (checks.filter(c=>c.dataset.kind==='folder').length) (window.__explorerShowToast||alert)('Folders cannot be moved — requirements only.', true);
                const ids = getSelectedReqIds();
                if (!ids.length){ (window.__explorerShowToast||alert)('Select requirement(s) first.', true); return; }
                const names = checks.filter(c=>c.dataset.kind==='requirement').map(c=>c.dataset.name);
                setMovePayload({deptId:deptId, ids:ids, names:names, at:Date.now()});
                updateToolbar(); syncRowHighlight();
                (window.__explorerShowToast||function(m){alert(m);})('Copied ' + ids.length + ' item(s) for moving — navigate to the destination folder and click Move.');
            }
            function clearStaged(){
                document.querySelectorAll('tr.is-cut').forEach(tr=>tr.classList.remove('is-cut'));
                clearMovePayload();
                updateToolbar();
            }
            btnCopy.addEventListener('click', stageForMove);
            btnMove.addEventListener('click', doMoveItems);

            function doMoveItems(){
                const mv = getMovePayload();
                if (!mv || !mv.ids || !mv.ids.length){
                    (window.__explorerShowToast||alert)('Click Copy first to select requirement(s) to move.', true);
                    return;
                }
                const fd = new FormData();
                fd.append('action', 'move_selected');
                fd.append('csrf_token', csrfToken);
                fd.append('ids', JSON.stringify(mv.ids));
                fd.append('target_folder_id', curFolderId !== null ? String(curFolderId) : '');
                fd.append('target_dept_id', String(deptId));
                fetch('admin_department_requirements.php', {method:'POST', body:fd})
                .then(r=>r.json())
                .then(data=>{
                    if (data.ok){
                        (window.__explorerShowToast||alert)(data.message);
                        clearStaged();
                        location.reload();
                    } else {
                        (window.__explorerShowToast||alert)(data.error||'Move failed', true);
                    }
                }).catch(()=>{ (window.__explorerShowToast||alert)('Network error', true); });
            }

            function doBulkDelete(){
                const ids = getSelectedReqIds();
                if(!ids.length) return;
                if(!confirm('Delete ' + ids.length + ' selected requirement(s)? This cannot be undone.')) return;
                const fd = new FormData();
                fd.append('action','bulk_delete');
                fd.append('csrf_token', csrfToken);
                fd.append('ids', JSON.stringify(ids));
                fetch('admin_department_requirements.php', {method:'POST', body:fd})
                .then(r=>r.json()).then(data=>{
                    if(data.ok){ (window.__explorerShowToast||alert)(data.message); location.reload(); }
                    else { (window.__explorerShowToast||alert)(data.error||'Delete failed', true); }
                }).catch(()=>{ (window.__explorerShowToast||alert)('Network error', true); });
            }

            document.addEventListener('keydown', function(e){
                if (e.target.tagName==='INPUT' || e.target.tagName==='TEXTAREA' || e.target.isContentEditable) return;
                if ((e.ctrlKey||e.metaKey) && (e.key.toLowerCase()==='c' || e.key.toLowerCase()==='x')) {
                    if (getSelectedReqIds().length){ e.preventDefault(); stageForMove(); }
                } else if (e.key==='Escape') {
                    if (getMovePayload()){ e.preventDefault(); clearStaged(); }
                } else if (e.key==='Delete') {
                    if (getSelectedReqIds().length){ e.preventDefault(); doBulkDelete(); }
                } else if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='a') {
                    const checks = document.querySelectorAll('.row-check');
                    if(checks.length){ e.preventDefault(); checks.forEach(c=>c.checked=true); onSelectionChange(); }
                }
            });

            updateToolbar(); syncRowHighlight();
        })();
    </script>
<?php endif; ?>

<!-- ========== MODAL: Create/Edit Requirement ========== -->
<div class="modal-overlay<?= $editModalActive ? ' active pre-opened' : '' ?>" id="requirementModal" aria-hidden="<?= $editModalActive ? 'false' : 'true' ?>">
    <div class="modal-box wide">
        <div class="modal-header">
            <h2 id="reqModalTitle">Create Requirement</h2>
            <button class="modal-close" data-csp-action="closeRequirementModal" type="button" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" id="requirementForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="reqAction" value="add_requirement">
            <input type="hidden" name="id" id="reqEditId" value="">
            <input type="hidden" name="folder_context_id" id="reqFolderContext" value="<?= $currentFolderId ?: '' ?>">
            <input type="hidden" name="department_id" id="reqDeptHidden" value="<?= $deptId ?>">
            <div class="modal-body">
                <div id="reqModalError" class="modal-error-alert" role="alert" aria-live="assertive" style="display:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span id="reqModalErrorText"></span>
                </div>

                <div id="locationDisplay" class="location-display" <?= $deptId ? '' : 'style="display:none;"' ?>>
                    <strong>Location:</strong> <span class="folder-path"><?= $locationBreadcrumb ?></span>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="reqCategory">Category</label>
                        <input type="text" name="category" id="reqCategory" class="form-control" list="categoryList" placeholder="e.g. Curriculum Materials">
                        <datalist id="categoryList">
                            <option value="Curriculum Materials"><option value="Instructional Plans"><option value="Faculty Profile">
                            <option value="Research Documents"><option value="Administrative Documents"><option value="Student Assessment">
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label for="reqDocType" class="required">Document Title</label>
                        <input type="text" name="document_type" id="reqDocType" class="form-control" required placeholder="Enter document title">
                    </div>
                </div>
                <div class="form-group">
                    <label for="reqDesc">Description</label>
                    <textarea name="description" id="reqDesc" class="form-control" rows="2" placeholder="Add a short description about this requirement."></textarea>
                </div>
                <div class="form-section">
                    <div class="form-section-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Deadline
                    </div>
                    <div class="form-group">
                        <div class="date-field">
                            <input type="text" id="reqExpiryDisplay" class="form-control deadline-date-input" placeholder="mm/dd/yyyy" inputmode="numeric" autocomplete="off" maxlength="10" aria-label="Deadline (mm/dd/yyyy)">
                            <input type="date" name="expiration_date" id="reqExpiry" class="date-native-hidden" tabindex="-1" aria-hidden="true">
                            <button type="button" class="date-pick-btn" id="reqExpiryPickBtn" title="Open calendar" aria-label="Open calendar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-csp-action="closeRequirementModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="reqSubmitBtn">Create Requirement</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== MODAL: Edit Folder ========== -->
<div class="modal-overlay" id="editFolderModal" aria-hidden="true">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>Edit Folder</h3>
            <button class="modal-close" data-csp-action="closeEditFolderModal" type="button" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_folder">
            <input type="hidden" name="department_id" value="<?= $deptId ?>">
            <input type="hidden" name="folder_id" id="editFolderId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="editFolderName" class="required">Folder Name</label>
                    <input type="text" name="folder_name" id="editFolderName" class="form-control" required placeholder="Enter folder name">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-csp-action="closeEditFolderModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== MODAL: Confirm Delete ========== -->
<div class="modal-overlay" id="deleteConfirmModal" aria-hidden="true">
    <div class="modal-box modal-sm">
        <div class="confirm-body">
            <div class="confirm-icon danger">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
            </div>
            <h3 id="deleteConfirmTitle">Delete?</h3>
            <p id="deleteConfirmMsg">This action cannot be undone.</p>
        </div>
        <div class="confirm-footer">
            <button type="button" class="btn btn-sm btn-secondary" data-csp-action="closeDeleteConfirmModal">Cancel</button>
            <button type="button" class="btn btn-sm btn-danger-solid" id="deleteConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ========== TOAST: Success banner (top, auto-vanish) ========== -->
<div class="modal-overlay" id="successModal" aria-hidden="true">
    <div class="modal-box toast-box" role="status" aria-live="polite">
        <div class="toast-body">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span id="successMsg">Done.</span>
            <button type="button" class="toast-dismiss" data-csp-action="closeSuccessModal" aria-label="Dismiss">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
</div>

<!-- ========== MODAL: Create Folder ========== -->
<div class="modal-overlay" id="folderModal" aria-hidden="true">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>Create Folder</h3>
            <button class="modal-close" data-csp-action="closeFolderModal" type="button" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="department_id" value="<?= $deptId ?>">
            <input type="hidden" name="parent_id" id="folderParentId" value="<?= $currentFolderId ?: '' ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label for="folderName" class="required">Folder Name</label>
                    <input type="text" name="folder_name" id="folderName" class="form-control" required placeholder="Enter folder name">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-csp-action="closeFolderModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Folder</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
// Teleport modals to <body> so position:fixed always covers full viewport
(function () {
    function teleportModals() {
        document.querySelectorAll('.modal-overlay').forEach(function (modal) {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', teleportModals);
    } else {
        teleportModals();
    }
})();

var isEditing = false;

function openRequirementModal() {
    isEditing = false;
    var m = document.getElementById('requirementModal');
    m.classList.add('active');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.getElementById('requirementForm').reset();
    document.getElementById('reqAction').value = 'add_requirement';
    document.getElementById('reqEditId').value = '';
    document.getElementById('reqDesc').value = '';
    document.getElementById('reqModalTitle').textContent = 'Create Requirement';
    document.getElementById('reqSubmitBtn').textContent = 'Create Requirement';
    if (window.__clearReqModalError) window.__clearReqModalError();
    if (window.__setExpiryValue) window.__setExpiryValue('');
    document.getElementById('locationDisplay').style.display = 'block';
    document.getElementById('reqFolderContext').value = <?= json_encode($currentFolderId) ?> || '';
    document.getElementById('reqDeptHidden').value = <?= json_encode($deptId) ?>;
}

function closeRequirementModal() {
    var m = document.getElementById('requirementModal');
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function openFolderModal() {
    var m = document.getElementById('folderModal');
    m.classList.add('active');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.getElementById('folderName').value = '';
    document.getElementById('folderParentId').value = <?= json_encode($currentFolderId ?: 0) ?>;
}

function closeFolderModal() {
    var m = document.getElementById('folderModal');
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function openEditFolderModal(id, name) {
    document.getElementById('editFolderId').value = id;
    document.getElementById('editFolderName').value = name;
    var m = document.getElementById('editFolderModal');
    m.classList.add('active');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeEditFolderModal() {
    var m = document.getElementById('editFolderModal');
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.querySelectorAll('.js-edit-folder').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditFolderModal(parseInt(btn.dataset.id, 10), btn.dataset.name || '');
    });
});

// ---- Delete Confirmation ----
var pendingDeleteForm = null;

function openDeleteConfirmModal(msg, title, btnLabel) {
    document.getElementById('deleteConfirmMsg').textContent =
        msg || 'Are you sure you want to delete this? This action cannot be undone.';
    document.getElementById('deleteConfirmTitle').textContent = title || 'Delete?';
    document.getElementById('deleteConfirmBtn').textContent = btnLabel || 'Delete';
    var m = document.getElementById('deleteConfirmModal');
    m.classList.add('active');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeDeleteConfirmModal() {
    var m = document.getElementById('deleteConfirmModal');
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    pendingDeleteForm = null;
}

document.querySelectorAll('.js-delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        pendingDeleteForm = form;
        openDeleteConfirmModal(
            form.getAttribute('data-confirm-msg'),
            form.getAttribute('data-confirm-title'),
            form.getAttribute('data-confirm-btn')
        );
    });
});

document.getElementById('deleteConfirmBtn').addEventListener('click', function () {
    if (!pendingDeleteForm) return;
    var f = pendingDeleteForm;
    pendingDeleteForm = null;

    var m = document.getElementById('deleteConfirmModal');
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    HTMLFormElement.prototype.submit.call(f);
});

// ---- Success Toast (top banner, auto-vanish, no scroll lock) ----
var __successTimer = null;
function showSuccessToast(msg) {
    var m = document.getElementById('successModal');
    if (!m) return;
    document.getElementById('successMsg').textContent = msg || 'Success.';
    m.classList.add('active');
    m.setAttribute('aria-hidden', 'false');
    if (__successTimer) clearTimeout(__successTimer);
    __successTimer = setTimeout(closeSuccessModal, 2600);
}
function closeSuccessModal() {
    var m = document.getElementById('successModal');
    if (!m) return;
    m.classList.remove('active');
    m.setAttribute('aria-hidden', 'true');
    if (__successTimer) { clearTimeout(__successTimer); __successTimer = null; }
}
window.showSuccessToast = showSuccessToast;
window.closeSuccessModal = closeSuccessModal;

// Backdrop click + Escape
document.querySelectorAll('.modal-overlay').forEach(function (modal) {
    modal.addEventListener('click', function (e) {
        if (e.target !== this) return;
        switch (this.id) {
            case 'requirementModal': closeRequirementModal(); break;
            case 'folderModal': closeFolderModal(); break;
            case 'editFolderModal': closeEditFolderModal(); break;
            case 'deleteConfirmModal': closeDeleteConfirmModal(); break;
            case 'successModal': closeSuccessModal(); break;
        }
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('requirementModal').classList.contains('active')) return closeRequirementModal();
    if (document.getElementById('folderModal').classList.contains('active')) return closeFolderModal();
    if (document.getElementById('editFolderModal').classList.contains('active')) return closeEditFolderModal();
    if (document.getElementById('deleteConfirmModal').classList.contains('active')) return closeDeleteConfirmModal();
    if (document.getElementById('successModal').classList.contains('active')) return closeSuccessModal();
});

// ---- Deadline field (mm/dd/yyyy) ----
(function () {
    var text   = document.getElementById('reqExpiryDisplay');
    var native = document.getElementById('reqExpiry');
    var btn    = document.getElementById('reqExpiryPickBtn');
    if (!text || !native || !btn) return;

    function pad(n) { return String(n).padStart(2, '0'); }

    function isoToDisplay(iso) {
        if (!iso) return '';
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
        return m ? (m[2] + '/' + m[3] + '/' + m[1]) : '';
    }
    function displayToIso(s) {
        s = (s || '').trim();
        if (!s) return '';
        var m = /^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/.exec(s);
        if (!m) return null;
        var mm = parseInt(m[1], 10), dd = parseInt(m[2], 10), yyyy = parseInt(m[3], 10);
        if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return null;
        var d = new Date(yyyy, mm - 1, dd);
        if (d.getFullYear() !== yyyy || d.getMonth() !== mm - 1 || d.getDate() !== dd) return null;
        return yyyy + '-' + pad(mm) + '-' + pad(dd);
    }
    function syncDisplay() { text.value = isoToDisplay(native.value); }

    text.addEventListener('input', function () {
        var v = this.value.replace(/[^\d]/g, '').slice(0, 8);
        var out;
        if (v.length <= 2) out = v;
        else if (v.length <= 4) out = v.slice(0, 2) + '/' + v.slice(2);
        else out = v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4);
        this.value = out;
        native.value = displayToIso(out) || '';
    });
    text.addEventListener('blur', function () {
        var iso = displayToIso(this.value);
        if (iso) {
            native.value = iso;
            this.value = isoToDisplay(iso);
        } else if (!this.value.trim()) {
            native.value = '';
        } else {
            this.value = isoToDisplay(native.value);
        }
    });
    native.addEventListener('change', syncDisplay);
    btn.addEventListener('click', function () {
        try {
            if (typeof native.showPicker === 'function') native.showPicker();
            else native.click();
        } catch (e) {
            try { native.click(); } catch (_) {}
        }
    });

    window.__setExpiryValue = function (iso) {
        native.value = iso || '';
        syncDisplay();
    };
})();

// ---- Edit modal prefill ----
<?php if ($editModalActive && $editData): ?>
(function () {
    var data = <?= json_encode($editData) ?>;
    function setV(id, val) { var el = document.getElementById(id); if (el) el.value = val; }
    setV('reqAction', 'edit_requirement');
    setV('reqEditId', data.id);
    setV('reqDeptHidden', data.department_id);
    setV('reqCategory', data.category);
    setV('reqDocType', data.document_type);
    setV('reqDesc', data.description || '');
    setV('reqFolderContext', data.folder_id || '');

    var titleEl = document.getElementById('reqModalTitle');
    if (titleEl) titleEl.textContent = 'Edit Requirement';
    var submitBtn = document.getElementById('reqSubmitBtn');
    if (submitBtn) submitBtn.textContent = 'Update Requirement';
    var locDisplay = document.getElementById('locationDisplay');
    if (locDisplay) locDisplay.style.display = 'none';

    if (window.__setExpiryValue) window.__setExpiryValue(data.expiration_date || '');
    isEditing = true;
})();
<?php endif; ?>

// ---- AJAX submit for edit_requirement ----
(function () {
    var form   = document.getElementById('requirementForm');
    var errBox = document.getElementById('reqModalError');
    var errTxt = document.getElementById('reqModalErrorText');
    if (!form || !errBox || !errTxt) return;

    function clearModalError() {
        errBox.style.display = 'none';
        errTxt.textContent = '';
    }
    window.__clearReqModalError = clearModalError;

    form.addEventListener('submit', function (e) {
        if (document.getElementById('reqAction').value !== 'edit_requirement') return;
        e.preventDefault();
        clearModalError();

        var submitBtn = document.getElementById('reqSubmitBtn');
        var origText  = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Updating...'; }

        fetch('admin_department_requirements.php', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = origText; }
            if (data && data.success) {
                window.location.href = data.redirect || window.location.href;
                return;
            }
            errTxt.textContent = (data && data.error) ? data.error : 'Update failed. Please try again.';
            errBox.style.display = 'flex';
            errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        })
        .catch(function () {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = origText; }
            errTxt.textContent = 'Network error. Please try again.';
            errBox.style.display = 'flex';
        });
    });
})();

// ---- Show success toast if there's a flash message ----
(function () {
    var msg = <?= $success ? json_encode($success, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) : 'null' ?>;
    if (!msg) return;
    showSuccessToast(msg);
})();
</script>

<?php include 'includes/footer.php'; ?>
