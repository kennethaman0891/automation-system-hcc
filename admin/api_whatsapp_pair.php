<?php
/**
 * HCC WhatsApp Bridge — Phone-number pairing code API
 *
 * POST action=request  phone=9198XXXXXXXX  → asks the bridge to generate a pairing code
 * GET  action=get                          → returns the generated 8-character code
 * POST action=cancel                       → clears the request/result
 *
 * The bridge (node whatsapp-bridge.js) watches whatsapp_pair_request in the
 * shared IPC directory and writes the code to whatsapp_pair_code.txt there.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

// Same directory whatsapp-bridge.js getBridgeTempDir() watches.
$tmpDir = waBridgeIpcDir();
$reqFile  = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'whatsapp_pair_request';
$codeFile = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'whatsapp_pair_code.txt';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}
if (in_array($action, ['request', 'cancel'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
    waVerifyCSRF($body);
}

switch ($action) {
    case 'request':
        $db = waGetDB();
        $stmt = $db->prepare("SELECT last_poll_at FROM whatsapp_sessions WHERE session_name = 'default'");
        $stmt->execute();
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastPollTs = !empty($sess['last_poll_at']) ? strtotime($sess['last_poll_at']) : 0;
        if ((time() - $lastPollTs) >= 90) {
            waJsonResponse(['success' => false, 'error' => 'WhatsApp bridge is not running. Start the bridge first.'], 503);
        }

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
