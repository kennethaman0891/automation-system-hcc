<?php
/**
 * HCC WhatsApp Replica — Parent Reply Simulator API
 * Simulates an incoming reply from a student's parent after receiving a school dispatch.
 * 
 * POST /admin/api_whatsapp_parent_simulator.php
 * Body: { "chat_id": 12, "student_name": "Aarav Sharma" }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$chatId = (int)($input['chat_id'] ?? 0);
$studentName = trim($input['student_name'] ?? '');

if (!$chatId) {
    waJsonResponse(['success' => false, 'error' => 'chat_id is required'], 400);
}

// Verify chat exists
$stmt = $db->prepare("SELECT id, wa_chat_id, name, avatar_url, unread_count, metadata FROM whatsapp_chats WHERE id = ?");
$stmt->execute([$chatId]);
$chat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chat) {
    waJsonResponse(['success' => false, 'error' => 'Chat not found'], 404);
}

if ($studentName === '') {
    $studentName = $chat['name'] ?: 'Student';
}

$parentReplies = [
    "Thank you respected Principal and Teachers! We have received {$studentName}'s academic report and appreciate your guidance. 🙏",
    "Noted with thanks. We have reviewed the progress report and are very happy with {$studentName}'s dedication this term.",
    "Received, thank you! We will ensure {$studentName} focuses on the recommended improvement areas.",
    "Thank you so much for the update. We have saved the official PDF document for our records.",
    "Thank you teacher. We are proud of {$studentName} and thankful to the entire HCC staff for their support! ✨"
];

$chosenBody = $parentReplies[array_rand($parentReplies)];
$waMsgId = 'sim_' . time() . '_' . bin2hex(random_bytes(4));
$timestamp = time();

$msgData = [
    'wa_msg_id'     => $waMsgId,
    'chat_id'       => $chatId,
    'from_me'       => false,
    'sender_id'     => $chat['wa_chat_id'],
    'sender_name'   => "Parent of {$studentName}",
    'sender_avatar' => $chat['avatar_url'] ?: "https://api.dicebear.com/7.x/initials/svg?seed=" . urlencode($studentName),
    'msg_type'      => 'text',
    'body'          => $chosenBody,
    'media_url'     => null,
    'media_mime'    => null,
    'status'        => 'delivered',
    'timestamp'     => $timestamp,
    'metadata'      => json_encode(['simulated' => true, 'student_name' => $studentName])
];

$msgId = waInsertMessage($db, $msgData);

// Increment unread count & update activity timestamp
$upStmt = $db->prepare("
    UPDATE whatsapp_chats 
    SET unread_count = unread_count + 1,
        last_message_id = ?,
        last_activity_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
");
$upStmt->execute([$msgId, $chatId]);

waJsonResponse([
    'success' => true,
    'message' => [
        'id'            => $msgId,
        'wa_msg_id'     => $waMsgId,
        'chat_id'       => $chatId,
        'from_me'       => false,
        'sender_id'     => $msgData['sender_id'],
        'sender_name'   => $msgData['sender_name'],
        'sender_avatar' => $msgData['sender_avatar'],
        'msg_type'      => 'text',
        'body'          => $chosenBody,
        'media_url'     => null,
        'media_mime'    => null,
        'status'        => 'delivered',
        'timestamp'     => $timestamp
    ]
]);
