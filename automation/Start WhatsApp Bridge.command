#!/usr/bin/env bash
# Double-click in Finder to start the HCC WhatsApp bridge once (single instance, no auto-restart).
# Runs as YOU (the Chrome profile in automation/.whatsapp-session belongs to your user).
cd "$(dirname "$0")" || exit 1
unset WA_TEST_MODE HCC_DB_NAME WA_ALLOWED_CHATS
./start-bridge.sh
echo
echo "Now open the hub (WhatsApp Web Replica) — the QR shows in the top bar within ~15s."
echo "Log: /tmp/whatsapp-bridge.log   Stop: $(pwd)/stop-bridge.sh"
