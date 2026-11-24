use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct JobPayload {
    #[serde(default)]
    pub enquiry_id: Option<u64>,
    #[serde(default)]
    pub chat_id: Option<u64>,
    #[serde(default)]
    pub message_id: Option<u64>,
    #[serde(default)]
    pub brief_date: Option<String>,
    #[serde(default)]
    pub action: Option<String>,
    #[serde(default)]
    pub parent_name: Option<String>,
    #[serde(default)]
    pub student_name: Option<String>,
    #[serde(default)]
    pub grade: Option<String>,
    #[serde(default)]
    pub note: Option<String>,
    #[serde(default)]
    pub should_fail: Option<bool>, // for testing retry
}

#[derive(Debug, Clone)]
pub struct Job {
    pub id: u64,
    pub job_type: String,
    pub payload: String,
    pub dedup_key: String,
    pub status: String,
    pub priority: i32,
    pub attempts: u32,
    pub max_attempts: u32,
    pub scheduled_at: String,
    pub last_error: Option<String>,
}

#[derive(Debug, Clone)]
pub struct AdmissionEnquiry {
    pub id: u64,
    pub parent_name: String,
    pub parent_phone: String,
    pub parent_email: Option<String>,
    pub student_name: String,
    pub interested_class: String,
    pub followup_category: String,
    pub next_followup_date: String,
    pub next_followup_time: String,
    pub followup_reason: String,
    pub assigned_staff_name: String,
    pub priority: String,
    pub reminder_status: String,
    pub suggested_message: Option<String>,
    pub next_recommended_action: Option<String>,
    pub internal_notes: Option<String>,
    pub is_completed: bool,
    pub is_cancelled: bool,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct EngineStats {
    pub pending_jobs: u64,
    pub processing_jobs: u64,
    pub completed_jobs: u64,
    pub failed_jobs: u64,
    pub total_enquiries: u64,
    pub today_followups: u64,
    pub overdue_followups: u64,
    pub upcoming_followups: u64,
    pub high_priority_enquiries: u64,
    pub awaiting_responses: u64,
    pub interested_parents: u64,
    pub completed_admissions: u64,
    pub last_run_timestamp: String,
}
