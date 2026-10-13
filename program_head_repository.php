<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();
// Every folder/upload/forward branch below ends in exit — sync on shutdown so
// ph_repository/<phSlug>/ always mirrors ph_repo_folders and holds no other folder.
register_shutdown_function(static function () use ($pdo): void {
    syncPhRepository($pdo);
});

// ======================================================================
//  SESSION & OWNERSHIP
// ======================================================================
$phId = (int) $_SESSION['user_id'];
$ownDeptId = 0;
$deptGone = false;
try {
    $uDeptStmt = $pdo->prepare(
        "SELECT u.department_id AS dept_id, d.id AS dept_exists
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id
          WHERE u.id = ?"
    );
    $uDeptStmt->execute([$phId]);
    $uRow = $uDeptStmt->fetch();
    if ($uRow && !empty($uRow['dept_id']) && !empty($uRow['dept_exists'])) {
        $ownDeptId = (int) $uRow['dept_id'];
        $_SESSION['department_id'] = $ownDeptId;
    } elseif ($uRow && !empty($uRow['dept_id'])) {
        // ponytail: department deleted — PH repository is independent; keep the
        // stale id as scope so rows stay reachable and PH can keep writing.
        // ceiling: dangling department_id (FK dropped) — upgrade path: soft-delete departments
        $ownDeptId = (int) $uRow['dept_id'];
        $deptGone = true;
    } else {
        $_SESSION['department_id'] = 0;
    }
} catch (Throwable $e) {
    $ownDeptId = 0;
}

$error = '';
$success = '';

// Get own department name
$cacheKey = 'ph_own_dept_' . $phId;
$ownDept = fileCacheGet($cacheKey, 3600);
if ($ownDept === false && $ownDeptId && !$deptGone) {
    $stmt = $pdo->prepare("SELECT id, department_name FROM departments WHERE id = ?");
    $stmt->execute([$ownDeptId]);
    $ownDept = $stmt->fetch();
    fileCacheSet($cacheKey, $ownDept, 3600);
}

$deptId = $ownDeptId;
$currentFolderId = isset($_GET['folder']) && $_GET['folder'] !== '' ? (int)$_GET['folder'] : null;
if ($currentFolderId === null && isset($_GET['folder_id']) && $_GET['folder_id'] !== '') {
    $currentFolderId = (int)$_GET['folder_id'];
}

// Validate folder belongs to the PH and department
$currentFolder = null;
if ($deptId > 0 && $currentFolderId !== null && $currentFolderId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?");
    $stmt->execute([$currentFolderId, $deptId, $phId]);
    $currentFolder = $stmt->fetch();
    if (!$currentFolder) {
        $error = 'Folder not found.';
        $currentFolderId = null;
    }
} else {
    if ($currentFolderId === 0) $currentFolderId = null;
}

if (isset($_GET['success'])) $success = $_GET['success'];
if (isset($_GET['error'])) $error = $_GET['error'];

// ======================================================================
//  DOWNLOAD HANDLER
// ======================================================================
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['repo_id'])) {
    $repoId = (int)$_GET['repo_id'];
    $stmt = $pdo->prepare("SELECT * FROM ph_repository WHERE id = ? AND program_head_id = ? AND (department_id = ?)");
    $stmt->execute([$repoId, $phId, $deptId]);
    $repo = $stmt->fetch();
    if (!$repo) die('Access denied.');
    $filePath = null;
    if (!empty($repo['file_path'])) {
        $filePath = securePath($repo['file_path']);
    } elseif (!empty($repo['ph_requirement_id'])) {
        $reqStmt = $pdo->prepare("SELECT file_path FROM ph_requirements WHERE id = ?");
        $reqStmt->execute([$repo['ph_requirement_id']]);
        $req = $reqStmt->fetch();
        if ($req && !empty($req['file_path'])) $filePath = securePath($req['file_path']);
    } elseif (!empty($repo['ph_document_id'])) {
        $docStmt = $pdo->prepare("SELECT file_path FROM ph_documents WHERE id = ?");
        $docStmt->execute([$repo['ph_document_id']]);
        $doc = $docStmt->fetch();
        if ($doc && !empty($doc['file_path'])) $filePath = securePath($doc['file_path']);
    } elseif (!empty($repo['document_id'])) {
        $docStmt2 = $pdo->prepare("SELECT file_path FROM documents WHERE id = ?");
        $docStmt2->execute([$repo['document_id']]);
        $doc2 = $docStmt2->fetch();
        if ($doc2 && !empty($doc2['file_path'])) $filePath = securePath($doc2['file_path']);
    }
    if (!$filePath || !file_exists($filePath)) die('File not found.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// ======================================================================
//  FOLDER ZIP DOWNLOAD HANDLER
// ======================================================================
if (isset($_GET['action']) && $_GET['action'] === 'download_folder_zip' && isset($_GET['folder_id'])) {
    $zipFolderId = (int)$_GET['folder_id'];

    $fStmt = $pdo->prepare("SELECT * FROM ph_repo_folders WHERE id = ? AND department_id = ? AND program_head_id = ?");
    $fStmt->execute([$zipFolderId, $deptId, $phId]);
    $zipFolder = $fStmt->fetch();

    if (!$zipFolder) {
        header('Location: program_head_repository.php?error=' . urlencode('Access denied. Folder not found or does not belong to you.'));
        exit;
    }

    if (!class_exists('ZipArchive')) {
        header('Location: program_head_repository.php?folder=' . $zipFolderId . '&error=' . urlencode('ZIP functionality is not available on this server.'));
        exit;
    }

    $folderIds   = [$zipFolderId];
    $folderPaths = [$zipFolderId => ''];
    $idx = 0;
    while ($idx < count($folderIds)) {
        $cur = $folderIds[$idx++];
        $children = $pdo->prepare("SELECT id, folder_name FROM ph_repo_folders WHERE parent_folder_id = ? AND department_id = ? AND program_head_id = ? ORDER BY folder_name");
        $children->execute([$cur, $deptId, $phId]);
        foreach ($children->fetchAll() as $ch) {
            $cid = (int)$ch['id'];
            $parentPath = $folderPaths[$cur];
            $folderPaths[$cid] = $parentPath === '' ? $ch['folder_name'] : $parentPath . '/' . $ch['folder_name'];
            $folderIds[] = $cid;
        }
    }

    $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
    $fileStmt = $pdo->prepare("
        SELECT pr.*,
               pd.file_path AS linked_file_path,
               pd.id        AS ph_document_id,
               pd.status    AS doc_status
          FROM ph_repository pr
          LEFT JOIN ph_documents pd ON pr.ph_document_id = pd.id
         WHERE pr.folder_id IN ($placeholders)
           AND pr.department_id = ?
           AND pr.program_head_id = ?
    ");
    $fileStmt->execute(array_merge($folderIds, [$deptId, $phId]));
    $files = $fileStmt->fetchAll();

    $files = array_filter($files, function ($f) {
        return !in_array($f['doc_status'] ?? null, ['rejected_program_head', 'rejected_admin'], true);
    });

    $entries = [];

    foreach ($files as $f) {
        $physicalPath = null;

        if (!empty($f['file_path'])) {
            $physicalPath = securePath($f['file_path']);
        }
        if (!$physicalPath && !empty($f['linked_file_path'])) {
            $physicalPath = securePath($f['linked_file_path']);
        }
        if (!$physicalPath && !empty($f['ph_requirement_id'])) {
            $reqStmt = $pdo->prepare("SELECT file_path FROM ph_requirements WHERE id = ?");
            $reqStmt->execute([$f['ph_requirement_id']]);
            $req = $reqStmt->fetch();
            if ($req && !empty($req['file_path'])) {
                $physicalPath = securePath($req['file_path']);
            }
        }
        if (!$physicalPath && !empty($f['document_id'])) {
            $docStmt = $pdo->prepare("SELECT file_path FROM documents WHERE id = ?");
            $docStmt->execute([$f['document_id']]);
            $doc = $docStmt->fetch();
            if ($doc && !empty($doc['file_path'])) {
                $physicalPath = securePath($doc['file_path']);
            }
        }

        if (!$physicalPath) continue;

        $fileFolderId = (int)$f['folder_id'];
        $subPath = $folderPaths[$fileFolderId] ?? '';

        $filename = $f['filename'] ?? '';
        if ($filename === '' || $filename === null) {
            $filename = $f['title'] ?? ('document_' . $f['id']);
        }
        $filename = basename((string)$filename);
        $filename = preg_replace('/[\/\\\\:*?"<>|]+/', '_', $filename);
        $filename = trim($filename);
        if ($filename === '') $filename = 'document_' . (int)$f['id'];

        $entryPath = $subPath === '' ? $filename : $subPath . '/' . $filename;

        $baseEntry = $entryPath;
        $counter = 1;
        while (isset($entries[$entryPath])) {
            $info = pathinfo($baseEntry);
            $entryPath = ($info['dirname'] !== '.' && $info['dirname'] !== '' ? $info['dirname'] . '/' : '')
                       . $info['filename'] . '_' . $counter
                       . (isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '');
            $counter++;
        }

        $entries[$entryPath] = $physicalPath;
    }

    if (empty($entries)) {
        header('Location: program_head_repository.php?folder=' . $zipFolderId . '&error=' . urlencode('This folder is empty. There are no files to include in the ZIP archive.'));
        exit;
    }

    $tmpZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ph_zip_' . $phId . '_' . $zipFolderId . '_' . bin2hex(random_bytes(6)) . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Location: program_head_repository.php?folder=' . $zipFolderId . '&error=' . urlencode('Unable to create ZIP archive.'));
        exit;
    }

    foreach ($entries as $entryPath => $physicalPath) {
        $zip->addFile($physicalPath, $entryPath);
    }

    if (!$zip->close()) {
        @unlink($tmpZipPath);
        header('Location: program_head_repository.php?folder=' . $zipFolderId . '&error=' . urlencode('Failed to finalize ZIP archive.'));
        exit;
    }

    $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)$zipFolder['folder_name']);
    $safeName = trim($safeName, '_');
    if ($safeName === '') $safeName = 'Folder';
    $downloadName = $safeName . '.zip';

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmpZipPath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($tmpZipPath);
    @unlink($tmpZipPath);

    logAudit($pdo, 'download_ph_repo_folder_zip', "Downloaded ZIP for folder '{$zipFolder['folder_name']}' (ID {$zipFolderId})", 'ph_repo_folders', $zipFolderId);
    exit;
}

// ======================================================================
//  FORWARDED REPO FILE -> ADMIN SUBMISSION
// ======================================================================
function linkRepoForwardToDocuments(PDO $pdo, array $repoItem, int $phId): ?int
{
    if (empty($repoItem['file_path'])) {
        return null;
    }
    // Idempotent: if a linked admin submission already exists, reuse it. Stops a
    // repeated "Forward" click from creating a second documents row.
    if (!empty($repoItem['document_id'])) {
        return (int)$repoItem['document_id'];
    }
    $lockKey = !empty($repoItem['id']) ? 'updoc:repo:' . (int)$repoItem['id'] : null;
    $locked = $lockKey ? acquireUploadLock($pdo, $lockKey) : false;
    if ($locked) {
        register_shutdown_function(function () use ($pdo, $lockKey) { releaseUploadLock($pdo, $lockKey); });
        // Re-check under the lock: a concurrent request may have just linked it.
        $chk = $pdo->prepare("SELECT document_id FROM ph_repository WHERE id = ?");
        $chk->execute([(int)$repoItem['id']]);
        $linked = $chk->fetchColumn();
        if (!empty($linked)) {
            releaseUploadLock($pdo, $lockKey);
            return (int)$linked;
        }
    }
    // Repository original is PROTECTED: forward an independent physical copy.
    // Approve / reject / archive then move only the copy — the original is never
    // renamed or deleted, so the PH repository entry can never point at a dead path.
    $sourceRel = (string)$repoItem['file_path'];
    $copyRel = copyStoredFile(
        $sourceRel,
        getFileStorageDir('pending_admin', 'ph'),
        (string)($repoItem['title'] ?? 'document'),
        strtolower(pathinfo($sourceRel, PATHINFO_EXTENSION))
    );
    if ($copyRel === null) {
        error_log('[CHED] forward aborted, could not copy repository original: ' . $sourceRel);
        return null;
    }
    $stmt = $pdo->prepare("
        INSERT INTO documents (department_id, category, document_type, title, filename, file_path, uploaded_by, teacher_requirement_id, status, version, upload_date, description, source_type, origin_source)
        VALUES (?, 'PH', 'Direct Upload', ?, ?, ?, ?, NULL, 'pending_admin', 1, NOW(), ?, 'repo_forward', 'repository')
    ");
    $stmt->execute([
        $repoItem['department_id'],
        $repoItem['title'],
        $repoItem['filename'] ?: basename($sourceRel),
        $copyRel,
        $phId,
        $repoItem['description'] ?? '',
    ]);
    $docId = (int) $pdo->lastInsertId();
    // Hash the copy now so the admin approval-time duplicate check is cheap.
    ensureDocumentHash($pdo, 'documents', $docId);
    // Record the link so admin review, the PH dashboard and the delete cascades
    // find this copy — and so the repository original is never repointed at it.
    if (!empty($repoItem['id'])) {
        $pdo->prepare("UPDATE ph_repository SET document_id = ? WHERE id = ?")->execute([$docId, (int)$repoItem['id']]);
    }
    if ($locked) releaseUploadLock($pdo, $lockKey);
    return $docId;
}

// ======================================================================
//  POST HANDLERS
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    // ---- CREATE FOLDER ----
    if ($action === 'create_folder') {
        $targetDept = $deptId;
        if ($targetDept < 1) {
            $error = 'Your account is not linked to an existing department, so repository folders cannot be saved. Contact the administrator to assign you a department.';
        }
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        if ($parentId === null && !empty($_POST['parent_folder_id'])) $parentId = (int)$_POST['parent_folder_id'];
        $folderName = trim($_POST['folder_name'] ?? '');

        if (empty($error) && $parentId !== null && $parentId > 0) {
            $chk = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?");
            $chk->execute([$parentId, $targetDept, $phId]);
            if (!$chk->fetch()) { $error = 'Invalid parent folder.'; $parentId = null; }
        } else $parentId = null;

        if (empty($error)) {
            if ($folderName === '') $error = 'Folder name is required.';
            elseif (mb_strlen($folderName) > 255) $error = 'Folder name too long.';
            elseif (preg_match('#[\/\\\\]#', $folderName)) $error = 'Folder name cannot contain slashes.';
            else {
                if ($parentId === null) {
                    $dup = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE (department_id = ?) AND program_head_id = ? AND parent_folder_id IS NULL AND folder_name = ?");
                    $dup->execute([$targetDept, $phId, $folderName]);
                } else {
                    $dup = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE (department_id = ?) AND program_head_id = ? AND parent_folder_id = ? AND folder_name = ?");
                    $dup->execute([$targetDept, $phId, $parentId, $folderName]);
                }
                if ($dup->fetch()) $error = 'A folder with this name already exists.';
                else {
                    try {
                        $ins = $pdo->prepare("INSERT INTO ph_repo_folders (department_id, program_head_id, parent_folder_id, folder_name) VALUES (?, ?, ?, ?)");
                        $ins->execute([$targetDept, $phId, $parentId, $folderName]);
                        $newId = (int)$pdo->lastInsertId();
                        logAudit($pdo, 'create_ph_repo_folder', "Created folder '{$folderName}' (ID {$newId})", 'ph_repo_folders', $newId);
                        $success = "Folder \"{$folderName}\" created successfully.";
                    } catch (PDOException $e) { $error = 'Failed to create folder: ' . $e->getMessage(); }
                }
            }
        }
        $redirect = 'program_head_repository.php?folder=' . ($parentId ?? 0);
        if (!empty($success)) $redirect .= '&success=' . urlencode($success);
        if (!empty($error)) $redirect .= '&error=' . urlencode($error);
        header('Location: ' . $redirect);
        exit;
    }

    // ---- EDIT FOLDER ----
    if ($action === 'edit_folder') {
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $folderName = trim($_POST['folder_name'] ?? '');
        if (!$folderId) $error = 'Folder not found.';
        if (empty($error) && $folderName === '') $error = 'Folder name is required.';

        if (empty($error)) {
            $chk = $pdo->prepare("SELECT * FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?");
            $chk->execute([$folderId, $deptId, $phId]);
            $folder = $chk->fetch();
            if (!$folder) $error = 'Folder not found or access denied.';
            else {
                $parent = $folder['parent_folder_id'];
                if ($parent === null) {
                    $dup = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE (department_id = ?) AND program_head_id = ? AND parent_folder_id IS NULL AND folder_name = ? AND id <> ?");
                    $dup->execute([$deptId, $phId, $folderName, $folderId]);
                } else {
                    $dup = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE (department_id = ?) AND program_head_id = ? AND parent_folder_id = ? AND folder_name = ? AND id <> ?");
                    $dup->execute([$deptId, $phId, $parent, $folderName, $folderId]);
                }
                if ($dup->fetch()) $error = 'A folder with this name already exists.';
                else {
                    $pdo->prepare('UPDATE ph_repo_folders SET folder_name = ?, updated_at = NOW() WHERE id = ?')->execute([$folderName, $folderId]);
                    logAudit($pdo, 'edit_ph_repo_folder', "Renamed folder to: $folderName", 'ph_repo_folders', $folderId);
                    $success = 'Folder renamed successfully.';
                }
            }
        }
        $redirect = 'program_head_repository.php?folder=' . ($currentFolderId ?? 0);
        if (!empty($success)) $redirect .= '&success=' . urlencode($success);
        if (!empty($error)) $redirect .= '&error=' . urlencode($error);
        header('Location: ' . $redirect);
        exit;
    }

    // ---- DELETE FOLDER ----
    if ($action === 'delete_folder') {
        $isAjax = !empty($_POST['ajax']);
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $response = ['success' => false, 'message' => ''];
        if ($folderId) {
            $chk = $pdo->prepare("SELECT * FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?");
            $chk->execute([$folderId, $deptId, $phId]);
            $folder = $chk->fetch();
            if (!$folder) {
                $response['message'] = 'Folder not found or access denied.';
            } else {
                $toDelete = [$folderId];
                $idx = 0;
                while ($idx < count($toDelete)) {
                    $cur = $toDelete[$idx++];
                    $children = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE parent_folder_id = ? AND program_head_id = ? AND (department_id = ?)");
                    $children->execute([$cur, $phId, $deptId]);
                    foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $cid) $toDelete[] = (int)$cid;
                }
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                // Soft delete (§12): only repository entries/folders are removed.
                $pdo->prepare("DELETE FROM ph_repository WHERE folder_id IN ($placeholders) AND program_head_id = ? AND (department_id = ?)")->execute(array_merge($toDelete, [$phId, $deptId]));
                $pdo->prepare("DELETE FROM ph_repo_folders WHERE id IN ($placeholders)")->execute($toDelete);
                logAudit($pdo, 'delete_ph_repo_folder', "Deleted folder '{$folder['folder_name']}' (ID {$folderId})", 'ph_repo_folders', $folderId);
                $response['success'] = true;
                $response['message'] = "Folder \"{$folder['folder_name']}\" and its contents deleted.";
                if ($currentFolderId == $folderId) $currentFolderId = $folder['parent_folder_id'] !== null ? (int)$folder['parent_folder_id'] : null;
            }
        } else {
            $response['message'] = 'Invalid folder ID.';
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        $redirect = 'program_head_repository.php?folder=' . ($currentFolderId ?? 0);
        if ($response['success']) $redirect .= '&success=' . urlencode($response['message']);
        else $redirect .= '&error=' . urlencode($response['message']);
        header('Location: ' . $redirect);
        exit;
    }

    // ---- FORWARD TO ADMIN ----
    if ($action === 'forward_to_admin') {
        $repoId = (int)($_POST['repo_id'] ?? 0);
        if (!$repoId) {
            $error = 'Invalid repository entry.';
        } else {
            $repo = $pdo->prepare("SELECT * FROM ph_repository WHERE id = ? AND program_head_id = ? AND (department_id = ?)");
            $repo->execute([$repoId, $phId, $deptId]);
            $repoItem = $repo->fetch();
            if (!$repoItem) {
                $error = 'Entry not found or access denied.';
            } else {
                // Repository original is protected: forwarding only ever creates an
                // independent copy (linkRepoForwardToDocuments), never moves the file.
                $forwarded = true;
                if (!empty($repoItem['ph_document_id'])) {
                    transitionDocumentStatus($pdo, 'ph_documents', (int)$repoItem['ph_document_id'], 'pending_admin');
                } elseif (!empty($repoItem['document_id'])) {
                    transitionDocumentStatus($pdo, 'documents', (int)$repoItem['document_id'], 'pending_admin');
                } else {
                    $forwarded = linkRepoForwardToDocuments($pdo, $repoItem, $phId) !== null;
                }
                if (!$forwarded) {
                    $error = 'Could not forward: the repository file is missing or its copy failed. Re-upload it, then forward again.';
                } else {
                    $pdo->prepare("UPDATE ph_repository SET forwarded_to_admin = 1, forwarded_at = NOW() WHERE id = ?")->execute([$repoId]);
                    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
                    foreach ($admins as $admin) {
                        createNotification(
                            $pdo,
                            $admin['id'],
                            'Document Forwarded by ' . currentRoleName(),
                            currentRoleName() . " forwarded: \"{$repoItem['title']}\"",
                            'info',
                            null
                        );
                    }
                    logAudit($pdo, 'forward_to_admin', "Forwarded entry #{$repoId}: {$repoItem['title']}", 'ph_repository', $repoId);
                    $success = "\"{$repoItem['title']}\" forwarded to Admin.";
                }
            }
        }
        $redirect = 'program_head_repository.php?folder=' . ($currentFolderId ?? 0);
        if (!empty($success)) $redirect .= '&success=' . urlencode($success);
        if (!empty($error)) $redirect .= '&error=' . urlencode($error);
        header('Location: ' . $redirect);
        exit;
    }

    // ---- MOVE FILE TO FOLDER (single drag & drop) ----
    if ($action === 'move_repo_file') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');

        try {
            $repoId      = (int)($_POST['repo_id'] ?? 0);
            $folderIdRaw = $_POST['folder_id'] ?? '';
            $folderId    = ($folderIdRaw === '' || $folderIdRaw === '0' || $folderIdRaw === 0)
                ? null
                : (int)$folderIdRaw;

            if ($repoId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid file.']);
                exit;
            }

            $repo = $pdo->prepare("SELECT id, folder_id FROM ph_repository WHERE id = ? AND program_head_id = ? AND (department_id = ?)");
            $repo->execute([$repoId, $phId, $deptId]);
            $repoItem = $repo->fetch(PDO::FETCH_ASSOC);

            if (!$repoItem) {
                echo json_encode(['success' => false, 'message' => 'File not found or access denied.']);
                exit;
            }

            if ($folderId !== null) {
                $f = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE id = ? AND program_head_id = ? AND (department_id = ?)");
                $f->execute([$folderId, $phId, $deptId]);
                if (!$f->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Invalid target folder.']);
                    exit;
                }
            }

            $currentFid = ($repoItem['folder_id'] === null || (int)$repoItem['folder_id'] === 0)
                ? null
                : (int)$repoItem['folder_id'];

            if ($currentFid === $folderId) {
                echo json_encode(['success' => true, 'noop' => true, 'message' => 'File is already in this location.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE ph_repository SET folder_id = ? WHERE id = ?")->execute([$folderId, $repoId]);
                logAudit($pdo, 'move_ph_repo_file', "Moved repo entry #{$repoId} to folder " . ($folderId ?? 'root'), 'ph_repository', $repoId);
                $pdo->commit();
            } catch (Throwable $tx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $tx;
            }

            echo json_encode([
                'success'   => true,
                'repo_id'   => $repoId,
                'folder_id' => $folderId,
                'message'   => $folderId ? 'File moved into folder.' : 'File moved to repository root.'
            ]);
            exit;
        } catch (Throwable $t) {
            error_log('[move_repo_file] ' . $t->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error while moving file.']);
            exit;
        }
    }

    // ---- MOVE SELECTED (bulk) ----
    if ($action === 'move_selected') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        try {
            $idsRaw = $_POST['ids'] ?? '';
            $ids = is_array($idsRaw) ? $idsRaw : (json_decode($idsRaw, true) ?: explode(',', $idsRaw));
            $ids = array_values(array_filter(array_map('intval', (array)$ids)));

            $folderIdRaw = $_POST['target_folder_id'] ?? '';
            $targetFolderId = ($folderIdRaw === '' || $folderIdRaw === '0' || $folderIdRaw === 0)
                ? null
                : (int)$folderIdRaw;

            if (empty($ids)) {
                echo json_encode(['ok' => false, 'error' => 'No items to move.']);
                exit;
            }

            if ($deptId < 1) {
                echo json_encode(['ok' => false, 'error' => 'Your account is not linked to a department.']);
                exit;
            }

            if ($targetFolderId !== null) {
                $fStmt = $pdo->prepare("SELECT id, folder_name FROM ph_repo_folders WHERE id = ? AND program_head_id = ? AND department_id = ?");
                $fStmt->execute([$targetFolderId, $phId, $deptId]);
                $tf = $fStmt->fetch(PDO::FETCH_ASSOC);
                if (!$tf) {
                    echo json_encode(['ok' => false, 'error' => 'Destination folder not found or access denied.']);
                    exit;
                }
                $destName = 'folder "' . $tf['folder_name'] . '"';
            } else {
                $destName = 'repository root';
            }

            $moved = 0; $skipped = 0; $errors = [];

            $pdo->beginTransaction();
            try {
                foreach ($ids as $rid) {
                    $stmt = $pdo->prepare("SELECT id, folder_id, title FROM ph_repository WHERE id = ? AND program_head_id = ? AND department_id = ?");
                    $stmt->execute([$rid, $phId, $deptId]);
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$item) { $errors[] = "ID $rid not found."; continue; }

                    $currFolder = ($item['folder_id'] === null || (int)$item['folder_id'] === 0)
                        ? null
                        : (int)$item['folder_id'];

                    if ($currFolder === $targetFolderId) { $skipped++; continue; }

                    $pdo->prepare("UPDATE ph_repository SET folder_id = ? WHERE id = ?")->execute([$targetFolderId, $rid]);

                    logAudit(
                        $pdo,
                        'move_ph_repo_file',
                        "Moved repo entry #{$rid} to " . ($targetFolderId ?? 'root') . " (bulk): {$item['title']}",
                        'ph_repository',
                        $rid
                    );
                    $moved++;
                }
                $pdo->commit();
            } catch (Throwable $tx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $tx;
            }

            $msg = "$moved file(s) moved to $destName.";
            if ($skipped) $msg .= " $skipped already in the destination.";
            echo json_encode([
                'ok'      => true,
                'moved'   => $moved,
                'skipped' => $skipped,
                'message' => $msg,
                'errors'  => $errors,
            ]);
            exit;
        } catch (Throwable $t) {
            error_log('[move_selected] ' . $t->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Server error while moving files.']);
            exit;
        }
    }

    // ---- UPLOAD FILE ----
    if ($action === 'repo_upload') {
        $title = trim($_POST['upload_title'] ?? '');
        $description = trim($_POST['upload_description'] ?? '');
        if ($title === '' || empty($_FILES['upload_file']['name'])) {
            $error = 'Document title and file are required.';
        } elseif ($deptId < 1) {
            $error = 'Your department was deleted, so you can no longer upload. Ask the administrator to reassign you to a department.';
        } else {
            $file = $_FILES['upload_file'];
            $phSlug = phRootSlug($pdo, $phId) ?: ('ph_' . $phId);
            // Full 7-layer pipeline from config.php: validation, content hash,
            // duplicate check under a per-(PH, dept) lock, safe move under a
            // system-generated name.
            $stored = validateAndStoreUpload(
                $pdo,
                $file,
                $title,
                'ph_repository/' . $phSlug,
                'updoc:repo:' . $phId . ':' . $deptId,
                fn(PDO $pdo, string $hash) => findDuplicateRepoDoc($pdo, $deptId, $phId, $title, $hash)
            );
            if (is_string($stored)) {
                $error = $stored;
            } else {
                $repoHash = $stored['hash'];
                $ext = $stored['ext'];
                $filename = $stored['filename'];
                $filePath = 'ph_repository/' . $phSlug . '/' . $filename;
                $uploadDir = UPLOAD_PATH . 'ph_repository' . DIRECTORY_SEPARATOR . $phSlug . DIRECTORY_SEPARATOR;
                $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
                if ($folderId !== null && $folderId > 0) {
                    $chk = $pdo->prepare("SELECT id FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?");
                    $chk->execute([$folderId, $deptId, $phId]);
                    if (!$chk->fetch()) $folderId = null;
                }
                    $autoForward = isset($_POST['auto_forward']);
                    try {
                        $ins = $pdo->prepare("INSERT INTO ph_repository (department_id, program_head_id, folder_id, title, filename, file_path, description, forwarded_to_admin, forwarded_at, created_at, file_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                        $ins->execute([
                            $deptId,
                            $phId,
                            $folderId,
                            $title,
                            basename($file['name']),
                            $filePath,
                            $description,
                            $autoForward ? 1 : 0,
                            $autoForward ? date('Y-m-d H:i:s') : null,
                            $repoHash ?? null
                        ]);
                        $newId = (int)$pdo->lastInsertId();
                        finalizeStorageFilename($pdo, $newId, 'ph_repository', $title, $ext);
                        $freshPath = $pdo->prepare("SELECT file_path, filename FROM ph_repository WHERE id = ?");
                        $freshPath->execute([$newId]);
                        $fresh = $freshPath->fetch() ?: ['file_path' => $filePath, 'filename' => basename($file['name'])];
                        $filePath = $fresh['file_path'];
                        logAudit($pdo, 'create_ph_repo_upload', "Uploaded document '$title' (ID $newId)", 'ph_repository', $newId);
                        if ($autoForward) {
                            $docId = linkRepoForwardToDocuments($pdo, [
                                'id' => $newId,
                                'department_id' => $deptId,
                                'title' => $title,
                                'filename' => $fresh['filename'] ?: basename($file['name']),
                                'file_path' => $filePath,
                                'description' => $description,
                            ], $phId);
                            if ($docId !== null) {
                                $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
                                foreach ($admins as $admin) {
                                    createNotification($pdo, $admin['id'], 'Document Forwarded by ' . currentRoleName(), currentRoleName() . " forwarded: \"$title\"", 'info', null);
                                }
                            } else {
                                $pdo->prepare("UPDATE ph_repository SET forwarded_to_admin = 0, forwarded_at = NULL WHERE id = ?")->execute([$newId]);
                            }
                        }
                        $success = $autoForward
                            ? ($docId ?? null) !== null
                                ? "Document uploaded and forwarded to Admin for review."
                                : "Document uploaded, but forwarding failed — use Forward on the entry to retry."
                            : "Document uploaded successfully.";
                    } catch (PDOException $e) {
                        if (file_exists($uploadDir . $filename)) @unlink($uploadDir . $filename);
                        error_log('[CHED] repo_upload failed: ' . $e->getMessage());
                        $error = 'Failed to save upload.';
                    }
                }
        }
        $redirect = 'program_head_repository.php?folder=' . ($currentFolderId ?? 0);
        if (!empty($success)) $redirect .= '&success=' . urlencode($success);
        if (!empty($error)) $redirect .= '&error=' . urlencode($error);
        header('Location: ' . $redirect);
        exit;
    }

    // ---- DELETE REPOSITORY ITEM ----
    if ($action === 'delete_repo') {
        $repoId = (int)($_POST['repo_id'] ?? 0);
        $isAjax = !empty($_POST['ajax']);
        $response = ['success' => false, 'message' => ''];
        $repo = $pdo->prepare("SELECT * FROM ph_repository WHERE id = ? AND program_head_id = ? AND (department_id = ?)");
        $repo->execute([$repoId, $phId, $deptId]);
        $repoItem = $repo->fetch();
        if ($repoItem) {
            $pdo->prepare("DELETE FROM ph_repository WHERE id = ?")->execute([$repoId]);
            logAudit($pdo, 'delete_ph_repository', "Removed entry #{$repoId}", 'ph_repository', $repoId);
            $response['success'] = true;
            $response['message'] = 'Document removed from repository.';
        } else {
            $response['message'] = 'Cannot remove: entry not found or access denied.';
        }
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        $redirect = 'program_head_repository.php?folder=' . ($currentFolderId ?? 0);
        if ($response['success']) $redirect .= '&success=' . urlencode($response['message']);
        else $redirect .= '&error=' . urlencode($response['message']);
        header('Location: ' . $redirect);
        exit;
    }
}

// ======================================================================
//  FETCH DATA FOR VIEW
// ======================================================================
$deptName = $ownDept['department_name'] ?? 'My Department';

$foldersInFolder = [];
$filesInFolder = [];
$contents = [];
$breadcrumbsWithIds = [];

// Build breadcrumb
if ($currentFolderId) {
    $stmt = $pdo->prepare('SELECT id, parent_folder_id, folder_name FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?');
    $stmt->execute([$currentFolderId, $deptId, $phId]);
    $folder = $stmt->fetch();
    $tmp = [];
    while ($folder) {
        array_unshift($tmp, $folder);
        if ($folder['parent_folder_id']) {
            $stmt = $pdo->prepare('SELECT id, parent_folder_id, folder_name FROM ph_repo_folders WHERE id = ? AND (department_id = ?) AND program_head_id = ?');
            $stmt->execute([$folder['parent_folder_id'], $deptId, $phId]);
            $folder = $stmt->fetch();
        } else break;
    }
    $breadcrumbsWithIds = $tmp;
}

// Subfolders
if ($currentFolderId) {
    $stmt = $pdo->prepare('SELECT id, folder_name as name, created_at, "folder" as type FROM ph_repo_folders WHERE (department_id = ?) AND program_head_id = ? AND parent_folder_id = ? ORDER BY folder_name');
    $stmt->execute([$deptId, $phId, $currentFolderId]);
} else {
    $stmt = $pdo->prepare('SELECT id, folder_name as name, created_at, "folder" as type FROM ph_repo_folders WHERE (department_id = ?) AND program_head_id = ? AND parent_folder_id IS NULL ORDER BY folder_name');
    $stmt->execute([$deptId, $phId]);
}
$foldersInFolder = $stmt->fetchAll();

// Files
if ($currentFolderId === null) {
    $fileStmt = $pdo->prepare("SELECT pr.*, pd.status as doc_status, pd.file_path as linked_file_path, pd.id AS ph_document_id FROM ph_repository pr LEFT JOIN ph_documents pd ON pr.ph_document_id = pd.id WHERE (pr.department_id = ?) AND pr.program_head_id = ? AND pr.folder_id IS NULL ORDER BY pr.created_at DESC, pr.id DESC");
    $fileStmt->execute([$deptId, $phId]);
} else {
    $fileStmt = $pdo->prepare("SELECT pr.*, pd.status as doc_status, pd.file_path as linked_file_path, pd.id AS ph_document_id FROM ph_repository pr LEFT JOIN ph_documents pd ON pr.ph_document_id = pd.id WHERE (pr.department_id = ?) AND pr.program_head_id = ? AND pr.folder_id = ? ORDER BY pr.created_at DESC, pr.id DESC");
    $fileStmt->execute([$deptId, $phId, $currentFolderId]);
}
$filesInFolder = $fileStmt->fetchAll();

$filesInFolder = array_values(array_filter($filesInFolder, function($item){
    $docStatus = $item['doc_status'] ?? null;
    if (in_array($docStatus, ['rejected_program_head', 'rejected_admin'], true)) return false;
    return true;
}));

foreach ($filesInFolder as &$fi) {
    $fi['original_repo_file_path'] = $fi['file_path'] ?? null;
    if (empty($fi['file_path']) && !empty($fi['linked_file_path'])) $fi['file_path'] = $fi['linked_file_path'];
}
unset($fi);

foreach ($foldersInFolder as $f) $contents[] = array_merge($f, ['is_folder' => true]);
foreach ($filesInFolder as $fi) $contents[] = array_merge($fi, ['is_folder' => false, 'name' => $fi['title'] ?? 'Untitled', 'type' => 'file']);
usort($contents, function($a, $b) {
    if ($a['is_folder'] != $b['is_folder']) return $a['is_folder'] ? -1 : 1;
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$locationBreadcrumb = htmlspecialchars($deptName);
if (!empty($breadcrumbsWithIds)) {
    $path = array_column($breadcrumbsWithIds, 'folder_name');
    $locationBreadcrumb .= ' > ' . implode(' > ', array_map('htmlspecialchars', $path));
}
if (empty($breadcrumbsWithIds) && $currentFolderId === null) $locationBreadcrumb .= ' ';

$pageTitle = currentRoleName() . ' Repository';

// ======================================================================
//  ICON HELPERS (reused from admin_manage_document.php)
// ======================================================================
if (!function_exists('render_dashboard_icon')) {
    function render_dashboard_icon(string $name): string {
        $icons = [
            'folder' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>',
            'file' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'plus' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>',
            'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
            'zip' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4a2 2 0 0 1 2-2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="10" x2="12" y2="16" stroke-dasharray="2 2"/><rect x="9" y="16" width="6" height="4" rx="1"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}

/* ═══════════════════════════════════════════════════════════════
   Reused from admin_manage_document.php — real File Explorer icons
   (inline SVG, no background container)
   ═══════════════════════════════════════════════════════════════ */
if (!function_exists('manage_doc_file_icon')) {
    function manage_doc_file_icon(string $slug): string
    {
        $slug = strtolower($slug);

        $body  = 'M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z';
        $fold  = 'M30 2l10 10h-8a2 2 0 0 1-2-2V2z';

        $icons = [
            'pdf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#e53935"/>'
                . '<path d="' . $fold . '" fill="#b71c1c"/>'
                . '<text x="24" y="37" font-family="Arial, Helvetica, sans-serif" font-size="10" font-weight="700" fill="#ffffff" text-anchor="middle" letter-spacing="0.3">PDF</text>'
                . '</svg>',

            'word' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#2b579a"/>'
                . '<path d="' . $fold . '" fill="#1e3f70"/>'
                . '<text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">W</text>'
                . '</svg>',

            'excel' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#217346"/>'
                . '<path d="' . $fold . '" fill="#185c37"/>'
                . '<text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">X</text>'
                . '</svg>',

            'powerpoint' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#d24726"/>'
                . '<path d="' . $fold . '" fill="#a5371e"/>'
                . '<text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">P</text>'
                . '</svg>',

            'image' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/>'
                . '<path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/>'
                . '<rect x="13" y="20" width="22" height="16" rx="1" fill="#5eb7e8"/>'
                . '<circle cx="19" cy="25" r="2" fill="#ffffff"/>'
                . '<path d="M13 34l6-6 4 4 4-5 8 7z" fill="#7cc576"/>'
                . '</svg>',

            'archive' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#ffc107"/>'
                . '<path d="' . $fold . '" fill="#e0a800"/>'
                . '<rect x="20" y="16" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="20" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="24" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="28" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="32" width="8" height="6" rx="1" fill="#5d4037"/>'
                . '</svg>',

            'document' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/>'
                . '<path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/>'
                . '<line x1="16" y1="22" x2="32" y2="22" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/>'
                . '<line x1="16" y1="27" x2="32" y2="27" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/>'
                . '<line x1="16" y1="32" x2="26" y2="32" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/>'
                . '</svg>',
        ];

        return $icons[$slug] ?? $icons['document'];
    }
}

if (!function_exists('manage_doc_folder_icon')) {
    function manage_doc_folder_icon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
            . '<path d="M6 10a2 2 0 0 1 2-2h11.5a2 2 0 0 1 1.7.9l1.6 2.2a2 2 0 0 0 1.7.9H40a2 2 0 0 1 2 2v3H6v-7z" fill="#f7b915"/>'
            . '<path d="M6 15h36v23a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V15z" fill="#ffcf47"/>'
            . '</svg>';
    }
}

/* Helper: map an extension to the slug used by manage_doc_file_icon() */
if (!function_exists('ext_to_icon_slug')) {
    function ext_to_icon_slug(string $ext): string {
        $ext = strtolower($ext);
        if ($ext === 'pdf')                                                return 'pdf';
        if (in_array($ext, ['doc','docx'], true))                          return 'word';
        if (in_array($ext, ['xls','xlsx'], true))                          return 'excel';
        if (in_array($ext, ['ppt','pptx'], true))                          return 'powerpoint';
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)) return 'image';
        if (in_array($ext, ['zip','rar','7z'], true))                      return 'archive';
        return 'document';
    }
}

ob_start();
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   MY DOCUMENT REPOSITORY — UI
   (offline · vanilla CSS · no webfonts)
   ═══════════════════════════════════════════════════════════════ */
:root {
    --primary: #3563e9;
    --primary-dark: #2448c7;
    --primary-soft: #6384ee;
    --primary-light: #eef2fe;
    --primary-glow: rgba(53,99,233,0.20);

    --success: #10b981;
    --success-light: #e7f8f1;
    --warning: #f59e0b;
    --warning-light: #fef4e0;
    --danger: #ef4444;
    --danger-light: #fdecec;

    --gray-50:  #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;

    --white: #ffffff;
    --border: #e6ebf2;
    --border-light: #eef2f7;

    --shadow-xs: 0 1px 2px rgba(15,23,42,.05);
    --shadow-sm: 0 1px 3px rgba(15,23,42,.08), 0 1px 2px rgba(15,23,42,.04);
    --shadow-md: 0 6px 16px -4px rgba(15,23,42,.10), 0 2px 6px -2px rgba(15,23,42,.06);
    --shadow-lg: 0 18px 40px -12px rgba(15,23,42,.18), 0 4px 12px -4px rgba(15,23,42,.08);

    --radius-sm: 10px;
    --radius-md: 14px;
    --radius-lg: 18px;

    --ease: cubic-bezier(.4,0,.2,1);
    --ease-out: cubic-bezier(.16,1,.3,1);
    --transition: all .2s var(--ease);

    --row-h: 52px;

    /* Arial-first — offline, eye-friendly */
    --font: Arial, "Helvetica Neue", Helvetica, Verdana, Tahoma,
            "Segoe UI", Roboto, "Liberation Sans", sans-serif;
}

* { box-sizing: border-box; }

body {
    font-family: var(--font);
    color: var(--gray-800);
}

/* ═══ Explorer container ═══════════════════════════════════ */
.explorer-container {
    flex: 1;
    background: var(--white);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    min-height: 0;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    position: relative;
}
.content-panel {
    padding: 0;
    overflow-y: auto;
    flex: 1;
    background: var(--white);
}

/* ═══ Breadcrumb ═══════════════════════════════════════════ */
.content-panel .breadcrumb {
    font-size: .875rem;
    color: var(--gray-500);
    margin: 0;
    display: flex;
    align-items: center;
    gap: .2rem;
    flex-wrap: wrap;
    background: linear-gradient(180deg, var(--gray-50) 0%, #fbfcfe 100%);
    border-bottom: 1px solid var(--border-light);
    padding: .8rem 1.35rem;
}
.content-panel .breadcrumb svg {
    flex-shrink: 0; color: var(--primary);
    width: 18px; height: 18px; margin-right: .2rem;
}
.content-panel .breadcrumb .bc-chip {
    color: var(--gray-600);
    text-decoration: none;
    font-weight: 600;
    padding: .32rem .6rem;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    transition: var(--transition);
}
.content-panel .breadcrumb .bc-chip:hover {
    color: var(--primary);
    background: var(--white);
    box-shadow: var(--shadow-xs);
    transform: translateY(-1px);
}
.content-panel .breadcrumb .bc-current {
    color: var(--gray-800);
    font-weight: 700;
    padding: .32rem .65rem;
    border-radius: 8px;
    background: var(--white);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-xs), 0 0 0 3px rgba(53,99,233,.06);
}
.content-panel .breadcrumb .sep {
    color: var(--gray-400); font-weight: 400;
    font-size: .9rem; padding: 0 .15rem; user-select: none;
}

/* ═══ Toolbar ══════════════════════════════════════════════ */
.content-panel .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 0;
    flex-wrap: wrap;
    gap: .6rem;
    background: var(--white);
    border-bottom: 1px solid var(--border-light);
    padding: .75rem 1.35rem;
}
.content-panel .toolbar .toolbar-left {
    display: flex; align-items: center; gap: .65rem; flex-wrap: wrap;
}
.content-panel .toolbar .btn-group { display: flex; gap: .55rem; }
.content-panel .toolbar .search-section { display: flex; align-items: center; gap: .55rem; }
.content-panel .toolbar .search-section label {
    font-weight: 600; font-size: .78rem; color: var(--gray-600);
    white-space: nowrap; letter-spacing: .01em;
}
.content-panel .toolbar .search-section input {
    flex: 1;
    padding: .55rem .8rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: .875rem;
    font-family: var(--font);
    color: var(--gray-800);
    background: var(--gray-50);
    transition: var(--transition);
    max-width: 280px;
    box-shadow: inset 0 1px 2px rgba(15,23,42,.03);
}
.content-panel .toolbar .search-section input:focus {
    outline: none;
    border-color: var(--primary);
    background-color: var(--white);
    box-shadow: 0 0 0 4px var(--primary-glow), inset 0 1px 2px rgba(15,23,42,.02);
}
.content-panel .toolbar .search-section input::placeholder { color: var(--gray-400); }
.content-panel .toolbar .count-pill {
    font-size: .75rem; font-weight: 600; color: var(--gray-600);
    background: var(--gray-100); padding: .32rem .75rem;
    border-radius: 9999px; white-space: nowrap;
}

/* ═══ Explorer ribbon ══════════════════════════════════════ */
.explorer-ribbon {
    display: flex;
    align-items: center;
    gap: .15rem;
    background: linear-gradient(180deg, #fff 0%, var(--gray-50) 100%);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: .2rem .25rem;
    box-shadow: var(--shadow-xs);
    transition: var(--transition);
}
.explorer-ribbon:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px var(--primary-glow);
}
.explorer-ribbon .ribbon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .42rem;
    min-width: 36px;
    height: 28px;
    padding: 0 .7rem;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 999px;
    color: var(--gray-600);
    font-size: .78rem;
    font-weight: 600;
    font-family: var(--font);
    cursor: pointer;
    white-space: nowrap;
    transition: var(--transition);
}
.explorer-ribbon .ribbon-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.explorer-ribbon .ribbon-btn:hover:not(:disabled) {
    background: var(--white);
    color: var(--primary);
    border-color: var(--border);
    box-shadow: var(--shadow-xs);
    transform: translateY(-1px);
}
.explorer-ribbon .ribbon-btn:active:not(:disabled) {
    background: var(--primary-light);
    transform: translateY(0);
}
.explorer-ribbon .ribbon-btn:disabled { opacity: .38; cursor: not-allowed; }
.explorer-ribbon .ribbon-sep {
    width: 1px; height: 16px; background: var(--border); margin: 0 .15rem;
}
.clipboard-hint {
    font-size: .74rem;
    color: var(--primary-dark);
    font-weight: 600;
    padding: .22rem .6rem;
    max-width: 240px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    background: var(--primary-light);
    border-radius: 999px;
    margin-left: .35rem;
}

/* ═══ Buttons ══════════════════════════════════════════════ */
.btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: 0 .95rem;
    height: 38px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: .85rem;
    font-family: var(--font);
    cursor: pointer;
    border: 1px solid transparent;
    transition: var(--transition);
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
    letter-spacing: -.005em;
}
.btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.btn-sm { padding: 0 .85rem; font-size: .8rem; height: 34px; }

.btn-primary {
    background: linear-gradient(180deg, var(--primary-soft) 0%, var(--primary) 100%);
    color: var(--white);
    border-color: var(--primary-dark);
    box-shadow:
        0 1px 0 rgba(255,255,255,.2) inset,
        0 1px 2px rgba(53,99,233,.35),
        0 4px 12px -4px rgba(53,99,233,.5);
}
.btn-primary:hover {
    background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
    transform: translateY(-1px);
    box-shadow:
        0 1px 0 rgba(255,255,255,.2) inset,
        0 4px 14px -4px rgba(53,99,233,.55);
}
.btn-primary:active { transform: translateY(0); }

.btn-secondary {
    background: var(--white);
    color: var(--gray-700);
    border-color: var(--border);
    box-shadow: var(--shadow-xs);
}
.btn-secondary:hover {
    color: var(--primary);
    border-color: var(--primary);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px -4px rgba(53,99,233,.35);
}
.btn-secondary:active { transform: translateY(0); }

.btn-danger {
    background: var(--white);
    color: var(--danger);
    border-color: var(--border);
    box-shadow: var(--shadow-xs);
}
.btn-danger:hover {
    background: var(--danger-light);
    border-color: var(--danger);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px -4px rgba(239,68,68,.4);
}

/* ═══ Data table ══════════════════════════════════════════ */
.data-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: .875rem;
    background: var(--white);
}
.data-table thead th {
    text-align: left;
    padding: .8rem 1.35rem;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    color: var(--gray-500);
    text-transform: uppercase;
    font-size: .68rem;
    letter-spacing: .09em;
    background: rgba(248,250,252,.92);
    -webkit-backdrop-filter: saturate(180%) blur(8px);
    backdrop-filter: saturate(180%) blur(8px);
    position: sticky;
    top: 0;
    z-index: 2;
    white-space: nowrap;
    font-family: var(--font);
}
.data-table thead th:last-child { text-align: right; width: 200px; }
.data-table thead th.sel-col,
.data-table tbody td.sel-col {
    width: 46px;
    text-align: center;
    padding: .5rem .7rem;
}
.data-table thead th.sel-col input,
.data-table tbody td.sel-col input {
    width: 16px;
    height: 16px;
    accent-color: var(--primary);
    cursor: pointer;
    vertical-align: middle;
}
.data-table tbody tr {
    transition: background-color .16s ease, box-shadow .16s ease;
    cursor: default;
    height: var(--row-h);
}
.data-table tbody tr.folder-row { cursor: pointer; }
.data-table tbody tr:hover { background: #fafcff; }
.data-table tbody tr.folder-row:hover {
    background: linear-gradient(90deg, var(--primary-light) 0%, #fbfcff 60%);
    box-shadow: inset 3px 0 0 0 var(--primary);
}
.data-table tbody tr:not(:last-child) td { border-bottom: 1px solid var(--border-light); }

.data-table td {
    padding: .7rem 1.35rem;
    vertical-align: middle;
    color: var(--gray-700);
    font-size: .875rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.data-table td:last-child { text-align: right; width: 200px; }
.data-table td.name-cell { white-space: normal; overflow-wrap: break-word; min-width: 260px; }

.data-table .item-name {
    display: flex;
    align-items: center;
    gap: .8rem;
    font-weight: 600;
    color: var(--gray-800);
    font-size: .885rem;
    min-width: 0;
}
.data-table .item-name a { text-decoration: none; color: inherit; transition: color .15s ease; }
.data-table .item-name a:hover { color: var(--primary); }

/* ── Folder icon — real Windows-style SVG, no background tile ── */
.data-table .folder-tag-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    background: transparent;
    border-radius: 0;
    box-shadow: none;
    transition: transform .18s var(--ease-out);
}
.data-table .folder-tag-icon svg {
    width: 32px;
    height: 32px;
    display: block;
    filter: drop-shadow(0 2px 3px rgba(200,130,15,.22));
}
.data-table tr.folder-row:hover .folder-tag-icon {
    transform: translateY(-1px) scale(1.06);
}

/* ── File icon — real file-type SVG, NO background tile ── */
.data-table .file-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    background: transparent;
    transition: transform .18s var(--ease-out);
}
.data-table .file-icon svg {
    width: 32px;
    height: 32px;
    display: block;
}
.data-table tr.file-row:hover .file-icon {
    transform: translateY(-1px) scale(1.06);
}

.data-table .item-sub {
    display: block;
    font-size: .76rem;
    font-weight: 500;
    color: var(--gray-500);
    margin-top: .2rem;
}
.data-table .item-text { min-width: 0; overflow: hidden; text-overflow: ellipsis; }

.data-table .type-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .3rem .7rem;
    border-radius: 9999px;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .02em;
    font-family: var(--font);
    border: 1px solid transparent;
}
.data-table .type-badge.folder {
    background: linear-gradient(135deg, #fff4d6, #ffe9a8);
    color: #94620a;
    border-color: rgba(230,161,0,.22);
}
.data-table .type-badge.file {
    background: var(--primary-light);
    color: var(--primary-dark);
    border-color: rgba(53,99,233,.14);
}

.data-table .actions {
    display: flex;
    gap: .3rem;
    justify-content: flex-end;
    align-items: center;
}
.data-table .actions .btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--white);
    border: 1px solid var(--border);
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    transition: var(--transition);
    color: var(--gray-500);
    text-decoration: none;
    box-shadow: var(--shadow-xs);
}
.data-table .actions .btn-icon svg { width: 15px; height: 15px; }
.data-table .actions .btn-icon:hover {
    background: var(--white);
    color: var(--primary);
    border-color: var(--primary);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px -3px rgba(53,99,233,.35);
}
.data-table .actions .btn-icon.danger:hover {
    background: var(--danger-light);
    color: var(--danger);
    border-color: var(--danger);
    box-shadow: 0 4px 10px -3px rgba(239,68,68,.4);
}
.data-table .actions .btn-icon.forward:hover {
    background: var(--success-light);
    color: #047857;
    border-color: var(--success);
    box-shadow: 0 4px 10px -3px rgba(16,185,129,.4);
}
.data-table .actions .btn-icon.zip:hover {
    background: #eef2ff;
    color: #4f46e5;
    border-color: #a5b4fc;
    box-shadow: 0 4px 10px -3px rgba(79,70,229,.4);
}
.data-table .actions .btn-icon:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }

/* ═══ Row selection ═══════════════════════════════════════ */
.data-table tr.row-selected {
    background: linear-gradient(90deg, var(--primary-light) 0%, #f7faff 70%) !important;
    box-shadow: inset 3px 0 0 0 var(--primary);
}
.data-table tr.row-selected td { border-bottom-color: #dbe7fe; }
.data-table tr.is-cut { opacity: .55; }
.data-table tr.is-cut td { position: relative; }
.data-table tr.is-cut .item-name::after {
    content: 'staged';
    display: inline-block;
    margin-left: .55rem;
    font-size: .62rem;
    font-weight: 800;
    color: var(--primary-dark);
    background: var(--primary-light);
    padding: .14rem .48rem;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: .06em;
    border: 1px solid rgba(53,99,233,.16);
}

/* ═══ Empty state ═════════════════════════════════════════ */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 5rem 1.5rem;
    color: var(--gray-500);
    background: var(--white);
}
.empty-state .empty-icon {
    width: 96px;
    height: 96px;
    border-radius: 28px;
    background:
        radial-gradient(circle at 30% 25%, rgba(255,255,255,.9), transparent 60%),
        linear-gradient(135deg, #eef2fe 0%, #dbe4fc 100%);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.35rem;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.9),
        0 1px 3px rgba(15,23,42,.06),
        0 0 0 1px rgba(53,99,233,.10);
}
.empty-state .empty-icon svg { width: 42px; height: 42px; opacity: 1; }
.empty-state .empty-state-icon {
    color: var(--primary); margin: 0 auto .9rem; display: block; opacity: .95;
}
.empty-state h3 {
    margin: 0 0 .35rem;
    font-size: 1.08rem;
    color: var(--gray-800);
    font-weight: 700;
    font-family: var(--font);
    letter-spacing: -.015em;
}
.empty-state p { margin: 0 0 .3rem; font-weight: 600; font-size: .9rem; color: var(--gray-600); }
.empty-state small {
    display: block; font-size: .8rem; color: var(--gray-500);
    max-width: 420px; line-height: 1.65;
}

/* ═══ Modals ══════════════════════════════════════════════ */
.modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 2000;
    background: rgba(15,23,42,.55);
    -webkit-backdrop-filter: blur(6px) saturate(150%);
    backdrop-filter: blur(6px) saturate(150%);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}
.modal-overlay.active {
    display: flex;
    animation: modalFade .22s ease;
}
@keyframes modalFade { from { opacity: 0; } to { opacity: 1; } }

.modal-overlay .modal-box {
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: 0 28px 60px -18px rgba(15,23,42,.35);
    width: 100%;
    max-width: 540px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    animation: modalSlideUp .32s var(--ease-out);
    border: 1px solid var(--border);
    overflow: hidden;
}
.modal-overlay .modal-box.modal-box-sm { max-width: 460px; }
@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(20px) scale(.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.modal-box .modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.1rem 1.35rem;
    border-bottom: 1px solid var(--border-light);
    flex-shrink: 0;
    background: linear-gradient(180deg, #ffffff 0%, var(--gray-50) 100%);
}
.modal-box .modal-header h2 {
    font-size: 1.02rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: .6rem;
    letter-spacing: -.015em;
    font-family: var(--font);
}
.modal-box .modal-header h2.danger-title { color: var(--danger); }

.modal-box .modal-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--white);
    border: 1px solid var(--border);
    cursor: pointer;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    color: var(--gray-500);
    transition: var(--transition);
    box-shadow: var(--shadow-xs);
}
.modal-box .modal-close:hover {
    background: var(--danger-light);
    color: var(--danger);
    border-color: var(--danger);
    transform: rotate(90deg);
}
.modal-box .modal-close:focus-visible, .btn-icon:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

.modal-box .modal-body {
    padding: 1.35rem;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    background: var(--white);
}
.modal-box .modal-body p.modal-msg { font-size: .9rem; color: var(--gray-700); margin: 0 0 .4rem; line-height: 1.55; }
.modal-box .modal-body small.modal-note { display: block; font-size: .79rem; color: var(--gray-500); line-height: 1.6; }
.modal-box > form { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
.modal-box .modal-body .form-group { margin-bottom: 1.1rem; }
.modal-box .modal-body .form-group:last-child { margin-bottom: 0; }
.modal-box .modal-body .form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: .45rem;
    font-size: .8rem;
    color: var(--gray-700);
    font-family: var(--font);
}
.modal-box .modal-body .form-group .required::after { content: " *"; color: var(--danger); }
.modal-box .modal-body .form-control {
    width: 100%;
    padding: .65rem .9rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: .875rem;
    font-family: var(--font);
    background: var(--gray-50);
    color: var(--gray-800);
    transition: var(--transition);
    height: auto;
    min-height: 42px;
    box-sizing: border-box;
    box-shadow: inset 0 1px 2px rgba(15,23,42,.03);
}
.modal-box .modal-body .form-control:hover { border-color: var(--gray-300); background: var(--white); }
.modal-box .modal-body .form-control:focus {
    border-color: var(--primary);
    background-color: var(--white);
    box-shadow: 0 0 0 4px var(--primary-glow);
    outline: none;
}
.modal-box .modal-body .form-control::placeholder { color: var(--gray-400); }
.modal-box .modal-body .check-label {
    display: flex; align-items: center; gap: .55rem;
    font-size: .85rem; font-weight: 500; color: var(--gray-700); cursor: pointer;
}
.modal-box .modal-body .check-label input {
    width: 1rem; height: 1rem; accent-color: var(--primary); margin: 0;
}

.modal-box .modal-footer {
    padding: 1rem 1.35rem;
    border-top: 1px solid var(--border-light);
    display: flex;
    gap: .6rem;
    justify-content: flex-end;
    flex-shrink: 0;
    background: linear-gradient(180deg, var(--gray-50) 0%, #fbfcfe 100%);
}
.modal-box .modal-footer .btn { min-width: 105px; justify-content: center; }

/* ═══ Scrollbars ══════════════════════════════════════════ */
.modal-box .modal-body::-webkit-scrollbar { width: 8px; }
.modal-box .modal-body::-webkit-scrollbar-track { background: transparent; }
.modal-box .modal-body::-webkit-scrollbar-thumb {
    background: var(--gray-300); border-radius: 999px;
}
.modal-box .modal-body::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }
.content-panel::-webkit-scrollbar { width: 10px; height: 10px; }
.content-panel::-webkit-scrollbar-track { background: transparent; }
.content-panel::-webkit-scrollbar-thumb {
    background: var(--gray-300);
    border-radius: 999px;
    border: 2px solid var(--white);
}
.content-panel::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }

/* ═══ Alerts ══════════════════════════════════════════════ */
.alert {
    padding: .95rem 1.15rem;
    border-radius: var(--radius-md);
    font-size: .875rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: .75rem;
    animation: slideDown .32s var(--ease-out);
    margin-bottom: 1rem;
    border: 1px solid;
    border-left-width: 4px;
    font-family: var(--font);
    box-shadow: var(--shadow-xs);
}
.alert svg { flex-shrink: 0; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.alert-danger {
    background: var(--danger-light); color: #b91c1c;
    border-color: #fecaca; border-left-color: var(--danger);
}
.alert-success {
    background: var(--success-light); color: #047857;
    border-color: #a7f3d0; border-left-color: var(--success);
}

/* ═══ Location display (in modals) ════════════════════════ */
.location-display {
    background: linear-gradient(180deg, var(--gray-50) 0%, #fbfcfe 100%);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: .7rem .95rem;
    font-size: .8rem;
    color: var(--gray-500);
    margin-bottom: 1.15rem;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: inset 0 1px 2px rgba(15,23,42,.03);
}
.location-display strong { color: var(--gray-700); font-weight: 700; }
.location-display .folder-path { color: var(--gray-700); font-weight: 600; }

/* ═══ Drag & Drop ═════════════════════════════════════════ */
.data-table tr.file-row[draggable="true"] { cursor: grab; }
.data-table tr.file-row[draggable="true"]:active { cursor: grabbing; }
.data-table tr.file-row.dragging {
    opacity: .4;
    background: var(--primary-light) !important;
}
.data-table tr.file-row.dragging td { border-bottom-color: #dbe7fe; }

.data-table tbody tr.folder-row.drag-over {
    background: linear-gradient(90deg, #e0e9ff 0%, #eef3ff 70%) !important;
    outline: 2px solid var(--primary);
    outline-offset: -2px;
    position: relative;
    z-index: 2;
    box-shadow: inset 0 0 0 4px rgba(53,99,233,.10);
}
.data-table tbody tr.folder-row.drag-over td {
    background: transparent !important;
    border-bottom-color: #dbe7fe !important;
}
.data-table tbody tr.folder-row.drag-over .folder-tag-icon {
    transform: scale(1.12);
    filter: drop-shadow(0 4px 8px rgba(53,99,233,.35));
}
.data-table tbody tr.folder-row.drag-over .item-name a {
    color: var(--primary-dark); font-weight: 800;
}

.breadcrumb #dropRoot.drop-target,
.breadcrumb #dropRoot.drag-over {
    background: var(--primary-light);
    color: var(--primary-dark);
    outline: 2px dashed var(--primary);
    outline-offset: -2px;
    border-radius: 8px;
    font-weight: 800;
    box-shadow: 0 0 0 4px rgba(53,99,233,.12);
}

.drag-ghost {
    position: fixed;
    pointer-events: none;
    z-index: 9999;
    background: var(--white);
    border: 1px solid var(--primary);
    border-radius: var(--radius-sm);
    padding: .45rem .85rem;
    font-size: .82rem;
    font-weight: 600;
    color: var(--primary-dark);
    box-shadow: var(--shadow-lg);
    opacity: .98;
    white-space: nowrap;
    top: -1000px;
    left: -1000px;
}
.drag-move-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: linear-gradient(180deg, var(--gray-800) 0%, var(--gray-900) 100%);
    color: #fff;
    padding: .7rem 1.05rem;
    border-radius: var(--radius-md);
    font-size: .875rem;
    font-weight: 600;
    font-family: var(--font);
    box-shadow: 0 18px 40px -12px rgba(15,23,42,.45);
    z-index: 9999;
    animation: toastIn .25s var(--ease-out);
}
@keyframes toastIn {
    from { opacity: 0; transform: translateY(10px) scale(.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* ═══ Responsive ══════════════════════════════════════════ */
@media (max-width: 1100px) {
    .content-panel .toolbar .search-section input { max-width: 190px; }
}
@media (max-width: 900px) {
    .explorer-container { max-width: none; border-radius: var(--radius-md); }
}
@media (max-width: 768px) {
    .content-panel .toolbar .search-section input { max-width: 100%; }
    .content-panel .toolbar .search-section { flex: 1; }
    .data-table thead th:last-child,
    .data-table td:last-child { width: 160px; }
}
@media (max-width: 640px) {
    .explorer-ribbon .ribbon-btn span { display: none; }
    .explorer-ribbon .ribbon-btn { padding: 0 .55rem; }
}
@media (max-width: 480px) {
    .content-panel .breadcrumb { font-size: .78rem; padding: .65rem .85rem; }
    .content-panel .toolbar { padding: .6rem .85rem; }
    .content-panel .toolbar .btn-group { flex-wrap: wrap; width: 100%; }
    .content-panel .toolbar .btn-group .btn { flex: 1; justify-content: center; min-width: 110px; }
    .data-table td, .data-table th { padding: .55rem .8rem; font-size: .8rem; }
    .data-table thead th:last-child,
    .data-table td:last-child { width: auto; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }
}
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger" role="alert"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php include 'includes/success_toast.php'; ?>

<!-- ===== FILE EXPLORER VIEW ===== -->
<div class="dashboard-container">
    <div class="explorer-container">
        <div class="content-panel">
            <!-- Breadcrumb -->
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3.6 6A2 2 0 0 1 5.6 4h4l1.8 2.4H19a2 2 0 0 1 2 2v9.3a2 2 0 0 1-2 2H5.6a2 2 0 0 1-2-2V6z" fill="#F6C344"/><path d="M3.6 9.3h17.8v7.4a2 2 0 0 1-2 2H5.6a2 2 0 0 1-2-2V9.3z" fill="#FFDC7F"/></svg>
                <a href="?folder=0" class="bc-chip" id="dropRoot">My Repository</a>
                <?php if ($currentFolderId): ?>
                    <?php foreach ($breadcrumbsWithIds as $seg): ?>
                        <span class="sep" aria-hidden="true">›</span>
                        <?php if ($seg['id'] == $currentFolderId): ?>
                            <span class="bc-current"><?= htmlspecialchars($seg['folder_name']) ?></span>
                        <?php else: ?>
                            <a href="?folder=<?= $seg['id'] ?>" class="bc-chip"><?= htmlspecialchars($seg['folder_name']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <div class="search-section">
                        <label for="explorerSearch">Search</label>
                        <input type="text" id="explorerSearch" placeholder="Search folders & files...">
                    </div>
                    <div class="explorer-ribbon" id="explorerRibbon" role="toolbar" aria-label="Explorer commands">
                        <button type="button" class="ribbon-btn" id="btnCopy" disabled title="Copy — stage selected file(s) for moving">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="5" width="7" height="7" rx="1"/><path d="M4 9V4a1 1 0 0 1 1-1h5"/></svg>
                            <span>Copy</span>
                        </button>
                        <span class="ribbon-sep" aria-hidden="true"></span>
                        <button type="button" class="ribbon-btn" id="btnMove" disabled title="Move — place the copied file(s) in this folder">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l3 5-3 5M15 8H7"/><path d="M7 4H4.5A2.5 2.5 0 0 0 2 6.5v3A2.5 2.5 0 0 0 4.5 12H7"/></svg>
                            <span>Move</span>
                        </button>
                        <span id="clipboardHint" class="clipboard-hint" style="display:none;"></span>
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-sm btn-secondary" data-csp-action="openFolderModal">
                        <?= render_dashboard_icon('plus') ?>
                        Create Folder
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" data-csp-action="openUploadModal" title="Upload a document">
                        <?= render_dashboard_icon('upload') ?>
                        Upload File
                    </button>
                </div>
            </div>

            <!-- Content Table -->
            <?php if (empty($contents)): ?>
                <div class="empty-state" id="explorerEmptyState">
                    <div class="empty-state-icon"><?= render_dashboard_icon('folder') ?></div>
                    <p>This folder is empty.</p>
                    <small>Current location: <?= $locationBreadcrumb ?></small>
                </div>
            <?php else: ?>
                <table class="data-table" id="explorerTable">
                    <thead>
                        <tr>
                            <th class="sel-col"><input type="checkbox" id="selAll" title="Select all"></th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Date Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="explorerTbody">
                        <?php foreach ($contents as $item): ?>
                            <?php if ($item['is_folder']): ?>
                                <tr class="folder-row" data-row-id="repo_folder:<?= (int)$item['id'] ?>" data-href="?folder=<?= $item['id'] ?>" data-folder-id="<?= (int)$item['id'] ?>" data-name="<?= strtolower(htmlspecialchars($item['name'])) ?>" data-type="folder">
                                    <td class="sel-col"><input type="checkbox" class="row-check" data-kind="folder" data-id="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>" title="Select <?= htmlspecialchars($item['name']) ?>" data-csp-stop></td>
                                    <td class="name-cell">
                                        <div class="item-name">
                                            <span class="folder-tag-icon"><?= manage_doc_folder_icon() ?></span>
                                            <a href="?folder=<?= $item['id'] ?>"><?= htmlspecialchars($item['name']) ?></a>
                                        </div>
                                    </td>
                                    <td><span class="type-badge folder">Folder</span></td>
                                    <td><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?></td>
                                    <td class="actions">
                                        <a href="?action=download_folder_zip&amp;folder_id=<?= (int)$item['id'] ?>" class="btn-icon zip" title="Download as ZIP" aria-label="Download <?= htmlspecialchars($item['name'], ENT_QUOTES) ?> as ZIP" style="text-decoration:none;">
                                            <?= render_dashboard_icon('zip') ?>
                                        </a>
                                        <button type="button" class="btn-icon js-edit-folder" title="Rename Folder" data-id="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button type="button" class="btn-icon danger js-delete-folder-btn" data-folder-id="<?= $item['id'] ?>" data-folder-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                    $title = $item['title'] ?? 'Untitled';
                                    $forwarded = !empty($item['forwarded_to_admin']);
                                    $created = $item['created_at'] ?? $item['uploaded_at'] ?? 'now';
                                    $filePath = $item['file_path'] ?? '';
                                    $fileExists = !empty($filePath) && (bool)securePath($filePath);

                                    // Determine icon slug from extension (fallback to title)
                                    $fileExtForIcon = !empty($filePath)
                                        ? strtolower(pathinfo($filePath, PATHINFO_EXTENSION))
                                        : strtolower(pathinfo((string)$title, PATHINFO_EXTENSION));
                                    $fileIconSlug = ext_to_icon_slug($fileExtForIcon);
                                ?>
                                <tr class="file-row" draggable="true" data-row-id="repo_file:<?= (int)$item['id'] ?>" data-repo-id="<?= (int)$item['id'] ?>" data-current-folder="<?= $currentFolderId === null ? '' : (int)$currentFolderId ?>" data-name="<?= strtolower(htmlspecialchars($title)) ?>" data-type="file">
                                    <td class="sel-col"><input type="checkbox" class="row-check" data-kind="file" data-id="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($title, ENT_QUOTES) ?>" title="Select <?= htmlspecialchars($title) ?>"></td>
                                    <td class="name-cell">
                                        <div class="item-name">
                                            <span class="file-icon"><?= manage_doc_file_icon($fileIconSlug) ?></span>
                                            <div>
                                                <?= htmlspecialchars($title) ?>
                                                <?php if (!$fileExists): ?>
                                                    <span class="item-sub" style="color: #b91c1c;">(file missing)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="type-badge file">File</span></td>
                                    <td><?= date('M j, Y g:i A', strtotime($created)) ?></td>
                                    <td class="actions">
                                        <?php
                                            $viewTitle = htmlspecialchars($title, ENT_QUOTES);
                                            $viewExt = '';
                                            $viewSource = 'ph_repository';
                                            $viewId = (int)$item['id'];
                                            if (!empty($filePath)) {
                                                $viewExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                            }
                                            $originalPath = $item['original_repo_file_path'] ?? null;
                                            if (!empty($originalPath)) {
                                                $viewSource = 'ph_repository';
                                                $viewId = (int)$item['id'];
                                            } elseif (!empty($item['ph_document_id'])) {
                                                $viewSource = 'ph_document';
                                                $viewId = (int)$item['ph_document_id'];
                                            } elseif (!empty($item['document_id'])) {
                                                $viewSource = 'document';
                                                $viewId = (int)$item['document_id'];
                                            }
                                        ?>
                                        <button type="button" class="btn-icon view-btn" title="View" aria-label="View <?= $viewTitle ?>"
                                            data-id="<?= $viewId ?>"
                                            data-type="<?= $viewSource ?>"
                                            data-title="<?= $viewTitle ?>"
                                            data-extension="<?= htmlspecialchars($viewExt) ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <a href="?action=download&repo_id=<?= $item['id'] ?>" class="btn-icon" title="Download" aria-label="Download <?= htmlspecialchars($title) ?>" style="text-decoration:none;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        </a>
                                        <button type="button" class="btn-icon danger js-ajax-delete-btn" data-repo-id="<?= $item['id'] ?>" data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                        <button type="button" class="btn-icon forward js-forward-btn" data-repo-id="<?= $item['id'] ?>" data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>" data-forwarded="<?= $forwarded ? '1' : '0' ?>">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========== MODALS ========== -->
<div class="modal-overlay" id="folderModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Create Folder</h2>
            <button class="modal-close" data-csp-action="closeFolderModal" type="button">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_folder">
            <input type="hidden" name="parent_id" id="folderParentId" value="<?= $currentFolderId !== null ? $currentFolderId : '' ?>">
            <div class="modal-body">
                <div class="location-display">
                    <strong>Location:</strong> <span class="folder-path"><?= $locationBreadcrumb ?></span>
                </div>
                <div class="form-group">
                    <label for="folderName" class="required">Folder Name</label>
                    <input type="text" name="folder_name" id="folderName" class="form-control" required placeholder="Enter folder name" maxlength="255" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-csp-action="closeFolderModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Folder</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editFolderModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Rename Folder</h2>
            <button class="modal-close" data-csp-action="closeEditFolderModal" type="button">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_folder">
            <input type="hidden" name="folder_id" id="editFolderId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="editFolderName" class="required">Folder Name</label>
                    <input type="text" name="folder_name" id="editFolderName" class="form-control" required placeholder="Enter folder name" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-csp-action="closeEditFolderModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="uploadModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Upload File</h2>
            <button class="modal-close" data-csp-action="closeUploadModal" type="button" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="repo_upload">
            <input type="hidden" name="folder_id" id="repoUploadFolderId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="uploadTitle" class="required">Document Title</label>
                    <input type="text" name="upload_title" id="uploadTitle" class="form-control" required placeholder="Enter document name..." maxlength="255" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="uploadFile" class="required">Select File</label>
                    <input type="file" name="upload_file" id="uploadFile" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.svg">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-csp-action="closeUploadModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteConfirmModal">
    <div class="modal-box modal-box-sm">
        <div class="modal-header">
            <h2 id="deleteConfirmTitle">Confirm Deletion</h2>
            <button class="modal-close" data-csp-action="closeDeleteConfirmModal" type="button" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <p class="modal-msg" id="deleteConfirmMsg"></p>
            <small class="modal-note" id="deleteConfirmNote" style="display:none;"></small>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-csp-action="closeDeleteConfirmModal">Cancel</button>
            <button type="button" class="btn btn-danger" id="deleteConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- Forward Again Confirmation Modal -->
<div class="modal-overlay" id="forwardAgainModal">
    <div class="modal-box modal-box-sm">
        <div class="modal-header">
            <h2>Already Forwarded to Admin</h2>
            <button class="modal-close" data-csp-action="closeForwardAgainModal" type="button" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <p class="modal-msg" id="forwardAgainMsg"></p>
            <small class="modal-note">This document has already been forwarded to Admin. Do you want to forward it again?</small>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-csp-action="closeForwardAgainModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="forwardAgainBtn">Forward Again</button>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    /* ── CSRF ───────────────────────────────────────────── */
    const AJAX_CSRF = <?= json_encode(getCsrfToken()) ?>;
    function getCsrfToken() {
        if (AJAX_CSRF) return AJAX_CSRF;
        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    /* ── Toast helper ───────────────────────────────────── */
    const toastHost = document.createElement('div');
    function showToast(msg, isErr) {
        const t = document.createElement('div');
        t.className = 'drag-move-toast';
        if (isErr) t.style.background = '#b91c1c';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2500);
    }
    window.__explorerShowToast = showToast;

    /* ── Confirm modal ──────────────────────────────────── */
    function openDeleteConfirmModal(msg, confirmLabel, mode, callback) {
        const modal = document.getElementById('deleteConfirmModal');
        const title = document.getElementById('deleteConfirmTitle');
        const msgEl = document.getElementById('deleteConfirmMsg');
        const note  = document.getElementById('deleteConfirmNote');
        const btn   = document.getElementById('deleteConfirmBtn');

        msgEl.textContent = msg || 'Are you sure you want to delete this?';
        note.style.display = 'none';

        if (mode === 'forward') {
            title.textContent = 'Forward to Admin';
            title.classList.remove('danger-title');
            btn.textContent = confirmLabel || 'Forward';
            btn.className = 'btn btn-primary';
        } else {
            title.textContent = 'Confirm Deletion';
            title.classList.add('danger-title');
            btn.textContent = confirmLabel || 'Delete';
            btn.className = 'btn btn-danger';
        }

        window._deleteConfirmCallback = callback;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    window.openDeleteConfirmModal = openDeleteConfirmModal;

    function closeDeleteConfirmModal() {
        document.getElementById('deleteConfirmModal').classList.remove('active');
        document.body.style.overflow = '';
        window._deleteConfirmCallback = null;
    }
    window.closeDeleteConfirmModal = closeDeleteConfirmModal;

    function openForwardAgainModal(title, callback) {
        const modal = document.getElementById('forwardAgainModal');
        const msgEl = document.getElementById('forwardAgainMsg');
        msgEl.textContent = 'Do you want to forward "' + title + '" to Admin again?';
        window._forwardAgainCallback = callback;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeForwardAgainModal() {
        document.getElementById('forwardAgainModal').classList.remove('active');
        document.body.style.overflow = '';
        window._forwardAgainCallback = null;
    }
    window.closeForwardAgainModal = closeForwardAgainModal;

    document.getElementById('forwardAgainBtn').addEventListener('click', function() {
        if (typeof window._forwardAgainCallback === 'function') {
            window._forwardAgainCallback();
            closeForwardAgainModal();
        }
    });
    document.getElementById('deleteConfirmBtn').addEventListener('click', function() {
        if (typeof window._deleteConfirmCallback === 'function') {
            window._deleteConfirmCallback();
            closeDeleteConfirmModal();
        }
    });

    /* ── Folder delete (AJAX) ───────────────────────────── */
    document.querySelectorAll('.js-delete-folder-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const folderId = this.dataset.folderId;
            const folderName = this.dataset.folderName || 'this folder';
            const confirmMsg = 'Delete folder "' + folderName + '" and all its contents? This will permanently delete all subfolders and files inside.';

            openDeleteConfirmModal(confirmMsg, 'Delete', 'danger', function() {
                const formData = new FormData();
                formData.append('action', 'delete_folder');
                formData.append('folder_id', folderId);
                formData.append('ajax', '1');
                formData.append('csrf_token', getCsrfToken());

                fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) window.location.reload();
                    else alert('Error: ' + (data.message || 'Failed to delete folder.'));
                })
                .catch(err => alert('An error occurred: ' + err.message));
            });
        });
    });

    /* ── Document delete (AJAX) ─────────────────────────── */
    document.querySelectorAll('.js-ajax-delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const repoId = this.dataset.repoId;
            const title  = this.dataset.title || 'this document';
            const confirmMsg = 'Delete "' + title + '"? This will permanently delete the file from the server.';

            openDeleteConfirmModal(confirmMsg, 'Delete', 'danger', function() {
                const formData = new FormData();
                formData.append('action', 'delete_repo');
                formData.append('repo_id', repoId);
                formData.append('ajax', '1');
                formData.append('csrf_token', getCsrfToken());

                fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) window.location.reload();
                    else alert('Error: ' + (data.message || 'Failed to delete document.'));
                })
                .catch(err => alert('An error occurred: ' + err.message));
            });
        });
    });

    /* ── Forward to Admin (AJAX) ────────────────────────── */
    document.querySelectorAll('.js-forward-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const repoId = this.dataset.repoId;
            const title  = this.dataset.title || 'this document';
            const isForwarded = this.dataset.forwarded === '1';

            function doForward() {
                const formData = new FormData();
                formData.append('action', 'forward_to_admin');
                formData.append('repo_id', repoId);
                formData.append('csrf_token', getCsrfToken());

                fetch(window.location.href, { method: 'POST', body: formData })
                .then(() => window.location.reload())
                .catch(err => alert('An error occurred: ' + err.message));
            }

            if (isForwarded) {
                openForwardAgainModal(title, doForward);
            } else {
                openDeleteConfirmModal('Forward "' + title + '" to Admin?', 'Forward', 'forward', doForward);
            }
        });
    });

    /* ── Edit folder modal ──────────────────────────────── */
    document.querySelectorAll('.js-edit-folder').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const id = parseInt(this.dataset.id, 10);
            const name = this.dataset.name || '';
            document.getElementById('editFolderId').value = id;
            document.getElementById('editFolderName').value = name;
            document.getElementById('editFolderModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    });

    /* ── Folder row click (navigate) ────────────────────── */
    document.querySelectorAll('.folder-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (window._justDropped) return;
            const target = e.target;
            if (target.closest('.actions') || target.closest('button') || target.closest('form') || target.closest('a') || target.closest('input')) {
                e.stopPropagation();
                return;
            }
            const href = this.dataset.href;
            if (href) window.location.href = href;
        });
    });

    /* ══════════════════════════════════════════════════════
       DRAG & DROP — move a file into a folder
       ══════════════════════════════════════════════════════ */
    let draggedRepoId = null;
    let draggedRow    = null;
    let ghostEl = null;
    function getGhost() {
        if (!ghostEl) {
            ghostEl = document.createElement('div');
            ghostEl.className = 'drag-ghost';
            document.body.appendChild(ghostEl);
        }
        return ghostEl;
    }
    function clearDnDState() {
        document.querySelectorAll(
            '#explorerTbody tr.dragging, #explorerTbody tr.drop-target, #explorerTbody tr.drag-over, ' +
            '#dropRoot.drop-target, #dropRoot.drag-over'
        ).forEach(el => el.classList.remove('dragging', 'drop-target', 'drag-over'));
    }

    document.addEventListener('dragstart', function (e) {
        const row = e.target.closest('tr.file-row[data-repo-id]');
        if (!row) return;
        if (e.target.closest('a, button, .actions, input, textarea, select')) {
            e.preventDefault();
            return;
        }
        draggedRepoId = row.dataset.repoId;
        draggedRow    = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', draggedRepoId);
        const labelEl = row.querySelector('.item-name');
        const ghost   = getGhost();
        ghost.textContent = labelEl ? labelEl.textContent.trim().substring(0, 60) : 'File';
        try { e.dataTransfer.setDragImage(ghost, 12, 12); } catch (_) {}
    });

    document.addEventListener('dragend', function (e) {
        const row = e.target.closest('tr.file-row[data-repo-id]');
        if (row) row.classList.remove('dragging');
        draggedRepoId = null;
        draggedRow    = null;
        clearDnDState();
    });

    document.addEventListener('dragover', function (e) {
        if (!draggedRepoId) return;
        const folderRow = e.target.closest('#explorerTbody tr.folder-row[data-folder-id]');
        if (folderRow) {
            const targetFolder = folderRow.dataset.folderId || '';
            const currentRow = draggedRow;
            const currentFolder = currentRow ? (currentRow.dataset.currentFolder || '') : '';
            if (currentFolder === String(targetFolder)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            folderRow.classList.add('drag-over');
            return;
        }
        const rootLink = e.target.closest('#dropRoot');
        if (rootLink) {
            const currentRow = draggedRow;
            const currentFolder = currentRow ? (currentRow.dataset.currentFolder || '') : '';
            if (currentFolder === '') return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            rootLink.classList.add('drag-over');
        }
    });

    document.addEventListener('dragleave', function (e) {
        const folderRow = e.target.closest('#explorerTbody tr.folder-row');
        if (folderRow && !folderRow.contains(e.relatedTarget)) folderRow.classList.remove('drag-over');
        const rootLink = e.target.closest('#dropRoot');
        if (rootLink && !rootLink.contains(e.relatedTarget)) rootLink.classList.remove('drag-over');
    });

    document.addEventListener('drop', function (e) {
        if (!draggedRepoId) return;
        const folderRow = e.target.closest('#explorerTbody tr.folder-row[data-folder-id]');
        const rootLink  = e.target.closest('#dropRoot');
        if (!folderRow && !rootLink) return;
        e.preventDefault();
        e.stopPropagation();

        let targetFolderId = null;
        if (folderRow) {
            targetFolderId = folderRow.dataset.folderId;
            folderRow.classList.remove('drag-over');
        } else if (rootLink) {
            targetFolderId = '';
            rootLink.classList.remove('drag-over');
        }
        doMove(targetFolderId);
    });

    function doMove(targetFolderId) {
        const row    = draggedRow;
        const repoId = draggedRepoId;
        if (!repoId || !row) return;
        row.style.pointerEvents = 'none';

        const formData = new FormData();
        formData.append('action',    'move_repo_file');
        formData.append('repo_id',   repoId);
        formData.append('folder_id', targetFolderId === null ? '' : String(targetFolderId));
        formData.append('csrf_token', getCsrfToken());

        fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(async (r) => {
                const text = await r.text();
                try { return JSON.parse(text); }
                catch (_) {
                    console.error('Non-JSON response from server:', text);
                    return { success: false, message: 'Unexpected server response.' };
                }
            })
            .then(data => {
                clearDnDState();
                if (data.success && !data.noop) {
                    window._justDropped = true;
                    setTimeout(() => { window._justDropped = false; }, 300);
                    row.remove();
                    showToast(data.message || 'File moved.');

                    const tbody = document.getElementById('explorerTbody');
                    if (tbody && tbody.children.length === 0) {
                        const panel = document.querySelector('.content-panel');
                        const table = document.getElementById('explorerTable');
                        if (panel && table) {
                            table.remove();
                            const empty = document.createElement('div');
                            empty.className = 'empty-state';
                            empty.innerHTML =
                                '<div class="empty-state-icon">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>' +
                                '</div><p>This folder is empty. Approved faculty submissions will appear here.</p>';
                            panel.appendChild(empty);
                        }
                    }
                } else if (data.noop) {
                    showToast('File is already in this location.');
                    row.style.pointerEvents = '';
                } else {
                    showToast(data.message || 'Failed to move file.', true);
                    row.style.pointerEvents = '';
                }
            })
            .catch(err => {
                clearDnDState();
                console.error(err);
                showToast('Network error while moving file.', true);
                row.style.pointerEvents = '';
            });
    }

    /* ══════════════════════════════════════════════════════
       EXPLORER COPY → MOVE (bulk)
       ══════════════════════════════════════════════════════ */
    (function(){
        const deptId = <?= json_encode((int)($deptId ?? 0)) ?>;
        const curFolderId = <?= json_encode($currentFolderId ? (int)$currentFolderId : null) ?>;
        const MOVE_KEY = 'ched_ph_move_items';
        const btnCopy = document.getElementById('btnCopy');
        const btnMove = document.getElementById('btnMove');
        const selAll = document.getElementById('selAll');
        const hint = document.getElementById('clipboardHint');
        if (!btnCopy || !btnMove) return;

        function getMovePayload(){
            try { const v = sessionStorage.getItem(MOVE_KEY); return v ? JSON.parse(v) : null; }
            catch(e){ return null; }
        }
        function setMovePayload(data){ sessionStorage.setItem(MOVE_KEY, JSON.stringify(data)); }
        function clearMovePayload(){ sessionStorage.removeItem(MOVE_KEY); }

        function getSelectedRows(){ return Array.from(document.querySelectorAll('.row-check:checked')); }
        function getSelectedFileIds(){
            return getSelectedRows()
                .filter(c => c.dataset.kind === 'file')
                .map(c => parseInt(c.dataset.id, 10))
                .filter(Boolean);
        }

        function updateToolbar(){
            const fileIds = getSelectedFileIds();
            const hasFileSel = fileIds.length > 0;
            const hasAnySel  = getSelectedRows().length > 0;
            const mv = getMovePayload();
            const hasStaged = !!(mv && mv.ids && mv.ids.length);

            btnCopy.disabled = !hasFileSel;
            btnMove.disabled = !hasStaged;

            if (hasStaged) {
                hint.textContent = 'Copied ' + mv.ids.length + ' file(s) — go to destination and click Move';
                hint.style.display = 'inline';
            } else {
                hint.style.display = 'none';
            }

            if (selAll) {
                const allChecks = document.querySelectorAll('.row-check');
                selAll.checked = allChecks.length > 0 && Array.from(allChecks).every(c => c.checked);
                selAll.indeterminate = !selAll.checked && hasAnySel;
            }
        }

        function syncRowHighlight(){
            document.querySelectorAll('tr[data-repo-id], tr.folder-row').forEach(tr => {
                const cbx = tr.querySelector('.row-check');
                tr.classList.toggle('row-selected', !!(cbx && cbx.checked));
            });
            const mv = getMovePayload();
            const dimIds = mv && mv.ids ? mv.ids : [];
            document.querySelectorAll('tr[data-repo-id]').forEach(tr => {
                const id = parseInt(tr.dataset.repoId, 10);
                tr.classList.toggle('is-cut', dimIds.includes(id));
            });
        }

        function onSelectionChange(){ syncRowHighlight(); updateToolbar(); }

        document.querySelectorAll('.row-check').forEach(cbx => {
            cbx.addEventListener('change', onSelectionChange);
            cbx.addEventListener('click', function(e){ e.stopPropagation(); });
        });
        if (selAll) selAll.addEventListener('change', function(){
            document.querySelectorAll('.row-check').forEach(c => { c.checked = selAll.checked; });
            onSelectionChange();
        });

        function stageForMove(){
            const checks = getSelectedRows();
            const folderSel = checks.filter(c => c.dataset.kind === 'folder');
            if (folderSel.length) {
                showToast('Folders cannot be moved — files only.', true);
            }
            const ids = getSelectedFileIds();
            if (!ids.length){
                showToast('Select file(s) first.', true);
                return;
            }
            const names = checks.filter(c => c.dataset.kind === 'file').map(c => c.dataset.name);
            setMovePayload({ deptId: deptId, ids: ids, names: names, at: Date.now() });
            updateToolbar(); syncRowHighlight();
            showToast('Copied ' + ids.length + ' file(s) for moving — navigate to the destination folder and click Move.');
        }

        function clearStaged(){
            document.querySelectorAll('tr.is-cut').forEach(tr => tr.classList.remove('is-cut'));
            clearMovePayload();
            updateToolbar();
        }

        btnCopy.addEventListener('click', stageForMove);
        btnMove.addEventListener('click', doMoveItems);

        function doMoveItems(){
            const mv = getMovePayload();
            if (!mv || !mv.ids || !mv.ids.length){
                showToast('Click Copy first to select file(s) to move.', true);
                return;
            }

            const fd = new FormData();
            fd.append('action', 'move_selected');
            fd.append('csrf_token', getCsrfToken());
            fd.append('ids', JSON.stringify(mv.ids));
            fd.append('target_folder_id', curFolderId !== null ? String(curFolderId) : '');

            fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.ok){
                        showToast(data.message);
                        clearStaged();
                        location.reload();
                    } else {
                        showToast(data.error || 'Move failed', true);
                    }
                })
                .catch(() => showToast('Network error', true));
        }

        document.addEventListener('keydown', function(e){
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
            if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'c' || e.key.toLowerCase() === 'x')) {
                if (getSelectedFileIds().length){ e.preventDefault(); stageForMove(); }
            } else if (e.key === 'Escape') {
                if (getMovePayload()){ e.preventDefault(); clearStaged(); }
            } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
                const checks = document.querySelectorAll('.row-check');
                if (checks.length){
                    e.preventDefault();
                    checks.forEach(c => c.checked = true);
                    onSelectionChange();
                }
            }
        });

        updateToolbar();
        syncRowHighlight();
    })();

    /* ── Search filter ──────────────────────────────────── */
    const searchInput = document.getElementById('explorerSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('#explorerTbody tr').forEach(tr => {
                const name = tr.getAttribute('data-name') || '';
                tr.style.display = (!q || name.includes(q)) ? '' : 'none';
            });
        });
    }

    /* ── Close modals on overlay click / Escape ─────────── */
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = '';
                if (this.id === 'deleteConfirmModal') window._deleteConfirmCallback = null;
                if (this.id === 'forwardAgainModal') window._forwardAgainCallback = null;
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            ['folderModal','editFolderModal','uploadModal','deleteConfirmModal','forwardAgainModal'].forEach(id => {
                const el = document.getElementById(id);
                if (el && el.classList.contains('active')) {
                    el.classList.remove('active');
                    document.body.style.overflow = '';
                    if (id === 'deleteConfirmModal') window._deleteConfirmCallback = null;
                    if (id === 'forwardAgainModal') window._forwardAgainCallback = null;
                }
            });
        }
    });
});

/* ── Global modal helpers (used by inline onclick) ──────── */
function openFolderModal() {
    document.getElementById('folderModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('folderParentId').value = <?= json_encode($currentFolderId ?? 0) ?>;
    setTimeout(() => document.getElementById('folderName').focus(), 50);
}
function closeFolderModal() {
    document.getElementById('folderModal').classList.remove('active');
    document.body.style.overflow = '';
}
function closeEditFolderModal() {
    document.getElementById('editFolderModal').classList.remove('active');
    document.body.style.overflow = '';
}
function openUploadModal() {
    const folder = new URLSearchParams(window.location.search).get('folder');
    document.getElementById('repoUploadFolderId').value = folder ? folder : '';
    document.getElementById('uploadModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('uploadTitle').focus(), 50);
}
function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
    document.body.style.overflow = '';
}
</script>

<?php include 'includes/footer.php'; ?>




