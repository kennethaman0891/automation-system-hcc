<?php
/**
 * HCC WhatsApp — Outbound Queue API (bridge dequeue)
 * GET /admin/api_whatsapp_outbound_queue.php?status=queued&limit=10
 *
 * Send-on-press: when the bridge asks for queued rows, anything older than
 * a short window is marked failed ("not sent — press Retry") and is NOT
 * returned. Only freshly pressed sends are delivered, exactly once.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireBridgeOrAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

switch ($action) {
    case 'list':
        $status = $_GET['status'] ?? 'queued';
        $limit = min(max(1, (int)($_GET['limit'] ?? 10)), 50);
        $id = (int)($_GET['id'] ?? 0);

        if ($id > 0) {
            $stmt = $db->prepare("
                SELECT q.*, c.wa_chat_id, c.name as chat_name
                FROM whatsapp_outbound_queue q
                JOIN whatsapp_chats c ON q.chat_id = c.id
                WHERE q.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
        } else {
            if ($status === 'queued') {
                // Mark stale queued/processing as failed — user must press Retry.
                waFailStaleOutbound($db, 120);
            }
            $dueClause = ($status === 'queued')
                ? ' AND (q.scheduled_at IS NULL OR q.scheduled_at <= NOW())'
                : '';
            // Fresh window: only rows whose created_at or scheduled_at is within the last 120s.
            $freshClause = ($status === 'queued')
                ? " AND GREATEST(q.created_at, COALESCE(q.scheduled_at, q.created_at)) >= (NOW() - INTERVAL 120 SECOND) AND q.origin = 'manual_ui' AND q.approved_by IS NOT NULL AND q.approved_by > 0"
                : '';
            $stmt = $db->prepare("
                SELECT q.*, c.wa_chat_id, c.name as chat_name
                FROM whatsapp_outbound_queue q
                JOIN whatsapp_chats c ON q.chat_id = c.id
                WHERE q.status = ?{$dueClause}{$freshClause}
                ORDER BY q.priority ASC, q.scheduled_at ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$status]);
        }
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tempStmt = $db->prepare("
            SELECT wa_msg_id
            FROM whatsapp_messages
            WHERE chat_id = ?
              AND (
                    wa_msg_id LIKE ? ESCAPE '!'
                 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.queue_id')) AS UNSIGNED) = ?
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        foreach ($items as &$item) {
            $item['priority'] = (int)$item['priority'];
            $item['attempts'] = (int)$item['attempts'];
            $item['max_attempts'] = (int)$item['max_attempts'];
            $item['temp_msg_id'] = null;
            $queueId = (int)$item['id'];
            try {
                $tempStmt->execute([(int)$item['chat_id'], 'temp_' . $queueId . '!_%', $queueId]);
                $tempId = $tempStmt->fetchColumn();
                if ($tempId !== false && $tempId !== null && $tempId !== '') {
                    $item['temp_msg_id'] = (string)$tempId;
                }
            } catch (Throwable $e) {
                $item['temp_msg_id'] = null;
            }
        }
        unset($item);

        waJsonResponse(['success' => true, 'data' => $items]);
        break;

    default:
        waJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}
