<?php
define('CHED_APP', true);
require_once 'config.php';
require_once 'includes/avatar_helpers.php';
requireAdmin();

$error = '';
$success = '';
$openTab = 'admin-account';

if (isset($_GET['msg'])) {
    $success = $_GET['msg'];
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, name, email, role, avatar FROM users WHERE id = ? AND role = ?');
$stmt->execute([$adminId, 'admin']);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header('Location: login.php');
    exit;
}

// --- Update account info ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    requireCsrfToken();
    $openTab = 'admin-account';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($name === '' || $email === '') {
        $error = 'Name and email are required.';
    } elseif (mb_strlen($name) > 120 || mb_strlen($email) > 190) {
        $error = 'Name or email is too long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $adminId]);
        if ($stmt->fetch()) {
            $error = 'Email already in use.';
        } else {
            try {
                $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ? AND role = ?')
                    ->execute([$name, $email, $adminId, 'admin']);
                $_SESSION['user_name'] = $name;
                $_SESSION['email'] = $email;
                $admin['name'] = $name;
                $admin['email'] = $email;
                logAudit($pdo, 'edit', 'Admin profile updated: ' . $email, 'user', $adminId);
                $success = 'Account information updated successfully.';
            } catch (PDOException $e) {
                error_log('[CHED] admin settings profile failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    }
}

// --- Upload photo ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_avatar') {
    requireCsrfToken();
    $openTab = 'admin-account';
    $res = avatarUpload($_FILES['avatar'] ?? [], $adminId, (string) ($admin['name'] ?? ''));
    if (!$res['ok']) {
        $error = $res['error'];
    } else {
        $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ? AND role = ?')
            ->execute([$res['path'], $adminId, 'admin']);
        if (($admin['avatar'] ?? '') && $admin['avatar'] !== $res['path']) {
            avatarDelete($admin['avatar']);
        }
        $_SESSION['avatar'] = $res['path'];
        $admin['avatar'] = $res['path'];
        logAudit($pdo, 'edit', 'Admin profile photo updated', 'user', $adminId);
        $success = 'Profile photo updated.';
    }
}

// --- Remove photo ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_avatar') {
    requireCsrfToken();
    $openTab = 'admin-account';
    avatarDelete($admin['avatar'] ?? null);
    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ? AND role = ?')
        ->execute([$adminId, 'admin']);
    unset($_SESSION['avatar']);
    $admin['avatar'] = null;
    logAudit($pdo, 'edit', 'Admin profile photo removed', 'user', $adminId);
    $success = 'Profile photo removed.';
}

// --- Change password ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    requireCsrfToken();
    $openTab = 'change-password';
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $now = time();
    if (!isset($_SESSION['pw_change_attempts'])) {
        $_SESSION['pw_change_attempts'] = [];
    }
    $_SESSION['pw_change_attempts'] = array_values(array_filter(
        $_SESSION['pw_change_attempts'],
        static fn($t) => ($now - (int)$t) < 600
    ));
    if (count($_SESSION['pw_change_attempts']) >= 5) {
        $error = 'Too many password change attempts. Please try again in 10 minutes.';
    } elseif ($current === '' || $new === '' || $confirm === '') {
        $error = 'All password fields are required.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND role = ?');
        $stmt->execute([$adminId, 'admin']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password'])) {
            $_SESSION['pw_change_attempts'][] = $now;
            $error = 'Current password is incorrect.';
        } else {
            try {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND role = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId, 'admin']);
                $_SESSION['pw_change_attempts'] = [];
                logAudit($pdo, 'edit', 'Admin password changed', 'user', $adminId);
                $success = 'Password changed successfully. Use your new password next time you log in.';
            } catch (PDOException $e) {
                error_log('[CHED] admin password change failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    }
}

$storageRoot = rtrim(SECURE_STORAGE_PATH, '/\\');
$archivePath = $storageRoot . DIRECTORY_SEPARATOR . 'admin_archive';
$tempRoot = rtrim(TEMP_PATH, '/\\');

$secureStorageOk = is_dir($storageRoot) && is_writable($storageRoot);
$archiveOk = is_dir($archivePath) || is_writable($storageRoot);
$tempOk = is_dir($tempRoot);
$dbOk = false;
try {
    $pdo->getAttribute(PDO::ATTR_SERVER_INFO);
    $dbOk = true;
} catch (Exception $e) {
    $dbOk = false;
}

$lastMigration = null;
try {
    $lastMigration = $pdo->query('SELECT MAX(applied_at) FROM schema_migrations')->fetchColumn() ?: null;
} catch (Exception $e) {
    $lastMigration = null;
}

$pageTitle = 'Settings';

ob_start();
?>
<style>
    @import url('offline/inter-font.css');
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
/* ============================================================
   Admin Settings — sidebar nav + unified account card
   ============================================================ */
.as-app {
    --as-bg: #f7f8fa;
    --as-surface: #ffffff;
    --as-surface-2: #f9fafb;
    --as-surface-3: #f3f4f6;
    --as-line: #eaecef;
    --as-line-2: #e4e7eb;
    --as-line-3: #d1d5db;
    --as-ink: #0b1220;
    --as-ink-2: #1f2937;
    --as-body: #4b5563;
    --as-muted: #6b7280;
    --as-faint: #9ca3af;
    --as-accent: #4f46e5;
    --as-accent-2: #6366f1;
    --as-accent-soft: #eef2ff;
    --as-accent-tint: #e0e7ff;
    --as-ok: #059669;
    --as-ok-soft: #ecfdf5;
    --as-warn: #b45309;
    --as-warn-soft: #fffbeb;
    --as-danger: #dc2626;
    --as-danger-soft: #fef2f2;
    --as-radius: 10px;
    --as-radius-lg: 14px;
    --as-shadow-sm: 0 1px 2px rgba(11,18,32,.04);
    --as-shadow: 0 1px 3px rgba(11,18,32,.06), 0 1px 2px rgba(11,18,32,.04);
    --as-shadow-md: 0 4px 12px -4px rgba(11,18,32,.14), 0 2px 4px rgba(11,18,32,.04);
    --as-shadow-lg: 0 12px 32px -8px rgba(11,18,32,.18), 0 3px 8px rgba(11,18,32,.05);
    --as-ring: 0 0 0 3px rgba(79,70,229,.18);

    max-width: 1180px;
    margin: 0 auto;
    padding: 1.25rem 1.25rem 3rem;
    color: var(--as-ink-2);
    font-size: 14px;
    line-height: 1.55;
    font-family: inherit;
    -webkit-font-smoothing: antialiased;
}

/* ============ Page header ============ */
.as-page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 0 .25rem 1.25rem;
    border-bottom: 1px solid var(--as-line);
    margin-bottom: 1.5rem;
}
.as-page-head h1 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--as-ink);
    letter-spacing: -.025em;
    line-height: 1.2;
}
.as-page-head p {
    margin: .3rem 0 0;
    font-size: .85rem;
    color: var(--as-muted);
    font-weight: 500;
}
.as-page-head-right {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .8rem;
    color: var(--as-muted);
    font-weight: 500;
}
.as-sys-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 4px rgba(16,185,129,.15);
    animation: asDotPulse 2.4s ease-in-out infinite;
}
@keyframes asDotPulse {
    0%,100% { box-shadow: 0 0 0 4px rgba(16,185,129,.15); }
    50%     { box-shadow: 0 0 0 7px rgba(16,185,129,.04); }
}

/* ============ Layout ============ */
.as-layout {
    display: grid;
    grid-template-columns: 268px minmax(0, 1fr);
    gap: 2rem;
    align-items: start;
}

/* ============ Sidebar (nav only) ============ */
.as-sidebar {
    position: sticky;
    top: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-height: calc(100vh - 2.5rem);
}
.as-nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: .35rem;
    background: var(--as-surface);
    border: 1px solid var(--as-line);
    border-radius: var(--as-radius-lg);
    box-shadow: var(--as-shadow-sm);
    overflow-y: auto;
    scrollbar-width: thin;
}
.as-nav::-webkit-scrollbar { width: 4px; }
.as-nav::-webkit-scrollbar-thumb { background: var(--as-line-2); border-radius: 4px; }

.as-nav-group {
    display: flex; flex-direction: column; gap: 1px;
    padding: .5rem 0;
}
.as-nav-group + .as-nav-group { border-top: 1px solid var(--as-line); }
.as-nav-group-label {
    font-size: .64rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--as-faint);
    padding: .25rem .6rem .35rem;
}

.as-nav-item {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: .65rem .7rem;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    font-family: inherit;
    font-size: .84rem;
    font-weight: 600;
    color: var(--as-body);
    cursor: pointer;
    text-align: left;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
    letter-spacing: -.005em;
}
.as-nav-item:hover { background: var(--as-surface-3); color: var(--as-ink); }
.as-nav-item:focus-visible { outline: none; box-shadow: var(--as-ring); }
.as-nav-item.is-active {
    background: var(--as-surface);
    color: var(--as-ink);
    border-color: var(--as-line-2);
    box-shadow: 0 1px 2px rgba(11,18,32,.04), 0 0 0 3px rgba(79,70,229,.06);
}
.as-nav-item.is-active::before {
    content: "";
    position: absolute;
    left: -5px; top: 50%;
    transform: translateY(-50%);
    width: 3px; height: 60%;
    border-radius: 3px;
    background: linear-gradient(180deg, var(--as-accent), var(--as-accent-2));
}

.as-nav-icon {
    flex-shrink: 0;
    width: 30px; height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    background: var(--as-surface-3);
    color: var(--as-muted);
    transition: background .2s ease, color .2s ease;
}
.as-nav-icon svg { width: 15px; height: 15px; }
.as-nav-item:hover .as-nav-icon { background: var(--as-line-2); color: var(--as-ink-2); }
.as-nav-item.is-active .as-nav-icon {
    background: linear-gradient(135deg, var(--as-accent), var(--as-accent-2));
    color: #fff;
    box-shadow: 0 3px 8px -2px rgba(79,70,229,.4);
}

.as-nav-text { min-width: 0; flex: 1; display: flex; flex-direction: column; }
.as-nav-label { font-weight: 650; font-size: .83rem; color: inherit; }
.as-nav-desc {
    font-size: .7rem;
    font-weight: 500;
    color: var(--as-faint);
    margin-top: 1px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.as-nav-item.is-active .as-nav-desc { color: var(--as-muted); }

.as-nav-chev {
    flex-shrink: 0;
    color: var(--as-faint);
    opacity: 0;
    transform: translateX(-4px);
    transition: opacity .18s ease, transform .18s ease;
}
.as-nav-chev svg { width: 14px; height: 14px; }
.as-nav-item.is-active .as-nav-chev,
.as-nav-item:hover .as-nav-chev { opacity: 1; transform: translateX(0); }

.as-sidebar-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem .9rem;
    border-top: 1px dashed var(--as-line-2);
    font-size: .7rem;
    color: var(--as-faint);
    font-weight: 600;
    letter-spacing: .02em;
}
.as-sidebar-foot code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    background: var(--as-surface-3);
    padding: .15rem .4rem;
    border-radius: 5px;
    color: var(--as-muted);
}

/* ============ Main ============ */
.as-main {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.as-panel[hidden] { display: none !important; }
.as-panel { display: flex; flex-direction: column; gap: 1.1rem; }
.as-panel.as-enter { animation: asPanelIn .35s cubic-bezier(.16,1,.3,1); }
@keyframes asPanelIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============ Unified account card ============ */
.as-account-card {
    background: var(--as-surface);
    border: 1px solid var(--as-line);
    border-radius: var(--as-radius-lg);
    box-shadow: var(--as-shadow-sm);
    overflow: hidden;
}

/* Hero zone inside the card */
.as-account-hero {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(120% 160% at 50% 0%, rgba(79,70,229,.10) 0%, rgba(79,70,229,.02) 45%, transparent 75%),
        linear-gradient(180deg, #ffffff 0%, #fbfbfe 100%);
    padding: 2.25rem 1.5rem 1.85rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: .35rem;
    border-bottom: 1px solid var(--as-line);
}
.as-account-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 20% 100%, rgba(99,102,241,.06), transparent 45%),
        radial-gradient(circle at 80% 0%, rgba(79,70,229,.05), transparent 40%);
    pointer-events: none;
}

.as-account-avatar-wrap {
    position: relative;
    display: inline-flex;
    margin-bottom: 1rem;
    z-index: 1;
}
.as-avatar {
    position: relative;
    width: 108px; height: 108px;
    padding: 0;
    border: 4px solid #fff;
    border-radius: 50%;
    overflow: hidden;
    cursor: pointer;
    background: linear-gradient(135deg, var(--as-accent) 0%, var(--as-accent-2) 100%);
    color: #fff;
    font-family: inherit;
    font-weight: 800;
    font-size: 2.3rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow:
        0 0 0 1px var(--as-line-2),
        0 0 0 5px rgba(79,70,229,.10),
        0 16px 32px -14px rgba(79,70,229,.5);
    transition: box-shadow .25s ease, transform .3s cubic-bezier(.34,1.56,.64,1);
    letter-spacing: -.02em;
}
.as-avatar:hover {
    transform: translateY(-2px);
    box-shadow:
        0 0 0 1px var(--as-accent),
        0 0 0 6px rgba(79,70,229,.16),
        0 22px 40px -14px rgba(79,70,229,.55);
}
.as-avatar:focus-visible { outline: none; box-shadow: 0 0 0 1px var(--as-accent), var(--as-ring); }
.as-avatar.is-dragover {
    box-shadow: 0 0 0 3px var(--as-accent), 0 0 0 8px rgba(79,70,229,.22), 0 22px 40px -14px rgba(79,70,229,.6);
    transform: scale(1.04);
}
.as-avatar-media { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.as-avatar-media img { width: 100%; height: 100%; object-fit: cover; display: block; }
.as-avatar-overlay {
    position: absolute; inset: 0;
    background: radial-gradient(circle at center, rgba(11,18,32,.28), rgba(11,18,32,.66));
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .2s ease;
    pointer-events: none;
    backdrop-filter: blur(1px);
}
.as-avatar-overlay svg { width: 24px; height: 24px; }
.as-avatar:hover .as-avatar-overlay,
.as-avatar:focus-visible .as-avatar-overlay { opacity: 1; }
.as-avatar-cam {
    position: absolute;
    right: 4px; bottom: 4px;
    width: 30px; height: 30px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--as-accent) 0%, var(--as-accent-2) 100%);
    color: #fff;
    border: 3px solid #fff;
    display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(79,70,229,.4);
    pointer-events: none;
    transition: transform .3s cubic-bezier(.34,1.56,.64,1);
}
.as-avatar:hover ~ .as-avatar-cam { transform: scale(1.12) rotate(-6deg); }
.as-avatar-cam svg { width: 13px; height: 13px; }

.as-avatar-menu {
    position: absolute;
    top: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%);
    display: flex; gap: .25rem;
    z-index: 30;
    background: #fff;
    border: 1px solid var(--as-line);
    border-radius: 10px;
    padding: .3rem;
    box-shadow: var(--as-shadow-md);
    animation: asMenuIn .22s cubic-bezier(.16,1,.3,1);
}
@keyframes asMenuIn {
    from { opacity: 0; transform: translateX(-50%) translateY(-6px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}
.as-avatar-menu[hidden] { display: none; }
.as-avatar-menu-item {
    font-family: inherit;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .02em;
    padding: .4rem .8rem;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--as-ink-2);
    cursor: pointer;
    transition: background .15s ease, color .15s ease;
}
.as-avatar-menu-item:hover { background: var(--as-accent-soft); color: var(--as-accent); }
.as-avatar-menu-item:focus-visible { outline: none; box-shadow: var(--as-ring); }
.as-avatar-menu-item.danger { color: var(--as-danger); }
.as-avatar-menu-item.danger:hover { background: var(--as-danger-soft); color: var(--as-danger); }

.as-account-hero-name {
    font-size: 1.45rem;
    font-weight: 800;
    color: var(--as-ink);
    letter-spacing: -.02em;
    line-height: 1.2;
    margin: .15rem 0 0;
    z-index: 1;
}
.as-account-hero-email {
    font-size: .875rem;
    color: var(--as-muted);
    font-weight: 500;
    word-break: break-all;
    z-index: 1;
}
.as-account-hero-role {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    margin-top: .7rem;
    background: linear-gradient(135deg, var(--as-accent-soft), var(--as-accent-tint));
    color: var(--as-accent);
    border: 1px solid rgba(79,70,229,.18);
    border-radius: 999px;
    padding: .32rem .85rem;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    z-index: 1;
}
.as-account-hero-role::before {
    content: "";
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,.2);
    animation: asRolePulse 2s ease-in-out infinite;
}
@keyframes asRolePulse {
    0%,100% { box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
    50%     { box-shadow: 0 0 0 6px rgba(16,185,129,.05); }
}

/* Card section head (inside unified card) */
.as-card-head {
    padding: 1.15rem 1.5rem;
    border-bottom: 1px solid var(--as-line);
    background: linear-gradient(180deg, #ffffff 0%, #fdfdfe 100%);
    display: flex;
    align-items: center;
    gap: .85rem;
}
.as-card-head-icon {
    flex-shrink: 0;
    width: 38px; height: 38px;
    border-radius: 10px;
    background: var(--as-accent-soft);
    color: var(--as-accent);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--as-accent-tint);
}
.as-card-head-icon svg { width: 17px; height: 17px; }
.as-card-head-text { min-width: 0; flex: 1; }
.as-card-head-text h2 {
    margin: 0;
    font-size: .98rem;
    font-weight: 700;
    color: var(--as-ink);
    letter-spacing: -.015em;
}
.as-card-head-text p {
    margin: .15rem 0 0;
    font-size: .79rem;
    color: var(--as-muted);
    font-weight: 500;
}
.as-card-head-badge {
    flex-shrink: 0;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--as-faint);
    background: var(--as-surface-2);
    border: 1px solid var(--as-line);
    padding: .25rem .55rem;
    border-radius: 6px;
}
.as-card-body { padding: 1.5rem; }
.as-card-foot {
    padding: 1rem 1.5rem;
    background: var(--as-surface-2);
    border-top: 1px solid var(--as-line);
    display: flex;
    gap: .6rem;
    justify-content: flex-end;
    flex-wrap: wrap;
}

/* Standalone card (for other panels) */
.as-card {
    background: var(--as-surface);
    border: 1px solid var(--as-line);
    border-radius: var(--as-radius-lg);
    box-shadow: var(--as-shadow-sm);
    overflow: hidden;
}

/* ============ Forms ============ */
.as-form-grid { display: grid; grid-template-columns: 1fr; gap: 1.15rem; }
@media (min-width: 720px) {
    .as-form-grid.as-grid-2 { grid-template-columns: 1fr 1fr; }
}
.as-field { display: flex; flex-direction: column; }
.as-label-row {
    display: flex; align-items: baseline; justify-content: space-between;
    margin-bottom: .45rem;
}
.as-label {
    font-size: .78rem;
    font-weight: 650;
    color: var(--as-ink-2);
    letter-spacing: -.005em;
}
.as-req { color: var(--as-danger); margin-left: 2px; font-weight: 700; }
.as-hint {
    font-size: .74rem;
    color: var(--as-muted);
    margin: .45rem 0 0;
    font-weight: 400;
}
.as-counter {
    font-size: .68rem;
    color: var(--as-faint);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    transition: color .2s ease;
}
.as-counter.warn { color: #d97706; }
.as-counter.danger { color: var(--as-danger); }

.as-input-wrap { position: relative; }
.as-input-wrap .as-input { padding-right: 2.75rem; }

.as-input {
    width: 100%;
    padding: .7rem .9rem;
    border: 1px solid var(--as-line-2);
    border-radius: var(--as-radius);
    font-family: inherit;
    font-size: .875rem;
    background: #fff;
    color: var(--as-ink);
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
    box-sizing: border-box;
    min-height: 42px;
    appearance: none;
}
.as-input::placeholder { color: var(--as-faint); }
.as-input:hover { border-color: var(--as-line-3); }
.as-input:focus {
    outline: none;
    border-color: var(--as-accent);
    box-shadow: var(--as-ring);
}

.as-input-wrap.is-valid .as-input {
    border-color: rgba(16,185,129,.5);
    background: linear-gradient(180deg, #ffffff 0%, #f8fffb 100%);
}
.as-check {
    position: absolute;
    right: .75rem; top: 50%;
    transform: translateY(-50%) scale(.6);
    width: 20px; height: 20px;
    border-radius: 50%;
    background: var(--as-ok-soft);
    color: var(--as-ok);
    display: inline-flex; align-items: center; justify-content: center;
    opacity: 0;
    transition: opacity .25s ease, transform .3s cubic-bezier(.34,1.56,.64,1);
    pointer-events: none;
}
.as-check svg { width: 12px; height: 12px; }
.as-input-wrap.is-valid .as-check { opacity: 1; transform: translateY(-50%) scale(1); }

.as-eye {
    position: absolute;
    right: .35rem; top: 50%;
    transform: translateY(-50%);
    width: 34px; height: 34px;
    border: none; background: transparent;
    color: var(--as-muted);
    cursor: pointer;
    border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    transition: color .15s ease, background .15s ease;
}
.as-eye:hover { color: var(--as-ink); background: var(--as-surface-3); }
.as-eye:focus-visible { outline: none; box-shadow: var(--as-ring); }
.as-eye svg { width: 16px; height: 16px; }
.as-eye .as-eye-off { display: none; }
.as-eye.is-visible .as-eye-on { display: none; }
.as-eye.is-visible .as-eye-off { display: block; }

/* Password strength */
.as-strength { margin-top: .7rem; }
.as-strength-bars { display: flex; gap: .3rem; margin-bottom: .35rem; }
.as-strength-bar {
    flex: 1; height: 4px;
    border-radius: 3px;
    background: var(--as-line-2);
    transition: background .3s ease;
}
.as-strength[data-score="1"] .as-strength-bar:nth-child(-n+1) { background: #ef4444; }
.as-strength[data-score="2"] .as-strength-bar:nth-child(-n+2) { background: #f59e0b; }
.as-strength[data-score="3"] .as-strength-bar:nth-child(-n+3) { background: #84cc16; }
.as-strength[data-score="4"] .as-strength-bar:nth-child(-n+4) { background: #10b981; }
.as-strength-label {
    display: flex; justify-content: space-between; align-items: center;
    font-size: .7rem; font-weight: 600; color: var(--as-faint);
}
.as-strength-text { transition: color .3s ease; }
.as-strength[data-score="1"] .as-strength-text { color: #ef4444; }
.as-strength[data-score="2"] .as-strength-text { color: #f59e0b; }
.as-strength[data-score="3"] .as-strength-text { color: #65a30d; }
.as-strength[data-score="4"] .as-strength-text { color: var(--as-ok); }

/* ============ Buttons ============ */
.as-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    padding: .65rem 1.1rem;
    border-radius: var(--as-radius);
    font-size: .83rem;
    font-weight: 650;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, box-shadow .15s ease, transform .1s ease;
    border: 1px solid transparent;
    text-decoration: none;
    line-height: 1;
    min-height: 42px;
    font-family: inherit;
    letter-spacing: -.005em;
}
.as-btn:focus-visible { outline: none; box-shadow: var(--as-ring); }
.as-btn:active { transform: translateY(1px); }
.as-btn svg { width: 15px; height: 15px; }

.as-btn-primary {
    background: var(--as-accent);
    color: #fff;
    box-shadow: 0 1px 2px rgba(79,70,229,.2), 0 0 0 1px rgba(79,70,229,.06);
}
.as-btn-primary:hover {
    background: #4338ca;
    box-shadow: 0 6px 14px -4px rgba(79,70,229,.5), 0 0 0 1px rgba(79,70,229,.1);
}
.as-btn-primary.is-loading { pointer-events: none; opacity: .85; }
.as-btn-primary.is-loading::after {
    content: "";
    width: 13px; height: 13px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,.35);
    border-top-color: #fff;
    animation: asSpin .7s linear infinite;
}
@keyframes asSpin { to { transform: rotate(360deg); } }

.as-btn-ghost {
    background: #fff;
    border-color: var(--as-line-2);
    color: var(--as-ink-2);
}
.as-btn-ghost:hover { background: var(--as-surface-2); border-color: var(--as-line-3); }

.as-btn-danger {
    background: var(--as-danger);
    color: #fff;
}
.as-btn-danger:hover { background: #b91c1c; box-shadow: 0 6px 14px -4px rgba(220,38,38,.4); }

/* ============ Info rows ============ */
.as-info {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0;
}
.as-info-row {
    display: grid;
    grid-template-columns: 180px 1fr;
    align-items: center;
    gap: 1rem;
    padding: .85rem 1.5rem;
    border-bottom: 1px solid var(--as-line);
    font-size: .855rem;
}
.as-info-row:last-child { border-bottom: none; }
.as-info-row:hover { background: var(--as-surface-2); }
.as-info-row dt {
    color: var(--as-muted);
    font-weight: 500;
    font-size: .82rem;
}
.as-info-row dd {
    margin: 0;
    color: var(--as-ink);
    font-weight: 600;
    text-align: left;
    word-break: break-word;
    display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
}

.as-tag {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .7rem; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase;
    padding: .2rem .55rem;
    border-radius: 6px;
    border: 1px solid transparent;
}
.as-tag-ok { background: var(--as-ok-soft); color: var(--as-ok); border-color: rgba(5,150,105,.2); }
.as-tag-warn { background: var(--as-warn-soft); color: var(--as-warn); border-color: rgba(180,83,9,.18); }
.as-tag::before {
    content: "";
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.as-path-row {
    display: flex; align-items: stretch; gap: .5rem;
    width: 100%;
}
.as-path {
    flex: 1; min-width: 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .78rem;
    color: var(--as-ink);
    background: var(--as-surface-2);
    border: 1px solid var(--as-line);
    border-radius: 8px;
    padding: .55rem .75rem;
    word-break: break-all;
}
.as-copy {
    flex-shrink: 0;
    width: 36px;
    background: #fff;
    border: 1px solid var(--as-line-2);
    border-radius: 8px;
    color: var(--as-muted);
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.as-copy:hover { background: var(--as-accent-soft); color: var(--as-accent); border-color: var(--as-accent-tint); }
.as-copy:focus-visible { outline: none; box-shadow: var(--as-ring); }
.as-copy svg { width: 14px; height: 14px; }
.as-copy.copied { background: var(--as-ok-soft); color: var(--as-ok); border-color: rgba(5,150,105,.25); }

/* ============ Toasts ============ */
.as-toasts {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
    display: flex;
    flex-direction: column-reverse;
    gap: .55rem;
    max-width: min(380px, calc(100vw - 2rem));
    pointer-events: none;
}
.as-toast {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .85rem 1rem;
    border-radius: var(--as-radius-lg);
    background: #fff;
    border: 1px solid var(--as-line);
    box-shadow: var(--as-shadow-lg);
    pointer-events: auto;
    animation: asToastIn .35s cubic-bezier(.34,1.56,.64,1);
    position: relative;
    overflow: hidden;
}
@keyframes asToastIn {
    from { opacity: 0; transform: translateY(14px) scale(.96); }
    to { opacity: 1; transform: none; }
}
.as-toast.dismiss { animation: asToastOut .3s ease forwards; }
@keyframes asToastOut {
    to { opacity: 0; transform: translateX(20px) scale(.96); }
}
.as-toast-icon {
    flex-shrink: 0;
    width: 22px; height: 22px;
    border-radius: 6px;
    display: inline-flex; align-items: center; justify-content: center;
    margin-top: 1px;
}
.as-toast-icon svg { width: 14px; height: 14px; }
.as-toast.success .as-toast-icon { background: var(--as-ok-soft); color: var(--as-ok); }
.as-toast.error   .as-toast-icon { background: var(--as-danger-soft); color: var(--as-danger); }
.as-toast.info    .as-toast-icon { background: var(--as-accent-soft); color: var(--as-accent); }
.as-toast-body { flex: 1; min-width: 0; }
.as-toast-title {
    font-size: .83rem;
    font-weight: 700;
    color: var(--as-ink);
    letter-spacing: -.005em;
}
.as-toast-msg {
    font-size: .8rem;
    color: var(--as-muted);
    margin-top: 1px;
    word-break: break-word;
}
.as-toast-close {
    flex-shrink: 0;
    width: 24px; height: 24px;
    border: none;
    background: transparent;
    color: var(--as-faint);
    cursor: pointer;
    border-radius: 6px;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s ease, color .15s ease;
}
.as-toast-close:hover { background: var(--as-surface-3); color: var(--as-ink); }
.as-toast-close svg { width: 12px; height: 12px; }
.as-toast::after {
    content: "";
    position: absolute;
    left: 0; bottom: 0; height: 2px;
    width: 100%;
    background: var(--as-accent);
    transform-origin: left;
    animation: asToastBar 4.5s linear forwards;
}
.as-toast.success::after { background: var(--as-ok); }
.as-toast.error::after { background: var(--as-danger); }
@keyframes asToastBar {
    from { transform: scaleX(1); }
    to { transform: scaleX(0); }
}

/* ============ Modal ============ */
.as-modal {
    position: fixed;
    inset: 0;
    z-index: 10000;
    background: rgba(11,18,32,.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
}
.as-modal.active { display: flex; animation: asModalFade .2s ease; }
@keyframes asModalFade { from { opacity: 0; } to { opacity: 1; } }
.as-modal-box {
    background: #fff;
    border-radius: var(--as-radius-lg);
    width: 100%;
    max-width: 440px;
    box-shadow: 0 24px 64px -8px rgba(11,18,32,.28), 0 8px 24px rgba(11,18,32,.12);
    overflow: hidden;
    animation: asModalBox .3s cubic-bezier(.16,1,.3,1);
}
@keyframes asModalBox {
    from { opacity: 0; transform: translateY(20px) scale(.96); }
    to { opacity: 1; transform: none; }
}
.as-modal-head {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--as-line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.as-modal-head h3 {
    margin: 0;
    font-size: .98rem;
    font-weight: 700;
    color: var(--as-ink);
    letter-spacing: -.015em;
    display: flex;
    align-items: center;
    gap: .55rem;
}
.as-modal-head h3 svg {
    width: 18px; height: 18px;
    color: var(--as-danger);
    background: var(--as-danger-soft);
    padding: 6px; box-sizing: content-box;
    border-radius: 8px;
}
.as-modal-close {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid var(--as-line);
    background: #fff;
    color: var(--as-muted);
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s ease, color .15s ease, transform .2s ease;
}
.as-modal-close:hover { background: var(--as-surface-2); color: var(--as-ink); transform: rotate(90deg); }
.as-modal-close:focus-visible { outline: none; box-shadow: var(--as-ring); }
.as-modal-close svg { width: 14px; height: 14px; }
.as-modal-body {
    padding: 1.5rem;
    font-size: .875rem;
    color: var(--as-body);
    line-height: 1.6;
}
.as-modal-body p { margin: 0; }
.as-modal-foot {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--as-line);
    background: var(--as-surface-2);
    display: flex;
    gap: .6rem;
    justify-content: flex-end;
}

/* ============ Responsive ============ */
@media (max-width: 900px) {
    .as-layout { grid-template-columns: 1fr; gap: 1.25rem; }
    .as-sidebar { position: static; max-height: none; }
    .as-nav { flex-direction: row; overflow-x: auto; padding: .35rem; }
    .as-nav-group { flex-direction: row; padding: 0; border-top: none; gap: .25rem; }
    .as-nav-group + .as-nav-group { border-top: none; padding-left: .35rem; border-left: 1px solid var(--as-line); margin-left: .25rem; }
    .as-nav-group-label { display: none; }
    .as-nav-item { flex-shrink: 0; padding: .5rem .7rem; }
    .as-nav-item.is-active::before { left: 20%; top: auto; bottom: 0; transform: none; width: 60%; height: 2px; }
    .as-nav-text { display: none; }
    .as-nav-icon { width: 34px; height: 34px; }
    .as-sidebar-foot { display: none; }
}
@media (max-width: 640px) {
    .as-app { padding: 1rem .9rem 2.5rem; }
    .as-account-hero { padding: 2rem 1.15rem 1.6rem; }
    .as-avatar { width: 92px; height: 92px; font-size: 2rem; }
    .as-account-hero-name { font-size: 1.2rem; }
    .as-card-body { padding: 1.15rem; }
    .as-card-head { padding: 1rem 1.15rem; }
    .as-card-foot { padding: .9rem 1.15rem; flex-direction: column-reverse; }
    .as-card-foot .as-btn { width: 100%; }
    .as-info-row { grid-template-columns: 1fr; gap: .3rem; padding: .8rem 1.15rem; }
    .as-info-row dt { font-size: .74rem; }
    .as-toasts { left: .75rem; right: .75rem; bottom: .75rem; max-width: none; }
    .as-page-head h1 { font-size: 1.25rem; }
}
@media (prefers-reduced-motion: reduce) {
    .as-app *, .as-app *::before, .as-app *::after {
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
    }
}
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
$initial = strtoupper(mb_substr(trim($admin['name']), 0, 1));
$settingsAvatarUrl = avatarUrl($admin['avatar'] ?? null);

$tabs = [
    ['key' => 'admin-account',   'label' => 'Account Details',   'desc' => 'Your name, email, photo',      'group' => 'Account', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>'],
    ['key' => 'change-password', 'label' => 'Change Password',   'desc' => 'Update your login password',  'group' => 'Account', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>'],
    ['key' => 'storage',         'label' => 'Storage',           'desc' => 'Repository configuration',    'group' => 'System', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75"/></svg>'],
    ['key' => 'system-info',     'label' => 'System Information', 'desc' => 'Runtime and diagnostics',    'group' => 'System', 'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>'],
];
?>
<div class="as-app">



    <div class="as-layout">

        <!-- ==================== SIDEBAR ==================== -->
        <aside class="as-sidebar" aria-label="Settings navigation">
            <nav class="as-nav" role="tablist" aria-label="Settings sections" aria-orientation="vertical">
                <?php
                $grouped = [];
                foreach ($tabs as $t) { $grouped[$t['group']][] = $t; }
                foreach ($grouped as $groupName => $items): ?>
                    <div class="as-nav-group">
                        <div class="as-nav-group-label"><?= htmlspecialchars($groupName) ?></div>
                        <?php foreach ($items as $t):
                            $isActive = ($t['key'] === $openTab);
                        ?>
                            <button type="button"
                                    class="as-nav-item<?= $isActive ? ' is-active' : '' ?>"
                                    role="tab"
                                    id="tab-<?= $t['key'] ?>"
                                    data-tab="<?= $t['key'] ?>"
                                    data-label="<?= htmlspecialchars($t['label']) ?>"
                                    aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                                    aria-controls="panel-<?= $t['key'] ?>"
                                    tabindex="<?= $isActive ? '0' : '-1' ?>">
                                <span class="as-nav-icon"><?= $t['icon'] ?></span>
                                <span class="as-nav-text">
                                    <span class="as-nav-label"><?= htmlspecialchars($t['label']) ?></span>
                                    <span class="as-nav-desc"><?= htmlspecialchars($t['desc']) ?></span>
                                </span>
                                <span class="as-nav-chev" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </nav>

       
        </aside>

        <!-- ==================== MAIN ==================== -->
        <main class="as-main">

            <!-- ========== ADMIN ACCOUNT (unified card) ========== -->
            <div class="as-panel" id="panel-admin-account" role="tabpanel" aria-labelledby="tab-admin-account" tabindex="0">
                <section class="as-account-card">

                    <!-- Hero zone -->
                    <div class="as-account-hero">
                        <div class="as-account-avatar-wrap">
                            <button type="button" class="as-avatar" id="avatarBtn"
                                    title="Change profile photo"
                                    aria-label="Profile photo options"
                                    aria-haspopup="menu" aria-expanded="false" aria-controls="avatarMenu">
                                <span class="as-avatar-media">
                                    <?php if ($settingsAvatarUrl): ?>
                                        <img src="<?= htmlspecialchars($settingsAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <?= $initial ?>
                                    <?php endif; ?>
                                </span>
                                <span class="as-avatar-overlay" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </span>
                            </button>
                            <span class="as-avatar-cam" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </span>
                            <div class="as-avatar-menu" id="avatarMenu" role="menu" hidden>
                                <button type="button" class="as-avatar-menu-item" id="avatarUploadBtn" role="menuitem">Upload</button>
                                <?php if ($settingsAvatarUrl): ?>
                                <button type="button" class="as-avatar-menu-item danger" id="avatarRemoveBtn" role="menuitem">Remove</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <h2 class="as-account-hero-name"><?= sanitize($admin['name']) ?></h2>
                        <div class="as-account-hero-email"><?= sanitize($admin['email']) ?></div>
                        <span class="as-account-hero-role">Administrator</span>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="avatarForm" style="display:none;" aria-hidden="true">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="upload_avatar">
                        <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif">
                    </form>
                    <form method="POST" id="avatarRemoveForm" style="display:none;" aria-hidden="true">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remove_avatar">
                    </form>

                    <!-- Info section (same card, below hero) -->
                    <div class="as-card-head">
                        <span class="as-card-head-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                        </span>
                        <div class="as-card-head-text">
                            <h2>Personal Information</h2>
                            <p>Your name and contact email used across the system</p>
                        </div>
                    </div>

                    <div class="as-card-body">
                        <form method="POST" autocomplete="off" novalidate id="profileForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_profile">
                            <div class="as-form-grid as-grid-2">
                                <div class="as-field">
                                    <div class="as-label-row">
                                        <label class="as-label" for="as-name">Full Name <span class="as-req" aria-hidden="true">*</span></label>
                                        <span class="as-counter" data-count-for="as-name" data-max="120"><?= mb_strlen($admin['name']) ?> / 120</span>
                                    </div>
                                    <div class="as-input-wrap" data-validate="name">
                                        <input type="text" id="as-name" name="name" class="as-input" required
                                            value="<?= sanitize($admin['name']) ?>" autocomplete="name" maxlength="120"
                                            placeholder="Your full name">
                                        <span class="as-check" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                    </div>
                                </div>
                                <div class="as-field">
                                    <div class="as-label-row">
                                        <label class="as-label" for="as-email">Email Address <span class="as-req" aria-hidden="true">*</span></label>
                                        <span class="as-counter" data-count-for="as-email" data-max="190"><?= mb_strlen($admin['email']) ?> / 190</span>
                                    </div>
                                    <div class="as-input-wrap" data-validate="email">
                                        <input type="email" id="as-email" name="email" class="as-input" required
                                            value="<?= sanitize($admin['email']) ?>" autocomplete="email" maxlength="190"
                                            placeholder="you@example.com">
                                        <span class="as-check" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="as-card-foot">
                        <button type="submit" form="profileForm" class="as-btn as-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            <span>Save Changes</span>
                        </button>
                    </div>
                </section>
            </div>

            <!-- ========== CHANGE PASSWORD ========== -->
            <div class="as-panel" id="panel-change-password" role="tabpanel" aria-labelledby="tab-change-password" tabindex="0" hidden>
                <section class="as-card">
                    <div class="as-card-head">
                        <span class="as-card-head-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        </span>
                        <div class="as-card-head-text">
                            <h2>Password</h2>
                            <p>Choose a strong password you don't use anywhere else</p>
                        </div>
                    </div>
                    <div class="as-card-body">
                        <form method="POST" autocomplete="off" novalidate id="passwordForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="change_password">
                            <div class="as-form-grid">
                                <div class="as-field">
                                    <label class="as-label" for="as-current-pass">Current Password <span class="as-req" aria-hidden="true">*</span></label>
                                    <div class="as-input-wrap">
                                        <input type="password" id="as-current-pass" name="current_password" class="as-input" required
                                            autocomplete="current-password" placeholder="Enter your current password">
                                        <button type="button" class="as-eye" data-toggle="as-current-pass" aria-label="Show password">
                                            <svg class="as-eye-on" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <svg class="as-eye-off" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="as-field">
                                    <label class="as-label" for="as-new-pass">New Password <span class="as-req" aria-hidden="true">*</span></label>
                                    <div class="as-input-wrap">
                                        <input type="password" id="as-new-pass" name="new_password" class="as-input" required
                                            autocomplete="new-password" minlength="8" placeholder="At least 8 characters">
                                        <button type="button" class="as-eye" data-toggle="as-new-pass" aria-label="Show password">
                                            <svg class="as-eye-on" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <svg class="as-eye-off" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                                        </button>
                                    </div>
                                    <div class="as-strength" id="as-strength" data-score="0">
                                        <div class="as-strength-bars">
                                            <span class="as-strength-bar"></span>
                                            <span class="as-strength-bar"></span>
                                            <span class="as-strength-bar"></span>
                                            <span class="as-strength-bar"></span>
                                        </div>
                                        <div class="as-strength-label">
                                            <span>Password strength</span>
                                            <span class="as-strength-text" id="as-strength-text">—</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="as-field">
                                    <label class="as-label" for="as-confirm-pass">Confirm New Password <span class="as-req" aria-hidden="true">*</span></label>
                                    <div class="as-input-wrap">
                                        <input type="password" id="as-confirm-pass" name="confirm_password" class="as-input" required
                                            autocomplete="new-password" minlength="8" placeholder="Repeat new password">
                                        <button type="button" class="as-eye" data-toggle="as-confirm-pass" aria-label="Show password">
                                            <svg class="as-eye-on" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <svg class="as-eye-off" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                                        </button>
                                    </div>
                                    <p class="as-hint">Passwords are hashed and never stored in plain text.</p>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="as-card-foot">
                        <button type="submit" form="passwordForm" class="as-btn as-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                            <span>Update Password</span>
                        </button>
                    </div>
                </section>
            </div>

            <!-- ========== STORAGE ========== -->
            <div class="as-panel" id="panel-storage" role="tabpanel" aria-labelledby="tab-storage" tabindex="0" hidden>
                <section class="as-card">
                    <div class="as-card-head">
                        <span class="as-card-head-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75"/></svg>
                        </span>
                        <div class="as-card-head-text">
                            <h2>Storage Configuration</h2>
                            <p>Where the repository stores your documents</p>
                        </div>
                        <span class="as-card-head-badge">Read only</span>
                    </div>
                    <dl class="as-info">
                        <div class="as-info-row" style="grid-template-columns:1fr; gap:.6rem;">
                            <dt>Secure storage path</dt>
                            <dd>
                                <div class="as-path-row">
                                    <span class="as-path" id="path-storage"><?= sanitize($storageRoot) ?></span>
                                    <button type="button" class="as-copy" data-copy="#path-storage" title="Copy path" aria-label="Copy storage path">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                    </button>
                                </div>
                            </dd>
                        </div>
                        <div class="as-info-row">
                            <dt>Repository</dt>
                            <dd><?= $secureStorageOk ? '<span class="as-tag as-tag-ok">Connected</span>' : '<span class="as-tag as-tag-warn">Unavailable</span>' ?></dd>
                        </div>
                        <div class="as-info-row">
                            <dt>Archive folder</dt>
                            <dd><?= $archiveOk ? '<span class="as-tag as-tag-ok">Connected</span>' : '<span class="as-tag as-tag-warn">Unavailable</span>' ?></dd>
                        </div>
                        <div class="as-info-row">
                            <dt>Temporary storage</dt>
                            <dd><?= $tempOk ? '<span class="as-tag as-tag-ok">Connected</span>' : '<span class="as-tag as-tag-warn">Unavailable</span>' ?></dd>
                        </div>
                        <div class="as-info-row">
                            <dt>Max upload size</dt>
                            <dd><?= number_format(MAX_FILE_SIZE / (1024 * 1024), 0) ?> MB</dd>
                        </div>
                        <div class="as-info-row">
                            <dt>Allowed file types</dt>
                            <dd><?= sanitize(ALLOWED_EXTENSIONS_LABEL) ?></dd>
                        </div>
                    </dl>
                </section>
            </div>

            <!-- ========== SYSTEM INFO ========== -->
            <div class="as-panel" id="panel-system-info" role="tabpanel" aria-labelledby="tab-system-info" tabindex="0" hidden>
                <section class="as-card">
                    <div class="as-card-head">
                        <span class="as-card-head-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        </span>
                        <div class="as-card-head-text">
                            <h2>System Information</h2>
                            <p>Runtime environment and diagnostic info</p>
                        </div>
                        <span class="as-card-head-badge">Read only</span>
                    </div>
                    <dl class="as-info">
                        <div class="as-info-row"><dt>System name</dt><dd>CHED Compliance Repository System</dd></div>
                        <div class="as-info-row"><dt>User role</dt><dd>Administrator</dd></div>
                        <div class="as-info-row"><dt>Application version</dt><dd>1.0.0</dd></div>
                        <div class="as-info-row">
                            <dt>Database</dt>
                            <dd><?= $dbOk ? '<span class="as-tag as-tag-ok">Connected</span>' : '<span class="as-tag as-tag-warn">Disconnected</span>' ?></dd>
                        </div>
                        <div class="as-info-row">
                            <dt>Secure storage</dt>
                            <dd><?= $secureStorageOk ? '<span class="as-tag as-tag-ok">Connected</span>' : '<span class="as-tag as-tag-warn">Unavailable</span>' ?></dd>
                        </div>
                        <div class="as-info-row"><dt>PHP version</dt><dd><?= sanitize(PHP_VERSION) ?></dd></div>
                        <div class="as-info-row">
                            <dt>Last system update</dt>
                            <dd><?= $lastMigration ? date('M j, Y · g:i A', strtotime($lastMigration)) : 'Not available' ?></dd>
                        </div>
                    </dl>
                </section>
            </div>

        </main>
    </div>
</div>

<!-- Toast container -->
<div class="as-toasts" id="asToasts" aria-live="polite" aria-atomic="true"></div>

<!-- Remove-photo confirm modal -->
<div class="as-modal" id="removeAvatarModal" aria-hidden="true">
    <div class="as-modal-box" role="dialog" aria-modal="true" aria-labelledby="removeAvatarTitle">
        <div class="as-modal-head">
            <h3 id="removeAvatarTitle">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.9 12.1A2 2 0 0116.1 21H7.9a2 2 0 01-2-1.9L5 7m5 4v6m4-6v6M4 7h16M10 7V4h4v3"/></svg>
                Remove profile photo?
            </h3>
            <button type="button" class="as-modal-close" id="removeAvatarClose" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="as-modal-body">
            <p>Your current profile photo will be removed. You can upload a new one anytime.</p>
        </div>
        <div class="as-modal-foot">
            <button type="button" class="as-btn as-btn-ghost" id="removeAvatarCancel">Cancel</button>
            <button type="button" class="as-btn as-btn-danger" id="removeAvatarConfirm">Remove Photo</button>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';

    /* Toast system */
    var toastHost = document.getElementById('asToasts');
    function toast(type, title, msg) {
        if (!toastHost) return;
        var el = document.createElement('div');
        el.className = 'as-toast ' + type;
        var iconPath = type === 'success'
            ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
            : type === 'error'
            ? '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>'
            : '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>';
        el.innerHTML =
            '<span class="as-toast-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' + iconPath + '</svg></span>' +
            '<div class="as-toast-body">' +
                '<div class="as-toast-title"></div>' +
                '<div class="as-toast-msg"></div>' +
            '</div>' +
            '<button type="button" class="as-toast-close" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>';
        el.querySelector('.as-toast-title').textContent = title;
        el.querySelector('.as-toast-msg').textContent = msg || '';
        toastHost.appendChild(el);
        var dismiss = function () {
            el.classList.add('dismiss');
            setTimeout(function () { el.remove(); }, 300);
        };
        el.querySelector('.as-toast-close').addEventListener('click', dismiss);
        setTimeout(dismiss, 4500);
    }

    <?php if ($success): ?>
    toast('success', 'Saved', <?= json_encode($success) ?>);
    <?php endif; ?>
    <?php if ($error): ?>
    toast('error', 'Something went wrong', <?= json_encode($error) ?>);
    <?php endif; ?>

    /* Tab navigation */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.as-nav-item'));
    var panels = {
        'admin-account':   document.getElementById('panel-admin-account'),
        'change-password': document.getElementById('panel-change-password'),
        'storage':         document.getElementById('panel-storage'),
        'system-info':     document.getElementById('panel-system-info')
    };

    function showTab(key, focusTab) {
        if (!panels[key]) key = 'admin-account';
        tabs.forEach(function (t) {
            var active = t.getAttribute('data-tab') === key;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            t.tabIndex = active ? 0 : -1;
            if (active && focusTab) t.focus();
        });
        Object.keys(panels).forEach(function (k) {
            if (!panels[k]) return;
            var p = panels[k];
            if (k === key) {
                p.removeAttribute('hidden');
                p.classList.remove('as-enter');
                void p.offsetWidth;
                p.classList.add('as-enter');
            } else {
                p.setAttribute('hidden', '');
            }
        });
        if (history.replaceState) history.replaceState(null, '', '#tab=' + encodeURIComponent(key));
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { showTab(t.getAttribute('data-tab'), false); });
        t.addEventListener('keydown', function (e) {
            var i = tabs.indexOf(t);
            var next = null;
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            else if (e.key === 'Home') next = tabs[0];
            else if (e.key === 'End') next = tabs[tabs.length - 1];
            if (next) { e.preventDefault(); showTab(next.getAttribute('data-tab'), true); }
        });
    });

    var hashMatch = /#tab=([^&]+)/.exec(window.location.hash || '');
    var initial = hashMatch ? decodeURIComponent(hashMatch[1]) : <?= json_encode($openTab) ?>;
    if (!panels[initial]) initial = 'admin-account';
    showTab(initial, false);

    /* Password show/hide */
    document.querySelectorAll('.as-eye[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle'));
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    /* Live validation & counters */
    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    document.querySelectorAll('[data-count-for]').forEach(function (counter) {
        var input = document.getElementById(counter.getAttribute('data-count-for'));
        if (!input) return;
        var max = parseInt(counter.getAttribute('data-max') || '0', 10);
        function update() {
            var len = input.value.length;
            counter.textContent = len + ' / ' + max;
            counter.classList.toggle('warn', len > max * .8 && len <= max);
            counter.classList.toggle('danger', len > max);
        }
        input.addEventListener('input', update);
        update();
    });
    document.querySelectorAll('.as-input-wrap[data-validate]').forEach(function (wrap) {
        var type = wrap.getAttribute('data-validate');
        var input = wrap.querySelector('.as-input');
        if (!input) return;
        function check() {
            var v = input.value.trim();
            var ok = false;
            if (type === 'email') ok = emailRe.test(v) && v.length <= 190;
            else if (type === 'name') ok = v.length >= 2 && v.length <= 120;
            wrap.classList.toggle('is-valid', ok);
        }
        input.addEventListener('input', check);
        input.addEventListener('blur', check);
        check();
    });

    /* Password strength */
    var newPass = document.getElementById('as-new-pass');
    var strengthWrap = document.getElementById('as-strength');
    var strengthText = document.getElementById('as-strength-text');
    if (newPass && strengthWrap) {
        var labels = ['—', 'Weak', 'Fair', 'Good', 'Strong'];
        function scorePw(p) {
            if (!p) return 0;
            var s = 0;
            if (p.length >= 8) s++;
            if (p.length >= 12) s++;
            if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
            if (/\d/.test(p)) s++;
            if (/[^A-Za-z0-9]/.test(p)) s++;
            return Math.min(4, s);
        }
        function updateStrength() {
            var s = scorePw(newPass.value);
            strengthWrap.setAttribute('data-score', String(s));
            if (strengthText) strengthText.textContent = labels[s];
        }
        newPass.addEventListener('input', updateStrength);
        updateStrength();
    }

    /* Loading state */
    ['profileForm', 'passwordForm'].forEach(function (id) {
        var form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]') || document.querySelector('button[form="' + id + '"]');
            if (btn) btn.classList.add('is-loading');
        });
    });

    /* Avatar menu + drag-and-drop */
    var avatarBtn = document.getElementById('avatarBtn');
    var avatarMenu = document.getElementById('avatarMenu');
    var uploadBtn = document.getElementById('avatarUploadBtn');
    var removeBtn = document.getElementById('avatarRemoveBtn');
    var removeForm = document.getElementById('avatarRemoveForm');
    var fileInput = document.getElementById('avatarInput');
    var avatarForm = document.getElementById('avatarForm');

    function closeAvatarMenu() {
        if (!avatarMenu) return;
        avatarMenu.hidden = true;
        if (avatarBtn) avatarBtn.setAttribute('aria-expanded', 'false');
    }
    if (avatarBtn && avatarMenu) {
        avatarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = avatarMenu.hidden;
            avatarMenu.hidden = !open;
            avatarBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!avatarMenu.contains(e.target) && !avatarBtn.contains(e.target)) closeAvatarMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAvatarMenu();
        });
    }
    if (uploadBtn && fileInput) uploadBtn.addEventListener('click', function () { closeAvatarMenu(); fileInput.click(); });
    if (fileInput && avatarForm) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) {
                toast('info', 'Uploading', 'Your new photo is being processed…');
                avatarForm.submit();
            }
        });
    }
    if (avatarBtn && fileInput && avatarForm) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            avatarBtn.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); avatarBtn.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            avatarBtn.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); avatarBtn.classList.remove('is-dragover'); });
        });
        avatarBtn.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) return;
            var file = files[0];
            if (!/^image\//.test(file.type)) {
                toast('error', 'Invalid file', 'Please drop an image (JPG, PNG, WEBP, GIF).');
                return;
            }
            try {
                var dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
            } catch (err) {
                toast('error', 'Unsupported', 'Your browser does not support this drop action.');
                return;
            }
            toast('info', 'Uploading', 'Your new photo is being processed…');
            avatarForm.submit();
        });
    }

    /* Copy to clipboard */
    document.querySelectorAll('.as-copy[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector(btn.getAttribute('data-copy'));
            if (!target) return;
            var text = target.textContent.trim();
            var done = function () {
                btn.classList.add('copied');
                var prev = btn.innerHTML;
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
                toast('success', 'Copied', 'Path copied to clipboard.');
                setTimeout(function () { btn.classList.remove('copied'); btn.innerHTML = prev; }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    toast('error', 'Copy failed', 'Please copy the path manually.');
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                ta.remove();
            }
        });
    });

    /* Remove-photo modal */
    var modal = document.getElementById('removeAvatarModal');
    var mCancel = document.getElementById('removeAvatarCancel');
    var mClose = document.getElementById('removeAvatarClose');
    var mConfirm = document.getElementById('removeAvatarConfirm');
    var lastFocus = null;

    function openModal() {
        if (!modal) return;
        lastFocus = document.activeElement;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (mConfirm) mConfirm.focus();
    }
    function closeModal() {
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    if (removeBtn) removeBtn.addEventListener('click', function () { closeAvatarMenu(); openModal(); });
    if (mCancel) mCancel.addEventListener('click', closeModal);
    if (mClose) mClose.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Tab') {
                var f = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (!f.length) return;
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) closeModal();
    });
    if (mConfirm && removeForm) {
        mConfirm.addEventListener('click', function () { mConfirm.disabled = true; removeForm.submit(); });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
