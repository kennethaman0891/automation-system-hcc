<?php
/* ── HCC Assistant knowledge base ────────────────────────────────────
   Offline answers for api_assistant_chat.php.
   Icons, labels, and badges are the same marks as admin/includes/sidebar.php.
   Steps were checked against the live admin pages. Do not describe a click
   that the page does not offer.
   ────────────────────────────────────────────────────────────── */

function hccAssistantNav(): array
{
    return [
        'dashboard' => [
            'icon' => 'fa-solid fa-gauge-high', 'label' => 'Dashboard', 'href' => 'dashboard.php',
            'group' => 'Overview',
            'points' => [
                'The welcome band shows the school logo and the greeting in India time. If no report cards exist yet, the page offers Import Semester Excel.',
                'The WhatsApp card counts Parents, Dispatched, In Queue, and open Enquiries. Quick Batch Dispatch opens WhatsApp Hub.',
                'Below that are the daily operation cards, class chips into Results Matrix, Top Performer, Needs Support, and Promotion & System Alerts.',
                'This page does not show a students, subjects, or pass-rate card. Ask for today figures when you need those totals.',
            ],
        ],
        'unit_tests' => [
            'icon' => 'fa-solid fa-square-poll-vertical', 'label' => 'Unit Test Results', 'href' => 'unit_tests.php',
            'group' => 'Unit Tests (I – IV)',
            'points' => [
                'Filter by year, standard, division, and test. Tests 1 and 2 sit with Semester 1. Tests 3 and 4 sit with Semester 2.',
                'Use Export Excel, Print Marksheet, or Parent Slips. Parent slips print four slips on one A4.',
                'WhatsApp Hub on this page shares the selected test. A message waits until a person presses Send.',
                'Slip grades are the same for every standard: A1 from 91%, A2 from 81%, B1 from 71%, B2 from 61%, C1 from 51%, C2 from 41%, D from 35%, and E below 35%.',
            ],
        ],
        'unit_test_entry' => [
            'icon' => 'fa-solid fa-file-pen', 'label' => 'Unit Test Entry', 'href' => 'unit_test_entry.php',
            'group' => 'Unit Tests (I – IV)',
            'points' => [
                'Choose Unit Test 1, 2, 3, or 4, then the class, roll number, and student name.',
                'Enter marks for each subject. The default maximum is 20, and half marks are allowed.',
                'Press Save Changes, or Save & Add Next. These marks stay on the unit-test record. They are not the semester FA and SA columns.',
            ],
        ],
        'import_unit_tests' => [
            'icon' => 'fa-solid fa-file-import', 'label' => 'Import Unit Tests', 'href' => 'import_unit_tests.php',
            'group' => 'Unit Tests (I – IV)',
            'points' => [
                'Upload the unit-test workbook. Nothing is saved on upload.',
                'Review the classes the page detected, then confirm the import.',
                'The workbook uses the school unit-test layout, including a 20-mark maximum and half marks.',
                'Open Unit Test Results afterwards and check that class.',
            ],
        ],
        'timetable' => [
            'icon' => 'fa-solid fa-calendar-week', 'label' => 'Exam Timetable', 'href' => 'timetable.php',
            'group' => 'Academic Schedule', 'badge' => 'Builder', 'badgeClass' => 'gold',
            'points' => [
                'Pick a saved schedule, or start a new one. Generate Timetable Matrix can draft from the fixed statement or from your own prompt. You can also build the grid by hand.',
                'Use + Add Date and + Add Class, fill the subject in each cell, then press Save Schedule.',
                'Print asks you to save first when the grid has unsaved edits. CSV downloads the grid.',
                'This is the examination schedule. It is not the daily teaching timetable.',
            ],
        ],
        'seating' => [
            'icon' => 'fa-solid fa-chair', 'label' => 'Exam Seating Plan', 'href' => 'exam_seating.php',
            'group' => 'Academic Schedule',
            'points' => [
                'Choose the academic year. Link a saved Exam Timetable and one of its slots, or type the exam date and slot label. Seats per bench are 2 or 3.',
                'Select at least two classes. Add each room with its name and its number of benches. The page will not generate a plan for a single class.',
                'Preview Matrix only draws the plan. Generate & Save Seating Plan stores it.',
                'Then print the Bench Matrix, Room Door Chits, or Desk Stickers. If students remain unseated, add benches or rooms.',
            ],
        ],
        'substitute' => [
            'icon' => 'fa-solid fa-people-arrows', 'label' => 'Substitute Allocator', 'href' => 'substitute_allocator.php',
            'group' => 'Academic Schedule',
            'points' => [
                'If no teachers are listed, expand Quick Teacher Schedule Setup and add the staff mapping first.',
                'Choose the date, tick the absent teachers, and press Load Uncovered Slots.',
                'Review the ranked substitute on each uncovered period. Press Auto-Allocate Best Fit, or change a name and press Save Manual Selection.',
                'Print Staffroom Proxy Noticeboard for the saved day. A teacher notice opens as a WhatsApp draft you send yourself. Mark Sent only records that you notified them. It does not send the message.',
            ],
        ],
        'at_risk' => [
            'icon' => 'fa-solid fa-triangle-exclamation', 'label' => 'At-Risk Radar', 'href' => 'at_risk_radar.php',
            'group' => 'Daily Operations',
            'points' => [
                'Set the academic year, unit test, semester, and any class filter. The drop threshold defaults to 12%. You can set it from 1 to 60. A student appears only when at least one rule matches.',
                'Sudden Drop: the unit-test percentage is higher than the semester percentage by at least that threshold, for the student overall or for one subject.',
                'Exam-Anxiety Split: FA is 60% or more and SA is under the pass mark. The pass mark is 35% in Standard 9 and 10, and 33% in Standards 1 to 8. This is checked on each subject and on the FA and SA totals.',
                'Repeated Fail: the same subject is under that pass mark in both the unit test and the semester, or the overall percentage is under the pass mark in both.',
                'Export a CSV or stage a bilingual PTM WhatsApp draft. The draft is not sent until someone confirms it. The Alerts number on the dashboard uses a simpler count and can differ from this list.',
            ],
        ],
        'parent_sign' => [
            'icon' => 'fa-solid fa-file-signature', 'label' => 'Parent-Sign Chase', 'href' => 'parent_sign_chase.php',
            'group' => 'Daily Operations',
            'points' => [
                'Filter by year, standard, division, and chase bucket: All Unsigned, Never Sent, Sent but Unsigned, or Already Signed. Search can use roll number or name.',
                'Press Mark Signed when the parent has signed. Undo Sign clears it. The same flag is the Parents sign box on Student Marks Entry and the digital toggle on Results Matrix.',
                'Open in WhatsApp Hub prepares the batch. Nothing is sent until you confirm it in the hub.',
                'Print Assembly Bench-Check Sheet is the paper list for the morning check.',
            ],
        ],
        'results' => [
            'icon' => 'fa-solid fa-table', 'label' => 'Results Matrix', 'href' => 'results.php',
            'group' => 'Semester Results',
            'points' => [
                'Choose the academic year, standard, division, and semester. Division choices are A, B, and C. Semester choices are Semester 1 and Semester 2. Search can filter by roll number or name.',
                'Read the total, percentage, and pass or fail. Pass is 35% or more in Standard 9 and 10, and 33% or more in Standards 1 to 8, from FA plus SA.',
                'Print opens the print-ready marksheet. Export downloads the Excel sheet. Download Analytics PDF is the class analytics report, not the student marksheet.',
                'Open a student row for that student report card. Import jumps to Import Semester Excel for this class.',
            ],
        ],
        'entry' => [
            'icon' => 'fa-solid fa-pen-to-square', 'label' => 'Student Marks Entry', 'href' => 'entry.php',
            'group' => 'Semester Results',
            'points' => [
                'Type the academic year, standard, division, semester, roll number, and student name. You can also enter date of birth, attendance, and a remark. Those class fields lock while you edit an existing student.',
                'Fill FA obtained and SA obtained for each subject. Maxima come from Manage Class Subjects on the same page. Over-maximum marks are blocked before save.',
                'Press Save Student Entry, or Save & Add Next Student. Results Matrix then shows the total, percentage, grade, and pass or fail.',
                'The Parents sign box on this form sets the same flag used by Parent-Sign Chase.',
            ],
        ],
        'import_excel' => [
            'icon' => 'fa-solid fa-file-excel', 'label' => 'Import Semester Excel', 'href' => 'import_excel.php',
            'group' => 'Semester Results',
            'points' => [
                'The page steps are Upload File, Review Classes, then Import Complete. A master file can carry more than one class tab.',
                'Check the detected class, roster, and marks. You can correct the academic year, standard, division, and semester before you confirm.',
                'Confirm one class, or confirm all detected classes. Nothing is saved until that confirm.',
                'Open Results Matrix afterwards for that class. Unit tests use Import Unit Tests, not this page.',
            ],
        ],
        'promote' => [
            'icon' => 'fa-solid fa-user-graduate', 'label' => 'Promote Students', 'href' => 'promote_students.php',
            'group' => 'Records & Management',
            'points' => [
                'Set the current academic year and the target academic year, then press Update View. The board lists Standards 1 to 10.',
                'Standard 10 is Graduated and cannot be promoted. For every other class, open the checklist. Every student starts ticked. Untick anyone who should stay behind, then press Confirm & Promote.',
                'Promotion copies the ticked students. It does not filter by pass or fail. Roll numbers stay the same, and the new class starts with blank marks.',
                'Promotion is refused when the target year is locked in Records Archive. Revert promotion deletes the target class records for that year, standard, division, and semester.',
            ],
        ],
        'bonafide' => [
            'icon' => 'fa-solid fa-award', 'label' => 'Bonafide Certificate', 'href' => 'bonafide_certificate.php',
            'group' => 'Records & Management',
            'points' => [
                'Search by roll number or name and choose the student.',
                'Check the details and the purpose. The purpose starts as Educational Purpose. The certificate serial is assigned for that academic year and is kept if it already belongs to this student.',
                'Download PDF or Print. The preview is the document that prints. The school name, logo, and principal details come from school settings.',
            ],
        ],
        'dispatch_log' => [
            'icon' => 'fa-solid fa-clock-rotate-left', 'label' => 'WhatsApp Dispatch Log', 'href' => 'whatsapp_dispatch_log.php',
            'group' => 'Records & Management',
            'points' => [
                'This page is the history of WhatsApp sends from the hub.',
                'Filter by document: report card, unit test, bonafide, or announcement.',
                'Filter by status: sent, opened, ready, pending, skipped, failed, or no phone.',
            ],
        ],
        'archive' => [
            'icon' => 'fa-solid fa-box-archive', 'label' => 'Records Archive', 'href' => 'records_archive.php',
            'group' => 'Records & Management', 'badge' => 'Vault', 'badgeClass' => 'archive',
            'points' => [
                'Each year card shows students, report-card marks, unit-test records, promotion snapshots, and the pass rate when marks exist. Open a card to work in that year.',
                'Archive / Store Year moves a year into the vault. A locked year cannot be used as an editable target for promotion. The active year is marked on the vault.',
                'Backup DB downloads a MySQL export of the database. Promote Students on this page jumps to the promotion board.',
            ],
        ],
        'whatsapp' => [
            'icon' => 'fa-brands fa-whatsapp', 'label' => 'WhatsApp Hub', 'href' => 'whatsapp_share.php',
            'group' => 'Communication & Admissions', 'badge' => 'Live', 'badgeClass' => 'wa',
            'points' => [
                'If the bridge is logged out, scan the QR. On the phone use WhatsApp, then Linked Devices, then Link a Device. A phone camera can also open the link.',
                'Choose the class and the document: report card, unit test slip, bonafide certificate, or a school notice. Pick the students and read the message.',
                'Press Send only when the message is right. Nothing is sent before that press.',
                'WhatsApp Dispatch Log then shows sent, opened, ready, pending, skipped, failed, or no phone. Substitute notices are sent from Substitute Allocator, not from this hub.',
            ],
        ],
        'followups' => [
            'icon' => 'fa-solid fa-phone-volume', 'label' => 'Admission Follow-ups', 'href' => 'admission_followups.php',
            'group' => 'Communication & Admissions', 'badge' => 'CRM', 'badgeClass' => 'crm',
            'points' => [
                'The desks are Today, Overdue, Upcoming, High-Priority, Awaiting, Interested, and Completed.',
                'Dispatch the WhatsApp message yourself from this page.',
                'Run Engine Now queues due reminder jobs and refreshes the morning briefing. It is not the button that sends a result or a report card.',
            ],
        ],
        'enquiries' => [
            'icon' => 'fa-solid fa-envelope-open-text', 'label' => 'Admission Enquiries', 'href' => 'admission_enquiries.php',
            'group' => 'Communication & Admissions', 'badge' => 'Pipeline', 'badgeClass' => 'pipeline',
            'points' => [
                'Capture the parent enquiry, including one that arrived on WhatsApp.',
                'Move it through New, Contacted, Information Shared, Interested, Visit Planned, Documents Pending, Application Submitted, Enrolled, or Closed.',
                'An AI draft can be suggested for a reply. Read it and edit it. Analyze and save do not send WhatsApp.',
                'Send is a separate action, and it is refused while the bridge is disconnected.',
            ],
        ],
        'drive' => [
            'icon' => 'fa-brands fa-google-drive', 'label' => 'Drive Workspace', 'href' => 'shared_workspace.php',
            'group' => 'Cloud Workspace', 'badge' => 'Drive', 'badgeClass' => 'drive',
            'points' => [
                'Paste a folder link from drive.google.com or docs.google.com, or a valid folder ID, and save it. A folder link is enough.',
                'Connect Google is shown only when sign-in is offered. That sign-in is optional. You can upload files into the linked workspace and open what is already there.',
                'Unlink removes the folder connection from this system. It does not delete the folder in Google Drive.',
            ],
        ],
        'security' => [
            'icon' => 'fa-solid fa-user-shield', 'label' => 'Security', 'href' => 'change_password.php',
            'group' => 'Account',
            'points' => [
                'Open Account Security & Password and use Account Password to replace your own admin password.',
                'If you cannot sign in, use Forgot password? on the login page. That recovery page is outside the dashboard.',
            ],
        ],
        'logout' => [
            'icon' => 'fa-solid fa-arrow-right-from-bracket', 'label' => 'Log out', 'href' => 'logout.php',
            'group' => 'Account',
            'points' => [
                'Log out is the last item in the sidebar.',
                'Confirm it in the dialog. Unsaved changes on the page you are leaving are lost.',
                'The assistant cannot log you out by itself. Open is only the button that shows the confirm page.',
            ],
        ],
    ];
}

function hccAssistantIconOk(string $icon): bool
{
    return (bool)preg_match('/^fa-(solid|brands) fa-[a-z0-9-]+$/', $icon);
}

function hccAssistantPublicItem(array $item, string $text): ?array
{
    $icon = (string)($item['icon'] ?? '');
    $href = (string)($item['href'] ?? '');
    if (!hccAssistantIconOk($icon) || !preg_match('/^[a-z0-9_]+\.php$/', $href)) {
        return null;
    }
    if (!is_file(dirname(__DIR__) . '/' . $href)) {
        return null;
    }
    $row = [
        'icon' => $icon,
        'label' => (string)($item['label'] ?? ''),
        'href' => $href,
    ];
    if ($text !== '') {
        $row['text'] = $text;
    }
    $points = [];
    foreach ((array)($item['points'] ?? []) as $point) {
        $point = trim((string)$point);
        if ($point !== '') {
            $points[] = $point;
        }
    }
    if ($points) {
        $row['points'] = $points;
    }
    $badge = (string)($item['badge'] ?? '');
    $badgeClass = (string)($item['badgeClass'] ?? '');
    if ($badge !== '' && preg_match('/^(gold|wa|archive|crm|pipeline|drive)$/', $badgeClass)) {
        $row['badge'] = $badge;
        $row['badgeClass'] = $badgeClass;
    }
    return $row;
}

function hccAssistantPublicReply(array $topic): array
{
    $nav = hccAssistantNav();
    $byHref = [];
    foreach ($nav as $item) {
        $byHref[$item['href']] = $item;
    }

    $groups = [];
    if (!empty($topic['walkthrough'])) {
        foreach ($nav as $item) {
            $pub = hccAssistantPublicItem($item, '');
            if ($pub === null || empty($pub['points'])) {
                continue;
            }
            $g = (string)$item['group'];
            if (!isset($groups[$g])) {
                $groups[$g] = ['label' => $g, 'items' => []];
            }
            $groups[$g]['items'][] = $pub;
        }
    }

    /* Consecutive steps for the same page become one card: icon, name,
       badge, numbered points. href stays on the card so the client can
       render a separate Open button. The card itself is not a link. */
    $steps = [];
    foreach ($topic['steps'] as $step) {
        if (is_string($step)) {
            $steps[] = $step;
            continue;
        }
        if (!is_array($step)) {
            continue;
        }
        $text = trim((string)($step['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $key = (string)($step['nav'] ?? '');
        $last = count($steps) - 1;
        if ($last >= 0 && is_array($steps[$last]) && ($steps[$last]['_nav'] ?? '') === $key && $key !== '') {
            $steps[$last]['points'][] = $text;
            continue;
        }
        $block = ['_nav' => $key, 'points' => [$text]];
        if ($key !== '' && isset($nav[$key])) {
            $item = hccAssistantPublicItem($nav[$key], '');
            if ($item !== null) {
                unset($item['points'], $item['text']);
                $block = array_merge($item, $block);
            }
        }
        $steps[] = $block;
    }
    foreach ($steps as $i => $step) {
        if (is_array($step)) {
            unset($steps[$i]['_nav']);
        }
    }

    $links = [];
    foreach ($topic['links'] as $link) {
        $href = (string)($link['href'] ?? '');
        if (!isset($byHref[$href])) {
            continue;
        }
        $pub = hccAssistantPublicItem($byHref[$href], '');
        if ($pub === null) {
            continue;
        }
        unset($pub['text']);
        $label = trim((string)($link['label'] ?? ''));
        if ($label !== '') {
            $pub['label'] = $label;
        }
        $links[] = $pub;
    }

    /* A page that already has an Open button on its card does not need
       a second chip at the bottom of the answer. */
    $opened = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            if (!empty($item['href'])) {
                $opened[$item['href']] = true;
            }
        }
    }
    foreach ($steps as $step) {
        if (is_array($step) && !empty($step['href'])) {
            $opened[$step['href']] = true;
        }
    }
    $links = array_values(array_filter($links, static function (array $link) use ($opened): bool {
        return !isset($opened[(string)($link['href'] ?? '')]);
    }));

    return [
        'title' => (string)$topic['title'],
        'text' => (string)$topic['text'],
        'steps' => $steps,
        'groups' => array_values($groups),
        'links' => $links,
        'followups' => array_values($topic['followups']),
        'ordered' => array_key_exists('ordered', $topic) ? (bool)$topic['ordered'] : true,
    ];
}

function hccAssistantTopics(): array
{
    return [
        'overview' => [
            'keywords' => ['system workflow', 'how the system', 'the system works', 'whole system', 'end to end', 'what can this system', 'overview', 'all modules', 'sidebar', 'what can you do', 'who are you', 'your name'],
            'title' => 'How the HCC Automation System works',
            'text' => 'Follow the sidebar from top to bottom. Each module is explained in steps, with the same icon, name, and badge as the menu. The steps stay in this chat. Open is the only button that leaves for that page. The mark beside each reply is the school logo.',
            'walkthrough' => true,
            'steps' => [],
            'links' => [],
            'followups' => ['How do I enter marks?', 'What is the passing percentage?', 'How does WhatsApp sharing work?'],
            'ordered' => false,
        ],
        'marks_entry' => [
            'keywords' => ['enter marks', 'marks entry', 'add marks', 'semester marks', 'input marks', 'edit marks', 'update marks', 'fa', 'sa'],
            'title' => 'Entering semester marks',
            'text' => 'Semester marks are stored per student and per subject as FA (formative) and SA (summative). Over-maximum marks are blocked before save.',
            'steps' => [
                ['nav' => 'entry', 'text' => 'Open Student Marks Entry under Semester Results. Type the academic year, standard, division, semester, roll number, and student name. You can also enter date of birth, attendance, and a remark. Those class fields lock while you edit an existing student.'],
                ['nav' => 'entry', 'text' => 'Fill FA obtained and SA obtained for each subject. Maxima come from the class subject list. If a subject or its maximum is missing, open Manage Class Subjects on the same page.'],
                ['nav' => 'entry', 'text' => 'Press Save Student Entry, or Save & Add Next Student. Results Matrix then shows the total, percentage, grade, and pass or fail from FA + SA. The Parents sign box on this form sets the same flag used by Parent-Sign Chase.'],
            ],
            'links' => [['label' => 'Student Marks Entry', 'href' => 'entry.php'], ['label' => 'Results Matrix', 'href' => 'results.php']],
            'followups' => ['What is the passing percentage?', 'How do I import Excel?', 'How do I print report cards?'],
        ],
        'import_excel' => [
            'keywords' => ['import', 'excel', 'xlsx', 'upload', 'spreadsheet', 'bulk import', 'csv'],
            'title' => 'Importing marks from Excel',
            'text' => 'Semester marks and unit tests use two different pages. Both show a preview before anything is saved.',
            'steps' => [
                ['nav' => 'import_excel', 'text' => 'Semester marks: open Import Semester Excel. The page steps are Upload File, Review Classes, then Import Complete. Upload the .xlsx workbook. A master file can carry more than one class tab.'],
                ['nav' => 'import_excel', 'text' => 'Check the detected class, roster, and marks. You can correct the academic year, standard, division, and semester before you confirm. Confirm one class, or confirm all detected classes.'],
                ['nav' => 'import_unit_tests', 'text' => 'Unit tests: open Import Unit Tests instead. Upload that workbook, review the detected classes, then confirm. The importer expects the school unit-test layout, including a 20-mark maximum and half marks.'],
                ['nav' => 'results', 'text' => 'After a semester import, open Results Matrix for that class. After a unit-test import, open Unit Test Results.'],
            ],
            'links' => [['label' => 'Import Semester Excel', 'href' => 'import_excel.php'], ['label' => 'Import Unit Tests', 'href' => 'import_unit_tests.php']],
            'followups' => ['How do I enter marks?', 'How do unit tests work?'],
        ],
        'unit_tests' => [
            'keywords' => ['unit test', 'unit tests', 'unit test entry', 'test i', 'test ii', 'parent slips', 'unit test results'],
            'title' => 'Unit Tests (I – IV)',
            'text' => 'Unit tests are stored apart from semester FA and SA. Tests 1 and 2 sit with Semester 1; tests 3 and 4 sit with Semester 2.',
            'steps' => [
                ['nav' => 'unit_test_entry', 'text' => 'Open Unit Test Entry. Choose Unit Test 1, 2, 3, or 4, then the class, roll number, and student name. Enter marks for each subject. The default maximum is 20, and half marks are allowed. Press Save Changes, or Save & Add Next.'],
                ['nav' => 'import_unit_tests', 'text' => 'To load a whole workbook, open Import Unit Tests, review the detected classes, and confirm.'],
                ['nav' => 'unit_tests', 'text' => 'Open Unit Test Results and filter by year, standard, division, and test. Use Export Excel, Print Marksheet, or Parent Slips (four slips on one A4). WhatsApp Hub on that page shares the selected test.'],
                ['nav' => 'unit_tests', 'text' => 'The slip grade is the same for every standard: A1 from 91%, A2 from 81%, B1 from 71%, B2 from 61%, C1 from 51%, C2 from 41%, D from 35%, and E below 35%.'],
            ],
            'links' => [['label' => 'Unit Test Entry', 'href' => 'unit_test_entry.php'], ['label' => 'Unit Test Results', 'href' => 'unit_tests.php']],
            'followups' => ['What is the passing percentage?', 'What is Parent-Sign Chase?'],
        ],
        'report_card' => [
            'keywords' => ['report card', 'print marksheet', 'results matrix', 'marksheet', 'print results', 'download results'],
            'title' => 'Results Matrix and report cards',
            'text' => 'Results Matrix is the class view for one semester. Printing and the individual report card both start here.',
            'steps' => [
                ['nav' => 'results', 'text' => 'Open Results Matrix under Semester Results. Choose the academic year, standard, division, and semester. Division choices are A, B, and C. Semester choices are Semester 1 and Semester 2. Search can filter by roll number or name.'],
                ['nav' => 'results', 'text' => 'Read the total, percentage, and pass or fail. Pass is 35% or more in Standard 9 and 10, and 33% or more in Standards 1 to 8. Parents Sign can be Digital Sign, a blank block for a physical signature, or hidden.'],
                ['nav' => 'results', 'text' => 'Print opens the print-ready marksheet. Export downloads the Excel sheet. Download Analytics PDF is the class analytics report, not the student marksheet. Open a student row for that student’s report card. Import jumps to Import Semester Excel for this class.'],
                ['nav' => 'whatsapp', 'text' => 'WhatsApp Hub on the same toolbar shares this class. A send still waits until a person confirms it.'],
            ],
            'links' => [['label' => 'Results Matrix', 'href' => 'results.php'], ['label' => 'Student Marks Entry', 'href' => 'entry.php']],
            'followups' => ['What is the passing percentage?', 'How does WhatsApp sharing work?'],
        ],
        'passing' => [
            'keywords' => ['passing', 'pass mark', 'pass criteria', 'fail', 'percentage', 'grade', 'minimum', 'distinction'],
            'title' => 'Pass marks and grades',
            'text' => 'Semester report cards and unit-test slips use different scales. The dashboard pass rate, when you ask for today’s figures, uses the semester rule only.',
            'steps' => [
                ['nav' => 'results', 'text' => 'Semester, Standard 9 and 10: pass at 35% or more of FA + SA. Grades are Distinction from 75%, Grade I from 60%, Grade II from 45%, Pass from 35%, and Fail below 35%.'],
                ['nav' => 'results', 'text' => 'Semester, Standards 1 to 8: pass at 33% or more. Letter grades are A1 from 90.5%, A2 from 80.5%, B1 from 70.5%, B2 from 60.5%, C1 from 50.5%, C2 from 40.5%, D from 33%, E1 from 21%, and E2 below 21%.'],
                ['nav' => 'unit_tests', 'text' => 'Unit-test slips use one scale for every standard: A1 from 91%, A2 from 81%, B1 from 71%, B2 from 61%, C1 from 51%, C2 from 41%, D from 35%, and E below 35%. D is the lowest pass band on the slip.'],
            ],
            'links' => [['label' => 'Results Matrix', 'href' => 'results.php'], ['label' => 'Unit Test Results', 'href' => 'unit_tests.php']],
            'followups' => ['What is the At-Risk Radar?', 'Show me today\'s stats'],
        ],
        'timetable' => [
            'keywords' => ['timetable', 'time table', 'exam schedule', 'exam timetable', 'builder'],
            'title' => 'Exam Timetable builder',
            'text' => 'Exam Timetable sits under Academic Schedule and carries the Builder badge. It publishes examination dates and subjects, not the daily teaching timetable.',
            'steps' => [
                ['nav' => 'timetable', 'text' => 'Open Exam Timetable. Pick a saved schedule, or start a new one. Generate Timetable Matrix can draft from the fixed statement or from your own prompt. You can also build it by hand.'],
                ['nav' => 'timetable', 'text' => 'Use + Add Date and + Add Class, then fill the subject in each cell. Press Save Schedule. If you print with unsaved edits, the page asks you to save first or preview the draft.'],
                ['nav' => 'timetable', 'text' => 'Print opens the official preview. CSV downloads the grid. The seating planner can later link one of these saved schedules.'],
            ],
            'links' => [['label' => 'Exam Timetable', 'href' => 'timetable.php'], ['label' => 'Exam Seating Plan', 'href' => 'exam_seating.php']],
            'followups' => ['How does exam seating work?', 'How do substitutes work?'],
        ],
        'seating' => [
            'keywords' => ['seating', 'seat', 'seats', 'hall', 'exam seating', 'desk sticker', 'door chit'],
            'title' => 'Exam Seating Plan',
            'text' => 'The planner interleaves classes on each bench so neighbours are from different classes whenever the numbers allow.',
            'steps' => [
                ['nav' => 'seating', 'text' => 'Open Exam Seating Plan. Choose the academic year. Optionally link a saved Exam Timetable and pick one of its slots. Otherwise type the exam date and slot label. Seats per bench are 2 or 3.'],
                ['nav' => 'seating', 'text' => 'Select at least two classes or divisions. Add each room with its name and number of benches. The page will not generate a plan for a single class.'],
                ['nav' => 'seating', 'text' => 'Preview Matrix only draws the plan. Generate & Save Seating Plan stores it. Then print the Bench Matrix, Room Door Chits, or Desk Stickers. If students remain unseated, add benches or rooms.'],
            ],
            'links' => [['label' => 'Exam Seating Plan', 'href' => 'exam_seating.php'], ['label' => 'Exam Timetable', 'href' => 'timetable.php']],
            'followups' => ['How does the timetable work?'],
        ],
        'substitute' => [
            'keywords' => ['substitute', 'substitution', 'absent teacher', 'proxy', 'allocator'],
            'title' => 'Substitute Allocator',
            'text' => 'This page covers one date. It ranks free teachers by a clear period, fewer substitutions in the past 7 days, and a subject match.',
            'steps' => [
                ['nav' => 'substitute', 'text' => 'Open Substitute Allocator. If no teachers are listed, expand Quick Teacher Schedule Setup and add the staff mapping first.'],
                ['nav' => 'substitute', 'text' => 'Step 1: choose the date, tick the absent teachers, and press Load Uncovered Slots.'],
                ['nav' => 'substitute', 'text' => 'Step 2: review the ranked substitute on each uncovered period. Press Auto-Allocate Best Fit, or change a name and press Save Manual Selection.'],
                ['nav' => 'substitute', 'text' => 'Print Staffroom Proxy Noticeboard for the saved day. For a teacher notice, copy the message or open WhatsApp. You send it yourself, then press Mark Sent here. Mark Sent only records that you notified them.'],
            ],
            'links' => [['label' => 'Substitute Allocator', 'href' => 'substitute_allocator.php']],
            'followups' => ['How does the timetable work?'],
        ],
        'at_risk' => [
            'keywords' => ['at risk', 'at-risk', 'radar', 'sudden drop', 'exam anxiety', 'repeated fail', 'struggling'],
            'title' => 'At-Risk Radar',
            'text' => 'At-Risk Radar compares unit tests with semester report cards for the year and semester you select. A student appears only when at least one of the three rules matches.',
            'steps' => [
                ['nav' => 'at_risk', 'text' => 'Open At-Risk Radar under Daily Operations. Set the academic year, unit test, semester, and any class filter. The drop threshold defaults to 12%. You can set it from 1 to 60.'],
                ['nav' => 'at_risk', 'text' => 'Sudden Drop: the unit-test percentage is higher than the semester percentage by at least that threshold, for the student overall or for one subject.'],
                ['nav' => 'at_risk', 'text' => 'Exam-Anxiety Split: FA is 60% or more and SA is under the pass mark. The pass mark is 35% in Standard 9 and 10, and 33% in Standards 1 to 8. This is checked on each subject and on the FA and SA totals.'],
                ['nav' => 'at_risk', 'text' => 'Repeated Fail: the same subject is under that pass mark in both the unit test and the semester, or the overall percentage is under the pass mark in both.'],
                ['nav' => 'dashboard', 'text' => 'The Alerts number on the dashboard is a different, simpler count for the active year. It counts a student when any subject has FA of at least 65% and SA under 35%, or when that subject’s combined marks are under the pass mark. Do not expect it to match the radar list.'],
                ['nav' => 'at_risk', 'text' => 'From the radar you can export a CSV and stage a bilingual PTM WhatsApp draft. The draft is not sent until someone confirms it.'],
            ],
            'links' => [['label' => 'At-Risk Radar', 'href' => 'at_risk_radar.php'], ['label' => 'Dashboard', 'href' => 'dashboard.php']],
            'followups' => ['What is the passing percentage?', 'How does WhatsApp sharing work?'],
        ],
        'parent_sign' => [
            'keywords' => ['parent sign', 'parents sign', 'signature', 'unsigned', 'signed', 'chase', 'bench check'],
            'title' => 'Parent-Sign Chase',
            'text' => 'This queue reads parents_sign on semester report cards and on unit-test slips. A student leaves the unsigned list when that flag is 1.',
            'steps' => [
                ['nav' => 'parent_sign', 'text' => 'Open Parent-Sign Chase under Daily Operations. Filter by year, standard, division, and chase bucket: All Unsigned, Never Sent, Sent but Unsigned, or Already Signed. You can also search by roll number or name.'],
                ['nav' => 'parent_sign', 'text' => 'Press Mark Signed when the parent has signed. Undo Sign clears it again. The same flag can be set with the Parents sign box on Student Marks Entry, and with the digital toggle on Results Matrix.'],
                ['nav' => 'whatsapp', 'text' => 'Open in WhatsApp Hub prepares the batch. Nothing is sent until you confirm it in the hub. Print Assembly Bench-Check Sheet is the paper list for the morning check.'],
            ],
            'links' => [['label' => 'Parent-Sign Chase', 'href' => 'parent_sign_chase.php'], ['label' => 'WhatsApp Hub', 'href' => 'whatsapp_share.php']],
            'followups' => ['How does WhatsApp sharing work?', 'How do I print report cards?'],
        ],
        'whatsapp' => [
            'keywords' => ['whatsapp', 'whatsapp hub', 'dispatch', 'send message', 'share results', 'bridge', 'qr code'],
            'title' => 'WhatsApp Hub',
            'text' => 'Result messages, slips, certificates, and notices are sent only after a person reviews them and presses Send. The hub does not send on its own.',
            'steps' => [
                ['nav' => 'whatsapp', 'text' => 'Open WhatsApp Hub under Communication & Admissions. If the bridge is logged out, scan the QR. On the phone use WhatsApp, Linked Devices, Link a Device. A phone camera can also open the link.'],
                ['nav' => 'whatsapp', 'text' => 'Choose the class and the document: report card, unit test slip, bonafide certificate, or a school notice. Pick the students and the message template, then read the message.'],
                ['nav' => 'whatsapp', 'text' => 'Press Send only when the message is right. The WhatsApp Dispatch Log then shows sent, opened, ready, pending, skipped, failed, or no phone.'],
                ['nav' => 'substitute', 'text' => 'Substitute notices are the exception. Those open from Substitute Allocator as a WhatsApp draft you send yourself. Mark Sent on that page only records the notice. It does not send it.'],
            ],
            'links' => [['label' => 'WhatsApp Hub', 'href' => 'whatsapp_share.php'], ['label' => 'WhatsApp Dispatch Log', 'href' => 'whatsapp_dispatch_log.php']],
            'followups' => ['How do admission enquiries work?', 'What is Parent-Sign Chase?'],
        ],
        'admissions' => [
            'keywords' => ['admission', 'admissions', 'enquiry', 'enquiries', 'inquiry', 'new student', 'follow up', 'follow-up', 'followup', 'pipeline'],
            'title' => 'Admission enquiries and follow-ups',
            'text' => 'Enquiries and follow-ups are two sidebar pages. WhatsApp to a parent is still confirmed by a person.',
            'steps' => [
                ['nav' => 'enquiries', 'text' => 'Open Admission Enquiries. Capture the parent enquiry, including one that arrived on WhatsApp. Move it through New, Contacted, Information Shared, Interested, Visit Planned, Documents Pending, Application Submitted, Enrolled, or Closed.'],
                ['nav' => 'enquiries', 'text' => 'An AI draft can be suggested for a reply. Read it and edit it. Analyze and save do not send WhatsApp. Send is a separate action, and it is refused while the bridge is disconnected.'],
                ['nav' => 'followups', 'text' => 'Open Admission Follow-ups. The desks are Today, Overdue, Upcoming, High-Priority, Awaiting, Interested, and Completed. Dispatch the WhatsApp message yourself from that page.'],
                ['nav' => 'followups', 'text' => 'Run Engine Now queues due reminder jobs and refreshes the morning briefing. It is not the button that sends a result or a report card.'],
            ],
            'links' => [['label' => 'Admission Enquiries', 'href' => 'admission_enquiries.php'], ['label' => 'Admission Follow-ups', 'href' => 'admission_followups.php']],
            'followups' => ['How does WhatsApp sharing work?'],
        ],
        'promote' => [
            'keywords' => ['promote', 'promotion', 'next class', 'next standard', 'academic year', 'year end'],
            'title' => 'Promoting students',
            'text' => 'Promotion copies the students you tick into the next standard. It does not filter by pass or fail. Source records stay where they are, and roll numbers are kept.',
            'steps' => [
                ['nav' => 'promote', 'text' => 'Open Promote Students. Set the current academic year and the target academic year, then press Update View. The board lists Standards 1 to 10.'],
                ['nav' => 'promote', 'text' => 'Standard 10 is Graduated and cannot be promoted. For every other class, open the checklist. Every student starts ticked. Untick anyone who should stay behind, then press Confirm & Promote.'],
                ['nav' => 'promote', 'text' => 'The target class is created with the same roll numbers and blank marks. A promotion alert is added to the dashboard bell and links to that class in Results Matrix.'],
                ['nav' => 'archive', 'text' => 'Promotion is refused when the target year is locked in Records Archive. Revert promotion deletes the target class records for that year, standard, division, and semester. Use it only when that target class should be cleared.'],
            ],
            'links' => [['label' => 'Promote Students', 'href' => 'promote_students.php'], ['label' => 'Records Archive', 'href' => 'records_archive.php']],
            'followups' => ['What is the Records Archive?', 'What is the passing percentage?'],
        ],
        'bonafide' => [
            'keywords' => ['bonafide', 'bona fide', 'certificate', 'leaving certificate'],
            'title' => 'Bonafide Certificate',
            'text' => 'One certificate is prepared for one student. The school name, logo, and principal details are taken from school settings.',
            'steps' => [
                ['nav' => 'bonafide', 'text' => 'Open Bonafide Certificate under Records & Management. Search by roll number or name and choose the student.'],
                ['nav' => 'bonafide', 'text' => 'Check the details and the purpose. The purpose starts as Educational Purpose. The certificate serial is assigned for that academic year and is kept if it already belongs to this student.'],
                ['nav' => 'bonafide', 'text' => 'Download PDF or Print. The preview is the document that prints.'],
            ],
            'links' => [['label' => 'Bonafide Certificate', 'href' => 'bonafide_certificate.php']],
            'followups' => ['How do I print report cards?', 'How does WhatsApp sharing work?'],
        ],
        'archive' => [
            'keywords' => ['archive', 'records archive', 'vault', 'old records', 'previous year', 'backup'],
            'title' => 'Records Archive',
            'text' => 'Records Archive is the vault for a whole academic year. It keeps report cards, unit tests, bonafide certificates, and promotion history.',
            'steps' => [
                ['nav' => 'archive', 'text' => 'Open Records Archive. Each year card shows students, report-card marks, unit-test records, promotion snapshots, and the pass rate when marks exist. Open a card to work in that year.'],
                ['nav' => 'archive', 'text' => 'Archive / Store Year moves a year into the vault. A locked year cannot be used as an editable target for promotion. The active year is marked on the vault.'],
                ['nav' => 'archive', 'text' => 'Backup DB downloads a MySQL export of the database. Promote Students on this page jumps to the promotion board.'],
            ],
            'links' => [['label' => 'Records Archive', 'href' => 'records_archive.php'], ['label' => 'Promote Students', 'href' => 'promote_students.php']],
            'followups' => ['How do I promote students?'],
        ],
        'drive' => [
            'keywords' => ['drive', 'google drive', 'cloud', 'workspace', 'shared folder'],
            'title' => 'Drive Workspace',
            'text' => 'Drive Workspace stores school files in a Google Drive folder. A folder link is enough. Google sign-in is used only when OAuth is offered.',
            'steps' => [
                ['nav' => 'drive', 'text' => 'Open Drive Workspace under Cloud Workspace. Paste a folder link from drive.google.com or docs.google.com, or a valid folder ID, and save it.'],
                ['nav' => 'drive', 'text' => 'If Connect Google is shown, that sign-in is optional and uses the school Google account. You can upload files into the linked workspace and open what is already there.'],
                ['nav' => 'drive', 'text' => 'Unlink removes the folder connection from this system. It does not delete the folder in Google Drive.'],
            ],
            'links' => [['label' => 'Drive Workspace', 'href' => 'shared_workspace.php']],
            'followups' => ['How does WhatsApp sharing work?'],
        ],
        'security' => [
            'keywords' => ['password', 'change password', 'security', 'login', 'log in', 'sign in', 'logout', 'log out', 'forgot', 'reset password', 'account'],
            'title' => 'Account and security',
            'text' => 'Security is at the bottom of the sidebar, separate from the main menu.',
            'steps' => [
                ['nav' => 'security', 'text' => 'Open Security. The page is Account Security & Password. Use Account Password to replace your own admin password.'],
                ['nav' => 'security', 'text' => 'If you cannot sign in, use Forgot password? on the login page. That recovery page is not inside the dashboard.'],
                ['nav' => 'logout', 'text' => 'Log out is the last item in the sidebar. Confirm it in the dialog. Unsaved changes on the page you are leaving are lost. The assistant cannot log you out by itself.'],
            ],
            'links' => [['label' => 'Security', 'href' => 'change_password.php']],
            'followups' => ['Explain the system workflow'],
        ],
        'dashboard' => [
            'keywords' => ['dashboard', 'home page', 'notifications', 'bell', 'alerts', 'top performer', 'daily operations'],
            'title' => 'Using the dashboard',
            'text' => 'The dashboard is the Overview item at the top of the sidebar. It does not show a students, subjects, or pass-rate card. Ask for today’s figures if you need those totals.',
            'steps' => [
                ['nav' => 'dashboard', 'text' => 'The welcome band uses the school logo and the greeting in India time. If no report cards exist yet, the page offers Import Semester Excel.'],
                ['nav' => 'whatsapp', 'text' => 'The WhatsApp card shows Parents, Dispatched, In Queue, and open Enquiries. Quick Batch Dispatch opens WhatsApp Hub for report cards, unit-test slips, bonafide certificates, or school notices. Inbound AI Action Cards also open the hub.'],
                ['nav' => 'at_risk', 'text' => 'Daily operations has four cards. At-Risk Student Radar shows an Alerts count. That count is the simple active-year rule (FA at least 65% with SA under 35%, or that subject under the pass mark). The radar page uses the three-rule list and can show a different number.'],
                ['nav' => 'parent_sign', 'text' => 'Parent-Sign Morning Queue counts report cards and unit-test slips where parents_sign is still 0.'],
                ['nav' => 'substitute', 'text' => 'Substitute Teacher Desk counts substitution rows saved for today’s date.'],
                ['nav' => 'seating', 'text' => 'Exam Hall Seating Planner counts seating plans whose exam date is today or later.'],
                ['nav' => 'dashboard', 'text' => 'Promotion & System Alerts lists recent notices, with All, Unread, Unit Tests, and Promotions. The same bell is in the header. Class chips open that class in Results Matrix. Performance Overview shows Top Performer and Needs Support when results exist.'],
            ],
            'links' => [['label' => 'Dashboard', 'href' => 'dashboard.php'], ['label' => 'Results Matrix', 'href' => 'results.php']],
            'followups' => ['Show me today\'s stats', 'Explain the system workflow'],
        ],
    ];
}
