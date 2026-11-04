# Admission hub audit

This report describes the School WhatsApp Hub as it stands after the admission workflow was finished inside the existing PHP, MySQL, and WhatsApp-style WebUI. The project was not renamed or replaced.

## Architecture

```
Staff browser
  WhatsApp-style WebUI (whatsapp_share.php, whatsapp-web.php)
  Admission pages (admission_enquiries.php, admission_followups.php)
        |
        v
PHP APIs (admin/api_admission_enquiry.php, api_admission_followup.php, api_whatsapp_ai.php)
        |
        v
MySQL hcc_admin
        |
        +--> Rust admission-ai-service :8085 --> Ollama (one model)
        |
        +--> Rust hcc-automation-engine (MySQL job queue, reminders only)
```

Approved facts live in `knowledge/approved/*.json`. The model never receives permission to run SQL. Staff approve a draft, open a `wa.me` link, and press Send themselves.

## Directory map

| Path | Role |
|---|---|
| `admin/` | Existing admin pages plus admission enquiry and follow-up pages and APIs |
| `admin/includes/` | Shared admin header, sidebar, WhatsApp helpers |
| `assets/js/whatsapp-*.js` | WhatsApp-style WebUI, including the new-enquiry modal and enquiry drawer |
| `assets/js/admission-followups.js` | Follow-up dashboard behaviour |
| `assets/js/hcc-pdf-engine.js` | Existing client PDF generation |
| `automation/admission-ai-service/` | Rust AI draft service |
| `automation/rust_engine/` | Rust reminder queue |
| `automation/whatsapp-bridge.js` | Existing Node bridge. Not used to send admission replies |
| `automation/windows/` | Windows start and service scripts |
| `config/` | Database and env loading. `env.php` stays untracked |
| `data/migrations/` | Enquiry, follow-up, and timetable SQL |
| `docs/` | Deployment, audit, test report, limits |
| `includes/` | Shared PHP helpers, including CSRF and audit logging |
| `knowledge/approved/` | Approved admission facts |
| `tests/admission_workflow_e2e.php` | End-to-end workflow check |
| `public_*.php` | Existing public certificate, card, and slip pages |

## Database relationships

Existing school tables (`report_card`, `users`, `whatsapp_contacts`, `whatsapp_chats`, `whatsapp_messages`, `audit_logs`) stay in place.

```
users.id
  ^ assigned_staff_id / created_by on admission_enquiries

report_card.parent_phone / student_name
  ^ matched when an enquiry is captured (matched_student_roll)

whatsapp_contacts.id  <-- admission_enquiries.contact_id
whatsapp_chats.id     <-- admission_enquiries.chat_id
whatsapp_messages.chat_id --> whatsapp_chats.id

admission_enquiries
  |-- admission_enquiry_messages (incoming, ai_draft, staff_edit, approved_response, follow_up, status_change, note)
  |-- admission_followup_history (created, opened WhatsApp, manual send, completed, cancelled)

admission_matching_rules
  standalone settings for duplicate detection

automation_jobs.dedup_key UNIQUE
  |-- automation_job_history
```

Follow-up dates, priority, reminder status, and manual-send flags are columns on `admission_enquiries`, added by `data/migrations/migrate_admission_followups.php`. No second parent or student table was added.

## Feature inventory

Already in the project before this pass:

- Admin login, admin and editor roles, CSRF on classic form posts, audit log
- WhatsApp-style chat list, conversation pane, roster, dispatch log, PDF share
- Admission tables, pipeline statuses, duplicate rules, enquiry dashboard
- Follow-up dashboard sections and the Rust reminder engine
- Rust AI service with intent list, knowledge files, single-job queue, and a safety check
- Follow-up screen Open WhatsApp and manual-send confirmation

Finished in this pass, because those pieces did not meet the workflow:

- JSON screens now send a CSRF token the server accepts, and the token is present in the page
- New enquiries validate international and Indian mobile numbers before save
- Same phone and student cannot be inserted twice by two overlapping requests
- Generate Draft calls the Rust service. If Rust or Ollama is down, the draft withholds fees, seats, dates, eligibility, and document lists and tells staff the service is down
- Approved replies are stored as `pending`, not `sent`
- Enquiry screen and WhatsApp drawer can open `https://wa.me/...` and record a manual send with `delivery_verified = 0`
- Logging a follow-up date writes `next_followup_date` so the dashboard and the Rust engine see it
- End-to-end test, Windows scripts, and these documents

## Missing or intentionally not built

- Automatic reading of a WhatsApp inbox
- Automatic pressing of Send, delivery receipts, or read receipts
- WhatsApp Business Cloud API and unofficial WhatsApp APIs
- A live seat register. Vacant seats stay an office check
- A Windows run of `tests/admission_workflow_e2e.php`. The pass recorded here is on the development Mac

## Integration plan

1. Keep capturing enquiries in the existing WebUI and `admission_enquiries.php`.
2. Keep parent and student identity in `whatsapp_contacts`, `whatsapp_chats`, and `report_card`.
3. Keep drafts behind `POST /api/process` on `127.0.0.1:8085`.
4. Keep reminders in `automation_jobs`, recovered by `hcc-automation-engine`.
5. Keep sending as a manual `wa.me` handoff.

## Risks and compatibility

- The WebUI posts JSON. CSRF must accept `X-CSRF-Token`. Classic forms still post `csrf_token`.
- An approved message used to be inserted as WhatsApp status `sent`. It is now `pending` until a person presses Send.
- `AI_INFERENCE_CONCURRENCY` above 1 can load more than one inference. The Windows script refuses that on the 8 GB machine.
- The development Mac has many Ollama models installed. The Windows PC must pull only `llama3.2:1b`.
- macOS release binaries do not run on Windows. Build them on the Windows PC.
- `automation/whatsapp-bridge.js` still has a macOS Chrome path. Admission replies do not use it.

## Implementation sequence

The sequence below is what the code now follows. Do not rebuild it as a new app.

1. Apply `create_admission_enquiry_tables.sql` if those tables are missing.
2. Run `php data/migrations/migrate_admission_followups.php`.
3. Confirm `config/env.php` and start MySQL and Apache.
4. `ollama pull llama3.2:1b` and start one Ollama process.
5. Start `admission-ai-service`, then `hcc-automation-engine`.
6. Run `php tests/admission_workflow_e2e.php` on the target PC.
