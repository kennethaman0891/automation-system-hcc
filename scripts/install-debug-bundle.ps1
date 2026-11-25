# Install the HCC debug bundle into a chosen XAMPP document root.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File install-debug-bundle.ps1 [-TargetDir C:\xampp\htdocs\hcc]
param(
    [string]$TargetDir = "C:\xampp\htdocs\hcc"
)

$ErrorActionPreference = "Stop"
$Src = Split-Path -Parent $PSScriptRoot

Write-Host "Installing HCC debug bundle into: $TargetDir"
New-Item -ItemType Directory -Force -Path $TargetDir | Out-Null
$TargetDir = (Resolve-Path $TargetDir).Path

if ($TargetDir -eq $Src) {
    throw "Refusing to install into the source directory ($TargetDir)."
}

$Excludes = @(
    ".git", "dist", "node_modules", "logs",
    "wa-promo-poster.jpg", "*.mp4", "*.mov", "*.webm"
)

Get-ChildItem -Path $Src -Force | Where-Object {
    $name = $_.Name
    -not ($Excludes -contains $name) -and
    -not ($name -like "*.mp4") -and
    -not ($name -like "*.mov") -and
    -not ($name -like "*.webm")
} | Copy-Item -Destination $TargetDir -Recurse -Force

# Remove nested runtime/secret files that were copied inside folders.
Remove-Item -Path (Join-Path $TargetDir "config\env.php") -Force -ErrorAction SilentlyContinue
Remove-Item -Path (Join-Path $TargetDir "automation\.env") -Force -ErrorAction SilentlyContinue
Remove-Item -Path (Join-Path $TargetDir "storage\whatsapp\media") -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path (Join-Path $TargetDir "storage\whatsapp\ipc") -Recurse -Force -ErrorAction SilentlyContinue

New-Item -ItemType Directory -Force -Path (Join-Path $TargetDir "logs") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $TargetDir "storage\whatsapp\media") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $TargetDir "storage\whatsapp\ipc") | Out-Null

$EnvExample = Join-Path $TargetDir "config\env.example.php"
$EnvFile = Join-Path $TargetDir "config\env.php"
if ((Test-Path $EnvExample) -and -not (Test-Path $EnvFile)) {
    Copy-Item -Path $EnvExample -Destination $EnvFile

    $secret = ""
    if (Get-Command php -ErrorAction SilentlyContinue) {
        $secret = (& php -r "echo bin2hex(random_bytes(32));" 2>$null)
    }
    if (-not $secret) {
        $bytes = New-Object byte[] 32
        [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        $secret = -join ($bytes | ForEach-Object { $_.ToString("x2") })
    }

    $content = Get-Content $EnvFile -Raw
    $content = $content -replace 'CHANGE_ME_php_-r_echo_bin2hex_random_bytes_32_EOL', $secret
    Set-Content -Path $EnvFile -Value $content -NoNewline
    Write-Host "Created config/env.php with a locally generated JWT secret."
} else {
    Write-Host "Kept existing config/env.php (not overwritten)."
}

if (Get-Command composer -ErrorAction SilentlyContinue -and (Test-Path (Join-Path $TargetDir "composer.json"))) {
    Push-Location $TargetDir
    try { composer install --no-interaction --prefer-dist }
    catch { Write-Warning "composer install failed; run it manually: cd $TargetDir && composer install" }
    finally { Pop-Location }
} else {
    Write-Host "Composer not found or no composer.json; run 'composer install' manually if needed."
}

$manifest = Join-Path $TargetDir "MANIFEST-ADMIN.txt"
if (Test-Path $manifest) {
    $expected = (Get-Content $manifest | Where-Object { $_ -ne "" }).Count
    $missing = 0
    foreach ($rel in (Get-Content $manifest)) {
        if ($rel -and -not (Test-Path (Join-Path $TargetDir $rel))) {
            Write-Error "MISSING: $rel"
            $missing++
        }
    }
    if ($missing -gt 0) { throw "$missing admin file(s) missing after install." }
    Write-Host "ADMIN COMPLETE: $expected/$expected files present"
} else {
    Write-Warning "MANIFEST-ADMIN.txt not found; admin completeness not verified."
}

Write-Host ""
Write-Host "Install complete."
Write-Host "Local admin login: http://localhost/hcc/admin/login.php"
Write-Host "Debug check: php $TargetDir\scripts\debug-doctor.php"
