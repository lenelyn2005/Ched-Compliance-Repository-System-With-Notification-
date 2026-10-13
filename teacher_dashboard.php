<?php
define('CHED_APP', true);
require_once 'config.php';
requireTeacher();

$teacherId = (int) $_SESSION['user_id'];

// The submission stages are named after whoever reviews them, which is the
// Program Head or the Unit Head of this faculty member's department.
$deptStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
$deptStmt->execute([$teacherId]);
$teacherDeptId = (int) $deptStmt->fetchColumn();
$reviewer = $teacherDeptId ? getProgramHeadForDepartment($pdo, $teacherDeptId) : null;
$reviewerName = phRoleName($reviewer['role'] ?? null);

// ========== AJAX HANDLERS ==========
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    $ajaxAction = $_POST['ajax_action'] ?? '';

    if ($ajaxAction === 'refresh_dashboard') {
        $progress = getTeacherProgress($pdo, $teacherId);
        $effectivePct = $progress['total'] > 0
            ? round(($progress['approved'] / $progress['total']) * 100, 2)
            : 0;
        sendJson([
            'approved'   => $progress['approved'],
            'submitted'  => $progress['submitted'],
            'rejected'   => $progress['rejected'],
            'pending'    => $progress['pending'],
            'total'      => $progress['total'],
            'percentage' => $effectivePct,
        ]);
    }

    if (!empty($_POST['ajax_upload'])) {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid session. Please refresh and try again.']);
            exit;
        }
        $isPh = isset($_POST['is_ph_upload']) && $_POST['is_ph_upload'] === '1';
        if ($isPh) {
            $reqId = (int) ($_POST['ph_requirement_id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            if (!$reqId) {
                echo json_encode(['success' => false, 'message' => 'Invalid PH requirement.']);
                exit;
            }
            $check = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id=? AND teacher_id=?");
            $check->execute([$reqId, $teacherId]);
            $assignment = $check->fetch();
            if (!$assignment) {
                echo json_encode(['success' => false, 'message' => 'Invalid PH requirement.']);
                exit;
            }
            if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadError = $_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                $errorMsg = 'Please select a file to upload.';
                if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) { $ext = strtolower(pathinfo($_FILES['document_file']['name'] ?? '', PATHINFO_EXTENSION)); $errorMsg = 'File is too large. Maximum size is ' . maxFileSizeLabelFor($ext) . '.'; }
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }
            $result = uploadTeacherPHSubmission($pdo, $assignment['id'], $teacherId, $_FILES['document_file'], $comment);
            echo json_encode($result === true
                ? ['success' => true, 'message' => "File uploaded successfully! Your document is now pending review by the $reviewerName."]
                : ['success' => false, 'message' => $result]);
            exit;
        }
        $reqId = (int) ($_POST['requirement_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        if (!$reqId) {
            echo json_encode(['success' => false, 'message' => 'Invalid requirement.']);
            exit;
        }
        $check = $pdo->prepare("SELECT id, requirement_type FROM teacher_requirements WHERE id=? AND teacher_id=?");
        $check->execute([$reqId, $teacherId]);
        $reqData = $check->fetch();
        if (!$reqData) {
            echo json_encode(['success' => false, 'message' => 'Invalid requirement. This requirement is not assigned to you.']);
            exit;
        }
        if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadError = $_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMsg = 'Please select a file to upload.';
            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) { $ext = strtolower(pathinfo($_FILES['document_file']['name'] ?? '', PATHINFO_EXTENSION)); $errorMsg = 'File is too large. Maximum size is ' . maxFileSizeLabelFor($ext) . '.'; }
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        $result = uploadTeacherSubmission($pdo, $reqId, $teacherId, $_FILES['document_file'], $comment);
        echo json_encode($result === true
            ? ['success' => true, 'message' => "File uploaded successfully! Your document is now pending review by the $reviewerName."]
            : ['success' => false, 'message' => $result]);
        exit;
    }

    if ($ajaxAction === 'delete_requirement') {
        $ajaxCsrf = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($ajaxCsrf)) {
            echo json_encode(['success' => false, 'message' => 'Invalid session. Please refresh and try again.']);
            exit;
        }
        $reqId = (int) ($_POST['requirement_id'] ?? 0);
        $isPh  = !empty($_POST['is_ph_requirement']);
        if (!$reqId) {
            echo json_encode(['success' => false, 'message' => 'Invalid requirement ID.']);
            exit;
        }
        if ($isPh) {
            $check = $pdo->prepare("
                SELECT tpr.id FROM teacher_ph_requirements tpr
                JOIN ph_documents pd ON pd.teacher_ph_requirement_id = tpr.id
                WHERE tpr.id = ? AND tpr.teacher_id = ?
                AND pd.status IN ('approved_admin', 'approved_program_head')
                ORDER BY pd.version DESC LIMIT 1
            ");
            $check->execute([$reqId, $teacherId]);
            if ($check->fetch()) {
                $pdo->prepare("DELETE FROM teacher_ph_requirements WHERE id = ? AND teacher_id = ?")->execute([$reqId, $teacherId]);
                logAudit($pdo, 'teacher_delete_requirement', "Teacher deleted PH requirement ID $reqId", 'teacher_ph_requirements', $reqId);
                echo json_encode(['success' => true, 'message' => 'Requirement removed from your dashboard.']);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Only approved requirements can be removed.']);
            exit;
        }
        $checkReq = $pdo->prepare("
            SELECT tr.id FROM teacher_requirements tr
            LEFT JOIN (
                SELECT d1.teacher_requirement_id, d1.status
                FROM documents d1
                INNER JOIN (
                    SELECT teacher_requirement_id, MAX(version) as max_version
                    FROM documents GROUP BY teacher_requirement_id
                ) d2 ON d1.teacher_requirement_id = d2.teacher_requirement_id AND d1.version = d2.max_version
            ) latest_doc ON tr.id = latest_doc.teacher_requirement_id
            WHERE tr.id = ? AND tr.teacher_id = ?
            AND latest_doc.status IN ('approved_admin', 'approved_program_head')
        ");
        $checkReq->execute([$reqId, $teacherId]);
        if ($checkReq->fetch()) {
            $pdo->prepare("DELETE FROM teacher_requirements WHERE id = ? AND teacher_id = ?")->execute([$reqId, $teacherId]);
            logAudit($pdo, 'teacher_delete_requirement', "Teacher deleted requirement ID $reqId", 'teacher_requirements', $reqId);
            echo json_encode(['success' => true, 'message' => 'Requirement removed from your dashboard.']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Only approved requirements can be removed.']);
        exit;
    }
    exit;
}

// ========== FETCH DATA ==========
$progress = getTeacherProgress($pdo, $teacherId);
$progress['percentage'] = $progress['total'] > 0
    ? round(($progress['approved'] / $progress['total']) * 100, 2)
    : 0;

$requirements  = getTeacherRequirementsOptimized($pdo, $teacherId);
$phRequirements = getTeacherPHRequirementsOptimized($pdo, $teacherId);

// Label = the real head who created/assigned the requirement (Unit Head vs
// Program Head) — same rule as teacher_tasks.php.
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
    $phReq['is_ph_requirement']   = true;
    $phReq['source']              = 'ph_document';
    $phReq['category']            = $headLabel($phReq['created_by'] ?? null);
    $phReq['document_type']       = $phReq['title'];
    $phReq['mandatory']           = 1;
    $phReq['expiration_date']     = $phReq['deadline'] ?? null;
    $phReq['latest_admin_comment'] = $phReq['latest_admin_comment'] ?? null;
    $phReq['latest_ph_comment']    = $phReq['latest_ph_comment'] ?? null;
    $phReq['latest_filename']      = $phReq['latest_filename'] ?? null;
    $phReq['template_filename']    = $phReq['ref_filename'] ?? null;
    $phReq['template_file_path']   = $phReq['ref_file_path'] ?? null;
    $phReq['source_label']         = $headLabel($phReq['created_by'] ?? null);
    $phReq['source_label_class']   = 'badge-info';
}
unset($phReq);

foreach ($requirements as &$req) {
    $req['source']             = 'document';
    $req['is_ph_requirement']  = false;
    $req['template_filename']  = null;
    $req['template_file_path'] = null;
    if (isset($req['requirement_type']) && $req['requirement_type'] === 'program_head_requirement') {
        $req['source_label']       = $headLabel($req['assigned_by'] ?? null);
        $req['source_label_class'] = 'badge-info';
    } else {
        $req['source_label']       = 'Admin';
        $req['source_label_class'] = 'badge-warning';
    }
}
unset($req);

$allRequirements = array_merge($requirements, $phRequirements);

$completedRequirements  = [];
$rejectedRequirements   = [];
$pendingRequirements    = [];
$notStartedRequirements = [];

foreach ($allRequirements as $req) {
    $status = $req['current_status'] ?? '';
    if (in_array($status, ['approved_program_head', 'approved_admin'])) {
        $completedRequirements[] = $req;
    } elseif (in_array($status, ['rejected_program_head', 'rejected_admin'])) {
        $rejectedRequirements[] = $req;
    } elseif (in_array($status, ['pending_program_head', 'pending_admin'])) {
        $pendingRequirements[] = $req;
    } else {
        $notStartedRequirements[] = $req;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload']) && !empty($_POST['requirement_id'])) {
    requireCsrfToken();
    $reqId   = (int) $_POST['requirement_id'];
    $comment = trim($_POST['comment'] ?? '');
    $check = $pdo->prepare("SELECT id FROM teacher_requirements WHERE id=? AND teacher_id=?");
    $check->execute([$reqId, $teacherId]);
    if (!$check->fetch()) {
        $error = 'Invalid requirement.';
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a file to upload.';
    } else {
        $result = uploadTeacherSubmission($pdo, $reqId, $teacherId, $_FILES['document_file'], $comment);
        if ($result === true) {
            header("Location: teacher_dashboard.php?msg=File uploaded successfully!&t=" . time());
            exit;
        }
        $error = $result;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_ph']) && !empty($_POST['ph_requirement_id'])) {
    requireCsrfToken();
    $phReqId = (int) $_POST['ph_requirement_id'];
    $comment = trim($_POST['comment'] ?? '');
    $check = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id=? AND teacher_id=?");
    $check->execute([$phReqId, $teacherId]);
    $assignment = $check->fetch();
    if (!$assignment) {
        $error = 'Invalid PH requirement.';
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a file to upload.';
    } else {
        $result = uploadTeacherPHSubmission($pdo, $assignment['id'], $teacherId, $_FILES['document_file'], $comment);
        if ($result === true) {
            header("Location: teacher_dashboard.php?msg=File uploaded successfully!&t=" . time());
            exit;
        }
        $error = $result;
    }
}

if (isset($_GET['msg'])) $success = $_GET['msg'];

$statusConfig = [
    'pending_program_head'  => ['label' => "Pending $reviewerName Review", 'class' => 'badge-warning', 'icon' => 'pending',       'canUpload' => false],
    'approved_program_head' => ['label' => "Approved by $reviewerName",   'class' => 'badge-success', 'icon' => 'approved',      'canUpload' => false],
    'rejected_program_head' => ['label' => 'Needs Correction',           'class' => 'badge-danger',  'icon' => 'rejected',      'canUpload' => true],
    'pending_admin'         => ['label' => 'Pending Admin Review',       'class' => 'badge-info',    'icon' => 'pending-admin', 'canUpload' => false],
    'rejected_admin'        => ['label' => 'Needs Correction',           'class' => 'badge-danger',  'icon' => 'rejected',      'canUpload' => true],
    'approved_admin'        => ['label' => 'Fully Approved',             'class' => 'badge-success', 'icon' => 'approved',      'canUpload' => false],
];

function renderStatusIcon($iconType)
{
    $icons = [
        'approved'      => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
        'rejected'      => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
        'pending'       => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
        'pending-admin' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>',
        'not-started'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>',
    ];
    return $icons[$iconType] ?? $icons['not-started'];
}

function renderNoteBlock(array $parts, string $triggerLabel = 'Notes', string $triggerIconClass = 'info'): string
{
    $parts = array_filter($parts, fn($p) => !empty(trim((string)($p['body'] ?? ''))));
    if (empty($parts)) return '';
    static $noteSeq = 0;
    $noteSeq++;
    $uid = 'note-' . $noteSeq;
    $icon = [
        'info'    => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>',
        'danger'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
    ][$triggerIconClass] ?? '';
    $totalBlocks = count($parts);
    $html  = '<button type="button" class="btn btn-sm btn-outline note-btn" data-note-target="' . $uid . '" data-note-title="' . htmlspecialchars($triggerLabel, ENT_QUOTES, 'UTF-8') . '">'
        . $icon . htmlspecialchars($triggerLabel, ENT_QUOTES, 'UTF-8')
        . ($totalBlocks > 1 ? ' <span class="note-count">' . $totalBlocks . '</span>' : '') . '</button>';
    $html .= '<div id="' . $uid . '" hidden>';
    foreach ($parts as $p) {
        $variant = $p['variant'] ?? 'default';
        $html .= '<div class="note-block">';
        $html .= '<div class="note-block-title note-block-title--' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<div class="note-block-body note-block-body--' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . '">' . nl2br(sanitize((string)$p['body'])) . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function renderDescButton(array $req): string
{
    $desc = trim((string)($req['description'] ?? ''));
    if ($desc === '') return '';
    $name = ($req['category'] ?? '') . ' - ' . ($req['document_type'] ?? '');
    return '<button type="button" class="btn btn-sm btn-outline desc-btn"'
        . ' data-name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-desc="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">'
        . 'Description</button>';
}

function renderDeadlineBadge(array $req): string
{
    if (empty($req['expiration_date'])) return '';
    $expiryDate = strtotime($req['expiration_date']);
    if (!$expiryDate) return '';
    $today    = strtotime(date('Y-m-d'));
    $daysLeft = ($expiryDate - $today) / (60 * 60 * 24);
    $isOverdue = $daysLeft < 0;
    $isUrgent  = $daysLeft >= 0 && $daysLeft <= EXPIRY_WARNING_DAYS;
    $pillClass = $isOverdue ? 'is-overdue' : ($isUrgent ? 'is-urgent' : '');
    $flag = $isOverdue ? '<span class="deadline-flag">Expired</span>'
        : ($isUrgent ? '<span class="deadline-flag">Due soon</span>' : '');
    return '<span class="deadline-pill ' . $pillClass . '">'
        . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>'
        . date('M j, Y', $expiryDate) . $flag . '</span>';
}

$pageTitle = facultyRoleName($_SESSION['role'] ?? '') . ' Dashboard';
ob_start();
?>
<style>
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

    * {
        box-sizing: border-box;
    }

    body {
        background: var(--gray-50);
        font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        color: var(--gray-800);
        font-size: 0.875rem;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(14px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes shimmer {
        0% {
            background-position: -200% 0;
        }

        100% {
            background-position: 200% 0;
        }
    }

    @keyframes pulse-ring {

        0%,
        100% {
            transform: scale(0.95);
            opacity: 1;
        }

        50% {
            transform: scale(1.02);
        }
    }

    @keyframes ringPulse {

        0%,
        100% {
            opacity: 0.5;
            transform: scale(1);
        }

        50% {
            opacity: 1;
            transform: scale(1.05);
        }
    }

    @keyframes modalIn {
        from {
            opacity: 0;
            transform: translateY(12px) scale(0.98);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

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
        letter-spacing: -0.025em;
        margin: 0;
        line-height: 1.25;
    }

    .page-head p {
        margin: 0.25rem 0 0;
        font-size: 0.875rem;
        color: var(--gray-500);
    }

    .page-head .page-meta {
        font-size: 0.8125rem;
        color: var(--gray-500);
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 9999px;
        padding: 0.4rem 0.85rem;
        white-space: nowrap;
        box-shadow: var(--shadow-xs);
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-weight: 500;
    }

    .page-head .page-meta .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--gray-400);
    }

    .page-head .page-meta.has-progress {
        color: var(--primary-dark);
        border-color: #bfdbfe;
        background: var(--primary-light);
    }

    .page-head .page-meta.has-progress .dot {
        background: var(--primary);
    }

    .page-head .page-meta.is-done {
        color: #047857;
        border-color: #a7f3d0;
        background: #ecfdf5;
    }

    .page-head .page-meta.is-done .dot {
        background: var(--success);
    }

    .progress-hero {
        background: radial-gradient(1200px 400px at 85% -10%, rgba(96, 165, 250, 0.18), transparent 60%), radial-gradient(900px 380px at 10% 110%, rgba(16, 185, 129, 0.14), transparent 60%), linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #334155 100%);
        border-radius: 14px;
        padding: 1.75rem 2rem;
        color: white;
        display: flex;
        align-items: center;
        gap: 2.25rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 20px 40px -22px rgba(15, 23, 42, 0.45);
    }

    .progress-hero::before {
        content: '';
        position: absolute;
        top: -60%;
        right: -15%;
        width: 450px;
        height: 450px;
        background: radial-gradient(circle, rgba(99, 102, 241, 0.22) 0%, transparent 65%);
        pointer-events: none;
        animation: pulse-ring 8s ease-in-out infinite;
    }

    .progress-hero::after {
        content: '';
        position: absolute;
        bottom: -40%;
        left: 5%;
        width: 250px;
        height: 250px;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.14) 0%, transparent 65%);
        pointer-events: none;
    }

    .progress-ring-container {
        position: relative;
        width: 128px;
        height: 128px;
        flex-shrink: 0;
        z-index: 1;
    }

    .progress-ring {
        transform: rotate(-90deg);
        filter: drop-shadow(0 0 8px rgba(16, 185, 129, 0.4));
    }

    .progress-ring-glow {
        position: absolute;
        inset: -4px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, transparent 70%);
        animation: ringPulse 3s ease-in-out infinite;
    }

    .progress-ring-bg {
        fill: none;
        stroke: rgba(255, 255, 255, 0.1);
        stroke-width: 7;
    }

    .progress-ring-fill {
        fill: none;
        stroke: url(#progressGradient);
        stroke-width: 7;
        stroke-linecap: round;
        stroke-dasharray: 314.159;
        stroke-dashoffset: 314.159;
        transition: stroke-dashoffset 1.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .big-pct {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 1.75rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        font-variant-numeric: tabular-nums;
    }

    .progress-info {
        flex: 1;
        z-index: 1;
        min-width: 0;
    }

    .progress-info .hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(148, 163, 184, 0.9);
        margin-bottom: 0.35rem;
    }

    .progress-info .hero-eyebrow svg {
        width: 12px;
        height: 12px;
    }

    .progress-info h2 {
        font-size: 1.4rem;
        font-weight: 800;
        margin: 0 0 0.25rem;
        letter-spacing: -0.02em;
        background: linear-gradient(135deg, #ffffff 0%, #e2e8f0 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .progress-info .hero-sub {
        font-size: 0.85rem;
        opacity: 0.85;
        margin: 0;
    }

    .hero-progress-bar {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 9999px;
        height: 8px;
        overflow: hidden;
        margin: 1rem 0 1.1rem;
        position: relative;
    }

    .hero-progress-fill {
        background: linear-gradient(90deg, #10b981, #34d399, #6ee7b7);
        height: 100%;
        width: 0%;
        border-radius: 9999px;
        transition: width 1.6s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 0 16px rgba(16, 185, 129, 0.6);
        position: relative;
    }

    .hero-progress-fill::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.3) 50%, transparent 100%);
        animation: shimmer 2s ease-in-out infinite;
        background-size: 200% 100%;
    }

    .stats-summary {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.625rem;
    }

    .stat-item {
        background: rgba(255, 255, 255, 0.06);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        padding: 0.7rem 0.5rem;
        border-radius: var(--radius-md);
        text-align: center;
        border: 1px solid rgba(255, 255, 255, 0.08);
        transition: var(--transition);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.15rem;
    }

    .stat-item:hover {
        background: rgba(255, 255, 255, 0.11);
        transform: translateY(-1px);
        border-color: rgba(255, 255, 255, 0.16);
    }

    .stat-item .stat-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        display: inline-block;
        margin-bottom: 0.15rem;
    }

    .stat-val {
        font-size: 1.35rem;
        font-weight: 800;
        display: block;
        letter-spacing: -0.02em;
        font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    .stat-lbl {
        font-size: 0.6rem;
        opacity: 0.72;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        display: block;
        font-weight: 600;
    }

    .next-steps {
        margin-top: 0.875rem;
        padding: 0.65rem 0.9rem;
        background: rgba(255, 255, 255, 0.08);
        border-radius: var(--radius-md);
        font-size: 0.8rem;
        border: 1px solid rgba(255, 255, 255, 0.06);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .next-steps svg {
        width: 14px;
        height: 14px;
        opacity: 0.7;
        flex-shrink: 0;
    }

    .next-steps p,
    .next-steps small {
        margin: 0;
        opacity: 0.92;
    }

    .next-steps strong {
        color: #6ee7b7;
    }

    .card {
        background: var(--white);
        border-radius: 14px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: box-shadow 0.2s ease;
        box-shadow: var(--shadow-xs);
    }

    .card:hover {
        box-shadow: var(--shadow-sm);
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

    .card-content {
        padding: 1.25rem;
    }

    .section-count {
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .tab-nav {
        display: flex;
        gap: 0.25rem;
        padding: 0.3rem;
        background: var(--gray-100);
        border-radius: var(--radius-lg);
        margin-bottom: 1.25rem;
        overflow-x: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .tab-nav::-webkit-scrollbar {
        display: none;
    }

    .tab-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.875rem;
        border-radius: var(--radius-md);
        font-family: inherit;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        border: none;
        background: transparent;
        color: var(--gray-500);
        white-space: nowrap;
        flex-shrink: 0;
    }

    .tab-btn:hover:not(.active) {
        color: var(--gray-700);
        background: rgba(255, 255, 255, 0.6);
    }

    .tab-btn.active {
        background: white;
        color: var(--primary);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.06);
    }

    .tab-btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    .tab-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.4rem;
        height: 1.2rem;
        padding: 0 0.35rem;
        border-radius: 9999px;
        background: var(--gray-200);
        color: var(--gray-600);
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
        transition: var(--transition);
    }

    .tab-btn.active .tab-count {
        background: var(--primary-light);
        color: var(--primary-dark);
    }

    .req-section {
        will-change: opacity, transform;
    }

    .req-section.tab-hidden {
        display: none;
    }

    .req-section.req-animate {
        animation: fadeInUp 0.35s ease both;
    }

    .req-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 0.85rem;
    }

    /* ============================================================
       Requirement card
       ============================================================ */
    .req-card {
        position: relative;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 1rem 1.05rem;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
        box-shadow: var(--shadow-xs);
        transition: var(--transition);
        overflow: hidden;
    }

    .req-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--gray-200);
    }

    .req-card.approved {
        border-color: #d1fae5;
    }

    .req-card.approved::before {
        background: var(--success);
    }

    .req-card.rejected {
        border-color: #fecaca;
    }

    .req-card.rejected::before {
        background: var(--danger);
    }

    .req-card.pending-review {
        border-color: #fde68a;
    }

    .req-card.pending-review::before {
        background: var(--warning);
    }

    .req-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 12px 26px -18px rgba(15, 23, 42, 0.35);
    }

    .req-card.approved:hover {
        border-color: #a7f3d0;
    }

    .req-card.rejected:hover {
        border-color: #fca5a5;
    }

    .req-card.pending-review:hover {
        border-color: #fcd34d;
    }

    .req-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.5rem;
    }

    /* Category line — ONLY shows the category name + mandatory dot. No source badge here. */
    .req-category {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--gray-500);
        letter-spacing: 0.06em;
    }

    .req-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--gray-900);
        margin-top: 0.25rem;
        line-height: 1.35;
        letter-spacing: -0.01em;
    }

    .mandatory-dot {
        display: inline-block;
        width: 6px;
        height: 6px;
        background: var(--danger);
        border-radius: 50%;
        flex-shrink: 0;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.18);
    }

    /* Status indicator */
    .status-indicator {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 10px;
        flex-shrink: 0;
    }

    .status-indicator svg {
        width: 16px;
        height: 16px;
    }

    .status-indicator.status-approved {
        background: #ecfdf5;
        color: #047857;
    }

    .status-indicator.status-rejected {
        background: #fef2f2;
        color: #b91c1c;
    }

    .status-indicator.status-pending {
        background: #fef3c7;
        color: #b45309;
    }

    .status-indicator.status-pending-admin {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .status-indicator.status-not-started {
        background: var(--gray-100);
        color: var(--gray-400);
    }

    /* Badge row — source badge + status badge + deadline pill sit side by side */
    .badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem;
        align-items: center;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.22rem 0.62rem;
        border-radius: 9999px;
        font-size: 0.72rem;
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

    .badge-secondary {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .version-info {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.7rem;
        font-weight: 500;
        color: var(--gray-500);
    }

    .version-info svg {
        width: 12px;
        height: 12px;
    }

    .deadline-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 0.22rem 0.55rem;
        border-radius: 999px;
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .deadline-pill svg {
        width: 12px;
        height: 12px;
    }

    .deadline-pill.is-urgent {
        background: #fef3c7;
        color: #92400e;
    }

    .deadline-pill.is-overdue {
        background: #fee2e2;
        color: #b91c1c;
    }

    .deadline-pill .deadline-flag {
        font-size: 0.62rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 0.05rem 0.35rem;
        border-radius: 4px;
        background: currentColor;
        color: #fff;
        line-height: 1;
    }

    .deadline-pill.is-urgent .deadline-flag {
        background: var(--warning);
    }

    .deadline-pill.is-overdue .deadline-flag {
        background: var(--danger);
    }

    .req-actions {
        margin-top: auto;
        padding-top: 0.7rem;
        border-top: 1px solid var(--gray-100);
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.8125rem;
        font-weight: 500;
        font-family: inherit;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.4;
        white-space: nowrap;
    }

    .btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    .btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.78rem;
        border-radius: 8px;
    }

    .btn svg {
        flex-shrink: 0;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
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

    .note-btn {
        border-radius: 999px;
        border-color: var(--gray-200);
        background: var(--white);
        color: var(--gray-700);
        font-weight: 600;
    }

    .note-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
    }

    .note-btn svg {
        width: 13px;
        height: 13px;
    }

    .note-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.1rem;
        height: 1.1rem;
        padding: 0 0.3rem;
        border-radius: 999px;
        background: var(--primary);
        color: #fff;
        font-size: 0.6rem;
        font-weight: 800;
        line-height: 1;
    }

    .note-btn:hover .note-count {
        background: var(--primary-dark);
    }

    .alert {
        padding: 0.9rem 1.15rem;
        border-radius: var(--radius-md);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.875rem;
        border: 1px solid var(--border);
        border-left: 3px solid;
        box-shadow: var(--shadow-xs);
        animation: fadeInUp 0.3s ease forwards;
    }

    .alert svg {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }

    .alert-success {
        background: #ecfdf5;
        color: #047857;
        border-left-color: var(--success);
        border-color: #a7f3d0;
    }

    .alert-danger {
        background: #fef2f2;
        color: #b91c1c;
        border-left-color: var(--danger);
        border-color: #fecaca;
    }

    .modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .modal.show {
        display: flex;
    }

    .modal-content {
        background: var(--white);
        border-radius: 14px;
        width: 100%;
        max-width: 440px;
        box-shadow: var(--shadow-xl);
        animation: modalIn 0.22s cubic-bezier(0.34, 1.3, 0.64, 1);
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 2rem);
        overflow: hidden;
        border: 1px solid var(--border);
    }

    .modal-content--notes {
        max-width: 600px;
    }

    .modal-header {
        padding: 0.95rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-shrink: 0;
        background: var(--white);
    }

    .modal-header h3 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 650;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.55rem;
        letter-spacing: -0.01em;
    }

    .modal-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--primary);
    }

    .modal-body {
        padding: 1.25rem;
        overflow-y: auto;
        flex: 1;
        min-height: 0;
    }

    .close-btn {
        background: var(--gray-100);
        border: none;
        cursor: pointer;
        color: var(--gray-500);
        transition: var(--transition);
        width: 2rem;
        height: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        flex-shrink: 0;
    }

    .close-btn:hover {
        color: var(--gray-900);
        background: var(--gray-200);
    }

    .close-btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    .note-block {
        margin-bottom: 1.1rem;
    }

    .note-block:last-child {
        margin-bottom: 0;
    }

    .note-block-title {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-600);
        padding-bottom: 0.45rem;
        margin-bottom: 0.6rem;
        border-bottom: 1px solid var(--gray-100);
    }

    .note-block-title::before {
        content: '';
        width: 4px;
        height: 12px;
        border-radius: 999px;
        background: var(--gray-300);
    }

    .note-block-title--info::before {
        background: var(--info);
    }

    .note-block-title--danger::before {
        background: var(--danger);
    }

    .note-block-title--primary::before {
        background: var(--primary);
    }

    .note-block-title--info {
        color: var(--info);
    }

    .note-block-title--danger {
        color: var(--danger);
    }

    .note-block-title--primary {
        color: var(--primary);
    }

    .note-block-body {
        font-size: 0.875rem;
        line-height: 1.6;
        color: var(--gray-700);
        padding: 0.85rem 1rem;
        background: var(--gray-50);
        border-radius: var(--radius-md);
        border-left: 3px solid var(--gray-200);
        word-break: break-word;
    }

    .note-block-body--info {
        border-left-color: var(--info);
        background: var(--info-light);
        color: #1e40af;
    }

    .note-block-body--danger {
        border-left-color: var(--danger);
        background: var(--danger-light);
        color: #991b1b;
    }

    .note-block-body--primary {
        border-left-color: var(--primary);
        background: var(--primary-light);
        color: #1e40af;
    }

    .form-group {
        margin-bottom: 1.125rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.4rem;
        font-weight: 600;
        font-size: 0.82rem;
        color: var(--gray-700);
    }

    .form-control {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-family: inherit;
        transition: var(--transition);
        background: var(--white);
        color: var(--gray-800);
    }

    .form-control:hover {
        border-color: var(--gray-300);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    .form-control::placeholder {
        color: var(--gray-400);
    }

    .requirements-panel .tab-nav {
        margin-bottom: 0.875rem;
    }

    .requirements-panel .req-section {
        margin-bottom: 0.75rem;
    }

    .requirements-panel .req-section:last-of-type {
        margin-bottom: 0;
    }

    .tab-empty {
        display: none;
        text-align: center;
        padding: 3rem 1.25rem;
        background: var(--white);
        border-radius: 14px;
        border: 1px dashed var(--gray-300);
        margin-bottom: 1rem;
    }

    .tab-empty.visible {
        display: block;
        animation: fadeInUp 0.35s ease both;
    }

    .tab-empty-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        background: var(--gray-100);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
        color: var(--gray-400);
    }

    .tab-empty-icon svg {
        width: 26px;
        height: 26px;
    }

    .tab-empty h4 {
        margin: 0 0 0.3rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--gray-800);
        letter-spacing: -0.01em;
    }

    .tab-empty p {
        margin: 0;
        font-size: 0.82rem;
        color: var(--gray-500);
        line-height: 1.45;
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.5rem;
            gap: 1rem;
        }

        .page-head h1 {
            font-size: 1.2rem;
        }

        .progress-hero {
            flex-direction: column;
            padding: 1.5rem;
            gap: 1.25rem;
            text-align: center;
        }

        .progress-info .hero-eyebrow,
        .progress-info h2,
        .progress-info .hero-sub {
            text-align: center;
        }

        .stats-summary {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }

        .stat-item {
            padding: 0.625rem 0.5rem;
        }

        .stat-val {
            font-size: 1.15rem;
        }

        .stat-lbl {
            font-size: 0.55rem;
        }

        .req-grid {
            grid-template-columns: 1fr;
        }

        .card-header {
            padding: 0.9rem 1rem;
        }

        .card-content {
            padding: 1rem;
        }

        .modal-content {
            margin: 0.5rem;
            max-width: calc(100vw - 1rem);
        }
    }

    @media (max-width: 640px) {
        .tab-btn {
            padding: 0.45rem 0.7rem;
            font-size: 0.72rem;
        }

        .tab-count {
            min-width: 1.2rem;
            height: 1.1rem;
            font-size: 0.6rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {

        .progress-ring-fill,
        .hero-progress-fill,
        .hero-progress-fill::after,
        .progress-hero::before,
        .progress-ring-glow,
        .modal-content,
        .tab-empty,
        .req-section.req-animate,
        .alert,
        .req-card {
            animation: none !important;
            transition: none !important;
        }
    }
</style>
<?php $extraStyles = ob_get_clean();
include 'includes/header.php'; ?>



<?php if ($error): ?>
    <div class="alert alert-danger">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <?= sanitize($error) ?>
    </div>
<?php endif; ?>

<?php include 'includes/success_toast.php'; ?>

<div class="progress-hero">
    <svg style="position:absolute;width:0;height:0;">
        <defs>
            <linearGradient id="progressGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#10b981" />
                <stop offset="100%" stop-color="#34d399" />
            </linearGradient>
        </defs>
    </svg>

    <div class="progress-ring-container">
        <div class="progress-ring-glow"></div>
        <svg class="progress-ring" width="128" height="128" viewBox="0 0 120 120">
            <circle class="progress-ring-bg" cx="60" cy="60" r="50" />
            <circle class="progress-ring-fill" id="progressRingFill" cx="60" cy="60" r="50" />
        </svg>
        <span class="big-pct" id="bigPct" data-current="0">0%</span>
    </div>

    <div class="progress-info">
        <span class="hero-eyebrow">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            </svg>
            Overall Progress
        </span>
        <h2>My Progress</h2>
        <p class="hero-sub" id="heroProgressText">
            <?= (int) $progress['approved'] ?> approved &middot; <?= (int) $progress['submitted'] ?> in review &middot; <?= (int) $progress['total'] ?> total
        </p>

        <div class="hero-progress-bar">
            <div class="hero-progress-fill" id="heroProgressFill"></div>
        </div>

        <div class="stats-summary">
            <div class="stat-item"><span class="stat-dot" style="background:#10b981;box-shadow:0 0 8px rgba(16,185,129,0.6);"></span><span class="stat-val" id="statApproved" style="color: #10b981;">0</span><span class="stat-lbl">Done</span></div>
            <div class="stat-item"><span class="stat-dot" style="background:#f59e0b;box-shadow:0 0 8px rgba(245,158,11,0.6);"></span><span class="stat-val" id="statSubmitted" style="color: #f59e0b;">0</span><span class="stat-lbl">Review</span></div>
            <div class="stat-item"><span class="stat-dot" style="background:#ef4444;box-shadow:0 0 8px rgba(239,68,68,0.6);"></span><span class="stat-val" id="statRejected" style="color: #ef4444;">0</span><span class="stat-lbl">Fix</span></div>
            <div class="stat-item"><span class="stat-dot" style="background:#94a3b8;box-shadow:0 0 8px rgba(148,163,184,0.6);"></span><span class="stat-val" id="statPending" style="color: #94a3b8;">0</span><span class="stat-lbl">To Do</span></div>
        </div>

        <?php if ($progress['percentage'] < 100): ?>
            <div class="next-steps">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <small>Complete <strong id="heroPendingCount"><?= (int) $progress['pending'] ?></strong> more requirement<?= $progress['pending'] != 1 ? 's' : '' ?> to reach 100%</small>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card requirements-panel">
    <div class="card-header">
        <h3>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            </svg>
            My Requirements
        </h3>
        <span class="section-count"><?= count($allRequirements) ?> items</span>
    </div>
    <div class="card-content">

        <div class="tab-nav" role="tablist" aria-label="Filter requirements by status">
            <button class="tab-btn active" data-filter="all" role="tab" aria-selected="true">All <span class="tab-count"><?= count($allRequirements) ?></span></button>
            <button class="tab-btn" data-filter="todo" role="tab" aria-selected="false">To Do <span class="tab-count"><?= count($notStartedRequirements) ?></span></button>
            <button class="tab-btn" data-filter="in-review" role="tab" aria-selected="false">In Review <span class="tab-count"><?= count($pendingRequirements) ?></span></button>
            <button class="tab-btn" data-filter="needs-fix" role="tab" aria-selected="false">Needs Fix <span class="tab-count"><?= count($rejectedRequirements) ?></span></button>
            <button class="tab-btn" data-filter="completed" role="tab" aria-selected="false">Completed <span class="tab-count"><?= count($completedRequirements) ?></span></button>
        </div>

        <!-- COMPLETED -->
        <?php if (!empty($completedRequirements)): ?>
            <div class="req-section" data-tab="completed">
                <div class="card" style="margin-bottom:0.75rem;">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Completed (<?= count($completedRequirements) ?>)
                        </h3>
                        <span class="section-count"><?= count($completedRequirements) ?> items</span>
                    </div>
                    <div class="card-content">
                        <div class="req-grid">
                            <?php foreach ($completedRequirements as $req):
                                $sc = $statusConfig[$req['current_status']] ?? ['label' => 'Unknown', 'class' => 'badge-secondary', 'icon' => 'not-started'];
                                $deadline = renderDeadlineBadge($req);
                            ?>
                                <div class="req-card approved">
                                    <div class="req-header">
                                        <div>
                                            <div class="req-category">
                                                <?php if ($req['mandatory']): ?><span class="mandatory-dot" title="Mandatory"></span><?php endif; ?>
                                                <?= sanitize($req['category']) ?>
                                            </div>
                                            <div class="req-title"><?= sanitize($req['document_type']) ?></div>
                                        </div>
                                        <div class="status-indicator status-<?= $sc['icon'] ?>"><?= renderStatusIcon($sc['icon']) ?></div>
                                    </div>
                                    <div class="badge-row">
                                        <?php if (!empty($req['source_label'])): ?>
                                            <span class="badge <?= $req['source_label_class'] ?? 'badge-secondary' ?>"><?= sanitize($req['source_label']) ?></span>
                                        <?php endif; ?>
                                        <span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span>
                                        <?php if ($deadline): ?><?= $deadline ?><?php endif; ?>
                                    </div>
                                    <?php if (!empty($req['latest_version'])): ?>
                                        <div class="version-info">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Version <?= $req['latest_version'] ?> &middot;
                                            <?= !empty($req['last_uploaded']) ? date('M j, Y', strtotime($req['last_uploaded'])) : 'N/A' ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="req-actions">
                                        <?= renderDescButton($req) ?>
                                        <?php if (!empty($req['template_filename'])): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['ph_requirement_id'] ?>" data-type="ph_requirement" data-title="<?= sanitize($req['template_filename']) ?>" data-filename="<?= sanitize($req['template_filename']) ?>">Template</button>
                                        <?php endif; ?>
                                        <?php if ($req['submission_id']): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['submission_id'] ?>" data-type="<?= $req['source'] ?>" data-title="<?= sanitize($req['document_type']) ?>" data-filename="<?= sanitize($req['filename'] ?? '') ?>" data-extension="<?= strtolower(pathinfo($req['filename'] ?? '', PATHINFO_EXTENSION)) ?>">View</button>
                                        <?php endif; ?>
                                        <?php if (in_array($req['current_status'], ['approved_admin', 'approved_program_head'])): ?>
                                            <button class="btn btn-sm btn-danger delete-requirement-btn" data-reqid="<?= $req['id'] ?>" data-is-ph="<?= !empty($req['is_ph_requirement']) ? '1' : '0' ?>">Remove</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- NEEDS FIX -->
        <?php if (!empty($rejectedRequirements)): ?>
            <div class="req-section" data-tab="needs-fix">
                <div class="card" style="margin-bottom:0.75rem;">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            Needs Fix (<?= count($rejectedRequirements) ?>)
                        </h3>
                        <span class="section-count"><?= count($rejectedRequirements) ?> items</span>
                    </div>
                    <div class="card-content">
                        <div class="req-grid">
                            <?php foreach ($rejectedRequirements as $req):
                                $sc = $statusConfig[$req['current_status']] ?? ['label' => 'Needs Correction', 'class' => 'badge-danger', 'icon' => 'rejected'];
                                $noteParts = [];
                                if (!empty($req['description'])) $noteParts[] = ['title' => 'Description', 'body' => $req['description'], 'variant' => 'info'];
                                if (!empty($req['latest_ph_comment'])) $noteParts[] = ['title' => 'PH Review', 'body' => $req['latest_ph_comment'], 'variant' => 'primary'];
                                if (!empty($req['latest_admin_comment'])) $noteParts[] = ['title' => 'Admin Feedback', 'body' => $req['latest_admin_comment'], 'variant' => 'danger'];
                                $deadline = renderDeadlineBadge($req);
                            ?>
                                <div class="req-card rejected" data-row-id="<?= $req['source'] . ':' . (int) $req['submission_id'] ?>">
                                    <div class="req-header">
                                        <div>
                                            <div class="req-category">
                                                <?php if ($req['mandatory']): ?><span class="mandatory-dot" title="Mandatory"></span><?php endif; ?>
                                                <?= sanitize($req['category']) ?>
                                            </div>
                                            <div class="req-title"><?= sanitize($req['document_type']) ?></div>
                                        </div>
                                        <div class="status-indicator status-<?= $sc['icon'] ?>"><?= renderStatusIcon($sc['icon']) ?></div>
                                    </div>
                                    <div class="badge-row">
                                        <?php if (!empty($req['source_label'])): ?>
                                            <span class="badge <?= $req['source_label_class'] ?? 'badge-secondary' ?>"><?= sanitize($req['source_label']) ?></span>
                                        <?php endif; ?>
                                        <span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span>
                                        <?php if ($deadline): ?><?= $deadline ?><?php endif; ?>
                                    </div>
                                    <?php if (!empty($req['latest_version'])): ?>
                                        <div class="version-info">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Version <?= $req['latest_version'] ?> &middot;
                                            <?= !empty($req['last_uploaded']) ? date('M j, Y', strtotime($req['last_uploaded'])) : 'N/A' ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="req-actions">
                                        <?= renderDescButton($req) ?>
                                        <?= renderNoteBlock($noteParts, 'Feedback', 'danger') ?>
                                        <?php if (!empty($req['template_filename'])): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['ph_requirement_id'] ?>" data-type="ph_requirement" data-title="<?= sanitize($req['template_filename']) ?>" data-filename="<?= sanitize($req['template_filename']) ?>">Template</button>
                                        <?php endif; ?>
                                        <?php if ($req['submission_id']): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['submission_id'] ?>" data-type="<?= $req['source'] ?>" data-title="<?= sanitize($req['document_type']) ?>">View</button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-primary upload-btn" data-reqid="<?= !empty($req['is_ph_requirement']) ? ($req['ph_requirement_id'] ?? '') : $req['id'] ?>" data-is-ph="<?= !empty($req['is_ph_requirement']) ? '1' : '0' ?>" data-label="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>">Resubmit</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- IN REVIEW -->
        <?php if (!empty($pendingRequirements)): ?>
            <div class="req-section" data-tab="in-review">
                <div class="card" style="margin-bottom:0.75rem;">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            In Review (<?= count($pendingRequirements) ?>)
                        </h3>
                        <span class="section-count"><?= count($pendingRequirements) ?> items</span>
                    </div>
                    <div class="card-content">
                        <div class="req-grid">
                            <?php foreach ($pendingRequirements as $req):
                                $sc = $statusConfig[$req['current_status']] ?? ['label' => 'Pending', 'class' => 'badge-warning', 'icon' => 'pending'];
                                $deadline = renderDeadlineBadge($req);
                            ?>
                                <div class="req-card pending-review">
                                    <div class="req-header">
                                        <div>
                                            <div class="req-category">
                                                <?php if ($req['mandatory']): ?><span class="mandatory-dot" title="Mandatory"></span><?php endif; ?>
                                                <?= sanitize($req['category']) ?>
                                            </div>
                                            <div class="req-title"><?= sanitize($req['document_type']) ?></div>
                                        </div>
                                        <div class="status-indicator status-<?= $sc['icon'] ?>"><?= renderStatusIcon($sc['icon']) ?></div>
                                    </div>
                                    <div class="badge-row">
                                        <?php if (!empty($req['source_label'])): ?>
                                            <span class="badge <?= $req['source_label_class'] ?? 'badge-secondary' ?>"><?= sanitize($req['source_label']) ?></span>
                                        <?php endif; ?>
                                        <span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span>
                                        <?php if ($deadline): ?><?= $deadline ?><?php endif; ?>
                                    </div>
                                    <?php if (!empty($req['latest_version'])): ?>
                                        <div class="version-info">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Version <?= $req['latest_version'] ?> &middot;
                                            <?= !empty($req['last_uploaded']) ? date('M j, Y', strtotime($req['last_uploaded'])) : 'N/A' ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="req-actions">
                                        <?= renderDescButton($req) ?>
                                        <?php if (!empty($req['template_filename'])): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['ph_requirement_id'] ?>" data-type="ph_requirement" data-title="<?= sanitize($req['template_filename']) ?>" data-filename="<?= sanitize($req['template_filename']) ?>">Template</button>
                                        <?php endif; ?>
                                        <?php if ($req['submission_id']): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['submission_id'] ?>" data-type="<?= $req['source'] ?>" data-title="<?= sanitize($req['document_type']) ?>" data-filename="<?= sanitize($req['filename'] ?? '') ?>" data-extension="<?= strtolower(pathinfo($req['filename'] ?? '', PATHINFO_EXTENSION)) ?>">View</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TO DO -->
        <?php if (!empty($notStartedRequirements)): ?>
            <div class="req-section" data-tab="todo">
                <div class="card" style="margin-bottom:0.75rem;">
                    <div class="card-header">
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                            To Do (<?= count($notStartedRequirements) ?>)
                        </h3>
                        <span class="section-count"><?= count($notStartedRequirements) ?> items</span>
                    </div>
                    <div class="card-content">
                        <div class="req-grid">
                            <?php foreach ($notStartedRequirements as $req):
                                $deadline = renderDeadlineBadge($req);
                            ?>
                                <div class="req-card not-started">
                                    <div class="req-header">
                                        <div>
                                            <div class="req-category">
                                                <?php if ($req['mandatory']): ?><span class="mandatory-dot" title="Mandatory"></span><?php endif; ?>
                                                <?= sanitize($req['category']) ?>
                                            </div>
                                            <div class="req-title"><?= sanitize($req['document_type']) ?></div>
                                        </div>
                                        <div class="status-indicator status-not-started"><?= renderStatusIcon('not-started') ?></div>
                                    </div>
                                    <div class="badge-row">
                                        <?php if (!empty($req['source_label'])): ?>
                                            <span class="badge <?= $req['source_label_class'] ?? 'badge-secondary' ?>"><?= sanitize($req['source_label']) ?></span>
                                        <?php endif; ?>
                                        <span class="badge badge-secondary">Not Uploaded</span>
                                        <?php if ($deadline): ?><?= $deadline ?><?php endif; ?>
                                    </div>
                                    <div class="req-actions">
                                        <?= renderDescButton($req) ?>
                                        <?php if (!empty($req['template_filename'])): ?>
                                            <button class="btn btn-sm btn-outline view-btn" data-id="<?= $req['ph_requirement_id'] ?>" data-type="ph_requirement" data-title="<?= sanitize($req['template_filename']) ?>" data-filename="<?= sanitize($req['template_filename']) ?>">Template</button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-primary upload-btn" data-reqid="<?= !empty($req['is_ph_requirement']) ? ($req['ph_requirement_id'] ?? '') : $req['id'] ?>" data-is-ph="<?= !empty($req['is_ph_requirement']) ? '1' : '0' ?>" data-label="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>">Upload File</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Empty states -->
        <div class="tab-empty" data-tab="all" id="tabEmptyAll">
            <div class="tab-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg></div>
            <h4>No requirements assigned yet</h4>
            <p>Your PH will assign tasks to you.</p>
        </div>
        <div class="tab-empty" data-tab="todo" id="tabEmptyTodo">
            <div class="tab-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg></div>
            <h4>Nothing to do</h4>
            <p>You have no pending requirements to complete right now.</p>
        </div>
        <div class="tab-empty" data-tab="in-review" id="tabEmptyReview">
            <div class="tab-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg></div>
            <h4>Nothing in review</h4>
            <p>Requirements you submit will appear here while awaiting review.</p>
        </div>
        <div class="tab-empty" data-tab="needs-fix" id="tabEmptyFix">
            <div class="tab-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg></div>
            <h4>No corrections needed</h4>
            <p>Nothing needs fixing right now. Great work!</p>
        </div>
        <div class="tab-empty" data-tab="completed" id="tabEmptyCompleted">
            <div class="tab-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M18.75 4.236c.982.143 1.954.317 2.916.52A6.003 6.003 0 0116.27 9.728M18.75 4.236V4.5c0 2.108-.966 3.99-2.48 5.228m0 0a6.003 6.003 0 01-3.77 1.522m0 0a6.003 6.003 0 01-3.77-1.522" />
                </svg></div>
            <h4>Nothing completed yet</h4>
            <p>Approved requirements will appear here as you finish them.</p>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg> Upload Document</h3>
            <button class="close-btn" data-modal="uploadModal" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <?= csrfField() ?>
                <input type="hidden" name="requirement_id" id="upload_req_id">
                <input type="hidden" name="ph_requirement_id" id="upload_ph_req_id">
                <input type="hidden" name="is_ph_upload" id="upload_is_ph">
                <div class="form-group">
                    <label>Requirement</label>
                    <div id="upload_req_label" style="font-weight:600; color:var(--primary); padding:0.75rem; background:var(--primary-light); border-radius:var(--radius-md); font-size:0.82rem;"></div>
                </div>
                <div class="form-group">
                    <label>File <span style="color:var(--danger)">*</span></label>
                    <input type="file" name="document_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx" required>
                </div>
                <div class="form-group">
                    <label>Comment</label>
                    <textarea name="comment" class="form-control" rows="3" placeholder="Any notes about this submission..."></textarea>
                </div>
                <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" id="uploadBtn">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Notes Modal -->
<div id="notesModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="notesModalTitle">
    <div class="modal-content modal-content--notes">
        <div class="modal-header">
            <h3 id="notesModalTitle"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                </svg> <span id="notesModalTitleText">Notes</span></h3>
            <button class="close-btn" data-modal="notesModal" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg></button>
        </div>
        <div class="modal-body" id="notesModalBody"></div>
    </div>
</div>

<!-- Description Modal -->
<div id="descModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="descModalTitle">
    <div class="modal-content modal-content--notes">
        <div class="modal-header">
            <h3 id="descModalTitle"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                </svg> Description</h3>
            <button class="close-btn" data-modal="descModal" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg></button>
        </div>
        <div class="modal-body">
            <p id="descReqName" style="margin:0 0 0.75rem; font-weight:600; color:var(--gray-900); font-size:0.875rem;"></p>
            <p id="descBody" style="margin:0; color:var(--gray-700); font-size:0.9rem; line-height:1.6; white-space:pre-wrap; overflow-wrap:break-word;"></p>
        </div>
    </div>
</div>

<!-- Remove Requirement Confirm Modal -->
<div id="removeReqModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="removeReqTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="removeReqTitle"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="color:var(--danger)">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg> Remove Requirement</h3>
            <button class="close-btn" data-modal="removeReqModal" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg></button>
        </div>
        <div class="modal-body">
            <p style="margin:0 0 1.25rem; color:var(--gray-700); font-size:0.9rem; line-height:1.6;">Remove this approved requirement from your dashboard?</p>
            <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" id="removeReqCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="removeReqConfirm">Remove</button>
            </div>
        </div>
    </div>
</div>
</div>

<script nonce="<?= csp_nonce() ?>">
    const csrfToken = '<?php echo getCsrfToken(); ?>';

    (function() {
        const CIRC = 314.159;

        function animateNumber(el, from, to, duration, suffix) {
            if (!el) return;
            let start = null;

            function step(ts) {
                if (start === null) start = ts;
                const p = Math.min((ts - start) / duration, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                const val = Math.round(from + (to - from) * eased);
                el.textContent = val + (suffix || '');
                if (p < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        }
        window.updateTeacherProgressHero = function(percentage, animate) {
            percentage = Math.max(0, Math.min(100, Number(percentage) || 0));
            const ring = document.getElementById('progressRingFill');
            const bar = document.getElementById('heroProgressFill');
            const pctEl = document.getElementById('bigPct');
            if (ring) ring.style.strokeDashoffset = CIRC - (CIRC * percentage / 100);
            if (bar) bar.style.width = percentage + '%';
            if (pctEl) {
                const from = parseInt(pctEl.dataset.current || '0', 10);
                if (animate) animateNumber(pctEl, from, percentage, 1400, '%');
                else pctEl.textContent = percentage + '%';
                pctEl.dataset.current = percentage;
            }
        };
        const initialPct = <?= (float) $progress['percentage'] ?>;
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                window.updateTeacherProgressHero(initialPct, true);
            });
        });
        animateNumber(document.getElementById('statApproved'), 0, <?= (int) $progress['approved'] ?>, 1000, '');
        animateNumber(document.getElementById('statSubmitted'), 0, <?= (int) $progress['submitted'] ?>, 1100, '');
        animateNumber(document.getElementById('statRejected'), 0, <?= (int) $progress['rejected'] ?>, 1200, '');
        animateNumber(document.getElementById('statPending'), 0, <?= (int) $progress['pending'] ?>, 1300, '');
    })();

    function showToast(message, type) {
        const toast = document.createElement('div');
        const icon = type === 'success' ?
            '<svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' :
            '<svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>';
        toast.innerHTML = '<span style="display:flex;align-items:center;">' + icon + '</span> <span></span>';
        toast.querySelector('span:last-child').textContent = message;
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;padding:0.875rem 1.25rem;display:flex;align-items:center;gap:0.75rem;background:' +
            (type === 'success' ? '#ecfdf5' : '#fef2f2') + ';color:' +
            (type === 'success' ? '#065f46' : '#991b1b') +
            ';border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.12), 0 0 0 1px ' + (type === 'success' ? '#a7f3d0' : '#fecaca') + ';font-weight:600;font-size:0.85rem;max-width:360px;';
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    (function() {
        const notesModal = document.getElementById('notesModal');
        const notesModalBody = document.getElementById('notesModalBody');
        const notesModalTitle = document.getElementById('notesModalTitleText');

        function openNotesModal(title, html) {
            if (!notesModal) return;
            notesModalTitle.textContent = title || 'Notes';
            notesModalBody.innerHTML = html || '';
            notesModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeNotesModal() {
            if (!notesModal) return;
            notesModal.classList.remove('show');
            notesModalBody.innerHTML = '';
            document.body.style.overflow = '';
        }
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.note-btn');
            if (!btn) return;
            const targetId = btn.dataset.noteTarget;
            const src = document.getElementById(targetId);
            if (!src) return;
            openNotesModal(btn.dataset.noteTitle || 'Notes', src.innerHTML);
        });
        if (notesModal) {
            notesModal.querySelectorAll('.close-btn').forEach(b => b.addEventListener('click', closeNotesModal));
            notesModal.addEventListener('click', function(e) {
                if (e.target === notesModal) closeNotesModal();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && notesModal.classList.contains('show')) closeNotesModal();
            });
        }
        window.openNotesModal = openNotesModal;
        window.closeNotesModal = closeNotesModal;
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.desc-btn');
            if (!btn) return;
            const dm = document.getElementById('descModal');
            if (!dm) return;
            document.getElementById('descReqName').textContent = btn.dataset.name || '';
            document.getElementById('descBody').textContent = btn.dataset.desc || '';
            dm.classList.add('show');
        });
    })();

    document.querySelectorAll('.upload-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const isPh = this.dataset.isPh === '1';
            document.getElementById('upload_req_id').value = isPh ? '' : this.dataset.reqid;
            document.getElementById('upload_ph_req_id').value = isPh ? this.dataset.reqid : '';
            document.getElementById('upload_is_ph').value = isPh ? '1' : '0';
            document.getElementById('upload_req_label').textContent = this.dataset.label;
            document.getElementById('uploadModal').classList.add('show');
        });
    });

    document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('uploadBtn');
        const form = this;
        const fileInput = form.querySelector('input[type="file"]');
        const f = fileInput && fileInput.files[0];
        if (f) {
            const isImg = /\.(jpe?g|png|gif|webp)$/i.test(f.name);
            if (f.size > (isImg ? 10 : 100) * 1024 * 1024) {
                showToast('File too large. Maximum size is ' + (isImg ? '10MB' : '100MB') + '.', 'error');
                return;
            }
        }
        btn.textContent = 'Uploading...';
        btn.disabled = true;
        const formData = new FormData(form);
        formData.append('ajax_upload', '1');
        if (!formData.has('csrf_token')) formData.append('csrf_token', csrfToken);
        fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('Server error: ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    document.getElementById('uploadModal').classList.remove('show');
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    btn.textContent = 'Upload';
                    btn.disabled = false;
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                btn.textContent = 'Upload';
                btn.disabled = false;
                showToast('Upload failed: ' + error.message, 'error');
            });
    });

    let pendingRemoveBtn = null;

    function closeRemoveReqModal() {
        document.getElementById('removeReqModal').classList.remove('show');
        pendingRemoveBtn = null;
    }

    document.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.delete-requirement-btn');
        if (!deleteBtn) return;
        e.preventDefault();
        pendingRemoveBtn = deleteBtn;
        document.getElementById('removeReqModal').classList.add('show');
    });

    document.getElementById('removeReqCancel').addEventListener('click', closeRemoveReqModal);

    document.getElementById('removeReqConfirm').addEventListener('click', function() {
        const deleteBtn = pendingRemoveBtn;
        closeRemoveReqModal();
        if (!deleteBtn) return;
        const reqId = deleteBtn.dataset.reqid;
        const isPh = deleteBtn.dataset.isPh === '1';
        const formData = new FormData();
        formData.append('ajax_action', 'delete_requirement');
        formData.append('requirement_id', reqId);
        formData.append('is_ph_requirement', isPh ? '1' : '0');
        formData.append('csrf_token', csrfToken);
        fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const card = deleteBtn.closest('.req-card');
                    if (card) {
                        card.style.transition = 'opacity 0.3s, transform 0.3s';
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            card.remove();
                            checkEmptySections();
                        }, 300);
                    }
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(() => showToast('An error occurred', 'error'));
    });

    let currentTab = 'all';
    const reqTabs = Array.from(document.querySelectorAll('.tab-btn[data-filter]'));
    const reqSections = Array.from(document.querySelectorAll('.req-section[data-tab]'));

    function setActiveTab(filter) {
        currentTab = filter;
        reqTabs.forEach(tab => {
            const isActive = tab.dataset.filter === filter;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        let visibleCount = 0;
        reqSections.forEach(section => {
            const grid = section.querySelector('.req-grid');
            const hasItems = grid && grid.children.length > 0;
            const matches = (filter === 'all' || section.dataset.tab === filter) && hasItems;
            section.classList.toggle('tab-hidden', !matches);
            section.classList.remove('req-animate');
            if (matches) {
                visibleCount++;
                void section.offsetWidth;
                section.classList.add('req-animate');
            }
        });
        document.querySelectorAll('.tab-empty').forEach(el => {
            el.classList.toggle('visible', el.dataset.tab === filter && visibleCount === 0);
        });
    }

    reqTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            setActiveTab(this.dataset.filter);
        });
    });

    function checkEmptySections() {
        setActiveTab(currentTab);
    }
    setActiveTab('all');

    document.querySelectorAll('.close-btn[data-modal]').forEach(btn => {
        btn.addEventListener('click', function() {
            const m = document.getElementById(this.dataset.modal);
            if (m) m.classList.remove('show');
        });
    });

    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) e.target.classList.remove('show');
    });

    if (window.CHED && CHED.Poller) {
        CHED.Poller.register('teacherDashboard', function() {
            return fetch('teacher_dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'ajax_action=refresh_dashboard'
                })
                .then(r => r.json())
                .then(d => {
                    if (!d || typeof d.approved === 'undefined') return;
                    const total = typeof d.total !== 'undefined' ? d.total : 0;
                    const pct = typeof d.percentage !== 'undefined' ? d.percentage : (total > 0 ? Math.round((d.approved / total) * 100) : 0);
                    if (window.updateTeacherProgressHero) window.updateTeacherProgressHero(pct, true);
                    const stats = {
                        statApproved: d.approved,
                        statSubmitted: d.submitted,
                        statRejected: d.rejected,
                        statPending: d.pending
                    };
                    Object.keys(stats).forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.textContent = stats[id];
                    });
                    const txt = document.getElementById('heroProgressText');
                    if (txt) txt.textContent = d.approved + ' approved · ' + d.submitted + ' in review · ' + total + ' total';
                    const pendingEl = document.getElementById('heroPendingCount');
                    if (pendingEl) pendingEl.textContent = d.pending;
                })
                .catch(() => {});
        }, 30000);
    }
</script>

<?php include 'includes/footer.php'; ?>