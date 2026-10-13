#requires -Version 7
<#
  CHED Compliance — duplicate-prevention concurrency + NULL-hash test.

    T1  Two truly-parallel uploads of the SAME file bytes for the SAME
        assignment: exactly one row must persist (advisory lock + unique index).
    T2  DB unique index (uq_documents_tr_hash) rejects a second row with the
        same (teacher_requirement_id, file_hash).
    T3  NULL file_hash rows are exempt from the unique index (documents the
        residual risk when hashing fails / legacy rows).

  Run:  pwsh -File scripts/concurrency_test.ps1
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
$tag  = "CONC_$ts"
$pw   = 'Conc#Test-2026'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_conc_$ts"
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
    $a = @('-s', '-D', $hdr, '-o', $body, '-w', '%{http_code}', '-b', $Jar, '-c', $Jar) + $Extra + $Url
    $code = & curl.exe @a
    [pscustomobject]@{ Code = [int]("$code".Trim()); Headers = (Get-Content $hdr -Raw); Body = (Get-Content $body -Raw) }
}
function Get-Csrf([string]$Html) { if ($Html -match 'name="csrf_token"\s+value="([A-Za-z0-9]+)"') { return $Matches[1] }; return $null }
function Get-Loc([string]$Headers) { if ($Headers -match '(?im)^Location:\s*(.+?)\s*$') { return $Matches[1] }; return '' }
function Login([string]$Email, [string]$Pw, [string]$Jar) {
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar
    $c = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar -Extra @('-X','POST','--data-urlencode',"csrf_token=$c",'--data-urlencode',"email=$Email",'--data-urlencode',"password=$Pw",'--data-urlencode','remember=1')
    return (Get-Loc $r.Headers)
}
function Token([string]$Jar, [string]$Page) {
    $t = Get-Csrf (Invoke-Curl "$Base/$Page" -Jar $Jar).Body
    if (-not $t) { $t = Get-Csrf (Invoke-Curl "$Base/teacher_dashboard.php" -Jar $Jar).Body }
    return $t
}

$tEmail = "conc_t_${ts}@example.test"
$tJar1 = Join-Path $work 't1.jar'; $tJar2 = Join-Path $work 't2.jar'
$hash = (& $Php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

$shared = Join-Path $work 'same.pdf'
Set-Content -Path $shared -NoNewline -Value "%PDF-1.4`n1 0 obj<</Type/Catalog>>endobj`ntrailer<</Root 1 0 R>>`n%%EOF`n% conc-$ts"
$sharedCurl = $shared.Replace('\','/')
$sha = (Get-FileHash -Algorithm SHA256 -LiteralPath $shared).Hash.ToLower()

try {
    Invoke-Sql "INSERT INTO users (name,email,password,role,department_id) VALUES ('Conc T','$tEmail','$hash','teacher',$DeptId);" | Out-Null
    Invoke-Sql "INSERT INTO department_requirements (department_id, category, document_type, description, mandatory, status) VALUES ($DeptId,'${tag}','Doc','conc',1,'active');" | Out-Null
    $dr = Scalar "SELECT id FROM department_requirements WHERE category='${tag}' LIMIT 1"
    Invoke-Sql "INSERT INTO teacher_requirements (teacher_id, semester, requirement_id, requirement_type, assigned_by) VALUES ((SELECT id FROM users WHERE email='$tEmail'),'1st',$dr,'admin_requirement',NULL);" | Out-Null
    $tr = Scalar "SELECT id FROM teacher_requirements WHERE requirement_id=$dr LIMIT 1"

    Check 'teacher login #1' ((Login $tEmail $pw $tJar1) -match 'teacher_') ''
    # Single-active-session policy: a second browser/device login for the same
    # account is denied while the first session is fresh.
    Check 'teacher login #2 denied (single-session policy)' ((Login $tEmail $pw $tJar2) -notmatch 'teacher_') ''
    $tk1 = Token $tJar1 'teacher_tasks.php'
    # Two tabs of the SAME browser share one session (same cookie). Copy the jar
    # so the two parallel curl processes don't race writing the same file.
    Copy-Item $tJar1 $tJar2 -Force
    Check 'CSRF token obtained' ([bool]$tk1) ''

    # ---- T1: launch two uploads simultaneously ----
    $out1 = Join-Path $work 'o1.txt'; $out2 = Join-Path $work 'o2.txt'
    function Start-Upload([string]$Jar, [string]$Token, [string]$Out) {
        $psi = [System.Diagnostics.ProcessStartInfo]::new()
        $psi.FileName = 'curl.exe'
        $psi.UseShellExecute = $false
        foreach ($a in @('-s','-o',$Out,'-b',$Jar,'-c',$Jar,
                '-H','X-Requested-With: XMLHttpRequest','-X','POST',
                '-F',"csrf_token=$Token",'-F','ajax_upload=1',"-F","requirement_id=$tr",
                '-F',"document_file=@$sharedCurl;type=application/pdf",
                "$Base/teacher_tasks.php")) { $psi.ArgumentList.Add($a) }
        return [System.Diagnostics.Process]::Start($psi)
    }
    $p1 = Start-Upload $tJar1 $tk1 $out1
    $p2 = Start-Upload $tJar2 $tk1 $out2
    $p1.WaitForExit(); $p2.WaitForExit()
    $b1 = if (Test-Path $out1) { Get-Content $out1 -Raw } else { '' }
    $b2 = if (Test-Path $out2) { Get-Content $out2 -Raw } else { '' }

    $okCount = ([int]($b1 -match '"success":\s*true') + [int]($b2 -match '"success":\s*true'))
    $dupCount = ([int]($b1 -match 'already been submitted') + [int]($b2 -match 'already been submitted'))
    $rows = [int](Scalar "SELECT COUNT(*) FROM documents WHERE teacher_requirement_id=$tr")
    Check 'T1. exactly one row persisted' ($rows -eq 1) "rows=$rows ok=$okCount dup=$dupCount"
    Check 'T1. exactly one upload reported success' ($okCount -eq 1) "ok=$okCount"
    Check 'T1. the loser was duplicate-blocked' ($dupCount -eq 1) "dup=$dupCount"

    # ---- T2: DB unique index rejects identical (tr, hash) ----
    Invoke-Sql "UPDATE documents SET file_hash='$sha' WHERE teacher_requirement_id=$tr;" | Out-Null
    $ins = Invoke-Sql "INSERT INTO documents (teacher_requirement_id, department_id, category, document_type, title, filename, file_path, uploaded_by, status, version, upload_date, file_hash) VALUES ($tr,$DeptId,'${tag}','Doc','${tag}_x','x.pdf','pending_program_head/x.pdf',NULL,'pending_program_head',99,NOW(),'$sha');"
    $rejected = ($ins -join ' ') -match 'Duplicate entry|1062'
    Check 'T2. unique index rejects duplicate (tr,hash)' $rejected "resp=$($ins -join ' ')"
    Invoke-Sql "DELETE FROM documents WHERE title='${tag}_x';" | Out-Null

    # ---- T3: NULL hash rows are exempt (documented residual) ----
    Invoke-Sql "INSERT INTO documents (teacher_requirement_id, department_id, category, document_type, title, filename, file_path, uploaded_by, status, version, upload_date, file_hash) VALUES ($tr,$DeptId,'${tag}','Doc','${tag}_n1','n1.pdf','pending_program_head/n1.pdf',NULL,'pending_program_head',100,NOW(),NULL),($tr,$DeptId,'${tag}','Doc','${tag}_n2','n2.pdf','pending_program_head/n2.pdf',NULL,'pending_program_head',101,NOW(),NULL);" | Out-Null
    $nullRows = [int](Scalar "SELECT COUNT(*) FROM documents WHERE title IN ('${tag}_n1','${tag}_n2')")
    Check 'T3. NULL-hash rows exempt (both inserted)' ($nullRows -eq 2) "rows=$nullRows"
    Invoke-Sql "DELETE FROM documents WHERE title IN ('${tag}_n1','${tag}_n2');" | Out-Null
}
finally {
    try {
        foreach ($p in (Invoke-Sql "SELECT file_path FROM documents WHERE title LIKE '${tag}%';")) {
            if ([string]::IsNullOrWhiteSpace($p)) { continue }
            $disk = Join-Path $storage ($p.Trim() -replace '/','\')
            if (Test-Path -LiteralPath $disk) { Remove-Item -LiteralPath $disk -Force -ErrorAction SilentlyContinue }
        }
        Invoke-Sql @"
DELETE FROM documents WHERE title LIKE '${tag}%' OR teacher_requirement_id IN (SELECT id FROM teacher_requirements WHERE requirement_id IN (SELECT id FROM department_requirements WHERE category LIKE '${tag}%'));
DELETE FROM teacher_requirements WHERE requirement_id IN (SELECT id FROM department_requirements WHERE category='${tag}');
DELETE FROM department_requirements WHERE category='${tag}';
DELETE FROM in_app_notifications WHERE message LIKE '%${tag}%' OR title LIKE '%${tag}%';
DELETE FROM audit_trail WHERE description LIKE '%${tag}%' OR description LIKE '%conc_%example.test%';
DELETE FROM login_attempts WHERE email LIKE 'conc\_%@example.test';
DELETE FROM users WHERE email LIKE 'conc\_%${ts}@example.test';
"@ | Out-Null
        Write-Host '[i] cleanup complete'
    } catch { Write-Warning "cleanup failed: $($_.Exception.Message)" }
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ''
Write-Host ("=== {0}/{1} passed ===" -f ($script:results.Count - $fail), $script:results.Count)
if ($fail -gt 0) { exit 1 } else { exit 0 }
