<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/user_account.php';

$id = (int)($_SESSION['admin_id'] ?? 0);
$path = hccAvatarAbsolutePath($id);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit;
}

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
exit;
