<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config.php';

$localPath = 'C:/ched_secure_storage/documents/ph_repository/kent_delgado/Record of all student of bsis/lenelyn.pptx';
$objectKey = 'ph_repository/kent_delgado/Record of all student of bsis/lenelyn.pptx';
$mime      = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

// 1. Back up the row first, so deleting it later is always reversible.
$stmt = $pdo->prepare("SELECT * FROM ph_repository WHERE file_path = ?");
$stmt->execute([$objectKey]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "No ph_repository row found for key: $objectKey\n");
    exit(1);
}
echo "Row backup:\n" . json_encode($row, JSON_PRETTY_PRINT) . "\n\n";

// 2. Verify the local file exists.
if (!is_file($localPath)) {
    fwrite(STDERR, "Local file missing: $localPath\n");
    exit(1);
}
$sha  = hash_file('sha256', $localPath);
$size = filesize($localPath);
echo "Local: $size bytes, sha256=$sha\n";

// 3. Upload to R2.
$fh = fopen($localPath, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Cannot open: $localPath\n");
    exit(1);
}
try {
    R2Storage::default()->put($objectKey, $fh, $mime, ['sha256' => $sha]);
} catch (\Throwable $e) {
    fwrite(STDERR, "Upload failed: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($fh)) fclose($fh);
}
echo "Uploaded: $objectKey\n";

// 4. Verify it landed.
$info = R2Storage::default()->head($objectKey);
if ($info === null) {
    fwrite(STDERR, "Verification FAILED: head() returned null\n");
    exit(1);
}
if ((int) $info['size'] !== $size) {
    fwrite(STDERR, "Verification FAILED: size mismatch expected=$size got={$info['size']}\n");
    exit(1);
}
echo "Verified: size matches ($size bytes)\n";
echo "\nDone — ph_repository#{$row['id']} is now satisfied.\n";