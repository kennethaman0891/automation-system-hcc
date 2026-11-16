# HCC Campus Automation System — Executive Presentation Speaker Script

**Deck File**: [docs/presentation/index.html](index.html)  
**Target Duration**: 18–22 minutes (plus Q&A)  
**Presenter Controls**: `←` / `→` or `Space` to advance · `F` Fullscreen · `S` Toggle on-screen speaker notes · `G` Slide grid · `P` or `Cmd+P` Export 16:9 PDF / PowerPoint handout.

---

## Slide-by-Slide Presenter Guide

### Slide 1 — Title & Executive Vision (1 min)
- **Key Message**: "We built and hardened a single, institutional-grade digital campus platform specifically for Hindustani Covenant Church High School & Junior College, Solapur — keeping every student record, mark sheet, and parent conversation safely inside the school's own infrastructure."
- **Highlight Numbers**: 887 active students, 10,231+ Semester & Unit Test subject marks, 39 MySQL tables, and 14 HCC-native daily operational automations.

### Slide 2 — Solving Real Daily School Office & Teacher Friction (1.5 min)
- **Key Message**: "Off-the-shelf school ERPs fail in practice because teachers still work in messy Excel sheets, parents communicate on WhatsApp instead of portals, and morning emergencies like absent teachers or exam seating still run on paper."
- **Transition**: "Let's look at the engineering architecture that connects all of these seamlessly."

### Slide 3 — 4-Tier Local-First Polyglot Architecture (2 min)
- **Walk Left-to-Right**:
  1. **Browser & Public Portals**: Bootstrap 5.3 admin workspace + QR-verified public parent links (100% zero-CDN local assets).
  2. **Apache + PHP 8.3 (`:80`)**: Enforces RBAC, CSRF, CSP nonces, grading logic, Excel parsing, and Google Drive workspace sync.
  3. **MariaDB (`:3306`)**: 39 relational tables inside `hcc_admin`.
  4. **Local Daemons & AI**: Node.js Puppeteer bridge for WhatsApp Web, Rust `admission-ai-service` (`:8085`) calling local Ollama (`llama3.2:1b` on `:11434`), and Rust `hcc-automation-engine` worker.

### Slide 4 — Dual-Board Academic & CCE Grading Engine (1.5 min)
- **Key Message**: "Unlike generic systems that force one grading scale, HCC handles both **Std 1–8 CCE (FA 70 + SA 30, 9-point A1–E2, 33% passing)** and **Std 9–10 Maharashtra SSC (Distinction/Grade I/II, 35% passing)** automatically — now showing an accurate **819 / 887 (92.3%)** cohort pass rate."

### Slide 5 — Unit Tests I–IV & Zero-Cloud PDF Print Pipeline (1 min)
- **Key Message**: "All four Unit Tests track fractional `.5` marks and absentees, generate 4-per-A4 printable parent slips, and bundle self-contained offline HTML/PDF class dossiers via `jsPDF` and `ZipArchive`."

### Slide 6 — Automation #1: Messy Teacher-Excel Auto-Healer & Metadata Shield (1.5 min)
- **Key Message**: "Teachers don't have to fear re-uploading Excel sheets anymore. Our Auto-Healer skips `BOYS/GIRLS` divider rows safely, detects swapped roll numbers using fuzzy name matching, evaluates Excel remark formulas, and **never wipes existing DOBs or parent phone numbers**."

### Slide 7 — Automations #6 & #7: Timetable, 7:15 AM Substitute Allocator & Exam Seating (2 min)
- **Live Demo Cue**: Mention `admin/substitute_allocator.php` and `admin/exam_seating.php`.
- **Key Message**: "When 3 teachers call in sick at 7:15 AM, supervisors tick their names and get a ranked list of free teachers (balanced by 7-day proxy count and subject match), a printable noticeboard grid, and pre-written WhatsApp alerts in under 60 seconds. During exams, the seating planner alternates different standards on every bench automatically."

### Slide 8 — Automations #4, #5 & #8: GR-to-Bonafide, QR Portals & Academic Vault (1.5 min)
- **Key Message**: "Typing a student's GR number auto-fills the entire Bonafide Certificate (including DOB-in-words and atomic serial numbers). Every printed certificate carries an HMAC-SHA256 QR code that banks and scholarship desks can scan to verify authenticity. And once a year is locked in `records_archive.php`, server-side guards prevent accidental edits."

### Slide 9 — Automations #2 & #3: At-Risk Radar & Parent-Sign Chase Queue (1.5 min)
- **Key Message**: "Instead of discovering failures at year-end, the **At-Risk Radar** compares Unit Tests against Semester results to spot sudden `>=12%` drops or exam-anxiety splits (`High FA / Fail SA`) and drafts a bilingual PTM invite. Meanwhile, the **Parent-Sign Chase Queue** shows exactly who never received or never signed their card."

### Slide 10 — School WhatsApp Hub & Strict Send-on-Press Protocol (1.5 min)
- **Key Message**: "Teachers can send an entire class's report cards via Guided Sequential Mode (`Alt+Arrow`) or chat in the WhatsApp Web Replica. Crucially, **zero messages are ever sent autonomously** — every dispatch requires a human click on Send (`origin='manual_ui'`), protecting the school's number from WhatsApp bans."

### Slide 11 — Local-First Admission AI & Result-Day Co-Pilot (1.5 min)
- **Key Message**: "Parent enquiries never leave the school PC. Our Rust AI service feeds local `llama3.2:1b` strictly from 9 approved JSON knowledge files, redacts any invented fees or seat claims, and includes a **📊 Co-Pilot** button inside the chat composer that drafts polite, mark-accurate replies on result day."

### Slide 12 — Durable Rust Worker & Passive WhatsApp Intelligence (1.5 min)
- **Key Message**: "Our Rust background daemon runs 24/7 with crash recovery: scanning inbound WhatsApp texts into **1-click Office Action Cards** (leave notes, fee receipts, phone changes), auto-advancing follow-ups when parents reply, compiling an **8:30 AM Principal Daily Briefing**, and running a **DOM Selector Watchdog** on the bridge."

### Slide 13 — Defense-in-Depth Security Layer (1.5 min)
- **Key Message**: "We audited all 303 files and fixed 21 security issues: locking down unauthenticated APIs, jailing media reads inside `storage/whatsapp/`, locking `storage/`, `automation/`, and `knowledge/` via `.htaccess`, securing Google OAuth, adding CSRF tokens and CSP nonces, and removing hardcoded credentials from Rust source code."

### Slide 14 — Cross-Platform Deployment & Operations Matrix (1 min)
- **Key Message**: "Whether deployed on the school's 8 GB Windows PC (via NSSM services), a macOS admin machine (`launchd`), or a Linux server (`systemd`), the setup and backup playbook is standardized and documented."

### Slide 15 — Institutional Impact & Next Steps (1 min)
- **Closing**: Open for Board / Staff Q&A. Keep `docs/HCC_MASTER_INSTRUCTION_GUIDE.md` handy for operational questions.

---

## Anticipated Q&A Appendix

1. **Q: Can AI accidentally promise a fee discount or send a wrong message to a parent?**  
   *A: No. Two independent locks prevent this: `SafetyValidator` in Rust checks every draft against `knowledge/approved/fee_structure.json`, and `whatsapp_outbound_queue` enforces `origin='manual_ui'` — meaning nothing leaves the school without a staff member reviewing and clicking Send.*
2. **Q: What happens if the internet goes down during exam report printing?**  
   *A: Everything (Bootstrap, Font Awesome, Inter/Merriweather fonts, jsPDF, html2canvas, JSZip, MySQL, Rust AI, and Ollama) is 100% self-hosted locally. Printing, grading, Bonafides, Seating Plans, and Local AI work even completely offline.*
3. **Q: How do we export this presentation to PowerPoint or PDF?**  
   *A: Open `docs/presentation/index.html` in Chrome/Edge/Ego Lite and click **Export PDF / PPT** (or press `P` / `Cmd+P`). Choose "Save as PDF" with Background Graphics enabled for a clean 16:9 slide deck.*
