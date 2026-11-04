#!/usr/bin/env bash
#
# HCC WhatsApp Bridge supervisor
# Keeps the Puppeteer bridge running: restarts it automatically if it exits,
# and refuses to start a second copy.
#
# Usage:  ./start-bridge.sh
# Logs :  /tmp/whatsapp-bridge.log

set -u
cd "$(dirname "$0")" || exit 1

LOG="/tmp/whatsapp-bridge.log"
LOCK="/tmp/whatsapp-bridge.supervisor.lock"

# Single-instance guard
if [ -e "$LOCK" ] && kill -0 "$(cat "$LOCK" 2>/dev/null)" 2>/dev/null; then
  echo "Supervisor already running (pid $(cat "$LOCK")). Exiting."
  exit 0
fi
echo $$ > "$LOCK"

cleanup() {
  rm -f "$LOCK"
  pkill -f "node whatsapp-bridge.js" 2>/dev/null
  exit 0
}
trap cleanup INT TERM

while true; do
  # Never allow two bridges at once (they share the Chrome profile)
  if pgrep -f "node whatsapp-bridge.js" >/dev/null 2>&1; then
    sleep 3
    continue
  fi

  echo "[supervisor] $(date) starting bridge" >> "$LOG"
  node whatsapp-bridge.js >> "$LOG" 2>&1
  code=$?
  echo "[supervisor] $(date) bridge exited with code $code — restarting in 10s" >> "$LOG"

  # Clear any lock left behind by a crash before the next attempt
  rm -f .whatsapp-session/SingletonLock \
        .whatsapp-session/SingletonSocket \
        .whatsapp-session/SingletonCookie 2>/dev/null

  sleep 10
done
