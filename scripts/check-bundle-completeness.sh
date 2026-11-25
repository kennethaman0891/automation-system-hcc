#!/usr/bin/env bash
# Verify that a HCC install/bundle contains every admin PHP file.
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
MANIFEST="$ROOT/MANIFEST-ADMIN.txt"
EXPECTED=91

if [[ ! -f "$MANIFEST" ]]; then
    echo "ERROR: missing $MANIFEST" >&2
    exit 1
fi

count=0
missing=0
while IFS= read -r rel || [[ -n "$rel" ]]; do
    [[ -z "$rel" ]] && continue
    count=$((count + 1))
    if [[ ! -f "$ROOT/$rel" ]]; then
        echo "MISSING: $rel" >&2
        missing=$((missing + 1))
    fi
done < "$MANIFEST"

if [[ "$count" -ne "$EXPECTED" ]]; then
    echo "ERROR: manifest has $count entries, expected $EXPECTED" >&2
    exit 1
fi

actual=$(find "$ROOT/admin" -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
if [[ "$actual" -ne "$EXPECTED" ]]; then
    echo "ERROR: admin/ has $actual PHP files, expected $EXPECTED" >&2
    exit 1
fi

if [[ "$missing" -ne 0 ]]; then
    echo "ERROR: $missing admin file(s) missing" >&2
    exit 1
fi

echo "ADMIN COMPLETE: $count/$EXPECTED files present"
