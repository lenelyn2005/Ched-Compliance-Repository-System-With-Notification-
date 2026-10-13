<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

// Make sure every department has its archive folder
ensureDepartmentFolders($pdo, ['admin_archive']);

// Auto-archive expired approved documents
autoArchiveDocuments($pdo);

// -------------------------------------------------------------------------
// Helper: Get folder path segments [id, name] from root to the folder ID
// -------------------------------------------------------------------------
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

// -------------------------------------------------------------------------
// Process GET parameters
// -------------------------------------------------------------------------
$success = '';
$error = '';
$filterDept = isset($_GET['dept']) && is_numeric($_GET['dept']) ? (int) $_GET['dept'] : null;
// ponytail: array-typed search[]=x would fatal in trim() - treat as empty
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
            $filePath = securePath($doc['file_path']);
            if ($filePath && file_exists($filePath) && !@unlink($filePath)) {
                error_log('[CHED] archive delete: unlink failed for ph_documents #' . $deleteId . ': ' . $filePath);
                $error = 'Could not remove the file from storage; the record was not deleted.';
                $doc = false;
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
            $filePath = securePath($doc['file_path']);
            if ($filePath && file_exists($filePath) && !@unlink($filePath)) {
                error_log('[CHED] archive delete: unlink failed for documents #' . $deleteId . ': ' . $filePath);
                $error = 'Could not remove the file from storage; the record was not deleted.';
                $doc = false;
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
        $oldPath = securePath($doc['file_path']);
        $filename = basename($doc['file_path']);
        $newRelativePath = storageSubdir('admin_repository', $pdo, (int) $doc['department_id'], $table === 'documents' ? documentFolderId($pdo, 'documents', $id) : null) . '/' . $filename;
        $newFullPath = UPLOAD_PATH . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
        if (!is_dir(dirname($newFullPath))) {
            mkdir(dirname($newFullPath), 0755, true);
        }

        $fileRestored = false;

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

        // Preserve expiry_date and validity_status on restore (do not clear them)
        $pdo->prepare("UPDATE $table SET file_path = ?, archived = 0 WHERE id = ?")
            ->execute([$fileRestored ? $newRelativePath : $doc['file_path'], $id]);
        logAudit($pdo, 'unarchive', ($source === 'ph_document' ? 'PH document' : 'Document') . ' restored from archive: ' . $doc['title'], $source, $id);

        // ponytail: PH notification removed — archive restore is admin-only awareness

        $success = $fileRestored ? "Document successfully restored from archive." : "Document restored (file was missing - restored path in DB only).";
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
            'file_exists'       => (bool)securePath($doc['file_path']),
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
    /**
     * Returns ['class' => slug, 'svg' => inline-SVG].
     * Real Windows File Explorer–style document icons: colored document
     * shape with folded corner + file-type label. No wrapper, no background.
     */
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

        // Shared document body path (viewBox 0 0 48 48)
        $body = 'M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z';
        $fold = 'M30 2l10 10h-8a2 2 0 0 1-2-2V2z';

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

            // Image — white document with a photo thumbnail
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

            // Text — white document with text lines
            'text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">'
                . '<path d="' . $body . '" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/>'
                . '<path d="' . $fold . '" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/>'
                . '<line x1="16" y1="22" x2="32" y2="22" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/>'
                . '<line x1="16" y1="27" x2="32" y2="27" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/>'
                . '<line x1="16" y1="32" x2="26" y2="32" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/>'
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

        return ['class' => $key, 'svg' => $icons[$key] ?? $icons['document']];
    }
}

// Real Windows-style folder icon — no container
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
// ENHANCED UI STYLES — Arial-first, matching admin_manage_document.php
// -------------------------------------------------------------------------
ob_start();
?>
<style>
    @import url('offline/inter-font.css');
    /* ============================================================
       ARCHIVE — ENHANCED UI
       Same font + real File Explorer icons as admin_manage_document.php
       ============================================================ */

    :root {
        /* Brand */
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-light: #eef2ff;
        --primary-soft: #6366f1;

        --secondary: #64748b;

        --success: #10b981;
        --success-light: #d1fae5;
        --success-dark: #065f46;

        --warning: #f59e0b;
        --warning-light: #fef3c7;
        --warning-dark: #92400e;

        --danger: #ef4444;
        --danger-light: #fee2e2;
        --danger-dark: #b91c1c;

        --info: #3b82f6;
        --info-light: #dbeafe;

        /* Neutrals */
        --gray-50: #f8fafc;
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
        --border: #e6eaf1;

        /* Elevation */
        --shadow-xs: 0 1px 2px rgba(15, 23, 42, .04);
        --shadow-sm: 0 1px 3px rgba(15, 23, 42, .06), 0 1px 2px rgba(15, 23, 42, .04);
        --shadow-md: 0 4px 12px rgba(15, 23, 42, .07), 0 2px 4px rgba(15, 23, 42, .04);
        --shadow-lg: 0 12px 28px rgba(15, 23, 42, .10), 0 4px 10px rgba(15, 23, 42, .05);

        --radius-sm: 8px;
        --radius-md: 12px;
        --radius-lg: 16px;
        --radius-pill: 9999px;

        --transition: all .2s cubic-bezier(.4, 0, .2, 1);
        --ease-out: cubic-bezier(.16, 1, .3, 1);

        /* Eye-friendly, Arial-first system font stack (offline) — matches admin_manage_document.php */
        --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;

        /* Monospace for file extensions / codes */
        --font-mono: "Cascadia Mono", Consolas, "SF Mono", Monaco,
            "Courier New", monospace;

        --app-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 55%, #eef2f7 100%);
    }

    * {
        box-sizing: border-box;
    }

    body {
        background: var(--app-bg);
        background-attachment: fixed;
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
        border-radius: 4px;
    }

    ::selection {
        background: var(--primary-light);
        color: var(--primary-dark);
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
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .repo-body {
        padding: 1.5rem 1.5rem;
    }

    /* ============================================================
       BUTTONS
       ============================================================ */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        padding: .55rem 1rem;
        border-radius: 10px;
        font-family: inherit;
        font-size: .78rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.4;
        white-space: nowrap;
        letter-spacing: .01em;
    }

    .btn-sm {
        padding: .45rem .75rem;
        font-size: .75rem;
    }

    .btn-icon {
        padding: .5rem;
        min-width: 32px;
        min-height: 32px;
        justify-content: center;
        border-radius: 8px;
    }

    .btn-primary {
        background: var(--primary);
        color: var(--white);
        border-color: var(--primary);
        box-shadow: 0 1px 2px rgba(79, 70, 229, .2);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(79, 70, 229, .3);
    }

    .btn-primary:active {
        transform: translateY(0);
    }

    .btn-outline {
        background: #fff;
        border-color: var(--border);
        color: var(--gray-700);
        box-shadow: var(--shadow-xs);
    }

    .btn-outline:hover {
        border-color: var(--gray-300);
        color: var(--gray-800);
        background: var(--gray-50);
        box-shadow: var(--shadow-sm);
        transform: translateY(-1px);
    }

    .btn-danger {
        background: #fff;
        border-color: var(--border);
        color: var(--gray-600);
        box-shadow: var(--shadow-xs);
    }

    .btn-danger:hover {
        background: var(--danger-light);
        border-color: #fecaca;
        color: var(--danger);
        box-shadow: var(--shadow-sm);
        transform: translateY(-1px);
    }

    .btn-success {
        background: #fff;
        border-color: var(--border);
        color: var(--gray-600);
        box-shadow: var(--shadow-xs);
    }

    .btn-success:hover {
        background: var(--success-light);
        border-color: #a7f3d0;
        color: var(--success-dark);
        box-shadow: var(--shadow-sm);
        transform: translateY(-1px);
    }

    .btn-disabled {
        opacity: .45;
        cursor: not-allowed;
        pointer-events: none;
    }

    /* ============================================================
       BREADCRUMB
       ============================================================ */
    .breadcrumb-nav {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .35rem;
        font-family: var(--font-sans);
        font-size: .82rem;
        font-weight: 500;
        color: var(--gray-500);
        margin-bottom: 1.1rem;
        padding: .5rem 1rem .5rem .75rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-pill);
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        box-shadow: var(--shadow-xs);
    }

    .breadcrumb-nav a {
        color: var(--gray-700);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .15rem .4rem;
        border-radius: 6px;
    }

    .breadcrumb-nav a:hover {
        color: var(--primary);
        background: var(--primary-light);
    }

    .breadcrumb-nav .current {
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -.005em;
        padding: .15rem .4rem;
    }

    .breadcrumb-nav .separator {
        color: var(--gray-300);
        font-weight: 400;
        padding: 0 .1rem;
    }

    .breadcrumb-nav .home-icon {
        width: 15px;
        height: 15px;
        display: inline-block;
        vertical-align: middle;
        color: var(--primary);
    }

    /* ============================================================
       DEPARTMENT CARDS
       ============================================================ */
    .dept-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: .9rem;
    }

    .dept-card {
        position: relative;
        background: #fff;
        border-radius: 12px;
        border: 1px solid var(--border);
        padding: 1.05rem 1rem .9rem;
        transition: var(--transition);
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        box-shadow: var(--shadow-xs);
        display: flex;
        flex-direction: column;
        min-height: 150px;
        overflow: hidden;
    }

    .dept-card::before {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--primary) 0%, var(--primary-soft) 100%);
        opacity: 0;
        transition: opacity .25s ease;
    }

    .dept-card::after {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at 100% 0%,
                rgba(79, 70, 229, .06) 0%, transparent 55%);
        opacity: 0;
        transition: opacity .3s ease;
        pointer-events: none;
    }

    .dept-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-lg);
        border-color: #c7d2fe;
    }

    .dept-card:hover::before,
    .dept-card:hover::after {
        opacity: 1;
    }

    /* Real folder icon — no background box, just the icon */
    .dept-card .folder-icon-big {
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        width: auto;
        height: auto;
        padding: 0;
        margin-bottom: .55rem;
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
        font-size: .95rem;
        font-weight: 700;
        line-height: 1.3;
        margin: 0 0 .25rem 0;
        color: var(--gray-900);
        letter-spacing: -.01em;
    }

    .dept-card .dept-code {
        font-family: var(--font-mono);
        font-size: .65rem;
        color: var(--gray-600);
        font-weight: 600;
        margin-bottom: .55rem;
        text-transform: uppercase;
        letter-spacing: .09em;
        background: var(--gray-100);
        align-self: flex-start;
        padding: .2rem .55rem;
        border-radius: var(--radius-pill);
    }

    .dept-stats {
        display: flex;
        gap: .4rem;
        margin-top: auto;
        padding-top: .65rem;
        border-top: 1px solid var(--gray-100);
    }

    .dept-stats .stat-pill {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .3rem;
        background: var(--gray-50);
        border: 1px solid var(--gray-200);
        padding: .38rem .5rem;
        border-radius: 9px;
        font-size: .7rem;
        line-height: 1.3;
        transition: var(--transition);
        min-width: 0;
    }

    .dept-stats .stat-pill:hover {
        border-color: var(--gray-300);
        background: #fff;
    }

    .dept-stats .stat-pill svg {
        width: 13px;
        height: 13px;
        flex-shrink: 0;
        opacity: .55;
    }

    .dept-stats .stat-pill .stat-number {
        font-weight: 800;
        color: var(--gray-800);
        font-variant-numeric: tabular-nums lining-nums;
    }

    .dept-stats .stat-pill .stat-label {
        color: var(--gray-500);
        font-weight: 500;
    }

    .dept-card .arrow {
        position: absolute;
        top: .85rem;
        right: .85rem;
        width: 26px;
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--gray-50);
        border: 1px solid var(--border);
        border-radius: 8px;
        transition: var(--transition);
        font-size: .8rem;
        color: var(--gray-500);
        font-weight: 700;
    }

    .dept-card:hover .arrow {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
        transform: translateX(2px);
    }

    .dept-card .last-archived {
        font-size: .72rem;
        color: var(--gray-400);
        margin-top: .35rem;
        font-weight: 500;
    }

    /* ============================================================
       CONTENT LIST
       ============================================================ */
    .content-list {
        display: flex;
        flex-direction: column;
        gap: .55rem;
    }

    .content-item {
        display: flex;
        align-items: center;
        padding: .95rem 1.15rem;
        border: 1px solid var(--border);
        border-radius: 12px;
        transition: var(--transition);
        gap: 1rem;
        cursor: default;
        background: #fff;
        position: relative;
        overflow: hidden;
    }

    .content-item::before {
        content: '';
        position: absolute;
        top: 12px;
        bottom: 12px;
        left: 0;
        width: 3px;
        border-radius: 0 3px 3px 0;
        background: var(--primary);
        opacity: 0;
        transition: opacity .2s ease;
    }

    .content-item:hover {
        background: var(--gray-50);
        border-color: var(--gray-200);
        box-shadow: var(--shadow-sm);
        transform: translateX(2px);
    }

    .content-item:hover::before {
        opacity: 1;
    }

    .content-item.folder-item {
        cursor: pointer;
    }

    .content-item.folder-item::before {
        background: var(--warning);
    }

    .content-item.folder-item:hover {
        background: linear-gradient(180deg, #ffffff 0%, #fefce8 100%);
        border-color: #fde68a;
        box-shadow: var(--shadow-md);
        transform: translateX(3px);
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
        gap: .2rem;
    }

    .content-item .info .name {
        font-weight: 700;
        font-size: .9rem;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-wrap: wrap;
        line-height: 1.4;
        overflow-wrap: break-word;
    }

    .content-item .info .name .badge {
        display: inline-flex;
        align-items: center;
        padding: .18rem .55rem;
        border-radius: var(--radius-pill);
        font-size: .62rem;
        font-weight: 700;
        line-height: 1.25;
        background: var(--gray-100);
        color: var(--gray-600);
        text-transform: uppercase;
        letter-spacing: .06em;
        border: 1px solid var(--gray-200);
    }

    .content-item .info .name .badge.missing {
        background: var(--danger-light);
        color: var(--danger-dark);
        border-color: #fecaca;
    }

    .content-item .info .meta {
        font-size: .74rem;
        color: var(--gray-500);
        display: flex;
        gap: .75rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .content-item .info .meta span {
        display: inline-flex;
        align-items: center;
        gap: .28rem;
    }

    .content-item .actions {
        display: flex;
        gap: .3rem;
        flex-shrink: 0;
        align-items: center;
    }

    /* ============================================================
       EMPTY STATE
       ============================================================ */
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
        color: var(--gray-500);
        border: 1px dashed var(--border);
        border-radius: 12px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .empty-state svg {
        width: 42px;
        height: 42px;
        margin: 0 auto .85rem;
        display: block;
        opacity: .45;
        color: var(--gray-400);
    }

    .empty-state p {
        font-size: .92rem;
        font-weight: 600;
        color: var(--gray-500);
        margin: 0;
    }

    /* ============================================================
       MODALS
       ============================================================ */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, .55);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }

    .modal.show {
        display: flex;
        animation: modalFadeIn .2s ease;
    }

    @keyframes modalFadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    .modal-content {
        background: var(--white);
        border-radius: 16px;
        width: 100%;
        max-width: 520px;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .25), 0 8px 24px rgba(15, 23, 42, .12);
        animation: slideUp .3s var(--ease-out);
        border: 1px solid var(--border);
        overflow: hidden;
    }

    @keyframes slideUp {
        from {
            transform: translateY(16px) scale(.98);
            opacity: 0;
        }

        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }

    /* Success toast — top banner, hugs text, no backdrop, page stays clickable */
    #successModal {
        background: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        padding: 0;
        align-items: flex-start;
        justify-content: center;
        pointer-events: none;
    }

    #successModal.show {
        animation: none;
    }

    #successModal .modal-content {
        pointer-events: auto;
        width: auto;
        max-width: calc(100vw - 2rem);
        margin-top: 16px;
        border-radius: 10px;
        border: 1px solid #bbf7d0;
        border-left: 4px solid #16a34a;
        background: #f0fdf4;
        box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
        animation: archToastDrop .26s cubic-bezier(.2, .9, .3, 1);
        overflow: visible;
    }

    @keyframes archToastDrop {
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

    .modal-header {
        padding: 1.15rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
    }

    .modal-header h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: .55rem;
        letter-spacing: -.01em;
    }

    .modal-header h3 svg {
        flex-shrink: 0;
    }

    .modal-header h3::before {
        display: none;
    }

    .restore-modal .modal-header h3 svg {
        color: var(--success);
    }

    .modal:not(.restore-modal) .modal-header h3 svg {
        color: var(--danger);
    }

    .modal-body {
        padding: 1.5rem;
        background: #fff;
    }

    .modal-body p {
        margin: 0 0 .85rem 0;
        color: var(--gray-700);
        font-size: .9rem;
        line-height: 1.55;
    }

    .modal-body p strong {
        color: var(--gray-900);
        font-weight: 700;
        display: inline-block;
        padding: .5rem .75rem;
        background: var(--gray-50);
        border: 1px solid var(--border);
        border-radius: 8px;
        max-width: 100%;
        word-break: break-word;
    }

    .close-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff;
        border: 1px solid var(--border);
        cursor: pointer;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        color: var(--gray-500);
        transition: var(--transition);
        font-size: 1.25rem;
        line-height: 1;
    }

    .close-btn:hover {
        background: var(--gray-50);
        color: var(--gray-800);
        border-color: var(--gray-300);
    }

    /* ============================================================
       ALERTS
       ============================================================ */
    .alert {
        padding: .9rem 1.1rem;
        border-radius: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: .75rem;
        font-size: .9rem;
        border: 1px solid;
        box-shadow: var(--shadow-sm);
        animation: slideDown .25s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-8px);
        }

        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }

    .alert-success {
        background: linear-gradient(135deg, #ecfdf5 0%, #f6fffb 100%);
        color: var(--success-dark);
        border-color: #a7f3d0;
    }

    .alert-danger {
        background: linear-gradient(135deg, #fef2f2 0%, #fff7f7 100%);
        color: var(--danger-dark);
        border-color: #fecaca;
    }

    .archive-flash {
        position: fixed;
        top: 1.25rem;
        left: 50%;
        transform: translateX(-50%);
        z-index: 20000;
        max-width: min(420px, calc(100vw - 2rem));
        margin: 0;
        box-shadow: var(--shadow-lg);
    }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 992px) {
        .dashboard-container {
            padding: .2rem;
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
            padding: .2rem;
        }

        .repo-body {
            padding: 1.25rem;
        }

        .dept-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .content-item {
            padding: .85rem;
            flex-wrap: wrap;
            gap: .7rem;
        }

        .content-item .actions {
            margin-left: auto;
        }

        .breadcrumb-nav {
            font-size: .8rem;
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

        .modal {
            padding: 1rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {

        *,
        *::before,
        *::after {
            animation-duration: .001ms !important;
            transition-duration: .001ms !important;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="dashboard-container">

    <div class="repo-section">

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert" aria-live="assertive" style="margin:0 0 1rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
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
                            <span class="arrow">→</span>
                            <div class="folder-icon-big"><?= archive_folder_icon() ?></div>
                            <div class="dept-name"><?= sanitize($d['department_name']) ?></div>
                            <?php if ($code): ?>
                                <div class="dept-code"><?= sanitize($code) ?></div>
                            <?php endif; ?>
                            <div class="dept-stats">
                                <span class="stat-pill">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="21 8 21 21 3 21 3 8" />
                                        <rect x="1" y="3" width="22" height="5" />
                                        <line x1="10" y1="12" x2="14" y2="12" />
                                    </svg>
                                    <span class="stat-number"><?= $stats['file_count'] ?></span>
                                    <span class="stat-label">Archived</span>
                                </span>
                                <span class="stat-pill">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 4H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z" />
                                    </svg>
                                    <span class="stat-number"><?= $stats['folder_count'] ?></span>
                                    <span class="stat-label">Folders</span>
                                </span>
                            </div>
                            <div class="last-archived">Last archived: <?= $stats['last_archived'] ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- ===== FILE LIST VIEW (ARCHIVES) ===== -->
            <div class="repo-body">

                <!-- Breadcrumb Navigation -->
                <div class="breadcrumb-nav">
                    <a href="admin_archive_documents.php" title="All Departments">
                        <svg class="home-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
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

                <div class="content-list" id="contentList">
                    <?php if (empty($folderContents)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            <p>No archived items found in this folder.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($folderContents as $item): ?>
                            <?php if ($item['is_folder']): ?>
                                <div class="content-item folder-item" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-type="folder"
                                    data-csp-href="admin_archive_documents.php?dept=<?= $filterDept ?>&folder=<?= $item['id'] ?>">
                                    <div class="icon folder-icon">
                                        <?= archive_folder_icon() ?>
                                    </div>
                                    <div class="info">
                                        <div class="name"><?= sanitize($item['name']) ?></div>
                                        <div class="meta">
                                            <span>Folder</span>
                                        </div>
                                    </div>
                                    <div class="actions">
                                        <span class="btn btn-sm btn-icon" style="cursor:default;opacity:.5;">›</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php
                                $fileExists = $item['file_exists'] ?? false;
                                $uploadDate = !empty($item['upload_date']) ? date('M d, Y g:i A', strtotime($item['upload_date'])) : 'Unknown';
                                $visual = archive_file_visual($item['file_type'] ?? '', $item['file_ext'] ?? '');
                                ?>
                                <div class="content-item" data-search="<?= sanitize(strtolower($item['name'])) ?>" data-type="file" data-filetype="<?= $visual['class'] ?>">
                                    <div class="icon file-icon">
                                        <?= $visual['svg'] ?>
                                    </div>
                                    <div class="info">
                                        <div class="name">
                                            <?= sanitize($item['name']) ?>
                                            <?php if (!$fileExists): ?>
                                                <span class="badge missing">Missing</span>
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
                                        <button class="btn btn-sm btn-outline btn-icon view-btn"
                                            data-id="<?= $item['id'] ?>"
                                            data-type="<?= $item['source'] ?>"
                                            data-title="<?= sanitize($item['name']) ?>"
                                            data-filename="<?= sanitize($item['filename']) ?>"
                                            title="Preview">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                        </button>
                                        <?php if ($fileExists): ?>
                                            <button type="button" class="btn btn-sm btn-success btn-icon restore-btn" title="Restore to active" data-id="<?= $item['id'] ?>" data-source="<?= $item['source'] ?>" data-title="<?= sanitize($item['name']) ?>">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.66 0 3-4.03 3-9s-1.34-9-3-9m0 18c-1.66 0-3-4.03-3-9s1.34-9 3-9" />
                                                </svg>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger btn-icon delete-btn" title="Permanently Delete" data-id="<?= $item['id'] ?>" data-source="<?= $item['source'] ?>" data-title="<?= sanitize($item['name']) ?>">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="3 6 5 6 21 6" />
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                </svg>
                                            </button>
                                        <?php else: ?>
                                            <span class="btn btn-sm btn-disabled btn-icon" title="File not found">
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

<!-- Restore Confirmation Modal -->
<div id="restoreModal" class="modal restore-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.66 0 3-4.03 3-9s-1.34-9-3-9m0 18c-1.66 0-3-4.03-3-9s1.34-9 3-9" />
                </svg>
                Restore Document
            </h3>
            <button class="close-btn" data-modal="restoreModal">&times;</button>
        </div>
        <div class="modal-body">
            <p>Restore this document to the active repository?</p>
            <p style="margin-top: .75rem;"><strong id="restoreDocTitle"></strong></p>
            <form method="post" action="admin_archive_documents.php" id="restoreForm" style="margin-top: 1.5rem;">
                <input type="hidden" name="unarchive" id="restore_id">
                <input type="hidden" name="unarchive_source" id="restore_source" value="document">
                <input type="hidden" name="dept" value="<?= $filterDept ?>">
                <input type="hidden" name="folder" value="<?= $selectedFolder ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <?= csrfField() ?>
                <div style="display: flex; gap: .75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" data-modal="restoreModal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Restore</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Permanently Delete
            </h3>
            <button class="close-btn" data-modal="deleteModal">&times;</button>
        </div>
        <div class="modal-body">
            <p>Warning: This will permanently delete the file. This action cannot be undone.</p>
            <p style="margin-top: .75rem;"><strong id="deleteDocTitle"></strong></p>
            <form method="post" action="admin_archive_documents.php" id="deleteForm" style="margin-top: 1.5rem;">
                <input type="hidden" name="delete_id" id="delete_id">
                <input type="hidden" name="delete_source" id="delete_source" value="document">
                <input type="hidden" name="dept" value="<?= $filterDept ?>">
                <input type="hidden" name="folder" value="<?= $selectedFolder ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <?= csrfField() ?>
                <label for="deleteAdminPassword" style="display:block;margin-bottom:.4rem;font-size:.82rem;font-weight:600;color:var(--gray-600);">Confirm your admin password</label>
                <input type="password" id="deleteAdminPassword" name="admin_password" class="form-control" autocomplete="current-password" required style="margin-bottom:1rem;width:100%;padding:.65rem .85rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:.875rem;">
                <div style="display: flex; gap: .75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" data-modal="deleteModal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Delete</button>
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
        el.setAttribute('role', ok ? 'status' : 'alert');
        el.setAttribute('aria-live', ok ? 'polite' : 'assertive');
        var icon = ok ?
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 11 14.01"/></svg>' :
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        el.innerHTML = icon + '<span></span>';
        el.querySelector('span').textContent = message;
        document.body.appendChild(el);
        setTimeout(function() {
            el.style.transition = 'opacity 0.4s';
            el.style.opacity = '0';
            setTimeout(function() {
                el.remove();
            }, 400);
        }, 4000);
    }

    // Delete modal trigger
    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            pendingArchiveItem = this.closest('.content-item');
            document.getElementById('delete_id').value = this.dataset.id;
            document.getElementById('delete_source').value = this.dataset.source || 'document';
            document.getElementById('deleteDocTitle').textContent = this.dataset.title || 'Document';
            document.getElementById('deleteModal').classList.add('show');
        });
    });

    // Restore modal trigger
    document.querySelectorAll('.restore-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            pendingArchiveItem = this.closest('.content-item');
            document.getElementById('restore_id').value = this.dataset.id;
            document.getElementById('restore_source').value = this.dataset.source || 'document';
            document.getElementById('restoreDocTitle').textContent = this.dataset.title || 'Document';
            document.getElementById('restoreModal').classList.add('show');
        });
    });

    function submitArchiveAction(form) {
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        fetch(form.action || 'admin_archive_documents.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(form)),
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
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
                    setTimeout(function() {
                        item.remove();
                    }, 250);
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
    if (archiveDeleteForm) archiveDeleteForm.addEventListener('submit', function(e) {
        e.preventDefault();
        submitArchiveAction(this);
    });
    if (archiveRestoreForm) archiveRestoreForm.addEventListener('submit', function(e) {
        e.preventDefault();
        submitArchiveAction(this);
    });

    // Close modals on X, Cancel button, or background click
    document.querySelectorAll('[data-modal]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById(this.dataset.modal).classList.remove('show');
        });
    });
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('show');
        }
    };
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
<script nonce="<?= csp_nonce() ?>">
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
<?php include 'includes/footer.php'; ?>
