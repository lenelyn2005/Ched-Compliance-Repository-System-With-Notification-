<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

$flashMsg = trim($_GET['msg'] ?? '');
$flashError = trim($_GET['error'] ?? '');

// =============================================================================
// 0. ZIP download handlers
// =============================================================================
if (isset($_GET['zip_dept']) && is_numeric($_GET['zip_dept'])) {
    $deptId = (int)$_GET['zip_dept'];
    createDepartmentZip($pdo, $deptId);
    exit;
}
if (isset($_GET['zip_folder']) && is_numeric($_GET['zip_folder'])) {
    $folderId = (int)$_GET['zip_folder'];
    createFolderZip($pdo, $folderId);
    exit;
}

// -----------------------------------------------------------------------------
// ZIP helper functions
// -----------------------------------------------------------------------------
function getFolderTree($pdo, $deptId, $parentId = null)
{
    $folders = [];
    $sql = "SELECT id, folder_name FROM department_folders 
            WHERE department_id = ? AND parent_folder_id " . ($parentId === null ? "IS NULL" : "= ?") . " 
            AND LOWER(folder_name) <> 'root' ORDER BY folder_name";
    $params = [$deptId];
    if ($parentId !== null) $params[] = $parentId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $subfolders = $stmt->fetchAll();
    foreach ($subfolders as $sf) {
        $folders[] = [
            'id'         => $sf['id'],
            'name'       => $sf['folder_name'],
            'subfolders' => getFolderTree($pdo, $deptId, $sf['id']),
            'documents'  => getDocumentsForFolder($pdo, $deptId, $sf['id'])
        ];
    }
    return $folders;
}

function getDocumentsForFolder($pdo, $deptId, $folderId)
{
    $documents = [];
    $sql = "SELECT * FROM department_requirements WHERE department_id = ? AND folder_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deptId, $folderId]);
    $reqs = $stmt->fetchAll();

    foreach ($reqs as $req) {
        $trStmt = $pdo->prepare("SELECT id FROM teacher_requirements WHERE requirement_id = ?");
        $trStmt->execute([$req['id']]);
        $trIds = $trStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($trIds)) {
            $placeholders = implode(',', array_fill(0, count($trIds), '?'));
            $docStmt = $pdo->prepare("
                SELECT d.*, 'document' as source
                FROM documents d
                WHERE d.teacher_requirement_id IN ($placeholders)
                  AND d.status = 'approved_admin'
                  AND d.archived = 0
            ");
            $docStmt->execute($trIds);
            foreach ($docStmt->fetchAll() as $doc) {
                $documents[$doc['id'] . '_document'] = $doc;
            }
        }
        $directStmt = $pdo->prepare("
            SELECT d.*, 'document' as source
            FROM documents d
            WHERE d.department_id = ?
              AND d.category = ?
              AND d.document_type = ?
              AND d.teacher_requirement_id IS NULL
              AND d.status = 'approved_admin'
              AND d.archived = 0
        ");
        $directStmt->execute([$deptId, $req['category'], $req['document_type']]);
        foreach ($directStmt->fetchAll() as $doc) {
            $documents[$doc['id'] . '_document'] = $doc;
        }
    }
    return array_values($documents);
}

function getRootDocuments($pdo, $deptId)
{
    $documents = [];
    $sql = "SELECT * FROM department_requirements WHERE department_id = ? AND (folder_id IS NULL OR folder_id = 0)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deptId]);
    $reqs = $stmt->fetchAll();

    foreach ($reqs as $req) {
        $trStmt = $pdo->prepare("SELECT id FROM teacher_requirements WHERE requirement_id = ?");
        $trStmt->execute([$req['id']]);
        $trIds = $trStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($trIds)) {
            $placeholders = implode(',', array_fill(0, count($trIds), '?'));
            $docStmt = $pdo->prepare("
                SELECT d.*, 'document' as source
                FROM documents d
                WHERE d.teacher_requirement_id IN ($placeholders)
                  AND d.status = 'approved_admin'
                  AND d.archived = 0
            ");
            $docStmt->execute($trIds);
            foreach ($docStmt->fetchAll() as $doc) {
                $documents[$doc['id'] . '_document'] = $doc;
            }
        }
        $directStmt = $pdo->prepare("
            SELECT d.*, 'document' as source
            FROM documents d
            WHERE d.department_id = ?
              AND d.category = ?
              AND d.document_type = ?
              AND d.teacher_requirement_id IS NULL
              AND d.status = 'approved_admin'
              AND d.archived = 0
        ");
        $directStmt->execute([$deptId, $req['category'], $req['document_type']]);
        foreach ($directStmt->fetchAll() as $doc) {
            $documents[$doc['id'] . '_document'] = $doc;
        }
    }

    $phStmt = $pdo->prepare("
        SELECT pd.*, 'ph_document' as source
        FROM ph_documents pd
        JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
        JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
        WHERE pd.department_id = ?
          AND pd.status = 'approved_admin'
          AND pd.archived = 0
    ");
    $phStmt->execute([$deptId]);
    foreach ($phStmt->fetchAll() as $doc) {
        $documents[$doc['id'] . '_ph_document'] = $doc;
    }

    $phDirectStmt = $pdo->prepare("
        SELECT d.*, 'document' as source
        FROM documents d
        WHERE d.department_id = ?
          AND d.category = 'PH'
          AND d.document_type = 'Direct Upload'
          AND d.teacher_requirement_id IS NULL
          AND d.status = 'approved_admin'
          AND d.archived = 0
    ");
    $phDirectStmt->execute([$deptId]);
    foreach ($phDirectStmt->fetchAll() as $doc) {
        $documents[$doc['id'] . '_document'] = $doc;
    }

    return array_values($documents);
}

function zipEntryName(string $name): string
{
    // Strip path separators + NUL so stored filenames/folder names can't zip-slip.
    return preg_replace('#[/\\\\]+#', '_', str_replace("\0", '', $name));
}

function addFolderToZip($zip, $folderData, $currentPath)
{
    foreach ($folderData['documents'] as $doc) {
        $filePath = securePath($doc['file_path']);
        if ($filePath && file_exists($filePath)) {
            $zip->addFile($filePath, $currentPath . '/' . zipEntryName($doc['filename']));
        }
    }
    foreach ($folderData['subfolders'] as $subfolder) {
        $subPath = $currentPath . '/' . zipEntryName($subfolder['name']);
        $zip->addEmptyDir($subPath);
        addFolderToZip($zip, $subfolder, $subPath);
    }
}

function createDepartmentZip($pdo, $deptId)
{
    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE id = ?");
    $stmt->execute([$deptId]);
    $dept = $stmt->fetch();
    if (!$dept) {
        die('Department not found');
    }
    $deptName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dept['department_name']);

    $rootDocs = getRootDocuments($pdo, $deptId);
    $subfolders = getFolderTree($pdo, $deptId, null);

    if (!class_exists('ZipArchive')) {
        die('ZipArchive extension not available.');
    }
    $zip = new ZipArchive();
    $zipName = tempnam(sys_get_temp_dir(), 'zip');
    if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('Could not create ZIP.');
    }

    $basePath = $deptName;
    $zip->addEmptyDir($basePath);
    foreach ($rootDocs as $doc) {
        $filePath = securePath($doc['file_path']);
        if ($filePath && file_exists($filePath)) {
            $zip->addFile($filePath, $basePath . '/' . zipEntryName($doc['filename']));
        }
    }

    foreach ($subfolders as $folder) {
        $folderPath = $basePath . '/' . zipEntryName($folder['name']);
        $zip->addEmptyDir($folderPath);
        addFolderToZip($zip, $folder, $folderPath);
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $deptName . '.zip"');
    header('Content-Length: ' . filesize($zipName));
    readfile($zipName);
    unlink($zipName);
    exit;
}

function createFolderZip($pdo, $folderId)
{
    $stmt = $pdo->prepare("SELECT id, folder_name, department_id FROM department_folders WHERE id = ?");
    $stmt->execute([$folderId]);
    $folder = $stmt->fetch();
    if (!$folder) {
        die('Folder not found');
    }
    $deptId = $folder['department_id'];
    $folderName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $folder['folder_name']);

    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE id = ?");
    $stmt->execute([$deptId]);
    $dept = $stmt->fetch();
    $deptName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dept['department_name']);

    $tree = [
        'id'         => $folder['id'],
        'name'       => $folder['folder_name'],
        'subfolders' => getFolderTree($pdo, $deptId, $folder['id']),
        'documents'  => getDocumentsForFolder($pdo, $deptId, $folder['id'])
    ];

    if (!class_exists('ZipArchive')) {
        die('ZipArchive extension not available.');
    }
    $zip = new ZipArchive();
    $zipName = tempnam(sys_get_temp_dir(), 'zip');
    if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        die('Could not create ZIP.');
    }

    $basePath = $deptName . '/' . $folderName;
    $zip->addEmptyDir($basePath);
    addFolderToZip($zip, $tree, $basePath);

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $folderName . '.zip"');
    header('Content-Length: ' . filesize($zipName));
    readfile($zipName);
    unlink($zipName);
    exit;
}

// =============================================================================
// 1. Handle document deletion (POST + CSRF only — no GET state changes)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && is_numeric($_POST['delete'])) {
    requireCsrfToken();
    $docId = (int) $_POST['delete'];
    $source = $_POST['source'] ?? 'document';

    if ($source === 'document') {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if ($doc) {
            // Soft delete (§12): move to admin_archive + flag, keep row recoverable.
            $oldPath = securePath($doc['file_path']);
            $filename = basename($doc['file_path']);
            $newRelativePath = storageSubdir('admin_archive', $pdo, (int) $doc['department_id'], documentFolderId($pdo, 'documents', $docId)) . '/' . $filename;
            $newFullPath = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
            if (!is_dir(dirname($newFullPath))) {
                mkdir(dirname($newFullPath), 0755, true);
            }
            $hasFile = $oldPath && file_exists($oldPath);
            // ponytail: archive only when the move succeeds (or there is no
            // file to move); DB must not claim archived while file is live.
            $fileMoved = !$hasFile || rename($oldPath, $newFullPath);
            if (!$fileMoved) {
                error_log('[CHED] soft-delete move failed for document ' . $docId . ': ' . $oldPath);
            } else {
                $pdo->prepare('UPDATE documents SET file_path = ?, archived = 1 WHERE id = ?')
                    ->execute([$hasFile ? $newRelativePath : $doc['file_path'], $docId]);
                logAudit($pdo, 'delete_document', 'Soft-deleted document (moved to archive): ' . $doc['title'], 'document', $docId);
            }
        }
    } elseif ($source === 'ph_document') {
        $stmt = $pdo->prepare('SELECT * FROM ph_documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $archiveFolder = 'my_repository';
            $oldPath = securePath($doc['file_path']);
            $filename = basename($doc['file_path']);
            $newRelativePath = 'admin_archive/' . $archiveFolder . '/' . $filename;
            $newFullPath = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
            if (!is_dir(dirname($newFullPath))) {
                mkdir(dirname($newFullPath), 0755, true);
            }
            $hasFile = $oldPath && file_exists($oldPath);
            // ponytail: move (not copy) — no duplicate file stays in repository;
            // archive only when the move succeeds.
            $fileMoved = !$hasFile || rename($oldPath, $newFullPath);
            if (!$fileMoved) {
                error_log('[CHED] soft-delete move failed for PH document ' . $docId . ': ' . $oldPath);
            } else {
                $pdo->prepare('UPDATE ph_documents SET file_path = ?, archived = 1 WHERE id = ?')
                    ->execute([$hasFile ? $newRelativePath : $doc['file_path'], $docId]);
                logAudit($pdo, 'delete_document', 'Soft-deleted PH document (moved to archive): ' . $doc['title'], 'ph_document', $docId);
            }
        }
    }

    $dept = (int)($_POST['dept_id'] ?? 0);
    $folder = (int)($_POST['folder_id'] ?? 0);
    $url = 'admin_manage_document.php?dept=' . $dept . '&folder=' . $folder;
    header('Location: ' . $url);
    exit;
}

// =============================================================================
// 1b. Handle manual archive (POST with CSRF)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_id']) && is_numeric($_POST['archive_id'])) {
    requireCsrfToken();
    $archiveId = (int) $_POST['archive_id'];
    $source = $_POST['archive_source'] ?? 'document';
    $archiveSuccess = false;

    if ($source === 'ph_document') {
        $stmt = $pdo->prepare('SELECT * FROM ph_documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$archiveId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $archiveFolder = 'my_repository';
            $oldPath = securePath($doc['file_path']);
            $filename = basename($doc['file_path']);
            $newRelativePath = 'admin_archive/' . $archiveFolder . '/' . $filename;
            $newFullPath = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
            if (!is_dir(dirname($newFullPath))) {
                mkdir(dirname($newFullPath), 0755, true);
            }
            $hasFile = $oldPath && file_exists($oldPath);
            // ponytail: move (not copy) — no duplicate file stays in repository;
            // archive only when the move succeeds.
            $fileMoved = !$hasFile || rename($oldPath, $newFullPath);
            // ph_repository holds its own physical copy, so archiving this document
            // must NOT repoint the repository row — that is left untouched entirely.
            if (!$fileMoved) {
                error_log('[CHED] manual archive move failed for PH document ' . $archiveId . ': ' . $oldPath);
            } else {
                $pdo->prepare('UPDATE ph_documents SET file_path = ?, archived = 1 WHERE id = ?')
                    ->execute([$hasFile ? $newRelativePath : $doc['file_path'], $archiveId]);
                logAudit($pdo, 'manual_archive', 'Manually archived PH document: ' . $doc['title'], 'ph_document', $archiveId);
                $archiveSuccess = true;
            }
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$archiveId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $oldPath = securePath($doc['file_path']);
            $filename = basename($doc['file_path']);
            $newRelativePath = storageSubdir('admin_archive', $pdo, (int) $doc['department_id'], documentFolderId($pdo, 'documents', $archiveId)) . '/' . $filename;
            $newFullPath = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
            if (!is_dir(dirname($newFullPath))) {
                mkdir(dirname($newFullPath), 0755, true);
            }
            $hasFile = $oldPath && file_exists($oldPath);
            $fileMoved = !$hasFile || rename($oldPath, $newFullPath);
            if (!$fileMoved) {
                error_log('[CHED] manual archive move failed for document ' . $archiveId . ': ' . $oldPath);
            } else {
                $pdo->prepare('UPDATE documents SET file_path = ?, archived = 1 WHERE id = ?')
                    ->execute([$hasFile ? $newRelativePath : $doc['file_path'], $archiveId]);
                logAudit($pdo, 'manual_archive', 'Manually archived document: ' . $doc['title'], 'document', $archiveId);
                $archiveSuccess = true;
            }
        }
    }

    $dept = isset($_POST['dept_id']) ? (int)$_POST['dept_id'] : 0;
    $folder = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0;
    $redirect = 'admin_manage_document.php?dept=' . $dept . '&folder=' . $folder;
    if ($archiveSuccess) {
        $redirect .= '&msg=' . urlencode('Document successfully archived.');
    } else {
        $redirect .= '&error=' . urlencode('Document not found or already archived.');
    }
    header('Location: ' . $redirect);
    exit;
}

// =============================================================================
// 2. Get all departments (deduplicated by name)
// =============================================================================
$departments = $pdo->query("
    SELECT MIN(id) as id, department_name, department_code
    FROM departments
    GROUP BY department_name
    ORDER BY department_name
")->fetchAll();


// =============================================================================
// 3. Determine current view (card overview or department explorer)
// =============================================================================
$selectedDeptId = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$selectedFolderId = isset($_GET['folder']) ? (int)$_GET['folder'] : 0;
$showCards = ($selectedDeptId == 0);

// =============================================================================
// 4. If a department is selected, build folder/file data (no tree sidebar)
// =============================================================================
$breadcrumb = [];
$folderContents = [];
$selectedDeptName = '';

if (!$showCards) {
    foreach ($departments as $d) {
        if ((int)$d['id'] === $selectedDeptId) {
            $selectedDeptName = $d['department_name'];
            break;
        }
    }

    $currentFolderId = $selectedFolderId;
    if ($currentFolderId) {
        $folderId = $currentFolderId;
        while ($folderId) {
            $stmt = $pdo->prepare("SELECT id, folder_name, parent_folder_id FROM department_folders WHERE id = ?");
            $stmt->execute([$folderId]);
            $f = $stmt->fetch();
            if (!$f) break;
            array_unshift($breadcrumb, ['id' => $f['id'], 'name' => $f['folder_name']]);
            $folderId = $f['parent_folder_id'];
        }
    }

    $subfolders = [];
    if ($currentFolderId) {
        $stmt = $pdo->prepare("SELECT id, folder_name FROM department_folders WHERE department_id = ? AND parent_folder_id = ? AND LOWER(folder_name) <> 'root' ORDER BY folder_name");
        $stmt->execute([$selectedDeptId, $currentFolderId]);
        $subfolders = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, folder_name FROM department_folders WHERE department_id = ? AND parent_folder_id IS NULL AND LOWER(folder_name) <> 'root' ORDER BY folder_name");
        $stmt->execute([$selectedDeptId]);
        $subfolders = $stmt->fetchAll();
    }

    function getRequirementsWithDocs($pdo, $deptId, $folderId, $includeUnassigned = false)
    {
        $params = [$deptId];
        if ($includeUnassigned || $folderId == 0) {
            $sql = "SELECT dr.* FROM department_requirements dr
                    WHERE dr.department_id = ? AND (dr.folder_id IS NULL OR dr.folder_id = 0)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            $sql = "SELECT dr.* FROM department_requirements dr
                    WHERE dr.department_id = ? AND dr.folder_id = ?";
            $params[] = $folderId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $reqs = $stmt->fetchAll();

        foreach ($reqs as &$req) {
            $trStmt = $pdo->prepare("SELECT id FROM teacher_requirements WHERE requirement_id = ?");
            $trStmt->execute([$req['id']]);
            $trIds = $trStmt->fetchAll(PDO::FETCH_COLUMN);

            $req['documents'] = [];
            if (!empty($trIds)) {
                $placeholders = implode(',', array_fill(0, count($trIds), '?'));
                $docStmt = $pdo->prepare("
                    SELECT d.*, u.name as uploaded_by_name
                    FROM documents d
                    LEFT JOIN users u ON d.uploaded_by = u.id
                    WHERE d.teacher_requirement_id IN ($placeholders)
                      AND d.status = 'approved_admin'
                      AND d.archived = 0
                    ORDER BY d.upload_date DESC
                ");
                $docStmt->execute($trIds);
                $req['documents'] = $docStmt->fetchAll();
            }

            $directStmt = $pdo->prepare("
                SELECT d.*, u.name as uploaded_by_name
                FROM documents d
                LEFT JOIN users u ON d.uploaded_by = u.id
                WHERE d.department_id = ?
                  AND d.category = ?
                  AND d.document_type = ?
                  AND d.teacher_requirement_id IS NULL
                  AND d.status = 'approved_admin'
                  AND d.archived = 0
                ORDER BY d.upload_date DESC
            ");
            $directStmt->execute([$deptId, $req['category'], $req['document_type']]);
            $req['documents'] = array_merge($req['documents'], $directStmt->fetchAll());
        }
        return $reqs;
    }

    $isDeptLevel = ($selectedFolderId == 0 || !$currentFolderId);
    $folderIdForReqs = $currentFolderId ?: 0;
    $reqs = getRequirementsWithDocs($pdo, $selectedDeptId, $folderIdForReqs, $isDeptLevel);

    foreach ($subfolders as $sf) {
        $folderContents[] = [
            'type'       => 'folder',
            'id'         => $sf['id'],
            'name'       => $sf['folder_name'],
            'is_folder'  => true,
        ];
    }

    foreach ($reqs as $req) {
        foreach ($req['documents'] as $doc) {
            $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
            $fileType = 'Document';
            if (in_array($ext, ['pdf'])) $fileType = 'PDF';
            elseif (in_array($ext, ['doc', 'docx'])) $fileType = 'Word';
            elseif (in_array($ext, ['xls', 'xlsx'])) $fileType = 'Excel';
            elseif (in_array($ext, ['ppt', 'pptx'])) $fileType = 'PowerPoint';
            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $fileType = 'Image';
            elseif (in_array($ext, ['zip', 'rar'])) $fileType = 'Archive';

            $docName = $doc['title'] ?: $doc['filename'];
            $requirementName = $req['document_type'] . ' (' . $req['category'] . ')';
            if ($docName === trim($req['category'] . ' - ' . $req['document_type'])) {
                $requirementName = '';
            }

            $folderContents[] = [
                'type'              => 'file',
                'id'                => $doc['id'],
                'source'            => 'document',
                'name'              => $docName,
                'filename'          => $doc['filename'],
                'file_path'         => $doc['file_path'],
                'file_exists'       => securePath($doc['file_path']) !== false,
                'file_type'         => $fileType,
                'upload_date'       => $doc['upload_date'],
                'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
                'status'            => $doc['status'],
                'archived'          => $doc['archived'],
                'is_folder'         => false,
                'requirement_name'  => $requirementName,
            ];
        }
    }

    if ($isDeptLevel) {
        $phStmt = $pdo->prepare("
            SELECT pd.*, u.name as uploaded_by_name, phr.title AS requirement_title
            FROM ph_documents pd
            JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            LEFT JOIN users u ON pd.uploaded_by = u.id
            WHERE pd.department_id = ?
              AND pd.status = 'approved_admin'
              AND pd.archived = 0
            ORDER BY pd.upload_date DESC
        ");
        $phStmt->execute([$selectedDeptId]);
        $phDocs = $phStmt->fetchAll();

        foreach ($phDocs as $doc) {
            $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
            $fileType = 'Document';
            if (in_array($ext, ['pdf'])) $fileType = 'PDF';
            elseif (in_array($ext, ['doc', 'docx'])) $fileType = 'Word';
            elseif (in_array($ext, ['xls', 'xlsx'])) $fileType = 'Excel';
            elseif (in_array($ext, ['ppt', 'pptx'])) $fileType = 'PowerPoint';
            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $fileType = 'Image';
            elseif (in_array($ext, ['zip', 'rar'])) $fileType = 'Archive';

            $docName = $doc['title'] ?: $doc['filename'];
            $requirementName = $doc['requirement_title'] ?? 'PH Requirement';
            if ($docName === $requirementName) {
                $requirementName = '';
            }

            $folderContents[] = [
                'type'              => 'file',
                'id'                => $doc['id'],
                'source'            => 'ph_document',
                'name'              => $docName,
                'filename'          => $doc['filename'],
                'file_path'         => $doc['file_path'],
                'file_exists'       => securePath($doc['file_path']) !== false,
                'file_type'         => $fileType,
                'upload_date'       => $doc['upload_date'],
                'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
                'status'            => $doc['status'],
                'archived'          => $doc['archived'] ?? 0,
                'is_folder'         => false,
                'requirement_name'  => $requirementName,
            ];
        }
    }

    if ($isDeptLevel) {
        $phDirectStmt = $pdo->prepare("
            SELECT d.*, u.name as uploaded_by_name
            FROM documents d
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE d.department_id = ?
              AND d.category = 'PH'
              AND d.document_type = 'Direct Upload'
              AND d.teacher_requirement_id IS NULL
              AND d.status = 'approved_admin'
              AND d.archived = 0
            ORDER BY d.upload_date DESC
        ");
        $phDirectStmt->execute([$selectedDeptId]);
        $phDirectDocs = $phDirectStmt->fetchAll();

        foreach ($phDirectDocs as $doc) {
            $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
            $fileType = 'Document';
            if (in_array($ext, ['pdf'])) $fileType = 'PDF';
            elseif (in_array($ext, ['doc', 'docx'])) $fileType = 'Word';
            elseif (in_array($ext, ['xls', 'xlsx'])) $fileType = 'Excel';
            elseif (in_array($ext, ['ppt', 'pptx'])) $fileType = 'PowerPoint';
            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $fileType = 'Image';
            elseif (in_array($ext, ['zip', 'rar'])) $fileType = 'Archive';

            $docName = $doc['title'] ?: $doc['filename'];

            $folderContents[] = [
                'type'              => 'file',
                'id'                => $doc['id'],
                'source'            => 'document',
                'name'              => $docName,
                'filename'          => $doc['filename'],
                'file_path'         => $doc['file_path'],
                'file_exists'       => securePath($doc['file_path']) !== false,
                'file_type'         => $fileType,
                'upload_date'       => $doc['upload_date'],
                'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
                'status'            => $doc['status'],
                'archived'          => $doc['archived'] ?? 0,
                'is_folder'         => false,
            ];
        }
    }

    usort($folderContents, function ($a, $b) {
        if ($a['is_folder'] && !$b['is_folder']) return -1;
        if (!$a['is_folder'] && $b['is_folder']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
}

// =============================================================================
// 4b. Real File Explorer–style icons (inline SVG, no background container)
// =============================================================================
if (!function_exists('manage_doc_file_icon')) {
    /**
     * Returns an inline SVG string that mimics the real Windows File Explorer
     * document icon: a colored document shape with a folded corner and the
     * file-type label rendered on the body. No wrapper, no background box.
     */
    function manage_doc_file_icon(string $slug): string
    {
        $slug = strtolower($slug);

        // Shared document body path (viewBox 0 0 48 48)
        $body  = 'M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z';
        $fold  = 'M30 2l10 10h-8a2 2 0 0 1-2-2V2z';

        $icons = [
            // PDF — red document with "PDF"
            'pdf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#e53935"/>'
                . '<path d="' . $fold . '" fill="#b71c1c"/>'
                . '<text x="24" y="37" font-family="Arial, Helvetica, sans-serif" font-size="10" font-weight="700" fill="#ffffff" text-anchor="middle" letter-spacing="0.3">PDF</text>'
                . '</svg>',

            // Word — blue document with "W"
            'word' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#2b579a"/>'
                . '<path d="' . $fold . '" fill="#1e3f70"/>'
                . '<text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">W</text>'
                . '</svg>',

            // Excel — green document with "X"
            'excel' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#217346"/>'
                . '<path d="' . $fold . '" fill="#185c37"/>'
                . '<text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">X</text>'
                . '</svg>',

            // PowerPoint — orange document with "P"
            'powerpoint' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#d24726"/>'
                . '<path d="' . $fold . '" fill="#a5371e"/>'
                . '<text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">P</text>'
                . '</svg>',

            // Image — white document with a photo thumbnail (blue sky + mountain + sun)
            'image' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/>'
                . '<path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/>'
                . '<rect x="13" y="20" width="22" height="16" rx="1" fill="#5eb7e8"/>'
                . '<circle cx="19" cy="25" r="2" fill="#ffffff"/>'
                . '<path d="M13 34l6-6 4 4 4-5 8 7z" fill="#7cc576"/>'
                . '</svg>',

            // Archive — amber document with zipper
            'archive' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#ffc107"/>'
                . '<path d="' . $fold . '" fill="#e0a800"/>'
                . '<rect x="20" y="16" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="20" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="24" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="28" width="8" height="2" fill="#5d4037"/>'
                . '<rect x="20" y="32" width="8" height="6" rx="1" fill="#5d4037"/>'
                . '</svg>',

            // Default document — white with grey text lines
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

// Real Windows-style folder icon — back panel + front body, no container
if (!function_exists('manage_doc_folder_icon')) {
    function manage_doc_folder_icon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
            . '<path d="M6 10a2 2 0 0 1 2-2h11.5a2 2 0 0 1 1.7.9l1.6 2.2a2 2 0 0 0 1.7.9H40a2 2 0 0 1 2 2v3H6v-7z" fill="#f7b915"/>'
            . '<path d="M6 15h36v23a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V15z" fill="#ffcf47"/>'
            . '</svg>';
    }
}

// =============================================================================
// 5. Render the page
// =============================================================================
$pageTitle = 'Document Repository';
ob_start();
?>

<style>
    @import url('offline/inter-font.css');
    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-light: #eef2ff;
        --primary-soft: #6366f1;
        --secondary: #64748b;
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
        --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.04);
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
        --radius-lg: 12px;
        --radius-xl: 16px;
        --transition: all 0.2s ease;

        /* Eye-friendly, Arial-first system font stack (offline) */
        --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    * {
        box-sizing: border-box;
    }

    body {
        background: #eef1f7;
        font-family: var(--font-sans);
        color: var(--gray-800);
        font-size: 14.5px;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    :focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
        border-radius: 6px;
    }

    ::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }

    ::-webkit-scrollbar-track {
        background: transparent;
    }

    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 9999px;
        border: 3px solid transparent;
        background-clip: content-box;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
        background-clip: content-box;
    }

    .content-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding: 1.25rem 1.5rem !important;
        gap: 0;
    }

    .dashboard-container {
        flex: 1;
        width: 100%;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0;
    }

    .repo-section {
        flex: 1;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .repo-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 1.1rem 1.75rem;
        border-bottom: 1px solid #edf1f7;
        background: #fafbff;
    }

    .repo-head-left {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-width: 0;
    }

    .repo-head-icon {
        width: 44px;
        height: 44px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 13px;
        color: #fff;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-soft) 100%);
    }

    .repo-head-title {
        font-size: 1.05rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--gray-900);
        line-height: 1.25;
    }

    .repo-head-sub {
        font-size: 0.78rem;
        color: var(--gray-500);
        font-weight: 400;
        margin-top: 0.18rem;
        line-height: 1.4;
    }

    .repo-count {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.42rem 0.85rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        color: var(--primary-dark);
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        white-space: nowrap;
    }

    .repo-body {
        padding: 1.6rem 1.75rem 1.75rem;
        flex: 1;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.55rem 1rem;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.4;
        font-family: inherit;
        white-space: nowrap;
        letter-spacing: 0.01em;
    }

    .btn-sm {
        padding: 0.4rem 0.7rem;
        font-size: 0.74rem;
    }

    .btn-icon {
        padding: 0.45rem;
        min-width: 30px;
        min-height: 30px;
        justify-content: center;
        border-radius: 8px;
    }

    .btn-outline {
        background: #ffffff;
        border-color: #e2e8f0;
        color: var(--gray-700);
    }

    .btn-outline:hover {
        border-color: #c7d2fe;
        color: var(--primary-dark);
        background: #f8faff;
    }

    .btn-danger {
        background: #ffffff;
        border-color: #e2e8f0;
        color: var(--gray-600);
    }

    .btn-danger:hover {
        background: #fef2f2;
        border-color: #fecaca;
        color: var(--danger);
    }

    .btn-disabled {
        opacity: 0.4;
        cursor: not-allowed;
        pointer-events: none;
    }

    .btn-zip {
        background: #ffffff;
        border-color: #e2e8f0;
        color: var(--gray-600);
    }

    .btn-zip:hover {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }

    .breadcrumb-nav {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.35rem;
        font-size: 0.82rem;
        color: var(--gray-500);
        margin-bottom: 1.35rem;
        padding: 0.5rem 1rem;
        border: 1px solid #e5e7eb;
        border-radius: 9999px;
        background: #f9fafb;
        max-width: 100%;
    }

    .breadcrumb-nav a {
        color: var(--gray-600);
        text-decoration: none;
        font-weight: 600;
        transition: color 0.15s ease, background 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        padding: 0.15rem 0.4rem;
        border-radius: 6px;
    }

    .breadcrumb-nav a:hover {
        color: var(--primary);
        background: #eef2ff;
    }

    .breadcrumb-nav .current {
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.01em;
        padding: 0.15rem 0.4rem;
    }

    .breadcrumb-nav .separator {
        color: var(--gray-300);
        font-weight: 400;
        padding: 0 0.1rem;
    }

    .breadcrumb-nav .home-icon {
        width: 15px;
        height: 15px;
        display: inline-block;
        vertical-align: middle;
        margin-right: 0.1rem;
        color: var(--primary);
    }

    .dept-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
    }

    .dept-card {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        padding: 0;
        transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        text-decoration: none;
        color: inherit;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        position: relative;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .dept-card-link {
        display: flex;
        flex-direction: column;
        flex: 1;
        text-decoration: none;
        color: inherit;
        padding: 1.05rem 1.15rem 0;
        cursor: pointer;
    }

    .dept-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 22px -12px rgba(30, 41, 59, 0.2);
        border-color: #c7d2fe;
    }

    /* Real folder icon — no background box, just the icon */
    .dept-card .folder-icon-big {
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        width: auto;
        height: auto;
        padding: 0;
        margin-bottom: 0.55rem;
        background: transparent;
        border: none;
        flex-shrink: 0;
    }

    .dept-card .folder-icon-big svg {
        width: 38px;
        height: 38px;
        display: block;
    }

    .dept-card .dept-name {
        font-size: 0.9rem;
        font-weight: 700;
        line-height: 1.35;
        margin: 0 0 0.2rem 0;
        color: var(--gray-900);
        letter-spacing: -0.01em;
    }

    .dept-card .dept-code {
        font-size: 0.65rem;
        color: var(--gray-500);
        font-weight: 700;
        margin-bottom: 0;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .dept-card .arrow {
        position: absolute;
        top: 1.15rem;
        right: 1.15rem;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        color: var(--gray-500);
        transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .dept-card:hover .arrow {
        background: var(--primary);
        border-color: var(--primary);
        color: white;
    }

    .dept-card .zip-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
        margin: 0.7rem 1.15rem 1.05rem;
        padding: 0.42rem 0.65rem;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.66rem;
        font-weight: 700;
        text-decoration: none;
        color: var(--gray-600);
        letter-spacing: 0.05em;
        text-transform: uppercase;
        transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .dept-card .zip-btn:hover {
        border-color: var(--primary);
        background: var(--primary);
        color: white;
    }

    .content-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .content-item {
        display: flex;
        align-items: center;
        padding: 0.9rem 1.15rem;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        gap: 0.9rem;
        cursor: default;
        background: #ffffff;
        position: relative;
    }

    .content-item:hover {
        background: #fafbff;
        border-color: #c7d2fe;
        box-shadow: 0 6px 16px -10px rgba(30, 41, 59, 0.15);
    }

    .content-item.folder-item {
        cursor: pointer;
    }

    .content-item.folder-item:hover {
        border-color: #fde68a;
        background: #fffdf5;
    }

    /* Icon slot — transparent, just sizes the SVG. Real icon colors live inside the SVG. */
    .content-item .icon {
        width: 32px;
        height: 32px;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        background: transparent;
        border: none;
        border-radius: 0;
        box-shadow: none;
        transition: transform 0.2s ease;
    }

    .content-item .icon svg {
        width: 100%;
        height: 100%;
        display: block;
    }

    .content-item:hover .icon {
        transform: scale(1.08);
    }

    /* No color overrides — colors come from the SVG itself */
    .content-item .icon.folder-icon,
    .content-item .icon.file-icon {
        background: transparent;
        border: none;
        color: inherit;
    }

    .content-item .info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.22rem;
    }

    .content-item .info .name {
        font-weight: 700;
        font-size: 0.9rem;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
        line-height: 1.4;
    }

    .content-item .info .name .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.15rem 0.45rem;
        border-radius: 9999px;
        font-size: 0.62rem;
        font-weight: 700;
        line-height: 1.3;
        background: var(--gray-100);
        color: var(--gray-600);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border: 1px solid var(--gray-200);
    }

    .content-item .info .name .badge.missing {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .content-item .info .meta {
        font-size: 0.74rem;
        color: var(--gray-500);
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .content-item .info .meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.28rem;
        padding: 0.1rem 0.5rem 0.1rem 0.4rem;
        border-radius: 9999px;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        font-weight: 500;
        line-height: 1.5;
        white-space: nowrap;
        max-width: 100%;
    }

    .content-item .info .meta span svg {
        opacity: 0.65;
        flex-shrink: 0;
    }

    .content-item .info .meta .requirement-tag {
        display: inline-flex;
        align-items: center;
        padding: 0.14rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.62rem;
        font-weight: 700;
        background: #eef2ff;
        color: #4338ca;
        border: 1px solid #c7d2fe;
        letter-spacing: 0.02em;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .content-item .actions {
        display: flex;
        gap: 0.3rem;
        flex-shrink: 0;
        align-items: center;
        opacity: 0.6;
        transition: opacity 0.15s ease;
    }

    .content-item:hover .actions,
    .content-item:focus-within .actions {
        opacity: 1;
    }

    .empty-state {
        text-align: center;
        padding: 3.5rem 1.5rem;
        color: var(--gray-500);
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #fafbfc;
    }

    .empty-state svg {
        width: 46px;
        height: 46px;
        margin: 0 auto 1rem;
        display: block;
        opacity: 0.3;
        color: var(--gray-400);
    }

    .empty-state p {
        font-size: 0.92rem;
        font-weight: 600;
        color: var(--gray-500);
        margin: 0;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-overlay .modal-box {
        background: #ffffff;
        border-radius: var(--radius-xl);
        box-shadow: 0 20px 50px -12px rgba(0, 0, 0, 0.25);
        width: 100%;
        max-width: 420px;
        display: flex;
        flex-direction: column;
        border: 1px solid #e5e7eb;
        overflow: hidden;
    }

    /* Success toast — top banner, hugs text, no backdrop */
    .modal-overlay#successModal {
        background: none;
        padding: 0;
        align-items: flex-start;
        justify-content: center;
    }

    .modal-overlay#successModal .modal-box {
        width: auto;
        max-width: calc(100vw - 2rem);
        margin-top: 16px;
        border-radius: 10px;
        border: 1px solid #bbf7d0;
        border-left: 4px solid #16a34a;
        background: #f0fdf4;
        box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
        animation: docToastDrop .26s cubic-bezier(.2, .9, .3, 1);
        overflow: visible;
    }

    @keyframes docToastDrop {
        from {
            transform: translateY(-18px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .toast-row {
        display: flex;
        align-items: center;
        gap: .55rem;
        padding: .7rem .75rem .7rem .9rem;
        font-size: .88rem;
        font-weight: 600;
        color: #14532d;
    }

    .toast-row>svg {
        flex-shrink: 0;
        color: #16a34a;
    }

    .toast-dismiss {
        margin-left: .35rem;
        flex-shrink: 0;
        border: 0;
        background: transparent;
        color: #4d7c5f;
        cursor: pointer;
        padding: .2rem;
        border-radius: 6px;
        display: flex;
    }

    .toast-dismiss:hover {
        background: rgba(22, 163, 74, .12);
        color: #166534;
    }

    .modal-box .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        background: #fafbff;
    }

    .modal-box .modal-header h2 {
        font-size: 1.05rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--gray-900);
        margin: 0;
    }

    .modal-box .modal-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        cursor: pointer;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        color: var(--gray-500);
        transition: background 0.15s ease, color 0.15s ease;
        flex-shrink: 0;
    }

    .modal-box .modal-close:hover {
        background: #f3f4f6;
        color: var(--gray-800);
    }

    .modal-box .modal-body {
        padding: 1.5rem;
    }

    .modal-box .modal-body p {
        font-size: 0.92rem;
        color: var(--gray-700);
        margin: 0;
        line-height: 1.55;
    }

    .modal-box .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e5e7eb;
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        background: #fafbfc;
    }

    .modal-box .modal-footer .btn {
        min-width: 96px;
        justify-content: center;
    }

    .btn-secondary {
        background: #ffffff;
        border-color: #e5e7eb;
        color: var(--gray-700);
    }

    .btn-secondary:hover {
        background: #f9fafb;
        border-color: #cbd5e1;
    }

    .btn-danger-solid {
        background: var(--danger);
        border-color: transparent;
        color: white;
    }

    .btn-danger-solid:hover {
        background: #dc2626;
    }

    @media (max-width: 992px) {
        .dashboard-container {
            padding: 0.2rem;
        }

        .repo-head {
            padding: 1rem 1.5rem;
        }

        .repo-body {
            padding: 1.5rem;
        }

        .dept-grid {
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.2rem;
        }

        .repo-head {
            padding: 1rem 1.25rem;
            gap: 0.75rem;
        }

        .repo-head-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
        }

        .repo-head-title {
            font-size: 0.98rem;
        }

        .repo-head-sub {
            font-size: 0.74rem;
        }

        .repo-body {
            padding: 1.25rem;
        }

        .dept-grid {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .content-item {
            padding: 0.8rem 0.9rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .content-item .actions {
            margin-left: auto;
            opacity: 1;
        }

        .breadcrumb-nav {
            font-size: 0.8rem;
            padding: 0.45rem 0.85rem;
        }

        .dept-card .zip-btn {
            margin: 0.6rem 1rem 0.9rem;
        }
    }

    @media (max-width: 480px) {
        .content-item {
            flex-direction: column;
            align-items: stretch;
        }

        .content-item .info {
            width: 100%;
        }

        .content-item .actions {
            justify-content: flex-end;
        }

        .repo-count {
            display: none;
        }
    }

    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            transition-duration: 0.001ms !important;
        }
    }

    .archive-flash {
        position: fixed;
        top: 1.25rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 20000;
        max-width: min(400px, calc(100vw - 2rem));
        margin: 0;
        padding: 0.9rem 1.1rem;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.9rem;
        border: 1px solid;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .archive-flash.alert-success {
        background: #ecfdf5;
        color: #065f46;
        border-color: #a7f3d0;
    }

    .archive-flash.alert-danger {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }
</style>

<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<div class="dashboard-container">

    <div class="repo-section">

        <div class="repo-head">
            <div class="repo-head-left">
                <div class="repo-head-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 4H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z" />
                    </svg>
                </div>
                <div>
                    <div class="repo-head-title">Document Repository</div>
                    <div class="repo-head-sub">Browse, preview, download, archive, or delete approved documents.</div>
                </div>
            </div>
            <div class="repo-count">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22 4 12 14.01 11 14.01" />
                </svg>
                Approved only
            </div>
        </div>


        <?php if ($flashError): ?>
            <div class="archive-flash alert-danger" role="alert" aria-live="assertive">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                <span><?= htmlspecialchars($flashError) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($showCards): ?>
            <div class="repo-body">
                <div class="dept-grid" id="deptGrid">
                    <?php foreach ($departments as $d):
                        $code = $d['department_code'] ?? '';
                    ?>
                        <div class="dept-card" data-name="<?= sanitize(strtolower($d['department_name'])) ?>">
                            <a href="admin_manage_document.php?dept=<?= $d['id'] ?>&folder=0" class="dept-card-link">
                                <span class="arrow">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="9 18 15 12 9 6" />
                                    </svg>
                                </span>
                                <div class="folder-icon-big"><?= manage_doc_folder_icon() ?></div>
                                <div class="dept-name"><?= sanitize($d['department_name']) ?></div>
                                <?php if ($code): ?>
                                    <div class="dept-code"><?= sanitize($code) ?></div>
                                <?php endif; ?>
                            </a>
                            <a href="?zip_dept=<?= $d['id'] ?>" class="zip-btn" title="Download all files as ZIP">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="7 10 12 15 17 10" />
                                    <line x1="12" y1="15" x2="12" y2="3" />
                                </svg>
                                ZIP
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="repo-body">

                <div class="breadcrumb-nav">
                    <a href="admin_manage_document.php" title="All Departments">
                        <svg class="home-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
                        All Departments
                    </a>
                    <span class="separator">/</span>

                    <?php if ($selectedFolderId == 0): ?>
                        <span class="current"><?= sanitize($selectedDeptName) ?></span>
                    <?php else: ?>
                        <a href="admin_manage_document.php?dept=<?= $selectedDeptId ?>&folder=0"><?= sanitize($selectedDeptName) ?></a>
                        <?php
                        $total = count($breadcrumb);
                        foreach ($breadcrumb as $index => $folder):
                            $isLast = ($index === $total - 1);
                        ?>
                            <span class="separator">/</span>
                            <?php if ($isLast): ?>
                                <span class="current"><?= sanitize($folder['name']) ?></span>
                            <?php else: ?>
                                <a href="admin_manage_document.php?dept=<?= $selectedDeptId ?>&folder=<?= $folder['id'] ?>"><?= sanitize($folder['name']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="content-list" id="contentList">
                    <?php if (empty($folderContents)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            <p>This folder is empty.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($folderContents as $item): ?>
                            <?php if ($item['is_folder']): ?>
                                <div class="content-item folder-item" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-type="folder"
                                    data-csp-href="?dept=<?= $selectedDeptId ?>&folder=<?= $item['id'] ?>">
                                    <div class="icon folder-icon">
                                        <?= manage_doc_folder_icon() ?>
                                    </div>
                                    <div class="info">
                                        <div class="name">
                                            <?= sanitize($item['name']) ?>
                                        </div>
                                    </div>
                                    <div class="actions">
                                        <a href="?zip_folder=<?= $item['id'] ?>" class="btn btn-sm btn-zip" title="Download this folder as ZIP">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <polyline points="7 10 12 15 17 10" />
                                                <line x1="12" y1="15" x2="12" y2="3" />
                                            </svg>
                                            ZIP
                                        </a>
                                        <span class="btn btn-sm btn-icon" style="cursor:default;opacity:0.35;color:var(--gray-400);">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="9 18 15 12 9 6" />
                                            </svg>
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php
                                $fileExists = $item['file_exists'] ?? false;
                                $downloadLink = ($item['source'] === 'document')
                                    ? 'download_document.php?id=' . $item['id']
                                    : 'download_ph_document.php?id=' . $item['id'];
                                $uploadDate = !empty($item['upload_date']) ? date('M d, Y g:i A', strtotime($item['upload_date'])) : 'Unknown';
                                $fileTypeSlug = strtolower($item['file_type'] ?? 'document');
                                ?>
                                <div class="content-item" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-type="file" data-filetype="<?= sanitize($fileTypeSlug) ?>">
                                    <div class="icon file-icon">
                                        <?= manage_doc_file_icon($fileTypeSlug) ?>
                                    </div>
                                    <div class="info">
                                        <div class="name">
                                            <?= sanitize($item['name']) ?>
                                            <?php if (!$fileExists): ?>
                                                <span class="badge missing">Missing</span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['requirement_name'])): ?>
                                                <span class="requirement-tag"><?= sanitize($item['requirement_name']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="meta">
                                            <span>
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                                    <line x1="16" y1="2" x2="16" y2="6" />
                                                    <line x1="8" y1="2" x2="8" y2="6" />
                                                    <line x1="3" y1="10" x2="21" y2="10" />
                                                </svg>
                                                <?= $uploadDate ?>
                                            </span>
                                            <span>
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                                    <circle cx="12" cy="7" r="4" />
                                                </svg>
                                                <?= sanitize($item['uploaded_by'] ?? 'Unknown') ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="actions">
                                        <a href="#" class="btn btn-sm btn-outline btn-icon view-btn"
                                            data-id="<?= $item['id'] ?>"
                                            data-type="<?= $item['source'] ?>"
                                            data-title="<?= sanitize($item['name']) ?>"
                                            data-filename="<?= sanitize($item['filename']) ?>"
                                            title="Preview">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                        </a>
                                        <?php if ($fileExists): ?>
                                            <a href="<?= $downloadLink ?>" class="btn btn-sm btn-outline btn-icon" title="Download">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                    <polyline points="7 10 12 15 17 10" />
                                                    <line x1="12" y1="15" x2="12" y2="3" />
                                                </svg>
                                            </a>
                                            <form method="POST" action="admin_manage_document.php" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                                <input type="hidden" name="archive_id" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="archive_source" value="<?= $item['source'] ?>">
                                                <input type="hidden" name="dept_id" value="<?= $selectedDeptId ?>">
                                                <input type="hidden" name="folder_id" value="<?= $selectedFolderId ?>">
                                                <button type="button" class="btn btn-sm btn-outline btn-icon js-archive-doc" title="Archive" data-archive-name="<?= sanitize($item['name']) ?>">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="21 8 21 21 3 21 3 8" />
                                                        <rect x="1" y="3" width="22" height="5" />
                                                        <line x1="10" y1="12" x2="14" y2="12" />
                                                    </svg>
                                                </button>
                                            </form>
                                            <form method="POST" action="admin_manage_document.php" style="display:inline;" class="js-delete-form">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                                <input type="hidden" name="delete" value="<?= $item['id'] ?>">
                                                <input type="hidden" name="source" value="<?= $item['source'] ?>">
                                                <input type="hidden" name="dept_id" value="<?= $selectedDeptId ?>">
                                                <input type="hidden" name="folder_id" value="<?= $selectedFolderId ?>">
                                                <button type="button" class="btn btn-sm btn-danger btn-icon js-delete-doc" title="Delete" data-delete-name="<?= sanitize($item['name']) ?>">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="btn btn-sm btn-disabled btn-icon" title="File not found">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                    <polyline points="7 10 12 15 17 10" />
                                                    <line x1="12" y1="15" x2="12" y2="3" />
                                                </svg>
                                            </span>
                                            <span class="btn btn-sm btn-disabled btn-icon" title="Cannot delete - file missing">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10" />
                                                    <line x1="15" y1="9" x2="9" y2="15" />
                                                    <line x1="9" y1="9" x2="15" y2="15" />
                                                </svg>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div> <!-- /repo-section -->
</div>

<!-- ========== MODAL: Delete Confirmation ========== -->
<div class="modal-overlay" id="deleteConfirmModal" aria-hidden="true">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="deleteConfirmTitle">
        <div class="modal-header">
            <h2 id="deleteConfirmTitle">Confirm Deletion</h2>
            <button class="modal-close" id="deleteConfirmClose" type="button" aria-label="Close dialog">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <p id="deleteConfirmMsg">Are you sure you want to delete this document?</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="deleteCancelBtn">Cancel</button>
            <button type="button" class="btn btn-danger-solid" id="deleteConfirmBtn">Confirm Delete</button>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    (function() {
        var modal = document.getElementById('deleteConfirmModal');
        var titleEl = document.getElementById('deleteConfirmTitle');
        var msg = document.getElementById('deleteConfirmMsg');
        var confirmBtn = document.getElementById('deleteConfirmBtn');
        var cancelBtn = document.getElementById('deleteCancelBtn');
        var closeBtn = document.getElementById('deleteConfirmClose');
        if (!modal || !confirmBtn) return;
        var pendingForm = null;

        document.querySelectorAll('.archive-flash').forEach(function(el) {
            setTimeout(function() {
                el.style.transition = 'opacity 0.4s';
                el.style.opacity = '0';
                setTimeout(function() {
                    el.remove();
                }, 400);
            }, 4000);
        });

        function openConfirmModal(form, opts) {
            pendingForm = form;
            if (titleEl) titleEl.textContent = opts.title;
            msg.textContent = opts.message;
            confirmBtn.textContent = opts.confirmLabel;
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            if (confirmBtn.focus) confirmBtn.focus();
        }

        function closeConfirmModal() {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            pendingForm = null;
        }

        document.querySelectorAll('.js-delete-doc').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var form = btn.closest('form');
                var name = btn.getAttribute('data-delete-name');
                if (form) openConfirmModal(form, {
                    title: 'Confirm Deletion',
                    message: name ?
                        'Are you sure you want to delete "' + name + '"? This action cannot be undone.' :
                        'Are you sure you want to delete this document? This action cannot be undone.',
                    confirmLabel: 'Confirm Delete'
                });
            });
        });

        document.querySelectorAll('.js-archive-doc').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var form = btn.closest('form');
                var name = btn.getAttribute('data-archive-name');
                if (form) openConfirmModal(form, {
                    title: 'Confirm Archive',
                    message: name ?
                        'Archive "' + name + '"? It will be moved to the archive and removed from the active repository.' :
                        'Archive this document? It will be moved to the archive and removed from the active repository.',
                    confirmLabel: 'Confirm Archive'
                });
            });
        });

        confirmBtn.addEventListener('click', function() {
            if (pendingForm) {
                var form = pendingForm;
                pendingForm = null;
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                form.submit();
            }
        });

        if (cancelBtn) cancelBtn.addEventListener('click', closeConfirmModal);
        if (closeBtn) closeBtn.addEventListener('click', closeConfirmModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeConfirmModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) closeConfirmModal();
        });
    })();
</script>

<div class="modal-overlay" id="successModal" aria-hidden="true">
    <div class="modal-box" role="status" aria-live="polite">
        <div class="toast-row">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            <span id="successMsg">Done.</span>
            <button type="button" class="toast-dismiss" data-csp-action="closeDocSuccessToast" aria-label="Dismiss">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>
    </div>
</div>
<script nonce="<?= csp_nonce() ?>">
    var __docSuccessTimer = null;

    function closeDocSuccessToast() {
        var m = document.getElementById('successModal');
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
        clearTimeout(__docSuccessTimer);
    }
    <?php if ($flashMsg): ?>
        document.getElementById('successMsg').textContent = <?= json_encode($flashMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.getElementById('successModal').classList.add('active');
        document.getElementById('successModal').setAttribute('aria-hidden', 'false');
        __docSuccessTimer = setTimeout(closeDocSuccessToast, 2600);
    <?php endif; ?>
</script>
<?php include 'includes/footer.php'; ?>
