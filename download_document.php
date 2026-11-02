<?php
if (!defined('CHED_APP')) define('CHED_APP', true);
require_once 'config.php';
require_once __DIR__ . '/includes/measure_baseline.php';
requireAdmin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: admin_manage_document.php?error=Invalid document ID');
    exit;
}

$corr = 'dl_' . uniqid();
$t0 = microtime(true);
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
    measure_stage($corr, 'db_fetch', $t0, microtime(true));
    header('Location: admin_manage_document.php?error=Document not found or not approved');
    exit;
}

$filePath = (string) $document['file_path'];

// Storage outage must not read as "file not found" (plan §9): only a genuine
// absence redirects to the not-found message; typed failures fail loudly.
$t_r2 = microtime(true);
// Skip duplicate R2 HEAD before stream; rely on stream open to confirm presence.
measure_stage($corr, 'r2_exists', $t_r2, microtime(true));

// Log the download
logAudit($pdo, 'download_document', 'Downloaded document: ' . $document['title'], 'document', $docId);

$t_download = microtime(true);
measure_stage($corr, 'stream_start', $t_r2, $t_download);
try {
    storageStreamDownload($filePath, basename($filePath));
} catch (StorageObjectNotFound $e) {
    measure_stage($corr, 'stream_end', $t_download, microtime(true));
    error_log('[CHED] download_document: object genuinely missing: ' . $e->getMessage());
    header('Location: admin_manage_document.php?error=' . urlencode('File not found on server.'));
    exit;
} catch (StorageException $e) {
    measure_stage($corr, 'stream_end', $t_download, microtime(true));
    error_log('[CHED] download_document blocked, storage unavailable: ' . get_class($e));
    header('Location: admin_manage_document.php?error=Storage temporarily unavailable. Please try again.');
    exit;
}
measure_stage($corr, 'stream_end', $t_download, microtime(true));
exit;
?>
