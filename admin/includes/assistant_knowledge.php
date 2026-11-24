<?php
/* ── HCC Assistant knowledge base ────────────────────────────────────
   Deterministic, offline knowledge base used by api_assistant_chat.php.
   Each topic: keywords (weighted match), title, text, steps, links, follow-ups.
   Links must point to existing admin pages (validated by the API).
   ────────────────────────────────────────────────────────────── */

function hccAssistantTopics(): array
{
    return [
        'overview' => [
            'keywords' => ['system', 'workflow', 'overview', 'modules', 'features', 'everything', 'end to end', 'whole system', 'what can this system'],
            'title' => 'How the HCC Automation System works',
            'text' => 'The system manages the full academic cycle of the school in one place — from entering marks to sharing results with parents.',
            'steps' => [
                'Enter or import marks — Unit Tests (I–IV) and Semester results (FA + SA).',
                'Review results in the Results Matrix and Unit Test Results pages.',
                'Print report cards, slips and certificates (PDF / print view).',
                'Track follow-ups — At-Risk Radar and Parent-Sign Chase.',
                'Communicate with parents through the WhatsApp Hub (every send is confirmed by a person).',
                'Manage admissions — enquiries pipeline and follow-up reminders.',
                'At year end, promote students and archive records.',
            ],
            'links' => [['label' => 'Results Matrix', 'href' => 'results.php'], ['label' => 'Unit Test Results', 'href' => 'unit_tests.php']],
            'followups' => ['How do I enter marks?', 'How do I import Excel?', 'How does WhatsApp sharing work?'],
        ],
        'marks_entry' => [
            'keywords' => ['enter marks', 'marks entry', 'entry', 'add marks', 'fa', 'sa', 'semester marks', 'input marks', 'edit marks', 'update marks'],
            'title' => 'Entering semester marks',
            'text' => 'Semester marks are stored per student, per subject, as FA (formative) and SA (summative) scores.',
            'steps' => [
                'Open Student Marks Entry from the sidebar (Semester Results).',
                'Select the academic year, standard, division and semester.',
                'Pick the student by roll number and fill in FA / SA obtained marks for each subject.',
                'Save — totals, percentage and pass/fail are calculated automatically.',
            ],
            'links' => [['label' => 'Student Marks Entry', 'href' => 'entry.php'], ['label' => 'Results Matrix', 'href' => 'results.php']],
            'followups' => ['What is the passing percentage?', 'How do I import Excel?', 'How do I print report cards?'],
        ],
        'import_excel' => [
            'keywords' => ['import', 'excel', 'xlsx', 'upload', 'spreadsheet', 'bulk', 'csv', 'sheet'],
            'title' => 'Importing marks from Excel',
            'text' => 'You can bulk-load marks instead of typing them one by one.',
            'steps' => [
                'Semester marks: use Import Semester Excel. Unit tests: use Import Unit Tests.',
                'Choose the class details, then upload the .xlsx file in the expected column layout.',
                'Review the preview / validation messages and confirm the import.',
                'Check the imported data in Results Matrix or Unit Test Results.',
            ],
            'links' => [['label' => 'Import Semester Excel', 'href' => 'import_excel.php'], ['label' => 'Import Unit Tests', 'href' => 'import_unit_tests.php']],
            'followups' => ['How do I enter marks?', 'How do unit tests work?'],
        ],
        'unit_tests' => [
            'keywords' => ['unit test', 'unit tests', 'ut', 'test results', 'slips', 'unit test entry', 'test i', 'test ii'],
            'title' => 'Unit Tests (I – IV)',
            'text' => 'Unit tests are tracked separately from semester exams.',
            'steps' => [
                'Enter scores in Unit Test Entry, or bulk-load them via Import Unit Tests.',
                'View class-wise results in Unit Test Results.',
                'Print unit test slips and report cards for parents from the results page.',
            ],
            'links' => [['label' => 'Unit Test Entry', 'href' => 'unit_test_entry.php'], ['label' => 'Unit Test Results', 'href' => 'unit_tests.php']],
            'followups' => ['How do I import Excel?', 'What is Parent-Sign Chase?'],
        ],
        'report_card' => [
            'keywords' => ['report card', 'print', 'pdf', 'result', 'results', 'matrix', 'download', 'marksheet'],
            'title' => 'Results Matrix & report cards',
            'text' => 'The Results Matrix shows a class at a glance and is the starting point for printing.',
            'steps' => [
                'Open Results Matrix and select the class (year, standard, division, semester).',
                'Review totals, percentages and pass / fail status per student.',
                'Open a student to view the report card, or use the print / PDF batch option for the whole class.',
            ],
            'links' => [['label' => 'Results Matrix', 'href' => 'results.php']],
            'followups' => ['What is the passing percentage?', 'How does WhatsApp sharing work?'],
        ],
        'passing' => [
            'keywords' => ['pass', 'passing', 'fail', 'percentage', 'grade', 'criteria', 'minimum', '35', '33'],
            'title' => 'Pass criteria',
            'text' => 'Pass/fail is calculated automatically from total marks (FA + SA) across subjects.',
            'steps' => [
                'Standard 9 and above: a student passes at 35% or more.',
                'Below standard 9: a student passes at 33% or more.',
                'The dashboard pass rate and average are computed from these rules.',
            ],
            'links' => [['label' => 'Results Matrix', 'href' => 'results.php']],
            'followups' => ['What is the At-Risk Radar?', 'Show me today\'s stats'],
        ],
        'timetable' => [
            'keywords' => ['timetable', 'time table', 'schedule', 'exam schedule', 'exam timetable', 'slots', 'calendar'],
            'title' => 'Exam Timetable builder',
            'text' => 'Design and publish exam schedules and class time slots.',
            'steps' => [
                'Open Exam Timetable from Academic Schedule.',
                'Create the exam, add dates, subjects and time slots per class.',
                'Print the timetable or export it as CSV to share.',
            ],
            'links' => [['label' => 'Exam Timetable', 'href' => 'timetable.php']],
            'followups' => ['How does exam seating work?', 'How do substitutes work?'],
        ],
        'seating' => [
            'keywords' => ['seating', 'seat', 'seats', 'hall', 'room', 'exam seating', 'arrangement'],
            'title' => 'Exam Seating Plan',
            'text' => 'Generates seating arrangements for upcoming exams.',
            'steps' => [
                'Open Exam Seating Plan.',
                'Choose the exam date, classes and rooms / capacity.',
                'Generate the plan, review it and print it for invigilators.',
            ],
            'links' => [['label' => 'Exam Seating Plan', 'href' => 'exam_seating.php']],
            'followups' => ['How does the timetable work?'],
        ],
        'substitute' => [
            'keywords' => ['substitute', 'substitution', 'absent teacher', 'proxy', 'leave', 'allocator', 'cover'],
            'title' => 'Substitute Allocator',
            'text' => 'Assigns free teachers to cover classes of absent staff for the day.',
            'steps' => [
                'Open Substitute Allocator.',
                'Mark the absent teacher(s) for today.',
                'Review suggested substitutes for each affected period and confirm.',
            ],
            'links' => [['label' => 'Substitute Allocator', 'href' => 'substitute_allocator.php']],
            'followups' => ['How does the timetable work?'],
        ],
        'at_risk' => [
            'keywords' => ['at risk', 'at-risk', 'risk', 'radar', 'weak', 'struggling', 'low marks', 'failing students'],
            'title' => 'At-Risk Radar',
            'text' => 'Flags students who need attention in the active academic year.',
            'steps' => [
                'A student is flagged if FA is high (≥ 65%) but SA is low (< 35%) — a possible exam-pressure gap.',
                'A student is also flagged if their overall percentage is below the pass mark.',
                'Open At-Risk Radar to see the list and plan interventions or contact parents.',
            ],
            'links' => [['label' => 'At-Risk Radar', 'href' => 'at_risk_radar.php']],
            'followups' => ['What is the passing percentage?', 'How does WhatsApp sharing work?'],
        ],
        'parent_sign' => [
            'keywords' => ['parent sign', 'signature', 'sign', 'signed', 'unsigned', 'chase', 'acknowledge'],
            'title' => 'Parent-Sign Chase',
            'text' => 'Tracks which report cards and unit test results are still not signed by parents.',
            'steps' => [
                'Open Parent-Sign Chase to see unsigned results grouped by class.',
                'Send reminders to parents through WhatsApp (you confirm each send).',
                'When a parent signs, mark it — the student disappears from the pending list.',
            ],
            'links' => [['label' => 'Parent-Sign Chase', 'href' => 'parent_sign_chase.php']],
            'followups' => ['How does WhatsApp sharing work?'],
        ],
        'whatsapp' => [
            'keywords' => ['whatsapp', 'wa', 'message', 'send', 'share', 'parents', 'bridge', 'dispatch', 'chat', 'notify'],
            'title' => 'WhatsApp Hub',
            'text' => 'Share results and reminders with parents on WhatsApp. Nothing is sent automatically — a staff member always reviews and presses Send.',
            'steps' => [
                'Open WhatsApp Hub and make sure the bridge session is connected (scan the QR code if asked).',
                'Choose the class / students and the message template or document to share.',
                'Review the message, then confirm the send.',
                'Every send is recorded in the WhatsApp Dispatch Log (sent / pending status).',
            ],
            'links' => [['label' => 'WhatsApp Hub', 'href' => 'whatsapp_share.php'], ['label' => 'Dispatch Log', 'href' => 'whatsapp_dispatch_log.php']],
            'followups' => ['How do admission enquiries work?', 'What is Parent-Sign Chase?'],
        ],
        'admissions' => [
            'keywords' => ['admission', 'admissions', 'enquiry', 'enquiries', 'inquiry', 'new student', 'follow up', 'follow-up', 'followup', 'crm', 'pipeline', 'lead'],
            'title' => 'Admission enquiries & follow-ups',
            'text' => 'A simple CRM for parent enquiries about admission.',
            'steps' => [
                'Capture the enquiry in Admission Enquiries (or from a WhatsApp chat).',
                'An AI draft reply may be suggested — staff review and edit it before use.',
                'Follow-up reminders are queued automatically; work through them in Admission Follow-ups.',
                'Move the enquiry through its stages until it is Enrolled or Closed.',
            ],
            'links' => [['label' => 'Admission Enquiries', 'href' => 'admission_enquiries.php'], ['label' => 'Admission Follow-ups', 'href' => 'admission_followups.php']],
            'followups' => ['How does WhatsApp sharing work?'],
        ],
        'promote' => [
            'keywords' => ['promote', 'promotion', 'next class', 'new year', 'year end', 'academic year', 'move students'],
            'title' => 'Promoting students',
            'text' => 'Moves students to the next standard at the end of the academic year.',
            'steps' => [
                'Make sure final results are complete for the year.',
                'Open Promote Students, choose the source class and the target year / class.',
                'Review the list (passed students are promoted) and confirm.',
                'A promotion notification appears in the dashboard bell.',
            ],
            'links' => [['label' => 'Promote Students', 'href' => 'promote_students.php']],
            'followups' => ['What is the Records Archive?'],
        ],
        'bonafide' => [
            'keywords' => ['bonafide', 'certificate', 'certificates', 'bona fide', 'leaving'],
            'title' => 'Bonafide Certificate',
            'text' => 'Generates an official bonafide certificate for a student.',
            'steps' => [
                'Open Bonafide Certificate.',
                'Search the student, fill in purpose / details.',
                'Generate and print — school name, logo and principal details come from settings.',
            ],
            'links' => [['label' => 'Bonafide Certificate', 'href' => 'bonafide_certificate.php']],
            'followups' => ['How do I print report cards?'],
        ],
        'archive' => [
            'keywords' => ['archive', 'records', 'vault', 'old records', 'history', 'previous year'],
            'title' => 'Records Archive',
            'text' => 'Stores past academic records so you can look them up later without cluttering current data.',
            'steps' => ['Open Records Archive.', 'Filter by year / class to find historical results and documents.'],
            'links' => [['label' => 'Records Archive', 'href' => 'records_archive.php']],
            'followups' => ['How do I promote students?'],
        ],
        'drive' => [
            'keywords' => ['drive', 'google drive', 'cloud', 'workspace', 'files', 'folder', 'storage'],
            'title' => 'Drive Workspace',
            'text' => 'Connects to Google Drive so generated documents can be stored and shared.',
            'steps' => [
                'Open Drive Workspace and sign in with the school Google account if prompted.',
                'Upload or browse files and create share links.',
            ],
            'links' => [['label' => 'Drive Workspace', 'href' => 'shared_workspace.php']],
            'followups' => ['How does WhatsApp sharing work?'],
        ],
        'security' => [
            'keywords' => ['password', 'change password', 'security', 'login', 'logout', 'log out', 'account', 'forgot', 'reset'],
            'title' => 'Account & security',
            'text' => 'Keep your admin account secure.',
            'steps' => [
                'Change your password from Security at the bottom of the sidebar.',
                'Forgot your password? Use “Forgot password” on the login page.',
                'Always log out from the sidebar when using a shared computer.',
            ],
            'links' => [['label' => 'Security', 'href' => 'change_password.php']],
            'followups' => ['Explain the system workflow'],
        ],
        'dashboard' => [
            'keywords' => ['dashboard', 'home', 'notifications', 'bell', 'alerts', 'top performer', 'cards'],
            'title' => 'Using the dashboard',
            'text' => 'The dashboard gives an at-a-glance summary of results, classes and daily operations.',
            'steps' => [
                'Stat cards show students, subjects, pass rate and average score.',
                'Daily Operations shows live counts — at-risk students, unsigned results, substitutions and seating plans.',
                'The bell shows recent promotion and system alerts.',
            ],
            'links' => [],
            'followups' => ['Show me today\'s stats', 'Explain the system workflow'],
        ],
    ];
}
