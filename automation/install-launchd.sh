#!/usr/bin/env bash
# Install (or refresh) the HCC WhatsApp bridge LaunchAgent without hardcoding
# an absolute XAMPP path into the repo.
#
# Usage:
#   ./automation/install-launchd.sh
#   ./automation/install-launchd.sh --unload
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
TEMPLATE="$SCRIPT_DIR/com.hcc.whatsapp-bridge.plist.template"
LABEL="com.hcc.whatsapp-bridge"
DEST="${HOME}/Library/LaunchAgents/${LABEL}.plist"
LOG_DIR="${TMPDIR:-/tmp}"
LOG_DIR="${LOG_DIR%/}"
LOG_FILE="${LOG_DIR}/whatsapp-bridge.launchd.log"

if [[ "${1:-}" == "--unload" ]]; then
  launchctl bootout "gui/$(id -u)/${LABEL}" 2>/dev/null || launchctl unload "$DEST" 2>/dev/null || true
  rm -f "$DEST"
  echo "Unloaded and removed $DEST"
  exit 0
fi

if [[ ! -f "$TEMPLATE" ]]; then
  echo "ERROR: missing template $TEMPLATE" >&2
  exit 1
fi
if [[ ! -x "$SCRIPT_DIR/start-bridge.sh" ]]; then
  chmod +x "$SCRIPT_DIR/start-bridge.sh" || true
fi

mkdir -p "$(dirname "$DEST")"
# Escape & for XML safety in paths (unlikely but cheap)
xml_escape() { printf '%s' "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'; }

AUTO_XML="$(xml_escape "$SCRIPT_DIR")"
LOG_XML="$(xml_escape "$LOG_FILE")"
sed -e "s|__HCC_AUTOMATION_DIR__|${AUTO_XML}|g" \
    -e "s|__HCC_LAUNCHD_LOG__|${LOG_XML}|g" \
    "$TEMPLATE" > "$DEST"

plutil -lint "$DEST" >/dev/null

# Prefer modern bootout/bootstrap when available
UID_NUM="$(id -u)"
launchctl bootout "gui/${UID_NUM}/${LABEL}" 2>/dev/null || launchctl unload "$DEST" 2>/dev/null || true
if launchctl bootstrap "gui/${UID_NUM}" "$DEST" 2>/dev/null; then
  launchctl enable "gui/${UID_NUM}/${LABEL}" 2>/dev/null || true
  launchctl kickstart -k "gui/${UID_NUM}/${LABEL}" 2>/dev/null || true
else
  launchctl load "$DEST"
fi

echo "Installed LaunchAgent: $DEST"
echo "WorkingDirectory: $SCRIPT_DIR"
echo "Logs: $LOG_FILE"
echo "Status: launchctl print gui/${UID_NUM}/${LABEL} 2>/dev/null | head"
