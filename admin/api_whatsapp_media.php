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

if ($localPath) {
    $baseStorage = realpath(__DIR__ . '/../storage/whatsapp');
    $resolvedLocalPath = ($baseStorage !== false && strpos((string)$localPath, "\0") === false)
        ? realpath((string)$localPath)
        : false;

    if ($resolvedLocalPath !== false) {
        if ($baseStorage === false || !str_starts_with($resolvedLocalPath, $baseStorage . DIRECTORY_SEPARATOR) || !is_file($resolvedLocalPath)) {
            waJsonResponse(['success' => false, 'error' => 'Forbidden media path'], 403);
        }
        if (is_readable($resolvedLocalPath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo ? (string)finfo_file($finfo, $resolvedLocalPath) : '';
            if ($finfo) {
                finfo_close($finfo);
            }

            $rawMime = $detectedMime ?: ($mime ?: 'application/octet-stream');
            $effectiveMime = strtolower(trim(explode(';', str_replace(["\r", "\n", "\0"], '', (string)$rawMime), 2)[0]));

            $safeInlineMimes = [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'audio/mpeg',
                'audio/ogg',
                'video/mp4',
                'application/pdf',
            ];

            header('X-Content-Type-Options: nosniff');
            if (in_array($effectiveMime, $safeInlineMimes, true)) {
                header('Content-Type: ' . $effectiveMime);
                header('Content-Disposition: inline');
            } else {
                header('Content-Type: application/octet-stream');
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($resolvedLocalPath)) ?: 'media.bin';
                header('Content-Disposition: attachment; filename="' . $safeName . '"');
            }
            header('Content-Length: ' . filesize($resolvedLocalPath));
            header('Cache-Control: private, max-age=3600');
            readfile($resolvedLocalPath);
            exit;
        }
    }
}

if ($url) {
    $cleanUrl = trim((string)$url);
    if (preg_match('/[\r\n\0]/', $cleanUrl) || !preg_match('#^https://#i', $cleanUrl) || !filter_var($cleanUrl, FILTER_VALIDATE_URL)) {
        waJsonResponse(['success' => false, 'error' => 'Invalid media redirect URL'], 400);
    }
    // Proxy from WhatsApp CDN (with auth if needed)
    // For now, redirect to CDN URL (in production, stream with proper auth headers)
    header('Location: ' . $cleanUrl);
    exit;
}

waJsonResponse(['success' => false, 'error' => 'Media not available'], 404);
