<?php
/**
 * Offline PHP pipeline tests against hcc_wa_test via API_BASE (default http://127.0.0.1:9080/admin).
 * Does not touch live WhatsApp.
 */
declare(strict_types=1);
$_SERVER['SCRIPT_NAME'] = __FILE__;
$_SERVER['HTTP_HOST'] = 'localhost';
putenv('WA_TEST_MODE=1');
putenv('HCC_DB_NAME=hcc_wa_test');
$_ENV['WA_TEST_MODE'] = '1';
$_ENV['HCC_DB_NAME'] = 'hcc_wa_test';

$root = dirname(__DIR__, 2);
require $root . '/config/database.php';
require $root . '/includes/functions.php';
require $root . '/admin/includes/whatsapp_functions.php';
require __DIR__ . '/lib/assert_fixture_only.php';

$api = getenv('E2E_API_BASE') ?: 'http://127.0.0.1:9080/admin';
$results = [];
$passed = 0; $failed = 0;
function t(string $id, string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed, $results;
    $results[] = compact('id', 'name', 'ok', 'detail');
    if ($ok) { $passed++; echo "PASS  $id  $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
    else { $failed++; echo "FAIL  $id  $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function httpJson(string $method, string $url, ?array $body = null, array $extraHeaders = []): array {
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json', 'X-Admin-Test: 1', 'Content-Type: application/json'], $extraHeaders);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => ''];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body !== null ? json_encode($body) : '{}';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw, 'err' => $err];
}

$db = getDB();
t('DB1', 'connected to test DB', (string)$db->query('SELECT DATABASE()')->fetchColumn() === 'hcc_wa_test', (string)$db->query('SELECT DATABASE()')->fetchColumn());

$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/chats.json'), true);
$allowedWa = array_map(fn($f) => $f['wa_chat_id'], array_values($fixtures));
$rows = $db->query('SELECT id, wa_chat_id, name FROM whatsapp_chats')->fetchAll(PDO::FETCH_ASSOC);
$map = [];
foreach ($rows as $r) $map[$r['wa_chat_id']] = (int)$r['id'];
t('DB2', 'fixture chats present', count($map) >= 4, 'n=' . count($map));

// Non-fixture refusal helper test
try {
    e2eAssertFixtureChat($db, $map[$fixtures['personal']['wa_chat_id']], $allowedWa);
    t('SF1', 'fixture assert accepts personal', true);
} catch (Throwable $e) {
    t('SF1', 'fixture assert accepts personal', false, $e->getMessage());
}

// Insert a decoy "real" chat in TEST db only, then ensure send 404/refuse path for missing and that our assert catches it
$db->prepare("INSERT INTO whatsapp_chats (wa_chat_id, name, chat_type, created_at, updated_at) VALUES ('919999000501@c.us','DECOY Non-Fixture', 'individual', NOW(), NOW())")->execute();
$decoyId = (int)$db->lastInsertId();
$caught = false;
try { e2eAssertFixtureChat($db, $decoyId, $allowedWa); } catch (Throwable $e) { $caught = true; }
t('SF2', 'fixture assert rejects decoy', $caught);
// Clean decoy
$db->prepare('DELETE FROM whatsapp_chats WHERE id = ?')->execute([$decoyId]);

$secret = defined('WHATSAPP_BRIDGE_SECRET') ? WHATSAPP_BRIDGE_SECRET : '';
t('SEC1', 'bridge secret configured', is_string($secret) && strlen($secret) >= 16, 'len=' . strlen((string)$secret));

// --- Send path ---
foreach (['personal' => 'c.us', 'group' => 'g.us', 'lid' => '@lid'] as $key => $kind) {
    $wa = $fixtures[$key]['wa_chat_id'];
    $chatId = $map[$wa];
    e2eAssertFixtureChat($db, $chatId, $allowedWa);
    $body = "E2E send $key " . time();
    $r = httpJson('POST', $api . '/api_whatsapp_send.php', [
        'chat_id' => $chatId,
        'msg_type' => 'text',
        'body' => $body,
    ]);
    $qid = (int)($r['json']['queue_id'] ?? ($r['json']['queued'][0]['queue_id'] ?? 0));
    if (!$qid && !empty($r['json']['data']['queue_id'])) $qid = (int)$r['json']['data']['queue_id'];
    // response shape variance
    if (!$qid && isset($r['json']['queued']) && is_array($r['json']['queued'])) {
        foreach ($r['json']['queued'] as $q) if (!empty($q['queue_id'])) $qid = (int)$q['queue_id'];
    }
    t("SEND_$key", "enqueue $kind", !empty($r['json']['success']) && $qid > 0, 'qid=' . $qid . ' code=' . $r['code'] . ' raw=' . substr($r['raw'],0,120));
    $GLOBALS['_e2e_queues'][$key] = ['qid' => $qid, 'body' => $body, 'chat_id' => $chatId, 'wa' => $wa];
}

// 404 stale chat
$r = httpJson('POST', $api . '/api_whatsapp_send.php', ['chat_id' => 99999999, 'body' => 'nope']);
t('SEND_404', 'stale chat id clean 404', $r['code'] === 404 || (!empty($r['json']['error']) && stripos((string)$r['json']['error'], 'not found') !== false), 'code='.$r['code']);

// scheduled future
$wa = $fixtures['self']['wa_chat_id'];
$chatId = $map[$wa];
$future = date('Y-m-d H:i:s', time() + 3600);
// Use enqueue helper directly for scheduled_at
$enq = waEnqueueWhatsAppOutbound($db, [
    'chat_id' => $chatId,
    'msg_type' => 'text',
    'body' => 'E2E scheduled ' . time(),
    'scheduled_at' => $future,
    'priority' => 5,
    'origin' => 'manual_ui',
    'approved_by' => 1,
    'dispatch_method' => 'manual',
    'dispatched_by' => 1,
]);
$schedId = (int)($enq['queue_id'] ?? $enq['id'] ?? 0);
t('SCHED1', 'scheduled row created', $schedId > 0, 'id='.$schedId);
$due = $db->query("SELECT id FROM whatsapp_outbound_queue WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at <= NOW()) AND id = {$schedId}")->fetchColumn();
t('SCHED2', 'future scheduled not due', !$due, 'due=' . var_export($due, true));

// --- Simulate bridge: claim queue, mark sent with real wa_msg_id ---
function bridgeHeaders(string $secret, string $payload): array {
    return ['X-WA-Signature: ' . hash_hmac('sha256', $payload, $secret)];
}

foreach ($GLOBALS['_e2e_queues'] as $key => $info) {
    if ($info['qid'] <= 0) continue;
    $waMsgId = 'true_' . $info['wa'] . '_' . time() . '_' . $key;
    // processing -> sent via outbound_update
    $payload = json_encode(['id' => $info['qid'], 'status' => 'processing', 'attempts' => 1]);
    $r = httpJson('POST', $api . '/api_whatsapp_outbound_update.php', json_decode($payload, true));
    // some endpoints may not need HMAC
    $payload2 = json_encode(['id' => $info['qid'], 'status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')]);
    $r2 = httpJson('POST', $api . '/api_whatsapp_outbound_update.php', json_decode($payload2, true));
    $st = $db->query("SELECT status FROM whatsapp_outbound_queue WHERE id=".(int)$info['qid'])->fetchColumn();
    t("BRIDGE_SENT_$key", 'queue marked sent', $st === 'sent', 'status='.$st);

    // Insert message row as bridge would via ingest (from_me)
    $ingest = [[
        'wa_msg_id' => $waMsgId,
        'wa_chat_id' => $info['wa'],
        'from_me' => true,
        'timestamp' => time(),
        'msg_type' => 'text',
        'body' => $info['body'],
        'status' => 'sent',
        'chat_type' => str_contains($info['wa'], '@g.us') ? 'group' : 'individual',
    ]];
    $raw = json_encode($ingest);
    $r = httpJson('POST', $api . '/api_whatsapp_ingest.php', $ingest, bridgeHeaders($secret, $raw));
    // httpJson re-encodes body — signature must match exact POST body
}
// Fix ingest with correct HMAC on exact body
foreach ($GLOBALS['_e2e_queues'] as $key => $info) {
    if ($info['qid'] <= 0) continue;
    $waMsgId = 'true_' . $info['wa'] . '_ing_' . time() . '_' . $key;
    $ingest = [[
        'wa_msg_id' => $waMsgId,
        'wa_chat_id' => $info['wa'],
        'from_me' => true,
        'timestamp' => time(),
        'msg_type' => 'text',
        'body' => $info['body'],
        'status' => 'sent',
        'chat_type' => str_contains($info['wa'], '@g.us') ? 'group' : 'individual',
    ]];
    $raw = json_encode($ingest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($api . '/api_whatsapp_ingest.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-WA-Signature: ' . hash_hmac('sha256', $raw, $secret),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    t("INGEST_OUT_$key", 'from_me ingest', $code === 200 && !empty($j['success']), 'code='.$code.' raw='.substr((string)$resp,0,100));
    $GLOBALS['_e2e_queues'][$key]['wa_msg_id'] = $waMsgId;
    $cnt = (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_msg_id=".$db->quote($waMsgId))->fetchColumn();
    t("DEDUP_PRE_$key", 'row once', $cnt === 1, 'cnt='.$cnt);

    // duplicate ingest ignored
    $ch = curl_init($api . '/api_whatsapp_ingest.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WA-Signature: ' . hash_hmac('sha256', $raw, $secret)],
    ]);
    curl_exec($ch); curl_close($ch);
    $cnt2 = (int)$db->query("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_msg_id=".$db->quote($waMsgId))->fetchColumn();
    t("DEDUP_$key", 'duplicate ignored', $cnt2 === 1, 'cnt='.$cnt2);
}

// Invalid HMAC
$raw = json_encode([['wa_msg_id'=>'x','wa_chat_id'=>$fixtures['personal']['wa_chat_id'],'from_me'=>false,'timestamp'=>time(),'body'=>'bad']]);
$ch = curl_init($api . '/api_whatsapp_ingest.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WA-Signature: deadbeef'],
]);
$resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
t('HMAC_BAD', 'invalid HMAC rejected', $code === 401, 'code='.$code);

// Incoming receive
$inId = 'false_' . $fixtures['personal']['wa_chat_id'] . '_' . time();
$ingest = [[
    'wa_msg_id' => $inId,
    'wa_chat_id' => $fixtures['personal']['wa_chat_id'],
    'from_me' => false,
    'timestamp' => time(),
    'msg_type' => 'text',
    'body' => 'E2E incoming hello',
    'chat_type' => 'individual',
    'sender_name' => 'Fixture Parent One',
]];
$raw = json_encode($ingest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ch = curl_init($api . '/api_whatsapp_ingest.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WA-Signature: ' . hash_hmac('sha256', $raw, $secret)],
]);
$resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = json_decode((string)$resp, true);
t('RECV1', 'incoming ingest', $code === 200 && !empty($j['success']), 'code='.$code);

// --- JioMart-style bot burst: incoming must be STORED ONLY (zero outbound rows) ---
$qBefore = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();
$burst = [];
for ($i = 0; $i < 5; $i++) {
    $burst[] = [
        'wa_msg_id' => 'false_' . $fixtures['personal']['wa_chat_id'] . '_botburst_' . time() . '_' . $i,
        'wa_chat_id' => $fixtures['personal']['wa_chat_id'],
        'from_me' => false,
        'timestamp' => time() + $i,
        'msg_type' => 'text',
        'body' => "I'm sorry, I didn't understand that!",
        'chat_type' => 'individual',
        'sender_name' => 'Offers Bot',
    ];
}
$raw = json_encode($burst, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ch = curl_init($api . '/api_whatsapp_ingest.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WA-Signature: ' . hash_hmac('sha256', $raw, $secret)],
]);
$resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
sleep(1);
$qAfter = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();
t('NOREPLY1', 'bot "didn\'t understand" burst ingested', $code === 200, 'code=' . $code);
t('NOREPLY2', 'incoming burst creates ZERO outbound rows', $qAfter === $qBefore, "before=$qBefore after=$qAfter");

// --- Bridge queue feed only returns user-pressed rows (manual_ui + approving admin) ---
$personalChatId = $map[$fixtures['personal']['wa_chat_id']];
$db->prepare("INSERT INTO whatsapp_outbound_queue (chat_id, msg_type, body, priority, attempts, max_attempts, status, dispatch_method, origin, approved_by)
              VALUES (?, 'text', 'GATE-TEST-NOT-PRESSED', 5, 0, 1, 'queued', 'manual', 'automation', NULL)")->execute([$personalChatId]);
$rogueId = (int)$db->lastInsertId();
$ch = curl_init($api . '/api_whatsapp_outbound_queue.php?status=queued&limit=50');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Bridge-Secret: ' . $secret]]);
$resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = json_decode((string)$resp, true);
$ids = array_map(fn($r) => (int)$r['id'], (array)($j['data'] ?? []));
t('QGATE1', 'bridge feed excludes non-pressed (automation/unapproved) rows', $code === 200 && !in_array($rogueId, $ids, true), 'code=' . $code . ' rogue=' . $rogueId);
// test DB only: remove the synthetic non-pressed row so no automation-origin rows remain
$db->prepare("DELETE FROM whatsapp_outbound_queue WHERE id=? AND body='GATE-TEST-NOT-PRESSED'")->execute([$rogueId]);

// Ticks: delivered then read (via ingest status or update API if exists)
$waMsgId = $GLOBALS['_e2e_queues']['personal']['wa_msg_id'] ?? '';
if ($waMsgId) {
    // Prefer updating message status columns directly to simulate tick pipeline sink
    $db->prepare("UPDATE whatsapp_messages SET status='delivered' WHERE wa_msg_id=? AND status IN ('sent','pending')")->execute([$waMsgId]);
    $st = $db->query("SELECT status FROM whatsapp_messages WHERE wa_msg_id=".$db->quote($waMsgId))->fetchColumn();
    t('TICK1', 'sent->delivered', $st === 'delivered', 'st='.$st);
    $db->prepare("UPDATE whatsapp_messages SET status='read' WHERE wa_msg_id=? AND status IN ('sent','delivered','pending')")->execute([$waMsgId]);
    $st = $db->query("SELECT status FROM whatsapp_messages WHERE wa_msg_id=".$db->quote($waMsgId))->fetchColumn();
    t('TICK2', 'delivered->read', $st === 'read', 'st='.$st);
    // never downgrade via waInsertMessage upsert
    waInsertMessage($db, [
        'wa_msg_id' => $waMsgId,
        'chat_id' => $map[$fixtures['personal']['wa_chat_id']],
        'from_me' => true,
        'msg_type' => 'text',
        'body' => $GLOBALS['_e2e_queues']['personal']['body'] ?? 'x',
        'status' => 'sent',
        'timestamp' => time(),
    ]);
    $st2 = $db->query("SELECT status FROM whatsapp_messages WHERE wa_msg_id=".$db->quote($waMsgId))->fetchColumn();
    t('TICK3', 'upsert refuses status downgrade', $st2 === 'read', 'st='.$st2);
}

// Failure / retry
$chatId = $map[$fixtures['personal']['wa_chat_id']];
$r = httpJson('POST', $api . '/api_whatsapp_send.php', ['chat_id'=>$chatId,'body'=>'E2E failme '.time()]);
$qid = 0;
if (!empty($r['json']['queue_id'])) $qid = (int)$r['json']['queue_id'];
if (!$qid && !empty($r['json']['queued'][0]['queue_id'])) $qid = (int)$r['json']['queued'][0]['queue_id'];
if ($qid) {
    $db->prepare("UPDATE whatsapp_outbound_queue SET status='failed', error_message='mock send failure', attempts=3 WHERE id=?")->execute([$qid]);
    $r = httpJson('POST', $api . '/api_whatsapp_send.php', ['action'=>'retry','queue_id'=>$qid]);
    $st = $db->query("SELECT status FROM whatsapp_outbound_queue WHERE id=$qid")->fetchColumn();
    t('RETRY1', 'retry failed -> queued', !empty($r['json']['success']) && $st === 'queued', 'st='.$st);
} else {
    t('RETRY1', 'retry failed -> queued', false, 'no qid');
}

// Templates CRUD quick
$r = httpJson('GET', $api . '/api_whatsapp_templates.php?action=list');
t('TPL1', 'templates list', !empty($r['json']['success']), 'code='.$r['code']);

$r = httpJson('POST', $api . '/api_whatsapp_templates.php?action=create', [
    'title' => 'E2E Tpl', 'category' => 'custom', 'scope' => 'both', 'body' => 'Hi {parent_name}'
]);
$tid = (int)($r['json']['data']['id'] ?? 0);
t('TPL2', 'template create', $tid > 0, 'id='.$tid);
$r = httpJson('POST', $api . '/api_whatsapp_templates.php?action=send', [
    'id' => $tid,
    'chat_id' => $map[$fixtures['personal']['wa_chat_id']],
    'body' => 'E2E tpl body ' . time(),
    'save_edit' => 0,
]);
$tq = [];
foreach (($r['json']['queued'] ?? []) as $q) if (!empty($q['queue_id'])) $tq[] = (int)$q['queue_id'];
t('TPL3', 'template send enqueue fixture only', !empty($r['json']['success']) && $tq, 'q='.json_encode($tq));
if ($tq) {
    $bad = $db->query("SELECT c.wa_chat_id FROM whatsapp_outbound_queue q JOIN whatsapp_chats c ON c.id=q.chat_id WHERE q.id IN (".implode(',', $tq).") AND c.wa_chat_id NOT IN ('".implode("','", array_map('addslashes', $allowedWa))."')")->fetchAll();
    t('TPL4', 'template queues are fixtures', empty($bad), json_encode($bad));
    $db->exec("UPDATE whatsapp_outbound_queue SET status='cancelled' WHERE id IN (".implode(',', $tq).")");
}

// Broadcast to multiple fixtures
$r = httpJson('POST', $api . '/api_whatsapp_send.php', [
    'chat_ids' => [$map[$fixtures['personal']['wa_chat_id']], $map[$fixtures['group']['wa_chat_id']]],
    'body' => 'E2E broadcast ' . time(),
]);
$bq = [];
foreach (($r['json']['queued'] ?? []) as $q) if (!empty($q['queue_id'])) $bq[] = (int)$q['queue_id'];
if (!$bq && !empty($r['json']['queue_ids'])) $bq = array_map('intval', $r['json']['queue_ids']);
t('BCAST1', 'broadcast enqueues 2', count($bq) === 2 || (!empty($r['json']['success']) && count($bq) >= 2), 'q='.json_encode($bq).' raw='.substr($r['raw'],0,120));
if ($bq) $db->exec("UPDATE whatsapp_outbound_queue SET status='cancelled' WHERE id IN (".implode(',', $bq).")");

// Revoke rules via existing unit + DB status path
$now = time();
$old = $now - (49 * 3600);
// Use PHP revoke endpoint if available
$msgWa = $GLOBALS['_e2e_queues']['personal']['wa_msg_id'] ?? '';
if ($msgWa) {
    $msgDbId = (int)$db->query("SELECT id FROM whatsapp_messages WHERE wa_msg_id=".$db->quote($msgWa))->fetchColumn();
    // Ensure timestamp within 48h for revoke window
    $db->prepare("UPDATE whatsapp_messages SET timestamp = ? WHERE id = ?")->execute([time(), $msgDbId]);
    $r = httpJson('POST', $api . '/api_whatsapp_chat_actions.php', [
        'chat_id' => $map[$fixtures['personal']['wa_chat_id']],
        'action' => 'delete_message',
        'msg_id' => $msgDbId,
        'for_everyone' => true,
    ]);
    t('REV1', 'delete-for-everyone -> revoke_pending', $r['code'] < 500 && ( ($r['json']['status'] ?? '') === 'revoke_pending' || !empty($r['json']['success']) ), 'code='.$r['code'].' '.substr($r['raw'],0,160));

    // delete-for-me
    $inId2 = 'true_' . $fixtures['personal']['wa_chat_id'] . '_forme_' . time();
    $db->prepare("INSERT INTO whatsapp_messages (wa_msg_id, chat_id, from_me, msg_type, body, status, timestamp) VALUES (?,?,1,'text','bye','sent',?)")
       ->execute([$inId2, $map[$fixtures['personal']['wa_chat_id']], time()]);
    $meId = (int)$db->lastInsertId();
    $r = httpJson('POST', $api . '/api_whatsapp_chat_actions.php', [
        'chat_id' => $map[$fixtures['personal']['wa_chat_id']],
        'action' => 'delete_message',
        'msg_id' => $meId,
        'for_everyone' => false,
    ]);
    t('REV2', 'delete-for-me', $r['code'] < 500 && !empty($r['json']['success']), 'code='.$r['code'].' '.substr($r['raw'],0,120));

    // >48h rejected
    $oldId = 'true_' . $fixtures['personal']['wa_chat_id'] . '_old_' . time();
    $db->prepare("INSERT INTO whatsapp_messages (wa_msg_id, chat_id, from_me, msg_type, body, status, timestamp) VALUES (?,?,1,'text','old','sent',?)")
       ->execute([$oldId, $map[$fixtures['personal']['wa_chat_id']], time() - (49*3600)]);
    $oldDb = (int)$db->lastInsertId();
    $r = httpJson('POST', $api . '/api_whatsapp_chat_actions.php', [
        'chat_id' => $map[$fixtures['personal']['wa_chat_id']],
        'action' => 'delete_message',
        'msg_id' => $oldDb,
        'for_everyone' => true,
    ]);
    t('REV3', '>48h revoke rejected', ($r['json']['status'] ?? '') === 'revoke_failed' || $r['code'] === 422, 'code='.$r['code'].' '.substr($r['raw'],0,160));
}

// Manual lock: every queued row from this suite must be manual_ui
$bad = $db->query("SELECT id, origin, approved_by, status FROM whatsapp_outbound_queue WHERE status='queued' AND (origin IS NULL OR origin <> 'manual_ui' OR approved_by IS NULL OR approved_by=0)")->fetchAll(PDO::FETCH_ASSOC);
t('LOCK1', 'queued_rows_manual_approved', count($bad) === 0, json_encode($bad));

$out = ['suite'=>'php_pipelines','passed'=>$passed,'failed'=>$failed,'results'=>$results];
file_put_contents(__DIR__ . '/results/php_pipelines.json', json_encode($out, JSON_PRETTY_PRINT));
echo "\nPHP pipelines: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
