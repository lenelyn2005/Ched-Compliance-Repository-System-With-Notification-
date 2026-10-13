<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();

$deptId = (int)$_SESSION['department_id'];
$phId = (int)$_SESSION['user_id'];
$error = '';
$success = '';

// Teacher and Staff are the same account: one creation/edit/delete flow, one set of
// pages. Only the stored role (and the label it displays as) differs.
$ROLE_LABELS = ['teacher' => 'Teacher', 'staff' => 'Staff'];
// Named $facultyRoleLabel, not $roleLabel: header.php/sidebar.php set $roleLabel to a
// plain string and this page includes them, which turned the call below into a fatal.
$facultyRoleLabel = static fn(?string $r): string => $ROLE_LABELS[$r] ?? 'Teacher';

// Auto-cleanup: sweep ONLY faculty never linked to any department AND never
// linked to any head. The previous subquery-driven version mass-deleted every
// faculty whose creator had been removed — a footgun if a PH is deleted and
// re-created, or if a migration temporarily strips head roles. This matches
// the documented intent ("only faculty never linked to any department").
// Faculty with a dangling program_head_id are kept; the admin can prune them
// deliberately if needed.
$orphaned = $pdo->exec("DELETE FROM users WHERE role IN ('teacher','staff') AND department_id IS NULL AND program_head_id IS NULL");
if ($orphaned) {
    logAudit($pdo, 'auto_delete_orphaned_teacher', "$orphaned orphaned member account(s) removed", 'user', 0);
}

// Handle delete (POST + CSRF; only faculty in this department)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && is_numeric($_POST['delete'])) {
    requireCsrfToken();
    $id = (int)$_POST['delete'];
    // Scope MUST match the SELECT below:
    //   program_head_id = me  OR  (department_id = me AND program_head_id IS NULL)
    $stmt = $pdo->prepare(
        'DELETE FROM users
          WHERE id = ? AND role IN ("teacher","staff")
            AND (program_head_id = ? OR (department_id = ? AND program_head_id IS NULL))'
    );
    $stmt->execute([$id, $phId, $deptId]);
    // PDOStatement::execute() returns true even on 0 affected rows — must
    // check rowCount() or a PH clicking delete on someone else's row gets a
    // false "Account removed" toast while nothing happened.
    if ($stmt->rowCount() > 0) {
        logAudit($pdo, 'delete_teacher', "Account deleted (id: $id) by " . phRoleShort(), 'user', $id);
        header('Location: program_head_teachers.php?msg=Account removed');
        exit;
    }
    $error = 'Account not found or you do not have permission to remove it.';
}

if (isset($_GET['msg'])) {
    $success = $_GET['msg'];
}

// Handle add/edit (a delete POST has its own handler above - never fall through
// into it, or this block's errors overwrite the delete's real message)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete'])) {
    requireCsrfToken();
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Shared page for Program Head and Unit Head: allowedRoleFor() checks the
    // submitted role against the logged-in actor's own list (teacher|staff), so a
    // crafted role=department / unit_head / admin is rejected instead of coerced.
    $role = allowedRoleFor($_SESSION['role'] ?? '', $_POST['role'] ?? '');
    if ($role === null) {
        $error = 'Invalid account type selected.';
        $role = 'teacher'; // never written: $error below blocks every save branch
    }
    $roleText = $facultyRoleLabel($role);

    // A head without a department would write department_id = 0 and produce a
    // faculty row no department query ever matches - refuse instead.
    if ($error === '' && $deptId <= 0) {
        $error = 'Your account is not linked to a department yet. Ask an administrator to assign one first.';
    } elseif ($error === '' && (empty($name) || empty($email))) {
        $error = 'Name and email are required.';
    } elseif ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($error === '' && !$id && empty($password)) {
        $error = 'Password is required for new users.';
    } elseif ($error === '' && $password !== '' && strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($error === '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $id ?? 0]);
        if ($stmt->fetch()) {
            $error = 'Email already in use.';
        } else {
            try {
                if ($id) {
                    // Update. Scope MUST match the SELECT that renders this page:
                    //   program_head_id = me  OR  (department_id = me AND program_head_id IS NULL)
                    // The old "(department_id = me OR department_id IS NULL)" was broader — a
                    // PH could edit rows the page never showed them (another head's faculty,
                    // or any department_id IS NULL orphan).
                    if (!empty($password)) {
                        $stmt = $pdo->prepare(
                            'UPDATE users SET name=?, email=?, password=?, role=?, department_id=?, program_head_id=?
                              WHERE id=? AND role IN ("teacher","staff")
                                AND (program_head_id = ? OR (department_id = ? AND program_head_id IS NULL))'
                        );
                        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $deptId, $phId, $id, $phId, $deptId]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE users SET name=?, email=?, role=?, department_id=?, program_head_id=?
                              WHERE id=? AND role IN ("teacher","staff")
                                AND (program_head_id = ? OR (department_id = ? AND program_head_id IS NULL))'
                        );
                        $stmt->execute([$name, $email, $role, $deptId, $phId, $id, $phId, $deptId]);
                    }
                    if ($stmt->rowCount() === 0) {
                        // 0 rows can mean "not found" OR "no-op update". A no-op is harmless;
                        // a not-found means the id belongs to a faculty this PH cannot manage.
                        // Re-check existence to distinguish the two and report honestly.
                        $exists = $pdo->prepare('SELECT id FROM users WHERE id = ?');
                        $exists->execute([$id]);
                        if (!$exists->fetch()) {
                            $error = 'Account not found or you do not have permission to edit it.';
                        } else {
                            logAudit($pdo, 'edit_teacher', "$roleText updated by " . phRoleShort() . ": $email", 'user', $id);
                            $success = "$roleText updated successfully.";
                        }
                    } else {
                        logAudit($pdo, 'edit_teacher', "$roleText updated by " . phRoleShort() . ": $email", 'user', $id);
                        $success = "$roleText updated successfully.";
                    }
                } else {
                    // Create
                    $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role, department_id, program_head_id) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $deptId, $phId]);
                    $newId = (int)$pdo->lastInsertId();
                    logAudit($pdo, 'create_teacher', "$roleText created by " . phRoleShort() . ": $email", 'user', $newId);

                    // Send Welcome Notification & Email
                    createNotification($pdo, $newId, 'Welcome to CHED Compliance!', 'Your account has been created. Login to view your assigned tasks.', 'info');

                    $success = "$roleText account created successfully.";
                }
            } catch (PDOException $e) {
                error_log('[CHED] faculty save failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    }
}

// Fetch faculty (teachers and staff) in this department
$teachers = $pdo->prepare("SELECT * FROM users WHERE role IN ('teacher','staff') AND ((department_id = ? AND program_head_id = ?) OR (department_id = ? AND program_head_id IS NULL) OR (department_id IS NULL AND program_head_id = ?)) ORDER BY name");
$teachers->execute([$deptId, $phId, $deptId, $phId]);
$teachers = $teachers->fetchAll();

$editUser = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($teachers as $t) {
        if ((int)$t['id'] === (int)$_GET['edit']) {
            $editUser = $t;
            break;
        }
    }
}

$pageTitle = 'Manage Members';

function render_dashboard_icon(string $name): string {
    $icons = [
        'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'user' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    ];
    return $icons[$name] ?? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/></svg>';
}

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

    .overview-grid {
        display: grid;
        grid-template-columns: 420px 1fr;
        gap: 1.25rem;
        align-items: start;
    }

    @media (max-width: 1024px) {
        .overview-grid {
            grid-template-columns: 1fr;
        }
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

    .card-content {
        padding: 0;
    }

    .card-content.padded {
        padding: 1.25rem;
    }

    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }

    .data-table th {
        text-align: left;
        padding: 0.65rem 1.25rem;
        font-size: 0.7rem;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-500);
        background: var(--gray-50);
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .data-table td {
        padding: 0.8rem 1.25rem;
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

    .badge-neutral {
        background: var(--gray-100);
        color: var(--gray-600);
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

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8125rem;
    }

    .btn-block {
        width: 100%;
        justify-content: center;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-sm);
    }

    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
        border-color: var(--gray-200);
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

    .empty-state-icon svg {
        width: 26px;
        height: 26px;
    }

    .empty-state p {
        margin: 0 0 0.25rem;
        font-weight: 600;
        color: var(--gray-600);
    }

    .empty-state small {
        display: block;
        font-size: 0.8125rem;
        color: var(--gray-500);
    }

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

    /* Password visibility toggle */
    .umu-pass-wrap { position: relative; }
    .umu-pass-wrap .form-control { padding-right: 2.6rem; }
    .umu-pass-toggle {
        position: absolute; right: .45rem; top: 50%; transform: translateY(-50%);
        width: 32px; height: 32px; border-radius: 7px; border: 1px solid transparent;
        background: transparent; color: var(--gray-400); cursor: pointer; padding: 0;
        display: inline-flex; align-items: center; justify-content: center;
        transition: color .2s ease, background .2s ease, border-color .2s ease;
    }
    .umu-pass-toggle:hover { background: var(--gray-100); color: var(--gray-800); }
    .umu-pass-toggle:focus-visible { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
    .umu-pass-toggle svg { width: 17px; height: 17px; }

    textarea.form-control {
        resize: vertical;
    }

    .form-info {
        margin-bottom: 1.5rem;
        font-size: 0.85rem;
        color: var(--gray-600);
        background: var(--gray-50);
        padding: 0.75rem;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid var(--border);
    }

    .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.875rem;
        flex-shrink: 0;
        background: var(--primary-light);
        color: var(--primary-dark);
    }

    .avatar-danger {
        background: var(--danger-light);
        color: var(--danger);
    }

    .teacher-name {
        font-weight: 600;
        color: var(--gray-900);
    }

    .teacher-email {
        font-size: 0.8125rem;
        color: var(--gray-500);
    }

    .required-mark {
        color: var(--danger);
    }

    .required-note {
        font-size: 0.75rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.5rem;
            gap: 1rem;
        }

        .overview-grid {
            grid-template-columns: 1fr;
        }

        .data-table th, .data-table td {
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

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease;
    }

    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .modal-overlay .modal-box {
        display: block;
        position: relative;
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-xl);
        max-width: 420px;
        width: 90%;
        transform: scale(0.95);
        transition: transform 0.2s ease;
    }

    .modal-overlay.active .modal-box {
        transform: scale(1);
    }

    .modal-overlay .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-overlay .modal-header h3 {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-overlay .modal-header h3 svg {
        color: var(--danger);
    }

    .modal-overlay .modal-close {
        background: none;
        border: none;
        padding: 0.25rem;
        cursor: pointer;
        color: var(--gray-400);
        border-radius: var(--radius-sm);
        transition: var(--transition);
    }

    .modal-overlay .modal-close:hover {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .modal-overlay .modal-body {
        padding: 1.5rem;
    }

    .modal-overlay .modal-body p {
        margin: 0;
        color: var(--gray-600);
        line-height: 1.6;
    }

    .modal-overlay .modal-body .teacher-name-highlight {
        font-weight: 700;
        color: var(--gray-900);
    }

    .modal-overlay .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }

    .modal-overlay .modal-footer .btn {
        min-width: 100px;
    }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="dashboard-container">
    <?php if ($error): ?><div class="alert alert-danger"><svg xmlns="http://www.w3.org/2000/svg" style="width:18px;height:18px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg><?= sanitize($error) ?></div><?php endif; ?>

    <?php include 'includes/success_toast.php'; ?>

    <div class="overview-grid">
        <!-- Add New Teacher Form -->
        <div class="card">
            <div class="card-header">
                <h3><?= $editUser ? ' Edit Account' : ' Add New Account' ?></h3>
                <?php if ($editUser): ?><a href="program_head_teachers.php" class="btn btn-sm btn-secondary">Cancel</a><?php endif; ?>
            </div>
            <div class="card-content padded">
                <form method="POST">
                    <?= csrfField() ?>
                    <?php if ($editUser): ?><input type="hidden" name="id" value="<?= $editUser['id'] ?>"><?php endif; ?>

                    <div class="form-group">
                        <label>Full Name <span class="required-mark">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="Enter full name"
                            value="<?= sanitize($editUser['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="required-mark">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="name@institution.edu"
                            value="<?= sanitize($editUser['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Role <span class="required-mark">*</span></label>
                        <select name="role" class="form-control" required>
                            <?php foreach ($ROLE_LABELS as $roleValue => $roleText2): ?>
                                <option value="<?= $roleValue ?>" <?= ($editUser['role'] ?? 'teacher') === $roleValue ? 'selected' : '' ?>>
                                    <?= $roleText2 ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Password <?php if (!$editUser): ?><span class="required-mark">*</span><?php else: ?><span class="required-note">(Leave blank to keep current)</span><?php endif; ?></label>
                        <div class="umu-pass-wrap">
                            <input type="password" id="umu-pass" name="password" class="form-control" <?= $editUser ? '' : 'required' ?> placeholder="Enter password">
                            <button type="button" class="umu-pass-toggle" id="umuPassToggle" aria-label="Show password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path class="umu-pass-slash" stroke-linecap="round" d="M4 4l16 16" style="display:none" /></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        <?= $editUser ? 'Update Account' : 'Register Account' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Members -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                    Members
                </h3>
            </div>
            <div class="card-content">
                <?php if (empty($teachers)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </div>
                        <p>No Members Yet</p>
                        <small>Add your first member using the form.</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Email</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teachers as $t): ?>
                                <tr data-row-id="teacher:<?= (int)$t['id'] ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div class="avatar"><?= strtoupper(substr($t['name'], 0, 1)) ?></div>
                                            <span class="teacher-name"><?= sanitize($t['name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= ($t['role'] ?? '') === 'staff' ? 'badge-neutral' : 'badge-info' ?>">
                                            <?= sanitize($facultyRoleLabel($t['role'] ?? 'teacher')) ?>
                                        </span>
                                    </td>
                                    <td><span class="teacher-email"><?= sanitize($t['email']) ?></span></td>
                                    <td style="text-align: right;">
                                        <div class="btn-group" style="justify-content: flex-end;">
                                            <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                                                <svg xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                                Edit
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" title="Delete"
                                               data-csp-action="showDeleteModal" data-csp-args="<?= htmlspecialchars(json_encode([(int)$t['id'], sanitize($t['name'])]), ENT_QUOTES, 'UTF-8') ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                            </button>
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
    </div>
</div>

<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                Delete Account
            </h3>
            <button type="button" class="modal-close" data-csp-action="closeDeleteModal">
                <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <span class="teacher-name-highlight" id="deleteTeacherName"></span>?</p>
            <p style="margin-top: 0.5rem; font-size: 0.875rem;">Deleting this account will also remove their assignments. This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <form method="POST" action="program_head_teachers.php" id="deleteTeacherForm" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="delete" id="deleteTeacherId" value="">
                <button type="button" class="btn btn-secondary" data-csp-action="closeDeleteModal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
function showDeleteModal(id, name) {
    document.getElementById('deleteTeacherName').textContent = name;
    document.getElementById('deleteTeacherId').value = id;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('deleteModal').classList.contains('active')) {
        closeDeleteModal();
    }
});

/* Password visibility toggle - same as admin_manage_users.php */
var passToggle = document.getElementById('umuPassToggle');
var passInput = document.getElementById('umu-pass');
if (passToggle && passInput) passToggle.addEventListener('click', function(){
    var show = passInput.type === 'password';
    passInput.type = show ? 'text' : 'password';
    passToggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    passToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    var umuSlash = passToggle.querySelector('.umu-pass-slash');
    if (umuSlash) umuSlash.style.display = show ? 'none' : '';
});
</script>

<?php include 'includes/footer.php'; ?>




