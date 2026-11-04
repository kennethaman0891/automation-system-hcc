<?php
/**
 * HCC WhatsApp Replica — Session Management API
 * GET  /admin/api_whatsapp_session.php?action=status|qr
 * POST /admin/api_whatsapp_session.php?action=logout|refresh_qr
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');

switch ($action) {
    case 'status':
        $stmt = $db->prepare("SELECT * FROM whatsapp_sessions WHERE session_name = 'default'");
        $stmt->execute();
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        waJsonResponse([
            'success' => true,
            'session' => $session ? [
                'id' => (int)$session['id'],
                'is_connected' => (bool)$session['is_connected'],
                'connected_at' => $session['connected_at'],
                'last_poll_at' => $session['last_poll_at'],
                'phone_number' => $session['phone_number'],
                'device_name' => $session['device_name'],
                'platform' => $session['platform'],
                'qr_expires_at' => $session['qr_expires_at']
            ] : null
        ]);
        break;
        
    case 'qr':
        $stmt = $db->prepare("SELECT qr_code, qr_expires_at FROM whatsapp_sessions WHERE session_name = 'default'");
        $stmt->execute();
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session && $session['qr_code'] && $session['qr_expires_at'] && strtotime($session['qr_expires_at']) > time()) {
            waJsonResponse([
                'success' => true,
                'qr_code' => $session['qr_code'],
                'expires_at' => $session['qr_expires_at']
            ]);
        } else {
            waJsonResponse([
                'success' => false,
                'error' => 'No valid QR code. Request refresh.',
                'action' => 'refresh_qr'
            ], 404);
        }
        break;
        
    case 'sync':
        // Persist bridge session + QR state (POSTed as JSON by the Puppeteer bridge)
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $toMysql = function ($v) {
            if (!$v) return null;
            $ts = strtotime((string)$v);
            return $ts ? date('Y-m-d H:i:s', $ts) : null;
        };

        $sql = "INSERT INTO whatsapp_sessions
                (session_name, user_data_dir, qr_code, qr_expires_at, is_connected, connected_at,
                 last_poll_at, phone_number, device_name, platform, cookies, local_storage, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                    user_data_dir = VALUES(user_data_dir),
                    qr_code = VALUES(qr_code),
                    qr_expires_at = VALUES(qr_expires_at),
                    is_connected = VALUES(is_connected),
                    connected_at = VALUES(connected_at),
                    last_poll_at = VALUES(last_poll_at),
                    phone_number = VALUES(phone_number),
                    device_name = VALUES(device_name),
                    platform = VALUES(platform),
                    cookies = VALUES(cookies),
                    local_storage = VALUES(local_storage),
                    updated_at = NOW()";

        $stmt = $db->prepare($sql);
        try {
            $stmt->execute([
                $payload['session_name'] ?? 'default',
                $payload['user_data_dir'] ? mb_substr((string)$payload['user_data_dir'], 0, 500) : null,
                $payload['qr_code'] ?? null,
                $toMysql($payload['qr_expires_at'] ?? null),
                !empty($payload['is_connected']) ? 1 : 0,
                $toMysql($payload['connected_at'] ?? null),
                $toMysql($payload['last_poll_at'] ?? null) ?? date('Y-m-d H:i:s'),
                $payload['phone_number'] ? mb_substr((string)$payload['phone_number'], 0, 50) : null,
                $payload['device_name'] ? mb_substr((string)$payload['device_name'], 0, 255) : null,
                $payload['platform'] ? mb_substr((string)$payload['platform'], 0, 100) : null,
                isset($payload['cookies']) ? json_encode($payload['cookies']) : null,
                isset($payload['local_storage']) ? json_encode($payload['local_storage']) : null,
            ]);
        } catch (Throwable $e) {
            waJsonResponse([
                'success' => false,
                'error' => 'Session sync failed: ' . $e->getMessage()
            ], 500);
        }

        waJsonResponse(['success' => true]);
        break;

    case 'poll':
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $lastPoll = !empty($payload['last_poll_at']) ? date('Y-m-d H:i:s', strtotime((string)$payload['last_poll_at'])) : date('Y-m-d H:i:s');
        $db->prepare("UPDATE whatsapp_sessions SET last_poll_at = ?, updated_at = NOW() WHERE session_name = 'default'")
           ->execute([$lastPoll]);
        waJsonResponse(['success' => true]);
        break;

    case 'refresh_qr':
        $flagFile = '/tmp/whatsapp_qr_refresh_' . session_id();
        file_put_contents($flagFile, time());
        
        waJsonResponse([
            'success' => true,
            'message' => 'QR refresh requested. Poll /status for new QR.'
        ]);
        break;
        
    case 'logout':
        // Signal bridge to logout
        $flagFile = '/tmp/whatsapp_logout_' . session_id();
        file_put_contents($flagFile, time());
        
        // Update DB
        $db->prepare("UPDATE whatsapp_sessions SET is_connected = 0, qr_code = NULL WHERE session_name = 'default'")->execute();
        
        waJsonResponse(['success' => true, 'message' => 'Logout requested']);
        break;
        
    default:
        waJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}
