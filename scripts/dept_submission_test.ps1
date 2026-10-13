#requires -Version 7
<#
  CHED Compliance — PH -> department requirement submission test (HTTP, curl-based).

  Exercises uploadDepartmentSubmission() end-to-end through the real endpoint
  program_head_requirements.php (action=upload_personal), which is the ONLY caller:

    - validation: bad extension rejected before any DB/disk write
    - upload accepted -> documents row pending_admin, teacher_requirement_id NULL
      file_hash populated, physical file present under storage
    - notification: every admin receives an in_app "New Department Document" row
    - repeated request while pending -> blocked by page state (no 2nd row)
    - admin reject -> rejected_admin
    - re-upload of byte-identical file after reject -> SHA-256 duplicate blocked
    - re-upload of new bytes -> new version accepted (version bump)
    - admin approve -> approved_admin, file moved, old path gone

  Seeds an ephemeral PH + admin + department folder + department requirement,
  then removes every row/file it creates (verified 0 residue).

  Run:  pwsh -File scripts/dept_submission_test.ps1
#>
#requires -Version 7
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$Base     = 'http://localhost/o'
$php      = 'C:\xampp\php\php.exe'
$mysql    = 'C:\xampp\mysql\bin\mysql.exe'
$DeptId   = 1
$storage  = 'C:\ched_secure_storage\documents'
$ts       = Get-Date -Format 'yyyyMMddHHmmss'
$tag      = "DEPT_$ts"
$pw       = 'Dept!Test#2026'
$work     = Join-Path $env:TEMP "dept_sub_$ts"

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
function New-Pdf([string]$Name, [string]$Seed) {
    $p = Join-Path $work $Name
    Set-Content -Path $p -NoNewline -Value "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`n%%EOF`n% $Seed"
    return ($p -replace '\\','/')
}

$phEmail    = "dept_ph_${ts}@example.test"
$adminEmail = "dept_admin_${ts}@example.test"
$phJar      = Join-Path $work 'ph.jar'
$adminJar   = Join-Path $work 'admin.jar'

$hash = (& $php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

$pdf1 = New-Pdf 'v1.pdf' "dept-v1-$ts"
$pdf2 = New-Pdf 'v2.pdf' "dept-v2-$ts"
$bad  = Join-Path $work 'bad.txt'
Set-Content -Path $bad -NoNewline -Value "not a document $ts"
$bad = ($bad -replace '\\','/')

# One transaction-free seed block; ids pulled back by tag.
$folderId = $null; $reqId = $null; $phId = $null; $adminId = $null; $docA = $null; $docB = $null

function Upload-Personal([string]$Jar, [string]$File, [string]$Type, [string]$Csrf) {
    Invoke-Curl "$Base/program_head_requirements.php" -Jar $Jar -Extra @(
        '-X','POST',
        '-F',"csrf_token=$Csrf",'-F','action=upload_personal',"-F","requirement_id=$reqId",
        '-F','comment=dept test upload',
        '-F',"document_file=@$File;type=$Type"
    )
}

try {
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('Dept PH','$phEmail','$hash','department',$DeptId),
 ('Dept Admin','$adminEmail','$hash','admin',NULL);
"@ | Out-Null
    $phId    = Scalar "SELECT id FROM users WHERE email='$phEmail'"
    $adminId = Scalar "SELECT id FROM users WHERE email='$adminEmail'"

    Invoke-Sql "INSERT INTO department_folders (department_id, folder_name) VALUES ($DeptId, '${tag}_Folder');" | Out-Null
    $folderId = Scalar "SELECT id FROM department_folders WHERE folder_name='${tag}_Folder'"

    Invoke-Sql "INSERT INTO department_requirements (department_id, category, document_type, description, mandatory, status, folder_id) VALUES ($DeptId, '${tag}', 'Doc', 'dept-sub test', 1, 'active', $folderId);" | Out-Null
    $reqId = Scalar "SELECT id FROM department_requirements WHERE category='${tag}'"

    Check 'seed: folder created'    ([int]$folderId -gt 0) "folder_id=$folderId"
    Check 'seed: requirement created' ([int]$reqId -gt 0) "req_id=$reqId"

    Check 'login PH ok'    ((Login $phEmail $pw $phJar) -match 'program_head_') ''
    Check 'login admin ok' ((Login $adminEmail $pw $adminJar) -match 'admin_dashboard') ''

    # -------- validation: bad extension --------
    $r = Invoke-Curl "$Base/program_head_requirements.php" -Jar $phJar
    $c = Get-Csrf $r.Body
    Check 'CSRF token from requirements page' ([bool]$c) ''

    $r = Upload-Personal $phJar $bad 'text/plain' $c
    Check 'V1. bad extension rejected'  ($r.Body -match 'Only PDF, DOC, and DOCX') "code=$($r.Code)"
    Check 'V2. no row written on reject' ([int](Scalar "SELECT COUNT(*) FROM documents WHERE category='${tag}'") -eq 0) ''
    Check 'V3. no orphan file on reject' (@(Get-ChildItem -Recurse -File -LiteralPath $storage -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "*${tag}*" }).Count -eq 0) ''

    # -------- accepted upload --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_requirements.php" -Jar $phJar).Body
    $r = Upload-Personal $phJar $pdf1 'application/pdf' $c
    Check 'U1. upload accepted' ($r.Body -match 'uploaded successfully and forwarded to Admin') "code=$($r.Code)"
    $docA = Scalar "SELECT id FROM documents WHERE category='${tag}' ORDER BY id DESC LIMIT 1"
    Check 'U2. row pending_admin + teacher_requirement_id NULL' `
        (((Scalar "SELECT status FROM documents WHERE id=$docA") -eq 'pending_admin') -and `
         ((Scalar "SELECT COUNT(*) FROM documents WHERE id=$docA AND teacher_requirement_id IS NULL") -eq 1)) ''
    $hA = Scalar "SELECT file_hash FROM documents WHERE id=$docA"
    Check 'U3. file_hash populated (64 hex)' ($hA -match '^[0-9a-f]{64}$') "hash=$hA"
    $fpA = Scalar "SELECT file_path FROM documents WHERE id=$docA"
    $diskA = Join-Path $storage ($fpA -replace '/','\')
    Check 'U4. physical file stored' (Test-Path -LiteralPath $diskA) "path=$fpA"

    # -------- notification --------
    Check 'N1. admin notified of new dept document' `
        ([int](Scalar "SELECT COUNT(*) FROM in_app_notifications WHERE user_id=$adminId AND related_document_id=$docA AND related_doc_type='document'") -ge 1) ''

    # -------- repeated request while pending (blocked by state) --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_requirements.php" -Jar $phJar).Body
    $r = Upload-Personal $phJar $pdf2 'application/pdf' $c
    Check 'R1. repeat while pending blocked' ($r.Body -match 'Cannot upload while this requirement is pending review or approved') ''
    Check 'R2. still exactly one row' ([int](Scalar "SELECT COUNT(*) FROM documents WHERE category='${tag}'") -eq 1) ''

    # -------- admin reject --------
    $ac = Get-Csrf (Invoke-Curl "$Base/admin_submissions.php" -Jar $adminJar).Body
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=reject',
        '--data-urlencode','comment=please fix','--data-urlencode',"submission_id=$docA")
    Check 'A1. admin reject -> rejected_admin' ((Scalar "SELECT status FROM documents WHERE id=$docA") -eq 'rejected_admin') "loc=$(Get-Loc $r.Headers)"

    # -------- duplicate detection on byte-identical re-upload --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_requirements.php" -Jar $phJar).Body
    $r = Upload-Personal $phJar $pdf1 'application/pdf' $c
    Check 'D1. identical re-upload blocked (SHA-256)' ($r.Body -match 'This exact file has already been submitted') ''
    Check 'D2. no duplicate row inserted' ([int](Scalar "SELECT COUNT(*) FROM documents WHERE category='${tag}'") -eq 1) ''
    Check 'D3. no orphan file from dup reject' (@(Get-ChildItem -Recurse -File -LiteralPath $storage -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "*${tag}*" }).Count -eq 1) ''

    # -------- new bytes accepted, version bumps --------
    $c = Get-Csrf (Invoke-Curl "$Base/program_head_requirements.php" -Jar $phJar).Body
    $r = Upload-Personal $phJar $pdf2 'application/pdf' $c
    Check 'U5. new bytes accepted' ($r.Body -match 'uploaded successfully and forwarded to Admin') ''
    $docB = Scalar "SELECT id FROM documents WHERE category='${tag}' ORDER BY id DESC LIMIT 1"
    Check 'U6. version bumped to 2' ([int](Scalar "SELECT version FROM documents WHERE id=$docB") -ge 2) "ver=$(Scalar "SELECT version FROM documents WHERE id=$docB")"

    # -------- admin approve + physical move --------
    $pendingB = Scalar "SELECT file_path FROM documents WHERE id=$docB"
    $ac = Get-Csrf (Invoke-Curl "$Base/admin_submissions.php" -Jar $adminJar).Body
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docB")
    Check 'A2. admin approve -> approved_admin' ((Scalar "SELECT status FROM documents WHERE id=$docB") -eq 'approved_admin') "loc=$(Get-Loc $r.Headers)"
    $fpB = Scalar "SELECT file_path FROM documents WHERE id=$docB"
    $diskB = Join-Path $storage ($fpB -replace '/','\')
    Check 'A3. approved file exists at new path' (Test-Path -LiteralPath $diskB) "path=$fpB"
    $oldB = Join-Path $storage ($pendingB -replace '/','\')
    Check 'A4. pending file removed after move' (-not (Test-Path -LiteralPath $oldB)) "old=$pendingB"
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
DELETE FROM department_requirements WHERE category='${tag}';
DELETE FROM department_folders WHERE folder_name='${tag}_Folder';
DELETE FROM in_app_notifications WHERE message LIKE '%${tag}%';
DELETE FROM audit_trail WHERE description LIKE '%${tag}%' OR description LIKE '%dept\_%example.test%';
DELETE FROM login_attempts WHERE email LIKE 'dept\_%@example.test';
DELETE FROM users WHERE email LIKE 'dept\_%${ts}@example.test';
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
