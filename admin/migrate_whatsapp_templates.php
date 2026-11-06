<?php
/**
 * Create + seed whatsapp_message_templates.
 * CLI: php admin/migrate_whatsapp_templates.php
 * Web: requires admin session (admin session required).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth_check.php';
    waRequireAuth();
}

$db = getDB();
$sqlFile = dirname(__DIR__) . '/data/migrations/create_whatsapp_message_templates.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    fwrite(STDERR, "Missing migration SQL\n");
    exit(1);
}
$db->exec($sql);

$starters = [
    ['admission_enquiry_reply', 'Admission enquiry reply', 'admission', 'both',
     "Namaste {parent_name} ji,\n\nThank you for your interest in Hindustani Covenant Church High School for {student_name} ({class}).\n\nAdmissions for the current session are open. Please visit the school office between 09:00 AM and 03:30 PM with the required documents, or reply here with your questions.\n\nWarm regards,\nHCC High School Admission Desk"],
    ['followup_reminder', 'Follow-up reminder', 'followup', 'personal',
     "Namaste {parent_name} ji,\n\nGentle reminder regarding the admission follow-up for {student_name} ({class}) scheduled around {date}. Please let us know a convenient time for your visit.\n\n— HCC High School"],
    ['fee_reminder', 'Fee reminder', 'fees', 'both',
     "Dear {parent_name},\n\nThis is a polite reminder that the school fee for {student_name} ({class}) is due. Kindly clear the dues at the school office or via the approved payment channel by {date}.\n\nFor any clarification, reply to this message.\n\nAccounts Desk\nHCC High School"],
    ['holiday_notice', 'Holiday notice', 'announcement', 'group',
     "Dear Parents,\n\nPlease note that the school will remain closed on {date} on account of a holiday.\n\nRegular classes for {class} will resume on the next working day.\n\nPrincipal\nHCC High School"],
    ['ptm_meeting_notice', 'PTM / meeting notice', 'meeting', 'both',
     "Dear {parent_name},\n\nYou are invited to the Parent–Teacher Meeting for {student_name} ({class}) on {date}. Kindly confirm your attendance by replying to this message.\n\nClass Teacher\nHCC High School"],
    ['exam_timetable_notice', 'Exam / timetable notice', 'exams', 'both',
     "Dear Parents,\n\nThe examination / timetable notice for {class} is ready. Please note the schedule dated {date} and ensure {student_name} is prepared with the required stationery.\n\nExam Cell\nHCC High School"],
    ['document_certificate_ready', 'Document / certificate ready', 'documents', 'personal',
     "Dear {parent_name},\n\nThe requested document/certificate for {student_name} ({class}) is ready for collection from the school office. Please visit on any working day between 09:00 AM and 03:30 PM (reference date: {date}).\n\nOffice\nHCC High School"],
    ['general_announcement', 'General announcement', 'announcement', 'group',
     "Dear Parents / Guardians,\n\n{announcement}\n\nDate: {date}\nClass: {class}\n\nPrincipal\nHCC High School"],
    ['birthday_greeting', 'Birthday / greeting', 'greeting', 'personal',
     "Dear {parent_name},\n\nWishing {student_name} ({class}) a very happy birthday! May this year bring good health, joy, and success.\n\nWarm wishes from all of us at HCC High School.\n\n— HCC Family"],
];

$ins = $db->prepare("
    INSERT INTO whatsapp_message_templates
      (slug, title, category, scope, body, placeholders, is_starter, created_by)
    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ON DUPLICATE KEY UPDATE
      title = VALUES(title),
      category = VALUES(category),
      scope = VALUES(scope),
      body = IF(is_starter = 1 AND updated_by IS NULL, VALUES(body), body),
      placeholders = VALUES(placeholders)
");

$ph = json_encode(['parent_name','student_name','class','date','announcement'], JSON_UNESCAPED_UNICODE);
$uid = (int)($_SESSION['admin_id'] ?? 0) ?: null;

foreach ($starters as $row) {
    $ins->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $ph, $uid]);
}

$count = (int)$db->query('SELECT COUNT(*) FROM whatsapp_message_templates')->fetchColumn();
if (PHP_SAPI === 'cli') {
    echo "OK whatsapp_message_templates rows={$count}\n";
} else {
    waJsonResponse(['success' => true, 'count' => $count]);
}
