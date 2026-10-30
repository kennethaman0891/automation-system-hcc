<?php
/**
 * HCC WhatsApp Replica — Outbound Send API
 * POST /admin/api_whatsapp_send.php
 * Queues message for Puppeteer bridge to send
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $input = $_POST; // Support form-data too
}

$chatId = (int)($input['chat_id'] ?? 0);
$contactId = !empty($input['contact_id']) ? (int)$input['contact_id'] : null;
$msgType = $input['msg_type'] ?? 'text';
$body = $input['body'] ?? '';
$mediaPath = $input['media_path'] ?? null;
$mediaMime = $input['media_mime'] ?? null;
$caption = $input['caption'] ?? null;
$priority = min(max(1, (int)($input['priority'] ?? 5)), 10);
$dispatchMethod = $input['dispatch_method'] ?? 'auto';
$relatedStudentRoll = $input['related_student_roll'] ?? null;
$relatedClassKey = $input['related_class_key'] ?? null;
$relatedDocType = $input['related_doc_type'] ?? null;

if (!$chatId) {
    waJsonResponse(['success' => false, 'error' => 'chat_id required'], 400);
}

if ($msgType === 'text' && !$body) {
    waJsonResponse(['success' => false, 'error' => 'body required for text messages'], 400);
}

if (in_array($msgType, ['image','video','audio','document']) && !$mediaPath) {
    waJsonResponse(['success' => false, 'error' => 'media_path required for media messages'], 400);
}

// Verify chat exists
$stmt = $db->prepare("SELECT id, wa_chat_id FROM whatsapp_chats WHERE id = ?");
$stmt->execute([$chatId]);
$chat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$chat) {
    waJsonResponse(['success' => false, 'error' => 'Chat not found'], 404);
}

// Insert into outbound queue
$stmt = $db->prepare("
    INSERT INTO whatsapp_outbound_queue 
    (chat_id, contact_id, msg_type, body, media_path, media_mime, caption, 
     priority, dispatch_method, dispatched_by, related_student_roll, related_class_key, related_doc_type)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $chatId, $contactId, $msgType, $body, $mediaPath, $mediaMime, $caption,
    $priority, $dispatchMethod, $_SESSION['admin_id'],
    $relatedStudentRoll, $relatedClassKey, $relatedDocType
]);

$queueId = (int)$db->lastInsertId();

// Also create a pending message record for immediate UI feedback
$tempMsgId = 'temp_' . $queueId . '_' . time();
waInsertMessage($db, [
    'wa_msg_id' => $tempMsgId,
    'chat_id' => $chatId,
    'from_me' => true,
    'sender_id' => null,
    'sender_name' => 'You',
    'sender_avatar' => null,
    'msg_type' => $msgType,
    'body' => $msgType === 'text' ? $body : ($caption ?? $body),
    'media_url' => $mediaPath,
    'media_mime' => $mediaMime,
    'caption' => $caption,
    'status' => 'pending',
    'timestamp' => time(),
    'metadata' => ['queue_id' => $queueId, 'temp' => true]
]);

waJsonResponse([
    'success' => true,
    'queue_id' => $queueId,
    'temp_msg_id' => $tempMsgId,
    'message' => 'Message queued for delivery'
]);
