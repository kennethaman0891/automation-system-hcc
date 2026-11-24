use std::collections::HashMap;
use std::fs;
use std::path::Path;
use serde_json::Value;
use tracing::{info, warn};

use crate::types::AdmissionIntent;

#[derive(Debug, Clone)]
pub struct KnowledgeSlice {
    pub sources: Vec<String>,
    pub context_text: String,
    pub verified_fees: Vec<u32>,
    pub verified_grades: Vec<String>,
    pub verified_documents: Vec<String>,
    pub helpline: String,
    pub whatsapp: String,
    pub campus_address: String,
    pub email: String,
}

#[derive(Debug, Clone, Default)]
pub struct KnowledgeBase {
    pub documents: HashMap<String, Value>,
    pub summary_markdown: String,
}

impl KnowledgeBase {
    pub fn load_from_dir<P: AsRef<Path>>(dir: P) -> Self {
        let path = dir.as_ref();
        info!("Loading approved knowledge base from: {:?}", path);

        let mut documents = HashMap::new();
        let mut summary_markdown = String::new();

        if !path.exists() {
            warn!("Knowledge directory {:?} does not exist. Using fallback inline knowledge.", path);
            return Self::fallback();
        }

        let json_files = [
            ("prospectus", "admission_prospectus.json"),
            ("fees", "fee_structure.json"),
            ("classes", "class_information.json"),
            ("age", "age_criteria.json"),
            ("documents", "admission_checklist.json"),
            ("timings", "school_timings.json"),
            ("transport", "transport_details.json"),
            ("contact", "contact_information.json"),
            ("policies", "admission_policies.json"),
        ];

        for (key, file_name) in json_files {
            let file_path = path.join(file_name);
            if file_path.exists() {
                if let Ok(content) = fs::read_to_string(&file_path) {
                    if let Ok(val) = serde_json::from_str::<Value>(&content) {
                        documents.insert(key.to_string(), val);
                        info!("Loaded knowledge file: {}", file_name);
                    }
                }
            } else {
                warn!("Knowledge file not found: {:?}", file_path);
            }
        }

        let summary_path = path.join("SCHOOL_KNOWLEDGE_SUMMARY.md");
        if summary_path.exists() {
            if let Ok(content) = fs::read_to_string(&summary_path) {
                summary_markdown = content;
                info!("Loaded knowledge summary markdown.");
            }
        }

        if documents.is_empty() {
            Self::fallback()
        } else {
            Self {
                documents,
                summary_markdown,
            }
        }
    }

    pub fn fallback() -> Self {
        warn!("Using builtin hardcoded fallback knowledge base");
        let mut documents = HashMap::new();
        documents.insert(
            "contact".to_string(),
            serde_json::json!({
                "institution_name": "Hindustani Covenant Church High School",
                "campus_address": "Near Old Pune Naka, Solapur, Maharashtra 413001, India",
                "admission_helpline": "+91 217 000 0000",
                "admission_whatsapp": "+91 98016 60000",
                "official_email": "admissions@hccsolapur.edu.in"
            }),
        );
        Self {
            documents,
            summary_markdown: "Hindustani Covenant Church High School, Solapur. English Medium SSC Board.".to_string(),
        }
    }

    pub fn contact_fields(&self) -> (String, String, String, String) {
        let contact = self.documents.get("contact");
        let helpline = contact
            .and_then(|c| c.get("admission_helpline"))
            .and_then(|v| v.as_str())
            .filter(|s| !s.trim().is_empty())
            .unwrap_or("the school admission office")
            .to_string();
        let whatsapp = contact
            .and_then(|c| c.get("admission_whatsapp"))
            .and_then(|v| v.as_str())
            .filter(|s| !s.trim().is_empty())
            .unwrap_or("")
            .to_string();
        let address = contact
            .and_then(|c| c.get("campus_address"))
            .and_then(|v| v.as_str())
            .filter(|s| !s.trim().is_empty())
            .unwrap_or("Solapur, Maharashtra")
            .to_string();
        let email = contact
            .and_then(|c| c.get("official_email"))
            .and_then(|v| v.as_str())
            .filter(|s| !s.trim().is_empty())
            .unwrap_or("")
            .to_string();
        (helpline, whatsapp, address, email)
    }

    fn render_contact(&self) -> String {
        if let Some(c) = self.documents.get("contact") {
            let inst = c.get("institution_name").and_then(|v| v.as_str()).unwrap_or("Hindustani Covenant Church High School");
            let addr = c.get("campus_address").and_then(|v| v.as_str()).unwrap_or("Solapur, Maharashtra");
            let helpline = c.get("admission_helpline").and_then(|v| v.as_str()).unwrap_or("");
            let whatsapp = c.get("admission_whatsapp").and_then(|v| v.as_str()).unwrap_or("");
            let email = c.get("official_email").and_then(|v| v.as_str()).unwrap_or("");
            let website = c.get("website").and_then(|v| v.as_str()).unwrap_or("");

            let mut lines = vec![
                format!("SCHOOL IDENTITY & CONTACT:"),
                format!("• Institution: {}", inst),
                format!("• Address: {}", addr),
            ];
            if !helpline.is_empty() {
                lines.push(format!("• Admission Helpline: {}", helpline));
            }
            if !whatsapp.is_empty() {
                lines.push(format!("• Admission WhatsApp: {}", whatsapp));
            }
            if !email.is_empty() {
                lines.push(format!("• Email: {}", email));
            }
            if !website.is_empty() {
                lines.push(format!("• Website: {}", website));
            }
            if let Some(visit) = c.get("campus_visit_information").and_then(|v| v.as_object()) {
                if let Some(hours) = visit.get("hours").and_then(|v| v.as_str()) {
                    lines.push(format!("• Campus Visit Hours: {}", hours));
                }
                if let Some(proc) = visit.get("procedure").and_then(|v| v.as_str()) {
                    lines.push(format!("• Visit Procedure: {}", proc));
                }
                if let Some(guide) = visit.get("visitor_guidelines").and_then(|v| v.as_str()) {
                    lines.push(format!("• Visitor Guidelines: {}", guide));
                }
            }
            lines.join("\n")
        } else {
            "SCHOOL IDENTITY:\nInstitution: Hindustani Covenant Church High School (HCC High School), Solapur".to_string()
        }
    }

    pub fn render_timings(&self) -> Option<String> {
        let timings = self.documents.get("timings")?;
        let mut lines = vec![format!(
            "OFFICIAL DAILY SCHOOL TIMINGS ({}):",
            timings.get("academic_year").and_then(|v| v.as_str()).unwrap_or("Current Session")
        )];
        if let Some(schedules) = timings.get("daily_school_schedules").and_then(|v| v.as_object()) {
            for (stage, info) in schedules {
                let grades = info.get("grades").and_then(|v| v.as_str()).unwrap_or("");
                let mf = info.get("monday_to_friday").and_then(|v| v.as_str()).unwrap_or("");
                let sat = info.get("saturday").and_then(|v| v.as_str()).unwrap_or("");
                let mut detail = format!("• {} ({}): Mon-Fri {}", stage, grades, mf);
                if !sat.is_empty() {
                    detail.push_str(&format!(" | Sat {}", sat));
                }
                lines.push(detail);
            }
        }
        if let Some(office) = timings.get("admission_office_and_visitor_timings").and_then(|v| v.as_object()) {
            if let Some(hours) = office.get("enquiry_and_form_submission_hours").and_then(|v| v.as_str()) {
                lines.push(format!("• Admission Office Hours: {}", hours));
            }
            if let Some(pm) = office.get("principal_meeting_hours").and_then(|v| v.as_str()) {
                lines.push(format!("• Principal Meeting Hours: {}", pm));
            }
            if let Some(hol) = office.get("holidays").and_then(|v| v.as_str()) {
                lines.push(format!("• Holidays: {}", hol));
            }
        }
        Some(lines.join("\n"))
    }

    fn render_prospectus(&self) -> Option<String> {
        let p = self.documents.get("prospectus")?;
        let mut lines = vec![format!(
            "ADMISSION PROSPECTUS ({}):",
            p.get("academic_year").and_then(|v| v.as_str()).unwrap_or("2026-2027")
        )];
        if let Some(curr) = p.get("curriculum").and_then(|v| v.as_str()) {
            lines.push(format!("• Curriculum: {}", curr));
        }
        if let Some(overview) = p.get("overview").and_then(|v| v.as_str()) {
            lines.push(format!("• Overview: {}", overview));
        }
        if let Some(cycle) = p.get("admission_cycle").and_then(|v| v.as_object()) {
            if let Some(st) = cycle.get("admissions_open_status").and_then(|v| v.as_str()) {
                lines.push(format!("• Admission Status: {}", st));
            }
            if let Some(fo) = cycle.get("forms_open").and_then(|v| v.as_str()) {
                lines.push(format!("• Forms Open From: {}", fo));
            }
            if let Some(mode) = cycle.get("application_mode").and_then(|v| v.as_str()) {
                lines.push(format!("• Application Mode: {}", mode));
            }
            if let Some(ts) = cycle.get("academic_term_start").and_then(|v| v.as_str()) {
                lines.push(format!("• Academic Term Start: {}", ts));
            }
        }
        if let Some(highlights) = p.get("key_highlights").and_then(|v| v.as_array()) {
            lines.push("• Key Highlights & Facilities:".to_string());
            for item in highlights {
                if let Some(txt) = item.as_str() {
                    lines.push(format!("  - {}", txt));
                }
            }
        }
        Some(lines.join("\n"))
    }

    fn render_classes(&self) -> Option<(String, Vec<String>)> {
        let cls = self.documents.get("classes")?;
        let mut grades_out = Vec::new();
        let mut lines = vec![format!(
            "CLASS INFORMATION & CURRICULUM ({}):",
            cls.get("academic_year").and_then(|v| v.as_str()).unwrap_or("2026-2027")
        )];
        if let Some(board) = cls.get("board").and_then(|v| v.as_str()) {
            lines.push(format!("• Board: {}", board));
        }
        if let Some(med) = cls.get("medium").and_then(|v| v.as_str()) {
            lines.push(format!("• Medium: {}", med));
        }
        if let Some(langs) = cls.get("second_languages").and_then(|v| v.as_array()) {
            let l: Vec<&str> = langs.iter().filter_map(|v| v.as_str()).collect();
            if !l.is_empty() {
                lines.push(format!("• Second Languages: {}", l.join(", ")));
            }
        }
        if let Some(stages) = cls.get("classes_offered").and_then(|v| v.as_array()) {
            for stage in stages {
                let s_name = stage.get("stage").and_then(|v| v.as_str()).unwrap_or("");
                let focus = stage.get("focus").and_then(|v| v.as_str()).unwrap_or("");
                let g_list: Vec<String> = stage
                    .get("grades")
                    .and_then(|v| v.as_array())
                    .map(|arr| arr.iter().filter_map(|x| x.as_str().map(|s| s.to_string())).collect())
                    .unwrap_or_default();
                grades_out.extend(g_list.clone());
                lines.push(format!("• {} ({}): {}", s_name, g_list.join(", "), focus));
            }
        }
        if let Some(ratio) = cls.get("class_size_and_ratio").and_then(|v| v.as_object()) {
            let str_ratio = ratio.get("student_teacher_ratio").and_then(|v| v.as_str()).unwrap_or("");
            let cap = ratio.get("section_capacity").and_then(|v| v.as_str()).unwrap_or("");
            let secs = ratio.get("sections_per_grade").and_then(|v| v.as_u64()).unwrap_or(0);
            lines.push(format!("• Class Size & Ratio: {} ratio, {} ({} sections per grade)", str_ratio, cap, secs));
        }
        if let Some(seats) = cls.get("current_seats_status").and_then(|v| v.as_object()) {
            if let Some(sum) = seats.get("status_summary").and_then(|v| v.as_str()) {
                lines.push(format!("• Seat Allocation Policy: {}", sum));
            }
            if let Some(pol) = seats.get("policy_on_seat_inquiries").and_then(|v| v.as_str()) {
                lines.push(format!("• Seat Verification Policy: {}", pol));
            }
        }
        lines.push("• CRITICAL SAFETY RULE: Never guarantee exact vacancy count. Ask parent to verify live seat register with the admission office.".to_string());
        Some((lines.join("\n"), grades_out))
    }

    fn render_transport(&self) -> Option<(String, Vec<u32>)> {
        let tr = self.documents.get("transport")?;
        let mut fees_out = Vec::new();
        let mut lines = vec![format!(
            "SCHOOL TRANSPORT DETAILS ({}):",
            tr.get("academic_year").and_then(|v| v.as_str()).unwrap_or("2026-2027")
        )];
        if let Some(ov) = tr.get("facility_overview").and_then(|v| v.as_str()) {
            lines.push(format!("• Overview: {}", ov));
        }
        if let Some(safety) = tr.get("safety_features").and_then(|v| v.as_array()) {
            let items: Vec<&str> = safety.iter().filter_map(|v| v.as_str()).collect();
            if !items.is_empty() {
                lines.push(format!("• Safety Features: {}", items.join("; ")));
            }
        }
        if let Some(routes) = tr.get("routes_and_coverage").and_then(|v| v.as_array()) {
            for r in routes {
                let r_num = r.get("route_number").and_then(|v| v.as_str()).unwrap_or("");
                let r_name = r.get("name").and_then(|v| v.as_str()).unwrap_or("");
                let stops: Vec<&str> = r
                    .get("stops")
                    .and_then(|v| v.as_array())
                    .map(|a| a.iter().filter_map(|x| x.as_str()).collect())
                    .unwrap_or_default();
                lines.push(format!("• {} ({}): {}", r_num, r_name, stops.join(", ")));
            }
        }
        if let Some(t_fees) = tr.get("transport_fee_structure").and_then(|v| v.as_object()) {
            let mut fee_bits = Vec::new();
            for (zone, obj) in t_fees {
                let annual = obj.get("annual_fee").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                let term = obj.get("term_installment").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                if annual > 0 { fees_out.push(annual); }
                if term > 0 { fees_out.push(term); }
                fee_bits.push(format!("{}: ₹{}/yr (₹{}/term)", zone.replace('_', " "), annual, term));
            }
            if !fee_bits.is_empty() {
                lines.push(format!("• Transport Fee: {}", fee_bits.join(" | ")));
            }
        }
        if let Some(notes) = tr.get("registration_notes").and_then(|v| v.as_str()) {
            lines.push(format!("• Notes: {}", notes));
        }
        Some((lines.join("\n"), fees_out))
    }

    fn render_policies(&self) -> (Option<String>, Vec<u32>) {
        let mut policy_fees = Vec::new();
        let Some(policies) = self.documents.get("policies") else {
            return (None, policy_fees);
        };
        let mut lines = vec![format!(
            "ADMISSION & REFUND POLICIES ({}):",
            policies.get("policy_title").and_then(|v| v.as_str()).unwrap_or("HCC High School")
        )];
        if let Some(kp) = policies.get("key_policies").and_then(|v| v.as_object()) {
            for (k, v) in kp {
                let label = k.replace('_', " ");
                if let Some(s) = v.as_str() {
                    lines.push(format!("• {}: {}", label, s));
                } else if let Some(arr) = v.as_array() {
                    lines.push(format!("• {}:", label));
                    for item in arr {
                        if let Some(line) = item.as_str() {
                            lines.push(format!("  - {}", line));
                            if line.contains("5,000") { policy_fees.push(5000); }
                            if line.contains("1,000") { policy_fees.push(1000); }
                        }
                    }
                }
            }
        }
        (Some(lines.join("\n")), policy_fees)
    }

    /// Retrieve verified facts and context for a specific intent and optional grade
    pub fn retrieve_slice(&self, intent: &AdmissionIntent, grade_hint: Option<&str>, _user_query: &str) -> KnowledgeSlice {
        let mut sources = Vec::new();
        let mut context_parts = Vec::new();
        let mut verified_fees = Vec::new();
        let mut verified_grades = Vec::new();
        let mut verified_documents = Vec::new();

        let (helpline, whatsapp, campus_address, email) = self.contact_fields();

        // Always include school identity + contact info from contact_information.json
        context_parts.push(self.render_contact());
        if let Some(cls) = self.documents.get("classes") {
            let board = cls.get("board").and_then(|v| v.as_str()).unwrap_or("Maharashtra State Board (SSC Board)");
            let med = cls.get("medium").and_then(|v| v.as_str()).unwrap_or("English Medium");
            let year = cls.get("academic_year").and_then(|v| v.as_str()).unwrap_or("2026-2027");
            context_parts.push(format!("ACADEMIC PROFILE:\n• Affiliation: {}\n• Medium: {}\n• Academic Year: {}", board, med, year));
        }

        match intent {
            AdmissionIntent::FeesAndPayment => {
                sources.push("fee_structure.json".to_string());
                sources.push("admission_policies.json".to_string());

                if let Some(fees) = self.documents.get("fees") {
                    let year = fees.get("academic_year").and_then(|v| v.as_str()).unwrap_or("2026-2027");
                    let mut fee_text = format!("APPROVED OFFICIAL FEE SCHEDULE ({}):\n", year);
                    if let Some(notes) = fees.get("general_notes").and_then(|v| v.as_array()) {
                        for n in notes {
                            if let Some(s) = n.as_str() {
                                fee_text.push_str(&format!("• {}\n", s));
                            }
                        }
                    }
                    fee_text.push('\n');

                    if let Some(grades) = fees.get("grades").and_then(|g| g.as_object()) {
                        for (grade_name, details) in grades {
                            let adm_fee = details.get("admission_fee_one_time").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                            let total = details.get("total_annual_fee_new_admission").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                            let existing_total = details.get("total_annual_fee_existing_student").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                            let tuition = details.get("annual_tuition_fee").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                            let term1 = details.get("term_1_tuition").and_then(|v| v.as_u64()).unwrap_or(0) as u32;
                            let term2 = details.get("term_2_tuition").and_then(|v| v.as_u64()).unwrap_or(0) as u32;

                            for val in [adm_fee, total, existing_total, tuition, term1, term2] {
                                if val > 0 && !verified_fees.contains(&val) {
                                    verified_fees.push(val);
                                }
                            }
                            if let Some(obj) = details.as_object() {
                                for (k, v) in obj {
                                    if k.ends_with("_fee") {
                                        if let Some(n) = v.as_u64() {
                                            let u = n as u32;
                                            if u > 0 && !verified_fees.contains(&u) {
                                                verified_fees.push(u);
                                            }
                                        }
                                    }
                                }
                            }
                            verified_grades.push(grade_name.clone());

                            let matches_hint = grade_hint.map_or(false, |h| grade_name.to_lowercase().contains(&h.to_lowercase()));
                            if matches_hint || grade_hint.is_none() {
                                fee_text.push_str(&format!(
                                    "• {}: Total New Admission: ₹{}, Existing Student: ₹{}, Annual Tuition: ₹{} (Term 1: ₹{}, Term 2: ₹{}, One-time Admission Fee: ₹{})\n",
                                    grade_name, total, existing_total, tuition, term1, term2, adm_fee
                                ));
                            }
                        }
                    }
                    context_parts.push(fee_text);
                }
            }

            AdmissionIntent::AgeRequirements => {
                sources.push("age_criteria.json".to_string());
                if let Some(age) = self.documents.get("age") {
                    let cutoff = age.get("cutoff_date").and_then(|v| v.as_str()).unwrap_or("31st December 2026");
                    let summary = age.get("rules_summary").and_then(|v| v.as_str()).unwrap_or("");
                    let underage = age.get("underage_policy").and_then(|v| v.as_str()).unwrap_or("");
                    let mut age_text = format!("OFFICIAL AGE CUT-OFF CRITERIA (Cutoff: {}):\n", cutoff);
                    if !summary.is_empty() {
                        age_text.push_str(&format!("• {}\n", summary));
                    }
                    if let Some(mapping) = age.get("grade_age_mapping").and_then(|m| m.as_array()) {
                        for item in mapping {
                            let grade = item.get("grade").and_then(|g| g.as_str()).unwrap_or("");
                            let min_age = item.get("minimum_age").and_then(|a| a.as_str()).unwrap_or("");
                            let extra = item
                                .get("eligible_birth_range")
                                .or_else(|| item.get("requirement"))
                                .and_then(|b| b.as_str())
                                .unwrap_or("");
                            age_text.push_str(&format!("• {}: {} ({})\n", grade, min_age, extra));
                            verified_grades.push(grade.to_string());
                        }
                    }
                    if !underage.is_empty() {
                        age_text.push_str(&format!("• Underage Policy: {}\n", underage));
                    }
                    context_parts.push(age_text);
                }
            }

            AdmissionIntent::RequiredDocuments => {
                sources.push("admission_checklist.json".to_string());
                if let Some(docs) = self.documents.get("documents") {
                    let mut doc_text = format!(
                        "{}:\n",
                        docs.get("document_title").and_then(|v| v.as_str()).unwrap_or("MANDATORY DOCUMENT CHECKLIST")
                    );
                    if let Some(levels) = docs.get("checklist_by_grade_level").and_then(|v| v.as_object()) {
                        for (level_key, arr) in levels {
                            doc_text.push_str(&format!("\n[{}]\n", level_key.replace('_', " ").to_uppercase()));
                            if let Some(items) = arr.as_array() {
                                for (idx, item) in items.iter().enumerate() {
                                    let doc_name = item.get("document").and_then(|v| v.as_str()).unwrap_or("");
                                    let copies = item.get("copies_required").and_then(|v| v.as_str()).unwrap_or("");
                                    let cond = item.get("condition").and_then(|v| v.as_str()).unwrap_or("");
                                    let mandatory = item.get("mandatory").and_then(|v| v.as_bool()).unwrap_or(true);
                                    if !doc_name.is_empty() {
                                        verified_documents.push(doc_name.to_string());
                                        let mut line = format!("{}. {} ({})", idx + 1, doc_name, if mandatory { "Mandatory" } else { "If applicable" });
                                        if !copies.is_empty() { line.push_str(&format!(" — {}", copies)); }
                                        if !cond.is_empty() { line.push_str(&format!(" — {}", cond)); }
                                        doc_text.push_str(&line);
                                        doc_text.push('\n');
                                    }
                                }
                            }
                        }
                    }
                    context_parts.push(doc_text);
                }
            }

            AdmissionIntent::ClassAvailability => {
                sources.push("class_information.json".to_string());
                if let Some((text, grades)) = self.render_classes() {
                    verified_grades.extend(grades);
                    context_parts.push(text);
                }
            }

            AdmissionIntent::SchoolTimings => {
                sources.push("school_timings.json".to_string());
                if let Some(text) = self.render_timings() {
                    context_parts.push(text);
                }
            }

            AdmissionIntent::Transport => {
                sources.push("transport_details.json".to_string());
                if let Some((text, t_fees)) = self.render_transport() {
                    verified_fees.extend(t_fees);
                    context_parts.push(text);
                }
            }

            AdmissionIntent::CampusVisit => {
                sources.push("contact_information.json".to_string());
                sources.push("school_timings.json".to_string());
                if let Some(t) = self.render_timings() {
                    context_parts.push(t);
                }
                if let Some(p) = self.render_prospectus() {
                    sources.push("admission_prospectus.json".to_string());
                    context_parts.push(p);
                }
            }

            AdmissionIntent::Facilities => {
                sources.push("admission_prospectus.json".to_string());
                if let Some(p) = self.render_prospectus() {
                    context_parts.push(p);
                }
            }

            AdmissionIntent::ApplicationProcess => {
                sources.push("admission_prospectus.json".to_string());
                sources.push("admission_policies.json".to_string());
                if let Some(p) = self.render_prospectus() {
                    context_parts.push(p);
                }
            }

            AdmissionIntent::AdmissionEligibility => {
                sources.push("age_criteria.json".to_string());
                sources.push("admission_policies.json".to_string());
                if let Some(age) = self.documents.get("age") {
                    if let Some(mapping) = age.get("grade_age_mapping").and_then(|m| m.as_array()) {
                        let mut age_lines = vec!["ELIGIBILITY & AGE CRITERIA:".to_string()];
                        for item in mapping {
                            let grade = item.get("grade").and_then(|g| g.as_str()).unwrap_or("");
                            let min_age = item.get("minimum_age").and_then(|a| a.as_str()).unwrap_or("");
                            age_lines.push(format!("• {}: {}", grade, min_age));
                            verified_grades.push(grade.to_string());
                        }
                        context_parts.push(age_lines.join("\n"));
                    }
                }
            }

            AdmissionIntent::AdmissionFollowUp => {
                sources.push("contact_information.json".to_string());
                sources.push("school_timings.json".to_string());
                if let Some(t) = self.render_timings() {
                    context_parts.push(t);
                }
            }

            AdmissionIntent::AdmissionEnquiry | AdmissionIntent::GeneralEnquiry => {
                sources.push("admission_prospectus.json".to_string());
                sources.push("class_information.json".to_string());
                sources.push("school_timings.json".to_string());
                if let Some(p) = self.render_prospectus() {
                    context_parts.push(p);
                }
                if let Some((c_text, grades)) = self.render_classes() {
                    verified_grades.extend(grades);
                    context_parts.push(c_text);
                }
                if let Some(t) = self.render_timings() {
                    context_parts.push(t);
                }
            }
        }

        // Always render policies cleanly (with formatted bullet list for fee_refund_policy)
        let (policy_text, policy_fees) = self.render_policies();
        for f in policy_fees {
            if !verified_fees.contains(&f) {
                verified_fees.push(f);
            }
        }
        if let Some(pt) = policy_text {
            context_parts.push(pt);
            if !sources.iter().any(|s| s.contains("policies")) {
                sources.push("admission_policies.json".to_string());
            }
        }

        KnowledgeSlice {
            sources,
            context_text: context_parts.join("\n\n"),
            verified_fees,
            verified_grades,
            verified_documents,
            helpline,
            whatsapp,
            campus_address,
            email,
        }
    }
}
