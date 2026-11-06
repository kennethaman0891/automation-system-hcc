#!/usr/bin/env bash
# Prove start-bridge.sh refuses a second instance (start-once, no restart loop).
# Does NOT leave a live WhatsApp bridge running.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$(cd "$(dirname "$0")" && pwd)/results/single_instance.json"
LOCK="/tmp/whatsapp-bridge.instance.lock"

# Ensure no live bridge
pkill -f 'node whatsapp-bridge.js' 2>/dev/null || true
sleep 1
if pgrep -f 'node whatsapp-bridge.js' >/dev/null 2>&1; then
  echo '{"suite":"single_instance","passed":0,"failed":1,"results":[{"id":"SI0","name":"no live bridge during offline tests","ok":false}]}' > "$OUT"
  echo "FAIL SI0 live bridge still running"
  exit 1
fi

# Hold the instance lock with this shell's pid so start-bridge must refuse
echo $$ > "$LOCK"
set +e
msg=$(cd "$ROOT/automation" && ./start-bridge.sh 2>&1)
code=$?
set -e
echo "$msg" > /tmp/hcc-si-out.txt

ok=false
if echo "$msg" | grep -qiE 'already running|lock held'; then
  echo "PASS SI1 second start refused"
  ok=true
else
  echo "FAIL SI1 expected refuse, got: $msg"
  # Kill anything accidental
  pkill -f 'node whatsapp-bridge.js' 2>/dev/null || true
  pkill -f 'start-bridge.sh' 2>/dev/null || true
fi

python3 - <<PY
import json
ok = $( [[ "$ok" == true ]] && echo True || echo False )
open("$OUT","w").write(json.dumps({"suite":"single_instance","passed": 1 if ok else 0,"failed": 0 if ok else 1,"results":[{"id":"SI1","name":"second start refused","ok": ok}]}, indent=2))
PY

rm -f "$LOCK"
pkill -f 'node whatsapp-bridge.js' 2>/dev/null || true
sleep 1
if pgrep -f 'node whatsapp-bridge.js' >/dev/null 2>&1; then
  echo FAIL leftover bridge
  exit 1
fi
echo PASS single-instance checks
if [ "$ok" != true ]; then exit 1; fi
exit 0
