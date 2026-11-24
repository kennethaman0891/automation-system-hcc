# Windows deployment — HCC / School WhatsApp Hub

This is a local XAMPP deployment for one Windows PC: Intel CPU, 8 GB RAM, 500 GB disk. It is not a cloud setup.

Do not call this machine production-ready until `php tests/admission_workflow_e2e.php` exits successfully on this same Windows PC. A pass on another computer does not count.

The project name stays HCC / School WhatsApp Hub. Do not rename it.

## What runs

- XAMPP Apache and PHP 8.3 or newer serve the existing HTML/JS WhatsApp WebUI and the PHP APIs.
- MySQL database name: `hcc_admin`.
- `admission-ai-service` is a Rust axum process. It listens on `127.0.0.1:8085` and calls Ollama at `http://127.0.0.1:11434`.
- `hcc-automation-engine` is a Rust process. It reads the MySQL job queue and prepares follow-up reminders. It does not send WhatsApp messages.
- Approved school facts live in `knowledge/approved/*.json`.

AI text is a draft. A staff member reviews it, clicks Open WhatsApp (`https://wa.me/<digits>?text=...`), presses Send in WhatsApp, then marks the row manually confirmed sent. That confirmation is not delivery and it is not a read receipt.

Do not add the WhatsApp Business Cloud API. Do not add an unofficial WhatsApp API. Do not store WhatsApp passwords.

`automation/whatsapp-bridge.js` is not part of the startup order below. Install Node only if you still choose to run that existing script. See Prerequisites.

## Prerequisites

Install these yourself. The scripts in this repo do not download them.

- XAMPP for Windows with Apache, MySQL, and PHP 8.3 or newer. Confirm with `C:\xampp\php\php.exe -v`.
- Composer, then `composer install` in the repo root. `vendor/` is not committed.
- Either the Rust toolchain (rustup stable, plus the Visual Studio C++ build tools so `cargo` can link) or Windows release binaries you built elsewhere. The binaries in a macOS checkout are not Windows binaries. Do not copy those.
- Ollama for Windows. It must serve `http://127.0.0.1:11434`.
- Node.js 18 or newer if you run the WhatsApp bridge (`automation\whatsapp-bridge.js`). The bridge automatically detects Chrome in Windows standard installation locations (`Program Files`, `Program Files (x86)`, and `%LOCALAPPDATA%`), or Microsoft Edge as fallback. Start the bridge via `automation\start-bridge.bat` or `automation\windows\start-bridge.ps1`.

Suggested tree:

```
C:\xampp\htdocs\hcc
```

The site is then `http://localhost/hcc/admin/`.

## Config file

Copy `config\env.example.php` to `config\env.php`. Edit the copy. Never commit `config\env.php`.

On Windows set `DB_SOCKET` to an empty string so PHP uses TCP. Set `DB_HOST` to `127.0.0.1`. Create the MySQL database `hcc_admin` and a user that matches `DB_USER` (the template name is `hcc_app`). Choose the password yourself and put it only in `config\env.php` and in the `DB_PASS` environment variable used by the follow-up engine. Those two values must match.

Replace `JWT_SECRET` before anyone else can open the site. Generate one with:

```
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Details for every key are in `docs/CONFIGURATION.md`.

## One Ollama model

8 GB RAM can hold one small model. Install one model at a time. Do not pull several large models.

Starter model:

```
ollama pull llama3.2:1b
```

Check with `ollama list`. Leave `AI_MODEL` at `llama3.2:1b` and `AI_INFERENCE_CONCURRENCY` at `1`. The AI process is a single worker. Do not raise concurrency on this machine.

## Build

From `C:\xampp\htdocs\hcc`, if you are compiling on this PC:

```
cd automation\admission-ai-service
cargo build --release
cd ..\rust_engine
cargo build --release
```

Expected binaries:

- `automation\admission-ai-service\target\release\admission-ai-service.exe`
- `automation\rust_engine\target\release\hcc-automation-engine.exe`

If you were given Windows binaries instead, put them at those two paths. A copy under `automation\bin\hcc-automation-engine.exe` is also accepted by the follow-up script.

## Database migrations

Take a `mysqldump` of `hcc_admin` first. See `docs/BACKUP_AND_RECOVERY.md`.

Do not drop tables. Do not delete production rows. Run the commands below from `C:\xampp\htdocs\hcc`.

The timetable schema used by the rest of the school app is `data\migrations\create_timetable_tables.sql`. Load it once if those tables are not already there. It is separate from the admission list below.

Admission order:

1. WhatsApp tables, only if they are missing:

   ```
   C:\xampp\php\php.exe admin\migrate_whatsapp_replica.php
   ```

2. Admission enquiry tables. Use one of these, not both, on a new database.

   The PHP runner loads the admin auth check, so a bare CLI run redirects to login. For an unattended Windows setup, import the SQL file:

   ```
   cmd /c "C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u hcc_app -p hcc_admin < data\migrations\create_admission_enquiry_tables.sql"
   ```

   `-p` prompts for the password. Do not put the password in the command.

   `admin\migrate_admission_enquiries.php` runs that same SQL, but only when an admin session is already present.

3. Follow-up columns, job tables, and sample rows:

   ```
   C:\xampp\php\php.exe data\migrations\migrate_admission_followups.php
   ```

   This adds missing columns and creates the follow-up tables. It inserts sample enquiry rows only when `admission_enquiries` is empty. If the table already has rows, it skips the sample insert. Do not delete real rows to make it seed again.

4. AI draft tables and knowledge seed, if the file is present (it is in this tree):

   ```
   C:\xampp\php\php.exe admin\migrate_ai_admission.php
   ```

   `CREATE TABLE IF NOT EXISTS` does not replace an `admission_enquiries` table created in step 2. The knowledge insert updates rows that share the same slug. It does not delete enquiry rows.

## Startup order

Start them in this order. Stopping is the reverse order.

1. MySQL (XAMPP Control Panel).
2. Ollama (its normal Windows app, so `http://127.0.0.1:11434` answers).
3. Admission AI service.
4. Follow-up engine (`--daemon`).
5. Apache (XAMPP Control Panel).
6. WhatsApp Bridge (optional, for live automated inbound/outbound sync).

### Foreground

Run the background workers in separate PowerShell / CMD windows:

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\start-admission-ai.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\start-followup-engine.ps1
```

If running the WhatsApp Bridge on Windows, open a window and run this once:

```
automation\start-bridge.bat
```

That starts `automation\windows\start-bridge.ps1` minimized and leaves it running. The supervisor restarts the bridge if it exits, so the QR on WhatsApp Share can come back without a second command. A second run sees the supervisor is already up and leaves it alone. Stop it with `automation\stop-bridge.bat`.

You can also start the supervisor directly:

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\start-bridge.ps1
```

The supervisor checks Node.js on `PATH`, runs `npm install` when `node_modules` is missing or was copied from macOS and will not load, cleans stale profile locks (`Singleton*` and `lockfile`), and logs to `logs\whatsapp-bridge.log`. PHP and the bridge share QR and restart flags in `storage\whatsapp\ipc`. That folder is the same even when Apache and Node run as different Windows users. Do not point those flags at each user's Temp folder.

Google Chrome or Microsoft Edge must be installed. The bridge uses that browser when Puppeteer's own Chrome download is not present. Copying the macOS `automation\node_modules` folder is not enough; let the supervisor run `npm install` on this PC.

Closing a window stops that process. The scripts read settings from the environment. They do not contain a database password.

Before the follow-up window:

```
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_USER = "hcc_app"
$env:DB_NAME = "hcc_admin"
$env:DB_PASS = "<the same password as config\env.php>"
```

Do not set `DB_SOCKET`. The follow-up script removes it for that process so a copied macOS socket path is not used.

The AI script fills these when they are unset: host `127.0.0.1`, port `8085`, Ollama `http://127.0.0.1:11434`, model `llama3.2:1b`, concurrency `1`. It refuses to listen on any address other than `127.0.0.1`. It refuses to start if concurrency is not `1`.

PHP calls the AI service with `RUST_AI_SERVICE_URL`, default `http://127.0.0.1:8085`. You only need to set that variable for the Apache process if the AI port is not 8085. Set it as a Windows system environment variable, then restart Apache. `config\env.php` is not read by the Rust processes.

## Services

`automation\windows\register-services.ps1` registers the background services. Run it from an elevated PowerShell. It is safe to run again: an existing service is updated, not duplicated.

```
powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\hcc\automation\windows\register-services.ps1 -RepoRoot C:\xampp\htdocs\hcc -IncludeBridge
```

Optional `-NssmPath` points at `nssm.exe` if it is not on `PATH`. Add `-IncludeBridge` if you also want the WhatsApp Bridge registered as a background service (`HCC-WhatsAppBridge`).

If NSSM is already installed, the script registers:

- `HCC-AdmissionAI` → `start-admission-ai.ps1`
- `HCC-FollowupEngine` → `start-followup-engine.ps1`
- `HCC-WhatsAppBridge` → `start-bridge.ps1` (when `-IncludeBridge` is specified)

It passes the current process environment for `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, and `DB_NAME` into the follow-up service. It does not write those values into the repo. If `DB_PASS` is missing on the first run, the follow-up service is registered and not started. If the service already exists, a run without `DB_PASS` leaves that service unchanged so a password already stored on it is not wiped. Set `DB_PASS` and run the script again.

NSSM is not downloaded. If `nssm.exe` is not found, the script prints `schtasks` commands and does not create the tasks. Those commands use `/F`, so running them again replaces the task. `ONSTART` tasks run as SYSTEM. Put `DB_PASS` in the machine environment, not in the task command line, then reboot or restart the task. Leave `DB_SOCKET` unset.

XAMPP MySQL and Apache are still started from the XAMPP Control Panel unless you have already installed them as Windows services yourself. The two NSSM services do not replace that. Start MySQL and Ollama before the Rust services.

## Health checks

AI service, after the script prints the URL:

```
http://127.0.0.1:8085/api/health
```

A healthy body has `"status": "healthy"`, `"concurrency_limit": 1`, the model name, the Ollama model list, and the knowledge topics that loaded. `knowledge/approved` must be visible from the repo working directory. If the directory is missing, the process falls back to a small built-in set and the topic list will not match the files on disk.

Follow-up engine, with `DB_PASS` already set in that window:

```
C:\xampp\htdocs\hcc\automation\rust_engine\target\release\hcc-automation-engine.exe --status
```

`--status` prints JSON counts and does not change job rows. Use the start script, not `--status`, to run the daemon.

PHP can also see the AI process from `admin/api_whatsapp_ai.php?action=service_status` once Apache is up and you are logged in.

## Firewall

`admission-ai-service` must bind to `127.0.0.1` only. Do not add a Windows Firewall inbound rule for port 8085 or for Ollama's port 11434. Staff browsers talk to Apache. They do not talk to the AI process or to Ollama.

## Not production-ready

Do not declare this deployment production-ready until `tests/admission_workflow_e2e.php` passes on this Windows machine:

```
cd C:\xampp\htdocs\hcc
C:\xampp\php\php.exe tests\admission_workflow_e2e.php
```

## Related docs

- Linux bridge service: `docs/LINUX_DEPLOYMENT.md`
- macOS LaunchAgent: `./automation/install-launchd.sh`
