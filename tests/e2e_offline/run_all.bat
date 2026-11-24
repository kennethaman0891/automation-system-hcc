@echo off
setlocal enabledelayedexpansion
REM Offline WhatsApp & Admission E2E runner for Windows.
REM Does NOT start live WhatsApp. Requires PHP (C:\xampp\php\php.exe or PATH) and Node.js 18+.

cd /d "%~dp0..\.."
set "ROOT=%CD%"
set "E2E=%~dp0"

if defined PHP_BIN (
  set "PHP=%PHP_BIN%"
) else if exist "C:\xampp\php\php.exe" (
  set "PHP=C:\xampp\php\php.exe"
) else (
  set "PHP=php"
)

where "%PHP%" >nul 2>&1
if errorlevel 1 (
  if not exist "%PHP%" (
    echo ERROR: PHP not found. Install XAMPP at C:\xampp or add php.exe to PATH.
    exit /b 1
  )
)

where node >nul 2>&1
if errorlevel 1 (
  echo ERROR: Node.js not found on PATH. Install Node.js 18+ first.
  exit /b 1
)

if not exist "%E2E%results" mkdir "%E2E%results"

set "WA_TEST_MODE=1"
set "HCC_DB_NAME=hcc_wa_test"
set "WA_MOCK_URL=http://127.0.0.1:9876/"
set "WA_ALLOWED_CHATS=919999000001@c.us,120363999000002@g.us,123456789012345@lid,919999000099@c.us"
set "E2E_API_BASE=http://127.0.0.1:9080/admin"
set "API_BASE_URL=http://127.0.0.1:9080/admin"
set "HEADLESS=true"
set "ENABLE_PRIVACY_FILTER=false"
set "USER_DATA_DIR=%ROOT%\automation\.whatsapp-session-test"
set "PHP_BIN=%PHP%"

set /a PASS=0
set /a FAIL=0

echo == Setup test DB ==
"%PHP%" "%E2E%setup_test_db.php"
if errorlevel 1 (
  echo ERROR: setup_test_db.php failed. Ensure MariaDB is running and config\env.php is configured.
  exit /b 1
)

call :run_suite safety_guard node "%E2E%test_safety_guard.js"
call :run_suite platform node "%E2E%test_platform.js"
call :run_suite revoke_rules node "%E2E%test_revoke_rules.js"
call :run_suite hub_js node "%E2E%test_hub_js.js"
call :run_suite no_silent_typing node "%E2E%test_no_silent_typing.js"
call :run_suite incoming_list node "%E2E%test_incoming_list.js"
call :run_suite preview_dedupe "%PHP%" "%E2E%test_preview_dedupe.php"
call :run_suite php_pipelines "%PHP%" "%E2E%test_php_pipelines.php"
call :run_suite no_auto_send "%PHP%" "%E2E%test_no_auto_send.php"
call :run_suite admission_ai_review "%PHP%" "%E2E%test_admission_ai_review.php"
call :run_suite admission_ai_api_engine "%PHP%" "%E2E%test_admission_ai_api_engine.php"
call :run_suite lid_rules node "%ROOT%\automation\test-wa-lid-rules.js"

echo.
echo ========================================
echo Windows offline suites: PASS=%PASS% FAIL=%FAIL%
echo Note: For mock bridge + bash audits, run tests/e2e_offline/run_all.sh in Git Bash.
echo For the mandatory production release gate, run:
echo   "%PHP%" tests\admission_workflow_e2e.php
echo ========================================

if %FAIL% GTR 0 exit /b 1
exit /b 0

:run_suite
set "SNAME=%~1"
shift
echo.
echo ===== %SNAME% =====
"%~1" "%~2"
if errorlevel 1 (
  set /a FAIL+=1
  echo ^>^> %SNAME% FAILED
) else (
  set /a PASS+=1
  echo ^>^> %SNAME% OK
)
exit /b 0
