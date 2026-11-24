# Read-only verification and health check for HCC / School WhatsApp Hub on Windows.
# Checks prerequisites, config, Windows PE binaries, Apache .htaccess protection,
# Ollama, Admission AI service, Follow-up engine, and optionally runs the E2E gate.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\verify-windows.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\verify-windows.ps1 -RunE2E
#
#Requires -Version 5.1

param(
    [string]$PhpExe = "",
    [string]$BaseUrl = "http://127.0.0.1/hcc",
    [string]$AiUrl = "http://127.0.0.1:8085",
    [string]$OllamaUrl = "http://127.0.0.1:11434",
    [switch]$RunE2E
)

$ErrorActionPreference = "Continue"

. (Join-Path $PSScriptRoot "hcc-pe.ps1")

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$script:Passed = 0
$script:Warned = 0
$script:Failed = 0

function Record-Check {
    param(
        [string]$Id,
        [string]$Name,
        [ValidateSet("PASS", "WARN", "FAIL")][string]$Status,
        [string]$Detail = ""
    )
    $color = "Green"
    if ($Status -eq "PASS") { $script:Passed++ }
    elseif ($Status -eq "WARN") { $script:Warned++; $color = "Yellow" }
    else { $script:Failed++; $color = "Red" }

    $suffix = ""
    if (-not [string]::IsNullOrWhiteSpace($Detail)) { $suffix = " — $Detail" }
    Write-Host ("{0,-4}  {1,-4}  {2}{3}" -f $Status, $Id, $Name, $suffix) -ForegroundColor $color
}

function Resolve-PhpExecutable {
    param([string]$Explicit)
    if (-not [string]::IsNullOrWhiteSpace($Explicit) -and (Test-Path -LiteralPath $Explicit)) {
        return (Resolve-Path -LiteralPath $Explicit).Path
    }
    if (Test-Path -LiteralPath "C:\xampp\php\php.exe") {
        return "C:\xampp\php\php.exe"
    }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source) { return $cmd.Source }
    return $null
}

function Invoke-HttpStatus {
    param([string]$Url)
    try {
        $resp = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5 -MaximumRedirection 0 -ErrorAction Stop
        return @{ Code = [int]$resp.StatusCode; Body = $resp.Content; Error = "" }
    } catch {
        $webResp = $_.Exception.Response
        if ($null -ne $webResp) {
            $code = [int]$webResp.StatusCode
            return @{ Code = $code; Body = ""; Error = "" }
        }
        return @{ Code = 0; Body = ""; Error = $_.Exception.Message }
    }
}

Write-Host "HCC / School WhatsApp Hub — Windows Verification"
Write-Host "RepoRoot: $RepoRoot"
Write-Host ""

# W1: Install path
if ($RepoRoot -ieq "C:\xampp\htdocs\hcc") {
    Record-Check "W1" "Install path is C:\xampp\htdocs\hcc" "PASS"
} else {
    Record-Check "W1" "Install path is C:\xampp\htdocs\hcc" "WARN" "Current path: $RepoRoot (BASE_URL and .htaccess expect /hcc/)"
}

# W2: PHP 8.3+ and extensions
$resolvedPhp = Resolve-PhpExecutable -Explicit $PhpExe
if (-not $resolvedPhp) {
    Record-Check "W2" "PHP 8.3+ and extensions" "FAIL" "php.exe not found"
} else {
    $phpCheck = & $resolvedPhp -r "echo PHP_VERSION; exit(version_compare(PHP_VERSION,'8.3.0','>=') ? 0 : 1);" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Record-Check "W2" "PHP 8.3+ detected" "PASS" "PHP $phpCheck ($resolvedPhp)"
    } else {
        Record-Check "W2" "PHP 8.3+ detected" "FAIL" "Found PHP $phpCheck"
    }

    $extCheck = & $resolvedPhp -r "\$req=['ctype','dom','fileinfo','filter','gd','iconv','libxml','mbstring','simplexml','xml','xmlreader','xmlwriter','zip','zlib','pdo_mysql','curl','openssl']; \$m=array_filter(\$req, fn(\$e)=>!extension_loaded(\$e)); \$d=array_map('trim',explode(',',(string)ini_get('disable_functions'))); \$f=array_filter(['exec','proc_open'], fn(\$x)=>in_array(\$x,\$d,true)||!function_exists(\$x)); if(\$m||\$f){echo 'missing_ext='.implode(',',\$m).' disabled_fn='.implode(',',\$f); exit(1);} echo 'OK';" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Record-Check "W3" "PHP extensions + exec/proc_open" "PASS"
    } else {
        Record-Check "W3" "PHP extensions + exec/proc_open" "FAIL" "$extCheck"
    }
}

# W4: Composer vendor/
if (Test-Path -LiteralPath (Join-Path $RepoRoot "vendor\autoload.php")) {
    Record-Check "W4" "Composer vendor/autoload.php" "PASS"
} else {
    Record-Check "W4" "Composer vendor/autoload.php" "FAIL" "Run 'composer install' in $RepoRoot"
}

# W5: config\env.php and JWT_SECRET
$envFile = Join-Path $RepoRoot "config\env.php"
if (-not (Test-Path -LiteralPath $envFile)) {
    Record-Check "W5" "config\env.php present" "FAIL" "Copy from config\env.example.php"
} else {
    $envRaw = Get-Content -LiteralPath $envFile -Raw -Encoding UTF8
    if ($envRaw -match "CHANGE_ME") {
        Record-Check "W5" "config\env.php configured" "FAIL" "JWT_SECRET still contains CHANGE_ME placeholder"
    } else {
        Record-Check "W5" "config\env.php configured" "PASS"
    }
}

# W6: Approved knowledge directory
$knowledgeDir = Join-Path $RepoRoot "knowledge\approved"
$jsonCount = 0
if (Test-Path -LiteralPath $knowledgeDir) {
    $jsonCount = @(Get-ChildItem -LiteralPath $knowledgeDir -Filter "*.json" -ErrorAction SilentlyContinue).Count
}
if ($jsonCount -gt 0) {
    Record-Check "W6" "knowledge\approved JSON files" "PASS" "$jsonCount files"
} else {
    Record-Check "W6" "knowledge\approved JSON files" "WARN" "No JSON files found in $knowledgeDir"
}

# W7: Windows PE binaries
$aiExe = Join-Path $RepoRoot "automation\admission-ai-service\target\release\admission-ai-service.exe"
if (Test-HccWindowsExecutable $aiExe) {
    Record-Check "W7" "admission-ai-service.exe (Windows PE)" "PASS"
} else {
    Record-Check "W7" "admission-ai-service.exe (Windows PE)" "FAIL" "Build with 'cargo build --release' in automation\admission-ai-service"
}

$engineExe = Join-Path $RepoRoot "automation\rust_engine\target\release\hcc-automation-engine.exe"
$engineAlt = Join-Path $RepoRoot "automation\bin\hcc-automation-engine.exe"
$resolvedEngine = $null
if (Test-HccWindowsExecutable $engineExe) { $resolvedEngine = $engineExe }
elseif (Test-HccWindowsExecutable $engineAlt) { $resolvedEngine = $engineAlt }

if ($resolvedEngine) {
    Record-Check "W8" "hcc-automation-engine.exe (Windows PE)" "PASS"
} else {
    Record-Check "W8" "hcc-automation-engine.exe (Windows PE)" "FAIL" "Build with 'cargo build --release' in automation\rust_engine"
}

# W9 & W10: Apache admin reachability + 403 on config/env.php
$adminRes = Invoke-HttpStatus -Url "$($BaseUrl.TrimEnd('/'))/admin/"
if ($adminRes.Code -in @(200, 301, 302)) {
    Record-Check "W9" "Apache admin UI reachable" "PASS" "HTTP $($adminRes.Code)"
} else {
    Record-Check "W9" "Apache admin UI reachable" "FAIL" "HTTP $($adminRes.Code) $($adminRes.Error)"
}

$secRes = Invoke-HttpStatus -Url "$($BaseUrl.TrimEnd('/'))/config/env.php"
if ($secRes.Code -eq 403) {
    Record-Check "W10" "Apache blocks /config/env.php (403)" "PASS"
} else {
    Record-Check "W10" "Apache blocks /config/env.php (403)" "FAIL" "Got HTTP $($secRes.Code) — verify AllowOverride All in httpd.conf"
}

# W11: Ollama reachable + llama3.2:1b
$ollamaRes = Invoke-HttpStatus -Url "$($OllamaUrl.TrimEnd('/'))/api/tags"
if ($ollamaRes.Code -eq 200) {
    if ($ollamaRes.Body -match "llama3\.2:1b") {
        Record-Check "W11" "Ollama reachable with llama3.2:1b" "PASS"
    } else {
        Record-Check "W11" "Ollama reachable with llama3.2:1b" "WARN" "Ollama up, but llama3.2:1b not listed (run 'ollama pull llama3.2:1b')"
    }
} else {
    Record-Check "W11" "Ollama reachable" "FAIL" "HTTP $($ollamaRes.Code) $($ollamaRes.Error)"
}

# W12: Admission AI health
$aiRes = Invoke-HttpStatus -Url "$($AiUrl.TrimEnd('/'))/api/health"
if ($aiRes.Code -eq 200) {
    try {
        $health = $aiRes.Body | ConvertFrom-Json
        if ($health.status -eq "healthy" -and [int]$health.concurrency_limit -eq 1) {
            Record-Check "W12" "Admission AI service healthy" "PASS" "model=$($health.model) concurrency=1"
        } else {
            Record-Check "W12" "Admission AI service healthy" "WARN" "status=$($health.status) concurrency=$($health.concurrency_limit)"
        }
    } catch {
        Record-Check "W12" "Admission AI service healthy" "WARN" "Non-JSON response from /api/health"
    }
} else {
    Record-Check "W12" "Admission AI service healthy" "FAIL" "HTTP $($aiRes.Code) $($aiRes.Error)"
}

# W13: Follow-up engine --status
if (-not $resolvedEngine) {
    Record-Check "W13" "Follow-up engine --status" "WARN" "Skipped (binary not built)"
} elseif ([string]::IsNullOrWhiteSpace($env:DB_PASS)) {
    Record-Check "W13" "Follow-up engine --status" "WARN" "Skipped (set `$env:DB_PASS to test DB connection)"
} else {
    $statusOut = & $resolvedEngine --status 2>&1
    if ($LASTEXITCODE -eq 0) {
        Record-Check "W13" "Follow-up engine --status" "PASS"
    } else {
        Record-Check "W13" "Follow-up engine --status" "FAIL" "$($statusOut -join ' ')"
    }
}

# W14: Mandatory E2E release gate (optional switch)
if ($RunE2E) {
    Write-Host ""
    Write-Host "==> Running mandatory E2E release gate (tests\admission_workflow_e2e.php)" -ForegroundColor Cyan
    if (-not $resolvedPhp) {
        Record-Check "W14" "Mandatory E2E release gate" "FAIL" "php.exe not found"
    } else {
        Push-Location $RepoRoot
        try {
            & $resolvedPhp (Join-Path $RepoRoot "tests\admission_workflow_e2e.php")
            if ($LASTEXITCODE -eq 0) {
                Record-Check "W14" "Mandatory E2E release gate" "PASS"
            } else {
                Record-Check "W14" "Mandatory E2E release gate" "FAIL" "Exited with code $LASTEXITCODE"
            }
        } finally {
            Pop-Location
        }
    }
}

Write-Host ""
Write-Host "Summary: $($script:Passed) passed, $($script:Warned) warnings, $($script:Failed) failed"
if ($script:Failed -gt 0) {
    exit 1
}
exit 0
