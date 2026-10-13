#requires -Version 7
<#
  CHED Compliance — HTTP direct-access guard test (real Apache).

  Confirms sensitive resources cannot be pulled directly and that legitimate
  pages still render. Run: pwsh -File scripts/direct_access_test.ps1
#>
$ErrorActionPreference = 'Stop'
$Base = 'http://localhost/o'
$script:results = [System.Collections.Generic.List[object]]::new()
function Check([string]$Name, [bool]$Ok, [string]$Detail = '') {
    $tagRes = if ($Ok) { 'PASS' } else { 'FAIL' }
    $script:results.Add([pscustomobject]@{ Test = $Name; Result = $Ok })
    Write-Host ("[{0}] {1}  {2}" -f $tagRes, $Name, $Detail)
}

function Code([string]$path) {
    return [int](& curl.exe -s -o NUL -w "%{http_code}" "http://localhost/o/$path").Trim()
}

$mustBlock = @(
    'config.php',
    'includes/env_loader.php','includes/performance.php','includes/header.php',
    'includes/footer.php','includes/sidebar.php','includes/avatar_helpers.php','includes/success_toast.php',
    '.env', '.env.example', '.env.bak', '.git/config', '.git/HEAD',
    'migrations/2026_security_hardening.sql', 'final.database.sql',
    'scripts/backfill_file_hashes.php', 'scripts/move_failure_test.php',
    'scripts/hash_guard_test.php', 'scripts/cred_verify.php',
    'scripts/smoke_test.ps1', 'scripts/dept_submission_test.ps1',
    'logs/email_errors.log', 'composer.json',
    'SECURITY_HARDENING_REPORT.md', 'CODEBASE_AUDIT_NOTE.md', 'CHED_Complete_Codebase_Defense_Notes.md'
)

foreach ($u in $mustBlock) {
    $code = & curl.exe -s -o NUL -w "%{http_code}" "http://localhost/o/$u"
    Check "blocked: $u" ($code -eq '403') "http=$code"
}

# Normal application pages must keep working (legitimate includes still load).
# Normal application pages must keep working (legitimate includes still load).
$loginCode = (& curl.exe -s -o NUL -w "%{http_code}" "http://localhost/o/login.php") -join ''
Check 'login.php serves (200)' ($loginCode.Trim() -eq '200') "http=$loginCode"
$loginBody = & curl.exe -s "http://localhost/o/login.php" | Out-String
Check 'login.php renders CSRF form (includes load)' ([bool]([regex]::IsMatch($loginBody, 'name="csrf_token"'))) ''

$fail = @($script:results | Where-Object { $_.Result -eq $false }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
