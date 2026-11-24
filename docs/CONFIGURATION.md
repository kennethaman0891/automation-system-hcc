# Configuration — HCC / School WhatsApp Hub

Two places hold settings. They are not the same file.

- PHP reads `config/env.php`. That file is a PHP array. It is gitignored. Copy it from `config/env.example.php`. Never commit the copy.
- The Rust programs read process environment variables. They do not read `config/env.php`. The Windows scripts pass through variables you have already set. They do not contain passwords.

Do not put real secrets in this document, in git, or in a task-scheduler command line.

Nothing here makes the app production-ready. See the test gate in `docs/WINDOWS_DEPLOYMENT.md`.

## Change these before any shared deployment

- `JWT_SECRET` in `config/env.php`. The template value contains `CHANGE_ME`. Generate a new one and paste only that value into `env.php`:

  ```
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  ```

  An empty `JWT_SECRET` stops PHP with a generic configuration error. A value that still contains `CHANGE_ME` is logged as a warning and the app still boots. Do not leave the placeholder on a machine other people can open.

- `DB_PASS` in `config/env.php`, and the same password in the `DB_PASS` environment variable for `hcc-automation-engine`. The template ships an empty password so a local database can be wired up. Before anyone else uses the site, set a password you choose (password manager, or `openssl rand -base64 24` if OpenSSL is installed). Create the MySQL user with that password. Do not reuse it as `JWT_SECRET`.

No other key is a substitute for those two.

## PHP — config/env.php

| Key | Safe default | What it does |
|---|---|---|
| `DB_HOST` | `127.0.0.1` on Windows. The template says `localhost`. | MySQL host. On Windows use `127.0.0.1` so the connection is TCP. |
| `DB_NAME` | `hcc_admin` | Database name. Create this database before the migrations. |
| `DB_USER` | `hcc_app` | MySQL account used by PHP. The key must be present and non-empty. |
| `DB_PASS` | empty in the template | Password for `DB_USER`. The key must exist. An empty string is allowed by the loader and is not acceptable on a shared machine. |
| `DB_CHARSET` | `utf8mb4` | PDO charset. Leave `utf8mb4` unless the database was created with another charset on purpose. |
| `DB_SOCKET` | empty string | Unix socket path. On Windows PHP ignores this value, including a macOS socket copied in `env.php`, and connects with TCP to `127.0.0.1`. On macOS, an omitted key uses the XAMPP socket when that file exists. |
| `JWT_SECRET` | placeholder starting with `CHANGE_ME` | Secret for signed links and session-related HMAC. Replace it. See above. |
| `CORS_ORIGIN` | `http://localhost` | Present in the template. `config/database.php` does not read it. It is not an access-control switch. Leave `http://localhost` unless you are changing code that starts using it. |

`config/database.php` also defines `BASE_URL` as `/hcc/`. That matches a site at `http://localhost/hcc/`. Do not rename the folder if you want those URLs to keep working.

## PHP process environment

| Variable | Safe default | What it does |
|---|---|---|
| `RUST_AI_SERVICE_URL` | `http://127.0.0.1:8085` | Base URL PHP uses to call the admission AI service (`/api/health` and draft requests). Read from the Apache process environment, not from `env.php`. Leave it unset when the AI service uses the default bind. If you change the AI port, set this to the same `http://127.0.0.1:<port>` and restart Apache. |

## Admission AI service

The binary is `admission-ai-service`. `automation\windows\start-admission-ai.ps1` sets a default when the variable is unset, then starts the release binary with `--host`, `--port`, and `--model`. Host and port in the current binary come from those flags; the flags are required for a non-default host or port to stick. Ollama's URL is read from the environment inside the process.

The process working directory must be the repo root (`C:\xampp\htdocs\hcc`). The binary looks for knowledge in this order: `../../knowledge/approved` relative to the working directory, `knowledge/approved`, a macOS XAMPP path, then `C:\xampp\htdocs\hcc\knowledge\approved`.

| Variable | Safe default | What it does |
|---|---|---|
| `AI_SERVICE_HOST` | `127.0.0.1` | Bind address. Keep `127.0.0.1`. The start script rejects anything else. Do not use `0.0.0.0`. |
| `AI_SERVICE_PORT` | `8085` | Bind port. Must match `RUST_AI_SERVICE_URL`. |
| `OLLAMA_HOST` | `http://127.0.0.1:11434` | Ollama base URL. One local Ollama. Do not point this at a public host. |
| `AI_MODEL` | `llama3.2:1b` | Model name sent to Ollama. On 8 GB RAM this is the starter model. Pull that one model. Do not pull several large models, and do not switch this to a larger model while another model is loaded. |
| `AI_INFERENCE_CONCURRENCY` | `1` | How many inference jobs run at once. The binary reads this and clamps it to 1–4. On 8 GB RAM leave it at `1`. `/api/health` reports `concurrency_limit`. `start-admission-ai.ps1` exits if the value is not `1`. |
| `KNOWLEDGE_DIR` | `<repo>\knowledge\approved` | Folder of approved JSON facts. The binary uses this path when it exists. Otherwise it tries `knowledge/approved` from the repo root, then the built-in macOS and `C:\xampp\htdocs\hcc\knowledge\approved` paths. |

Knowledge files the loader picks up when the directory exists:

- `admission_prospectus.json`
- `fee_structure.json`
- `class_information.json`
- `age_criteria.json`
- `admission_checklist.json`
- `school_timings.json`
- `transport_details.json`
- `contact_information.json`
- `admission_policies.json`
- `SCHOOL_KNOWLEDGE_SUMMARY.md`

Missing files are skipped. If the directory is missing or empty, the service uses a small built-in fallback. Check `/api/health` and confirm `knowledge_topics_loaded` matches the files you expect.

The service also caps its in-memory queue (`max_queue_depth` 100) and uses a 60 second request timeout. Those are compiled in. Do not raise them to run more than one inference at a time.

CLI, if you are not using the script: `admission-ai-service --host 127.0.0.1 --port 8085 --model llama3.2:1b serve`. `benchmark` can call more than one model. Do not run that against several large models on this PC.

## Follow-up engine

The binary is `hcc-automation-engine`. It uses the environment below. Flags such as `--db-host` override the environment for that one field. `start-followup-engine.ps1` does not pass `--db-pass` on the command line, because the process list would show it. The script exits with status 1 if `DB_PASS` is unset. Always set `DB_PASS` yourself before `--daemon`, `--status`, `--once`, or `--recover`.

| Variable | Safe default | What it does |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host. `localhost` is rewritten to `127.0.0.1` for TCP. |
| `DB_PORT` | `3306` | MySQL TCP port. |
| `DB_USER` | `hcc_app` | Same account as PHP `DB_USER`, unless you deliberately split them. |
| `DB_PASS` | none | Required. Same password as PHP `DB_PASS`. No default is written in the scripts. |
| `DB_NAME` | `hcc_admin` | Same database as PHP. |
| `DB_SOCKET` | omit on Windows | Unix socket. Leave it unset. The start script removes it from the process if it was inherited. The engine uses TCP when no socket path exists. |

Modes you will actually use:

- `--daemon` — loop every 5 seconds. On startup it moves `automation_jobs` rows left in `processing` back to `pending`, then enqueues due reminders and processes pending jobs. This is what the Windows script runs.
- `--status` — JSON counts only. Does not recover or process.
- `--once` — one cycle: recover, enqueue due reminders, process, exit.
- `--recover` — only the processing → pending reset, then exit.

The engine writes a suggested message and a staff notification. A person still has to open WhatsApp and press Send. Restarting it cannot send a WhatsApp message.

## What stays out of git

- `config/env.php`
- `vendor/` (restore with `composer install`)
- `automation/node_modules/`
- `logs/`
- `automation/.whatsapp-session` and `%USERPROFILE%\.hcc-whatsapp-data` if those session folders exist

WhatsApp session folders are not required for this deployment.

### Bridge process supervisors

| OS | How to keep the bridge running |
|---|---|
| macOS | `./automation/install-launchd.sh` (renders `com.hcc.whatsapp-bridge.plist.template`) |
| Windows | `automation\start-bridge.bat` / `windows\start-bridge.ps1` (see `WINDOWS_DEPLOYMENT.md`) |
| Linux | `./automation/linux/install-systemd.sh` (see `LINUX_DEPLOYMENT.md`) |

Delete-for-everyone is queued as outbound `msg_type=revoke`. The bridge must be
connected to perform it inside WhatsApp Web. Optional env `WHATSAPP_REVOKE_WINDOW_SEC`
overrides the default 48-hour window check (PHP + Node share the same rule).
 Do not create them to satisfy a checklist, and do not commit them.
