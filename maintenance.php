<?php
define('CHED_APP', true);
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

echo "Running maintenance...\n";

// 1. Expiration monitoring (single canonical logic, all document types)
$sync = syncExpirationStatuses($pdo);
echo "Expiration sync completed: "
    . "{$sync['requirements_expired']} requirements expired, "
    . "{$sync['requirements_expiring']} expiring, "
    . "{$sync['requirements_active']} re-activated; "
    . "{$sync['documents']} documents + {$sync['ph_documents']} ph_documents refreshed.\n";

// 2. File archiving for long-expired approved documents (separate concern:
//    moves files to uploads/archived; does NOT change expiration status)
$archived = autoArchiveDocuments($pdo);
echo "Archived $archived expired documents.\n";

// Cleanup missing files
// Not part of the scheduled run: it permanently DELETEs rows whose file is
// gone, which is a one-off repair, not a periodic job. Run it on demand:
//   php C:\xampp\htdocs\o\maintenance.php --cleanup
if (in_array('--cleanup', $argv ?? [], true)) {
    echo "\nCleaning up documents with missing files...\n";
    $cleanupResults = cleanupMissingFiles($pdo);
    echo "Deleted {$cleanupResults['documents_deleted']} documents with missing files.\n";
    echo "Deleted {$cleanupResults['ph_documents_deleted']} ph_documents with missing files.\n";
    if (!empty($cleanupResults['errors'])) {
        echo "Errors encountered:\n";
        foreach ($cleanupResults['errors'] as $error) {
            echo "  - $error\n";
        }
    }
}
echo "Done.\n";
