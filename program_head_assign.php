<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();

$deptId = (int) $_SESSION['department_id'];
$phId   = (int) $_SESSION['user_id'];
$error   = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

// Get current folder filter from GET
$selectedFolderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

// ============================================================================
// HELPER: build folder dropdown options (recursive)
// ============================================================================
function getFolderOptions($pdo, $departmentId, $parentId = null, $prefix = '', $selectedId = null) {
    $stmt = $pdo->prepare('SELECT id, folder_name FROM department_folders WHERE department_id = ? AND parent_folder_id ' . ($parentId === null ? 'IS NULL' : '= ?') . ' AND LOWER(folder_name) <> \'root\' ORDER BY folder_name');
    if ($parentId === null) {
        $stmt->execute([$departmentId]);
    } else {
        $stmt->execute([$departmentId, $parentId]);
    }
    $folders = $stmt->fetchAll();
    $html = '';
    foreach ($folders as $f) {
        $sel = ($selectedId !== null && (int)$f['id'] === (int)$selectedId) ? ' selected' : '';
        $html .= '<option value="' . $f['id'] . '"' . $sel . '>' . $prefix . htmlspecialchars($f['folder_name']) . '</option>';
        $html .= getFolderOptions($pdo, $departmentId, $f['id'], $prefix . '— ', $selectedId);
    }
    return $html;
}

// POST HANDLER (unchanged, but we keep folder filter in redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    requireCsrfToken();
    $teacherIds = $_POST['teacher_ids'] ?? [];
    $reqIds     = $_POST['req_ids'] ?? [];
$deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
    if ($deadline) { $dt = DateTime::createFromFormat('m/d/Y', $deadline); $deadline = $dt ? $dt->format('Y-m-d') : $deadline; }

    if (empty($teacherIds) || empty($reqIds)) {
        $error = 'Please select at least one faculty member and one requirement.';
    } else {
        $count  = 0;
        $alreadyAssigned = 0;
        $failed = 0;
        $error = '';
        $newlyAssigned = [];

        $reqIdsInt = array_values(array_map('intval', $reqIds));

        // Preload requirement info for post-commit notifications
        $reqInfoMap = [];
        if ($reqIdsInt) {
            $reqPlaceholders = implode(',', array_fill(0, count($reqIdsInt), '?'));
            $reqInfoStmt = $pdo->prepare("SELECT id, category, document_type FROM department_requirements WHERE id IN ($reqPlaceholders)");
            $reqInfoStmt->execute($reqIdsInt);
            foreach ($reqInfoStmt->fetchAll() as $r) {
                $reqInfoMap[(int)$r['id']] = $r;
            }
        }

        // Bulk assignment runs inside a transaction. Email/notification delivery is
        // deferred until after the transaction commits so emails are never sent
        // while the transaction is open, and are not lost if it rolls back.
        try {
            $pdo->beginTransaction();
            foreach ($teacherIds as $tid) {
                $tid = (int) $tid;
                foreach ($reqIdsInt as $rid) {
                    $assignmentResult = assignRequirementToTeacher($pdo, $tid, $rid, $phId, $deadline, 'admin_requirement', true);
                    if ($assignmentResult['success']) {
                        if ($assignmentResult['inserted']) {
                            $count++;
                            $newlyAssigned[] = ['teacher_id' => $tid, 'req_id' => $rid];
                        } else {
                            $alreadyAssigned++;
                        }
                    } else {
                        $failed++;
                    }
                }
            }
            if ($failed > 0) {
                $pdo->rollBack();
                $count = 0;
                $newlyAssigned = [];
                $error = 'Failed to assign some requirements. No changes were saved.';
            } else {
                $pdo->commit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $count = 0;
            $newlyAssigned = [];
            $error = 'Assignment failed: ' . $e->getMessage();
        }

        // Notify teachers after the transaction commits
        if (empty($error) && $count > 0) {
            $assignedReqNames = [];
            foreach ($newlyAssigned as $item) {
                $reqData = $reqInfoMap[$item['req_id']] ?? [];
                $assignedReqNames[$item['req_id']] = ($reqData['category'] ?? 'Requirement') . ' - ' . ($reqData['document_type'] ?? '');
                createNotification(
                    $pdo,
                    $item['teacher_id'],
                    'New Requirement Assigned',
                    "You have been assigned: " . ($reqData['category'] ?? 'Requirement') . " - " . ($reqData['document_type'] ?? '') . " (From Admin)",
                    'info'
                );
            }
            // One summary per admin (rate-limit safe vs per-assignment spam)
            $adminSummary = implode('; ', array_unique($assignedReqNames));
            $adminUsers = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
            foreach ($adminUsers as $admin) {
                createNotification(
                    $pdo,
                    $admin['id'],
                    'Admin Requirement Assigned to Faculty / Staff',
                    "$count admin requirement(s) assigned to faculty member(s): $adminSummary",
                    'info'
                );
            }
        }

        if ($count > 0) {
            $success = "Successfully assigned $count new requirement(s).";
            if ($alreadyAssigned > 0) {
                $success .= " ($alreadyAssigned already assigned - skipped)";
            }
        } elseif ($alreadyAssigned > 0) {
            $success = "All selected requirements are already assigned to the selected faculty.";
        } elseif ($failed > 0 || !empty($error)) {
            $error = $error ?: 'Failed to assign some requirements.';
        } else {
            $error = 'No requirements were assigned.';
        }
        // Redirect to keep folder filter
        $redirectParams = 'folder=' . ($selectedFolderId ?? 0);
        if (!empty($error)) {
            header("Location: program_head_assign.php?$redirectParams&error=" . urlencode($error));
        } else {
            header("Location: program_head_assign.php?$redirectParams&success=" . urlencode($success));
        }
        exit;
    }
}

// DATA FETCHING
$allTeachers = $pdo->prepare("SELECT id, name FROM users WHERE role IN ('teacher','staff') AND department_id = ? ORDER BY name");
$allTeachers->execute([$deptId]);
$allTeachers = $allTeachers->fetchAll();

// Get all requirements, but filter by folder if selected
$reqSql = "SELECT * FROM department_requirements WHERE department_id = ?";
$params = [$deptId];
if ($selectedFolderId) {
    $reqSql .= " AND folder_id = ?";
    $params[] = $selectedFolderId;
}
$reqSql .= " ORDER BY category, document_type";
$stmt = $pdo->prepare($reqSql);
$stmt->execute($params);
$requirements = $stmt->fetchAll();

// Get ALL assigned requirement IDs (for all teachers in department)
$assignedReqIds = $pdo->prepare("
    SELECT DISTINCT requirement_id FROM (
        SELECT tr.requirement_id 
        FROM teacher_requirements tr
        JOIN users u ON tr.teacher_id = u.id
        WHERE u.department_id = ?
        UNION
        SELECT tr.requirement_id
        FROM documents d
        JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        JOIN users u ON tr.teacher_id = u.id
        WHERE u.department_id = ? AND d.status IN ('approved_admin', 'approved_program_head', 'pending_admin')
        UNION
        SELECT dr.id as requirement_id
        FROM documents d
        JOIN department_requirements dr ON dr.category = d.category AND dr.document_type = d.document_type AND dr.department_id = d.department_id
        WHERE d.department_id = ? AND d.teacher_requirement_id IS NULL AND d.status IN ('approved_admin', 'approved_program_head', 'pending_admin')
    ) combined
");
$assignedReqIds->execute([$deptId, $deptId, $deptId]);
$assignedReqIds = $assignedReqIds->fetchAll(PDO::FETCH_COLUMN);

// Also get folder tree for dropdown (we'll build options separately)
$folderOptions = getFolderOptions($pdo, $deptId, null, '', $selectedFolderId);

$pageTitle = 'Assign Requirements';

// SVG icon helper (same set as program_head_dashboard.php — UI only)
if (!function_exists('render_dashboard_icon')) {
    function render_dashboard_icon(string $name): string {
        $icons = [
            'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>',
            'check' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'folder' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>',
            'send' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
            'link' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}
include 'includes/header.php'; 
?>

<style>
    /* Reuse same design tokens as dashboard */
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
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        font-family: 'Inter', sans-serif;
        color: var(--gray-800);
        font-size: 0.9375rem;
        line-height: 1.5;
        background: #f8fafc;
    }

    /* Page title — same as dashboard */
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
        letter-spacing: -0.02em;
        margin: 0;
        line-height: 1.25;
    }

    .page-head p {
        margin: 0.25rem 0 0;
        font-size: 0.875rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    .page-head .page-meta {
        font-size: 0.8125rem;
        color: var(--gray-500);
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 9999px;
        padding: 0.4rem 0.85rem;
        white-space: nowrap;
    }
    .card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: box-shadow 0.15s ease;
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
    .card-header .card-sub {
        font-size: 0.8rem;
        color: var(--gray-500);
        font-weight: 400;
    }
    .card-body {
        padding: 1.25rem;
    }
    .form-group {
        margin-bottom: 1rem;
    }
    .form-group:last-child {
        margin-bottom: 0;
    }
    .form-label {
        display: block;
        margin-bottom: 0.4rem;
        font-weight: 600;
        font-size: 0.82rem;
        color: var(--gray-700);
    }
    .form-hint {
        font-size: 0.78rem;
        color: var(--gray-500);
    }
    .form-control {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: 0.875rem;
        transition: var(--transition);
        background: var(--white);
        color: var(--gray-800);
        box-sizing: border-box;
    }
    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.5;
        white-space: nowrap;
    }
    .btn svg {
        width: 15px;
        height: 15px;
    }
    .btn-sm {
        padding: 0.4rem 0.7rem;
        font-size: 0.78rem;
    }
    .btn-primary {
        background: var(--primary);
        color: white;
    }
    .btn-primary:hover {
        background: var(--primary-dark);
    }
    .btn-success {
        background: var(--success);
        color: white;
    }
    .btn-success:hover {
        background: #0e9f6e;
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
    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
        border-color: var(--gray-200);
    }
    .btn-secondary:hover {
        background: var(--gray-200);
    }
    .btn-block {
        width: 100%;
    }
    .btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }
    .alert {
        padding: 0.9rem 1.1rem;
        border-radius: var(--radius-md);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.875rem;
        border: 1px solid var(--border);
        border-left: 3px solid;
        margin-bottom: 0;
    }
    .alert svg {
        flex-shrink: 0;
    }
    .alert-success {
        background: #ecfdf5;
        color: #047857;
        border-left-color: var(--success);
    }
    .alert-error {
        background: #fef2f2;
        color: #b91c1c;
        border-left-color: var(--danger);
    }
    .teacher-list-box {
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        max-height: 260px;
        overflow-y: auto;
        background: var(--white);
    }
    .teacher-check-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.6rem 0.8rem;
        cursor: pointer;
        transition: background 0.15s ease;
        border-bottom: 1px solid var(--gray-100);
        font-size: 0.85rem;
        color: var(--gray-800);
    }
    .teacher-check-item:last-child {
        border-bottom: none;
    }
    .teacher-check-item:hover {
        background: var(--gray-50);
    }
    .teacher-check-item input {
        margin: 0;
        width: 1rem;
        height: 1rem;
        accent-color: var(--primary);
        flex-shrink: 0;
    }
    .select-all-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }
    .select-all-row label {
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--primary);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .select-all-row input {
        accent-color: var(--primary);
    }
    .req-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .req-grid {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .req-card {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 0.7rem 0.85rem;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        position: relative;
        display: flex;
        gap: 0.65rem;
        align-items: flex-start;
        cursor: pointer;
    }
    .req-card:hover {
        border-color: var(--gray-300);
        box-shadow: var(--shadow-xs);
    }
    .req-card.selected {
        border-color: var(--primary);
        background: var(--primary-light);
    }
    .req-card input[type="checkbox"] {
        width: 1rem;
        height: 1rem;
        cursor: pointer;
        accent-color: var(--primary);
        flex-shrink: 0;
        margin-top: 0.15rem;
    }
    .req-card-body {
        min-width: 0;
        flex: 1;
    }
    .req-title {
        font-weight: 600;
        font-size: 0.88rem;
        color: var(--gray-900);
        line-height: 1.35;
    }
    .req-detail {
        font-size: 0.76rem;
        color: var(--gray-500);
        display: flex;
        flex-wrap: wrap;
        gap: 0.3rem;
        align-items: center;
        margin-top: 0.3rem;
    }
    .req-desc {
        font-size: 0.8rem;
        color: var(--gray-600);
        line-height: 1.45;
        margin-top: 0.3rem;
    }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.18rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
    }
    .badge svg {
        width: 12px;
        height: 12px;
    }
    .badge-mandatory {
        background: #fef2f2;
        color: #b91c1c;
    }
    .badge-optional {
        background: var(--gray-100);
        color: var(--gray-600);
    }
    .badge-expired {
        background: #fee2e2;
        color: #b91c1c;
    }
    .badge-expiring {
        background: #fef3c7;
        color: #92400e;
    }
    .badge-active {
        background: #ecfdf5;
        color: #065f46;
    }
    .badge-warning {
        background: #fef3c7;
        color: #92400e;
    }
    .badge-info {
        background: #eff6ff;
        color: #1d4ed8;
    }
    .badge-folder {
        background: #fef3c7;
        color: #92400e;
    }
    .btn-group {
        display: flex;
        gap: 0.4rem;
    }
    .empty-state {
        text-align: center;
        padding: 2rem 1.25rem;
        color: var(--gray-500);
    }
    .empty-state-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 0.65rem;
        border-radius: 14px;
        background: var(--gray-100);
        color: var(--gray-400);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .empty-state-icon svg {
        width: 22px;
        height: 22px;
    }
    .empty-state p {
        margin: 0;
        font-weight: 600;
        font-size: 0.88rem;
        color: var(--gray-600);
    }
    .assignment-grid {
        display: grid;
        grid-template-columns: minmax(0, 360px) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
    }
    @media (max-width: 992px) {
        .assignment-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.5rem;
            gap: 1rem;
        }
        .page-head h1 {
            font-size: 1.2rem;
        }
        .card-body {
            padding: 1rem;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.001ms !important;
            transition-duration: 0.001ms !important;
        }
    }
    .folder-filter {
        margin-bottom: 0.85rem;
        padding: 0.65rem 0.8rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: var(--gray-50);
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
    }
    .folder-filter label {
        font-weight: 600;
        font-size: 0.78rem;
        color: var(--gray-700);
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .folder-filter label svg {
        color: var(--gray-400);
    }
    .folder-filter select {
        padding: 0.45rem 0.7rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: white;
        font-size: 0.82rem;
        font-family: inherit;
        color: var(--gray-800);
        min-width: 180px;
        flex: 1;
    }
    .folder-filter select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }
    .req-scroll-list {
        max-height: 480px;
        overflow-y: auto;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: 0.75rem;
    }
</style>

<div class="dashboard-container">
    <!-- Page header — same as dashboard -->
  

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= render_dashboard_icon('x-circle') ?> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php include 'includes/success_toast.php'; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="assignment-grid">
            <!-- LEFT COLUMN: Configuration -->
            <div class="card">
                <div class="card-header">
                    <h3><?= render_dashboard_icon('users') ?> Assign to Faculty / Staff</h3>
                    <span class="badge badge-warning">Admin Requirements</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Select Faculty / Staff <span style="color: var(--danger);">*</span></label>
                        <div class="select-all-row">
                            <span class="form-hint">Select faculty members</span>
                            <label>
                                <input type="checkbox" id="selectAllTeachers"> Select All
                            </label>
                        </div>
                        <div class="teacher-list-box">
                            <?php foreach ($allTeachers as $t): ?>
                                <label class="teacher-check-item">
                                    <input type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" class="teacher-cb">
                                    <span><?= htmlspecialchars($t['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="deadline">Deadline</label>
                        <div class="deadline-wrap" style="position:relative">
                            <input type="text" name="deadline" id="deadline" class="form-control req-deadline" placeholder="MM/DD/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}">
                            <input type="date" class="req-deadline-picker" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer">
                        </div>
                    </div>

                    <button type="submit" name="assign" class="btn btn-primary btn-block">
                        <?= render_dashboard_icon('check') ?> Confirm Assignment
                    </button>
                </div>
            </div>

            <!-- RIGHT COLUMN: Available Requirements -->
            <div class="card">
                <div class="card-header">
                    <h3><?= render_dashboard_icon('clipboard') ?> Admin Requirements</h3>
                    <div class="btn-group">
                        <button type="button" id="selectAllReqs" class="btn btn-sm btn-outline">All</button>
                        <button type="button" id="deselectAllReqs" class="btn btn-sm btn-outline">None</button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Folder Filter -->
                    <div class="folder-filter">
                        <label for="folderFilter"><?= render_dashboard_icon('folder') ?> Folder</label>
                        <select id="folderFilter" data-csp-nav="program_head_assign.php?folder=">
                            <option value="">All Folders</option>
                            <?= $folderOptions ?>
                        </select>
                        <?php if ($selectedFolderId): ?>
                            <a href="program_head_assign.php" class="btn btn-sm btn-secondary">Clear</a>
                        <?php endif; ?>
                    </div>

                    <div class="req-scroll-list">
                    <?php
                    $visibleCount = 0;
                    foreach ($requirements as $r):
                        if (in_array($r['id'], $assignedReqIds)) continue;
                        $visibleCount++;
                        // Expiration state from the single 2-day rule (date is source of truth)
                        $expiryState = classifyExpiryStatus($r['expiration_date'] ?? null);
                        $expiryBadgeClass = ['active' => 'badge-active', 'expiring' => 'badge-expiring', 'expired' => 'badge-expired'][$expiryState];
                        $expiryBadgeSuffix = ['active' => ' · Active', 'expiring' => ' · Expiring', 'expired' => ' · Expired'][$expiryState];
                        // Get folder name if any
                        $folderName = '';
                        if ($r['folder_id']) {
                            $folderStmt = $pdo->prepare("SELECT folder_name FROM department_folders WHERE id = ?");
                            $folderStmt->execute([$r['folder_id']]);
                            $folderName = $folderStmt->fetchColumn();
                        }
                    ?>
                        <div class="req-card" data-req-id="<?= $r['id'] ?>">
                            <input type="checkbox" name="req_ids[]" value="<?= $r['id'] ?>" class="req-cb" aria-label="Select <?= htmlspecialchars($r['document_type']) ?>">
                            <div class="req-card-body">
                                <div class="req-title"><?= htmlspecialchars($r['document_type']) ?></div>
                                <div class="req-detail">
                                    <span><?= htmlspecialchars($r['category']) ?></span>
                                    <?php if ($folderName): ?>
                                        <span class="badge badge-folder"><?= render_dashboard_icon('folder') ?> <?= htmlspecialchars($folderName) ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['mandatory']): ?>
                                        <span class="badge badge-mandatory">Required</span>
                                    <?php else: ?>
                                        <span class="badge badge-optional">Optional</span>
                                    <?php endif; ?>
                                    <?php if ($r['expiration_date']): ?>
                                        <span class="badge <?= $expiryBadgeClass ?>">
                                            <?= date('M j, Y', strtotime($r['expiration_date'])) ?><?= $expiryBadgeSuffix ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($r['description'])): ?>
                                    <div class="req-desc"><?= htmlspecialchars($r['description']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <?php if ($visibleCount === 0): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><?= render_dashboard_icon('link') ?></div>
                            <p>No unassigned requirements found<?= $selectedFolderId ? ' in this folder' : '' ?>.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<script nonce="<?= csp_nonce() ?>">
    // Teacher "Select All"
    const selectAllTeachers = document.getElementById('selectAllTeachers');
    const teacherCheckboxes = document.querySelectorAll('.teacher-cb');
    if (selectAllTeachers) {
        selectAllTeachers.addEventListener('change', function() {
            teacherCheckboxes.forEach(cb => cb.checked = this.checked);
        });
    }

    // Requirement "Select All / None" and card highlighting
    const selectAllReqs = document.getElementById('selectAllReqs');
    const deselectAllReqs = document.getElementById('deselectAllReqs');
    const reqCheckboxes = document.querySelectorAll('.req-cb');

    function updateReqCardSelection() {
        document.querySelectorAll('.req-card').forEach(card => {
            const cb = card.querySelector('.req-cb');
            if (cb && cb.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });
    }

    if (selectAllReqs) {
        selectAllReqs.addEventListener('click', () => {
            reqCheckboxes.forEach(cb => cb.checked = true);
            updateReqCardSelection();
        });
    }
    if (deselectAllReqs) {
        deselectAllReqs.addEventListener('click', () => {
            reqCheckboxes.forEach(cb => cb.checked = false);
            updateReqCardSelection();
        });
    }
    reqCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateReqCardSelection);
    });
    // Clicking anywhere on the card toggles its checkbox (except links/buttons/inputs)
    document.querySelectorAll('.req-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.closest('a, button, input, select, textarea')) return;
            const cb = card.querySelector('.req-cb');
            if (cb) {
                cb.checked = !cb.checked;
                updateReqCardSelection();
            }
        });
    });
    updateReqCardSelection();

    document.querySelectorAll('.deadline-wrap').forEach(wrap => {
        const input = wrap.querySelector('.req-deadline');
        const picker = wrap.querySelector('.req-deadline-picker');
        if (!input || !picker) return;
        picker.addEventListener('click', () => {
            const toDb = v => { const p = v.split('/'); return p.length === 3 ? p[2] + '-' + p[0] + '-' + p[1] : ''; };
            picker.value = input.value && input.value.includes('/') ? toDb(input.value) : '';
            picker.showPicker();
        });
        picker.addEventListener('change', () => {
            const p = picker.value.split('-');
            input.value = p.length === 3 ? p[1] + '/' + p[2] + '/' + p[0] : picker.value;
        });
    });
</script>

<?php include 'includes/footer.php'; ?>



