<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireBridgeOrAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    waJsonResponse(['success' => false, 'error' => 'id required'], 400);
}

$stmt = $db->prepare("SELECT id, wa_chat_id, name, chat_type FROM whatsapp_chats WHERE id = ?");
$stmt->execute([$id]);
$chat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chat) {
    waJsonResponse(['success' => false, 'error' => 'Chat not found'], 404);
}

waJsonResponse(['success' => true, 'data' => $chat]);
