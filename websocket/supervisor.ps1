# Keeps websocket/server.php alive for local XAMPP.
# Started at logon by the CHED-WebSocket scheduled task (scripts/install_websocket_task.ps1).
$ErrorActionPreference = 'SilentlyContinue'

$root = Split-Path -Parent $PSScriptRoot
$log  = Join-Path $root 'logs\websocket.log'

# One supervisor only; a stray second copy would fight over port 8080.
$mutex = New-Object System.Threading.Mutex($false, 'Local\CHEDWebSocketSupervisor')
if (-not $mutex.WaitOne(0)) { exit 0 }

$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) { $php = (Get-Command php.exe).Source }
if (-not $php) {
    Add-Content $log ("[{0}] php.exe not found; cannot start WebSocket server" -f (Get-Date -Format s))
    exit 1
}

while ($true) {
    Add-Content $log ("[{0}] starting websocket/server.php" -f (Get-Date -Format s))
    # Write per line so the log is never held open while the server runs.
    & $php (Join-Path $PSScriptRoot 'server.php') 2>&1 | ForEach-Object { Add-Content -Path $log -Value $_ }
    Add-Content $log ("[{0}] server exited; restarting in 5s" -f (Get-Date -Format s))
    Start-Sleep -Seconds 5
}
