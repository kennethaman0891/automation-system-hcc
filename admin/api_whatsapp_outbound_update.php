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
// MySQL TIMESTAMP rejects ISO-8601 (2026-11-05T06:55:02.444Z). A bad clock must
// not fail the status update — that left sent messages stuck on "processing".
$normalizeSqlTime = static function ($value): ?string {
    if ($value === null || $value === '') return null;
    $ts = strtotime((string)$value);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
};
foreach (['sent_at', 'delivered_at', 'read_at'] as $timeColumn) {
    if (!isset($input[$timeColumn])) continue;
    $sqlTime = $normalizeSqlTime($input[$timeColumn]);
    if ($sqlTime === null) continue;
    $updates[] = $timeColumn . ' = ?';
    $params[] = $sqlTime;
}

if (!$updates) {
    waJsonResponse(['success' => false, 'error' => 'No fields to update'], 400);
}

$params[] = $id;
$db->prepare("UPDATE whatsapp_outbound_queue SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($params);

waJsonResponse(['success' => true]);
