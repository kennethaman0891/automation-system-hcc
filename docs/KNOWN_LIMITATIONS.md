# Known limitations

The admission workflow is implemented in the existing hub. It is not production-ready until `php tests/admission_workflow_e2e.php` passes on the Windows PC that will run it.

## Sending

- Open WhatsApp only prefills a chat. The school application never presses Send.
- "Mark manually confirmed sent" records that a staff member says they pressed Send. `delivery_verified` stays 0. The app cannot know whether WhatsApp delivered or read the message.
- There is no WhatsApp Business Cloud API client and no stored WhatsApp password.

## AI

- Drafts are not messages. An administrator still approves the text.
- If Ollama or `admission-ai-service` is down, the draft is a holding reply. It quotes the approved helpline and does not state fees, seats, dates, eligibility, or document lists. The screen shows the service error.
- The service does not confirm vacant seats. Staff check the office register.
- Fee figures in a model draft are checked against `knowledge/approved/fee_structure.json`. A figure that is not in that file is flagged for escalation.
- The Windows start script allows only `llama3.2:1b` concurrency of 1. The binary can run up to 4 inferences if it is started by hand. Do not do that on 8 GB RAM.
- This development machine has other Ollama models installed. Do not copy that model list to the school PC.

## Data

- Duplicate detection follows `admission_matching_rules`. Phone plus student name blocks a second open enquiry when that rule is enabled. Other rules warn until a staff member confirms.
- Two saves for the same phone and student take a MySQL named lock so they cannot both pass the check.
- A second Generate Draft within 8 seconds returns the draft that was just stored.
- Follow-up reminders are prepared by the Rust engine. They are not sent.
- Restarting the engine returns `automation_jobs` left in `processing` to `pending`. Captured enquiries stay in MySQL.

## Interface

- The enquiry actions were added to the existing enquiry screen and the WhatsApp drawer. Other admin pages were not redesigned.
- The end-to-end test drives the PHP APIs over HTTP. It does not click through the WhatsApp desktop app.
