# Start the local admission AI service (127.0.0.1:8085).
# Reads AI_* and OLLAMA_HOST from the process environment. Does not store secrets.
#Requires -Version 5.1

$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "hcc-pe.ps1")

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location $RepoRoot

function Set-DefaultEnv {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Value
    )
    $current = [Environment]::GetEnvironmentVariable($Name, "Process")
    if ([string]::IsNullOrWhiteSpace($current)) {
        Set-Item -Path "Env:$Name" -Value $Value
    } else {
        Set-Item -Path "Env:$Name" -Value $current.Trim()
    }
}

Set-DefaultEnv -Name "AI_SERVICE_HOST" -Value "127.0.0.1"
Set-DefaultEnv -Name "AI_SERVICE_PORT" -Value "8085"
Set-DefaultEnv -Name "OLLAMA_HOST" -Value "http://127.0.0.1:11434"
Set-DefaultEnv -Name "AI_MODEL" -Value "llama3.2:1b"
Set-DefaultEnv -Name "AI_INFERENCE_CONCURRENCY" -Value "1"
Set-DefaultEnv -Name "KNOWLEDGE_DIR" -Value (Join-Path $RepoRoot "knowledge\approved")

if ($env:AI_SERVICE_HOST -eq "localhost") {
    Write-Host "AI_SERVICE_HOST=localhost rewritten to 127.0.0.1."
    $env:AI_SERVICE_HOST = "127.0.0.1"
}

if ($env:AI_SERVICE_HOST -ne "127.0.0.1") {
    Write-Host "AI_SERVICE_HOST must be 127.0.0.1. Refusing to listen on $($env:AI_SERVICE_HOST)."
    exit 1
}

$port = 0
if (-not [int]::TryParse($env:AI_SERVICE_PORT, [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
    Write-Host "AI_SERVICE_PORT is not a valid TCP port."
    exit 1
}

if ($env:AI_INFERENCE_CONCURRENCY -ne "1") {
    Write-Host "AI_INFERENCE_CONCURRENCY must stay 1 on an 8 GB RAM machine. Refusing to start."
    exit 1
}

try {
    $ollama = [Uri]$env:OLLAMA_HOST
    if ($ollama.Host -notin @("127.0.0.1", "localhost", "::1")) {
        Write-Host "OLLAMA_HOST must point at loopback Ollama, not $($ollama.Host)."
        exit 1
    }
} catch {
    Write-Host "OLLAMA_HOST is not a valid URL."
    exit 1
}

if (-not (Test-Path -LiteralPath $env:KNOWLEDGE_DIR)) {
    Write-Host "Knowledge directory not found: $($env:KNOWLEDGE_DIR)"
    Write-Host "The service will start with its built-in fallback until knowledge\approved is present."
}

$candidates = @(
    (Join-Path $RepoRoot "automation\admission-ai-service\target\release\admission-ai-service.exe"),
    (Join-Path $RepoRoot "automation\admission-ai-service\target\release\admission-ai-service")
)
$binary = $null
foreach ($cand in $candidates) {
    if (Test-HccWindowsExecutable $cand) {
        $binary = $cand
        break
    }
}
if (-not $binary) {
    Write-Host "No Windows build of admission-ai-service was found."
    Write-Host "A file copied from macOS is a Mach-O binary and will not run here."
    Write-Host "Build it on this Windows PC:"
    Write-Host "  cd automation\admission-ai-service"
    Write-Host "  cargo build --release"
    exit 1
}

Write-Host "Repo: $RepoRoot"
Write-Host "Binary: $binary"
Write-Host "Model: $($env:AI_MODEL)"
Write-Host "Ollama: $($env:OLLAMA_HOST)"
Write-Host "Concurrency: 1"
Write-Host "Health: http://127.0.0.1:$($env:AI_SERVICE_PORT)/api/health"

& $binary --host $env:AI_SERVICE_HOST --port $env:AI_SERVICE_PORT --model $env:AI_MODEL serve
exit $LASTEXITCODE
