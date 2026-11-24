# Start hcc-automation-engine --daemon.
# Database settings come from the process environment. DB_PASS is required.
# The password is not written here and is not passed on the command line.
#Requires -Version 5.1

$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "hcc-pe.ps1")

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location $RepoRoot

if ([string]::IsNullOrWhiteSpace($env:DB_PASS)) {
    Write-Host "DB_PASS is not set. Set it in the environment to the same value as config\env.php, then run this script again."
    Write-Host "This script does not contain a database password."
    exit 1
}

if ([string]::IsNullOrWhiteSpace($env:DB_HOST)) { $env:DB_HOST = "127.0.0.1" }
if ([string]::IsNullOrWhiteSpace($env:DB_PORT)) { $env:DB_PORT = "3306" }
if ([string]::IsNullOrWhiteSpace($env:DB_USER)) { $env:DB_USER = "hcc_app" }
if ([string]::IsNullOrWhiteSpace($env:DB_NAME)) { $env:DB_NAME = "hcc_admin" }

if (Test-Path Env:DB_SOCKET) {
    Remove-Item Env:DB_SOCKET
    Write-Host "DB_SOCKET removed for this process. Windows uses MySQL TCP."
}

$port = 0
if (-not [int]::TryParse($env:DB_PORT, [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
    Write-Host "DB_PORT is not a valid TCP port."
    exit 1
}

$candidates = @(
    (Join-Path $RepoRoot "automation\rust_engine\target\release\hcc-automation-engine.exe"),
    (Join-Path $RepoRoot "automation\rust_engine\target\release\hcc-automation-engine"),
    (Join-Path $RepoRoot "automation\bin\hcc-automation-engine.exe"),
    (Join-Path $RepoRoot "automation\bin\hcc-automation-engine")
)
$binary = $null
foreach ($cand in $candidates) {
    if (Test-HccWindowsExecutable $cand) {
        $binary = $cand
        break
    }
}
if (-not $binary) {
    Write-Host "No Windows build of hcc-automation-engine was found."
    Write-Host "A file copied from macOS is a Mach-O binary and will not run here."
    Write-Host "Build it on this Windows PC:"
    Write-Host "  cd automation\rust_engine"
    Write-Host "  cargo build --release"
    exit 1
}

Write-Host "Repo: $RepoRoot"
Write-Host "Binary: $binary"
Write-Host "MySQL: $($env:DB_USER)@$($env:DB_HOST):$($env:DB_PORT)/$($env:DB_NAME)"
Write-Host "Starting hcc-automation-engine --daemon"
Write-Host "Startup recovery moves automation_jobs left in processing back to pending."

& $binary --daemon
exit $LASTEXITCODE
