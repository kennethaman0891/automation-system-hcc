use crate::models::{AdmissionEnquiry, JobPayload};
use mysql::prelude::*;
use mysql::*;

fn table_exists(conn: &mut PooledConn, table_name: &str) -> Result<bool> {
    let found: Option<u64> = conn.exec_first(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
        (table_name,),
    )?;
    Ok(found.is_some())
}

pub fn scan_and_enqueue_reminders(pool: &Pool) -> Result<usize> {
    let mut conn = pool.get_conn()?;

    // Exclude already-processed overdue reminders so they are not re-scanned endlessly every 5s
    let query = "
        SELECT
            id, parent_name, COALESCE(student_name, '') AS student_name,
            COALESCE(interested_class, '') AS interested_class,
            COALESCE(followup_category, 'First response pending') AS followup_category,
            DATE_FORMAT(next_followup_date, '%Y-%m-%d') as next_followup_date,
            TIME_FORMAT(COALESCE(next_followup_time, '10:00:00'), '%H:%i:%s') as next_followup_time,
            COALESCE(followup_reason, '') as followup_reason,
            COALESCE(priority, 'medium') as priority
        FROM admission_enquiries
        WHERE is_completed = 0
          AND is_cancelled = 0
          AND next_followup_date IS NOT NULL
          AND TIMESTAMP(next_followup_date, COALESCE(next_followup_time, '10:00:00')) <= NOW()
          AND (
              reminder_status = 'pending'
              OR (
                  reminder_status = 'overdue'
                  AND NOT EXISTS (
                      SELECT 1 FROM automation_jobs aj
                      WHERE aj.dedup_key = CONCAT(
                          'reminder_',
                          admission_enquiries.id,
                          '_',
                          DATE_FORMAT(admission_enquiries.next_followup_date, '%Y-%m-%d'),
                          '_',
                          TIME_FORMAT(COALESCE(admission_enquiries.next_followup_time, '10:00:00'), '%H:%i:%s')
                      )
                  )
              )
          )
    ";

    let rows: Vec<AdmissionEnquiry> = conn.query_map(
        query,
        |(
            id,
            parent_name,
            student_name,
            interested_class,
            followup_category,
            next_followup_date,
            next_followup_time,
            followup_reason,
            priority,
        ): (
            u64,
            String,
            String,
            String,
            String,
            String,
            String,
            String,
            String,
        )| {
            AdmissionEnquiry {
                id,
                parent_name,
                parent_phone: String::new(),
                parent_email: None,
                student_name,
                interested_class,
                followup_category,
                next_followup_date,
                next_followup_time,
                followup_reason,
                assigned_staff_name: "Admissions Desk".to_string(),
                priority,
                reminder_status: "pending".to_string(),
                suggested_message: None,
                next_recommended_action: None,
                internal_notes: None,
                is_completed: false,
                is_cancelled: false,
            }
        },
    )?;

    let mut enqueued = 0;

    for enq in rows {
        let dedup_key = format!(
            "reminder_{}_{}_{}",
            enq.id, enq.next_followup_date, enq.next_followup_time
        );

        let priority_num = match enq.priority.to_lowercase().as_str() {
            "urgent" => 30,
            "high" => 20,
            "medium" => 10,
            _ => 5,
        };

        let payload = JobPayload {
            enquiry_id: Some(enq.id),
            action: Some("generate_reminder".to_string()),
            parent_name: Some(enq.parent_name.clone()),
            student_name: Some(enq.student_name.clone()),
            grade: Some(enq.interested_class.clone()),
            note: Some(enq.followup_reason.clone()),
            ..Default::default()
        };

        let payload_json = serde_json::to_string(&payload).unwrap_or_else(|_| "{}".to_string());

        conn.exec_drop(
            "INSERT IGNORE INTO automation_jobs (
                job_type, payload, dedup_key, status, priority, max_attempts, scheduled_at
            ) VALUES (
                'admission_reminder', ?, ?, 'pending', ?, 3, NOW()
            )",
            (payload_json, &dedup_key, priority_num),
        )?;

        if conn.affected_rows() > 0 {
            enqueued += 1;
        }
    }

    Ok(enqueued)
}

/// Automation #9 & #12: Enqueue un-triaged recent inbound WhatsApp messages for signal extraction
pub fn scan_and_enqueue_inbound_triage(pool: &Pool) -> Result<usize> {
    let mut conn = pool.get_conn()?;
    if !table_exists(&mut conn, "inbound_signal_cards")?
        || !table_exists(&mut conn, "whatsapp_messages")?
    {
        return Ok(0);
    }

    let rows: Vec<(u64, u64)> = conn.query_map(
        "SELECT m.id, m.chat_id
         FROM whatsapp_messages m
         WHERE m.from_me = 0
           AND m.msg_type != 'system'
           AND COALESCE(NULLIF(TRIM(m.body), ''), '') != ''
           AND m.received_at >= NOW() - INTERVAL 7 DAY
           AND NOT EXISTS (
               SELECT 1 FROM inbound_signal_cards isc WHERE isc.message_id = m.id
           )
           AND NOT EXISTS (
               SELECT 1 FROM automation_jobs aj WHERE aj.dedup_key = CONCAT('triage_msg_', m.id)
           )
         ORDER BY m.id DESC
         LIMIT 50",
        |(msg_id, chat_id): (u64, u64)| (msg_id, chat_id),
    )?;

    let mut enqueued = 0;
    for (msg_id, chat_id) in rows {
        let dedup_key = format!("triage_msg_{}", msg_id);
        let payload = JobPayload {
            chat_id: Some(chat_id),
            message_id: Some(msg_id),
            action: Some("inbound_triage".to_string()),
            ..Default::default()
        };
        let payload_json = serde_json::to_string(&payload).unwrap_or_else(|_| "{}".to_string());

        conn.exec_drop(
            "INSERT IGNORE INTO automation_jobs (
                job_type, payload, dedup_key, status, priority, max_attempts, scheduled_at
            ) VALUES (
                'inbound_triage', ?, ?, 'pending', 15, 3, NOW()
            )",
            (payload_json, &dedup_key),
        )?;

        if conn.affected_rows() > 0 {
            enqueued += 1;
        }
    }

    Ok(enqueued)
}

/// Automation #13: Schedule today's Principal Daily Briefing job if not yet generated
pub fn schedule_daily_briefing(pool: &Pool) -> Result<usize> {
    let mut conn = pool.get_conn()?;
    if !table_exists(&mut conn, "daily_briefings")? {
        return Ok(0);
    }

    let existing_count: u64 = conn
        .query_first("SELECT COUNT(*) FROM daily_briefings WHERE brief_date = CURDATE()")?
        .unwrap_or(0);
    if existing_count > 0 {
        return Ok(0);
    }

    let today_str: String = conn
        .query_first("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d')")?
        .unwrap_or_else(|| chrono::Local::now().format("%Y-%m-%d").to_string());

    let dedup_key = format!("daily_briefing_{}", today_str);
    let payload = JobPayload {
        brief_date: Some(today_str),
        action: Some("daily_briefing".to_string()),
        ..Default::default()
    };
    let payload_json = serde_json::to_string(&payload).unwrap_or_else(|_| "{}".to_string());

    conn.exec_drop(
        "INSERT IGNORE INTO automation_jobs (
            job_type, payload, dedup_key, status, priority, max_attempts, scheduled_at
        ) VALUES (
            'daily_briefing', ?, ?, 'pending', 25, 3, NOW()
        )",
        (payload_json, &dedup_key),
    )?;

    Ok(if conn.affected_rows() > 0 { 1 } else { 0 })
}
