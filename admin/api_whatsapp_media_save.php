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

$waMediaKey = $input['wa_media_key'] ?? '';
$msgId = (int)($input['msg_id'] ?? 0);
$localPath = $input['local_path'] ?? '';
$mimeType = $input['mime_type'] ?? '';
$fileSize = (int)($input['file_size'] ?? 0);
$thumbnailPath = $input['thumbnail_path'] ?? '';

if (!$waMediaKey || !$msgId || !$localPath) {
    waJsonResponse(['success' => false, 'error' => 'wa_media_key, msg_id, local_path required'], 400);
}

$stmt = $db->prepare("
    INSERT INTO whatsapp_media (wa_media_key, msg_id, local_path, mime_type, file_size, thumbnail_path, download_status)
    VALUES (?, ?, ?, ?, ?, ?, 'downloaded')
    ON DUPLICATE KEY UPDATE
        msg_id = VALUES(msg_id),
        local_path = VALUES(local_path),
        mime_type = VALUES(mime_type),
        file_size = VALUES(file_size),
        thumbnail_path = VALUES(thumbnail_path),
        download_status = 'downloaded'
");
$stmt->execute([$waMediaKey, $msgId, $localPath, $mimeType, $fileSize, $thumbnailPath]);

waJsonResponse(['success' => true]);
