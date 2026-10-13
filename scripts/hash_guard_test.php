<?php
/**
 * CHED Compliance — "reject when hash cannot be computed" guard (CLI).
 *
 * Section 2 of the hardening: a new submission must be rejected if its bytes
 * cannot be hashed, instead of silently bypassing duplicate detection.
 *
 * hashUploadedTempFile() only returns null when the temp file is not readable;
 * in the normal HTTP flow the MIME probe (finfo) already reads the same temp
 * file first, so this guard is defence-in-depth. This test proves the helper's
 * null contract and that every upload route carries the guard (static check).
 *
 * Run: php scripts/hash_guard_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
define('CHED_APP', true);
require __DIR__ . '/../config.php';

$fail = 0;
function chk($name, $ok, $detail = '') {
    global $fail;
    if (!$ok) { $fail++; }
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $name  $detail\n";
}

// 1. helper null contract
chk('hashUploadedTempFile: missing file -> null',
    hashUploadedTempFile(['name' => 'x.pdf', 'error' => UPLOAD_ERR_OK, 'tmp_name' => __DIR__ . '/__nope__.pdf']) === null);
chk('hashUploadedTempFile: empty tmp_name -> null',
    hashUploadedTempFile(['name' => 'x.pdf', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '']) === null);

$tmp = tempnam(sys_get_temp_dir(), 'chg');
file_put_contents($tmp, 'KNOWN-BYTES');
$expected = hash_file('sha256', $tmp);
chk('hashUploadedTempFile() matches hash_file', hashUploadedTempFile(['tmp_name' => $tmp, 'name' => 'x.pdf']) === $expected);
@unlink($tmp);

// Static: each new-submission route must reject a null hash.
$routes = [
    'config.php'                 => 4, // 4 upload* functions
    'program_head_repository.php'=> 1,
    'program_head_dashboard.php' => 1,
];
$needle = 'Could not read the uploaded file to verify its contents';
foreach ($routes as $file => $min) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    $n = substr_count((string)$src, $needle);
    chk("guard present in $file (>= $min)", $n >= $min, "occurrences=$n");
}

echo "\n=== " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($fail failures) ===\n";
exit($fail === 0 ? 0 : 1);
