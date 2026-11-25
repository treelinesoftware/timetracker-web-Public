# Time Tracker installer for Windows. Run in PowerShell as your normal user (no admin needed):
#   iwr -UseBasicParsing <server>/downloads/install.ps1 -OutFile $env:TEMP\tt-install.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File $env:TEMP\tt-install.ps1 -Server <server> -Token <token>
# Uninstall:
#   powershell -NoProfile -ExecutionPolicy Bypass -File $env:TEMP\tt-install.ps1 -Uninstall
param(
    [string]$Server,
    [string]$Token,
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'
$AppDir = Join-Path $env:LOCALAPPDATA 'TimeTracker'
$Exe = Join-Path $AppDir 'timetracker.exe'
$Shortcut = Join-Path ([Environment]::GetFolderPath('Startup')) 'Time Tracker.lnk'

function Stop-Tracker {
    $running = Get-Process -Name 'timetracker' -ErrorAction SilentlyContinue
    if ($running) {
        # No console to send Ctrl+C to, so stop it directly; at most one interval of activity is lost
        $running | Stop-Process -Force
        Start-Sleep -Seconds 1
    }
}

if ($Uninstall) {
    Stop-Tracker
    Remove-Item $Shortcut -ErrorAction SilentlyContinue
    Remove-Item $AppDir -Recurse -Force -ErrorAction SilentlyContinue
    Write-Host 'Time Tracker removed.'
    exit 0
}

if (-not $Server -or -not $Token) {
    Write-Error 'Usage: install.ps1 -Server http://host/timetracker -Token TOKEN'
    exit 1
}
$Server = $Server.TrimEnd('/')

$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } else { 'amd64' }

Write-Host "==> Downloading tracker ($arch)"
New-Item -ItemType Directory -Force -Path $AppDir | Out-Null
Stop-Tracker
$tmp = "$Exe.new"
Invoke-WebRequest -UseBasicParsing -Uri "$Server/downloads/timetracker-windows-$arch.exe" -OutFile $tmp
Move-Item -Force $tmp $Exe

Write-Host '==> Writing configuration'
# WriteAllLines writes UTF-8 without a byte-order mark
[IO.File]::WriteAllLines((Join-Path $AppDir '.env'), [string[]]@(
    "WEBHOOK_URL=$Server/api/ingest.php",
    "WEBHOOK_TOKEN=$Token"
))

Write-Host '==> Setting up autostart'
$trackerArgs = '-track -tray -submission-interval 5m -logfile "' + (Join-Path $AppDir 'tracker.log') + '"'
$shell = New-Object -ComObject WScript.Shell
$lnk = $shell.CreateShortcut($Shortcut)
$lnk.TargetPath = $Exe
$lnk.Arguments = $trackerArgs
$lnk.WorkingDirectory = $AppDir
$lnk.Description = 'Time Tracker activity tracker'
$lnk.Save()

Write-Host '==> Testing connection to the server'
try {
    $resp = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$Server/api/ingest.php" `
        -Headers @{ 'X-Tracker-Token' = $Token } -Body '{"sessions":[]}' -ContentType 'application/json'
    Write-Host "    server accepted the token"
} catch {
    Write-Warning "Server check failed: $($_.Exception.Message). Check the server address and token."
}

Write-Host '==> Starting Time Tracker'
Start-Process -FilePath $Exe -ArgumentList $trackerArgs -WorkingDirectory $AppDir
Start-Sleep -Seconds 3
if (Get-Process -Name 'timetracker' -ErrorAction SilentlyContinue) {
    Write-Host "==> Done. Time Tracker is running as $env:COMPUTERNAME and starts when you sign in."
    Write-Host "    Look for the clock icon in the notification area (you may need to click ^)."
    Write-Host "    Log: $AppDir\tracker.log"
} else {
    Write-Warning "Installed, but the tracker is not running. See $AppDir\tracker.log"
    exit 1
}
