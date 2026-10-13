<?php
define('CHED_APP', true);
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF for state-changing POST (mark_read etc.). get_notifications is read-only but we still validate token if present.
    $csrfOk = true;
    if (in_array($_POST['action'], ['mark_read','mark_all_read','clean_invalid'], true)) {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($token)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']);
            exit;
        }
    }
    header('Content-Type: application/json');
    http_response_code(200);

    if ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
        markSingleNotificationRead($pdo, (int) $_POST['notification_id'], $userId);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'mark_all_read') {
        markNotificationsRead($pdo, $userId);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'get_notifications') {
        $lastId = isset($_POST['last_id']) ? (int) $_POST['last_id'] : 0;
        $newNotifications = fetchValidNotifications($pdo, $userId, $lastId);
        $unreadCount = getUnreadNotificationCount($pdo, $userId);
        echo json_encode([
            'success' => true,
            'notifications' => $newNotifications,
            'unread_count' => $unreadCount
        ]);
        exit;
    }

    if ($_POST['action'] === 'clean_invalid') {
        $deleted = deleteInvalidNotifications($pdo, $userId);
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF');
    }
    markNotificationsRead($pdo, $userId);
    header('Location: notifications.php');
    exit;
}
$csrfToken = getCsrfToken();

deleteInvalidNotifications($pdo, $userId);
$notifications = fetchValidNotifications($pdo, $userId);

$typeStyles = [
    'success' => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>', 'class' => 'notif-success', 'color' => '#059669'],
    'danger' => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>', 'class' => 'notif-danger', 'color' => '#dc2626'],
    'warning' => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>', 'class' => 'notif-warning', 'color' => '#f59e0b'],
    'info' => ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>', 'class' => 'notif-info', 'color' => '#6366f1'],
];

// Tint palettes for the icon chip — matched to admin stat-card palette
$typeTints = [
    'success' => ['bg' => '#dcfce7', 'fg' => '#047857'],
    'danger'  => ['bg' => '#fee2e2', 'fg' => '#b91c1c'],
    'warning' => ['bg' => '#fef3c7', 'fg' => '#b45309'],
    'info'    => ['bg' => '#dbeafe', 'fg' => '#1d4ed8'],
];

$pageTitle = 'Activity Notifications';

ob_start();
?>
<style>
    @import url('offline/inter-font.css');

    :root {
        /* Same palette as admin_dashboard.php */
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

    *,
    *::before,
    *::after { box-sizing: border-box; }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: var(--gray-50);
        color: var(--gray-800);
        font-size: 0.875rem;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    /* ---------- Utility helpers (self-contained, tailwind-safe) ---------- */
    .flex { display: flex; }
    .flex-col { flex-direction: column; }
    .flex-1 { flex: 1 1 0%; min-width: 0; }
    .justify-between { justify-content: space-between; }
    .items-start { align-items: flex-start; }
    .gap-4 { gap: 1rem; }
    .mb-2 { margin-bottom: 0.5rem; }
    .mt-4 { margin-top: 1rem; }
    .text-xs { font-size: 0.75rem; }
    .text-sm { font-size: 0.875rem; }
    .font-bold { font-weight: 700; }

    /* ---------- Layout shell (matches admin_dashboard) ---------- */
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

    /* ---------- Page head ---------- */
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
    .page-head .page-meta.has-unread {
        color: var(--primary-dark);
        border-color: #bfdbfe;
        background: var(--primary-light);
    }
    .page-head .page-meta.has-unread .dot { background: var(--primary); }

    /* ---------- Card (same as admin) ---------- */
    .card {
        background: var(--white);
        border-radius: 14px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: box-shadow 0.2s ease;
        box-shadow: var(--shadow-xs);
    }
    .card:hover { box-shadow: var(--shadow-sm); }

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

    .card-content { padding: 0; }
    .card-content.no-padding { padding: 0 !important; }

    /* ---------- Buttons (same as admin) ---------- */
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
        font-family: inherit;
    }
    .btn svg { width: 15px; height: 15px; }
    .btn-sm { padding: 0.5rem 1rem; font-size: 0.8125rem; }
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
    .btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Header actions wrapper */
    .header-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* ---------- Badges (same as admin) ---------- */
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
    .badge svg { width: 12px; height: 12px; }
    .badge-submitted {
        background: #eff6ff;
        color: #1d4ed8;
    }
    .badge-pending {
        background: var(--gray-100);
        color: var(--gray-600);
    }
    .badge-approved {
        background: #ecfdf5;
        color: #047857;
    }
    .badge-rejected {
        background: #fef2f2;
        color: #b91c1c;
    }

    /* Pulsing dot on live "N unread" badge in the card header */
    .card-header .badge-submitted::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
        flex-shrink: 0;
        animation: pulseDot 2s ease-in-out infinite;
    }
    @keyframes pulseDot {
        0%, 100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.45); }
        50%      { box-shadow: 0 0 0 4px rgba(37, 99, 235, 0); }
    }

    /* ---------- Notification row ---------- */
    .notif-history-item {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.1rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
        background: var(--white);
        transition: background 0.15s ease;
        cursor: pointer;
    }
    .notif-history-item:last-child { border-bottom: none; }
    .notif-history-item:hover { background: #f8fafc; }

    /* Left accent bar + tint driven by the badge state.
       When the JS flips "Unread" → "Read", the bar and tint disappear
       automatically — no extra JS hooks required. */
    .notif-history-item:has(.status-badge.badge-submitted) {
        background: #f8fbff;
    }
    .notif-history-item:has(.status-badge.badge-submitted):hover {
        background: #eff6ff;
    }
    .notif-history-item:has(.status-badge.badge-submitted)::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0.75rem;
        bottom: 0.75rem;
        width: 3px;
        border-radius: 0 3px 3px 0;
        background: var(--primary);
    }

    /* Kept for compatibility — no visual override, :has() drives state */
    .unread-bg { /* intentionally empty */ }

    /* Icon chip — matches admin's stat-icon treatment */
    .stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: color-mix(in srgb, currentColor 12%, #ffffff);
        transition: transform 0.2s ease;
    }
    .stat-icon svg { width: 20px; height: 20px; }
    .notif-history-item:hover .stat-icon { transform: scale(1.06); }

    /* Row typography */
    .notif-history-item h4 {
        font-size: 0.9rem;
        font-weight: 650;
        color: var(--gray-900);
        letter-spacing: -0.01em;
        line-height: 1.35;
        margin: 0;
    }

    /* Timestamp pill — override inline colours from PHP + JS templates */
    .notif-history-item .text-xs {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        margin-top: 0.35rem !important;
        color: var(--gray-500) !important;
        font-size: 0.72rem !important;
        font-weight: 500;
    }
    .notif-history-item .text-xs svg {
        color: var(--gray-400) !important;
        width: 12px;
        height: 12px;
    }

    /* Message text — override the inline `--slate-600` from the JS/old template */
    .notif-history-item p {
        color: var(--gray-600) !important;
        font-size: 0.85rem !important;
        line-height: 1.55 !important;
        margin: 0 !important;
    }

    /* Status badge in a row */
    .notif-history-item .status-badge {
        flex-shrink: 0;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-size: 0.65rem !important;
        padding: 0.22rem 0.6rem !important;
    }

    /* View Related Item — quiet outline button that matches admin's btn-outline */
    .view-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.42rem 0.85rem;
        border-radius: 7px;
        font-size: 0.78rem;
        font-weight: 500;
        font-family: inherit;
        background: transparent;
        border: 1px solid var(--gray-200);
        color: var(--gray-700);
        cursor: pointer;
        transition: all 0.15s ease;
        text-decoration: none;
    }
    .view-btn svg { width: 13px; height: 13px; }
    .view-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }
    .view-btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    .unread-item { cursor: pointer; }

    /* ---------- Empty state (matches admin) ---------- */
    .empty-state {
        text-align: center;
        padding: 3rem 1.25rem;
        color: var(--gray-500);
    }
    .empty-state-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 0.85rem;
        border-radius: 14px;
        background: var(--gray-100);
        color: var(--gray-400);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .empty-state-icon svg { width: 26px; height: 26px; }
    .empty-state h3 {
        margin: 0 0 0.4rem;
        font-size: 1rem;
        font-weight: 650;
        color: var(--gray-900);
        letter-spacing: -0.01em;
    }
    .empty-state p {
        margin: 0;
        font-size: 0.85rem;
        color: var(--gray-500);
    }
    .empty-state .btn { margin-top: 1.5rem; }

    /* ---------- Footer note (matches admin's section chrome) ---------- */
    .footer-note {
        padding: 0.85rem 1.25rem;
        text-align: center;
        font-size: 0.75rem;
        color: var(--gray-500);
        background: #f8fafc;
        border-top: 1px solid var(--border);
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 768px) {
        .dashboard-container { padding: 0.5rem; gap: 1rem; }
        .page-head h1 { font-size: 1.2rem; }
        .notif-history-item {
            padding: 0.9rem 1rem;
            gap: 0.85rem;
        }
        .notif-history-item .flex.justify-between.items-start {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }
    }

    @media (max-width: 480px) {
        .stat-icon { width: 36px; height: 36px; border-radius: 10px; }
        .stat-icon svg { width: 18px; height: 18px; }
        .notif-history-item { padding: 0.85rem; gap: 0.75rem; }
        .btn-sm { padding: 0.4rem 0.75rem; font-size: 0.78rem; }
        .card-header { padding: 0.85rem 1rem; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="dashboard-container">
    <?php $unreadCount = getUnreadNotificationCount($pdo, $userId); ?>


    <!-- Notifications card -->
    <div class="card">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                Notification History
            </h3>
            <div class="header-actions">
                <?php if ($unreadCount > 0): ?>
                    <form method="POST" action="notifications.php" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <button type="submit" name="mark_all_read" value="1" class="btn btn-sm btn-outline">Mark All Read</button>
                    </form>
                <?php endif; ?>
                <span class="badge <?= $unreadCount > 0 ? 'badge-submitted' : 'badge-pending' ?>"><?= $unreadCount ?> unread</span>
            </div>
        </div>
        <div class="card-content no-padding">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                        </svg>
                    </div>
                    <h3>Your notification tray is empty</h3>
                    <p>Any updates regarding document reviews or task assignments will appear here.</p>
                    <a href="<?= $role === 'admin' ? 'admin_dashboard.php' : (in_array($role, ['department', 'unit_head'], true) ? 'program_head_dashboard.php' : 'teacher_dashboard.php') ?>"
                        class="btn btn-primary">Back to Dashboard</a>
                </div>
            <?php else: ?>
                <div class="flex flex-col">
                    <?php foreach ($notifications as $index => $n):
                        $style = $typeStyles[$n['type']] ?? $typeStyles['info'];
                        $tint  = $typeTints[$n['type']] ?? $typeTints['info'];
                        $isLast = ($index === count($notifications) - 1);
                        $isUnread = !$n['is_read'];

                        $docTitle = $n['doc_title'] ?? 'Document';

                        $timestamp = '';
                        $timestampUnix = 0;
                        if (!empty($n['created_at']) && $n['created_at'] !== '0000-00-00 00:00:00') {
                            $timestampUnix = strtotime($n['created_at']);
                            $timestamp = $timestampUnix > 0 ? timeAgo($n['created_at']) : 'Just now';
                        } else {
                            $timestamp = 'Just now';
                        }
                        ?>
                        <div class="notif-history-item flex gap-4 <?= $isUnread ? 'unread-bg' : '' ?>"
                            data-notification-id="<?= $n['id'] ?>">
                            <div class="stat-icon" style="background: <?= $tint['bg'] ?>; color: <?= $tint['fg'] ?>;">
                                <?= $style['icon'] ?>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <h4 class="font-bold text-base" style="margin: 0; color: var(--gray-900);">
                                            <?= sanitize($n['title']) ?>
                                        </h4>
                                        <div class="text-xs" style="color: var(--danger); margin-top: 4px;">
                                            <svg xmlns="http://www.w3.org/2000/svg"
                                                style="width:12px;height:12px;vertical-align:middle;margin-right:0.25rem;"
                                                fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span class="relative-time"
                                                data-timestamp="<?= $timestampUnix ?>"><?= $timestamp ?></span>
                                        </div>
                                    </div>
                                    <span
                                        class="badge <?= $isUnread ? 'badge-submitted' : 'badge-pending' ?> text-xs status-badge"><?= $isUnread ? 'Unread' : 'Read' ?></span>
                                </div>
                                <p class="text-sm" style="margin: 0; color: var(--gray-600); line-height: 1.6;">
                                    <?= sanitize($n['message']) ?>
                                </p>

                                <?php if ($n['related_document_id']): ?>
                                    <div class="mt-4">
                                        <button class="view-btn" data-id="<?= $n['related_document_id'] ?>"
                                            data-type="<?= $n['doc_type'] ?? 'document' ?>" data-title="<?= sanitize($docTitle) ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            View Related Item
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="footer-note">
                    Showing last 200 activities. Older notifications are automatically cleaned up.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script nonce="<?= csp_nonce() ?>">
        function timeAgoFromTimestamp(timestamp) {
            const now = Math.floor(Date.now() / 1000);
            const diff = now - timestamp;
            if (diff < 60) return 'Just now';
            if (diff < 120) return '1 minute ago';
            if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
            if (diff < 7200) return '1 hour ago';
            if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
            if (diff < 172800) return '1 day ago';
            if (diff < 2592000) return Math.floor(diff / 86400) + ' days ago';
            if (diff < 5184000) return '1 month ago';
            if (diff < 31536000) return Math.floor(diff / 2592000) + ' months ago';
            if (diff < 63072000) return '1 year ago';
            return Math.floor(diff / 31536000) + ' years ago';
        }

        function updateRelativeTimes() {
            document.querySelectorAll('.relative-time').forEach(el => {
                const ts = parseInt(el.dataset.timestamp, 10);
                if (ts > 0) el.textContent = timeAgoFromTimestamp(ts);
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateRelativeTimes();
            setInterval(updateRelativeTimes, 10000);

            let lastNotificationId = 0;
            const notificationsContainer = document.querySelector('.notif-history-item.flex');

            if (notificationsContainer) {
                lastNotificationId = parseInt(notificationsContainer.dataset.notificationId) || 0;
            }

            function fetchNewNotifications() {
                fetch('notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_notifications&last_id=' + lastNotificationId
                })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) return;
                        // Always sync the badge from the authoritative server count,
                        // even when no NEW notifications arrived (fixes stale/inflated
                        // count after marking items read or via the header bell).
                        updateNotificationBadge(data.unread_count || 0);
                        if (data.notifications && data.notifications.length > 0) {
                            renderNewNotifications(data.notifications);
                        }
                    })
                    .catch(err => console.error('Notification fetch error:', err));
            }

            function updateNotificationBadge(count) {
                const badges = document.querySelectorAll('.card-header .badge');
                badges.forEach(badge => {
                    if (badge.textContent.includes('unread')) {
                        badge.textContent = count + ' unread';
                        badge.className = count > 0 ? 'badge badge-submitted' : 'badge badge-pending';
                    }
                });
                updateHeaderBadge(count);
            }

            function updateHeaderBadge(count) {
                const bell = document.getElementById('notifCountBadge');
                if (!bell) return;
                if (count > 0) {
                    bell.textContent = count > 99 ? '99+' : count;
                } else {
                    bell.remove();
                }
            }

            function renderNewNotifications(notifications) {
                const container = document.querySelector('.notif-list') || document.querySelector('.flex.flex-col');
                if (!container) return;

                notifications.forEach(n => {
                    if (n.id > lastNotificationId) {
                        lastNotificationId = n.id;
                        prependNotification(n);
                    }
                });
            }

            const svgIcons = {
                'success': '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
                'danger': '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>',
                'warning': '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>',
                'info': '<svg xmlns="http://www.w3.org/2000/svg" style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
            };

            function prependNotification(n) {
                const container = document.querySelector('.flex.flex-col');
                if (!container) return;

                const style = { 'success': '#059669', 'danger': '#dc2626', 'warning': '#f59e0b', 'info': '#6366f1' };
                const color = style[n.type] || style['info'];
                const iconSvg = svgIcons[n.type] || svgIcons['info'];
                const tsDate = n.created_at && n.created_at !== '0000-00-00 00:00:00' ? new Date(n.created_at) : null;
                const timestampUnix = tsDate ? Math.floor(tsDate.getTime() / 1000) : 0;
                const timestamp = timestampUnix > 0 ? `<span class="relative-time" data-timestamp="${timestampUnix}">${timeAgoFromTimestamp(timestampUnix)}</span>` : 'Just now';

                const html = `
            <div class="notif-history-item flex gap-4 unread-bg" data-notification-id="${n.id}" style="animation: fadeInUp 0.3s ease;">
                <div class="stat-icon" style="color: ${color};">
                    ${iconSvg}
                </div>
                <div class="flex-1">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <h4 class="font-bold text-base" style="margin: 0; color: var(--gray-900);">${escapeHtml(n.title)}</h4>
                            <div class="text-xs" style="color: var(--danger); margin-top: 4px;">
                                <svg xmlns="http://www.w3.org/2000/svg" style="width:12px;height:12px;vertical-align:middle;margin-right:0.25rem;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                ${timestamp}
                            </div>
                        </div>
                        <span class="badge badge-submitted text-xs status-badge">Unread</span>
                    </div>
                    <p class="text-sm" style="margin: 0; color: var(--gray-600); line-height: 1.6;">${escapeHtml(n.message)}</p>
                </div>
            </div>
        `;

                const firstItem = container.querySelector('.notif-history-item');
                if (firstItem) {
                    firstItem.insertAdjacentHTML('beforebegin', html);
                } else {
                    container.innerHTML = html;
                }
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            const CSRF_TOKEN = "<?= $csrfToken ?>";
            // Delegated click handler so dynamically prepended items also work
            const notifListEl = document.querySelector('.flex.flex-col') || document;
            notifListEl.addEventListener('click', function (e) {
                const item = e.target.closest('.notif-history-item[data-notification-id]');
                if (!item) return;
                if (e.target.closest('.view-btn')) return;

                const notificationId = parseInt(item.dataset.notificationId);
                const statusBadge = item.querySelector('.status-badge');

                if (statusBadge && statusBadge.textContent === 'Unread') {
                    fetch('notifications.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&notification_id=' + notificationId + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                statusBadge.textContent = 'Read';
                                statusBadge.className = 'badge badge-pending text-xs status-badge';
                                item.style.background = '';
                                const headerBadge = document.querySelector('.card-header .badge');
                                if (headerBadge && headerBadge.textContent.includes('unread')) {
                                    const remaining = Math.max(0, (parseInt(headerBadge.textContent) || 1) - 1);
                                    headerBadge.textContent = remaining + ' unread';
                                    headerBadge.className = remaining > 0 ? 'badge badge-submitted' : 'badge badge-pending';
                                }
                            }
                        })
                        .catch(err => console.error('Mark read error:', err));
                }
            });

            // Poll every 1s; source of truth for the history list (no WebSocket dependency)
            setInterval(fetchNewNotifications, 1000);
        });
    </script>


