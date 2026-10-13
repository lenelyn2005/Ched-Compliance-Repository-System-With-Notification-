<?php
define('CHED_APP', true);
require_once 'config.php';
requireTeacher();

$teacherId = (int) $_SESSION['user_id'];

// Submission stages are named after whoever reviews them: the Program Head or
// the Unit Head of this faculty member's department.
$deptStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
$deptStmt->execute([$teacherId]);
$teacherDeptId = (int) $deptStmt->fetchColumn();
$reviewer = $teacherDeptId ? getProgramHeadForDepartment($pdo, $teacherDeptId) : null;
$reviewerName = phRoleName($reviewer['role'] ?? null);

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');

    $ajaxAction = $_POST['ajax_action'] ?? '';

    // Live task counts refresh (30s poll + WS-triggered). Read-only; role-guarded.
    if ($ajaxAction === 'refresh_counts') {
        $sections = getTeacherTasksHtml($pdo, $teacherId);
        sendJson([
            'not_started' => count($sections['not_started']),
            'pending' => count($sections['pending']),
            'rejected' => count($sections['rejected']),
            'completed' => count($sections['completed']),
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
                if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                    $ext = strtolower(pathinfo($_FILES['document_file']['name'] ?? '', PATHINFO_EXTENSION));
                    $errorMsg = 'File is too large. Maximum size is ' . maxFileSizeLabelFor($ext) . '.';
                }
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }

            $result = uploadTeacherPHSubmission($pdo, $assignment['id'], $teacherId, $_FILES['document_file'], $comment);
            if ($result === true) {
                echo json_encode(['success' => true, 'message' => "File uploaded successfully! Your document is now pending review by the $reviewerName."]);
            } else {
                echo json_encode(['success' => false, 'message' => $result]);
            }
            exit;
        } else {
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
                if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                    $ext = strtolower(pathinfo($_FILES['document_file']['name'] ?? '', PATHINFO_EXTENSION));
                    $errorMsg = 'File is too large. Maximum size is ' . maxFileSizeLabelFor($ext) . '.';
                }
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                exit;
            }

            $result = uploadTeacherSubmission($pdo, $reqId, $teacherId, $_FILES['document_file'], $comment);
            if ($result === true) {
                echo json_encode(['success' => true, 'message' => "File uploaded successfully! Your document is now pending review by the $reviewerName."]);
            } else {
                echo json_encode(['success' => false, 'message' => $result]);
            }
            exit;
        }
    }

    if ($ajaxAction === 'delete_requirement') {
        $ajaxCsrf = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($ajaxCsrf)) {
            echo json_encode(['success' => false, 'message' => 'Invalid session. Please refresh and try again.']);
            exit;
        }

        $reqId = (int) ($_POST['requirement_id'] ?? 0);
        $isPh = !empty($_POST['is_ph_requirement']);

        if (!$reqId) {
            echo json_encode(['success' => false, 'message' => 'Invalid requirement ID.']);
            exit;
        }

        if ($isPh) {
            $check = $pdo->prepare("
                SELECT tpr.id
                FROM teacher_ph_requirements tpr
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
            } else {
                echo json_encode(['success' => false, 'message' => 'Only approved requirements can be removed.']);
                exit;
            }
        } else {
            $checkReq = $pdo->prepare("
                SELECT tr.id
                FROM teacher_requirements tr
                WHERE tr.id = ? AND tr.teacher_id = ?
                  AND (
                      SELECT status FROM documents
                      WHERE teacher_requirement_id = tr.id
                      ORDER BY version DESC LIMIT 1
                  ) IN ('approved_admin', 'approved_program_head')
            ");
            $checkReq->execute([$reqId, $teacherId]);

            if ($checkReq->fetch()) {
                $pdo->prepare("DELETE FROM teacher_requirements WHERE id = ? AND teacher_id = ?")->execute([$reqId, $teacherId]);
                logAudit($pdo, 'teacher_delete_requirement', "Teacher deleted requirement ID $reqId", 'teacher_requirements', $reqId);
                echo json_encode(['success' => true, 'message' => 'Requirement removed from your dashboard.']);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Only approved requirements can be removed.']);
                exit;
            }
        }
    }

    exit;
}

$requirements = getTeacherRequirementsOptimized($pdo, $teacherId);
$phRequirements = getTeacherPHRequirementsOptimized($pdo, $teacherId);

// Label = the real head who created/assigned the requirement (Unit Head vs
// Program Head) — same rule as admin_submissions.php origin string.
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
    $phReq['is_ph_requirement'] = true;
    $phReq['source'] = 'ph_document';
    $phReq['category'] = $headLabel($phReq['created_by'] ?? null);
    $phReq['document_type'] = $phReq['title'];
    $phReq['mandatory'] = 1;
    $phReq['expiration_date'] = $phReq['deadline'] ?? null;
    $phReq['latest_admin_comment'] = $phReq['latest_admin_comment'] ?? null;
    $phReq['latest_ph_comment'] = $phReq['latest_ph_comment'] ?? null;
    $phReq['latest_filename'] = $phReq['latest_filename'] ?? null;
    $phReq['template_filename'] = $phReq['ref_filename'] ?? null;
    $phReq['template_file_path'] = $phReq['ref_file_path'] ?? null;
    $phReq['source_label'] = $headLabel($phReq['created_by'] ?? null);
    $phReq['source_label_class'] = 'badge-info';
}
unset($phReq);

foreach ($requirements as &$req) {
    $req['source'] = 'document';
    $req['is_ph_requirement'] = false;
    $req['template_filename'] = null;
    $req['template_file_path'] = null;

    if (isset($req['requirement_type']) && $req['requirement_type'] === 'program_head_requirement') {
        $req['source_label'] = $headLabel($req['assigned_by'] ?? null);
        $req['source_label_class'] = 'badge-info';
    } else {
        $req['source_label'] = 'Admin';
        $req['source_label_class'] = 'badge-warning';
    }
}
unset($req);

$allRequirements = array_merge($requirements, $phRequirements);

$completedRequirements = [];
$rejectedRequirements = [];
$pendingRequirements = [];
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

$isPhUpload = ($_POST['is_ph_upload'] ?? '0') === '1';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !$isPhUpload
    && !empty($_POST['requirement_id'])
) {

    requireCsrfToken();
    $reqId = (int) $_POST['requirement_id'];
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
            header("Location: teacher_tasks.php?msg=File uploaded successfully! Your document is now pending review by the $reviewerName.&t=" . time());
            exit;
        } else {
            $error = $result;
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $isPhUpload
    && !empty($_POST['ph_requirement_id'])
) {

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
            header("Location: teacher_tasks.php?msg=File uploaded successfully! Your document is now pending review by the $reviewerName.&t=" . time());
            exit;
        } else {
            $error = $result;
        }
    }
}

$allRequirements = array_merge($requirements, $phRequirements);

if (isset($_GET['msg'])) {
    $success = $_GET['msg'];
}

// Notifications render in the header bell + notifications.php (this fetch was dead code)

function renderStatusIcon($iconType)
{
    $icons = [
        'approved' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
        'rejected' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
        'pending' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
        'pending-admin' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>',
        'not-started' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>',
    ];
    return $icons[$iconType] ?? $icons['not-started'];
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

    * {
        box-sizing: border-box;
    }

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

    /* Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .animate-in {
        animation: fadeInUp 0.5s ease forwards;
    }

    /* Page Hero */
    .page-hero {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
        border-radius: var(--radius-xl);
        padding: 2rem;
        color: white;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: var(--shadow-lg);
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
        background: rgba(255, 255, 255, 0.2);
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-size: 0.85rem;
        font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.3);
        position: relative;
        z-index: 1;
        transition: var(--transition);
    }

    .page-hero-badge:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-1px);
    }

    /* Alerts */
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

    .alert-success {
        background: var(--success-light);
        color: #0b6b4c;
        border-left-color: var(--success);
    }

    .alert-danger {
        background: var(--danger-light);
        color: #b91c1c;
        border-left-color: var(--danger);
    }

    .alert svg {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }

    /* Cards */
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

    .card:hover {
        box-shadow: var(--shadow-sm);
    }

    .card-header {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--white);
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

    .card-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--gray-400);
    }

    .card-header-badge {
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 0.25rem 0.75rem;
        border-radius: 99px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .card-content {
        padding: 1.25rem;
        flex: 1;
    }

    .card-content.no-padding {
        padding: 0 !important;
    }

    /* Table */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        white-space: nowrap;
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
        position: sticky;
        top: 0;
        white-space: nowrap;
    }

    .data-table td {
        padding: 0.8rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
        font-size: 0.875rem;
        color: var(--gray-700);
    }

    .data-table tr:hover {
        background: #f8fafc;
    }

    .data-table tr:hover td {
        color: var(--gray-900);
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    /* Badges */
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

    .badge svg {
        width: 12px;
        height: 12px;
    }

    .badge-pending {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .badge-pending-ph {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-pending-admin {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .badge-approved {
        background: #ecfdf5;
        color: #047857;
    }

    .badge-rejected {
        background: #fef2f2;
        color: #b91c1c;
    }

    .badge-info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .badge-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-success {
        background: #ecfdf5;
        color: #047857;
    }

    .badge-danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .badge-secondary {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    /* Buttons */
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

    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.8125rem;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: var(--shadow-xs);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-sm);
    }

    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
        border-color: var(--border);
    }

    .btn-secondary:hover {
        background: var(--gray-200);
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

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    .btn-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 2.5rem 1.5rem;
        color: var(--gray-500);
    }

    .empty-state-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 0.75rem;
        border-radius: 16px;
        background: var(--gray-100);
        color: var(--gray-400);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .empty-state svg {
        width: 26px;
        height: 26px;
    }

    .empty-state h3 {
        margin: 0 0 0.25rem;
        color: var(--gray-600);
        font-weight: 600;
        font-size: 1rem;
    }

    .empty-state p {
        margin: 0;
        color: var(--gray-500);
        font-size: 0.875rem;
    }

    /* Modal */
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

    .modal.show {
        display: flex;
    }

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
        margin: 0;
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-header h3 svg {
        width: 20px;
        height: 20px;
        color: var(--primary);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }

    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: var(--gray-400);
        cursor: pointer;
        line-height: 1;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
        transition: var(--transition);
    }

    .close-btn:hover {
        color: var(--gray-600);
        background: var(--gray-100);
    }

    /* Form */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gray-700);
    }

    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: 0.9375rem;
        transition: var(--transition);
        background: var(--white);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    .req-doc-type {
        font-weight: 600;
        color: var(--gray-900);
    }

    .req-category {
        font-size: 0.7rem;
        color: var(--gray-500);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-weight: 600;
    }

    .mandatory-dot {
        display: inline-block;
        width: 6px;
        height: 6px;
        background: var(--danger);
        border-radius: 50%;
        flex-shrink: 0;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
    }

    /* Section Count */
    .section-count {
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.5rem;
            gap: 1rem;
        }

        .page-hero {
            flex-direction: column;
            text-align: center;
            padding: 1.5rem;
            gap: 1rem;
        }

        .page-hero-badge {
            margin-left: 0;
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem;
            font-size: 0.8125rem;
        }

        .btn-group {
            flex-direction: column;
            align-items: stretch;
        }

        .btn-group .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="dashboard-container">
    <?php if ($error): ?><div class="alert alert-danger"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg><?= sanitize($error) ?></div><?php endif; ?>

    <?php include 'includes/success_toast.php'; ?>

    <!-- ALL TASKS -->
    <div class="card">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                My Tasks (<?= count($allRequirements) ?>)
            </h3>
            <span class="section-count"><?= count($allRequirements) ?> items</span>
        </div>
        <div class="card-content no-padding">
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
                            if (in_array($status, ['approved_program_head', 'approved_admin'])) {
                                $sc = ['label' => 'Complete', 'class' => 'badge-approved', 'icon' => 'approved', 'canUpload' => false];
                            } elseif (in_array($status, ['pending_program_head'])) {
                                $sc = ['label' => "Pending $reviewerName", 'class' => 'badge-pending-ph', 'icon' => 'pending', 'canUpload' => false];
                            } elseif ($status === 'pending_admin') {
                                $sc = ['label' => 'Pending Admin', 'class' => 'badge-pending-admin', 'icon' => 'pending-admin', 'canUpload' => false];
                            } elseif (in_array($status, ['rejected_program_head', 'rejected_admin'])) {
                                $sc = ['label' => 'Need Fix', 'class' => 'badge-rejected', 'icon' => 'rejected', 'canUpload' => true];
                            } else {
                                $sc = ['label' => 'Not Uploaded', 'class' => 'badge-secondary', 'icon' => 'not-started', 'canUpload' => true];
                            }
                            $taskRowId = !empty($req['is_ph_requirement'])
                                ? 'ph_task:' . (int) ($req['ph_requirement_id'] ?? 0)
                                : 'task:' . (int) ($req['id'] ?? 0);
                        ?>
                            <tr data-row-id="<?= $taskRowId ?>">
                                <td>
                                    <div class="req-category">
                                        <?php if ($req['mandatory']): ?><span class="mandatory-dot" title="Mandatory"></span><?php endif; ?>
                                        <?= sanitize($req['category']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="req-doc-type"><?= sanitize($req['document_type']) ?></span>
                                </td>
                                <td><?= !empty($req['source_label']) ? '<span class="badge ' . ($req['source_label_class'] ?? 'badge-secondary') . '">' . sanitize($req['source_label']) . '</span>' : '—' ?></td>
                                <td>
                                    <?php if ($req['expiration_date']): ?>
                                        <?php
                                        $expiryDate = strtotime($req['expiration_date']);
                                        $today = strtotime(date('Y-m-d'));
                                        $daysLeft = ($expiryDate - $today) / (60 * 60 * 24);
                                        $isOverdue = $daysLeft < 0;
                                        $isUrgent = $daysLeft >= 0 && $daysLeft <= EXPIRY_WARNING_DAYS;
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem;">
                                            <svg xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;<?= $isOverdue ? 'color:var(--danger);' : ($isUrgent ? 'color:var(--warning);' : 'color:var(--gray-500);') ?>" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                            </svg>
                                            <span style="<?= $isOverdue ? 'color: var(--danger); font-weight:700;' : ($isUrgent ? 'color: var(--warning); font-weight:600;' : 'color: var(--gray-500);') ?>">
                                                <?= date('M j, Y', $expiryDate) ?>
                                            </span>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge badge-danger" style="background: var(--danger); color:white;">Expired</span>
                                            <?php elseif ($isUrgent): ?>
                                                <span class="badge badge-warning" style="background: var(--warning); color:white;">Expiring</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $sc['class'] ?>"><?= $sc['label'] ?></span></td>
                                <td>
                                    <div class="btn-group">
                                        <?php if (!empty(trim((string)($req['description'] ?? '')))): ?>
                                            <button type="button" class="btn btn-sm btn-outline desc-btn"
                                                data-name="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>"
                                                data-desc="<?= sanitize($req['description']) ?>">
                                                Description
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($req['template_filename'])): ?>
                                            <button class="btn btn-sm btn-outline view-btn"
                                                data-id="<?= $req['ph_requirement_id'] ?>"
                                                data-type="ph_requirement"
                                                data-title="<?= sanitize($req['template_filename']) ?>"
                                                data-filename="<?= sanitize($req['template_filename']) ?>">
                                                Template
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($sc['canUpload']): ?>
                                            <button class="btn btn-sm btn-primary upload-btn"
                                                data-reqid="<?= !empty($req['is_ph_requirement']) ? ($req['ph_requirement_id'] ?? '') : $req['id'] ?>"
                                                data-is-ph="<?= !empty($req['is_ph_requirement']) ? '1' : '0' ?>"
                                                data-label="<?= sanitize($req['category'] . ' - ' . $req['document_type']) ?>">
                                                Upload
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- EMPTY STATE -->
    <?php if (empty($allRequirements)): ?>
        <div class="card">
            <div class="card-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    My Tasks
                </h3>
            </div>
            <div class="card-content">
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    <h3>No Tasks Assigned</h3>
                    <p>You don't have any requirements assigned yet.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;margin-right:0.5rem;color:var(--primary);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Upload Document
                </h3>
                <button class="close-btn" data-modal="uploadModal" type="button">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
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
                        <div id="upload_req_label" style="font-weight:600; color:var(--primary); padding:0.75rem; background:var(--gray-50); border-radius:var(--radius-md);"></div>
                    </div>
                    <div class="form-group">
                        <label>File <span style="color:var(--danger)">*</span></label>
                        <input type="file" name="document_file" class="form-control"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx" required>
                    </div>
                    <div class="form-group">
                        <label>Comment</label>
                        <textarea name="comment" class="form-control" rows="3" placeholder="Notes about this submission..."></textarea>
                    </div>
                    <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                        <button type="submit" class="btn btn-primary" id="uploadSubmitBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Upload
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
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;margin-right:0.5rem;color:var(--primary);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    Review Feedback
                </h3>
                <button class="close-btn" data-modal="commentModal" type="button">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>PH Feedback</label>
                    <p id="phComment" style="background:var(--gray-50); border-radius:8px; padding:0.875rem; min-height:50px; font-size:0.875rem; border-left:4px solid var(--primary); color:var(--gray-700);">—</p>
                </div>
                <div class="form-group" id="adminCommentGroup">
                    <label>Admin Feedback</label>
                    <p id="adminComment" style="background:var(--gray-50); border-radius:8px; padding:0.875rem; min-height:50px; font-size:0.875rem; border-left:4px solid var(--danger); color:var(--gray-700);">—</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Description Modal -->
    <div id="descModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;margin-right:0.5rem;color:var(--primary);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                    </svg>
                    Description
                </h3>
                <button class="close-btn" data-modal="descModal" type="button">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <p id="descReqName" style="margin:0 0 0.75rem; font-weight:600; color:var(--gray-900); font-size:0.875rem;"></p>
                <p id="descBody" style="margin:0; color:var(--gray-700); font-size:0.9rem; line-height:1.6; white-space:pre-wrap; overflow-wrap:break-word; max-height:50vh; overflow-y:auto;"></p>
            </div>

        </div>
    </div>

    <?php
    $extraScripts = "
<script nonce=\"" . csp_nonce() . "\">
// ============================================================
// CSRF token for AJAX
// ============================================================
const csrfToken = '" . getCsrfToken() . "';

// ============================================================
// Toast helper
// ============================================================
function showToast(message, type) {
    const toast = document.createElement('div');
    const icon = type === 'success'
        ? '<svg xmlns=\"http://www.w3.org/2000/svg\" style=\"width:20px;height:20px;flex-shrink:0;\" fill=\"none\" viewBox=\"0 0 24 24\" stroke-width=\"2\" stroke=\"currentColor\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z\" /></svg>'
        : '<svg xmlns=\"http://www.w3.org/2000/svg\" style=\"width:20px;height:20px;flex-shrink:0;\" fill=\"none\" viewBox=\"0 0 24 24\" stroke-width=\"2\" stroke=\"currentColor\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z\" /></svg>';

    toast.innerHTML = '<span style=\"display:flex;align-items:center;\">' + icon + '</span> <span></span>';
    toast.querySelector('span:last-child').textContent = message;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;padding:0.875rem 1.25rem;display:flex;align-items:center;gap:0.75rem;background:' +
        (type === 'success' ? '#ecfdf5' : '#fef2f2') + ';color:' +
        (type === 'success' ? '#065f46' : '#991b1b') +
        ';border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.12), 0 0 0 1px ' + (type === 'success' ? '#a7f3d0' : '#fecaca') + ';font-weight:600;font-size:0.85rem;animation:slideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);max-width:360px;';
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================================
// Open upload modal
// ============================================================
document.querySelectorAll('.upload-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const isPh = this.dataset.isPh === '1';
        document.getElementById('upload_req_id').value = isPh ? '' : this.dataset.reqid;
        document.getElementById('upload_ph_req_id').value = isPh ? this.dataset.reqid : '';
        document.getElementById('upload_is_ph').value = isPh ? '1' : '0';
        document.getElementById('upload_req_label').textContent = this.dataset.label;
        document.getElementById('uploadModal').classList.add('show');
    });
});

// ============================================================
// AJAX upload submit (fixes the silent failure)
// ============================================================
document.getElementById('uploadForm')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const form = this;
    const btn = document.getElementById('uploadSubmitBtn');
    const fileInput = form.querySelector('input[type=\"file\"]');
    const f = fileInput.files[0];

    if (!f) {
        showToast('Please select a file to upload.', 'error');
        return;
    }
    const isImg = /\.(jpe?g|png|gif|webp)$/i.test(f.name);
    if (f.size > (isImg ? 10 : 100) * 1024 * 1024) {
        showToast('File too large. Maximum size is ' + (isImg ? '10MB' : '100MB') + '.', 'error');
        return;
    }

    const originalHtml = btn.innerHTML;
    btn.innerHTML = 'Uploading...';
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
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            showToast(data.message, 'error');
        }
    })
    .catch(error => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        showToast('Upload failed: ' + error.message, 'error');
    });
});

// ============================================================
// Comments modal
// ============================================================
document.querySelectorAll('.comment-btn').forEach(b => {
    b.addEventListener('click', function () {
        const ph = this.dataset.ph;
        const admin = this.dataset.admin;
        document.getElementById('phComment').textContent = ph || '—';
        document.getElementById('adminComment').textContent = admin || '—';
        document.getElementById('adminCommentGroup').style.display = admin ? 'block' : 'none';
        document.getElementById('commentModal').classList.add('show');
    });
});

// ============================================================
// Description modal
// ============================================================
document.querySelectorAll('.desc-btn').forEach(b => {
    b.addEventListener('click', function () {
        document.getElementById('descReqName').textContent = this.dataset.name || '';
        document.getElementById('descBody').textContent = this.dataset.desc || '';
        document.getElementById('descModal').classList.add('show');
    });
});

// ============================================================
// Modal close handlers
// ============================================================
document.querySelectorAll('.close-btn[data-modal]').forEach(b => {
    b.addEventListener('click', function () {
        document.getElementById(this.dataset.modal).classList.remove('show');
    });
});

window.addEventListener('click', e => {
    if (e.target.classList.contains('modal') && e.target.id !== 'docViewerModal') {
        e.target.classList.remove('show');
    }
});

// ============================================================
// Live task counts poll
// ============================================================
if (window.CHED && CHED.Poller) {
    CHED.Poller.register('teacherTasks', function () {
        return fetch('teacher_tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'ajax_action=refresh_counts'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || typeof d.not_started === 'undefined') return;
            var sections = { 'not_started': 'To Do', 'pending': 'Pending Review', 'rejected': 'Returned', 'completed': 'Approved' };
            document.querySelectorAll('.card').forEach(function (card) {
                var h3 = card.querySelector('.card-header h3');
                if (!h3) return;
                var title = h3.textContent.trim();
                Object.keys(sections).forEach(function (key) {
                    if (title.indexOf(sections[key]) === 0) {
                        var countEl = card.querySelector('.section-count');
                        if (countEl) countEl.textContent = d[key] + ' items';
                    }
                });
            });
        }).catch(function () {});
    }, 30000);
}
</script>
";
    include 'includes/footer.php';
