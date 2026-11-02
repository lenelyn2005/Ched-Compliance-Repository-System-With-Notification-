<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

$flashMsg = trim($_GET['msg'] ?? '');
$flashError = trim($_GET['error'] ?? '');

function build_share_url(string $rawToken): string
{
    $path = '/ched_share_portal.php?t=' . $rawToken;

    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/') . $path;
    }

    $xfp = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    if ($xfp === 'https' || $xfp === 'http') {
        $scheme = $xfp;
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    $hostHeader = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = trim(explode(',', $hostHeader)[0]);

    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($dir === '/' || $dir === '.') $dir = '';

    return $scheme . '://' . $host . $dir . $path;
}

/* AJAX detection + JSON response helpers (used by share-link endpoints). */
function wants_json(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

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
              AND d.folder_id IS NULL
        ");
        $directStmt->execute([$deptId, $req['category'], $req['document_type']]);
        foreach ($directStmt->fetchAll() as $doc) {
            $documents[$doc['id'] . '_document'] = $doc;
        }
    }
    // shortcut: pickup docs with admin-set folder override for this folder.
    if ($folderId > 0) {
        $pickupStmt = $pdo->prepare("SELECT d.*, 'document' as source FROM documents d WHERE d.department_id = ? AND d.teacher_requirement_id IS NULL AND d.status = 'approved_admin' AND d.archived = 0 AND d.folder_id = ? ORDER BY d.upload_date DESC");
        $pickupStmt->execute([$deptId, $folderId]);
        foreach ($pickupStmt->fetchAll() as $doc) {
            $documents[$doc['id'] . '_document'] = $doc;
        }
    }
    return array_values($documents);
}

// shortcut: uses existing getDocumentsForFolder logic (respects archive/visibility rules); upgrade to a single count query for larger trees.
function folderFileCount($pdo, int $deptId, int $folderId): int
{
    return count(getDocumentsForFolder($pdo, $deptId, $folderId));
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
              AND d.folder_id IS NULL
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
          AND (d.folder_id IS NULL OR d.folder_id = 0)
    ");
    $phDirectStmt->execute([$deptId]);
    foreach ($phDirectStmt->fetchAll() as $doc) {
        $documents[$doc['id'] . '_document'] = $doc;
    }

    // shortcut: pickup direct-upload docs explicitly moved to repository root.
    $rootPickupStmt = $pdo->prepare("SELECT d.*, 'document' as source FROM documents d WHERE d.department_id = ? AND d.teacher_requirement_id IS NULL AND d.status = 'approved_admin' AND d.archived = 0 AND d.folder_id = 0 ORDER BY d.upload_date DESC");
    $rootPickupStmt->execute([$deptId]);
    foreach ($rootPickupStmt->fetchAll() as $doc) {
        $documents[$doc['id'] . '_document'] = $doc;
    }

    return array_values($documents);
}

function zipEntryName(string $name): string
{
    return preg_replace('#[/\\\\]+#', '_', str_replace("\0", '', $name));
}

function archiveMoveDocument(PDO $pdo, string $table, int $docId, string $oldRel, string $newRel): bool
{
    if (STORAGE_DRIVER === 'r2') {
        try {
            $hasFile = storageMoveObject($oldRel, $newRel);
        } catch (StorageException $e) {
            error_log("[CHED] archive move failed for $table#$docId: " . get_class($e));
            return false;
        }
        $pdo->prepare("UPDATE `$table` SET file_path = ?, archived = 1 WHERE id = ?")
            ->execute([$hasFile ? $newRel : $oldRel, $docId]);
        return true;
    }
    // Local driver is disabled in production (APP_ENV=production requires STORAGE_DRIVER=r2).
    // For local development only, keep the legacy rename logic but log a warning.
    error_log("[CHED] archiveMoveDocument called with local driver; moving to local path is deprecated. Use R2 storage.");
    $oldPath      = securePath($oldRel);
    $newFullPath  = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRel);
    if (!is_dir(dirname($newFullPath))) {
        mkdir(dirname($newFullPath), 0755, true);
    }
    $hasFile = $oldPath && file_exists($oldPath);
    if (!$hasFile || rename($oldPath, $newFullPath)) {
        $pdo->prepare("UPDATE `$table` SET file_path = ?, archived = 1 WHERE id = ?")
            ->execute([$hasFile ? $newRel : $oldRel, $docId]);
        return true;
    }
    error_log("[CHED] archive move failed for $table#$docId: " . $oldPath);
    return false;
}

$GLOBALS['zip_staged_files'] = [];

/**
 * True move, not copy.
 *
 * - Locks the document row with SELECT … FOR UPDATE inside a transaction so
 *   concurrent drag-drop retries cannot create inconsistent records.
 * - Idempotent: if the document is already in the target folder, returns
 *   ok+noop without touching R2 or the database.
 * - Verifies the destination R2 object before committing the DB update.
 * - If the DB commit fails after the R2 move, attempts to reverse the R2 move.
 * - Never creates a second documents record; only folder_id / file_path change.
 */
function moveDocumentToFolder(PDO $pdo, int $docId, int $targetFolderId): array
{
    $startedTx = false;
    $moved     = false;
    $currentRel = null;
    $newRel     = null;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        // Lock the row so concurrent moves serialize.
        $stmt = $pdo->prepare(
            'SELECT id, department_id, file_path, folder_id
               FROM documents
              WHERE id = ?
                AND teacher_requirement_id IS NULL
                AND status = ?
                AND archived = 0
              FOR UPDATE'
        );
        $stmt->execute([$docId, 'approved_admin']);
        $doc = $stmt->fetch();

        if (!$doc) {
            if ($startedTx) $pdo->rollBack();
            return [
                'ok'    => false,
                'error' => 'Document not eligible for movement (must be Direct Upload / approved_admin / unarchived).',
            ];
        }

        $deptId = (int)$doc['department_id'];

        // Validate destination folder belongs to same department.
        if ($targetFolderId > 0) {
            $fstmt = $pdo->prepare('SELECT id, department_id FROM department_folders WHERE id = ?');
            $fstmt->execute([$targetFolderId]);
            $folder = $fstmt->fetch();
            if (!$folder || (int)$folder['department_id'] !== $deptId) {
                if ($startedTx) $pdo->rollBack();
                return [
                    'ok'    => false,
                    'error' => 'Destination folder not found or does not belong to this document\'s department.',
                ];
            }
        }

        $currentFolderId = ($doc['folder_id'] !== null && $doc['folder_id'] !== '')
            ? (int)$doc['folder_id']
            : 0;

        // Idempotent: same-folder drop (retry / repeat) is a safe no-op.
        if ($currentFolderId === $targetFolderId) {
            if ($startedTx) $pdo->rollBack();
            return [
                'ok'      => true,
                'noop'    => true,
                'message' => 'Document is already in this folder.',
            ];
        }

        $currentRel  = str_replace('\\', '/', (string)($doc['file_path'] ?? ''));
        $currentName = basename($currentRel);
        if ($currentName === '' || $currentName === '.') {
            if ($startedTx) $pdo->rollBack();
            return [
                'ok'    => false,
                'error' => 'Document file path is missing or invalid.',
            ];
        }

        $newRel = storageSubdir('admin_repository', $pdo, $deptId, $targetFolderId ?: null) . '/' . $currentName;
        $newRel = rtrim(str_replace('\\', '/', $newRel), '/');

        // Perform R2 move while row is locked; no DB change yet.
        if ($currentRel !== '' && $currentRel !== $newRel) {
            try {
                $moved = storageMoveObject($currentRel, $newRel);
            } catch (StorageException | InvalidArgumentException $e) {
                if ($startedTx) $pdo->rollBack();
                error_log('[CHED] document move R2 error doc#' . $docId . ': ' . get_class($e));
                return [
                    'ok'    => false,
                    'error' => 'Storage move failed: ' . $e->getMessage(),
                ];
            }
        }

        $filePathUpdate = ($moved && $newRel !== $currentRel) ? $newRel : $currentRel;

        // Single UPDATE on the SAME row — true move, not copy.
        $upd = $pdo->prepare('UPDATE documents SET folder_id = ?, file_path = ? WHERE id = ?');
        $upd->execute([$targetFolderId > 0 ? $targetFolderId : null, $filePathUpdate, $docId]);

        if ($startedTx) $pdo->commit();

        $folderName = '';
        if ($targetFolderId > 0) {
            $fstmt2 = $pdo->prepare('SELECT folder_name FROM department_folders WHERE id = ?');
            $fstmt2->execute([$targetFolderId]);
            $folderName = $fstmt2->fetchColumn() ?: 'folder ' . $targetFolderId;
        }
        $destinationName = $folderName ? 'folder "' . $folderName . '"' : 'repository root';
        logAudit(
            $pdo,
            'move_document',
            'Moved document #' . $docId . ' ("' . basename($currentRel) . '") to ' . $destinationName,
            'document',
            $docId
        );

        return [
            'ok'        => true,
            'message'   => 'Document moved to ' . $destinationName . '.',
            'folder_id' => $targetFolderId,
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (\Throwable $rb) {}
        }
        // Best-effort reverse if the R2 move already happened.
        if ($moved && $currentRel && $newRel && $newRel !== $currentRel) {
            try {
                storageMoveObject($newRel, $currentRel);
            } catch (\Throwable $rev) {
                error_log('[CHED] document move reverse after error doc#' . $docId . ': ' . get_class($rev));
            }
        }
        error_log('[CHED] document move error doc#' . $docId . ': ' . $e->getMessage());
        return [
            'ok'    => false,
            'error' => 'Document placement could not be saved. Original file kept intact.',
        ];
    }
}

function stageFileForZip($zip, ?string $relativePath, string $entryName): void
{
    if (empty($relativePath)) return;
    $staged = storageStageLocalCopy((string) $relativePath);
    if ($staged === null) return;
    $GLOBALS['zip_staged_files'][] = $staged;
    $zip->addFile($staged, $entryName);
}

function addFolderToZip($zip, $folderData, $currentPath)
{
    foreach ($folderData['documents'] as $doc) {
        stageFileForZip($zip, $doc['file_path'], $currentPath . '/' . zipEntryName($doc['filename']));
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
    try {
        foreach ($rootDocs as $doc) {
            stageFileForZip($zip, $doc['file_path'], $basePath . '/' . zipEntryName($doc['filename']));
        }

        foreach ($subfolders as $folder) {
            $folderPath = $basePath . '/' . zipEntryName($folder['name']);
            $zip->addEmptyDir($folderPath);
            addFolderToZip($zip, $folder, $folderPath);
        }

        $zip->close();
    } catch (StorageException $e) {
        error_log('[CHED] department ZIP blocked, storage unavailable: ' . get_class($e));
        foreach ($GLOBALS['zip_staged_files'] as $t) @unlink($t);
        @$zip->close();
        @unlink($zipName);
        http_response_code(503);
        die('Storage temporarily unavailable. Please try again.');
    }
    foreach ($GLOBALS['zip_staged_files'] as $t) {
        @unlink($t);
    }
    $GLOBALS['zip_staged_files'] = [];

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
    try {
        addFolderToZip($zip, $tree, $basePath);
        $zip->close();
    } catch (StorageException $e) {
        error_log('[CHED] folder ZIP blocked, storage unavailable: ' . get_class($e));
        foreach ($GLOBALS['zip_staged_files'] as $t) @unlink($t);
        @$zip->close();
        @unlink($zipName);
        http_response_code(503);
        die('Storage temporarily unavailable. Please try again.');
    }
    foreach ($GLOBALS['zip_staged_files'] as $t) {
        @unlink($t);
    }
    $GLOBALS['zip_staged_files'] = [];

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $folderName . '.zip"');
    header('Content-Length: ' . filesize($zipName));
    readfile($zipName);
    unlink($zipName);
    exit;
}

// -----------------------------------------------------------------------------
// Move Direct Upload Document (drag-and-drop, AJAX; no separate Move UI)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_document']) && is_numeric($_POST['move_document'])) {
    requireCsrfToken();
    $docId = (int) $_POST['move_document'];
    $targetFolderId = isset($_POST['target_folder_id']) && $_POST['target_folder_id'] !== '' ? (int)$_POST['target_folder_id'] : 0;
    $deptId = (int)($_POST['dept_id'] ?? 0);
    $folderId = (int)($_POST['folder_id'] ?? 0);

    $result = moveDocumentToFolder($pdo, $docId, $targetFolderId);
    if (wants_json()) {
        http_response_code($result['ok'] ? 200 : 400);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    $redirect = 'admin_manage_document.php?dept=' . $deptId . '&folder=' . $folderId;
    if ($result['ok']) {
        $redirect .= $result['noop'] ? '&msg=' . urlencode('Document already in selected folder.') : '&msg=' . urlencode($result['message']);
    } else {
        $redirect .= '&error=' . urlencode($result['error']);
    }
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && is_numeric($_POST['delete'])) {
    requireCsrfToken();
    $docId = (int) $_POST['delete'];
    $source = $_POST['source'] ?? 'document';

    if ($source === 'document') {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $newRelativePath = storageSubdir('admin_archive', $pdo, (int) $doc['department_id'], documentFolderId($pdo, 'documents', $docId)) . '/' . basename($doc['file_path']);
            if (archiveMoveDocument($pdo, 'documents', $docId, (string) $doc['file_path'], $newRelativePath)) {
                logAudit($pdo, 'delete_document', 'Soft-deleted document (moved to archive): ' . $doc['title'], 'document', $docId);
            }
        }
    } elseif ($source === 'ph_document') {
        $stmt = $pdo->prepare('SELECT * FROM ph_documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $newRelativePath = 'admin_archive/my_repository/' . basename($doc['file_path']);
            if (archiveMoveDocument($pdo, 'ph_documents', $docId, (string) $doc['file_path'], $newRelativePath)) {
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
            $newRelativePath = 'admin_archive/my_repository/' . basename($doc['file_path']);
            if (archiveMoveDocument($pdo, 'ph_documents', $archiveId, (string) $doc['file_path'], $newRelativePath)) {
                logAudit($pdo, 'manual_archive', 'Manually archived PH document: ' . $doc['title'], 'ph_document', $archiveId);
                $archiveSuccess = true;
            }
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND archived = 0 AND status = "approved_admin"');
        $stmt->execute([$archiveId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $newRelativePath = storageSubdir('admin_archive', $pdo, (int) $doc['department_id'], documentFolderId($pdo, 'documents', $archiveId)) . '/' . basename($doc['file_path']);
            if (archiveMoveDocument($pdo, 'documents', $archiveId, (string) $doc['file_path'], $newRelativePath)) {
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
// CHED Share Link: create (all departments)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_share_link'])) {
    requireCsrfToken();

    $maxRaw = $_POST['share_max_visitors'] ?? '5';
    if ($maxRaw === 'unlimited') {
        $maxVisitors = null;
    } elseif ($maxRaw === 'custom') {
        $custom = (int)($_POST['share_custom_max'] ?? 0);
        if ($custom < 1 || $custom > 100000) {
            header('Location: admin_manage_document.php?error=' . urlencode('Custom visitor limit must be between 1 and 100000.'));
            exit;
        }
        $maxVisitors = $custom;
    } else {
        $maxVisitors = (int)$maxRaw;
        if ($maxVisitors < 1) {
            header('Location: admin_manage_document.php?error=' . urlencode('Invalid visitor limit.'));
            exit;
        }
    }

    $expRaw = trim($_POST['share_expires_at'] ?? '');
    try {
        if ($expRaw === '') throw new RuntimeException('empty');
        $local  = new DateTime($expRaw, new DateTimeZone(date_default_timezone_get()));
        $utc    = clone $local;
        $utc->setTimezone(new DateTimeZone('UTC'));
        $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
        if ($utc <= $nowUtc) throw new RuntimeException('past');
    } catch (Throwable $e) {
        header('Location: admin_manage_document.php?error=' . urlencode('Expiration must be a future date/time.'));
        exit;
    }

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $adminId   = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

    try {
        $ins = $pdo->prepare("
            INSERT INTO ched_share_links
              (token_hash, token_plain, department_id, max_visitors, visitor_count,
               expires_at, status, created_by, created_at)
            VALUES (?, ?, NULL, ?, 0, ?, 'active', ?, UTC_TIMESTAMP())
        ");
        $ins->execute([$tokenHash, $rawToken, $maxVisitors, $utc->format('Y-m-d H:i:s'), $adminId]);
        $newId = (int)$pdo->lastInsertId();

        $url = build_share_url($rawToken);

        $_SESSION['ched_new_share'] = [
            'url'     => $url,
            'expires' => $utc->format('Y-m-d H:i:s'),
            'max'     => $maxVisitors,
            'link_id' => $newId,
        ];

        logAudit($pdo, 'create_share_link',
            "Created CHED share link #{$newId} (ALL departments, max " . ($maxVisitors ?? '∞') . ")",
            'ched_share_link', $newId);

        header('Location: admin_manage_document.php');
        exit;
    } catch (Throwable $e) {
        error_log('[CHED share] create failed: ' . $e->getMessage());
        header('Location: admin_manage_document.php?error=' . urlencode('Failed to create share link. Check the error log.'));
        exit;
    }
}

// =============================================================================
// CHED Share Link: revoke (only allowed for non-revoked links)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_share_link'])) {
    requireCsrfToken();
    $linkId  = (int)$_POST['revoke_share_link'];
    $adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

    if ($linkId <= 0) {
        if (wants_json()) json_response(400, ['ok' => false, 'error' => 'Invalid link ID.']);
        header('Location: admin_manage_document.php?error=' . urlencode('Invalid link ID.'));
        exit;
    }

    try {
        $upd = $pdo->prepare("
            UPDATE ched_share_links
               SET status='revoked', revoked_at=UTC_TIMESTAMP(), revoked_by=?
             WHERE id=? AND status IN ('active','full','expired')
        ");
        $upd->execute([$adminId, $linkId]);
        if ($upd->rowCount() > 0) {
            logAudit($pdo, 'revoke_share_link',
                "Revoked CHED share link #{$linkId}", 'ched_share_link', $linkId);
            if (wants_json()) {
                json_response(200, ['ok' => true, 'id' => $linkId, 'status' => 'revoked']);
            }
            header('Location: admin_manage_document.php?msg=' . urlencode('Share link revoked.'));
        } else {
            if (wants_json()) {
                json_response(404, ['ok' => false, 'error' => 'Link not found or already revoked.']);
            }
            header('Location: admin_manage_document.php?error=' . urlencode('Link not found or already revoked.'));
        }
    } catch (Throwable $e) {
        error_log('[CHED share] revoke failed: ' . $e->getMessage());
        if (wants_json()) {
            json_response(500, ['ok' => false, 'error' => 'Failed to revoke link.']);
        }
        header('Location: admin_manage_document.php?error=' . urlencode('Failed to revoke link.'));
    }
    exit;
}

// =============================================================================
// CHED Share Link: remove (only allowed for revoked links)
// Permanently deletes the share-link record. The audit trail is preserved
// in the audit log (create/revoke/remove events are all logged). Underlying
// compliance documents stored in R2 are never touched.
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_share_link'])) {
    requireCsrfToken();
    $linkId  = (int)$_POST['remove_share_link'];
    $adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

    if ($linkId <= 0) {
        if (wants_json()) json_response(400, ['ok' => false, 'error' => 'Invalid link ID.']);
        header('Location: admin_manage_document.php?error=' . urlencode('Invalid link ID.'));
        exit;
    }

    try {
        $sel = $pdo->prepare("SELECT id, status FROM ched_share_links WHERE id = ?");
        $sel->execute([$linkId]);
        $row = $sel->fetch();

        if (!$row) {
            if (wants_json()) json_response(404, ['ok' => false, 'error' => 'Link not found.']);
            header('Location: admin_manage_document.php?error=' . urlencode('Link not found.'));
            exit;
        }
        if ($row['status'] !== 'revoked') {
            if (wants_json()) {
                json_response(403, ['ok' => false, 'error' => 'Only revoked links can be removed.']);
            }
            header('Location: admin_manage_document.php?error=' . urlencode('Only revoked links can be removed.'));
            exit;
        }

        $del = $pdo->prepare("DELETE FROM ched_share_links WHERE id = ? AND status = 'revoked'");
        $del->execute([$linkId]);
        if ($del->rowCount() > 0) {
            logAudit($pdo, 'remove_share_link',
                "Removed revoked CHED share link #{$linkId} from list",
                'ched_share_link', $linkId);
            if (wants_json()) {
                json_response(200, ['ok' => true, 'id' => $linkId]);
            }
            header('Location: admin_manage_document.php?msg=' . urlencode('Share link removed.'));
        } else {
            if (wants_json()) {
                json_response(500, ['ok' => false, 'error' => 'Failed to remove link.']);
            }
            header('Location: admin_manage_document.php?error=' . urlencode('Failed to remove link.'));
        }
    } catch (Throwable $e) {
        error_log('[CHED share] remove failed: ' . $e->getMessage());
        if (wants_json()) {
            json_response(500, ['ok' => false, 'error' => 'Failed to remove link.']);
        }
        header('Location: admin_manage_document.php?error=' . urlencode('Failed to remove link.'));
    }
    exit;
}

$departments = $pdo->query("
    SELECT MIN(id) as id, department_name, department_code
    FROM departments
    GROUP BY department_name
    ORDER BY department_name
")->fetchAll();

$selectedDeptId = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$selectedFolderId = isset($_GET['folder']) ? (int)$_GET['folder'] : 0;
$showCards = ($selectedDeptId == 0);
$breadcrumb = [];
$folderContents = [];
$selectedDeptName = '';

// Share-link data (loaded for the all-departments view)
$shareLinks = [];
$newShare = $_SESSION['ched_new_share'] ?? null;
if ($newShare) {
    unset($_SESSION['ched_new_share']);
}
if ($showCards) {
    try {
        // Latch stale rows whenever the admin views the list, so stored status
        // reflects expiration without waiting for share-portal traffic.
        $pdo->query("UPDATE ched_share_links SET status='expired' WHERE status='active' AND expires_at <= UTC_TIMESTAMP()");
        $slStmt = $pdo->query("
            SELECT sl.*, u.name AS creator_name
            FROM ched_share_links sl
            LEFT JOIN users u ON u.id = sl.created_by
            ORDER BY sl.created_at DESC
            LIMIT 100
        ");
        $shareLinks = $slStmt->fetchAll();
    } catch (Throwable $e) {
        $shareLinks = [];
        error_log('[CHED share] list failed: ' . $e->getMessage());
    }
}

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
                  AND d.folder_id IS NULL
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
            'file_count' => folderFileCount($pdo, $selectedDeptId, $sf['id']),
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
                'file_exists'       => storageFilePresent((string)($doc['file_path'] ?? '')),
                'file_type'         => $fileType,
                'upload_date'       => $doc['upload_date'],
                'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
                'status'            => $doc['status'],
                'archived'          => $doc['archived'],
                'is_folder'         => false,
                'requirement_name'  => $requirementName,
                'teacher_requirement_id' => $doc['teacher_requirement_id'] ?? null,
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
                'file_exists'       => storageFilePresent((string)($doc['file_path'] ?? '')),
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
        // Only show PH Direct Uploads that have NOT been moved into a folder.
        // This prevents a moved document from appearing here AND in the
        // destination folder (no duplication after drag-and-drop).
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
              AND (d.folder_id IS NULL OR d.folder_id = 0)
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
                'file_exists'       => storageFilePresent((string)($doc['file_path'] ?? '')),
                'file_type'         => $fileType,
                'upload_date'       => $doc['upload_date'],
                'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
                'status'            => $doc['status'],
                'archived'          => $doc['archived'] ?? 0,
                'is_folder'         => false,
                'teacher_requirement_id' => null,
            ];
        }
    }

    // shortcut: pickup docs with explicit folder override (drag-and-drop placement).
    $folderIdForPickup = ($folderIdForReqs > 0) ? $folderIdForReqs : 0;
    $pickupStmt = $pdo->prepare("
        SELECT d.*, u.name as uploaded_by_name FROM documents d
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE d.department_id = ?
          AND d.teacher_requirement_id IS NULL
          AND d.status = 'approved_admin'
          AND d.archived = 0
          AND d.folder_id = ?
        ORDER BY d.upload_date DESC
    ");
    $pickupStmt->execute([$selectedDeptId, $folderIdForPickup]);
    $pickupDocs = $pickupStmt->fetchAll();
    foreach ($pickupDocs as $doc) {
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
            'file_exists'       => storageFilePresent((string)($doc['file_path'] ?? '')),
            'file_type'         => $fileType,
            'upload_date'       => $doc['upload_date'],
            'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
            'status'            => $doc['status'],
            'archived'          => $doc['archived'],
            'is_folder'         => false,
            'requirement_name'  => '',
            'teacher_requirement_id' => $doc['teacher_requirement_id'] ?? null,
        ];
    }

    usort($folderContents, function ($a, $b) {
        if ($a['is_folder'] && !$b['is_folder']) return -1;
        if (!$a['is_folder'] && $b['is_folder']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
}

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

    .repo-head-right {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
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

    .btn-outline.is-copied {
        background: #ecfdf5;
        border-color: #a7f3d0;
        color: #065f46;
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

    .btn-primary {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
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

    .breadcrumb-nav a.drag-over {
        background: #e0f2fe;
        color: #0369a1;
        box-shadow: inset 0 0 0 2px #38bdf8;
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

    /* =========================================
       DEPARTMENT CARDS (outer - untouched from original)
       ========================================= */
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
        font-family: inherit;
        cursor: pointer;
    }

    .dept-card .zip-btn:hover {
        border-color: var(--primary);
        background: var(--primary);
        color: white;
    }

    /* =========================================
       NEW INNER FOLDER CONTENT STYLES (Screenshot)
       ========================================= */
    .folder-contents-wrap {
        background: #FDFCF8;
        border-radius: 16px;
        padding: 1.5rem;
        margin: 0 -1.75rem -1.75rem -1.75rem;
    }

    .folder-list-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .folder-item-card {
        display: flex;
        align-items: center;
        background: #ffffff;
        border: 1px solid #E8E4D9;
        border-radius: 12px;
        height: 76px;
        transition: border-color 0.18s ease, box-shadow 0.18s ease;
        text-decoration: none;
        color: inherit;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        cursor: pointer;
        position: relative;
    }

    .folder-item-card:hover {
        box-shadow: 0 4px 12px -4px rgba(0, 0, 0, 0.08);
        border-color: #D6D0C4;
    }

    .folder-item-card .folder-link-area {
        display: flex;
        align-items: center;
        flex: 1;
        height: 100%;
        padding: 0 1rem;
        gap: 1rem;
    }

    .folder-item-card .folder-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        flex-shrink: 0;
    }

    .folder-item-card .folder-icon svg {
        width: 100%;
        height: 100%;
    }

    .folder-item-card .folder-info-group {
        display: flex;
        flex-direction: column;
        justify-content: center;
        flex: 1;
        min-width: 0;
    }

    .folder-item-card .folder-link-area {
        display: flex;
        align-items: center;
        flex: 1;
        height: 100%;
        padding: 0 1rem;
        gap: 1rem;
        text-decoration: none; /* ADDED THIS LINE */
    }

    .folder-item-card .folder-name {
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.3;
        margin: 0;
        color: #333;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        text-decoration: none; /* ADDED THIS LINE */
    }

    .folder-item-card .folder-count {
        font-size: 0.75rem;
        color: #888;
        font-weight: 400;
        margin-top: 0.15rem;
        text-decoration: none; /* ADDED THIS LINE */
    }

    .folder-item-card .folder-arrow {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        color: #BBB;
        flex-shrink: 0;
        transition: color 0.18s ease, transform 0.18s ease;
    }

    .folder-item-card:hover .folder-arrow {
        color: #555;
        transform: translateX(2px);
    }

    .folder-item-card .folder-zip-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        margin-right: 1rem;
        background: #F9F8F4;
        border: 1px solid #EEEBE2;
        border-radius: 8px;
        text-decoration: none;
        color: #888;
        transition: all 0.18s ease;
        flex-shrink: 0;
    }

    .folder-item-card .folder-zip-btn:hover {
        border-color: #D6D0C4;
        background: #ffffff;
        color: #333;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .files-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1rem;
    }

    .file-card {
        display: flex;
        align-items: center;
        background: #ffffff;
        border: 1px solid #E8E4D9;
        border-radius: 12px;
        padding: 12px 16px;
        gap: 14px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        transition: box-shadow 0.18s ease, border-color 0.18s ease;
        position: relative;
    }

    .file-card:hover {
        box-shadow: 0 4px 12px -4px rgba(0, 0, 0, 0.08);
        border-color: #D6D0C4;
    }

    .file-card .icon-wrapper {
        width: 44px;
        height: 44px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .file-card .icon-wrapper svg {
        width: 100%;
        height: 100%;
    }

    .file-card .file-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }

    .file-card .file-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: #333;
        margin: 0 0 4px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
    }

    .file-card .file-meta {
        font-size: 0.75rem;
        color: #888;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .file-card .file-meta span {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .file-card .file-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: all 0.18s ease;
        text-decoration: none;
    }

    .action-btn.preview-btn {
        background: transparent;
        color: #555;
        border: 1px solid #EEEBE2;
    }

    .action-btn.preview-btn:hover {
        background: #F9F8F4;
        color: #333;
    }

    .action-btn.download-btn {
        background: #1A1A1A;
        color: #FFF;
    }

    .action-btn.download-btn:hover {
        background: #333;
    }

    .action-btn.delete-btn {
        background: transparent;
        color: #ef4444;
        border: 1px solid #fee2e2;
    }

    .action-btn.delete-btn:hover {
        background: #fef2f2;
    }

    .action-btn.archive-btn {
        background: transparent;
        color: #f59e0b;
        border: 1px solid #fef3c7;
    }

    .action-btn.archive-btn:hover {
        background: #fffbeb;
    }

    .action-btn.disabled {
        opacity: 0.3;
        cursor: not-allowed;
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

    .content-item .actions form {
        display: inline-flex;
        margin: 0;
    }

    .empty-state {
        text-align: center;
        padding: 3.5rem 1.5rem;
        color: var(--gray-500);
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        background: #fafbfc;
        grid-column: 1 / -1;
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

    .modal-overlay#createShareModal .modal-box {
        max-width: 500px;
    }

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

    .form-label {
        display: block;
        font-size: 0.82rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 0.35rem;
    }

    .form-control {
        width: 100%;
        padding: 0.55rem 0.7rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.88rem;
        color: var(--gray-800);
        background: #fff;
        margin-bottom: 0.75rem;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
    }

    .scope-card {
        padding: 0.75rem 1rem;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        border-radius: 10px;
        margin-bottom: 1rem;
    }

    .scope-card .scope-label {
        font-size: 0.82rem;
        font-weight: 700;
        color: #4338ca;
    }

    .scope-card .scope-value {
        font-size: 0.88rem;
        color: #334155;
        margin-top: 0.15rem;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.14rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.66rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .share-links-panel {
        margin-top: 2rem;
        padding: 1.25rem 1.5rem;
        background: #fafbff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
    }

    .share-links-panel h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--gray-800);
        margin: 0 0 0.75rem;
    }

    .content-item[draggable="true"] {
        cursor: grab;
    }

    .content-item.dragging {
        opacity: 0.45;
    }

    .content-item.folder-item.drag-over {
        background: #e0f2fe;
        box-shadow: inset 0 0 0 2px #38bdf8;
        border-color: #38bdf8;
    }

    .drag-ghost {
        pointer-events: none;
        background: var(--primary);
        color: #fff;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.8rem;
        position: fixed;
        top: -100px;
        left: -100px;
        z-index: 9999;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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
        .folder-list-grid {
            grid-template-columns: repeat(2, 1fr);
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
        .folder-contents-wrap {
            padding: 1rem;
            margin: 0 -1.25rem -1.25rem -1.25rem;
        }
        .folder-list-grid {
            grid-template-columns: 1fr;
        }
        .files-grid {
            grid-template-columns: 1fr;
        }
        .file-card {
            padding: 10px 12px;
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
                    <div class="repo-head-sub">Browse, preview, download, archive, or delete approved documents. Drag a Direct Upload document onto a folder to move it.</div>
                </div>
            </div>
            <div class="repo-head-right">
                <?php if ($showCards): ?>
                    <button type="button" class="btn btn-primary" id="openShareFromHeader">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="18" cy="5" r="3" />
                            <circle cx="6" cy="12" r="3" />
                            <circle cx="18" cy="19" r="3" />
                            <line x1="8.6" y1="13.5" x2="15.4" y2="17.5" />
                            <line x1="15.4" y1="6.5" x2="8.6" y2="10.5" />
                        </svg>
                        Share Repository
                    </button>
                <?php endif; ?>
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

                <?php if (!empty($shareLinks)): ?>
                <div class="share-links-panel" id="shareLinksPanel">
                    <h3>Active share links (all departments)</h3>
                    <div class="content-list" id="shareLinksList">
                        <?php foreach ($shareLinks as $sl):
                            $status = $sl['status'];
                            $expUtc = new DateTime($sl['expires_at'], new DateTimeZone('UTC'));
                            if ($status === 'active' && $expUtc <= new DateTime('now', new DateTimeZone('UTC'))) {
                                $status = 'expired';
                            }
                            if ($status === 'active' && $sl['max_visitors'] !== null
                                && (int)$sl['visitor_count'] >= (int)$sl['max_visitors']) {
                                $status = 'full';
                            }
                            $badgeMap = [
                                'active'  => ['#ecfdf5', '#065f46', '#a7f3d0'],
                                'full'    => ['#fffbeb', '#92400e', '#fde68a'],
                                'expired' => ['#f3f4f6', '#4b5563', '#d1d5db'],
                                'revoked' => ['#fef2f2', '#b91c1c', '#fecaca'],
                            ];
                            [$bg, $fg, $bd] = $badgeMap[$status] ?? $badgeMap['expired'];
                            $local = clone $expUtc;
                            $local->setTimezone(new DateTimeZone(date_default_timezone_get()));
                            $maxLabel = $sl['max_visitors'] === null ? 'Unlimited' : (string)(int)$sl['max_visitors'];
                            $scopeLabel = $sl['department_id'] === null ? 'All departments' : 'Department #' . (int)$sl['department_id'];
                            $isRevoked = ($sl['status'] === 'revoked');
                            $shareUrl = '';
                            if (!$isRevoked && !empty($sl['token_plain'])) {
                                try {
                                    $shareUrl = build_share_url((string)$sl['token_plain']);
                                } catch (Throwable $e) {
                                    $shareUrl = '';
                                }
                            }
                        ?>
                            <div class="content-item" data-link-id="<?= (int)$sl['id'] ?>" data-status="<?= sanitize($sl['status']) ?>" style="padding:.8rem 1rem">
                                <div class="info">
                                    <div class="name" style="gap:.5rem">
                                        <span class="status-pill" style="background:<?= $bg ?>;color:<?= $fg ?>;border:1px solid <?= $bd ?>">
                                            <?= sanitize($status) ?>
                                        </span>
                                        <span style="font-family:ui-monospace,monospace;font-size:.78rem;color:#475569">
                                            #<?= (int)$sl['id'] ?> · <?= substr($sl['token_hash'], 0, 8) ?>…
                                        </span>
                                        <span style="font-size:.72rem;color:#64748b;font-weight:500">
                                            <?= sanitize($scopeLabel) ?>
                                        </span>
                                    </div>
                                    <div class="meta">
                                        <span>Visitors: <?= (int)$sl['visitor_count'] ?> / <?= sanitize($maxLabel) ?></span>
                                        <span>Expires: <?= sanitize($local->format('M d, Y g:i A')) ?></span>
                                        <span>By: <?= sanitize($sl['creator_name'] ?: '—') ?></span>
                                    </div>
                                </div>
                                <div class="actions" style="opacity:1">
                                    <?php if ($isRevoked): ?>
                                        <form method="POST" action="admin_manage_document.php" class="js-remove-form" data-link-id="<?= (int)$sl['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                            <input type="hidden" name="remove_share_link" value="<?= (int)$sl['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                        </form>
                                    <?php else: ?>
                                        <?php if ($shareUrl !== ''): ?>
                                            <button type="button" class="btn btn-sm btn-outline js-copy-link" data-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>" title="Copy share URL">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                                                </svg>
                                                <span>Copy Link</span>
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" action="admin_manage_document.php" class="js-revoke-form" data-link-id="<?= (int)$sl['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                            <input type="hidden" name="revoke_share_link" value="<?= (int)$sl['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
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
                        <a href="admin_manage_document.php?dept=<?= $selectedDeptId ?>&folder=0"
                           data-drop-target-folder="0"><?= sanitize($selectedDeptName) ?></a>
                        <?php
                        $total = count($breadcrumb);
                        foreach ($breadcrumb as $index => $folder):
                            $isLast = ($index === $total - 1);
                        ?>
                            <span class="separator">/</span>
                            <?php if ($isLast): ?>
                                <span class="current"><?= sanitize($folder['name']) ?></span>
                            <?php else: ?>
                                <a href="admin_manage_document.php?dept=<?= $selectedDeptId ?>&folder=<?= $folder['id'] ?>"
                                   data-drop-target-folder="<?= (int)$folder['id'] ?>"><?= sanitize($folder['name']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php
                $folderItems = array_filter($folderContents, fn($item) => $item['is_folder']);
                $fileItems = array_filter($folderContents, fn($item) => !$item['is_folder']);
                ?>

                <div class="folder-contents-wrap">
                    <?php if (empty($folderItems) && empty($fileItems)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            <p>This folder is empty.</p>
                        </div>
                    <?php else: ?>
                        
                        <?php if (!empty($folderItems)): ?>
                            <div class="folder-list-grid">
                                <?php foreach ($folderItems as $item): ?>
                                    <div class="folder-item-card" data-search="<?= sanitize(strtolower($item['name'])) ?>" 
                                         data-csp-href="?dept=<?= $selectedDeptId ?>&folder=<?= $item['id'] ?>" 
                                         data-folder-id="<?= (int)$item['id'] ?>" 
                                         data-folder-name="<?= sanitize($item['name']) ?>">
                                        
                                        <a href="?dept=<?= $selectedDeptId ?>&folder=<?= $item['id'] ?>" class="folder-link-area">
                                            <div class="folder-icon">
                                                <?= manage_doc_folder_icon() ?>
                                            </div>
                                            <div class="folder-info-group">
                                                <div class="folder-name"><?= sanitize($item['name']) ?></div>
                                                <div class="folder-count"><?= (int)($item['file_count'] ?? 0) ?> files</div>
                                            </div>
                                            <span class="folder-arrow">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="9 18 15 12 9 6" />
                                                </svg>
                                            </span>
                                        </a>
                                        
                                        <a href="?zip_folder=<?= $item['id'] ?>" class="folder-zip-btn" title="Download this folder as ZIP">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <polyline points="7 10 12 15 17 10" />
                                                <line x1="12" y1="15" x2="12" y2="3" />
                                            </svg>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fileItems)): ?>
                            <div class="files-grid" id="contentList">
                                <?php foreach ($fileItems as $item): ?>
                                    <?php
                                    $fileExists = $item['file_exists'] ?? false;
                                    $downloadLink = ($item['source'] === 'document')
                                        ? 'download_document.php?id=' . $item['id']
                                        : 'download_ph_document.php?id=' . $item['id'];
                                    $uploadDate = !empty($item['upload_date']) ? date('M d', strtotime($item['upload_date'])) : 'Unknown';
                                    $fileTypeSlug = strtolower($item['file_type'] ?? 'document');
                                    ?>
                                    <?php $isMovable = ($item['source'] === 'document' && empty($item['teacher_requirement_id'])); ?>
                                    
                                    <div class="file-card" data-row-id="<?= $item['source'] ?>:<?= (int)$item['id'] ?>" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-type="file" data-filetype="<?= sanitize($fileTypeSlug) ?>"
                                        <?= $isMovable ? 'draggable="true" data-doc-id="' . (int)$item['id'] . '" data-doc-name="' . sanitize($item['name']) . '" data-doc-source="' . sanitize($item['source']) . '"' : '' ?>>
                                        
                                        <div class="icon-wrapper">
                                            <?= manage_doc_file_icon($fileTypeSlug) ?>
                                        </div>
                                        
                                        <div class="file-info">
                                            <div class="file-name">
                                                <?= sanitize($item['name']) ?>
                                            </div>
                                            <div class="file-meta">
                                                <span><?= $fileTypeSlug === 'word' ? 'Word' : ($fileTypeSlug === 'pdf' ? 'PDF' : ucfirst($fileTypeSlug)) ?></span>
                                                <span>•</span>
                                                <span><?= $uploadDate ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="file-actions">
                                            <a href="#" class="action-btn preview-btn view-btn"
                                                data-id="<?= $item['id'] ?>"
                                                data-type="<?= $item['source'] ?>"
                                                data-title="<?= sanitize($item['name']) ?>"
                                                data-filename="<?= sanitize($item['filename']) ?>"
                                                title="Preview">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                            </a>
                                            
                                            <?php if ($fileExists): ?>
                                                <a href="<?= $downloadLink ?>" class="action-btn download-btn" title="Download">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                                                    <button type="button" class="action-btn archive-btn js-archive-doc" title="Archive" data-archive-name="<?= sanitize($item['name']) ?>">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                                                    <button type="button" class="action-btn delete-btn js-delete-doc" title="Delete" data-delete-name="<?= sanitize($item['name']) ?>">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="3 6 5 6 21 6" />
                                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                        </svg>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="action-btn disabled" title="File not found">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                        <polyline points="7 10 12 15 17 10" />
                                                        <line x1="12" y1="15" x2="12" y2="3" />
                                                    </svg>
                                                </span>
                                                <span class="action-btn disabled" title="Cannot delete - file missing">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="10" />
                                                        <line x1="15" y1="9" x2="9" y2="15" />
                                                        <line x1="9" y1="9" x2="15" y2="15" />
                                                    </svg>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div> <!-- /repo-section -->
</div>

<!-- ========== MODAL: Delete / Archive Confirmation ========== -->
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

<!-- ========== MODAL: Create Share Link ========== -->
<?php if ($showCards): ?>
<div class="modal-overlay" id="createShareModal" aria-hidden="true">
    <form method="POST" action="admin_manage_document.php" class="modal-box" role="dialog" aria-modal="true" aria-labelledby="createShareTitle">
        <div class="modal-header">
            <h2 id="createShareTitle">Create Share Link</h2>
            <button class="modal-close" type="button" id="closeCreateShare" aria-label="Close dialog">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

            <label class="form-label" for="shareMaxVisitors">Maximum visitors</label>
            <select name="share_max_visitors" id="shareMaxVisitors" class="form-control">
                <option value="3">3 visitors</option>
                <option value="5" selected>5 visitors (default)</option>
                <option value="10">10 visitors</option>
                <option value="20">20 visitors</option>
                <option value="custom">Custom number…</option>
                <option value="unlimited">Unlimited</option>
            </select>
            <input type="number" name="share_custom_max" id="shareCustomMax" min="1" max="100000"
                   placeholder="Custom visitor limit" class="form-control" style="display:none">

            <label class="form-label" for="shareExpiresAt">Expires at</label>
            <input type="datetime-local" name="share_expires_at" id="shareExpiresAt" required class="form-control">
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cancelCreateShare">Cancel</button>
            <button type="submit" class="btn btn-primary" name="create_share_link" value="1">Create Link</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($newShare): ?>
<!-- ========== MODAL: Show-once share URL ========== -->
<div class="modal-overlay active" id="newShareModal" aria-hidden="false" style="z-index: 2100;">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="newShareTitle">
        <div class="modal-header">
            <h2 id="newShareTitle">Share link ready</h2>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 .75rem;">
                Copy and send this URL to the visitor now. It will <strong>not</strong> be shown again.
            </p>
            <div style="display:flex;gap:.5rem">
                <input type="text" readonly id="newShareUrl"
                       value="<?= htmlspecialchars($newShare['url'], ENT_QUOTES, 'UTF-8') ?>"
                       onclick="this.select()"
                       style="flex:1;padding:.55rem .7rem;border:1px solid #e2e8f0;border-radius:10px;
                              font-family:ui-monospace,monospace;font-size:.78rem">
                <button type="button" class="btn btn-primary" id="copyNewShareUrl">Copy</button>
            </div>
            <p style="margin:.75rem 0 0;color:#64748b;font-size:.76rem">
                Expires: <?php
                    try {
                        $d = new DateTime($newShare['expires'], new DateTimeZone('UTC'));
                        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
                        echo sanitize($d->format('M d, Y g:i A'));
                    } catch (Throwable $e) {
                        echo sanitize($newShare['expires']);
                    }
                ?> ·
                Max visitors: <?= $newShare['max'] === null ? 'Unlimited' : (int)$newShare['max'] ?>
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="closeNewShare">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

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

<script nonce="<?= csp_nonce() ?>">
    /* ========== Share-link actions: Copy Link / Revoke / Remove ========== */
    (function () {
        function showDocToast(msg, isError) {
            var existing = document.querySelector('body > .archive-flash');
            if (existing) existing.remove();
            var el = document.createElement('div');
            el.className = 'archive-flash ' + (isError ? 'alert-danger' : 'alert-success');
            el.setAttribute('role', 'alert');
            el.setAttribute('aria-live', isError ? 'assertive' : 'polite');
            el.textContent = msg;
            document.body.appendChild(el);
            setTimeout(function () {
                el.style.transition = 'opacity 0.4s';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 400);
            }, 3000);
        }

        document.querySelectorAll('.js-copy-link').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var url = btn.getAttribute('data-url');
                if (!url) return;

                var ok = false;
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(url);
                        ok = true;
                    }
                } catch (_) { }

                if (!ok) {
                    var ta = document.createElement('textarea');
                    ta.value = url;
                    ta.style.position = 'fixed';
                    ta.style.top = '-1000px';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { ok = document.execCommand('copy'); } catch (__) { ok = false; }
                    document.body.removeChild(ta);
                }

                var span = btn.querySelector('span');
                if (span) {
                    var orig = span.textContent;
                    span.textContent = ok ? 'Copied!' : 'Copy failed';
                    btn.classList.add('is-copied');
                    setTimeout(function () {
                        span.textContent = orig;
                        btn.classList.remove('is-copied');
                    }, 1600);
                }
                if (!ok) showDocToast('Could not copy the link. Please copy it manually.', true);
            });
        });

        async function ajaxPost(form) {
            var res = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(new FormData(form))
            });
            var data = null;
            try { data = await res.json(); } catch (_) { }
            return { ok: res.ok, status: res.status, data: data };
        }

        function buildRemoveForm(linkId, csrf) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'admin_manage_document.php';
            form.className = 'js-remove-form';
            form.setAttribute('data-link-id', String(linkId));

            var c = document.createElement('input');
            c.type = 'hidden'; c.name = 'csrf_token'; c.value = csrf;
            form.appendChild(c);

            var r = document.createElement('input');
            r.type = 'hidden'; r.name = 'remove_share_link'; r.value = String(linkId);
            form.appendChild(r);

            var b = document.createElement('button');
            b.type = 'submit'; b.className = 'btn btn-sm btn-danger'; b.textContent = 'Remove';
            form.appendChild(b);

            bindRemoveForm(form);
            return form;
        }

        function convertRowToRevoked(row, linkId) {
            if (!row) return;
            row.setAttribute('data-status', 'revoked');
            var pill = row.querySelector('.status-pill');
            if (pill) {
                pill.textContent = 'revoked';
                pill.style.background = '#fef2f2';
                pill.style.color = '#b91c1c';
                pill.style.borderColor = '#fecaca';
            }
            var actions = row.querySelector('.actions');
            if (actions) {
                actions.innerHTML = '';
                var csrf = row.querySelector('input[name="csrf_token"]');
                actions.appendChild(buildRemoveForm(linkId, csrf ? csrf.value : ''));
            }
        }

        function checkEmptyShareList() {
            var list = document.getElementById('shareLinksList');
            if (!list) return;
            if (list.querySelector('.content-item[data-link-id]')) return;
            var panel = document.getElementById('shareLinksPanel');
            if (!panel) return;
            panel.innerHTML = '<h3>Active share links (all departments)</h3>'
                + '<div style="padding:.9rem 1rem;color:#64748b;font-size:.85rem">No share links yet.</div>';
        }

        function bindRevokeForm(form) {
            if (!form || form.__bound) return;
            form.__bound = true;
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!confirm('Revoke this share link? Visitors will lose access immediately.')) return;
                var btn = form.querySelector('button[type="submit"]');
                if (btn) btn.disabled = true;
                var r = await ajaxPost(form);
                if (btn) btn.disabled = false;
                if (r.ok && r.data && r.data.ok) {
                    convertRowToRevoked(form.closest('.content-item'), r.data.id);
                    showDocToast('Share link revoked.');
                } else {
                    showDocToast((r.data && r.data.error) || 'Failed to revoke link.', true);
                }
            });
        }

        function bindRemoveForm(form) {
            if (!form || form.__bound) return;
            form.__bound = true;
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!confirm('Remove this revoked share link from the list? The underlying documents will not be affected.')) return;
                var btn = form.querySelector('button[type="submit"]');
                if (btn) btn.disabled = true;
                var r = await ajaxPost(form);
                if (btn) btn.disabled = false;
                if (r.ok && r.data && r.data.ok) {
                    var row = form.closest('.content-item');
                    if (row) row.remove();
                    showDocToast('Share link removed.');
                    checkEmptyShareList();
                } else {
                    showDocToast((r.data && r.data.error) || 'Failed to remove link.', true);
                }
            });
        }

        document.querySelectorAll('.js-revoke-form').forEach(bindRevokeForm);
        document.querySelectorAll('.js-remove-form').forEach(bindRemoveForm);
    })();
</script>

<script nonce="<?= csp_nonce() ?>">
    /* ========== Create Share / New Share modals ========== */
    (function() {
        var createModal = document.getElementById('createShareModal');
        if (createModal) {
            var maxSel = document.getElementById('shareMaxVisitors');
            var customEl = document.getElementById('shareCustomMax');
            var expiresEl = document.getElementById('shareExpiresAt');

            function openCreate() {
                if (customEl) { customEl.style.display = 'none'; customEl.value = ''; }
                if (maxSel) maxSel.value = '5';
                if (expiresEl) {
                    var d = new Date();
                    d.setDate(d.getDate() + 30);
                    d.setHours(23, 59, 0, 0);
                    var pad = function(n) { return String(n).padStart(2, '0'); };
                    expiresEl.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                                    + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                }
                createModal.classList.add('active');
                createModal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }
            function closeCreate() {
                createModal.classList.remove('active');
                createModal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            var headerBtn = document.getElementById('openShareFromHeader');
            if (headerBtn) headerBtn.addEventListener('click', openCreate);
            var cancelBtn = document.getElementById('cancelCreateShare');
            if (cancelBtn) cancelBtn.addEventListener('click', closeCreate);
            var closeBtn = document.getElementById('closeCreateShare');
            if (closeBtn) closeBtn.addEventListener('click', closeCreate);
            createModal.addEventListener('click', function(e) {
                if (e.target === createModal) closeCreate();
            });

            if (maxSel && customEl) {
                maxSel.addEventListener('change', function() {
                    customEl.style.display = (maxSel.value === 'custom') ? '' : 'none';
                    if (maxSel.value === 'custom') customEl.focus();
                });
            }
        }

        var newModal = document.getElementById('newShareModal');
        if (newModal) {
            function closeNew() {
                newModal.classList.remove('active');
                newModal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
            var closeNewBtn = document.getElementById('closeNewShare');
            if (closeNewBtn) closeNewBtn.addEventListener('click', closeNew);
            newModal.addEventListener('click', function(e) {
                if (e.target === newModal) closeNew();
            });

            var copyBtn = document.getElementById('copyNewShareUrl');
            if (copyBtn) {
                copyBtn.addEventListener('click', async function() {
                    var inp = document.getElementById('newShareUrl');
                    if (!inp) return;
                    try {
                        await navigator.clipboard.writeText(inp.value);
                    } catch (_) {
                        inp.select();
                        document.execCommand('copy');
                    }
                    this.textContent = 'Copied';
                });
            }
        }
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
    <?php if ($flashMsg && !$newShare): ?>
        document.getElementById('successMsg').textContent = <?= json_encode($flashMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.getElementById('successModal').classList.add('active');
        document.getElementById('successModal').setAttribute('aria-hidden', 'false');
        __docSuccessTimer = setTimeout(closeDocSuccessToast, 2600);
    <?php endif; ?>
</script>

<!-- ========== Dynamic Drag-and-Drop (Windows Explorer style) ========== -->
<script nonce="<?= csp_nonce() ?>">
(function () {
    var csrfToken       = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
    var deptId          = <?= (int)$selectedDeptId ?>;
    var currentFolderId = <?= (int)$selectedFolderId ?>;

    var draggedDocId = null;
    var draggedRow   = null;
    var draggedName  = null;
    var moving       = false;

    function showMoveToast(msg, isErr) {
        var existing = document.querySelector('body > .archive-flash');
        if (existing) existing.remove();
        var t = document.createElement('div');
        t.className = 'archive-flash ' + (isErr ? 'alert-danger' : 'alert-success');
        t.setAttribute('role', 'alert');
        t.setAttribute('aria-live', isErr ? 'assertive' : 'polite');
        var s = document.createElement('span');
        s.textContent = msg;
        t.appendChild(s);
        document.body.appendChild(t);
        setTimeout(function () {
            t.style.transition = 'opacity 0.4s';
            t.style.opacity = '0';
            setTimeout(function () { t.remove(); }, 400);
        }, 3000);
    }

    function bumpFolderBadge(folderEl) {
        var codeEl = folderEl.querySelector('.folder-count');
        if (!codeEl) return;
        var m = codeEl.textContent.match(/(\d+)/);
        if (!m) return;
        var n = parseInt(m[1], 10) + 1;
        codeEl.textContent = n + ' files';
    }

    function ensureEmptyState() {
        var list = document.getElementById('contentList');
        if (!list) return;
        if (list.querySelector('.file-card')) return;
        list.innerHTML =
            '<div class="empty-state">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
                    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
                    '<polyline points="14 2 14 8 20 8"/>' +
                '</svg>' +
                '<p>This folder is empty.</p>' +
            '</div>';
    }

    async function performMove(docId, targetFolderId, dropEl, row) {
        if (moving || !docId) return;
        moving = true;

        document.querySelectorAll('.drag-over').forEach(function (el) { el.classList.remove('drag-over'); });

        var body = new URLSearchParams();
        body.append('csrf_token', csrfToken);
        body.append('move_document', docId);
        body.append('target_folder_id', targetFolderId);
        body.append('dept_id', deptId);
        body.append('folder_id', currentFolderId);

        try {
            var res = await fetch('admin_manage_document.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            });
            var data = null;
            try { data = await res.json(); } catch (_) {}

            if (res.ok && data && data.ok) {
                if (data.noop) {
                    showMoveToast('Document is already in this folder.', false);
                } else {
                    showMoveToast(data.message || 'Document moved.', false);
                    if (row && row.parentNode) row.remove();
                    if (dropEl) {
                        bumpFolderBadge(dropEl);
                    }
                    ensureEmptyState();
                }
            } else {
                showMoveToast((data && data.error) || 'Move failed.', true);
            }
        } catch (err) {
            showMoveToast('Move failed: network error.', true);
        } finally {
            moving = false;
        }
    }

    /* Drag sources: only eligible Direct Upload rows. */
    document.querySelectorAll('.file-card[draggable="true"][data-doc-id]').forEach(function (row) {
        row.addEventListener('dragstart', function (e) {
            draggedDocId = this.dataset.docId;
            draggedName  = this.dataset.docName;
            draggedRow   = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedDocId);

            var ghost = document.createElement('div');
            ghost.className   = 'drag-ghost';
            ghost.textContent = draggedName || 'Document';
            document.body.appendChild(ghost);
            e.dataTransfer.setDragImage(ghost, 0, 0);
            setTimeout(function () { ghost.remove(); }, 0);
        });
        row.addEventListener('dragend', function () {
            this.classList.remove('dragging');
            draggedDocId = null;
            draggedName  = null;
            draggedRow   = null;
            document.querySelectorAll('.drag-over').forEach(function (el) { el.classList.remove('drag-over'); });
        });
    });

    /* Drop targets: visible subfolders. */
    document.querySelectorAll('.folder-item-card[data-folder-id]').forEach(function (folder) {
        folder.addEventListener('dragover', function (e) {
            if (!draggedDocId) return;
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        });
        folder.addEventListener('dragleave', function (e) {
            if (e.relatedTarget && this.contains(e.relatedTarget)) return;
            this.classList.remove('drag-over');
        });
        folder.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!draggedDocId) return;
            var row      = draggedRow;
            var docId    = draggedDocId;
            var targetId = this.dataset.folderId;
            this.classList.remove('drag-over');
            if (!docId || targetId === undefined || targetId === '') return;
            performMove(docId, targetId, this, row);
        });
    });

    /* Drop targets: breadcrumb (parent folder / department root). */
    document.querySelectorAll('[data-drop-target-folder]').forEach(function (el) {
        el.addEventListener('dragover', function (e) {
            if (!draggedDocId) return;
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        });
        el.addEventListener('dragleave', function (e) {
            if (e.relatedTarget && this.contains(e.relatedTarget)) return;
            this.classList.remove('drag-over');
        });
        el.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!draggedDocId) return;
            var row      = draggedRow;
            var docId    = draggedDocId;
            var targetId = this.dataset.dropTargetFolder;
            this.classList.remove('drag-over');
            if (!docId) return;
            performMove(docId, targetId, null, row);
        });
    });
})();
</script>
<?php include 'includes/footer.php'; ?>