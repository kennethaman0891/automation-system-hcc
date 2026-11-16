<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

$auth = waRequireBridgeOrAuth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (($auth['via'] ?? '') !== 'bridge_secret') {
    if (($auth['admin_role'] ?? '') !== 'admin') {
        waJsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    }
    if (!verifyCSRFToken()) {
        waJsonResponse(['success' => false, 'error' => 'CSRF verification failed'], 403);
    }
}

$db = waGetDB();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$waMediaKey = trim((string)($input['wa_media_key'] ?? ''));
$msgId = (int)($input['msg_id'] ?? 0);
$localPath = trim((string)($input['local_path'] ?? ''));
$mimeType = str_replace(["\r", "\n", "\0"], '', trim((string)($input['mime_type'] ?? '')));
$fileSize = max(0, (int)($input['file_size'] ?? 0));
$thumbnailPath = trim((string)($input['thumbnail_path'] ?? ''));

if (!$waMediaKey || !$msgId || !$localPath) {
    waJsonResponse(['success' => false, 'error' => 'wa_media_key, msg_id, local_path required'], 400);
}

$storageBaseDir = __DIR__ . '/../storage/whatsapp';
if (!is_dir($storageBaseDir)) {
    @mkdir($storageBaseDir, 0755, true);
}
$baseStorage = realpath($storageBaseDir);
if ($baseStorage === false) {
    waJsonResponse(['success' => false, 'error' => 'Storage directory unavailable'], 500);
}

$resolveSafeWaPath = static function (string $path) use ($baseStorage): ?string {
    if ($path === '' || strpos($path, "\0") !== false) {
        return null;
    }
    $real = realpath($path);
    if ($real !== false) {
        if (is_dir($real) || !str_starts_with($real, $baseStorage . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }
    $base = basename($path);
    if ($base === '' || $base === '.' || $base === '..' || strpos($base, '/') !== false || strpos($base, '\\') !== false) {
        return null;
    }
    $realParent = realpath(dirname($path));
    if ($realParent === false) {
        return null;
    }
    if ($realParent !== $baseStorage && !str_starts_with($realParent, $baseStorage . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $realParent . DIRECTORY_SEPARATOR . $base;
};

$safeLocalPath = $resolveSafeWaPath($localPath);
if ($safeLocalPath === null) {
    waJsonResponse(['success' => false, 'error' => 'Forbidden: local_path must reside inside storage/whatsapp'], 403);
}

$safeThumbnailPath = '';
if ($thumbnailPath !== '') {
    $resolvedThumb = $resolveSafeWaPath($thumbnailPath);
    if ($resolvedThumb === null) {
        waJsonResponse(['success' => false, 'error' => 'Forbidden: thumbnail_path must reside inside storage/whatsapp'], 403);
    }
    $safeThumbnailPath = $resolvedThumb;
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
$stmt->execute([$waMediaKey, $msgId, $safeLocalPath, substr($mimeType, 0, 100), $fileSize, $safeThumbnailPath]);

waJsonResponse(['success' => true]);
