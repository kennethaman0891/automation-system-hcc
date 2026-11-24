use std::sync::OnceLock;
use regex::Regex;
use crate::types::{AdmissionIntent, ChatMessageContext, ParentDetails, StudentDetails};

pub struct IntentClassifier;

struct IntentPatterns {
    fees: Regex,
    age: Regex,
    docs: Regex,
    seats: Regex,
    timings: Regex,
    transport: Regex,
    visit: Regex,
    facilities: Regex,
    process: Regex,
    followup: Regex,
    eligibility: Regex,
    admission: Regex,
}

fn intent_patterns() -> &'static IntentPatterns {
    static PATTERNS: OnceLock<IntentPatterns> = OnceLock::new();
    PATTERNS.get_or_init(|| IntentPatterns {
        fees: Regex::new(r"(?i)\b(?:fees?|cost|costs|payment|payments|installments?|tuition|charges?)\b").unwrap(),
        age: Regex::new(r"(?i)\b(?:age|ages|born|birth|dob|date\s+of\s+birth|years\s+old|cut[\s-]?off)\b").unwrap(),
        docs: Regex::new(r"(?i)\b(?:documents?|certificates?|marksheets?|leaving\s+certificate|transfer\s+certificate|lc|tc|aadhaar|aadhar)\b").unwrap(),
        seats: Regex::new(r"(?i)\b(?:seats?|vacant|vacancy|vacancies|availability|available\s+seats|any\s+seats\s+left)\b").unwrap(),
        timings: Regex::new(r"(?i)\b(?:timings?|timing|what\s+time|school\s+hours|working\s+hours|office\s+hours|school\s+time)\b").unwrap(),
        transport: Regex::new(r"(?i)\b(?:bus|buses|transport|transportation|vans?|pick[\s-]?up|routes?|bus\s+drop)\b").unwrap(),
        visit: Regex::new(r"(?i)\b(?:visit|visiting|campus\s+tour|see\s+the\s+school|come\s+to\s+school|appointment\s+with\s+principal)\b").unwrap(),
        facilities: Regex::new(r"(?i)\b(?:facilit(?:y|ies)|laborator(?:y|ies)|science\s+lab|computer\s+lab|playground|smart\s+class(?:rooms?)?|sports\s+ground|library)\b").unwrap(),
        process: Regex::new(r"(?i)\b(?:how\s+to\s+apply|admission\s+process|form\s+submission|application\s+steps?|registration\s+procedure|apply\s+for\s+admission)\b").unwrap(),
        followup: Regex::new(r"(?i)\b(?:status\s+of|already\s+submitted|form\s+number|follow[\s-]?up|applied\s+on)\b").unwrap(),
        eligibility: Regex::new(r"(?i)\b(?:eligib(?:le|ility)|criteria|qualify|can\s+my\s+child\s+join)\b").unwrap(),
        admission: Regex::new(r"(?i)\b(?:admissions?|admit|new\s+admission|join\s+school|enrol+|enrollment|enrolment)\b").unwrap(),
    })
}

fn parent_name_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(r"(?i)\b(?:i\s+am|my\s+name\s+is|myself|this\s+is)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)").unwrap()
    })
}

fn phone_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"(?:\+91|91|0)?[6-9]\d{9}\b").unwrap())
}

fn student_name_regexes() -> &'static [Regex; 2] {
    static RES: OnceLock<[Regex; 2]> = OnceLock::new();
    RES.get_or_init(|| [
        Regex::new(r"(?i)\b(?:son|daughter|child|ward|kid|student)(?:'s)?\s+(?:name\s+is\s+|named\s+)([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)").unwrap(),
        Regex::new(r"(?i)\b(?:for\s+my\s+son|for\s+my\s+daughter|for\s+my\s+child)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+(?:in|for|to)\b").unwrap(),
    ])
}

fn grade_regexes() -> &'static [Regex; 4] {
    static RES: OnceLock<[Regex; 4]> = OnceLock::new();
    RES.get_or_init(|| [
        Regex::new(r"(?i)\b(?:grade|class|standard|std)\s*(10|[1-9]|viii|vii|iii|ii|iv|vi|ix|i|v|x)\b").unwrap(),
        Regex::new(r"(?i)\b(10|[1-9])(?:st|nd|rd|th)?\s*(?:grade|class|standard|std)\b").unwrap(),
        Regex::new(r"(?i)\b(nursery|lkg|ukg|jr\.?\s*kg|sr\.?\s*kg|junior\s+kg|senior\s+kg|pre[\s-]primary)\b").unwrap(),
        Regex::new(r"(?i)\b(1st|2nd|3rd|4th|5th|6th|7th|8th|9th|10th)\b").unwrap(),
    ])
}

fn normalize_grade_token(raw: &str) -> String {
    match raw.trim().to_lowercase().as_str() {
        "nursery" => "Nursery".to_string(),
        "lkg" | "jr kg" | "jr. kg" | "junior kg" => "LKG".to_string(),
        "ukg" | "sr kg" | "sr. kg" | "senior kg" => "UKG".to_string(),
        "pre-primary" | "pre primary" => "Pre-Primary".to_string(),
        "1" | "1st" | "i" => "Grade 1".to_string(),
        "2" | "2nd" | "ii" => "Grade 2".to_string(),
        "3" | "3rd" | "iii" => "Grade 3".to_string(),
        "4" | "4th" | "iv" => "Grade 4".to_string(),
        "5" | "5th" | "v" => "Grade 5".to_string(),
        "6" | "6th" | "vi" => "Grade 6".to_string(),
        "7" | "7th" | "vii" => "Grade 7".to_string(),
        "8" | "8th" | "viii" => "Grade 8".to_string(),
        "9" | "9th" | "ix" => "Grade 9".to_string(),
        "10" | "10th" | "x" => "Grade 10".to_string(),
        other => format!("Grade {}", other),
    }
}

fn extract_grade_from_text(lower: &str) -> Option<String> {
    for re in grade_regexes() {
        if let Some(caps) = re.captures(lower) {
            if let Some(m) = caps.get(1) {
                return Some(normalize_grade_token(m.as_str()));
            }
        }
    }
    None
}

impl IntentClassifier {
    /// Fast rule-based intent classification and heuristic entity extraction
    pub fn classify_and_extract(
        message: &str,
        context_history: &[ChatMessageContext],
        fallback_parent_name: Option<&str>,
        fallback_parent_phone: Option<&str>,
    ) -> (AdmissionIntent, f32, ParentDetails, StudentDetails) {
        let text = message.trim();
        let lower = text.to_lowercase();

        // 1. Identify Intent
        let (intent, confidence) = Self::detect_intent(&lower);

        // 2. Extract Parent Details
        let mut parent_details = ParentDetails {
            name: fallback_parent_name.map(|s| s.to_string()),
            phone: fallback_parent_phone.map(|s| s.to_string()),
        };

        if parent_details.name.is_none() {
            if let Some(caps) = parent_name_regex().captures(text) {
                parent_details.name = caps.get(1).map(|m| m.as_str().to_string());
            }
        }

        if parent_details.phone.is_none() {
            if let Some(caps) = phone_regex().captures(text) {
                parent_details.phone = caps.get(0).map(|m| m.as_str().to_string());
            }
        }

        // 3. Extract Student Details
        let mut student_details = StudentDetails {
            name: None,
            grade_applying_for: None,
            academic_year: Some("2026-2027".to_string()),
            current_school: None,
        };

        let stopwords = [
            "is", "was", "has", "born", "applying", "wants", "seeking", "eligible",
            "ready", "currently", "for", "in", "to", "at", "grade", "class",
            "admission", "school",
        ];
        for re in student_name_regexes() {
            if let Some(caps) = re.captures(text) {
                if let Some(name) = caps.get(1) {
                    let candidate = name.as_str().trim();
                    let cand_lower = candidate.to_lowercase();
                    let contains_stopword = stopwords
                        .iter()
                        .any(|&sw| cand_lower.split_whitespace().any(|w| w == sw));
                    if !contains_stopword && candidate.len() > 2 {
                        student_details.name = Some(candidate.to_string());
                        break;
                    }
                }
            }
        }

        // Extract grade / class applying for from current message
        student_details.grade_applying_for = extract_grade_from_text(&lower);

        // If not found in current message, look back at recent conversation context (including Roman numerals i..x)
        if student_details.grade_applying_for.is_none() {
            for prev in context_history.iter().rev().take(6) {
                if let Some(grade) = extract_grade_from_text(&prev.body.to_lowercase()) {
                    student_details.grade_applying_for = Some(grade);
                    break;
                }
            }
        }

        (intent, confidence, parent_details, student_details)
    }

    pub fn detect_intent(lower: &str) -> (AdmissionIntent, f32) {
        let p = intent_patterns();
        if p.fees.is_match(lower) {
            (AdmissionIntent::FeesAndPayment, 0.96)
        } else if p.age.is_match(lower) {
            (AdmissionIntent::AgeRequirements, 0.94)
        } else if p.docs.is_match(lower) {
            (AdmissionIntent::RequiredDocuments, 0.95)
        } else if p.seats.is_match(lower) {
            (AdmissionIntent::ClassAvailability, 0.93)
        } else if p.timings.is_match(lower) {
            (AdmissionIntent::SchoolTimings, 0.95)
        } else if p.transport.is_match(lower) {
            (AdmissionIntent::Transport, 0.96)
        } else if p.visit.is_match(lower) {
            (AdmissionIntent::CampusVisit, 0.92)
        } else if p.facilities.is_match(lower) {
            (AdmissionIntent::Facilities, 0.91)
        } else if p.process.is_match(lower) {
            (AdmissionIntent::ApplicationProcess, 0.94)
        } else if p.followup.is_match(lower) {
            (AdmissionIntent::AdmissionFollowUp, 0.93)
        } else if p.eligibility.is_match(lower) {
            (AdmissionIntent::AdmissionEligibility, 0.90)
        } else if p.admission.is_match(lower) {
            (AdmissionIntent::AdmissionEnquiry, 0.88)
        } else {
            (AdmissionIntent::GeneralEnquiry, 0.75)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn avoids_substring_false_positives() {
        for word in ["coffee", "message", "welcome", "advance"] {
            let (intent, _) = IntentClassifier::detect_intent(word);
            assert_eq!(
                intent,
                AdmissionIntent::GeneralEnquiry,
                "Expected GeneralEnquiry for '{}', got {:?}",
                word,
                intent
            );
        }
    }

    #[test]
    fn extracts_roman_numeral_grade_from_context() {
        let history = vec![ChatMessageContext {
            from_me: false,
            sender_name: Some("Parent".to_string()),
            body: "Looking for admission in STD IV".to_string(),
            timestamp: 1700000000,
        }];
        let (intent, _, _, student) = IntentClassifier::classify_and_extract(
            "What are the annual fees?",
            &history,
            None,
            None,
        );
        assert_eq!(intent, AdmissionIntent::FeesAndPayment);
        assert_eq!(student.grade_applying_for.as_deref(), Some("Grade 4"));
    }

    #[test]
    fn extracts_all_roman_numerals_i_to_x() {
        let cases = [
            ("std i", "Grade 1"),
            ("std ii", "Grade 2"),
            ("class iii", "Grade 3"),
            ("grade iv", "Grade 4"),
            ("standard v", "Grade 5"),
            ("std vi", "Grade 6"),
            ("std vii", "Grade 7"),
            ("std viii", "Grade 8"),
            ("class ix", "Grade 9"),
            ("grade x", "Grade 10"),
        ];
        for (msg, expected) in cases {
            let (_, _, _, student) = IntentClassifier::classify_and_extract(msg, &[], None, None);
            assert_eq!(student.grade_applying_for.as_deref(), Some(expected), "failed for {}", msg);
        }
    }
}
