<?php
define('CHED_APP', true);
require_once 'config.php';

// Every request — page load and state-changing POST alike — passes this gate
// first. Role comes from the users row on every call, never from the session
// alone, so a regular Admin / Program Head / Unit Head / Staff / Faculty
// session is redirected before it can read or change anything here.
requireSuperAdmin();

$error   = '';
$success = '';

// Function: adminSubtreeIds
// Every account the Super Admin can switch off hangs off an Admin through
// users.created_by (Admin -> Program Head/Unit Head -> Faculty/Staff). Walks
// that tree breadth-first so one toggle covers the whole branch.
// ponytail: iterative + visited set, not a recursive CTE, so it works on any
// MySQL/MariaDB the app is dropped onto and can never spin on a bad row.
function adminSubtreeIds(PDO $pdo, int $adminId): array
{
    $ids    = [$adminId];
    $seen   = [$adminId => true];
    $queue  = [$adminId];
    $lookup = $pdo->prepare('SELECT id FROM users WHERE created_by = ?');

    while ($queue) {
        $current = array_shift($queue);
        $lookup->execute([$current]);
        foreach ($lookup->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $childId = (int) $childId;
            if (isset($seen[$childId])) {
                continue;
            }
            $seen[$childId] = true;
            $ids[]          = $childId;
            $queue[]        = $childId;
        }
    }
    return $ids;
}

$success = match ((string) ($_GET['msg'] ?? '')) {
    'created' => 'Administrator account created.',
    'updated' => 'Administrator account updated.',
    'toggled' => 'Account status updated.',
    default   => '',
};

// Form state: keeps what was typed when validation fails, otherwise the row being edited.
$formName  = '';
$formEmail = '';
$formId    = null;
$editUser  = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([(int) $_GET['edit']]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editUser) {
        $formId    = (int) $editUser['id'];
        $formName  = (string) $editUser['name'];
        $formEmail = (string) $editUser['email'];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// State-changing actions — POST only, CSRF verified, role admin is the ONLY
// value ever written (it is a SQL literal below, never read from input).
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (in_array($action, ['header_upload_avatar', 'header_remove_avatar'], true)) {
        // Rendered and handled by includes/header.php further down.
        $success = '';
    } elseif ($action === 'save_admin') {
        $id    = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass  = (string) ($_POST['password'] ?? '');

        $formId    = $id;
        $formName  = $name;
        $formEmail = $email;

        // A crafted id can only ever land on an Admin row — every other role is
        // refused before any read or write happens.
        if ($id) {
            $chk = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $chk->execute([$id]);
            if (($chk->fetchColumn() ?: null) !== 'admin') {
                $error = 'Only Administrator accounts can be managed from this page.';
                $id    = null;
            }
        }

        if ($error === '') {
            if ($name === '' || $email === '') {
                $error = 'Name and email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } elseif ($id === null && $pass === '') {
                $error = 'Password is required for new accounts.';
            } elseif ($pass !== '' && strlen($pass) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                $stmt->execute([$email, $id ?? 0]);
                if ($stmt->fetch()) {
                    $error = 'Email already in use.';
                } else {
                    try {
                        if ($id) {
                            if ($pass !== '') {
                                $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ? AND role = 'admin'")
                                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $id]);
                            } else {
                                $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ? AND role = 'admin'")
                                    ->execute([$name, $email, $id]);
                            }
                            logAudit($pdo, 'edit', "Admin account updated: $email (id: $id)", 'user', $id);
                            header('Location: super_admin_dashboard.php?msg=updated');
                            exit;
                        }
                        // 'admin' is hardcoded: no request can create any other role here.
                        $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')")
                            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                        $newId = (int) $pdo->lastInsertId();
                        logAudit($pdo, 'create', "Admin account created: $email", 'user', $newId);
                        header('Location: super_admin_dashboard.php?msg=created');
                        exit;
                    } catch (PDOException $e) {
                        // 23000 = the unique email key lost a race; anything else stays generic.
                        error_log('[CHED Compliance] super_admin save failed: ' . $e->getMessage());
                        $error = ($e->getCode() === '23000')
                            ? 'Email already in use.'
                            : 'Database error. Please try again.';
                    }
                }
            }
        }
    } elseif ($action === 'set_active') {
        $tid   = (int) ($_POST['user_id'] ?? 0);
        $value = in_array((string) ($_POST['value'] ?? ''), ['0', '1'], true) ? (int) $_POST['value'] : null;

        if ($value === null) {
            $error = 'Invalid status value.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
                $stmt->execute([$tid]);
                if (!$stmt->fetch()) {
                    $error = 'Administrator account not found.';
                } else {
                    // Cascade: an Admin's whole branch follows it. Staff/Faculty hang off a
                    // Program Head/Unit Head via created_by, so one walk covers every level.
                    $branchIds = adminSubtreeIds($pdo, $tid);
                    $inList    = implode(',', array_map('intval', $branchIds));

                    // UPDATE carries the same admin-only guard as the SELECT above, so a row
                    // that is not an Admin can never be touched even if the tree lies.
                    $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'admin'")
                        ->execute([$value, $tid]);
                    $pdo->prepare("UPDATE users SET is_active = ? WHERE id IN ($inList) AND id != ? AND created_by IS NOT NULL")
                        ->execute([$value, $tid]);

                    logAudit($pdo, $value ? 'admin_activated' : 'admin_deactivated',
                        ($value ? 'Admin activated: ' : 'Admin deactivated: ') . "id=$tid"
                        . ' (branch of ' . count($branchIds) . ' account(s) set to ' . ($value ? 'active' : 'inactive') . ')',
                        'user', $tid);
                    header('Location: super_admin_dashboard.php?msg=toggled');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('[CHED Compliance] super_admin status toggle failed: ' . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        }
    } else {
        $error = 'Invalid request.';
    }
}

// Admin accounts only — the Super Admin's own row (role super_admin) is not listed.
$users = $pdo->query("
    SELECT id, name, email, is_active, last_login_at, active_session_token, active_session_last_seen
      FROM users
     WHERE role = 'admin'
     ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// How many accounts ride along with each Admin (Program Head / Unit Head and the
// Staff/Faculty under them). Shown before the Super Admin commits to a toggle.
foreach ($users as &$u) {
    $u['branch_count'] = count(adminSubtreeIds($pdo, (int) $u['id'])) - 1;
}
unset($u);

$pageTitle = 'Administrator Accounts';

ob_start();
?>
<style>
    @import url('offline/inter-font.css');
/* Scoped to this page only (same token set as admin_manage_users.php) */
.sad-wrap {
    --sad-primary: #197B65;
    --sad-primary-dark: #125A4A;
    --sad-primary-soft: #E8F4F1;
    --sad-ink: #1A202C;
    --sad-body: #2D3748;
    --sad-muted: #5B6B7C;
    --sad-line: #E2E8F0;
    --sad-line-soft: rgba(36,37,39,.07);
    --sad-card: #FFFFFF;
    --sad-danger: #C53030;
    --sad-danger-bg: #FED7D7;
    --sad-ok: #065F46;
    --sad-ok-bg: #D1FAE5;
    --sad-ring: 0 0 0 3px rgba(25,123,101,.25);
    max-width: 1300px; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--sad-body); line-height: 1.5;
}

.sad-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem; }
.sad-title { margin: 0; font-size: 1.35rem; font-weight: 800; letter-spacing: -.02em; color: var(--sad-ink); }
.sad-sub { margin: .15rem 0 0; font-size: .85rem; color: var(--sad-muted); }
.sad-grid { display: flex; flex-direction: column; gap: 1rem; }

.sad-card { background: var(--sad-card); border-radius: 12px; border: 1px solid var(--sad-line);
    box-shadow: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.05); display: flex; flex-direction: column; overflow: hidden; }
.sad-card-head { padding: .9rem 1.25rem; border-bottom: 1px solid var(--sad-line-soft);
    display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.sad-card-head h3 { font-size: 1rem; font-weight: 700; color: var(--sad-ink); margin: 0; }
.sad-card-head p { margin: .15rem 0 0; font-size: .78rem; color: var(--sad-muted); font-weight: 400; }
.sad-card-body { padding: 1.25rem; }
.sad-card-body.flush { padding: 0; }

.sad-alert { padding: .8rem 1rem; border-radius: 10px; font-weight: 500; font-size: .87rem;
    border: 1px solid transparent; display: flex; align-items: flex-start; gap: .65rem; }
.sad-alert-danger { background: var(--sad-danger-bg); color: #991B1B; border-color: rgba(229,62,62,.22); }
.sad-alert-success { background: var(--sad-ok-bg); color: var(--sad-ok); border-color: rgba(5,150,105,.22); }

.sad-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.sad-label { display: block; margin-bottom: .4rem; font-weight: 600; font-size: .8rem; color: #3E4C5E; }
.sad-req { color: var(--sad-danger); }
.sad-hint { font-weight: 400; color: var(--sad-muted); font-size: .75rem; }
.sad-input { width: 100%; padding: .68rem .9rem; border: 1px solid var(--sad-line); border-radius: 8px;
    font-family: inherit; font-size: .88rem; background: #FAFAFA; color: var(--sad-ink);
    transition: border-color .2s ease, box-shadow .2s ease, background .2s ease; box-sizing: border-box; }
.sad-input:hover { border-color: #B9CBD1; }
.sad-input:focus { outline: none; border-color: var(--sad-primary); background: #fff; box-shadow: var(--sad-ring); }

.sad-btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    padding: .68rem 1.15rem; border-radius: 8px; font-size: .85rem; font-weight: 600; cursor: pointer;
    transition: background .2s ease, border-color .2s ease, box-shadow .2s ease; border: 1px solid transparent;
    text-decoration: none; line-height: 1; min-height: 40px; }
.sad-btn:focus-visible { outline: none; box-shadow: var(--sad-ring); }
.sad-btn svg { width: 16px; height: 16px; }
.sad-btn-sm { padding: .5rem .8rem; font-size: .78rem; border-radius: 7px; min-height: 36px; }
.sad-btn-primary { background: var(--sad-primary); color: #fff; }
.sad-btn-primary:hover { background: var(--sad-primary-dark); box-shadow: 0 4px 12px rgba(25,123,101,.22); }
.sad-btn-secondary { background: #fff; color: #3E4C5E; border-color: var(--sad-line); }
.sad-btn-secondary:hover { background: #F8FAFC; color: var(--sad-ink); }
.sad-btn-danger { background: #fff; color: var(--sad-danger); border-color: #F5C2C2; }
.sad-btn-danger:hover { background: var(--sad-danger); color: #fff; border-color: var(--sad-danger); }
.sad-icon-btn { width: 36px; height: 36px; padding: 0; border-radius: 8px; min-height: 0; }
.sad-icon-btn svg { width: 17px; height: 17px; }
.sad-actions { display: flex; align-items: center; justify-content: flex-end; gap: .45rem; }
.sad-form-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1.15rem;
    border-top: 1px solid var(--sad-line-soft); padding-top: 1.1rem; flex-wrap: wrap; }

.sad-table-scroll { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.sad-table { width: 100%; min-width: 720px; border-collapse: collapse; }
.sad-table th { background: #F8FAFC; padding: .7rem 1.15rem; text-align: left; font-size: .72rem; font-weight: 700;
    color: var(--sad-muted); text-transform: uppercase; letter-spacing: .06em;
    border-bottom: 1px solid var(--sad-line); white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.sad-table td { padding: .8rem 1.15rem; border-bottom: 1px solid var(--sad-line-soft);
    vertical-align: middle; font-size: .87rem; color: var(--sad-ink); }
.sad-table tbody tr:hover { background: var(--sad-primary-soft); }
.sad-table tr:last-child td { border-bottom: none; }

.sad-user { display: flex; align-items: center; gap: .7rem; min-width: 0; }
.sad-avatar { width: 38px; height: 38px; border-radius: 10px; color: #fff; font-weight: 700; font-size: .8rem;
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    background: linear-gradient(135deg, var(--sad-primary) 0%, var(--sad-primary-dark) 100%); }
.sad-user-name { font-weight: 600; color: var(--sad-ink); overflow: hidden; text-overflow: ellipsis; }
.sad-user-email { font-size: .76rem; color: var(--sad-muted); overflow: hidden; text-overflow: ellipsis; }

.sad-badge { display: inline-flex; align-items: center; gap: .4rem; padding: .32rem .7rem;
    border-radius: 9999px; font-size: .73rem; font-weight: 700; white-space: nowrap; }
.sad-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.sad-meta { color: var(--sad-muted); font-size: .84rem; }

.sad-empty { text-align: center; padding: 3rem 1.5rem; color: var(--sad-muted); }
.sad-empty p { margin: 0; font-size: .9rem; }

@media (max-width: 768px) {
    .sad-form-grid { grid-template-columns: 1fr; }
    .sad-card-body { padding: 1rem; }
    .sad-form-actions .sad-btn { flex: 1 1 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .sad-wrap *, .sad-wrap *::before, .sad-wrap *::after { animation: none !important; transition: none !important; }
}
</style>
<?php
$extraStyles = ob_get_clean();

$extraScripts = <<<'JS'
<script nonce="<?= csp_nonce() ?>">
(function () {
  // Destructive-ish action guard (no inline handlers: CSP script-src 'self').
  document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
})();
</script>
<script nonce="<?= csp_nonce() ?>">
(function () {
  // shortcut: lightweight 5s poll updates only badges, not full reload.
  // upgrade to WebSocket if real-time is required continuously.
  function pollStatus() {
    fetch('status_poll.php', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.admins) return;
        data.admins.forEach(function (admin) {
          var badge = document.querySelector('[data-status-id="' + admin.id + '"]');
          if (!badge) return;
          var online = !!admin.online;
          badge.innerHTML = '<span class="dot" style="background:' + (online ? '#065F46' : '#9CA3AF') + ';"></span>' + (online ? 'Online' : 'Offline');
          badge.style.background = online ? 'var(--sad-ok-bg)' : '#F3F4F6';
          badge.style.color = online ? 'var(--sad-ok)' : '#6B7280';
        });
      })
      .catch(function () {});
  }
  setInterval(pollStatus, 5000);
})();
</script>
JS;
$extraScripts = str_replace('<?= csp_nonce() ?>', csp_nonce(), $extraScripts);

include 'includes/header.php';
?>
<div class="sad-wrap">

    <div class="sad-head">
        <div>
            <h1 class="sad-title">Administrator Accounts</h1>
            <p class="sad-sub">Create, review, and activate or deactivate Administrator accounts.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="sad-alert sad-alert-danger" role="alert" aria-live="assertive">
            <span><?= sanitize($error) ?></span>
        </div>
    <?php elseif ($success): ?>
        <div class="sad-alert sad-alert-success" role="status" aria-live="polite">
            <span><?= sanitize($success) ?></span>
        </div>
    <?php endif; ?>

    <div class="sad-grid">

        <div class="sad-card">
            <div class="sad-card-head">
                <div>
                    <h3><?= $editUser ? 'Edit Administrator' : 'New Administrator' ?></h3>
                    <p><?= $editUser ? 'Updating ' . sanitize($editUser['name']) : 'New accounts are created with the Administrator role only.' ?></p>
                </div>
                <?php if ($editUser): ?>
                    <a href="super_admin_dashboard.php" class="sad-btn sad-btn-sm sad-btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
            <div class="sad-card-body">
                <form method="POST" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_admin">
                    <?php if ($formId): ?><input type="hidden" name="id" value="<?= (int) $formId ?>"><?php endif; ?>

                    <div class="sad-form-grid">
                        <div>
                            <label class="sad-label" for="sad-name">Full name <span class="sad-req" aria-hidden="true">*</span></label>
                            <input type="text" id="sad-name" name="name" class="sad-input" required
                                   placeholder="e.g. Juan Dela Cruz" value="<?= sanitize($formName) ?>" autocomplete="name">
                        </div>
                        <div>
                            <label class="sad-label" for="sad-email">Email address <span class="sad-req" aria-hidden="true">*</span></label>
                            <input type="email" id="sad-email" name="email" class="sad-input" required
                                   placeholder="name@example.com" value="<?= sanitize($formEmail) ?>" autocomplete="email">
                        </div>
                        <div>
                            <label class="sad-label" for="sad-pass">Password
                                <?= $editUser
                                    ? '<span class="sad-hint">(leave blank to keep the current password)</span>'
                                    : '<span class="sad-req" aria-hidden="true">*</span>' ?>
                            </label>
                            <input type="password" id="sad-pass" name="password" class="sad-input"
                                   <?= $editUser ? '' : 'required' ?> placeholder="••••••••"
                                   minlength="8" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="sad-form-actions">
                        <button type="submit" class="sad-btn sad-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            <?= $editUser ? 'Save changes' : 'Create Administrator' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="sad-card">
            <div class="sad-card-head">
                <div>
                    <h3>Administrator accounts</h3>
                    <p><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?> with the Administrator role</p>
                </div>
            </div>
            <div class="sad-card-body flush">
                <?php if (empty($users)): ?>
                    <div class="sad-empty">
                        <p>No Administrator accounts found.</p>
                    </div>
                <?php else: ?>
                    <div class="sad-table-scroll">
                        <table class="sad-table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Last login</th>
                                    <th scope="col" style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <?php
                                    $parts   = preg_split('/\s+/', trim($u['name']));
                                    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
                                    if ($initials === '') $initials = strtoupper(substr($u['name'], 0, 1));
                                    $active  = (int) $u['is_active'] === 1;
                                    $threshold = ONLINE_INACTIVITY_THRESHOLD ?? 1800;
                                    $token = !empty($u['active_session_token']) ? trim((string) $u['active_session_token']) : '';
                                    $lastSeen = (int) ($u['active_session_last_seen'] ?? 0);
                                    $online = ($token !== '' && $lastSeen > (time() - $threshold));
                                ?>
                                <tr>
                                    <td>
                                        <div class="sad-user">
                                            <div class="sad-avatar" aria-hidden="true"><?= $initials ?></div>
                                            <div style="min-width:0;">
                                                <div class="sad-user-name"><?= sanitize($u['name']) ?></div>
                                                <div class="sad-user-email"><?= sanitize($u['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="sad-badge" data-status-id="<?= (int) $u['id'] ?>" style="<?= $online
                                            ? 'background:var(--sad-ok-bg);color:var(--sad-ok);'
                                            : 'background:#F3F4F6;color:#6B7280;' ?>">
                                            <span class="dot" style="background:<?= $online ? '#065F46' : '#9CA3AF' ?>;"></span><?= $online ? 'Online' : 'Offline' ?>
                                        </span>
                                        <?php if ((int) $u['branch_count'] > 0): ?>
                                        <div class="sad-user-email" style="margin-top:.25rem;">
                                            +<?= (int) $u['branch_count'] ?> account<?= (int) $u['branch_count'] === 1 ? '' : 's' ?> below
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="sad-meta"><?= $u['last_login_at']
                                        ? sanitize(date('M j, Y g:i A', strtotime($u['last_login_at'])))
                                        : '—' ?></span></td>
                                    <td>
                                        <div class="sad-actions">
                                            <a href="?edit=<?= (int) $u['id'] ?>" class="sad-btn sad-btn-sm sad-btn-secondary sad-icon-btn"
                                               title="Edit <?= sanitize($u['name']) ?>" aria-label="Edit <?= sanitize($u['name']) ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                            </a>
                                            <form method="POST"
                                                  data-confirm="<?= $active
                                                      ? 'Deactivate ' . sanitize($u['name']) . '?'
                                                        . ((int) $u['branch_count'] > 0
                                                            ? ' Their ' . (int) $u['branch_count'] . ' linked account'
                                                              . ((int) $u['branch_count'] === 1 ? '' : 's')
                                                              . ' (Program Head, Unit Head, Staff, Faculty) will also be deactivated.'
                                                            : '')
                                                        . ' They will not be able to sign in.'
                                                      : 'Reactivate ' . sanitize($u['name']) . '?'
                                                        . ((int) $u['branch_count'] > 0
                                                            ? ' Their ' . (int) $u['branch_count'] . ' linked account'
                                                              . ((int) $u['branch_count'] === 1 ? '' : 's') . ' will also be reactivated.'
                                                            : '') ?>">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="set_active">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <input type="hidden" name="value" value="<?= $active ? '0' : '1' ?>">
                                                <button type="submit" class="sad-btn sad-btn-sm <?= $active ? 'sad-btn-danger' : 'sad-btn-primary' ?>"
                                                        title="<?= $active ? 'Deactivate' : 'Activate' ?> <?= sanitize($u['name']) ?>"
                                                        aria-label="<?= $active ? 'Deactivate' : 'Activate' ?> <?= sanitize($u['name']) ?>">
                                                    <?= $active ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
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
<?php include 'includes/footer.php'; ?>
