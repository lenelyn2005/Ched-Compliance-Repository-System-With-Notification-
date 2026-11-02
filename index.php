<?php
define('CHED_APP', true);
require_once 'config.php';

if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin_dashboard.php');
            break;
        case 'super_admin':
            header('Location: super_admin_dashboard.php');
            break;
        case 'department':
        case 'unit_head':
            header('Location: program_head_dashboard.php');
            break;
        case 'teacher':
        case 'staff':
            header('Location: teacher_dashboard.php');
            break;
    }
    exit;
}
header('Location: login.php');
exit;
