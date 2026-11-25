#!/usr/bin/env python3
"""
HCC Repository Privacy & Leak Guard.
Blocks real phone numbers, non-fixture WhatsApp JIDs, WhatsApp chat exports,
secrets/private keys, local user paths, and forbidden runtime files from
being committed or pushed.

Usage:
  python3 scripts/check_privacy_leaks.py           # scan all tracked files
  python3 scripts/check_privacy_leaks.py --staged  # scan staged files (pre-commit)
"""
import argparse
import os
import re
import subprocess
import sys

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Explicit allowlist of obviously synthetic / placeholder / official-fixture phone numbers (digits only, 10 or 12 digits)
ALLOWED_PHONE_DIGITS = {
    # Standard synthetic placeholders
    "9876543210", "919876543210",
    "9812345678", "919812345678",
    "9000000001", "919000000001",
    "9800011111", "919800011111",
    "9811111111", "919811111111",
    "9822222222", "919822222222",
    "9833333333", "919833333333",
    "9876599999", "919876599999",
    "9111111999", "919111111999",
    "9100001111", "919100001111",
    "9999999998", "919999999998",
    "9999999999", "919999999999",
    # E2E offline fixture & decoy numbers (9999000xxx)
    "9999000001", "919999000001",
    "9999000077", "919999000077",
    "9999000099", "919999000099",
    "9999000123", "919999000123",
    "9999000501", "919999000501",
    "9999000502", "919999000502",
    # Approved synthetic school demo series (+91 98016 60000..60010)
    *[f"98016600{i:02d}" for i in range(0, 11)],
    *[f"9198016600{i:02d}" for i in range(0, 11)],
    # Synthetic substitute allocator demo series (9822001101..9822001124)
    *[f"98220011{i:02d}" for i in range(1, 25)],
    *[f"9198220011{i:02d}" for i in range(1, 25)],
}

ALLOWED_JIDS = {
    "12345@g.us",
    "120363@g.us",
    "120363999000002@g.us",
    "120363000000009999@g.us",
    "120363999888777@g.us",
    "123456789012@lid",
    "123456789012345@lid",
    "154321098765432@lid",
    "987654321012345@lid",
    "999@lid",
    "false_999@lid",
    "some-group@lid",
    "slug@lid",
    "name@lid",
    "digits@lid",
    "non-@lid",
    "91xxxxxxxxxx@c.us",
    "s@g.us",
}

FORBIDDEN_PATH_PATTERNS = [
    re.compile(r"^config/env\.php$"),
    re.compile(r"(^|/)\.env($|\.(?!example$))"),
    re.compile(r"\.whatsapp-session"),
    re.compile(r"^storage/whatsapp/(?!.*/\.gitkeep$)"),
    re.compile(r"^data/private/"),
    re.compile(r"^data/state\.json$"),
    re.compile(r"^logs/(?!\.htaccess$)"),
    re.compile(r"^tests/e2e_offline/results/(?!\.gitkeep$)"),
    re.compile(r"^tests/last_.*_result\.json$"),
    re.compile(r"\.(?:db|sqlite|sqlite3|pem|key|p12|pfx|vcf|log|pid|mp4|mov|webm)$", re.I),
    re.compile(r"^assets/videos/(?!\.gitkeep$)"),
    re.compile(r"^assets/images/wa-promo-poster\.jpg$"),
    re.compile(r"whatsapp[\s_-]*chat.*\.(?:txt|zip|json|csv|html)$", re.I),
    re.compile(r"chat[\s_-]*export", re.I),
]

SKIP_CONTENT_FILES = {
    "automation/package-lock.json",
    "composer.lock",
    "automation/admission-ai-service/Cargo.lock",
    "automation/rust_engine/Cargo.lock",
    "scripts/check_privacy_leaks.py",
}

PHONE_RE = re.compile(r"(?<!\d)(?:\+?91[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}(?!\d)")
JID_RE = re.compile(r"[0-9A-Za-z._-]+@(?:s\.whatsapp\.net|c\.us|g\.us|lid|broadcast)", re.I)
WA_EXPORT_RE = re.compile(r"^\[?\d{1,2}/\d{1,2}/\d{2,4},\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[APap][Mm])?\]?\s*[-–]?\s+[^:\n]{2,40}:\s+\S+")
SECRET_RE = re.compile(
    r"(?:AIza[0-9A-Za-z\-_]{35}|sk-[0-9A-Za-z]{20,}|ghp_[0-9A-Za-z]{36}|AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |EC |OPENSSH |)PRIVATE KEY-----)"
)
USER_PATH_RE = re.compile(r"(?:/Users/(?!<redacted>|yourname|username|user\b)[a-zA-Z0-9._-]+|C:\\Users\\(?!<redacted>|yourname|username|user\b)[a-zA-Z0-9._-]+)")


def git_files(staged: bool):
    cmd = ["git", "diff", "--cached", "--name-only", "--diff-filter=ACM"] if staged else ["git", "ls-files"]
    out = subprocess.check_output(cmd, cwd=REPO_ROOT).decode("utf-8", errors="replace")
    return [p for p in out.splitlines() if p.strip()]


def is_allowed_jid(jid: str) -> bool:
    low = jid.lower()
    if low in ALLOWED_JIDS:
        return True
    # Strip optional true_/false_ message id prefix
    core = re.sub(r"^(?:true|false)_", "", low)
    if core in ALLOWED_JIDS:
        return True
    user_part = core.split("@", 1)[0]
    digits = re.sub(r"\D", "", user_part)
    if digits and digits in ALLOWED_PHONE_DIGITS:
        return True
    return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--staged", action="store_true", help="Scan only git staged files")
    args = parser.parse_args()

    files = git_files(args.staged)
    violations = []

    for rel in files:
        for pat in FORBIDDEN_PATH_PATTERNS:
            if pat.search(rel):
                violations.append((rel, 0, "FORBIDDEN_FILE", f"Matched blocked path rule: {pat.pattern}"))
                break

        if rel in SKIP_CONTENT_FILES:
            continue

        full = os.path.join(REPO_ROOT, rel)
        if not os.path.isfile(full):
            continue
        try:
            raw = open(full, "rb").read()
        except OSError:
            continue
        if b"\x00" in raw[:4096]:
            continue

        text = raw.decode("utf-8", errors="replace")
        for lineno, line in enumerate(text.splitlines(), 1):
            for m in PHONE_RE.finditer(line):
                digits = re.sub(r"\D", "", m.group(0))
                if digits not in ALLOWED_PHONE_DIGITS:
                    violations.append((rel, lineno, "UNAPPROVED_PHONE", m.group(0)))

            for m in JID_RE.finditer(line):
                if not is_allowed_jid(m.group(0)):
                    violations.append((rel, lineno, "UNAPPROVED_WA_JID", m.group(0)))

            if WA_EXPORT_RE.match(line):
                violations.append((rel, lineno, "WA_CHAT_EXPORT", line[:80]))

            for m in SECRET_RE.finditer(line):
                # Ignore explicit unit-test redaction fixture
                if "sk-abcdefghijklmnop" in m.group(0):
                    continue
                violations.append((rel, lineno, "SECRET_OR_KEY", m.group(0)[:24] + "..."))

            for m in USER_PATH_RE.finditer(line):
                violations.append((rel, lineno, "LOCAL_USER_PATH", m.group(0)))

    if violations:
        print(f"PRIVACY GUARD FAILED: {len(violations)} violation(s) detected:\n", file=sys.stderr)
        for rel, lineno, kind, detail in violations:
            loc = f"{rel}:{lineno}" if lineno else rel
            print(f"  [{kind}] {loc} -> {detail}", file=sys.stderr)
        print(
            "\nFix or redact the items above before committing. "
            "Synthetic test numbers must come from the allowlist in scripts/check_privacy_leaks.py.",
            file=sys.stderr,
        )
        return 1

    print(f"Privacy guard passed ({len(files)} file(s) checked, 0 leaks).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
