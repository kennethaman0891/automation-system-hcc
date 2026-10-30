# Automation system HCC

PHP/XAMPP admin system: student management, timetable, WhatsApp bridge,
Google Drive sharing, certificates and parent-sign workflows.

## Requirements

- XAMPP (Apache + MySQL, PHP 8.3+)
- Composer
- Node.js (only for the WhatsApp bridge in `automation/`)

## Setup

1. Clone into XAMPP's docroot so URLs resolve as `/hcc/...`:
   ```
   cd /Applications/XAMPP/xamppfiles/htdocs
   git clone https://github.com/kennethaman0891/automation-system-hcc.git hcc
   ```
2. Restore PHP dependencies (PhpSpreadsheet):
   ```
   cd hcc && composer install
   ```
3. Create the environment config (it is gitignored — never commit real secrets):
   ```
   cp config/env.example.php config/env.php
   ```
   Edit `config/env.php` and set `DB_USER`, `DB_PASS`, and a real `JWT_SECRET`
   (generate with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`).
   The app is fail-closed: it will refuse to run until the placeholder
   JWT_SECRET is replaced.
4. Import the MySQL schema (database name from `DB_NAME`, default `hcc_admin`)
   and load `data/migrations/create_timetable_tables.sql`.
5. For the WhatsApp bridge:
   ```
   cd automation && npm install && node whatsapp-bridge.js
   ```
6. Open `http://localhost/hcc/admin/` and log in.

## Layout

| Path | Purpose |
|---|---|
| `admin/` | Admin panel pages and APIs |
| `config/` | Bootstrap + env loading (`env.php` ignored, `env.example.php` template) |
| `includes/` | Shared functions, Google Drive client |
| `automation/` | Node.js WhatsApp bridge + scripts |
| `data/` | Migrations and runtime state (partially ignored) |
| `public_*.php` | Certificate / card / slip pages |
| `vendor/` | Composer dependencies (ignored, restored via `composer install`) |

## Not tracked (by design, see `.gitignore`)

- `config/env.php` — real credentials
- `vendor/`, `automation/node_modules/` — dependencies
- `logs/`, `data/state.json` — runtime artifacts
- `.playwright-mcp/` — browser test logs
