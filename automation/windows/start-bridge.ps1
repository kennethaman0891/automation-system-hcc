# Start and supervise the HCC WhatsApp Bridge (Node.js + Puppeteer).
# Automatically restarts if the process crashes, prevents concurrent profile access,
# and cleans stale Chrome lockfiles before restart.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File automation\windows\start-bridge.ps1
#
# Requires -Version 5.1

$ErrorActionPreference = "Stop"

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$AutomationDir = Join-Path $RepoRoot "automation"
Set-Location $AutomationDir

$LogDir = Join-Path $RepoRoot "logs"
if (-not (Test-Path -LiteralPath $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir | Out-Null
}
$LogFile = Join-Path $LogDir "whatsapp-bridge.log"
$SessionDir = Join-Path $AutomationDir ".whatsapp-session"

function Write-SupervisorLog {
    param([string]$Message)
    $ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $entry = "[$ts] [supervisor] $Message"
    Write-Host $entry
    try {
        Add-Content -Path $LogFile -Value $entry -ErrorAction SilentlyContinue
    } catch {}
}

# One supervisor per logon session. A second double-click leaves the first one running.
# Keep this object alive for the whole script so the mutex is not released early.
$SupervisorMutex = New-Object System.Threading.Mutex($false, "Local\HCCWhatsAppBridgeSupervisor")
$supervisorOwned = $false
try {
    $supervisorOwned = $SupervisorMutex.WaitOne(0, $false)
} catch {
    $abandoned = $_.Exception
    if ($abandoned -is [System.Threading.AbandonedMutexException] -or $abandoned.InnerException -is [System.Threading.AbandonedMutexException]) {
        $supervisorOwned = $true
    } else {
        throw
    }
}
if (-not $supervisorOwned) {
    Write-SupervisorLog "Another WhatsApp bridge supervisor is already running. Leaving it in place."
    exit 0
}

# 1. Verify Node.js is installed
$nodeCmd = Get-Command node -ErrorAction SilentlyContinue
if (-not $nodeCmd) {
    Write-SupervisorLog "ERROR: Node.js was not found on PATH. Install Node.js 18+ from https://nodejs.org/"
    exit 1
}

# 2. Check dependencies
$nodeModules = Join-Path $AutomationDir "node_modules"
if (-not (Test-Path -LiteralPath $nodeModules)) {
    Write-SupervisorLog "node_modules not found in $AutomationDir. Running npm install..."
    Push-Location $AutomationDir
    try {
        & npm install
        if ($LASTEXITCODE -ne 0) {
            Write-SupervisorLog "ERROR: npm install failed with exit code $LASTEXITCODE."
            exit 1
        }
    } finally {
        Pop-Location
    }
}

function Clear-StaleProfileLocks {
    $lockNames = @("SingletonLock", "SingletonSocket", "SingletonCookie", "lockfile")
    foreach ($f in $lockNames) {
        $lockPath = Join-Path $SessionDir $f
        if (Test-Path -LiteralPath $lockPath) {
            try {
                Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
                Write-SupervisorLog "Removed stale lock: $f"
            } catch {}
        }
    }
}

function Get-RunningBridgeProcesses {
    try {
        return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
            $_.CommandLine -and $_.CommandLine -like "*whatsapp-bridge.js*" -and $_.ProcessId -ne $PID
        })
    } catch {
        return @()
    }
}

$IpcDir = Join-Path $RepoRoot "storage\whatsapp\ipc"
if (-not (Test-Path -LiteralPath $IpcDir)) {
    New-Item -ItemType Directory -Path $IpcDir | Out-Null
}
# PHP waBridgeIpcDir() uses this same folder on Windows. Set it before Node starts
# so Apache (often a different user) and this process watch the same flag files.
$env:WHATSAPP_IPC_DIR = $IpcDir

Write-SupervisorLog "Starting HCC WhatsApp Bridge supervisor on Windows"
Write-SupervisorLog "Repo: $RepoRoot"
Write-SupervisorLog "Node: $($nodeCmd.Source)"
Write-SupervisorLog "Logs: $LogFile"
Write-SupervisorLog "IPC: $IpcDir"

# A node_modules folder copied from macOS can fail to load. Reinstall on this PC.
Push-Location $AutomationDir
try {
    & node -e "require('puppeteer'); require('axios'); require('ws'); require('dotenv');"
    if ($LASTEXITCODE -ne 0) {
        Write-SupervisorLog "Node modules failed to load. Running npm install for this Windows PC..."
        & npm install
        if ($LASTEXITCODE -ne 0) {
            Write-SupervisorLog "ERROR: npm install failed with exit code $LASTEXITCODE."
            exit 1
        }
    }
} finally {
    Pop-Location
}

# Clean locks once on boot
Clear-StaleProfileLocks

$scriptPath = Join-Path $AutomationDir "whatsapp-bridge.js"

while ($true) {
    $existing = Get-RunningBridgeProcesses
    if ($existing.Count -gt 0) {
        $pids = ($existing | ForEach-Object { $_.ProcessId }) -join ", "
        Write-SupervisorLog "Another WhatsApp bridge is running (PID: $pids). Waiting 10s..."
        Start-Sleep -Seconds 10
        continue
    }

    Write-SupervisorLog "Starting node whatsapp-bridge.js"

    $processInfo = New-Object System.Diagnostics.ProcessStartInfo
    $processInfo.FileName = $nodeCmd.Source
    $processInfo.Arguments = "`"$scriptPath`""
    $processInfo.WorkingDirectory = $AutomationDir
    $processInfo.RedirectStandardOutput = $false
    $processInfo.RedirectStandardError = $false
    $processInfo.UseShellExecute = $false
    # Copies the current environment, then pins the shared IPC directory.
    $processInfo.EnvironmentVariables["WHATSAPP_IPC_DIR"] = $IpcDir

    try {
        $proc = [System.Diagnostics.Process]::Start($processInfo)
        $proc.WaitForExit()
        $exitCode = $proc.ExitCode
    } catch {
        Write-SupervisorLog "Failed to launch process: $_"
        $exitCode = -1
    }

    Write-SupervisorLog "Bridge exited with code $exitCode — cleaning locks and restarting in 10s"
    Clear-StaleProfileLocks
    Start-Sleep -Seconds 10
}
