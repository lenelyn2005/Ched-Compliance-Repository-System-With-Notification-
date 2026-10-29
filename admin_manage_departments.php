<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

// Manage departments: add/edit/delete
$error = '';
$success = '';
$deleteModalError = '';
$deleteModalTarget = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['department_name'] ?? '');
        $code = trim($_POST['department_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        if (empty($name)) {
            $error = 'Department name is required.';
        } else {
            try {
                if ($id) {
                    $pdo->prepare('UPDATE departments SET department_name=?, department_code=?, description=? WHERE id=?')
                        ->execute([$name, $code, $description, $id]);
                    logAudit($pdo, 'edit', "Dept updated: $name", 'department', $id);
                    if (function_exists('fileCacheDelete')) { fileCacheDelete('departments_list'); }
                    $success = "Department updated successfully.";
                } else {
                    $pdo->prepare('INSERT INTO departments (department_name, department_code, description) VALUES (?, ?, ?)')
                        ->execute([$name, $code, $description]);
                    $newId = (int) $pdo->lastInsertId();
                    logAudit($pdo, 'create', "Dept created: $name", 'department', $newId);
                    try { createDepartmentFolders($newId, $pdo); } catch (Throwable $e) { error_log('[Dept] create dirs failed: '.$e->getMessage()); }
                    if (function_exists('fileCacheDelete')) { fileCacheDelete('departments_list'); }
                    $success = "Department created successfully.";
                }
            } catch (PDOException $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        if (!isAdmin()) {
            $error = 'Unauthorized.';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $adminPass = $_POST['admin_password'] ?? '';
            $tmpTarget = null;
            if ($id > 0) {
                $tmp = $pdo->prepare('SELECT id, department_name FROM departments WHERE id=?');
                $tmp->execute([$id]);
                $tmpTarget = $tmp->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($id === 0) {
                $error = 'Invalid department selection.';
                $deleteModalError = $error;
                $deleteModalTarget = $tmpTarget;
            } elseif ($adminPass === '') {
                $error = 'Admin password is required to confirm deletion.';
                $deleteModalError = $error;
                $deleteModalTarget = $tmpTarget;
            } else {
                $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
                $stmt->execute([(int)$_SESSION['user_id']]);
                $adminRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$adminRow || !password_verify($adminPass, $adminRow['password'])) {
                    $error = 'Incorrect password. Deletion cancelled.';
                    $deleteModalError = 'Incorrect password. Please try again.';
                    $deleteModalTarget = $tmpTarget;
                } else {
                    if (!$tmpTarget) {
                        $error = 'Department not found.';
                        $deleteModalError = $error;
                    } else {
                        $deptName = $tmpTarget['department_name'];
                       $pdo->prepare('DELETE FROM teacher_requirements WHERE requirement_id IN (SELECT id FROM department_requirements WHERE department_id = ?)')->execute([$id]);
                        $pdo->prepare('DELETE FROM department_requirements WHERE department_id = ?')->execute([$id]);
                        $pdo->prepare('DELETE FROM departments WHERE id=?')->execute([$id]);
                        try { $pdo->prepare('DELETE FROM department_folders WHERE department_id=?')->execute([$id]); } catch (PDOException $e) {}
                        if (function_exists('fileCacheDelete')) { fileCacheDelete('departments_list'); }
                        logAudit($pdo, 'delete', "Dept deleted: $deptName", 'department', $id);
                        header('Location: admin_manage_departments.php?msg=Department deleted successfully');
                        exit;
                    }
                }
            }
        }
    }
}

if (isset($_GET['msg'])) $success = $_GET['msg'];

$editDept = null;
if (!empty($_GET['edit'])) {
    $editDept = $pdo->prepare('SELECT * FROM departments WHERE id=?');
    $editDept->execute([(int) $_GET['edit']]);
    $editDept = $editDept->fetch();
}

$departments = $pdo->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM users WHERE department_id=d.id AND role IN ('department','unit_head')) AS ph_count,
           (SELECT COUNT(*) FROM department_requirements WHERE department_id=d.id) AS req_count
    FROM departments d ORDER BY d.department_name
")->fetchAll();

$pageTitle = 'Manage Departments';

ob_start();
?>
<style>
    @import url('offline/inter-font.css');
.umd-wrap {
    --umd-primary: #197B65;
    --umd-primary-dark: #125A4A;
    --umd-primary-soft: #E8F4F1;
    --umd-ink: #1A202C;
    --umd-body: #2D3748;
    --umd-muted: #5B6B7C;
    --umd-line: #E2E8F0;
    --umd-line-soft: rgba(36,37,39,.07);
    --umd-card: #FFFFFF;
    --umd-danger: #C53030;
    --umd-danger-bg: #FED7D7;
    --umd-ok: #065F46;
    --umd-ok-bg: #D1FAE5;
    --umd-ring: 0 0 0 3px rgba(25,123,101,.25);
    --umd-radius: 12px;
    --umd-shadow: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.05);
    max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--umd-body); line-height: 1.5;
}

.umd-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem; }
.umd-title { margin: 0; font-size: 1.35rem; font-weight: 800; letter-spacing: -.02em; color: var(--umd-ink); }
.umd-sub { margin: .15rem 0 0; font-size: .85rem; color: var(--umd-muted); }
.umd-stats { display: flex; gap: .5rem; flex-wrap: wrap; }
.umd-stat { display: inline-flex; align-items: center; gap: .45rem; background: var(--umd-card);
    border: 1px solid var(--umd-line); border-radius: 9999px; padding: .4rem .8rem;
    font-size: .78rem; font-weight: 600; color: var(--umd-ink); box-shadow: var(--umd-shadow); }
.umd-stat svg { width: 15px; height: 15px; color: var(--umd-primary); }
.umd-stat b { font-weight: 800; }

.umd-grid { display: flex; flex-direction: column; gap: 1rem; }
.umd-card { background: var(--umd-card); border-radius: var(--umd-radius); border: 1px solid var(--umd-line);
    box-shadow: var(--umd-shadow); overflow: hidden; }
.umd-card-head { padding: .9rem 1.25rem; border-bottom: 1px solid var(--umd-line-soft);
    display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
.umd-card-head h3 { font-size: 1rem; font-weight: 700; color: var(--umd-ink); margin: 0;
    display: flex; align-items: center; gap: .55rem; }
.umd-card-head h3 svg { width: 18px; height: 18px; color: var(--umd-primary); }
.umd-card-body { padding: 1.25rem; }
.umd-card-body.flush { padding: 0; }

.umd-alert { padding: .8rem 1rem; border-radius: 10px; font-weight: 500; display: flex;
    align-items: flex-start; gap: .65rem; font-size: .87rem; border: 1px solid transparent; }
.umd-alert svg { flex-shrink: 0; width: 19px; height: 19px; margin-top: 1px; }
.umd-alert-success { background: var(--umd-ok-bg); color: var(--umd-ok); border-color: rgba(5,150,105,.22); }
.umd-alert-danger { background: var(--umd-danger-bg); color: #991B1B; border-color: rgba(229,62,62,.22); }

.umd-form-row { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.umd-label { display: block; margin-bottom: .4rem; font-weight: 600; font-size: .8rem; color: #3E4C5E; }
.umd-req { color: var(--umd-danger); }
.umd-hint { font-weight: 400; color: var(--umd-muted); font-size: .75rem; }
.umd-input { width: 100%; padding: .68rem .9rem; border: 1px solid var(--umd-line); border-radius: 8px;
    font-family: inherit; font-size: .88rem; background: #FAFAFA; color: var(--umd-ink);
    transition: border-color .2s ease, box-shadow .2s ease, background .2s ease; box-sizing: border-box; }
input.umd-input, textarea.umd-input { cursor: text; }
textarea.umd-input { resize: vertical; min-height: 56px; }
.umd-input:hover { border-color: #B9CBD1; }
.umd-input:focus { outline: none; border-color: var(--umd-primary); background: #fff; box-shadow: var(--umd-ring); }
.umd-char { font-size: .72rem; color: var(--umd-muted); text-align: right; margin-top: .3rem; }

.umd-btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    padding: .68rem 1.15rem; border-radius: 8px; font-size: .85rem; font-weight: 600; font-family: inherit;
    cursor: pointer; transition: background .2s ease, border-color .2s ease, box-shadow .2s ease;
    border: 1px solid transparent; text-decoration: none; line-height: 1; min-height: 40px; }
.umd-btn:focus-visible { outline: none; box-shadow: var(--umd-ring); }
.umd-btn svg { width: 16px; height: 16px; }
.umd-btn-sm { padding: .5rem .8rem; font-size: .78rem; border-radius: 7px; min-height: 36px; }
.umd-btn-primary { background: var(--umd-primary); color: #fff; min-width: 200px; }
.umd-btn-primary:hover { background: var(--umd-primary-dark); box-shadow: 0 4px 12px rgba(25,123,101,.22); }
.umd-btn-secondary { background: #fff; color: #3E4C5E; border-color: var(--umd-line); }
.umd-btn-secondary:hover { background: #F8FAFC; color: var(--umd-ink); }
.umd-btn-danger { background: #fff; color: var(--umd-danger); border-color: #F5C2C2; }
.umd-btn-danger:hover { background: var(--umd-danger); color: #fff; border-color: var(--umd-danger); }
.umd-icon-btn { width: 36px; height: 36px; padding: 0; min-height: 0; border-radius: 8px; }
.umd-icon-btn svg { width: 17px; height: 17px; }
.umd-form-actions { display: flex; justify-content: flex-end; margin-top: 1.15rem;
    border-top: 1px solid var(--umd-line-soft); padding-top: 1.1rem; }

.umd-toolbar { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
.umd-search { position: relative; min-width: 210px; flex: 1 1 220px; max-width: 340px; }
.umd-search input { width: 100%; padding: .6rem .8rem; border: 1px solid var(--umd-line);
    border-radius: 8px; font-size: .84rem; background: #FAFAFA; color: var(--umd-ink); font-family: inherit; box-sizing: border-box; }
.umd-search input:focus { outline: none; border-color: var(--umd-primary); background: #fff; box-shadow: var(--umd-ring); }
.umd-count { font-size: .78rem; color: var(--umd-muted); }

.umd-table-scroll { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.umd-table { width: 100%; min-width: 680px; border-collapse: collapse; }
.umd-table th { background: #F8FAFC; padding: .7rem 1.15rem; text-align: left; font-size: .72rem; font-weight: 700;
    color: var(--umd-muted); text-transform: uppercase; letter-spacing: .06em;
    border-bottom: 1px solid var(--umd-line); white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.umd-table td { padding: .8rem 1.15rem; border-bottom: 1px solid var(--umd-line-soft);
    vertical-align: middle; font-size: .87rem; color: var(--umd-ink); }
.umd-table tbody tr { transition: background-color .2s ease; }
.umd-table tbody tr:hover { background: var(--umd-primary-soft); }
.umd-table tbody tr:focus-within { background: rgba(25,123,101,.1); }
.umd-table tr:last-child td { border-bottom: none; }

.umd-dept { display: flex; align-items: center; gap: .7rem; min-width: 0; }
.umd-avatar { width: 38px; height: 38px; border-radius: 10px; color: #fff; font-weight: 700; font-size: .72rem;
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; letter-spacing: .03em;
    background: linear-gradient(135deg, var(--umd-primary) 0%, var(--umd-primary-dark) 100%); }
.umd-dept-name { font-weight: 600; color: var(--umd-ink); overflow: hidden; text-overflow: ellipsis; }
.umd-dept-desc { font-size: .76rem; color: var(--umd-muted); display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden; }
.umd-code { background: #F1F5F9; border: 1px solid var(--umd-line); padding: .15rem .5rem; border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .78rem; color: var(--umd-ink); white-space: nowrap; }
.umd-badge { display: inline-flex; align-items: center; gap: .4rem; padding: .32rem .7rem;
    border-radius: 9999px; font-size: .73rem; font-weight: 700; white-space: nowrap; }
.umd-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.umd-badge-ph { background: var(--umd-primary-soft); color: var(--umd-primary-dark); }
.umd-badge-req { background: #F1F5F9; color: #3E4C5E; }
.umd-actions { display: flex; gap: .45rem; justify-content: flex-end; align-items: center; }
.umd-actions form { margin: 0; display: inline-flex; }

.umd-empty { text-align: center; padding: 3rem 1.5rem; color: var(--umd-muted); }
.umd-empty svg { width: 52px; height: 52px; color: #CBD5E0; margin-bottom: .7rem; }
.umd-empty p { margin: 0; font-size: .9rem; }
.umd-empty .umd-count { display: block; margin-top: .3rem; }

/* Delete confirmation modal — outside .umd-wrap so vars must be redeclared */
.umd-modal-overlay { --umd-danger:#C53030;--umd-danger-bg:#FED7D7;--umd-ink:#1A202C;--umd-muted:#5B6B7C;--umd-line:#E2E8F0;--umd-line-soft:rgba(36,37,39,.07);--umd-ring:0 0 0 3px rgba(25,123,101,.25); position: fixed; inset: 0; z-index: 2000; background: rgba(17,24,39,.48);
    display: none; align-items: center; justify-content: center; padding: 1.5rem; }
.umd-modal-overlay.active { display: flex; animation: umdModalFadeIn .2s ease; }
@keyframes umdModalFadeIn { from { opacity: 0; } to { opacity: 1; } }
.umd-modal-box { background: #fff; border-radius: 14px; border: 1px solid var(--umd-line);
    box-shadow: 0 20px 60px rgba(0,0,0,.18), 0 8px 24px rgba(0,0,0,.08);
    width: 100%; max-width: 420px; max-height: 85vh; display: flex; flex-direction: column;
    animation: umdModalSlideUp .28s cubic-bezier(.16,1,.3,1); overflow: hidden; }
@keyframes umdModalSlideUp { from { opacity: 0; transform: translateY(16px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
.umd-modal-head { display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.25rem; border-bottom: 1px solid var(--umd-line-soft);
    background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%); }
.umd-modal-head h2 { font-size: 1rem; font-weight: 700; color: var(--umd-ink); margin: 0;
    display: flex; align-items: center; gap: .55rem; letter-spacing: -.01em; }
.umd-modal-head h2 svg { width: 19px; height: 19px; color: var(--umd-danger); }
.umd-modal-close { display: inline-flex; align-items: center; justify-content: center; background: #fff;
    border: 1px solid var(--umd-line); cursor: pointer; width: 32px; height: 32px; border-radius: 8px;
    color: var(--umd-muted); transition: background .2s ease, color .2s ease; }
.umd-modal-close:hover { background: #F1F5F9; color: var(--umd-ink); }
.umd-modal-close:focus-visible { outline: none; box-shadow: var(--umd-ring); }
.umd-modal-close svg { width: 16px; height: 16px; }
.umd-modal-body { padding: 1.25rem; }
.umd-modal-body p { margin: 0; font-size: .88rem; color: #3E4C5E; }
.umd-modal-body strong { color: var(--umd-ink); }
.umd-modal-warn { margin-top: .6rem; font-size: .78rem; color: var(--umd-danger); font-weight: 600; }
.umd-modal-foot { padding: .9rem 1.25rem; border-top: 1px solid var(--umd-line-soft);
    display: flex; gap: .6rem; justify-content: flex-end; background: #F8FAFC; }
.umd-btn-danger-solid { background:#C53030; color:#fff; border-color:#C53030; }
.umd-btn-danger-solid:hover { background:#9B2C2C; border-color:#9B2C2C; }
.umd-btn-danger-solid:disabled{opacity:.6;cursor:not-allowed}

/* Success toast — top banner, hugs text, no backdrop, no scroll lock */
.umd-modal-overlay#successModal { background: none; padding: 0; align-items: flex-start; justify-content: center; }
.umd-modal-overlay#successModal.active { animation: none; }
.umd-modal-overlay#successModal .umd-modal-box { width: auto; max-width: calc(100vw - 2rem); max-height: none;
    margin-top: 16px; border-radius: 10px; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a;
    background: #f0fdf4; box-shadow: 0 12px 32px rgba(15,23,42,.16);
    animation: umdToastDrop .26s cubic-bezier(.2,.9,.3,1); }
@keyframes umdToastDrop { from { transform: translateY(-18px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.umd-toast { display: flex; align-items: center; gap: .55rem; margin: 0;
    padding: .7rem .75rem .7rem .9rem; font-size: .88rem; font-weight: 600; color: #14532d; }
.umd-toast > svg { flex-shrink: 0; color: #16a34a; }
.umd-toast-dismiss { margin-left: .35rem; flex-shrink: 0; border: 0; background: transparent;
    color: #4d7c5f; cursor: pointer; padding: .2rem; border-radius: 6px; display: flex; }
.umd-toast-dismiss:hover { background: rgba(22,163,74,.12); color: #166534; }

@media (max-width: 768px) {
    .umd-form-row { grid-template-columns: 1fr; }
    .umd-card-body { padding: 1rem; }
    .umd-btn-primary { min-width: 0; width: 100%; }
    .umd-form-actions .umd-btn { flex: 1 1 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .umd-wrap *, .umd-wrap *::before, .umd-wrap *::after { animation: none !important; transition: none !important; }
}
</style>
<?php
$extraStyles = ob_get_clean();
$totalPh = 0; $totalReq = 0;
foreach ($departments as $td) { $totalPh += (int)($td['ph_count'] ?? 0); $totalReq += (int)($td['req_count'] ?? 0); }
$extraScripts = <<<'JS'
<script nonce="<?= csp_nonce() ?>">
(function(){
  var search = document.getElementById('umdSearch');
  var rows = document.querySelectorAll('#umdTableBody tr');
  var count = document.getElementById('umdCount');
  var empty = document.getElementById('umdNoResult');
  function apply(){
    var q = (search ? search.value : '').trim().toLowerCase();
    var visible = 0;
    rows.forEach(function(r){
      var hay = (r.getAttribute('data-search') || '').toLowerCase();
      var show = !q || hay.indexOf(q) !== -1;
      r.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (count) count.textContent = visible + ' shown';
    if (empty) empty.style.display = visible === 0 ? '' : 'none';
  }
  if (search) search.addEventListener('input', apply);
  var desc = document.getElementById('umd-desc');
  var counter = document.getElementById('umdDescCount');
  function syncCount(){ if (desc && counter) counter.textContent = desc.value.length + ' characters'; }
  if (desc) { desc.addEventListener('input', syncCount); syncCount(); }
  apply();
})();
</script>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var modal=document.getElementById('deleteConfirmModal');
  if(!modal) return;
  var form=document.getElementById('umdDeleteForm');
  var inpId=document.getElementById('umdDeleteDeptId');
  var inpPass=document.getElementById('umdDeletePass');
  var cancelBtn=document.getElementById('umdDeleteCancel');
  var inlineErr=document.getElementById('umdDeleteInlineError');
  var lastTrigger=null;
  function showInline(msg){ if(!inlineErr) return; inlineErr.textContent=msg; inlineErr.style.display=msg?'':'none'; }
  function openModal(btn){
    inpId.value=btn.getAttribute('data-dept-id')||'';
    inpPass.value=''; showInline(''); inpPass.style.borderColor='';
    modal.classList.add('active'); modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden'; lastTrigger=btn;
    setTimeout(function(){inpPass.focus();},80);
  }
  function closeModal(){
    modal.classList.remove('active'); modal.setAttribute('aria-hidden','true');
    document.body.style.overflow=''; inpPass.value=''; showInline(''); inpPass.style.borderColor='';
    if(lastTrigger&&lastTrigger.focus) lastTrigger.focus(); lastTrigger=null;
  }
  document.querySelectorAll('.js-umd-delete-btn').forEach(function(b){
    b.addEventListener('click', function(){ openModal(b); });
  });
  if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape'&&modal.classList.contains('active')) closeModal(); });
  if(form) form.addEventListener('submit', function(e){
    if(!inpPass.value.trim()){ e.preventDefault(); showInline('Please enter your admin password.'); inpPass.focus(); inpPass.style.borderColor='#C53030'; }
  });
  if(inpPass) inpPass.addEventListener('input', function(){ this.style.borderColor=''; showInline(''); });
})();
</script>
JS;
$extraScripts = str_replace('<?= csp_nonce() ?>', csp_nonce(), $extraScripts);
include 'includes/header.php';
?>
<div class="umd-wrap">

<?php if ($error && !$deleteModalError): ?>
    <div class="umd-alert umd-alert-danger" role="alert" aria-live="assertive">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
        <span><?= sanitize($error) ?></span>
    </div>
<?php endif; ?>

<div class="umd-grid">
    <div class="umd-card">
        <div class="umd-card-head">
            <h3><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg><?= $editDept ? 'Edit department' : 'Add new department' ?></h3>
       
        </div>
        <div class="umd-card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $editDept ? 'edit' : 'add' ?>">
                <?php if ($editDept): ?><input type="hidden" name="id" value="<?= $editDept['id'] ?>"><?php endif; ?>

                <div class="umd-form-row">
                    <div>
                        <label class="umd-label" for="umd-name">Department name <span class="umd-req" aria-hidden="true">*</span></label>
                        <input type="text" id="umd-name" name="department_name" class="umd-input" required
                            value="<?= sanitize($editDept['department_name'] ?? '') ?>"
                            placeholder="e.g. College of Computer Studies" autocomplete="off">
                    </div>
                    <div>
                        <label class="umd-label" for="umd-code">Code</label>
                        <input type="text" id="umd-code" name="department_code" class="umd-input"
                            value="<?= sanitize($editDept['department_code'] ?? '') ?>" placeholder="e.g. CCS" autocomplete="off">
                    </div>
                </div>


                <div class="umd-form-actions">
                    <?php if ($editDept): ?><a href="admin_manage_departments.php" class="umd-btn umd-btn-secondary">Cancel</a><?php endif; ?>
                    <button type="submit" class="umd-btn umd-btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        <?= $editDept ? 'Update department' : 'Create department' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="umd-card">
        <div class="umd-card-head">
            <h3><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>Registered departments (<?= count($departments) ?>)</h3>
            <span class="umd-count" id="umdCount" role="status"><?= count($departments) ?> shown</span>
        </div>
        <div class="umd-card-head" style="background:#FCFDFD;">
            <div class="umd-toolbar" style="width:100%;">
                <div class="umd-search">
                    <input type="search" id="umdSearch" placeholder="Search name, code, or description…" aria-label="Search departments">
                </div>
            </div>
        </div>
        <div class="umd-card-body flush">
            <?php if (empty($departments)): ?>
                <div class="umd-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                    <p>No departments found.</p>
                    <span class="umd-count">Create your first department using the form above.</span>
                </div>
            <?php else: ?>
                <div class="umd-table-scroll">
                    <table class="umd-table">
                        <thead>
                            <tr>
                                <th scope="col">Department</th>
                                <th scope="col">Code</th>
                                <th scope="col" style="text-align:center;">Personnel</th>
                                <th scope="col" style="text-align:center;">Reqs</th>
                                <th scope="col" style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="umdTableBody">
                            <?php foreach ($departments as $d): ?>
                                <?php
                                    $code = trim($d['department_code'] ?? '');
                                    $mono = strtoupper(preg_replace('/[^A-Za-z]/', '', $code));
                                    $mono = substr($mono !== '' ? $mono : trim($d['department_name']), 0, 2);
                                    if ($mono === '') $mono = '–';
                                ?>
                                <tr data-search="<?= sanitize($d['department_name'] . ' ' . ($d['department_code'] ?? '') . ' ' . ($d['description'] ?? '')) ?>">
                                    <td>
                                        <div class="umd-dept">
                                            <div class="umd-avatar" aria-hidden="true"><?= sanitize($mono) ?></div>
                                            <div style="min-width:0;">
                                                <div class="umd-dept-name"><?= sanitize($d['department_name']) ?></div>
                                                <?php if ($d['description']): ?>
                                                    <div class="umd-dept-desc"><?= sanitize($d['description']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><code class="umd-code"><?= sanitize($d['department_code'] ?: '—') ?></code></td>
                                    <td style="text-align:center;">
                                        <span class="umd-badge umd-badge-ph"><span class="dot" aria-hidden="true"></span><?= $d['ph_count'] ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="umd-badge umd-badge-req"><span class="dot" aria-hidden="true"></span><?= $d['req_count'] ?></span>
                                    </td>
                                    <td style="text-align:right;">
                                        <div class="umd-actions">
                                            <a href="?edit=<?= $d['id'] ?>" class="umd-btn umd-btn-sm umd-btn-secondary umd-icon-btn" title="Edit <?= sanitize($d['department_name']) ?>" aria-label="Edit <?= sanitize($d['department_name']) ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                            </a>
                                            <a href="admin_department_requirements.php?dept=<?= $d['id'] ?>"
                                                class="umd-btn umd-btn-sm umd-btn-secondary umd-icon-btn" title="Requirements for <?= sanitize($d['department_name']) ?>" aria-label="Requirements for <?= sanitize($d['department_name']) ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" /></svg>
                                            </a>
                                            <button type="button" class="umd-btn umd-btn-sm umd-btn-danger-solid umd-icon-btn js-umd-delete-btn" title="Delete <?= sanitize($d['department_name']) ?>" aria-label="Delete <?= sanitize($d['department_name']) ?>" aria-haspopup="dialog" data-dept-id="<?= $d['id'] ?>" data-dept-name="<?= sanitize($d['department_name']) ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                                </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="umd-empty" id="umdNoResult" style="display:none;">
                    <p>No departments match your search.</p>
                    <span class="umd-count">Try a different name, code, or description.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<div class="umd-modal-overlay" id="deleteConfirmModal" aria-hidden="true">
    <div class="umd-modal-box" role="dialog" aria-modal="true" aria-labelledby="umdDeleteTitle">
        <div class="umd-modal-head">
            <h2 id="umdDeleteTitle">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                Confirm deletion
            </h2>
        </div>
        <form method="POST" id="umdDeleteForm" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="umdDeleteDeptId" value="<?= $deleteModalTarget ? (int)$deleteModalTarget['id'] : '' ?>">
            <div class="umd-modal-body">
                <p>You are about to <strong>permanently delete</strong> this department. This cannot be undone.</p>
                <?php if ($deleteModalError): ?>
                <div id="umdDeleteInlineError" class="umd-alert umd-alert-danger" role="alert" style="margin-top:.85rem"><?= sanitize($deleteModalError) ?></div>
                <?php else: ?>
                <div id="umdDeleteInlineError" class="umd-alert umd-alert-danger" role="alert" style="margin-top:.85rem;display:none"></div>
                <?php endif; ?>
                <div style="margin-top:1rem">
                    <label for="umdDeletePass" class="umd-label">Confirm with your admin password <span style="color:#C53030">*</span></label>
                    <input type="password" name="admin_password" id="umdDeletePass" class="umd-input" required placeholder="Enter your password" autocomplete="current-password" style="<?= $deleteModalError ? 'border-color:#C53030' : '' ?>">
                </div>
                <p class="umd-modal-warn">This action will permanently remove the department and unlink its data.</p>
            </div>
            <div class="umd-modal-foot">
                <button type="button" class="umd-btn umd-btn-secondary" id="umdDeleteCancel">Cancel</button>
                <button type="submit" class="umd-btn umd-btn-danger-solid">Delete department</button>
            </div>
        </form>
    </div>
</div>
<?php if ($deleteModalError): ?>
<script nonce="<?= csp_nonce() ?>">(function(){var m=document.getElementById('deleteConfirmModal');if(m){m.classList.add('active');m.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';var p=document.getElementById('umdDeletePass');if(p)setTimeout(function(){p.focus();},80);}})();</script>
<?php endif; ?>

<div class="umd-modal-overlay" id="successModal" aria-hidden="true">
    <div class="umd-modal-box" role="status" aria-live="polite">
        <div class="umd-toast">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span id="successMsg">Done.</span>
            <button type="button" class="umd-toast-dismiss" data-csp-action="closeDeptSuccessToast" aria-label="Dismiss">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
</div>
<script nonce="<?= csp_nonce() ?>">
    var __deptSuccessTimer = null;
    function closeDeptSuccessToast() {
        var m = document.getElementById('successModal');
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
        clearTimeout(__deptSuccessTimer);
    }
    <?php if ($success): ?>
        document.getElementById('successMsg').textContent = <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        document.getElementById('successModal').classList.add('active');
        document.getElementById('successModal').setAttribute('aria-hidden', 'false');
        __deptSuccessTimer = setTimeout(closeDeptSuccessToast, 2600);
    <?php endif; ?>
</script>
<?php include 'includes/footer.php'; ?>
