#requires -Version 7
<#
  CHED Compliance — single-active-session test (HTTP, curl-based).

  Verifies the server-side "one active session per account" guard
  (users.active_session_token / active_session_last_seen; seeded by
  migrations/2026_single_active_session.sql):

    A. First login succeeds and the dashboard is reachable.
    B. A second login (different cookie jar = different browser/device) is
       DENIED with the "already logged in" message while the first is fresh.
    C. The denied attempt does NOT displace the first session.
    D. A wrong password while the account is active returns the generic
       failure (no account-state leak), never the duplicate message.
    E. Logout releases the claim, so the other browser can then log in.
    F. A token idle past the TTL (300s) is abandoned and CAN be taken over;
       the older session is then killed server-side -> login.php?session=1.
    G. The displaced session's AJAX request gets a 401 JSON, not HTML.
    H. A session killed by account lockout is redirected -> login.php?locked=1
       and its token claim is cleared.

  Seeds one ephemeral teacher, then removes it. No JS is involved; every
  assertion is enforced by PHP/MySQL.
  Run:  pwsh -File scripts/single_session_test.ps1
#>
[CmdletBinding()]
param(
    [string]$Base  = 'http://localhost/o',
    [string]$Php   = 'C:\xampp\php\php.exe',
    [string]$Mysql = 'C:\xampp\mysql\bin\mysql.exe',
    [int]   $Ttl   = 300
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$ts   = Get-Date -Format 'yyyyMMddHHmmss'
$tag  = "SAS_$ts"
$pw   = 'Single#Session-2026'
$email = "sas_${ts}@example.test"
$work = Join-Path ([System.IO.Path]::GetTempPath()) "ched_sas_$ts"
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
# Logs in and returns the redirect Location (or the page itself on denial).
function Login([string]$Pw, [string]$Jar) {
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar
    $c = Get-Csrf $r.Body
    $r = Invoke-Curl "$Base/login.php" -Jar $Jar -Extra @(
        '-X', 'POST', '--data-urlencode', "csrf_token=$c",
        '--data-urlencode', "email=$email", '--data-urlencode', "password=$Pw"
    )
    [pscustomobject]@{ Location = (Get-Loc $r.Headers); Body = $r.Body }
}

$hash = (& $Php -r "echo password_hash('${pw}', PASSWORD_DEFAULT);" 2>&1 | Out-String).Trim()
if (-not $hash) { throw 'password_hash failed' }

$jarA = Join-Path $work 'a.jar'
$jarB = Join-Path $work 'b.jar'

try {
    Invoke-Sql "INSERT INTO users (name,email,password,role) VALUES ('SAS Test','$email','$hash','teacher');" | Out-Null

    # A. first login (browser A) -> dashboard
    $a = Login $pw $jarA
    Check 'A first login reaches dashboard' ($a.Location -match 'teacher_dashboard') $a.Location

    # B. second login (browser B) denied with the duplicate message
    $b = Login $pw $jarB
    Check 'B second login denied (duplicate)' ($b.Body -match 'already logged in on another browser or device')

    # C. denied attempt left browser A's session valid
    $ra = Invoke-Curl "$Base/teacher_dashboard.php" -Jar $jarA
    Check 'C first session survives the denial' ($ra.Code -eq 200)

    # D. wrong password -> generic failure, no duplicate leak
    $w = Login 'WrongPass_zzz' $jarB
    Check 'D wrong password stays generic' ($w.Body -match 'Invalid email or password' -and $w.Body -notmatch 'already logged in on another browser')

    # E. logout releases the claim -> browser B can log in
    $lg = Invoke-Curl "$Base/logout.php" -Jar $jarA
    $c2 = Get-Csrf $lg.Body
    Invoke-Curl "$Base/logout.php" -Jar $jarA -Extra @('-X', 'POST', '--data-urlencode', "csrf_token=$c2") | Out-Null
    $b2 = Login $pw $jarB
    Check 'E logout frees the account' ($b2.Location -match 'teacher_dashboard') $b2.Location

    # F. stale token (idle > TTL) can be taken over; older session is killed
    Invoke-Sql "UPDATE users SET active_session_last_seen = UNIX_TIMESTAMP() - ($Ttl + 100) WHERE email='$email';" | Out-Null
    $a3 = Login $pw $jarA
    Check 'F stale token takeover allowed' ($a3.Location -match 'teacher_dashboard') $a3.Location
    $killed = Invoke-Curl "$Base/teacher_dashboard.php" -Jar $jarB
    Check 'F displaced session redirected to ?session=1' ((Get-Loc $killed.Headers) -match 'login\.php\?session=1') (Get-Loc $killed.Headers)

    # G. displaced session's AJAX request -> 401 JSON
    #    (re-create the displacement: B logs in again, then A is stale)
    Invoke-Sql "UPDATE users SET active_session_last_seen = NULL WHERE email='$email';" | Out-Null
    Login $pw $jarA | Out-Null
    Invoke-Sql "UPDATE users SET active_session_last_seen = UNIX_TIMESTAMP() - ($Ttl + 100) WHERE email='$email';" | Out-Null
    Login $pw $jarB | Out-Null
    $ajax = Invoke-Curl "$Base/teacher_dashboard.php" -Jar $jarA -Extra @('-H', 'X-Requested-With: XMLHttpRequest')
    Check 'G displaced AJAX -> 401 JSON' ($ajax.Code -eq 401 -and $ajax.Body -match '"success":false') "HTTP $($ajax.Code)"

    # H. lockout kill -> ?locked=1 and the token claim is cleared
    Invoke-Sql "UPDATE users SET active_session_token=NULL, active_session_last_seen=NULL, locked_until=NULL WHERE email='$email';" | Out-Null
    $h = Login $pw $jarA
    Invoke-Sql "UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 600 SECOND) WHERE email='$email';" | Out-Null
    $hk = Invoke-Curl "$Base/teacher_dashboard.php" -Jar $jarA
    Check 'H lockout kill -> ?locked=1' ((Get-Loc $hk.Headers) -match 'login\.php\?locked=1') (Get-Loc $hk.Headers)
    $claim = Scalar "SELECT active_session_token IS NULL FROM users WHERE email='$email';"
    Check 'H lockout kill clears the token claim' ($claim -eq '1')

    # I. audit trail recorded the deny + the kills
    $deny = Scalar "SELECT COUNT(*) FROM audit_trail WHERE action='login_denied_active_session' AND description LIKE '%$email%';"
    $kill = Scalar "SELECT COUNT(*) FROM audit_trail WHERE action IN ('session_killed_duplicate','session_killed_lockout') AND created_at > NOW() - INTERVAL 10 MINUTE;"
    Check 'I audit: login denied recorded' ([int]$deny -ge 1) "rows=$deny"
    Check 'I audit: session kill recorded'  ([int]$kill -ge 1) "rows=$kill"
} finally {
    try {
        Invoke-Sql "DELETE FROM login_attempts WHERE email='$email'; DELETE FROM audit_trail WHERE description LIKE '%$email%'; DELETE FROM users WHERE email='$email';" | Out-Null
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