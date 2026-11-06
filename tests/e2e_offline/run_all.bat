@echo off
REM Offline e2e on Windows — requires PHP in PATH and Node.
REM Does NOT start live WhatsApp.
set WA_TEST_MODE=1
set HCC_DB_NAME=hcc_wa_test
set WA_MOCK_URL=http://127.0.0.1:9876/
set E2E_API_BASE=http://127.0.0.1:9080/admin
echo Use Git Bash or WSL to run tests/e2e_offline/run_all.sh for full coverage.
echo Or: node tests\e2e_offline\test_safety_guard.js
echo      node tests\e2e_offline\test_platform.js
php tests\e2e_offline\setup_test_db.php
