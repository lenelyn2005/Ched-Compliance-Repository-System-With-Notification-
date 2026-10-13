#requires -Version 7
<#
  CHED Compliance - authenticated CSP browser test (headless Chrome).

  Logs in via fetch() to the real login form, then loads every migrated page in
  a same-origin iframe and asserts, in a REAL browser under the ENFORCED CSP:
    - 0 inline event-handler attributes in the rendered document
    - 0 inline <script> without a nonce
    - the page's inline scripts actually executed (a known global fn exists)

  Seeds ephemeral admin/PH/teacher users and deletes them afterwards.

  Run:  pwsh -File scripts/csp_browser_test.ps1 [-Base http://localhost/o]
  Exit: 0 = all passed (or browser unavailable), 1 = one or more failures.
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
$ts  = Get-Date -Format 'yyyyMMddHHmmss'
$pw  = 'Csp#Browser-2026'
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_cspbr_$ts"
New-Item -ItemType Directory -Path $work -Force | Out-Null

# scripts/ is HTTP-denied, so stage the harnesses at a temporary serveable root path.
$e2eName = "_csp_e2e_$ts.html"
$evtName = "_csp_evt_$ts.html"
$e2ePath = Join-Path $repoRoot $e2eName
$evtPath = Join-Path $repoRoot $evtName

$script:results = [System.Collections.Generic.List[object]]::new()
function Check([string]$Name, [bool]$Ok, [string]$Detail = '') {
    $tagRes = if ($Ok) { 'PASS' } else { 'FAIL' }
    $script:results.Add([pscustomobject]@{ Test = $Name; Result = $tagRes; Detail = $Detail })
    Write-Host ("[{0}] {1}  {2}" -f $tagRes, $Name, $Detail)
}

function Find-Browser {
    foreach ($p in @(
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe')) {
        if (Test-Path $p) { return $p }
    }
    return $null
}

function Invoke-Browser {
    param([string]$Browser, [string]$Url, [string]$Tag)
    $profile = Join-Path $work ("prof_$Tag")
    $outF    = Join-Path $work ("dom_$Tag.html")
    $errF    = Join-Path $work ("dom_$Tag.err")
    $p = Start-Process -FilePath $Browser -ArgumentList @(
        '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
        '--no-default-browser-check', "--user-data-dir=$profile",
        '--virtual-time-budget=15000', '--dump-dom', $Url
    ) -RedirectStandardOutput $outF -RedirectStandardError $errF -PassThru
    if (-not $p.WaitForExit(60000)) { try { $p.Kill() } catch {} }
    $dom = Get-Content $outF -Raw -ErrorAction SilentlyContinue
    if ($dom -match 'RESULT_START(.*?)RESULT_END') { return $Matches[1] }
    return $null
}

$dbUser = 'ched_app'; $dbPass = ''
foreach ($line in Get-Content (Join-Path $repoRoot '.env')) {
    if ($line -match '^\s*DB_USER\s*=\s*(.+)$') { $dbUser = $Matches[1].Trim() }
    if ($line -match '^\s*DB_PASS\s*=\s*(.+)$') { $dbPass = $Matches[1].Trim() }
}
$dbArgs = @('-u', $dbUser, "-p$dbPass", '-h', '127.0.0.1', '-N', '-B', 'ched_compliance')
function Invoke-Sql([string]$sql) { $sql | & $Mysql @dbArgs 2>&1 }

$browser = Find-Browser
if (-not $browser) {
    Write-Host '[SKIP] no Chromium browser found; browser-level checks skipped'
    exit 0
}

$adminEmail = "cspbr_admin_${ts}@example.test"
$phEmail    = "cspbr_ph_${ts}@example.test"
$teachEmail = "cspbr_teach_${ts}@example.test"
$hash = (& $Php -r "echo password_hash('$pw', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()

$roles = @(
    @{ who = 'admin'; email = $adminEmail; pages = @(
        'admin_dashboard.php', 'admin_analytics.php::switchChartType',
        'admin_department_requirements.php::openFolderModal',
        'admin_manage_departments.php::closeDeptSuccessToast',
        'admin_manage_document.php::closeDocSuccessToast',
        'admin_manage_users.php::closeUserSuccessToast',
        'admin_submissions.php::closeSubSuccessToast',
        'admin_archive_documents.php::closeArchSuccessToast',
        'admin_audit_trail.php', 'admin_report.php', 'admin_settings.php',
        'notifications.php') },
    @{ who = 'ph'; email = $phEmail; pages = @(
        'program_head_dashboard.php::openReqModal',
        'program_head_requirements.php::selectPath',
        'program_head_repository.php::openUploadModal',
        'program_head_create_assign.php::toggleEdit',
        'program_head_assign.php', 'program_head_history.php',
        'program_head_teachers.php::showDeleteModal',
        'program_head_pending.php') },
    @{ who = 'teacher'; email = $teachEmail; pages = @(
        'teacher_dashboard.php', 'teacher_tasks.php::showToast',
        'teacher_submissions.php', 'notifications.php') }
)

try {
    Copy-Item (Join-Path $PSScriptRoot 'csp_browser_e2e.html') $e2ePath -Force
    Copy-Item (Join-Path $PSScriptRoot 'csp_events_test.html')   $evtPath -Force
    Invoke-Sql 'DELETE FROM login_attempts;' | Out-Null
    Invoke-Sql @"
INSERT INTO users (name,email,password,role,department_id) VALUES
 ('CSPB Admin','$adminEmail','$hash','admin',NULL),
 ('CSPB PH','$phEmail','$hash','department',$DeptId),
 ('CSPB Teacher','$teachEmail','$hash','teacher',$DeptId);
"@ | Out-Null

    foreach ($role in $roles) {
        $pagesParam = [uri]::EscapeDataString(($role.pages -join ','))
        $url = "$Base/$e2eName?base=$([uri]::EscapeDataString($Base))&e=$([uri]::EscapeDataString($role.email))&p=$([uri]::EscapeDataString($pw))&pages=$pagesParam"
        $json = Invoke-Browser -Browser $browser -Url $url -Tag $role.who
        if (-not $json) { Check "$($roles.who) browser run" $false 'no RESULT (browser failed to render)'; continue }
        $rows = $json | ConvertFrom-Json
        foreach ($r in $rows) {
            if ($r.PSObject.Properties.Name -contains 'loginInfo') {
                Check "$($role.who) login" (($r.loginInfo -like '*status=200*') -or ($r.loginInfo -like '*status=302*')) "$($r.loginInfo)"
                continue
            }
            if ($r.login) { Check "$($role.who) login" $false "$($r.login)"; continue }
            $ok = ($r.badScripts -eq 0) -and ($r.handlers -eq 0) -and (-not $r.error)
            $extra = if ($r.error) { " err=$($r.error)" } else { '' }
            Check "$($role.who) $($r.page) browser-rendered" $ok "handlers=$($r.handlers) badScripts=$($r.badScripts) ready=$($r.ready) path=$($r.path)$extra"
            $fnProp = $r.PSObject.Properties | Where-Object { $_.Name -like 'fn:*' }
            if ($fnProp) {
                $okFn = ($fnProp.Value -eq 'function')
                Check "$($role.who) $($r.page) inline JS ran ($($fnProp.Name))" $okFn "typeof=$($fnProp.Value)"
            }
        }
    }

    # Delegation-primitive regression (authless): proves csp-events.js click/change dispatch.
    $prim = Invoke-Browser -Browser $browser -Url "$Base/$evtName" -Tag 'primitives'
    if (-not $prim) {
        Check 'csp-events primitives render' $false 'no RESULT'
    }
    else {
        $p = $prim | ConvertFrom-Json
        Check 'csp-events action args'        ($p.runs -contains 'fn:a,2')                         ($p.runs -join ' | ')
        Check 'csp-events action element'     ($p.runs -contains 'fn:EL:BUTTON')                    ''
        Check 'csp-events action event'       ($p.runs -contains 'fn:EV:click')                     ''
        Check 'csp-events stop propagation'   (-not ($p.runs -contains 'outer'))                    ''
        Check 'csp-events href navigation'    ($p.hashAfterLink -eq '#ok')                          $p.hashAfterLink
        Check 'csp-events href skips button'  ($p.hashUnchangedOnInnerButton -eq $true)             ''
        Check 'csp-events nav + encoding'     ($p.hashAfterNav -eq '#nav=a%20b')                    $p.hashAfterNav
        Check 'csp-events submit-on-change'   ($p.submitted -eq $true)                              ''
    }
}
finally {
    Invoke-Sql "DELETE FROM users WHERE email IN ('$adminEmail','$phEmail','$teachEmail');" | Out-Null
    Remove-Item -Force $e2ePath, $evtPath -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}

$fail = @($script:results | Where-Object { $_.Result -eq 'FAIL' })
Write-Host ""
Write-Host ("{0} checks, {1} failed" -f $script:results.Count, $fail.Count)
if ($fail.Count -gt 0) {
    $fail | Format-Table -AutoSize | Out-String | Write-Host
    exit 1
}
exit 0
