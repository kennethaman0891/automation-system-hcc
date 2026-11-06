<?php
/**
 * HCC WhatsApp Replica — Chat Actions API
 * POST /admin/api_whatsapp_chat_actions.php
 * Actions: pin, archive, mute, block, delete, mark_read, star, clear
 */
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

$chatId = (int)($input['chat_id'] ?? 0);
$action = $input['action'] ?? '';

if (!$chatId || !$action) {
    waJsonResponse(['success' => false, 'error' => 'chat_id and action required'], 400);
}

// Verify chat exists
$stmt = $db->prepare("SELECT id, wa_chat_id FROM whatsapp_chats WHERE id = ?");
$stmt->execute([$chatId]);
$chat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$chat) {
    waJsonResponse(['success' => false, 'error' => 'Chat not found'], 404);
}

try {
    switch ($action) {
        case 'pin':
            $pin = (bool)($input['pin'] ?? true);
            $db->prepare("UPDATE whatsapp_chats SET is_pinned = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$pin ? 1 : 0, $chatId]);
            waJsonResponse(['success' => true, 'pinned' => $pin]);
            break;
            
        case 'archive':
            $archive = (bool)($input['archive'] ?? true);
            $db->prepare("UPDATE whatsapp_chats SET is_archived = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$archive ? 1 : 0, $chatId]);
            waJsonResponse(['success' => true, 'archived' => $archive]);
            break;
            
        case 'mute':
            $mute = (bool)($input['mute'] ?? true);
            $duration = (int)($input['duration'] ?? 0); // 0 = forever, else hours
            $muteUntil = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration * 3600) : null;
            $db->prepare("UPDATE whatsapp_chats SET is_muted = ?, mute_until = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$mute ? 1 : 0, $muteUntil, $chatId]);
            waJsonResponse(['success' => true, 'muted' => $mute, 'mute_until' => $muteUntil]);
            break;
            
        case 'mark_read':
            $db->prepare("UPDATE whatsapp_chats SET unread_count = 0 WHERE id = ?")->execute([$chatId]);
            $db->prepare("UPDATE whatsapp_messages SET status = 'read' WHERE chat_id = ? AND from_me = 0 AND status != 'read'")->execute([$chatId]);
            waJsonResponse(['success' => true]);
            break;
            
        case 'clear':
            // Delete all messages in chat (but keep chat)
            $db->prepare("DELETE FROM whatsapp_messages WHERE chat_id = ?")->execute([$chatId]);
            $db->prepare("UPDATE whatsapp_chats SET last_message_id = NULL, unread_count = 0 WHERE id = ?")->execute([$chatId]);
            waJsonResponse(['success' => true]);
            break;
            
        case 'delete':
            // Delete chat and all messages
            $db->prepare("DELETE FROM whatsapp_chats WHERE id = ?")->execute([$chatId]);
            // Messages cascade deleted via FK
            waJsonResponse(['success' => true]);
            break;
            
        case 'star':
            $msgId = (int)($input['msg_id'] ?? 0);
            $star = (bool)($input['star'] ?? true);
            if (!$msgId) {
                waJsonResponse(['success' => false, 'error' => 'msg_id required for star'], 400);
            }
            $db->prepare("UPDATE whatsapp_messages SET is_starred = ? WHERE id = ? AND chat_id = ?")
               ->execute([$star ? 1 : 0, $msgId, $chatId]);
            waJsonResponse(['success' => true, 'starred' => $star]);
            break;
            
        case 'delete_message':
            $msgId = (int)($input['msg_id'] ?? 0);
            $forEveryone = (bool)($input['for_everyone'] ?? false);
            if (!$msgId) {
                waJsonResponse(['success' => false, 'error' => 'msg_id required'], 400);
            }
            if ($forEveryone) {
                waEnsureRevokeSchema($db);
                $stmt = $db->prepare("SELECT id, wa_msg_id, from_me, status, msg_type, timestamp, body FROM whatsapp_messages WHERE id = ? AND chat_id = ?");
                $stmt->execute([$msgId, $chatId]);
                $msg = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$msg) {
                    waJsonResponse(['success' => false, 'error' => 'Message not found'], 404);
                }
                if (empty($msg['from_me'])) {
                    waJsonResponse(['success' => false, 'error' => 'Delete for everyone is only available for messages you sent'], 400);
                }
                $waMsgId = (string)($msg['wa_msg_id'] ?? '');
                if ($waMsgId === '' || str_starts_with($waMsgId, 'temp_')) {
                    waJsonResponse(['success' => false, 'error' => 'Message has no WhatsApp id yet; wait until it is sent'], 409);
                }
                if (($msg['msg_type'] ?? '') === 'revoked' || ($msg['status'] ?? '') === 'revoked') {
                    waJsonResponse(['success' => true, 'status' => 'revoked', 'message' => 'Already revoked']);
                }
                if (($msg['status'] ?? '') === 'revoke_pending') {
                    $qid = $db->prepare("SELECT id FROM whatsapp_outbound_queue WHERE chat_id = ? AND msg_type = 'revoke' AND status IN ('queued','processing') AND body LIKE ? ORDER BY id DESC LIMIT 1");
                    $qid->execute([$chatId, '%' . $waMsgId . '%']);
                    $existingQ = (int)$qid->fetchColumn();
                    waJsonResponse([
                        'success' => true,
                        'status' => 'revoke_pending',
                        'queue_id' => $existingQ ?: null,
                        'message' => 'Revoke already queued'
                    ]);
                }
                $windowCheck = waCanRevokeForEveryone((int)($msg['timestamp'] ?? 0));
                if (!$windowCheck['ok']) {
                    $reason = $windowCheck['reason'] ?? 'Outside delete-for-everyone window';
                    $db->prepare("UPDATE whatsapp_messages SET status = 'revoke_failed', metadata = JSON_SET(COALESCE(metadata, '{}'), '$.revoke_error', ?) WHERE id = ? AND chat_id = ?")
                       ->execute([$reason, $msgId, $chatId]);
                    waJsonResponse(['success' => false, 'status' => 'revoke_failed', 'error' => $reason], 422);
                }

                $payload = json_encode([
                    'wa_msg_id' => $waMsgId,
                    'msg_db_id' => (int)$msg['id'],
                    'timestamp' => (int)($msg['timestamp'] ?? 0),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE whatsapp_messages SET status = 'revoke_pending' WHERE id = ? AND chat_id = ?")
                       ->execute([$msgId, $chatId]);
                    $ins = $db->prepare("
                        INSERT INTO whatsapp_outbound_queue
                            (chat_id, msg_type, body, priority, attempts, max_attempts, status, dispatch_method,
                             origin, approved_by, approved_at, dispatched_by, related_doc_type)
                        VALUES (?, 'revoke', ?, 1, 0, 1, 'queued', 'manual',
                             'manual_ui', ?, NOW(), ?, 'revoke')
                    ");
                    $adminId = (int)($_SESSION['admin_id'] ?? 0);
                    $ins->execute([$chatId, $payload, $adminId > 0 ? $adminId : null, $adminId > 0 ? $adminId : null]);
                    $queueId = (int)$db->lastInsertId();
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }

                waJsonResponse([
                    'success' => true,
                    'status' => 'revoke_pending',
                    'queue_id' => $queueId,
                    'wa_msg_id' => $waMsgId,
                    'message' => 'Revoke queued for the WhatsApp bridge'
                ]);
            } else {
                $db->prepare("DELETE FROM whatsapp_messages WHERE id = ? AND chat_id = ?")->execute([$msgId, $chatId]);
                waJsonResponse(['success' => true]);
            }
            break;
            
        default:
            waJsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Exception $e) {
    waJsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
