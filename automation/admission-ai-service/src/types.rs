use serde::{Deserialize, Serialize};

/// Supported intents for HCC Admission Enquiry Assistant
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub enum AdmissionIntent {
    AdmissionEnquiry,
    AdmissionEligibility,
    AgeRequirements,
    FeesAndPayment,
    RequiredDocuments,
    ClassAvailability,
    SchoolTimings,
    Transport,
    Facilities,
    CampusVisit,
    ApplicationProcess,
    AdmissionFollowUp,
    GeneralEnquiry,
}

impl AdmissionIntent {
    pub fn as_str(&self) -> &'static str {
        match self {
            AdmissionIntent::AdmissionEnquiry => "Admission enquiry",
            AdmissionIntent::AdmissionEligibility => "Admission eligibility",
            AdmissionIntent::AgeRequirements => "Age requirements",
            AdmissionIntent::FeesAndPayment => "Fees and payment",
            AdmissionIntent::RequiredDocuments => "Required documents",
            AdmissionIntent::ClassAvailability => "Class availability",
            AdmissionIntent::SchoolTimings => "School timings",
            AdmissionIntent::Transport => "Transport",
            AdmissionIntent::Facilities => "Facilities",
            AdmissionIntent::CampusVisit => "Campus visit",
            AdmissionIntent::ApplicationProcess => "Application process",
            AdmissionIntent::AdmissionFollowUp => "Admission follow-up",
            AdmissionIntent::GeneralEnquiry => "General enquiry",
        }
    }

    pub fn from_str_loose(s: &str) -> Self {
        let norm = s.to_lowercase();
        if norm.contains("fee") || norm.contains("payment") || norm.contains("cost") || norm.contains("installment") {
            AdmissionIntent::FeesAndPayment
        } else if norm.contains("age") || norm.contains("dob") || norm.contains("birth") || norm.contains("cutoff") {
            AdmissionIntent::AgeRequirements
        } else if norm.contains("doc") || norm.contains("certificate") || norm.contains("marksheet") || norm.contains("lc") || norm.contains("tc") || norm.contains("aadhaar") {
            AdmissionIntent::RequiredDocuments
        } else if norm.contains("seat") || norm.contains("vacan") || norm.contains("availab") || norm.contains("capacity") {
            AdmissionIntent::ClassAvailability
        } else if norm.contains("time") || norm.contains("timing") || norm.contains("hour") || norm.contains("schedule") {
            AdmissionIntent::SchoolTimings
        } else if norm.contains("bus") || norm.contains("transport") || norm.contains("van") || norm.contains("route") || norm.contains("pickup") {
            AdmissionIntent::Transport
        } else if norm.contains("facility") || norm.contains("lab") || norm.contains("ground") || norm.contains("smart class") || norm.contains("library") {
            AdmissionIntent::Facilities
        } else if norm.contains("visit") || norm.contains("campus") || norm.contains("tour") || norm.contains("appointment") {
            AdmissionIntent::CampusVisit
        } else if norm.contains("process") || norm.contains("how to apply") || norm.contains("form") || norm.contains("step") || norm.contains("procedure") {
            AdmissionIntent::ApplicationProcess
        } else if norm.contains("follow") || norm.contains("status") || norm.contains("update") || norm.contains("submitted") {
            AdmissionIntent::AdmissionFollowUp
        } else if norm.contains("eligib") || norm.contains("criteri") || norm.contains("qualif") {
            AdmissionIntent::AdmissionEligibility
        } else if norm.contains("admiss") || norm.contains("join") || norm.contains("seat") || norm.contains("standard") || norm.contains("grade") {
            AdmissionIntent::AdmissionEnquiry
        } else {
            AdmissionIntent::GeneralEnquiry
        }
    }

    pub fn all() -> Vec<&'static str> {
        vec![
            "Admission enquiry",
            "Admission eligibility",
            "Age requirements",
            "Fees and payment",
            "Required documents",
            "Class availability",
            "School timings",
            "Transport",
            "Facilities",
            "Campus visit",
            "Application process",
            "Admission follow-up",
            "General enquiry",
        ]
    }
}

/// Extracted parent details
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ParentDetails {
    pub name: Option<String>,
    pub phone: Option<String>,
}

/// Extracted student details
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct StudentDetails {
    pub name: Option<String>,
    pub grade_applying_for: Option<String>,
    pub academic_year: Option<String>,
    pub current_school: Option<String>,
}

/// Chat message context passed from PHP / MySQL
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatMessageContext {
    pub from_me: bool,
    pub sender_name: Option<String>,
    pub body: String,
    pub timestamp: u64,
}

/// Inbound request to process an enquiry
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EnquiryRequest {
    pub chat_id: u64,
    pub message_id: Option<u64>,
    pub parent_phone: Option<String>,
    pub parent_name: Option<String>,
    pub current_message: String,
    pub conversation_context: Vec<ChatMessageContext>,
    pub model: Option<String>,
}

/// Required output format from specification
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AdmissionDraftResponse {
    pub intent: String,
    pub confidence: f32,
    pub extracted_parent_details: ParentDetails,
    pub extracted_student_details: StudentDetails,
    pub relevant_knowledge_sources: Vec<String>,
    pub response_draft: String,
    pub missing_information: Vec<String>,
    pub escalation_required: bool,
    pub escalation_reason: Option<String>,
}

/// Raw LLM generation intermediate structure
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct RawLlmResponse {
    pub intent: Option<String>,
    pub confidence: Option<f32>,
    pub extracted_parent_details: Option<ParentDetails>,
    pub extracted_student_details: Option<StudentDetails>,
    pub relevant_knowledge_sources: Option<Vec<String>>,
    pub response_draft: Option<String>,
    pub missing_information: Option<Vec<String>>,
    pub escalation_required: Option<bool>,
    pub escalation_reason: Option<String>,
}

/// Benchmark metric structure
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BenchmarkResult {
    pub model_name: String,
    pub total_tests: usize,
    pub passed_tests: usize,
    pub avg_latency_ms: u64,
    pub tokens_per_sec: f32,
    pub json_compliance_pct: f32,
    pub factual_accuracy_pct: f32,
    pub memory_footprint_mb: u64,
    pub recommended_for_8gb_win: bool,
    pub notes: String,
}

/// Request payload for Automation #9 inbound message triage
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TriageInboundRequest {
    pub chat_id: Option<u64>,
    pub message_id: Option<u64>,
    pub sender_name: Option<String>,
    pub student_name: Option<String>,
    pub student_class: Option<String>,
    pub msg_type: Option<String>,
    pub message_text: String,
    pub model: Option<String>,
}

/// Response payload for Automation #9 inbound message triage
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TriageInboundResponse {
    pub signal_type: String,
    pub confidence: f32,
    pub extracted_json: serde_json::Value,
    pub suggested_reply: String,
    pub summary: String,
    pub used_ollama: bool,
}

/// Individual subject mark entry for Result Co-Pilot
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SubjectMark {
    pub subject_name: String,
    pub obtained: f64,
    pub max: f64,
    pub grade: Option<String>,
    pub is_absent: Option<bool>,
}

/// Request payload for Result Co-Pilot parent reply drafting
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ResultCopilotRequest {
    pub chat_id: Option<u64>,
    pub student_name: String,
    pub roll_no: Option<String>,
    pub class_label: Option<String>,
    pub academic_year: Option<String>,
    pub exam_label: Option<String>,
    pub parent_name: Option<String>,
    pub parent_message: Option<String>,
    #[serde(default)]
    pub subjects: Vec<SubjectMark>,
    pub overall_obtained: Option<f64>,
    pub overall_max: Option<f64>,
    pub percentage: Option<f64>,
    pub overall_grade: Option<String>,
    pub attendance: Option<String>,
    pub remark: Option<String>,
    pub school_timings: Option<serde_json::Value>,
    pub model: Option<String>,
}

/// Response payload for Result Co-Pilot
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ResultCopilotResponse {
    pub draft_reply: String,
    pub weak_subjects: Vec<String>,
    pub strong_subjects: Vec<String>,
    pub used_ollama: bool,
    pub escalation_required: bool,
    pub notes: Vec<String>,
}
