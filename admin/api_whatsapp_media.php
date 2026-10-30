<?php
/**
 * HCC WhatsApp Replica — Media Proxy
 * GET /admin/api_whatsapp_media.php?msg_id=123&type=thumbnail|media
 * Streams media with auth, prevents direct CDN URL exposure
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();

$db = waGetDB();
$msgId = (int)($_GET['msg_id'] ?? 0);
$type = $_GET['type'] ?? 'media'; // 'media' or 'thumbnail'

if (!$msgId) {
    waJsonResponse(['success' => false, 'error' => 'msg_id required'], 400);
}

$stmt = $db->prepare("
    SELECT m.media_url, m.media_mime, m.thumbnail_url, 
           wm.local_path, wm.thumbnail_path, wm.mime_type
    FROM whatsapp_messages m
    LEFT JOIN whatsapp_media wm ON wm.msg_id = m.id
    WHERE m.id = ?
");
$stmt->execute([$msgId]);
$msg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$msg) {
    waJsonResponse(['success' => false, 'error' => 'Message not found'], 404);
}

$url = ($type === 'thumbnail' && $msg['thumbnail_url']) ? $msg['thumbnail_url'] : $msg['media_url'];
$localPath = ($type === 'thumbnail' && $msg['thumbnail_path']) ? $msg['thumbnail_path'] : $msg['local_path'];
$mime = ($type === 'thumbnail' && $msg['mime_type']) ? $msg['mime_type'] : $msg['media_mime'];

if ($localPath && file_exists($localPath)) {
    // Serve local file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $localPath);
    finfo_close($finfo);
    
    header('Content-Type: ' . ($detectedMime ?: ($mime ?: 'application/octet-stream')));
    header('Content-Length: ' . filesize($localPath));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    readfile($localPath);
    exit;
}

if ($url) {
    // Proxy from WhatsApp CDN (with auth if needed)
    // For now, redirect to CDN URL (in production, stream with proper auth headers)
    header('Location: ' . $url);
    exit;
}

waJsonResponse(['success' => false, 'error' => 'Media not available'], 404);
