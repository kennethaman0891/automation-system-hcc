<?php
/**
 * HCC WhatsApp Bridge — Allowlist Filter API
 * Provides roster phone numbers and admission keywords to the Puppeteer bridge
 * for privacy-preserving chat filtering at ingestion time.
 * 
 * GET /admin/api_whatsapp_bridge_filter.php
 * Returns: { roster_numbers: [...], admission_keywords: [...], class_groups: [...] }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';

header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();

// Get all student parent phones from report_card
$studentPhones = [];
$stmt = $db->query("
    SELECT DISTINCT parent_phone, student_name, roll_no, academic_year, std, division, semester
    FROM report_card
    WHERE parent_phone IS NOT NULL AND parent_phone != ''
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $clean = preg_replace('/\D/', '', $row['parent_phone']);
    if (str_starts_with($clean, '0')) $clean = substr($clean, 1);
    if (strlen($clean) === 10) $clean = '91' . $clean;
    if (strlen($clean) >= 12) {
        $studentPhones[] = [
            'wa_id' => $clean . '@c.us',
            'phone_e164' => '+' . $clean,
            'name' => $row['student_name'],
            'roll_no' => $row['roll_no'],
            'class_key' => "{$row['academic_year']}|{$row['std']}|{$row['division']}|{$row['semester']}",
            'source' => 'student'
        ];
    }
}

// Get teacher phones (the table is optional — some installs don't have it)
$teacherPhones = [];
$hasTeacherTable = false;
try {
    $chk = $db->query("SHOW TABLES LIKE 'teacher_contacts'");
    $hasTeacherTable = (bool)$chk->fetchColumn();
} catch (Throwable $e) {
    $hasTeacherTable = false;
}

if ($hasTeacherTable) {
    $stmt = $db->query("
        SELECT DISTINCT phone, name, subject, employee_id
        FROM teacher_contacts
        WHERE phone IS NOT NULL AND phone != ''
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $clean = preg_replace('/\D/', '', $row['phone']);
        if (str_starts_with($clean, '0')) $clean = substr($clean, 1);
        if (strlen($clean) === 10) $clean = '91' . $clean;
        if (strlen($clean) >= 12) {
            $teacherPhones[] = [
                'wa_id' => $clean . '@c.us',
                'phone_e164' => '+' . $clean,
                'name' => $row['name'],
                'employee_id' => $row['employee_id'],
                'subject' => $row['subject'],
                'source' => 'teacher'
            ];
        }
    }
}

// Get whatsapp_contacts with student_link or teacher_link source
$linkedContacts = [];
$stmt = $db->query("
    SELECT wa_id, phone_e164, name, source, linked_student_roll, linked_class_key
    FROM whatsapp_contacts
    WHERE source IN ('student_link', 'teacher_link', 'direct_chat')
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $linkedContacts[] = $row;
}

// Get class group chat IDs from whatsapp_chats
$classGroups = [];
$stmt = $db->query("
    SELECT wa_chat_id, name, linked_class_key, metadata
    FROM whatsapp_chats
    WHERE chat_type IN ('group', 'broadcast')
    AND (metadata LIKE '%is_class_group%' OR linked_class_key IS NOT NULL)
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $classGroups[] = $row;
}

// Admission keywords (same as in api_whatsapp_ingest.php)
$admissionKeywords = [
    'admission', 'admissions', 'fee', 'fees', 'nursery', 'lkg', 'ukg',
    'standard', 'grade', 'eligibility', 'timings', 'transport', 'campus visit',
    'enquiry', 'inquiry', 'apply', 'application', 'form', 'prospectus',
    'age criteria', 'age limit', 'vacancy', 'seat', 'available', 'open'
];

waJsonResponse([
    'success' => true,
    'data' => [
        'roster_numbers' => array_merge($studentPhones, $teacherPhones),
        'linked_contacts' => $linkedContacts,
        'class_groups' => $classGroups,
        'admission_keywords' => $admissionKeywords,
        'generated_at' => date('c')
    ]
]);
