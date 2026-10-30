<?php
/**
 * HCC WhatsApp Replica — Outbound Queue API
 * GET  /admin/api_whatsapp_outbound_queue.php?status=queued&limit=10
 * POST /admin/api_whatsapp_outbound_update.php
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

switch ($action) {
    case 'list':
        $status = $_GET['status'] ?? 'queued';
        $limit = min(max(1, (int)($_GET['limit'] ?? 10)), 50);
        
        $stmt = $db->prepare("
            SELECT q.*, c.wa_chat_id, c.name as chat_name
            FROM whatsapp_outbound_queue q
            JOIN whatsapp_chats c ON q.chat_id = c.id
            WHERE q.status = ?
            ORDER BY q.priority ASC, q.scheduled_at ASC
            LIMIT ?
        ");
        $stmt->execute([$status, $limit]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as &$item) {
            $item['priority'] = (int)$item['priority'];
            $item['attempts'] = (int)$item['attempts'];
            $item['max_attempts'] = (int)$item['max_attempts'];
        }
        
        waJsonResponse(['success' => true, 'data' => $items]);
        break;
        
    default:
        waJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}
