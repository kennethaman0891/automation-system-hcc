#!/usr/bin/env bash
#
# HCC WhatsApp Bridge — start once
# Starts a single Puppeteer bridge process. Does NOT restart on exit.
# Stop with: ./stop-bridge.sh
#
# Usage:  ./start-bridge.sh
# Logs :  /tmp/whatsapp-bridge.log

set -u
cd "$(dirname "$0")" || exit 1

# Pick a WORKING node binary (a broken Homebrew node can exist on PATH).
# Order: $HCC_NODE, newest NVM v24, newest NVM, PATH node, Homebrew, /usr/local, /usr/bin.
node_ok() { [ -x "$1" ] && "$1" --version >/dev/null 2>&1; }
NODE_BIN=""
CANDIDATES=()
[ -n "${HCC_NODE:-}" ] && CANDIDATES+=("$HCC_NODE")
if [ -d "$HOME/.nvm/versions/node" ]; then
  for d in $(ls -1 "$HOME/.nvm/versions/node" 2>/dev/null | grep '^v24' | sort -V -r); do CANDIDATES+=("$HOME/.nvm/versions/node/$d/bin/node"); done
  for d in $(ls -1 "$HOME/.nvm/versions/node" 2>/dev/null | sort -V -r); do CANDIDATES+=("$HOME/.nvm/versions/node/$d/bin/node"); done
fi
command -v node >/dev/null 2>&1 && CANDIDATES+=("$(command -v node)")
CANDIDATES+=(/opt/homebrew/bin/node /usr/local/bin/node /usr/bin/node)
for c in "${CANDIDATES[@]}"; do
  if node_ok "$c"; then NODE_BIN="$c"; break; fi
done
if [ -z "$NODE_BIN" ]; then
  echo "No working node binary found (set HCC_NODE=/path/to/node)" >&2
  exit 1
fi
export PATH="$(dirname "$NODE_BIN"):$PATH"

TMP_DIR="/tmp"
[ -d "$TMP_DIR" ] || TMP_DIR="${TMPDIR:-/tmp}"
LOG="$TMP_DIR/whatsapp-bridge.log"
LOCK="$TMP_DIR/whatsapp-bridge.instance.lock"

# Single-instance guard (correctness — shared Chrome profile)
if pgrep -f "node whatsapp-bridge.js" >/dev/null 2>&1; then
  echo "Bridge already running. Stop it first with ./stop-bridge.sh"
  exit 0
fi
if [ -e "$LOCK" ] && kill -0 "$(cat "$LOCK" 2>/dev/null)" 2>/dev/null; then
  echo "Bridge lock held by pid $(cat "$LOCK"). Exiting."
  exit 0
fi

# Clean up any leftover Chrome instances bound to this project's session
ps aux | awk '/\.whatsapp-session/ && !/awk/ {print $2}' | while read -r pid; do
  [ -n "$pid" ] && kill -9 "$pid" 2>/dev/null || true
done

# Clear stale Chrome singleton locks from a previous crash
rm -f .whatsapp-session/SingletonLock       .whatsapp-session/SingletonSocket       .whatsapp-session/SingletonCookie       .whatsapp-session/lockfile 2>/dev/null

# Ensure isolated Puppeteer Chrome is available
if ! "$NODE_BIN" -e "const p = require('puppeteer'); if (!require('fs').existsSync(p.executablePath())) process.exit(1);" 2>/dev/null; then
  echo "[start] Puppeteer Chrome missing, installing..." >> "$LOG"
  npx puppeteer install chrome >> "$LOG" 2>&1 || true
fi

if [[ "${1:-}" == "--foreground" ]] || [[ "${FOREGROUND:-0}" == "1" ]]; then
  echo "[start] $(date) starting bridge (supervised foreground)" >> "$LOG"
  echo "[start] node=$NODE_BIN ($("$NODE_BIN" --version))" >> "$LOG"
  echo "$$" > "$LOCK"
  exec "$NODE_BIN" whatsapp-bridge.js
fi

echo "[start] $(date) starting bridge (no auto-restart)" >> "$LOG"
# Run in background; record pid for stop-bridge.sh
echo "[start] node=$NODE_BIN ($("$NODE_BIN" --version))" >> "$LOG"
# exec name stays "node whatsapp-bridge.js" so the pgrep guard still matches
nohup "$NODE_BIN" whatsapp-bridge.js >> "$LOG" 2>&1 &
BPID=$!
echo "$BPID" > "$LOCK"
echo "Bridge started pid=$BPID  log=$LOG"
echo "Stop with: $(pwd)/stop-bridge.sh"
