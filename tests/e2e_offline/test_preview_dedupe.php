<?php
/**
 * 2026-11-06 incoming fix: a chat-list preview stand-in is replaced by the real message,
 * and storing incoming (preview or real) never creates an outbound/queued reply.
 * Runs on the hcc_wa_test DB only.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
putenv('WA_TEST_MODE=1');
putenv('HCC_DB_NAME=hcc_wa_test');
$_SERVER['SCRIPT_NAME'] = '/tests/e2e_offline/test_preview_dedupe.php';
$root = dirname(__DIR__, 2);
require $root . '/config/database.php';
require $root . '/includes/functions.php';
require $root . '/admin/includes/whatsapp_functions.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name $detail\n"; }
}
$db = getDB();
check('db_is_test', str_starts_with((string)DB_NAME, 'hcc_wa_test'), (string)DB_NAME);
if (!str_starts_with((string)DB_NAME, 'hcc_wa_test')) { exit(1); }

$wa = '919999000077@c.us';   // synthetic, test DB only
$chatId = waGetOrCreateChat($db, $wa, 'individual', 'E2E Preview Parent', null);
$db->prepare('DELETE FROM whatsapp_messages WHERE chat_id = ?')->execute([$chatId]);
$qBefore = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();

$tag = bin2hex(random_bytes(4));
waInsertMessage($db, ['wa_msg_id' => 'preview:' . $tag . 'a', 'chat_id' => $chatId, 'from_me' => false, 'body' => 'Is admission open for class 6? Please tell the fee…', 'status' => 'delivered', 'timestamp' => time(), 'metadata' => ['preview_only' => true]]);
waInsertMessage($db, ['wa_msg_id' => 'preview:' . $tag . 'b', 'chat_id' => $chatId, 'from_me' => false, 'body' => 'Different topic', 'status' => 'delivered', 'timestamp' => time(), 'metadata' => ['preview_only' => true]]);
$count = fn() => (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE chat_id = {$chatId} AND wa_msg_id LIKE 'preview:%'")->fetchColumn();
check('two_previews_stored', $count() === 2, (string)$count());

waInsertMessage($db, ['wa_msg_id' => 'REAL' . $tag, 'chat_id' => $chatId, 'from_me' => false, 'body' => 'Is admission open for class 6? Please tell the fee structure and timings.', 'status' => 'delivered', 'timestamp' => time()]);
$dropped = waDropSupersededPreviews($db, $chatId, 'Is admission open for class 6? Please tell the fee structure and timings.');
check('matching_preview_replaced_by_real_message', $dropped === 1 && $count() === 1, "dropped=$dropped left=" . $count());
$real = (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE chat_id = {$chatId} AND wa_msg_id = 'REAL{$tag}'")->fetchColumn();
check('real_message_kept', $real === 1);
check('unrelated_preview_kept', (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE chat_id = {$chatId} AND body = 'Different topic'")->fetchColumn() === 1);
check('never_deletes_non_preview_rows', waDropSupersededPreviews($db, $chatId, 'Is admission open') === 0 && $real === 1);

$qAfter = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();
check('incoming_preview_and_real_create_no_outbound_rows', $qAfter === $qBefore, "before=$qBefore after=$qAfter");

$src = file_get_contents($root . '/admin/api_whatsapp_ingest.php');
check('ingest_skips_enquiry_sync_for_previews', strpos($src, "empty(\$msg['from_me']) && !\$isPreviewOnly") !== false);
check('ingest_has_no_send_calls', !preg_match('/waEnqueueWhatsAppOutbound|api_whatsapp_send|outbound_queue\s*\(/i', $src));
$sse = file_get_contents($root . '/admin/api_whatsapp_events.php');
check('sse_sends_event_ids_for_resume', strpos($sse, 'echo "id: {$id}') !== false);

$db->prepare('DELETE FROM whatsapp_messages WHERE chat_id = ?')->execute([$chatId]);
$db->prepare('DELETE FROM whatsapp_chats WHERE id = ?')->execute([$chatId]);
echo "\nPreview dedupe: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
