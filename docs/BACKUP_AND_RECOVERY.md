# Backup and recovery — HCC / School WhatsApp Hub

Back up before every migration. Do not drop tables and do not delete production rows as a preparation step. Restoring a dump replaces the tables inside that dump; that is the recovery path, not a cleanup step.

This app is not production-ready until `tests/admission_workflow_e2e.php` passes on the Windows machine you are backing up. Take the backup anyway.

## What to copy

Do this while staff are not saving records. Stop `hcc-automation-engine` so it is not mid-update. You can leave MySQL running. `--single-transaction` is the consistent snapshot for InnoDB.

### 1. Database `hcc_admin`

From `cmd.exe`, so redirection works. `-p` prompts. Do not put the password in the command or in the file name.

```
mkdir C:\backups\hcc
cmd /c "C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3306 -u hcc_app -p --single-transaction --routines hcc_admin > C:\backups\hcc\hcc_admin-YYYYMMDD.sql"
```

Use the real `DB_USER` if it is not `hcc_app`. Keep the `.sql` file off the web root. `C:\xampp\htdocs` is the wrong place for it.

### 2. Knowledge files

Copy the whole folder:

```
C:\xampp\htdocs\hcc\knowledge\approved
```

Those JSON files are the facts the AI draft is allowed to use. The database knowledge seed is a copy loaded by `admin\migrate_ai_admission.php`. Back up the files and the database. Either one alone is an incomplete restore.

### 3. config\env.php

Copy `C:\xampp\htdocs\hcc\config\env.php` to the same offline backup disk. It holds `DB_PASS` and `JWT_SECRET`. It is gitignored. Do not commit it, do not email it, and do not put it back into `htdocs` under another name.

### 4. Uploads and generated files, if they exist

This tree has no `uploads` directory. Excel imports use the Windows temp directory and are not a permanent store.

If these paths exist on the machine, copy them too. They are not required for a fresh install:

- `C:\xampp\htdocs\hcc\storage\` (the optional WhatsApp bridge writes media under `storage\whatsapp\` when it has been used)
- `C:\xampp\htdocs\hcc\logs\`
- `C:\xampp\htdocs\hcc\data\state.json`

Skip `vendor\` and `automation\node_modules\`. Restore those with `composer install` and, only if you still use the bridge, `npm install`.

## Do not require WhatsApp session files

Do not store WhatsApp session credentials as part of this backup. The admission workflow does not need them. AI drafts and follow-up suggestions are not sent by themselves.

If someone has already run the optional scripts, these folders may exist:

- `C:\xampp\htdocs\hcc\automation\.whatsapp-session` (default data directory for `automation\whatsapp-bridge.js`)
- `%USERPROFILE%\.hcc-whatsapp-data` (default data directory for `automation\whatsapp_sender.js`)

They are browser profiles, not application config. They must not be committed. Leave them out of the required backup set. If you already have a copy, treat it like a password and keep it offline: a copied profile can still be a logged-in WhatsApp Web session. The supported send path is the `https://wa.me/<digits>?text=...` link, a manual Send press, and a manual confirmation. That confirmation is not delivery and it is not a read receipt.

## Restore

1. Stop Apache, stop `hcc-automation-engine`, and stop `admission-ai-service`. Leave MySQL running.
2. Put `config\env.php` back in `config\`. Confirm `DB_SOCKET` is empty and `DB_HOST` is `127.0.0.1`.
3. Put `knowledge\approved` back.
4. Create an empty database named `hcc_admin` if it is gone. Do not drop it if you are about to import a dump that already contains `DROP TABLE` statements for its tables — point the import at the existing database.
5. Import:

   ```
   cmd /c "C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u hcc_app -p hcc_admin < C:\backups\hcc\hcc_admin-YYYYMMDD.sql"
   ```

6. Start in the deployment order: MySQL (already up), Ollama, admission AI, follow-up engine, Apache.
7. Open `http://127.0.0.1:8085/api/health` and run `hcc-automation-engine.exe --status` with `DB_PASS` set.

A dump import replaces the tables it contains with the backed-up rows. Do not run extra `DELETE` statements before or after the import.

## What survives a process restart

Captured `admission_enquiries` rows live in MySQL. Stopping the AI service, the follow-up engine, Apache, or Ollama does not remove them.

`admission_ai_drafts` and suggested follow-up text also live in MySQL once they have been saved. They are drafts. A restart does not send them.

The follow-up engine does not call WhatsApp. On startup it only repairs its own job table and then writes suggestions and staff notifications. A crash cannot flush a queue of WhatsApp sends, because there is no send queue to WhatsApp.

## Job rows left in `processing`

If the engine stops while a job is `processing`, that row is stuck until recovery runs.

Recovery sets `automation_jobs.status` from `processing` back to `pending`, clears `locked_at` and `locked_by`, and writes an `automation_job_history` row. It does not delete the job and it does not delete the enquiry.

These entry points run that recovery:

- `--recover` does only that reset, then exits.
- `--once` does the reset, then enqueues due reminders and processes pending jobs, then exits.
- `--daemon` does the same reset once, when the process starts, then loops. `start-followup-engine.ps1` uses `--daemon`.

`--status` does not recover anything.

After recovery, a pending reminder job may run and update `suggested_message` on the enquiry. That is still a draft for staff. It does not press Send.

## AI service restart

`admission-ai-service` keeps its queue in memory. A restart drops requests that had not finished. It does not touch MySQL enquiry rows. Start Ollama first, then the AI service, then check `/api/health` for `llama3.2:1b` and concurrency 1. Do not pull extra models as part of a restore.
