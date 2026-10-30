<?php
session_start();

// CSRF: prefer POST with token, but keep GET working for sidebar links.
// If a token is present in the request, validate it; POST without a valid token is rejected.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    $sess = $_SESSION['csrf_token'] ?? '';
    if ($sent !== '' || $sess !== '') {
        if ($sent === '' || $sess === '' || !hash_equals((string)$sess, (string)$sent)) {
            http_response_code(403);
            exit('Invalid logout request.');
        }
    }
} else {
    // GET fallback: if token supplied via query, validate when session has one.
    $sentGet = $_GET['csrf_token'] ?? '';
    $sessGet = $_SESSION['csrf_token'] ?? '';
    if ($sentGet !== '' && $sessGet !== '' && !hash_equals((string)$sessGet, (string)$sentGet)) {
        http_response_code(403);
        exit('Invalid logout request.');
    }
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params["path"] ?: '/',
            'domain' => $params["domain"],
            'secure' => $params["secure"],
            'httponly' => $params["httponly"],
            'samesite' => 'Strict',
        ]);
    } else {
        // PHP < 7.3 fallback: append samesite to path (no array options support).
        setcookie(session_name(), '', time() - 42000,
            ($params["path"] ?: '/') . '; samesite=Strict',
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    unset($_COOKIE[session_name()]);
}
session_destroy();
// Rotate ID after destroy on a fresh session (prevents fixation on shared browsers).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
    session_regenerate_id(true);
}
$_SESSION = [];
header('Location: login.php');
exit;
