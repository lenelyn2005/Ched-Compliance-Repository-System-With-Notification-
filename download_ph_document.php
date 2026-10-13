<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: admin_manage_document.php?error=Invalid document ID');
    exit;
}

$docId = (int)$_GET['id'];

// Get the PH document
$stmt = $pdo->prepare("
    SELECT pd.*, dep.department_name
    FROM ph_documents pd
    JOIN departments dep ON pd.department_id = dep.id
    WHERE pd.id = ? AND pd.status = 'approved_admin' AND pd.archived = 0
");
$stmt->execute([$docId]);
$document = $stmt->fetch();

if (!$document) {
    header('Location: admin_manage_document.php?error=PH Document not found or not approved');
    exit;
}

$filePath = securePath($document['file_path']);

if (!$filePath || !file_exists($filePath)) {
    header('Location: admin_manage_document.php?error=File not found on server.');
    exit;
}

// Log the download
logAudit($pdo, 'download_document', 'Downloaded PH document: ' . $document['title'], 'ph_document', $docId);

// Set headers for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($document['file_path']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
?>
