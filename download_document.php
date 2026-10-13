<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: admin_manage_document.php?error=Invalid document ID');
    exit;
}

$docId = (int)$_GET['id'];

// Get the document
$stmt = $pdo->prepare("
    SELECT d.*, dep.department_name
    FROM documents d
    JOIN departments dep ON d.department_id = dep.id
    WHERE d.id = ? AND d.status = 'approved_admin' AND d.archived = 0
");
$stmt->execute([$docId]);
$document = $stmt->fetch();

if (!$document) {
    header('Location: admin_manage_document.php?error=Document not found or not approved');
    exit;
}

$filePath = securePath($document['file_path']);

if (!$filePath || !file_exists($filePath)) {
    header('Location: admin_manage_document.php?error=File not found on server.');
    exit;
}

// Log the download
logAudit($pdo, 'download_document', 'Downloaded document: ' . $document['title'], 'document', $docId);

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
