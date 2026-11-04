<?php
/**
 * HCC WhatsApp Replica — Inbound Message Ingestion
 * Receives messages from Puppeteer bridge via HMAC-authenticated POST
 * POST /admin/api_whatsapp_ingest.php
 * Headers: X-WA-Signature: <hmac_sha256(payload, secret)>
 * Body: JSON array of messages
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Verify HMAC signature
$secret = defined('WHATSAPP_BRIDGE_SECRET') ? WHATSAPP_BRIDGE_SECRET : 'change-this-secret-in-config';
$signature = $_SERVER['HTTP_X_WA_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');

if (!$signature || !waValidateHMAC($payload, $signature, $secret)) {
    waJsonResponse(['success' => false, 'error' => 'Invalid signature'], 401);
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    waJsonResponse(['success' => false, 'error' => 'Expected JSON array'], 400);
}

$db = waGetDB();
$inserted = 0;
$errors = [];

$db->beginTransaction();
try {
    foreach ($data as $index => $msg) {
        try {
            // Validate required fields
            if (empty($msg['wa_msg_id']) || empty($msg['wa_chat_id']) || !isset($msg['from_me']) || empty($msg['timestamp'])) {
                $errors[] = "Message $index: missing required fields";
                continue;
            }
            
            // Get or create chat
            $chatId = waGetOrCreateChat($db, $msg['wa_chat_id'], $msg['chat_type'] ?? 'individual', $msg['chat_name'] ?? null, $msg['chat_avatar'] ?? null);
            
            // Get or create sender contact (for inbound messages)
            $senderId = null;
            if (!$msg['from_me'] && !empty($msg['sender_wa_id'])) {
                $senderId = waGetOrCreateContact(
                    $db,
                    $msg['sender_wa_id'],
                    $msg['sender_phone'] ?? null,
                    $msg['sender_name'] ?? null,
                    $msg['sender_push_name'] ?? null,
                    $msg['sender_avatar'] ?? null
                );
            }
            
            // Resolve quoted message ID
            $quotedMsgId = null;
            if (!empty($msg['quoted_wa_msg_id'])) {
                $stmt = $db->prepare("SELECT id FROM whatsapp_messages WHERE wa_msg_id = ?");
                $stmt->execute([$msg['quoted_wa_msg_id']]);
                $quotedMsgId = $stmt->fetchColumn();
            }
            
            // Insert message
            waInsertMessage($db, [
                'wa_msg_id' => $msg['wa_msg_id'],
                'chat_id' => $chatId,
                'from_me' => (bool)$msg['from_me'],
                'sender_id' => $msg['sender_wa_id'] ?? null,
                'sender_name' => $msg['sender_name'] ?? null,
                'sender_avatar' => $msg['sender_avatar'] ?? null,
                'msg_type' => $msg['msg_type'] ?? 'text',
                'body' => $msg['body'] ?? null,
                'media_url' => $msg['media_url'] ?? null,
                'media_mime' => $msg['media_mime'] ?? null,
                'media_size' => $msg['media_size'] ?? null,
                'media_width' => $msg['media_width'] ?? null,
                'media_height' => $msg['media_height'] ?? null,
                'media_duration' => $msg['media_duration'] ?? null,
                'thumbnail_url' => $msg['thumbnail_url'] ?? null,
                'quoted_msg_id' => $quotedMsgId,
                'is_forwarded' => $msg['is_forwarded'] ?? false,
                'is_starred' => $msg['is_starred'] ?? false,
                'status' => $msg['from_me'] ? 'sent' : 'delivered',
                'timestamp' => (int)$msg['timestamp'],
                'metadata' => $msg['metadata'] ?? null
            ]);
            $inserted++;

            // If inbound message appears to be an admission enquiry, flag chat
            if (empty($msg['from_me']) && !empty($msg['body'])) {
                $bodyLower = strtolower($msg['body']);
                $admissionKeywords = ['admission', 'admissions', 'fee', 'fees', 'nursery', 'lkg', 'ukg', 'standard', 'grade', 'eligibility', 'timings', 'transport', 'campus visit'];
                foreach ($admissionKeywords as $kw) {
                    if (str_contains($bodyLower, $kw)) {
                        $db->prepare("UPDATE whatsapp_chats SET is_admission_enquiry = 1 WHERE id = ?")->execute([$chatId]);
                        break;
                    }
                }
            }
            
        } catch (Exception $e) {
            $errors[] = "Message $index: " . $e->getMessage();
        }
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    waJsonResponse(['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()], 500);
}

waJsonResponse([
    'success' => true,
    'inserted' => $inserted,
    'errors' => $errors
]);
