# AI Admission Review (local Ollama + optional API)

Built / extended 2026-11-06. Automatic WhatsApp sending remains **removed**. This feature only creates editable drafts; Send is manual after review.

## Where it lives

Open **Admission Enquiries** (`admin/admission_enquiries.php`). At the top of the page is a separate section:

**AI Admission Review**

## Analysis engines

Use the **Analysis engine** selector:

| Engine | Default | What Analyze uses |
|--------|---------|-------------------|
| **Local** | Yes | Ollama on this Mac (`http://127.0.0.1:11434`), model `llama3.2:1b` (or `AI_MODEL`) |
| **API** | No | Server-side HTTPS call to your configured OpenAI-compatible provider (NVIDIA NIM or generic) |

There is **no silent fallback** between Local and API. If the selected engine fails, Analyze returns an error; switch engines yourself.

### Privacy / usage disclosure

Selecting **API** and clicking **Analyze** transmits **only** the selected chat text / pasted context to the chosen HTTPS provider and **may incur usage charges**. Connection Test uses a **synthetic sample**, not selected messages. Keys never leave the server except as the `Authorization` header to the configured host.

## Workflow (manual — same for Local and API)

1. **Select source** — inbound chats/messages, or paste text if none stored.
2. Choose **Analysis engine** (Local default, or API after configuring settings).
3. Click **Analyze selected** (confirm the context; API shows an extra disclosure).
4. Edit fields + reply → **Save draft**.
5. Optionally **Create / update enquiry** (explicit).
6. **Mark reviewed**.
7. **Send** only if WhatsApp is linked and draft is `reviewed`. Idempotent. Bridge offline → nothing queued.

Analyze / Save / Mark reviewed / settings save / connection test **never** enqueue WhatsApp. Only reviewed **Send** does.

## API settings (admin only)

Click **API settings**. Requires `admin` role + CSRF.

- **Provider**: `NVIDIA NIM (OpenAI-compatible)` or `Generic OpenAI-compatible`
- **Base URL**: HTTPS only. NVIDIA documented default (verified): `https://integrate.api.nvidia.com/v1`
- **Model ID**: enter the provider’s model id yourself — **no model id is guessed or hard-coded as a default**
- **API key**: password field, always empty on render. Leave blank to keep the existing key. After Save, UI shows only **Configured** / **Not configured** — the key is never echoed.
- **Save settings** / **Remove saved key** / **Test connection** (explicit click; synthetic prompt only)

No model call runs on page load, settings save, or provider selection alone.

### Provider examples (verified base URL only)

**NVIDIA NIM (OpenAI-compatible chat completions)**

- Base URL: `https://integrate.api.nvidia.com/v1`
- Endpoint used: `{base}/chat/completions`
- Model ID: paste the exact id from your NVIDIA NIM / catalog page (do not invent)
- Auth: `Authorization: Bearer <key>`

**Generic OpenAI-compatible**

- Base URL: your provider’s HTTPS OpenAI-compatible root (e.g. `https://api.example.com/v1`)
- Same `/chat/completions` path, Bearer key, model id you supply
- SSRF guard rejects loopback, private, link-local, metadata, reserved hosts/IPs, non-HTTPS, and credentials-in-URL. Redirects are disabled.

Local Ollama stays on its own trusted localhost config and is **not** subject to the public API SSRF rules.

## Secret storage

- API keys are **AES-256-GCM** encrypted at rest under `data/private/admission_ai_api_key.enc` (outside web-served content; directory `0700`, file `0600`, Apache `.htaccess` Deny).
- Master key is derived from `JWT_SECRET` in gitignored `config/env.php` (not stored next to the ciphertext).
- Keys are **never** stored in the DB `settings` table, git, JS, localStorage, HTML, logs, test results, or API JSON responses.
- Encryption/decryption errors fail closed (no plaintext fallback).
- Engine prefs (`admission_ai_engine`, `admission_ai_provider`, `admission_ai_base_url`, `admission_ai_model`) live in `settings` as non-secret strings only.

**Backups:** include `data/private/` and `config/env.php` together if you need to restore a working key; a ciphertext without the matching `JWT_SECRET` cannot be decrypted. Exclude key files from public shares.

## Draft provenance

Each analysis stores (never the key):

- `analysis_engine` — `local` or `api`
- `analysis_provider` — e.g. `nvidia`, `openai_compatible`, or `ollama`
- `analysis_model` — model id used
- `analysis_endpoint_host` — hostname only (e.g. `integrate.api.nvidia.com`), empty for local

Switching the engine later does **not** rewrite prior drafts’ provenance.

## Statuses

`draft` → `needs_review` → `reviewed` → `sent` (or `failed`)

## HTTP API

`admin/api_admission_ai_review.php`

- GET: `health`, `list_chats`, `list_messages`, `list_drafts`, `get_draft`, `get_settings`
- POST (+ CSRF): `analyze`, `save_draft`, `mark_reviewed`, `send`, `create_enquiry`, `delete_draft`, `save_settings`, `test_connection`

`save_settings` / `test_connection` require **admin** role. Real admin session required. No `qa_test` bypass.

## Schema

- `data/migrations/create_admission_ai_review_drafts.sql`
- `data/migrations/alter_admission_ai_review_provenance.sql`
- Apply: `php admin/migrate_admission_ai_review_drafts.php` then `php admin/migrate_admission_ai_review_provenance.php`

## Requirements

**Local**

```bash
ollama serve
ollama pull llama3.2:1b   # or set AI_MODEL
```

**API**

1. Open API settings as admin.
2. Choose provider, set HTTPS base URL, enter model id, paste key → Save.
3. Optionally Test connection (synthetic).
4. Switch Analysis engine to API → Analyze.

No cloud credentials are requested in chat; enter the key only in the protected admin UI on your PHP app.

## Tests

```bash
./tests/e2e_offline/run_all.sh
# includes admission_ai_review (mock Ollama), admission_ai_api_engine (mock HTTP), audit_no_auto_send
```

Offline suite uses `AIR_MOCK_OLLAMA=1` and `AIR_MOCK_HTTP=1` — **no live provider call and no API quota spend**. Passing tests do **not** prove a real NVIDIA/OpenAI account works; that remains unverified until you enter a key and run Test connection / Analyze yourself.

## Known limitations

- Prod may have **zero inbound** WhatsApp messages stored — use manual paste until inbound ingest works.
- Small local models (1b) can mis-quote; always review before Send.
- API model quality/availability depends on the provider and the model id you enter.
- Real external API service is **unverified** in automated tests (mock transport only).
- Send still needs the WhatsApp bridge linked (you start it); this feature never starts the bridge.
