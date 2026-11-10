# Starts the local WebSocket server at logon and restarts it after a crash.
# Uses a Scheduled Task when run elevated, otherwise the per-user Startup folder
# (no admin rights needed). Run once:
#   powershell -ExecutionPolicy Bypass -File scripts\install_websocket_task.ps1
# Remove with:
#   powershell -ExecutionPolicy Bypass -File scripts\install_websocket_task.ps1 -Remove
param([switch]$Remove)

$name = 'CHED-WebSocket'
$root = Split-Path -Parent $PSScriptRoot
$startupVbs = Join-Path ([Environment]::GetFolderPath('Startup')) "$name.vbs"
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if ($Remove) {
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
    Remove-Item $startupVbs -ErrorAction SilentlyContinue
    Write-Host "Removed autostart for $name"
    exit 0
}

# Preferred: a real scheduled task (also restarts the supervisor itself on failure).
if ($isAdmin) {
    try {
        $action = New-ScheduledTaskAction `
            -Execute 'powershell.exe' `
            -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $root 'websocket\supervisor.ps1')) `
            -WorkingDirectory $root
        $trigger = New-ScheduledTaskTrigger -AtLogOn
        $settings = New-ScheduledTaskSettingsSet `
            -AllowStartIfOnBatteries `
            -DontStopIfGoingOnBatteries `
            -MultipleInstances IgnoreNew `
            -RestartCount 3 `
            -RestartInterval (New-TimeSpan -Minutes 1) `
            -ExecutionTimeLimit ([TimeSpan]::Zero)

        Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Settings $settings -Force -ErrorAction Stop | Out-Null
        Remove-Item $startupVbs -ErrorAction SilentlyContinue
        Start-ScheduledTask -TaskName $name
        Write-Host "Installed and started scheduled task $name"
        exit 0
    } catch {
        Write-Host "Scheduled task failed ($($_.Exception.Message)); using Startup folder instead."
    }
}

# Fallback: hidden launcher in the current user's Startup folder.
$supervisor = Join-Path $root 'websocket\supervisor.ps1'
$vbs = "CreateObject(`"WScript.Shell`").Run `"powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"`"$supervisor`"`"`", 0, False"
Set-Content -Path $startupVbs -Value $vbs -Encoding ASCII
Start-Process 'wscript.exe' -ArgumentList "`"$startupVbs`""
Write-Host "Installed Startup launcher: $startupVbs"
