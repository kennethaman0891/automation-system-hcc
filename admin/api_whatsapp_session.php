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
        
    case 'refresh_qr':
        // Signal bridge to generate new QR (via file flag or Redis)
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
