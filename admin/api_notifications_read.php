<?php
/* Mark all system notifications as read (shared header bell). POST + CSRF. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if (!empty($GLOBALS['WA_AUTH_VIA_BRIDGE'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCSRFToken()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}
try {
    rcMarkNotificationsRead();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not update notifications']);
}
