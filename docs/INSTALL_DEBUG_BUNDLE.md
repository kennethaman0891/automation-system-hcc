# HCC Debug Install Bundle

This branch ships a clean, ready-to-install copy of the HCC admin app plus
installer scripts and a debug doctor. It excludes all runtime/session/secret
data (WhatsApp sessions, logs, videos, `config/env.php`, `automation/.env`,
`vendor/`, `node_modules/`).

All **91 admin PHP files** are guaranteed present — verified by
`MANIFEST-ADMIN.txt` and `scripts/check-bundle-completeness.sh`.

## Direct download

Download the whole folder as a ZIP directly from GitHub:

```
https://github.com/kennethaman0891/automation-system-hcc/archive/refs/heads/debug-install-bundle.zip
```

Unzip it, then run the installer for your platform below.

## macOS / Linux

```bash
cd ~/Downloads/hcc-debug-install-bundle-main   # adjust to the extracted folder
chmod +x scripts/*.sh

# Default target: /Applications/XAMPP/xamppfiles/htdocs/hcc
bash scripts/install-debug-bundle.sh

# Or a custom target:
bash scripts/install-debug-bundle.sh /path/to/xampp/htdocs/hcc
```

The installer:
- copies all app files into the target (excluding runtime/secret paths),
- creates `config/env.php` from the example with a fresh random `JWT_SECRET`
  (never overwriting an existing one),
- creates writable `logs/` and `storage/whatsapp/{media,ipc}` dirs,
- runs `composer install` if Composer is available,
- verifies all 91 admin files.

## Windows (XAMPP)

```powershell
cd C:\Users\<you>\Downloads\hcc-debug-install-bundle-main
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\install-debug-bundle.ps1
# Custom target:
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\install-debug-bundle.ps1 -TargetDir C:\xampp\htdocs\hcc
```

Default target: `C:\xampp\htdocs\hcc`.

## After install

```
http://localhost/hcc/admin/login.php
```

Run the debug doctor:

```bash
php scripts/debug-doctor.php
```

It reports PHP version, required extensions, config status, writable dirs,
admin file count (expect `91/91`), and flags any forbidden artifacts.

## Troubleshooting

- **`JWT_SECRET still uses the CHANGE_ME placeholder`** — run
  `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and paste the value into
  `config/env.php`.
- **`composer install` failed** — install Composer 2 and rerun it inside the
  target folder; PhpSpreadsheet is required for Excel imports.
- **DB auth errors** — fill `DB_USER`/`DB_PASS` in `config/env.php` and create
  the `hcc_admin` database in phpMyAdmin.
- **Missing admin file** — rerun `bash scripts/check-bundle-completeness.sh`
  and confirm the extracted ZIP is complete.

## Privacy note

This bundle contains no real phone numbers, WhatsApp chats, session data, or
media. All numbers in source files are synthetic test fixtures.
