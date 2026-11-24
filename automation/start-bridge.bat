@echo off
REM HCC WhatsApp Bridge - keep the Windows supervisor running.
REM The supervisor restarts the bridge after a crash so the QR can come back.
cd /d "%~dp0"
where node >nul 2>&1
if errorlevel 1 (
  echo Node.js was not found in PATH. Install Node.js 18 or newer from https://nodejs.org/
  exit /b 1
)
where powershell >nul 2>&1
if errorlevel 1 (
  echo PowerShell was not found. Starting a restart loop in this window.
  goto :batloop
)
start "HCC WhatsApp Bridge" /MIN powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0windows\start-bridge.ps1"
echo WhatsApp bridge supervisor started.
echo It keeps running in a minimized window. Stop it with stop-bridge.bat
exit /b 0

:batloop
if not exist "%~dp0..\storage\whatsapp\ipc" mkdir "%~dp0..\storage\whatsapp\ipc"
set "WHATSAPP_IPC_DIR=%~dp0..\storage\whatsapp\ipc"
:loop
node whatsapp-bridge.js >> "%TEMP%\whatsapp-bridge.log" 2>&1
echo Bridge exited. Restarting in 10 seconds...
timeout /t 10 /nobreak >nul
goto loop
