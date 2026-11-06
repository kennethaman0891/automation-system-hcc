<?php
/**
 * Delete-for-everyone (revoke) path — API + DB with mocked bridge updates.
 * Does NOT talk to live WhatsApp Web.
 *
 * Run: php tests/test_whatsapp_revoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/admin/includes/whatsapp_functions.php';

$passed = 0;
$failed = 0;

function ok(string $name, bool $cond, string $detail = ''): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
    else { $failed++; echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function httpPost(string $url, array $body): array {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Admin-Test: 1',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw];
}

$db = waGetDB();
waEnsureRevokeSchema($db);
$base = 'http://127.0.0.1/hcc/admin';
$tag = 'REVTEST_' . date('YmdHis');
$waChat = '919100001111@c.us';
$waMsg = 'true_' . $waChat . '_' . $tag;

// Cleanup leftovers
$oldChat = $db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id = " . $db->quote($waChat))->fetchColumn();
if ($oldChat) {
    $db->prepare("DELETE FROM whatsapp_outbound_queue WHERE chat_id = ?")->execute([(int)$oldChat]);
    $db->prepare("DELETE FROM whatsapp_messages WHERE chat_id = ?")->execute([(int)$oldChat]);
    $db->prepare("DELETE FROM whatsapp_chats WHERE id = ?")->execute([(int)$oldChat]);
}

$chatId = waGetOrCreateChat($db, $waChat, 'individual', 'Revoke Test Chat', null);
$msgDbId = waInsertMessage($db, [
    'wa_msg_id' => $waMsg,
    'chat_id' => $chatId,
    'from_me' => true,
    'sender_name' => 'You',
    'msg_type' => 'text',
    'body' => 'Revoke me please',
    'status' => 'sent',
    'timestamp' => time() - 30,
]);
$msgDbId = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($waMsg))->fetchColumn();
ok('seed from_me message', $msgDbId > 0, 'id=' . $msgDbId);

// Reject inbound (not from_me)
$otherId = waInsertMessage($db, [
    'wa_msg_id' => $waMsg . '_IN',
    'chat_id' => $chatId,
    'from_me' => false,
    'msg_type' => 'text',
    'body' => 'from parent',
    'status' => 'delivered',
    'timestamp' => time() - 20,
]);
$otherId = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($waMsg . '_IN'))->fetchColumn();
$rej = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', [
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $otherId, 'for_everyone' => true,
]);
ok('reject revoke on inbound message', $rej['code'] === 400, 'http=' . $rej['code']);

// Reject outside window
$oldWa = $waMsg . '_OLD';
$oldId = waInsertMessage($db, [
    'wa_msg_id' => $oldWa,
    'chat_id' => $chatId,
    'from_me' => true,
    'msg_type' => 'text',
    'body' => 'too old',
    'status' => 'sent',
    'timestamp' => time() - (60 * 60 * 72),
]);
$oldId = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($oldWa))->fetchColumn();
$oldR = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', [
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $oldId, 'for_everyone' => true,
]);
$oldStatus = (string)$db->query("SELECT status FROM whatsapp_messages WHERE id = {$oldId}")->fetchColumn();
ok('outside window → revoke_failed', $oldR['code'] === 422 && $oldStatus === 'revoke_failed', 'http=' . $oldR['code'] . ' status=' . $oldStatus);

// Happy path: queue revoke_pending
$q = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', [
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $msgDbId, 'for_everyone' => true,
]);
$st = (string)$db->query("SELECT status FROM whatsapp_messages WHERE id = {$msgDbId}")->fetchColumn();
$queueId = (int)($q['json']['queue_id'] ?? 0);
$qRow = $queueId ? $db->query("SELECT msg_type, status, body, max_attempts FROM whatsapp_outbound_queue WHERE id = {$queueId}")->fetch(PDO::FETCH_ASSOC) : null;
ok('queue revoke_pending', $q['code'] === 200 && $st === 'revoke_pending' && $queueId > 0, 'queue=' . $queueId . ' status=' . $st);
ok('queue row is msg_type=revoke', ($qRow['msg_type'] ?? '') === 'revoke' && ($qRow['status'] ?? '') === 'queued', json_encode($qRow));
ok('revoke max_attempts=1', (int)($qRow['max_attempts'] ?? 0) === 1);

// Idempotent second request
$q2 = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', [
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $msgDbId, 'for_everyone' => true,
]);
ok('second request stays revoke_pending', ($q2['json']['status'] ?? '') === 'revoke_pending', json_encode($q2['json']));

// Mock bridge success
$okUp = httpPost($base . '/api_whatsapp_outbound_update.php?qa_test=1', [
    'id' => $queueId,
    'status' => 'sent',
    'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
]);
$after = $db->query("SELECT msg_type, status, body FROM whatsapp_messages WHERE id = {$msgDbId}")->fetch(PDO::FETCH_ASSOC);
ok('mock bridge success → revoked', $okUp['code'] === 200 && ($after['status'] ?? '') === 'revoked' && ($after['msg_type'] ?? '') === 'revoked', json_encode($after));

// Mock bridge failure path on a fresh message
$failWa = $waMsg . '_FAIL';
$failId = waInsertMessage($db, [
    'wa_msg_id' => $failWa,
    'chat_id' => $chatId,
    'from_me' => true,
    'msg_type' => 'text',
    'body' => 'will fail revoke',
    'status' => 'sent',
    'timestamp' => time() - 10,
]);
$failId = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($failWa))->fetchColumn();
$fq = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', [
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $failId, 'for_everyone' => true,
]);
$failQid = (int)($fq['json']['queue_id'] ?? 0);
$failUp = httpPost($base . '/api_whatsapp_outbound_update.php?qa_test=1', [
    'id' => $failQid,
    'status' => 'failed',
    'attempts' => 1,
    'error_message' => 'Delete for everyone is not available for this message',
]);
$failRow = $db->query("SELECT status, metadata FROM whatsapp_messages WHERE id = {$failId}")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode((string)($failRow['metadata'] ?? '{}'), true) ?: [];
ok('mock bridge failure → revoke_failed', ($failRow['status'] ?? '') === 'revoke_failed', json_encode($failRow));
ok('failure reason stored in metadata', str_contains((string)($meta['revoke_error'] ?? ''), 'not available'), json_encode($meta));

// Delete-for-me still hard-deletes
$meWa = $waMsg . '_ME';
$meId = waInsertMessage($db, [
    'wa_msg_id' => $meWa,
    'chat_id' => $chatId,
    'from_me' => true,
    'msg_type' => 'text',
    'body' => 'delete for me',
    'status' => 'sent',
    'timestamp' => time(),
]);
$meId = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id = " . $db->quote($meWa))->fetchColumn();
$meR = httpPost($base . '/api_whatsapp_chat_actions.php?qa_test=1', [
    'chat_id' => $chatId, 'action' => 'delete_message', 'msg_id' => $meId, 'for_everyone' => false,
]);
$gone = (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE id = {$meId}")->fetchColumn();
ok('delete-for-me still removes row', $meR['code'] === 200 && $gone === 0);

// Cleanup
$db->prepare("DELETE FROM whatsapp_outbound_queue WHERE chat_id = ?")->execute([$chatId]);
$db->prepare("DELETE FROM whatsapp_messages WHERE chat_id = ?")->execute([$chatId]);
$db->prepare("DELETE FROM whatsapp_chats WHERE id = ?")->execute([$chatId]);
ok('cleanup', true);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
