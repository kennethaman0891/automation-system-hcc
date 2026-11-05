@echo off
setlocal enabledelayedexpansion

REM =========================================================================
REM HCC WhatsApp Bridge Supervisor (Windows CMD / Scheduler / Startup)
REM Keeps the Puppeteer bridge running: restarts automatically if it exits,
REM and prevents concurrent instances against the same Chrome profile.
REM
REM Usage:  automation\start-bridge.bat
REM Logs :  logs\whatsapp-bridge.log
REM =========================================================================

cd /d "%~dp0"
set "REPO_ROOT=%~dp0.."
set "LOG_DIR=%~dp0..\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
set "BRIDGE_LOG=%LOG_DIR%\whatsapp-bridge.log"
set "SESSION_DIR=%~dp0.whatsapp-session"

echo [supervisor] Starting HCC WhatsApp Bridge supervisor on Windows...
echo [supervisor] Working directory: %cd%
echo [supervisor] Logs: %BRIDGE_LOG%

REM 1. Verify Node.js is installed and on PATH
where node >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Node.js was not found in PATH!
    echo Please install Node.js 18+ from https://nodejs.org/ and restart this terminal.
    pause
    exit /b 1
)

REM 2. Verify dependencies
if not exist "%~dp0node_modules" (
    echo [WARNING] node_modules not found in %~dp0. Installing dependencies...
    call npm install
    if %errorlevel% neq 0 (
        echo [ERROR] npm install failed!
        pause
        exit /b 1
    )
)

REM 3. Clean any stale locks left from prior reboots
if exist "%SESSION_DIR%\SingletonLock" del /f /q "%SESSION_DIR%\SingletonLock" >nul 2>&1
if exist "%SESSION_DIR%\SingletonSocket" del /f /q "%SESSION_DIR%\SingletonSocket" >nul 2>&1
if exist "%SESSION_DIR%\SingletonCookie" del /f /q "%SESSION_DIR%\SingletonCookie" >nul 2>&1
if exist "%SESSION_DIR%\lockfile" del /f /q "%SESSION_DIR%\lockfile" >nul 2>&1

:supervisor_loop
REM 4. Check if another instance of whatsapp-bridge.js is already running
for /f "tokens=*" %%P in ('powershell -NoProfile -Command "Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -and $_.CommandLine -like '*whatsapp-bridge.js*' -and $_.ProcessId -ne $PID } | Select-Object -ExpandProperty ProcessId"') do (
    if not "%%P"=="" (
        echo [supervisor] Another whatsapp-bridge.js is already active (PID %%P). Waiting 10s...
        timeout /t 10 /nobreak >nul
        goto supervisor_loop
    )
)

echo [%date% %time%] [supervisor] Starting node whatsapp-bridge.js >> "%BRIDGE_LOG%"
echo [supervisor] Launching bridge...

node whatsapp-bridge.js >> "%BRIDGE_LOG%" 2>&1
set "EXIT_CODE=%errorlevel%"

echo [%date% %time%] [supervisor] Bridge exited with code %EXIT_CODE% — restarting in 10s >> "%BRIDGE_LOG%"
echo [supervisor] Bridge exited with code %EXIT_CODE%.

REM Clear any stale locks before the next attempt
if exist "%SESSION_DIR%\SingletonLock" del /f /q "%SESSION_DIR%\SingletonLock" >nul 2>&1
if exist "%SESSION_DIR%\SingletonSocket" del /f /q "%SESSION_DIR%\SingletonSocket" >nul 2>&1
if exist "%SESSION_DIR%\SingletonCookie" del /f /q "%SESSION_DIR%\SingletonCookie" >nul 2>&1
if exist "%SESSION_DIR%\lockfile" del /f /q "%SESSION_DIR%\lockfile" >nul 2>&1

echo [supervisor] Restarting in 10 seconds (Ctrl+C to abort)...
timeout /t 10 >nul
goto supervisor_loop
