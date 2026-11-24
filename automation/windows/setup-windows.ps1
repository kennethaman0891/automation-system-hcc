# End-to-end Windows setup helper for HCC / School WhatsApp Hub.
# Safe to re-run (idempotent): never overwrites existing config\env.php or automation\.env.
#
# Usage:
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\setup-windows.ps1
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\setup-windows.ps1 -RunMigrations
#
#Requires -Version 5.1

param(
    [string]$PhpExe = "",
    [switch]$SkipComposer,
    [switch]$SkipBuild,
    [switch]$SkipBridgeNpm,
    [switch]$SkipOllamaPull,
    [switch]$RunMigrations
)

$ErrorActionPreference = "Stop"

. (Join-Path $PSScriptRoot "hcc-pe.ps1")

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location $RepoRoot

function Write-Step {
    param([string]$Message)
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
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
    if ($cmd -and $cmd.Source) {
        return $cmd.Source
    }
    return $null
}

Write-Host "HCC / School WhatsApp Hub — Windows Setup"
Write-Host "RepoRoot: $RepoRoot"

if ($RepoRoot -ine "C:\xampp\htdocs\hcc") {
    Write-Host "WARNING: Expected install path is C:\xampp\htdocs\hcc (BASE_URL and .htaccess assume /hcc/)." -ForegroundColor Yellow
}

# 1. Runtime directories
Write-Step "Ensuring writable runtime directories"
$dirs = @(
    (Join-Path $RepoRoot "logs"),
    (Join-Path $RepoRoot "storage"),
    (Join-Path $RepoRoot "storage\whatsapp"),
    (Join-Path $RepoRoot "storage\whatsapp\ipc"),
    (Join-Path $RepoRoot "storage\whatsapp\media"),
    (Join-Path $RepoRoot "storage\workspace")
)
foreach ($d in $dirs) {
    if (-not (Test-Path -LiteralPath $d)) {
        New-Item -ItemType Directory -Path $d | Out-Null
        Write-Host "Created: $d"
    }
}

# 2. PHP version, extensions, and exec/proc_open check
Write-Step "Checking PHP 8.3+ and required extensions"
$resolvedPhp = Resolve-PhpExecutable -Explicit $PhpExe
if (-not $resolvedPhp) {
    Write-Host "ERROR: PHP not found. Install XAMPP for Windows (PHP 8.3+) at C:\xampp first." -ForegroundColor Red
    exit 1
}
Write-Host "PHP: $resolvedPhp"

$phpCheckCode = @'
$ok = true;
echo "PHP_VERSION=" . PHP_VERSION . PHP_EOL;
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    echo "ERR_VERSION=PHP 8.3+ required" . PHP_EOL;
    $ok = false;
}
$req = ['ctype','dom','fileinfo','filter','gd','iconv','libxml','mbstring','simplexml','xml','xmlreader','xmlwriter','zip','zlib','pdo_mysql','curl','openssl'];
$missing = [];
foreach ($req as $ext) {
    if (!extension_loaded($ext)) { $missing[] = $ext; }
}
if ($missing) {
    echo "ERR_EXT=" . implode(',', $missing) . PHP_EOL;
    $ok = false;
}
$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
foreach (['exec', 'proc_open'] as $fn) {
    if (in_array($fn, $disabled, true) || !function_exists($fn)) {
        echo "ERR_FN=" . $fn . PHP_EOL;
        $ok = false;
    }
}
exit($ok ? 0 : 1);
'@
$phpCheckOut = & $resolvedPhp -r $phpCheckCode 2>&1
foreach ($line in $phpCheckOut) { Write-Host "  $line" }
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: PHP check failed. Enable the missing extensions or functions in C:\xampp\php\php.ini and re-run." -ForegroundColor Red
    exit 1
}

# 3. config\env.php bootstrap
Write-Step "Checking config\env.php"
$envPath = Join-Path $RepoRoot "config\env.php"
$envExamplePath = Join-Path $RepoRoot "config\env.example.php"
if (-not (Test-Path -LiteralPath $envPath)) {
    if (-not (Test-Path -LiteralPath $envExamplePath)) {
        Write-Host "ERROR: Missing template $envExamplePath" -ForegroundColor Red
        exit 1
    }
    $jwtSecret = (& $resolvedPhp -r "echo bin2hex(random_bytes(32));").Trim()
    $raw = Get-Content -LiteralPath $envExamplePath -Raw -Encoding UTF8
    $raw = $raw -replace "'DB_HOST'\s*=>\s*'localhost'", "'DB_HOST'     => '127.0.0.1'"
    $raw = $raw -replace "'JWT_SECRET'\s*=>\s*'CHANGE_ME[^']*'", "'JWT_SECRET'  => '$jwtSecret'"
    Set-Content -LiteralPath $envPath -Value $raw -Encoding UTF8
    Write-Host "Created config\env.php with DB_HOST=127.0.0.1, DB_SOCKET='', and a fresh random JWT_SECRET." -ForegroundColor Green
    Write-Host "ACTION REQUIRED: Edit config\env.php to set DB_PASS (and WHATSAPP_BRIDGE_SECRET if using the bridge)." -ForegroundColor Yellow
} else {
    Write-Host "config\env.php already exists (left unchanged)."
}

# 4. automation\.env bootstrap
Write-Step "Checking automation\.env"
$bridgeEnv = Join-Path $RepoRoot "automation\.env"
$bridgeEnvExample = Join-Path $RepoRoot "automation\.env.example"
if (-not (Test-Path -LiteralPath $bridgeEnv)) {
    if (Test-Path -LiteralPath $bridgeEnvExample) {
        Copy-Item -LiteralPath $bridgeEnvExample -Destination $bridgeEnv
        Write-Host "Created automation\.env from automation\.env.example." -ForegroundColor Green
        Write-Host "ACTION REQUIRED: Set WHATSAPP_BRIDGE_SECRET in automation\.env to match config\env.php." -ForegroundColor Yellow
    } else {
        Write-Host "SKIP: automation\.env.example not found."
    }
} else {
    Write-Host "automation\.env already exists (left unchanged)."
}

# 5. Composer install
if (-not $SkipComposer) {
    Write-Step "Checking Composer dependencies (vendor/)"
    $autoload = Join-Path $RepoRoot "vendor\autoload.php"
    if (-not (Test-Path -LiteralPath $autoload)) {
        $composerCmd = Get-Command composer -ErrorAction SilentlyContinue
        if (-not $composerCmd) {
            Write-Host "WARNING: composer not found on PATH. Install Composer 2 and run 'composer install' in $RepoRoot." -ForegroundColor Yellow
        } else {
            & composer install --no-interaction
            if ($LASTEXITCODE -ne 0) {
                Write-Host "ERROR: composer install failed with exit code $LASTEXITCODE." -ForegroundColor Red
                exit 1
            }
            Write-Host "Composer dependencies installed." -ForegroundColor Green
        }
    } else {
        Write-Host "vendor\autoload.php already present."
    }
}

# 6. Build Rust services
if (-not $SkipBuild) {
    Write-Step "Checking / building Windows Rust services"
    $aiExe = Join-Path $RepoRoot "automation\admission-ai-service\target\release\admission-ai-service.exe"
    $engineExe = Join-Path $RepoRoot "automation\rust_engine\target\release\hcc-automation-engine.exe"
    $engineAltExe = Join-Path $RepoRoot "automation\bin\hcc-automation-engine.exe"

    $needAi = -not (Test-HccWindowsExecutable $aiExe)
    $needEngine = -not ((Test-HccWindowsExecutable $engineExe) -or (Test-HccWindowsExecutable $engineAltExe))

    if (-not $needAi -and -not $needEngine) {
        Write-Host "Both Windows PE Rust binaries are already built." -ForegroundColor Green
    } else {
        $cargoCmd = Get-Command cargo -ErrorAction SilentlyContinue
        if (-not $cargoCmd) {
            Write-Host "WARNING: cargo not found on PATH. Install rustup + VS C++ Build Tools, then re-run this script." -ForegroundColor Yellow
        } else {
            if ($needAi) {
                $aiDir = Join-Path $RepoRoot "automation\admission-ai-service"
                if (Test-Path -LiteralPath (Join-Path $aiDir "Cargo.toml")) {
                    Write-Host "Building admission-ai-service..."
                    Push-Location $aiDir
                    try {
                        & cargo build --release
                        if ($LASTEXITCODE -ne 0) { throw "cargo build failed for admission-ai-service" }
                    } finally {
                        Pop-Location
                    }
                } else {
                    Write-Host "ERROR: Missing $aiDir\Cargo.toml." -ForegroundColor Red
                    exit 1
                }
            }
            if ($needEngine) {
                $engineDir = Join-Path $RepoRoot "automation\rust_engine"
                if (Test-Path -LiteralPath (Join-Path $engineDir "Cargo.toml")) {
                    Write-Host "Building hcc-automation-engine..."
                    Push-Location $engineDir
                    try {
                        & cargo build --release
                        if ($LASTEXITCODE -ne 0) { throw "cargo build failed for rust_engine" }
                    } finally {
                        Pop-Location
                    }
                } else {
                    Write-Host "ERROR: Missing $engineDir\Cargo.toml." -ForegroundColor Red
                    exit 1
                }
            }
        }
    }
}

# 7. Ollama starter model
if (-not $SkipOllamaPull) {
    Write-Step "Checking Ollama starter model (llama3.2:1b)"
    $ollamaCmd = Get-Command ollama -ErrorAction SilentlyContinue
    if (-not $ollamaCmd) {
        Write-Host "WARNING: ollama not found on PATH. Install Ollama for Windows and run 'ollama pull llama3.2:1b'." -ForegroundColor Yellow
    } else {
        $listOut = (& ollama list 2>&1) -join "`n"
        if ($listOut -match "llama3\.2:1b") {
            Write-Host "Ollama model llama3.2:1b is already present." -ForegroundColor Green
        } else {
            Write-Host "Pulling llama3.2:1b (starter model for 8 GB RAM)..."
            & ollama pull llama3.2:1b
            if ($LASTEXITCODE -ne 0) {
                Write-Host "WARNING: ollama pull llama3.2:1b failed. Ensure Ollama is running and retry." -ForegroundColor Yellow
            }
        }
    }
}

# 8. Node.js WhatsApp Bridge packages (optional)
if (-not $SkipBridgeNpm) {
    Write-Step "Checking Node.js WhatsApp Bridge dependencies"
    $nodeCmd = Get-Command node -ErrorAction SilentlyContinue
    if (-not $nodeCmd) {
        Write-Host "INFO: Node.js not found on PATH (only needed if you run the WhatsApp Bridge)."
    } else {
        $autoDir = Join-Path $RepoRoot "automation"
        Push-Location $autoDir
        try {
            & node -e "require('puppeteer'); require('axios'); require('ws'); require('dotenv');" 2>$null
            if ($LASTEXITCODE -ne 0) {
                Write-Host "Installing Node.js dependencies in automation\..."
                & npm install
                if ($LASTEXITCODE -ne 0) {
                    Write-Host "WARNING: npm install failed in automation\ (exit $LASTEXITCODE)." -ForegroundColor Yellow
                }
            } else {
                Write-Host "Node.js bridge dependencies verified." -ForegroundColor Green
            }
        } finally {
            Pop-Location
        }
    }
}

# 9. Optional PHP migrations
if ($RunMigrations) {
    Write-Step "Running idempotent PHP database migrations"
    Write-Host "Note: Make sure MariaDB is running and 'hcc_admin' + admission_enquiries base SQL are loaded first."
    $migrations = @(
        "admin\migrate_whatsapp_replica.php",
        "data\migrations\migrate_admission_followups.php",
        "admin\migrate_ai_admission.php"
    )
    foreach ($m in $migrations) {
        $full = Join-Path $RepoRoot $m
        if (Test-Path -LiteralPath $full) {
            Write-Host "Running $m ..."
            & $resolvedPhp $full
            if ($LASTEXITCODE -ne 0) {
                Write-Host "ERROR: Migration $m exited with code $LASTEXITCODE." -ForegroundColor Red
                exit 1
            }
        }
    }
}

Write-Host ""
Write-Host "Setup complete. Next steps:" -ForegroundColor Green
Write-Host "  1. Confirm config\env.php has your MariaDB password (DB_PASS)."
Write-Host "  2. Start services in order: MariaDB (XAMPP) -> Ollama -> Admission AI -> Follow-up Engine -> Apache (XAMPP)."
Write-Host "  3. Run verification:"
Write-Host "     powershell -NoProfile -ExecutionPolicy Bypass -File $RepoRoot\automation\windows\verify-windows.ps1 -RunE2E"
