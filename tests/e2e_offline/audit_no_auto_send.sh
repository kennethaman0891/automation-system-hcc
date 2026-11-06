#!/usr/bin/env bash
# Static proof: only user-press endpoints may enqueue or call bridge send.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
OUT="tests/e2e_offline/results/audit_no_auto_send.txt"
mkdir -p tests/e2e_offline/results
: > "$OUT"
fail=0

echo "=== audit_no_auto_send $(date) ===" | tee -a "$OUT"

EXCLUDE=(
  --glob '!**/node_modules/**'
  --glob '!**/target/**'
  --glob '!**/.whatsapp-session*/**'
  --glob '!**/vendor/**'
  --glob '!**/hcc-review-backup*/**'
  --glob '!**/backups/**'
  --glob '!tests/e2e_offline/results/**'
  --glob '!automation/whatsapp-bridge.js.bak'
)

section() { echo; echo "--- $1 ---"; echo "--- $1 ---" >> "$OUT"; }

assert_zero() {
  local label="$1"; shift
  local hits
  hits=$(rg -n "${EXCLUDE[@]}" "$@" 2>/dev/null | grep -v '^tests/' | grep -v 'audit_no_auto_send' || true)
  if [ -n "$hits" ]; then
    echo "FAIL $label"
    echo "$hits"
    echo "FAIL $label" >> "$OUT"
    echo "$hits" >> "$OUT"
    fail=$((fail+1))
  else
    echo "PASS $label (0 hits in prod code)"
    echo "PASS $label" >> "$OUT"
  fi
}

section "Forbidden lock / kill-switch / QA strings in prod code"
assert_zero "no_WA_SEND_MODE" -e 'WA_SEND_MODE' admin automation assets config
assert_zero "no_WA_ALLOW_QA_SEND" -e 'WA_ALLOW_QA_SEND' admin automation assets config
assert_zero "no_WA_REMOTE_ALERTS" -e 'WA_REMOTE_ALERTS_WHATSAPP' admin automation assets config
assert_zero "no_WA_SEND_STALE" -e 'WA_SEND_STALE_HOLD_SEC' admin automation assets config
assert_zero "no_BRIDGE_DO_NOT_START" -e 'BRIDGE_DO_NOT_START' admin automation assets config
assert_zero "no_whatsapp_send_lock" -e 'whatsapp_send_lock' admin automation assets config
assert_zero "no_qa_test_param" -e 'qa_test' admin automation assets config
assert_zero "no_X_Admin_Test" -e 'X-Admin-Test|X_Admin_Test|HTTP_X_ADMIN_TEST' admin automation assets config
assert_zero "no_blocked_auto_status_write" -e "status.*=.*'blocked_auto'|blocked_auto:" admin automation assets
assert_zero "no_held_status_write" -e "status.*=.*'held'|\"held\"" admin/includes admin/api_whatsapp*.php automation/whatsapp-bridge.js automation/start-bridge.sh

section "INSERT into whatsapp_outbound_queue — callers"
echo "All INSERT sites (excluding migrations):" | tee -a "$OUT"
INSERTS=$(rg -n "${EXCLUDE[@]}" -e 'INSERT INTO [`'\'']?whatsapp_outbound_queue' admin automation assets 2>/dev/null | grep -v migrate_ || true)
echo "$INSERTS" | tee -a "$OUT"
# Allowed files that may insert:
# - admin/includes/whatsapp_functions.php (waEnqueueWhatsAppOutbound)
# - admin/api_whatsapp_chat_actions.php (revoke on press)
ALLOWED_INSERT='admin/includes/whatsapp_functions.php|admin/api_whatsapp_chat_actions.php'
bad_ins=$(echo "$INSERTS" | grep -vE "$ALLOWED_INSERT" | grep -v '^$' || true)
if [ -n "$bad_ins" ]; then
  echo "FAIL unexpected outbound INSERT sites"
  echo "$bad_ins"
  echo "FAIL unexpected outbound INSERT sites" >> "$OUT"
  fail=$((fail+1))
else
  echo "PASS outbound INSERT only in enqueue helper + chat_actions revoke"
  echo "PASS outbound INSERT only in enqueue helper + chat_actions revoke" >> "$OUT"
fi

section "Who calls waEnqueueWhatsAppOutbound"
CALLERS=$(rg -n "${EXCLUDE[@]}" -e 'waEnqueueWhatsAppOutbound\s*\(' admin automation assets 2>/dev/null || true)
echo "$CALLERS" | tee -a "$OUT"
# Allowed: definition + api_whatsapp_send.php + api_whatsapp_templates.php
bad_call=$(echo "$CALLERS" | grep -v 'function waEnqueueWhatsAppOutbound' | grep -v 'api_whatsapp_send.php' | grep -v 'api_whatsapp_templates.php' | grep -v 'api_admission_ai_review.php' | grep -v '^$' || true)
if [ -n "$bad_call" ]; then
  echo "FAIL unexpected waEnqueue callers"
  echo "$bad_call"
  fail=$((fail+1))
  echo "FAIL unexpected waEnqueue callers" >> "$OUT"
else
  echo "PASS waEnqueue only from send + templates + ai_review send"
  echo "PASS waEnqueue only from send + templates + ai_review send" >> "$OUT"
fi

section "Bridge sendText/sendMedia/sendMessage callers"
# Inside bridge, sendText is called from sendQueuedMessage only ideally
BRIDGE_SEND=$(rg -n 'this\.sendText\(|this\.sendMedia\(|this\.sendMessage\(' automation/whatsapp-bridge.js 2>/dev/null || true)
echo "$BRIDGE_SEND" | tee -a "$OUT"
# whatsapp_sender.js must refuse
if rg -n 'puppeteer|web\.whatsapp\.com' automation/whatsapp_sender.js 2>/dev/null | grep -v '^$' >/dev/null; then
  echo "FAIL whatsapp_sender.js still contains send capability"
  fail=$((fail+1))
else
  echo "PASS whatsapp_sender.js has no send capability"
  echo "PASS whatsapp_sender.js has no send capability" >> "$OUT"
fi

section "AI / automated_send / followup must not enqueue WhatsApp"
assert_zero "ai_no_outbound_insert" -e 'whatsapp_outbound_queue' admin/api_whatsapp_ai.php
assert_zero "automated_send_is_410" -e 'INSERT INTO|waEnqueue' admin/api_automated_send.php
# followup may mention whatsapp URLs for manual open — but not outbound_queue
assert_zero "followup_no_outbound_queue" -e 'whatsapp_outbound_queue|waEnqueueWhatsAppOutbound' admin/api_admission_followup.php
assert_zero "rust_no_outbound_queue" -e 'whatsapp_outbound_queue' automation/rust_engine/src
assert_zero "admission_ai_api_no_outbound" -e 'whatsapp_outbound_queue|waEnqueueWhatsAppOutbound' admin/includes/admission_ai_api.php
# analyze/save/settings must not call enqueue except via the dedicated send case in api_admission_ai_review.php


section "Bridge background scraping never types into WhatsApp"
OPENCHAT_BODY=$(awk '/^  async openChat\(waChatId/{f=1} /^  async sendText\(waChatId, text\)/{f=0} f' automation/whatsapp-bridge.js)
if echo "$OPENCHAT_BODY" | grep -Eq 'keyboard\.(type|press|sendCharacter)'; then
  echo "FAIL openChat types into the page"; echo "FAIL openChat types into the page" >> "$OUT"; fail=$((fail+1))
else
  echo "PASS openChat has no keyboard typing"; echo "PASS openChat has no keyboard typing" >> "$OUT"
fi
SELF_BODY=$(awk '/^  async pollSelfChat\(\)/{f=1} /^  async pollMessages\(\)/{f=0} f' automation/whatsapp-bridge.js | grep -v '^\s*//')
if echo "$SELF_BODY" | grep -q 'openChat(selfJid'; then
  echo "FAIL pollSelfChat opens self chat by phone number"; echo "FAIL pollSelfChat by phone" >> "$OUT"; fail=$((fail+1))
else
  echo "PASS pollSelfChat never searches by linked number"; echo "PASS pollSelfChat never searches by linked number" >> "$OUT"
fi
if grep -q "q.origin = 'manual_ui' AND q.approved_by IS NOT NULL" admin/api_whatsapp_outbound_queue.php; then
  echo "PASS bridge queue feed requires manual_ui + approver"; echo "PASS bridge queue feed requires manual_ui + approver" >> "$OUT"
else
  echo "FAIL bridge queue feed not gated"; echo "FAIL bridge queue feed not gated" >> "$OUT"; fail=$((fail+1))
fi

section "start-bridge has no restart loop / kill switch"
if rg -n 'BRIDGE_DO_NOT_START|while true' automation/start-bridge.sh >/dev/null; then
  echo "FAIL start-bridge.sh still has kill-switch or restart loop"
  rg -n 'BRIDGE_DO_NOT_START|while true' automation/start-bridge.sh
  fail=$((fail+1))
else
  echo "PASS start-bridge.sh is start-once"
  echo "PASS start-bridge.sh is start-once" >> "$OUT"
fi

section "Lock file absent"
if [ -f admin/includes/whatsapp_send_lock.php ]; then
  echo "FAIL whatsapp_send_lock.php still exists"
  fail=$((fail+1))
else
  echo "PASS whatsapp_send_lock.php deleted"
  echo "PASS whatsapp_send_lock.php deleted" >> "$OUT"
fi
if [ -f admin/api_whatsapp_send_token.php ]; then
  echo "FAIL api_whatsapp_send_token.php still exists"
  fail=$((fail+1))
else
  echo "PASS api_whatsapp_send_token.php deleted"
  echo "PASS api_whatsapp_send_token.php deleted" >> "$OUT"
fi

echo
echo "AUDIT_FAILS=$fail" | tee -a "$OUT"
if [ "$fail" -gt 0 ]; then exit 1; fi
exit 0
