#!/usr/bin/env bash
# Build a clean ZIP of tracked HCC files only. Runtime/session/vendor files are
# excluded automatically because they are not tracked by Git.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"
OUT="$DIST/hcc-debug-bundle.zip"

mkdir -p "$DIST"
rm -f "$OUT"

if ! git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "ERROR: build requires a Git checkout of this branch." >&2
    exit 1
fi

git -C "$ROOT" archive --format=zip -o "$OUT" HEAD
"$ROOT/scripts/check-bundle-completeness.sh" "$ROOT"

echo "Built: $OUT"
echo "This ZIP contains tracked files only; no secrets, sessions, logs, or vendor data."
