<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

$auth = waRequireBridgeOrAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$db = waGetDB();
waEnsureRevokeSchema($db);
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (($auth['via'] ?? '') !== 'bridge_secret') {
    waVerifyCSRF(is_array($input) ? $input : null);
}

$id = (int)($input['id'] ?? 0);
if (!$id) {
    waJsonResponse(['success' => false, 'error' => 'id required'], 400);
}

$allowedStatuses = ['queued', 'processing', 'sent', 'delivered', 'read', 'failed', 'cancelled'];
$updates = [];
$params = [];

if (isset($input['status'])) {
    $newStatus = trim((string)$input['status']);
    if (!in_array($newStatus, $allowedStatuses, true)) {
        waJsonResponse(['success' => false, 'error' => 'Invalid outbound status'], 422);
    }
    $updates[] = 'status = ?';
    $params[] = $newStatus;
}
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

// When a revoke job finishes, mirror the real outcome onto the message row.
$q = $db->prepare("SELECT id, chat_id, msg_type, body, status, error_message FROM whatsapp_outbound_queue WHERE id = ?");
$q->execute([$id]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if ($row && ($row['msg_type'] ?? '') === 'revoke') {
    $meta = json_decode((string)($row['body'] ?? ''), true);
    $msgDbId = (int)($meta['msg_db_id'] ?? 0);
    $waMsgId = (string)($meta['wa_msg_id'] ?? '');
    $newStatus = (string)($row['status'] ?? '');
    if ($msgDbId > 0 || $waMsgId !== '') {
        if ($newStatus === 'sent') {
            if ($msgDbId > 0) {
                $db->prepare("
                    UPDATE whatsapp_messages
                    SET msg_type = 'revoked',
                        status = 'revoked',
                        body = 'This message was deleted',
                        metadata = JSON_SET(COALESCE(metadata, '{}'), '$.revoke_error', NULL, '$.revoked_at', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ'))
                    WHERE id = ? AND chat_id = ?
                ")->execute([$msgDbId, (int)$row['chat_id']]);
            } else {
                $db->prepare("
                    UPDATE whatsapp_messages
                    SET msg_type = 'revoked',
                        status = 'revoked',
                        body = 'This message was deleted',
                        metadata = JSON_SET(COALESCE(metadata, '{}'), '$.revoke_error', NULL, '$.revoked_at', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ'))
                    WHERE wa_msg_id = ? AND chat_id = ?
                ")->execute([$waMsgId, (int)$row['chat_id']]);
            }
        } elseif ($newStatus === 'failed') {
            $reason = (string)($row['error_message'] ?? 'Revoke failed');
            if ($msgDbId > 0) {
                $db->prepare("
                    UPDATE whatsapp_messages
                    SET status = 'revoke_failed',
                        metadata = JSON_SET(COALESCE(metadata, '{}'), '$.revoke_error', ?)
                    WHERE id = ? AND chat_id = ?
                ")->execute([$reason, $msgDbId, (int)$row['chat_id']]);
            } else {
                $db->prepare("
                    UPDATE whatsapp_messages
                    SET status = 'revoke_failed',
                        metadata = JSON_SET(COALESCE(metadata, '{}'), '$.revoke_error', ?)
                    WHERE wa_msg_id = ? AND chat_id = ?
                ")->execute([$reason, $waMsgId, (int)$row['chat_id']]);
            }
        }
    }
}

waJsonResponse(['success' => true]);
