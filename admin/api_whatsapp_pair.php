<?php
/**
 * HCC WhatsApp Bridge — Phone-number pairing code API
 *
 * POST action=request  phone=9198XXXXXXXX  → asks the bridge to generate a pairing code
 * GET  action=get                          → returns the generated 8-character code
 * POST action=cancel                       → clears the request/result
 *
 * The bridge (node whatsapp-bridge.js) watches /tmp/whatsapp_pair_request and
 * writes the resulting code to /tmp/whatsapp_pair_code.txt.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

// Cross-platform IPC temp directory matching Node.js bridge getBridgeTempDir()
$tmpDir = getenv('WHATSAPP_IPC_DIR');
if (!$tmpDir || !is_dir($tmpDir)) {
    if (DIRECTORY_SEPARATOR !== '\\' && is_dir('/tmp')) {
        $tmpDir = '/tmp';
    } else {
        $tmpDir = sys_get_temp_dir();
    }
}
$reqFile  = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'whatsapp_pair_request';
$codeFile = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'whatsapp_pair_code.txt';

$action = $_GET['action'] ?? 'get';
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

switch ($action) {
    case 'request':
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        if (strlen($phone) < 8) {
            waJsonResponse(['success' => false, 'error' => 'Enter the WhatsApp number (10 digits for +91, or include the country code).'], 400);
        }
        @unlink($codeFile);
        if (file_put_contents($reqFile, $phone) === false) {
            waJsonResponse(['success' => false, 'error' => 'Could not write pairing request.'], 500);
        }
        waJsonResponse(['success' => true, 'phone' => $phone, 'message' => 'Pairing code requested. Poll action=get.']);
        break;

    case 'cancel':
        @unlink($reqFile);
        @unlink($codeFile);
        waJsonResponse(['success' => true]);
        break;

    case 'get':
    default:
        if (!is_file($codeFile)) {
            waJsonResponse(['success' => false, 'pending' => true, 'error' => 'Waiting for the bridge to generate the code.']);
        }
        $raw = trim((string)file_get_contents($codeFile));
        if ($raw === '') {
            waJsonResponse(['success' => false, 'pending' => true, 'error' => 'Waiting for the bridge to generate the code.']);
        }
        if (str_starts_with($raw, 'ERROR:')) {
            waJsonResponse(['success' => false, 'error' => trim(substr($raw, 6))], 502);
        }
        waJsonResponse(['success' => true, 'code' => $raw]);
        break;
}
