<?php
/**
 * CHED Compliance — system settings come from .env, not hardcoded literals (CLI).
 *
 * Proves both halves of config.php's settings block: defaults when the env
 * var is absent, and .env values when present.
 *
 * Run: php scripts/settings_env_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

$fail = 0;
function chk($name, $ok, $detail = '') {
    global $fail;
    if (!$ok) { $fail++; }
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $name  $detail\n";
}

$cfg = realpath(__DIR__ . '/../config.php');

// Child echoes the four settings separated by chr(124) so no shell quoting is needed.
function runChild(string $cfg, array $env): string
{
    $child = tempnam(sys_get_temp_dir(), 'set') . '.php';
    $envPhp = '';
    foreach ($env as $k => $v) {
        $envPhp .= 'putenv(' . var_export("$k=$v", true) . '); ';
    }
    $emit = 'echo MAX_FILE_SIZE, chr(124), EXPIRY_WARNING_DAYS, chr(124),'
          . ' implode(",", ALLOWED_EXTENSIONS), chr(124), ALLOWED_EXTENSIONS_LABEL;';
    file_put_contents($child, "<?php $envPhp require " . var_export($cfg, true) . "; $emit");
    $out = trim((string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($child)));
    @unlink($child);
    return $out;
}

// 1. no env vars -> hardcoded defaults
$d = runChild($cfg, []);
chk('defaults: 100MB / 2 days / 12 extensions',
    $d === '104857600|2|pdf,doc,docx,jpg,jpeg,png,gif,webp,xls,xlsx,ppt,pptx|PDF, DOC/DOCX, JPG/PNG/GIF/WEBP, XLS/XLSX, PPT/PPTX',
    "got: $d");

// 2. env vars set -> they win (assumes your local .env doesn't redefine these keys)
$o = runChild($cfg, [
    'MAX_FILE_SIZE' => '5242880',
    'EXPIRY_WARNING_DAYS' => '9',
    'ALLOWED_EXTENSIONS' => 'pdf, png',
    'ALLOWED_EXTENSIONS_LABEL' => 'PDF, PNG',
]);
chk('overrides: 5MB / 9 days / trimmed extension list',
    $o === '5242880|9|pdf,png|PDF, PNG',
    "got: $o");

echo "\n=== " . ($fail === 0 ? 'PASS' : 'FAIL') . " ($fail failures) ===\n";
exit($fail === 0 ? 0 : 1);
