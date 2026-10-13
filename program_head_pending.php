<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();

$deptId = (int)$_SESSION['department_id'];
$phId = (int)$_SESSION['user_id'];
$csrfToken = getCsrfToken();

$pendingSubs = getAllPendingForProgramHead($pdo, $deptId, $phId);

$pendingSubs = array_filter($pendingSubs, function($sub) {
    return !($sub['document_type'] === 'Direct Upload' && empty($sub['teacher_requirement_id']));
});

// Live queue count refresh (30s poll + WS-triggered). Read-only; role-guarded.
if (isAjaxRequest() && ($_POST['action'] ?? '') === 'refresh_count') {
    sendJson(['count' => count($pendingSubs)]);
}

$pageTitle = 'Pending Reviews';
$successMsg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

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

    .content-area { padding: 1.25rem 1.5rem !important; }

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

    .repo-section .card {
        background: transparent;
        border: none;
        border-radius: 0;
        box-shadow: none;
    }
    .repo-section .card:hover { box-shadow: none; }
    .repo-section .card-header {
        background: var(--white);
        border-radius: 0;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
    }

    /* Card */
    .card {
        background: var(--white);
        border-radius: 0;
        border: none;
        overflow: hidden;
        transition: var(--transition);
        box-shadow: none;
        flex: 1;
    }
    .card:hover { box-shadow: none; }
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
    .stat-value { font-weight: 800; color: var(--gray-900); font-size: 0.85rem; }
    .card-content { padding: 1.25rem; }
    .card-content.no-padding { padding: 0; }

    /* Table — matches admin_submissions */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0;
    }
    .table-responsive::-webkit-scrollbar { height: 6px; }
    .table-responsive::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 3px; }
    .data-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.875rem;
        min-width: 760px;
    }
    .data-table td { overflow-wrap: break-word; }
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
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: #f8fafc; }

    /* Centered Actions column */
    .data-table th.actions-col,
    .data-table td.actions-col {
        text-align: center;
        width: 190px;
        white-space: nowrap;
    }

    /* Badges — premium pill */
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
    .badge-warning { background: var(--warning-light); color: #92400e; border-color: #fde68a; }
    .badge-success { background: var(--success-light); color: #065f46; border-color: #a7f3d0; }
    .badge-danger { background: var(--danger-light); color: #b91c1c; border-color: #fecaca; }
    .badge-info { background: var(--info-light); color: #1e40af; border-color: #bfdbfe; }
    .badge-pending-ph { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
    .type-badge { display: inline-flex; align-items: center; padding: 0.28rem 0.6rem; border-radius: 9999px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border: 1px solid transparent; }
    .type-badge.dept { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
    .type-badge.ph { background: #fef3c7; color: #92400e; border-color: #fde68a; }

    /* ================================================================
       ACTION BUTTONS — centered icon-only control group
       ================================================================ */
    .action-group {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        flex-wrap: nowrap;
        margin: 0 auto;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border-radius: 9px;
        border: 1px solid var(--border);
        background: var(--white);
        color: var(--gray-500);
        cursor: pointer;
        transition: var(--transition);
        box-shadow: var(--shadow-sm);
        flex: 0 0 auto;
        line-height: 1;
        text-decoration: none;
    }
    .action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn:active { transform: translateY(0); }
    .action-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

    .action-btn--view {
        color: var(--primary);
        border-color: #c7d2fe;
        background: var(--primary-light);
    }
    .action-btn--view:hover {
        background: #e0e7ff;
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.18);
    }

    .action-btn--note:hover {
        color: #b45309;
        border-color: #fde68a;
        background: var(--warning-light);
        box-shadow: 0 4px 10px rgba(245, 158, 11, 0.2);
    }
    .action-btn--note.has-note {
        color: #b45309;
        border-color: #fde68a;
        background: var(--warning-light);
    }
    .action-btn--note.has-note:hover {
        background: #fde68a;
        box-shadow: 0 4px 10px rgba(245, 158, 11, 0.28);
    }

    .action-btn--approve {
        color: #047857;
        border-color: #a7f3d0;
        background: var(--success-light);
    }
    .action-btn--approve:hover {
        background: #a7f3d0;
        box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2);
    }

    .action-btn--reject {
        color: #b91c1c;
        border-color: #fecaca;
        background: var(--danger-light);
    }
    .action-btn--reject:hover {
        background: #fecaca;
        box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);
    }

    /* Keep legacy .btn styles for modals */
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
    .btn:hover { transform: translateY(-1px); }
    .btn:active { transform: translateY(0); }
    .btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .btn-sm { padding: 0.4rem 0.7rem; font-size: 0.75rem; }
    .btn-primary { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 1px 2px rgba(79,70,229,0.2); }
    .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); box-shadow: 0 4px 10px rgba(79,70,229,0.3); }
    .btn-outline { background: white; border-color: var(--border); color: var(--gray-700); box-shadow: var(--shadow); }
    .btn-outline:hover { border-color: var(--gray-300); color: var(--gray-800); background: var(--gray-50); box-shadow: var(--shadow-md); }
    .btn-success { background: var(--success); color: white; border-color: var(--success); box-shadow: 0 1px 2px rgba(16,185,129,0.2); }
    .btn-success:hover { background: #0e9f6e; border-color: #0e9f6e; box-shadow: 0 4px 10px rgba(16,185,129,0.3); }
    .btn-danger { background: var(--danger); color: white; border-color: var(--danger); box-shadow: 0 1px 2px rgba(239,68,68,0.2); }
    .btn-danger:hover { background: #dc2626; border-color: #dc2626; box-shadow: 0 4px 10px rgba(239,68,68,0.3); }
    .btn-secondary { background: white; color: var(--gray-700); border-color: var(--border); box-shadow: var(--shadow); }
    .btn-secondary:hover { background: var(--gray-50); border-color: var(--gray-300); box-shadow: var(--shadow-md); }

    /* Empty state — matches admin */
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
        color: var(--gray-500);
        background: var(--gray-50);
        border: 1px dashed var(--border);
        border-radius: 12px;
        margin: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .empty-state-icon { color: var(--gray-400); margin: 0 auto 0.85rem; display: block; opacity: 0.9; }
    .empty-state p { font-size: 0.9rem; margin: 0; color: var(--gray-500); }

    /* Modals — matches admin */
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
    .modal.show { display: flex; animation: modalFadeIn 0.2s ease; }
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
    @keyframes slideUp { from { transform: translateY(16px) scale(0.98); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
    @media (prefers-reduced-motion: reduce) {
        .modal-content { animation: none; }
        .btn, .action-btn, .card { transition: none; }
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
    .modal-header--danger h3::before { background: var(--danger); }
    .modal-header--success h3::before { background: var(--success); }
    .modal-header--warning h3::before { background: var(--warning); }
    .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; min-height: 0; background: white; }
    .modal-body .form-group { margin-bottom: 1.15rem; }
    .modal-body .form-group label { display: block; font-weight: 600; margin-bottom: 0.4rem; font-size: 0.78rem; color: var(--gray-700); letter-spacing: 0.01em; }
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
    .close-btn:hover { background: var(--gray-50); color: var(--gray-800); border-color: var(--gray-300); }
    .form-group { margin-bottom: 1.15rem; }
    .form-group label { display: block; font-weight: 600; margin-bottom: 0.4rem; font-size: 0.78rem; color: var(--gray-700); }
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
        box-sizing: border-box;
    }
    .form-control:hover { border-color: var(--gray-300); }
    .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light), 0 1px 2px rgba(0,0,0,0.04); background: white; }
    .form-control::placeholder { color: var(--gray-400); }
    textarea.form-control { resize: vertical; min-height: 88px; }

    .w-5 { width: 1.25rem; height: 1.25rem; }
    .w-4 { width: 1rem; height: 1rem; }
    .w-3 { width: 0.75rem; height: 0.75rem; }
    .w-16 { width: 4rem; height: 4rem; }
    .h-5 { height: 1.25rem; }
    .h-4 { height: 1rem; }
    .h-3 { height: 0.75rem; }
    .h-16 { height: 4rem; }
    .text-warning { color: var(--warning); }
    .text-danger { color: var(--danger); }
    .text-success { color: var(--success); }

    /* Responsive — matches admin */
    @media (max-width: 768px) {
        .card-header { flex-direction: column; align-items: flex-start; }
        .card-header-stats { width: 100%; justify-content: space-between; }
        .data-table { font-size: 0.8rem; min-width: 700px; }
        .data-table th, .data-table td { padding: 0.5rem 0.75rem; }
        .data-table th.actions-col,
        .data-table td.actions-col { width: 170px; }
        .action-btn { width: 32px; height: 32px; border-radius: 8px; }
        .action-btn svg { width: 15px; height: 15px; }
    }
    @media (max-width: 600px) {
        .data-table { min-width: 620px; font-size: 0.75rem; }
        .data-table th, .data-table td { padding: 0.4rem 0.5rem; }
        .data-table th.actions-col,
        .data-table td.actions-col { width: 150px; }
        .badge { font-size: 0.6rem; padding: 0.1rem 0.4rem; }
        .card-header { padding: 0.75rem 1rem; }
        .card-header h3 { font-size: 0.9rem; }
        .action-group { gap: 0.25rem; }
        .action-btn { width: 30px; height: 30px; }
        .action-btn svg { width: 14px; height: 14px; }
    }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<div class="dashboard-container">
    <div class="repo-section">
        <div class="card">
            <div class="card-header">
                <h3>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Submissions Awaiting Review
                </h3>
                <div class="card-header-stats">
                    <div class="stat-item"><span class="stat-value"><?= count($pendingSubs) ?></span> pending</div>
                </div>
            </div>
            <div class="card-content no-padding">
                <?php if (empty($pendingSubs)): ?>
                    <div class="empty-state">
                        <svg class="empty-state-icon w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <p>All caught up! All submissions have been reviewed.</p>
                        <a href="program_head_dashboard.php" class="btn btn-outline" style="margin-top:1rem;">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 12H5M12 19l-7-7 7-7"></path></svg>
                            Back to Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th>User</th>
                                    <th>Requirement Category</th>
                                    <th>Document Type</th>
                                    <th>Submission Date</th>
                                    <th class="actions-col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingSubs as $sub): 
                                    $isDept = $sub['submission_type'] === 'department';
                                    $hasNote = !empty(trim($sub['teacher_comment'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($sub['source_label'])): ?>
                                            <span class="badge <?= $sub['source_badge_class'] ?? 'badge-info' ?>"><?= sanitize($sub['source_label']) ?></span>
                                        <?php else: ?>
                                            <span class="type-badge <?= $isDept ? 'dept' : 'ph' ?>"><?= $isDept ? 'Dept' : 'DH' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong style="font-weight:700; color:var(--gray-900);"><?= sanitize($sub['teacher_name']) ?></strong></td>
                                    <td><span style="color:var(--gray-600); font-size:0.85rem;"><?= sanitize($sub['category']) ?></span></td>
                                    <td><span style="font-weight:600; color:var(--gray-900);"><?= sanitize($sub['document_type']) ?></span></td>
                                    <td><span style="font-size:0.82rem; color:var(--gray-500);"><?= date('M j, Y h:i A', strtotime($sub['upload_date'])) ?></span></td>
                                    <td class="actions-col">
                                        <div class="action-group">
                                            <button class="action-btn action-btn--view view-btn" 
                                                    data-id="<?= $sub['id'] ?>" 
                                                    data-type="<?= $sub['submission_type'] === 'department' ? 'document' : 'ph_document' ?>"
                                                    data-title="<?= sanitize($sub['category'] . ' - ' . $sub['document_type']) ?>"
                                                    data-filename="<?= sanitize($sub['filename'] ?? '') ?>"
                                                    data-extension="<?= strtolower(pathinfo($sub['filename'] ?? '', PATHINFO_EXTENSION)) ?>"
                                                    title="View Document" aria-label="View document">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                            </button>

                                            <?php if ($hasNote): ?>
                                            <button class="action-btn action-btn--note note-btn has-note"
                                                    data-id="<?= $sub['id'] ?>"
                                                    data-title="<?= sanitize($sub['category'] . ' - ' . $sub['document_type']) ?>"
                                                    data-teacher="<?= sanitize($sub['teacher_name']) ?>"
                                                    data-note="<?= sanitize($sub['teacher_comment'] ?? '') ?>"
                                                    title="Note available — click to view"
                                                    aria-label="View faculty note">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                          d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                                </svg>
                                            </button>
                                            <?php endif; ?>

                                            <button type="button" class="action-btn action-btn--approve approve-btn"
                                                    data-id="<?= $sub['id'] ?>"
                                                    data-type="<?= $isDept ? 'department' : 'ph' ?>"
                                                    data-title="<?= sanitize($sub['category'] . ' - ' . $sub['document_type']) ?>"
                                                    title="Approve" aria-label="Approve submission">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            </button>
                                            <button class="action-btn action-btn--reject reject-btn" 
                                                    data-id="<?= $sub['id'] ?>" 
                                                    data-type="<?= $isDept ? 'department' : 'ph' ?>"
                                                    data-title="<?= sanitize($sub['category'] . ' - ' . $sub['document_type']) ?>"
                                                    title="Reject" aria-label="Reject submission">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
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

<!-- Approve Modal -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header modal-header--success">
            <h3>
                <svg class="w-5 h-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Approve Submission
            </h3>
            <button class="close-btn" data-modal="approveModal">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 1rem 0; color: var(--gray-700); font-size:0.9rem;">
                Are you sure you want to approve this document?<br>
                <strong id="approveDocTitle" style="color:var(--gray-900);"></strong>
            </p>
            <p id="approveExtraInfo" style="margin:0 0 1.25rem 0; font-size:0.82rem; color:var(--gray-500); font-style:italic;"></p>
            <form method="post" id="approveForm" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="submission_id" id="approve_sid">
                <input type="hidden" name="action" value="approve">
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" data-modal="approveModal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header modal-header--danger">
            <h3>
                <svg class="w-5 h-5 text-danger" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                Reject Submission
            </h3>
            <button class="close-btn" data-modal="rejectModal">&times;</button>
        </div>
        <div class="modal-body">
            <p id="reject_info" style="margin-bottom: 1rem; font-size: 0.9rem; color: var(--gray-600);"></p>
            <form method="post" id="rejectForm" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="submission_id" id="reject_sid">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Reason for Rejection <span style="color: var(--danger);">*</span></label>
                    <textarea name="comment" class="form-control" rows="4" required placeholder="Please provide specific feedback to the faculty member..."></textarea>
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" data-modal="rejectModal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Note Modal — only the note content -->
<div id="noteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header modal-header--warning">
            <h3>
                <svg class="w-5 h-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line></svg>
                Faculty Note
            </h3>
            <button class="close-btn" data-modal="noteModal">&times;</button>
        </div>
        <div class="modal-body">
            <div id="note_content" style="background:var(--gray-50); border:1px solid var(--border); padding:1rem; border-radius:10px; color:var(--gray-700); white-space:pre-wrap; word-break:break-word; font-size:0.9rem; line-height:1.6;"></div>
        </div>
    </div>
</div>

<!-- Success Toast -->
<?php include 'includes/success_toast.php'; ?>

<?php
$extraScripts = "
<script nonce=\"" . csp_nonce() . "\">
    const csrfToken = '$csrfToken';
";
$extraScripts .= "
    document.querySelectorAll('.approve-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('approve_sid').value = this.dataset.id;
            document.getElementById('approveDocTitle').textContent = this.dataset.title || 'Document';
            document.getElementById('approveExtraInfo').textContent = this.dataset.message || '';
            const form = document.getElementById('approveForm');
            form.action = this.dataset.type === 'ph' ? 'program_head_review_ph.php' : 'program_head_review.php';
            document.getElementById('approveModal').classList.add('show');
        });
    });

    document.querySelectorAll('.reject-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('reject_sid').value = this.dataset.id;
            document.getElementById('reject_info').textContent = 'Rejecting: ' + this.dataset.title;
            const form = document.getElementById('rejectForm');
            if (this.dataset.type === 'ph') {
                form.action = 'program_head_review_ph.php';
            } else {
                form.action = 'program_head_review.php';
            }
            document.getElementById('rejectModal').classList.add('show');
        });
    });

    document.querySelectorAll('.note-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const note = this.dataset.note;
            const contentEl = document.getElementById('note_content');
            if (note && note.trim() !== '') {
                contentEl.textContent = note;
            } else {
                contentEl.innerHTML = '<span style=\"color: var(--gray-400); font-style: italic;\">No note provided by the faculty member.</span>';
            }
            document.getElementById('noteModal').classList.add('show');
        });
    });

    document.querySelectorAll('.close-btn[data-modal], .btn[data-modal]').forEach(btn => {
        btn.addEventListener('click', function () {
            const target = this.dataset.modal;
            if (target) document.getElementById(target).classList.remove('show');
        });
    });

    window.onclick = function (event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('show');
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                modal.classList.remove('show');
            });
        }
    });

    // # ponytail: real-time SSE removed, re-add only if profiler shows need. Upgrade: cached count or short poll.
</script>
";
include 'includes/footer.php'; 
?>



