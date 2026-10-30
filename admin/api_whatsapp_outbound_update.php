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

$id = (int)($input['id'] ?? 0);
if (!$id) {
    waJsonResponse(['success' => false, 'error' => 'id required'], 400);
}

$updates = [];
$params = [];

if (isset($input['status'])) { $updates[] = 'status = ?'; $params[] = $input['status']; }
if (isset($input['attempts'])) { $updates[] = 'attempts = ?'; $params[] = (int)$input['attempts']; }
if (isset($input['error_message'])) { $updates[] = 'error_message = ?'; $params[] = $input['error_message']; }
if (isset($input['sent_at'])) { $updates[] = 'sent_at = ?'; $params[] = $input['sent_at']; }
if (isset($input['delivered_at'])) { $updates[] = 'delivered_at = ?'; $params[] = $input['delivered_at']; }
if (isset($input['read_at'])) { $updates[] = 'read_at = ?'; $params[] = $input['read_at']; }

if (!$updates) {
    waJsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
}

$params[] = $id;
$db->prepare("UPDATE whatsapp_outbound_queue SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($params);

waJsonResponse(['success' => true]);
