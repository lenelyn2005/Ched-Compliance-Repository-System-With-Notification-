<?php
// CLI-only self-check for validateAndStoreUpload() (config.php) — the shared
// 7-layer upload pipeline. Run: php upload_pipeline_selftest.php
// Layer 7's move_uploaded_file fails for CLI-made files by design, so the
// "clean file" cases assert that the pipeline REACHED layer 7.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ERROR); // finfo warns on missing tmp files; the assertion cares about the return, not the warning
define('CHED_APP', true);
require_once __DIR__ . '/config.php';

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$pdo = new PDO('sqlite::memory:'); // type-hint only: no lockKey/dupCheck given, so no SQL runs

function makeFile(string $tmpPath, string $name, int $err = UPLOAD_ERR_OK, ?int $size = null): array {
    return ['name' => $name, 'tmp_name' => $tmpPath, 'error' => $err,
            'size' => $size ?? (is_file($tmpPath) ? filesize($tmpPath) : 0)];
}
function check(string $label, mixed $got, string $expectContains): void {
    $ok = is_string($got) && str_contains($got, $expectContains);
    echo ($ok ? 'PASS' : 'FAIL') . "  $label\n";
    if (!$ok) { echo '      got: ' . var_export($got, true) . "\n"; exit(1); }
}

// Layer 1: PHP upload error
check('L1 rejects UPLOAD_ERR_INI_SIZE',
    validateAndStoreUpload($pdo, makeFile($tmp . 'x.pdf', 'x.pdf', UPLOAD_ERR_INI_SIZE), 'T', 'tmp_selftest'),
    'too large');

// Layer 2: extension allow-list
check('L2 rejects .php',
    validateAndStoreUpload($pdo, makeFile($tmp . 'x.php', 'x.php'), 'T', 'tmp_selftest'),
    'Invalid file type');

// Layer 3: size cap (size key is caller-supplied, as in $_FILES)
check('L3 rejects oversize image',
    validateAndStoreUpload($pdo, makeFile($tmp . 'x.jpg', 'x.jpg', UPLOAD_ERR_OK, IMAGE_MAX_BYTES + 1), 'T', 'tmp_selftest'),
    'must not exceed');

// Layer 4: unreadable tmp = fail closed
check('L4 fails closed on unreadable file',
    validateAndStoreUpload($pdo, makeFile($tmp . 'missing.pdf', 'missing.pdf'), 'T', 'tmp_selftest'),
    'verify its type');

// Layer 5: MZ (Windows executable) bytes behind a .pdf name
$mz = $tmp . 'mz.pdf'; file_put_contents($mz, "MZ\x90\x00" . str_repeat("\x00", 64));
check('L5 rejects executable content named .pdf',
    validateAndStoreUpload($pdo, makeFile($mz, 'report.pdf'), 'T', 'tmp_selftest'),
    'does not match its extension');

// Layers 1-5 pass -> layer 7: move_uploaded_file fails for CLI files by design
$pdf = $tmp . 'ok.pdf'; file_put_contents($pdf, "%PDF-1.4\n%fake\n");
check('clean PDF reaches layer 7 (move fails only in CLI)',
    validateAndStoreUpload($pdo, makeFile($pdf, 'report.pdf'), 'T', 'tmp_selftest'),
    'Failed to save file');

// docx corner: real docx sniffs as application/zip and must still pass the gates
$docx = $tmp . 'ok.docx'; file_put_contents($docx, "PK\x03\x04" . str_repeat("\x00", 32));
check('docx (sniffed as zip) reaches layer 7',
    validateAndStoreUpload($pdo, makeFile($docx, 'report.docx'), 'T', 'tmp_selftest'),
    'Failed to save file');

@rmdir(UPLOAD_PATH . 'tmp_selftest'); // empty scratch dir the layer-7 attempt created
echo "All checks passed.\n";
