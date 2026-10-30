<?php
/**
 * HCC WhatsApp Replica — Messages API
 * GET /admin/api_whatsapp_messages.php?chat_id=123&page=1&per_page=50&before_msg_id=
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();

$chatId = (int)($_GET['chat_id'] ?? 0);
if (!$chatId) {
    waJsonResponse(['success' => false, 'error' => 'chat_id required'], 400);
}

// Verify chat exists
$stmt = $db->prepare("SELECT id, wa_chat_id, name FROM whatsapp_chats WHERE id = ?");
$stmt->execute([$chatId]);
$chat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$chat) {
    waJsonResponse(['success' => false, 'error' => 'Chat not found'], 404);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(max(1, (int)($_GET['per_page'] ?? 50)), 100);
$beforeMsgId = $_GET['before_msg_id'] ?? null;

$where = ['chat_id = ?'];
$params = [$chatId];

if ($beforeMsgId) {
    $where[] = 'id < ?';
    $params[] = (int)$beforeMsgId;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM whatsapp_messages WHERE $whereSql";
$dataSql = "
    SELECT 
        id, wa_msg_id, from_me, sender_id, sender_name, sender_avatar,
        msg_type, body, media_url, media_mime, media_size, media_width, media_height,
        media_duration, thumbnail_url, quoted_msg_id, is_forwarded, is_starred,
        status, timestamp, received_at, acknowledged_at, metadata
    FROM whatsapp_messages
    WHERE $whereSql
    ORDER BY timestamp DESC, id DESC
    LIMIT ? OFFSET ?
";

$result = waPaginate($db, $countSql, $dataSql, $params, $page, $perPage);

// Reverse to chronological order (oldest first)
$result['items'] = array_reverse($result['items']);

// Enrich quoted messages
$quotedIds = array_filter(array_column($result['items'], 'quoted_msg_id'));
if ($quotedIds) {
    $placeholders = implode(',', array_fill(0, count($quotedIds), '?'));
    $stmt = $db->prepare("SELECT id, wa_msg_id, body, msg_type, from_me FROM whatsapp_messages WHERE id IN ($placeholders)");
    $stmt->execute(array_values($quotedIds));
    $quotedMap = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $quotedMap[$row['id']] = $row;
    }
    foreach ($result['items'] as &$msg) {
        if ($msg['quoted_msg_id'] && isset($quotedMap[$msg['quoted_msg_id']])) {
            $msg['quoted'] = $quotedMap[$msg['quoted_msg_id']];
        }
    }
}

// Format for frontend
foreach ($result['items'] as &$msg) {
    $msg['from_me'] = (bool)$msg['from_me'];
    $msg['is_forwarded'] = (bool)$msg['is_forwarded'];
    $msg['is_starred'] = (bool)$msg['is_starred'];
    $msg['timestamp'] = (int)$msg['timestamp'];
    if ($msg['media_size']) $msg['media_size'] = (int)$msg['media_size'];
    if ($msg['media_width']) $msg['media_width'] = (int)$msg['media_width'];
    if ($msg['media_height']) $msg['media_height'] = (int)$msg['media_height'];
    if ($msg['media_duration']) $msg['media_duration'] = (int)$msg['media_duration'];
    if ($msg['metadata']) $msg['metadata'] = json_decode($msg['metadata'], true);
}

waJsonResponse([
    'success' => true,
    'data' => $result['items'],
    'pagination' => $result['pagination'],
    'chat' => $chat
]);
