# HCC Campus Automation System — Master Instruction Guide & Operations Manual

**Institution**: Hindustani Covenant Church High School & Junior College, Solapur  
**Version**: v2.5 (Hardened & Extended)  
**Target Environments**: macOS (XAMPP / PHP-FPM), Windows 10/11 (XAMPP, 8 GB RAM PC), Linux (Apache/Nginx + PHP-FPM)

---

## Table of Contents
1. [System Overview & Architecture](#1-system-overview--architecture)
2. [Ports, Services & Directory Layout](#2-ports-services--directory-layout)
3. [Prerequisites & Installation](#3-prerequisites--installation)
4. [Configuration & Secrets Management](#4-configuration--secrets-management)
5. [Database Setup & Strict Migration Order](#5-database-setup--strict-migration-order)
6. [Daily Operations Guide for School Staff](#6-daily-operations-guide-for-school-staff)
7. [WhatsApp Hub, Admission AI & Rust Worker Operations](#7-whatsapp-hub-admission-ai--rust-worker-operations)
8. [Security Architecture, Rotation & Backup/Recovery](#8-security-architecture-rotation--backuprecovery)
9. [Troubleshooting & Diagnostic Runbook](#9-troubleshooting--diagnostic-runbook)

---

## 1. System Overview & Architecture

The **HCC Campus Automation System** is a local-first, privacy-preserving school operations platform combining:
- **Academic & Clerical Core (PHP 8.3+ / MariaDB)**: Dual-board grading (Std 1–8 CCE 9-point `A1–E2` with 33% pass; Std 9–10 Maharashtra State Board SSC with 35% pass), Unit Tests I–IV, Excel Auto-Healer importers, Exam Timetables, 7:15 AM Substitute Allocator, Exam Hall Seating Planner, At-Risk Radar, Parent-Sign Chase Queue, GR-to-Bonafide Studio, Academic Vault, and Google Drive Workspace.
- **Cryptographic Public Portals**: Tamper-proof URL-safe HMAC-SHA256 links (`public_card.php`, `public_slip.php`, `public_bonafide.php`) with QR code verification.
- **School WhatsApp Hub (`admin/whatsapp_share.php`)**: Dual-view Guided Sequential Hub + WhatsApp Web Replica powered by a Node.js Puppeteer bridge (`automation/whatsapp-bridge.js`) under a strict **human-in-the-loop Send-on-Press** architecture.
- **Local-First Admission AI (`automation/admission-ai-service`)**: Rust Axum HTTP service (`127.0.0.1:8085`) wrapping local Ollama (`llama3.2:1b` on `:11434`), grounded in 9 approved JSON files inside `knowledge/approved/`, with post-generation hallucination checks (`SafetyValidator`).
- **Durable Background Worker (`automation/rust_engine`)**: Rust binary (`hcc-automation-engine`) polling MySQL `automation_jobs` every 5 seconds for reminder preparation, crash recovery, inbound parent WhatsApp signal extraction, and the 8:30 AM Principal Daily Briefing.

---

## 2. Ports, Services & Directory Layout

### Service & Port Matrix
| Component | Process / Binary | Default Address / Port | Purpose |
|---|---|---|---|
| **Web Portal & APIs** | Apache + PHP 8.3+ (or PHP-FPM) | `http://localhost/hcc/admin/` (`:80`) | Staff dashboard, marks, timetables, WhatsApp Hub |
| **Database** | MariaDB 10.4+ / 11.x (`hcc_admin`) | `127.0.0.1:3306` or Unix socket | Persistent academic, WhatsApp, enquiry & audit data |
| **Local LLM** | Ollama (`ollama serve`) | `http://127.0.0.1:11434` | Local `llama3.2:1b` inference (no cloud leak) |
| **Admission AI** | `admission-ai-service` (Rust) | `http://127.0.0.1:8085` | Intent classification, RAG + SafetyValidator, Co-Pilot |
| **Follow-up Engine** | `hcc-automation-engine --daemon` (Rust) | Outbound to MariaDB | Durable `automation_jobs`, signals & daily briefings |
| **WhatsApp Bridge** | `node automation/whatsapp-bridge.js` | Puppeteer Chrome + HTTP poll | 3s queue poll, 15s allowlist refresh, DOM watchdog |

### Directory Map
| Path | Contents | Web Access |
|---|---|---|
| `admin/` | Staff pages, operational tools, APIs (`api_*.php`), `includes/` | Authenticated (`admin` / `editor`) |
| `assets/` | Self-hosted vendor libs (`Bootstrap 5.3.3`, `Font Awesome 6.5.0`, `jsPDF`, `html2canvas`, `JSZip`, fonts), CSS & JS | Public static assets |
| `config/` | `database.php`, `env.example.php`, `env.php` (gitignored) | **Blocked (`403 Forbidden`)** |
| `data/migrations/` | Idempotent `.sql` & CLI migration scripts | **Blocked (`403 Forbidden`)** |
| `includes/` | `functions.php`, `google_drive.php`, `whatsapp_functions.php` shim | **Blocked (`403 Forbidden`)** |
| `automation/` | Node Puppeteer bridge, `rust_engine/`, `admission-ai-service/`, OS startup scripts | **Blocked (`403 Forbidden`)** |
| `knowledge/approved/` | 9 institutional truth JSON files + `SCHOOL_KNOWLEDGE_SUMMARY.md` | **Blocked (`403 Forbidden`)** |
| `storage/` | `whatsapp/media/` & `workspace/` staff uploads (served only via authenticated proxy) | **Blocked (`403 Forbidden`)** |
| `logs/`, `scratch/`, `tools/`, `vendor/` | Logs, test utilities, Composer packages | **Blocked (`403 Forbidden`)** |
| `public_*.php` | `public_card.php`, `public_slip.php`, `public_bonafide.php` | Public (HMAC-SHA256 token gated) |

---

## 3. Prerequisites & Installation

1. **XAMPP** (Apache 2.4+, MariaDB 10.4+, PHP 8.2/8.3+ with `pdo_mysql`, `mbstring`, `zip`, `gd`, `fileinfo`, `curl`, `openssl`).
2. **Composer** (`composer install` in repo root to install `phpoffice/phpspreadsheet`).
3. **Node.js 18+ / 24 LTS** (`cd automation && npm install` for Puppeteer WhatsApp Bridge).
4. **Rust Toolchain** (`cargo build --release` inside `automation/rust_engine` and `automation/admission-ai-service`).
   - Copy compiled engine binary:
     ```bash
     cp automation/rust_engine/target/release/hcc-automation-engine automation/bin/hcc-automation-engine
     ```
5. **Ollama** (for local AI):
   ```bash
   ollama pull llama3.2:1b
   ```

---

## 4. Configuration & Secrets Management

### `config/env.php` (Copy from `config/env.example.php`)
Never commit `config/env.php` to Git. Required keys:
- `DB_HOST`: `127.0.0.1` (recommended on Windows/Linux TCP) or `localhost` (macOS socket).
- `DB_NAME`: `hcc_admin`
- `DB_USER` & `DB_PASS`: Dedicated MySQL user (e.g., `hcc_app`) with strong password.
- `DB_SOCKET`: `/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock` on macOS XAMPP; `''` (empty) on Windows.
- `JWT_SECRET`: Generate with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Used for HMAC public links and AES-256-GCM encryption of optional external AI API keys.
- `WHATSAPP_BRIDGE_SECRET`: 32+ char shared secret matching `automation/.env`.

### `automation/.env`
```env
WHATSAPP_BRIDGE_SECRET=<same 32+ char secret as config/env.php>
ENABLE_PRIVACY_FILTER=true
```

### Rust & Service Environment Variables
- **`hcc-automation-engine`**: Reads `DB_HOST`, `DB_PORT` (`3306`), `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_SOCKET`. *Note: `db.rs` no longer embeds any hardcoded fallback password; when invoked from `admin/api_admission_followup.php`, PHP passes DB credentials via `proc_open()` environment variables.*
- **`admission-ai-service`**:
  - `AI_SERVICE_HOST=127.0.0.1`, `AI_SERVICE_PORT=8085`
  - `OLLAMA_HOST=http://127.0.0.1:11434`, `AI_MODEL=llama3.2:1b`
  - `AI_INFERENCE_CONCURRENCY=1` (keep `1` on 8 GB RAM machines)
  - `AI_SERVICE_SECRET` *(optional)*: When set, PHP sends matching `X-AI-Service-Secret`.

---

## 5. Database Setup & Strict Migration Order

To prevent table schema collisions on fresh installations, apply migrations in this **exact order**:
1. Import base school schema (`report_card`, `users`, `settings`, `class_subjects`, `audit_logs`, etc.) + `data/migrations/create_timetable_tables.sql`.
2. Run WhatsApp Replica base migration (CLI only):
   ```bash
   php admin/migrate_whatsapp_replica.php
   php admin/migrate_whatsapp_templates.php
   ```
3. Run Admission Enquiries **before** `migrate_ai_admission.php`:
   ```bash
   mysql -u hcc_app -p hcc_admin < data/migrations/create_admission_enquiry_tables.sql
   php data/migrations/migrate_admission_followups.php
   php admin/migrate_ai_admission.php
   mysql -u hcc_app -p hcc_admin < data/migrations/create_admission_ai_review_drafts.sql
   ```
4. Apply the **2026-11 Hardening & Daily Operations Reconciliation Migrations**:
   ```bash
   mysql -u hcc_app -p hcc_admin < data/migrations/2026_11_academic_ops_tables.sql
   mysql -u hcc_app -p hcc_admin < data/migrations/2026_11_reconcile_wa_ai_schema.sql
   ```

---

## 6. Daily Operations Guide for School Staff

### A. Academic Marks, Excel Auto-Healer & Promotion
1. **Excel Markbook Import (`admin/import_excel.php` & `admin/import_unit_tests.php`)**:
   - Download the built-in blank template (Row 1 = Headers, Row 2 = Max Marks, Row 3+ = Students).
   - Upload multi-sheet teacher workbooks (`I A`..`X C`). The **Auto-Healer** ignores standalone `BOYS`/`GIRLS` dividers, evaluates Excel `=IF(...)` remark formulas, detects swapped roll numbers via fuzzy name matching, flags blank cells vs explicit `AB`/`0`, and **preserves existing `date_of_birth`, `parent_phone`, and `parents_sign`** on overwrite.
2. **Results Matrix & Print (`admin/results.php`, `admin/view_report_card.php`, `admin/export_pdf_batch.php`)**:
   - View class standings (Std 1–8 CBSE `A1–E2` / 33% pass; Std 9–10 Maharashtra SSC / 35% pass).
   - Print or download individual A4 PDFs (with QR verification badge) or export self-contained offline HTML class ZIP archives.
3. **Unit Tests I–IV (`admin/unit_tests.php`, `admin/unit_test_slips.php`)**:
   - Enter `.5` precision marks, toggle interactive parent signatures, and print 4-per-A4 progress slips.
4. **End-of-Year Promotion & Vault (`admin/promote_students.php`, `admin/records_archive.php`)**:
   - Promoting `Sem 2` cleanly initializes the next academic year into `Sem 1` while carrying forward `parent_phone` and `date_of_birth`.
   - Locking an academic year in `records_archive.php` enforces a **server-side write block** (`rcIsVaultLocked()`) across all entry, sign, and import pages.

### B. Proactive Academic & Morning Office Tools
1. **At-Risk Sudden-Drop Radar (`admin/at_risk_radar.php`)**:
   - Cross-checks Unit Tests vs Semester marks to highlight `>=12%` drops, repeated fails, and *Exam-Anxiety Splits* (`>=60%` FA internal, failed SA written). Click **Stage PTM Invite** to prepare a bilingual WhatsApp draft.
2. **Parent-Sign Chase Queue (`admin/parent_sign_chase.php`)**:
   - Filter unsigned cards into *Never Sent* vs *Sent but Unsigned*. Toggle signatures in 1 click, print the **Assembly Bench-Check Sheet**, or forward pending rolls straight into the WhatsApp Hub.
3. **7:15 AM Teacher Substitute Allocator (`admin/substitute_allocator.php`)**:
   - Tick absent teachers -> inspect uncovered periods -> view conflict-free substitute rankings (by 7-day proxy count + subject affinity) -> click **Auto-Allocate Best Fit** -> print the staffroom proxy sheet and stage morning WhatsApp notifications.
4. **Exam Hall Seating Planner (`admin/exam_seating.php`)**:
   - Select participating classes & room capacities -> generate interleaved bench layouts (alternating different standards per bench) -> print **Room Door Roll-Range Chits**, **Bench Matrix Grids**, and **Desk Stickers**.

### C. Clerical Certificates & Google Workspace
- **Instant GR-to-Bonafide (`admin/bonafide_certificate.php`)**: Type a student's GR number or name to auto-fill Name, Std, Division, DOB, DOB-in-Words, and next sequential serial number. Every issued Bonafide displays a QR code verified strictly against `bonafide_issued_log` via `public_bonafide.php`.
- **Shared Workspace (`admin/shared_workspace.php`)**: Connect institutional Google Drive folders (validated `https://drive.google.com` links only) and store/download archived files securely through the authenticated workspace proxy.

---

## 7. WhatsApp Hub, Admission AI & Rust Worker Operations

### Startup Sequence (Stop in Reverse)
1. **MariaDB** (XAMPP Control Panel / `brew services`)
2. **Ollama**: `ollama serve` (ensure `llama3.2:1b` is pulled)
3. **Rust Admission AI (`:8085`)**:
   - macOS/Linux: `./automation/admission-ai-service/target/release/admission-ai-service --host 127.0.0.1 --port 8085 --model llama3.2:1b serve`
   - Windows: `automation\windows\start-admission-ai.ps1` (or `HCC-AdmissionAI` service)
4. **Rust Follow-up Engine**:
   - macOS/Linux: `DB_PASS=... ./automation/bin/hcc-automation-engine --daemon`
   - Windows: `automation\windows\start-followup-engine.ps1` (or `HCC-FollowupEngine` service)
5. **Apache Web Server (`:80`)**
6. **WhatsApp Bridge** *(optional, for live WhatsApp Web sync)*:
   - macOS: `./automation/start-bridge.sh` (or `launchd` via `install-launchd.sh`)
   - Windows: `automation\windows\start-bridge.ps1`
   - Linux: `systemd` via `automation/linux/install-systemd.sh`

### Daily WhatsApp & AI Features
- **Send-on-Press Rule**: Nothing is ever sent automatically. Staff must review drafts and click **Send** (`origin='manual_ui'`).
- **Result-Day Co-Pilot (`📊 Co-Pilot` inside WhatsApp Composer)**: Generates a factual, marks-grounded draft reply for any linked parent chat.
- **8:30 AM Principal Briefing & Inbound Signal Cards (`admin/admission_followups.php`)**: Review extracted parent leave notices, phone updates, fee claims, and PTM confirmations with 1-click **Apply Action** or **Dismiss**.
- **DOM Selector Watchdog**: Periodically verifies WhatsApp Web selectors (`chatListRows`, compose box) and updates `whatsapp_sessions.dom_health_status` so broken selectors are caught before dispatch.

---

## 8. Security Architecture, Rotation & Backup/Recovery

- **Access Control**: `admin/auth_check.php` enforces `admin` vs `editor` RBAC; sensitive settings (`save_smtp`, `save_google_oauth`), Excel imports, promotions, vault locking, and Bonafides require `admin`. Google OAuth requires `email_verified=true` and pre-provisioned user accounts.
- **Directory Locks**: `config/`, `data/`, `includes/`, `logs/`, `storage/`, `automation/`, `knowledge/`, `tools/`, `scratch/`, and `vendor/` all return `HTTP 403 Forbidden` over the web.
- **Credential Rotation Checklist**:
  1. Rotate `DB_PASS` in MariaDB (`ALTER USER 'hcc_app'@'localhost' ...`), `config/env.php`, and Windows/macOS service environment configs.
  2. Rotate `JWT_SECRET` (note: rotating `JWT_SECRET` invalidates older parent links and requires re-saving any encrypted external AI API key).
  3. Rotate `WHATSAPP_BRIDGE_SECRET` in both `config/env.php` and `automation/.env`, then restart `whatsapp-bridge.js`.
- **Database Backup**:
  ```bash
  mysqldump --single-transaction -u hcc_app -p hcc_admin > backup_hcc_$(date +%Y%m%d).sql
  ```

---

## 9. Troubleshooting & Diagnostic Runbook

| Symptom | Root Cause | Resolution |
|---|---|---|
| `Unknown column 'analysis_engine'` or missing `inbound_signal_cards` | Reconciliation migrations not yet applied | Run `data/migrations/2026_11_academic_ops_tables.sql` and `2026_11_reconcile_wa_ai_schema.sql`. |
| Outbound WhatsApp item marked `failed` after 120s | Bridge was offline/paused (`waFailStaleOutbound` prevents stale auto-flush) | Ensure bridge is running (`Last poll < 60s`), then click **Retry** in WhatsApp Hub. *(Staggered batches >60 recipients are protected when `scheduled_at > NOW()`.)* |
| Bridge fails to launch Chrome (`SingletonLock`) | Prior browser crashed leaving lock files | Run `./automation/stop-bridge.sh` then `./automation/start-bridge.sh` (auto-cleans `Singleton*` locks). |
| Rust AI `:8085` returns unavailable | Ollama not running or wrong model | Run `ollama list`, verify `llama3.2:1b`, check `http://127.0.0.1:8085/api/health`. |
| E2E tests (`tests/admission_workflow_e2e.php`) | Needs CLI session against Apache/php-fpm | Run `php tests/admission_workflow_e2e.php` — automatically handles CLI admin session via `tests/lib_cli_session.php`. |
| `Records Archive` rejects edits | Academic year is sealed (`is_locked = 1`) | Unlock temporarily in `admin/records_archive.php` (admin only), make correction, then re-seal. |
