<?php
/**
 * HCC WhatsApp Replica — Session Management API
 * GET  /admin/api_whatsapp_session.php?action=status|qr
 * POST /admin/api_whatsapp_session.php?action=logout|refresh_qr
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

$auth = waRequireBridgeOrAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');
$rawInput = json_decode(file_get_contents('php://input'), true);
if (!is_array($rawInput)) {
    $rawInput = $_POST;
}

if (in_array($action, ['sync', 'qr_push', 'poll', 'ensure', 'refresh_qr', 'logout'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
    if (($auth['via'] ?? '') !== 'bridge_secret') {
        waVerifyCSRF(is_array($rawInput) ? $rawInput : null);
    }
}

function waEnsureSessionIngestColumns(PDO $db): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $cols = $db->query('SHOW COLUMNS FROM whatsapp_sessions')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }
    if (!in_array('last_ingest_at', $cols, true)) {
        $db->exec('ALTER TABLE whatsapp_sessions ADD COLUMN last_ingest_at DATETIME NULL DEFAULT NULL');
    }
    if (!in_array('last_skip_note', $cols, true)) {
        $db->exec('ALTER TABLE whatsapp_sessions ADD COLUMN last_skip_note VARCHAR(255) NULL DEFAULT NULL');
    }
    if (!in_array('is_syncing', $cols, true)) {
        $db->exec('ALTER TABLE whatsapp_sessions ADD COLUMN is_syncing TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!in_array('dom_health_status', $cols, true)) {
        $db->exec('ALTER TABLE whatsapp_sessions ADD COLUMN dom_health_status VARCHAR(64) NULL DEFAULT NULL');
    }
    if (!in_array('dom_health_checked_at', $cols, true)) {
        $db->exec('ALTER TABLE whatsapp_sessions ADD COLUMN dom_health_checked_at DATETIME NULL DEFAULT NULL');
    }
    $ready = true;
}

waEnsureSessionIngestColumns($db);

switch ($action) {
    case 'status':
        $stmt = $db->prepare("SELECT * FROM whatsapp_sessions WHERE session_name = 'default'");
        $stmt->execute();
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastPollTs = !empty($session['last_poll_at']) ? strtotime($session['last_poll_at']) : 0;
        $isBridgeRunning = (time() - $lastPollTs) < 90;
        $qrExpiresTs = !empty($session['qr_expires_at']) ? strtotime($session['qr_expires_at']) : 0;
        $isQrValid = !empty($session['qr_code']) && ($qrExpiresTs > time());
        $isSyncing = !empty($session['is_syncing']);

        waJsonResponse([
            'success' => true,
            'bridge_running' => $isBridgeRunning,
            'is_syncing' => $isSyncing,
            'session' => $session ? [
                'id' => (int)$session['id'],
                'is_connected' => (bool)$session['is_connected'],
                'connected_at' => $session['connected_at'],
                'last_poll_at' => $session['last_poll_at'],
                'phone_number' => $session['phone_number'],
                'device_name' => $session['device_name'],
                'platform' => $session['platform'],
                'qr_expires_at' => $session['qr_expires_at'],
                'qr_valid' => $isQrValid,
                'bridge_running' => $isBridgeRunning,
                'is_syncing' => $isSyncing,
                'last_ingest_at' => $session['last_ingest_at'] ?? null,
                'last_skip_note' => $session['last_skip_note'] ?? null,
                'dom_health_status' => $session['dom_health_status'] ?? null,
                'dom_health_checked_at' => $session['dom_health_checked_at'] ?? null
            ] : null
        ]);
        break;
        
    case 'qr':
        $stmt = $db->prepare("SELECT qr_code, qr_expires_at, last_poll_at, is_syncing FROM whatsapp_sessions WHERE session_name = 'default'");
        $stmt->execute();
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastPollTs = !empty($session['last_poll_at']) ? strtotime($session['last_poll_at']) : 0;
        $isBridgeRunning = (time() - $lastPollTs) < 90;
        $qrExpiresTs = !empty($session['qr_expires_at']) ? strtotime($session['qr_expires_at']) : 0;
        $isQrValid = !empty($session['qr_code']) && ($qrExpiresTs > time());
        $isSyncing = !empty($session['is_syncing']);

        if ($session && $isQrValid) {
            waJsonResponse([
                'success' => true,
                'bridge_running' => $isBridgeRunning,
                'is_syncing' => $isSyncing,
                'qr_code' => $session['qr_code'],
                'expires_at' => $session['qr_expires_at']
            ]);
        } else {
            // Do not write a refresh flag here. The page polls every few seconds,
            // and each flag made the bridge click the QR and replace a code that
            // was still being scanned. The bridge publishes the next code itself.
            waJsonResponse([
                'success' => false,
                'bridge_running' => $isBridgeRunning,
                'is_syncing' => $isSyncing,
                'error' => $isSyncing ? 'QR scanned. Synchronizing chats with phone...' : ($isBridgeRunning ? 'Waiting for a QR code.' : 'WhatsApp bridge is offline.'),
                'action' => $isSyncing ? 'syncing' : 'wait'
            ], $isBridgeRunning ? 404 : 503);
        }
        break;
        
    case 'sync':
        // Persist bridge session + QR state (POSTed as JSON by the Puppeteer bridge)
        $payload = $rawInput;

        $toMysql = function ($v) {
            if (!$v) return null;
            $ts = strtotime((string)$v);
            return $ts ? date('Y-m-d H:i:s', $ts) : null;
        };

        $domHealth = isset($payload['dom_health_status']) && $payload['dom_health_status'] !== ''
            ? mb_substr((string)$payload['dom_health_status'], 0, 64)
            : null;
        $domHealthAt = $domHealth !== null ? ($toMysql($payload['dom_health_checked_at'] ?? null) ?? date('Y-m-d H:i:s')) : null;

        $sql = "INSERT INTO whatsapp_sessions
                (session_name, user_data_dir, qr_code, qr_expires_at, is_connected, connected_at,
                 last_poll_at, phone_number, device_name, platform, cookies, local_storage, dom_health_status, dom_health_checked_at, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
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
                    dom_health_status = COALESCE(VALUES(dom_health_status), dom_health_status),
                    dom_health_checked_at = COALESCE(VALUES(dom_health_checked_at), dom_health_checked_at),
                    is_syncing = 0,
                    updated_at = NOW()";

        $stmt = $db->prepare($sql);
        try {
            $stmt->execute([
                $payload['session_name'] ?? 'default',
                !empty($payload['user_data_dir']) ? mb_substr((string)$payload['user_data_dir'], 0, 500) : null,
                $payload['qr_code'] ?? null,
                $toMysql($payload['qr_expires_at'] ?? null),
                !empty($payload['is_connected']) ? 1 : 0,
                $toMysql($payload['connected_at'] ?? null),
                $toMysql($payload['last_poll_at'] ?? null) ?? date('Y-m-d H:i:s'),
                !empty($payload['phone_number']) ? mb_substr((string)$payload['phone_number'], 0, 50) : null,
                !empty($payload['device_name']) ? mb_substr((string)$payload['device_name'], 0, 255) : null,
                !empty($payload['platform']) ? mb_substr((string)$payload['platform'], 0, 100) : null,
                isset($payload['cookies']) ? json_encode($payload['cookies']) : null,
                isset($payload['local_storage']) ? json_encode($payload['local_storage']) : null,
                $domHealth,
                $domHealthAt,
            ]);
        } catch (Throwable $e) {
            waJsonResponse([
                'success' => false,
                'error' => 'Session sync failed: ' . $e->getMessage()
            ], 500);
        }

        waJsonResponse(['success' => true]);
        break;

    case 'qr_push':
        // Small update while the QR is on screen or the moment a scan succeeds.
        // Does not rewrite cookies or localStorage.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        }
        $qrPayload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($qrPayload)) {
            $qrPayload = [];
        }
        $qrCode = array_key_exists('qr_code', $qrPayload) ? $qrPayload['qr_code'] : null;
        if ($qrCode === '') {
            $qrCode = null;
        }
        $qrExpires = null;
        if (!empty($qrPayload['qr_expires_at'])) {
            $qrTs = strtotime((string)$qrPayload['qr_expires_at']);
            if ($qrTs) {
                $qrExpires = date('Y-m-d H:i:s', $qrTs);
            }
        }
        $qrConnected = !empty($qrPayload['is_connected']) ? 1 : 0;
        $qrSyncing = !empty($qrPayload['is_syncing']) ? 1 : 0;
        $qrConnectedAt = null;
        if ($qrConnected && !empty($qrPayload['connected_at'])) {
            $qrConnTs = strtotime((string)$qrPayload['connected_at']);
            if ($qrConnTs) {
                $qrConnectedAt = date('Y-m-d H:i:s', $qrConnTs);
            }
        }
        $qrPoll = date('Y-m-d H:i:s');
        $qrStmt = $db->prepare("INSERT INTO whatsapp_sessions
                (session_name, qr_code, qr_expires_at, is_connected, connected_at, last_poll_at, is_syncing, created_at, updated_at)
                VALUES ('default', ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    qr_code = VALUES(qr_code),
                    qr_expires_at = VALUES(qr_expires_at),
                    is_connected = VALUES(is_connected),
                    connected_at = IF(VALUES(is_connected) = 1, COALESCE(connected_at, VALUES(connected_at)), connected_at),
                    last_poll_at = VALUES(last_poll_at),
                    is_syncing = VALUES(is_syncing),
                    updated_at = NOW()");
        try {
            $qrStmt->execute([$qrCode, $qrExpires, $qrConnected, $qrConnectedAt, $qrPoll, $qrSyncing]);
        } catch (Throwable $e) {
            waJsonResponse(['success' => false, 'error' => 'QR update failed'], 500);
        }
        waJsonResponse(['success' => true]);
        break;

    case 'poll':
        $payload = $rawInput;
        $lastPoll = !empty($payload['last_poll_at']) ? date('Y-m-d H:i:s', strtotime((string)$payload['last_poll_at'])) : date('Y-m-d H:i:s');
        $sets = ['last_poll_at = ?', 'updated_at = NOW()'];
        $params = [$lastPoll];
        if (!empty($payload['last_ingest_at'])) {
            $ingestTs = strtotime((string)$payload['last_ingest_at']);
            if ($ingestTs) {
                $sets[] = 'last_ingest_at = ?';
                $params[] = date('Y-m-d H:i:s', $ingestTs);
            }
        }
        if (array_key_exists('last_skip_note', $payload)) {
            $note = $payload['last_skip_note'];
            $sets[] = 'last_skip_note = ?';
            $params[] = ($note === null || $note === '') ? null : mb_substr((string)$note, 0, 255);
        }
        if (isset($payload['is_syncing'])) {
            $sets[] = 'is_syncing = ?';
            $params[] = !empty($payload['is_syncing']) ? 1 : 0;
        }
        if (!empty($payload['dom_health_status'])) {
            $sets[] = 'dom_health_status = ?';
            $params[] = mb_substr((string)$payload['dom_health_status'], 0, 64);
            $sets[] = 'dom_health_checked_at = ?';
            $checkedTs = !empty($payload['dom_health_checked_at']) ? strtotime((string)$payload['dom_health_checked_at']) : false;
            $params[] = $checkedTs ? date('Y-m-d H:i:s', $checkedTs) : date('Y-m-d H:i:s');
        }
        $db->prepare('UPDATE whatsapp_sessions SET ' . implode(', ', $sets) . " WHERE session_name = 'default'")
           ->execute($params);
        waJsonResponse(['success' => true]);
        break;

    case 'ensure':
        // The page asks for this when the heartbeat is stale. The bridge
        // process exits and launchd starts it again. Apache must not spawn
        // Chrome itself (it runs as a different user than the LaunchAgent).
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        }
        $ensureStmt = $db->prepare("SELECT last_poll_at FROM whatsapp_sessions WHERE session_name = 'default'");
        $ensureStmt->execute();
        $ensureRow = $ensureStmt->fetch(PDO::FETCH_ASSOC);
        $ensurePollTs = !empty($ensureRow['last_poll_at']) ? strtotime($ensureRow['last_poll_at']) : 0;
        $ensureRunning = (time() - $ensurePollTs) < 90;
        if ($ensureRunning) {
            waJsonResponse(['success' => true, 'restarting' => false, 'bridge_running' => true]);
        }
        $ensureTmp = getenv('WHATSAPP_IPC_DIR');
        if (!$ensureTmp || !is_dir($ensureTmp)) {
            $ensureTmp = (DIRECTORY_SEPARATOR !== '\\' && is_dir('/tmp')) ? '/tmp' : sys_get_temp_dir();
        }
        $ensureFlag = rtrim($ensureTmp, '/\\') . DIRECTORY_SEPARATOR . 'whatsapp_bridge_restart_default';
        if (!is_file($ensureFlag) || (time() - (int)@filemtime($ensureFlag)) >= 45) {
            @file_put_contents($ensureFlag, (string)time());
        }
        waJsonResponse([
            'success' => true,
            'restarting' => true,
            'bridge_running' => false,
            'message' => 'WhatsApp bridge is starting. The QR will appear here.'
        ]);
        break;

    case 'refresh_qr':
        $tmpDir = getenv('WHATSAPP_IPC_DIR');
        if (!$tmpDir || !is_dir($tmpDir)) {
            $tmpDir = (DIRECTORY_SEPARATOR !== '\\' && is_dir('/tmp')) ? '/tmp' : sys_get_temp_dir();
        }
        $sep = DIRECTORY_SEPARATOR;
        // Bridge watches whatsapp_qr_refresh_default (and sessionName). PHP session_id flags were never read.
        @file_put_contents(rtrim($tmpDir, '/\\') . $sep . 'whatsapp_qr_refresh_default', time());
        
        waJsonResponse([
            'success' => true,
            'message' => 'QR refresh requested. Poll /status for new QR.'
        ]);
        break;
        
    case 'logout':
        // Signal bridge to logout
        $tmpDir = getenv('WHATSAPP_IPC_DIR');
        if (!$tmpDir || !is_dir($tmpDir)) {
            $tmpDir = (DIRECTORY_SEPARATOR !== '\\' && is_dir('/tmp')) ? '/tmp' : sys_get_temp_dir();
        }
        $sep = DIRECTORY_SEPARATOR;
        // Bridge watches whatsapp_logout_default (and sessionName). Do not write PHP session_id orphans.
        @file_put_contents(rtrim($tmpDir, '/\\') . $sep . 'whatsapp_logout_default', time());
        
        // Update DB
        $db->prepare("UPDATE whatsapp_sessions SET is_connected = 0, qr_code = NULL WHERE session_name = 'default'")->execute();
        
        waJsonResponse(['success' => true, 'message' => 'Logout requested']);
        break;
        
    default:
        waJsonResponse(['success' => false, 'error' => 'Invalid action'], 400);
}
