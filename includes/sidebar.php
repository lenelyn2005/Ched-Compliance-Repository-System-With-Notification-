<?php
// Direct-access guard: include-only file. Refuse a direct web request.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
// sidebar.php
if (!defined('CHED_APP')) {
    exit;
}

if (defined('SIDEBAR_ALREADY_LOADED')) {
    return;
}
define('SIDEBAR_ALREADY_LOADED', true);

if (!function_exists('sidebar_is_active')) {
    function sidebar_is_active(string $href, string $current): bool
    {
        return basename($href) === $current;
    }
}

if (!function_exists('sidebar_icon')) {
    function sidebar_icon(string $name): string
    {
        $icons = [
            'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/>',
            'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
            'building' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
            'checklist' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
            'archive' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>',
            'inbox' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>',
            'chart' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
            'bar-chart' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>',
            'clock' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'scroll' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
            'bell' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
            'logout' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>',
            'pen' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
            'paperclip' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>',
            'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        ];
        $path = $icons[$name] ?? $icons['dashboard'];
        return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">' . $path . '</svg>';
    }
}

$current = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';
$userName = $_SESSION['user_name'] ?? 'User';
$userId = $_SESSION['user_id'] ?? null;

// header.php already computed $unreadCount earlier in this request; only
// fall back to the COUNT query when a page includes the sidebar standalone.
if (!isset($unreadCount)) {
    $unreadCount = 0;
    if ($userId) {
        try {
            $unreadCount = getUnreadNotificationCount($pdo, (int) $userId);
        } catch (Exception $e) {
        }
    }
}

// ---------------------------------------------------------------
// Sidebar sections (label + links). Kept grouped for the new UI.
// ---------------------------------------------------------------
$sidebarSections = [];

if ($role === 'admin') {
    $sidebarSections = [
        [
            'label' => 'Overview',
            'links' => [
                ['href' => 'admin_dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'color' => 'primary'],
            ],
        ],
        [
            'label' => 'Management',
            'links' => [
                ['href' => 'admin_manage_users.php', 'label' => 'Manage Users', 'icon' => 'users', 'color' => 'info'],
                ['href' => 'admin_manage_departments.php', 'label' => 'Departments', 'icon' => 'building', 'color' => 'muted'],
                ['href' => 'admin_department_requirements.php', 'label' => 'Requirements', 'icon' => 'checklist', 'color' => 'warning'],
                ['href' => 'admin_manage_document.php', 'label' => 'Document Repo', 'icon' => 'archive', 'color' => 'muted'],
            ],
        ],
        [
            'label' => 'Workflow',
            'links' => [
                ['href' => 'admin_submissions.php', 'label' => 'Submissions', 'icon' => 'inbox', 'color' => 'info'],
                ['href' => 'notifications.php', 'label' => 'Notifications', 'icon' => 'bell', 'color' => 'warning'],
            ],
        ],
        [
            'label' => 'Insights',
            'links' => [
                ['href' => 'admin_analytics.php', 'label' => 'Analytics', 'icon' => 'chart', 'color' => 'success'],
                ['href' => 'admin_report.php', 'label' => 'Reports', 'icon' => 'bar-chart', 'color' => 'purple'],
                ['href' => 'admin_audit_trail.php', 'label' => 'Audit Trail', 'icon' => 'clock', 'color' => 'muted'],
                ['href' => 'admin_archive_documents.php', 'label' => 'Archives', 'icon' => 'archive', 'color' => 'muted'],
            ],
        ],
        [
            'label' => 'System',
            'links' => [
                ['href' => 'admin_settings.php', 'label' => 'Settings', 'icon' => 'settings', 'color' => 'muted'],
            ],
        ],
    ];
} elseif ($role === 'super_admin') {
    $sidebarSections = [
        [
            'label' => 'Overview',
            'links' => [
                ['href' => 'super_admin_dashboard.php', 'label' => 'Administrator Accounts', 'icon' => 'users', 'color' => 'primary'],
                ['href' => 'admin_audit_trail.php', 'label' => 'Audit Trail', 'icon' => 'clock', 'color' => 'muted'],
                ['href' => 'admin_settings.php', 'label' => 'System Settings', 'icon' => 'settings', 'color' => 'muted'],
            ],
        ],
    ];
} elseif (in_array($role, ['department', 'unit_head'], true)) {
    $deptId = $_SESSION['department_id'] ?? 0;
    $phId = $_SESSION['user_id'] ?? 0;
    $pendingBadge = 0;

    $deptCount = 0;
    try {
        $deptStmt = $pdo->prepare("
            SELECT d.id, d.file_path, d.document_type, d.teacher_requirement_id
            FROM documents d
            LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
            LEFT JOIN users u ON tr.teacher_id = u.id
            WHERE d.department_id = ? AND d.status = 'pending_program_head'
              AND d.version = (
                  SELECT MAX(d2.version) 
                  FROM documents d2 
                  WHERE IF(d.teacher_requirement_id IS NOT NULL, d2.teacher_requirement_id = d.teacher_requirement_id,
                      IF(d.document_type = 'Direct Upload', d2.id = d.id,
                          d2.department_id = d.department_id AND d2.category = d.category AND d2.document_type = d.document_type AND d2.teacher_requirement_id IS NULL))
              )
        ");
        $deptStmt->execute([$deptId]);
        $deptDocs = $deptStmt->fetchAll();

        $deptCount = count(array_filter($deptDocs, function($doc) {
            if ($doc['document_type'] === 'Direct Upload' && empty($doc['teacher_requirement_id'])) {
                return false;
            }
            $filePath = $doc['file_path'] ?? null;
            if (empty($filePath)) {
                return false;
            }
            return storageFilePresent($filePath);
        }));
    } catch (Exception $e) {
        $deptCount = 0;
    }

    $phCount = 0;
    try {
        $phStmt = $pdo->prepare("
            SELECT pd.id, pd.file_path
            FROM ph_documents pd
            JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            JOIN users u ON tpr.teacher_id = u.id
            JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            WHERE phr.created_by = ? AND pd.status = 'pending_program_head'
              AND pd.version = (
                  SELECT MAX(pd2.version)
                  FROM ph_documents pd2
                  WHERE pd2.teacher_ph_requirement_id = pd.teacher_ph_requirement_id
              )
        ");
        $phStmt->execute([$phId]);
        $phDocs = $phStmt->fetchAll();

        $phCount = count(array_filter($phDocs, function($doc) {
            $filePath = $doc['file_path'] ?? null;
            if (empty($filePath)) {
                return false;
            }
            return storageFilePresent($filePath);
        }));
    } catch (Exception $e) {
        $phCount = 0;
    }

    $pendingBadge = $deptCount + $phCount;

    $sidebarSections = [
        [
            'label' => 'Overview',
            'links' => [
                ['href' => 'program_head_dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'color' => 'primary'],
            ],
        ],
        [
            'label' => 'Team',
            'links' => [
                ['href' => 'program_head_teachers.php', 'label' => 'Manage Member', 'icon' => 'users', 'color' => 'info'],
                ['href' => 'program_head_create_assign.php', 'label' => 'Create & Assign', 'icon' => 'pen', 'color' => 'success'],
                ['href' => 'program_head_assign.php', 'label' => 'Assign Tasks', 'icon' => 'paperclip', 'color' => 'purple'],
            ],
        ],
        [
            'label' => 'Workflow',
            'links' => [
                ['href' => 'program_head_pending.php', 'label' => 'Pending Reviews', 'icon' => 'clock', 'badge' => $pendingBadge ?: null, 'color' => 'warning'],
                ['href' => 'program_head_history.php', 'label' => 'Submission History', 'icon' => 'scroll', 'color' => 'muted'],
                ['href' => 'program_head_requirements.php', 'label' => 'Requirements', 'icon' => 'checklist', 'color' => 'warning'],
                ['href' => 'program_head_repository.php', 'label' => phRoleName($role) . ' Repository', 'icon' => 'archive', 'color' => 'muted'],
            ],
        ],
        [
            'label' => 'System',
            'links' => [
                ['href' => 'notifications.php', 'label' => 'Notifications', 'icon' => 'bell', 'color' => 'warning'],
            ],
        ],
    ];
} elseif (isTeacher()) {
    $sidebarSections = [
        [
            'label' => 'Overview',
            'links' => [
                ['href' => 'teacher_dashboard.php', 'label' => 'My Dashboard', 'icon' => 'dashboard', 'color' => 'primary'],
            ],
        ],
        [
            'label' => 'My Work',
            'links' => [
                ['href' => 'teacher_tasks.php', 'label' => 'My Tasks', 'icon' => 'checklist', 'color' => 'success'],
                ['href' => 'teacher_submissions.php', 'label' => 'My Submissions', 'icon' => 'inbox', 'color' => 'info'],
            ],
        ],
        [
            'label' => 'System',
            'links' => [
                ['href' => 'notifications.php', 'label' => 'Notifications', 'icon' => 'bell', 'color' => 'warning'],
            ],
        ],
    ];
}

// ---------------------------------------------------------------
// Admin: inject pending submissions badge into admin_submissions.php
// ---------------------------------------------------------------
if ($role === 'admin') {
    try {
        $docRow = db_query("SELECT COUNT(*) AS cnt FROM documents d WHERE d.archived = 0 AND d.status = 'pending_admin' AND d.version = (SELECT MAX(d2.version) FROM documents d2 WHERE IF(d.teacher_requirement_id IS NOT NULL, d2.teacher_requirement_id = d.teacher_requirement_id, IF(d.document_type = 'Direct Upload', d2.id = d.id, d2.department_id = d.department_id AND d2.category = d.category AND d2.document_type = d.document_type AND d2.teacher_requirement_id IS NULL))) AND d.file_path IS NOT NULL AND d.file_path != ''", [], 0);
        $pendingCount = (int)(($docRow[0]['cnt'] ?? $docRow[0]['COUNT(*)'] ?? 0));
        $phRow = db_query("SELECT COUNT(*) AS cnt FROM ph_documents pd WHERE pd.archived = 0 AND pd.status = 'pending_admin' AND pd.version = (SELECT MAX(pd2.version) FROM ph_documents pd2 WHERE IF(pd.teacher_ph_requirement_id IS NOT NULL, pd2.teacher_ph_requirement_id = pd.teacher_ph_requirement_id, pd2.id = pd.id)) AND pd.file_path IS NOT NULL AND pd.file_path != ''", [], 0);
        $pendingCount += (int)(($phRow[0]['cnt'] ?? $phRow[0]['COUNT(*)'] ?? 0));

        if ($pendingCount > 0) {
            foreach ($sidebarSections as &$section) {
                foreach ($section['links'] as &$link) {
                    if ($link['href'] === 'admin_submissions.php') {
                        $link['badge'] = $pendingCount;
                        break 2;
                    }
                }
            }
            unset($link, $section);
        }
    } catch (Exception $e) {
    }
}

// ---------------------------------------------------------------
// User footer data
// ---------------------------------------------------------------
$roleLabels = [
    'admin'      => 'Administrator',
    'super_admin' => 'Super Admin',
    'department' => 'Program Head',
    'teacher'    => 'Faculty',
    'staff'      => 'Staff',
];
$roleLabel = in_array($role, ['department', 'unit_head'], true)
    ? phRoleName($role)
    : ($roleLabels[$role] ?? ucfirst($role));

$avatarInitial = '';
$nameTrim = trim((string)$userName);
if ($nameTrim !== '') {
    $avatarInitial = strtoupper(mb_substr($nameTrim, 0, 1));
}
if ($avatarInitial === '') {
    $avatarInitial = '?';
}
?>
<aside class="sidebar" id="mainSidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <img src="picture/love.jpg" alt="CHED Logo" class="sidebar-logo">
            <div class="sidebar-brand-text">
                <h2>CHED Compliance</h2>
                <small>Document Repository</small>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach ($sidebarSections as $section): ?>
            <?php if (empty($section['links'])) continue; ?>
            <div class="nav-section">
                <?php if (!empty($section['label'])): ?>
                    <div class="nav-section-title"><?= htmlspecialchars($section['label']) ?></div>
                <?php endif; ?>
                <?php foreach ($section['links'] as $link): ?>
                    <a href="<?= htmlspecialchars($link['href']) ?>"
                       class="<?= sidebar_is_active($link['href'], $current) ? 'active' : '' ?>">
                        <span class="nav-icon <?= htmlspecialchars($link['color'] ?? '') ?>">
                            <?= sidebar_icon($link['icon']) ?>
                        </span>
                        <span class="nav-label"><?= htmlspecialchars($link['label']) ?></span>
                        <?php if (!empty($link['badge'])): ?>
                            <span class="nav-badge"><?= (int) $link['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
</aside>