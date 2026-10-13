#requires -Version 7
<#
  CHED Compliance — HTTP smoke test (curl-based).

  Covers the flows marked UNVERIFIED in SECURITY_HARDENING_REPORT.md:
    - PH login (session + CSRF)
    - PH quick-direct upload            -> success
    - PH quick-direct upload (again)    -> duplicate blocked
    - PH repository upload              -> success
    - PH repository upload (again)      -> duplicate blocked
    - Admin login + dashboard load

  Seeds two ephemeral users and one temp PDF, then removes every row/file it
  created. Uses the DML-only .env credentials (no root needed).

  Run:  pwsh -File scripts/smoke_test.ps1
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
$storage  = 'C:\ched_secure_storage\documents'
$ts   = Get-Date -Format 'yyyyMMddHHmmss'
$tag  = "SMOKE_$ts"
$pw   = 'Sm0ke#Test-2026'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_smoke_$ts"
New-Item -ItemType Directory -Path $work -Force | Out-Null

$script:results = [System.Collections.Generic.List[object]]::new()
function Check([string]$Name, [bool]$Ok, [string]$Detail = '') {
    $tagRes = if ($Ok) { 'PASS' } else { 'FAIL' }
    $script:results.Add([pscustomobject]@{ Test = $Name; Result = $tagRes; Detail = $Detail })
    Write-Host ("[{0}] {1}  {2}" -f $tagRes, $Name, $Detail)
}

# --- DB creds from .env (DML-only account) ---
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
    $a = @('-s', '-D', $hdr, '-o', $body, '-w', '%{http_code}', '-b', $Jar, '-c', $Jar)
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

$phEmail    = "smoke_ph_${ts}@example.test"
$adminEmail = "smoke_admin_${ts}@example.test"
$phJar      = Join-Path $work 'ph.jar'
$adminJar   = Join-Path $work 'admin.jar'
$pdf        = Join-Path $work "$tag.pdf"
Set-Content -Path $pdf -NoNewline -Value "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`ntrailer<</Root 1 0 R>>`n%%EOF`n% $ts"
$pdfCurl = $pdf.Replace('\', '/')

$hash = (& $Php -r "echo password_hash('$pw', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

try {
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('Smoke PH','$phEmail','$hash','department',$DeptId),
 ('Smoke Admin','$adminEmail','$hash','admin',NULL);
"@ | Out-Null

    # ---------------- PH login ----------------
    $r = Invoke-Curl "$Base/login.php" -Jar $phJar
    $csrf = Get-Csrf $r.Body
    Check 'PH login page served with CSRF' ($r.Code -eq 200 -and [bool]$csrf) "code=$($r.Code)"

    $r = Invoke-Curl "$Base/login.php" -Jar $phJar -Extra @(
        '-X', 'POST',
        '--data-urlencode', "csrf_token=$csrf",
        '--data-urlencode', "email=$phEmail",
        '--data-urlencode', "password=$pw",
        '--data-urlencode', 'remember=1'
    )
    $loc = Get-Loc $r.Headers
    Check 'PH login redirects to PH dashboard' ($r.Code -eq 302 -and $loc -match 'program_head_dashboard\.php') "code=$($r.Code) loc=$loc"

    # ---------------- PH direct upload ----------------
    $r = Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar
    $csrf = Get-Csrf $r.Body
    Check 'Create/Assign page served with CSRF' ($r.Code -eq 200 -and [bool]$csrf) "code=$($r.Code)"

    $directTitle = "${tag}_direct"
    $directForm = @(
        '-F', "csrf_token=$csrf",
        '-F', 'action=direct_upload',
        '-F', "upload_title=$directTitle",
        '-F', 'upload_description=smoke direct',
        '-F', "upload_file=@$pdfCurl;type=application/pdf"
    )
    $r = Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar -Extra $directForm
    Check 'PH direct upload #1 succeeds' ($r.Body -match 'uploaded and forwarded to Admin') "code=$($r.Code)"
    # Archive #1 so the (title,dept) guard passes and the SHA-256 guard is what fires.
    Invoke-Sql "UPDATE documents SET archived=1 WHERE title='$directTitle' AND source_type='quick_direct';" | Out-Null
    $r = Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar -Extra $directForm
    Check 'PH direct upload #2 duplicate-blocked (SHA-256)' ($r.Body -match 'This exact file has already been uploaded') "code=$($r.Code)"

    # ---------------- PH repository upload ----------------
    $r = Invoke-Curl "$Base/program_head_repository.php" -Jar $phJar
    $repoCsrf = Get-Csrf $r.Body
    Check 'Repository page served with CSRF' ($r.Code -eq 200 -and [bool]$repoCsrf) "code=$($r.Code)"

    $repoTitle = "${tag}_repo"
    $repoForm = @(
        '-F', "csrf_token=$repoCsrf",
        '-F', 'action=repo_upload',
        '-F', "upload_title=$repoTitle",
        '-F', 'upload_description=smoke repo',
        '-F', 'auto_forward=1',
        '-F', "upload_file=@$pdfCurl;type=application/pdf"
    )
    $r = Invoke-Curl "$Base/program_head_repository.php" -Jar $phJar -Extra $repoForm
    $loc = Get-Loc $r.Headers
    Check 'PH repository upload #1 succeeds' ($r.Code -eq 302 -and $loc -match 'uploaded\+and\+forwarded') "code=$($r.Code) loc=$loc"
    $r = Invoke-Curl "$Base/program_head_repository.php" -Jar $phJar -Extra $repoForm
    $loc = Get-Loc $r.Headers
    Check 'PH repository upload #2 duplicate-blocked' ($r.Code -eq 302 -and $loc -match 'already\+exists\+in\+your\+repository') "code=$($r.Code) loc=$loc"

    # ---------------- Admin login ----------------
    $r = Invoke-Curl "$Base/login.php" -Jar $adminJar
    $ac = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/login.php" -Jar $adminJar -Extra @(
        '-X', 'POST',
        '--data-urlencode', "csrf_token=$ac",
        '--data-urlencode', "email=$adminEmail",
        '--data-urlencode', "password=$pw",
        '--data-urlencode', 'remember=1'
    )
    $loc = Get-Loc $r.Headers
    Check 'Admin login redirects to admin dashboard' ($r.Code -eq 302 -and $loc -match 'admin_dashboard\.php') "code=$($r.Code) loc=$loc"
    $r = Invoke-Curl "$Base/admin_dashboard.php" -Jar $adminJar
    Check 'Admin dashboard loads (auth ok)' ($r.Code -eq 200) "code=$($r.Code)"
}
finally {
    # ---- cleanup: DB rows first (paths), then files, then users ----
    try {
        $paths = Invoke-Sql "SELECT file_path FROM documents WHERE title LIKE '$tag%' UNION ALL SELECT file_path FROM ph_repository WHERE title LIKE '$tag%';"
        foreach ($p in $paths) {
            if ([string]::IsNullOrWhiteSpace($p)) { continue }
            $disk = Join-Path $storage ($p.Trim() -replace '/', '\')
            if (Test-Path -LiteralPath $disk) { Remove-Item -LiteralPath $disk -Force -ErrorAction SilentlyContinue }
        }
        Invoke-Sql @"
DELETE FROM in_app_notifications WHERE message LIKE '%$ts%' OR title LIKE '%$ts%';
DELETE FROM audit_trail WHERE description LIKE '%$ts%' OR description LIKE '%example.test%';
DELETE FROM login_attempts WHERE email LIKE 'smoke\_%@example.test' OR email LIKE '%$ts%@example.test';
DELETE FROM ph_repository WHERE title LIKE '$tag%';
DELETE FROM documents WHERE title LIKE '$tag%';
DELETE FROM users WHERE email LIKE '%$ts%@example.test';
"@ | Out-Null
        Write-Host "[i] cleanup complete"
    } catch {
        Write-Warning "cleanup failed: $($_.Exception.Message)"
    }
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
