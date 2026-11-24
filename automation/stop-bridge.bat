@echo off
REM Stop the HCC WhatsApp supervisor, the bridge, and Chrome started for its profile.
REM Does not stop other node.exe or Chrome windows.
cd /d "%~dp0"
where powershell >nul 2>&1
if errorlevel 1 (
  echo PowerShell was not found. Close the "HCC WhatsApp Bridge" window.
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$procs = Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and ( $_.CommandLine -like '*whatsapp-bridge.js*' -or $_.CommandLine -like '*start-bridge.ps1*' -or ( $_.CommandLine -like '*.whatsapp-session*' -and $_.Name -match 'chrome|msedge' ) ) }; foreach ($p in $procs) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }"
del /f /q "%TEMP%\whatsapp-bridge.instance.lock" 2>nul
echo Bridge stop attempted.
