<?php
define('CHED_APP', true);
require_once 'config.php';

requireAdmin();

// Auto-archive - run only once per day (admin-only: runs after the guard so
// anonymous requests cannot trigger a state change)
$lastArchive = $_SESSION['last_archive_run'] ?? '';
$today = date('Y-m-d');
if ($lastArchive !== $today) {
    autoArchiveExpiredDocuments($pdo);
    $_SESSION['last_archive_run'] = $today;
}

// Define CSRF token BEFORE it's used in HTML forms
$csrfToken = getCsrfToken();

$filterDept = !empty($_GET['dept']) ? (int) $_GET['dept'] : null;
// '' = all submissions, otherwise the head role whose direct uploads to show
$filterUploader = in_array($_GET['uploader'] ?? '', ['department', 'unit_head'], true) ? $_GET['uploader'] : '';

// All departments for filter
$departments = $pdo->query("SELECT id, department_name FROM departments ORDER BY department_name")->fetchAll();

// Helper: Build URL for filter
function buildQueryString($params)
{
    $query = [];
    foreach ($params as $key => $value) {
        if (!empty($value)) {
            $query[$key] = $value;
        }
    }
    return empty($query) ? '' : '?' . http_build_query($query);
}

// -------------------------------------------------------------------------
// Helper: Get folder path from folder ID (climbing parent chain)
// -------------------------------------------------------------------------
function getFolderPath($pdo, $folderId)
{
    if (!$folderId) return '';
    $path = [];
    $currentId = $folderId;
    while ($currentId) {
        $stmt = $pdo->prepare("SELECT id, folder_name, parent_folder_id FROM department_folders WHERE id = ?");
        $stmt->execute([$currentId]);
        $folder = $stmt->fetch();
        if (!$folder) break;
        $path[] = $folder['folder_name'];
        $currentId = $folder['parent_folder_id'];
    }
    $path = array_reverse($path);
    return implode(' > ', $path);
}

// -------------------------------------------------------------------------
// NEEDS REVIEW (status = 'pending_admin')
// -------------------------------------------------------------------------
// Don't filter by archived - show expired docs with badge instead
$pendingConditions = ["d.archived = 0", "d.status = 'pending_admin'"];
$pendingParams = [];
if ($filterDept) {
    $pendingConditions[] = "d.department_id = ?";
    $pendingParams[] = $filterDept;
}
if ($filterUploader !== '') {
    $pendingConditions[] = "d.document_type = 'Direct Upload'";
    $pendingConditions[] = "uploader.role = ?";
    $pendingParams[] = $filterUploader;
}
$pendingWhere = 'WHERE ' . implode(' AND ', $pendingConditions);

// Documents – pending_admin (with folder info)
$pendingDocs = $pdo->prepare("
    SELECT d.*,
           d.program_head_comment AS program_head_comment,
           d.admin_comment,
           tr.teacher_id,
           dept.department_name,
           ph.name AS ph_name,
           dept_ph.name AS dept_ph_name,
           uploader.name as uploader_name,
           uploader.role as uploader_role,
           'document' AS doc_source,
           d.validity_status,
           dr.folder_id,
           dr.document_type AS req_doc_type,
           dr.category AS req_category,
           (SELECT dr2.folder_id FROM department_requirements dr2
            WHERE dr2.department_id = d.department_id AND dr2.category = d.category
              AND dr2.document_type = d.document_type LIMIT 1) AS fallback_folder_id
    FROM documents d
    LEFT JOIN departments dept ON d.department_id = dept.id
    LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    LEFT JOIN department_requirements dr ON tr.requirement_id = dr.id
    LEFT JOIN users ph ON d.reviewed_by_program_head = ph.id
    LEFT JOIN users uploader ON d.uploaded_by = uploader.id
    LEFT JOIN users dept_ph ON dept_ph.department_id = d.department_id AND dept_ph.role IN ('department','unit_head')
    $pendingWhere
    AND d.version = (
        SELECT MAX(d2.version)
        FROM documents d2
        WHERE IF(d.teacher_requirement_id IS NOT NULL,
            d2.teacher_requirement_id = d.teacher_requirement_id,
            IF(d.document_type = 'Direct Upload',
                d2.id = d.id,
                d2.department_id = d.department_id
                AND d2.category = d.category
                AND d2.document_type = d.document_type
                AND d2.teacher_requirement_id IS NULL))
    )
    AND d.file_path IS NOT NULL AND d.file_path != ''
    ORDER BY d.upload_date DESC
    LIMIT 300
");
$pendingDocs->execute($pendingParams);
$pendingDocs = $pendingDocs->fetchAll();

// ph_documents – pending_admin (with ph_requirement info)
$phPendingConditions = ["pd.archived = 0", "pd.status = 'pending_admin'"];
$phPendingParams = [];
if ($filterDept) {
    $phPendingConditions[] = "pd.department_id = ?";
    $phPendingParams[] = $filterDept;
}
if ($filterUploader !== '') {
    // Teacher submissions are never direct uploads, so the direct-upload tabs
    // list documents only. Without this they leaked into the filtered tab.
    $phPendingConditions[] = '1 = 0';
}
$phPendingWhere = 'WHERE ' . implode(' AND ', $phPendingConditions);

$pendingPhDocs = $pdo->prepare("
    SELECT pd.*,
           pd.program_head_comment AS program_head_comment,
           pd.admin_comment,
           tpr.teacher_id,
           dept.department_name,
           ph.name AS ph_name,
           dept_ph.name AS dept_ph_name,
           u.name as uploader_name,
           u.role as uploader_role,
           'ph_document' AS doc_source,
           phr.title AS requirement_title
    FROM ph_documents pd
    LEFT JOIN departments dept ON pd.department_id = dept.id
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    LEFT JOIN users ph ON pd.reviewed_by_program_head = ph.id
    LEFT JOIN users u ON pd.uploaded_by = u.id
    LEFT JOIN users dept_ph ON dept_ph.department_id = pd.department_id AND dept_ph.role IN ('department','unit_head')
    $phPendingWhere
    AND pd.version = (
        SELECT MAX(pd2.version)
        FROM ph_documents pd2
        WHERE IF(pd.teacher_ph_requirement_id IS NOT NULL,
            pd2.teacher_ph_requirement_id = pd.teacher_ph_requirement_id,
            pd2.id = pd.id)
    )
    AND pd.file_path IS NOT NULL AND pd.file_path != ''
    ORDER BY pd.upload_date DESC
    LIMIT 300
");
$pendingPhDocs->execute($phPendingParams);
$pendingPhDocs = $pendingPhDocs->fetchAll();

$pendingSubmissions = array_merge($pendingDocs, $pendingPhDocs);
usort($pendingSubmissions, function ($a, $b) {
    return strtotime($b['upload_date']) - strtotime($a['upload_date']);
});

// Status label mapping (only pending_admin shown - approved by PH, waiting for admin)
$statusLabels = [
    'pending_admin' => ['label' => 'Pending Review', 'class' => 'badge-warning'],
    'approved_admin' => ['label' => 'Approved', 'class' => 'badge-success'],
    'rejected_admin' => ['label' => 'Rejected', 'class' => 'badge-danger'],
];

// Live table refresh (3s poll). Read-only; role-guarded.
if (isAjaxRequest() && ($_POST['action'] ?? '') === 'refresh_submissions') {
    ob_start();
    foreach ($pendingSubmissions as $sub):
        $originString = !empty($sub['department_name']) ? sanitize($sub['department_name']) : 'No Department';
        if ($sub['doc_source'] === 'document') {
            $folderId = $sub['folder_id'] ?? null;
            if (!$folderId) $folderId = $sub['fallback_folder_id'] ?? null;
            if ($folderId) {
                $folderPath = getFolderPath($pdo, $folderId);
                if ($folderPath) $originString .= ' > ' . sanitize($folderPath);
            }
        } elseif ($sub['doc_source'] === 'ph_document') {
            $originString .= in_array($sub['uploader_role'] ?? '', ['department', 'unit_head'], true)
                ? ' > ' . phRoleName($sub['uploader_role']) . ' Requirement'
                : ' > Head Requirement';
        }
        $expiryDate = $sub['expiry_date'] ?? null;
        $isExpired = false;
        $expiryLabel = '';
        $expiryState = classifyExpiryStatus($expiryDate);
        if ($expiryDate && $expiryState !== 'active') {
            $isExpired = true;
            $expiryLabel = expiryStatusLabel($expiryState);
        }
        ?>
        <tr>
            <td><div class="doc-info"><span class="doc-title"><?= sanitize($sub['title']) ?></span></div></td>
            <td><div class="origin-cell"><span class="origin-dept"><?= $originString ?></span><?php if (!empty($sub['uploader_name'])): ?><span class="origin-dept" style="color:var(--gray-500);"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align:-1px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>Uploaded by: <?= in_array($sub['uploader_role'] ?? '', ['department', 'unit_head'], true) ? phRoleName($sub['uploader_role']) : sanitize($sub['uploader_name']) ?></span><?php endif; ?></div></td>
            <td><span class="badge badge-warning"><?= $statusLabels['pending_admin']['label'] ?></span><?php if ($isExpired && $expiryDate): ?><span class="badge badge-danger" style="margin-left: 0.25rem;" title="Deadline: <?= date('M j, Y', strtotime($expiryDate)) ?>"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg><?= $expiryLabel ?></span><?php endif; ?></td>
            <td><?= date('M j, Y', strtotime($sub['upload_date'])) ?></td>
            <td class="text-right"><div class="btn-group">
                <button class="btn btn-sm btn-outline view-btn clickable" data-id="<?= $sub['id'] ?>" data-type="<?= $sub['doc_source'] ?>" data-title="<?= sanitize($sub['title'] ?? 'Document') ?>" data-filename="<?= sanitize($sub['filename'] ?? '') ?>" data-extension="<?= strtolower(pathinfo($sub['filename'] ?? '', PATHINFO_EXTENSION)) ?>" title="View Document"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>View</button>
                <button type="button" class="btn btn-sm btn-success approve-btn clickable" data-id="<?= $sub['id'] ?>" data-type="<?= $sub['doc_source'] ?? 'document' ?>" data-title="<?= sanitize($sub['title'] ?? 'Document') ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Approve</button>
                <button class="btn btn-sm btn-danger reject-btn clickable" data-id="<?= $sub['id'] ?>" data-type="<?= $sub['doc_source'] ?? 'document' ?>" title="Reject"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>Reject</button>
                <?php if (!empty(trim($sub['description'] ?? ''))): ?><button class="btn btn-sm btn-outline note-btn clickable" data-note="<?= sanitize($sub['description']) ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>Note</button><?php endif; ?>
            </div></td>
        </tr>
        <?php
    endforeach;
    sendJson(['count' => count($pendingSubmissions), 'table_html' => ob_get_clean()]);
}

$flashMsg = $_GET['msg'] ?? '';
$flashError = $_GET['error'] ?? '';
$pageTitle = 'Submissions Review';

ob_start();
?>
<style>
    /* ================================================================
       ENHANCED ADMIN SUBMISSIONS — Premium Admin Design System
       Aligned to admin_department_requirements & admin_dashboard
       Positions untouched, visuals elevated
       ================================================================ */
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
        --shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --radius-xl: 16px;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        font-family: 'Inter', sans-serif;
        color: var(--gray-800);
        -webkit-font-smoothing: antialiased;
    }

    .content-area {
        padding: 1.25rem 1.5rem !important;
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
        border-radius: 12px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .repo-section .alert {
        margin: 1rem 1.25rem 0;
        border-radius: 12px;
        box-shadow: var(--shadow);
    }

    .repo-section .filter-card {
        background: var(--white);
        border: none;
        border-bottom: 1px solid var(--border);
        border-radius: 0;
        box-shadow: none;
        margin-bottom: 0;
        padding: 1rem 1.25rem;
    }

    .repo-section .filter-tabs {
        padding: 0.85rem 1.25rem;
        margin-bottom: 0;
        background: var(--gray-50);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .repo-section .card {
        background: transparent;
        border: none;
        border-radius: 0;
        box-shadow: none;
    }

    .repo-section .card:hover {
        box-shadow: none;
    }

    .repo-section .card-header {
        background: var(--white);
        border-radius: 0;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gray-900);
        margin: 0;
    }

    .page-header-actions {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    /* Alerts — premium rounded with full border + icon, matches dept page */
    .alert {
        padding: 0.9rem 1.1rem;
        border-radius: 12px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.875rem;
        border: 1px solid;
        margin-bottom: 0.75rem;
        box-shadow: var(--shadow);
        animation: slideDown 0.3s ease;
    }
    @keyframes slideDown { from { opacity:0; transform: translateY(-6px);} to {opacity:1; transform:none;}}

    .alert-success {
        background: #ecfdf5;
        color: #065f46;
        border-color: #a7f3d0;
    }

    .alert-danger {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    /* Filter Card — elevated but same position */
    .filter-card {
        background: var(--white);
        border-radius: 0;
        padding: 1rem 1.25rem;
        border: none;
        box-shadow: none;
        margin-bottom: 0;
    }

    .filter-form {
        display: flex;
        gap: 0.75rem;
        align-items: flex-end;
        flex-wrap: wrap;
        background: var(--gray-50);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 0.85rem;
    }

    .filter-group {
        flex: 1;
        min-width: 200px;
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }

    .filter-group label {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--gray-700);
        display: flex;
        align-items: center;
        gap: 0.4rem;
        letter-spacing: 0.01em;
        text-transform: uppercase;
    }

    .form-control {
        width: 100%;
        padding: 0.6rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.875rem;
        transition: var(--transition);
        background: var(--white);
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }

    .form-control:hover { border-color: var(--gray-300); }
    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light), 0 1px 2px rgba(0,0,0,0.04);
        background: white;
    }
    .form-control::placeholder { color: var(--gray-400); }

    textarea.form-control {
        resize: vertical;
        min-height: 88px;
    }

    /* Filter Tabs — pill segmented, same position */
    .filter-tabs {
        display: flex;
        gap: 0.4rem;
        margin-bottom: 0;
        flex-wrap: wrap;
    }

    .filter-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 0.85rem;
        border-radius: 9999px;
        font-size: 0.78rem;
        font-weight: 600;
        text-decoration: none;
        transition: var(--transition);
        border: 1px solid var(--border);
        background: var(--white);
        color: var(--gray-600);
        white-space: nowrap;
        box-shadow: var(--shadow);
        letter-spacing: 0.01em;
    }

    .filter-tab:hover {
        background: var(--gray-50);
        border-color: var(--gray-300);
        color: var(--gray-800);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    .filter-tab.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
        box-shadow: 0 2px 8px rgba(79,70,229,0.3);
    }

    .filter-tab.active:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
    }

    .filter-tab.ph-direct.active {
        background: var(--warning);
        border-color: var(--warning);
    }

    .filter-tab.ph-direct.active:hover {
        background: #d97706;
    }

    /* Cards */
    .card {
        background: var(--white);
        border-radius: 0;
        border: none;
        overflow: hidden;
        transition: var(--transition);
        box-shadow: none;
        flex: 1;
    }

    .card:hover {
        box-shadow: none;
    }

    .card-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .card-header h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        letter-spacing: -0.01em;
    }
    .card-header h3 svg { color: var(--primary); }

    .card-header-stats {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--gray-50);
        border: 1px solid var(--border);
        border-radius: 9999px;
        padding: 0.3rem 0.7rem;
    }

    .stat-item {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.72rem;
        color: var(--gray-500);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .stat-value {
        font-weight: 800;
        color: var(--gray-900);
        font-size: 0.85rem;
    }

    .card-content {
        padding: 1.25rem;
    }

    .card-content.no-padding {
        padding: 0;
    }

    /* Tables — premium, matches dept_requirements data-table */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0;
    }
    .table-responsive::-webkit-scrollbar { height: 6px; }
    .table-responsive::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 3px; }

    .data-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
        min-width: 600px;
    }

    .data-table th {
        text-align: left;
        padding: 0.8rem 1rem;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-500);
        background: var(--gray-50);
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .data-table td {
        padding: 0.85rem 1rem;
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

    .data-table tr.clickable:hover td {
        cursor: pointer;
    }

    .doc-info {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }

    .doc-title {
        font-weight: 700;
        color: var(--gray-900);
        font-size: 0.875rem;
        letter-spacing: -0.01em;
        line-height: 1.3;
    }

    .origin-cell {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }

    .origin-dept {
        font-size: 0.8rem;
        color: var(--gray-600);
        font-weight: 500;
        line-height: 1.4;
    }

    /* Badges — premium pill with border + uppercase tracking */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.28rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.68rem;
        font-weight: 700;
        line-height: 1.25;
        white-space: nowrap;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        border: 1px solid transparent;
    }

    .badge-warning {
        background: var(--warning-light);
        color: #92400e;
        border-color: #fde68a;
    }

    .badge-success {
        background: var(--success-light);
        color: #065f46;
        border-color: #a7f3d0;
    }

    .badge-danger {
        background: var(--danger-light);
        color: #b91c1c;
        border-color: #fecaca;
    }

    .badge-info {
        background: var(--info-light);
        color: #1e40af;
        border-color: #bfdbfe;
    }

    .badge-via-ph {
        background: #eef2ff;
        color: #4338ca;
        border-color: #c7d2fe;
    }

    .badge-system {
        background: var(--gray-100);
        color: var(--gray-600);
        border-color: var(--gray-200);
    }

    /* Buttons — premium, shadows + 10px radius, matches dept page */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.5rem 0.85rem;
        border-radius: 10px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.4;
        white-space: nowrap;
        letter-spacing: 0.01em;
    }

    .btn:hover {
        transform: translateY(-1px);
    }
    .btn:active { transform: translateY(0); }

    .btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    .btn-sm {
        padding: 0.4rem 0.7rem;
        font-size: 0.75rem;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
        box-shadow: 0 1px 2px rgba(79,70,229,0.2);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
        box-shadow: 0 4px 10px rgba(79,70,229,0.3);
    }

    .btn-outline {
        background: white;
        border-color: var(--border);
        color: var(--gray-700);
        box-shadow: var(--shadow);
    }

    .btn-outline:hover {
        border-color: var(--gray-300);
        color: var(--gray-800);
        background: var(--gray-50);
        box-shadow: var(--shadow-md);
    }

    .btn-success {
        background: var(--success);
        color: white;
        border-color: var(--success);
        box-shadow: 0 1px 2px rgba(16,185,129,0.2);
    }

    .btn-success:hover {
        background: #0e9f6e;
        border-color: #0e9f6e;
        box-shadow: 0 4px 10px rgba(16,185,129,0.3);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
        border-color: var(--danger);
        box-shadow: 0 1px 2px rgba(239,68,68,0.2);
    }

    .btn-danger:hover {
        background: #dc2626;
        border-color: #dc2626;
        box-shadow: 0 4px 10px rgba(239,68,68,0.3);
    }

    .btn-secondary {
        background: white;
        color: var(--gray-700);
        border-color: var(--border);
        box-shadow: var(--shadow);
    }

    .btn-secondary:hover {
        background: var(--gray-50);
        border-color: var(--gray-300);
        box-shadow: var(--shadow-md);
    }

    .btn-group {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    /* Empty State — premium dashed card, matches dept empty-state */
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
        color: var(--gray-500);
        background: var(--gray-50);
        border: 1px dashed var(--border);
        border-radius: 12px;
        margin: 1.25rem;
    }

    .empty-state-icon {
        color: var(--gray-400);
        margin: 0 auto 0.85rem;
        display: block;
        opacity: 0.9;
    }

    .empty-state p {
        font-size: 0.9rem;
        margin: 0;
        color: var(--gray-500);
    }

    /* Modals — premium, matches dept page modal-overlay */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(17, 24, 39, 0.48);
        backdrop-filter: blur(6px);
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }

    .modal.show {
        display: flex;
        animation: modalFadeIn 0.2s ease;
    }
    @keyframes modalFadeIn { from { opacity:0; } to {opacity:1; } }

    .modal-content {
        background: var(--white);
        border-radius: 16px;
        width: 100%;
        max-width: 520px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.18), 0 8px 24px rgba(0,0,0,0.08);
        animation: slideUp 0.28s cubic-bezier(0.16,1,0.3,1);
        border: 1px solid var(--border);
        overflow: hidden;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
    }

    @keyframes slideUp {
        from {
            transform: translateY(16px) scale(0.98);
            opacity: 0;
        }

        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }

    /* Success toast — top banner, hugs text, no backdrop, page stays clickable */
    #successModal { background: none; backdrop-filter: none; padding: 0; align-items: flex-start;
        justify-content: center; pointer-events: none; }
    #successModal.show { animation: none; }
    #successModal .modal-content { pointer-events: auto; width: auto; max-width: calc(100vw - 2rem);
        max-height: none; margin-top: 16px; padding: 0; border-radius: 10px;
        border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; background: #f0fdf4;
        box-shadow: 0 12px 32px rgba(15,23,42,.16); animation: subToastDrop .26s cubic-bezier(.2,.9,.3,1); }
    @keyframes subToastDrop { from { transform: translateY(-18px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .toast-row { display: flex; align-items: center; gap: .55rem; padding: .7rem .75rem .7rem .9rem;
        font-size: .88rem; font-weight: 600; color: #14532d; }
    .toast-row > svg { flex-shrink: 0; color: #16a34a; }
    .toast-dismiss { margin-left: .35rem; flex-shrink: 0; border: 0; background: transparent; color: #4d7c5f;
        cursor: pointer; padding: .2rem; border-radius: 6px; display: flex; }
    .toast-dismiss:hover { background: rgba(22,163,74,.12); color: #166534; }

    @media (prefers-reduced-motion: reduce) {
        .modal-content {
            animation: none;
        }

        .btn,
        .filter-tab,
        .card {
            transition: none;
        }
    }

    .text-primary {
        color: var(--primary);
    }

    .text-warning {
        color: var(--warning);
    }

    .text-danger {
        color: var(--danger);
    }

    .w-5 {
        width: 1.25rem;
        height: 1.25rem;
    }

    .w-6 {
        width: 1.5rem;
        height: 1.5rem;
    }

    .w-4 {
        width: 1rem;
        height: 1rem;
    }

    .w-3 {
        width: 0.75rem;
        height: 0.75rem;
    }

    .w-16 {
        width: 4rem;
        height: 4rem;
    }

    .h-5 {
        height: 1.25rem;
    }

    .h-6 {
        height: 1.5rem;
    }

    .h-4 {
        height: 1rem;
    }

    .h-3 {
        height: 0.75rem;
    }

    .h-16 {
        height: 4rem;
    }

    .modal-header {
        padding: 1.15rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
        flex-shrink: 0;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        letter-spacing: -0.01em;
    }
    .modal-header h3::before {
        content: '';
        width: 3px;
        height: 18px;
        background: var(--primary);
        border-radius: 9999px;
        display: inline-block;
    }

    .modal-body {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
        min-height: 0;
        background: white;
    }
    .modal-body .form-group { margin-bottom: 1.15rem; }
    .modal-body .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.4rem;
        font-size: 0.78rem;
        color: var(--gray-700);
        letter-spacing: 0.01em;
    }

    .close-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: white;
        border: 1px solid var(--border);
        cursor: pointer;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        color: var(--gray-500);
        transition: var(--transition);
        box-shadow: var(--shadow);
        font-size: 1.25rem;
        line-height: 1;
    }

    .close-btn:hover {
        background: var(--gray-50);
        color: var(--gray-800);
        border-color: var(--gray-300);
    }

    /* Utility classes */
    .text-sm {
        font-size: 0.875rem;
    }

    .text-right {
        text-align: right;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .filter-form {
            gap: 0.75rem;
        }
        .filter-group {
            min-width: 150px;
        }
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .repo-section .filter-card {
            padding: 1rem;
        }
        .repo-section .filter-tabs {
            padding: 0.75rem 1rem 0.5rem;
        }
        .repo-section .card-header {
            padding: 0.75rem 1rem;
        }
        .filter-form {
            flex-direction: column;
            align-items: stretch;
        }
        .filter-group {
            width: 100%;
            min-width: unset;
        }
        .filter-tabs {
            justify-content: flex-start;
            overflow-x: auto;
            flex-wrap: nowrap;
            padding-bottom: 0.25rem;
            gap: 0.25rem;
        }
        .filter-tab {
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
        }
        .card-header {
            flex-direction: column;
            align-items: flex-start;
        }
        .card-header-stats {
            width: 100%;
            justify-content: space-between;
        }
        .btn-group {
            flex-wrap: wrap;
            justify-content: flex-start;
        }
        .data-table {
            font-size: 0.8rem;
            min-width: 500px;
        }
        .data-table th,
        .data-table td {
            padding: 0.5rem 0.75rem;
        }
    }

    @media (max-width: 600px) {
        .btn-group .btn {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        .data-table {
            min-width: 400px;
            font-size: 0.75rem;
        }
        .data-table th,
        .data-table td {
            padding: 0.4rem 0.5rem;
        }
        .badge {
            font-size: 0.6rem;
            padding: 0.1rem 0.4rem;
        }
        .card-header {
            padding: 0.5rem 0.75rem;
        }
        .card-header h3 {
            font-size: 0.9rem;
        }
        .filter-card {
            padding: 0.75rem;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<div class="dashboard-container">

    <div class="repo-section">


    <?php if ($flashError): ?>
        <div class="alert alert-danger">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            <?= sanitize($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="filter-card">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>Filter by Department</label>
                <select name="dept" class="form-control" data-csp-submit>
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $filterDept == $d['id'] ? 'selected' : '' ?>>
                            <?= sanitize($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" style="flex: 0; min-width: auto;">
                <a href="admin_submissions.php" class="btn btn-secondary">Clear</a>
            </div>
        </form>
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="admin_submissions.php<?= buildQueryString(['uploader' => '', 'dept' => $filterDept]) ?>"
            class="filter-tab clickable <?= $filterUploader === '' ? 'active' : '' ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            All Submissions
        </a>
        <a href="admin_submissions.php<?= buildQueryString(['uploader' => 'department', 'dept' => $filterDept]) ?>"
            class="filter-tab ph-direct clickable <?= $filterUploader === 'department' ? 'active' : '' ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
            <?= sanitize(phRoleName('department')) ?> Direct Uploads
        </a>
        <a href="admin_submissions.php<?= buildQueryString(['uploader' => 'unit_head', 'dept' => $filterDept]) ?>"
            class="filter-tab ph-direct clickable <?= $filterUploader === 'unit_head' ? 'active' : '' ?>">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
            <?= sanitize(phRoleName('unit_head')) ?> Direct Uploads
        </a>
    </div>

    <!-- Pending Submissions Table -->
    <div class="card">
        <div class="card-header">
            <h3>
                <svg class="w-5 h-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Pending Review
            </h3>
            <div class="card-header-stats">
                <div class="stat-item">
                    <span class="stat-value"><?= count($pendingSubmissions) ?></span> items
                </div>
            </div>
        </div>
        <div class="card-content no-padding">
            <?php if (empty($pendingSubmissions)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p>No pending submissions.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Document Info</th>
                                <th>Origin</th>
                                <th>Status</th>
                                <th>Date Uploaded</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingSubmissions as $sub):

                                // Build origin string – department + folder path (if any).
                                // A document can outlive its department row (or have none yet),
                                // so never let a NULL department name blank the cell.
                                $originString = !empty($sub['department_name'])
                                    ? sanitize($sub['department_name'])
                                    : 'No Department';
                                if ($sub['doc_source'] === 'document') {
                                    // Teacher path: folder via tr→dr. PH personal upload of an
                                    // admin requirement has no tr row — fall back to matching the
                                    // requirement directly so origin reads like a teacher upload.
                                    $folderId = $sub['folder_id'] ?? null;
                                    if (!$folderId) {
                                        $folderId = $sub['fallback_folder_id'] ?? null;
                                    }
                                    if ($folderId) {
                                        $folderPath = getFolderPath($pdo, $folderId);
                                        if ($folderPath) {
                                            $originString .= ' > ' . sanitize($folderPath);
                                        }
                                    }
                                } elseif ($sub['doc_source'] === 'ph_document') {
                                    // Name the head who owns the requirement, from the uploader's
                                    // real role — a Unit Head's requirement should not read "PH".
                                    $originString .= in_array($sub['uploader_role'] ?? '', ['department', 'unit_head'], true)
                                        ? ' > ' . phRoleName($sub['uploader_role']) . ' Requirement'
                                        : ' > Head Requirement';
                                }
                                // Direct upload: origin stays department name only (no suffix)

                                $expiryDate = $sub['expiry_date'] ?? null;
                                $isExpired = false;
                                $expiryLabel = '';
                                // Single 2-day rule (date is source of truth; covers
                                // documents and ph_documents alike)
                                $expiryState = classifyExpiryStatus($expiryDate);
                                if ($expiryDate && $expiryState !== 'active') {
                                    $isExpired = true;
                                    $expiryLabel = expiryStatusLabel($expiryState);
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="doc-info">
                                            <span class="doc-title"><?= sanitize($sub['title']) ?></span>

                                        </div>
                                    </td>
                                    <td>
                                        <div class="origin-cell">
                                            <span class="origin-dept"><?= $originString ?></span>
                                            <?php if (!empty($sub['uploader_name'])): ?>
                                                <span class="origin-dept" style="color:var(--gray-500);">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align:-1px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                                    Uploaded by: <?= in_array($sub['uploader_role'] ?? '', ['department', 'unit_head'], true) ? phRoleName($sub['uploader_role']) : sanitize($sub['uploader_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-warning"><?= $statusLabels['pending_admin']['label'] ?></span>
                                        <?php if ($isExpired && $expiryDate): ?>
                                            <span class="badge badge-danger" style="margin-left: 0.25rem;" title="Deadline: <?= date('M j, Y', strtotime($expiryDate)) ?>">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                                <?= $expiryLabel ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($sub['upload_date'])) ?></td>
                                    <td class="text-right">
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline view-btn clickable" data-id="<?= $sub['id'] ?>"
                                                data-type="<?= $sub['doc_source'] ?>"
                                                data-title="<?= sanitize($sub['title'] ?? 'Document') ?>"
                                                data-filename="<?= sanitize($sub['filename'] ?? '') ?>"
                                                data-extension="<?= strtolower(pathinfo($sub['filename'] ?? '', PATHINFO_EXTENSION)) ?>"
                                                title="View Document">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                View
                                            </button>
                                            <button type="button" class="btn btn-sm btn-success approve-btn clickable"
                                                data-id="<?= $sub['id'] ?>"
                                                data-type="<?= $sub['doc_source'] ?? 'document' ?>"
                                                data-title="<?= sanitize($sub['title'] ?? 'Document') ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                Approve
                                            </button>
                                            <button class="btn btn-sm btn-danger reject-btn clickable" data-id="<?= $sub['id'] ?>"
                                                data-type="<?= $sub['doc_source'] ?? 'document' ?>" title="Reject">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                Reject
                                            </button>
                                            <?php if (!empty(trim($sub['description'] ?? ''))): ?>
                                                <button class="btn btn-sm btn-outline note-btn clickable"
                                                    data-note="<?= sanitize($sub['description']) ?>">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                                                    Note
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    </div> <!-- /repo-section -->
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="display: flex; align-items: center; gap: 0.5rem;">
                <svg class="w-5 h-5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                Reject Submission
            </h3>
            <button class="close-btn" data-modal="rejectModal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" action="admin_review_submission.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="submission_id" id="reject_submission_id">
                <input type="hidden" name="doc_source" id="reject_doc_source">
                <input type="hidden" name="redirect" value="admin_submissions.php">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Reason for Rejection <span style="color: var(--danger);">*</span></label>
                    <textarea name="comment" class="form-control" rows="4" required
                        placeholder="Please provide feedback on why this document is being rejected..."></textarea>
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                   
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="display: flex; align-items: center; gap: 0.5rem;">
                <svg class="w-5 h-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Approve Submission
            </h3>
            <button class="close-btn" data-modal="approveModal">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 1rem 0; color: var(--gray-700);">
                Are you sure you want to approve this document?<br>
                <strong id="approveDocTitle"></strong>
            </p>
            <form method="post" action="admin_review_submission.php" id="approveForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="submission_id" id="approve_submission_id">
                <input type="hidden" name="doc_source" id="approve_doc_source">
                <input type="hidden" name="redirect" value="admin_submissions.php">
                <input type="hidden" name="action" value="approve">
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                   
                    <button type="submit" class="btn btn-success">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Note Modal -->
<div id="noteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="display: flex; align-items: center; gap: 0.5rem;">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                Document Note
            </h3>
            <button class="close-btn" data-modal="noteModal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="noteContent"
                style="background: var(--gray-50); border: 1px solid var(--border); padding: 1rem; border-radius: var(--radius-sm); color: var(--gray-700); white-space: pre-wrap;">
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    document.querySelector('.data-table tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        if (btn.classList.contains('approve-btn')) {
            document.getElementById('approve_submission_id').value = btn.dataset.id;
            document.getElementById('approve_doc_source').value = btn.dataset.type || 'document';
            document.getElementById('approveDocTitle').textContent = btn.dataset.title || 'Document';
            document.getElementById('approveModal').classList.add('show');
        } else if (btn.classList.contains('reject-btn')) {
            document.getElementById('reject_submission_id').value = btn.dataset.id;
            document.getElementById('reject_doc_source').value = btn.dataset.type || 'document';
            document.getElementById('rejectModal').classList.add('show');
        } else if (btn.classList.contains('note-btn')) {
            document.getElementById('noteContent').textContent = btn.dataset.note || 'No note available.';
            document.getElementById('noteModal').classList.add('show');
        }
    });

    document.querySelectorAll('.close-btn[data-modal]').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById(this.dataset.modal).classList.remove('show');
        });
    });
    window.onclick = function (event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('show');
        }
    }

    CHED.Poller.register('adminSubmissions', function () {
        return fetch('admin_submissions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'action=refresh_submissions'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || typeof d.count === 'undefined') return;
            var stat = document.querySelector('.card-header-stats .stat-value');
            if (stat && stat.textContent !== String(d.count)) {
                stat.textContent = d.count;
                document.querySelector('.data-table tbody').innerHTML = d.table_html || '';
            }
        }).catch(function () {});
    }, 3000);
</script>

<div id="successModal" class="modal">
    <div class="modal-content" role="status" aria-live="polite">
        <div class="toast-row">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span id="successMsg">Done.</span>
            <button type="button" class="toast-dismiss" data-csp-action="closeSubSuccessToast" aria-label="Dismiss">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
</div>
<script nonce="<?= csp_nonce() ?>">
    var __subSuccessTimer = null;
    function closeSubSuccessToast() {
        var m = document.getElementById('successModal');
        m.classList.remove('show');
        clearTimeout(__subSuccessTimer);
    }
    <?php if ($flashMsg): ?>
        document.getElementById('successMsg').textContent = <?= json_encode($flashMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.getElementById('successModal').classList.add('show');
        __subSuccessTimer = setTimeout(closeSubSuccessToast, 2600);
    <?php endif; ?>
</script>
<?php include 'includes/footer.php'; ?>


