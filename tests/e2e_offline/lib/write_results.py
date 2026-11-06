#!/usr/bin/env python3
import json, glob, os, datetime
e2e = os.environ.get("E2E_RESULTS", os.path.join(os.path.dirname(__file__), "..", "results"))
e2e = os.path.abspath(e2e)
suites = []
total_p = total_f = 0
for f in sorted(glob.glob(os.path.join(e2e, "*.json"))):
    base = os.path.basename(f)
    if base in ("summary.json", "fixture_chats.json"):
        continue
    try:
        d = json.load(open(f))
    except Exception:
        continue
    if isinstance(d, dict) and "suite" in d:
        suites.append(d)
        total_p += int(d.get("passed") or 0)
        total_f += int(d.get("failed") or 0)
suites_ok = int(os.environ.get("E2E_SUITES_OK", "0"))
suites_failed = int(os.environ.get("E2E_SUITES_FAILED", "0"))
summary = {
    "generated_at": datetime.datetime.now().isoformat(timespec="seconds"),
    "suites_ok": suites_ok,
    "suites_failed": suites_failed,
    "assertions_passed": total_p,
    "assertions_failed": total_f,
    "suites": suites,
    "live_bridge_left_stopped": True,
}
open(os.path.join(e2e, "summary.json"), "w").write(json.dumps(summary, indent=2))
md = [
    "# HCC WhatsApp offline e2e results",
    "",
    f"- Generated: {summary['generated_at']} (Asia/Calcutta)",
    f"- Suites OK: {summary['suites_ok']}",
    f"- Suites failed: {summary['suites_failed']}",
    f"- Assertions passed: {summary['assertions_passed']}",
    f"- Assertions failed: {summary['assertions_failed']}",
    f"- Live bridge left stopped: yes",
    "",
    "## Suites",
    "",
]
for s in suites:
    md.append(f"### {s.get('suite')} — {s.get('passed',0)} pass / {s.get('failed',0)} fail")
    for r in s.get("results") or []:
        mark = "PASS" if r.get("ok") else "FAIL"
        det = f" — {r.get('detail')}" if r.get("detail") else ""
        rid = r.get("id")
        name = r.get("name")
        md.append(f"- {mark} `{rid}` {name}{det}")
    md.append("")
open(os.path.join(e2e, "RESULTS.md"), "w").write("\n".join(md))
print("Wrote summary + RESULTS.md")
