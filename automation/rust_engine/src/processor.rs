use crate::models::{Job, JobPayload};
use mysql::prelude::*;
use mysql::*;
use serde_json::json;
use std::time::Instant;

pub fn prepare_suggested_whatsapp_message(
    parent_name: &str,
    student_name: &str,
    grade: &str,
    category: &str,
    reason: &str,
) -> String {
    let clean_grade = if grade.trim().is_empty() {
        "the upcoming grade"
    } else {
        grade.trim()
    };
    let clean_student = if student_name.trim().is_empty() {
        "your child"
    } else {
        student_name.trim()
    };
    let clean_parent = if parent_name.trim().is_empty() {
        "Parent"
    } else {
        parent_name.trim()
    };

    match category {
        "First response pending" => {
            format!(
                "Namaste {} ji, thank you for contacting Hindustani Covenant Church High School regarding admission for {} ({}). Our admissions team is delighted to assist you. We offer the Maharashtra State Board (SSC) curriculum, modern digital classrooms, sports facilities, and school transport across Solapur. Would you like us to share our school prospectus and fee breakdown?",
                clean_parent, clean_student, clean_grade
            )
        }
        "Admission details shared" => {
            format!(
                "Namaste {} ji, hope you had a chance to review the admission details and syllabus for {} in {}. We would be happy to schedule a campus walk-through and an interaction with our teaching faculty. Please let us know a convenient time this week.",
                clean_parent, clean_student, clean_grade
            )
        }
        "Parent requested more information" => {
            format!(
                "Namaste {} ji, following up from HCC High School regarding your query about {}. We have detailed information on our academic curriculum, extracurricular clubs, and transport routes ready for you. When would be a good time to discuss?",
                clean_parent, reason
            )
        }
        "Parent interested" => {
            format!(
                "Namaste {} ji, we are glad to note your keen interest in HCC High School for {} ({}). Seats for the upcoming academic session are filling up rapidly. Would you like to reserve an interaction slot or collect the admission kit from our administrative office this week?",
                clean_parent, clean_student, clean_grade
            )
        }
        "Campus visit pending" => {
            format!(
                "Namaste {} ji, gentle reminder regarding your campus visit to Hindustani Covenant Church High School for {}. Our campus is open Monday to Saturday from 9:00 AM to 2:00 PM. Please let us know if today or tomorrow suits your family.",
                clean_parent, clean_student
            )
        }
        "Documents pending" => {
            format!(
                "Namaste {} ji, gentle reminder from HCC High School admissions desk regarding the pending documents (Birth Certificate / Previous School Transfer Certificate) for {}'s admission to {}. Kindly submit the copies at your earliest convenience to confirm the provisional seat.",
                clean_parent, clean_student, clean_grade
            )
        }
        "Application pending" => {
            format!(
                "Namaste {} ji, we hope you are having a wonderful day. The admission application form for {} ({}) is currently awaiting completed submission. Please feel free to reach out if you need any assistance in completing the form.",
                clean_parent, clean_student, clean_grade
            )
        }
        "Admission decision pending" => {
            format!(
                "Namaste {} ji, greetings from Hindustani Covenant Church High School. The admission evaluation for {} ({}) is under committee review. Our principal and admissions board would like to share the next steps with you. Kindly confirm if we may call you today.",
                clean_parent, clean_student, clean_grade
            )
        }
        _ => {
            format!(
                "Namaste {} ji, following up from HCC High School admissions desk regarding {} for {}. Please let us know how we can best assist your family.",
                clean_parent, clean_student, clean_grade
            )
        }
    }
}

pub fn should_populate_suggested_message(existing: Option<&str>) -> bool {
    existing.map(|s| s.trim().is_empty()).unwrap_or(true)
}

fn extract_10_digit_phone(text: &str) -> Option<String> {
    let mut digits = String::new();
    for ch in text.chars() {
        if ch.is_ascii_digit() {
            digits.push(ch);
        } else if !digits.is_empty() {
            if digits.len() >= 10 {
                let candidate = &digits[digits.len() - 10..];
                if matches!(candidate.chars().next(), Some('6'..='9')) {
                    return Some(candidate.to_string());
                }
            }
            digits.clear();
        }
    }
    if digits.len() >= 10 {
        let candidate = &digits[digits.len() - 10..];
        if matches!(candidate.chars().next(), Some('6'..='9')) {
            return Some(candidate.to_string());
        }
    }
    None
}

pub fn classify_inbound_message(body: &str, msg_type: &str) -> (&'static str, f64, Option<String>) {
    let lower = body.to_lowercase();
    let phone = extract_10_digit_phone(body);

    if lower.contains("absent")
        || lower.contains("leave")
        || lower.contains("sick")
        || lower.contains("fever")
        || lower.contains("unwell")
        || lower.contains("hospital")
        || lower.contains("not coming")
    {
        ("leave", 0.91, phone)
    } else if lower.contains("new number")
        || lower.contains("changed number")
        || lower.contains("change number")
        || lower.contains("update number")
        || lower.contains("change phone")
        || (phone.is_some()
            && (lower.contains("number") || lower.contains("mobile") || lower.contains("phone")))
    {
        ("phone_change", 0.90, phone)
    } else if lower.contains("paid")
        || lower.contains("payment")
        || lower.contains("upi")
        || lower.contains("utr")
        || lower.contains("receipt")
        || lower.contains("transaction")
        || lower.contains("transferred")
        || lower.contains("challan")
    {
        ("fee_claim", 0.89, phone)
    } else if lower.contains("ptm")
        || lower.contains("meeting")
        || lower.contains("will attend")
        || lower.contains("coming tomorrow")
        || lower.contains("confirm")
    {
        ("ptm_confirm", 0.85, phone)
    } else if lower.contains("result")
        || lower.contains("marks")
        || lower.contains("report card")
        || lower.contains("percentage")
        || lower.contains("unit test")
        || lower.contains("exam")
    {
        ("result_query", 0.87, phone)
    } else if msg_type == "image"
        || msg_type == "document"
        || lower.contains("aadhar")
        || lower.contains("aadhaar")
        || lower.contains("birth certificate")
        || lower.contains("leaving certificate")
        || lower.contains("document")
    {
        ("doc_upload", 0.84, phone)
    } else {
        ("other", 0.60, phone)
    }
}

fn build_signal_suggested_reply(
    signal_type: &str,
    student_name: Option<&str>,
    extracted_phone: Option<&str>,
) -> String {
    let stu = student_name.unwrap_or("your child");
    match signal_type {
        "leave" => format!(
            "Respected Parent,\n\nThank you for informing the school regarding {}'s absence. We have noted this leave notice for the class teacher's register.\n\nWarm regards,\nHCC High School Office",
            stu
        ),
        "phone_change" => format!(
            "Respected Parent,\n\nThank you for sharing the updated contact number ({}). Our office will update the student record after verification.\n\nWarm regards,\nHCC High School Office",
            extracted_phone.unwrap_or("provided")
        ),
        "fee_claim" => "Respected Parent,\n\nThank you for sharing the fee payment update. Our accounts desk will verify the transaction and share confirmation shortly.\n\nWarm regards,\nHCC High School Accounts Desk".to_string(),
        "ptm_confirm" => "Respected Parent,\n\nThank you for confirming your visit/PTM slot. Kindly carry a valid photo ID for campus entry.\n\nWarm regards,\nHCC High School Office".to_string(),
        "result_query" => format!(
            "Respected Parent,\n\nThank you for reaching out regarding {}'s academic marks/report card. The class teacher / office is reviewing the record and will reply shortly.\n\nWarm regards,\nHCC High School Office",
            stu
        ),
        "doc_upload" => "Respected Parent,\n\nThank you for sharing the document. Our admission desk will review it and confirm the checklist status.\n\nWarm regards,\nHCC High School Office".to_string(),
        _ => "Respected Parent,\n\nThank you for your message. Our staff will review and respond during school office hours (09:00 AM to 03:30 PM, Mon-Sat).\n\nWarm regards,\nHCC High School Office".to_string(),
    }
}

pub fn process_pending_jobs(pool: &Pool, worker_id: &str, limit: usize) -> Result<usize> {
    let mut conn = pool.get_conn()?;

    let jobs: Vec<Job> = conn.query_map(
        format!(
            "SELECT id, job_type, payload, dedup_key, status, priority, attempts, max_attempts,
                    DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:%i:%s') as scheduled_at, last_error
             FROM automation_jobs
             WHERE status = 'pending' AND scheduled_at <= NOW()
             ORDER BY priority DESC, scheduled_at ASC
             LIMIT {}",
            limit
        ),
        |(
            id,
            job_type,
            payload,
            dedup_key,
            status,
            priority,
            attempts,
            max_attempts,
            scheduled_at,
            last_error,
        )| Job {
            id,
            job_type,
            payload,
            dedup_key,
            status,
            priority,
            attempts,
            max_attempts,
            scheduled_at,
            last_error,
        },
    )?;

    if jobs.is_empty() {
        return Ok(0);
    }

    let mut processed = 0;

    for job in jobs {
        conn.exec_drop(
            "UPDATE automation_jobs
             SET status = 'processing', locked_at = NOW(), locked_by = ?
             WHERE id = ? AND status = 'pending'",
            (worker_id, job.id),
        )?;

        if conn.affected_rows() == 0 {
            continue;
        }

        let start_time = Instant::now();
        let payload: JobPayload = serde_json::from_str(&job.payload).unwrap_or_default();
        let is_simulated_fail = payload.should_fail.unwrap_or(false);

        let mut tx = conn.start_transaction(TxOpts::default())?;
        let execution_result = if is_simulated_fail {
            Err("Simulated network or service timeout for retry verification".to_string())
        } else {
            execute_single_job(&mut tx, &job, &payload, worker_id)
        };

        let duration_ms = start_time.elapsed().as_millis() as u32;

        match execution_result {
            Ok(summary) => {
                tx.exec_drop(
                    "UPDATE automation_jobs
                     SET status = 'completed', completed_at = NOW(), locked_at = NULL, locked_by = NULL
                     WHERE id = ?",
                    (job.id,),
                )?;

                tx.exec_drop(
                    "INSERT INTO automation_job_history (
                        job_id, job_type, attempt_number, status, worker_id,
                        execution_duration_ms, output_summary, executed_at
                    ) VALUES (
                        ?, ?, ?, 'success', ?, ?, ?, NOW()
                    )",
                    (
                        job.id,
                        &job.job_type,
                        job.attempts + 1,
                        worker_id,
                        duration_ms,
                        &summary,
                    ),
                )?;

                tx.commit()?;
                processed += 1;
            }
            Err(err_msg) => {
                // Roll back any partial job mutation before recording failure/retry
                tx.rollback()?;

                let mut err_tx = conn.start_transaction(TxOpts::default())?;
                let next_attempts = job.attempts + 1;
                if next_attempts < job.max_attempts {
                    let backoff_secs = next_attempts * 30;
                    err_tx.exec_drop(
                        format!(
                            "UPDATE automation_jobs
                             SET status = 'pending', attempts = ?,
                                 scheduled_at = DATE_ADD(NOW(), INTERVAL {} SECOND),
                                 last_error = ?, locked_at = NULL, locked_by = NULL
                             WHERE id = ?",
                            backoff_secs
                        ),
                        (next_attempts, &err_msg, job.id),
                    )?;

                    err_tx.exec_drop(
                        "INSERT INTO automation_job_history (
                            job_id, job_type, attempt_number, status, worker_id,
                            execution_duration_ms, output_summary, error_details, executed_at
                        ) VALUES (
                            ?, ?, ?, 'retrying', ?, ?, 'Job scheduled for retry with backoff', ?, NOW()
                        )",
                        (
                            job.id,
                            &job.job_type,
                            next_attempts,
                            worker_id,
                            duration_ms,
                            &err_msg,
                        ),
                    )?;
                } else {
                    err_tx.exec_drop(
                        "UPDATE automation_jobs
                         SET status = 'failed', attempts = ?, last_error = ?,
                             locked_at = NULL, locked_by = NULL
                         WHERE id = ?",
                        (next_attempts, &err_msg, job.id),
                    )?;

                    err_tx.exec_drop(
                        "INSERT INTO automation_job_history (
                            job_id, job_type, attempt_number, status, worker_id,
                            execution_duration_ms, output_summary, error_details, executed_at
                        ) VALUES (
                            ?, ?, ?, 'failed', ?, ?, 'Job failed permanently after max attempts', ?, NOW()
                        )",
                        (
                            job.id,
                            &job.job_type,
                            next_attempts,
                            worker_id,
                            duration_ms,
                            &err_msg,
                        ),
                    )?;
                }
                err_tx.commit()?;
            }
        }
    }

    Ok(processed)
}

fn execute_single_job(
    tx: &mut Transaction<'_>,
    job: &Job,
    payload: &JobPayload,
    _worker_id: &str,
) -> std::result::Result<String, String> {
    match job.job_type.as_str() {
        "admission_reminder" => {
            let enquiry_id = payload
                .enquiry_id
                .ok_or_else(|| "Missing enquiry_id in payload".to_string())?;

            let enq_data: Option<(
                String,
                String,
                String,
                String,
                String,
                String,
                Option<String>,
                String,
                i8,
                i8,
            )> = tx
                .exec_first(
                    "SELECT parent_name, student_name, interested_class, followup_category,
                            COALESCE(followup_reason, ''), COALESCE(assigned_staff_name, 'Admissions Desk'),
                            suggested_message,
                            IF(next_followup_date < CURDATE(), 'overdue', 'reminded') as new_reminder_status,
                            is_completed, is_cancelled
                     FROM admission_enquiries
                     WHERE id = ?",
                    (enquiry_id,),
                )
                .map_err(|e| e.to_string())?;

            let (
                parent_name,
                student_name,
                grade,
                category,
                reason,
                staff_name,
                existing_suggested,
                new_reminder_status,
                is_completed,
                is_cancelled,
            ) = enq_data.ok_or_else(|| format!("Enquiry #{} not found", enquiry_id))?;

            if is_completed != 0 {
                return Ok(format!(
                    "Enquiry #{} already completed. Reminder skipped.",
                    enquiry_id
                ));
            }
            if is_cancelled != 0 {
                return Ok(format!(
                    "Enquiry #{} was cancelled. Reminder skipped.",
                    enquiry_id
                ));
            }

            // NEVER overwrite a non-empty staff or AI draft already on admission_enquiries
            if should_populate_suggested_message(existing_suggested.as_deref()) {
                let suggested_msg = prepare_suggested_whatsapp_message(
                    &parent_name,
                    &student_name,
                    &grade,
                    &category,
                    &reason,
                );
                tx.exec_drop(
                    "UPDATE admission_enquiries
                     SET reminder_status = ?, suggested_message = ?, updated_at = NOW()
                     WHERE id = ?",
                    (&new_reminder_status, &suggested_msg, enquiry_id),
                )
                .map_err(|e| e.to_string())?;
            } else {
                tx.exec_drop(
                    "UPDATE admission_enquiries
                     SET reminder_status = ?, updated_at = NOW()
                     WHERE id = ?",
                    (&new_reminder_status, enquiry_id),
                )
                .map_err(|e| e.to_string())?;
            }

            let notif_title = format!("Follow-up Reminder: {} ({})", parent_name, student_name);
            let notif_msg = format!(
                "Follow-up due for {} ({}) - Category: {} - Staff: {}. Review conversation & suggested WhatsApp message.",
                student_name, grade, category, staff_name
            );
            let notif_link = format!("admission_enquiries.php?id={}", enquiry_id);

            tx.exec_drop(
                "INSERT INTO system_notifications (title, message, type, link) VALUES (?, ?, 'admission_followup', ?)",
                (&notif_title, &notif_msg, &notif_link),
            )
            .map_err(|e| e.to_string())?;

            let history_title = format!("Automated Reminder Generated: {}", category);
            let history_details = format!(
                "Rust Automation Engine flagged follow-up due. Staff alert dispatched to {}. Manual Send-on-Press required.",
                staff_name
            );

            tx.exec_drop(
                "INSERT INTO admission_followup_history (
                    enquiry_id, action, title, details, performed_by, manual_sent_confirmed, delivery_verified
                ) VALUES (?, 'reminder_generated', ?, ?, 'Rust Automation Engine', 0, 0)",
                (enquiry_id, &history_title, &history_details),
            )
            .map_err(|e| e.to_string())?;

            Ok(format!(
                "Processed reminder for enquiry #{} (Parent: {}, Student: {}).",
                enquiry_id, parent_name, student_name
            ))
        }

        "inbound_triage" => {
            let msg_id = payload
                .message_id
                .ok_or_else(|| "Missing message_id in inbound_triage payload".to_string())?;

            let msg_row: Option<(
                u64,
                i8,
                String,
                Option<String>,
                Option<String>,
                String,
                String,
                Option<String>,
                Option<String>,
            )> = tx
                .exec_first(
                    "SELECT m.chat_id, m.from_me, m.msg_type, m.body,
                            DATE_FORMAT(m.received_at, '%Y-%m-%d %H:%i:%s') as received_at_str,
                            c.wa_chat_id, COALESCE(c.name, ''),
                            c.linked_student_roll, c.linked_class_key
                     FROM whatsapp_messages m
                     JOIN whatsapp_chats c ON c.id = m.chat_id
                     WHERE m.id = ?",
                    (msg_id,),
                )
                .map_err(|e| e.to_string())?;

            let (
                chat_id,
                from_me,
                msg_type,
                body_opt,
                received_at_opt,
                wa_chat_id,
                _chat_name,
                linked_roll,
                linked_class_key,
            ) = msg_row.ok_or_else(|| format!("Message #{} not found", msg_id))?;

            if from_me != 0 {
                return Ok(format!("Message #{} is outbound (from_me=1); skipped.", msg_id));
            }

            let body = body_opt.unwrap_or_default();
            if body.trim().is_empty() {
                return Ok(format!("Message #{} has empty text body; skipped.", msg_id));
            }

            // Match student in report_card by linked roll/class first, else parent_phone
            let mut matched_student: Option<(String, String, String)> = None;
            if let Some(ref roll) = linked_roll {
                if !roll.trim().is_empty() {
                    if let Some(ref cls) = linked_class_key {
                        matched_student = tx
                            .exec_first(
                                "SELECT student_name, roll_no, CONCAT(std, '-', division)
                                 FROM report_card
                                 WHERE roll_no = ? AND (CONCAT(std, '-', division) = ? OR std = ?)
                                 ORDER BY academic_year DESC, semester DESC
                                 LIMIT 1",
                                (roll, cls, cls),
                            )
                            .map_err(|e| e.to_string())?;
                    }
                    if matched_student.is_none() {
                        matched_student = tx
                            .exec_first(
                                "SELECT student_name, roll_no, CONCAT(std, '-', division)
                                 FROM report_card
                                 WHERE roll_no = ?
                                 ORDER BY academic_year DESC, semester DESC
                                 LIMIT 1",
                                (roll,),
                            )
                            .map_err(|e| e.to_string())?;
                    }
                }
            }

            let phone_digits: String = wa_chat_id
                .split('@')
                .next()
                .unwrap_or("")
                .chars()
                .filter(|c| c.is_ascii_digit())
                .collect();
            let last10_chat = if !wa_chat_id.ends_with("@g.us") && phone_digits.len() >= 10 {
                Some(phone_digits[phone_digits.len() - 10..].to_string())
            } else {
                None
            };

            if matched_student.is_none() {
                if let Some(ref last10) = last10_chat {
                    matched_student = tx
                        .exec_first(
                            "SELECT student_name, roll_no, CONCAT(std, '-', division)
                             FROM report_card
                             WHERE parent_phone LIKE CONCAT('%', ?)
                             ORDER BY academic_year DESC, semester DESC
                             LIMIT 1",
                            (last10,),
                        )
                        .map_err(|e| e.to_string())?;
                }
            }

            let (signal_type, confidence, extracted_phone) = classify_inbound_message(&body, &msg_type);
            let snippet: String = body.chars().take(240).collect();
            let extracted_json = json!({
                "phone_mentioned": extracted_phone,
                "snippet": snippet,
                "wa_chat_id": wa_chat_id,
                "msg_type": msg_type,
            })
            .to_string();

            let (stu_name, stu_roll, stu_class) = match &matched_student {
                Some((n, r, c)) => (Some(n.as_str()), Some(r.as_str()), Some(c.as_str())),
                None => (None, None, None),
            };
            let suggested_reply = build_signal_suggested_reply(
                signal_type,
                stu_name,
                extracted_phone.as_deref(),
            );

            // Insert signal card for text queries/actions (or non-trivial 'other' if it looks actionable)
            if signal_type != "other" {
                tx.exec_drop(
                    "INSERT IGNORE INTO inbound_signal_cards (
                        chat_id, message_id, signal_type,
                        matched_student_name, matched_roll_no, matched_class,
                        extracted_json, confidence, suggested_reply, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    (
                        chat_id,
                        msg_id,
                        signal_type,
                        stu_name,
                        stu_roll,
                        stu_class,
                        &extracted_json,
                        confidence,
                        &suggested_reply,
                    ),
                )
                .map_err(|e| e.to_string())?;
            } else {
                // Record a lightweight dismissed/low-priority row or pending if longer inquiry?
                // Recording 'dismissed' marks message as triaged so scheduler doesn't re-enqueue every cycle.
                tx.exec_drop(
                    "INSERT IGNORE INTO inbound_signal_cards (
                        chat_id, message_id, signal_type,
                        matched_student_name, matched_roll_no, matched_class,
                        extracted_json, confidence, suggested_reply, status, resolved_at
                    ) VALUES (?, ?, 'other', ?, ?, ?, ?, ?, ?, 'dismissed', NOW())",
                    (
                        chat_id,
                        msg_id,
                        stu_name,
                        stu_roll,
                        stu_class,
                        &extracted_json,
                        confidence,
                        &suggested_reply,
                    ),
                )
                .map_err(|e| e.to_string())?;
            }

            // Automation #12: Auto-advance active admission_enquiries follow-up when parent replies
            let mut advanced_enquiry_id: Option<u64> = None;
            if let Some(ref last10) = last10_chat {
                let active_enq: Option<(u64, String, String, Option<String>)> = tx
                    .exec_first(
                        "SELECT id, COALESCE(status, 'NEW'), COALESCE(followup_category, 'First response pending'),
                                DATE_FORMAT(last_inbound_at, '%Y-%m-%d %H:%i:%s')
                         FROM admission_enquiries
                         WHERE is_completed = 0
                           AND is_cancelled = 0
                           AND (phone_e164 LIKE CONCAT('%', ?) OR phone_number LIKE CONCAT('%', ?))
                         ORDER BY id DESC
                         LIMIT 1",
                        (last10, last10),
                    )
                    .map_err(|e| e.to_string())?;

                if let Some((enq_id, status_str, cat_str, last_in_opt)) = active_enq {
                    let is_newer = match (&last_in_opt, &received_at_opt) {
                        (None, _) => true,
                        (Some(prev), Some(curr)) => curr > prev,
                        (Some(_), None) => false,
                    };

                    if is_newer {
                        let new_cat = if cat_str == "First response pending" {
                            "Parent requested more information"
                        } else {
                            cat_str.as_str()
                        };
                        let new_status = if status_str == "NEW" {
                            "CONTACTED"
                        } else {
                            status_str.as_str()
                        };

                        tx.exec_drop(
                            "UPDATE admission_enquiries
                             SET status = ?,
                                 followup_category = ?,
                                 reminder_status = 'pending',
                                 next_followup_date = GREATEST(COALESCE(next_followup_date, CURDATE()), CURDATE()),
                                 last_inbound_at = COALESCE(?, NOW()),
                                 updated_at = NOW()
                             WHERE id = ?",
                            (new_status, new_cat, received_at_opt.as_deref(), enq_id),
                        )
                        .map_err(|e| e.to_string())?;

                        tx.exec_drop(
                            "INSERT INTO admission_followup_history (
                                enquiry_id, action, title, details, performed_by, manual_sent_confirmed, delivery_verified
                             ) VALUES (?, 'parent_reply_received', 'Parent Replied on WhatsApp', ?, 'Rust Automation Engine (Signal Triage)', 0, 0)",
                            (
                                enq_id,
                                format!("Detected inbound reply ({}). Category: {}, Status: {}.", signal_type, new_cat, new_status),
                            ),
                        )
                        .map_err(|e| e.to_string())?;

                        advanced_enquiry_id = Some(enq_id);
                    }
                }
            }

            Ok(format!(
                "Triaged msg #{} -> signal '{}' (student: {:?}, advanced_enquiry: {:?}).",
                msg_id, signal_type, stu_name, advanced_enquiry_id
            ))
        }

        "daily_briefing" => {
            let unreplied_chats: u64 = tx
                .query_first(
                    "SELECT COUNT(*)
                     FROM whatsapp_chats c
                     JOIN whatsapp_messages m ON m.id = c.last_message_id
                     WHERE c.chat_type = 'individual'
                       AND m.from_me = 0
                       AND c.last_activity_at >= NOW() - INTERVAL 7 DAY",
                )
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let today_followups: u64 = tx
                .query_first(
                    "SELECT COUNT(*) FROM admission_enquiries
                     WHERE next_followup_date = CURDATE() AND is_completed = 0 AND is_cancelled = 0",
                )
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let overdue_followups: u64 = tx
                .query_first(
                    "SELECT COUNT(*) FROM admission_enquiries
                     WHERE next_followup_date < CURDATE() AND is_completed = 0 AND is_cancelled = 0",
                )
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let urgent_enquiries: u64 = tx
                .query_first(
                    "SELECT COUNT(*) FROM admission_enquiries
                     WHERE priority IN ('high', 'urgent') AND is_completed = 0 AND is_cancelled = 0",
                )
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let pending_signals: u64 = tx
                .query_first("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending'")
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let leave_signals: u64 = tx
                .query_first("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending' AND signal_type = 'leave'")
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let fee_signals: u64 = tx
                .query_first("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending' AND signal_type = 'fee_claim'")
                .map_err(|e| e.to_string())?
                .unwrap_or(0);

            let dom_health: String = tx
                .query_first("SELECT COALESCE(dom_health_status, 'unknown') FROM whatsapp_sessions ORDER BY id DESC LIMIT 1")
                .map_err(|e| e.to_string())?
                .unwrap_or_else(|| "unknown".to_string());

            let brief_date = payload
                .brief_date
                .clone()
                .unwrap_or_else(|| chrono::Local::now().format("%Y-%m-%d").to_string());

            let summary_markdown = format!(
                "### HCC High School — 8:30 AM Principal Daily Briefing ({})\n\
- **Un-replied Parent Chats (7d):** {}\n\
- **Due Today Follow-ups:** {} | **Overdue:** {} | **High/Urgent Active:** {}\n\
- **Pending Inbound Signal Cards:** {} (Leave notices: {}, Fee claims: {})\n\
- **WhatsApp Bridge DOM Health:** {}\n\n\
_All parent replies require manual staff verification and Send-on-Press in WhatsApp Hub._",
                brief_date,
                unreplied_chats,
                today_followups,
                overdue_followups,
                urgent_enquiries,
                pending_signals,
                leave_signals,
                fee_signals,
                dom_health
            );

            let metrics_json = json!({
                "brief_date": brief_date,
                "unreplied_chats": unreplied_chats,
                "today_followups": today_followups,
                "overdue_followups": overdue_followups,
                "urgent_enquiries": urgent_enquiries,
                "pending_signals": pending_signals,
                "leave_signals": leave_signals,
                "fee_signals": fee_signals,
                "dom_health_status": dom_health,
            })
            .to_string();

            tx.exec_drop(
                "INSERT INTO daily_briefings (brief_date, summary_markdown, metrics_json)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     summary_markdown = VALUES(summary_markdown),
                     metrics_json = VALUES(metrics_json)",
                (&brief_date, &summary_markdown, &metrics_json),
            )
            .map_err(|e| e.to_string())?;

            Ok(format!("Generated daily briefing for {}.", brief_date))
        }

        "custom_test_job" => Ok(format!(
            "Custom test job processed successfully for payload: {:?}",
            payload
        )),

        _ => Err(format!("Unknown job type: {}", job.job_type)),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn suggested_message_uses_maharashtra_state_board_ssc_not_icse() {
        let msg = prepare_suggested_whatsapp_message(
            "Patil",
            "Riya",
            "Grade 5",
            "First response pending",
            "",
        );
        assert!(msg.contains("Maharashtra State Board (SSC) curriculum"));
        assert!(!msg.contains("ICSE"));
    }

    #[test]
    fn preserves_existing_non_empty_suggested_message() {
        assert!(!should_populate_suggested_message(Some("Custom AI draft")));
        assert!(should_populate_suggested_message(Some("   ")));
        assert!(should_populate_suggested_message(None));
    }

    #[test]
    fn classifies_all_inbound_signal_types() {
        assert_eq!(classify_inbound_message("He has fever, absent today", "text").0, "leave");
        assert_eq!(classify_inbound_message("Paid Rs 5000 via UPI", "text").0, "fee_claim");
        let (st, _, ph) = classify_inbound_message("Please change mobile number to 0000000000", "text");
        assert_eq!(st, "phone_change");
        assert_eq!(ph.as_deref(), Some("0000000000"));
        assert_eq!(classify_inbound_message("Will attend PTM tomorrow", "text").0, "ptm_confirm");
        assert_eq!(classify_inbound_message("When are Semester 1 marks out?", "text").0, "result_query");
        assert_eq!(classify_inbound_message("Attached image", "image").0, "doc_upload");
    }
}
