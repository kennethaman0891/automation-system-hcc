# WhatsApp automatic sending — removal report

Date: 2026-11-06 (Asia/Calcutta)
Backup: `~/hcc-review-backup-20261106-002600/`

## Goal

Remove every automatic WhatsApp send path end to end.  
**Not** a lock or kill-switch. The capability is deleted.  
The only send path is: user opens hub → writes or picks a template → presses Send → bridge sends that row once.

## Removed features (file → what was removed)

| File | What was removed |
|------|------------------|
| `admin/includes/whatsapp_send_lock.php` | **Deleted.** Entire lock layer (WA_SEND_MODE, confirm tokens, hold/draft/blocked_auto helpers). |
| `admin/api_whatsapp_send_token.php` | **Deleted.** Confirm-token endpoint. |
| `admin/api_automated_send.php` | Body replaced with **HTTP 410**. PDF/report auto-send gone. |
| `automation/whatsapp_sender.js` | Body replaced with refuse stub (exit 2). CLI Puppeteer send gone. |
| `admin/auth_check.php` | Removed `qa_test` / `X-Admin-Test` login bypass. |
| `admin/includes/whatsapp_functions.php` | Removed lock require; `waRequireAuth` is session-only; enqueue always queues on user press and requires admin; added `waFailStaleOutbound` (stale → failed, press Retry). |
| `admin/api_whatsapp_send.php` | Confirm-token / held / draft / blocked_auto paths removed. Retry only for failed/cancelled on press. |
| `admin/api_whatsapp_templates.php` | Confirm-token gate removed; send still on press via enqueue. |
| `admin/api_whatsapp_outbound_queue.php` | No hold/filter-by-lock. On dequeue, stale queued rows → failed; only fresh rows returned. |
| `admin/api_whatsapp_ai.php` | `approve_draft` **never** inserts into outbound queue / never sends. |
| `automation/start-bridge.sh` | Kill-switch `BRIDGE_DO_NOT_START` removed; restart loop removed; **start once**. |
| `automation/stop-bridge.sh` | **Added** clean stop script. |
| `automation/whatsapp-bridge.js` | No backlog flush/hold; no auto-retry of failed rows; no `WA_SEND_MODE` checks; uses `X-Bridge-Secret` (not QA header). |
| `assets/js/whatsapp-store.js` | Confirm-token helpers removed; AI approve defaults to no send. |
| `assets/js/whatsapp-templates.js` | Token fetch removed. |
| `assets/js/hcc-pdf-engine.js` | `shareWhatsAppAutomated` refuses; points user to hub Send. |
| `config/env.php` / `env.example.php` | Removed keys: `WA_SEND_MODE`, `WA_ALLOW_QA_SEND`, `WA_REMOTE_ALERTS_WHATSAPP`, `WA_SEND_STALE_HOLD_SEC`. |
| `config/database.php` | Test DB override only when `PHP_SAPI` is `cli` or `cli-server` (HTTP cannot flip DB). |
| `automation/linux/*.template` | `Restart=on-failure` (crash only). |
| `automation/hcc-shots.js`, `hcc-toast-shot.js` | Removed `qa_test` query params. |

## What remains (send-on-press)

- Hub chat list, conversation, compose, templates (edit/save/send on press)
- Group / broadcast send on press (2s stagger between chats — still one user action)
- Ticks (sent / delivered / read)
- Delete for me / delete for everyone on press
- Retry on press
- QR / session
- Ingest + admission enquiry creation (no reply)
- Bridge secret auth for bridge→API (ingest, queue drain, session) — **cannot** create sends (`waRequireAuth` rejects bridge)

## Proof A — static audit

Script: `tests/e2e_offline/audit_no_auto_send.sh`

```
PASS no_WA_SEND_MODE
PASS no_WA_ALLOW_QA_SEND
PASS no_WA_REMOTE_ALERTS
PASS no_WA_SEND_STALE
PASS no_BRIDGE_DO_NOT_START
PASS no_whatsapp_send_lock
PASS no_qa_test_param
PASS no_X_Admin_Test
PASS no_blocked_auto_status_write
PASS no_held_status_write
PASS outbound INSERT only in enqueue helper + chat_actions revoke
PASS waEnqueue only from send + templates
PASS whatsapp_sender.js has no send capability
PASS ai_no_outbound_insert
PASS automated_send_is_410
PASS followup_no_outbound_queue
PASS rust_no_outbound_queue
PASS start-bridge.sh is start-once
PASS whatsapp_send_lock.php deleted
PASS api_whatsapp_send_token.php deleted
AUDIT_FAILS=0
```

Outbound INSERT sites left:

- `admin/includes/whatsapp_functions.php` → `waEnqueueWhatsAppOutbound` (user-press only)
- `admin/api_whatsapp_chat_actions.php` → revoke on press

Enqueue callers:

- `admin/api_whatsapp_send.php`
- `admin/api_whatsapp_templates.php` (action=send)

## Proof B — offline tests

```
./tests/e2e_offline/run_all.sh
DONE suites_ok=10 suites_failed=0
```

Suites: audit_no_auto_send, safety_guard, platform, revoke_rules, hub_js, single_instance, php_pipelines (38 checks), no_auto_send (18 checks), bridge_mock (14 checks), lid_rules.

`no_auto_send` proved: user press queues once; no admin → throw; stale → failed + Retry text; inbound store adds zero outbound; ingest source has no enqueue; lock files gone; automated_send stub is 410; test DB SAPI-gated.

## Proof C — production queue

At end of this work (`hcc_admin`):

- `queued_or_processing = 0`
- No rows in `held` / `draft` / `blocked_auto` to convert (table had no such rows; convert step was a no-op)
- Live bridge: **stopped**

## Honest leftovers / notes

- Audit columns `origin`, `approved_by`, `approved_at`, `confirm_nonce` remain on the table (harmless). Enqueue still sets `origin=manual_ui` + `approved_by` for audit. Statuses `held`/`draft`/`blocked_auto` may still exist in the MySQL ENUM from the old migration but are **not produced** by current code.
- `admin/migrate_whatsapp_manual_send_lock.php` remains as historical migration; it is not a runtime lock.
- Admission follow-up / rust engine still create **suggested text** and `automation_jobs` reminder jobs — they do **not** write `whatsapp_outbound_queue` or send WhatsApp (audit confirms). Staff must copy text into the hub and press Send.
- AI drafts can still be generated and approved as text — approval does not send.
- Parent “Open WhatsApp” links in admission follow-up still open WhatsApp Web with prefilled text; WhatsApp itself still requires a human to press send there. That is outside this queue.
- Optional launchd/systemd KeepAlive / Restart=on-failure can restart a crashed bridge process; they cannot create outbound rows.
