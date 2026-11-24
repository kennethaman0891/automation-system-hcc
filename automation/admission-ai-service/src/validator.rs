use std::sync::OnceLock;
use regex::Regex;
use tracing::{info, warn};
use crate::knowledge::KnowledgeSlice;
use crate::types::AdmissionDraftResponse;

pub struct SafetyValidator;

fn sql_injection_regexes() -> &'static [Regex; 3] {
    static RES: OnceLock<[Regex; 3]> = OnceLock::new();
    RES.get_or_init(|| [
        Regex::new(r"(?i)\b(SELECT|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE)\b").unwrap(),
        Regex::new(r"(?i)\b(UNION\s+SELECT|exec\s*\(|cmd\.exe|/bin/sh)\b").unwrap(),
        Regex::new(r"--\s*$").unwrap(),
    ])
}

fn fee_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"(?:₹|Rs\.?|INR)\s*([\d,]+(?:\.\d{1,2})?)").unwrap())
}

fn seat_claim_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(
            r"(?i)\b(?:(?:only|still|currently|there\s+are|we\s+(?:still\s+)?have|got)\s+)?(\d{1,3})\s+(?:vacant\s+|available\s+|open\s+|remaining\s+)?(?:seats?|vacanc(?:y|ies))(?:\s+(?:left|available|remaining|open|vacant))?\b",
        )
        .unwrap()
    })
}

impl SafetyValidator {
    /// Validates and hardens the response draft against approved school facts
    pub fn validate_and_harden(
        draft: &mut AdmissionDraftResponse,
        slice: &KnowledgeSlice,
        _user_message: &str,
    ) {
        let mut violations = Vec::new();
        let helpline_ref = if slice.helpline.trim().is_empty() {
            "the school admission office"
        } else {
            slice.helpline.trim()
        };

        // 1. Check for Arbitrary SQL / Code Injection
        for re in sql_injection_regexes() {
            if re.is_match(&draft.response_draft) {
                warn!("CRITICAL SAFETY: Model generated SQL or command injection! Scrubbing response.");
                draft.response_draft = format!(
                    "Respected Parent, thank you for contacting HCC High School. For accurate admission assistance, our office desk is at your service. Please reach us at {} or visit our admission office during working hours.",
                    helpline_ref
                );
                draft.escalation_required = true;
                draft.escalation_reason = Some(
                    "Security filter: Model output contained prohibited database or command keywords.".to_string(),
                );
                return;
            }
        }

        // 2. Never invent fees check (flag AND redact hallucinated fee figures)
        let mut unverified_amounts: Vec<(String, u32)> = Vec::new();
        for cap in fee_regex().captures_iter(&draft.response_draft) {
            if let (Some(full_match), Some(num_match)) = (cap.get(0), cap.get(1)) {
                let raw_num = num_match.as_str().replace(',', "");
                let clean_num: u32 = raw_num
                    .split('.')
                    .next()
                    .unwrap_or("0")
                    .parse()
                    .unwrap_or(0);
                if clean_num > 500 {
                    let is_verified = slice.verified_fees.iter().any(|&vf| vf == clean_num);
                    if !is_verified {
                        unverified_amounts.push((full_match.as_str().to_string(), clean_num));
                    }
                }
            }
        }
        if !unverified_amounts.is_empty() {
            let mut scrubbed = draft.response_draft.clone();
            for (literal, amt) in unverified_amounts {
                warn!("SAFETY VIOLATION: Unverified fee amount detected and redacted: ₹{}", amt);
                scrubbed = scrubbed.replace(&literal, "[fee to be confirmed by school office]");
                violations.push(format!("Unverified fee figure ₹{} detected and redacted in generated draft", amt));
            }
            draft.response_draft = scrubbed;
        }

        // 3. Never invent available seats check
        if seat_claim_regex().is_match(&draft.response_draft) {
            let mut claimed = Vec::new();
            for caps in seat_claim_regex().captures_iter(&draft.response_draft) {
                if let Some(m) = caps.get(1) {
                    claimed.push(m.as_str().to_string());
                }
            }
            let summary = if claimed.is_empty() {
                "few".to_string()
            } else {
                claimed.join(", ")
            };
            warn!("SAFETY VIOLATION: Hallucinated seat count detected: {} seats", summary);
            violations.push(format!(
                "Draft asserted exact seat vacancy ({} seats) without office register verification",
                summary
            ));
            draft.response_draft = seat_claim_regex()
                .replace_all(
                    &draft.response_draft,
                    "Seats are allocated on a first-come, first-served basis subject to office register verification",
                )
                .to_string();
        }

        // 4. Missing Information analysis
        if draft.extracted_student_details.grade_applying_for.is_none()
            && (draft.intent.contains("Fee") || draft.intent.contains("Age") || draft.intent.contains("Document"))
        {
            if !draft
                .missing_information
                .iter()
                .any(|m| m.contains("grade") || m.contains("class"))
            {
                draft.missing_information.push(
                    "Student's target grade/class is not specified. (Please confirm grade for exact fee/age criteria)"
                        .to_string(),
                );
            }
        }

        if draft.extracted_student_details.name.is_none() {
            if !draft.missing_information.iter().any(|m| m.contains("name")) {
                draft
                    .missing_information
                    .push("Student's full name not provided".to_string());
            }
        }

        // 5. Handle Violations & Escalation
        if !violations.is_empty() {
            draft.escalation_required = true;
            let reason = violations.join("; ");
            draft.escalation_reason = Some(reason);
            info!("Draft flagged for escalation: {:?}", draft.escalation_reason);
        }

        // 6. Ensure polite school greeting and closing if absent
        if !draft.response_draft.contains("HCC")
            && !draft.response_draft.contains("Hindustani Covenant Church")
        {
            let wa_part = if slice.whatsapp.trim().is_empty() {
                String::new()
            } else {
                format!(" | WhatsApp: {}", slice.whatsapp.trim())
            };
            draft.response_draft = format!(
                "Respected Parent,\n\n{}\n\nWarm regards,\nAdmission Office\nHindustani Covenant Church High School, Solapur\nHelpline: {}{}",
                draft.response_draft.trim(),
                helpline_ref,
                wa_part
            );
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::types::{ParentDetails, StudentDetails};

    fn sample_slice() -> KnowledgeSlice {
        KnowledgeSlice {
            sources: vec!["fee_structure.json".to_string()],
            context_text: String::new(),
            verified_fees: vec![5000, 28000, 37000],
            verified_grades: vec!["Grade 5".to_string()],
            verified_documents: vec![],
            helpline: "+91 217 000 0000".to_string(),
            whatsapp: "+91 98016 60000".to_string(),
            campus_address: "Solapur".to_string(),
            email: "admissions@hccsolapur.edu.in".to_string(),
        }
    }

    #[test]
    fn redacts_hallucinated_fee_and_flags_escalation() {
        let mut draft = AdmissionDraftResponse {
            intent: "Fees and payment".to_string(),
            confidence: 0.95,
            extracted_parent_details: ParentDetails::default(),
            extracted_student_details: StudentDetails::default(),
            relevant_knowledge_sources: vec![],
            response_draft: "Hindustani Covenant Church High School annual fee is ₹99,999 and admission fee ₹5,000.".to_string(),
            missing_information: vec![],
            escalation_required: false,
            escalation_reason: None,
        };
        SafetyValidator::validate_and_harden(&mut draft, &sample_slice(), "fees?");
        assert!(draft.escalation_required);
        assert!(!draft.response_draft.contains("99,999"));
        assert!(draft.response_draft.contains("₹5,000"));
        assert!(draft.response_draft.contains("[fee to be confirmed by school office]"));
    }

    #[test]
    fn redacts_seat_vacancy_claims_and_keeps_rte_percentage() {
        let mut draft = AdmissionDraftResponse {
            intent: "Class availability".to_string(),
            confidence: 0.9,
            extracted_parent_details: ParentDetails::default(),
            extracted_student_details: StudentDetails::default(),
            relevant_knowledge_sources: vec![],
            response_draft: "HCC High School has 12 seats available in Grade 5, with 25% seats reserved under RTE.".to_string(),
            missing_information: vec![],
            escalation_required: false,
            escalation_reason: None,
        };
        SafetyValidator::validate_and_harden(&mut draft, &sample_slice(), "seats?");
        assert!(draft.escalation_required);
        assert!(!draft.response_draft.contains("12 seats available"));
        assert!(draft.response_draft.contains("25% seats reserved"));
    }
}
