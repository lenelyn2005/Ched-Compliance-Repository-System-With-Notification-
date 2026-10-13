#requires -Version 7
<#
  CHED Compliance - preview / download authorization + archive<->restore round trip.

  Covers the FINAL-remediation acceptance items that the other scripts do not:
    - preview.php: admin can preview; unassigned teacher is denied (403)
    - download_document.php: admin downloads an approved+live doc (200, attachment)
    - autoArchiveDocuments(): expired approved doc is moved to admin_archive,
      archived=1, file physically relocated, old path gone
    - download_document.php on an archived doc is blocked (302 redirect)
    - admin_archive_documents.php unarchive (HTTP POST + CSRF): archived=0,
      file physically moved back to admin_repository
    - preview + download work again after restore

  Seeds one ephemeral admin + teacher + approved_admin document, then removes
  every row/file it creates (verified 0 residue).

  Run:  pwsh -File scripts/archive_restore_test.ps1
#>
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$Base     = 'http://localhost/o'
$php      = 'C:\xampp\php\php.exe'
$mysql    = 'C:\xampp\mysql\bin\mysql.exe'
$DeptId   = 1
$storage  = 'C:\ched_secure_storage\documents'
$ts       = Get-Date -Format 'yyyyMMddHHmmss'
$tag      = "ARCH_$ts"
$pw       = 'Arch!Test#2026'
$work     = Join-Path $env:TEMP "arch_restore_$ts"

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
    param([string]$Url, [string[]]$Extra = @(), [string]$Jar, [switch]$Raw)
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
function Login([string]$Email, [string]$Pw, [string]$Jar) {
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar
    $c = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode',"email=$Email",
        '--data-urlencode',"password=$Pw",'--data-urlencode','remember=1'
    )
    return (Get-Loc $r.Headers)
}

$adminEmail = "arch_admin_${ts}@example.test"
$teachEmail = "arch_teach_${ts}@example.test"
$adminJar   = Join-Path $work 'admin.jar'
$teachJar   = Join-Path $work 'teach.jar'

$hash = (& $php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

$fname = "${tag}.pdf"
$seed = Join-Path $work $fname
Set-Content -Path $seed -NoNewline -Value "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`n%%EOF`n% $tag"
$fileHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $seed).Hash.ToLower()

$adminId = $null; $teachId = $null; $docId = $null
$preArchivePath = "admin_repository/bsis/$fname"
$diskPre = Join-Path $storage ($preArchivePath -replace '/','\')

try {
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('Arch Admin','$adminEmail','$hash','admin',NULL),
 ('Arch Teacher','$teachEmail','$hash','teacher',$DeptId);
"@ | Out-Null
    $adminId = Scalar "SELECT id FROM users WHERE email='$adminEmail'"
    $teachId = Scalar "SELECT id FROM users WHERE email='$teachEmail'"

    New-Item -ItemType Directory -Path (Split-Path -Parent $diskPre) -Force | Out-Null
    Copy-Item -LiteralPath $seed -Destination $diskPre -Force

    Invoke-Sql @"
INSERT INTO documents
  (department_id, category, document_type, title, filename, file_path, file_hash,
   uploaded_by, status, version, upload_date, expiry_date, archived)
VALUES
  ($DeptId, '${tag}', 'Report', '${tag} archive test', '$fname', '$preArchivePath',
   '$fileHash', $adminId, 'approved_admin', 1, NOW(), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 0);
"@ | Out-Null
    $docId = Scalar "SELECT id FROM documents WHERE category='${tag}'"

    Check 'seed: admin login ok'   ((Login $adminEmail $pw $adminJar) -match 'admin_dashboard') ''
    Check 'seed: teacher login ok' ((Login $teachEmail $pw $teachJar) -match 'teacher_dashboard') ''
    Check 'seed: doc + file present' ((Test-Path -LiteralPath $diskPre) -and ([int]$docId -gt 0)) "doc=$docId"

    # -------- preview authorization --------
    $r = Invoke-Curl "$Base/preview.php?id=$docId&source=document" -Jar $adminJar
    Check 'P1. admin can preview live doc' (($r.Code -eq 200) -and ($r.Body -notmatch 'Access denied')) "code=$($r.Code)"
    $r = Invoke-Curl "$Base/preview.php?id=$docId&source=document" -Jar $teachJar
    Check 'P2. unassigned teacher denied (403)' ($r.Code -eq 403) "code=$($r.Code)"

    # -------- download authorization (live) --------
    $r = Invoke-Curl "$Base/download_document.php?id=$docId" -Jar $adminJar
    Check 'D1. admin downloads live doc' (($r.Code -eq 200) -and ($r.Headers -match 'Content-Disposition:\s*attachment')) "code=$($r.Code)"
    $r = Invoke-Curl "$Base/download_document.php?id=$docId" -Jar $teachJar
    Check 'D2. non-admin download denied' ($r.Code -in 302,401,403) "code=$($r.Code)"

    # -------- auto-archive on expired approved doc --------
    $r = Invoke-Curl "$Base/admin_archive_documents.php" -Jar $adminJar
    $archived = Scalar "SELECT archived FROM documents WHERE id=$docId"
    $fpArch = Scalar "SELECT file_path FROM documents WHERE id=$docId"
    $diskArch = Join-Path $storage ($fpArch -replace '/','\')
    Check 'A1. auto-archive ran -> archived=1' ([int]$archived -eq 1) "archived=$archived"
    Check 'A2. archived file exists at new path' (Test-Path -LiteralPath $diskArch) "path=$fpArch"
    Check 'A3. file removed from live path' (-not (Test-Path -LiteralPath $diskPre)) "old=$preArchivePath"
    Check 'A3b. archived path under admin_archive' ($fpArch -match '^admin_archive/') "path=$fpArch"

    # -------- archived-doc access rules --------
    $r = Invoke-Curl "$Base/download_document.php?id=$docId" -Jar $adminJar
    Check 'A3c. archived doc download blocked (302)' ($r.Code -eq 302) "code=$($r.Code) loc=$(Get-Loc $r.Headers)"
    $r = Invoke-Curl "$Base/preview.php?id=$docId&source=document" -Jar $adminJar
    Check 'P3. admin preview of archived allowed' ($r.Code -eq 200) "code=$($r.Code)"

    # -------- unarchive via HTTP POST + CSRF --------
    $page = Invoke-Curl "$Base/admin_archive_documents.php" -Jar $adminJar
    $c = Get-Csrf $r.Body
    if (-not $c) { $c = Get-Csrf $page.Body }
    $r = Invoke-Curl "$Base/admin_archive_documents.php" -Jar $adminJar -Extra @(
        '-X','POST','-H','X-Requested-With: XMLHttpRequest',
        '--data-urlencode',"csrf_token=$c",'--data-urlencode',"unarchive=$docId",
        '--data-urlencode','unarchive_source=document'
    )
    $archived2 = Scalar "SELECT archived FROM documents WHERE id=$docId"
    $fpRest = Scalar "SELECT file_path FROM documents WHERE id=$docId"
    $diskRest = Join-Path $storage ($fpRest -replace '/','\')
    Check 'R1. unarchive -> archived=0' ([int]$archived2 -eq 0) "archived=$archived2 loc=$(Get-Loc $r.Headers)"
    Check 'R2. restored file exists' (Test-Path -LiteralPath $diskRest) "path=$fpRest"
    Check 'R3. restored under admin_repository' ($fpRest -match '^admin_repository/') "path=$fpRest"
    Check 'R4. archive copy gone' (-not (Test-Path -LiteralPath $diskArch)) "old=$fpArch"

    # -------- works again after restore --------
    $r = Invoke-Curl "$Base/preview.php?id=$docId&source=document" -Jar $adminJar
    Check 'R4b. admin preview after restore' (($r.Code -eq 200) -and ($r.Body -notmatch 'Access denied')) "code=$($r.Code)"
    $r = Invoke-Curl "$Base/download_document.php?id=$docId" -Jar $adminJar
    Check 'R4c. admin download after restore' (($r.Code -eq 200) -and ($r.Headers -match 'Content-Disposition:\s*attachment')) "code=$($r.Code)"
}
finally {
    try {
        foreach ($p in (Invoke-Sql "SELECT file_path FROM documents WHERE category='${tag}';")) {
            if ([string]::IsNullOrWhiteSpace($p)) { continue }
            $disk = Join-Path $storage ($p.Trim() -replace '/','\')
            if (Test-Path -LiteralPath $disk) { Remove-Item -LiteralPath $disk -Force -ErrorAction SilentlyContinue }
        }
        Invoke-Sql @"
DELETE FROM documents WHERE category='${tag}';
DELETE FROM in_app_notifications WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'arch\_%${ts}@example.test');
DELETE FROM audit_trail WHERE description LIKE '%${tag}%';
DELETE FROM login_attempts WHERE email LIKE 'arch\_%${ts}@example.test';
DELETE FROM users WHERE email LIKE 'arch\_%${ts}@example.test';
"@ | Out-Null
        $left = (Scalar "SELECT COUNT(*) FROM documents WHERE category='${tag}'")
        Write-Host "[i] cleanup complete (residual docs=$left)"
    } catch { Write-Warning "cleanup failed: $($_.Exception.Message)" }
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
