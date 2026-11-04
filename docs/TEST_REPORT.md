# Test report — admission enquiry workflow

Date: 2026-11-03

Machine: development Mac (Apache, MySQL `hcc_admin`, Ollama with `llama3.2:1b` loaded by the service, Rust release binaries).

This is not a Windows 8 GB run. Do not treat the application as production-ready on the school PC until the same command passes there.

## Commands

```
php tests/admission_workflow_e2e.php
php scratch/test_admission_enquiries.php
```

The end-to-end script deletes only the rows it creates (`+919999999999` and roll `77001`). The older scratch script was cleaned up afterwards so its sample parent was not left in MySQL.

## End-to-end result

25 passed, 0 failed, 0 skipped.

| Check | Result |
|---|---|
| 1. Capture a new parent enquiry | Pass. HTTP 201, original message stored verbatim |
| 2. Validate parent phone number | Pass. `12345` rejected with HTTP 422 |
| 3. Create a parent profile and reuse the student | Pass. Contact, chat, and report-card roll `77001` linked |
| 4. Detect an admission-related intent | Pass. Intent was one of the supported labels |
| 5. Retrieve approved admission information | Pass. Knowledge sources returned |
| 6. Generate an AI response | Pass. Draft text returned from the Rust service |
| 7. Detect missing or unsupported information | Pass |
| 8. Escalate uncertain responses | Pass. Seat claim of "4 seats" was not copied into the draft |
| 9. Edit and approve a response | Pass. Outbound chat row stayed `pending`, not `sent` |
| 10. Open WhatsApp with prefilled text | Pass. URL started `https://wa.me/919999999999?text=` |
| 11. Manually confirm sending | Pass. `manual_sent_confirmed = 1`, `delivery_verified = 0` |
| 12. Schedule a follow-up | Pass. `next_followup_date` matched the test date |
| 13. Display the reminder on that date | Pass. Today list and today count included the enquiry |
| 14. Complete the follow-up | Pass. Delivery flag stayed unverified |
| 15. Update the admission pipeline | Pass. Status became `INTERESTED` |
| 16. Verify audit history | Pass. Audit rows and timeline rows were present |
| 17. Recover a job after a crash | Pass. A `processing` job returned to `pending` with a `recovered` history row |
| 18. Concurrent creates do not duplicate | Pass. One row remained. A second draft call was deduped |

Raw machine output is in `tests/last_e2e_result.json`.

## Older module test

`scratch/test_admission_enquiries.php`: 20 passed, 0 failed. That script checks tables, duplicate rules, the offline holding draft, pipeline statuses, and the audit log.

## Page check

`admin/admission_enquiries.php?qa_test=1` returned the enquiry screen with Generate AI Draft, Open WhatsApp, Mark manually confirmed sent, and the sentence that a manual confirmation is not a delivery receipt.

## Not tested here

- Pressing Send inside the real WhatsApp app
- A cold boot of the two Rust programs as Windows services
- Memory use of `llama3.2:1b` on an 8 GB Windows PC
- Two large models loaded at once. That configuration is rejected by the Windows start script
