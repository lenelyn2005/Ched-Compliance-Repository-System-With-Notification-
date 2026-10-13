<?php

define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

// Handle AJAX requests for real-time data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    header('Content-Type: application/json');

    switch ($_POST['action']) {
        case 'get_analytics_data':
            echo json_encode(getAnalyticsData($_POST));
            exit;
        case 'export_data':
            echo json_export_data($_POST);
            exit;
    }
}

function getAnalyticsData($filters = [])
{
    global $pdo;

    $dateRange = $filters['date_range'] ?? 'last_30_days';
    $department = $filters['department'] ?? 'all';

    $dateDoc = getDateRangeCondition($dateRange, 'd');
    $datePh = getDateRangeCondition($dateRange, 'pd');
    $dateDocFull = $dateRange !== 'all' ? $dateDoc : '1=1';
    $datePhFull = $dateRange !== 'all' ? $datePh : '1=1';

    $deptCondition = "1=1";
    $deptParams = [];
    if ($department !== 'all' && is_numeric($department)) {
        $deptCondition = "d.department_id = :dept_id";
        $deptParams[':dept_id'] = (int) $department;
    }

    $docWindow = "SELECT d.* FROM documents d INNER JOIN (
        SELECT COALESCE(teacher_requirement_id, id) AS grp, MAX(version) AS mv
        FROM documents GROUP BY COALESCE(teacher_requirement_id, id)
    ) lv ON COALESCE(d.teacher_requirement_id, d.id) = lv.grp AND d.version = lv.mv";

    $phWindow = "SELECT pd.* FROM ph_documents pd INNER JOIN (
        SELECT COALESCE(teacher_ph_requirement_id, id) AS grp, MAX(version) AS mv
        FROM ph_documents GROUP BY COALESCE(teacher_ph_requirement_id, id)
    ) lv ON COALESCE(pd.teacher_ph_requirement_id, pd.id) = lv.grp AND pd.version = lv.mv";

    $totalDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d WHERE d.archived = 0 AND $dateDocFull AND $deptCondition")->fetchColumn();
    $totalPhDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($phWindow) pd WHERE pd.archived = 0 AND $datePhFull")->fetchColumn();
    $totalAllActive = $totalDocs + $totalPhDocs;

    // Total Documents KPI = what admin_manage_document.php holds: approved, not archived.
    $repoDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d
        WHERE d.archived = 0 AND d.status = 'approved_admin'
          AND $dateDocFull AND $deptCondition")->fetchColumn();
    $repoPhDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($phWindow) pd
        WHERE pd.archived = 0 AND pd.status = 'approved_admin'
          AND $datePhFull")->fetchColumn();
    $repoTotal = $repoDocs + $repoPhDocs;

    $archivedDocs = (int) $pdo->query("SELECT COUNT(*) FROM documents d WHERE " . archivedDocCondition() . " AND $dateDocFull AND $deptCondition")->fetchColumn();
    $archivedPh = (int) $pdo->query("SELECT COUNT(*) FROM ph_documents pd WHERE pd.archived = 1 AND pd.status = 'approved_admin' AND $datePhFull")->fetchColumn();
    $archived = $archivedDocs + $archivedPh;

    $pendingDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d
        WHERE d.archived = 0 AND d.status = 'pending_admin'
          AND d.file_path IS NOT NULL AND d.file_path != ''
          AND $dateDocFull AND $deptCondition")->fetchColumn();
    $pendingDocs += (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d
        LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
        WHERE d.archived = 0 AND d.status = 'pending_program_head'
          AND d.teacher_requirement_id IS NOT NULL
          AND COALESCE(tr.requirement_type, 'admin_requirement') = 'admin_requirement'
          AND d.file_path IS NOT NULL AND d.file_path != ''
          AND $dateDocFull AND $deptCondition")->fetchColumn();
    $pending = $pendingDocs;

    // Approved/Rejected = decisions made in admin_submissions.php (adminReview /
    // adminReviewPH), so PH-side statuses (approved_program_head,
    // rejected_program_head) are not counted.
    $approvedDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d
        WHERE d.status = 'approved_admin' AND $dateDocFull AND $deptCondition")->fetchColumn();
    $approvedPh = (int) $pdo->query("SELECT COUNT(*) FROM ($phWindow) pd
        WHERE pd.status = 'approved_admin' AND $datePhFull")->fetchColumn();
    $approved = $approvedDocs + $approvedPh;

    $approvedActive = (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d
        WHERE d.archived = 0 AND d.status = 'approved_admin' AND $dateDocFull AND $deptCondition")->fetchColumn();

    $rejectedDocs = (int) $pdo->query("SELECT COUNT(*) FROM ($docWindow) d
        WHERE d.archived = 0 AND d.status IN ('rejected_admin','rejected_program_head')
          AND $dateDocFull AND $deptCondition")->fetchColumn();
    $rejectedPh = (int) $pdo->query("SELECT COUNT(*) FROM ($phWindow) pd
        WHERE pd.archived = 0 AND pd.status IN ('rejected_admin','rejected_program_head') AND $datePhFull")->fetchColumn();
    $rejected = $rejectedDocs + $rejectedPh;

    $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalFaculty = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher','staff')")->fetchColumn();
    $totalProgramHeads = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'department'")->fetchColumn();
    $totalUnitHeads = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'unit_head'")->fetchColumn();
    $totalAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();

    $reqDeptCond = "1=1";
    $reqDeptParams = [];
    if ($department !== 'all' && is_numeric($department)) {
        $reqDeptCond = "dr.department_id = :req_dept";
        $reqDeptParams[':req_dept'] = (int) $department;
    }

    $stmtE = $pdo->prepare("SELECT COUNT(*) FROM department_requirements dr
        WHERE dr.expiration_date IS NOT NULL AND dr.expiration_date != '0000-00-00'
          AND dr.expiration_date <= CURDATE() AND $reqDeptCond");
    $stmtE->execute($reqDeptParams);
    $expired = (int) $stmtE->fetchColumn();

    $stmtX = $pdo->prepare("SELECT COUNT(*) FROM department_requirements dr
        WHERE dr.expiration_date IS NOT NULL AND dr.expiration_date != '0000-00-00'
          AND dr.expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL " . EXPIRY_WARNING_DAYS . " DAY)
          AND $reqDeptCond");
    $stmtX->execute($reqDeptParams);
    $expiringTotal = (int) $stmtX->fetchColumn();

    $stmtX30 = $pdo->prepare("SELECT COUNT(*) FROM department_requirements dr
        WHERE dr.expiration_date IS NOT NULL AND dr.expiration_date != '0000-00-00'
          AND dr.expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND $reqDeptCond");
    $stmtX30->execute($reqDeptParams);
    $expiring30 = (int) $stmtX30->fetchColumn();

    $stmtX60 = $pdo->prepare("SELECT COUNT(*) FROM department_requirements dr
        WHERE dr.expiration_date IS NOT NULL AND dr.expiration_date != '0000-00-00'
          AND dr.expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
          AND $reqDeptCond");
    $stmtX60->execute($reqDeptParams);
    $expiring60 = (int) $stmtX60->fetchColumn();

    $stmtX90 = $pdo->prepare("SELECT COUNT(*) FROM department_requirements dr
        WHERE dr.expiration_date IS NOT NULL AND dr.expiration_date != '0000-00-00'
          AND dr.expiration_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 61 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
          AND $reqDeptCond");
    $stmtX90->execute($reqDeptParams);
    $expiring90 = (int) $stmtX90->fetchColumn();

    $stmtAllR = $pdo->prepare("SELECT COUNT(*) FROM department_requirements dr WHERE $reqDeptCond");
    $stmtAllR->execute($reqDeptParams);
    $reqTotal = (int) $stmtAllR->fetchColumn();
    $activeDocs = max(0, $reqTotal - $expired - $expiringTotal);

    $monthlyLabels = [];
    $monthlyCounts = [];
    for ($i = 11; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $monthlyLabels[] = date('M Y', strtotime($monthStart));

        $stmtM = $pdo->prepare("SELECT COUNT(*) FROM ($docWindow) d
            WHERE d.archived = 0 AND d.upload_date BETWEEN :start AND :end AND $deptCondition");
        $stmtM->execute(array_merge([':start' => $monthStart, ':end' => $monthEnd . ' 23:59:59'], $deptParams));
        $docCount = (int) $stmtM->fetchColumn();

        $stmtP = $pdo->prepare("SELECT COUNT(*) FROM ($phWindow) pd WHERE pd.archived = 0 AND pd.upload_date BETWEEN :start AND :end");
        $stmtP->execute([':start' => $monthStart, ':end' => $monthEnd . ' 23:59:59']);
        $phCount = (int) $stmtP->fetchColumn();

        $monthlyCounts[] = $docCount + $phCount;
    }

    $weeklyLabels = [];
    $weeklyCounts = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $weeklyLabels[] = date('D', strtotime($date));

        $stmtW = $pdo->prepare("SELECT COUNT(*) FROM ($docWindow) d WHERE d.archived = 0 AND DATE(d.upload_date) = :date AND $deptCondition");
        $stmtW->execute(array_merge([':date' => $date], $deptParams));
        $docCount = (int) $stmtW->fetchColumn();

        $stmtWp = $pdo->prepare("SELECT COUNT(*) FROM ($phWindow) pd WHERE pd.archived = 0 AND DATE(pd.upload_date) = :date");
        $stmtWp->execute([':date' => $date]);
        $phCount = (int) $stmtWp->fetchColumn();

        $weeklyCounts[] = $docCount + $phCount;
    }

    $dateDocPerf = getDateRangeCondition($dateRange, 'doc');
    $sqlDept = "SELECT
                    d.id,
                    d.department_name,
                    COUNT(doc.id) AS total_docs,
                    SUM(CASE WHEN doc.status IN ('rejected_admin','rejected_program_head') THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN doc.status = 'approved_admin' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN doc.status IN ('pending_program_head','pending_admin') THEN 1 ELSE 0 END) AS pending
                FROM departments d
                LEFT JOIN ($docWindow) doc ON d.id = doc.department_id AND doc.archived = 0";
    if ($dateRange !== 'all') {
        $sqlDept .= " AND $dateDocPerf";
    }
    $sqlDept .= " GROUP BY d.id ORDER BY d.department_name";
    $deptRows = $pdo->query($sqlDept)->fetchAll(PDO::FETCH_ASSOC);
    $deptPerformance = [];
    foreach ($deptRows as $dept) {
        $total = (int) $dept['total_docs'];
        $approvedD = (int) $dept['approved'];
        $completion = $total > 0 ? round(($approvedD / $total) * 100) : 0;
        $deptPerformance[] = [
            'name' => $dept['department_name'],
            'total' => $total,
            'approved' => $approvedD,
            'rejected' => (int) $dept['rejected'],
            'pending' => (int) $dept['pending'],
            'completion' => $completion
        ];
    }

    $sqlActivity = "SELECT a.action, u.name, u.email, u.role, a.created_at
                    FROM audit_trail a
                    LEFT JOIN users u ON a.user_id = u.id
                    WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    if ($department !== 'all' && is_numeric($department)) {
        $sqlActivity .= " AND (u.department_id = :dept_id OR a.user_id IS NULL)";
    }
    $sqlActivity .= " ORDER BY a.created_at DESC LIMIT 15";
    $stmtAct = $pdo->prepare($sqlActivity);
    if ($department !== 'all' && is_numeric($department)) {
        $stmtAct->bindValue(':dept_id', (int) $department);
    }
    $stmtAct->execute();
    $userActivity = [];
    foreach ($stmtAct->fetchAll(PDO::FETCH_ASSOC) as $act) {
        $roleLabel = match ((string)($act['role'] ?? '')) {
            '', 'null' => 'System',
            'admin' => 'Admin',
            'teacher' => 'Teacher',
            'staff' => 'Staff',
            default => phRoleName($act['role']),
        };
        // Legacy rows stored as ph_direct_upload* — relabel by the actor's role now.
        $action = $act['action'];
        if (in_array($action, ['ph_direct_upload', 'head_direct_upload', 'ph_direct_upload_forwarded', 'head_direct_upload_forwarded'], true)) {
            $action = $roleLabel . ' Direct Upload' . (str_ends_with($action, '_forwarded') ? ' (Forwarded)' : '');
        }
        $userActivity[] = [
            'action' => $action,
            'user' => $act['name'] ?? null ?: 'System',
            'role' => $roleLabel,
            'time' => $act['created_at'],
            'type' => getActivityType($act['action'])
        ];
    }

    $phPerformance = $pdo->query("
        SELECT
            u.id,
            u.name,
            d.department_name,
            COUNT(DISTINCT doc.id) AS reviewed,
            SUM(CASE WHEN doc.status = 'approved_admin' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN doc.status IN ('rejected_program_head','rejected_admin') THEN 1 ELSE 0 END) AS rejected
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        LEFT JOIN ($docWindow) doc ON doc.reviewed_by_program_head = u.id AND doc.archived = 0
        WHERE u.role IN ('department','unit_head')
        GROUP BY u.id
        ORDER BY reviewed DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $statusData = [
        'labels' => ['Approved', 'Pending', 'Rejected'],
        'data' => [$approvedActive, $pending, $rejected],
        'colors' => ['#10B981', '#F59E0B', '#EF4444']
    ];

    $validityData = [
        'labels' => ['Active', 'Expiring', 'Expired'],
        'data' => [$activeDocs, $expiringTotal, $expired],
        'colors' => ['#3B82F6', '#F59E0B', '#EF4444']
    ];

    $complianceRate = $totalAllActive > 0 ? round(($approvedActive / $totalAllActive) * 100, 1) : 0;

    $avgReviewTime = (int)$pdo->query("
        SELECT COALESCE(AVG(DATEDIFF(reviewed_at_admin, upload_date)), 0)
        FROM documents
        WHERE status = 'approved_admin' AND reviewed_at_admin IS NOT NULL
    ")->fetchColumn();

    $stuckSubmissions = (int)$pdo->query("
        SELECT COUNT(*) FROM documents
        WHERE status IN ('pending_admin','pending_program_head')
        AND archived = 0
        AND upload_date < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn();

    $totalReqs = (int)$pdo->query("SELECT COUNT(*) FROM department_requirements")->fetchColumn();
    $coveredReqs = (int)$pdo->query("
        SELECT COUNT(DISTINCT dr.id)
        FROM department_requirements dr
        JOIN documents d ON d.category = dr.category AND d.department_id = dr.department_id
        WHERE d.status = 'approved_admin' AND d.archived = 0
    ")->fetchColumn();
    $requirementsCoverage = $totalReqs > 0 ? round(($coveredReqs / $totalReqs) * 100, 1) : 0;

    return [
        'total_docs' => $totalDocs,
        'total_ph_docs' => $totalPhDocs,
        'total_all' => $totalAllActive,
        'repo_total' => $repoTotal,
        'repo_docs' => $repoDocs,
        'repo_ph_docs' => $repoPhDocs,
        'archived' => $archived,
        'pending' => $pending,
        'pending_docs' => $pendingDocs,
        'approved' => $approved,
        'approved_docs' => $approvedDocs,
        'approved_ph' => $approvedPh,
        'rejected' => $rejected,
        'rejected_docs' => $rejectedDocs,
        'rejected_ph' => $rejectedPh,
        'expired' => $expired,
        'active_docs' => $activeDocs,
        'expiring_total' => $expiringTotal,
        'expiring30' => $expiring30,
        'expiring60' => $expiring60,
        'expiring90' => $expiring90,
        'compliance_rate' => $complianceRate,
        'avg_review_time' => $avgReviewTime,
        'stuck_submissions' => $stuckSubmissions,
        'requirements_coverage' => $requirementsCoverage,
        'covered_requirements' => $coveredReqs,
        'total_requirements' => $totalReqs,
        'total_users' => $totalUsers,
        'total_faculty' => $totalFaculty,
        'total_program_heads' => $totalProgramHeads,
        'total_unit_heads' => $totalUnitHeads,
        'total_admins' => $totalAdmins,
        'monthly_trends' => ['labels' => $monthlyLabels, 'data' => $monthlyCounts],
        'weekly_trends' => ['labels' => $weeklyLabels, 'data' => $weeklyCounts],
        'department_performance' => $deptPerformance,
        'ph_performance' => $phPerformance,
        'user_activity' => $userActivity,
        'status_data' => $statusData,
        'validity_data' => $validityData
    ];
}

function getDateRangeCondition($range, $alias = 'd')
{
    switch ($range) {
        case 'today':
            return "DATE($alias.upload_date) = CURDATE()";
        case 'last_7_days':
            return "$alias.upload_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case 'last_30_days':
            return "$alias.upload_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        case 'last_90_days':
            return "$alias.upload_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
        case 'this_month':
            return "YEAR($alias.upload_date) = YEAR(CURDATE()) AND MONTH($alias.upload_date) = MONTH(CURDATE())";
        case 'last_month':
            return "$alias.upload_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND $alias.upload_date < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        default:
            return "1=1";
    }
}

function getActivityType($action)
{
    if (stripos($action, 'approve') !== false) return 'approve';
    if (stripos($action, 'reject') !== false) return 'reject';
    if (stripos($action, 'upload') !== false) return 'upload';
    if (stripos($action, 'update') !== false) return 'update';
    return 'other';
}

function json_export_data($filters)
{
    $data = getAnalyticsData($filters);
    $type = $filters['export_type'] ?? 'csv';

    if ($type === 'json') {
        return json_encode(['success' => true, 'data' => $data]);
    }

    $filename = 'analytics_export_' . date('Y-m-d') . '.csv';
    $filepath = __DIR__ . '/exports/' . $filename;
    if (!is_dir(__DIR__ . '/exports')) mkdir(__DIR__ . '/exports', 0755, true);
    if (!file_exists(__DIR__ . '/exports/.htaccess')) @file_put_contents(__DIR__ . '/exports/.htaccess', "Deny from all\n");

    $file = fopen($filepath, 'w');
    fputcsv($file, ['Metric', 'Value', 'Date']);
    fputcsv($file, ['Total Documents (Active)', $data['total_all'], date('Y-m-d')]);
    fputcsv($file, ['Pending Documents', $data['pending'], date('Y-m-d')]);
    fputcsv($file, ['Approved Documents', $data['approved'], date('Y-m-d')]);
    fputcsv($file, ['Rejected Documents', $data['rejected'], date('Y-m-d')]);
    fputcsv($file, ['Archived Documents', $data['archived'], date('Y-m-d')]);
    fputcsv($file, ['Active Documents', $data['active_docs'], date('Y-m-d')]);
    fputcsv($file, ['Expiring Documents (≤' . EXPIRY_WARNING_DAYS . 'd)', $data['expiring_total'], date('Y-m-d')]);
    fputcsv($file, ['Expired Documents', $data['expired'], date('Y-m-d')]);
    fputcsv($file, ['Compliance Rate', $data['compliance_rate'] . '%', date('Y-m-d')]);
    fputcsv($file, ['Total Users', $data['total_users'], date('Y-m-d')]);
    fputcsv($file, ['Average Review Time', $data['avg_review_time'] . ' days', date('Y-m-d')]);
    fputcsv($file, ['Stuck Submissions', $data['stuck_submissions'], date('Y-m-d')]);
    fputcsv($file, ['Monthly Trends', '', '']);
    foreach ($data['monthly_trends']['labels'] as $i => $label) {
        fputcsv($file, [$label, $data['monthly_trends']['data'][$i], '']);
    }
    fclose($file);

    return json_encode(['success' => true, 'filename' => $filename]);
}

// ===========================================================
// Load initial analytics data (unfiltered) for page display
// ===========================================================
$analyticsData = getAnalyticsData(['date_range' => 'all', 'department' => 'all']);
$totalAllDocs = $analyticsData['total_all'];
$repoTotal = $analyticsData['repo_total'];
$repoDocs = $analyticsData['repo_docs'];
$repoPhDocs = $analyticsData['repo_ph_docs'];
$archived = $analyticsData['archived'];
$pending = $analyticsData['pending'];
$pendingDocs = $analyticsData['pending_docs'];
$approved = $analyticsData['approved'];
$approvedDocs = $analyticsData['approved_docs'];
$approvedPh = $analyticsData['approved_ph'];
$rejected = $analyticsData['rejected'];
$rejectedDocs = $analyticsData['rejected_docs'];
$rejectedPh = $analyticsData['rejected_ph'];
$expiredDocs = $analyticsData['expired'];
$activeDocs = $analyticsData['active_docs'];
$expiringTotal = $analyticsData['expiring_total'];
$expiring30 = $analyticsData['expiring30'];
$expiring60 = $analyticsData['expiring60'];
$expiring90 = $analyticsData['expiring90'];
$complianceRate = $analyticsData['compliance_rate'];
$avgReviewTime = $analyticsData['avg_review_time'];
$stuckSubmissions = $analyticsData['stuck_submissions'];
$requirementsCoverage = $analyticsData['requirements_coverage'];
$totalUsers = $analyticsData['total_users'];
$totalFaculty = $analyticsData['total_faculty'];
$totalProgramHeads = $analyticsData['total_program_heads'];
$totalUnitHeads = $analyticsData['total_unit_heads'];
$totalAdmins = $analyticsData['total_admins'];
$monthlyLabels = $analyticsData['monthly_trends']['labels'];
$monthlyCounts = $analyticsData['monthly_trends']['data'];
$weeklyLabels = $analyticsData['weekly_trends']['labels'];
$weeklyCounts = $analyticsData['weekly_trends']['data'];
$deptStats = $analyticsData['department_performance'];
$phPerformance = $analyticsData['ph_performance'];
$recentActivity = $analyticsData['user_activity'];

// Check if system has no data at all
$isEmptySystem = ($totalAllDocs === 0 && $totalUsers === 0);

$pageTitle = "Analytics Dashboard";
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
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        --chart-surface: #ffffff;
        --app-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }

    [data-theme="dark"] {
        --gray-50: #0f172a;
        --gray-100: #1e293b;
        --gray-200: #334155;
        --gray-600: #94a3b8;
        --gray-700: #cbd5e1;
        --gray-900: #f8fafc;
        --white: #1e293b;
        --border: #334155;
        --chart-surface: #1e293b;
        --app-bg: #0f172a;
    }

    * {
        box-sizing: border-box;
    }

    body {
        background: var(--app-bg);
        font-family: 'Inter', sans-serif;
        -webkit-font-smoothing: antialiased;
    }

    .content-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding: 1.25rem 1.5rem !important;
        gap: 0;
    }

    .analytics-container {
        flex: 1;
        width: 100%;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        box-shadow: var(--shadow);
    }

    .page-header h1 {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        letter-spacing: -0.02em;
    }

    .page-header .header-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--primary) 0%, #6366f1 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .page-header .header-icon svg {
        width: 18px;
        height: 18px;
    }

    .page-header .subtitle {
        font-size: 0.78rem;
        color: var(--gray-500);
        margin-top: 0.2rem;
        font-weight: 500;
    }

    .live-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.55rem;
        background: var(--success-light);
        color: #047857;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
    }

    .live-dot {
        width: 6px;
        height: 6px;
        background: var(--success);
        border-radius: 50%;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.3;
        }
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 0.75rem;
    }

    .stat-card {
        background: var(--white);
        border-radius: 12px;
        padding: 1rem 0.9rem;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        background: var(--kpi-accent, var(--primary));
        opacity: 1;
        top: auto;
    }

    .stat-card::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, transparent 60%, rgba(0, 0, 0, 0.01) 100%);
        pointer-events: none;
    }

    .stat-card[data-accent="primary"] {
        --kpi-accent: var(--primary);
    }

    .stat-card[data-accent="success"] {
        --kpi-accent: var(--success);
    }

    .stat-card[data-accent="warning"] {
        --kpi-accent: var(--warning);
    }

    .stat-card[data-accent="danger"] {
        --kpi-accent: var(--danger);
    }

    .stat-card[data-accent="info"] {
        --kpi-accent: var(--info);
    }

    .stat-card[data-accent="secondary"] {
        --kpi-accent: var(--secondary);
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
        border-color: var(--gray-200);
    }

    .stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid rgba(0, 0, 0, 0.04);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .stat-icon svg {
        width: 18px;
        height: 18px;
    }

    .stat-info {
        flex: 1;
        min-width: 0;
    }

    .stat-info h3 {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-500);
        margin-bottom: 0.2rem;
    }

    .stat-number {
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--gray-900);
        line-height: 1.1;
        letter-spacing: -0.02em;
    }

    .report-card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 0;
    }

    .card-header {
        padding: 0.9rem 1.1rem;
        background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .card-header h3 {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
        letter-spacing: -0.01em;
    }

    .card-header h3::before {
        content: '';
        width: 3px;
        height: 16px;
        background: var(--primary);
        border-radius: 9999px;
        display: inline-block;
    }

    .card-header h3 svg {
        width: 16px;
        height: 16px;
        color: var(--primary);
    }

    .card-body {
        padding: 1.1rem 1.25rem;
    }

    .charts-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .chart-wrap {
        position: relative;
        height: 200px;
    }

    .chart-wrap.small {
        height: 170px;
    }

    .chart-wrap.large {
        height: 280px;
    }

    .doughnut-container {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1.5rem;
        flex-wrap: wrap;
    }

    .doughnut-chart {
        position: relative;
        width: 180px;
        height: 180px;
        max-width: 100%;
    }

    @media (max-width: 768px) {
        .doughnut-chart {
            width: 150px;
            height: 150px;
        }
    }

    .doughnut-legend {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.78rem;
    }

    .legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
    }

    .legend-text {
        color: var(--gray-700);
        font-weight: 500;
    }

    .legend-value {
        margin-left: auto;
        font-weight: 700;
        color: var(--gray-900);
    }

    .alert-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 0.75rem;
    }

    .alert-card {
        background: var(--white);
        border-radius: 12px;
        padding: 1rem 0.9rem;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .alert-card::before {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        top: auto;
    }

    .alert-card.danger {
        --kpi-accent: var(--danger);
    }

    .alert-card.danger::before {
        background: var(--danger);
    }

    .alert-card.danger .alert-card-icon {
        background: var(--danger-light);
        color: var(--danger);
    }

    .alert-card.warning {
        --kpi-accent: var(--warning);
    }

    .alert-card.warning::before {
        background: var(--warning);
    }

    .alert-card.warning .alert-card-icon {
        background: var(--warning-light);
        color: var(--warning);
    }

    .alert-card.success {
        --kpi-accent: var(--success);
    }

    .alert-card.success::before {
        background: var(--success);
    }

    .alert-card.success .alert-card-icon {
        background: var(--success-light);
        color: var(--success);
    }

    .alert-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .alert-card-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid rgba(0, 0, 0, 0.04);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .alert-card-icon svg {
        width: 18px;
        height: 18px;
    }

    .alert-card-info {
        flex: 1;
        min-width: 0;
    }

    .alert-card-info h3 {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-500);
        margin-bottom: 0.2rem;
    }

    .alert-card-number {
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1.1;
        letter-spacing: -0.02em;
    }

    .alert-card-number.danger {
        color: #DC2626;
    }

    .alert-card-number.warning {
        color: #D97706;
    }

    .alert-card-number.success {
        color: #059669;
    }

    .table-wrap {
        overflow-x: auto;
    }

    .data-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.82rem;
    }

    .data-table th {
        background: var(--gray-50);
        padding: 0.75rem 0.9rem;
        text-align: left;
        font-weight: 700;
        color: var(--gray-500);
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .data-table td {
        padding: 0.7rem 0.9rem;
        border-bottom: 1px solid var(--gray-100);
        color: var(--gray-700);
    }

    .data-table tbody tr:last-child td {
        border-bottom: none;
    }

    .data-table tbody tr:hover td {
        background: #f8fafc;
    }

    [data-theme="dark"] .data-table tbody tr:hover td {
        background: var(--gray-100);
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.22rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        border: 1px solid transparent;
        line-height: 1.25;
    }

    .badge.success {
        background: #d1fae5;
        color: #065f46;
        border-color: #a7f3d0;
    }

    .badge.warning {
        background: #fef3c7;
        color: #92400e;
        border-color: #fde68a;
    }

    .badge.danger {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .completion-cell {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .progress-bar {
        background: var(--gray-100);
        border-radius: 9999px;
        height: 8px;
        overflow: hidden;
        flex: 1;
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

    .completion-percent {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--gray-700);
        min-width: 2.5rem;
        text-align: right;
    }

    .activity-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        max-height: 320px;
        overflow-y: auto;
    }

    .activity-item {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.7rem 0.85rem;
        background: var(--gray-50);
        border-radius: 10px;
        font-size: 0.82rem;
        transition: background 0.15s;
    }

    .activity-item:hover {
        background: var(--gray-100);
    }

    .activity-icon {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-weight: 800;
        font-size: 0.85rem;
    }

    .activity-icon svg {
        width: 16px;
        height: 16px;
    }

    .activity-icon.approve {
        background: var(--success-light);
        color: var(--success);
    }

    .activity-icon.reject {
        background: var(--danger-light);
        color: var(--danger);
    }

    .activity-icon.upload {
        background: var(--primary-light);
        color: var(--primary);
    }

    .activity-icon.update {
        background: var(--info-light);
        color: var(--info);
    }

    .activity-icon.other {
        background: var(--gray-200);
        color: var(--gray-600);
    }

    .activity-content {
        flex: 1;
        min-width: 0;
    }

    .activity-title {
        font-weight: 600;
        color: var(--gray-900);
        font-size: 0.82rem;
    }

    .activity-meta {
        font-size: 0.72rem;
        color: var(--gray-600);
        margin-top: 0.15rem;
        font-weight: 500;
    }

    .comparison-container {
        display: flex;
        justify-content: space-around;
        margin-top: 1rem;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding-top: 0.75rem;
        border-top: 1px solid var(--border);
    }

    .comparison-item {
        text-align: center;
    }

    .comparison-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--gray-900);
        letter-spacing: -0.02em;
    }

    .comparison-label {
        font-size: 0.68rem;
        color: var(--gray-600);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .two-col {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 1rem;
    }

    .chart-type-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        padding: 0;
        border: 1px solid var(--border);
        background: var(--white);
        border-radius: 8px;
        cursor: pointer;
        transition: var(--transition);
        color: var(--gray-500);
    }

    .chart-type-btn svg {
        width: 15px;
        height: 15px;
    }

    .chart-type-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    .chart-type-btn.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .chart-type-btn:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Empty State Styles */
    .empty-state-container {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 60vh;
        padding: 2rem;
    }

    .empty-state-card {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 3rem 2.5rem;
        text-align: center;
        box-shadow: var(--shadow);
        max-width: 520px;
        width: 100%;
    }

    .empty-state-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: var(--gray-100);
        color: var(--gray-400);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        border: 2px solid var(--border);
    }

    .empty-state-icon svg {
        width: 40px;
        height: 40px;
    }

    .empty-state-title {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--gray-900);
        margin: 0 0 0.75rem;
        letter-spacing: -0.02em;
    }

    .empty-state-subtitle {
        font-size: 0.9rem;
        color: var(--gray-500);
        margin: 0 0 2rem;
        line-height: 1.5;
    }

    .empty-state-stats {
        display: flex;
        justify-content: center;
        gap: 2rem;
        margin-bottom: 2rem;
        padding-bottom: 2rem;
        border-bottom: 1px solid var(--border);
    }

    .empty-stat {
        text-align: center;
    }

    .empty-stat-number {
        display: block;
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--gray-900);
    }

    .empty-stat-label {
        font-size: 0.72rem;
        color: var(--gray-500);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .empty-state-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    .empty-state-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        transition: var(--transition);
        border: none;
        cursor: pointer;
    }

    .empty-state-btn.primary {
        background: var(--primary);
        color: white;
    }

    .empty-state-btn.primary:hover {
        background: var(--primary-dark);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        transform: translateY(-1px);
    }

    .empty-state-btn.secondary {
        background: transparent;
        color: var(--gray-700);
        border: 1px solid var(--border);
    }

    .empty-state-btn.secondary:hover {
        background: var(--gray-50);
        border-color: var(--gray-300);
    }

    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .alert-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .two-col {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .alert-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .charts-row {
            grid-template-columns: 1fr;
        }

        .analytics-container {
            gap: 0.75rem;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .alert-grid {
            grid-template-columns: 1fr;
        }
    }

    [data-theme="dark"] .card-header {
        background: var(--gray-100);
    }

    [data-theme="dark"] .activity-item {
        background: var(--gray-100);
    }

    [data-theme="dark"] .filter-bar {
        background: var(--white);
    }
</style>
<?php
$extraStyles = ob_get_clean();
include 'includes/header.php';
?>

<?php if (!$isEmptySystem): ?>
    <div class="analytics-container">


        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card" data-accent="primary">
                <div class="stat-icon" style="background:var(--primary-light);color:var(--primary);">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div class="stat-info">
                    <h3>Total Documents</h3>
                    <div class="stat-number" id="kpi_total_all"><?= number_format($repoTotal) ?></div>
                </div>
            </div>
            <div class="stat-card" data-accent="warning">
                <div class="stat-icon" style="background:var(--warning-light);color:var(--warning);">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-info">
                    <h3>Pending</h3>
                    <div class="stat-number" id="kpi_pending"><?= $pending ?></div>
                </div>
            </div>
            <div class="stat-card" data-accent="success">
                <div class="stat-icon" style="background:var(--success-light);color:var(--success);">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-info">
                    <h3>Approved</h3>
                    <div class="stat-number" id="kpi_approved"><?= $approved ?></div>
                </div>
            </div>
            <div class="stat-card" data-accent="danger">
                <div class="stat-icon" style="background:var(--danger-light);color:var(--danger);">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-info">
                    <h3>Rejected</h3>
                    <div class="stat-number" id="kpi_rejected"><?= $rejected ?></div>
                </div>
            </div>
            <div class="stat-card" data-accent="secondary">
                <div class="stat-icon" style="background:var(--gray-200);color:var(--gray-600);">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                    </svg>
                </div>
                <div class="stat-info">
                    <h3>Archived</h3>
                    <div class="stat-number" id="kpi_archived"><?= $archived ?></div>
                </div>
            </div>
            <div class="stat-card" data-accent="info">
                <div class="stat-icon" style="background:var(--info-light);color:var(--info);">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div class="stat-info">
                    <h3>Total Users</h3>
                    <div class="stat-number" id="kpi_total_users"><?= $totalUsers ?></div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1: Status + Validity Doughnuts -->
        <div class="charts-row">
            <div class="report-card">
                <div class="card-header">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                        </svg> Document Status</h3>
                </div>
                <div class="card-body">
                    <div class="doughnut-container" id="statusChartContainer">
                        <div class="doughnut-chart"><canvas id="statusChart"></canvas></div>
                        <div class="doughnut-legend" id="statusLegend"></div>
                    </div>
                </div>
            </div>
            <div class="report-card">
                <div class="card-header">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg> Document Validity</h3>
                </div>
                <div class="card-body">
                    <div class="doughnut-container" id="validityChartContainer">
                        <div class="doughnut-chart"><canvas id="validityChart"></canvas></div>
                        <div class="doughnut-legend" id="validityLegend"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Expiry Alerts -->
        <h2 class="section-title">Expiring &amp; Expired Documents</h2>
        <div class="alert-grid">
            <div class="alert-card danger">
                <div class="alert-card-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.667 1.73-3L13.73 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg></div>
                <div class="alert-card-info">
                    <h3>Expired</h3>
                    <div class="alert-card-number danger" id="exp_expired"><?= $expiredDocs ?></div>
                </div>
            </div>
            <div class="alert-card warning">
                <div class="alert-card-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg></div>
                <div class="alert-card-info">
                    <h3>Expiring ≤<?= EXPIRY_WARNING_DAYS ?>d</h3>
                    <div class="alert-card-number warning" id="exp_total"><?= $expiringTotal ?></div>
                </div>
            </div>
            <div class="alert-card success">
                <div class="alert-card-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg></div>
                <div class="alert-card-info">
                    <h3>Active &amp; Valid</h3>
                    <div class="alert-card-number success" id="exp_active"><?= $activeDocs ?></div>
                </div>
            </div>
            <div class="alert-card warning">
                <div class="alert-card-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg></div>
                <div class="alert-card-info">
                    <h3>Next 30 Days</h3>
                    <div class="alert-card-number warning" id="exp30"><?= $expiring30 ?></div>
                </div>
            </div>
            <div class="alert-card warning">
                <div class="alert-card-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg></div>
                <div class="alert-card-info">
                    <h3>31–60 Days</h3>
                    <div class="alert-card-number warning" id="exp60"><?= $expiring60 ?></div>
                </div>
            </div>
            <div class="alert-card warning">
                <div class="alert-card-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg></div>
                <div class="alert-card-info">
                    <h3>61–90 Days</h3>
                    <div class="alert-card-number warning" id="exp90"><?= $expiring90 ?></div>
                </div>
            </div>
        </div>

        <!-- Weekly Trends + Users -->
        <div class="two-col">
            <div class="report-card">
                <div class="card-header">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg> Weekly Uploads</h3>
                    <div>
                        <button class="chart-type-btn active" data-csp-action="switchChartType" data-csp-args='["weekly","line"]' data-csp-event aria-label="Show line chart"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="3 17 9 11 13 15 21 7" />
                                <polyline points="14 7 21 7 21 14" />
                            </svg></button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="weeklyChart"></canvas></div>
                </div>
            </div>
            <div class="report-card">
                <div class="card-header">
                    <h3><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6z" />
                        </svg> Users by Role</h3>
                </div>
                <div class="card-body">
                    <div class="chart-wrap small"><canvas id="usersChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Monthly Trends -->
        <h2 class="section-title">Monthly Submission Trends</h2>
        <div class="report-card">
            <div class="card-header">
                <h3>Document Submissions Over Time</h3>
                <div>
                    <button class="chart-type-btn active" data-csp-action="switchChartType" data-csp-args='["monthly","bar"]' data-csp-el aria-label="Show bar chart"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="12" width="4" height="9" />
                            <rect x="10" y="7" width="4" height="14" />
                            <rect x="17" y="3" width="4" height="18" />
                        </svg></button>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-wrap large"><canvas id="monthlyChart"></canvas></div>
                <div class="comparison-container" id="monthlyStats"></div>
            </div>
        </div>

        <!-- Department Performance -->
        <h2 class="section-title">Department Performance</h2>
        <div class="report-card">
            <div class="card-header">
                <h3>Document Status by Department</h3>
            </div>
            <div class="table-wrap">
                <table class="data-table" id="deptTable">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th style="text-align:center;">Total</th>
                            <th style="text-align:center;">Approved</th>
                            <th style="text-align:center;">Pending</th>
                            <th style="text-align:center;">Rejected</th>
                            <th>Completion</th>
                        </tr>
                    </thead>
                    <tbody id="deptTableBody">
                        <?php foreach ($deptStats as $dept):
                            $completion = $dept['completion'];
                            $fillClass = $completion >= 80 ? 'green' : ($completion >= 50 ? 'yellow' : 'red');
                        ?>
                            <tr>
                                <td><?= sanitize($dept['name']) ?></td>
                                <td style="text-align:center;"><?= $dept['total'] ?></td>
                                <td style="text-align:center;"><span class="badge success"><?= $dept['approved'] ?></span></td>
                                <td style="text-align:center;"><span class="badge warning"><?= $dept['pending'] ?></span></td>
                                <td style="text-align:center;"><span class="badge danger"><?= $dept['rejected'] ?></span></td>
                                <td>
                                    <div class="completion-cell">
                                        <div class="progress-bar">
                                            <div class="progress-fill <?= $fillClass ?>" style="width: <?= $completion ?>%"></div>
                                        </div>
                                        <span class="completion-percent"><?= $completion ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Activity -->
        <h2 class="section-title">Recent Activity</h2>
        <div class="report-card">
            <div class="card-header">
                <h3>Audit Trail (Last 24 Hours)</h3>
            </div>
            <div class="card-body">
                <div class="activity-list" id="activityList">
                    <?php
                    $actIconMap = [
                        'approve' => '<polyline points="20 6 9 17 4 12"/>',
                        'reject'  => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
                        'upload'  => '<path d="M12 19V6"/><path d="M5 12l7-7 7 7"/>',
                        'update'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
                        'other'   => '<circle cx="12" cy="12" r="2"/>'
                    ];
                    foreach ($recentActivity as $activity):
                        $iconClass = $activity['type'];
                        $actSvg = $actIconMap[$iconClass] ?? $actIconMap['other'];
                    ?>
                        <div class="activity-item">
                            <div class="activity-icon <?= $iconClass ?>" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $actSvg ?></svg></div>
                            <div class="activity-content">
                                <div class="activity-title"><?= sanitize($activity['action']) ?></div>
                                <div class="activity-meta"><?= sanitize($activity['user']) ?> · <?= sanitize($activity['role']) ?> · <?= timeAgo($activity['time']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

<?php else: ?>

    <div class="empty-state-container">
        <div class="empty-state-card">
            <div class="empty-state-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.088-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m-16.5 0c0 .72.848 1.167 1.594 1.167.72 0 1.594-.447 1.594-1.167V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m16.5 0v3.75a2.18 2.18 0 0 1-.75 1.661v5.13c0 1.094.787 2.036 1.872 2.18a2.18 2.18 0 0 1-1.872 2.18H6.75a2.18 2.18 0 0 1-1.872-2.18v-5.13a2.18 2.18 0 0 1-.75-1.661v-3.75m16.5 0V6.75a2.18 2.18 0 0 0-1.594-2.175 48.114 48.114 0 0 0-3.413-.387m-16.5 0a2.18 2.18 0 0 0-1.594 2.175V6.75c0 1.081.768 2.015 1.837 2.175.72.213 1.594.42 1.594.167Z" />
                </svg>
            </div>
            <h1 class="empty-state-title">No Pages in the System</h1>
            <p class="empty-state-subtitle">There are currently no documents, users, or departments recorded. Add data to see system analytics.</p>
            <div class="empty-state-stats">
                <div class="empty-stat">
                    <span class="empty-stat-number">0</span>
                    <span class="empty-stat-label">Documents</span>
                </div>
                <div class="empty-stat">
                    <span class="empty-stat-number">0</span>
                    <span class="empty-stat-label">Users</span>
                </div>
                <div class="empty-stat">
                    <span class="empty-stat-number">0</span>
                    <span class="empty-stat-label">Departments</span>
                </div>
            </div>
            <div class="empty-state-actions">
                <a href="admin_manage_document.php" class="empty-state-btn primary">Add First Document</a>
                <a href="admin_manage_departments.php" class="empty-state-btn secondary">Manage Departments</a>
            </div>
        </div>
    </div>

<?php endif; ?>

<script src="offline/chart.js"></script>
<script nonce="<?= csp_nonce() ?>">
    <?php if (!$isEmptySystem): ?>
        Chart.defaults.font.family = "'Inter', sans-serif";
        let chartInstances = {};

        let chartTypes = {
            status: 'doughnut',
            validity: 'doughnut',
            weekly: 'line',
            monthly: 'bar'
        };

        let currentStatusData = <?= json_encode($analyticsData['status_data']) ?>;
        let currentValidityData = <?= json_encode($analyticsData['validity_data']) ?>;
        let weeklyLabels = <?= json_encode($weeklyLabels) ?>;
        let weeklyCounts = <?= json_encode(array_map('intval', $weeklyCounts)) ?>;
        let monthlyLabels = <?= json_encode($monthlyLabels) ?>;
        let monthlyCounts = <?= json_encode(array_map('intval', $monthlyCounts)) ?>;
        let usersData = [<?= $totalFaculty ?>, <?= $totalProgramHeads ?>, <?= $totalUnitHeads ?>, <?= $totalAdmins ?>];
        const phDeptLabel = <?= json_encode(phRoleName('department')) ?>;
        const phUnitLabel = <?= json_encode(phRoleName('unit_head')) ?>;

        function chartBorderColor() {
            const v = getComputedStyle(document.documentElement).getPropertyValue('--chart-surface').trim();
            return v || '#ffffff';
        }

        function isDark() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }

        function gridColor() {
            return isDark() ? 'rgba(148,163,184,0.18)' : 'rgba(15,23,42,0.08)';
        }

        function tickColor() {
            return isDark() ? '#cbd5e1' : '#475569';
        }

        function initStatusChart(type) {
            chartTypes.status = type;
            const ctx = document.getElementById('statusChart');
            if (chartInstances.status) chartInstances.status.destroy();
            chartInstances.status = new Chart(ctx, {
                type: type,
                data: {
                    labels: currentStatusData.labels,
                    datasets: [{
                        data: currentStatusData.data,
                        backgroundColor: currentStatusData.colors,
                        borderWidth: type === 'bar' ? 0 : 2,
                        borderColor: chartBorderColor()
                    }]
                },
                options: type === 'bar' ?
                    {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: gridColor()
                                },
                                ticks: {
                                    color: tickColor()
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: tickColor()
                                }
                            }
                        }
                    } :
                    {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '60%',
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
            });
            updateLegend('statusLegend', currentStatusData);
        }

        function initValidityChart(type) {
            chartTypes.validity = type;
            const ctx = document.getElementById('validityChart');
            if (chartInstances.validity) chartInstances.validity.destroy();
            chartInstances.validity = new Chart(ctx, {
                type: type,
                data: {
                    labels: currentValidityData.labels,
                    datasets: [{
                        data: currentValidityData.data,
                        backgroundColor: currentValidityData.colors,
                        borderWidth: type === 'bar' ? 0 : 2,
                        borderColor: chartBorderColor()
                    }]
                },
                options: type === 'bar' ?
                    {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: gridColor()
                                },
                                ticks: {
                                    color: tickColor()
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: tickColor()
                                }
                            }
                        }
                    } :
                    {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '60%',
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
            });
            updateLegend('validityLegend', currentValidityData);
        }

        function updateLegend(elId, dataObj) {
            const legend = document.getElementById(elId);
            if (!legend) return;
            let total = dataObj.data.reduce((a, b) => a + b, 0);
            legend.innerHTML = dataObj.labels.map((l, i) =>
                `<div class="legend-item"><span class="legend-dot" style="background:${dataObj.colors[i]}"></span>` +
                `<span class="legend-text">${l}</span><span class="legend-value">${total > 0 ? Math.round(dataObj.data[i] / total * 100) : 0}%</span></div>`
            ).join('');
        }

        function initWeeklyChart(type) {
            chartTypes.weekly = type;
            const ctx = document.getElementById('weeklyChart');
            if (chartInstances.weekly) chartInstances.weekly.destroy();
            chartInstances.weekly = new Chart(ctx, {
                type: type,
                data: {
                    labels: weeklyLabels,
                    datasets: [{
                        label: 'Uploads',
                        data: weeklyCounts,
                        backgroundColor: '#4f46e5',
                        borderRadius: 8,
                        borderColor: '#4f46e5',
                        borderWidth: type === 'line' ? 2 : 0,
                        fill: type === 'line',
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: gridColor()
                            },
                            ticks: {
                                precision: 0,
                                color: tickColor()
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: tickColor()
                            }
                        }
                    }
                }
            });
        }

        function initMonthlyChart() {
            chartTypes.monthly = 'bar';
            const ctx = document.getElementById('monthlyChart');
            if (chartInstances.monthly) chartInstances.monthly.destroy();
            chartInstances.monthly = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Submissions',
                        data: monthlyCounts,
                        backgroundColor: 'rgba(79,70,229,0.7)',
                        borderColor: '#4f46e5',
                        borderWidth: 1,
                        borderRadius: 4,
                        pointRadius: 0,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: gridColor()
                            },
                            ticks: {
                                precision: 0,
                                color: tickColor()
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: tickColor()
                            }
                        }
                    }
                }
            });
            const total = monthlyCounts.reduce((a, b) => a + b, 0);
            const avg = Math.round(total / 12);
            const current = monthlyCounts[monthlyCounts.length - 1] || 0;
            const prev = monthlyCounts[monthlyCounts.length - 2] || 0;
            const mom = prev ? ((current - prev) / prev * 100).toFixed(1) : 0;
            document.getElementById('monthlyStats').innerHTML =
                `<div class="comparison-item"><div class="comparison-value">${current}</div><div class="comparison-label">This Month</div></div>` +
                `<div class="comparison-item"><div class="comparison-value" style="color:${mom >= 0 ? '#10B981' : '#EF4444'};">${mom >= 0 ? '+' : ''}${mom}%</div><div class="comparison-label">vs Last Month</div></div>` +
                `<div class="comparison-item"><div class="comparison-value">${total}</div><div class="comparison-label">Last 12 Months</div></div>` +
                `<div class="comparison-item"><div class="comparison-value">${avg}</div><div class="comparison-label">Monthly Average</div></div>`;
        }

        function initUsersChart() {
            const ctx = document.getElementById('usersChart');
            if (chartInstances.users) chartInstances.users.destroy();
            chartInstances.users = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Faculty', phDeptLabel + 's', phUnitLabel + 's', 'Admins'],
                    datasets: [{
                        data: usersData,
                        backgroundColor: ['#3B82F6', '#8B5CF6', '#0EA5E9', '#10B981'],
                        cutout: '60%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        }

        function switchChartType(chartName, type, el) {
            if (el && el.closest) {
                const buttons = el.closest('.card-header').querySelectorAll('.chart-type-btn');
                buttons.forEach(btn => btn.classList.remove('active'));
                el.classList.add('active');
            }
            switch (chartName) {
                case 'status':
                    initStatusChart(type);
                    break;
                case 'validity':
                    initValidityChart(type);
                    break;
                case 'weekly':
                    initWeeklyChart(type);
                    break;
                case 'monthly':
                    initMonthlyChart();
                    break;
            }
        }

        function applyFilters() {
            const filters = {
                date_range: document.getElementById('dateRange').value,
                department: document.getElementById('department').value,
                action: 'get_analytics_data',
                csrf_token: <?= json_encode(getCsrfToken()) ?>
            };
            fetch('admin_analytics.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams(filters)
                })
                .then(res => res.json())
                .then(data => {
                    if (!data) return;

                    if (data.total_all === 0 && data.total_users === 0) {
                        document.querySelector('.analytics-container').innerHTML = `
                    <div class="empty-state-container">
                        <div class="empty-state-card">
                            <div class="empty-state-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.088-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m-16.5 0c0 .72.848 1.167 1.594 1.167.72 0 1.594-.447 1.594-1.167V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m16.5 0v3.75a2.18 2.18 0 0 1-.75 1.661v5.13c0 1.094.787 2.036 1.872 2.18a2.18 2.18 0 0 1-1.872 2.18H6.75a2.18 2.18 0 0 1-1.872-2.18v-5.13a2.18 2.18 0 0 1-.75-1.661v-3.75m16.5 0V6.75a2.18 2.18 0 0 0-1.594-2.175 48.114 48.114 0 0 0-3.413-.387m-16.5 0a2.18 2.18 0 0 0-1.594 2.175V6.75c0 1.081.768 2.015 1.837 2.175.72.213 1.594.42 1.594.167Z"/>
                                </svg>
                            </div>
                            <h1 class="empty-state-title">No Pages in the System</h1>
                            <p class="empty-state-subtitle">There are currently no documents, users, or departments recorded. Add data to see system analytics.</p>
                            <div class="empty-state-stats">
                                <div class="empty-stat"><span class="empty-stat-number">0</span><span class="empty-stat-label">Documents</span></div>
                                <div class="empty-stat"><span class="empty-stat-number">0</span><span class="empty-stat-label">Users</span></div>
                                <div class="empty-stat"><span class="empty-stat-number">0</span><span class="empty-stat-label">Departments</span></div>
                            </div>
                            <div class="empty-state-actions">
                                <a href="admin_manage_document.php" class="empty-state-btn primary">Add First Document</a>
                                <a href="admin_manage_departments.php" class="empty-state-btn secondary">Manage Departments</a>
                            </div>
                        </div>
                    </div>
                `;
                        return;
                    }

                    document.getElementById('kpi_total_all').innerText = data.repo_total.toLocaleString();
                    document.getElementById('kpi_pending').innerText = data.pending;
                    document.getElementById('kpi_approved').innerText = data.approved;
                    document.getElementById('kpi_rejected').innerText = data.rejected;
                    document.getElementById('kpi_archived').innerText = data.archived;
                    document.getElementById('kpi_total_users').innerText = data.total_users;
                    const complianceEl = document.getElementById('kpi_compliance');
                    if (complianceEl) complianceEl.innerText = data.compliance_rate + '%';
                    const subCompliance = document.getElementById('kpi_sub_compliance');
                    if (subCompliance) subCompliance.innerHTML = `${data.active_docs} active · ${data.expired} expired`;
                    const avgReviewEl = document.getElementById('kpi_avg_review');
                    if (avgReviewEl) avgReviewEl.innerText = data.avg_review_time;
                    const stuckEl = document.getElementById('kpi_stuck');
                    if (stuckEl) stuckEl.innerText = data.stuck_submissions;
                    const reqCovEl = document.getElementById('kpi_req_coverage');
                    if (reqCovEl) reqCovEl.innerText = data.requirements_coverage + '%';
                    const subReq = document.getElementById('kpi_sub_req');
                    if (subReq) subReq.innerHTML = `${data.covered_requirements}/${data.total_requirements} assigned`;

                    document.getElementById('exp_expired').innerText = data.expired;
                    document.getElementById('exp_total').innerText = data.expiring_total;
                    document.getElementById('exp_active').innerText = data.active_docs;
                    document.getElementById('exp30').innerText = data.expiring30;
                    document.getElementById('exp60').innerText = data.expiring60;
                    document.getElementById('exp90').innerText = data.expiring90;

                    currentStatusData = data.status_data;
                    initStatusChart(chartTypes.status);

                    currentValidityData = data.validity_data;
                    initValidityChart(chartTypes.validity);

                    weeklyLabels = data.weekly_trends.labels;
                    weeklyCounts = data.weekly_trends.data;
                    initWeeklyChart(chartTypes.weekly);

                    monthlyLabels = data.monthly_trends.labels;
                    monthlyCounts = data.monthly_trends.data;
                    initMonthlyChart();

                    usersData = [data.total_faculty, data.total_program_heads, data.total_unit_heads, data.total_admins];
                    initUsersChart();

                    const deptBody = document.getElementById('deptTableBody');
                    deptBody.innerHTML = '';
                    data.department_performance.forEach(dept => {
                        let fillClass = dept.completion >= 80 ? 'green' : (dept.completion >= 50 ? 'yellow' : 'red');
                        deptBody.innerHTML += `<tr><td>${escapeHtml(dept.name)}</td><td style="text-align:center;">${dept.total}</td><td style="text-align:center;"><span class="badge success">${dept.approved}</span></td><td style="text-align:center;"><span class="badge warning">${dept.pending}</span></td><td style="text-align:center;"><span class="badge danger">${dept.rejected}</span></td><td><div class="completion-cell"><div class="progress-bar"><div class="progress-fill ${fillClass}" style="width: ${dept.completion}%"></div></div><span class="completion-percent">${dept.completion}%</span></div></td></tr>`;
                    });

                    const actList = document.getElementById('activityList');
                    actList.innerHTML = '';
                    if (data.user_activity.length === 0) {
                        actList.innerHTML = '<div class="activity-item"><div class="activity-icon other"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"/></svg></div><div class="activity-content"><div class="activity-title">No recent activity</div></div></div>';
                    } else {
                        const actSvgMap = {
                            approve: '<polyline points="20 6 9 17 4 12"/>',
                            reject: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
                            upload: '<path d="M12 19V6"/><path d="M5 12l7-7 7 7"/>',
                            update: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
                            other: '<circle cx="12" cy="12" r="2"/>'
                        };
                        data.user_activity.slice(0, 15).forEach(act => {
                            let iconClass = act.type;
                            let svg = actSvgMap[iconClass] || actSvgMap['other'];
                            actList.innerHTML += `<div class="activity-item"><div class="activity-icon ${iconClass}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${svg}</svg></div><div class="activity-content"><div class="activity-title">${escapeHtml(act.action)}</div><div class="activity-meta">${escapeHtml(act.user)} · ${escapeHtml(act.role || '')} · ${act.time}</div></div></div>`;
                        });
                    }
                }).catch(console.error);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initStatusChart('doughnut');
            initValidityChart('doughnut');
            initWeeklyChart('line');
            initMonthlyChart('line');
            initUsersChart();
        });

        setInterval(() => {
            applyFilters();
        }, 30000);
    <?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>