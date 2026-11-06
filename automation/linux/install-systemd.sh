#!/usr/bin/env bash
# Install the HCC WhatsApp bridge as a systemd --user service.
# Equivalent to macOS install-launchd.sh and Windows start-bridge / NSSM.
#
# Usage (on Linux):
#   ./automation/linux/install-systemd.sh
#   ./automation/linux/install-systemd.sh --system   # needs root; installs under /etc/systemd/system
#   ./automation/linux/install-systemd.sh --unload
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
AUTOMATION_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_ROOT="$(cd "$AUTOMATION_DIR/.." && pwd)"
TEMPLATE="$SCRIPT_DIR/hcc-whatsapp-bridge.service.template"
UNIT_NAME="hcc-whatsapp-bridge.service"
MODE="user"

for arg in "$@"; do
  case "$arg" in
    --system) MODE="system" ;;
    --unload)
      if [[ "$MODE" == "system" ]] || [[ "${2:-}" == "--system" ]]; then
        sudo systemctl disable --now "$UNIT_NAME" 2>/dev/null || true
        sudo rm -f "/etc/systemd/system/$UNIT_NAME"
        sudo systemctl daemon-reload
      else
        systemctl --user disable --now "$UNIT_NAME" 2>/dev/null || true
        rm -f "${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user/$UNIT_NAME"
        systemctl --user daemon-reload
      fi
      echo "Unloaded $UNIT_NAME"
      exit 0
      ;;
    --help|-h)
      sed -n '2,12p' "$0"
      exit 0
      ;;
  esac
done

if [[ ! -f "$TEMPLATE" ]]; then
  echo "ERROR: missing $TEMPLATE" >&2
  exit 1
fi
chmod +x "$AUTOMATION_DIR/start-bridge.sh" || true

render() {
  sed -e "s|__HCC_AUTOMATION_DIR__|${AUTOMATION_DIR}|g" \
      -e "s|__HCC_REPO_ROOT__|${REPO_ROOT}|g" \
      "$TEMPLATE"
}

if [[ "$MODE" == "system" ]]; then
  DEST="/etc/systemd/system/$UNIT_NAME"
  echo "Installing system unit to $DEST (sudo)..."
  render | sudo tee "$DEST" >/dev/null
  if command -v systemd-analyze >/dev/null 2>&1; then
    systemd-analyze verify "$DEST" || true
  fi
  sudo systemctl daemon-reload
  sudo systemctl enable --now "$UNIT_NAME"
  echo "Enabled system service $UNIT_NAME"
  echo "Logs: journalctl -u $UNIT_NAME -f"
else
  DEST_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
  DEST="$DEST_DIR/$UNIT_NAME"
  mkdir -p "$DEST_DIR"
  render > "$DEST"
  if command -v systemd-analyze >/dev/null 2>&1; then
    systemd-analyze verify "$DEST" || true
  fi
  systemctl --user daemon-reload
  systemctl --user enable --now "$UNIT_NAME"
  echo "Enabled user service $UNIT_NAME"
  echo "Logs: journalctl --user -u $UNIT_NAME -f"
  echo "Tip: loginctl enable-linger \$USER   # keep running after logout"
fi

echo "WorkingDirectory: $AUTOMATION_DIR"
echo "Start script: $AUTOMATION_DIR/start-bridge.sh"
