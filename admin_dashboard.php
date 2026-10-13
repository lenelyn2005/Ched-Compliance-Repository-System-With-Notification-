<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Live dashboard refresh (30s poll + WS-triggered). Read-only; role-guarded.
if (isAjaxRequest() && ($_POST['action'] ?? '') === 'refresh_dashboard') {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $stats = getAdminDashboardStats($pdo);
    $stats['expiredCount'] = count(getExpiredRequirements($pdo, $adminId ?: null));
    sendJson($stats);
}

// Dismiss expired requirement from admin dashboard (persists to DB)
if (isAjaxRequest() && ($_POST['action'] ?? '') === 'dismiss_expired') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        sendJson(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
    }
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $reqId = (int)($_POST['requirement_id'] ?? 0);
    if (!$adminId || !$reqId) {
        sendJson(['success' => false, 'message' => 'Invalid request.']);
    }
    $check = $pdo->prepare("SELECT id FROM department_requirements WHERE id = ? AND status = 'expired'");
    $check->execute([$reqId]);
    if (!$check->fetch()) {
        sendJson(['success' => false, 'message' => 'Requirement not found or not expired.']);
    }
    $ins = $pdo->prepare("INSERT IGNORE INTO admin_dashboard_dismissals (admin_id, requirement_id) VALUES (?, ?)");
    $ins->execute([$adminId, $reqId]);
    logAudit($pdo, 'dismiss_expired', "Admin dismissed expired requirement #$reqId from dashboard", 'department_requirements', $reqId);
    sendJson(['success' => true]);
}

// ============================================================================
// DATA QUERIES (unchanged)
// ============================================================================

$totalDepts = (int)(db_query("SELECT COUNT(*) FROM departments")[0]['COUNT(*)'] ?? 0);
$totalProgramHeads = (int)(db_query("SELECT COUNT(*) FROM users WHERE role = 'department'")[0]['COUNT(*)'] ?? 0);
$totalUnitHeads = (int)(db_query("SELECT COUNT(*) FROM users WHERE role = 'unit_head'")[0]['COUNT(*)'] ?? 0);
$pendingAdmin = getPendingSubmissionCount($pdo);
$approvedTotal = (int)(db_query("SELECT COUNT(*) FROM documents WHERE status = 'approved_admin'")[0]['COUNT(*)'] ?? 0);
$approvedTotal += (int)(db_query("SELECT COUNT(*) FROM ph_documents WHERE status = 'approved_admin'")[0]['COUNT(*)'] ?? 0);
$rejectedTotal = (int)(db_query("SELECT COUNT(*) FROM documents WHERE status IN ('rejected_admin','rejected_program_head')")[0]['COUNT(*)'] ?? 0);
$rejectedTotal += (int)(db_query("SELECT COUNT(*) FROM ph_documents WHERE status IN ('rejected_admin','rejected_program_head')")[0]['COUNT(*)'] ?? 0);

// Real progress: approved teacher submissions vs assigned tasks per department.
// Denominator = teacher_requirements assigned under this department (not raw
// department_requirements count, not direct PH uploads). Numerator = those with
// latest document version approved_admin (includes archived approved docs,
// matching getTeacherProgress() which has no archived filter).
$depts = db_query("
    WITH req_statuses AS (
        SELECT 
            dr.id,
            dr.department_id,
            CASE
                WHEN approved_req.id IS NOT NULL OR direct_approved.id IS NOT NULL THEN 'complete'
                WHEN correction_req.id IS NOT NULL OR direct_rejected.id IS NOT NULL THEN 'needs-correction'
                WHEN review_req.id IS NOT NULL OR direct_review.id IS NOT NULL THEN 'in-review'
                WHEN pendingph_req.id IS NOT NULL THEN 'pending-ph'
                WHEN NOT EXISTS (SELECT 1 FROM teacher_requirements tr WHERE tr.requirement_id = dr.id)
                     AND direct_approved.id IS NULL AND direct_review.id IS NULL AND direct_rejected.id IS NULL
                     THEN 'not-started'
                ELSE 'pending-ph'
            END as rstatus
        FROM department_requirements dr
        LEFT JOIN (
            SELECT DISTINCT tr.requirement_id as id
            FROM teacher_requirements tr
            INNER JOIN documents d ON d.teacher_requirement_id = tr.id
            WHERE d.status = 'approved_admin'
        ) approved_req ON approved_req.id = dr.id
        LEFT JOIN (
            SELECT DISTINCT dr2.id
            FROM documents d
            INNER JOIN department_requirements dr2 
                ON d.department_id = dr2.department_id 
                AND d.category = dr2.category 
                AND d.document_type = dr2.document_type
            WHERE d.status = 'approved_admin' AND d.teacher_requirement_id IS NULL
        ) direct_approved ON direct_approved.id = dr.id
        LEFT JOIN (
            SELECT DISTINCT tr.requirement_id as id
            FROM teacher_requirements tr
            INNER JOIN documents d ON d.teacher_requirement_id = tr.id
            INNER JOIN (
                SELECT teacher_requirement_id, MAX(version) as max_ver
                FROM documents WHERE teacher_requirement_id IS NOT NULL
                GROUP BY teacher_requirement_id
            ) lm ON d.teacher_requirement_id = lm.teacher_requirement_id AND d.version = lm.max_ver
            WHERE d.status = 'pending_admin'
        ) review_req ON review_req.id = dr.id
        LEFT JOIN (
            SELECT DISTINCT dr2.id
            FROM documents d
            INNER JOIN department_requirements dr2 
                ON d.department_id = dr2.department_id 
                AND d.category = dr2.category 
                AND d.document_type = dr2.document_type
            WHERE d.status IN ('pending_program_head', 'pending_admin') AND d.teacher_requirement_id IS NULL
        ) direct_review ON direct_review.id = dr.id
        LEFT JOIN (
            SELECT DISTINCT tr.requirement_id as id
            FROM teacher_requirements tr
            INNER JOIN documents d ON d.teacher_requirement_id = tr.id
            INNER JOIN (
                SELECT teacher_requirement_id, MAX(version) as max_ver
                FROM documents WHERE teacher_requirement_id IS NOT NULL
                GROUP BY teacher_requirement_id
            ) lm ON d.teacher_requirement_id = lm.teacher_requirement_id AND d.version = lm.max_ver
            WHERE d.status IN ('rejected_admin', 'rejected_program_head')
        ) correction_req ON correction_req.id = dr.id
        LEFT JOIN (
            SELECT DISTINCT dr2.id
            FROM documents d
            INNER JOIN department_requirements dr2 
                ON d.department_id = dr2.department_id 
                AND d.category = dr2.category 
                AND d.document_type = dr2.document_type
            WHERE d.status IN ('rejected_program_head', 'rejected_admin') AND d.teacher_requirement_id IS NULL
        ) direct_rejected ON direct_rejected.id = dr.id
        LEFT JOIN (
            SELECT DISTINCT tr.requirement_id as id
            FROM teacher_requirements tr
            INNER JOIN documents d ON d.teacher_requirement_id = tr.id
            INNER JOIN (
                SELECT teacher_requirement_id, MAX(version) as max_ver
                FROM documents WHERE teacher_requirement_id IS NOT NULL
                GROUP BY teacher_requirement_id
            ) lm ON d.teacher_requirement_id = lm.teacher_requirement_id AND d.version = lm.max_ver
            WHERE d.status = 'pending_program_head'
        ) pendingph_req ON pendingph_req.id = dr.id
    ),
    dept_status AS (
        SELECT 
            rs.department_id,
            SUM(CASE WHEN rs.rstatus = 'complete' THEN 1 ELSE 0 END) as complete_count,
            SUM(CASE WHEN rs.rstatus = 'in-review' THEN 1 ELSE 0 END) as in_review_count,
            SUM(CASE WHEN rs.rstatus = 'pending-ph' THEN 1 ELSE 0 END) as pending_ph_count,
            SUM(CASE WHEN rs.rstatus = 'needs-correction' THEN 1 ELSE 0 END) as correction_count,
            SUM(CASE WHEN rs.rstatus = 'not-started' THEN 1 ELSE 0 END) as not_started_count
        FROM req_statuses rs
        GROUP BY rs.department_id
    )
    SELECT
        d.id,
        d.department_name,
        d.department_code,
        COALESCE(u.ph_count, 0) as ph_count,
        COALESCE(r.total_reqs, 0) as total_reqs,
        COALESCE(ta.total_assigned, 0) as total_assigned,
        COALESCE(ds.complete_count, 0) as complete_count,
        COALESCE(ds.in_review_count, 0) as in_review_count,
        COALESCE(ds.pending_ph_count, 0) as pending_ph_count,
        COALESCE(ds.correction_count, 0) as correction_count,
        COALESCE(ds.not_started_count, 0) as not_started_count
    FROM departments d
    LEFT JOIN (
        SELECT department_id, COUNT(*) as ph_count
        FROM users WHERE role IN ('department','unit_head') GROUP BY department_id
    ) u ON d.id = u.department_id
    LEFT JOIN (
        SELECT department_id, COUNT(*) as total_reqs
        FROM department_requirements GROUP BY department_id
    ) r ON d.id = r.department_id
    LEFT JOIN (
        SELECT dr.department_id, COUNT(*) as total_assigned
        FROM teacher_requirements tr
        INNER JOIN department_requirements dr ON dr.id = tr.requirement_id
        GROUP BY dr.department_id
    ) ta ON d.id = ta.department_id
    LEFT JOIN dept_status ds ON d.id = ds.department_id
    ORDER BY d.department_name
");

autoExpireRequirements($pdo, $_SESSION['user_id'] ?? null);
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$expiredReqs = getExpiredRequirements($pdo, $adminId ?: null);

$reqSqlFull = "SELECT dr.*, d.department_name,
    COUNT(DISTINCT tr.id) as total_assigned,
    COUNT(DISTINCT CASE WHEN docs.status = 'approved_admin' THEN docs.id END) as approved_count,
    COUNT(DISTINCT docs.id) as file_count,
    (SELECT COUNT(*) FROM documents d2
     WHERE d2.department_id = dr.department_id
     AND d2.category = dr.category
     AND d2.document_type = dr.document_type
     AND d2.status = 'approved_admin'
     AND d2.teacher_requirement_id IS NULL
     AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_approved_count,
    (SELECT COUNT(*) FROM documents d2
     WHERE d2.department_id = dr.department_id
     AND d2.category = dr.category
     AND d2.document_type = dr.document_type
     AND d2.status IN ('pending_program_head', 'pending_admin')
     AND d2.teacher_requirement_id IS NULL
     AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_pending_count,
    (SELECT COUNT(*) FROM documents d2
     WHERE d2.department_id = dr.department_id
     AND d2.category = dr.category
     AND d2.document_type = dr.document_type
     AND d2.status IN ('rejected_program_head', 'rejected_admin')
     AND d2.teacher_requirement_id IS NULL
     AND (dr.cycle_started_at IS NULL OR d2.upload_date >= dr.cycle_started_at)) as direct_rejected_count,
    (SELECT GROUP_CONCAT(latest.status) FROM (
        SELECT d3.status, d3.teacher_requirement_id, d3.upload_date
        FROM documents d3
        INNER JOIN (
            SELECT teacher_requirement_id, MAX(version) AS max_ver
            FROM documents
            WHERE teacher_requirement_id IS NOT NULL
            GROUP BY teacher_requirement_id
        ) lm ON d3.teacher_requirement_id = lm.teacher_requirement_id AND d3.version = lm.max_ver
    ) latest
    WHERE latest.teacher_requirement_id IN (SELECT tr2.id FROM teacher_requirements tr2 WHERE tr2.requirement_id = dr.id)
      AND (dr.cycle_started_at IS NULL OR latest.upload_date >= dr.cycle_started_at)) as latest_statuses
FROM department_requirements dr
LEFT JOIN departments d ON dr.department_id = d.id
LEFT JOIN teacher_requirements tr ON tr.requirement_id = dr.id
LEFT JOIN documents docs ON docs.teacher_requirement_id = tr.id
    AND (dr.cycle_started_at IS NULL OR docs.upload_date >= dr.cycle_started_at)
GROUP BY dr.id, d.department_name
ORDER BY dr.id DESC";
$allReqs = $pdo->query($reqSqlFull)->fetchAll();
$totalAllReqs = count($allReqs);

$flashMsg = $_GET['msg'] ?? '';
$flashError = $_GET['error'] ?? '';

$pageTitle = 'Admin Dashboard';

// SVG icon helper (same set as program_head_dashboard.php — UI only)
if (!function_exists('render_dashboard_icon')) {
    function render_dashboard_icon(string $name): string {
        $icons = [
            'building' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
            'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'clock' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'check' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'alert' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
            'chevron-up' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>',
            'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" x2="19" y1="12" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
            'pen' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
            'x' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
            'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>',
            'folder' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>',
            'chart' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>',
            'inbox' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}

// ============================================================================
// MODERN UI STYLES
// ============================================================================

ob_start();
?>
<style>
    @import url('offline/inter-font.css');

    :root {
        /* Color Palette — same as program_head_dashboard */
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --primary-light: #eff6ff;
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
        --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        font-family: 'Inter', sans-serif;
        color: var(--gray-800);
        font-size: 0.9375rem;
        line-height: 1.5;
    }

    /* Page title — refined hierarchy */
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

    .all-reqs th {
        position: sticky;
        top: 0;
        z-index: 1;
        box-shadow: inset 0 -1px 0 var(--border); /* collapse borders drop on sticky th */
    }

    .req-scroll {
        scrollbar-width: thin;
        scrollbar-color: var(--gray-300) transparent;
    }
    .req-scroll::-webkit-scrollbar { width: 8px; }
    .req-scroll::-webkit-scrollbar-track { background: var(--gray-100); }
    .req-scroll::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }
    .req-scroll::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }


    /* Flash alerts — refined */
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
        box-shadow: var(--shadow-xs);
        animation: alertIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes alertIn {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .alert svg {
        flex-shrink: 0;
    }

    .alert .alert-text {
        flex: 1;
        min-width: 0;
    }

    .alert .alert-close {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1.25rem;
        line-height: 1;
        color: inherit;
        opacity: 0.55;
        padding: 0 0.25rem;
        border-radius: 6px;
    }

    .alert .alert-close:hover {
        opacity: 1;
    }

    .alert-success {
        background: #ecfdf5;
        color: #047857;
        border-left-color: var(--success);
    }

    .alert-danger {
        background: #fef2f2;
        color: #b91c1c;
        border-left-color: var(--danger);
    }

    /* Stats Grid — refined KPI cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 0.75rem;
    }

    .stat-card {
        background: var(--white);
        border-radius: 12px;
        padding: 1rem 0.85rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: var(--shadow-xs);
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid var(--border);
        position: relative;
        overflow: hidden;
    }

    .stat-card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--stat-color, var(--primary));
        opacity: 0.8;
        transition: opacity 0.2s ease;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        border-color: color-mix(in srgb, var(--stat-color) 30%, var(--border));
    }

    .stat-card:hover::after {
        opacity: 1;
    }

    .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: var(--stat-bg, #f3f4f6);
        color: var(--stat-color, #3b82f6);
        transition: transform 0.2s ease;
    }

    .stat-icon svg {
        width: 20px;
        height: 20px;
    }

    .stat-card:hover .stat-icon {
        transform: scale(1.08);
    }

    .stat-info h3 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gray-900);
        line-height: 1;
        margin: 0 0 0.2rem 0;
        letter-spacing: -0.02em;
    }

    .stat-info p {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--gray-500);
        margin: 0;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .stat-card.department { --stat-color: #3b82f6; --stat-bg: #dbeafe; }
    .stat-card.head { --stat-color: #8b5cf6; --stat-bg: #ede9fe; }
    .stat-card.unit-head { --stat-color: #0ea5e9; --stat-bg: #e0f2fe; }
    .stat-card.pending { --stat-color: #f59e0b; --stat-bg: #fef3c7; }
    .stat-card.approved { --stat-color: #22c55e; --stat-bg: #dcfce7; }
    .stat-card.rejected { --stat-color: #ef4444; --stat-bg: #fee2e2; }

    /* ============================================================
       Expired Requirements — redesigned
       Cleaner cards, aligned rows, readable hierarchy.
       Responsive across desktop / tablet / mobile.
       All JS hooks preserved (class names unchanged).
       ============================================================ */
    .expired-section {
        background: var(--white);
        border: 1px solid #fecaca;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: var(--shadow-xs);
    }

    /* ---- Topbar ---- */
    .expired-topbar {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 1rem 1.15rem;
        background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
        border-bottom: 1px solid #fecaca;
    }

    .expired-topbar .expired-icon {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        flex-shrink: 0;
        box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25);
    }
    .expired-topbar .expired-icon svg { width: 20px; height: 20px; }

    .expired-topbar .expired-meta {
        display: flex;
        flex-direction: column;
        min-width: 0;
        gap: 0.1rem;
        flex: 1;
    }
    .expired-topbar .expired-meta h3 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.015em;
        line-height: 1.25;
    }
    .expired-topbar .expired-meta p {
        margin: 0;
        font-size: 0.75rem;
        color: var(--gray-500);
        font-weight: 400;
        line-height: 1.3;
    }

    .expired-topbar .expired-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 26px;
        padding: 0 0.6rem;
        background: var(--danger);
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        border-radius: 9999px;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
        font-variant-numeric: tabular-nums;
        flex-shrink: 0;
    }

    .expired-topbar .expired-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .expired-toggle-btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--white);
        border: 1px solid var(--gray-200);
        border-radius: 9px;
        color: var(--gray-500);
        cursor: pointer;
        transition: all 0.15s;
        padding: 0;
        box-shadow: var(--shadow-xs);
    }
    .expired-toggle-btn:hover { background: var(--gray-50); color: var(--gray-700); border-color: var(--gray-300); }
    .expired-toggle-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .expired-toggle-btn svg { width: 16px; height: 16px; transition: transform 0.25s ease; }
    .expired-section.collapsed .expired-toggle-btn svg { transform: rotate(180deg); }

    .expired-body {
        max-height: 8000px;
        opacity: 1;
        transition: max-height 0.35s ease, opacity 0.25s ease;
        overflow: hidden;
    }
    .expired-section.collapsed .expired-body { max-height: 0; opacity: 0; }

    /* ---- Department group ---- */
    .expired-dept-group { border-bottom: 1px solid var(--gray-100); }
    .expired-dept-group:last-child { border-bottom: none; }

    .expired-dept-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 160px 130px;
        gap: 1rem;
        align-items: center;
        padding: 0.5rem 1.15rem 0.5rem 2.75rem;
        background: var(--gray-50);
        user-select: none;
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--gray-400);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        border-bottom: 1px solid var(--gray-200);
    }

    .expired-dept-header .dept-icon-wrap {
        display: none;
    }

    .expired-dept-header .dept-code {
        grid-column: 1;
        font-size: 0.8125rem;
        font-weight: 700;
        color: var(--gray-900);
        text-transform: none;
        letter-spacing: -0.01em;
    }

    .expired-dept-header .dept-col-date {
        grid-column: 2;
        text-align: right;
    }

    .expired-dept-header .dept-col-actions {
        grid-column: 3;
        text-align: right;
    }

    .expired-dept-header .dept-count {
        position: absolute;
        right: 3.5rem;
        font-size: 0.65rem;
        font-weight: 700;
        color: var(--danger);
        background: var(--danger-light);
        border: 1px solid #fecaca;
        border-radius: 9999px;
        padding: 0.05rem 0.45rem;
        line-height: 1.4;
    }

    .expired-dept-header .dept-chevron {
        position: absolute;
        right: 1.15rem;
        color: var(--gray-400);
        transition: transform 0.2s ease;
        display: inline-flex;
        align-items: center;
    }

    .expired-dept-header .dept-chevron svg { width: 14px; height: 14px; }
    .expired-dept-group.collapsed .dept-chevron { transform: rotate(-90deg); }

    .expired-dept-items { display: flex; flex-direction: column; }
    .expired-dept-group.collapsed .expired-dept-items { display: none; }

    /* ---- Item row ---- */
    .expired-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 160px 130px;
        gap: 1rem;
        align-items: center;
        padding: 0.85rem 1.15rem 0.85rem 2.75rem;
        border-top: 1px solid var(--gray-100);
        transition: background 0.15s;
        position: relative;
    }
    .expired-item:first-of-type { border-top: none; }
    .expired-item::before {
        content: '';
        position: absolute;
        left: 1.15rem;
        top: 50%;
        transform: translateY(-50%);
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--danger);
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12);
    }
    .expired-item:hover { background: #fef8f8; }

    .expired-item .item-info {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        min-width: 0;
    }
    .expired-item .item-doctype {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gray-900);
        line-height: 1.35;
        overflow-wrap: break-word;
    }
    .expired-item .item-category {
        font-size: 0.75rem;
        color: var(--gray-500);
        line-height: 1.35;
        overflow-wrap: break-word;
    }

    .expired-item .item-date {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        white-space: nowrap;
        gap: 0.25rem;
    }
    .expired-item .item-date .date {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-weight: 700;
        font-size: 0.78rem;
        color: #b91c1c;
        background: var(--danger-light);
        border: 1px solid #fecaca;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-variant-numeric: tabular-nums;
        line-height: 1.3;
    }
    .expired-item .item-date .time-ago {
        font-size: 0.7rem;
        color: var(--gray-500);
        font-variant-numeric: tabular-nums;
    }

    .expired-item .item-actions {
        display: flex;
        gap: 0.4rem;
        justify-content: flex-end;
        align-items: center;
    }

    .expired-item .btn-sm {
        padding: 0.4rem 0.75rem;
        font-size: 0.78rem;
        border-radius: 7px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.15s;
        cursor: pointer;
        white-space: nowrap;
        border: 1px solid transparent;
        line-height: 1.2;
        font-family: inherit;
    }
    .expired-item .btn-sm svg { width: 13px; height: 13px; }
    .expired-item .btn-edit {
        background: var(--primary);
        color: #fff;
        box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);
    }
    .expired-item .btn-edit:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
    }
    .expired-item .btn-dismiss {
        background: var(--white);
        color: var(--gray-500);
        border-color: var(--gray-200);
        width: 32px;
        padding: 0;
        height: 32px;
    }
    .expired-item .btn-dismiss:hover {
        background: var(--danger-light);
        color: var(--danger);
        border-color: #fecaca;
    }

    /* ---- Tablet (≤ 900px) ---- */
    @media (max-width: 900px) {
        .expired-dept-header .dept-col-date,
        .expired-dept-header .dept-col-actions { display: none; }

        .expired-dept-header {
            grid-template-columns: 1fr auto auto;
        }

        .expired-item {
            grid-template-columns: minmax(0, 1fr) auto;
            grid-template-areas:
                "info actions"
                "date actions";
            gap: 0.4rem 1rem;
            padding: 0.85rem 1.15rem 0.85rem 2.5rem;
            align-items: center;
        }
        .expired-item::before { left: 1rem; }
        .expired-item .item-info { grid-area: info; }
        .expired-item .item-date {
            grid-area: date;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 0.5rem;
        }
        .expired-item .item-actions {
            grid-area: actions;
            align-self: center;
        }
    }

    /* ---- Mobile (≤ 560px) ---- */
    @media (max-width: 560px) {
        .expired-topbar { padding: 0.85rem 0.95rem; gap: 0.6rem; }
        .expired-topbar .expired-icon { width: 36px; height: 36px; border-radius: 10px; }
        .expired-topbar .expired-icon svg { width: 18px; height: 18px; }
        .expired-topbar .expired-meta h3 { font-size: 0.875rem; }
        .expired-topbar .expired-meta p { font-size: 0.7rem; }

        .expired-dept-header { padding: 0.65rem 0.95rem; gap: 0.55rem; }

        .expired-item {
            grid-template-columns: 1fr;
            grid-template-areas:
                "info"
                "date"
                "actions";
            gap: 0.6rem;
            padding: 0.9rem 0.95rem 0.9rem 2.4rem;
        }
        .expired-item::before { left: 0.95rem; }
        .expired-item .item-date {
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            gap: 0.5rem;
        }
        .expired-item .item-actions {
            justify-content: flex-start;
            align-self: auto;
        }
    }

    /* Cards — clean container style with refined shadows */
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

    .card-plain {
        background: transparent;
        border: none;
        box-shadow: none;
    }

    .card-plain:hover {
        box-shadow: none;
    }

    .card-plain .card-header {
        background: transparent;
        border-bottom: none;
        padding: 0 0.25rem 0.75rem;
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

    .card-content {
        padding: 0;
    }

    .card-content.padded {
        padding: 1.25rem;
    }

    /* Overview layout — same 2-col collapse as program_head_dashboard */
    .overview-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
    }

    /* Buttons — refined with subtle elevation */
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
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
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
        padding: 0.5rem 1rem;
        font-size: 0.8125rem;
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

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-success:hover {
        background: #0e9f6e;
        transform: translateY(-1px);
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
    }

    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
        border-color: var(--gray-200);
    }

    .btn-secondary:hover {
        background: var(--gray-200);
    }

    .btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Tables — refined, scannable */
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
        padding: 0.7rem 1.25rem;
        font-size: 0.7rem;
        font-weight: 650;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: var(--gray-500);
        background: #f8fafc;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .data-table td {
        padding: 0.8rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
        color: var(--gray-700);
        transition: background 0.12s ease;
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    .data-table tbody tr {
        transition: background 0.12s ease;
    }

    .data-table tbody tr:hover td {
        background: #f8fafc;
    }

    .data-table tbody tr:active td {
        background: #f1f5f9;
    }

    .cell-title {
        font-weight: 600;
        color: var(--gray-900);
        font-size: 0.875rem;
        line-height: 1.35;
    }

    /* Badges — same quiet style as program_head_dashboard */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.6rem;
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

    .badge-complete {
        background: #ecfdf5;
        color: #047857;
    }

    .badge-inreview {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .badge-pendingph {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-correction {
        background: #fef2f2;
        color: #b91c1c;
    }

    .badge-notstarted {
        background: #f3f4f6;
        color: #6b7280;
    }

    .badge-secondary {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    /* Progress Bar */
    .progress-bar {
        background: var(--gray-100);
        border-radius: 9999px;
        height: 8px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        border-radius: 9999px;
        transition: width 0.3s ease;
    }

    .progress-fill.green {
        background: var(--success);
    }

    .progress-fill.yellow {
        background: var(--warning);
    }

    .progress-fill.red {
        background: var(--danger);
    }

    /* Stacked Progress Bar */
    .progress-stack {
        display: flex;
        width: 100%;
        height: 10px;
        border-radius: 9999px;
        overflow: hidden;
        background: var(--gray-100);
    }
    .progress-stack span {
        height: 100%;
        transition: width 0.3s ease;
    }
    .progress-stack .seg-complete { background: var(--success); }
    .progress-stack .seg-inreview { background: var(--primary); }
    .progress-stack .seg-pendingph { background: var(--warning); }
    .progress-stack .seg-notstarted { background: var(--gray-300); }
    .progress-stack .seg-correction { background: var(--danger); }

    .completion-banner {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-weight: 700;
        font-size: 0.85rem;
        border-radius: 9999px;
        padding: 0.25rem 0.75rem;
        white-space: nowrap;
    }
    .completion-banner.banner-green { background: var(--success-light); color: #0b6b4c; }
    .completion-banner.banner-yellow { background: var(--warning-light); color: #b45309; }
    .completion-banner.banner-red { background: var(--danger-light); color: #b91c1c; }

    .dept-progress-cell {
        min-width: 260px;
    }

    /* Utility classes */
    .flex { display: flex; }
    .flex-1 { flex: 1; }
    .items-center { align-items: center; }
    .gap-2 { gap: 0.5rem; }
    .flex-wrap { flex-wrap: wrap; }

    /* Department row enhancements */
    .dept-progress-row {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .dept-name {
        font-weight: 700;
        color: var(--gray-900);
    }

    .dept-stats {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .dept-stat-item {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .dept-stat-item.approved {
        background: var(--success-light);
        color: #0b6b4c;
    }

    .dept-stat-item.pending {
        background: var(--warning-light);
        color: #b45309;
    }

    .dept-stat-item.rejected {
        background: var(--danger-light);
        color: #b91c1c;
    }

    /* Quick Actions — refined white list cards */
    .quick-actions-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.625rem;
        width: 100%;
    }

    .action-card {
        position: relative;
        background: var(--white);
        border-radius: 12px;
        padding: 0.8rem 0.9rem;
        color: var(--gray-800);
        text-decoration: none;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-xs);
        font-weight: 400;
    }

    .action-card:hover {
        border-color: var(--gray-300);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        transform: translateY(-1px);
    }

    .action-card:active {
        transform: translateY(0);
    }

    .action-card .action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        flex-shrink: 0;
        background: var(--gray-100);
        color: var(--gray-600);
        transition: transform 0.2s ease;
    }

    .action-card:hover .action-icon {
        transform: scale(1.06);
    }

    .action-card .action-icon svg {
        width: 18px;
        height: 18px;
        display: block;
    }

    .action-card[data-action="users"] .action-icon { background: #eff6ff; color: #1d4ed8; }
    .action-card[data-action="requirements"] .action-icon { background: #f5f3ff; color: #6d28d9; }
    .action-card[data-action="review"] .action-icon { background: #fef3c7; color: #b45309; }
    .action-card[data-action="repository"] .action-icon { background: #ecfdf5; color: #047857; }
    .action-card[data-action="analytics"] .action-icon { background: #f1f5f9; color: #475569; }

    .action-card .action-body {
        display: flex;
        flex-direction: column;
        min-width: 0;
        flex: 1;
    }

    .action-card .action-title {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gray-900);
        line-height: 1.25;
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }

    .action-card .action-desc {
        font-size: 0.78rem;
        color: var(--gray-500);
        line-height: 1.35;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .action-card .action-arrow {
        display: inline-flex;
        flex-shrink: 0;
        color: var(--gray-300);
        transition: transform 0.15s ease, color 0.15s ease;
    }

    .action-card:hover .action-arrow {
        transform: translateX(2px);
        color: var(--gray-500);
    }

    .action-card .count-badge {
        background: var(--danger);
        color: white;
        border-radius: 9999px;
        padding: 0.1rem 0.45rem;
        font-size: 0.6875rem;
        font-weight: 700;
        min-width: 1.25rem;
        text-align: center;
        line-height: 1.3;
    }

    /* Empty State — same as program_head_dashboard */
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

    /* Modals — same as program_head_dashboard */
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
        border-radius: 12px;
        width: 100%;
        max-width: 480px;
        box-shadow: var(--shadow-lg);
        overflow: hidden;
    }

    .modal-header {
        padding: 0.9rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 650;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        letter-spacing: -0.01em;
    }

    .modal-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--danger);
    }

    .modal-body {
        padding: 1.25rem;
    }

    .close-btn {
        background: none;
        border: none;
        font-size: 1.4rem;
        cursor: pointer;
        color: var(--gray-400);
        transition: var(--transition);
        line-height: 1;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
    }

    .close-btn:hover {
        color: var(--gray-600);
        background: var(--gray-100);
    }

    .close-btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Form Elements — refined */
    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.4rem;
        font-weight: 600;
        font-size: 0.82rem;
        color: var(--gray-700);
        letter-spacing: -0.01em;
    }

    .form-control {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: 0.875rem;
        color: var(--gray-800);
        background: var(--white);
        box-sizing: border-box;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .form-control:hover {
        border-color: var(--gray-300);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    textarea.form-control {
        resize: vertical;
    }

    .form-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 1rem;
    }

    /* Responsive Adjustments — same breakpoints as program_head_dashboard */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 992px) {
        .overview-grid {
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

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem 1rem;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
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
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="dashboard-container">



    <!-- Flash Messages -->

    <?php if ($flashError): ?>
        <div class="alert alert-danger" role="alert">
            <?= render_dashboard_icon('x-circle') ?>
            <span class="alert-text"><?= htmlspecialchars($flashError) ?></span>
            <button type="button" class="alert-close" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card department" title="Total departments">
            <div class="stat-icon"><?= render_dashboard_icon('building') ?></div>
            <div class="stat-info">
                <h3><?= $totalDepts ?></h3>
                <p>Departments</p>
            </div>
        </div>
        <div class="stat-card head" title="Total program heads">
            <div class="stat-icon"><?= render_dashboard_icon('users') ?></div>
            <div class="stat-info">
                <h3><?= $totalProgramHeads ?></h3>
                <p><?= sanitize(phRoleName('department')) ?></p>
            </div>
        </div>
        <div class="stat-card unit-head" title="Total unit heads">
            <div class="stat-icon"><?= render_dashboard_icon('users') ?></div>
            <div class="stat-info">
                <h3><?= $totalUnitHeads ?></h3>
                <p><?= sanitize(phRoleName('unit_head')) ?></p>
            </div>
        </div>
        <div class="stat-card pending" title="Submissions awaiting admin review">
            <div class="stat-icon"><?= render_dashboard_icon('clock') ?></div>
            <div class="stat-info">
                <h3><?= $pendingAdmin ?></h3>
                <p>Pending Admin</p>
            </div>
        </div>
        <div class="stat-card approved" title="Approved documents">
            <div class="stat-icon"><?= render_dashboard_icon('check') ?></div>
            <div class="stat-info">
                <h3><?= $approvedTotal ?></h3>
                <p>Approved</p>
            </div>
        </div>
        <div class="stat-card rejected" title="Rejected documents">
            <div class="stat-icon"><?= render_dashboard_icon('x-circle') ?></div>
            <div class="stat-info">
                <h3><?= $rejectedTotal ?></h3>
                <p>Rejected</p>
            </div>
        </div>
    </div>

    <!-- Expired Requirements — redesigned -->
    <?php if (!empty($expiredReqs)):
        $grouped = [];
        foreach ($expiredReqs as $eReq) {
            $key = $eReq['department_code'] ?: $eReq['department_name'];
            $grouped[$key][] = $eReq;
        }
        ksort($grouped);
    ?>
        <div class="expired-section" id="expiredSection">
            <div class="expired-topbar">
                <div class="expired-icon">
                    <?= render_dashboard_icon('alert') ?>
                </div>
                <div class="expired-meta">
                    <h3>Expired Requirements</h3>
                    <p>Deadlines that have passed &mdash; review or reactivate</p>
                </div>
                <span class="expired-badge" id="expiredCount"><?= count($expiredReqs) ?></span>
                <div class="expired-actions">
                    <button type="button" class="expired-toggle-btn" id="expiredToggleBtn" aria-expanded="true" aria-controls="expiredBody" aria-label="Toggle expired requirements">
                        <?= render_dashboard_icon('chevron-up') ?>
                    </button>
                </div>
            </div>
            <div class="expired-body" id="expiredBody">
                <?php foreach ($grouped as $deptCode => $items):
                    $deptName = $items[0]['department_name'];
                ?>
                    <div class="expired-dept-group" data-dept="<?= htmlspecialchars($deptCode) ?>">
                        <div class="expired-dept-header">
                            <span class="dept-icon-wrap"><?= render_dashboard_icon('building') ?></span>
                            <span class="dept-code"><?= htmlspecialchars($deptCode) ?> Requirement</span>
                            <span class="dept-col-date">Expired Date</span>
                            <span class="dept-col-actions">Actions</span>
                        </div>
                        <div class="expired-dept-items">
                            <?php foreach ($items as $eReq): ?>
                                <div class="expired-item" data-id="<?= (int)$eReq['id'] ?>">
                                    <div class="item-info">
                                        <span class="item-doctype"><?= sanitize($eReq['document_type']) ?></span>
                                        <span class="item-category"><?= sanitize($eReq['category']) ?></span>
                                    </div>
                                    <div class="item-date">
                                        <span class="date"><?= date('M j, Y', strtotime($eReq['expiration_date'])) ?></span>
                                        <span class="time-ago"><?= timeAgo($eReq['expiration_date']) ?></span>
                                    </div>
                                    <div class="item-actions">
                                        <a href="admin_department_requirements.php?edit=<?= $eReq['id'] ?>&dept=<?= $eReq['department_id'] ?>" class="btn-sm btn-edit">
                                            <?= render_dashboard_icon('pen') ?> Edit
                                        </a>
                                        <button type="button" class="btn-sm btn-dismiss cancel-expired" title="Dismiss" aria-label="Dismiss">
                                            <?= render_dashboard_icon('x') ?> 
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Overview Grid -->
    <div class="overview-grid">
        <!-- Department Progress Table -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('chart') ?> Department Progress</h3>
                <a href="admin_manage_departments.php" class="btn btn-sm btn-outline">Manage</a>
            </div>
            <div class="card-content">
                <?php if (empty($depts)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?= render_dashboard_icon('building') ?></div>
                        <p>No departments configured yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Progress</th>
                                    <th>Status Breakdown</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($depts as $dept):
                                    $totalReqs = (int)($dept['total_reqs'] ?? 0);
                                    $complete = (int)($dept['complete_count'] ?? 0);
                                    $inReview = (int)($dept['in_review_count'] ?? 0);
                                    $pendingPH = (int)($dept['pending_ph_count'] ?? 0);
                                    $correction = (int)($dept['correction_count'] ?? 0);
                                    $notStarted = (int)($dept['not_started_count'] ?? 0);
                                    // Progress based on complete count
                                    $pct = $totalReqs > 0 ? (int)round(($complete / $totalReqs) * 100) : 0;
                                    $pct = max(0, min(100, $pct));

                                    $segComplete = $totalReqs > 0 ? round($complete / $totalReqs * 100) : 0;
                                    $segInReview = $totalReqs > 0 ? round($inReview / $totalReqs * 100) : 0;
                                    $segPendingPH = $totalReqs > 0 ? round($pendingPH / $totalReqs * 100) : 0;
                                    $segCorrection = $totalReqs > 0 ? round($correction / $totalReqs * 100) : 0;
                                    $segNotStarted = $totalReqs > 0 ? round($notStarted / $totalReqs * 100) : 0;

                                    if ($totalReqs === 0) {
                                        $bannerClass = 'banner-yellow';
                                        $bannerLabel = 'No requirements yet';
                                    } elseif ($pct >= 80) {
                                        $bannerClass = 'banner-green';
                                        $bannerLabel = $pct . '% Complete';
                                    } elseif ($pct >= 50) {
                                        $bannerClass = 'banner-yellow';
                                        $bannerLabel = $pct . '% Complete';
                                    } else {
                                        $bannerClass = 'banner-red';
                                        $bannerLabel = $pct . '% Complete';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="dept-progress-row">
                                                <div class="dept-name"><?= sanitize($dept['department_code'] ?: $dept['department_name']) ?></div>

                                            </div>
                                        </td>
                                        <td>
                                            <div class="dept-progress-cell">
                                                <div class="flex items-center gap-2" style="margin-bottom:0.6rem;">
                                                    <div class="progress-stack flex-1" title="<?= $complete ?> complete · <?= $inReview ?> in review · <?= $pendingPH ?> pending PH · <?= $notStarted ?> not started · <?= $correction ?> needs correction">
                                                         <span class="seg-complete" style="width: <?= $segComplete ?>%"></span>
                                                         <span class="seg-inreview" style="width: <?= $segInReview ?>%"></span>
                                                         <span class="seg-pendingph" style="width: <?= $segPendingPH ?>%"></span>
                                                         <span class="seg-notstarted" style="width: <?= $segNotStarted ?>%"></span>
                                                         <span class="seg-correction" style="width: <?= $segCorrection ?>%"></span>
                                                     </div>
                                                    <span class="completion-banner <?= $bannerClass ?>"><?= $bannerLabel ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="flex gap-2 flex-wrap">
                                                 <?php if ($complete > 0): ?><span class="badge badge-complete"><?= $complete ?> complete</span><?php endif; ?>
                                                 <?php if ($inReview > 0): ?><span class="badge badge-inreview"><?= $inReview ?> in review</span><?php endif; ?>
                                                 <?php if ($pendingPH > 0): ?><span class="badge badge-pendingph"><?= $pendingPH ?> pending PH</span><?php endif; ?>
                                                 <?php if ($notStarted > 0): ?><span class="badge badge-notstarted"><?= $notStarted ?> not started</span><?php endif; ?>
                                                 <?php if ($correction > 0): ?><span class="badge badge-correction"><?= $correction ?> needs correction</span><?php endif; ?>
                                                 <?php if ($totalReqs === 0): ?><span class="badge badge-secondary">No data</span><?php endif; ?>
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

        <!-- Quick Actions -->
        <div class="card card-plain">
            <div class="card-header">
                <h3><?= render_dashboard_icon('clipboard') ?> Quick Actions</h3>
            </div>
            <div class="quick-actions-grid">
                <a href="admin_manage_users.php" class="action-card" data-action="users">
                    <div class="action-icon"><?= render_dashboard_icon('users') ?></div>
                    <div class="action-body">
                        <div class="action-title">Manage Users</div>
                        <div class="action-desc">Control accounts &amp; roles</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>
                <a href="admin_department_requirements.php" class="action-card" data-action="requirements">
                    <div class="action-icon"><?= render_dashboard_icon('clipboard') ?></div>
                    <div class="action-body">
                        <div class="action-title">Define Requirements</div>
                        <div class="action-desc">Set department requirements</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>
                <a href="admin_submissions.php" class="action-card" data-action="review">
                    <div class="action-icon"><?= render_dashboard_icon('inbox') ?></div>
                    <div class="action-body">
                        <div class="action-title">Review Submissions <?php $count = $pendingAdmin ?? 0; ?><?php if ($count > 0): ?><span class="count-badge"><?= $count ?></span><?php endif; ?></div>
                        <div class="action-desc">Approve or reject pending items</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>
                <a href="admin_manage_document.php" class="action-card" data-action="repository">
                    <div class="action-icon"><?= render_dashboard_icon('folder') ?></div>
                    <div class="action-body">
                        <div class="action-title">Document Repository</div>
                        <div class="action-desc">Browse approved documents</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>
                <a href="admin_analytics.php" class="action-card" data-action="analytics">
                    <div class="action-icon"><?= render_dashboard_icon('chart') ?></div>
                    <div class="action-body">
                        <div class="action-title">Analytics &amp; Reports</div>
                        <div class="action-desc">Insights &amp; exports</div>
                    </div>
                    <span class="action-arrow"><?= render_dashboard_icon('arrow-right') ?></span>
                </a>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:1.25rem;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; padding:0.875rem 1.25rem;">
            <h3 style="margin:0; font-size:0.95rem; font-weight:650; color:var(--gray-900); display:flex; align-items:center; gap:0.55rem; letter-spacing:-0.01em;">
                <?= render_dashboard_icon('folder') ?> All Requirements
                <span style="font-size:0.7rem; color:var(--gray-500); font-weight:500; background:var(--gray-100); padding:0.15rem 0.5rem; border-radius:9999px; border:1px solid var(--border); margin-left:0.35rem;"><?= $totalAllReqs ?></span>
            </h3>
        </div>
        <div class="card-content" style="padding:0;">
            <div class="table-responsive req-scroll" style="max-height:26rem; overflow-y:auto;">
                <table class="data-table all-reqs" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Document Type</th>
                            <th>Deadline</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allReqs)): ?>
                        <tr><td colspan="4" style="padding:2rem 1.25rem; text-align:center; color:var(--gray-500);">No requirements found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($allReqs as $r):
                            $totalAssigned = (int)($r['total_assigned'] ?? 0);
                            $directApprovedCount = (int)($r['direct_approved_count'] ?? 0);
                            $directPendingCount = (int)($r['direct_pending_count'] ?? 0);
                            $directRejectedCount = (int)($r['direct_rejected_count'] ?? 0);
                            $latestStatuses = array_filter(array_map('trim', explode(',', (string)($r['latest_statuses'] ?? ''))));

                            $allApproved = !empty($latestStatuses)
                                && !in_array('pending_program_head', $latestStatuses, true)
                                && !in_array('pending_admin', $latestStatuses, true)
                                && !in_array('rejected_program_head', $latestStatuses, true)
                                && !in_array('rejected_admin', $latestStatuses, true);
                            $allAssignedApproved = ($totalAssigned > 0) && count($latestStatuses) >= $totalAssigned && $allApproved && $directPendingCount === 0 && $directRejectedCount === 0;
                            $directOnlyApproved = ($totalAssigned === 0) && $directApprovedCount > 0 && $directPendingCount === 0 && $directRejectedCount === 0;
                            $hasAnyApproved = $directApprovedCount > 0 || (!empty($latestStatuses) && in_array('approved_admin', $latestStatuses, true));

                            $statusDelivery = null;
                            if ($hasAnyApproved) { $statusDelivery = 'complete'; }
                            elseif ($allAssignedApproved || $directOnlyApproved) { $statusDelivery = 'complete'; }
                            elseif ($directRejectedCount > 0 || in_array('rejected_admin', $latestStatuses, true) || in_array('rejected_program_head', $latestStatuses, true)) { $statusDelivery = 'correction'; }
                            elseif ($directPendingCount > 0 || in_array('pending_admin', $latestStatuses, true) || in_array('pending_program_head', $latestStatuses, true)) {
                                $statusDelivery = (in_array('pending_admin', $latestStatuses, true) || $directPendingCount > 0) ? 'review' : 'pending-ph';
                            } else {
                                $freshCycle = !empty($r['cycle_started_at']);
                                $statusDelivery = (!$freshCycle && ($totalAssigned > 0 || !empty($latestStatuses) || ($r['file_count'] ?? 0) > 0)) ? 'pending-ph' : 'not-started';
                            }

                            $badgeConfig = [
                                'complete' => ['label'=>'Complete','class'=>'badge-complete','style'=>'font-size:0.72rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:9999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;'],
                                'review' => ['label'=>'In Review','class'=>'badge-inreview','style'=>'font-size:0.72rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:9999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;'],
                                'pending-ph' => ['label'=>'Pending PH','class'=>'badge-pendingph','style'=>'font-size:0.72rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:9999px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;'],
                                'correction' => ['label'=>'Needs Correction','class'=>'badge-correction','style'=>'font-size:0.72rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:9999px;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;'],
                                'not-started' => ['label'=>'Not Started','class'=>'badge-notstarted','style'=>'font-size:0.72rem;font-weight:600;padding:0.2rem 0.55rem;border-radius:9999px;background:#f3f4f6;color:#6b7280;border:1px solid var(--gray-200);'],
                            ];
                            $badge = $badgeConfig[$statusDelivery] ?? $badgeConfig['not-started'];
                        ?>
                        <tr>
                            <td><span class="cell-title" style="font-size:0.8rem; font-weight:500;"><?= sanitize($r['category']) ?></span></td>
                            <td><span style="font-size:0.85rem; color:var(--gray-700);"><?= sanitize($r['document_type']) ?></span></td>
                            <td>
                                <?php if ($r['expiration_date']): ?>
                                    <span style="font-size:0.8rem; color:var(--gray-700); font-variant-numeric:tabular-nums;"><?= date('M j, Y', strtotime($r['expiration_date'])) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--gray-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $badge['class'] ?>" style="<?= $badge['style'] ?>"><?= $badge['label'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

  

<script nonce="<?= csp_nonce() ?>">
    // Modal handling
    document.querySelectorAll('.reject-btn, .reject-btn-ph').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = e.currentTarget.dataset.id;
            document.getElementById('reject_submission_id').value = id;
            document.getElementById('rejectModal').classList.add('show');
        });
    });

    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modalId = e.currentTarget.dataset.modal;
            document.getElementById(modalId).classList.remove('show');
        });
    });

    // Close modal when clicking outside
    window.addEventListener('click', (e) => {
        const modal = document.getElementById('rejectModal');
        if (e.target === modal) {
            modal.classList.remove('show');
        }
    });

    // Close modal with Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.getElementById('rejectModal').classList.remove('show');
        }
    });

    // Dismiss flash alerts
    document.querySelectorAll('.alert .alert-close').forEach(btn => {
        btn.addEventListener('click', function () {
            this.closest('.alert').remove();
        });
    });

    // Dismiss expired item
    document.querySelectorAll('.cancel-expired').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const item = e.currentTarget.closest('.expired-item');
            if (!item) return;
            const reqId = item.dataset.id;
            // Persist dismissal to server
            if (reqId) {
                fetch('admin_dashboard.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: 'action=dismiss_expired&requirement_id=' + encodeURIComponent(reqId) + '&csrf_token=' + encodeURIComponent(<?= json_encode(getCsrfToken()) ?>)
                }).catch(function () {});
            }
            item.style.transition = 'opacity 0.2s, max-height 0.2s';
            item.style.opacity = '0';
            item.style.maxHeight = '0';
            item.style.overflow = 'hidden';
            item.style.padding = '0';
            item.style.borderTop = 'none';
            setTimeout(() => {
                item.remove();
                const items = document.querySelectorAll('.expired-item');
                const badge = document.getElementById('expiredCount');
                if (badge) badge.textContent = items.length;
                document.querySelectorAll('.expired-dept-group').forEach(g => {
                    const count = g.querySelectorAll('.expired-item').length;
                    const countEl = g.querySelector('.dept-count');
                    if (countEl) countEl.textContent = count;
                    if (count === 0) g.remove();
                });
                if (items.length === 0) document.getElementById('expiredSection').remove();
            }, 200);
        });
    });

    // Expired section collapse/expand
    (function() {
        const section = document.getElementById('expiredSection');
        const toggleBtn = document.getElementById('expiredToggleBtn');
        if (!section || !toggleBtn) return;
        toggleBtn.addEventListener('click', () => {
            section.classList.toggle('collapsed');
            toggleBtn.setAttribute('aria-expanded', String(!section.classList.contains('collapsed')));
        });
    })();



</script>

<?php if (!isAjaxRequest()): ?>
<script nonce="<?= csp_nonce() ?>">
    CHED.Poller.register('adminDashboard', function () {
        return fetch('admin_dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'action=refresh_dashboard'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || typeof d.totalDepts === 'undefined') return;
            var map = { 'Departments': 'totalDepts', 'Program Head': 'totalProgramHeads', 'Unit Head': 'totalUnitHeads', 'Pending Admin': 'pendingAdmin', 'Approved': 'approvedTotal', 'Rejected': 'rejectedTotal' };
            document.querySelectorAll('.stat-card').forEach(function (card) {
                var label = card.querySelector('.stat-info p');
                var h3 = card.querySelector('.stat-info h3');
                if (label && h3 && map[label.textContent.trim()]) {
                    h3.textContent = d[map[label.textContent.trim()]];
                }
            });
            var badge = document.getElementById('expiredCount');
            if (badge) badge.textContent = d.expiredCount;
        }).catch(function () {});
    }, 30000);
</script>
<?php endif; ?>

<?php include 'includes/success_toast.php'; ?>
<?php include 'includes/footer.php'; ?>


