#!/usr/bin/env bash
# Stop the HCC WhatsApp bridge (and any leftover Chrome for this session).
set -u
cd "$(dirname "$0")" || exit 1
TMP_DIR="/tmp"
[ -d "$TMP_DIR" ] || TMP_DIR="${TMPDIR:-/tmp}"
LOCK="$TMP_DIR/whatsapp-bridge.instance.lock"
LOG="$TMP_DIR/whatsapp-bridge.log"

if [ -e "$LOCK" ]; then
  pid=$(cat "$LOCK" 2>/dev/null || true)
  if [ -n "${pid:-}" ] && kill -0 "$pid" 2>/dev/null; then
    kill "$pid" 2>/dev/null || true
    sleep 1
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$LOCK"
fi
pkill -f "node whatsapp-bridge.js" 2>/dev/null || true
# Only Chrome tied to this project's session dir
ps aux | awk '/\.whatsapp-session/ && !/awk/ {print $2}' | while read -r pid; do
  [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  [ -n "$pid" ] && kill -9 "$pid" 2>/dev/null || true
done
echo "[stop] $(date) bridge stopped" >> "$LOG"
echo "Bridge stopped."
