<?php
/**
 * HCC WhatsApp Replica — Server-Sent Events Endpoint
 * GET /admin/api_whatsapp_events.php
 * Keeps connection open, pushes new messages, status updates, typing indicators
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();

// Release session lock so concurrent requests from the same session are not blocked
session_write_close();

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Set time limit to 0 (unlimited) for long-running connection
set_time_limit(0);

// Disable output buffering for immediate streaming
while (ob_get_level() > 0) {
    ob_end_flush();
}

// Send initial connection event
echo "event: connected\n";
echo "data: {\"message\":\"SSE connected\"}\n\n";
flush();

$db = waGetDB();
$adminId = (int)$_SESSION['admin_id'];
$lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : 0;

// Polling-based SSE: poll database for new events
$pollInterval = 2; // seconds
$maxRuntime = 300; // 5 minutes max per connection
$startTime = time();
$lastHeartbeat = time();

function sendEvent(string $event, array $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

function sendHeartbeat(): void {
    echo ": heartbeat\n\n";
    flush();
}

while (time() - $startTime < $maxRuntime) {
    if (connection_aborted()) {
        break;
    }

    // Check for new messages (inbound + outbound status updates)
    $stmt = $db->prepare("
        SELECT m.id, m.wa_msg_id, m.chat_id, m.from_me, m.sender_id, m.sender_name, m.sender_avatar,
               m.msg_type, m.body, m.media_url, m.media_mime, m.media_size, m.media_width, m.media_height,
               m.media_duration, m.thumbnail_url, m.quoted_msg_id, m.is_forwarded, m.is_starred,
               m.status, m.timestamp, m.received_at, m.metadata,
               c.wa_chat_id, c.name as chat_name, c.chat_type
        FROM whatsapp_messages m
        JOIN whatsapp_chats c ON m.chat_id = c.id
        WHERE m.id > ?
        ORDER BY m.id ASC
        LIMIT 50
    ");
    $stmt->execute([$lastEventId]);
    $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($newMessages) {
        foreach ($newMessages as $msg) {
            $lastEventId = max($lastEventId, (int)$msg['id']);
            
            $payload = [
                'id' => (int)$msg['id'],
                'wa_msg_id' => $msg['wa_msg_id'],
                'chat_id' => (int)$msg['chat_id'],
                'wa_chat_id' => $msg['wa_chat_id'],
                'chat_name' => $msg['chat_name'],
                'chat_type' => $msg['chat_type'],
                'from_me' => (bool)$msg['from_me'],
                'sender_id' => $msg['sender_id'],
                'sender_name' => $msg['sender_name'],
                'sender_avatar' => $msg['sender_avatar'],
                'msg_type' => $msg['msg_type'],
                'body' => $msg['body'],
                'media_url' => $msg['media_url'],
                'media_mime' => $msg['media_mime'],
                'media_size' => $msg['media_size'] ? (int)$msg['media_size'] : null,
                'media_width' => $msg['media_width'] ? (int)$msg['media_width'] : null,
                'media_height' => $msg['media_height'] ? (int)$msg['media_height'] : null,
                'media_duration' => $msg['media_duration'] ? (int)$msg['media_duration'] : null,
                'thumbnail_url' => $msg['thumbnail_url'],
                'quoted_msg_id' => $msg['quoted_msg_id'] ? (int)$msg['quoted_msg_id'] : null,
                'is_forwarded' => (bool)$msg['is_forwarded'],
                'is_starred' => (bool)$msg['is_starred'],
                'status' => $msg['status'],
                'timestamp' => (int)$msg['timestamp'],
                'received_at' => $msg['received_at'],
                'metadata' => $msg['metadata'] ? json_decode($msg['metadata'], true) : null
            ];
            
            sendEvent('message', $payload);
        }
    }
    
    // Check for chat updates (unread count, last activity, pin/archive/mute changes)
    $stmt = $db->prepare("
        SELECT id, wa_chat_id, unread_count, last_activity_at, is_pinned, is_archived, is_muted, mute_until
        FROM whatsapp_chats
        WHERE updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
        AND (is_pinned = 1 OR is_archived = 1 OR is_muted = 1 OR unread_count > 0)
        LIMIT 20
    ");
    $stmt->execute();
    $chatUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($chatUpdates) {
        foreach ($chatUpdates as $chat) {
            sendEvent('chat_update', [
                'id' => (int)$chat['id'],
                'wa_chat_id' => $chat['wa_chat_id'],
                'unread_count' => (int)$chat['unread_count'],
                'last_activity_at' => $chat['last_activity_at'],
                'is_pinned' => (bool)$chat['is_pinned'],
                'is_archived' => (bool)$chat['is_archived'],
                'is_muted' => (bool)$chat['is_muted'],
                'mute_until' => $chat['mute_until']
            ]);
        }
    }
    
    // Send heartbeat every 15 seconds
    if (time() - $lastHeartbeat >= 15) {
        sendHeartbeat();
        $lastHeartbeat = time();
    }
    
    sleep($pollInterval);
}

// Connection timeout
sendEvent('disconnect', ['reason' => 'timeout']);
