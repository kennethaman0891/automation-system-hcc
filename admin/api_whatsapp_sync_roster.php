<?php
/**
 * HCC WhatsApp Replica — Class Roster Synchronization API
 * Synchronizes students and parent contact information from academic tables
 * into whatsapp_contacts and whatsapp_chats.
 * 
 * Supports:
 *   GET  /admin/api_whatsapp_sync_roster.php?class_key=...
 *   POST /admin/api_whatsapp_sync_roster.php { "class_key": "..." }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();

// Accept class_key from POST or JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
waVerifyCSRF($input);
$classKey = trim($input['class_key'] ?? '');

// If no class key provided, determine default from available classes
if ($classKey === '') {
    $availableClasses = rcClassOptions();
    if (!empty($availableClasses)) {
        $first = $availableClasses[0];
        $classKey = rcBuildClassKey(
            $first['academic_year'],
            $first['std'],
            $first['division'],
            $first['semester']
        );
    } else {
        $classKey = '2024-25_1_B_Sem 1';
    }
}

$cParsed = rcParseClassKey(urldecode($classKey));
$year     = $cParsed['academic_year'] ?: '2024-25';
$std      = $cParsed['std'] ?: '1';
$division = $cParsed['division'] ?: 'B';
$semester = $cParsed['semester'] ?: 'Sem 1';

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$classLabel = "STD {$romanStd} {$division} ({$year})";

// Fetch student records for this class
$students = rcAllStudents(['class_key' => $classKey]);
if (empty($students)) {
    // Try pipe-separated or underscore-separated fallback
    $altKey = str_replace(['_', '|'], ['|', '_'], $classKey);
    $students = rcAllStudents(['class_key' => $altKey]);
}
if (empty($students)) {
    // If still empty, fetch any students in std/division/year
    $fallbackStmt = $db->prepare("
        SELECT DISTINCT roll_no, student_name, parent_phone, std, division, academic_year, semester
        FROM report_card 
        WHERE academic_year = ? AND std = ? AND division = ?
        ORDER BY roll_no ASC
    ");
    $fallbackStmt->execute([$year, $std, $division]);
    $students = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Never invent fake demo student phones into live whatsapp_chats/contacts

$syncedCount = 0;
$createdChats = [];

// 1. Create / Ensure Class Broadcast Group Chat
$groupWaId = "class_" . preg_replace('/[^a-zA-Z0-9]/', '_', $classKey) . "@g.us";
$groupName = "{$classLabel} — Official Parents Broadcast";
$groupAvatar = "../assets/images/hcclogo.jpg";

$gStmt = $db->prepare("SELECT id FROM whatsapp_chats WHERE wa_chat_id = ?");
$gStmt->execute([$groupWaId]);
$existingGroup = $gStmt->fetch(PDO::FETCH_ASSOC);

if (!$existingGroup) {
    $insG = $db->prepare("
        INSERT INTO whatsapp_chats 
        (wa_chat_id, chat_type, name, avatar_url, is_pinned, unread_count, metadata, created_at, updated_at)
        VALUES (?, 'group', ?, ?, 1, 0, ?, NOW(), NOW())
    ");
    $groupMeta = json_encode([
        'is_class_group' => true,
        'class_key'      => $classKey,
        'class_label'    => $classLabel,
        'academic_year'  => $year,
        'std'            => $std,
        'division'       => $division
    ], JSON_UNESCAPED_UNICODE);
    $insG->execute([$groupWaId, $groupName, $groupAvatar, $groupMeta]);
    $groupId = (int)$db->lastInsertId();

    // Insert welcome system message in group
    waInsertMessage($db, [
        'wa_msg_id'     => 'sys_grp_' . time() . '_' . $groupId,
        'chat_id'       => $groupId,
        'from_me'       => true,
        'sender_id'     => 'principal@hcc.edu',
        'sender_name'   => 'Principal / Class Teacher',
        'sender_avatar' => '../assets/images/hcclogo.jpg',
        'msg_type'      => 'text',
        'body'          => "📌 Welcome to the official parent broadcast group for {$classLabel}. Academic circulars, exam schedules, and holiday announcements will be shared here.",
        'status'        => 'delivered',
        'timestamp'     => time() - 3600
    ]);
}

// 2. Sync Individual Student Parent Chats (strict phone validation; never fabricate phones)
foreach ($students as $stu) {
    $roll = (string)($stu['roll_no'] ?? '');
    $name = trim((string)($stu['student_name'] ?? "Student {$roll}"));
    
    // Normalize and strictly validate phone number
    $rawPhone = trim((string)($stu['parent_phone'] ?? ''));
    $vPhone = waValidateWhatsAppPhone($rawPhone);
    if (!$vPhone['ok']) {
        continue;
    }
    $cleanDigits = $vPhone['digits'];
    $phoneE164 = $vPhone['e164'];
    $waChatId = $cleanDigits . '@c.us';
    $avatarUrl = null;

    // Upsert into whatsapp_contacts
    $cStmt = $db->prepare("
        INSERT INTO whatsapp_contacts 
        (wa_id, phone_e164, name, push_name, avatar_url, source, linked_student_roll, linked_class_key, updated_at)
        VALUES (?, ?, ?, ?, ?, 'student_link', ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            push_name = VALUES(push_name),
            avatar_url = VALUES(avatar_url),
            linked_student_roll = VALUES(linked_student_roll),
            linked_class_key = VALUES(linked_class_key),
            updated_at = NOW()
    ");
    $cStmt->execute([$waChatId, $phoneE164, $name, $name, $avatarUrl, $roll, $classKey]);
    $contactId = (int)$db->lastInsertId();

    // Upsert into whatsapp_chats
    $metaJson = json_encode([
        'student_roll'  => $roll,
        'student_name'  => $name,
        'class_key'     => $classKey,
        'parent_phone'  => $phoneE164,
        'academic_year' => $year,
        'std'           => $std,
        'division'      => $division,
        'semester'      => $semester,
        'percentage'    => $stu['percentage'] ?? null,
        'grade'         => $stu['grade'] ?? null
    ], JSON_UNESCAPED_UNICODE);

    $chStmt = $db->prepare("SELECT id FROM whatsapp_chats WHERE wa_chat_id = ?");
    $chStmt->execute([$waChatId]);
    $existingChat = $chStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingChat) {
        $chatId = (int)$existingChat['id'];
        $uStmt = $db->prepare("
            UPDATE whatsapp_chats 
            SET name = ?, avatar_url = ?, metadata = ?, linked_student_roll = ?, linked_class_key = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $uStmt->execute([$name, $avatarUrl, $metaJson, $roll, $classKey, $chatId]);
    } else {
        $iStmt = $db->prepare("
            INSERT INTO whatsapp_chats 
            (wa_chat_id, chat_type, name, avatar_url, is_pinned, unread_count, metadata, linked_student_roll, linked_class_key, created_at, updated_at)
            VALUES (?, 'individual', ?, ?, 0, 0, ?, ?, ?, NOW(), NOW())
        ");
        $iStmt->execute([$waChatId, $name, $avatarUrl, $metaJson, $roll, $classKey]);
        $chatId = (int)$db->lastInsertId();

        // Seed initial greeting message so the conversation is active
        $initMsgId = 'seed_' . time() . '_' . $chatId;
        waInsertMessage($db, [
            'wa_msg_id'     => $initMsgId,
            'chat_id'       => $chatId,
            'from_me'       => true,
            'sender_id'     => 'principal@hcc.edu',
            'sender_name'   => 'HCC High School',
            'sender_avatar' => '../assets/images/hcclogo.jpg',
            'msg_type'      => 'text',
            'body'          => "Dear Parent, welcome to HCC WhatsApp Communication Portal for {$name} (Roll {$roll}, {$classLabel}). Academic reports and slips will be shared directly here.",
            'status'        => 'delivered',
            'timestamp'     => time() - 1800
        ]);
    }

    $syncedCount++;
}

// Fetch all updated chats to return to frontend
$fetchStmt = $db->prepare("
    SELECT 
        c.id, c.wa_chat_id, c.chat_type, c.name, c.avatar_url,
        c.is_archived, c.is_pinned, c.is_muted, c.unread_count, c.last_activity_at, c.metadata,
        m.wa_msg_id as last_msg_id, m.body as last_msg_body, m.msg_type as last_msg_type,
        m.from_me as last_msg_from_me, m.timestamp as last_msg_timestamp, m.status as last_msg_status
    FROM whatsapp_chats c
    LEFT JOIN whatsapp_messages m ON c.last_message_id = m.id
    WHERE c.is_archived = 0
    ORDER BY c.is_pinned DESC, c.last_activity_at DESC
    LIMIT 100
");
$fetchStmt->execute();
$chats = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($chats as &$chat) {
    if ($chat['metadata']) {
        $chat['metadata'] = json_decode($chat['metadata'], true);
    }
    $chat['last_message'] = $chat['last_msg_id'] ? [
        'wa_msg_id'  => $chat['last_msg_id'],
        'body'       => $chat['last_msg_body'],
        'type'       => $chat['last_msg_type'],
        'from_me'    => (bool)$chat['last_msg_from_me'],
        'timestamp'  => (int)$chat['last_msg_timestamp'],
        'status'     => $chat['last_msg_status']
    ] : null;
    unset($chat['last_msg_id'], $chat['last_msg_body'], $chat['last_msg_type'], $chat['last_msg_from_me'], $chat['last_msg_timestamp'], $chat['last_msg_status']);
}

waJsonResponse([
    'success'      => true,
    'class_key'    => $classKey,
    'class_label'  => $classLabel,
    'synced_count' => $syncedCount,
    'chats'        => $chats
]);
