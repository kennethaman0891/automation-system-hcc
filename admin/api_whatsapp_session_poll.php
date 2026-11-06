<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireBridgeOrAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$lastPollAt = $input['last_poll_at'] ?? date('Y-m-d H:i:s');

$db->prepare("UPDATE whatsapp_sessions SET last_poll_at = ? WHERE session_name = 'default'")
   ->execute([$lastPollAt]);

waJsonResponse(['success' => true]);
