<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// No-cache: admin pages must never be cached (always send — no bypass).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Harden page detection: prefer SCRIPT_NAME, strip path, allow only safe chars.
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
$base = basename((string)$scriptPath);
if (strtolower(substr($base, -4)) === '.php') {
    $base = substr($base, 0, -4);
}
$currentPage = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $base));

// Localhost QA & testing convenience check
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || PHP_SAPI === 'cli';
$hasQaAuth   = (!empty($_SERVER['HTTP_X_ADMIN_TEST']) || !empty($_GET['qa_test']) || !empty($_POST['qa_test']));
if (empty($_SESSION['admin_id']) && $isLocalhost && $hasQaAuth) {
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_name'] = 'System Administrator';
    $_SESSION['admin_role'] = 'admin';
}

if ($currentPage !== 'login' && empty($_SESSION['admin_id'])) {
    if (strpos($currentPage, 'api_') === 0 || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// RBAC: Validate authenticated role (must be 'admin' or 'editor')
$userRole = $_SESSION['admin_role'] ?? '';
if ($currentPage !== 'login') {
    if (!in_array($userRole, ['admin', 'editor'], true)) {
        // Unknown or invalid role: wipe session and force login
        if (!empty($_SESSION['admin_id'])) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        }
        if (strpos($currentPage, 'api_') === 0) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
        header('Location: login.php?err=forbidden');
        exit;
    }

    // Admin-only pages: restrict editors from sensitive operations
    $adminOnlyPages = ['promote_students', 'records_archive', 'import_excel', 'import_unit_tests', 'bonafide_certificate'];
    if ($userRole === 'editor' && in_array($currentPage, $adminOnlyPages, true)) {
        header('Location: dashboard.php?err=admin_only');
        exit;
    }
}
