#requires -Version 7
<#
  CHED Compliance - CSP nonce + inline-handler regression test (curl-based).

  Logs in as admin, PH and teacher, then for each page asserts:
    1. HTTP 200
    2. A Content-Security-Policy(-Report-Only) header is present and carries a nonce
    3. Every inline <script> carries the SAME nonce as the header
    4. No inline event-handler attribute (on*=) remains in the HTML
    5. No javascript: URL remains in the HTML
  Plus: two requests to the same page yield DIFFERENT nonces (per-response).

  Seeds ephemeral users and removes every row it created.

  Run:  pwsh -File scripts/csp_nonce_test.ps1 [-Base http://localhost/o]
  Exit: 0 = all passed, 1 = one or more failures.
#>
[CmdletBinding()]
param(
    [string]$Base  = 'http://localhost/o',
    [string]$Php   = 'C:\xampp\php\php.exe',
    [string]$Mysql = 'C:\xampp\mysql\bin\mysql.exe',
    [int]   $DeptId = 1
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$ts   = Get-Date -Format 'yyyyMMddHHmmss'
$tag  = "CSP_$ts"
$pw   = 'Csp#Test-2026'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_csp_$ts"
New-Item -ItemType Directory -Path $work -Force | Out-Null

$script:results = [System.Collections.Generic.List[object]]::new()
function Check([string]$Name, [bool]$Ok, [string]$Detail = '') {
    $tagRes = if ($Ok) { 'PASS' } else { 'FAIL' }
    $script:results.Add([pscustomobject]@{ Test = $Name; Result = $tagRes; Detail = $Detail })
    Write-Host ("[{0}] {1}  {2}" -f $tagRes, $Name, $Detail)
}

$dbUser = 'ched_app'; $dbPass = ''
foreach ($line in Get-Content (Join-Path $repoRoot '.env')) {
    if ($line -match '^\s*DB_USER\s*=\s*(.+)$') { $dbUser = $Matches[1].Trim() }
    if ($line -match '^\s*DB_PASS\s*=\s*(.+)$') { $dbPass = $Matches[1].Trim() }
}
$dbArgs = @('-u', $dbUser, "-p$dbPass", '-h', '127.0.0.1', '-N', '-B', 'ched_compliance')
function Invoke-Sql([string]$sql) { $sql | & $Mysql @dbArgs 2>&1 }

function Invoke-Curl {
    param([string]$Url, [string[]]$Extra = @(), [string]$Jar)
    $hdr  = Join-Path $work ([guid]::NewGuid().ToString('N') + '.h')
    $body = Join-Path $work ([guid]::NewGuid().ToString() + '.b')
    $a = @('-s', '-D', $hdr, '-o', $body, '-w', '%{http_code}')
    if ($Jar) { $a += @('-b', $Jar, '-c', $Jar) }
    $a += $Extra
    $a += $Url
    $code = & curl.exe @a
    [pscustomobject]@{
        Code    = [int]("$code".Trim())
        Headers = (Get-Content $hdr -Raw)
        Body    = (Get-Content $body -Raw)
    }
}
function Get-Csrf([string]$Html) {
    if ($Html -match 'name="csrf_token"\s+value="([A-Za-z0-9]+)"') { return $Matches[1] }
    return $null
}
function Get-Loc([string]$Headers) {
    if ($Headers -match '(?im)^Location:\s*(.+?)\s*$') { return $Matches[1] }
    return ''
}
function Get-CspNonce([string]$Headers) {
    if ($Headers -match "(?im)^Content-Security-Policy(?:-Report-Only)?:\s*(.+?)\s*$") {
        $policy = $Matches[1]
        if ($policy -match "'nonce-([^']+)'") { return $Matches[1] }
    }
    return $null
}
function Get-CspHeaderName([string]$Headers) {
    if ($Headers -match '(?im)^(Content-Security-Policy-Report-Only):') { return 'Report-Only' }
    if ($Headers -match '(?im)^(Content-Security-Policy):') { return 'Enforced' }
    return 'None'
}

# Verify one page. Returns nothing; records checks.
function Test-Page {
    param([string]$Jar, [string]$Path, [string]$Who)
    $r = Invoke-Curl "$Base/$Path" -Jar $Jar
    $nonce = Get-CspNonce $r.Headers
    $mode  = Get-CspHeaderName $r.Headers
    Check "$Who $Path served (200)" ($r.Code -eq 200) "code=$($r.Code)"
    if ($r.Code -ne 200) { return }
    Check "$Who $Path CSP header + nonce" ([bool]$nonce) "mode=$mode"

    if ($nonce) {
        # Every inline <script> (no src=) must carry the header nonce.
        $tags = [regex]::Matches($r.Body, '(?is)<script\b[^>]*>')
        $inline = 0; $bad = 0; $snip = @()
        foreach ($m in $tags) {
            if ($m.Value -match '\bsrc\s*=') { continue }
            $inline++
            if ($m.Value -notmatch ('nonce="' + [regex]::Escape($nonce) + '"')) {
                $bad++
                $s = [Math]::Max(0, $m.Index - 30); $len = [Math]::Min(90, $r.Body.Length - $s)
                $snip += ('...' + ($r.Body.Substring($s, $len) -replace '\s+', ' '))
            }
        }
        Check "$Who $Path inline scripts nonced ($inline)" ($bad -eq 0) ("bad=$bad " + ($snip -join ' | '))
    }

    $handlers = [regex]::Matches($r.Body, '(?i)\son[a-z]+\s*=')
    Check "$Who $Path no inline handlers" ($handlers.Count -eq 0) "count=$($handlers.Count)"

    $jsUrls = [regex]::Matches($r.Body, '(?i)javascript\s*:')
    Check "$Who $Path no javascript: URLs" ($jsUrls.Count -eq 0) "count=$($jsUrls.Count)"
}

$adminEmail = "csp_admin_${ts}@example.test"
$phEmail    = "csp_ph_${ts}@example.test"
$teachEmail = "csp_teach_${ts}@example.test"
$adminJar   = Join-Path $work 'admin.jar'
$phJar      = Join-Path $work 'ph.jar'
$teachJar   = Join-Path $work 'teach.jar'

$hash = (& $Php -r "echo password_hash('$pw', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

function Login([string]$email, [string]$Jar) {
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar
    $csrf = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar -Extra @(
        '-X', 'POST',
        '--data-urlencode', "csrf_token=$csrf",
        '--data-urlencode', "email=$email",
        '--data-urlencode', "password=$pw"
    )
    return $r
}

try {
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('CSP Admin','$adminEmail','$hash','admin',NULL),
 ('CSP PH','$phEmail','$hash','department',$DeptId),
 ('CSP Teacher','$teachEmail','$hash','teacher',$DeptId);
"@ | Out-Null

    # --- login.php itself (unauthenticated) ---
    $lr = Invoke-Curl "$Base/login.php"
    $ln = Get-CspNonce $lr.Headers
    Check 'login.php CSP header + nonce' ([bool]$ln) "mode=$(Get-CspHeaderName $lr.Headers)"
    if ($ln) {
        $tags = [regex]::Matches($lr.Body, '(?is)<script\b[^>]*>')
        $bad = 0
        foreach ($m in $tags) { if ($m.Value -match '\bsrc\s*=') { continue }; if ($m.Value -notmatch ('nonce="' + [regex]::Escape($ln) + '"')) { $bad++ } }
        Check 'login.php inline scripts nonced' ($bad -eq 0) "bad=$bad"
    }
    Check 'login.php no inline handlers' (([regex]::Matches($lr.Body, '(?i)\son[a-z]+\s*=')).Count -eq 0)

    # --- per-role page sweep ---
    $adminPages = @('admin_dashboard.php','admin_analytics.php','admin_department_requirements.php',
        'admin_manage_departments.php','admin_manage_document.php','admin_manage_users.php',
        'admin_submissions.php','admin_archive_documents.php','admin_audit_trail.php',
        'admin_report.php','admin_settings.php','notifications.php')
    $phPages = @('program_head_dashboard.php','program_head_requirements.php','program_head_repository.php',
        'program_head_create_assign.php','program_head_assign.php','program_head_history.php',
        'program_head_teachers.php','program_head_pending.php','notifications.php')
    $teachPages = @('teacher_dashboard.php','teacher_tasks.php','teacher_submissions.php','notifications.php')

    Check 'Admin login' ((Login $adminEmail $adminJar).Code -eq 302)
    Check 'PH login'    ((Login $phEmail $phJar).Code -eq 302)
    Check 'Teacher login' ((Login $teachEmail $teachJar).Code -eq 302)

    foreach ($p in $adminPages) { Test-Page -Jar $adminJar -Path $p -Who 'admin' }
    foreach ($p in $phPages)    { Test-Page -Jar $phJar    -Path $p -Who 'ph' }
    foreach ($p in $teachPages) { Test-Page -Jar $teachJar -Path $p -Who 'teacher' }

    # --- nonce uniqueness / per-response freshness ---
    $u1 = Get-CspNonce (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $phJar).Headers
    $u2 = Get-CspNonce (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $phJar).Headers
    Check 'nonce differs between responses' ([bool]$u1 -and [bool]$u2 -and $u1 -ne $u2) "u1=$u1 u2=$u2"

    # --- external asset + static file reachable ---
    $ev = Invoke-Curl "$Base/includes/csp-events.js" -Jar $phJar
    Check 'csp-events.js served' ($ev.Code -eq 200 -and $ev.Body -match 'data-csp-action') "code=$($ev.Code)"
}
finally {
    Invoke-Sql "DELETE FROM users WHERE email IN ('$adminEmail','$phEmail','$teachEmail');" | Out-Null
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' })
Write-Host ""
Write-Host ("{0} checks, {1} failed" -f $script:results.Count, $fail.Count)
if ($fail.Count -gt 0) { $fail | Format-Table -AutoSize | Out-String | Write-Host; exit 1 }
exit 0
