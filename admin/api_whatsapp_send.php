<?php
/**
 * HCC WhatsApp — Outbound Send API (user press only)
 * POST /admin/api_whatsapp_send.php
 *
 * The ONLY production way to queue a WhatsApp send (besides template send
 * and revoke/retry on press). Requires a real admin session.
 * Supports: single chat_id, chat_ids[] (2s stagger), quoted_msg_id, retry of failed queue.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

$auth = waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;
waVerifyCSRF(is_array($input) ? $input : null);

$adminId = (int)($auth['admin_id'] ?? 0);
if ($adminId <= 0) {
    waJsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
}

// Retry a failed / cancelled row — only on explicit user press.
if (($input['action'] ?? '') === 'retry') {
    $queueId = (int)($input['queue_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM whatsapp_outbound_queue WHERE id = ?");
    $st->execute([$queueId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) waJsonResponse(['success' => false, 'error' => 'Queue item not found'], 404);
    $okStatuses = ['failed', 'cancelled'];
    if (!in_array($row['status'], $okStatuses, true)) {
        waJsonResponse(['success' => false, 'error' => 'Only failed or cancelled items can be retried'], 409);
    }
    $db->prepare("UPDATE whatsapp_outbound_queue
        SET status = 'queued', error_message = NULL, attempts = 0,
            origin = 'manual_ui', approved_by = ?, approved_at = NOW(),
            dispatch_method = 'manual',
            scheduled_at = NOW(), updated_at = NOW()
        WHERE id = ?")
       ->execute([$adminId, $queueId]);
    $db->prepare("UPDATE whatsapp_messages SET status = 'pending' WHERE chat_id = ? AND (
        wa_msg_id LIKE ? OR CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.queue_id')) AS UNSIGNED) = ?
    )")->execute([(int)$row['chat_id'], 'temp_' . $queueId . '_%', $queueId]);
    waJsonResponse(['success' => true, 'queue_id' => $queueId, 'status' => 'queued', 'origin' => 'manual_ui']);
}

$chatIds = [];
if (!empty($input['chat_ids']) && is_array($input['chat_ids'])) {
    foreach ($input['chat_ids'] as $cid) {
        $cid = (int)$cid;
        if ($cid > 0) $chatIds[] = $cid;
    }
}
if (!empty($input['chat_id'])) $chatIds[] = (int)$input['chat_id'];
$chatIds = array_values(array_unique($chatIds));

$msgType = $input['msg_type'] ?? 'text';
$body = $input['body'] ?? '';
$mediaPath = $input['media_path'] ?? null;
$mediaMime = $input['media_mime'] ?? null;
$caption = $input['caption'] ?? null;
$priority = min(max(1, (int)($input['priority'] ?? 5)), 10);
$relatedStudentRoll = $input['related_student_roll'] ?? null;
$relatedClassKey = $input['related_class_key'] ?? null;
$relatedDocType = $input['related_doc_type'] ?? null;
$quotedMsgId = !empty($input['quoted_msg_id']) ? (int)$input['quoted_msg_id'] : null;
if (!$quotedMsgId && !empty($input['reply_to']['id'])) {
    $quotedMsgId = (int)$input['reply_to']['id'];
}

if (!$chatIds) {
    waJsonResponse(['success' => false, 'error' => 'chat_id required'], 400);
}
if ($msgType === 'text' && !$body) {
    waJsonResponse(['success' => false, 'error' => 'body required for text messages'], 400);
}
if (in_array($msgType, ['image','video','audio','document'], true) && !$mediaPath) {
    waJsonResponse(['success' => false, 'error' => 'media_path required for media messages'], 400);
}

$queued = [];
foreach ($chatIds as $idx => $chatId) {
    $stmt = $db->prepare("SELECT id, wa_chat_id FROM whatsapp_chats WHERE id = ?");
    $stmt->execute([$chatId]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$chat) {
        waJsonResponse(['success' => false, 'error' => "Chat not found: {$chatId}"], 404);
    }
    // Stagger multi-chat press only; still one user action.
    $sched = count($chatIds) > 1 ? date('Y-m-d H:i:s', time() + ($idx * 2)) : null;
    $res = waEnqueueWhatsAppOutbound($db, [
        'chat_id' => $chatId,
        'msg_type' => $msgType,
        'body' => $body,
        'media_path' => $mediaPath,
        'media_mime' => $mediaMime,
        'caption' => $caption,
        'priority' => $priority,
        'dispatch_method' => 'manual',
        'origin' => 'manual_ui',
        'approved_by' => $adminId,
        'related_student_roll' => $relatedStudentRoll,
        'related_class_key' => $relatedClassKey,
        'related_doc_type' => $relatedDocType,
        'quoted_msg_id' => $quotedMsgId,
        'scheduled_at' => $sched,
        'dispatched_by' => $adminId,
    ]);
    $queued[] = $res + ['chat_id' => $chatId];
}

$first = $queued[0];
waJsonResponse([
    'success' => true,
    'queue_id' => $first['queue_id'],
    'temp_msg_id' => $first['temp_msg_id'],
    'queued' => $queued,
    'count' => count($queued),
    'message' => count($queued) > 1 ? 'Messages queued for broadcast' : 'Message queued for delivery'
]);
