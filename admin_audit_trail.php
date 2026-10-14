<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

// --- Delete All Audit Logs: password confirmation, CSRF & admin authorization ---
$auditDeleteError = $_SESSION['audit_delete_error'] ?? '';
$auditDeleteSuccess = $_SESSION['audit_delete_success'] ?? '';
unset($_SESSION['audit_delete_error'], $_SESSION['audit_delete_success']);

// Password verification endpoint (AJAX): verify correct admin password before final confirmation
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['verify_admin_password'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    header('Content-Type: application/json');
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin access required.']);
        exit;
    }
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired session. Please refresh the page and try again.']);
        exit;
    }
    $adminPassword = $_POST['admin_password'] ?? '';
    if ($adminPassword === '') {
        echo json_encode(['success' => false, 'error' => 'Password is required.']);
        exit;
    }
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT password, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
    $hash = $adminUser['password'] ?? '';
    $dbRole = $adminUser['role'] ?? '';
    if ($dbRole !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin access required.']);
        exit;
    }
    if (empty($hash) || !password_verify($adminPassword, $hash)) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password. Audit logs were not deleted.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Password verified.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_all_audit_logs'])) {
    // Admin authorization (requireAdmin already called, double-check role)
    if (!isAdmin()) {
        $auditDeleteError = 'Unauthorized: Admin access required.';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $auditDeleteError]);
            exit;
        }
        $_SESSION['audit_delete_error'] = $auditDeleteError;
        header('Location: admin_audit_trail.php');
        exit;
    }

    // CSRF protection
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $auditDeleteError = 'Invalid or expired session. Please refresh the page and try again.';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $auditDeleteError]);
            exit;
        }
        $_SESSION['audit_delete_error'] = $auditDeleteError;
        header('Location: admin_audit_trail.php');
        exit;
    }

    $adminPassword = $_POST['admin_password'] ?? '';
    if ($adminPassword === '') {
        $auditDeleteError = 'Password is required.';
    } else {
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT password, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
        $hash = $adminUser['password'] ?? '';
        $dbRole = $adminUser['role'] ?? '';

        // Extra Admin authorization: ensure DB role is admin as well
        if ($dbRole !== 'admin') {
            $auditDeleteError = 'Unauthorized: Admin access required.';
        } else if (empty($hash) || !password_verify($adminPassword, $hash)) {
            // Verify the password securely server-side using password_verify()
            $auditDeleteError = 'Incorrect password. Audit logs were not deleted.';
        } else {
            try {
                $pdo->exec('DELETE FROM audit_trail');
                // Re-log the deletion itself so there is an audit trail entry post-purge (optional)
                try { logAudit($pdo, 'delete', 'All audit logs deleted by admin (id: ' . $adminId . ')'); } catch (Exception $e) {}
                $auditDeleteSuccess = 'All audit logs have been permanently deleted.';
            } catch (Exception $e) {
                $auditDeleteError = 'Failed to delete audit logs: ' . $e->getMessage();
            }
        }
    }

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        if ($auditDeleteError !== '') {
            echo json_encode(['success' => false, 'error' => $auditDeleteError]);
        } else {
            echo json_encode(['success' => true, 'message' => $auditDeleteSuccess]);
        }
        exit;
    }

    if ($auditDeleteError !== '') {
        $_SESSION['audit_delete_error'] = $auditDeleteError;
    } else {
        $_SESSION['audit_delete_success'] = $auditDeleteSuccess;
    }
    // After successful deletion, refresh the audit log and show a success message
    header('Location: admin_audit_trail.php');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$filterAction = $_GET['action'] ?? '';
$filterUser = !empty($_GET['user']) ? (int)$_GET['user'] : null;

// Build conditions for WHERE clause
$conditions = [];
$params = [];

if (!empty($filterAction)) {
    $conditions[] = 'a.action LIKE ?';
    $params[] = '%' . $filterAction . '%';
}

if ($filterUser !== null) {
    $conditions[] = 'a.user_id = ?';
    $params[] = $filterUser;
}

// Build WHERE clause
$where = '';
if (!empty($conditions)) {
    $where = 'WHERE ' . implode(' AND ', $conditions);
}

// Count total rows for pagination
$totalQuery = $pdo->prepare("SELECT COUNT(*) FROM audit_trail a $where");
$totalQuery->execute($params);
$totalRows = (int)$totalQuery->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// Prepare main query
$sql = "
    SELECT a.*, u.name AS user_name, u.role
    FROM audit_trail a
    LEFT JOIN users u ON a.user_id = u.id
    $where
";

// Add filter for user roles or system entries
if (!empty($where)) {
    $sql .= " AND (u.role IN ('admin', 'department', 'unit_head') OR u.id IS NULL)";
} else {
    $sql .= " WHERE (u.role IN ('admin', 'department', 'unit_head') OR u.id IS NULL)";
}

$sql .= " ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset";

$logs = $pdo->prepare($sql);
$logs->execute($params);
$logs = $logs->fetchAll();

$allUsers = $pdo->query("SELECT id, name FROM users WHERE role IN ('admin', 'department', 'unit_head') ORDER BY name")->fetchAll();

$pageTitle = 'Audit Trail';

// Start output buffering for extra styles
ob_start();
?>
<style>
    @import url('offline/inter-font.css');

    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-light: #eef2ff;
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
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;
        --white: #ffffff;
        --border: #e5e7eb;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --radius-sm: 0.375rem;
        --radius-md: 0.5rem;
        --radius-lg: 0.75rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        --app-bg: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
    }

    [data-theme="dark"] {
        --gray-50: #0f172a;
        --gray-100: #1e293b;
        --gray-200: #334155;
        --gray-600: #94a3b8;
        --gray-700: #cbd5e1;
        --gray-900: #f8fafc;
        --white: #1e293b;
        --border: #334155;
        --app-bg: #0f172a;
    }

    [data-theme="dark"] .card-header,
    [data-theme="dark"] .data-table th {
        background: var(--gray-100);
    }

    * { box-sizing: border-box; }
    body {
        background: var(--app-bg);
        font-family: 'Inter', sans-serif;
        min-height: 100vh;
    }

    .audit-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0.875rem;
        display: flex;
        flex-direction: column;
        gap: 0.875rem;
    }

    /* Page Header */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        padding: 0.875rem 1.125rem;
        box-shadow: var(--shadow-sm);
    }
    .page-header h1 {
        font-size: 1.3rem;
        font-weight: 800;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        letter-spacing: -0.025em;
    }
    .page-header .header-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--primary) 0%, #6366f1 100%);
        color: #fff;
    }
    .page-header .header-icon svg { width: 20px; height: 20px; }
    .page-header .subtitle {
        font-size: 0.78rem;
        color: var(--gray-600);
        margin-top: 0.15rem;
    }
    .live-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.55rem;
        background: var(--success-light);
        color: #047857;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
    }
    .live-dot {
        width: 6px;
        height: 6px;
        background: var(--success);
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }

    /* Filter Card */
    .filter-card {
        background: var(--white);
        border-radius: var(--radius-md);
        border: 1px solid var(--border);
        padding: 0.75rem 1rem;
        box-shadow: var(--shadow-sm);
    }
    .filter-form {
        display: flex;
        gap: 1rem;
        align-items: flex-end;
        flex-wrap: wrap;
    }
    .filter-group {
        flex: 1;
        min-width: 180px;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }
    .filter-group label {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--gray-700);
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .form-control {
        width: 100%;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--gray-300);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: 0.85rem;
        transition: var(--transition);
        background: var(--white);
        color: var(--gray-900);
    }
    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }
    .filter-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex: 0 0 auto;
    }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        line-height: 1.4;
    }
    .btn-sm {
        padding: 0.3rem 0.65rem;
        font-size: 0.7rem;
    }
    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: var(--shadow-sm);
    }
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }
    .btn-secondary {
        background: var(--gray-200);
        color: var(--gray-700);
    }
    .btn-secondary:hover {
        background: var(--gray-300);
    }
    .btn-outline {
        background: transparent;
        border: 1px solid var(--gray-300);
        color: var(--gray-700);
    }
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }

    /* Card */
    .card {
        background: var(--white);
        border-radius: var(--radius-md);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .card-header {
        padding: 0.7rem 0.9rem;
        background: var(--gray-50);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .card-header h3 {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }
    .card-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--primary);
    }
    .card-content {
        padding: 1rem;
    }
    .card-content.no-padding {
        padding: 0;
    }

    /* Table */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.78rem;
    }
    .data-table th {
        text-align: left;
        padding: 0.55rem 0.85rem;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: var(--gray-700);
        background: var(--gray-50);
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }
    .data-table td {
        padding: 0.55rem 0.85rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
        color: var(--gray-700);
    }
    .data-table tr:last-child td {
        border-bottom: none;
    }
    .data-table tbody tr:hover td {
        background: var(--gray-50);
    }
    .text-xs {
        font-size: 0.7rem;
    }
    .font-semibold {
        font-weight: 600;
    }

    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.12rem 0.45rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .badge-pending { background: var(--gray-200); color: var(--gray-700); }
    .badge-approved { background: var(--success-light); color: #047857; }
    .badge-rejected { background: var(--danger-light); color: #b91c1c; }
    .badge-pending-ph { background: var(--warning-light); color: #b45309; }
    .badge-submitted { background: var(--info-light); color: #1e40af; }
    .badge-system { background: var(--gray-100); color: var(--gray-600); }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: var(--gray-600);
        font-size: 0.875rem;
    }

    /* Pagination */
    .pagination-wrapper {
        display: flex;
        gap: 0.25rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .pagination-wrapper .btn {
        min-width: 32px;
        justify-content: center;
    }
    .pagination-wrapper .btn-primary {
        pointer-events: none;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .audit-container { padding: 0.625rem; }
        .filter-form {
            flex-direction: column;
            align-items: stretch;
        }
        .filter-group { min-width: unset; }
        .filter-actions { flex-direction: row; justify-content: stretch; }
        .filter-actions .btn { flex: 1; }
        .data-table th,
        .data-table td {
            padding: 0.4rem 0.6rem;
        }
        .card-header { flex-direction: column; align-items: stretch; }
        .pagination-wrapper { justify-content: center; }
    }
    @media (max-width: 480px) {
        .page-header { flex-direction: column; align-items: start; }
        .live-badge { align-self: start; }
    }

    /* Delete All Audit Logs - button & alerts */
    .btn-danger {
        background: var(--danger);
        color: #fff;
        border: 1px solid var(--danger);
        box-shadow: var(--shadow-sm);
    }
    .btn-danger:hover {
        background: #dc2626;
        border-color: #dc2626;
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }
    .alert {
        padding: 0.75rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.85rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid;
    }
    .alert-success {
        background: var(--success-light);
        color: #065f46;
        border-color: #a7f3d0;
    }
    .alert-danger {
        background: var(--danger-light);
        color: #991b1b;
        border-color: #fecaca;
    }

    /* Password confirmation modal */
    .audit-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }
    .audit-modal.show {
        display: flex;
    }
    .audit-modal-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.55);
        backdrop-filter: blur(2px);
    }
    .audit-modal-content {
        position: relative;
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        width: 100%;
        max-width: 420px;
        margin: 1rem;
        padding: 1.25rem;
        z-index: 1;
        animation: modalIn 0.2s ease-out;
    }
    @keyframes modalIn {
        from { opacity: 0; transform: translateY(10px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .audit-modal-content h3 {
        font-size: 1rem;
        font-weight: 700;
        color: var(--gray-900);
        margin: 0 0 0.35rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .audit-modal-content h3 svg { color: var(--danger); width: 20px; height: 20px; }
    .audit-modal-content .modal-desc {
        font-size: 0.82rem;
        color: var(--gray-600);
        margin-bottom: 1rem;
        line-height: 1.5;
    }
    .audit-modal .form-group { margin-bottom: 0.75rem; }
    .audit-modal .form-group label {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--gray-700);
        margin-bottom: 0.25rem;
        display: block;
    }
    .audit-modal .form-control {
        width: 100%;
        padding: 0.6rem 0.75rem;
        border: 1px solid var(--gray-300);
        border-radius: var(--radius-sm);
        font-size: 0.85rem;
    }
    .audit-modal .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }
    .modal-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }
    .modal-error {
        display: none;
        margin-top: 0.5rem;
        padding: 0.5rem 0.75rem;
        background: var(--danger-light);
        color: #991b1b;
        border: 1px solid #fecaca;
        border-radius: var(--radius-sm);
        font-size: 0.78rem;
    }
    .modal-error.show { display: block; }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="audit-container">

    <?php if (!empty($auditDeleteError)): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($auditDeleteError) ?></div>
    <?php endif; ?>


    <!-- Filter Card -->
    <div class="filter-card">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>Action Type</label>
                <input type="text" name="action" class="form-control" value="<?= htmlspecialchars($filterAction) ?>" placeholder="e.g. login, create, upload">
            </div>
            <div class="filter-group">
                <label>User</label>
                <select name="user" class="form-control">
                    <option value="">All Users</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="admin_audit_trail.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="card">
        <div class="card-header">
            <h3>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Audit Log Entries <span class="badge badge-pending"><?= number_format($totalRows) ?> total</span>
            </h3>

            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <button type="button" id="deleteAllAuditLogsBtn" class="btn btn-sm btn-danger" <?php if ($totalRows === 0) echo 'disabled style="opacity:0.5;cursor:not-allowed;" title="No logs to delete"'; ?>>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete All Audit Logs
                </button>
                <!-- Pagination -->
                <div class="pagination-wrapper">
                <?php
                $baseUrl = '?action=' . urlencode($filterAction) . '&user=' . $filterUser;
                if ($page > 1):
                ?>
                    <a href="<?= $baseUrl ?>&page=<?= $page-1 ?>" class="btn btn-sm btn-outline">Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <a href="<?= $baseUrl ?>&page=<?= $p ?>" class="btn btn-sm <?= $p == $page ? 'btn-primary' : 'btn-outline' ?>" style="min-width:32px;justify-content:center;"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= $baseUrl ?>&page=<?= $page+1 ?>" class="btn btn-sm btn-outline">Next</a>
                <?php endif; ?>
            </div>
            </div>
        </div>
        <div class="card-content no-padding">
            <?php if (empty($logs)): ?>
                <div class="empty-state">No audit logs found matching your filters.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log):
                                $action = strtolower($log['action']);
                                $badgeClass = 'badge-pending';
                                if (strpos($action, 'login') !== false || strpos($action, 'approve') !== false || strpos($action, 'upload') !== false) {
                                    $badgeClass = 'badge-approved';
                                } elseif (strpos($action, 'delete') !== false || strpos($action, 'reject') !== false) {
                                    $badgeClass = 'badge-rejected';
                                } elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false) {
                                    $badgeClass = 'badge-pending-ph';
                                } elseif (strpos($action, 'create') !== false) {
                                    $badgeClass = 'badge-submitted';
                                } elseif (empty($log['user_id'])) {
                                    $badgeClass = 'badge-system';
                                }

                                // Role is its own thing: reuse phRoleName() so Program Head and
                                // Unit Head are distinguishable instead of both reading "DEPARTMENT".
                                $roleKey = (string)($log['role'] ?? '');
                                $roleLabel = match ($roleKey) {
                                    '', null => 'System',
                                    'admin' => 'Admin',
                                    'teacher' => 'Teacher',
                                    'staff' => 'Staff',
                                    default => phRoleName($roleKey),
                                };
                                $roleBadge = match ($roleKey) {
                                    '', null => 'badge-system',
                                    'admin' => 'badge-submitted',
                                    'department' => 'badge-approved',
                                    'unit_head' => 'badge-pending-ph',
                                    default => 'badge-pending',
                                };
                                // Description carries the " [ip=...]" suffix appended by logAudit();
                                // show it as its own detail instead of buried in the sentence.
                                $description = (string)($log['description'] ?? '');
                                $logIp = null;
                                if (preg_match('/^(.*?)\s*\[ip=([^\]]+)\]$/', $description, $m)) {
                                    $description = trim($m[1]);
                                    $logIp = $m[2];
                                }
                            ?>
                                <tr>
                                    <td class="text-xs" style="white-space: nowrap;">
                                        <?php if (!empty($log['created_at'])): ?>
                                            <?= date('M j, Y', strtotime($log['created_at'])) ?><br>
                                            <span style="color:var(--gray-600);"><?= date('g:i a', strtotime($log['created_at'])) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Date not recorded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="font-semibold"><?= htmlspecialchars($log['user_name'] ?? '(System)') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $roleBadge ?>"><?= htmlspecialchars($roleLabel) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>"><?= strtoupper($log['action']) ?></span>
                                    </td>
                                    <td style="font-size:0.875rem;line-height:1.4;">
                                        <?= htmlspecialchars($description) ?>
                                        <?php if ($logIp !== null): ?>
                                            <div style="color:var(--gray-600);font-size:0.75rem;margin-top:0.15rem;">IP <?= htmlspecialchars($logIp) ?></div>
                                        <?php endif; ?>
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

<!-- Delete All Audit Logs - Password Confirmation Modal -->
<div id="deleteAuditModal" class="audit-modal" aria-hidden="true" role="dialog" aria-labelledby="deleteAuditModalTitle">
    <div class="audit-modal-overlay" id="deleteAuditModalOverlay"></div>
    <div class="audit-modal-content">
        <h3 id="deleteAuditModalTitle">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Confirm Deletion
        </h3>
        <p class="modal-desc">Please enter your admin password to confirm deletion of all audit logs.</p>
        <form id="deleteAuditForm" method="POST" action="admin_audit_trail.php">
            <?= csrfField() ?>
            <input type="hidden" name="delete_all_audit_logs" value="1">
            <div class="form-group">
                <label for="adminPasswordInput">Admin Password</label>
                <input type="text" name="username" value="<?= sanitize($_SESSION['email'] ?? '') ?>" autocomplete="username" tabindex="-1" hidden>
                <input type="password" id="adminPasswordInput" name="admin_password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
            </div>
            <div id="deleteAuditModalError" class="modal-error" role="alert"></div>
            <div class="modal-actions">
                <button type="button" id="deleteAuditCancelBtn" class="btn btn-secondary btn-sm">Cancel</button>
                <button type="submit" id="deleteAuditConfirmBtn" class="btn btn-danger btn-sm">Continue</button>
            </div>
        </form>
    </div>
</div>

<!-- Final Confirmation Modal - stays on page, does NOT navigate to localhost -->
<div id="deleteAuditFinalModal" class="audit-modal" aria-hidden="true" role="dialog" aria-labelledby="deleteAuditFinalTitle">
    <div class="audit-modal-overlay" id="deleteAuditFinalOverlay"></div>
    <div class="audit-modal-content">
        <h3 id="deleteAuditFinalTitle" style="color:var(--danger);">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:var(--danger);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            Are you sure?
        </h3>
        <p class="modal-desc" style="font-weight:600;color:var(--gray-900);">Are you sure you want to permanently delete all audit logs? This action cannot be undone.</p>
        <div id="deleteAuditFinalError" class="modal-error" role="alert"></div>
        <div class="modal-actions">
            <button type="button" id="deleteAuditFinalCancelBtn" class="btn btn-secondary btn-sm">Cancel</button>
            <button type="button" id="deleteAuditFinalConfirmBtn" class="btn btn-danger btn-sm">Yes, Delete All Logs</button>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('DOMContentLoaded', function() {
    const passwordModal = document.getElementById('deleteAuditModal');
    const finalModal = document.getElementById('deleteAuditFinalModal');
    const openBtn = document.getElementById('deleteAllAuditLogsBtn');
    const overlay = document.getElementById('deleteAuditModalOverlay');
    const finalOverlay = document.getElementById('deleteAuditFinalOverlay');
    const cancelBtn = document.getElementById('deleteAuditCancelBtn');
    const finalCancelBtn = document.getElementById('deleteAuditFinalCancelBtn');
    const finalConfirmBtn = document.getElementById('deleteAuditFinalConfirmBtn');
    const form = document.getElementById('deleteAuditForm');
    const passwordInput = document.getElementById('adminPasswordInput');
    const errorEl = document.getElementById('deleteAuditModalError');
    const finalErrorEl = document.getElementById('deleteAuditFinalError');
    const continueBtn = document.getElementById('deleteAuditConfirmBtn');

    let pendingFormData = null;

    function openPasswordModal(e) {
        if (e) e.preventDefault();
        if (!passwordModal) return;
        if (openBtn && openBtn.hasAttribute('disabled')) return;
        // ensure final modal is hidden
        if (finalModal) { finalModal.classList.remove('show'); finalModal.setAttribute('aria-hidden','true'); }
        passwordModal.classList.add('show');
        passwordModal.setAttribute('aria-hidden', 'false');
        if (errorEl) { errorEl.textContent = ''; errorEl.classList.remove('show'); }
        if (finalErrorEl) { finalErrorEl.textContent = ''; finalErrorEl.classList.remove('show'); }
        if (passwordInput) {
            passwordInput.value = '';
            setTimeout(() => passwordInput.focus(), 50);
        }
        document.body.style.overflow = 'hidden';
    }

    function closePasswordModal() {
        if (!passwordModal) return;
        passwordModal.classList.remove('show');
        passwordModal.setAttribute('aria-hidden', 'true');
        if (!finalModal || !finalModal.classList.contains('show')) {
            document.body.style.overflow = '';
        }
        if (errorEl) { errorEl.textContent = ''; errorEl.classList.remove('show'); }
    }

    function openFinalModal() {
        if (!finalModal) return;
        finalModal.classList.add('show');
        finalModal.setAttribute('aria-hidden', 'false');
        if (finalErrorEl) { finalErrorEl.textContent = ''; finalErrorEl.classList.remove('show'); }
        document.body.style.overflow = 'hidden';
    }

    function closeFinalModal() {
        if (!finalModal) return;
        finalModal.classList.remove('show');
        finalModal.setAttribute('aria-hidden', 'true');
        if (!passwordModal || !passwordModal.classList.contains('show')) {
            document.body.style.overflow = '';
        }
        if (finalErrorEl) { finalErrorEl.textContent = ''; finalErrorEl.classList.remove('show'); }
    }

    function showPasswordError(msg) {
        if (!errorEl) return;
        errorEl.textContent = msg;
        errorEl.classList.add('show');
    }
    function showFinalError(msg) {
        if (!finalErrorEl) return;
        finalErrorEl.textContent = msg;
        finalErrorEl.classList.add('show');
    }

    if (openBtn) {
        openBtn.addEventListener('click', openPasswordModal);
    }
    if (overlay) overlay.addEventListener('click', closePasswordModal);
    if (finalOverlay) finalOverlay.addEventListener('click', closeFinalModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closePasswordModal);
    if (finalCancelBtn) finalCancelBtn.addEventListener('click', function() {
        closeFinalModal();
        // Return to password modal so user can correct password if needed
        // Keep pending data but allow user to retry
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (finalModal && finalModal.classList.contains('show')) {
                closeFinalModal();
            } else if (passwordModal && passwordModal.classList.contains('show')) {
                closePasswordModal();
            }
        }
    });

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const password = passwordInput ? passwordInput.value : '';
            if (!password || !password.trim()) {
                showPasswordError('Please enter your password.');
                if (passwordInput) passwordInput.focus();
                return;
            }
            // Require the currently logged-in Admin to enter and verify their correct account password
            // Verify password server-side BEFORE proceeding to final confirmation
            const csrfToken = form.querySelector('input[name="csrf_token"]')?.value || '';
            const verifyData = new FormData();
            verifyData.append('verify_admin_password', '1');
            verifyData.append('csrf_token', csrfToken);
            verifyData.append('admin_password', password);

            const originalContinueText = continueBtn ? continueBtn.textContent : '';
            if (continueBtn) { continueBtn.disabled = true; continueBtn.textContent = 'Verifying...'; }
            if (errorEl) errorEl.classList.remove('show');

            fetch('admin_audit_trail.php', {
                method: 'POST',
                body: verifyData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json().then(data => ({ok: res.ok, data})))
            .then(({ok, data}) => {
                if (continueBtn) { continueBtn.disabled = false; continueBtn.textContent = originalContinueText; }
                if (data && data.success) {
                    // Password is correct - only then proceed to final confirmation
                    pendingFormData = new FormData(form);
                    closePasswordModal();
                    openFinalModal();
                } else {
                    // If the password is incorrect, cancel the deletion and show an error message
                    const msg = (data && data.error) ? data.error : 'Incorrect password. Audit logs were not deleted.';
                    showPasswordError(msg);
                }
            })
            .catch(() => {
                if (continueBtn) { continueBtn.disabled = false; continueBtn.textContent = originalContinueText; }
                showPasswordError('An unexpected error occurred. Please try again.');
            });
        });
    }

    if (finalConfirmBtn) {
        finalConfirmBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!pendingFormData) {
                showFinalError('Missing password. Please reopen and enter your password again.');
                return;
            }
            const originalText = finalConfirmBtn.textContent;
            finalConfirmBtn.disabled = true;
            finalConfirmBtn.textContent = 'Deleting...';
            if (finalErrorEl) finalErrorEl.classList.remove('show');

            fetch('admin_audit_trail.php', {
                method: 'POST',
                body: pendingFormData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json().then(data => ({ok: res.ok, data})))
            .then(({ok, data}) => {
                if (data && data.success) {
                    closeFinalModal();
                    // After successful deletion, refresh the audit log and show a success message
                    window.location.reload();
                } else {
                    // If incorrect, do not delete anything and show an error
                    const msg = (data && data.error) ? data.error : 'Failed to delete audit logs.';
                    showFinalError(msg);
                    finalConfirmBtn.disabled = false;
                    finalConfirmBtn.textContent = originalText;
                }
            })
            .catch(() => {
                showFinalError('An unexpected error occurred. Please try again.');
                finalConfirmBtn.disabled = false;
                finalConfirmBtn.textContent = originalText;
            });
        });
    }
});
</script>
<?php
$extraScripts = ob_get_clean();
?>

<?php $toastMsg = $auditDeleteSuccess ?? ''; include 'includes/success_toast.php'; ?>
<?php include 'includes/footer.php'; ?>


