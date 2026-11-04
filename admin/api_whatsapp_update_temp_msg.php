<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$tempMsgId = $input['temp_msg_id'] ?? '';
$waMsgId = $input['wa_msg_id'] ?? '';
$status = $input['status'] ?? 'sent';

if (!$tempMsgId) {
    waJsonResponse(['success' => false, 'error' => 'temp_msg_id required'], 400);
}

// wa_msg_id is only known when the message actually went out; failure updates omit it.
if ($waMsgId) {
    $db->prepare("
        UPDATE whatsapp_messages
        SET wa_msg_id = ?, status = ?, metadata = JSON_SET(COALESCE(metadata, '{}'), '$.temp', false)
        WHERE wa_msg_id = ?
    ")->execute([$waMsgId, $status, $tempMsgId]);
} else {
    $db->prepare("
        UPDATE whatsapp_messages
        SET status = ?, metadata = JSON_SET(COALESCE(metadata, '{}'), '$.temp', false)
        WHERE wa_msg_id = ?
    ")->execute([$status, $tempMsgId]);
}

waJsonResponse(['success' => true]);
