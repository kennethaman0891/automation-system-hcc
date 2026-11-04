# Register the two HCC Rust workers as Windows services via NSSM, if nssm.exe is already installed.
# Does not download NSSM. If NSSM is absent, prints schtasks commands and does not create the tasks.
# Safe to re-run: an existing service is updated in place.
#Requires -Version 5.1

param(
    [string]$RepoRoot = "",
    [string]$NssmPath = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($RepoRoot)) {
    $RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
} else {
    $RepoRoot = (Resolve-Path -LiteralPath $RepoRoot).Path
}

$AiScript = Join-Path $RepoRoot "automation\windows\start-admission-ai.ps1"
$EngineScript = Join-Path $RepoRoot "automation\windows\start-followup-engine.ps1"
$PowerShellExe = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"

foreach ($path in @($AiScript, $EngineScript, $PowerShellExe)) {
    if (-not (Test-Path -LiteralPath $path)) {
        Write-Host "Missing file: $path"
        exit 1
    }
}

function Find-Nssm {
    param([string]$Explicit)
    if (-not [string]::IsNullOrWhiteSpace($Explicit)) {
        if (Test-Path -LiteralPath $Explicit) {
            return (Resolve-Path -LiteralPath $Explicit).Path
        }
        Write-Host "NssmPath was set but the file was not found: $Explicit"
        return $null
    }
    $cmd = Get-Command nssm -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source) {
        return $cmd.Source
    }
    $candidates = @(
        (Join-Path $env:ProgramFiles "nssm\win64\nssm.exe"),
        (Join-Path $env:ProgramFiles "nssm\nssm.exe")
    )
    if (-not [string]::IsNullOrWhiteSpace(${env:ProgramFiles(x86)})) {
        $candidates += (Join-Path ${env:ProgramFiles(x86)} "nssm\win64\nssm.exe")
    }
    if (-not [string]::IsNullOrWhiteSpace($env:ChocolateyInstall)) {
        $candidates += (Join-Path $env:ChocolateyInstall "bin\nssm.exe")
    }
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }
    return $null
}

function Test-EnvValueForNssm {
    param(
        [string]$Name,
        [string]$Value
    )
    if ($Value -match '[\s"]') {
        Write-Host "$Name contains a space or quote. NSSM's environment setter splits on spaces, so this value was not passed."
        Write-Host "Set $Name in the Windows system environment instead. The value was not printed."
        return $false
    }
    return $true
}

function Get-FollowupEnvPairs {
    $map = [ordered]@{
        DB_HOST = $(if ([string]::IsNullOrWhiteSpace($env:DB_HOST)) { "127.0.0.1" } else { $env:DB_HOST.Trim() })
        DB_PORT = $(if ([string]::IsNullOrWhiteSpace($env:DB_PORT)) { "3306" } else { $env:DB_PORT.Trim() })
        DB_USER = $(if ([string]::IsNullOrWhiteSpace($env:DB_USER)) { "hcc_app" } else { $env:DB_USER.Trim() })
        DB_NAME = $(if ([string]::IsNullOrWhiteSpace($env:DB_NAME)) { "hcc_admin" } else { $env:DB_NAME.Trim() })
    }
    if (-not [string]::IsNullOrWhiteSpace($env:DB_PASS)) {
        $map["DB_PASS"] = $env:DB_PASS
    }
    $pairs = New-Object System.Collections.Generic.List[string]
    foreach ($key in $map.Keys) {
        if (-not (Test-EnvValueForNssm -Name $key -Value ([string]$map[$key]))) {
            return $null
        }
        $pairs.Add("$key=$($map[$key])")
    }
    return ,$pairs.ToArray()
}

function Get-AiEnvPairs {
    $knowledge = $env:KNOWLEDGE_DIR
    if ([string]::IsNullOrWhiteSpace($knowledge)) {
        $knowledge = Join-Path $RepoRoot "knowledge\approved"
    }
    $concurrency = $env:AI_INFERENCE_CONCURRENCY
    if ([string]::IsNullOrWhiteSpace($concurrency)) {
        $concurrency = "1"
    }
    if ($concurrency.Trim() -ne "1") {
        Write-Host "AI_INFERENCE_CONCURRENCY is not 1. The service will be registered with 1."
    }
    $hostName = $env:AI_SERVICE_HOST
    if ([string]::IsNullOrWhiteSpace($hostName) -or $hostName.Trim() -eq "localhost") {
        $hostName = "127.0.0.1"
    }
    $map = [ordered]@{
        AI_SERVICE_HOST            = $hostName.Trim()
        AI_SERVICE_PORT            = $(if ([string]::IsNullOrWhiteSpace($env:AI_SERVICE_PORT)) { "8085" } else { $env:AI_SERVICE_PORT.Trim() })
        OLLAMA_HOST                = $(if ([string]::IsNullOrWhiteSpace($env:OLLAMA_HOST)) { "http://127.0.0.1:11434" } else { $env:OLLAMA_HOST.Trim() })
        AI_MODEL                   = $(if ([string]::IsNullOrWhiteSpace($env:AI_MODEL)) { "llama3.2:1b" } else { $env:AI_MODEL.Trim() })
        AI_INFERENCE_CONCURRENCY   = "1"
        KNOWLEDGE_DIR              = $knowledge.Trim()
    }
    $pairs = New-Object System.Collections.Generic.List[string]
    foreach ($key in $map.Keys) {
        if (-not (Test-EnvValueForNssm -Name $key -Value ([string]$map[$key]))) {
            return $null
        }
        $pairs.Add("$key=$($map[$key])")
    }
    return ,$pairs.ToArray()
}

function Show-ScheduledTaskCommands {
    Write-Host ""
    Write-Host "NSSM was not found. This script does not download it."
    Write-Host "From an elevated command prompt you can create two startup tasks. /F makes each command safe to re-run."
    Write-Host "Set DB_HOST, DB_PORT, DB_USER, DB_PASS, and DB_NAME as machine environment variables first."
    Write-Host "Do not put the database password in the task command. Leave DB_SOCKET unset."
    Write-Host "ONSTART tasks run as SYSTEM and do not see your user variables. Reboot after setting the machine variables."
    Write-Host "Start MySQL and Ollama before these tasks. These commands do not start XAMPP."
    Write-Host ""
    if ($AiScript -match '\s' -or $EngineScript -match '\s') {
        Write-Host "The repo path contains spaces. Create the tasks in Task Scheduler instead of schtasks."
        Write-Host "Program: $PowerShellExe"
        Write-Host "Start in: $RepoRoot"
        Write-Host "Admission AI arguments: -NoProfile -ExecutionPolicy Bypass -File `"$AiScript`""
        Write-Host "Follow-up arguments: -NoProfile -ExecutionPolicy Bypass -File `"$EngineScript`""
        return
    }
    $aiTr = "$PowerShellExe -NoProfile -ExecutionPolicy Bypass -File $AiScript"
    $engineTr = "$PowerShellExe -NoProfile -ExecutionPolicy Bypass -File $EngineScript"
    Write-Host "schtasks /Create /TN `"HCC Admission AI`" /SC ONSTART /RU SYSTEM /RL HIGHEST /F /TR `"$aiTr`""
    Write-Host ""
    Write-Host "schtasks /Create /TN `"HCC Follow-up Engine`" /SC ONSTART /RU SYSTEM /RL HIGHEST /F /TR `"$engineTr`""
    Write-Host ""
    Write-Host "Start in, if you create the tasks in the GUI instead: $RepoRoot"
}

function Invoke-Nssm {
    param(
        [string]$Nssm,
        [string[]]$NssmArgs,
        [switch]$Required
    )
    & $Nssm @NssmArgs
    if ($LASTEXITCODE -ne 0) {
        Write-Host "nssm $($NssmArgs -join ' ') failed with exit $LASTEXITCODE."
        if ($Required) {
            exit $LASTEXITCODE
        }
    }
}

function Register-HccService {
    param(
        [string]$Nssm,
        [string]$Name,
        [string]$DisplayName,
        [string]$ScriptPath,
        [string[]]$EnvPairs,
        [switch]$StartNow,
        [switch]$RestartOnExit
    )
    $appParameters = "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`""
    $logDir = Join-Path $RepoRoot "logs"
    if (-not (Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Path $logDir | Out-Null
    }
    $logFile = Join-Path $logDir "$Name.log"

    $existing = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "Service $Name already exists. Updating it."
        if ($existing.Status -eq "Running") {
            Invoke-Nssm -Nssm $Nssm -NssmArgs @("stop", $Name, "confirm")
        }
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "Application", $PowerShellExe) -Required
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppParameters", $appParameters) -Required
    } else {
        Write-Host "Installing service $Name."
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("install", $Name, $PowerShellExe, $appParameters) -Required
    }

    Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppDirectory", $RepoRoot) -Required
    Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "DisplayName", $DisplayName)
    Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "Description", "HCC School WhatsApp Hub. $DisplayName. Not production-ready until tests/admission_workflow_e2e.php passes on this PC.")
    Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "Start", "SERVICE_AUTO_START") -Required
    Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppStdout", $logFile)
    Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppStderr", $logFile)
    if ($RestartOnExit) {
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppExit", "Default", "Restart")
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppRestartDelay", "5000")
    } else {
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("set", $Name, "AppExit", "Default", "Exit")
    }

    if ($EnvPairs -and $EnvPairs.Count -gt 0) {
        $names = @($EnvPairs | ForEach-Object { ($_ -split "=", 2)[0] })
        Write-Host "Service $Name environment keys: $($names -join ', ')"
        Invoke-Nssm -Nssm $Nssm -NssmArgs (@("set", $Name, "AppEnvironmentExtra") + $EnvPairs) -Required
    }

    if ($StartNow) {
        Invoke-Nssm -Nssm $Nssm -NssmArgs @("start", $Name) -Required
        Write-Host "Started $Name."
    } else {
        Write-Host "Did not start $Name."
    }
}

$resolvedNssm = Find-Nssm -Explicit $NssmPath
if (-not $resolvedNssm) {
    Show-ScheduledTaskCommands
    exit 0
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "NSSM is at $resolvedNssm but this window is not elevated."
    Write-Host "Open PowerShell as Administrator and run this script again. NSSM was not changed."
    exit 1
}

Write-Host "Using NSSM: $resolvedNssm"
Write-Host "Repo: $RepoRoot"

$aiEnv = Get-AiEnvPairs
if ($null -eq $aiEnv) {
    Write-Host "Admission AI service was not registered. Fix the environment value reported above and run this script again."
    exit 1
}

$followupEnv = Get-FollowupEnvPairs
if ($null -eq $followupEnv) {
    Write-Host "Follow-up service was not registered. Fix the environment value reported above and run this script again."
    exit 1
}

$haveDbPass = -not [string]::IsNullOrWhiteSpace($env:DB_PASS)

Register-HccService -Nssm $resolvedNssm -Name "HCC-AdmissionAI" -DisplayName "HCC Admission AI" -ScriptPath $AiScript -EnvPairs $aiEnv -StartNow -RestartOnExit

if (-not $haveDbPass) {
    $existingFollowup = Get-Service -Name "HCC-FollowupEngine" -ErrorAction SilentlyContinue
    Write-Host "DB_PASS is not set in this window."
    if ($existingFollowup) {
        Write-Host "HCC-FollowupEngine already exists. It was left unchanged so a previously saved password is not removed."
    } else {
        Write-Host "Registering HCC-FollowupEngine without a password and not starting it."
        Register-HccService -Nssm $resolvedNssm -Name "HCC-FollowupEngine" -DisplayName "HCC Follow-up Engine" -ScriptPath $EngineScript -EnvPairs $followupEnv
    }
    Write-Host "Set DB_PASS to the same value as config\env.php and run this script again."
} else {
    Register-HccService -Nssm $resolvedNssm -Name "HCC-FollowupEngine" -DisplayName "HCC Follow-up Engine" -ScriptPath $EngineScript -EnvPairs $followupEnv -StartNow -RestartOnExit
}

Write-Host ""
Write-Host "NSSM services do not start XAMPP MySQL, Ollama, or Apache."
Write-Host "Order: MySQL, Ollama, HCC-AdmissionAI, HCC-FollowupEngine, Apache."
Write-Host "Health: http://127.0.0.1:8085/api/health"
if (-not $haveDbPass) {
    exit 1
}
