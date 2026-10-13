#requires -Version 7
<#
  CHED Compliance — end-to-end submission lifecycle test (HTTP, curl-based).

  Covers the full lifecycle for both document families:

    Flow A  admin_requirement (two-level): teacher upload -> pending_program_head
            -> PH approve -> pending_admin -> admin approve -> approved_admin
    Flow B  PH rejection + teacher resubmit (version bump)
    Flow C  PH-created requirement: teacher upload -> PH approve
            -> approved_program_head + ph_repository entry (stays in repo)
    Flow D  admin rejection of a pending_admin document -> rejected_admin
    Guard   admin cannot re-approve an already-approved document

  Seeds ephemeral users/requirements, drives the real endpoints, verifies DB
  status + on-disk file moves, then removes every row/file it created.

  Run:  pwsh -File scripts/e2e_test.ps1
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
$tag  = "E2E_$ts"
$pw   = 'E2e#Test-2026'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_e2e_$ts"
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
function Token([string]$Jar, [string]$Page) {
    $r = Invoke-Curl "$Base/$Page" -Jar $Jar
    $t = Get-Csrf $r.Body
    if (-not $t) { $t = Get-Csrf (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $Jar).Body }
    return $t
}

$phEmail = "e2e_ph_${ts}@example.test"
$adminEmail = "e2e_admin_${ts}@example.test"
$tEmail = "e2e_teacher_${ts}@example.test"
$phJar = Join-Path $work 'ph.jar'; $adminJar = Join-Path $work 'admin.jar'; $tJar = Join-Path $work 'teacher.jar'

$hash = (& $Php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

function New-Pdf([string]$Name, [string]$Seed) {
    $p = Join-Path $work $Name
    Set-Content -Path $p -NoNewline -Value "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`n%%EOF`n% $Seed"
    return $p
}
$pdfA = New-Pdf 'a.pdf' "e2e-a-$ts"
$pdfB = New-Pdf 'b.pdf' "e2e-b-$ts"
$pdfB2 = New-Pdf 'b2.pdf' "e2e-b2-$ts"
$pdfC = New-Pdf 'c.pdf' "e2e-c-$ts"
$pdfD = New-Pdf 'd.pdf' "e2e-d-$ts"
foreach ($v in 'pdfA','pdfB','pdfB2','pdfC','pdfD') { Set-Variable $v ((Get-Variable $v).Value.Replace('\','/')) }

try {
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('E2E PH','$phEmail','$hash','department',$DeptId),
 ('E2E Admin','$adminEmail','$hash','admin',NULL),
 ('E2E Teacher','$tEmail','$hash','teacher',$DeptId);
"@ | Out-Null
    $phId = Scalar "SELECT id FROM users WHERE email='$phEmail'"
    $adminId = Scalar "SELECT id FROM users WHERE email='$adminEmail'"
    $teacherId = Scalar "SELECT id FROM users WHERE email='$tEmail'"

    # Flow A: admin_requirement (two-level)
    Invoke-Sql "INSERT INTO department_requirements (department_id, category, document_type, description, mandatory, status) VALUES ($DeptId, '${tag}_A', 'Doc', 'e2e', 1, 'active');" | Out-Null
    $drA = Scalar "SELECT id FROM department_requirements WHERE category='${tag}_A' LIMIT 1"
    Invoke-Sql "INSERT INTO teacher_requirements (teacher_id, semester, requirement_id, requirement_type, assigned_by) VALUES ($teacherId,'1st',$drA,'admin_requirement',$adminId);" | Out-Null
    $trA = Scalar "SELECT id FROM teacher_requirements WHERE requirement_id=$drA LIMIT 1"

    # Flow B: admin_requirement (reject + resubmit)
    Invoke-Sql "INSERT INTO department_requirements (department_id, category, document_type, description, mandatory, status) VALUES ($DeptId, '${tag}_B', 'Doc', 'e2e', 1, 'active');" | Out-Null
    $drB = Scalar "SELECT id FROM department_requirements WHERE category='${tag}_B' LIMIT 1"
    Invoke-Sql "INSERT INTO teacher_requirements (teacher_id, semester, requirement_id, requirement_type, assigned_by) VALUES ($teacherId,'1st',$drB,'admin_requirement',$adminId);" | Out-Null
    $trB = Scalar "SELECT id FROM teacher_requirements WHERE requirement_id=$drB LIMIT 1"

    # Flow C: PH-created requirement
    Invoke-Sql "INSERT INTO ph_requirements (department_id, created_by, title, description, assigned) VALUES ($DeptId,$phId,'${tag}_PR','e2e',1);" | Out-Null
    $phrId = Scalar "SELECT id FROM ph_requirements WHERE title='${tag}_PR' LIMIT 1"
    Invoke-Sql "INSERT INTO teacher_ph_requirements (teacher_id, ph_requirement_id, requirement_type) VALUES ($teacherId,$phrId,'program_head_requirement');" | Out-Null

    # Flow D: direct upload pending_admin
    Invoke-Sql "INSERT INTO documents (teacher_requirement_id, department_id, category, document_type, title, filename, file_path, uploaded_by, status, version, upload_date, source_type, origin_source) VALUES (NULL,$DeptId,'PH','Direct Upload','${tag}_D','d.pdf','pending_admin/${tag}_D.pdf',$phId,'pending_admin',1,NOW(),'quick_direct','quick_direct');" | Out-Null
    $docD = Scalar "SELECT id FROM documents WHERE title='${tag}_D' LIMIT 1"
    $diskD = Join-Path $storage "pending_admin\${tag}_D.pdf"
    New-Item -ItemType Directory -Path (Split-Path $diskD) -Force | Out-Null
    Copy-Item ($pdfD -replace '/','\') $diskD

    # ---- logins ----
    Check 'E2E teacher login ok' ((Login $tEmail $pw $tJar) -match 'teacher_') ''
    Check 'E2E PH login ok' ((Login $phEmail $pw $phJar) -match 'program_head_dashboard') ''
    Check 'E2E admin login ok' ((Login $adminEmail $pw $adminJar) -match 'admin_dashboard') ''

    # ================= Flow A =================
    $tc = Token $tJar 'teacher_tasks.php'
    $r = Invoke-Curl "$Base/teacher_tasks.php" -Jar $tJar -Extra @(
        '-H','X-Requested-With: XMLHttpRequest','-X','POST',
        '-F',"csrf_token=$tc",'-F','ajax_upload=1',"-F","requirement_id=$trA",
        '-F',"document_file=@$pdfA;type=application/pdf"
    )
    Check 'A1. teacher upload accepted' ($r.Body -match '"success":\s*true') "code=$($r.Code) body=$($r.Body.Substring(0,[Math]::Min(120,$r.Body.Length)))"
    $docA = Scalar "SELECT id FROM documents WHERE teacher_requirement_id=$trA ORDER BY id DESC LIMIT 1"
    Check 'A2. doc pending_program_head' ((Scalar "SELECT status FROM documents WHERE id=$docA") -eq 'pending_program_head') ""

    $pc = Token $phJar 'program_head_pending.php'
    $r = Invoke-Curl "$Base/program_head_review.php" -Jar $phJar -Extra @('-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=approve','--data-urlencode',"submission_id=$docA")
    Check 'A3. PH approve -> pending_admin' ((Scalar "SELECT status FROM documents WHERE id=$docA") -eq 'pending_admin') "loc=$(Get-Loc $r.Headers)"

    $ac = Token $adminJar 'admin_submissions.php'
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @('-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=approve','--data-urlencode',"submission_id=$docA")
    Check 'A4. admin approve -> approved_admin' ((Scalar "SELECT status FROM documents WHERE id=$docA") -eq 'approved_admin') "loc=$(Get-Loc $r.Headers)"
    $fpA = Scalar "SELECT file_path FROM documents WHERE id=$docA"
    Check 'A5. approved file exists at new path' (Test-Path -LiteralPath (Join-Path $storage ($fpA -replace '/','\'))) "path=$fpA"

    # ================= Flow B (PH reject + resubmit) =================
    $tc = Token $tJar 'teacher_tasks.php'
    $r = Invoke-Curl "$Base/teacher_tasks.php" -Jar $tJar -Extra @(
        '-H','X-Requested-With: XMLHttpRequest','-X','POST',
        '-F',"csrf_token=$tc",'-F','ajax_upload=1',"-F","requirement_id=$trB",
        '-F',"document_file=@$pdfB;type=application/pdf"
    )
    $docB1 = Scalar "SELECT id FROM documents WHERE teacher_requirement_id=$trB ORDER BY id DESC LIMIT 1"
    Check 'B1. teacher upload accepted' ($r.Body -match '"success":\s*true') "body=$($r.Body.Substring(0,[Math]::Min(120,$r.Body.Length)))"

    $pc = Token $phJar 'program_head_pending.php'
    $r = Invoke-Curl "$Base/program_head_review.php" -Jar $phJar -Extra @('-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=reject','--data-urlencode','comment=Please fix','--data-urlencode',"submission_id=$docB1")
    Check 'B2. PH reject -> rejected_program_head' ((Scalar "SELECT status FROM documents WHERE id=$docB1") -eq 'rejected_program_head') "loc=$(Get-Loc $r.Headers)"

    $tc = Token $tJar 'teacher_tasks.php'
    $r = Invoke-Curl "$Base/teacher_tasks.php" -Jar $tJar -Extra @(
        '-H','X-Requested-With: XMLHttpRequest','-X','POST',
        '-F',"csrf_token=$tc",'-F','ajax_upload=1',"-F","requirement_id=$trB",
        '-F',"document_file=@$pdfB2;type=application/pdf"
    )
    Check 'B2. teacher resubmit accepted (new bytes)' ($r.Body -match '"success":\s*true') "body=$($r.Body.Substring(0,[Math]::Min(120,$r.Body.Length)))"
    $docB2 = Scalar "SELECT id FROM documents WHERE teacher_requirement_id=$trB ORDER BY id DESC LIMIT 1"
    $verB2 = Scalar "SELECT version FROM documents WHERE id=$docB2"
    Check 'B3. resubmit version bumped' ([int]$verB2 -ge 2 -and (Scalar "SELECT status FROM documents WHERE id=$docB2") -eq 'pending_program_head') "ver=$verB2"

    # ================= Flow C (PH-created requirement) =================
    $tc = Token $tJar 'teacher_tasks.php'
    $r = Invoke-Curl "$Base/teacher_tasks.php" -Jar $tJar -Extra @(
        '-H','X-Requested-With: XMLHttpRequest','-X','POST',
        '-F',"csrf_token=$tc",'-F','ajax_upload=1','-F','is_ph_upload=1',"-F","ph_requirement_id=$phrId",
        '-F',"document_file=@$pdfC;type=application/pdf"
    )
    Check 'C1. teacher PH-req upload accepted' ($r.Body -match '"success":\s*true') "body=$($r.Body.Substring(0,[Math]::Min(120,$r.Body.Length)))"
    $pdC = Scalar "SELECT id FROM ph_documents WHERE teacher_ph_requirement_id IN (SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id=$phrId) ORDER BY id DESC LIMIT 1"
    Check 'C2. ph_document pending_program_head' ((Scalar "SELECT status FROM ph_documents WHERE id=$pdC") -eq 'pending_program_head') ""

    $pc = Token $phJar 'program_head_pending.php'
    $r = Invoke-Curl "$Base/program_head_review_ph.php" -Jar $phJar -Extra @('-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=approve','--data-urlencode',"submission_id=$pdC")
    Check 'C3. PH approve -> approved_program_head' ((Scalar "SELECT status FROM ph_documents WHERE id=$pdC") -eq 'approved_program_head') "loc=$(Get-Loc $r.Headers)"
    Check 'C4. ph_repository entry created' ((Scalar "SELECT COUNT(*) FROM ph_repository WHERE ph_document_id=$pdC") -ge 1) ""

    # ================= Flow D (admin reject) =================
    $ac = Token $adminJar 'admin_submissions.php'
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @('-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=reject','--data-urlencode','comment=not acceptable','--data-urlencode',"submission_id=$docD")
    Check 'D1. admin reject -> rejected_admin' ((Scalar "SELECT status FROM documents WHERE id=$docD") -eq 'rejected_admin') "loc=$(Get-Loc $r.Headers)"

    # ================= Guard: re-approve already-approved =================
    $ac = Token $adminJar 'admin_submissions.php'
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @('-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=approve','--data-urlencode',"submission_id=$docA")
    Check 'G1. re-approve approved doc denied' ((Scalar "SELECT status FROM documents WHERE id=$docA") -eq 'approved_admin') "loc=$(Get-Loc $r.Headers)"
}
finally {
    try {
        foreach ($p in (Invoke-Sql "SELECT file_path FROM documents WHERE title LIKE '${tag}%' UNION ALL SELECT file_path FROM ph_documents WHERE title LIKE '${tag}%' UNION ALL SELECT file_path FROM ph_repository WHERE title LIKE '${tag}%';")) {
            if ([string]::IsNullOrWhiteSpace($p)) { continue }
            $disk = Join-Path $storage ($p.Trim() -replace '/', '\')
            if (Test-Path -LiteralPath $disk) { Remove-Item -LiteralPath $disk -Force -ErrorAction SilentlyContinue }
        }
        Invoke-Sql @"
DELETE FROM ph_repository WHERE title LIKE '${tag}%';
DELETE FROM ph_documents WHERE title LIKE '${tag}%';
DELETE FROM documents WHERE title LIKE '${tag}%' OR teacher_requirement_id IN (SELECT id FROM teacher_requirements WHERE requirement_id IN (SELECT id FROM department_requirements WHERE category LIKE '${tag}%'));
DELETE FROM teacher_ph_requirements WHERE ph_requirement_id IN (SELECT id FROM ph_requirements WHERE title LIKE '${tag}%');
DELETE FROM ph_requirements WHERE title LIKE '${tag}%';
DELETE FROM teacher_requirements WHERE requirement_id IN (SELECT id FROM department_requirements WHERE category LIKE '${tag}%');
DELETE FROM department_requirements WHERE category LIKE '${tag}%';
DELETE FROM in_app_notifications WHERE message LIKE '%${tag}%' OR title LIKE '%${tag}%';
DELETE FROM audit_trail WHERE description LIKE '%${tag}%' OR description LIKE '%e2e_%example.test%';
DELETE FROM login_attempts WHERE email LIKE 'e2e\_%@example.test';
DELETE FROM users WHERE email LIKE 'e2e\_%${ts}@example.test';
"@ | Out-Null
        Write-Host '[i] cleanup complete'
    } catch { Write-Warning "cleanup failed: $($_.Exception.Message)" }
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
