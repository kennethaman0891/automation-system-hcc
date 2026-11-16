<?php
/**
 * HCC WhatsApp Replica — Contacts API
 * GET /admin/api_whatsapp_contacts.php?search=&page=1&per_page=50
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(max(1, (int)($_GET['per_page'] ?? 50)), 100);
$search = trim($_GET['search'] ?? '');
$source = $_GET['source'] ?? '';

$where = ['1=1'];
$params = [];

if ($search !== '') {
    // Also look up any matching students from report_card who aren't yet in whatsapp_contacts
    try {
        $stuStmt = $db->prepare("
            SELECT DISTINCT roll_no, student_name, parent_phone, std, division, academic_year, semester
            FROM report_card
            WHERE student_name LIKE ? OR roll_no = ? OR parent_phone LIKE ?
            LIMIT 25
        ");
        $stuStmt->execute(["%$search%", $search, "%$search%"]);
        $matchingStudents = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($matchingStudents as $stu) {
            $roll = (string)($stu['roll_no'] ?? '');
            $name = trim((string)($stu['student_name'] ?? "Student {$roll}"));
            $rawPhone = trim((string)($stu['parent_phone'] ?? ''));
            $vPhone = waValidateWhatsAppPhone($rawPhone);
            if (!$vPhone['ok']) {
                continue;
            }
            $cleanDigits = $vPhone['digits'];
            $phoneE164 = $vPhone['e164'];
            $waId = $cleanDigits . '@c.us';
            $classKey = "{$stu['academic_year']}|{$stu['std']}|{$stu['division']}|{$stu['semester']}";
            $avatar = null;

            $insC = $db->prepare("
                INSERT INTO whatsapp_contacts 
                (wa_id, phone_e164, name, push_name, avatar_url, source, linked_student_roll, linked_class_key, updated_at)
                VALUES (?, ?, ?, ?, ?, 'student_link', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    name = COALESCE(NULLIF(VALUES(name), ''), name),
                    linked_student_roll = VALUES(linked_student_roll),
                    linked_class_key = VALUES(linked_class_key),
                    updated_at = NOW()
            ");
            $insC->execute([$waId, $phoneE164, $name, $name, $avatar, $roll, $classKey]);
        }
    } catch (Throwable $e) {}

    $where[] = '(name LIKE ? OR push_name LIKE ? OR phone_e164 LIKE ? OR wa_id LIKE ? OR linked_student_roll LIKE ? OR linked_class_key LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($source) {
    $where[] = 'source = ?';
    $params[] = $source;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM whatsapp_contacts WHERE $whereSql";
$dataSql = "
    SELECT id, wa_id, phone_e164, name, push_name, avatar_url, is_business, 
           is_blocked, is_saved, labels, last_seen_at, source, linked_student_roll, linked_class_key,
           created_at, updated_at
    FROM whatsapp_contacts
    WHERE $whereSql
    ORDER BY 
        CASE WHEN source = 'student_link' THEN 0 ELSE 1 END,
        name IS NULL, name ASC
    LIMIT ? OFFSET ?
";

$result = waPaginate($db, $countSql, $dataSql, $params, $page, $perPage);

foreach ($result['items'] as &$contact) {
    $contact['is_blocked'] = (bool)$contact['is_blocked'];
    $contact['is_saved'] = (bool)$contact['is_saved'];
    $contact['is_business'] = (bool)$contact['is_business'];
    if ($contact['labels']) $contact['labels'] = json_decode($contact['labels'], true);
}

waJsonResponse([
    'success' => true,
    'data' => $result['items'],
    'pagination' => $result['pagination']
]);
