#requires -Version 7
<#
  CHED Compliance — review-authorization test (HTTP, curl-based).

  Verifies that forged POSTs / cross-department / invalid-state review attempts
  cannot skip Program Head or Administrator review:

    A. Admin cannot approve a teacher submission still pending PROGRAM HEAD.
       (config.php adminReview whitelist = pending_admin only)
    B. PH cannot review a document that is no longer pending_program_head
       (e.g. a direct upload sitting in pending_admin).
    C. A PH from another department cannot review this department's submission.
    D. A PH cannot re-approve a ph_document already forwarded to Admin
       (new programHeadReviewPH state guard).
    E. Valid baseline transitions still work (PH approve -> pending_admin;
       admin approve pending_admin -> approved_admin).
    F. Unauthenticated POST is bounced to login.

  Seeds ephemeral users/requirements/documents, then removes everything.
  Run:  pwsh -File scripts/authz_test.ps1
#>
[CmdletBinding()]
param(
    [string]$Base  = 'http://localhost/o',
    [string]$Php   = 'C:\xampp\php\php.exe',
    [string]$Mysql = 'C:\xampp\mysql\bin\mysql.exe',
    [int]   $DeptId  = 1,
    [int]   $Dept2Id = 0
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$storage  = 'C:\ched_secure_storage\documents'
$ts   = Get-Date -Format 'yyyyMMddHHmmss'
$tag  = "AUTHZ_$ts"
$pw   = 'Auth#Test-2026'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_authz_$ts"
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
        '-X', 'POST',
        '--data-urlencode', "csrf_token=$c",
        '--data-urlencode', "email=$Email",
        '--data-urlencode', "password=$Pw",
        '--data-urlencode', 'remember=1'
    )
    return (Get-Loc $r.Headers)
}

$ts_ = $ts
$ph1Email = "authz_ph1_${ts}@example.test"
$ph2Email = "authz_ph2_${ts}@example.test"
$adminEmail = "authz_admin_${ts}@example.test"
$tEmail = "authz_teacher_${ts}@example.test"
$ph1Jar = Join-Path $work 'ph1.jar'
$ph2Jar = Join-Path $work 'ph2.jar'
$adminJar = Join-Path $work 'admin.jar'
$anonJar = Join-Path $work 'anon.jar'

$hash = (& $Php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

function New-StoredFile([string]$Rel) {
    $disk = Join-Path 'C:\ched_secure_storage\documents' ($Rel -replace '/', '\')
    $dir = Split-Path -Parent $disk
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Set-Content -Path $disk -NoNewline -Value "%PDF-1.4`n%%EOF`n% $Rel $ts_"
    return $Rel
}

try {
    # ---- seed users + a second department ----
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('Authz PH1','$ph1Email','$hash','department',$DeptId),
 ('Authz Admin','$adminEmail','$hash','admin',NULL),
 ('Authz Teacher','$tEmail','$hash','teacher',$DeptId);
"@ | Out-Null
    if (-not (Scalar "SELECT id FROM departments WHERE id=2;")) {
        Invoke-Sql "INSERT INTO departments (id,department_name,department_code) VALUES (2,'Authz Dept 2','AZ2');" | Out-Null
    }
    $ph2 = Invoke-Sql "INSERT INTO users (name,email,password,role,department_id) VALUES ('Authz PH2','$ph2Email','$hash','department',2); SELECT LAST_INSERT_ID();"
    $ph2Id = ($ph2 | Select-Object -Last 1)
    $ph1Id = Scalar "SELECT id FROM users WHERE email='$ph1Email'"
    $adminId = Scalar "SELECT id FROM users WHERE email='$adminEmail'"
    $teacherId = Scalar "SELECT id FROM users WHERE email='$tEmail'"

    # ---- seed requirements + submissions ----
    Invoke-Sql @"
INSERT INTO department_requirements (department_id, category, document_type, description, mandatory, status)
  VALUES ($DeptId, '${tag}', 'Doc', 'authz', 1, 'active');
"@ | Out-Null
    $drId = Scalar "SELECT id FROM department_requirements WHERE category='${tag}' LIMIT 1"
    Invoke-Sql "INSERT INTO teacher_requirements (teacher_id, semester, requirement_id, requirement_type, assigned_by) VALUES ($teacherId, '1st', $drId, 'admin_requirement', $adminId);" | Out-Null
    $trId = Scalar "SELECT id FROM teacher_requirements WHERE requirement_id=$drId LIMIT 1"

    # A. teacher submission (two-level): pending_program_head
    $relA = "pending_program_head/${tag}_A.pdf"
    New-StoredFile $relA
    Invoke-Sql @"
INSERT INTO documents (teacher_requirement_id, department_id, category, document_type, title, filename, file_path, uploaded_by, status, version, upload_date)
  VALUES ($trId, $DeptId, '${tag}', 'Doc', '${tag}_A', 'a.pdf', '$relA', $teacherId, 'pending_program_head', 1, NOW());
"@ | Out-Null
    $docA = Scalar "SELECT id FROM documents WHERE title='${tag}_A' LIMIT 1"

    # B. direct upload (single-level): pending_admin
    $relB = "pending_admin/${tag}_B.pdf"
    New-StoredFile $relB
    Invoke-Sql @"
INSERT INTO documents (teacher_requirement_id, department_id, category, document_type, title, filename, file_path, uploaded_by, status, version, upload_date, source_type, origin_source)
  VALUES (NULL, $DeptId, 'PH', 'Direct Upload', '${tag}_B', 'b.pdf', '$relB', $ph1Id, 'pending_admin', 1, NOW(), 'quick_direct', 'quick_direct');
"@ | Out-Null
    $docB = Scalar "SELECT id FROM documents WHERE title='${tag}_B' LIMIT 1"

    # C. ph_document submission (two-level PH requirement): pending_program_head
    $relC = "pending_program_head/${tag}_C.pdf"
    New-StoredFile $relC
    Invoke-Sql "INSERT INTO ph_requirements (department_id, created_by, title, description, assigned) VALUES ($DeptId, $ph1Id, '${tag}_PR', 'authz', 1);" | Out-Null
    $phrId = Scalar "SELECT id FROM ph_requirements WHERE title='${tag}_PR' LIMIT 1"
    Invoke-Sql "INSERT INTO teacher_ph_requirements (teacher_id, ph_requirement_id, requirement_type) VALUES ($teacherId, $phrId, 'program_head_requirement');" | Out-Null
    $tprId = Scalar "SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id=$phrId LIMIT 1"
    Invoke-Sql @"
INSERT INTO ph_documents (teacher_ph_requirement_id, department_id, title, filename, file_path, uploaded_by, status, version, upload_date)
  VALUES ($tprId, $DeptId, '${tag}_C', 'c.pdf', '$relC', $teacherId, 'pending_program_head', 1, NOW());
"@ | Out-Null
    $pdC = Scalar "SELECT id FROM ph_documents WHERE title='${tag}_C' LIMIT 1"

    function Status([string]$table, [int]$id) { Scalar "SELECT status FROM $table WHERE id=$id" }

    # ---------------- logins ----------------
    Check 'PH1 login ok' ((Login $ph1Email $pw $ph1Jar) -match 'program_head_dashboard') ''
    Check 'PH2 login ok' ((Login $ph2Email $pw $ph2Jar) -match 'program_head_dashboard') ''
    Check 'Admin login ok' ((Login $adminEmail $pw $adminJar) -match 'admin_dashboard') ''

    # ---------------- F. unauthenticated POST ----------------
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $anonJar -Extra @(
        '-X','POST','--data-urlencode','csrf_token=bogus','--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docA"
    )
    Check 'F. anon POST redirected to login' ($r.Code -eq 302 -and (Get-Loc $r.Headers) -match 'login\.php') "code=$($r.Code)"
    Check 'F. doc A unchanged' ((Status 'documents' $docA) -eq 'pending_program_head') ""

    # ---------------- A. admin cannot approve pending_program_head ----------------
    $r = Invoke-Curl "$Base/admin_submissions.php" -Jar $adminJar
    $ac = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docA"
    )
    $locA = Get-Loc $r.Headers
    Check 'A. admin approve of PH-pending doc denied' ($r.Code -eq 302 -and $locA -match 'not\+awaiting\+Admin') "code=$($r.Code) loc=$locA"
    Check 'A. doc A still pending_program_head' ((Status 'documents' $docA) -eq 'pending_program_head') ""

    # ---------------- B. PH cannot review pending_admin (skip admin) ----------------
    $r = Invoke-Curl "$Base/program_head_pending.php" -Jar $ph1Jar
    $pc = Get-Csrf $r.Body
    if (-not $pc) { $pc = Get-Csrf (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $ph1Jar).Body }
    $r = Invoke-Curl "$Base/program_head_review.php" -Jar $ph1Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docB"
    )
    Check 'B. PH approve of pending_admin doc denied' ($r.Code -eq 302 -and (Get-Loc $r.Headers) -match '(?i)denied|Failed\+to\+process') "code=$($r.Code) loc=$(Get-Loc $r.Headers)"
    Check 'B. doc B still pending_admin' ((Status 'documents' $docB) -eq 'pending_admin') ""

    # ---------------- C. cross-department PH review denied ----------------
    $r = Invoke-Curl "$Base/program_head_pending.php" -Jar $ph2Jar
    $pc2 = Get-Csrf $r.Body
    if (-not $pc2) { $pc2 = Get-Csrf (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $ph2Jar).Body }
    $r = Invoke-Curl "$Base/program_head_review.php" -Jar $ph2Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$pc2",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docA"
    )
    Check 'C. cross-dept PH review denied' ($r.Code -eq 302 -and (Get-Loc $r.Headers) -match '(?i)denied|Failed\+to\+process|access') "code=$($r.Code) loc=$(Get-Loc $r.Headers)"
    Check 'C. doc A unchanged after cross-dept' ((Status 'documents' $docA) -eq 'pending_program_head') ""

    # ---------------- E1. valid PH approve -> pending_admin ----------------
    $r = Invoke-Curl "$Base/program_head_pending.php" -Jar $ph1Jar
    $pc = Get-Csrf $r.Body
    if (-not $pc) { $pc = Get-Csrf (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $ph1Jar).Body }
    $r = Invoke-Curl "$Base/program_head_review.php" -Jar $ph1Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docA"
    )
    Check 'E1. valid PH approve -> pending_admin' ((Status 'documents' $docA) -eq 'pending_admin') "code=$($r.Code) loc=$(Get-Loc $r.Headers)"

    # ---------------- E2. valid admin approve -> approved_admin ----------------
    $r = Invoke-Curl "$Base/admin_submissions.php" -Jar $adminJar
    $ac = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/admin_review_submission.php" -Jar $adminJar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$ac",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$docB"
    )
    Check 'E2. admin approve pending_admin -> approved_admin' ((Status 'documents' $docB) -eq 'approved_admin') "code=$($r.Code) loc=$(Get-Loc $r.Headers)"

    # ---------------- D. PH valid approve of ph_document then re-approve denied ----------------
    $r = Invoke-Curl "$Base/program_head_pending.php" -Jar $ph1Jar
    $pc = Get-Csrf $r.Body
    if (-not $pc) { $pc = Get-Csrf (Invoke-Curl "$Base/program_head_dashboard.php" -Jar $ph1Jar).Body }
    $r = Invoke-Curl "$Base/program_head_review_ph.php" -Jar $ph1Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$pdC"
    )
    Check 'E2b. PH approve ph_document ok' ((Status 'ph_documents' $pdC) -eq 'approved_program_head') "code=$($r.Code) loc=$(Get-Loc $r.Headers)"
    $r = Invoke-Curl "$Base/program_head_review_ph.php" -Jar $ph1Jar -Extra @(
        '-X','POST','--data-urlencode',"csrf_token=$pc",'--data-urlencode','action=approve',
        '--data-urlencode',"submission_id=$pdC"
    )
    Check 'D. PH re-approve forwarded ph_doc denied' ((Status 'ph_documents' $pdC) -eq 'approved_program_head') "code=$($r.Code) loc=$(Get-Loc $r.Headers)"
}
finally {
    try {
        foreach ($p in (Invoke-Sql "SELECT file_path FROM documents WHERE title LIKE '${tag}%' UNION ALL SELECT file_path FROM ph_documents WHERE title LIKE '${tag}%' UNION ALL SELECT file_path FROM ph_repository WHERE title LIKE '${tag}%';")) {
            if ([string]::IsNullOrWhiteSpace($p)) { continue }
            $disk = Join-Path 'C:\ched_secure_storage\documents' ($p.Trim() -replace '/', '\')
            if (Test-Path -LiteralPath $disk) { Remove-Item -LiteralPath $disk -Force -ErrorAction SilentlyContinue }
        }
        Invoke-Sql @"
DELETE FROM ph_repository WHERE title LIKE '${tag}%';
DELETE FROM ph_documents WHERE title LIKE '${tag}%';
DELETE FROM documents WHERE title LIKE '${tag}%';
DELETE FROM teacher_ph_requirements WHERE ph_requirement_id IN (SELECT id FROM ph_requirements WHERE title='${tag}_PR');
DELETE FROM ph_requirements WHERE title='${tag}_PR';
DELETE FROM teacher_requirements WHERE requirement_id IN (SELECT id FROM department_requirements WHERE category='${tag}');
DELETE FROM department_requirements WHERE category='${tag}';
DELETE FROM in_app_notifications WHERE message LIKE '%${tag}%' OR title LIKE '%${tag}%';
DELETE FROM audit_trail WHERE description LIKE '%${tag}%' OR description LIKE '%authz_%example.test%';
DELETE FROM login_attempts WHERE email LIKE 'authz\_%@example.test';
DELETE FROM users WHERE email LIKE 'authz\_%${ts}@example.test';
"@ | Out-Null
        Write-Host '[i] cleanup complete'
    } catch {
        Write-Warning "cleanup failed: $($_.Exception.Message)"
    }
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
