<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

register_shutdown_function(static function () use ($pdo): void {
    syncPhRepository($pdo);
});

$error = '';
$success = '';
$deleteModalError = '';
$deleteModalTarget = null;

if (isset($_GET['msg'])) {
    $success = $_GET['msg'];
}

    // Handle add/edit/delete — all via POST+CSRF; delete requires admin password verification
    // Shared page: manages Program Head (role=department) and Unit Head (role=unit_head) accounts — Admin is on admin_settings.php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrfToken();
        // Secure delete: verify admin password server-side before any delete query
        if (($_POST['action'] ?? '') === 'delete_user') {
            if (!isAdmin()) {
                $error = 'Unauthorized.';
            } else {
                $delId = (int)($_POST['user_id'] ?? 0);
                $adminPass = $_POST['admin_password'] ?? '';
                // preload target for modal re-open on error
                $tmpTarget = null;
                if ($delId > 0) {
                    $tmp = $pdo->prepare('SELECT u.id, u.name, u.email, u.role, d.department_name FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?');
                    $tmp->execute([$delId]);
                    $tmpTarget = $tmp->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if ($delId === 0) {
                    $error = 'Invalid user selection.';
                    $deleteModalError = $error;
                    $deleteModalTarget = $tmpTarget;
                } elseif ($delId === (int)$_SESSION['user_id']) {
                    $error = 'You cannot delete your own account.';
                    $deleteModalError = $error;
                    $deleteModalTarget = $tmpTarget;
                } elseif ($tmpTarget && !in_array($tmpTarget['role'] ?? '', ['department', 'unit_head'], true)) {
                    $error = 'Only Program Head and Unit Head accounts can be deleted from this page.';
                    $deleteModalError = $error;
                    $deleteModalTarget = $tmpTarget;
                } elseif ($adminPass === '') {
                    $error = 'Admin password is required to confirm deletion.';
                    $deleteModalError = $error;
                    $deleteModalTarget = $tmpTarget;
                } else {
                    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
                    $stmt->execute([(int)$_SESSION['user_id']]);
                    $adminRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$adminRow || !password_verify($adminPass, $adminRow['password'])) {
                        $error = 'Incorrect password. Deletion cancelled.';
                        $deleteModalError = 'Incorrect password. Please try again.';
                        $deleteModalTarget = $tmpTarget;
                    } else {
                        if (!$tmpTarget) {
                            $error = 'User not found.';
                            $deleteModalError = $error;
                        } else {
                            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$delId]);
                            logAudit($pdo, 'delete', "User deleted: {$tmpTarget['email']} (id: $delId)", 'user', $delId);
                            header('Location: admin_manage_users.php?msg=User deleted successfully');
                            exit;
                        }
                    }
                }
            }
        } else {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Shared page: only Program Head and Unit Head are creatable here, and only
    // because this page requireAdmin()'s. A role outside the actor's own
    // allow-list (e.g. crafted role=teacher / role=admin) is rejected, not coerced.
    $role = allowedRoleFor($_SESSION['role'] ?? '', $_POST['role'] ?? '');
    if ($role === null) {
        $error = 'Invalid account type selected.';
        $role = 'department'; // never written: $error above blocks every save branch
    }
    $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    // Block crafted edits of non-PH accounts (Admin / Teacher)
    if ($id) {
        $chk = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $chk->execute([$id]);
        $chkRole = $chk->fetchColumn();
        if (!in_array($chkRole, ['department', 'unit_head'], true)) {
            $error = 'Only Program Head and Unit Head accounts can be edited from this page.';
            $id = null;
            $name = $email = $password = '';
            $role = 'department';
            $departmentId = null;
        }
    }

    if ($error === '' && (empty($name) || empty($email))) {
        $error = 'Name and email are required.';
    } elseif ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($error === '' && !$id && empty($password)) {
        $error = 'Password is required for new users.';
    } elseif ($error === '' && $password !== '' && strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($error === '') {
        // --- Email uniqueness check ---
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $id ?? 0]);
        $emailTaken = (bool)$stmt->fetch();

        // --- Name uniqueness check (case-insensitive, trimmed) ---
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id != ?');
        $stmt->execute([$name, $id ?? 0]);
        $nameTaken = (bool)$stmt->fetch();

        // --- One-user-per-department uniqueness check (server-side enforcement) ---
        // Rejects even manually crafted POSTs. When editing, the user's own current
        // department assignment is allowed (id != self is excluded).
        $deptTaken = false;
        if ($departmentId) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE department_id = ? AND id != ? AND role IN ('department','unit_head') LIMIT 1");
            $stmt->execute([$departmentId, $id ?? 0]);
            $deptTaken = (bool)$stmt->fetch();
        }

        if ($emailTaken) {
            $error = 'Email already in use.';
        } elseif ($nameTaken) {
            $error = 'Full name already in use.';
        } elseif ($deptTaken) {
            $error = 'This department already has an assigned user.';
        } else {
            try {
                               if ($id) {
                    if ($departmentId) {
                        if (!empty($password)) {
                            $pdo->prepare('UPDATE users SET name=?, email=?, password=?, role=?, department_id=? WHERE id=?')
                                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $departmentId, $id]);
                        } else {
                            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, department_id=? WHERE id=?')
                                ->execute([$name, $email, $role, $departmentId, $id]);
                        }
                        // Keep the head's repository, folders, and created requirements in the
                        // SAME scope as their session after reassignment.
                        $pdo->prepare('UPDATE ph_repository   SET department_id=? WHERE program_head_id=?')->execute([$departmentId, $id]);
                        $pdo->prepare('UPDATE ph_repo_folders SET department_id=? WHERE program_head_id=?')->execute([$departmentId, $id]);
                        $pdo->prepare('UPDATE ph_requirements SET department_id=? WHERE created_by=?')    ->execute([$departmentId, $id]);
                    } else {
                        if (!empty($password)) {
                            $pdo->prepare('UPDATE users SET name=?, email=?, password=?, role=? WHERE id=?')
                                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
                        } else {
                            $pdo->prepare('UPDATE users SET name=?, email=?, role=? WHERE id=?')
                                ->execute([$name, $email, $role, $id]);
                        }
                    }
                    $oldDeptId = $editUser ? (int)($editUser['department_id'] ?? 0) : 0;
                    if ($departmentId && $departmentId !== $oldDeptId) {
                        $deptName = '';
                        foreach ($departments as $d) {
                            if ((int)$d['id'] === $departmentId) { $deptName = $d['department_name']; break; }
                        }
                        $msg = "You have been assigned as " . phRoleName($role) . " for the department: $deptName.";
                        createNotification($pdo, $id, 'Department Assigned', $msg, 'info');
                        sendEmail($email, 'Department Assignment — CHED Compliance', "<p>Hello " . htmlspecialchars($name) . ",</p><p>$msg</p>");
                    }
                    logAudit($pdo, 'edit', "User updated: $email", 'user', $id);
                    $success = 'User updated successfully.';
                } else {
                    // created_by ties the new account to the creating Admin so the Super Admin
                    // cascade can switch this whole branch off with the Admin.
                    $pdo->prepare('INSERT INTO users (name, email, password, role, department_id, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $departmentId ?: null, (int) $_SESSION['user_id']]);
                    $newId = (int) $pdo->lastInsertId();
                    logAudit($pdo, 'create', "User created: $email (role: $role)", 'user', $newId);

                    // Send Welcome Notification & Email
                    createNotification($pdo, $newId, 'Welcome to CHED Compliance!', 'Your account has been created. Login to view your dashboard.', 'info');
                    if ($departmentId) {
                        $deptName = '';
                        foreach ($departments as $d) {
                            if ((int)$d['id'] === $departmentId) { $deptName = $d['department_name']; break; }
                        }
                        $msg = "You have been assigned as " . phRoleName($role) . " for the department: $deptName.";
                        createNotification($pdo, $newId, 'Department Assigned', $msg, 'info');
                        sendEmail($email, 'Department Assignment — CHED Compliance', "<p>Hello " . htmlspecialchars($name) . ",</p><p>$msg</p>");
                    }

                    $success = 'User created successfully.';
                }
            } catch (PDOException $e) {
                error_log('[CHED] manage_users save failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    }
    }
}

// Fetch Program Head and Unit Head accounts (Admin managed via admin_settings.php)
$users = $pdo->query("
    SELECT u.*, d.department_name 
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    WHERE u.role IN ('department','unit_head')
    ORDER BY u.name
")->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

// Map department_id => currently assigned user (used to block duplicate assignments in the UI)
$deptAssignments = [];
$deptRows = $pdo->query("SELECT id, name, department_id FROM users WHERE department_id IS NOT NULL AND role IN ('department','unit_head')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($deptRows as $row) {
    $deptAssignments[(int)$row['department_id']] = $row;
}

$editUser = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($users as $u) {
        if ((int)$u['id'] === (int)$_GET['edit']) {
            $editUser = $u;
            break;
        }
    }
}

$pageTitle = 'Manage Program & Unit Heads';

ob_start();
?>
<style>
    @import url('offline/inter-font.css');
/* Scoped to this page only — no :root / body leakage (ui-ux-pro-max: minimalism-and-swiss-style) */
.umu-wrap {
    --umu-primary: #197B65;
    --umu-primary-dark: #125A4A;
    --umu-primary-soft: #E8F4F1;
    --umu-ink: #1A202C;
    --umu-body: #2D3748;
    --umu-muted: #5B6B7C;
    --umu-line: #E2E8F0;
    --umu-line-soft: rgba(36,37,39,.07);
    --umu-bg: transparent;
    --umu-card: #FFFFFF;
    --umu-danger: #C53030;
    --umu-danger-bg: #FED7D7;
    --umu-ok: #065F46;
    --umu-ok-bg: #D1FAE5;
    --umu-ring: 0 0 0 3px rgba(25,123,101,.25);
    --umu-radius: 12px;
    --umu-shadow: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.05);
    max-width: 1300px; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--umu-body); line-height: 1.5;
}

.umu-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem; }
.umu-title { margin: 0; font-size: 1.35rem; font-weight: 800; letter-spacing: -.02em; color: var(--umu-ink); }
.umu-sub { margin: .15rem 0 0; font-size: .85rem; color: var(--umu-muted); }
.umu-stats { display: flex; gap: .5rem; flex-wrap: wrap; }
.umu-stat { display: inline-flex; align-items: center; gap: .45rem; background: var(--umu-card);
    border: 1px solid var(--umu-line); border-radius: 9999px; padding: .4rem .8rem;
    font-size: .78rem; font-weight: 600; color: var(--umu-ink); box-shadow: var(--umu-shadow); }
.umu-stat svg { width: 15px; height: 15px; color: var(--umu-primary); }
.umu-stat b { font-weight: 800; }

.umu-grid { display: flex; flex-direction: column; gap: 1rem; }

.umu-card { background: var(--umu-card); border-radius: var(--umu-radius); border: 1px solid var(--umu-line);
    box-shadow: var(--umu-shadow); display: flex; flex-direction: column; overflow: hidden; }
.umu-card-head { padding: .9rem 1.25rem; border-bottom: 1px solid var(--umu-line-soft);
    display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.umu-card-head h3 { font-size: 1rem; font-weight: 700; color: var(--umu-ink); margin: 0;
    display: flex; align-items: center; gap: .55rem; }
.umu-card-head h3 svg { width: 18px; height: 18px; color: var(--umu-primary); }
.umu-card-head p { margin: .15rem 0 0; font-size: .78rem; color: var(--umu-muted); font-weight: 400; }
.umu-card-body { padding: 1.25rem; }
.umu-card-body.flush { padding: 0; }

.umu-alert { padding: .8rem 1rem; border-radius: 10px; font-weight: 500; display: flex;
    align-items: flex-start; gap: .65rem; font-size: .87rem; border: 1px solid transparent; }
.umu-alert svg { flex-shrink: 0; width: 19px; height: 19px; margin-top: 1px; }
.umu-alert-success { background: var(--umu-ok-bg); color: var(--umu-ok); border-color: rgba(5,150,105,.22); }
.umu-alert-danger { background: var(--umu-danger-bg); color: #991B1B; border-color: rgba(229,62,62,.22); }

.umu-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.umu-label { display: block; margin-bottom: .4rem; font-weight: 600; font-size: .8rem; color: #3E4C5E; }
.umu-req { color: var(--umu-danger); }
.umu-hint { font-weight: 400; color: var(--umu-muted); font-size: .75rem; }
.umu-input {
    width: 100%; padding: .68rem .9rem; border: 1px solid var(--umu-line); border-radius: 8px;
    font-family: inherit; font-size: .88rem; background: #FAFAFA; color: var(--umu-ink);
    transition: border-color .2s ease, box-shadow .2s ease, background .2s ease; box-sizing: border-box;
}
input.umu-input, textarea.umu-input { cursor: text; }
select.umu-input { cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%235B6B7C'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right .7rem center; background-size: 15px; padding-right: 2.3rem; }
.umu-input:hover { border-color: #B9CBD1; }
.umu-input:focus { outline: none; border-color: var(--umu-primary); background: #fff; box-shadow: var(--umu-ring); }
.umu-pass-wrap { position: relative; }
.umu-pass-wrap .umu-input { padding-right: 2.6rem; }
.umu-pass-toggle { position: absolute; right: .45rem; top: 50%; transform: translateY(-50%);
    width: 32px; height: 32px; border-radius: 7px; border: 1px solid transparent; background: transparent;
    color: var(--umu-muted); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.umu-pass-toggle:hover { background: #F1F5F9; color: var(--umu-ink); }
.umu-pass-toggle:focus-visible { outline: none; box-shadow: var(--umu-ring); border-color: var(--umu-primary); }
.umu-pass-toggle svg { width: 17px; height: 17px; }

.umu-btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    padding: .68rem 1.15rem; border-radius: 8px; font-size: .85rem; font-weight: 600;
    cursor: pointer; transition: background .2s ease, border-color .2s ease, box-shadow .2s ease;
    border: 1px solid transparent; text-decoration: none; line-height: 1; min-height: 40px; }
.umu-btn:focus-visible { outline: none; box-shadow: var(--umu-ring); }
.umu-btn svg { width: 16px; height: 16px; }
.umu-btn-sm { padding: .5rem .8rem; font-size: .78rem; border-radius: 7px; min-height: 36px; }
.umu-btn-primary { background: var(--umu-primary); color: #fff; }
.umu-btn-primary:hover { background: var(--umu-primary-dark); box-shadow: 0 4px 12px rgba(25,123,101,.22); }
.umu-btn-secondary { background: #fff; color: #3E4C5E; border-color: var(--umu-line); }
.umu-btn-secondary:hover { background: #F8FAFC; color: var(--umu-ink); }
.umu-btn-danger { background: #fff; color: var(--umu-danger); border-color: #F5C2C2; }
.umu-btn-danger:hover { background: var(--umu-danger); color: #fff; border-color: var(--umu-danger); }
.umu-icon-btn { width: 36px; height: 36px; padding: 0; border-radius: 8px; min-height: 0; }
.umu-icon-btn svg { width: 17px; height: 17px; }
.umu-actions { display: flex; align-items: center; justify-content: flex-end; gap: .45rem; }
.umu-form-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1.15rem;
    border-top: 1px solid var(--umu-line-soft); padding-top: 1.1rem; flex-wrap: wrap; }

.umu-toolbar { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
.umu-search { position: relative; min-width: 210px; flex: 1 1 220px; max-width: 340px; }
.umu-search svg { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--umu-muted); pointer-events: none; }
.umu-search input { width: 100%; padding: .6rem .8rem .6rem 2.2rem; border: 1px solid var(--umu-line);
    border-radius: 8px; font-size: .84rem; background: #FAFAFA; color: var(--umu-ink); font-family: inherit; }
.umu-search input:focus { outline: none; border-color: var(--umu-primary); background: #fff; box-shadow: var(--umu-ring); }
.umu-filter { display: flex; gap: .4rem; flex-wrap: wrap; }
.umu-chip { border: 1px solid var(--umu-line); background: #fff; color: #3E4C5E; border-radius: 9999px;
    padding: .42rem .8rem; font-size: .78rem; font-weight: 600; cursor: pointer;
    transition: background .2s ease, border-color .2s ease, color .2s ease; }
.umu-chip:hover { border-color: var(--umu-primary); color: var(--umu-ink); }
.umu-chip[aria-pressed="true"] { background: var(--umu-ink); border-color: var(--umu-ink); color: #fff; }
.umu-chip:focus-visible { outline: none; box-shadow: var(--umu-ring); }
.umu-count { font-size: .78rem; color: var(--umu-muted); }

.umu-table-scroll { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.umu-table { width: 100%; min-width: 840px; border-collapse: collapse; }
.umu-table th { background: #F8FAFC; padding: .7rem 1.15rem; text-align: left; font-size: .72rem; font-weight: 700;
    color: var(--umu-muted); text-transform: uppercase; letter-spacing: .06em;
    border-bottom: 1px solid var(--umu-line); white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.umu-table td { padding: .8rem 1.15rem; border-bottom: 1px solid var(--umu-line-soft);
    vertical-align: middle; font-size: .87rem; color: var(--umu-ink); }
.umu-table tbody tr { transition: background-color .2s ease; }
.umu-table tbody tr:hover { background: var(--umu-primary-soft); }
.umu-table tbody tr:focus-within { background: rgba(25,123,101,.1); }
.umu-table tr:last-child td { border-bottom: none; }

.umu-user { display: flex; align-items: center; gap: .7rem; min-width: 0; }
.umu-avatar { width: 38px; height: 38px; border-radius: 10px; color: #fff; font-weight: 700; font-size: .8rem;
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; letter-spacing: .02em; }
.umu-avatar.dept { background: linear-gradient(135deg, var(--umu-primary) 0%, var(--umu-primary-dark) 100%); }
.umu-user-name { font-weight: 600; color: var(--umu-ink); overflow: hidden; text-overflow: ellipsis; }
.umu-user-email { font-size: .76rem; color: var(--umu-muted); overflow: hidden; text-overflow: ellipsis; }

.umu-badge { display: inline-flex; align-items: center; gap: .4rem; padding: .32rem .7rem;
    border-radius: 9999px; font-size: .73rem; font-weight: 700; white-space: nowrap; }
.umu-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.umu-badge svg { width: 13px; height: 13px; }
.umu-dept { color: var(--umu-muted); font-size: .84rem; }

.umu-empty { text-align: center; padding: 3rem 1.5rem; color: var(--umu-muted); }
.umu-empty svg { width: 52px; height: 52px; color: #CBD5E0; margin-bottom: .7rem; }
.umu-empty p { margin: 0; font-size: .9rem; }
.umu-empty .umu-count { display: block; margin-top: .3rem; }

@media (max-width: 768px) {
    .umu-form-grid { grid-template-columns: 1fr; }
    .umu-card-body { padding: 1rem; }
    .umu-form-actions .umu-btn { flex: 1 1 100%; }
}
/* Delete security modal — outside .umu-wrap so vars must be redeclared here */
.umu-modal-overlay{--umu-danger:#C53030;--umu-danger-bg:#FED7D7;--umu-ink:#1A202C;--umu-muted:#5B6B7C;--umu-line:#E2E8F0;--umu-line-soft:rgba(36,37,39,.07);--umu-ring:0 0 0 3px rgba(25,123,101,.25);position:fixed;inset:0;z-index:2000;background:rgba(17,24,39,.48);display:none;align-items:center;justify-content:center;padding:1.25rem}
.umu-modal-overlay.active{display:flex;animation:umuOverlayIn .2s ease}
@keyframes umuOverlayIn{from{opacity:0}to{opacity:1}}
.umu-modal-box{background:#fff;border-radius:14px;border:1px solid var(--umu-line);box-shadow:0 20px 60px rgba(0,0,0,.18),0 8px 24px rgba(0,0,0,.08);width:100%;max-width:440px;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;animation:umuBoxIn .28s cubic-bezier(.16,1,.3,1)}
@keyframes umuBoxIn{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.umu-modal-head{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--umu-line-soft);background:linear-gradient(180deg,#fff 0%,#f9fafb 100%)}
.umu-modal-head h2{font-size:1rem;font-weight:700;color:var(--umu-ink);margin:0;display:flex;align-items:center;gap:.55rem;letter-spacing:-.01em}
.umu-modal-head h2 svg{width:19px;height:19px;color:var(--umu-danger)}
.umu-modal-close{display:inline-flex;align-items:center;justify-content:center;background:#fff;border:1px solid var(--umu-line);cursor:pointer;width:32px;height:32px;border-radius:8px;color:var(--umu-muted);transition:background .2s ease,color .2s ease}
.umu-modal-close:hover{background:#F1F5F9;color:var(--umu-ink)}
.umu-modal-close:focus-visible{outline:none;box-shadow:var(--umu-ring)}
.umu-modal-close svg{width:16px;height:16px}
.umu-modal-body{padding:1.25rem;overflow:auto}
.umu-modal-body p{margin:0;font-size:.88rem;color:#3E4C5E;line-height:1.5}
.umu-modal-target{background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:.85rem 1rem;margin-top:.85rem;display:flex;align-items:center;gap:.75rem}
.umu-modal-target-ic{width:38px;height:38px;border-radius:10px;background:#fff;border:1px solid #FECACA;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.umu-modal-target-ic svg{width:18px;height:18px;color:var(--umu-danger)}
.umu-modal-target b{display:block;font-size:.88rem;color:var(--umu-ink);word-break:break-word}
.umu-modal-target span{display:block;font-size:.76rem;color:var(--umu-muted);word-break:break-all}
.umu-modal-field{margin-top:1rem}
.umu-modal-field label{display:block;margin-bottom:.4rem;font-weight:600;font-size:.8rem;color:#3E4C5E}
.umu-modal-warn{margin-top:.65rem;font-size:.78rem;color:var(--umu-danger);font-weight:600}
.umu-modal-foot{padding:.9rem 1.25rem;border-top:1px solid var(--umu-line-soft);display:flex;gap:.6rem;justify-content:flex-end;background:#F8FAFC;flex-wrap:wrap}
.umu-btn-danger-solid{background:#C53030;color:#fff;border-color:#C53030}
.umu-btn-danger-solid:hover{background:#9B2C2C;border-color:#9B2C2C}
.umu-btn-danger-solid:disabled{opacity:.6;cursor:not-allowed}

/* Success toast — top banner, hugs text, no backdrop, no scroll lock */
.umu-modal-overlay#successModal{background:none;padding:0;align-items:flex-start;justify-content:center}
.umu-modal-overlay#successModal.active{animation:none}
.umu-modal-overlay#successModal .umu-modal-box{width:auto;max-width:calc(100vw - 2rem);max-height:none;margin-top:16px;border-radius:10px;border:1px solid #bbf7d0;border-left:4px solid #16a34a;background:#f0fdf4;box-shadow:0 12px 32px rgba(15,23,42,.16);animation:umuToastDrop .26s cubic-bezier(.2,.9,.3,1)}
@keyframes umuToastDrop{from{transform:translateY(-18px);opacity:0}to{transform:translateY(0);opacity:1}}
.umu-toast{display:flex;align-items:center;gap:.55rem;margin:0;padding:.7rem .75rem .7rem .9rem;font-size:.88rem;font-weight:600;color:#14532d}
.umu-toast>svg{flex-shrink:0;color:#16a34a}
.umu-toast-dismiss{margin-left:.35rem;flex-shrink:0;border:0;background:transparent;color:#4d7c5f;cursor:pointer;padding:.2rem;border-radius:6px;display:flex}
.umu-toast-dismiss:hover{background:rgba(22,163,74,.12);color:#166534}
@media (prefers-reduced-motion: reduce) {
    .umu-wrap *, .umu-wrap *::before, .umu-wrap *::after { animation: none !important; transition: none !important; }
}
</style>
<?php
$extraStyles = ob_get_clean();
$extraScripts = <<<'JS'
<script nonce="<?= csp_nonce() ?>">
(function(){
  var search = document.getElementById('umuSearch');
  var chips = document.querySelectorAll('.umu-chip');
  var rows = document.querySelectorAll('#umuTableBody tr');
  var count = document.getElementById('umuCount');
  var empty = document.getElementById('umuNoResult');
  var activeRole = 'all';
  function apply(){
    var q = (search ? search.value : '').trim().toLowerCase();
    var visible = 0;
    rows.forEach(function(r){
      var hay = (r.getAttribute('data-search') || '').toLowerCase();
      var role = r.getAttribute('data-role') || '';
      var okQ = !q || hay.indexOf(q) !== -1;
      var okR = activeRole === 'all' || role === activeRole;
      var show = okQ && okR;
      r.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (count) count.textContent = visible + ' shown';
    if (empty) empty.style.display = visible === 0 ? '' : 'none';
  }
  if (search) search.addEventListener('input', apply);
  chips.forEach(function(c){
    c.addEventListener('click', function(){
      activeRole = c.getAttribute('data-filter');
      chips.forEach(function(x){ x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
var roleSel = document.getElementById('umu-role');
  var submitTxt = document.getElementById('umuSubmitText');
  // Button label follows the chosen role title so Create/Update names the right role.
  if (roleSel && submitTxt) {
    // verb from the rendered label ("Create Program Head" / "Update ...") - this heredoc is nowdoc, no PHP interpolation
    var verb = (submitTxt.textContent || '').trim().split(' ')[0];
    var syncRoleLabel = function(){
      submitTxt.textContent = verb + ' ' + roleSel.options[roleSel.selectedIndex].text;
    };
    roleSel.addEventListener('change', syncRoleLabel);
    syncRoleLabel();
  }
  apply();
    });
  });
  var toggle = document.getElementById('umuPassToggle');
  var pass = document.getElementById('umu-pass');
  if (toggle && pass) toggle.addEventListener('click', function(){
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    var slash = toggle.querySelector('.umu-pass-slash');
    if (slash) slash.style.display = show ? 'none' : '';
  });
  var deptField = document.getElementById('umuDeptField');
  var deptSelect = document.getElementById('umu-dept');
  var deptHint = document.getElementById('umuDeptHint');
  // Frontend guard: block submit if a taken department somehow became selectable.
  var userForm = document.querySelector('.umu-wrap form');
  if (userForm && deptSelect) {
    userForm.addEventListener('submit', function(e){
      var opt = deptSelect.options[deptSelect.selectedIndex];
      if (opt && opt.disabled) {
        e.preventDefault();
        if (deptHint) {
          deptHint.textContent = 'This department already has an assigned user.';
          deptHint.style.color = '#C53030';
        }
        deptSelect.focus();
      }
    });
  }
  apply();
})();
</script>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var modal=document.getElementById('umuDeleteModal');
  if(!modal) return;
  var form=document.getElementById('umuDeleteForm');
  var inpId=document.getElementById('umuDeleteUserId');
  var inpPass=document.getElementById('umuDeletePass');
  var cancelBtn=document.getElementById('umuDeleteCancel');
  var inlineErr=document.getElementById('umuDeleteInlineError');
  var lastTrigger=null;
  function showInline(msg){
    if(!inlineErr) return;
    inlineErr.textContent=msg;
    inlineErr.style.display=msg?'':'none';
  }
  function openModal(btn){
    inpId.value=btn.getAttribute('data-user-id')||'';
    inpPass.value='';
    showInline('');
    inpPass.style.borderColor='';
    modal.classList.add('active');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
    lastTrigger=btn;
    setTimeout(function(){inpPass.focus();},80);
  }
  function closeModal(){
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow='';
    inpPass.value='';
    showInline('');
    inpPass.style.borderColor='';
    if(lastTrigger&&lastTrigger.focus) lastTrigger.focus();
    lastTrigger=null;
  }
  document.querySelectorAll('.js-umu-delete-btn').forEach(function(b){
    b.addEventListener('click', function(){ openModal(b); });
  });
  if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape'&&modal.classList.contains('active')) closeModal(); });
  if(form) form.addEventListener('submit', function(e){
    if(!inpPass.value.trim()){
      e.preventDefault();
      showInline('Please enter your admin password.');
      inpPass.focus(); inpPass.style.borderColor='#C53030';
    }
  });
  if(inpPass) inpPass.addEventListener('input', function(){ this.style.borderColor=''; showInline(''); });
})();
</script>
JS;
$extraScripts = str_replace('<?= csp_nonce() ?>', csp_nonce(), $extraScripts);
include 'includes/header.php';
?>
<div class="umu-wrap">
<?php if ($error && !$deleteModalError): ?>
<div id="umuTopError" class="umu-alert umu-alert-danger" role="alert" aria-live="assertive">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
    <span><?= sanitize($error) ?></span>
</div>
<?php endif; ?>




<div class="umu-grid">
    <div class="umu-card">
    
        <div class="umu-card-body">
            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <?php if ($editUser): ?><input type="hidden" name="id" value="<?= $editUser['id'] ?>"><?php endif; ?>

                <div class="umu-form-grid">
                    <div>
                        <label class="umu-label" for="umu-name">Full name <span class="umu-req" aria-hidden="true">*</span></label>
                        <input type="text" id="umu-name" name="name" class="umu-input" required placeholder="e.g. Juan Dela Cruz"
                            value="<?= sanitize($editUser['name'] ?? '') ?>" autocomplete="name">
                    </div>

                    <div>
                        <label class="umu-label" for="umu-email">Email address <span class="umu-req" aria-hidden="true">*</span></label>
                        <input type="email" id="umu-email" name="email" class="umu-input" required placeholder="name@example.com"
                            value="<?= sanitize($editUser['email'] ?? '') ?>" autocomplete="email">
                    </div>

                    <div>
                        <label class="umu-label" for="umu-pass">Password <?= $editUser ? '<span class="umu-hint">(leave blank to keep current)</span>' : '<span class="umu-req" aria-hidden="true">*</span>' ?></label>
                        <div class="umu-pass-wrap">
                            <input type="password" id="umu-pass" name="password" class="umu-input" <?= $editUser ? '' : 'required' ?> placeholder="••••••••" autocomplete="new-password">
                            <button type="button" class="umu-pass-toggle" id="umuPassToggle" aria-label="Show password" aria-pressed="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path class="umu-pass-slash" stroke-linecap="round" d="M4 4l16 16" style="display:none" /></svg>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="umu-label" for="umu-role">Role <span class="umu-req" aria-hidden="true">*</span></label>
                        <?php $rl = ($editUser['role'] ?? '') === 'unit_head' ? 'unit_head' : 'department'; ?>
                        <select name="role" id="umu-role" class="umu-input" required>
                            <option value="department" <?= $rl === 'department' ? 'selected' : '' ?>>Program Head</option>
                            <option value="unit_head" <?= $rl === 'unit_head' ? 'selected' : '' ?>>Unit Head</option>
                        </select>
                    </div>

                    <div id="umuDeptField">
                        <label class="umu-label" for="umu-dept">Department access</label>
                        <select name="department_id" id="umu-dept" class="umu-input">
                            <option value="">-- Select department --</option>
                            <?php foreach ($departments as $d):
                                $deptId = (int)$d['id'];
                                $assigned = $deptAssignments[$deptId] ?? null;
                                $isCurrentUsersDept = $editUser && (int)($editUser['department_id'] ?? 0) === $deptId;
                                $isTaken = $assigned && !$isCurrentUsersDept;
                                $deptLabel = sanitize($d['department_name']);
                                if ($isTaken) {
                                    $deptLabel .= ' — already assigned to ' . sanitize($assigned['name']);
                                }
                            ?>
                                <option value="<?= $deptId ?>"
                                    <?= (($editUser['department_id'] ?? '') == $deptId) ? 'selected' : '' ?>
                                    <?= $isTaken ? 'disabled' : '' ?>>
                                    <?= $deptLabel ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="umu-hint" id="umuDeptHint" style="margin:.4rem 0 0;">Department can be assigned now or updated later.</p>
                    </div>

                </div>

                <div class="umu-form-actions">
                    <?php if ($editUser): ?><a href="admin_manage_users.php" class="umu-btn umu-btn-secondary">Cancel</a><?php endif; ?>
                    <button type="submit" class="umu-btn umu-btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        <span id="umuSubmitText"><?= $editUser ? 'Update ' . sanitize(phRoleName($editUser['role'])) : 'Create ' . sanitize(phRoleName('department')) ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="umu-card">
        <div class="umu-card-head">
            <h3><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg><?= phRoleName('department') ?> &amp; <?= phRoleName('unit_head') ?> accounts (<?= count($users) ?>)</h3>
            <span class="umu-count" id="umuCount" role="status"><?= count($users) ?> shown</span>
        </div>
        <div class="umu-card-head" style="border-bottom:1px solid var(--umu-line-soft); background:#FCFDFD;">
            <div class="umu-toolbar" style="width:100%;">
                <div class="umu-search">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    <input type="search" id="umuSearch" placeholder="Search name or email…" aria-label="Search Program and Unit Heads">
                </div>
            </div>
        </div>
        <div class="umu-card-body flush">
            <?php if (empty($users)): ?>
                <div class="umu-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    <p>No <?= phRoleName('department') ?> or <?= phRoleName('unit_head') ?> accounts found.</p>
                </div>
            <?php else: ?>
                <div class="umu-table-scroll">
                    <table class="umu-table">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Role</th>
                                <th scope="col">Department</th>
                                <th scope="col" style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="umuTableBody">
                            <?php foreach ($users as $u): ?>
                            <?php
                                $parts = preg_split('/\s+/', trim($u['name']));
                                $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
                                if ($initials === '') $initials = strtoupper(substr($u['name'], 0, 1));
                                $roleName = phRoleName($u['role']);
                            ?>
                        <tr data-role="<?= sanitize($u['role']) ?>" data-search="<?= sanitize($u['name'] . ' ' . $u['email'] . ' ' . ($u['department_name'] ?? '') . ' ' . $roleName) ?>" data-row-id="user:<?= (int)$u['id'] ?>">
                                <td>
                                    <div class="umu-user">
                                        <div class="umu-avatar dept" aria-hidden="true"><?= $initials ?></div>
                                        <div style="min-width:0;">
                                            <div class="umu-user-name"><?= sanitize($u['name']) ?></div>
                                            <div class="umu-user-email"><?= sanitize($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="umu-badge" style="background:var(--umu-primary-soft);color:var(--umu-primary-dark);"><span class="dot"></span><?= sanitize($roleName) ?></span></td>
                                <td><span class="umu-dept"><?= sanitize($u['department_name'] ?: '—') ?></span></td>
                                <td>
                                    <div class="umu-actions">
                                        <a href="?edit=<?= $u['id'] ?>" class="umu-btn umu-btn-sm umu-btn-secondary umu-icon-btn" title="Edit <?= sanitize($u['name']) ?>" aria-label="Edit <?= sanitize($u['name']) ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                        </a>
                                        <button type="button" class="umu-btn umu-btn-sm umu-btn-danger umu-icon-btn js-umu-delete-btn" title="Delete <?= sanitize($u['name']) ?>" aria-label="Delete <?= sanitize($u['name']) ?>" aria-haspopup="dialog"
                                            data-user-id="<?= $u['id'] ?>" data-user-name="<?= sanitize($u['name']) ?>" data-user-email="<?= sanitize($u['email']) ?>" data-user-role="<?= sanitize($u['role']) ?>" data-user-dept="<?= sanitize($u['department_name'] ?: '—') ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="umu-empty" id="umuNoResult" style="display:none;">
                    <p>No <?= phRoleName('department') ?>s or <?= phRoleName('unit_head') ?>s match your search.</p>
                    <span class="umu-count">Try a different name, email, or department.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<div class="umu-modal-overlay" id="umuDeleteModal" aria-hidden="true">
    <div class="umu-modal-box" role="dialog" aria-modal="true" aria-labelledby="umuDeleteTitle">
        <div class="umu-modal-head">
            <h2 id="umuDeleteTitle">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                Confirm deletion
            </h2>
        </div>
        <form method="POST" id="umuDeleteForm" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" id="umuDeleteUserId" value="<?= $deleteModalTarget ? (int)$deleteModalTarget['id'] : '' ?>">
            <div class="umu-modal-body">
                <p>You are about to <strong>permanently delete</strong> the following account. This cannot be undone.</p>
                <?php if ($deleteModalError): ?>
                <div id="umuDeleteInlineError" class="umu-alert umu-alert-danger" role="alert" style="margin-top:.85rem"><?= sanitize($deleteModalError) ?></div>
                <?php else: ?>
                <div id="umuDeleteInlineError" class="umu-alert umu-alert-danger" role="alert" style="margin-top:.85rem;display:none"></div>
                <?php endif; ?>
                <div class="umu-modal-field">
                    <label for="umuDeletePass">Confirm with your admin password <span style="color:var(--umu-danger)">*</span></label>
                    <input type="text" name="username" value="<?= sanitize($_SESSION['email'] ?? '') ?>" autocomplete="username" tabindex="-1" hidden>
                    <input type="password" name="admin_password" id="umuDeletePass" class="umu-input" required placeholder="Enter your password" autocomplete="current-password" style="<?= $deleteModalError ? 'border-color:#C53030' : '' ?>">
                </div>
                <p class="umu-modal-warn">This action will permanently remove the user and their access.</p>
            </div>
            <div class="umu-modal-foot">
                <button type="button" class="umu-btn umu-btn-secondary" id="umuDeleteCancel">Cancel</button>
                <button type="submit" class="umu-btn umu-btn-danger-solid">Delete user</button>
            </div>
        </form>
    </div>
</div>

<?php if ($deleteModalError): ?>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var m=document.getElementById('umuDeleteModal');
  if(m){ m.classList.add('active'); m.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; var p=document.getElementById('umuDeletePass'); if(p) setTimeout(function(){p.focus();},80); }
})();
</script>
<?php endif; ?>
<div class="umu-modal-overlay" id="successModal" aria-hidden="true">
    <div class="umu-modal-box" role="status" aria-live="polite">
        <div class="umu-toast">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span id="successMsg">Done.</span>
            <button type="button" class="umu-toast-dismiss" data-csp-action="closeUserSuccessToast" aria-label="Dismiss">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
</div>
<script nonce="<?= csp_nonce() ?>">
    var __umuSuccessTimer = null;
    function closeUserSuccessToast() {
        var m = document.getElementById('successModal');
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
        clearTimeout(__umuSuccessTimer);
    }
    <?php if ($success): ?>
        document.getElementById('successMsg').textContent = <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.getElementById('successModal').classList.add('active');
        document.getElementById('successModal').setAttribute('aria-hidden', 'false');
        __umuSuccessTimer = setTimeout(closeUserSuccessToast, 2600);
    <?php endif; ?>
    <?php if ($error && !$deleteModalError): ?>
        setTimeout(function(){ var e = document.getElementById('umuTopError'); if (e) e.remove(); }, 1000);
    <?php endif; ?>
</script>
<?php include 'includes/footer.php'; ?>
