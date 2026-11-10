<?php
define('CHED_APP', true);
require_once 'config.php';
requireTeacher();

$teacherId = (int) $_SESSION['user_id'];

// ============================================================
// REVIEWER CONTEXT
// ============================================================
$deptStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
$deptStmt->execute([$teacherId]);
$teacherDeptId = (int) $deptStmt->fetchColumn();
$reviewer     = $teacherDeptId ? getProgramHeadForDepartment($pdo, $teacherDeptId) : null;
$reviewerName = phRoleName($reviewer['role'] ?? null);

// ============================================================
// SHARED UPLOAD HANDLER
// ============================================================
function handleTeacherUpload(PDO $pdo, int $teacherId, bool $isPh, string $reviewerName): array
{
    $file = $_FILES['document_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg = 'Please select a file to upload.';
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $msg = 'File is too large. Maximum size is ' . maxFileSizeLabelFor($ext) . '.';
        }
        return ['success' => false, 'message' => $msg];
    }

    $comment = trim($_POST['comment'] ?? '');

    if ($isPh) {
        $reqId = (int) ($_POST['ph_requirement_id'] ?? 0);
        $stmt  = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id=? AND teacher_id=?");
        $stmt->execute([$reqId, $teacherId]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            return ['success' => false, 'message' => 'Invalid PH requirement.'];
        }
        $result = uploadTeacherPHSubmission($pdo, $assignment['id'], $teacherId, $file, $comment);
    } else {
        $reqId = (int) ($_POST['requirement_id'] ?? 0);
        $stmt  = $pdo->prepare("SELECT id FROM teacher_requirements WHERE id=? AND teacher_id=?");
        $stmt->execute([$reqId, $teacherId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Invalid requirement. This requirement is not assigned to you.'];
        }
        $result = uploadTeacherSubmission($pdo, $reqId, $teacherId, $file, $comment);
    }

    if ($result === true) {
        return [
            'success' => true,
            'message' => "Draft saved. Click View to review it, then Submit to send it to your $reviewerName."
        ];
    }
    return ['success' => false, 'message' => $result];
}

// ============================================================
// SHARED DELETE HANDLER
// ============================================================
function handleTeacherDelete(PDO $pdo, int $teacherId, bool $isPh): array
{
    $reqId = (int) ($_POST['requirement_id'] ?? 0);
    if (!$reqId) {
        return ['success' => false, 'message' => 'Invalid requirement ID.'];
    }

    if ($isPh) {
        $stmt = $pdo->prepare("
            SELECT tpr.id FROM teacher_ph_requirements tpr
            JOIN ph_documents pd ON pd.teacher_ph_requirement_id = tpr.id
            WHERE tpr.id = ? AND tpr.teacher_id = ?
              AND pd.status IN ('approved_admin', 'approved_program_head')
            ORDER BY pd.version DESC LIMIT 1
        ");
        $stmt->execute([$reqId, $teacherId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Only approved requirements can be removed.'];
        }
        $pdo->prepare("DELETE FROM teacher_ph_requirements WHERE id = ? AND teacher_id = ?")->execute([$reqId, $teacherId]);
        logAudit($pdo, 'teacher_delete_requirement', "Teacher deleted PH requirement ID $reqId", 'teacher_ph_requirements', $reqId);
    } else {
        $stmt = $pdo->prepare("
            SELECT tr.id FROM teacher_requirements tr
            WHERE tr.id = ? AND tr.teacher_id = ?
              AND (SELECT status FROM documents WHERE teacher_requirement_id = tr.id ORDER BY version DESC LIMIT 1)
                  IN ('approved_admin', 'approved_program_head')
        ");
        $stmt->execute([$reqId, $teacherId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Only approved requirements can be removed.'];
        }
        $pdo->prepare("DELETE FROM teacher_requirements WHERE id = ? AND teacher_id = ?")->execute([$reqId, $teacherId]);
        logAudit($pdo, 'teacher_delete_requirement', "Teacher deleted requirement ID $reqId", 'teacher_requirements', $reqId);
    }

    return ['success' => true, 'message' => 'Requirement removed from your dashboard.'];
}

// ============================================================
// AJAX DISPATCH
// ============================================================
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid session. Please refresh and try again.']);
        exit;
    }

    if (!empty($_POST['ajax_upload'])) {
        $isPh = ($_POST['is_ph_upload'] ?? '0') === '1';
        echo json_encode(handleTeacherUpload($pdo, $teacherId, $isPh, $reviewerName));
        exit;
    }

    if (($_POST['ajax_action'] ?? '') === 'delete_requirement') {
        $isPh = !empty($_POST['is_ph_requirement']);
        echo json_encode(handleTeacherDelete($pdo, $teacherId, $isPh));
        exit;
    }

    // ✅ DRAFT-FLOW: promote a draft to pending_program_head
    if (($_POST['ajax_action'] ?? '') === 'submit_draft') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        $isPh  = !empty($_POST['is_ph_requirement']);
        echo json_encode(submitTeacherDraft($pdo, $docId, $teacherId, $isPh));
        exit;
    }

    // ✅ DRAFT-FLOW: teacher discards their own draft
    if (($_POST['ajax_action'] ?? '') === 'delete_draft') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        $isPh  = !empty($_POST['is_ph_requirement']);
        echo json_encode(deleteTeacherDraft($pdo, $docId, $teacherId, $isPh));
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ============================================================
// NON-AJAX POST DISPATCH (form fallback)
// ============================================================
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $isPhUpload = ($_POST['is_ph_upload'] ?? '0') === '1';
    $hasReqId   = !empty($_POST['requirement_id']) || !empty($_POST['ph_requirement_id']);

    if ($hasReqId) {
        $result = handleTeacherUpload($pdo, $teacherId, $isPhUpload, $reviewerName);
        if ($result['success']) {
            header("Location: teacher_tasks.php?msg=" . urlencode($result['message']) . "&t=" . time());
            exit;
        }
        $error = $result['message'];
    }
}

$success = $_GET['msg'] ?? '';

// ============================================================
// FETCH + NORMALIZE REQUIREMENTS
// ============================================================
$requirements   = getTeacherRequirementsOptimized($pdo, $teacherId);
$phRequirements = getTeacherPHRequirementsOptimized($pdo, $teacherId);

$headIds = array_filter(array_unique(array_merge(
    array_column($phRequirements, 'created_by'),
    array_column($requirements, 'assigned_by')
)));
$headRoles = [];
if ($headIds) {
    $placeholders = implode(',', array_fill(0, count($headIds), '?'));
    $headStmt = $pdo->prepare("SELECT id, role FROM users WHERE id IN ($placeholders) AND role IN ('department','unit_head')");
    $headStmt->execute(array_values($headIds));
    $headRoles = $headStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
$headLabel = function ($userId) use ($headRoles, $reviewer) {
    $role = $headRoles[(int) $userId] ?? ($reviewer['role'] ?? null);
    return $role === 'unit_head' ? 'UH' : 'PH';
};

foreach ($phRequirements as &$phReq) {
    $label = $headLabel($phReq['created_by'] ?? null);
    $phReq = array_merge($phReq, [
        'is_ph_requirement'    => true,
        'source'               => 'ph_document',
        'category'             => $label,
        'document_type'        => $phReq['title'],
        'mandatory'            => 1,
        'expiration_date'      => $phReq['deadline'] ?? null,
        'latest_admin_comment' => $phReq['latest_admin_comment'] ?? null,
        'latest_ph_comment'    => $phReq['latest_ph_comment'] ?? null,
        'latest_filename'      => $phReq['latest_filename'] ?? null,
        'template_filename'    => $phReq['ref_filename'] ?? null,
        'template_file_path'   => $phReq['ref_file_path'] ?? null,
        'source_label'         => $label,
        'source_label_class'   => 'badge-info',
    ]);
}
unset($phReq);

foreach ($requirements as &$req) {
    $req['source']             = 'document';
    $req['is_ph_requirement']  = false;
    $req['template_filename']  = null;
    $req['template_file_path'] = null;

    if (($req['requirement_type'] ?? '') === 'program_head_requirement') {
        $req['source_label']       = $headLabel($req['assigned_by'] ?? null);
        $req['source_label_class'] = 'badge-info';
    } else {
        $req['source_label']       = 'Admin';
        $req['source_label_class'] = 'badge-warning';
    }
}
unset($req);

// ============================================================
// FILTER: TO DO ONLY
// ============================================================
// "To Do" = anything that is NOT pending, NOT rejected, NOT approved,
//           OR is a draft (needs the teacher to Submit).
$todoRequirements = [];

foreach (array_merge($requirements, $phRequirements) as $req) {
    $s = $req['current_status'] ?? '';

    if (in_array($s, ['approved_program_head', 'approved_admin'], true))    continue;
    if (in_array($s, ['pending_program_head', 'pending_admin'], true))      continue;
    if (in_array($s, ['rejected_program_head', 'rejected_admin'], true))    continue;

    // Includes: '' (never uploaded) AND 'draft' (needs Submit).
    $todoRequirements[] = $req;
}

$allRequirements = $todoRequirements; // total count now reflects To Do only

// ============================================================
// HELPERS
// ============================================================
function getTaskStatusMeta(string $status, string $reviewerName): array
{
    return match (true) {
        in_array($status, ['approved_program_head', 'approved_admin'], true) =>
            ['label' => 'Complete', 'class' => 'badge-approved', 'canUpload' => false],
        $status === 'draft' =>
            ['label' => 'Draft — Not Submitted', 'class' => 'badge-secondary', 'canUpload' => false],
        $status === 'pending_program_head' =>
            ['label' => "Pending $reviewerName", 'class' => 'badge-pending-ph', 'canUpload' => false],
        $status === 'pending_admin' =>
            ['label' => 'Pending Admin', 'class' => 'badge-pending-admin', 'canUpload' => false],
        in_array($status, ['rejected_program_head', 'rejected_admin'], true) =>
            ['label' => 'Need Fix', 'class' => 'badge-rejected', 'canUpload' => true],
        default =>
            ['label' => 'Not Uploaded', 'class' => 'badge-secondary', 'canUpload' => true],
    };
}

// ============================================================
// ICON LIBRARY (used by the Actions dropdown)
// ============================================================
function taskActionIcon(string $name): string
{
    $icons = [
        'eye'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'send'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>',
        'refresh' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>',
        'trash'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>',
        'info'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>',
        'file'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>',
        'message' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/>',
        'upload'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 7.5m0 0L7.5 12m4.5-4.5V21"/>',
    ];
    return $icons[$name] ?? '';
}
?>
<?php
$pageTitle = 'My Tasks';

ob_start();
?>
<style>
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

    * { box-sizing: border-box; }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--gray-50);
        color: var(--gray-800);
        font-size: 0.875rem;
        line-height: 1.5;
    }

    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        padding: 0.5rem;
    }

    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideUp  { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes slideIn  { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

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
        animation: fadeInUp 0.3s ease forwards;
    }
    .alert-success { background: var(--success-light); color: #0b6b4c; border-left-color: var(--success); }
    .alert-danger  { background: var(--danger-light);  color: #b91c1c; border-left-color: var(--danger); }
    .alert svg { width: 20px; height: 20px; flex-shrink: 0; }

    .card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-xs);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition: box-shadow 0.15s ease;
    }
    .card:hover { box-shadow: var(--shadow-sm); }

    .card-header {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--white);
        border-radius: 12px 12px 0 0;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .card-header h3 {
        font-size: 0.95rem;
        font-weight: 650;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin: 0;
        letter-spacing: -0.01em;
    }
    .card-header h3 svg { width: 18px; height: 18px; color: var(--gray-400); }

    .section-count {
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .card-content { padding: 0; flex: 1; }

    /* ===== RESPONSIVE TABLE ===== */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
    }

    .data-table th {
        background: var(--gray-50);
        padding: 0.65rem 1.25rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 650;
        text-transform: uppercase;
        color: var(--gray-500);
        letter-spacing: 0.06em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .data-table td {
        padding: 0.8rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
        font-size: 0.875rem;
        color: var(--gray-700);
    }

    .data-table tr:hover { background: #f8fafc; }
    .data-table tr:hover td { color: var(--gray-900); }
    .data-table tr:last-child td { border-bottom: none; }

    /* Prevent long filenames/urls breaking layout */
    .req-doc-type,
    .req-category,
    .data-table td span {
        overflow-wrap: anywhere;
    }

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
    .badge svg { width: 12px; height: 12px; }
    .badge-pending-ph    { background: #fef3c7; color: #92400e; }
    .badge-pending-admin { background: #eff6ff; color: #1d4ed8; }
    .badge-approved      { background: #ecfdf5; color: #047857; }
    .badge-rejected      { background: #fef2f2; color: #b91c1c; }
    .badge-info          { background: #eff6ff; color: #1d4ed8; }
    .badge-warning       { background: #fef3c7; color: #92400e; }
    .badge-danger        { background: #fef2f2; color: #b91c1c; }
    .badge-secondary     { background: var(--gray-100); color: var(--gray-600); }
    .badge-draft         { background: #ede9fe; color: #5b21b6; }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
    }
    .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.8125rem; }
    .btn-primary { background: var(--primary); color: white; box-shadow: var(--shadow-xs); }
    .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-sm); }
    .btn-outline { background: transparent; border-color: var(--gray-300); color: var(--gray-700); }
    .btn-outline:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }
    .btn-danger { background: transparent; border-color: #fecaca; color: #b91c1c; }
    .btn-danger:hover { background: #fef2f2; border-color: #ef4444; }
    .btn-group { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }

    /* ===== ACTION DROPDOWN ===== */
    .action-dropdown {
        position: relative;
        display: inline-block;
    }
    .action-dropdown .dropdown-trigger {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.4rem 0.75rem;
        border: 1px solid var(--gray-300);
        background: var(--white);
        border-radius: var(--radius-sm);
        font-size: 0.8125rem;
        font-weight: 500;
        color: var(--gray-700);
        cursor: pointer;
        transition: var(--transition);
        white-space: nowrap;
    }
    .action-dropdown .dropdown-trigger:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }
    .action-dropdown .dropdown-trigger svg {
        width: 14px;
        height: 14px;
        transition: transform 0.2s ease;
    }
    .action-dropdown.open .dropdown-trigger svg.chevron {
        transform: rotate(180deg);
    }
    .dropdown-menu {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 4px);
        min-width: 180px;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        z-index: 100;
        padding: 0.35rem;
        animation: fadeInUp 0.15s ease;
    }
    .action-dropdown.open .dropdown-menu {
        display: block;
    }
    .dropdown-menu .dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.5rem 0.65rem;
        background: none;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.8125rem;
        font-weight: 500;
        color: var(--gray-700);
        cursor: pointer;
        text-align: left;
        transition: background 0.15s ease;
    }
    .dropdown-menu .dropdown-item:hover {
        background: var(--gray-100);
    }
    .dropdown-menu .dropdown-item svg {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
        color: var(--gray-400);
    }
    .dropdown-menu .dropdown-item:hover svg {
        color: var(--gray-600);
    }
    .dropdown-menu .dropdown-item.text-danger {
        color: #b91c1c;
    }
    .dropdown-menu .dropdown-item.text-danger:hover {
        background: #fef2f2;
    }
    .dropdown-menu .dropdown-item.text-danger svg {
        color: #ef4444;
    }
    .dropdown-menu .dropdown-item.text-primary {
        color: var(--primary);
        font-weight: 600;
    }
    .dropdown-menu .dropdown-item.text-primary:hover {
        background: var(--primary-light);
    }
    .dropdown-menu .dropdown-item.text-primary svg {
        color: var(--primary);
    }
    .dropdown-menu .dropdown-divider {
        height: 1px;
        background: var(--gray-100);
        margin: 0.25rem 0;
    }

    .empty-state { text-align: center; padding: 2.5rem 1.5rem; color: var(--gray-500); }
    .empty-state-icon {
        width: 56px; height: 56px; margin: 0 auto 0.75rem;
        border-radius: 16px; background: var(--gray-100); color: var(--gray-400);
        display: flex; align-items: center; justify-content: center;
    }
    .empty-state svg { width: 26px; height: 26px; }
    .empty-state h3 { margin: 0 0 0.25rem; color: var(--gray-600); font-weight: 600; font-size: 1rem; }
    .empty-state p  { margin: 0; color: var(--gray-500); font-size: 0.875rem; }

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
    .modal.show { display: flex; }
    .modal-content {
        background: var(--white);
        border-radius: var(--radius-lg);
        width: 100%;
        max-width: 520px;
        box-shadow: var(--shadow-xl);
        animation: slideUp 0.2s ease;
    }
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .modal-header h3 {
        margin: 0; font-size: 1.125rem; font-weight: 700; color: var(--gray-900);
        display: flex; align-items: center; gap: 0.5rem;
    }
    .modal-header h3 svg { width: 20px; height: 20px; color: var(--primary); }
    .modal-body { padding: 1.5rem; }
    .close-btn {
        background: none; border: none; font-size: 1.5rem; color: var(--gray-400);
        cursor: pointer; line-height: 1; width: 32px; height: 32px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 9999px; transition: var(--transition);
        flex-shrink: 0;
    }
    .close-btn:hover { color: var(--gray-600); background: var(--gray-100); }

    .form-group { margin-bottom: 1.25rem; }
    .form-group label {
        display: block; margin-bottom: 0.5rem; font-weight: 600;
        font-size: 0.875rem; color: var(--gray-700);
    }
    .form-control {
        width: 100%; padding: 0.75rem 1rem;
        border: 1px solid var(--border); border-radius: var(--radius-sm);
        font-family: inherit; font-size: 0.9375rem;
        transition: var(--transition); background: var(--white);
    }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

    .req-doc-type { font-weight: 600; color: var(--gray-900); }
    .req-category {
        font-size: 0.7rem; color: var(--gray-500); text-transform: uppercase;
        letter-spacing: 0.03em; font-weight: 600;
    }
    .mandatory-dot {
        display: inline-block; width: 6px; height: 6px;
        background: var(--danger); border-radius: 50%; flex-shrink: 0;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
    }

    /* ============================================================
       MOBILE: stacked card layout (≤768px)
       ============================================================ */
    @media (max-width: 768px) {
        .dashboard-container { padding: 0.5rem; gap: 1rem; }

        .card-header { padding: 0.75rem 1rem; }
        .card-header h3 { font-size: 0.9rem; }

        /* Hide header row — labels come from data-label attributes */
        .data-table thead { display: none; }

        .data-table,
        .data-table tbody,
        .data-table tr,
        .data-table td { display: block; width: 100%; }

        .data-table tr {
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            margin-bottom: 0.85rem;
            padding: 0.25rem 0;
            background: var(--white);
            box-shadow: var(--shadow-xs);
        }
        .data-table tr:hover { background: var(--white); }

        .data-table td {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.6rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            white-space: normal;
            text-align: right;
        }
        .data-table td:last-child { border-bottom: none; }

        /* Left-side label generated from data-label */
        .data-table td::before {
            content: attr(data-label);
            flex-shrink: 0;
            font-size: 0.68rem;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
            text-align: left;
            padding-top: 0.15rem;
        }

        /* Right-side content aligns right, can wrap */
        .data-table td > * { text-align: right; min-width: 0; }

        /* Action dropdowns: full width trigger */
        .action-dropdown { width: 100%; }
        .action-dropdown .dropdown-trigger { width: 100%; justify-content: space-between; }
        .dropdown-menu {
            position: static;
            left: 0;
            right: 0;
            min-width: 0;
            width: 100%;
            box-shadow: none;
            border-radius: var(--radius-sm);
            margin-top: 0.35rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        /* Deadline row: keep the icon + date inline and allow wrap */
        .data-table td > div[style*="display:flex"] {
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .modal { padding: 0.75rem; }
        .modal-content { max-width: 100%; max-height: 92vh; overflow-y: auto; }
        .modal-header, .modal-body { padding: 1rem 1.25rem; }

        /* Prevents iOS zoom on input focus */
        .form-control { font-size: 16px; }

        .empty-state { padding: 1.75rem 1rem; }
    }

    @media (max-width: 480px) {
        .dashboard-container { padding: 0.35rem; }
        .data-table td { padding: 0.55rem 0.75rem; font-size: 0.82rem; }
        .badge { font-size: 0.7rem; }
        .section-count { font-size: 0.7rem; }
    }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<div class="dashboard-container">
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <?= sanitize($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <?= sanitize($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($allRequirements)): ?>
        <div class="card" data-section="not_started">
            <div class="card-header">
                <h3>
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    To Do
                </h3>
                <span class="section-count"><?= count($allRequirements) ?> items</span>
            </div>
            <div class="card-content">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Document Type</th>
                                <th>Source</th>
                                <th>Deadline</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allRequirements as $req):
                            $status = $req['current_status'] ?? '';
                            $sc     = getTaskStatusMeta($status, $reviewerName);
                            $taskRowId = !empty($req['is_ph_requirement'])
                                ? 'ph_task:' . (int) ($req['ph_requirement_id'] ?? 0)
                                : 'task:' . (int) ($req['id'] ?? 0);

                            // ✅ DRAFT-FLOW: pull the current draft for this row
                            $isDraft       = ($status ?? '') === 'draft';
                            $draftId       = !empty($req['draft_document_id']) ? (int)$req['draft_document_id'] : 0;
                            $draftFileName = $req['draft_filename'] ?? '';
                            $hasDraft      = $isDraft && $draftId > 0;

                            // ============================================================
                            // Build the action list for this row (rendered as a dropdown)
                            // ============================================================
                            $actions = [];

                            if ($hasDraft) {
                                $actions[] = [
                                    'label' => 'View Draft',
                                    'class' => 'view-btn',
                                    'icon'  => 'eye',
                                    'attrs' => [
                                        'data-id'       => $draftId,
                                        'data-type'     => !empty($req['is_ph_requirement']) ? 'ph_document' : 'document',
                                        'data-title'    => $draftFileName,
                                        'data-filename' => $draftFileName,
                                    ],
                                ];
                                $actions[] = [
                                    'label' => 'Submit',
                                    'class' => 'submit-draft-btn text-primary',
                                    'icon'  => 'send',
                                    'attrs' => [
                                        'data-id'    => $draftId,
                                        'data-is-ph' => !empty($req['is_ph_requirement']) ? '1' : '0',
                                    ],
                                ];
                                $actions[] = [
                                    'label' => 'Change File',
                                    'class' => 'change-draft-btn',
                                    'icon'  => 'refresh',
                                    'attrs' => [
                                        'data-reqid' => !empty($req['is_ph_requirement']) ? (int)($req['ph_requirement_id'] ?? 0) : (int)($req['id'] ?? 0),
                                        'data-is-ph' => !empty($req['is_ph_requirement']) ? '1' : '0',
                                        'data-label' => sanitize(($req['category'] ?? '') . ' - ' . ($req['document_type'] ?? '')),
                                    ],
                                ];
                                $actions[] = [
                                    'label' => 'Delete Draft',
                                    'class' => 'delete-draft-btn text-danger',
                                    'icon'  => 'trash',
                                    'attrs' => [
                                        'data-id'    => $draftId,
                                        'data-is-ph' => !empty($req['is_ph_requirement']) ? '1' : '0',
                                    ],
                                ];
                            } else {
                                if (!empty(trim((string)($req['description'] ?? '')))) {
                                    $actions[] = [
                                        'label' => 'Description',
                                        'class' => 'desc-btn',
                                        'icon'  => 'info',
                                        'attrs' => [
                                            'data-name' => sanitize(($req['category'] ?? '') . ' - ' . ($req['document_type'] ?? '')),
                                            'data-desc' => sanitize($req['description']),
                                        ],
                                    ];
                                }
                                if (!empty($req['template_filename'])) {
                                    $actions[] = [
                                        'label' => 'View Template',
                                        'class' => 'view-btn',
                                        'icon'  => 'file',
                                        'attrs' => [
                                            'data-id'       => (int)($req['ph_requirement_id'] ?? 0),
                                            'data-type'     => 'ph_requirement',
                                            'data-title'    => sanitize($req['template_filename']),
                                            'data-filename' => sanitize($req['template_filename']),
                                        ],
                                    ];
                                }
                                if (!empty($req['latest_ph_comment']) || !empty($req['latest_admin_comment'])) {
                                    $actions[] = [
                                        'label' => 'View Feedback',
                                        'class' => 'comment-btn',
                                        'icon'  => 'message',
                                        'attrs' => [
                                            'data-ph'    => sanitize($req['latest_ph_comment'] ?? ''),
                                            'data-admin' => sanitize($req['latest_admin_comment'] ?? ''),
                                        ],
                                    ];
                                }
                                if ($sc['canUpload']) {
                                    $actions[] = [
                                        'label' => 'Upload',
                                        'class' => 'upload-btn text-primary',
                                        'icon'  => 'upload',
                                        'attrs' => [
                                            'data-reqid' => !empty($req['is_ph_requirement']) ? (int)($req['ph_requirement_id'] ?? 0) : (int)($req['id'] ?? 0),
                                            'data-is-ph' => !empty($req['is_ph_requirement']) ? '1' : '0',
                                            'data-label' => sanitize(($req['category'] ?? '') . ' - ' . ($req['document_type'] ?? '')),
                                        ],
                                    ];
                                }
                            }
                        ?>
                            <tr data-row-id="<?= $taskRowId ?>">
                                <td data-label="Category">
                                    <div class="req-category">
                                        <?php if (!empty($req['mandatory'])): ?><span class="mandatory-dot" title="Mandatory"></span><?php endif; ?>
                                        <?= sanitize($req['category'] ?? '') ?>
                                    </div>
                                </td>
                                <td data-label="Document Type"><span class="req-doc-type"><?= sanitize($req['document_type'] ?? '') ?></span></td>
                                <td data-label="Source">
                                    <?php if (!empty($req['source_label'])): ?>
                                        <span class="badge <?= sanitize($req['source_label_class'] ?? 'badge-secondary') ?>"><?= sanitize($req['source_label']) ?></span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td data-label="Deadline">
                                    <?php if (!empty($req['expiration_date'])):
                                        $expiryDate = strtotime($req['expiration_date']);
                                        $today      = strtotime(date('Y-m-d'));
                                        $daysLeft   = ($expiryDate - $today) / 86400;
                                        $isOverdue  = $daysLeft < 0;
                                        $isUrgent   = $daysLeft >= 0 && $daysLeft <= EXPIRY_WARNING_DAYS;
                                    ?>
                                        <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;">
                                            <svg style="width:14px;height:14px;flex-shrink:0;<?= $isOverdue ? 'color:var(--danger);' : ($isUrgent ? 'color:var(--warning);' : 'color:var(--gray-500);') ?>" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                            </svg>
                                            <span style="<?= $isOverdue ? 'color:var(--danger);font-weight:700;' : ($isUrgent ? 'color:var(--warning);font-weight:600;' : 'color:var(--gray-500);') ?>">
                                                <?= date('M j, Y', $expiryDate) ?>
                                            </span>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge badge-danger" style="background:var(--danger);color:white;">Expired</span>
                                            <?php elseif ($isUrgent): ?>
                                                <span class="badge badge-warning" style="background:var(--warning);color:white;">Expiring</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td data-label="Status"><span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span></td>
                                <td data-label="Actions">
                                    <?php if (!empty($actions)): ?>
                                        <div class="action-dropdown">
                                            <button type="button" class="dropdown-trigger">
                                                Manage
                                                <svg class="chevron" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                                </svg>
                                            </button>
                                            <div class="dropdown-menu">
                                                <?php foreach ($actions as $i => $action): ?>
                                                    <?php if ($i > 0 && $action['class'] === 'delete-draft-btn text-danger'): ?>
                                                        <div class="dropdown-divider"></div>
                                                    <?php endif; ?>
                                                    <button type="button"
                                                        class="dropdown-item <?= $action['class'] ?>"
                                                        <?php foreach ($action['attrs'] as $k => $v): ?>
                                                            <?= $k ?>="<?= $v ?>"
                                                        <?php endforeach; ?>>
                                                        <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                            <?= taskActionIcon($action['icon']) ?>
                                                        </svg>
                                                        <?= sanitize($action['label']) ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:var(--gray-400);font-size:0.8rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h3>
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    To Do
                </h3>
            </div>
            <div class="card-content" style="padding:0;">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                    <h3>Nothing To Do</h3>
                    <p>You have no pending tasks to start right now.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Upload Modal -->
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Upload Document
            </h3>
            <button class="close-btn" data-modal="uploadModal" type="button">
                <svg style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <?= csrfField() ?>
                <input type="hidden" name="requirement_id" id="upload_req_id">
                <input type="hidden" name="ph_requirement_id" id="upload_ph_req_id">
                <input type="hidden" name="is_ph_upload" id="upload_is_ph" value="0">
                <div class="form-group">
                    <label>Requirement</label>
                    <div id="upload_req_label" style="font-weight:600;color:var(--primary);padding:0.75rem;background:var(--gray-50);border-radius:var(--radius-md);overflow-wrap:anywhere;"></div>
                </div>
                <div class="form-group">
                    <label>File <span style="color:var(--danger)">*</span></label>
                    <input type="file" name="document_file" class="form-control"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx" required>
                </div>
                <div class="form-group">
                    <label>Comment</label>
                    <textarea name="comment" class="form-control" rows="3" placeholder="Notes about this submission..."></textarea>
                </div>
                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" id="uploadSubmitBtn">
                        <svg style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                        Save Draft
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Comments Modal -->
<div id="commentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                Review Feedback
            </h3>
            <button class="close-btn" data-modal="commentModal" type="button">
                <svg style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>PH Feedback</label>
                <p id="phComment" style="background:var(--gray-50);border-radius:8px;padding:0.875rem;min-height:50px;font-size:0.875rem;border-left:4px solid var(--primary);color:var(--gray-700);white-space:pre-wrap;overflow-wrap:anywhere;">—</p>
            </div>
            <div class="form-group" id="adminCommentGroup">
                <label>Admin Feedback</label>
                <p id="adminComment" style="background:var(--gray-50);border-radius:8px;padding:0.875rem;min-height:50px;font-size:0.875rem;border-left:4px solid var(--danger);color:var(--gray-700);white-space:pre-wrap;overflow-wrap:anywhere;">—</p>
            </div>
        </div>
    </div>
</div>

<!-- Description Modal -->
<div id="descModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                </svg>
                Description
            </h3>
            <button class="close-btn" data-modal="descModal" type="button">
                <svg style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <p id="descReqName" style="margin:0 0 0.75rem;font-weight:600;color:var(--gray-900);font-size:0.875rem;overflow-wrap:anywhere;"></p>
            <p id="descBody" style="margin:0;color:var(--gray-700);font-size:0.9rem;line-height:1.6;white-space:pre-wrap;overflow-wrap:break-word;max-height:50vh;overflow-y:auto;"></p>
        </div>
    </div>
</div>

<?php
$extraScripts = "
<script nonce=\"" . csp_nonce() . "\">
const csrfToken = '" . getCsrfToken() . "';

function showToast(message, type) {
    const toast = document.createElement('div');
    const icon = type === 'success'
        ? '<svg style=\"width:20px;height:20px;flex-shrink:0;\" fill=\"none\" viewBox=\"0 0 24 24\" stroke-width=\"2\" stroke=\"currentColor\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z\" /></svg>'
        : '<svg style=\"width:20px;height:20px;flex-shrink:0;\" fill=\"none\" viewBox=\"0 0 24 24\" stroke-width=\"2\" stroke=\"currentColor\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z\" /></svg>';

    toast.innerHTML = '<span style=\"display:flex;align-items:center;\">' + icon + '</span> <span></span>';
    toast.querySelector('span:last-child').textContent = message;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;padding:0.875rem 1.25rem;display:flex;align-items:center;gap:0.75rem;background:' +
        (type === 'success' ? '#ecfdf5' : '#fef2f2') + ';color:' +
        (type === 'success' ? '#065f46' : '#991b1b') +
        ';border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.12),0 0 0 1px ' + (type === 'success' ? '#a7f3d0' : '#fecaca') + ';font-weight:600;font-size:0.85rem;animation:slideIn 0.3s cubic-bezier(0.34,1.56,0.64,1);max-width:calc(100vw - 40px);overflow-wrap:anywhere;';
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ====================== ACTION DROPDOWN TOGGLE ======================
document.querySelectorAll('.action-dropdown .dropdown-trigger').forEach(trigger => {
    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        const dropdown = this.closest('.action-dropdown');
        const wasOpen  = dropdown.classList.contains('open');

        // Close all others first
        document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));

        if (!wasOpen) dropdown.classList.add('open');
    });
});

// Close dropdown when clicking a menu item
document.querySelectorAll('.action-dropdown .dropdown-item').forEach(item => {
    item.addEventListener('click', () => {
        item.closest('.action-dropdown')?.classList.remove('open');
    });
});

// Close on outside click
document.addEventListener('click', e => {
    if (!e.target.closest('.action-dropdown')) {
        document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});

// ====================== UPLOAD / SAVE DRAFT ======================
document.querySelectorAll('.upload-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const isPh = this.dataset.isPh === '1';
        document.getElementById('upload_req_id').value    = isPh ? '' : this.dataset.reqid;
        document.getElementById('upload_ph_req_id').value = isPh ? this.dataset.reqid : '';
        document.getElementById('upload_is_ph').value     = isPh ? '1' : '0';
        document.getElementById('upload_req_label').textContent = this.dataset.label;
        document.getElementById('uploadModal').classList.add('show');
    });
});

document.getElementById('uploadForm')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const form      = this;
    const btn       = document.getElementById('uploadSubmitBtn');
    const fileInput = form.querySelector('input[type=\"file\"]');
    const f         = fileInput.files[0];

    if (!f) {
        showToast('Please select a file to upload.', 'error');
        return;
    }
    const isImg = /\\.(jpe?g|png|gif|webp)$/i.test(f.name);
    if (f.size > (isImg ? 10 : 100) * 1024 * 1024) {
        showToast('File too large. Maximum size is ' + (isImg ? '10MB' : '100MB') + '.', 'error');
        return;
    }

    const originalHtml = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.disabled = true;

    const formData = new FormData(form);
    formData.append('ajax_upload', '1');
    if (!formData.has('csrf_token')) formData.append('csrf_token', csrfToken);

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => { if (!r.ok) throw new Error('Server error: ' + r.status); return r.json(); })
    .then(data => {
        if (data.success) {
            document.getElementById('uploadModal').classList.remove('show');
            showToast(data.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            showToast(data.message, 'error');
        }
    })
    .catch(err => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        showToast('Upload failed: ' + err.message, 'error');
    });
});

// ====================== CHANGE DRAFT (re-upload) ======================
document.querySelectorAll('.change-draft-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const isPh = this.dataset.isPh === '1';
        document.getElementById('upload_req_id').value    = isPh ? '' : this.dataset.reqid;
        document.getElementById('upload_ph_req_id').value = isPh ? this.dataset.reqid : '';
        document.getElementById('upload_is_ph').value     = isPh ? '1' : '0';
        document.getElementById('upload_req_label').textContent = this.dataset.label;
        document.getElementById('uploadModal').classList.add('show');
    });
});

// ====================== DELETE DRAFT ======================
document.querySelectorAll('.delete-draft-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        if (!confirm('Delete this draft? This cannot be undone.')) return;

        const btnEl = this;
        const orig  = btnEl.textContent;
        btnEl.textContent = 'Deleting...';
        btnEl.disabled = true;

        const fd = new FormData();
        fd.append('ajax_action', 'delete_draft');
        fd.append('document_id', this.dataset.id);
        fd.append('is_ph_requirement', this.dataset.isPh === '1' ? '1' : '');
        fd.append('csrf_token', csrfToken);

        fetch(window.location.href, {
            method: 'POST', body: fd, credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                btnEl.textContent = orig;
                btnEl.disabled = false;
                showToast(data.message, 'error');
            }
        })
        .catch(err => {
            btnEl.textContent = orig;
            btnEl.disabled = false;
            showToast('Delete failed: ' + err.message, 'error');
        });
    });
});

// ====================== SUBMIT DRAFT ======================
document.querySelectorAll('.submit-draft-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        if (!confirm('Submit this document to your Program Head for review?\\n\\nYou will not be able to edit it until it is returned.')) {
            return;
        }

        const btnEl = this;
        const orig  = btnEl.textContent;
        btnEl.textContent = 'Submitting...';
        btnEl.disabled = true;

        const fd = new FormData();
        fd.append('ajax_action', 'submit_draft');
        fd.append('document_id', this.dataset.id);
        fd.append('is_ph_requirement', this.dataset.isPh === '1' ? '1' : '');
        fd.append('csrf_token', csrfToken);

        fetch(window.location.href, {
            method: 'POST', body: fd, credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                btnEl.textContent = orig;
                btnEl.disabled = false;
                showToast(data.message, 'error');
            }
        })
        .catch(err => {
            btnEl.textContent = orig;
            btnEl.disabled = false;
            showToast('Submit failed: ' + err.message, 'error');
        });
    });
});

// ====================== COMMENTS / DESCRIPTION / CLOSE ======================
document.querySelectorAll('.comment-btn').forEach(b => {
    b.addEventListener('click', function () {
        const ph    = this.dataset.ph;
        const admin = this.dataset.admin;
        document.getElementById('phComment').textContent    = ph    || '—';
        document.getElementById('adminComment').textContent = admin || '—';
        document.getElementById('adminCommentGroup').style.display = admin ? 'block' : 'none';
        document.getElementById('commentModal').classList.add('show');
    });
});

document.querySelectorAll('.desc-btn').forEach(b => {
    b.addEventListener('click', function () {
        document.getElementById('descReqName').textContent = this.dataset.name || '';
        document.getElementById('descBody').textContent    = this.dataset.desc || '';
        document.getElementById('descModal').classList.add('show');
    });
});

document.querySelectorAll('.close-btn[data-modal]').forEach(b => {
    b.addEventListener('click', function () {
        document.getElementById(this.dataset.modal).classList.remove('show');
    });
});
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal') && e.target.id !== 'universalDocumentModal') {
        e.target.classList.remove('show');
    }
});
</script>
";
include 'includes/footer.php';