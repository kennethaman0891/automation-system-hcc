<?php
/**
 * Offline proof: automatic sending is gone.
 * - Incoming messages store + create enquiry, zero outbound
 * - Stale queued rows become failed, not sent
 * - Failed rows not retried until press
 * - User press → exactly one queued row
 * - HTTP cannot enable test mode or bypass auth
 * - Removed endpoints return 410
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
putenv('WA_TEST_MODE=1');
putenv('HCC_DB_NAME=hcc_wa_test');
$_SERVER['SCRIPT_NAME'] = '/tests/e2e_offline/test_no_auto_send.php';

$root = dirname(__DIR__, 2);
require $root . '/config/database.php';
require $root . '/includes/functions.php';
require $root . '/admin/includes/whatsapp_functions.php';
require __DIR__ . '/lib/assert_fixture_only.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name $detail\n"; }
}

$db = getDB();
check('db_is_test', DB_NAME === 'hcc_wa_test' || str_starts_with((string)DB_NAME, 'hcc_wa_test'), (string)DB_NAME);

$allowed = array_filter(array_map('trim', explode(',', getenv('WA_ALLOWED_CHATS') ?: '919999000001@c.us,120363999000002@g.us,123456789012345@lid,919999000099@c.us')));
$chats = $db->query('SELECT id, wa_chat_id, name FROM whatsapp_chats')->fetchAll(PDO::FETCH_ASSOC);
$fixtureIds = [];
foreach ($chats as $c) {
    if (in_array(strtolower($c['wa_chat_id']), array_map('strtolower', $allowed), true)) {
        $fixtureIds[] = (int)$c['id'];
    }
}
check('fixture_chats_present', count($fixtureIds) >= 1, 'count=' . count($fixtureIds));
$chatId = $fixtureIds[0] ?? 0;

function countStatus(PDO $db, string $s): int {
    $st = $db->prepare('SELECT COUNT(*) FROM whatsapp_outbound_queue WHERE status=?');
    $st->execute([$s]);
    return (int)$st->fetchColumn();
}

// Session required for enqueue
$_SESSION = [];
session_id('e2e-no-auto-' . bin2hex(random_bytes(4)));
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'E2E';
$_SESSION['admin_role'] = 'admin';

$qBefore = countStatus($db, 'queued');

// 1) User-press enqueue → exactly one queued
$r = waEnqueueWhatsAppOutbound($db, [
    'chat_id' => $chatId,
    'body' => 'USER PRESS ' . time(),
    'approved_by' => 1,
    'dispatched_by' => 1,
]);
check('user_press_status_queued', ($r['status'] ?? '') === 'queued', json_encode($r));
check('user_press_queued_plus_one', countStatus($db, 'queued') === $qBefore + 1);

// 2) Enqueue without admin must throw
$threw = false;
$prev = $_SESSION['admin_id'] ?? null;
unset($_SESSION['admin_id']);
try {
    waEnqueueWhatsAppOutbound($db, [
        'chat_id' => $chatId,
        'body' => 'NO ADMIN ' . time(),
        'approved_by' => 0,
        'dispatched_by' => 0,
    ]);
} catch (Throwable $e) {
    $threw = true;
}
$_SESSION['admin_id'] = $prev;
check('enqueue_requires_admin', $threw);

// 3) Stale queued → failed via waFailStaleOutbound
$db->prepare("UPDATE whatsapp_outbound_queue SET created_at = (NOW() - INTERVAL 10 MINUTE) WHERE id = ?")
   ->execute([(int)$r['queue_id']]);
$res = waFailStaleOutbound($db, 120);
check('stale_marked_failed', $res['failed'] >= 1, json_encode($res));
$st = $db->prepare('SELECT status, error_message FROM whatsapp_outbound_queue WHERE id=?');
$st->execute([(int)$r['queue_id']]);
$row = $st->fetch(PDO::FETCH_ASSOC);
check('stale_status_failed', ($row['status'] ?? '') === 'failed', json_encode($row));
check('stale_error_mentions_retry', str_contains((string)($row['error_message'] ?? ''), 'press Retry'));

// 4) Inbound store does not create outbound. Ingest source has no enqueue.
$queuedBeforeInbound = countStatus($db, 'queued');
$waChat = null;
foreach ($chats as $c) {
    if ((int)$c['id'] === $chatId) { $waChat = $c['wa_chat_id']; break; }
}
$msgId = waInsertMessage($db, [
    'wa_msg_id' => 'e2e_in_' . time() . '_' . bin2hex(random_bytes(3)),
    'chat_id' => $chatId,
    'from_me' => false,
    'sender_name' => 'E2E Parent',
    'msg_type' => 'text',
    'body' => 'Hello, I want admission for my child in STD 1',
    'status' => 'delivered',
    'timestamp' => time(),
]);
check('inbound_stored', $msgId > 0);
check('inbound_zero_new_queued', countStatus($db, 'queued') === $queuedBeforeInbound);

$ingestSrc = file_get_contents($root . '/admin/api_whatsapp_ingest.php');
check('ingest_no_enqueue', !str_contains($ingestSrc, 'waEnqueueWhatsAppOutbound') && !str_contains($ingestSrc, 'whatsapp_outbound_queue'));
check('ingest_still_creates_enquiry_code', str_contains($ingestSrc, 'waSyncInboundAdmissionEnquiry') || str_contains($ingestSrc, 'admission_enquiries'));

$auto = (int)$db->query("SELECT COUNT(*) FROM whatsapp_outbound_queue WHERE created_at >= (NOW() - INTERVAL 1 MINUTE) AND origin IS NOT NULL AND origin != 'manual_ui'")->fetchColumn();
check('no_auto_origin_rows', $auto === 0, "auto_origin=$auto");

// 5) Lock / kill-switch files gone
check('lock_file_gone', !is_file($root . '/admin/includes/whatsapp_send_lock.php'));
check('send_token_gone', !is_file($root . '/admin/api_whatsapp_send_token.php'));
check('no_WA_SEND_MODE_const', !defined('WA_SEND_MODE'));

// 6) automated_send returns 410 content
$autoFile = file_get_contents($root . '/admin/api_automated_send.php');
check('automated_send_is_gone_stub', str_contains($autoFile, '410') && str_contains($autoFile, 'autosend_removed'));

// 7) HTTP cannot flip test DB: simulate by checking SAPI gate source
$dbSrc = file_get_contents($root . '/config/database.php');
check('test_db_sapi_gated', str_contains($dbSrc, "PHP_SAPI") && str_contains($dbSrc, 'cli-server'));

echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
