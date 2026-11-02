<?php
define('CHED_APP', true);
require_once 'config.php';
requireTeacher();

$success = $_GET['msg'] ?? '';

$teacherId = (int)$_SESSION['user_id'];

// AJAX: Live submissions count refresh (30s poll + WS-triggered).
// Cheap COUNTs placed BEFORE the full page fetches: this branch used to run
// after two unbounded SELECT * queries per poll tick.
if (isAjaxRequest() && ($_POST['action'] ?? '') === 'refresh_submissions') {
    $c1 = $pdo->prepare("
        SELECT COUNT(*) FROM documents d
        JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        WHERE tr.teacher_id = ? AND d.file_path IS NOT NULL AND d.file_path != ''
    ");
    $c1->execute([$teacherId]);
    $deptCount = (int) $c1->fetchColumn();
    $c2 = $pdo->prepare("
        SELECT COUNT(*) FROM ph_documents pd
        JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
        WHERE tpr.teacher_id = ? AND pd.file_path IS NOT NULL AND pd.file_path != ''
    ");
    $c2->execute([$teacherId]);
    $phCount = (int) $c2->fetchColumn();
    sendJson([
        'dept_submissions' => $deptCount,
        'ph_submissions' => $phCount,
        'total_submissions' => $deptCount + $phCount,
    ]);
}

// All versions of documents submitted by this teacher (department requirements)
$submissions = $pdo->prepare("
    SELECT d.*,
           dr.category,
           dr.document_type,
           ph.name AS ph_reviewer,
           ad.name AS admin_reviewer,
           'department' AS source_type
    FROM documents d
    JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
    JOIN department_requirements dr ON tr.requirement_id = dr.id
    LEFT JOIN users ph ON d.reviewed_by_program_head = ph.id
    LEFT JOIN users ad ON d.reviewed_by_admin = ad.id
    WHERE tr.teacher_id = ?
    ORDER BY d.upload_date DESC
");
$submissions->execute([$teacherId]);
$deptSubmissions = $submissions->fetchAll();

// Filter out documents with missing files
$deptSubmissions = array_values(array_filter($deptSubmissions, function ($doc) {
    $filePath = $doc['file_path'] ?? null;
    if (empty($filePath)) {
        return false;
    }
    return storageFilePresent($filePath);
}));

// Also fetch PH requirement submissions
$phSubmissions = $pdo->prepare("
    SELECT pd.*,
           'PH Assignment' AS category,
           phr.title AS document_type,
           cu.role AS head_role,
           ph.name AS ph_reviewer,
           ad.name AS admin_reviewer,
           'ph' AS source_type
    FROM ph_documents pd
    JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
    JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
    LEFT JOIN users cu ON cu.id = phr.created_by
    LEFT JOIN users ph ON pd.reviewed_by_program_head = ph.id
    LEFT JOIN users ad ON pd.reviewed_by_admin = ad.id
    WHERE tpr.teacher_id = ?
    ORDER BY pd.upload_date DESC
");
$phSubmissions->execute([$teacherId]);
$phSubmissions = $phSubmissions->fetchAll();

// Category/source show the real head who created the requirement
// (UH vs PH) — same rule as teacher_tasks.php, shortened.
foreach ($phSubmissions as &$sub) {
    $label = ($sub['head_role'] ?? '') === 'unit_head' ? 'UH' : 'PH';
    $sub['category'] = $label;
    $sub['source_label'] = $label;
}
unset($sub);

// Filter out ph_documents with missing files
$phSubmissions = array_values(array_filter($phSubmissions, function ($doc) {
    $filePath = $doc['file_path'] ?? null;
    if (empty($filePath)) {
        return false;
    }
    return storageFilePresent($filePath);
}));

// Merge both types of submissions
$submissions = array_merge($deptSubmissions, $phSubmissions);

// Reviewer of this teacher's department — Program Head or Unit Head.
$deptStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
$deptStmt->execute([$teacherId]);
$teacherDeptId = (int) $deptStmt->fetchColumn();
$reviewer = $teacherDeptId ? getProgramHeadForDepartment($pdo, $teacherDeptId) : null;
$reviewerName = ($reviewer['role'] ?? '') === 'unit_head' ? 'UH' : 'PH';

$statusLabels = [
    'pending_program_head' => ["Pending $reviewerName Review", 'badge-pending-ph'],
    'approved_program_head' => ["Approved by $reviewerName", 'badge-approved'],
    'rejected_program_head' => ["Rejected by $reviewerName", 'badge-rejected'],
    'pending_admin'        => ['Pending Admin Review', 'badge-pending-admin'],
    'approved_admin'       => ['Fully Approved', 'badge-approved'],
    'rejected_admin'       => ['Rejected by Admin', 'badge-rejected'],
];
?>
<?php
$pageTitle = 'My Submissions';

ob_start();
?>
<style>
    @import url('offline/inter-font.css');

    :root {
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --primary-light: #eff6ff;
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
        --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --radius-xl: 1.5rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--gray-50);
        color: var(--gray-800);
        font-size: 0.875rem;
        line-height: 1.5;
    }

    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        padding: 0.5rem;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes scaleIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }

        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .animate-in {
        animation: fadeInUp 0.5s ease forwards;
    }

    .page-hero {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
        border-radius: var(--radius-xl);
        padding: 2rem;
        color: white;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: var(--shadow-lg);
    }

    .page-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
        pointer-events: none;
    }

    .page-hero::after {
        content: '';
        position: absolute;
        bottom: -30%;
        left: 10%;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(59, 130, 246, 0.3) 0%, transparent 70%);
        pointer-events: none;
    }

    .page-hero-content {
        position: relative;
        z-index: 1;
        flex: 1;
    }

    .page-hero h1 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.01em;
    }

    .page-hero p {
        margin: 0.375rem 0 0;
        opacity: 0.85;
        font-size: 0.9rem;
    }

    .page-hero-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-size: 0.85rem;
        font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.3);
        position: relative;
        z-index: 1;
        transition: var(--transition);
    }

    .page-hero-badge:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-1px);
    }

    .card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-xs);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transition: box-shadow 0.15s ease;
    }

    .card:hover {
        box-shadow: var(--shadow-sm);
    }

    .card-header {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--white);
    }

    .card-header h3 {
        font-size: 0.95rem;
        font-weight: 650;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin: 0;
        letter-spacing: -0.01em;
    }

    .card-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--gray-400);
        flex-shrink: 0;
    }

    .card-content {
        padding: 1.25rem;
        flex: 1;
    }

    .card-content.no-padding {
        padding: 0 !important;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        white-space: nowrap;
    }

    .data-table th {
        background: var(--gray-50);
        padding: 0.65rem 1.25rem;
        text-align: left;
        font-size: 0.7rem;
        font-weight: 650;
        text-transform: uppercase;
        color: var(--gray-500);
        letter-spacing: 0.06em;
        border-bottom: 1px solid var(--border);
        position: sticky;
        top: 0;
        white-space: nowrap;
    }

    .data-table td {
        padding: 0.8rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
        vertical-align: middle;
        font-size: 0.875rem;
        color: var(--gray-700);
    }

    .data-table tr:hover {
        background: #f8fafc;
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.73rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
    }

    .badge svg {
        width: 12px;
        height: 12px;
    }

    .badge-pending {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .badge-pending-ph {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-pending-admin {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .badge-approved {
        background: #ecfdf5;
        color: #047857;
    }

    .badge-rejected {
        background: #fef2f2;
        color: #b91c1c;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        padding: 0.5rem 1rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
    }

    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.8125rem;
    }

    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
        border-color: var(--border);
    }

    .btn-secondary:hover {
        background: var(--gray-200);
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

    .btn-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
    }

    .empty-state svg {
        width: 64px;
        height: 64px;
        color: var(--gray-300);
        opacity: 0.5;
        margin-bottom: 1rem;
    }

    .empty-state h3 {
        margin: 0 0 0.5rem;
        color: var(--gray-700);
        font-weight: 600;
        font-size: 1.1rem;
    }

    .empty-state p {
        margin: 0;
        color: var(--gray-500);
        font-size: 0.9rem;
    }

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
        box-shadow: var(--shadow-xl);
        animation: slideUp 0.2s ease;
    }

    .modal-header {
        padding: 0.875rem 1.25rem;
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
        gap: 0.55rem;
        letter-spacing: -0.01em;
    }

    .modal-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--gray-400);
    }

    .modal-body {
        padding: 1.25rem;
    }

    .close-btn {
        background: var(--gray-100);
        border: none;
        font-size: 1.25rem;
        color: var(--gray-600);
        cursor: pointer;
        line-height: 1;
        width: 2rem;
        height: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-md);
        transition: var(--transition);
    }

    .close-btn:hover {
        color: var(--gray-900);
        background: var(--gray-200);
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--gray-700);
    }

    .req-doc-type {
        font-weight: 600;
        color: var(--gray-900);
    }

    .req-category {
        font-size: 0.7rem;
        color: var(--gray-500);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        font-weight: 600;
    }

    .section-count {
        background: var(--primary-light);
        color: var(--primary-dark);
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.25rem;
            gap: 1rem;
        }

        .page-hero {
            flex-direction: column;
            text-align: center;
            padding: 1.5rem;
            gap: 1rem;
        }

        .page-hero-badge {
            margin-left: 0;
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem;
            font-size: 0.8125rem;
        }
    }
</style>
<?php
$extraStyles = ob_get_clean();

include 'includes/header.php';
?>

<div class="dashboard-container">
    <div class="card">
        <div class="card-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                All Submissions
            </h3>
            <span class="section-count"><?= count($submissions) ?> items</span>
        </div>
        <div class="card-content" style="padding:0;">
            <?php if (empty($submissions)): ?>
                <div class="empty-state">
                    <div style="width:56px;height:56px;margin:0 auto 1rem;border-radius:16px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;">
                        <svg xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;color:var(--gray-400);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12l-3-3m0 0l-3 3m3-3v6m-1.5-8H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                    </div>
                    <h3>No Submissions Yet</h3>
                    <p>You haven't submitted any documents yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Document Type</th>
                                <th>Source</th>
                                <th>Version</th>
                                <th>Uploaded</th>
                                <th>Status</th>
                                <th>Reviewer</th>
                                <th>Feedback</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $sub):
                                $sl = $statusLabels[$sub['status']] ?? [$sub['status'], 'badge-pending'];
                                $sourceType = $sub['source_type'] ?? 'department';
                                $sourceLabel = $sourceType === 'ph' ? ($sub['source_label'] ?? 'PH') : 'Dept';
                                $sourceClass = $sourceType === 'ph' ? 'badge-info' : 'badge-warning';
                                // Determine which reviewer is active based on status
                                $isPhReview = in_array($sub['status'], ['pending_program_head', 'approved_program_head', 'rejected_program_head']);
                                $isAdminReview = in_array($sub['status'], ['pending_admin', 'approved_admin', 'rejected_admin']);
                            ?>
                                <tr>
                                    <td><span class="req-category"><?= sanitize($sub['category']) ?></span></td>
                                    <td><span class="req-doc-type"><?= sanitize($sub['document_type']) ?></span></td>
                                    <td><span class="badge <?= $sourceClass ?>"><?= $sourceLabel ?></span></td>
                                    <td>v<?= $sub['version'] ?></td>
                                    <td><?= date('M j, Y', strtotime($sub['upload_date'])) ?></td>
                                    <td><span class="badge <?= $sl[1] ?>"><?= $sl[0] ?></span></td>
                                    <td>
                                        <?php if ($isPhReview && $sub['ph_reviewer']): ?>
                                            <span style="font-weight:500;"><?= sanitize($sub['ph_reviewer']) ?></span>
                                            <?php if ($sub['reviewed_at_program_head']): ?>
                                                <br><small style="color:var(--gray-500);"><?= date('M j, Y', strtotime($sub['reviewed_at_program_head'])) ?></small>
                                            <?php endif; ?>
                                        <?php elseif ($isAdminReview && $sub['admin_reviewer']): ?>
                                            <span style="font-weight:500;"><?= sanitize($sub['admin_reviewer']) ?></span>
                                            <?php if ($sub['reviewed_at_admin']): ?>
                                                <br><small style="color:var(--gray-500);"><?= date('M j, Y', strtotime($sub['reviewed_at_admin'])) ?></small>
                                            <?php endif; ?>
                                            <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($sub['program_head_comment'] || $sub['admin_comment']): ?>
                                            <button class="btn btn-sm btn-outline comment-btn"
                                                data-ph="<?= sanitize($sub['program_head_comment'] ?? '') ?>"
                                                data-admin="<?= sanitize($sub['admin_comment'] ?? '') ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                                </svg>
                                                View
                                            </button>
                                            <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline view-btn"
                                            data-id="<?= $sub['id'] ?>"
                                            data-type="<?= $sourceType === 'ph' ? 'ph_document' : 'document' ?>"
                                            data-title="<?= sanitize($sub['document_type']) ?> v<?= $sub['version'] ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            View
                                        </button>
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

<!-- Comments Modal -->
<div id="commentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;margin-right:0.5rem;color:var(--primary);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                Review Feedback
            </h3>
            <button class="close-btn" data-modal="commentModal">
                <svg xmlns="http://www.w3.org/2000/svg" style="width:20px;height:20px;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>PH Feedback</label>
                <p id="phComment" style="background:var(--gray-50); border-radius:8px; padding:0.875rem; min-height:50px; font-size:0.875rem; border-left:4px solid var(--primary); color:var(--gray-700);">—</p>
            </div>
            <div class="form-group" id="adminCommentGroup">
                <label>Admin Feedback</label>
                <p id="adminComment" style="background:var(--gray-50); border-radius:8px; padding:0.875rem; min-height:50px; font-size:0.875rem; border-left:4px solid var(--danger); color:var(--gray-700);">—</p>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/success_toast.php'; ?>

<?php
$extraScripts = "
<script nonce=\"" . csp_nonce() . "\">
    document.querySelectorAll('.comment-btn').forEach(b => {
        b.addEventListener('click', function () {
            const ph = this.dataset.ph;
            const admin = this.dataset.admin;
            document.getElementById('phComment').textContent = ph || '—';
            document.getElementById('adminComment').textContent = admin || '—';
            document.getElementById('adminCommentGroup').style.display = admin ? 'block' : 'none';
            document.getElementById('commentModal').classList.add('show');
        });
    });
    document.querySelectorAll('.close-btn[data-modal]').forEach(b => {
        b.addEventListener('click', function () {
            document.getElementById(this.dataset.modal).classList.remove('show');
        });
    });
    window.addEventListener('click', e => {
        if (e.target.classList.contains('modal') && e.target.id !== 'docViewerModal') {
            e.target.classList.remove('show');
        }
    });

    CHED.Poller.register('teacherSubmissions', function () {
        return fetch('teacher_submissions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'action=refresh_submissions'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || typeof d.total_submissions === 'undefined') return;
            var deptEl = document.querySelector('.dept-section .section-count');
            var phEl = document.querySelector('.ph-section .section-count');
            if (deptEl) deptEl.textContent = d.dept_submissions + ' items';
            if (phEl) phEl.textContent = d.ph_submissions + ' items';
        }).catch(function () {});
    }, 30000);
</script>
";
include 'includes/footer.php';
?>