#!/usr/bin/env bash
# Offline WhatsApp e2e runner (macOS / Linux). Does NOT start live WhatsApp.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
E2E="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP_BIN:-/Applications/XAMPP/xamppfiles/bin/php}"
if [ ! -x "$PHP" ]; then PHP="$(command -v php)"; fi
# Prefer NVM Node 24 — Homebrew Node 26 is broken on this Mac (simdjson).
NODE=""
if [ -s "$HOME/.nvm/nvm.sh" ]; then
  # shellcheck disable=SC1090
  . "$HOME/.nvm/nvm.sh"
  nvm use 24 >/dev/null 2>&1 || nvm use 24.19.0 >/dev/null 2>&1 || true
fi
NODE="$(command -v node)"
if [ -x "$HOME/.nvm/versions/node/v24.19.0/bin/node" ]; then
  NODE="$HOME/.nvm/versions/node/v24.19.0/bin/node"
  export PATH="$(dirname "$NODE"):$PATH"
fi
mkdir -p "$E2E/results"
SUMMARY="$E2E/results/summary.json"
LOG="$E2E/results/run_all.log"
: > "$LOG"

export WA_TEST_MODE=1
export HCC_DB_NAME=hcc_wa_test
export WA_MOCK_URL="http://127.0.0.1:9876/"
export WA_ALLOWED_CHATS="919999000001@c.us,120363999000002@g.us,123456789012345@lid,919999000099@c.us"
export E2E_API_BASE="http://127.0.0.1:9080/admin"
export API_BASE_URL="$E2E_API_BASE"
export HEADLESS=true
export ENABLE_PRIVACY_FILTER=false
export USER_DATA_DIR="$ROOT/automation/.whatsapp-session-test"

echo "== Refuse live bridge ==" | tee -a "$LOG"
if pgrep -f 'node whatsapp-bridge.js' >/dev/null 2>&1; then
  echo "ERROR: live whatsapp-bridge.js is running. Stop it before offline tests." | tee -a "$LOG"
  exit 2
fi

echo "== Setup test DB ==" | tee -a "$LOG"
"$PHP" "$E2E/setup_test_db.php" | tee -a "$LOG"

echo "== Start mock WA on 9876 ==" | tee -a "$LOG"
pkill -f 'mock/mock_server.js' 2>/dev/null || true
"$NODE" "$E2E/mock/mock_server.js" >> "$E2E/results/mock_server.log" 2>&1 &
MOCK_PID=$!
echo "$MOCK_PID" > "$E2E/results/mock_server.pid"
sleep 1

echo "== Start PHP test API on 9080 ==" | tee -a "$LOG"
pkill -f 'php_test_router.php' 2>/dev/null || true
(
  cd "$ROOT"
  WA_TEST_MODE=1 HCC_DB_NAME=hcc_wa_test "$PHP" -S 127.0.0.1:9080 "$E2E/lib/php_test_router.php" >> "$E2E/results/php_server.log" 2>&1
) &
PHP_PID=$!
echo "$PHP_PID" > "$E2E/results/php_server.pid"
sleep 1

cleanup() {
  kill "$MOCK_PID" 2>/dev/null || true
  kill "$PHP_PID" 2>/dev/null || true
  pkill -f 'mock/mock_server.js' 2>/dev/null || true
  # Do NOT start live bridge. Kill any accidental bridge from tests.
  pkill -f 'node whatsapp-bridge.js' 2>/dev/null || true
  pkill -f 'user-data-dir=.*/\.whatsapp-session-test' 2>/dev/null || true
}
trap cleanup EXIT

pass=0; fail=0
run() {
  local name="$1"; shift
  echo "" | tee -a "$LOG"
  echo "===== $name =====" | tee -a "$LOG"
  set +e
  "$@" 2>&1 | tee -a "$LOG"
  local code=${PIPESTATUS[0]}
  set -e
  if [ "$code" -eq 0 ]; then pass=$((pass+1)); echo ">> $name OK"; else fail=$((fail+1)); echo ">> $name FAILED ($code)"; fi
}

run audit_no_auto_send bash "$E2E/audit_no_auto_send.sh"
run safety_guard "$NODE" "$E2E/test_safety_guard.js"
run platform "$NODE" "$E2E/test_platform.js"
run revoke_rules "$NODE" "$E2E/test_revoke_rules.js"
run hub_js "$NODE" "$E2E/test_hub_js.js"
run no_silent_typing "$NODE" "$E2E/test_no_silent_typing.js"
run incoming_list "$NODE" "$E2E/test_incoming_list.js"
run preview_dedupe "$PHP" "$E2E/test_preview_dedupe.php"
run single_instance bash "$E2E/test_single_instance.sh"
run php_pipelines "$PHP" "$E2E/test_php_pipelines.php"
run no_auto_send "$PHP" "$E2E/test_no_auto_send.php"
run admission_ai_review "$PHP" "$E2E/test_admission_ai_review.php"
run admission_ai_api_engine "$PHP" "$E2E/test_admission_ai_api_engine.php"
run bridge_mock "$NODE" "$E2E/test_bridge_mock.js"
# Existing capability tests against TEST DB if they honor env — skip live ones that need Apache on :80
run lid_rules "$NODE" "$ROOT/automation/test-wa-lid-rules.js"

export E2E_RESULTS="$E2E/results"
export E2E_SUITES_OK="$pass"
export E2E_SUITES_FAILED="$fail"
python3 "$E2E/lib/write_results.py"

echo ""
echo "DONE suites_ok=$pass suites_failed=$fail"
echo "Results: $E2E/results/RESULTS.md"
if [ "$fail" -gt 0 ]; then exit 1; else exit 0; fi
