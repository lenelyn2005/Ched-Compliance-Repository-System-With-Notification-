<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

ensureDepartmentFolders($pdo, ['admin_archive']);
autoArchiveDocuments($pdo);


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

// -------------------------------------------------------------------------
// Get department archive stats
// -------------------------------------------------------------------------
function getDepartmentArchiveStats($pdo, $deptId)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE d.department_id = ? AND " . archivedDocCondition());
    $stmt->execute([$deptId]);
    $fileCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ph_documents WHERE department_id = ? AND archived = 1 AND status = 'approved_admin'");
    $stmt->execute([$deptId]);
    $fileCount += (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM department_folders WHERE department_id = ?");
    $stmt->execute([$deptId]);
    $folderCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT MAX(d.upload_date) FROM documents d WHERE d.department_id = ? AND " . archivedDocCondition());
    $stmt->execute([$deptId]);
    $lastDate = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT MAX(upload_date) FROM ph_documents WHERE department_id = ? AND archived = 1 AND status = 'approved_admin'");
    $stmt->execute([$deptId]);
    $lastDatePh = $stmt->fetchColumn();
    if ($lastDatePh && (!$lastDate || $lastDatePh > $lastDate)) {
        $lastDate = $lastDatePh;
    }

    $lastArchived = $lastDate ? date('M j, Y', strtotime($lastDate)) : 'Never';

    return [
        'file_count' => $fileCount,
        'folder_count' => $folderCount,
        'last_archived' => $lastArchived
    ];
}

// shortcut: uses existing archive query logic (respects archive/visibility rules); upgrade to a single count query for larger trees.
function archiveFolderFileCount($pdo, int $deptId, int $folderId): int
{
    $folderCondition = ($folderId == 0)
        ? "(COALESCE(dr.folder_id, dr2.folder_id) IS NULL OR COALESCE(dr.folder_id, dr2.folder_id) = 0)"
        : "COALESCE(dr.folder_id, dr2.folder_id) = ?";
    $params = [$deptId];
    if ($folderId != 0) $params[] = $folderId;

    $sql = "
        SELECT COUNT(DISTINCT d.id)
        FROM documents d
        JOIN departments dep ON d.department_id = dep.id
        LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        LEFT JOIN department_requirements dr ON tr.requirement_id = dr.id
        LEFT JOIN department_requirements dr2 ON d.teacher_requirement_id IS NULL AND dr2.department_id = d.department_id AND dr2.category = d.category AND dr2.document_type = d.document_type
        WHERE d.department_id = ?
          AND d.archived = 1
          AND d.status = 'approved_admin'
          AND $folderCondition
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// -------------------------------------------------------------------------
// Process GET parameters
// -------------------------------------------------------------------------
$success = '';
$error = '';
$filterDept = isset($_GET['dept']) && is_numeric($_GET['dept']) ? (int) $_GET['dept'] : null;
$search = is_string($_GET['search'] ?? '') ? trim($_GET['search']) : '';
$selectedFolder = isset($_GET['folder']) && is_numeric($_GET['folder']) ? (int) $_GET['folder'] : null;

// -------------------------------------------------------------------------
// Handle permanent delete (POST only with CSRF)
// -------------------------------------------------------------------------
$deleteId = null;
$deleteSource = 'document';
if (isset($_POST['delete_id']) && is_numeric($_POST['delete_id'])) {
    requireCsrfToken();
    // §13: permanent deletion requires re-entering the admin password.
    $adminPass = (string) ($_POST['admin_password'] ?? '');
    $pwStmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $pwStmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $pwHash = $pwStmt->fetchColumn();
    if ($adminPass === '' || !$pwHash || !password_verify($adminPass, $pwHash)) {
        $error = 'Admin password is required to permanently delete a document.';
    } else {
        $deleteId = (int) $_POST['delete_id'];
        $deleteSource = $_POST['delete_source'] ?? 'document';
    }
}

if ($deleteId !== null) {
    $doc = null;
    if ($deleteSource === 'ph_document') {
        $stmt = $pdo->prepare('SELECT * FROM ph_documents WHERE id = ? AND archived = 1 AND status = "approved_admin"');
        $stmt->execute([$deleteId]);
        $doc = $stmt->fetch();
        if ($doc) {
            // Delete intent first: this operation is irreversible, so record it
            // before the file goes away (plan §6.2).
            logAudit($pdo, 'delete_intent', 'Permanent delete requested for PH document: ' . $doc['title'], 'ph_document', $deleteId);
            if (STORAGE_DRIVER === 'r2') {
                try {
                    // Idempotent: a genuine 404 already counts as deleted; any
                    // other failure throws and leaves the row untouched.
                    R2Storage::default()->delete((string) $doc['file_path']);
                } catch (StorageException $e) {
                    error_log('[CHED] archive delete blocked, storage unavailable: ' . get_class($e));
                    $error = 'Storage temporarily unavailable; nothing was deleted. Please try again.';
                    $doc = false;
                }
            } else {
                $filePath = securePath($doc['file_path']);
                if ($filePath && file_exists($filePath) && !@unlink($filePath)) {
                    error_log('[CHED] archive delete: unlink failed for ph_documents #' . $deleteId . ': ' . $filePath);
                    $error = 'Could not remove the file from storage; the record was not deleted.';
                    $doc = false;
                }
            }
            if ($doc) {
                $pdo->prepare('DELETE FROM ph_documents WHERE id = ?')->execute([$deleteId]);
                logAudit($pdo, 'delete_archived', 'Archived PH document permanently deleted: ' . $doc['title'], 'ph_document', $deleteId);
                $success = "Delete successful — Document permanently deleted.";
            }
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND archived = 1 AND status = "approved_admin"');
        $stmt->execute([$deleteId]);
        $doc = $stmt->fetch();
        if ($doc) {
            // Delete intent first: this operation is irreversible (plan §6.2).
            logAudit($pdo, 'delete_intent', 'Permanent delete requested for document: ' . $doc['title'], 'document', $deleteId);
            if (STORAGE_DRIVER === 'r2') {
                try {
                    R2Storage::default()->delete((string) $doc['file_path']);
                } catch (StorageException $e) {
                    error_log('[CHED] archive delete blocked, storage unavailable: ' . get_class($e));
                    $error = 'Storage temporarily unavailable; nothing was deleted. Please try again.';
                    $doc = false;
                }
            } else {
                $filePath = securePath($doc['file_path']);
                if ($filePath && file_exists($filePath) && !@unlink($filePath)) {
                    error_log('[CHED] archive delete: unlink failed for documents #' . $deleteId . ': ' . $filePath);
                    $error = 'Could not remove the file from storage; the record was not deleted.';
                    $doc = false;
                }
            }
            if ($doc) {
                $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$deleteId]);
                logAudit($pdo, 'delete_archived', 'Archived document permanently deleted: ' . $doc['title'], 'document', $deleteId);
                $success = "Delete successful — Document permanently deleted.";
            }
        }
    }
    if (isAjaxRequest()) {
        sendJson(['success' => (bool) $doc, 'message' => $success ?: 'Document not found or already removed.']);
    }
}

// -------------------------------------------------------------------------
// Handle unarchive (POST only with CSRF)
// -------------------------------------------------------------------------
if (isset($_POST['unarchive']) && is_numeric($_POST['unarchive'])) {
    requireCsrfToken();
    $id = (int) $_POST['unarchive'];
    $source = $_POST['unarchive_source'] ?? 'document';
    $table = ($source === 'ph_document') ? 'ph_documents' : 'documents';

    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND archived = 1 AND status = 'approved_admin'");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if ($doc) {
        $deptFolder = deptSlug((int) $doc['department_id'], $pdo);
        $oldRel = (string) $doc['file_path'];
        $filename = basename($oldRel);
        $newRelativePath = storageSubdir('admin_repository', $pdo, (int) $doc['department_id'], $table === 'documents' ? documentFolderId($pdo, 'documents', $id) : null) . '/' . $filename;

        $fileRestored = false;
        $restoreBlocked = false;

        if (STORAGE_DRIVER === 'r2') {
            // Object keys never move on their own, so the row's file_path is
            // authoritative — the legacy-subpath fallback below is local-only.
            try {
                $fileRestored = storageMoveObject($oldRel, $newRelativePath);
            } catch (StorageException $e) {
                error_log('[CHED] unarchive blocked, storage unavailable: ' . get_class($e));
                $error = 'Storage temporarily unavailable; the document was not restored. Please try again.';
                $restoreBlocked = true;
            }
        } else {
            $oldPath = securePath($oldRel);
            $newFullPath = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
            if (!is_dir(dirname($newFullPath))) {
                mkdir(dirname($newFullPath), 0755, true);
            }

            if ($oldPath && file_exists($oldPath)) {
                if (rename($oldPath, $newFullPath)) {
                    $fileRestored = true;
                } else {
                    $error = "Failed to move file back to main uploads.";
                }
            } else {
                // Try known archived subpaths
                $archivedCandidates = [
                    UPLOAD_PATH . 'admin_archive' . DIRECTORY_SEPARATOR . $deptFolder . DIRECTORY_SEPARATOR . $filename,
                    UPLOAD_PATH . 'admin_archive' . DIRECTORY_SEPARATOR . $filename,
                ];
                foreach ($archivedCandidates as $archivedPath) {
                    if (file_exists($archivedPath)) {
                        if (rename($archivedPath, $newFullPath)) {
                            $fileRestored = true;
                            break;
                        }
                    }
                }
            }
        }

        // Preserve expiry_date and validity_status on restore (do not clear them).
        // Skipped when storage was unavailable: the row must not be flipped to
        // "live" while its object is still sitting in the archive (plan §9).
        if (!$restoreBlocked) {
            $pdo->prepare("UPDATE $table SET file_path = ?, archived = 0 WHERE id = ?")
                ->execute([$fileRestored ? $newRelativePath : $doc['file_path'], $id]);
            logAudit($pdo, 'unarchive', ($source === 'ph_document' ? 'PH document' : 'Document') . ' restored from archive: ' . $doc['title'], $source, $id);

            $success = $fileRestored ? "Document successfully restored from archive." : "Document restored (file was missing - restored path in DB only).";
        }
    }
    if (isAjaxRequest()) {
        sendJson(['success' => (bool) $doc, 'message' => $success ?: ($error ?: 'Document not found or already restored.')]);
    }
}

// -------------------------------------------------------------------------
// Fetch departments (with code if available)
// -------------------------------------------------------------------------
$departments = [];
try {
    $departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
    if (!empty($departments) && !array_key_exists('code', $departments[0])) {
        foreach ($departments as &$d) {
            if (preg_match('/\(([^)]+)\)/', $d['department_name'], $matches)) {
                $d['code'] = $matches[1];
            } else {
                $d['code'] = '';
            }
        }
    }
} catch (Exception $e) {
    $departments = $pdo->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetchAll();
    foreach ($departments as &$d) {
        if (preg_match('/\(([^)]+)\)/', $d['department_name'], $matches)) {
            $d['code'] = $matches[1];
        } else {
            $d['code'] = '';
        }
    }
}

// Deduplicate departments by ID (keep one per ID)
$uniqueDepartments = [];
foreach ($departments as $department) {
    $uniqueDepartments[$department['id']] = $department;
}
$departments = array_values($uniqueDepartments);

$deptStats = [];
foreach ($departments as $d) {
    $deptStats[$d['id']] = getDepartmentArchiveStats($pdo, $d['id']);
}

// -------------------------------------------------------------------------
// If a department is selected, fetch folder contents (subfolders + archived docs)
// -------------------------------------------------------------------------
$folderContents = [];
$breadcrumb = [];
$selectedDeptName = '';

if ($filterDept) {
    // Get department name/code
    foreach ($departments as $d) {
        if ((int)$d['id'] === $filterDept) {
            $selectedDeptName = $d['department_name'];
            break;
        }
    }

    // Build breadcrumb
    if ($selectedFolder) {
        $crumbSegments = getFolderPathSegments($pdo, $selectedFolder);
        $breadcrumb = $crumbSegments;
    }

    // Get subfolders
    if ($selectedFolder) {
        $stmt = $pdo->prepare("SELECT id, folder_name FROM department_folders WHERE department_id = ? AND parent_folder_id = ? AND LOWER(folder_name) <> 'root' ORDER BY folder_name");
        $stmt->execute([$filterDept, $selectedFolder]);
        $subfolders = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, folder_name FROM department_folders WHERE department_id = ? AND parent_folder_id IS NULL AND LOWER(folder_name) <> 'root' ORDER BY folder_name");
        $stmt->execute([$filterDept]);
        $subfolders = $stmt->fetchAll();
    }

    // Get archived documents in the current folder
    $params = [$filterDept];
    $folderCondition = "";
    if ($selectedFolder) {
        $folderCondition = "AND COALESCE(dr.folder_id, dr2.folder_id) = ?";
        $params[] = $selectedFolder;
    } else {
        $folderCondition = "AND (COALESCE(dr.folder_id, dr2.folder_id) IS NULL OR COALESCE(dr.folder_id, dr2.folder_id) = 0)";
    }

    $sql = "
        SELECT d.*, dep.department_name,
               COALESCE(dr.folder_id, dr2.folder_id) AS folder_id,
               tr.id AS teacher_req_id,
               dr.document_type as req_document_type,
               dr.category as req_category,
               u.name as uploaded_by_name
        FROM documents d
        JOIN departments dep ON d.department_id = dep.id
        LEFT JOIN users u ON d.uploaded_by = u.id
        LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        LEFT JOIN department_requirements dr ON tr.requirement_id = dr.id
        LEFT JOIN department_requirements dr2 ON d.teacher_requirement_id IS NULL AND dr2.department_id = d.department_id AND dr2.category = d.category AND dr2.document_type = d.document_type
        WHERE d.department_id = ?
          AND d.archived = 1
          AND d.status = 'approved_admin'
          $folderCondition
        ORDER BY d.upload_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $archivedDocs = $stmt->fetchAll();

    // Add archived PH documents
    if (!$selectedFolder) {
        $phSql = "
            SELECT pd.*, dep.department_name,
                   NULL AS folder_id,
                   NULL AS teacher_req_id,
                   NULL as req_document_type,
                   NULL as req_category,
                   phr.title AS requirement_title,
                   u.name as uploaded_by_name
            FROM ph_documents pd
            JOIN departments dep ON pd.department_id = dep.id
            LEFT JOIN users u ON pd.uploaded_by = u.id
            LEFT JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            LEFT JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            WHERE pd.department_id = ?
              AND pd.archived = 1
              AND pd.status = 'approved_admin'
            ORDER BY pd.upload_date DESC
        ";
        $phStmt = $pdo->prepare($phSql);
        $phStmt->execute([$filterDept]);
        $phDocs = $phStmt->fetchAll();
        $archivedDocs = array_merge($archivedDocs, $phDocs);
    }

    // Build folderContents array (mix of subfolders and docs)
    foreach ($subfolders as $sf) {
        $folderContents[] = [
            'type'       => 'folder',
            'id'         => $sf['id'],
            'name'       => $sf['folder_name'],
            'is_folder'  => true,
            'file_count' => archiveFolderFileCount($pdo, $filterDept, $sf['id']),
        ];
    }

    // Add documents
    foreach ($archivedDocs as $doc) {
        $ext = strtolower(pathinfo($doc['filename'] ?? '', PATHINFO_EXTENSION));
        $fileType = 'Document';
        if (in_array($ext, ['pdf'])) $fileType = 'PDF';
        elseif (in_array($ext, ['doc', 'docx'])) $fileType = 'Word';
        elseif (in_array($ext, ['xls', 'xlsx'])) $fileType = 'Excel';
        elseif (in_array($ext, ['ppt', 'pptx'])) $fileType = 'PowerPoint';
        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $fileType = 'Image';
        elseif (in_array($ext, ['zip', 'rar'])) $fileType = 'Archive';

        $isPhDoc = isset($doc['teacher_ph_requirement_id']) || (!empty($doc['requirement_title']));
        $source = $isPhDoc ? 'ph_document' : 'document';

        $reqName = '';
        $docName = $doc['title'] ?: $doc['filename'];
        if (!empty($doc['req_document_type']) && !empty($doc['req_category'])) {
            $reqName = $doc['req_document_type'] . ' (' . $doc['req_category'] . ')';
            if ($docName === trim($doc['req_category'] . ' - ' . $doc['req_document_type'])) {
                $reqName = '';
            }
        } elseif (!empty($doc['requirement_title'])) {
            $reqName = $doc['requirement_title'];
        }

        $folderContents[] = [
            'type'              => 'file',
            'id'                => $doc['id'],
            'source'            => $source,
            'name'              => $docName,
            'filename'          => $doc['filename'],
            'file_path'         => $doc['file_path'],
            'file_exists'       => !empty($doc['file_path']) && storageFilePresent((string) $doc['file_path']),
            'file_type'         => $fileType,
            'file_ext'          => $ext,
            'upload_date'       => $doc['upload_date'],
            'status'            => $doc['status'],
            'archived'          => $doc['archived'],
            'is_folder'         => false,
            'requirement_name'  => $reqName,
            'uploaded_by'       => $doc['uploaded_by_name'] ?? 'Unknown',
            'origin'            => $doc['origin'] ?? '',
        ];
    }

    // Sort: folders first, then files alphabetically
    usort($folderContents, function ($a, $b) {
        if ($a['is_folder'] && !$b['is_folder']) return -1;
        if (!$a['is_folder'] && $b['is_folder']) return 1;
        return strcasecmp($a['name'], $b['name']);
    });
}

$pageTitle = $filterDept ? 'Archives  ' : 'Document Archives';

// -------------------------------------------------------------------------
// Real File Explorer–style icons (inline SVG, no background container)
// -------------------------------------------------------------------------
if (!function_exists('archive_file_visual')) {
    function archive_file_visual(string $fileType, string $ext = ''): array
    {
        $ext = strtolower($ext);
        $key = 'document';

        if ($fileType === 'PDF' || $ext === 'pdf') {
            $key = 'pdf';
        } elseif ($fileType === 'Word' || in_array($ext, ['doc', 'docx'])) {
            $key = 'word';
        } elseif ($fileType === 'Excel' || in_array($ext, ['xls', 'xlsx'])) {
            $key = 'excel';
        } elseif ($fileType === 'PowerPoint' || in_array($ext, ['ppt', 'pptx'])) {
            $key = 'powerpoint';
        } elseif ($fileType === 'Image' || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
            $key = 'image';
        } elseif ($fileType === 'Archive' || in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'])) {
            $key = 'archive';
        } elseif (in_array($ext, ['txt', 'rtf', 'csv'])) {
            $key = 'text';
        }

        $body = 'M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z';
        $fold = 'M30 2l10 10h-8a2 2 0 0 1-2-2V2z';

        $icons = [
            'pdf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#e53935"/><path d="' . $fold . '" fill="#b71c1c"/><text x="24" y="37" font-family="Arial, Helvetica, sans-serif" font-size="10" font-weight="700" fill="#ffffff" text-anchor="middle" letter-spacing="0.3">PDF</text></svg>',
            'word' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#2b579a"/><path d="' . $fold . '" fill="#1e3f70"/><text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">W</text></svg>',
            'excel' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#217346"/><path d="' . $fold . '" fill="#185c37"/><text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">X</text></svg>',
            'powerpoint' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#d24726"/><path d="' . $fold . '" fill="#a5371e"/><text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">P</text></svg>',
            'image' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/><path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/><rect x="13" y="20" width="22" height="16" rx="1" fill="#5eb7e8"/><circle cx="19" cy="25" r="2" fill="#ffffff"/><path d="M13 34l6-6 4 4 4-5 8 7z" fill="#7cc576"/></svg>',
            'archive' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#ffc107"/><path d="' . $fold . '" fill="#e0a800"/><rect x="20" y="16" width="8" height="2" fill="#5d4037"/><rect x="20" y="20" width="8" height="2" fill="#5d4037"/><rect x="20" y="24" width="8" height="2" fill="#5d4037"/><rect x="20" y="28" width="8" height="2" fill="#5d4037"/><rect x="20" y="32" width="8" height="6" rx="1" fill="#5d4037"/></svg>',
            'text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/><path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/><line x1="16" y1="22" x2="32" y2="22" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/><line x1="16" y1="27" x2="32" y2="27" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/><line x1="16" y1="32" x2="26" y2="32" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/></svg>',
            'document' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/><path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/><line x1="16" y1="22" x2="32" y2="22" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/><line x1="16" y1="27" x2="32" y2="27" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/><line x1="16" y1="32" x2="26" y2="32" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/></svg>',
        ];

        return ['class' => $key, 'svg' => $icons[$key] ?? $icons['document']];
    }
}

if (!function_exists('archive_folder_icon')) {
    function archive_folder_icon(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
            . '<path d="M6 10a2 2 0 0 1 2-2h11.5a2 2 0 0 1 1.7.9l1.6 2.2a2 2 0 0 0 1.7.9H40a2 2 0 0 1 2 2v3H6v-7z" fill="#f7b915"/>'
            . '<path d="M6 15h36v23a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V15z" fill="#ffcf47"/>'
            . '</svg>';
    }
}

// -------------------------------------------------------------------------
// ENHANCED UI STYLES — Matching admin_manage_document.php
// -------------------------------------------------------------------------
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
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
        --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    * { box-sizing: border-box; }

    body {
        background: #eef1f7;
        font-family: var(--font-sans);
        color: var(--gray-800);
        font-size: 14.5px;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
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
    }

    .btn-sm { padding: 0.4rem 0.7rem; font-size: 0.74rem; }
    .btn-icon { padding: 0.45rem; min-width: 30px; min-height: 30px; justify-content: center; border-radius: 8px; }
    
    .btn-outline { background: #ffffff; border-color: #e2e8f0; color: var(--gray-700); }
    .btn-outline:hover { border-color: #c7d2fe; color: var(--primary-dark); background: #f8faff; }
    .btn-success { background: #ffffff; border-color: #e2e8f0; color: var(--gray-600); }
    .btn-success:hover { background: var(--success-light); border-color: #a7f3d0; color: #065f46; }
    .btn-danger { background: #ffffff; border-color: #e2e8f0; color: var(--gray-600); }
    .btn-danger:hover { background: #fef2f2; border-color: #fecaca; color: var(--danger); }
    .btn-disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

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
    .breadcrumb-nav a { color: var(--gray-600); text-decoration: none; font-weight: 600; transition: color 0.15s ease, background 0.15s ease; display: inline-flex; align-items: center; gap: 0.2rem; padding: 0.15rem 0.4rem; border-radius: 6px; }
    .breadcrumb-nav a:hover { color: var(--primary); background: #eef2ff; }
    .breadcrumb-nav .current { font-weight: 700; color: var(--gray-900); letter-spacing: -0.01em; padding: 0.15rem 0.4rem; }
    .breadcrumb-nav .separator { color: var(--gray-300); font-weight: 400; padding: 0 0.1rem; }
    .breadcrumb-nav .home-icon { width: 15px; height: 15px; display: inline-block; vertical-align: middle; margin-right: 0.1rem; color: var(--primary); }

    /* =========================================
       DEPARTMENT CARDS
       ========================================= */
    .dept-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; }
    .dept-card {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        padding: 1.25rem;
        transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        text-decoration: none;
        color: inherit;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        position: relative;
        display: flex;
        flex-direction: column;
    }
    .dept-card:hover { transform: translateY(-3px); box-shadow: 0 10px 22px -12px rgba(30, 41, 59, 0.2); border-color: #c7d2fe; }
    .dept-card .folder-icon-big { display: inline-flex; margin-bottom: 0.75rem; }
    .dept-card .folder-icon-big svg { width: 38px; height: 38px; display: block; }
    .dept-card .dept-name { font-size: 0.95rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.25rem; }
    .dept-card .dept-code { font-size: 0.65rem; color: var(--gray-500); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.5rem; }
    .dept-card .arrow { position: absolute; top: 1.25rem; right: 1.25rem; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; color: var(--gray-500); transition: all 0.18s ease; }
    .dept-card:hover .arrow { background: var(--primary); border-color: var(--primary); color: white; }
    .dept-card .dept-stats { display: flex; gap: 0.5rem; margin-top: auto; padding-top: 0.75rem; border-top: 1px solid var(--gray-100); }
    .dept-card .stat-pill { display: inline-flex; align-items: center; gap: 0.3rem; background: #f9fafb; border: 1px solid #e5e7eb; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.7rem; color: var(--gray-500); }
    .dept-card .stat-pill .stat-number { font-weight: 700; color: var(--gray-700); }

    /* =========================================
       INNER FOLDER & FILE GRIDS (The match)
       ========================================= */
    .folder-contents-wrap {
        background: #FDFCF8;
        border-radius: 16px;
        padding: 1.5rem;
        margin: 0 -1.75rem -1.75rem -1.75rem;
    }

    .folder-list-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; margin-bottom: 1.5rem; }
    
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
    .folder-item-card:hover { box-shadow: 0 4px 12px -4px rgba(0, 0, 0, 0.08); border-color: #D6D0C4; }
    .folder-item-card .folder-link-area { display: flex; align-items: center; flex: 1; height: 100%; padding: 0 1rem; gap: 1rem; text-decoration: none; color: inherit; }
    .folder-item-card .folder-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; flex-shrink: 0; }
    .folder-item-card .folder-icon svg { width: 100%; height: 100%; }
    .folder-item-card .folder-info-group { display: flex; flex-direction: column; justify-content: center; flex: 1; min-width: 0; }
    .folder-item-card .folder-name { font-size: 0.95rem; font-weight: 600; line-height: 1.3; margin: 0; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .folder-item-card .folder-count { font-size: 0.75rem; color: #888; font-weight: 400; margin-top: 0.15rem; }
    .folder-item-card .folder-arrow { display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; color: #BBB; flex-shrink: 0; transition: color 0.18s ease, transform 0.18s ease; }
    .folder-item-card:hover .folder-arrow { color: #555; transform: translateX(2px); }
    .folder-item-card .folder-zip-btn { display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; margin-right: 1rem; background: #F9F8F4; border: 1px solid #EEEBE2; border-radius: 8px; text-decoration: none; color: #888; transition: all 0.18s ease; flex-shrink: 0; }
    .folder-item-card .folder-zip-btn:hover { border-color: #D6D0C4; background: #ffffff; color: #333; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

    .files-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
    .file-card { display: flex; align-items: center; background: #ffffff; border: 1px solid #E8E4D9; border-radius: 12px; padding: 12px 16px; gap: 14px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02); transition: box-shadow 0.18s ease, border-color 0.18s ease; position: relative; cursor: pointer; }
    .file-card:hover { box-shadow: 0 4px 12px -4px rgba(0, 0, 0, 0.08); border-color: #D6D0C4; }
    .file-card .icon-wrapper { width: 44px; height: 44px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .file-card .icon-wrapper svg { width: 100%; height: 100%; }
    .file-card .file-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .file-card .file-name { font-size: 0.9rem; font-weight: 600; color: #333; margin: 0 0 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3; }
    .file-card .file-meta { font-size: 0.75rem; color: #888; display: flex; align-items: center; gap: 4px; }
    .file-card .file-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .action-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer; transition: all 0.18s ease; text-decoration: none; }
    .action-btn.preview-btn { background: transparent; color: #555; border: 1px solid #EEEBE2; }
    .action-btn.preview-btn:hover { background: #F9F8F4; color: #333; }
    .action-btn.download-btn { background: #1A1A1A; color: #FFF; }
    .action-btn.download-btn:hover { background: #333; }
    .action-btn.delete-btn { background: transparent; color: #ef4444; border: 1px solid #fee2e2; }
    .action-btn.delete-btn:hover { background: #fef2f2; }
    .action-btn.restore-btn { background: transparent; color: #f59e0b; border: 1px solid #fef3c7; }
    .action-btn.restore-btn:hover { background: #fffbeb; }
    .action-btn.disabled { opacity: 0.3; cursor: not-allowed; }

    /* File info modal */
    .file-info-name {
        margin: 0;
        padding: 0.75rem 1rem;
        background: var(--gray-50);
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        color: var(--gray-900);
        word-break: break-word;
    }

    /* Modals & Toast */
    .modal { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, .55); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1.5rem; }
    .modal.show { display: flex; animation: modalFadeIn .2s ease; }
    @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
    .modal-content { background: var(--white); border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 24px 60px rgba(15, 23, 42, .25); overflow: hidden; animation: slideUp .3s ease; }
    @keyframes slideUp { from { transform: translateY(16px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header { padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .modal-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--gray-900); display: flex; align-items: center; gap: .55rem; }
    .modal-body { padding: 1.5rem; }
    .modal-body p { margin: 0 0 .85rem 0; color: var(--gray-700); font-size: .9rem; }
    .modal-body strong { color: var(--gray-900); display: block; padding: .5rem .75rem; background: var(--gray-50); border: 1px solid var(--border); border-radius: 8px; word-break: break-word; }
    .close-btn { background: #fff; border: 1px solid var(--border); cursor: pointer; width: 32px; height: 32px; border-radius: 8px; color: var(--gray-500); font-size: 1.25rem; line-height: 1; }
    .close-btn:hover { background: var(--gray-50); color: var(--gray-800); }

    #successModal { background: none; backdrop-filter: none; align-items: flex-start; justify-content: center; pointer-events: none; padding: 0; }
    #successModal .modal-content { pointer-events: auto; width: auto; margin-top: 16px; border-radius: 10px; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; background: #f0fdf4; }
    .toast-row { display: flex; align-items: center; gap: .55rem; padding: .7rem .75rem .7rem .9rem; font-size: .88rem; font-weight: 600; color: #14532d; }
    .toast-dismiss { margin-left: .35rem; border: 0; background: transparent; color: #4d7c5f; cursor: pointer; padding: .2rem; border-radius: 6px; }

    @media (max-width: 992px) { .folder-list-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 768px) { .folder-list-grid { grid-template-columns: 1fr; } .files-grid { grid-template-columns: 1fr; } .folder-contents-wrap { padding: 1rem; margin: 0 -1.25rem -1.25rem -1.25rem; } }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<div class="dashboard-container">
    <div class="repo-section">
        
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert" style="margin:1.5rem 1.75rem 0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!$filterDept): ?>
            <!-- ===== DEPARTMENT CARDS ===== -->
            <div class="repo-body">
                <div class="dept-grid" id="deptGrid">
                    <?php foreach ($departments as $d):
                        $stats = $deptStats[$d['id']] ?? ['file_count' => 0, 'folder_count' => 0, 'last_archived' => 'Never'];
                        $code = $d['code'] ?? '';
                    ?>
                        <a href="admin_archive_documents.php?dept=<?= $d['id'] ?>" class="dept-card" data-name="<?= sanitize(strtolower($d['department_name'])) ?>">
                            <span class="arrow">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                            </span>
                            <div class="folder-icon-big"><?= archive_folder_icon() ?></div>
                            <div class="dept-name"><?= sanitize($d['department_name']) ?></div>
                            <?php if ($code): ?>
                                <div class="dept-code"><?= sanitize($code) ?></div>
                            <?php endif; ?>
                            <div class="dept-stats">
                                <span class="stat-pill">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                                    <span class="stat-number"><?= $stats['file_count'] ?></span> Archived
                                </span>
                                <span class="stat-pill">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 4H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>
                                    <span class="stat-number"><?= $stats['folder_count'] ?></span> Folders
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- ===== FILE LIST VIEW (ARCHIVES) ===== -->
            <div class="repo-body">
                <div class="breadcrumb-nav">
                    <a href="admin_archive_documents.php" title="All Departments">
                        <svg class="home-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" /></svg>
                        All Departments
                    </a>
                    <span class="separator">/</span>

                    <?php if ($selectedFolder == 0 || !$selectedFolder): ?>
                        <span class="current"><?= sanitize($selectedDeptName) ?></span>
                    <?php else: ?>
                        <a href="admin_archive_documents.php?dept=<?= $filterDept ?>"><?= sanitize($selectedDeptName) ?></a>
                        <?php
                        $total = count($breadcrumb);
                        foreach ($breadcrumb as $index => $folder):
                            $isLast = ($index === $total - 1);
                        ?>
                            <span class="separator">/</span>
                            <?php if ($isLast): ?>
                                <span class="current"><?= sanitize($folder['name']) ?></span>
                            <?php else: ?>
                                <a href="admin_archive_documents.php?dept=<?= $filterDept ?>&folder=<?= $folder['id'] ?>"><?= sanitize($folder['name']) ?></a>
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
                        <div class="empty-state" style="text-align: center; padding: 3.5rem 1.5rem; color: var(--gray-500);">
                            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 1rem; opacity:0.3;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
                            <p style="font-size:0.92rem; font-weight:600; margin:0;">No archived items found in this folder.</p>
                        </div>
                    <?php else: ?>
                        
                        <?php if (!empty($folderItems)): ?>
                            <div class="folder-list-grid">
                                <?php foreach ($folderItems as $item): ?>
                                    <div class="folder-item-card" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-csp-href="admin_archive_documents.php?dept=<?= $filterDept ?>&folder=<?= $item['id'] ?>">
                                        <a href="?dept=<?= $filterDept ?>&folder=<?= $item['id'] ?>" class="folder-link-area">
                                            <div class="folder-icon"><?= archive_folder_icon() ?></div>
                                            <div class="folder-info-group">
                                                <div class="folder-name"><?= sanitize($item['name']) ?></div>
                                                <div class="folder-count"><?= (int)($item['file_count'] ?? 0) ?> files</div>
                                            </div>
                                            <span class="folder-arrow">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6" /></svg>
                                            </span>
                                        </a>
                                        <a href="?action=folder_zip&folder=<?= $item['id'] ?>&dept=<?= $filterDept ?>" class="folder-zip-btn" title="Download folder as ZIP" onclick="return confirm('Download folder as ZIP?');">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fileItems)): ?>
                            <div class="files-grid" id="contentList">
                                <?php foreach ($fileItems as $item): 
                                    $fileExists = $item['file_exists'] ?? false;
                                    $uploadDate = !empty($item['upload_date']) ? date('M d', strtotime($item['upload_date'])) : 'Unknown';
                                    $visual = archive_file_visual($item['file_type'] ?? '', $item['file_ext'] ?? '');
                                ?>
                                    <div class="file-card" data-row-id="<?= $item['source'] ?>:<?= (int)$item['id'] ?>" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-type="file" data-doc-name="<?= sanitize($item['name']) ?>">
                                        <div class="icon-wrapper">
                                            <?= $visual['svg'] ?>
                                        </div>
                                        <div class="file-info">
                                            <div class="file-name" title="<?= sanitize($item['name']) ?>">
                                                <?= sanitize($item['name']) ?>
                                                <?php if (!$fileExists): ?><span style="color:var(--danger); font-size:0.7rem;">(Missing)</span><?php endif; ?>
                                            </div>
                                            <div class="file-meta">
                                                <span><?= $uploadDate ?></span>
                                            </div>
                                        </div>
                                        <div class="file-actions">
                                            <button type="button" class="action-btn preview-btn view-btn"
                                                data-id="<?= $item['id'] ?>"
                                                data-type="<?= $item['source'] ?>"
                                                data-title="<?= sanitize($item['name']) ?>"
                                                data-filename="<?= sanitize($item['filename']) ?>"
                                                title="Preview">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                                            </button>
                                            
                                            <?php if ($fileExists): ?>
                                                <button type="button" class="action-btn restore-btn restore-btn" title="Restore to active" data-id="<?= $item['id'] ?>" data-source="<?= $item['source'] ?>" data-title="<?= sanitize($item['name']) ?>">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.66 0 3-4.03 3-9s-1.34-9-3-9m0 18c-1.66 0-3-4.03-3-9s1.34-9 3-9" /></svg>
                                                </button>
                                                <button type="button" class="action-btn delete-btn delete-btn" title="Permanently Delete" data-id="<?= $item['id'] ?>" data-source="<?= $item['source'] ?>" data-title="<?= sanitize($item['name']) ?>">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                                                </button>
                                            <?php else: ?>
                                                <span class="action-btn disabled" title="File not found">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" /></svg>
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
    </div> 
</div>

<!-- File Info Modal -->
<div id="fileInfoModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                Document Name
            </h3>
        </div>
        <div class="modal-body">
            <p class="file-info-name" id="fileInfoName"></p>
            <div style="display: flex; justify-content: flex-end; margin-top: 1.25rem;">
            </div>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div id="restoreModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Restore Document</h3>
            <button class="close-btn" data-modal="restoreModal">&times;</button>
        </div>
        <div class="modal-body">
            <p>Restore this document to the active repository?</p>
            <p><strong id="restoreDocTitle"></strong></p>
            <form method="post" action="admin_archive_documents.php" id="restoreForm" style="margin-top: 1.5rem;">
                <input type="hidden" name="unarchive" id="restore_id">
                <input type="hidden" name="unarchive_source" id="restore_source" value="document">
                <input type="hidden" name="dept" value="<?= $filterDept ?>">
                <input type="hidden" name="folder" value="<?= $selectedFolder ?>">
                <?= csrfField() ?>
                <div style="display: flex; gap: .75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" data-modal="restoreModal">Cancel</button>
                    <button type="submit" class="btn btn-success" style="background:var(--success);color:white;border-color:var(--success)">Confirm Restore</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Permanently Delete</h3>
            <button class="close-btn" data-modal="deleteModal">&times;</button>
        </div>
        <div class="modal-body">
            <p>Warning: This will permanently delete the file. This action cannot be undone.</p>
            <p><strong id="deleteDocTitle"></strong></p>
            <form method="post" action="admin_archive_documents.php" id="deleteForm" style="margin-top: 1.5rem;">
                <input type="hidden" name="delete_id" id="delete_id">
                <input type="hidden" name="delete_source" id="delete_source" value="document">
                <input type="hidden" name="dept" value="<?= $filterDept ?>">
                <input type="hidden" name="folder" value="<?= $selectedFolder ?>">
                <?= csrfField() ?>
                <label for="deleteAdminPassword" style="display:block;margin-bottom:.4rem;font-size:.82rem;font-weight:600;color:var(--gray-600);">Confirm your admin password</label>
                <input type="password" id="deleteAdminPassword" name="admin_password" autocomplete="current-password" required style="margin-bottom:1rem;width:100%;padding:.65rem .85rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:.875rem;">
                <div style="display: flex; gap: .75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" data-modal="deleteModal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background:var(--danger);color:white;border-color:var(--danger)">Confirm Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    var pendingArchiveItem = null;

    function showArchiveFlash(message, ok) {
        var el = document.createElement('div');
        el.className = ok ? 'alert alert-success archive-flash' : 'alert alert-danger archive-flash';
        el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;padding:1rem;border-radius:8px;font-weight:600;' + (ok ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' : 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;');
        el.innerHTML = message;
        document.body.appendChild(el);
        setTimeout(function() { el.remove(); }, 3500);
    }

    // ---- File card click → show full document name modal ----
    // Clicks that originate from the action buttons (.file-actions) must NOT
    // trigger the name-modal, so any click inside .file-actions is ignored.
    document.querySelectorAll('.file-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            if (e.target.closest('.file-actions')) return;
            document.getElementById('fileInfoName').textContent = this.dataset.docName || '';
            document.getElementById('fileInfoModal').classList.add('show');
        });
    });

    // Delete modal trigger
    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            pendingArchiveItem = this.closest('.file-card');
            document.getElementById('delete_id').value = this.dataset.id;
            document.getElementById('delete_source').value = this.dataset.source || 'document';
            document.getElementById('deleteDocTitle').textContent = this.dataset.title || 'Document';
            document.getElementById('deleteModal').classList.add('show');
        });
    });

    // Restore modal trigger
    document.querySelectorAll('.restore-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            pendingArchiveItem = this.closest('.file-card');
            document.getElementById('restore_id').value = this.dataset.id;
            document.getElementById('restore_source').value = this.dataset.source || 'document';
            document.getElementById('restoreDocTitle').textContent = this.dataset.title || 'Document';
            document.getElementById('restoreModal').classList.add('show');
        });
    });

    // NOTE: .view-btn / .preview-btn has NO added click handler here.
    // Whatever existing viewer logic (from header.php / footer.php / shared
    // JS that listens on .view-btn or data-id/data-type/data-filename) is
    // left completely untouched and will keep working as before.

    function submitArchiveAction(form) {
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        fetch(form.action || 'admin_archive_documents.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(form)),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) {
            return r.json();
        }).then(function(d) {
            var item = pendingArchiveItem;
            if (d.success) {
                var modal = form.closest('.modal');
                if (modal) modal.classList.remove('show');
                if (item && item.parentNode) {
                    item.style.transition = 'opacity 0.3s';
                    item.style.opacity = '0';
                    setTimeout(function() { item.remove(); }, 250);
                }
                showArchiveFlash(d.message || 'Action successful.', true);
            } else {
                showArchiveFlash(d.message || d.error || 'Action failed.', false);
            }
            if (submitBtn) submitBtn.disabled = false;
            pendingArchiveItem = null;
        }).catch(function() {
            if (submitBtn) submitBtn.disabled = false;
            showArchiveFlash('Request failed. Please try again.', false);
        });
    }

    var archiveDeleteForm = document.getElementById('deleteForm');
    var archiveRestoreForm = document.getElementById('restoreForm');
    if (archiveDeleteForm) archiveDeleteForm.addEventListener('submit', function(e) { e.preventDefault(); submitArchiveAction(this); });
    if (archiveRestoreForm) archiveRestoreForm.addEventListener('submit', function(e) { e.preventDefault(); submitArchiveAction(this); });

    document.querySelectorAll('[data-modal]').forEach(function(btn) {
        btn.addEventListener('click', function() { document.getElementById(this.dataset.modal).classList.remove('show'); });
    });
    window.onclick = function(event) { if (event.target.classList.contains('modal')) { event.target.classList.remove('show'); } };

    var __archSuccessTimer = null;
    function closeArchSuccessToast() {
        var m = document.getElementById('successModal');
        m.classList.remove('show');
        clearTimeout(__archSuccessTimer);
    }
    <?php if ($success): ?>
        document.getElementById('successMsg').textContent = <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.getElementById('successModal').classList.add('show');
        __archSuccessTimer = setTimeout(closeArchSuccessToast, 2600);
    <?php endif; ?>
</script>

<div id="successModal" class="modal">
    <div class="modal-content" role="status" aria-live="polite">
        <div class="toast-row">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            <span id="successMsg">Done.</span>
            <button type="button" class="toast-dismiss" data-csp-action="closeArchSuccessToast" aria-label="Dismiss">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>