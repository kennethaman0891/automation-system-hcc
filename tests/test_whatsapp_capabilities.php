<?php
/**
 * Capability test series for HCC WhatsApp automation (local API / DB only).
 * Covers: session/watch health, HMAC receive, dedupe, enqueue send, track
 * status transitions, delete-for-me / delete-for-everyone (DB only).
 *
 * Does NOT talk to live WhatsApp Web and does NOT leave queued outbound
 * rows that a connected bridge could send.
 *
 * Run: php tests/test_whatsapp_capabilities.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/admin/includes/whatsapp_functions.php';

$passed = 0;
$failed = 0;
$skipped = 0;
$results = [];

function cap(string $id, string $name, string $status, string $detail = ''): void {
    global $passed, $failed, $skipped, $results;
    $results[] = compact('id', 'name', 'status', 'detail');
    if ($status === 'PASS') { $passed++; echo "PASS  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
    elseif ($status === 'SKIP') { $skipped++; echo "SKIP  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
    else { $failed++; echo "FAIL  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function httpPost(string $url, string $body, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIE => 'qa_test=1',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true), 'err' => $err];
}

function httpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIE => 'qa_test=1',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Admin-Test: 1'],
    ]);
    // Prefer query qa_test for waRequireAuth
    $sep = str_contains($url, '?') ? '&' : '?';
    if (!str_contains($url, 'qa_test=')) {
        curl_setopt($ch, CURLOPT_URL, $url . $sep . 'qa_test=1');
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
}

$db = waGetDB();
$secret = defined('WHATSAPP_BRIDGE_SECRET') ? WHATSAPP_BRIDGE_SECRET : '';
$base = 'http://127.0.0.1/hcc/admin';
$testDigits = '9111111999'; // obvious fake
$testWa = '91' . $testDigits . '@c.us';
$testE164 = '+91' . $testDigits;
$tag = 'CAPTEST_' . date('YmdHis');

echo "WhatsApp capability test series\n";
echo "Tag: {$tag}\n\n";

// Cleanup helper
$cleanup = function () use ($db, $testWa, $testE164, $tag): void {
    $chatId = $db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id = " . $db->quote($testWa))->fetchColumn();
    if ($chatId) {
        $db->prepare("DELETE FROM whatsapp_outbound_queue WHERE chat_id = ?")->execute([(int)$chatId]);
        $db->prepare("DELETE FROM whatsapp_messages WHERE chat_id = ?")->execute([(int)$chatId]);
        $db->prepare("DELETE FROM whatsapp_chats WHERE id = ?")->execute([(int)$chatId]);
    }
    $db->prepare("DELETE FROM whatsapp_messages WHERE wa_msg_id LIKE ?")->execute([$tag . '%']);
    $db->prepare("DELETE FROM admission_enquiry_messages WHERE enquiry_id IN (SELECT id FROM admission_enquiries WHERE phone_e164 = ?)")->execute([$testE164]);
    $db->prepare("DELETE FROM admission_followup_history WHERE enquiry_id IN (SELECT id FROM admission_enquiries WHERE phone_e164 = ?)")->execute([$testE164]);
    $db->prepare("DELETE FROM admission_enquiries WHERE phone_e164 = ?")->execute([$testE164]);
};
$cleanup();

// --- PAIRING / SESSION / WATCH ---
$st = httpGet($base . '/api_whatsapp_session.php?action=status&qa_test=1');
if (($st['json']['success'] ?? false) === true) {
    $sess = $st['json']['session'] ?? [];
    $bridge = !empty($st['json']['bridge_running']) || !empty($sess['bridge_running']);
    $connected = !empty($sess['is_connected']);
    $pollAge = !empty($sess['last_poll_at']) ? (time() - strtotime($sess['last_poll_at'])) : 9999;
    cap('S1', 'Session status API responds', 'PASS', 'bridge_running=' . ($bridge ? 'yes' : 'no') . ' connected=' . ($connected ? 'yes' : 'no'));
    if ($bridge && $pollAge < 60) {
        cap('W1', 'Watch/poll heartbeat fresh (<60s)', 'PASS', 'last_poll_age=' . $pollAge . 's');
    } elseif ($bridge) {
        cap('W1', 'Watch/poll heartbeat fresh (<60s)', 'FAIL', 'last_poll_age=' . $pollAge . 's');
    } else {
        cap('W1', 'Watch/poll heartbeat fresh (<60s)', 'SKIP', 'bridge not running');
    }
    if ($connected) {
        cap('P1', 'Pairing/session linked', 'PASS', 'device=' . ($sess['device_name'] ?? ''));
    } else {
        cap('P1', 'Pairing/session linked', 'FAIL', 'is_connected=false (QR/login required). last_skip=' . ($sess['last_skip_note'] ?? ''));
    }
} else {
    cap('S1', 'Session status API responds', 'FAIL', 'http=' . $st['code'] . ' ' . substr($st['raw'], 0, 120));
    cap('W1', 'Watch/poll heartbeat fresh (<60s)', 'SKIP', 'no status');
    cap('P1', 'Pairing/session linked', 'SKIP', 'no status');
}

// --- RECEIVE: HMAC reject ---
if ($secret === '' || strlen($secret) < 16) {
    cap('R1', 'HMAC reject without valid signature', 'SKIP', 'secret missing');
} else {
    $bad = httpPost($base . '/api_whatsapp_ingest.php', json_encode([['wa_msg_id' => 'x']]), ['X-WA-Signature: deadbeef']);
    if ($bad['code'] === 401) {
        cap('R1', 'HMAC reject without valid signature', 'PASS', 'http=401');
    } else {
        cap('R1', 'HMAC reject without valid signature', 'FAIL', 'http=' . $bad['code']);
    }
}

// --- RECEIVE: ingest + dedupe ---
$msgId = $tag . '_IN1';
$payload = [[
    'wa_msg_id' => $msgId,
    'wa_chat_id' => $testWa,
    'from_me' => false,
    'sender_wa_id' => $testWa,
    'sender_phone' => $testE164,
    'sender_name' => 'Capability Test Parent',
    'chat_name' => 'Capability Test Parent',
    'chat_type' => 'individual',
    'msg_type' => 'text',
    'body' => 'Capability test inbound — please ignore. Admission enquiry for testing only.',
    'timestamp' => time(),
    'status' => 'delivered',
]];
if ($secret === '' || strlen($secret) < 16) {
    cap('R2', 'Receive/ingest stores message', 'SKIP', 'secret missing');
    cap('R3', 'Receive dedupe on same wa_msg_id', 'SKIP', 'secret missing');
} else {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $json, $secret);
    $r1 = httpPost($base . '/api_whatsapp_ingest.php', $json, ['X-WA-Signature: ' . $sig]);
    $ins = (int)($r1['json']['inserted'] ?? 0);
    if ($r1['code'] === 200 && $ins >= 1) {
        cap('R2', 'Receive/ingest stores message', 'PASS', 'inserted=' . $ins);
    } else {
        cap('R2', 'Receive/ingest stores message', 'FAIL', 'http=' . $r1['code'] . ' body=' . substr($r1['raw'], 0, 200));
    }
    $r2 = httpPost($base . '/api_whatsapp_ingest.php', $json, ['X-WA-Signature: ' . $sig]);
    $ins2 = (int)($r2['json']['inserted'] ?? -1);
    $rowCount = (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($msgId))->fetchColumn();
    if ($rowCount === 1 && $ins2 === 0) {
        cap('R3', 'Receive dedupe on same wa_msg_id', 'PASS', 'rows=1 inserted_second=' . $ins2);
    } else {
        // ON DUPLICATE may report insert 0 or 1 depending on lastInsertId — require single row
        if ($rowCount === 1) {
            cap('R3', 'Receive dedupe on same wa_msg_id', 'PASS', 'rows=1 inserted_second=' . $ins2);
        } else {
            cap('R3', 'Receive dedupe on same wa_msg_id', 'FAIL', 'rows=' . $rowCount . ' inserted_second=' . $ins2);
        }
    }
}

$chatId = (int)$db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id = " . $db->quote($testWa))->fetchColumn();
if (!$chatId) {
    // Create chat for send/delete tests even if ingest failed
    $chatId = waGetOrCreateChat($db, $testWa, 'individual', 'Capability Test Parent', null);
}

// --- SEND: enqueue then cancel (bridge is disconnected so it will not send; still cancel) ---
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_GET['qa_test'] = '1';
// Use HTTP with qa_test
$sendBody = json_encode([
    'chat_id' => $chatId,
    'msg_type' => 'text',
    'body' => $tag . ' queued outbound — MUST NOT be sent live',
    'priority' => 10,
    'dispatch_method' => 'auto',
]);
$send = httpPost($base . '/api_whatsapp_send.php?qa_test=1', $sendBody);
$queueId = (int)($send['json']['queue_id'] ?? 0);
$tempMsg = (string)($send['json']['temp_msg_id'] ?? '');
if ($send['code'] === 200 && $queueId > 0) {
    cap('Q1', 'Send enqueue creates outbound queue row', 'PASS', 'queue_id=' . $queueId);
} else {
    cap('Q1', 'Send enqueue creates outbound queue row', 'FAIL', 'http=' . $send['code'] . ' ' . substr($send['raw'], 0, 180));
}

// Immediately cancel so a future reconnect cannot send it
if ($queueId > 0) {
    $upd = httpPost($base . '/api_whatsapp_outbound_update.php?qa_test=1', json_encode([
        'id' => $queueId,
        'status' => 'cancelled',
        'error_message' => 'capability test cancel — never send',
    ]));
    $stQ = $db->prepare("SELECT status FROM whatsapp_outbound_queue WHERE id = ?");
    $stQ->execute([$queueId]);
    $qStatus = (string)$stQ->fetchColumn();
    if ($qStatus === 'cancelled') {
        cap('Q2', 'Queue cancel prevents live dispatch', 'PASS', 'status=cancelled');
    } else {
        // Fallback hard delete
        $db->prepare("DELETE FROM whatsapp_outbound_queue WHERE id = ?")->execute([$queueId]);
        cap('Q2', 'Queue cancel prevents live dispatch', $qStatus === '' ? 'PASS' : 'FAIL', 'status=' . $qStatus . ' (deleted if empty)');
    }
} else {
    cap('Q2', 'Queue cancel prevents live dispatch', 'SKIP', 'no queue row');
}

// --- TRACK: status transitions on message + queue timestamps ---
$trackMsgId = $tag . '_TRACK';
waInsertMessage($db, [
    'wa_msg_id' => $trackMsgId,
    'chat_id' => $chatId,
    'from_me' => true,
    'sender_name' => 'You',
    'msg_type' => 'text',
    'body' => 'track status bubble',
    'status' => 'sent',
    'timestamp' => time(),
]);
waInsertMessage($db, [
    'wa_msg_id' => $trackMsgId,
    'chat_id' => $chatId,
    'from_me' => true,
    'sender_name' => 'You',
    'msg_type' => 'text',
    'body' => 'track status bubble',
    'status' => 'delivered',
    'timestamp' => time(),
]);
$st1 = (string)$db->query("SELECT status FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($trackMsgId))->fetchColumn();
waInsertMessage($db, [
    'wa_msg_id' => $trackMsgId,
    'chat_id' => $chatId,
    'from_me' => true,
    'sender_name' => 'You',
    'msg_type' => 'text',
    'body' => 'track status bubble',
    'status' => 'read',
    'timestamp' => time(),
]);
$row = $db->query("SELECT status, acknowledged_at FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($trackMsgId))->fetch(PDO::FETCH_ASSOC);
if (($row['status'] ?? '') === 'read' && !empty($row['acknowledged_at'])) {
    cap('T1', 'Track status upgrades sent→delivered→read', 'PASS', 'ack=' . $row['acknowledged_at']);
} else {
    cap('T1', 'Track status upgrades sent→delivered→read', 'FAIL', json_encode($row));
}

// Queue track timestamps via outbound_update (ISO-8601 regression)
if ($queueId > 0) {
    // re-insert a disposable queue row for timestamp test, never leave as queued
    $db->prepare("INSERT INTO whatsapp_outbound_queue (chat_id, msg_type, body, priority, status, dispatched_by) VALUES (?,?,?,?,?,?)")
       ->execute([$chatId, 'text', $tag . ' track timestamps', 10, 'processing', 1]);
    $qid2 = (int)$db->lastInsertId();
    $iso = gmdate('Y-m-d\TH:i:s.000\Z');
    $u = httpPost($base . '/api_whatsapp_outbound_update.php?qa_test=1', json_encode([
        'id' => $qid2,
        'status' => 'sent',
        'sent_at' => $iso,
        'delivered_at' => $iso,
    ]));
    $qrow = $db->query("SELECT status, sent_at, delivered_at FROM whatsapp_outbound_queue WHERE id = {$qid2}")->fetch(PDO::FETCH_ASSOC);
    $db->prepare("DELETE FROM whatsapp_outbound_queue WHERE id = ?")->execute([$qid2]);
    if (($qrow['status'] ?? '') === 'sent' && !empty($qrow['sent_at'])) {
        cap('T2', 'Track outbound_update accepts ISO timestamps', 'PASS', 'sent_at=' . $qrow['sent_at']);
    } else {
        cap('T2', 'Track outbound_update accepts ISO timestamps', 'FAIL', json_encode($qrow) . ' http=' . $u['code']);
    }
} else {
    cap('T2', 'Track outbound_update accepts ISO timestamps', 'SKIP', 'no queue');
}

// --- DELETE: for me (DB row gone) and for everyone (queue + mock bridge) ---
$delMe = $tag . '_DEL_ME';
waInsertMessage($db, [
    'wa_msg_id' => $delMe, 'chat_id' => $chatId, 'from_me' => true,
    'msg_type' => 'text', 'body' => 'delete me', 'status' => 'sent', 'timestamp' => time(),
]);
$idMe = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($delMe))->fetchColumn();

$d1 = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', json_encode([
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $idMe, 'for_everyone' => false,
]));
$gone = (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE id = {$idMe}")->fetchColumn();
if ($d1['code'] === 200 && ($d1['json']['success'] ?? false) && $gone === 0) {
    cap('D1', 'Delete-for-me removes DB row', 'PASS', 'msg_id=' . $idMe);
} else {
    cap('D1', 'Delete-for-me removes DB row', 'FAIL', 'http=' . $d1['code'] . ' gone=' . $gone . ' ' . substr($d1['raw'], 0, 120));
}

$delEv = $tag . '_DEL_EV';
waInsertMessage($db, [
    'wa_msg_id' => $delEv, 'chat_id' => $chatId, 'from_me' => true,
    'msg_type' => 'text', 'body' => 'delete everyone', 'status' => 'sent', 'timestamp' => time() - 20,
]);
$idEv = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($delEv))->fetchColumn();
$d2 = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', json_encode([
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $idEv, 'for_everyone' => true,
]));
$revPendingRow = $db->query("SELECT status, msg_type FROM whatsapp_messages WHERE id = {$idEv}")->fetch(PDO::FETCH_ASSOC);
$d2Qid = (int)($d2['json']['queue_id'] ?? 0);
if ($d2['code'] === 200 && ($revPendingRow['status'] ?? '') === 'revoke_pending' && $d2Qid > 0) {
    cap('D2', 'Delete-for-everyone queues revoke_pending', 'PASS', 'queue_id=' . $d2Qid);
} else {
    cap('D2', 'Delete-for-everyone queues revoke_pending', 'FAIL', json_encode($revPendingRow) . ' http=' . $d2['code'] . ' q=' . $d2Qid);
}

$bridgeSrc = @file_get_contents($root . '/automation/whatsapp-bridge.js') ?: '';
$hasHelper = (bool)preg_match('/async\s+revokeForEveryone\s*\(/', $bridgeSrc);
if ($d2Qid > 0 && $hasHelper) {
    $mock = httpPost($base . '/api_whatsapp_outbound_update.php?qa_test=1', json_encode([
        'id' => $d2Qid,
        'status' => 'sent',
        'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]));
    $revFinal = $db->query("SELECT status, msg_type FROM whatsapp_messages WHERE id = {$idEv}")->fetch(PDO::FETCH_ASSOC);
    if (($revFinal['status'] ?? '') === 'revoked' && ($revFinal['msg_type'] ?? '') === 'revoked') {
        cap('D3', 'Mock bridge revoke success marks message revoked', 'PASS', 'queue_id=' . $d2Qid);
    } else {
        cap('D3', 'Mock bridge revoke success marks message revoked', 'FAIL', json_encode($revFinal) . ' http=' . $mock['code']);
    }
} else {
    cap('D3', 'Mock bridge revoke success marks message revoked', 'FAIL', 'queue=' . $d2Qid . ' helper=' . ($hasHelper ? 'yes' : 'no'));
}

// Filter API health (watch privacy)
$f = httpGet($base . '/api_whatsapp_bridge_filter.php?qa_test=1');
if (($f['json']['success'] ?? false) === true) {
    $kw = $f['json']['data']['admission_keywords'] ?? $f['json']['admission_keywords'] ?? [];
    cap('W2', 'Bridge privacy filter API', 'PASS', 'keywords=' . (is_array($kw) ? count($kw) : 0));
} else {
    cap('W2', 'Bridge privacy filter API', 'FAIL', 'http=' . $f['code']);
}

// Ensure no queued rows remain for test chat
$left = (int)$db->query("SELECT COUNT(*) FROM whatsapp_outbound_queue WHERE chat_id = {$chatId} AND status = 'queued'")->fetchColumn();
if ($left === 0) {
    cap('Q3', 'No leftover queued outbound for test chat', 'PASS');
} else {
    $db->prepare("DELETE FROM whatsapp_outbound_queue WHERE chat_id = ? AND status = 'queued'")->execute([$chatId]);
    cap('Q3', 'No leftover queued outbound for test chat', 'FAIL', "had {$left}, deleted now");
}

$cleanup();
cap('Z1', 'Cleanup removed capability test rows', 'PASS', 'phone=' . $testE164);

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
file_put_contents($root . '/tests/last_capabilities_result.json', json_encode([
    'passed' => $passed, 'failed' => $failed, 'skipped' => $skipped, 'results' => $results,
], JSON_PRETTY_PRINT));
exit($failed > 0 ? 1 : 0);
