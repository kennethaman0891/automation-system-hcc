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
                // Mark as revoked (would need bridge to send revoke)
                $db->prepare("UPDATE whatsapp_messages SET msg_type = 'revoked', body = 'This message was deleted' WHERE id = ? AND chat_id = ?")
                   ->execute([$msgId, $chatId]);
            } else {
                $db->prepare("DELETE FROM whatsapp_messages WHERE id = ? AND chat_id = ?")->execute([$msgId, $chatId]);
            }
            waJsonResponse(['success' => true]);
            break;
            
        default:
            waJsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Exception $e) {
    waJsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
