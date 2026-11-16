<?php
/**
 * End-to-end verification for WhatsApp Inbound Ingestion -> Admission Enquiries & Follow-ups
 * Tests:
 *  1. Bridge HMAC authentication on api_whatsapp_ingest.php
 *  2. Auto-capture of new admission enquiry from prospective parent message
 *  3. Grade/class detection from message text
 *  4. Subsequent parent replies appending to conversation without duplicating enquiry
 *  5. Visibility in api_admission_enquiry.php and api_admission_followup.php
 *  6. Cleanup of test data
 *
 * Run: php tests/test_whatsapp_inbound_admission.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/admin/includes/whatsapp_functions.php';

$secret = defined('WHATSAPP_BRIDGE_SECRET') ? WHATSAPP_BRIDGE_SECRET : '';
if (!$secret) {
    echo "FAIL: WHATSAPP_BRIDGE_SECRET is not defined in config/env.php\n";
    exit(1);
}

$db = waGetDB();
$testPhoneDigits = '9876599999';
$testPhoneE164 = '+91' . $testPhoneDigits;
$testWaChatId = '91' . $testPhoneDigits . '@c.us';
$testParentName = 'Pooja Deshmukh (Test QA)';

// 0. Clean any prior test artifacts
echo "[1/6] Cleaning any previous test records...\n";
$oldIds = $db->query("SELECT id FROM admission_enquiries WHERE phone_e164 = '{$testPhoneE164}'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($oldIds as $oid) {
    $db->prepare("DELETE FROM admission_enquiry_messages WHERE enquiry_id = ?")->execute([(int)$oid]);
    $db->prepare("DELETE FROM admission_followup_history WHERE enquiry_id = ?")->execute([(int)$oid]);
    $db->prepare("DELETE FROM admission_enquiries WHERE id = ?")->execute([(int)$oid]);
}
$oldChatId = $db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id = '{$testWaChatId}'")->fetchColumn();
if ($oldChatId) {
    $db->prepare("DELETE FROM whatsapp_messages WHERE chat_id = ?")->execute([(int)$oldChatId]);
    $db->prepare("DELETE FROM whatsapp_chats WHERE id = ?")->execute([(int)$oldChatId]);
}

// 1. Post initial inbound enquiry message via HMAC POST
echo "[2/6] Sending initial inbound WhatsApp message via api_whatsapp_ingest.php...\n";
$msgId1 = 'true_' . $testWaChatId . '_TEST_MSG_001';
$payload1 = [
    [
        'wa_msg_id' => $msgId1,
        'wa_chat_id' => $testWaChatId,
        'from_me' => false,
        'sender_wa_id' => $testWaChatId,
        'sender_phone' => $testPhoneE164,
        'sender_name' => $testParentName,
        'sender_push_name' => 'Pooja',
        'chat_name' => $testParentName,
        'chat_type' => 'individual',
        'msg_type' => 'text',
        'body' => 'Hello, please share admission details and fee structure for 5th standard for my daughter.',
        'timestamp' => time()
    ]
];

function postInbound(string $secret, array $payload): array {
    $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $jsonBody, $secret);
    $ch = curl_init('http://127.0.0.1/hcc/admin/api_whatsapp_ingest.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-WA-Signature: ' . $sig
        ],
        CURLOPT_TIMEOUT => 15
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$resp, 'json' => json_decode((string)$resp, true)];
}

$posted1 = postInbound($secret, $payload1);
$code1 = $posted1['code'];
$resp1 = $posted1['body'];

$res1 = json_decode((string)$resp1, true);
if ($code1 !== 200 || empty($res1['success']) || empty($res1['inserted'])) {
    echo "FAIL: Failed to ingest initial message. Code: {$code1}, Response: {$resp1}\n";
    exit(1);
}
echo "PASS: Ingested initial message. Code: 200, Inserted: {$res1['inserted']}\n";

// 2. Verify Database State
echo "[3/6] Verifying admission_enquiries, messages, and history...\n";
$enqStmt = $db->prepare("SELECT * FROM admission_enquiries WHERE phone_e164 = ? ORDER BY id DESC LIMIT 1");
$enqStmt->execute([$testPhoneE164]);
$enquiry = $enqStmt->fetch(PDO::FETCH_ASSOC);

if (!$enquiry) {
    echo "FAIL: No enquiry record found in admission_enquiries for {$testPhoneE164}!\n";
    exit(1);
}

assert($enquiry['parent_name'] === $testParentName, 'Parent name should match');
assert($enquiry['interested_class'] === 'STD V', 'Interested class should be detected as STD V');
assert($enquiry['status'] === 'NEW', 'Status should be NEW');
assert($enquiry['followup_category'] === 'First response pending', 'Category should be First response pending');
assert($enquiry['next_followup_date'] === date('Y-m-d'), 'Next followup date should be today');
assert(!empty($enquiry['chat_id']), 'Chat ID should be linked');

$enquiryId = (int)$enquiry['id'];
echo "PASS: Enquiry #{$enquiryId} auto-created with Class: {$enquiry['interested_class']}, Status: {$enquiry['status']}\n";

// Check timeline message
$msgCheck = $db->prepare("SELECT * FROM admission_enquiry_messages WHERE enquiry_id = ?");
$msgCheck->execute([$enquiryId]);
$msgs = $msgCheck->fetchAll(PDO::FETCH_ASSOC);
assert(count($msgs) === 1, 'Should have exactly 1 timeline message');
assert($msgs[0]['message_type'] === 'incoming_message', 'Message type should be incoming_message');
echo "PASS: Initial message linked to conversation timeline.\n";

// Check follow-up history
$histCheck = $db->prepare("SELECT * FROM admission_followup_history WHERE enquiry_id = ?");
$histCheck->execute([$enquiryId]);
$hist = $histCheck->fetchAll(PDO::FETCH_ASSOC);
assert(count($hist) >= 1, 'Should have follow-up history record');
echo "PASS: Initial creation history logged in admission_followup_history.\n";

// 3. Post second message from same parent
echo "[4/6] Posting second follow-up message from same parent...\n";
$msgId2 = 'true_' . $testWaChatId . '_TEST_MSG_002';
$payload2 = [
    [
        'wa_msg_id' => $msgId2,
        'wa_chat_id' => $testWaChatId,
        'from_me' => false,
        'sender_wa_id' => $testWaChatId,
        'sender_phone' => $testPhoneE164,
        'sender_name' => $testParentName,
        'chat_name' => $testParentName,
        'chat_type' => 'individual',
        'msg_type' => 'text',
        'body' => 'Also, do you offer school bus transport to Camp area?',
        'timestamp' => time() + 5
    ]
];
$db->prepare("UPDATE admission_enquiries SET next_followup_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?")->execute([$enquiryId]);
$posted2 = postInbound($secret, $payload2);
$code2 = $posted2['code'];
$resp2 = $posted2['body'];

$res2 = json_decode((string)$resp2, true);
assert($code2 === 200 && !empty($res2['success']), 'Second message should ingest successfully');
echo "PASS: Second message ingested.\n";

// Verify NO duplicate enquiry was created
$allEnqCount = $db->prepare("SELECT COUNT(*) FROM admission_enquiries WHERE phone_e164 = ?");
$allEnqCount->execute([$testPhoneE164]);
$count = (int)$allEnqCount->fetchColumn();
assert($count === 1, "Expected exactly 1 enquiry for parent, got {$count}");
echo "PASS: No duplicate enquiry created; parent reply linked to existing record.\n";

// Verify 2 messages now in timeline
$msgCheck->execute([$enquiryId]);
$msgs2 = $msgCheck->fetchAll(PDO::FETCH_ASSOC);
assert(count($msgs2) === 2, "Expected 2 messages in timeline, got " . count($msgs2));
echo "PASS: Second message appended to conversation timeline (total 2 messages).\n";
$movedDate = $db->prepare('SELECT next_followup_date FROM admission_enquiries WHERE id = ?');
$movedDate->execute([$enquiryId]);
assert($movedDate->fetchColumn() === date('Y-m-d'), 'A parent reply should move the follow-up to today');
echo "PASS: Parent reply moved next_followup_date to today.\n";

$payload2[0]['wa_msg_id'] = 'true_' . $testWaChatId . '_TEST_MSG_002_REPEAT';
$payload2[0]['timestamp'] = time() + 9;
$repeat = postInbound($secret, $payload2);
assert($repeat['code'] === 200 && !empty($repeat['json']['success']), 'Repeated scrape of the same reply should still ingest');
$msgCheck->execute([$enquiryId]);
assert(count($msgCheck->fetchAll(PDO::FETCH_ASSOC)) === 2, 'The same parent text inside 6 hours must not be stored twice');
echo "PASS: Repeated parent text did not duplicate the timeline.\n";

// 4. Test API listing and get endpoints
echo "[5/6] Verifying API endpoints for UI rendering...\n";
require_once __DIR__ . '/lib_cli_session.php';
$cookieFile = tempnam(sys_get_temp_dir(), 'hcc_test_cookie_');
$cliSess = hccCreateCliAdminSession($cookieFile, '127.0.0.1');
register_shutdown_function(function () use (&$cliSess, $cookieFile) {
    hccCleanupCliSession($cliSess);
    @unlink($cookieFile);
});
$apiBase = hccResolveAdminBase('http://127.0.0.1/hcc/admin', $cliSess, $cookieFile);
$loginCh = curl_init($apiBase . '/api_admission_enquiry.php?action=list&search=' . urlencode($testPhoneDigits));
curl_setopt_array($loginCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_TIMEOUT => 10
]);
$listOut = curl_exec($loginCh);
curl_close($loginCh);
$listJson = json_decode((string)$listOut, true);
assert(!empty($listJson['success']), 'api_admission_enquiry list should succeed');
$foundInList = false;
foreach ($listJson['data'] as $item) {
    if ((int)$item['id'] === $enquiryId) {
        $foundInList = true;
        break;
    }
}
assert($foundInList, "Enquiry #{$enquiryId} should appear in api_admission_enquiry.php list");
echo "PASS: Enquiry appears in api_admission_enquiry.php?action=list\n";

// Test get endpoint
$getCh = curl_init($apiBase . "/api_admission_enquiry.php?action=get&id={$enquiryId}");
curl_setopt_array($getCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_TIMEOUT => 10
]);
$getOut = curl_exec($getCh);
curl_close($getCh);
$getJson = json_decode((string)$getOut, true);
assert(!empty($getJson['success']) && !empty($getJson['timeline']), 'api_admission_enquiry get should return timeline');
assert(count($getJson['timeline']) === 2, 'Timeline should contain both messages');
echo "PASS: Full conversation timeline retrieved with both messages.\n";

// 5. Test admission_followup.php get
$fuCh = curl_init($apiBase . "/api_admission_followup.php?action=get&id={$enquiryId}");
curl_setopt_array($fuCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_TIMEOUT => 10
]);
$fuOut = curl_exec($fuCh);
curl_close($fuCh);
$fuJson = json_decode((string)$fuOut, true);
assert(!empty($fuJson['success']), 'api_admission_followup get should succeed');
assert(!empty($fuJson['messages']), 'Followup get should include conversation messages');
echo "PASS: Follow-up dashboard displays parent conversation thread.\n";

// Extra capture rules. These phones are not the enquiry created above.
echo "[5b] Checking group, blank sync, roster, and unknown-number rules...\n";

$groupPhone = '120363000000009999@g.us';
$groupPost = postInbound($secret, [[
    'wa_msg_id' => 'false_' . $groupPhone . '_TEST_GROUP',
    'wa_chat_id' => $groupPhone,
    'from_me' => false,
    'sender_wa_id' => $groupPhone,
    'sender_name' => 'Class Group',
    'chat_name' => 'Class Group',
    'chat_type' => 'group',
    'msg_type' => 'text',
    'body' => 'Please share admission details for this group test.',
    'timestamp' => time()
]]);
assert($groupPost['code'] === 200 && !empty($groupPost['json']['success']), 'Group payload should ingest');
$groupEnquiry = $db->prepare("SELECT COUNT(*) FROM admission_enquiries WHERE original_message LIKE '%this group test%'");
$groupEnquiry->execute();
assert((int)$groupEnquiry->fetchColumn() === 0, 'A group chat must not create an admission enquiry');
echo "PASS: Group message did not create an enquiry.\n";

$syncPhone = '919833333333';
$syncPost = postInbound($secret, [[
    'wa_msg_id' => 'chat_sync_' . $syncPhone . '@c.us',
    'wa_chat_id' => $syncPhone . '@c.us',
    'from_me' => false,
    'sender_wa_id' => $syncPhone . '@c.us',
    'sender_name' => 'Sync Row',
    'chat_type' => 'individual',
    'msg_type' => 'system',
    'body' => '',
    'timestamp' => time(),
    'metadata' => ['sync' => true]
]]);
assert($syncPost['code'] === 200 && !empty($syncPost['json']['success']), 'Blank sync row should still ingest as a chat');
$syncEnquiry = $db->prepare("SELECT COUNT(*) FROM admission_enquiries WHERE phone_e164 = '+919833333333'");
$syncEnquiry->execute();
assert((int)$syncEnquiry->fetchColumn() === 0, 'A blank system sync must not create an enquiry');
echo "PASS: Blank system sync did not create an enquiry.\n";

$rosterPhone10 = '9811111111';
$rosterE164 = '+91' . $rosterPhone10;
$db->prepare("DELETE FROM report_card WHERE roll_no = '77099'")->execute();
$db->prepare("
    INSERT INTO report_card (academic_year, std, division, semester, roll_no, student_name, subject_name, parent_phone)
    VALUES ('2026-27', '5', 'A', 'Sem 1', '77099', 'Roster Child QA', 'English', ?)
")->execute([$rosterPhone10]);

$okPost = postInbound($secret, [[
    'wa_msg_id' => 'false_91' . $rosterPhone10 . '@c.us_TEST_OK',
    'wa_chat_id' => '91' . $rosterPhone10 . '@c.us',
    'from_me' => false,
    'sender_wa_id' => '91' . $rosterPhone10 . '@c.us',
    'sender_name' => 'Roster Parent',
    'chat_type' => 'individual',
    'msg_type' => 'text',
    'body' => 'ok',
    'timestamp' => time()
]]);
assert($okPost['code'] === 200 && !empty($okPost['json']['success']), 'Roster ok message should ingest');
$okCount = $db->prepare('SELECT COUNT(*) FROM admission_enquiries WHERE phone_e164 = ?');
$okCount->execute([$rosterE164]);
assert((int)$okCount->fetchColumn() === 0, 'A roster parent saying ok must not become an enquiry');
echo "PASS: Roster parent 'ok' did not create an enquiry.\n";

$feePost = postInbound($secret, [[
    'wa_msg_id' => 'false_91' . $rosterPhone10 . '@c.us_TEST_FEE',
    'wa_chat_id' => '91' . $rosterPhone10 . '@c.us',
    'from_me' => false,
    'sender_wa_id' => '91' . $rosterPhone10 . '@c.us',
    'sender_name' => 'Roster Parent',
    'chat_type' => 'individual',
    'msg_type' => 'text',
    'body' => 'Please share the fee for nursery.',
    'timestamp' => time() + 2
]]);
assert($feePost['code'] === 200 && !empty($feePost['json']['success']), 'Roster fee message should ingest');
$feeRow = $db->prepare('SELECT id, interested_class FROM admission_enquiries WHERE phone_e164 = ? ORDER BY id DESC LIMIT 1');
$feeRow->execute([$rosterE164]);
$feeEnquiry = $feeRow->fetch(PDO::FETCH_ASSOC);
assert(!empty($feeEnquiry['id']), 'A roster parent asking about nursery fees must create an enquiry');
echo "PASS: Roster admission question created enquiry #{$feeEnquiry['id']}.\n";

$unknownPhone10 = '9822222222';
$unknownE164 = '+91' . $unknownPhone10;
$hiPost = postInbound($secret, [[
    'wa_msg_id' => 'false_91' . $unknownPhone10 . '@c.us_TEST_HI',
    'wa_chat_id' => '91' . $unknownPhone10 . '@c.us',
    'from_me' => false,
    'sender_wa_id' => '91' . $unknownPhone10 . '@c.us',
    'sender_name' => 'New Parent QA',
    'chat_type' => 'individual',
    'msg_type' => 'text',
    'body' => 'hi',
    'timestamp' => time()
]]);
assert($hiPost['code'] === 200 && !empty($hiPost['json']['success']), 'Unknown hi message should ingest');
$hiRow = $db->prepare('SELECT id, next_followup_date, followup_category FROM admission_enquiries WHERE phone_e164 = ?');
$hiRow->execute([$unknownE164]);
$hiEnquiry = $hiRow->fetch(PDO::FETCH_ASSOC);
assert(!empty($hiEnquiry['id']), 'An unknown number saying hi must create an enquiry');
assert($hiEnquiry['next_followup_date'] === date('Y-m-d'), 'Unknown enquiry follow-up should be today');
assert($hiEnquiry['followup_category'] === 'First response pending', 'Unknown enquiry should be awaiting a first response');
echo "PASS: Unknown number 'hi' created enquiry #{$hiEnquiry['id']} for today.\n";

$linkedRaw = (string)$db->query("SELECT phone_number FROM whatsapp_sessions WHERE session_name = 'default' LIMIT 1")->fetchColumn();
$linkedDigits = preg_replace('/\D/', '', $linkedRaw);
if (strlen($linkedDigits) === 10) $linkedDigits = '91' . $linkedDigits;
if (strlen($linkedDigits) >= 10) {
    $linkedE164 = '+' . $linkedDigits;
    $beforeLinked = $db->prepare('SELECT COUNT(*) FROM admission_enquiries WHERE phone_e164 = ?');
    $beforeLinked->execute([$linkedE164]);
    $linkedBefore = (int)$beforeLinked->fetchColumn();
    $linkedPost = postInbound($secret, [[
        'wa_msg_id' => 'false_' . $linkedDigits . '@c.us_TEST_SELF',
        'wa_chat_id' => $linkedDigits . '@c.us',
        'from_me' => false,
        'sender_wa_id' => $linkedDigits . '@c.us',
        'sender_name' => 'School Line',
        'chat_type' => 'individual',
        'msg_type' => 'text',
        'body' => 'hi from the linked school line',
        'timestamp' => time()
    ]]);
    assert($linkedPost['code'] === 200 && !empty($linkedPost['json']['success']), 'Linked-line message should still ingest into WhatsApp');
    $afterLinked = $db->prepare('SELECT COUNT(*) FROM admission_enquiries WHERE phone_e164 = ?');
    $afterLinked->execute([$linkedE164]);
    assert((int)$afterLinked->fetchColumn() === $linkedBefore, 'The linked school number must not become an admission enquiry');
    echo "PASS: Linked school number did not create an enquiry.\n";
}

// 6. Cleanup
echo "[6/6] Cleaning up test data...\n";
foreach ([$feeEnquiry['id'] ?? 0, $hiEnquiry['id'] ?? 0] as $extraId) {
    $extraId = (int)$extraId;
    if ($extraId < 1) continue;
    $db->prepare('DELETE FROM admission_enquiry_messages WHERE enquiry_id = ?')->execute([$extraId]);
    $db->prepare('DELETE FROM admission_followup_history WHERE enquiry_id = ?')->execute([$extraId]);
    $db->prepare('DELETE FROM admission_enquiries WHERE id = ?')->execute([$extraId]);
}
$db->prepare("DELETE FROM report_card WHERE roll_no = '77099'")->execute();
if (!empty($linkedDigits)) {
    $db->prepare('DELETE FROM whatsapp_messages WHERE wa_msg_id = ?')->execute(['false_' . $linkedDigits . '@c.us_TEST_SELF']);
}
foreach (['919833333333@c.us', '91' . $rosterPhone10 . '@c.us', '91' . $unknownPhone10 . '@c.us', $groupPhone] as $extraChat) {
    $extraChatId = $db->prepare('SELECT id FROM whatsapp_chats WHERE wa_chat_id = ?');
    $extraChatId->execute([$extraChat]);
    $extraChatDbId = $extraChatId->fetchColumn();
    if ($extraChatDbId) {
        $db->prepare('DELETE FROM whatsapp_messages WHERE chat_id = ?')->execute([(int)$extraChatDbId]);
        $db->prepare('DELETE FROM whatsapp_chats WHERE id = ?')->execute([(int)$extraChatDbId]);
    }
}
$db->prepare("DELETE FROM admission_enquiry_messages WHERE enquiry_id = ?")->execute([$enquiryId]);
$db->prepare("DELETE FROM admission_followup_history WHERE enquiry_id = ?")->execute([$enquiryId]);
$db->prepare("DELETE FROM admission_enquiries WHERE id = ?")->execute([$enquiryId]);
$cleanChatId = $db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id = '{$testWaChatId}'")->fetchColumn();
if ($cleanChatId) {
    $db->prepare("DELETE FROM whatsapp_messages WHERE chat_id = ?")->execute([(int)$cleanChatId]);
    $db->prepare("DELETE FROM whatsapp_chats WHERE id = ?")->execute([(int)$cleanChatId]);
}
@unlink($cookieFile);

echo "\n============================================================\n";
echo "SUCCESS: ALL WHATSAPP INBOUND ADMISSION TESTS PASSED (100%)\n";
echo "============================================================\n";
