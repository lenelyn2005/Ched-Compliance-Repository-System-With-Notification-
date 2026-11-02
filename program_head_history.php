<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();

$deptId = (int)$_SESSION['department_id'];
$filterTeacher = !empty($_GET['teacher']) ? (int)$_GET['teacher'] : null;
$filterStatus = $_GET['status'] ?? '';
// department and unit_head share this page — every "PH" label below is really this role.
$roleName = currentRoleName();

$teachers = $pdo->prepare("SELECT id, name FROM users WHERE role IN ('teacher','staff') AND department_id=? ORDER BY name");
$teachers->execute([$deptId]);
$teachers = $teachers->fetchAll();

$conditions = ["d.department_id = ?"];
$params = [$deptId];

if ($filterTeacher) {
    $conditions[] = "tr.teacher_id = ?";
    $params[] = $filterTeacher;
}
if ($filterStatus) {
    $conditions[] = "d.status = ?";
    $params[] = $filterStatus;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$history = $pdo->prepare("
    SELECT d.*,
           u.name AS teacher_name,
           u.role AS teacher_role,
           d.category,
           d.document_type,
           up.name as uploader_name
    FROM documents d
    LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    LEFT JOIN users u ON tr.teacher_id = u.id
    LEFT JOIN users up ON d.uploaded_by = up.id
    $where
    ORDER BY d.upload_date DESC
    LIMIT 200
");
$history->execute($params);
$history = $history->fetchAll();

$statusLabels = [
    'pending_program_head' => ['Pending ' . $roleName, 'badge-pending-ph'],
    'pending_admin'       => ['Forwarded', 'badge-pending-admin'],
    'approved_admin'     => ['Approved', 'badge-approved'],
    'rejected_program_head' => ['Rejected (' . $roleName . ')', 'badge-rejected'],
    'rejected_admin'     => ['Rejected (Admin)', 'badge-rejected'],
];
?>
<?php
$pageTitle = 'Submission History';

ob_start();
?>
<style>
.dashboard-container { max-width: 1400px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.5rem; }

.card { background: var(--white); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-direction: column; overflow: hidden; margin-bottom: 1.5rem; }
.card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: linear-gradient(to right, #f8fafc, #f1f5f9); }
.card-header h3 { font-size: 1.1rem; font-weight: 700; color: var(--gray-900); display: flex; align-items: center; gap: 0.6rem; margin: 0; letter-spacing: -0.01em; }
.card-header h3 svg { width: 22px; height: 22px; color: var(--primary); }
.card-content { padding: 1.5rem; flex: 1; }
.card-content.no-padding { padding: 0 !important; }

.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.85rem; color: var(--gray-700); }
.form-control { width: 100%; padding: 0.7rem 1rem; border: 1.5px solid var(--gray-200); border-radius: 10px; font-family: inherit; font-size: 0.9rem; transition: all 0.2s; background: var(--white); cursor: pointer; }
.form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); background: #f8fafc; }
.form-control:hover { border-color: var(--gray-300); }

.table-responsive { width: 100%; overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; white-space: nowrap; }
.data-table th { background: #f8fafc; padding: 0.875rem 1.25rem; text-align: left; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.06em; border-bottom: 1px solid var(--border); }
.data-table td { padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100); vertical-align: middle; font-size: 0.9rem; }
.data-table tbody tr { transition: all 0.15s ease; cursor: default; }
.data-table tbody tr:hover { background: linear-gradient(to right, #f8fafc, #f0f9ff); }
.data-table tbody tr:last-child td { border-bottom: none; }

.badge { display: inline-flex; align-items: center; padding: 0.35rem 0.75rem; border-radius: 99px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.badge-pending { background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); color: #64748b; }
.badge-pending-ph { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); color: #d97706; }
.badge-pending-admin { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); color: #1d4ed8; }
.badge-approved { background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); color: #059669; }
.badge-rejected { background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); color: #dc2626; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; padding: 0.55rem 1rem; border-radius: 10px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid transparent; text-decoration: none; line-height: 1; }
.btn svg { width: 14px; height: 14px; }
.btn-sm { padding: 0.4rem 0.75rem; font-size: 0.75rem; border-radius: 8px; }
.btn-secondary { background: var(--gray-50); color: var(--gray-700); border-color: var(--gray-200); }
.btn-secondary:hover { background: var(--gray-100); border-color: var(--gray-300); }
.btn-note { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.btn-note:hover { background: #fee2e2; border-color: #fca5a5; }

.empty-state { text-align: center; padding: 3.5rem 1.5rem; color: var(--text-light); display: flex; flex-direction: column; align-items: center; justify-content: center; }
.empty-state-icon { width: 56px; height: 56px; margin-bottom: 1rem; color: var(--text-muted); opacity: 0.5; }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>
            <div class="card">
                <div class="card-content" style="padding:1rem 1.5rem;">
                    <form method="GET" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
                        <div class="form-group" style="margin:0; flex:1; min-width:160px;">
                            <label>Faculty</label>
                            <select name="teacher" class="form-control" data-csp-submit>
                                <option value="">All Faculty</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $filterTeacher == $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0; flex:1; min-width:160px;">
                            <label>Status</label>
                            <select name="status" class="form-control" data-csp-submit>
                                <option value="">All</option>
                                <option value="pending_program_head" <?= $filterStatus==='pending_program_head'?'selected':''?>>Pending <?= sanitize($roleName) ?></option>
                                <option value="pending_admin" <?= $filterStatus==='pending_admin'?'selected':''?>>Forwarded to Admin</option>
                                <option value="approved_admin" <?= $filterStatus==='approved_admin'?'selected':''?>>Approved</option>
                                <option value="rejected_program_head" <?= $filterStatus==='rejected_program_head'?'selected':''?>>Rejected by <?= sanitize($roleName) ?></option>
                                <option value="rejected_admin" <?= $filterStatus==='rejected_admin'?'selected':''?>>Rejected by Admin</option>
                            </select>
                        </div>
                        <a href="program_head_history.php" class="btn btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/></svg>
                            Clear
                        </a>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
                        Submissions (<?= count($history) ?>)
                    </h3>
                </div>
                <div class="card-content" style="padding:0;">
                    <?php if (empty($history)): ?>
                        <div class="empty-state">
                            <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/></svg>
                            <p style="font-size:1rem; color:var(--text-light);">No submissions found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Requirement</th>
                                        <th>Ver.</th>
                                        <th>Uploaded</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $row):
                                        $sl = $statusLabels[$row['status']] ?? [$row['status'], 'badge-pending'];
                                        $hasPhComment    = !empty($row['program_head_comment']);
                                        $hasAdminComment = !empty($row['admin_comment']);
                                    ?>
                                    <tr>
                                        <td><?= sanitize($row['teacher_name'] ?: ($row['uploader_name'] ?: 'Me')) ?><?php if (!empty($row['teacher_role'])): ?> <span class="badge badge-pending-ph"><?= sanitize(facultyRoleName($row['teacher_role'])) ?></span><?php endif; ?></td>
                                        <td>
                                            <strong><?= sanitize($row['category']) ?></strong><br>
                                            <small style="color:var(--text-light);"><?= sanitize($row['document_type']) ?></small>
                                        </td>
                                        <td>v<?= $row['version'] ?></td>
                                        <td><?= date('M j, Y', strtotime($row['upload_date'])) ?></td>
                                        <td><span class="badge <?= $sl[1] ?>"><?= $sl[0] ?></span></td>
                                        <td>
                                            <div class="btn-group" style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                                                <button class="btn btn-sm btn-secondary view-btn"
                                                    data-id="<?= $row['id'] ?>" 
                                                    data-type="document"
                                                    data-title="<?= sanitize($row['document_type']) ?>"
                                                    data-filename="<?= sanitize($row['filename'] ?? '') ?>"
                                                    style="cursor:pointer;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    View
                                                </button>

                                                <?php if ($hasPhComment): ?>
                                                <button class="btn btn-sm btn-note view-comment-btn"
                                                    data-type="ph"
                                                    data-title="<?= sanitize($row['category'] . ' - ' . $row['document_type']) ?>"
                                                    data-comment="<?= sanitize($row['program_head_comment']) ?>"
                                                    style="cursor:pointer;"
                                                    title="<?= sanitize($roleName) ?> comment">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                                    <?= sanitize($roleName) ?>
                                                </button>
                                                <?php endif; ?>

                                                <?php if ($hasAdminComment): ?>
                                                <button class="btn btn-sm btn-note view-comment-btn"
                                                    data-type="admin"
                                                    data-title="<?= sanitize($row['category'] . ' - ' . $row['document_type']) ?>"
                                                    data-comment="<?= sanitize($row['admin_comment']) ?>"
                                                    style="cursor:pointer;"
                                                    title="Admin comment">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                                    Admin
                                                </button>
                                                <?php endif; ?>
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

<div id="commentModal" class="modal">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h3 id="commentModal_title" style="color:#1d4ed8;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span id="commentModal_label">Comment</span>
            </h3>
            <button class="close-btn" data-modal="commentModal" aria-label="Close modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <p id="commentModal_info" style="margin-bottom: 1rem; font-size: 0.9rem; color: var(--text-light);"></p>
            <div id="commentModal_text" class="form-control" style="background: #f8fafc; min-height: 100px; white-space: pre-wrap; font-size: 0.9rem; line-height: 1.6; color: var(--gray-800);"></div>
        </div>
    </div>
</div>

<style>
.modal { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1.5rem; }
.modal.show { display: flex; }
.modal-content { background: var(--white); border-radius: 20px; width: 100%; max-width: 480px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15); animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes modalSlideUp { from { transform: translateY(20px) scale(0.96); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
.modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
.modal-header h3 svg { width: 20px; height: 20px; }
.modal-body { padding: 1.5rem; }
.close-btn { background: none; border: none; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--text-light); cursor: pointer; transition: all 0.15s; }
.close-btn:hover { background: var(--gray-100); color: var(--text); }
</style>

<?php 
$phCommentLabel = json_encode($roleName . ' Comment');
$extraScripts = "
<script nonce=\"" . csp_nonce() . "\">
    document.querySelectorAll('.view-comment-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const type = this.dataset.type;
            const title = this.dataset.title;
            const comment = this.dataset.comment;

            const isPH = type === 'ph';
            document.getElementById('commentModal_label').textContent = isPH ? $phCommentLabel : 'Admin Comment';
            document.getElementById('commentModal_info').textContent = 'Document: ' + title;

            const textEl = document.getElementById('commentModal_text');
            if (comment && comment.trim() !== '') {
                textEl.textContent = comment;
            } else {
                textEl.innerHTML = '<span style=\\'color: var(--gray-400); font-style: italic;\\'>No comment provided.</span>';
            }

            document.getElementById('commentModal').classList.add('show');
        });
    });

    document.querySelectorAll('.close-btn[data-modal]').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById(this.dataset.modal).classList.remove('show');
        });
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                modal.classList.remove('show');
            });
        }
    });
</script>
";
include 'includes/footer.php'; ?>