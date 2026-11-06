@echo off
REM HCC WhatsApp Bridge — start once (Windows). No auto-restart loop.
cd /d "%~dp0"
where node >nul 2>&1
if errorlevel 1 (
  echo Node.js not found in PATH.
  exit /b 1
)
tasklist /FI "IMAGENAME eq node.exe" 2>nul | find /I "node.exe" >nul
REM Single-instance: if bridge already listed in a crude check, still try; user can stop first.
if exist "%TEMP%\whatsapp-bridge.instance.lock" (
  echo Bridge lock present. Run stop-bridge.bat first if needed.
)
echo Starting bridge once...
start "HCC WhatsApp Bridge" /MIN cmd /c "node whatsapp-bridge.js >> %TEMP%\whatsapp-bridge.log 2>&1"
echo Bridge started. Stop with stop-bridge.bat
