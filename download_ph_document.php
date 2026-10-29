<?php
if (!defined('CHED_APP')) define('CHED_APP', true);
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

$filePath = (string) $document['file_path'];

// Storage outage must not read as "file not found" (plan §9).
try {
    $fileAvailable = storageObjectExists($filePath);
} catch (StorageException $e) {
    error_log('[CHED] download_ph_document blocked, storage unavailable: ' . get_class($e));
    header('Location: admin_manage_document.php?error=Storage temporarily unavailable. Please try again.');
    exit;
}

if (!$fileAvailable) {
    header('Location: admin_manage_document.php?error=File not found on server.');
    exit;
}

// Log the download
logAudit($pdo, 'download_document', 'Downloaded PH document: ' . $document['title'], 'ph_document', $docId);

storageStreamDownload($filePath, basename($filePath));
exit;
?>
