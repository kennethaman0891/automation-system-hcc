# Windows Compatibility Report — HCC / School WhatsApp Hub

**Date:** 2026-11-17
**Update 2026-11-24:** On Windows the bridge and PHP share flags in `storage/whatsapp/ipc` (not each user's temp folder). `automation\start-bridge.bat` starts the restarting supervisor. The Windows start scripts refuse a copied macOS Mach-O binary. A macOS `DB_SOCKET` value in `config\env.php` is ignored on Windows so MySQL uses TCP.
**Audience:** School IT administrator (Windows PC deployment)
**Scope:** Can this project run end-to-end on Windows, what hardware/software is required, and exactly what must be done before it can be called ready?
**Method:** Direct inspection of the checkout at `C:\...\htdocs\hcc` equivalent (macOS path `/Applications/XAMPP/xamppfiles/htdocs/hcc`) on 2026-11-17. Evidence was taken from `.gitignore`, `composer.json`, `config/*.php`, `data/migrations/*`, `admin/*.php`, `admin/includes/*.php`, `automation/*` (Rust source, Node bridge, Windows scripts), `.htaccess` files, and `docs/*`. Version numbers were taken from `php -v` (PHP 8.5.12), `node -v` (v24.19.0), `composer.json`, and `automation/package.json`. Findings are stated honestly; items not yet tested on Windows are labelled as such.

---

## 1. Verdict

**Yes — this project can run end-to-end on Windows. But not from a plain `git clone`, and not without additional administrator work.**

The PHP application, database layer, Rust services, Node bridge, and Windows startup scripts are all present and largely portable. Three hard blockers prevent a clean-clone deployment, and four configuration/behaviour warnings must be handled. The single most important governance point: **the mandatory end-to-end test has only passed on the macOS development machine, not on Windows.** That Windows run is the release gate.

### TL;DR — blocker and risk table

| # | Severity | Issue | Evidence | Required action |
|---|----------|-------|----------|-----------------|
| 1 | **BLOCKER** | Both Rust service source trees are gitignored. A fresh clone contains **0 Rust source files**: no `Cargo.toml`, no `Cargo.lock`, no `src/`. | `.gitignore:20-21`; `git ls-files 'automation/rust_engine/**' 'automation/admission-ai-service/**'` → `0` | Copy the two full folders from this Mac checkout, or obtain prebuilt Windows `.exe` files. README clone instructions do not mention this. |
| 2 | **BLOCKER** | The compiled binaries currently in the tree are **macOS Mach-O arm64**, not Windows PE. `hcc-automation-engine` is 3.3 MB Mach-O; `target/` trees total roughly 710 MB (engine) and 1.1 GB (AI service). | `file automation/rust_engine/target/release/hcc-automation-engine` → `Mach-O 64-bit executable arm64` | Rebuild with Rust stable + MSVC linker on Windows (`cargo build --release`). **Do not copy the macOS artifacts.** |
| 3 | **BLOCKER / CONFIG** | `config/env.php` is gitignored. The template comment names a macOS socket; an unset/incorrect socket or placeholder `JWT_SECRET` will stop the app. | `.gitignore:2`; `config/env.example.php:12,30-31`; `config/database.php:75-78` | Copy `env.example.php` → `env.php`; set `DB_HOST=127.0.0.1`, `DB_SOCKET=''`, real random `JWT_SECRET`. |
| 4 | **WARNING** | Migration SQL uses MariaDB-only `ADD COLUMN/INDEX IF NOT EXISTS`. Standard MySQL 8 rejects it; PHP call sites catch the error and continue, leaving **columns missing** rather than failing loudly. | `data/migrations/2026_11_reconcile_wa_ai_schema.sql:8-11,15-18,22-26,30+`; `data/migrations/alter_admission_ai_review_provenance.sql:8-11`; `admin/migrate_whatsapp_replica.php:221-223`; `admin/includes/whatsapp_functions.php:230-231`; `admin/migrate_ai_admission.php:115-116` | Use **MariaDB 10.4+** (10.6+/11.x as the migration header states), or rewrite those statements with `information_schema` checks before running on MySQL 8. |
| 5 | **WARNING** | `BASE_URL` and `.htaccess` hardcode `/hcc/`; the docroot and URL are fixed. | `config/database.php:85`; root `.htaccess:6-13,28-30` | Install at `C:\xampp\htdocs\hcc` and browse `http://localhost/hcc/admin/`. Any other path needs code and `.htaccess` edits. |
| 6 | **WARNING** | Security is Apache-only: **12 `.htaccess` files** use Apache 2.4 directives. IIS ignores them. | Root `.htaccess:2,15-18,27-30`; `config/.htaccess`, `data/private/.htaccess`, `knowledge/.htaccess`, `storage/.htaccess`, etc. | Use XAMPP Apache with `AllowOverride All`. On IIS, translate to `web.config` `<requestFiltering>`/authorization rules or sensitive directories become web-accessible. |
| 7 | **WARNING** | PHP daemon status check depends on Windows `tasklist`/`taskkill`, `.exe` name, and `exec()`. | `admin/api_admission_followup.php:65-79,790-805`; `tests/lib_cli_session.php:121`; `proc_open` at `api_admission_followup.php:121` | Keep `exec()` enabled in `php.ini`. `proc_open` array form is supported on PHP 7.4+, so PHP 8.3+ is fine. |
| — | **GATE** | Windows E2E run has **not** been performed. | `docs/TEST_REPORT.md:7,20` (25 passed on macOS) | Run `C:\xampp\php\php.exe tests\admission_workflow_e2e.php` on the Windows PC before go-live. |

### What is already Windows-ready

- The WhatsApp bridge has genuine Windows paths and process handling: `automation/whatsapp-bridge.js:46-59` (Chrome/Edge under Program Files, Program Files (x86), `%LOCALAPPDATA%`), `:99-102` (`os.tmpdir()` when `win32`), `:3021` (skips POSIX `ps`), `:3698-3700` (`taskkill /F /PID`), `:4567-4579` (Windows `SIGINT` handling).
- Tracked Windows scripts: `automation/start-bridge.bat`, `automation/windows/start-admission-ai.ps1`, `start-followup-engine.ps1`, `start-bridge.ps1`, `register-services.ps1` (PowerShell 5.1+, optional NSSM, `schtasks` fallback).
- Rust source is portable: no `cfg(unix)`, `std::os::unix`, `libc::` or POSIX `signal` calls in either crate's `src/`. `automation/rust_engine/src/db.rs:125-152,221-229` uses `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`/`DB_NAME`, optional `DB_SOCKET`, and TCP fallback to `127.0.0.1:3306`. `automation/admission-ai-service/src/config.rs:19-49` already includes `C:\xampp\htdocs\hcc\knowledge\approved` as a candidate path.
- `.htaccess` blocks `config/`, `data/`, `logs/`, `vendor/`, `automation/`, `knowledge/`, `tools/`, `scratch/`, `storage/` and dotfiles (root `.htaccess:19-30`). Writable runtime dirs used by the code: `logs/` (`admin/forgot_password.php:61-90`), `storage/whatsapp` + `storage/workspace` (`admin/api_timetable.php:24-37`, `admin/api_whatsapp_media.php:40`), `logs/whatsapp-bridge.log`, `automation/.whatsapp-session`.
- Documentation already exists: `docs/WINDOWS_DEPLOYMENT.md`, `docs/CONFIGURATION.md`, `docs/BACKUP_AND_RECOVERY.md`, `docs/HCC_MASTER_INSTRUCTION_GUIDE.md`, `docs/KNOWN_LIMITATIONS.md`, `docs/TEST_REPORT.md`.

---

## 2. Recommended Windows stack (minimum exact versions and why)

| Component | Minimum / exact version | Why |
|-----------|-------------------------|-----|
| OS | **Windows 10 or 11, 64-bit** | 64-bit builds of Ollama, Node, Rust MSVC linker and XAMPP are expected. 32-bit is not supported by this stack. |
| Web server | **Apache 2.4** (via XAMPP for Windows) | 12 `.htaccess` files use Apache 2.4 syntax (`Require all denied`, `Options -Indexes`, `<IfModule mod_authz_core.c>`, `mod_rewrite`). IIS would silently ignore them. |
| PHP | **8.3+** (development machine: 8.5.12) | `composer.json:4` requires `phpspreadsheet ~5.4` with `php ^8.2`; project guidance sets 8.3+. Required extensions: `ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`, plus **PDO MySQL**, **curl**, **openssl**. `proc_open()` array form needs 7.4+. `exec()` must be enabled. |
| Database | **MariaDB 10.4+** — 10.6+/11.x preferred — **or** MySQL 8 only after SQL rewrite | Migrations use MariaDB-only `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS`. Current XAMPP Windows releases bundle MariaDB, which is the safe choice. |
| Package manager | **Composer 2** | `composer install` in repo root; `vendor/` is not committed. |
| Rust | **rustup stable + Visual Studio C++ Build Tools (MSVC toolchain)** | Both services are Rust 2021 edition (`Cargo.toml:4` in each crate). MSVC linker is required to produce `.exe` on Windows. |
| Node.js | **18+** (development machine: v24.19.0; `automation/package.json` `engines.node >=18.0.0`) | WhatsApp bridge + Puppeteer 22. |
| Browser | **Google Chrome or Microsoft Edge** (current) | Bridge drives real WhatsApp Web via Puppeteer; Windows install paths are auto-detected. |
| Ollama | **Ollama for Windows + `llama3.2:1b`** | AI service defaults to `http://127.0.0.1:11434` and model `llama3.2:1b` (`admission-ai-service/src/config.rs:39-43`). One small model only on 8 GB. |
| Shell / services | **PowerShell 5.1+**; **NSSM optional** | Startup scripts are `.ps1`. `register-services.ps1` uses NSSM when present and prints `schtasks` commands otherwise. |
| Install location | **`C:\xampp\htdocs\hcc`** | `BASE_URL='/hcc/'` (`config/database.php:85`) and hardcoded `/hcc/` rewrites/deny regex (root `.htaccess`) assume this path. |

**Explicitly not required / not wanted:** WhatsApp Business Cloud API, unofficial WhatsApp APIs, stored WhatsApp passwords (`docs/WINDOWS_DEPLOYMENT.md:17-19`).

---

## 3. Hardware specifications

| Profile | CPU | RAM | Disk | Rationale |
|---------|-----|-----|------|-----------|
| **Minimum** (documented baseline, `docs/WINDOWS_DEPLOYMENT.md:3`) | Intel 4-core | **8 GB** | **500 GB** total | The documented single-PC target. 8 GB can hold one small Ollama model; `AI_INFERENCE_CONCURRENCY` must stay at 1. Tight once Chrome/Puppeteer and MariaDB are also resident. |
| **Recommended** (practical guidance, not a project requirement) | 8-core modern CPU | **16 GB** | SSD with **100 GB free** | Headroom for Ollama `llama3.2:1b` + Puppeteer Chrome + MariaDB + Apache simultaneously; reduces contention during E2E and staff use. |
| **Multi-user / heavier use** | 8+ cores | **16–32 GB** | SSD, 100–200 GB free | More concurrent staff sessions and a longer follow-up queue. **Do not scale by raising AI concurrency**: the Windows start script refuses concurrency ≠ 1 (`docs/WINDOWS_DEPLOYMENT.md:57,65,172`). Scale by faster CPU/SSD and by keeping one model loaded. |

**Why these numbers:** the machine runs four resident workloads — (1) Ollama holding `llama3.2:1b`, (2) the Rust axum AI service, (3) MariaDB + Apache under XAMPP plus a Puppeteer-controlled Chrome if the bridge is used, and (4) the Rust follow-up daemon. On 8 GB, treat concurrency as **1** and run only one model (`docs/TEST_REPORT.md:56-58`).

---

## 4. Blocker-by-blocker detail, with evidence and exact fix

### 4.1 BLOCKER — Rust source is absent from a fresh clone

- **Evidence:** `.gitignore:20` = `automation/admission-ai-service/`, `.gitignore:21` = `automation/rust_engine/`. `git ls-files` over both paths returns **0** files. There is no tracked `Cargo.toml`, `Cargo.lock`, or `src/` for either service. The README's clone instructions omit this step.
- **Consequence:** `http://localhost/hcc/admin/` may load, but AI draft generation and the follow-up engine cannot run at all.
- **Exact fix:** From this Mac checkout, copy the **complete folders** `automation/rust_engine/` and `automation/admission-ai-service/` (source and manifests; you may exclude `target/`) into `C:\xampp\htdocs\hcc\automation\`. Alternatively supply prebuilt Windows `.exe` files built from that source. Update the README clone instructions to say this explicitly.

### 4.2 BLOCKER — Current binaries are macOS artifacts

- **Evidence:** `file automation/rust_engine/target/release/hcc-automation-engine` → `Mach-O 64-bit executable arm64`; the file is 3.3 MB. `automation/rust_engine/target/release/` contains `.dylib` proc-macro libraries and about 710 MB of artifacts; `automation/admission-ai-service/target/` about 1.1 GB.
- **Consequence:** Windows cannot execute Mach-O. Copying them produces "not a valid Win32 application".
- **Exact fix:** Install rustup stable + VS C++ Build Tools, then on Windows:
  ```
  cd C:\xampp\htdocs\hcc\automation\admission-ai-service
  cargo build --release
  cd ..\rust_engine
  cargo build --release
  ```
  Expected: `automation\admission-ai-service\target\release\admission-ai-service.exe` and `automation\rust_engine\target\release\hcc-automation-engine.exe`. A copy at `automation\bin\hcc-automation-engine.exe` is also accepted (`docs/WINDOWS_DEPLOYMENT.md:67-83`). **Do not copy the macOS `target/` directories** — they are large, wrong architecture, and useless on Windows.

### 4.3 BLOCKER/CONFIG — `config/env.php` is gitignored and the template mentions macOS

- **Evidence:** `.gitignore:2` = `config/env.php`. `config/env.example.php:11-12` says copy to `config/env.php`. `:30` sets `DB_SOCKET => ''` with comment `macOS XAMPP: /Applications/XAMPP/... (leave '' on Windows or if using TCP)`. `:31` sets a `CHANGE_ME_...` JWT secret that `config/database.php` deliberately rejects (fail-closed, `:14-19`).
- **Subtlety:** `config/database.php:75-78` already computes a Windows-aware default (`$defaultSocket = $isWindows ? '' : '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock'`), and the `getDB()` Windows branch at `:96-101` prioritises `mysql:host=127.0.0.1;port=3306`. So the macOS path is harmless **if `DB_SOCKET` is left empty**. If someone pastes the macOS socket into `config/env.php`, line 77 will use it.
- **Exact fix:** Copy `config\env.example.php` → `config\env.php`, then set:
  - `DB_HOST` = `127.0.0.1`
  - `DB_SOCKET` = `''` (empty string)
  - `DB_USER`/`DB_PASS` = the MariaDB user (template `hcc_app`) and its real password (same password must be in the follow-up engine's `DB_PASS` environment variable)
  - `JWT_SECRET` = real random value: `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
  - `WHATSAPP_BRIDGE_SECRET` if the bridge is used (must match bridge `.env`)
  - Never commit `config/env.php`.

### 4.4 WARNING — MariaDB-only migration syntax

- **Evidence:** `data/migrations/2026_11_reconcile_wa_ai_schema.sql:4` declares target `MariaDB 10.6+ / 11.x`; `:8-11`, `:15-18`, `:22-26`, `:30+` use `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`. `data/migrations/alter_admission_ai_review_provenance.sql:8-11` does the same. PHP call sites: `admin/migrate_whatsapp_replica.php:221-223` (also `ADD INDEX IF NOT EXISTS`), `admin/includes/whatsapp_functions.php:230-231`, `admin/migrate_ai_admission.php:115-116` — each inside `try/catch`.
- **Consequence on MySQL 8:** the statement throws; the catch prints a note and execution continues, so the provenance/telemetry columns are **silently missing**. Later queries can fail or features degrade without an obvious installer error.
- **Exact fix:** Use **MariaDB 10.4+ (10.6+/11.x preferred)** — current XAMPP Windows ships MariaDB. If MySQL 8 is mandated, rewrite each statement with an `information_schema.COLUMNS` / `STATISTICS` existence check before `ALTER TABLE`, and re-run the migrations on a fresh copy of the DB. Verify columns after migration rather than trusting "✅" output.

### 4.5 WARNING — Fixed docroot and URL

- **Evidence:** `config/database.php:85` `define('BASE_URL', '/hcc/')`; root `.htaccess:6-13` rewrites 404s to `/hcc/404.php`; `:27-30` denies on regex `^/hcc/(config|data|...)`.
- **Exact fix:** Install at `C:\xampp\htdocs\hcc`; browse `http://localhost/hcc/admin/`. Do not use a virtual host at `/` or a different folder name without editing `config/database.php:85` **and** every hardcoded `/hcc/` string in `.htaccess`.

### 4.6 WARNING — Apache-only security model

- **Evidence:** 12 `.htaccess` files exist: root, `automation/`, `config/`, `data/`, `data/private/`, `includes/`, `knowledge/`, `logs/`, `scratch/`, `storage/`, `tools/`, `vendor/`. Root `.htaccess:2` requires Apache 2.4; `:16` `Options -Indexes`; `:28-30` `<IfModule mod_authz_core.c>` with `Require all denied`; `:19-24` `mod_rewrite` deny rules.
- **Exact fix:** Use XAMPP Apache with `AllowOverride All` in `httpd.conf` and restart Apache. Confirm a browser GET to `http://localhost/hcc/config/env.php` returns 403. If IIS is required, translate every rule into `web.config` `<requestFiltering>` hiddenSegments/authorization rules; otherwise `config/`, `data/private/`, `knowledge/`, `storage/` may be served directly.

### 4.7 WARNING — Windows process checks and `exec()`

- **Evidence:** `admin/api_admission_followup.php:65-75` selects `.exe` suffix and Windows binary paths; `:790-805` uses `tasklist /FI "IMAGENAME eq hcc-automation-engine.exe"` with `pgrep` on non-Windows; `:121` launches via `proc_open([$binaryPath, $flag], ...)` (array form, PHP 7.4+). `tests/lib_cli_session.php:121` uses `taskkill /F /PID`.
- **Exact fix:** Leave `exec()`, `proc_open()` and `shell_exec()` **enabled** in `php.ini` (do not list them in `disable_functions`). Keep the engine binary at one of the paths checked at `:68-75`. No code change needed for Windows; this is already the Windows branch.

### 4.8 OK — Bridge, Rust source portability, and writable directories

- Bridge Windows support verified at `automation/whatsapp-bridge.js:46-59,99-102,3021,3698-3700,4567-4579`. Node `>=18`, Puppeteer 22. Scripts tracked: `automation/start-bridge.bat`, `automation/stop-bridge.bat`, `automation/windows/*.ps1`.
- Rust portability verified: no Unix-only APIs in `src/`; env-driven config as described in §1.
- Ensure the Apache/XAMPP service account can **write** to `logs/`, `storage/whatsapp`, `storage/workspace`, `logs/whatsapp-bridge.log`, `automation/.whatsapp-session` (`admin/forgot_password.php:61-90`, `admin/api_timetable.php:24-37`, `admin/api_whatsapp_media.php:40`).

---

## 5. Port and service matrix

| Port / resource | Component | Bind address | Notes |
|-----------------|-----------|--------------|-------|
| **80** | XAMPP Apache (PHP app + admin UI) | `0.0.0.0` / localhost | Staff browsers use `http://localhost/hcc/admin/`. This is the only port staff need. |
| **3306** | MariaDB / MySQL `hcc_admin` | `127.0.0.1` | Windows PHP uses TCP `127.0.0.1:3306` (`config/database.php:98-101`); engine uses `DB_HOST`/`DB_PORT` default `127.0.0.1:3306` (`rust_engine/src/db.rs:125-135`). |
| **8085** | `admission-ai-service` (axum) | **`127.0.0.1` only** | `admission-ai-service/src/config.rs:33-37`. Health: `http://127.0.0.1:8085/api/health`. The start script refuses non-loopback binds. |
| **11434** | Ollama | **`127.0.0.1` only** | Default `http://127.0.0.1:11434` (`config.rs:39-40`). One model: `llama3.2:1b`. |
| **Puppeteer / Chrome** | WhatsApp bridge (`whatsapp-bridge.js`) | local browser process, no listening port | Uses `os.tmpdir()` profile on Windows; logs to `logs/whatsapp-bridge.log`; optional component. |

**Firewall:** Do **not** create Windows Firewall inbound rules for 8085 or 11434 (`docs/WINDOWS_DEPLOYMENT.md:218-220`). Only Apache should be reachable from staff machines. If the school firewall blocks port 80 for staff, use an internal reverse proxy to Apache rather than exposing the AI/Ollama ports. Do not expose the app to the public internet.

---

## 6. Step-by-step Windows install from THIS Mac checkout

1. **Stage the full tree, including the gitignored parts.** Copy the entire project directory from this Mac to `C:\xampp\htdocs\hcc`. Critically include `automation\rust_engine\` and `automation\admission-ai-service\` (source + `Cargo.toml`/`Cargo.lock`), plus `config\env.example.php`. **Exclude** the macOS `target\` directories. A plain `git clone` alone is insufficient (§4.1–4.2).
2. **Install the stack** from §2: XAMPP for Windows (Apache, PHP 8.3+, MariaDB 10.4+/11.x), Composer 2, rustup stable + VS C++ Build Tools, Ollama for Windows, Node 18+ (24 LTS fine), Chrome or Edge, PowerShell 5.1+. Optional: NSSM.
3. **Verify versions:** `C:\xampp\php\php.exe -v`, `composer --version`, `cargo --version`, `node -v`, `ollama --version`. Check PHP extensions with `C:\xampp\php\php.exe -m` against the list in §2, and confirm `exec()`/`proc_open()` are not disabled.
4. **Composer:** from `C:\xampp\htdocs\hcc`, run `composer install` (generates `vendor/`).
5. **Config:** copy `config\env.example.php` → `config\env.php`; set values exactly as §4.3. Generate `JWT_SECRET`. Create MariaDB database `hcc_admin` and user `hcc_app` with the matching password. Never commit `env.php`.
6. **Apache:** set `AllowOverride All` in `httpd.conf`, restart Apache, browse `http://localhost/hcc/admin/`, and confirm `http://localhost/hcc/config/env.php` is forbidden.
7. **Ollama:** `ollama pull llama3.2:1b`; confirm `ollama list` and that `http://127.0.0.1:11434` answers. Keep only this one model.
8. **Build the Rust services** as in §4.2. Result: two `.exe` files at the documented paths.
9. **Database migrations** — back up first (`docs/BACKUP_AND_RECOVERY.md`), then in order (`docs/WINDOWS_DEPLOYMENT.md:85-127`):
   - `C:\xampp\php\php.exe admin\migrate_whatsapp_replica.php`
   - import `data\migrations\create_admission_enquiry_tables.sql` via `C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u hcc_app -p hcc_admin < ...` (or run `admin\migrate_admission_enquiries.php` from an admin session)
   - `C:\xampp\php\php.exe data\migrations\migrate_admission_followups.php`
   - `C:\xampp\php\php.exe admin\migrate_ai_admission.php`
   - Load `data\migrations\create_timetable_tables.sql` once if timetable tables are missing.
   - **Afterwards verify the provenance columns exist** (§4.4) — do not rely on migration stdout alone.
10. **Node bridge (optional):** in `C:\xampp\htdocs\hcc\automation`, `npm install`. If the bridge is used, align `WHATSAPP_BRIDGE_SECRET` with the bridge `.env`.
11. **Startup order** (`docs/WINDOWS_DEPLOYMENT.md:129-138`) — stop in reverse order:
    1. MariaDB (XAMPP Control Panel)
    2. Ollama
    3. Admission AI service — `powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\start-admission-ai.ps1`
    4. Follow-up engine — set `$env:DB_HOST="127.0.0.1"; $env:DB_PORT="3306"; $env:DB_USER="hcc_app"; $env:DB_NAME="hcc_admin"; $env:DB_PASS="<same as config/env.php>"`, **leave `DB_SOCKET` unset**, then run `start-followup-engine.ps1`
    5. Apache (XAMPP Control Panel)
    6. WhatsApp Bridge (optional) — `automation\start-bridge.bat` or `start-bridge.ps1`
12. **Register services (optional, for unattended start):** from elevated PowerShell, `powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\register-services.ps1 -RepoRoot C:\xampp\htdocs\hcc -IncludeBridge` (add `-NssmPath` if needed). Without NSSM the script prints `schtasks` commands; XAMPP MySQL/Apache still start from the Control Panel unless separately installed as services (`docs/WINDOWS_DEPLOYMENT.md:176-196`).

---

## 7. Verification checklist

| # | Check | Expected |
|---|-------|----------|
| 1 | `C:\xampp\php\php.exe -v` | PHP 8.3+; required extensions present; `exec()`/`proc_open()` not disabled |
| 2 | Browse `http://localhost/hcc/admin/` | Login page loads; no path/404 errors |
| 3 | Browse `http://localhost/hcc/config/env.php` | 403 / denied (Apache `AllowOverride All` working) |
| 4 | `http://127.0.0.1:8085/api/health` | `"status": "healthy"`, `"concurrency_limit": 1`, model name, Ollama model list, loaded knowledge topics (`docs/WINDOWS_DEPLOYMENT.md:198-206`) |
| 5 | `hcc-automation-engine.exe --status` | JSON counts; no job rows changed (`:208-214`) |
| 6 | `admin/api_whatsapp_ai.php?action=service_status` (logged in) | PHP sees the AI service |
| 7 | Inspect migrated tables | Provenance/follow-up columns from §4.4 actually exist; no silent MariaDB-syntax failure |
| 8 | `logs/whatsapp-bridge.log` (if bridge used) | Bridge starts, no profile-lock errors |
| 9 | Windows Firewall rule review | No inbound rules for 8085 or 11434 |
| 10 | **Mandatory E2E (release gate)** — `cd C:\xampp\htdocs\hcc` then `C:\xampp\php\php.exe tests\admission_workflow_e2e.php` | Exit successfully on **this Windows PC**. The macOS run reports 25 passed / 0 failed (`docs/TEST_REPORT.md:20`) and explicitly does **not** count (`docs/TEST_REPORT.md:7`; `docs/WINDOWS_DEPLOYMENT.md:5,224-229`). |

Do not mark the deployment production-ready until check 10 passes on the Windows machine.

---

## 8. End-to-end business flow (what "running" actually means)

1. **Enquiry** arrives (WhatsApp inbound via the bridge, or staff entry) and is stored in `admission_enquiries` / WhatsApp tables.
2. **AI draft** — PHP calls `admission-ai-service` at `http://127.0.0.1:8085`, which calls Ollama `llama3.2:1b` using approved facts from `knowledge/approved/*.json`. Output is a **draft only**.
3. **Staff approve** — a staff member reviews and approves the draft in the admin UI (human-in-the-loop; provenance recorded in `admission_ai_review_drafts` and `whatsapp_outbound_queue`).
4. **wa.me link** — the app builds a `https://wa.me/<digits>?text=...` link for the approved draft.
5. **Manual send + confirmation** — the staff member presses **Send in WhatsApp**, then marks the row manually **confirmed sent**. There is no auto-send API and no delivery/read receipt (`docs/WINDOWS_DEPLOYMENT.md:17-19`).
6. **Follow-up queue** — `hcc-automation-engine --daemon` reads the MySQL job queue and prepares follow-up reminders; it does **not** send messages (`docs/WINDOWS_DEPLOYMENT.md:14`).
7. **Audit** — actions are written to the audit log (`docs/ADMISSION_AUDIT.md`, `docs/TEST_REPORT.md:47` reference to 20 checks on tables, duplicate rules, holding draft, pipeline statuses and audit log).

---

## 9. Known limitations and risk table

| Risk / limitation | Evidence | Impact | Mitigation |
|-------------------|----------|--------|------------|
| **Windows E2E not yet run** | `docs/TEST_REPORT.md:7,56-58`; `docs/WINDOWS_DEPLOYMENT.md:5` | Cannot claim production-ready; cold-boot of Rust services as Windows services and 8 GB memory behaviour are unmeasured | Run the mandatory E2E command (§7) on the Windows PC; record results |
| **Rust source gitignored** | `.gitignore:20-21`; 0 tracked Rust files | Fresh clone is non-functional for AI/follow-up | Copy full folders or supply Windows binaries (§4.1) |
| **macOS-only compiled artifacts** | `file` → Mach-O arm64; ~710 MB + ~1.1 GB target trees | Wrong architecture; wasted disk | Rebuild with MSVC; exclude `target/` from the copy (§4.2) |
| **MariaDB-only SQL syntax** | migrations + PHP sites in §4.4 | Missing columns on MySQL 8, swallowed by `try/catch` | Use MariaDB 10.4+/11.x or rewrite with `information_schema` checks; verify columns |
| **No auto-send / no delivery proof** | `docs/WINDOWS_DEPLOYMENT.md:17-19`; `docs/HCC_AUTOSEND_LOCK_REPORT.md`, `docs/WHATSAPP_AUTOSEND_REMOVAL.md` | Staff must press Send and manually confirm; "confirmed sent" ≠ delivered | Train staff; do not add Cloud/unofficial APIs |
| **8 GB concurrency = 1** | `docs/WINDOWS_DEPLOYMENT.md:57,65,172` | Slower under load; raising concurrency is refused | 16 GB recommended; keep `AI_MODEL=llama3.2:1b`, `AI_INFERENCE_CONCURRENCY=1`, one model only |
| **Apache-only `.htaccess` security** | 12 `.htaccess` files | IIS deployment exposes sensitive dirs | Use XAMPP Apache + `AllowOverride All`, or port rules to `web.config` |
| **Fixed `/hcc/` path** | `config/database.php:85`; root `.htaccess` | Non-standard docroot breaks links and deny rules | Install at `C:\xampp\htdocs\hcc` |
| **Writable dirs / service account** | `logs/`, `storage/*`, `automation/.whatsapp-session` | Permission errors if Apache runs as a restricted account | Grant write access to the XAMPP/Apache service account; test forgot-password throttle and media upload |
| **`exec()` requirement** | `api_admission_followup.php:790-805` | Daemon status detection degrades if disabled | Do not disable `exec()`/`proc_open()` in `php.ini` |

---

## 10. Recommended next actions

| Priority | Action | Owner | Notes |
|----------|--------|-------|-------|
| **P0** | Copy the two gitignored Rust source folders (and the `env.php` template) from this Mac into the Windows staging tree; exclude macOS `target/` | School IT admin / project owner | Resolve blocker §4.1 before any build step |
| **P0** | Install Rust stable + VS C++ Build Tools and build both crates to Windows `.exe`; verify both paths | School IT admin | Resolve blocker §4.2 |
| **P0** | Create `config\env.php` with `DB_HOST=127.0.0.1`, `DB_SOCKET=''`, real `JWT_SECRET`, matching DB credentials | School IT admin | Resolve blocker §4.3; fail-closed secret blocks login otherwise |
| **P0** | Choose MariaDB 10.4+/11.x; run migrations; verify the §4.4 columns exist | School IT admin | Avoid silent MySQL 8 syntax failures |
| **P0** | Run `C:\xampp\php\php.exe tests\admission_workflow_e2e.php` on the Windows PC and retain output | School IT admin + project owner | **Release gate** — macOS 25/25 does not count |
| **P1** | Confirm `AllowOverride All`, Apache 2.4, 403 on `/hcc/config/`, no firewall rules for 8085/11434 | School IT admin | §4.6, §5 |
| **P1** | Register Windows services (`register-services.ps1`) with `DB_PASS` in machine environment, `DB_SOCKET` unset; test cold boot | School IT admin | Unmeasured risk from `docs/TEST_REPORT.md:56` |
| **P1** | Train staff on wa.me manual send + manual confirmation; post the "no delivery proof" notice | School admin | §8, §9 |
| **P2** | Update README clone instructions to mention the gitignored Rust folders and Windows build step | Documentation owner (content-agent can draft on request) | Prevents repeat of blocker §4.1 |
| **P2** | If MySQL 8 is mandated, rewrite `IF NOT EXISTS` DDL with `information_schema` checks | Developer / DBA | Removes MariaDB dependency |
| **P2** | Consider 16 GB + SSD and measure multi-user behaviour; do not raise AI concurrency | School IT admin | §3, §9 |

---

## Related documents

- `docs/WINDOWS_DEPLOYMENT.md` — full install, migration, startup, service and firewall guide
- `docs/CONFIGURATION.md` — every environment key
- `docs/BACKUP_AND_RECOVERY.md` — pre-migration backup procedure
- `docs/TEST_REPORT.md` — what has (and has not) been tested
- `docs/KNOWN_LIMITATIONS.md`, `docs/HCC_MASTER_INSTRUCTION_GUIDE.md`, `docs/ADMISSION_AUDIT.md`

*Report compiled from direct file inspection on 2026-11-17. No code was changed as part of this report.*
