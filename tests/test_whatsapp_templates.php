<?php
/**
 * Template library CRUD, placeholder fill, save-on-edit, and stubbed queue send.
 * Does NOT talk to live WhatsApp.
 *
 * Run: php tests/test_whatsapp_templates.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/admin/includes/whatsapp_functions.php';

$passed = 0; $failed = 0; $results = [];
function t(string $id, string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed, $results;
    $results[] = compact('id', 'name', 'ok', 'detail');
    if ($ok) { $passed++; echo "PASS  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
    else { $failed++; echo "FAIL  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function httpJson(string $method, string $url, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'X-Admin-Test: 1', 'Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIE => 'qa_test=1',
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body !== null ? json_encode($body) : '{}';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw];
}

$base = 'http://127.0.0.1/hcc/admin/api_whatsapp_templates.php';
$db = getDB();

// Ensure table
try { $db->query('SELECT 1 FROM whatsapp_message_templates LIMIT 1'); }
catch (Throwable $e) {
    $sql = file_get_contents($root . '/data/migrations/create_whatsapp_message_templates.sql');
    $db->exec((string)$sql);
}

// T1 list starters
$r = httpJson('GET', $base . '?action=list&qa_test=1');
$list = $r['json']['data'] ?? [];
t('T1', 'list templates', !empty($r['json']['success']) && count($list) >= 9, 'n=' . count($list));

// T2 placeholder fill helper
$filled = waFillTemplatePlaceholders(
    'Hi {parent_name}, re {student_name} ({class}) on {date}',
    ['parent_name' => 'Asha', 'student_name' => 'Riya', 'class' => 'STD I A', 'date' => '2026-11-05']
);
t('T2', 'placeholder fill', $filled === 'Hi Asha, re Riya (STD I A) on 2026-11-05', $filled);

// T3 create
$r = httpJson('POST', $base . '?action=create&qa_test=1', [
    'title' => 'Unit Test Template',
    'category' => 'custom',
    'scope' => 'both',
    'body' => 'Hello {parent_name} — {student_name} / {class} / {date}'
]);
$createdId = (int)($r['json']['data']['id'] ?? 0);
t('T3', 'create template', !empty($r['json']['success']) && $createdId > 0, 'id=' . $createdId);

// T4 update (save-on-edit)
$r = httpJson('POST', $base . '?action=update&qa_test=1', [
    'id' => $createdId,
    'title' => 'Unit Test Template Updated',
    'body' => 'Updated body for {parent_name}'
]);
t('T4', 'update / save-on-edit', !empty($r['json']['success']));

// T5 save_as_new
$r = httpJson('POST', $base . '?action=save_as_new&qa_test=1', [
    'id' => $createdId,
    'title' => 'Unit Test Template Copy',
    'body' => 'Copy body {student_name}',
    'scope' => 'personal',
    'category' => 'custom'
]);
$copyId = (int)($r['json']['data']['id'] ?? 0);
t('T5', 'save as new', !empty($r['json']['success']) && $copyId > 0 && $copyId !== $createdId, 'id=' . $copyId);

// T6 duplicate + rename
$r = httpJson('POST', $base . '?action=duplicate&qa_test=1', ['id' => $createdId]);
$dupId = (int)($r['json']['data']['id'] ?? 0);
t('T6a', 'duplicate', !empty($r['json']['success']) && $dupId > 0, 'id=' . $dupId);
$r = httpJson('POST', $base . '?action=rename&qa_test=1', ['id' => $dupId, 'title' => 'Renamed Dup']);
t('T6b', 'rename', !empty($r['json']['success']));

// T7 fill via API — ONLY fixture chats (never ORDER BY id DESC on live chats)
$fixtureWa = '919999000001@c.us'; // offline e2e fixture; also created below if missing
$chatId = (int)$db->query('SELECT id FROM whatsapp_chats WHERE wa_chat_id = ' . $db->quote($fixtureWa))->fetchColumn();
if ($chatId <= 0) {
    $db->prepare('INSERT INTO whatsapp_chats (wa_chat_id, name, chat_type, created_at, updated_at) VALUES (?,?,?,?,?)')
       ->execute([$fixtureWa, 'Fixture Parent One', 'individual', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $chatId = (int)$db->lastInsertId();
}
// Safety: refuse if the chosen chat is not the fixture JID
$chosenWa = (string)$db->query('SELECT wa_chat_id FROM whatsapp_chats WHERE id = ' . (int)$chatId)->fetchColumn();
if ($chosenWa !== $fixtureWa) {
    t('T7', 'fill via API', false, 'refused non-fixture chat id=' . $chatId . ' wa=' . $chosenWa);
    t('T8', 'template send enqueues outbound', false, 'skipped — non-fixture chat');
} else {
$r = httpJson('GET', $base . '?action=fill&id=' . $createdId . '&chat_id=' . $chatId . '&qa_test=1');
t('T7', 'fill via API', !empty($r['json']['success']) && isset($r['json']['data']['body']));

// T8 send through queue (stub — enqueue only; cancel afterward so bridge will not send)
$r = httpJson('POST', $base . '?action=send&qa_test=1', [
    'id' => $createdId,
    'chat_id' => $chatId,
    'body' => 'HCC template queue stub — do not deliver ' . time(),
    'save_edit' => 0
]);
$queued = $r['json']['queued'] ?? [];
$queueIds = [];
foreach ($queued as $q) { if (!empty($q['queue_id'])) $queueIds[] = (int)$q['queue_id']; }
$okSend = !empty($r['json']['success']) && !empty($queueIds);
t('T8', 'template send enqueues outbound', $okSend, 'queues=' . json_encode($queueIds));

// Cancel / mark cancelled so live bridge does not send
if ($okSend) {
    $stmt = $db->prepare("UPDATE whatsapp_outbound_queue SET status = 'cancelled' WHERE id = ?");
    foreach ($queueIds as $qid) {
        $stmt->execute([(int)$qid]);
    }
}
} // end fixture-chat else

// T10-safety: fail if any test queue row targets a non-fixture chat created by this run
$nonFix = $db->query("SELECT q.id, c.wa_chat_id FROM whatsapp_outbound_queue q JOIN whatsapp_chats c ON c.id=q.chat_id WHERE q.body LIKE 'HCC template queue stub%' AND c.wa_chat_id <> '919999000001@c.us' AND q.created_at >= (NOW() - INTERVAL 1 HOUR)")->fetchAll();
t('T8b', 'no non-fixture template queue targets', empty($nonFix), 'bad=' . json_encode($nonFix));

// T9 delete (archive)
$r = httpJson('POST', $base . '?action=delete&qa_test=1', ['id' => $createdId]);
t('T9a', 'delete created', !empty($r['json']['success']));
if ($copyId) httpJson('POST', $base . '?action=delete&qa_test=1', ['id' => $copyId]);
if ($dupId) httpJson('POST', $base . '?action=delete&qa_test=1', ['id' => $dupId]);

// T10 search / category
$r = httpJson('GET', $base . '?action=list&qa_test=1&category=fees&q=fee');
t('T10', 'search + category filter', !empty($r['json']['success']) && is_array($r['json']['data'] ?? null));

echo "\nSummary: passed={$passed} failed={$failed}\n";
file_put_contents($root . '/tests/last_templates_result.json', json_encode([
    'passed' => $passed, 'failed' => $failed, 'results' => $results, 'at' => date('c')
], JSON_PRETTY_PRINT));
exit($failed > 0 ? 1 : 0);
