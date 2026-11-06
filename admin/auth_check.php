<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Bridge secret: Puppeteer bridge may call selected APIs with X-Bridge-Secret.
// This is machine-to-machine auth for ingest/queue/session — NOT a send path.
// Send endpoints (api_whatsapp_send / templates send) reject bridge via waRequireAuth().
if (empty($GLOBALS['WA_AUTH_VIA_BRIDGE'])) {
    $__waBridgeSecret = defined('WHATSAPP_BRIDGE_SECRET') ? (string)WHATSAPP_BRIDGE_SECRET : '';
    $__hdr = (string)($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
    if ($__waBridgeSecret !== '' && strlen($__waBridgeSecret) >= 16 && $__hdr !== '' && hash_equals($__waBridgeSecret, $__hdr)) {
        $GLOBALS['WA_AUTH_VIA_BRIDGE'] = true;
        $_SESSION['admin_name'] = 'whatsapp-bridge';
        $_SESSION['admin_role'] = 'bridge';
        // Do not set admin_id — empty(0) is true in PHP; bridge uses the GLOBAL flag.
    }
}

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

$isBridge = !empty($GLOBALS['WA_AUTH_VIA_BRIDGE']);
// Bridge may only hit API endpoints, never HTML admin pages.
if ($isBridge && strpos($currentPage, 'api_') !== 0) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($currentPage !== 'login' && empty($_SESSION['admin_id']) && !$isBridge) {
    if (strpos($currentPage, 'api_') === 0 || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Location: login.php');
    exit;
}

// RBAC: Validate authenticated role (must be 'admin' or 'editor'); bridge role OK for api_* only.
$userRole = $_SESSION['admin_role'] ?? '';
if ($currentPage !== 'login') {
    $okRoles = $isBridge ? ['bridge'] : ['admin', 'editor'];
    if (!in_array($userRole, $okRoles, true)) {
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

    $adminOnlyPages = ['promote_students', 'records_archive', 'import_excel', 'import_unit_tests', 'bonafide_certificate'];
    if ($userRole === 'editor' && in_array($currentPage, $adminOnlyPages, true)) {
        header('Location: dashboard.php?err=admin_only');
        exit;
    }
}
