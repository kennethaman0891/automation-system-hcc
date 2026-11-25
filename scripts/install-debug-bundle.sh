#!/usr/bin/env bash
# Install the HCC debug bundle into a chosen XAMPP document root.
# Usage: install-debug-bundle.sh [target-dir]
#        HCC_TARGET_DIR=/path/to/hcc install-debug-bundle.sh
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

case "$(uname -s)" in
    Darwin) DEFAULT_TARGET="/Applications/XAMPP/xamppfiles/htdocs/hcc" ;;
    *) DEFAULT_TARGET="./hcc" ;;
esac

TARGET="${1:-${HCC_TARGET_DIR:-$DEFAULT_TARGET}}"
mkdir -p "$TARGET"
TARGET="$(cd "$TARGET" && pwd)"

if [[ "$TARGET" == "/" || "$TARGET" == "$SRC" ]]; then
    echo "ERROR: refusing to install into '$TARGET'." >&2
    exit 1
fi

echo "Installing HCC debug bundle into: $TARGET"

EXCLUDES=(
    --exclude='./.git'
    --exclude='./dist'
    --exclude='./node_modules'
    --exclude='./automation/node_modules'
    --exclude='./logs'
    --exclude='./storage/whatsapp/media'
    --exclude='./storage/whatsapp/ipc'
    --exclude='./config/env.php'
    --exclude='./automation/.env'
    --exclude='*.mp4'
    --exclude='*.mov'
    --exclude='*.webm'
    --exclude='./assets/images/wa-promo-poster.jpg'
)

(cd "$SRC" && tar -cf - "${EXCLUDES[@]}" .) | (cd "$TARGET" && tar -xf -)

mkdir -p "$TARGET/logs" "$TARGET/storage/whatsapp/media" "$TARGET/storage/whatsapp/ipc"

if [[ ! -f "$TARGET/config/env.php" && -f "$TARGET/config/env.example.php" ]]; then
    cp "$TARGET/config/env.example.php" "$TARGET/config/env.php"

    if command -v php >/dev/null 2>&1; then
        SECRET="$(php -r 'echo bin2hex(random_bytes(32));' 2>/dev/null || true)"
    fi
    if [[ -z "${SECRET:-}" ]] && command -v openssl >/dev/null 2>&1; then
        SECRET="$(openssl rand -hex 32)"
    fi
    if [[ -z "${SECRET:-}" ]] && command -v uuidgen >/dev/null 2>&1; then
        SECRET="$(uuidgen | tr -d '-')"
    fi
    if [[ -z "${SECRET:-}" ]]; then
        SECRET="LOCAL_DEV_SECRET_$(date +%s)_$RANDOM"
    fi

    PLACEHOLDER='CHANGE_ME_php_-r_echo_bin2hex_random_bytes_32_EOL'
    if [[ "$(uname -s)" == "Darwin" ]]; then
        sed -i '' "s#$PLACEHOLDER#$SECRET#g" "$TARGET/config/env.php"
    else
        sed -i "s#$PLACEHOLDER#$SECRET#g" "$TARGET/config/env.php"
    fi
    chmod 600 "$TARGET/config/env.php" 2>/dev/null || true
    echo "Created config/env.php with a locally generated JWT secret."
else
    echo "Kept existing config/env.php (not overwritten)."
fi

if command -v composer >/dev/null 2>&1 && [[ -f "$TARGET/composer.json" ]]; then
    (cd "$TARGET" && composer install --no-interaction --prefer-dist) \
        || echo "WARNING: composer install failed; run it manually." >&2
else
    echo "Composer not found or no composer.json; run 'composer install' manually if needed."
fi

"$TARGET/scripts/check-bundle-completeness.sh" "$TARGET"

echo
echo "Install complete."
echo "Local admin login: http://localhost/hcc/admin/login.php"
echo "Debug check: php $TARGET/scripts/debug-doctor.php"
