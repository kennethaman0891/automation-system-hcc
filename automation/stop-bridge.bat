@echo off
REM Stop HCC WhatsApp Bridge on Windows
cd /d "%~dp0"
for /f "tokens=2 delims=," %%P in ('tasklist /FI "IMAGENAME eq node.exe" /FO CSV /NH 2^>nul') do (
  wmic process where "ProcessId=%%~P" get CommandLine 2>nul | find /I "whatsapp-bridge.js" >nul && taskkill /PID %%~P /F >nul 2>&1
)
del /f /q "%TEMP%\whatsapp-bridge.instance.lock" 2>nul
echo Bridge stop attempted.
