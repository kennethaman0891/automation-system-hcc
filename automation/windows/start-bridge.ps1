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

Write-SupervisorLog "Starting HCC WhatsApp Bridge supervisor on Windows"
Write-SupervisorLog "Repo: $RepoRoot"
Write-SupervisorLog "Node: $($nodeCmd.Source)"
Write-SupervisorLog "Logs: $LogFile"

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
