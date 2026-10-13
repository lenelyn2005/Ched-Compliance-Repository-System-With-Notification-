#requires -Version 7
<#
  CHED Compliance - PH requirement-attachment lifecycle + password min-length policy.

  Covers FINAL-remediation acceptance items:
    - program_head_create_assign.php: create a PH requirement WITH an attachment ->
      stored file_path is "templates/<slug>/<file>" and the file exists on disk
      (guards the P4/C4 attachment-path bug)
    - update the requirement with a NEW attachment -> new path stored + on disk,
      the previous template file is deleted (deleteRequirementFile)
    - delete_requirement removes the row and the file
    - password min-length (>=8) enforced on BOTH account forms:
        * program_head_teachers.php (PH creating a faculty account)
        - admin_manage_users.php   (Admin creating a Program Head account)
      both: short password rejected, no row written.

  Seeds one ephemeral PH + admin, removes every row/file it creates.

  Run:  pwsh -File scripts/requirement_attachment_test.ps1
#>
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$Base     = 'http://localhost/o'
$php      = 'C:\xampp\php\php.exe'
$mysql    = 'C:\xampp\mysql\bin\mysql.exe'
$DeptId   = 1
$storage  = 'C:\ched_secure_storage\documents'
$ts       = Get-Date -Format 'yyyyMMddHHmmss'
$tag      = "REQATT_$ts"
$pw       = 'Req!Test#2026'
$work     = Join-Path $env:TEMP "req_attach_$ts"

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
function Invoke-Sql([string]$sql) { $sql | & $mysql @dbArgs 2>&1 }
function Scalar([string]$sql) { (Invoke-Sql $sql | Select-Object -First 1) }

function Invoke-Curl {
    param([string]$Url, [string[]]$Extra = @(), [string]$Jar)
    $hdr  = Join-Path $work ([guid]::NewGuid().ToString('N') + '.h')
    $body = Join-Path $work ([guid]::NewGuid().ToString() + '.b')
    $a = @('-s', '-D', $hdr, '-o', $body, '-w', '%{http_code}', '-b', $Jar, '-c', $Jar)
    $a += $Extra
    $a += $Url
    $code = & curl.exe @a
    [pscustomobject]@{ Code = [int]("$code".Trim()); Headers = (Get-Content $hdr -Raw); Body = (Get-Content $body -Raw) }
}
function Get-Csrf([string]$Html) {
    if ($Html -match 'name="csrf_token"\s+value="([A-Za-z0-9]+)"') { return $Matches[1] }
    return $null
}
function Get-Loc([string]$Headers) {
    if ($Headers -match '(?im)^Location:\s*(.+?)\s*$') { return $Matches[1] }
    return ''
}
function Login([string]$Email, [string]$Pw, [string]$Jar) {
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar
    $c = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode',"email=$Email",
        '--data-urlencode',"password=$Pw",'--data-urlencode','remember=1'
    )
    return (Get-Loc $r.Headers)
}
function New-Doc([string]$Name, [string]$Seed) {
    $p = Join-Path $work $Name
    Set-Content -Path $p -NoNewline -Value "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`n%%EOF`n% $Seed"
    return ($p -replace '\\','/')
}

$phEmail    = "req_ph_${ts}@example.test"
$adminEmail = "req_admin_${ts}@example.test"
$phJar      = Join-Path $work 'ph.jar'
$adminJar   = Join-Path $work 'admin.jar'
$reqTitle   = "${tag} requirement"

$hash = (& $php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

$pdf1 = New-Doc 'req1.pdf' "req-v1-$ts"
$pdf2 = New-Doc 'req2.pdf' "req-v2-$ts"

$phId = $null; $adminId = $null; $reqId = $null

try {
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('Req PH','$phEmail','$hash','department',$DeptId),
 ('Req Admin','$adminEmail','$hash','admin',NULL);
"@ | Out-Null
    $phId    = Scalar "SELECT id FROM users WHERE email='$phEmail'"
    $adminId = Scalar "SELECT id FROM users WHERE email='$adminEmail'"
    Check 'seed: PH + admin created' (([int]$phId -gt 0) -and ([int]$adminId -gt 0)) "ph=$phId admin=$adminId"
    Check 'login PH ok'    ((Login $phEmail $pw $phJar) -match 'program_head_') ''
    Check 'login admin ok' ((Login $adminEmail $pw $adminJar) -match 'admin_dashboard') ''

    # -------- create requirement with attachment --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar).Body
    Check 'C0. CSRF token from create page' ([bool]$c) ''
    $r = Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar -Extra @(
        '-X','POST','-F',"csrf_token=$c",'-F','action=create_requirement',
        '-F',"req_title=$reqTitle",'-F','req_description=attachment lifecycle test',
        '-F',"req_file=@$pdf1;type=application/pdf"
    )
    $reqId = Scalar "SELECT id FROM ph_requirements WHERE title='$reqTitle' AND created_by=$phId"
    Check 'C1. requirement row created' ([int]$reqId -gt 0) "req=$reqId"
    $fp1 = Scalar "SELECT file_path FROM ph_requirements WHERE id=$reqId"
    $fn1 = Scalar "SELECT filename FROM ph_requirements WHERE id=$reqId"
    Check 'C2. stored path under templates/<slug>/' ($fp1 -match '^templates/.+/.+') "path=$fp1"
    $disk1 = Join-Path $storage ($fp1 -replace '/','\')
    Check 'C3. attachment file exists on disk' (Test-Path -LiteralPath $disk1) "found=$(Test-Path -LiteralPath $disk1)"
    Check 'C4. original filename recorded' ($fn1 -eq 'req1.pdf') "filename=$fn1"

    # -------- update requirement with a NEW attachment --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar).Body
    $r = Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar -Extra @(
        '-X','POST','-F',"csrf_token=$c",'-F','action=update_requirement',
        '-F',"req_id=$reqId",'-F',"req_title=$reqTitle",'-F','req_description=updated',
        '-F',"req_file=@$pdf2;type=application/pdf"
    )
    $fp2 = Scalar "SELECT file_path FROM ph_requirements WHERE id=$reqId"
    $fn2 = Scalar "SELECT filename FROM ph_requirements WHERE id=$reqId"
    $disk2 = Join-Path $storage ($fp2 -replace '/','\')
    $diskOld = Join-Path $storage ($fp1 -replace '/','\')
    Check 'U1. path updated to new attachment' (($fp2 -ne $fp1) -and ($fp2 -match '^templates/.+/.+')) "old=$fp1 new=$fp2"
    Check 'U2. new attachment file exists' (Test-Path -LiteralPath $disk2) "found=$(Test-Path -LiteralPath $disk2)"
    Check 'U3. old attachment file removed' (-not (Test-Path -LiteralPath $diskOld)) "old=$diskOld"
    Check 'U4. filename metadata updated' ($fn2 -eq 'req2.pdf') "filename=$fn2"

    # -------- password min-length: program_head_teachers (PH creates teacher) --------
    $tEmail = "req_teach_${ts}@example.test"
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_teachers.php" -Jar $phJar).Body
    $r = Invoke-Curl "$Base/program_head_teachers.php" -Jar $phJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode','name=Short PW',
        '--data-urlencode',"email=$tEmail",'--data-urlencode','password=short12',
        '--data-urlencode','role=teacher'
    )
    Check 'P1. teacher short password rejected (7 chars)' ($r.Body -match 'Password must be at least 8 characters') "code=$($r.Code)"
    Check 'P2. no teacher row written' ([int](Scalar "SELECT COUNT(*) FROM users WHERE email='$tEmail'") -eq 0) ''

    # boundary: exactly 8 chars accepted
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_teachers.php" -Jar $phJar).Body
    $r = Invoke-Curl "$Base/program_head_teachers.php" -Jar $phJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode','name=Valid PW',
        '--data-urlencode',"email=$tEmail",'--data-urlencode','password=eightchr',
        '--data-urlencode','role=teacher'
    )
    $tId = Scalar "SELECT id FROM users WHERE email='$tEmail'"
    Check 'P3. teacher 8-char password accepted' ([int]$tId -gt 0) "user=$tEmail id=$tId"
    $stored = Scalar "SELECT password FROM users WHERE id=$tId"
    Check 'P4. password stored as bcrypt hash' ($stored -match '^\$2[aby]\$') "prefix=$($stored.Substring(0,[Math]::Min(4,$stored.Length)))"

    # -------- password min-length: admin_manage_users (admin creates PH) --------
    $aEmail = "req_shortph_${ts}@example.test"
    $c = Get-Csrf (Invoke-Curl "$Base/admin_manage_users.php" -Jar $adminJar).Body
    $r = Invoke-Curl "$Base/admin_manage_users.php" -Jar $adminJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode','name=Short PH',
        '--data-urlencode',"email=$aEmail",'--data-urlencode','password=abc',
        '--data-urlencode','role=department'
    )
    Check 'P5. admin short password rejected (3 chars)' ($r.Body -match 'Password must be at least 8 characters') "code=$($r.Code)"
    Check 'P6. no PH row written' ([int](Scalar "SELECT COUNT(*) FROM users WHERE email='$aEmail'") -eq 0) ''

    # -------- delete requirement removes row + file --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar).Body
    $r = Invoke-Curl "$Base/program_head_create_assign.php" -Jar $phJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode','action=delete_requirement',
        '--data-urlencode',"req_id=$reqId"
    )
    Check 'X1. requirement row deleted' ([int](Scalar "SELECT COUNT(*) FROM ph_requirements WHERE id=$reqId") -eq 0) ''
    Check 'X2. attachment file removed on delete' (-not (Test-Path -LiteralPath $disk2)) "path=$fp2"
}
finally {
    try {
        foreach ($p in (Invoke-Sql "SELECT file_path FROM ph_requirements WHERE title='$reqTitle';")) {
            if ([string]::IsNullOrWhiteSpace($p)) { continue }
            $disk = Join-Path $storage ($p.Trim() -replace '/','\')
            if (Test-Path -LiteralPath $disk) { Remove-Item -LiteralPath $disk -Force -ErrorAction SilentlyContinue }
        }
        Invoke-Sql @"
DELETE FROM ph_requirements WHERE title='$reqTitle';
DELETE FROM teacher_ph_requirements WHERE ph_requirement_id=$reqId;
DELETE FROM in_app_notifications WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'req\_%${ts}@example.test');
DELETE FROM audit_trail WHERE description LIKE '%${tag}%' OR description LIKE '%req\_%example.test%';
DELETE FROM login_attempts WHERE email LIKE 'req\_%${ts}@example.test';
DELETE FROM users WHERE email LIKE 'req\_%${ts}@example.test';
"@ | Out-Null
        $left = (Scalar "SELECT COUNT(*) FROM ph_requirements WHERE title='$reqTitle'")
        Write-Host "[i] cleanup complete (residual reqs=$left)"
    } catch { Write-Warning "cleanup failed: $($_.Exception.Message)" }
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
