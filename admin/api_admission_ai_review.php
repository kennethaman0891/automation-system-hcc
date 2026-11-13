<?php
/**
 * AI Admission Review API — user-triggered analyze → draft → review → manual send.
 * NEVER auto-sends. Analyze/save/review do not enqueue WhatsApp.
 *
 * GET  ?action=health|list_chats|list_messages|list_drafts|get_draft
 * POST ?action=analyze|save_draft|mark_reviewed|send|create_enquiry|delete_draft
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/includes/admission_ai_review.php';
require_once __DIR__ . '/includes/admission_ai_api.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$auth = waRequireAuth(); // real admin session only — rejects bridge / QA bypass
$db = waGetDB();
airEnsureReviewSchema($db);

$raw = file_get_contents('php://input');
$input = [];
if ($raw !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}
$input = array_merge($_GET, $_POST, $input);
$action = (string)($input['action'] ?? $_GET['action'] ?? '');

function airJson(array $data, int $code = 200): void {
    waJsonResponse($data, $code);
}

function airRequireCsrf(array $input): void {
    $token = (string)($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        airJson(['success' => false, 'error' => 'Invalid or missing CSRF token. Refresh the page.'], 403);
    }
}

function airDraftRow(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM admission_ai_review_drafts WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function airFormatDraft(array $row): array {
    $reply = $row['edited_reply'] !== null && $row['edited_reply'] !== ''
        ? $row['edited_reply']
        : $row['suggested_reply'];
    return [
        'id' => (int)$row['id'],
        'chat_id' => $row['chat_id'] !== null ? (int)$row['chat_id'] : null,
        'source_message_ids' => json_decode($row['source_message_ids'] ?: '[]', true) ?: [],
        'analyzed_snapshot' => json_decode($row['analyzed_snapshot'] ?: '[]', true) ?: [],
        'classification' => $row['classification'],
        'explanation' => $row['explanation'],
        'evidence_quotes' => json_decode($row['evidence_quotes'] ?: '[]', true) ?: [],
        'parent_name' => $row['parent_name'],
        'student_name' => $row['student_name'],
        'interested_class' => $row['interested_class'],
        'academic_year' => $row['academic_year'],
        'age_or_dob' => $row['age_or_dob'],
        'contact_number' => $row['contact_number'],
        'contact_number_masked' => airMaskPhone($row['contact_number']),
        'contact_number_source' => $row['contact_number_source'],
        'question_summary' => $row['question_summary'],
        'missing_details' => json_decode($row['missing_details'] ?: '[]', true) ?: [],
        'suggested_reply' => $row['suggested_reply'],
        'edited_reply' => $row['edited_reply'],
        'reply_for_send' => $reply,
        'status' => $row['status'],
        'model_used' => $row['model_used'],
        'analysis_engine' => $row['analysis_engine'] ?? 'local',
        'analysis_provider' => $row['analysis_provider'] ?? null,
        'analysis_model' => $row['analysis_model'] ?? null,
        'analysis_endpoint_host' => $row['analysis_endpoint_host'] ?? null,
        'analyzed_at' => $row['analyzed_at'],
        'analyzed_by' => $row['analyzed_by'] !== null ? (int)$row['analyzed_by'] : null,
        'reviewed_at' => $row['reviewed_at'],
        'reviewed_by' => $row['reviewed_by'] !== null ? (int)$row['reviewed_by'] : null,
        'enquiry_id' => $row['enquiry_id'] !== null ? (int)$row['enquiry_id'] : null,
        'send_queue_id' => $row['send_queue_id'] !== null ? (int)$row['send_queue_id'] : null,
        'source_wa_msg_id' => $row['source_wa_msg_id'],
        'input_mode' => $row['input_mode'],
        'error_message' => $row['error_message'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

try {
switch ($action) {

case 'health':
    $disc = airDiscoverLocalAi();
    $inbound = (int)$db->query('SELECT COUNT(*) FROM whatsapp_messages WHERE from_me = 0')->fetchColumn();
    $latestTs = (int)$db->query('SELECT MAX(timestamp) FROM whatsapp_messages WHERE from_me = 0')->fetchColumn();
    airJson([
        'success' => true,
        'ai' => $disc,
        'engine_settings' => airGetEngineSettings(),
        'inbound_message_count' => $inbound,
        'latest_inbound_ts' => $latestTs,
        'bridge_connected' => airIsBridgeConnected($db),
        'csrf_token' => generateCSRFToken(),
    ]);
    break;

case 'get_settings':
    airJson([
        'success' => true,
        'settings' => airGetEngineSettings(),
        'local_ai' => airDiscoverLocalAi(),
        'csrf_token' => generateCSRFToken(),
    ]);
    break;

case 'save_settings':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    if (($auth['admin_role'] ?? '') !== 'admin') {
        airJson(['success' => false, 'error' => 'Only administrators can change AI API settings'], 403);
    }
    $saveIn = [
        'engine' => $input['engine'] ?? null,
        'provider' => $input['provider'] ?? null,
        'base_url' => $input['base_url'] ?? null,
        'model' => $input['model'] ?? null,
        'remove_key' => !empty($input['remove_key']),
    ];
    if (isset($input['api_key']) && is_string($input['api_key']) && trim($input['api_key']) !== '') {
        $saveIn['api_key'] = trim($input['api_key']);
    }
    $res = airSaveEngineSettings($saveIn);
    if (!$res['ok']) {
        airJson(['success' => false, 'error' => $res['error'] ?? 'Save failed'], 400);
    }
    airJson(['success' => true, 'settings' => $res['settings'], 'message' => 'Settings saved. API key is never shown again.']);
    break;

case 'test_connection':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    if (($auth['admin_role'] ?? '') !== 'admin') {
        airJson(['success' => false, 'error' => 'Only administrators can test API connection'], 403);
    }
    $settings = airGetEngineSettings();
    $base = trim((string)($input['base_url'] ?? $settings['base_url']));
    $model = trim((string)($input['model'] ?? $settings['model']));
    $provider = trim((string)($input['provider'] ?? $settings['provider']));
    if ($provider === 'nvidia' && $base === '') $base = airNvidiaDefaultBaseUrl();
    $key = airLoadApiKey();
    if (isset($input['api_key']) && is_string($input['api_key']) && trim($input['api_key']) !== '') {
        $key = trim($input['api_key']);
    }
    if (!$key) {
        airJson(['success' => false, 'error' => 'No API key configured. Paste a key (or Save one first) then Test.'], 400);
    }
    if ($model === '') {
        airJson(['success' => false, 'error' => 'Enter a model ID before testing. Defaults are not guessed.'], 400);
    }
    $urlCheck = airValidateHttpsEndpoint($base);
    if (!$urlCheck['ok']) {
        airJson(['success' => false, 'error' => $urlCheck['error'] ?? 'Invalid URL'], 400);
    }
    $samplePrompt = airBuildAnalyzePrompt(
        'Synthetic connection test only. Reply with valid JSON classification unclear.',
        json_encode(['input_mode' => 'connection_test']),
        '(connection test — no school facts required)'
    );
    $gen = airCallOpenAiCompatible($base, $model, $key, $samplePrompt, 45);
    if (!$gen['ok']) {
        airJson([
            'success' => false,
            'error' => $gen['error'] ?? 'Connection test failed',
            'http_status' => $gen['http_status'] ?? null,
            'endpoint_host' => $gen['host'] ?? ($urlCheck['host'] ?? null),
        ], 502);
    }
    $val = airValidateModelJson($gen['raw'] ?? '');
    airJson([
        'success' => true,
        'message' => 'Connection OK. Provider responded.',
        'endpoint_host' => $gen['host'] ?? $urlCheck['host'],
        'model' => $model,
        'json_valid' => $val['ok'],
        'ms' => $gen['ms'] ?? null,
        'note' => 'Synthetic sample only — not your WhatsApp messages. No draft saved.',
    ]);
    break;

case 'list_chats':
    // One row per stored incoming message, newest first. The admission list
    // shows every inbound line, not one collapsed snippet per chat.
    $limit = min(100, max(1, (int)($input['limit'] ?? 80)));
    $sql = "
        SELECT m.id AS message_id, c.id AS chat_pk, c.wa_chat_id, c.name, c.chat_type,
               c.is_admission_enquiry, m.timestamp AS last_inbound_ts,
               CASE WHEN JSON_VALID(m.metadata) THEN JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.time_precision')) ELSE NULL END AS time_precision,
               CASE
                   WHEN COALESCE(m.body,'') != '' THEN m.body
                   WHEN m.msg_type = 'image' THEN '📷 [Photo]'
                   WHEN m.msg_type = 'audio' THEN '🎤 [Voice message]'
                   WHEN m.msg_type = 'video' THEN '🎥 [Video]'
                   WHEN m.msg_type = 'document' THEN '📄 [Document]'
                   ELSE '[Attachment]'
               END AS last_snippet
        FROM whatsapp_messages m
        INNER JOIN whatsapp_chats c ON c.id = m.chat_id
        WHERE m.from_me = 0 AND c.chat_type = 'individual'
        ORDER BY m.timestamp DESC, m.id DESC
        LIMIT {$limit}
    ";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $phoneInfo = airPhoneFromWaChatId($r['wa_chat_id']);
        $snippet = (string)$r['last_snippet'];
        if (function_exists('mb_substr')) {
            $snippet = mb_substr($snippet, 0, 180);
        } else {
            $snippet = substr($snippet, 0, 180);
        }
        $out[] = [
            'message_id' => (int)$r['message_id'],
            'chat_id' => (int)$r['chat_pk'],
            'name' => $r['name'] ?: 'Unknown',
            'chat_type' => $r['chat_type'],
            'phone_masked' => airMaskPhone($phoneInfo['phone'] ?? ''),
            'phone_known' => $phoneInfo['source'] === 'chat_identity',
            'is_admission_enquiry' => (bool)$r['is_admission_enquiry'],
            'last_inbound_ts' => (int)$r['last_inbound_ts'],
            'time_precision' => in_array($r['time_precision'] ?? '', ['day', 'minute', 'second'], true) ? $r['time_precision'] : '',
            'last_snippet' => $snippet,
            '_wa' => (string)$r['wa_chat_id'],
        ];
    }
    // The same photo was sometimes stored twice: once under the phone and once
    // under title:name@lid. Keep the phone row.
    $kept = [];
    foreach ($out as $row) {
        $isTitle = !$row['phone_known'] && preg_match('/^title:.+@lid$/i', $row['_wa']);
        if ($isTitle) {
            $name = mb_strtolower(trim($row['name']));
            $drop = false;
            foreach ($out as $other) {
                if ($other['message_id'] === $row['message_id'] || !$other['phone_known']) continue;
                if (mb_strtolower(trim($other['name'])) !== $name) continue;
                if ($other['last_snippet'] !== $row['last_snippet']) continue;
                if (abs($other['last_inbound_ts'] - $row['last_inbound_ts']) <= 180) {
                    $drop = true;
                    break;
                }
            }
            if ($drop) continue;
        }
        unset($row['_wa']);
        $kept[] = $row;
    }
    airJson([
        'success' => true,
        'data' => $kept,
        'empty' => count($kept) === 0,
        'empty_message' => count($kept) === 0
            ? 'No received messages available. Inbound WhatsApp messages have not been stored yet. You can paste parent text below for analysis (identity will stay unknown until a real chat is linked).'
            : null,
    ]);
    break;

case 'list_messages':
    $chatId = (int)($input['chat_id'] ?? 0);
    if ($chatId <= 0) airJson(['success' => false, 'error' => 'chat_id required'], 400);
    $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
    $st = $db->prepare("
        SELECT id, wa_msg_id, from_me, sender_name, msg_type,
               CASE
                   WHEN COALESCE(body,'') != '' THEN body
                   WHEN msg_type = 'image' THEN '📷 [Photo]'
                   WHEN msg_type = 'audio' THEN '🎤 [Voice message]'
                   WHEN msg_type = 'video' THEN '🎥 [Video]'
                   WHEN msg_type = 'document' THEN '📄 [Document]'
                   ELSE '[Attachment]'
               END AS body,
               timestamp, status, received_at,
               CASE WHEN JSON_VALID(metadata) THEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.time_precision')) ELSE NULL END AS time_precision
        FROM whatsapp_messages
        WHERE chat_id = ? AND from_me = 0
        ORDER BY timestamp DESC, id DESC
        LIMIT {$limit}
    ");
    $st->execute([$chatId]);
    $msgs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $msgs[] = [
            'id' => (int)$m['id'],
            'wa_msg_id' => $m['wa_msg_id'],
            'sender_name' => $m['sender_name'],
            'msg_type' => $m['msg_type'],
            'body' => $m['body'],
            'timestamp' => (int)$m['timestamp'],
            'time_precision' => in_array($m['time_precision'] ?? '', ['day', 'minute', 'second'], true) ? $m['time_precision'] : '',
            'received_at' => $m['received_at'],
        ];
    }
    $chat = $db->prepare('SELECT id, name, wa_chat_id, chat_type FROM whatsapp_chats WHERE id = ?');
    $chat->execute([$chatId]);
    $c = $chat->fetch(PDO::FETCH_ASSOC);
    airJson([
        'success' => true,
        'chat' => $c ? [
            'chat_id' => (int)$c['id'],
            'name' => $c['name'],
            'phone_masked' => airMaskPhone(airPhoneFromWaChatId($c['wa_chat_id'])['phone'] ?? $c['wa_chat_id']),
        ] : null,
        'data' => array_reverse($msgs), // chronological for display
        'analyze_hint' => 'Only the inbound messages you tick will be sent to the local AI. Nothing is sent on WhatsApp until you review a draft and press Send.',
    ]);
    break;

case 'list_drafts':
    $status = trim((string)($input['status'] ?? ''));
    $limit = min(100, max(1, (int)($input['limit'] ?? 50)));
    if ($status !== '') {
        $st = $db->prepare("SELECT * FROM admission_ai_review_drafts WHERE status = ? ORDER BY id DESC LIMIT {$limit}");
        $st->execute([$status]);
    } else {
        $st = $db->query("SELECT * FROM admission_ai_review_drafts ORDER BY id DESC LIMIT {$limit}");
    }
    $data = array_map('airFormatDraft', $st->fetchAll(PDO::FETCH_ASSOC));
    airJson(['success' => true, 'data' => $data]);
    break;

case 'get_draft':
    $id = (int)($input['id'] ?? 0);
    $row = airDraftRow($db, $id);
    if (!$row) airJson(['success' => false, 'error' => 'Draft not found'], 404);
    $fmt = airFormatDraft($row);
    $fmt['bridge_connected'] = airIsBridgeConnected($db);
    airJson(['success' => true, 'data' => $fmt]);
    break;

case 'analyze':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);

    $settings = airGetEngineSettings();
    $engine = (string)($input['engine'] ?? $settings['engine'] ?? 'local');
    if (!in_array($engine, ['local', 'api'], true)) {
        airJson(['success' => false, 'error' => 'Invalid analysis engine'], 400);
    }
    $disc = airDiscoverLocalAi();
    if ($engine === 'local') {
        if (empty($disc['ollama']) && empty($disc['service'])) {
            airJson([
                'success' => false,
                'error' => $disc['error'] ?? 'Local AI unavailable',
                'ai' => $disc,
                'hint' => 'Start Ollama, or switch Analysis engine to API. No silent fallback.',
            ], 503);
        }
    } else {
        if (!airHasApiKey()) {
            airJson(['success' => false, 'error' => 'API key not configured. Save a key in AI settings first.'], 400);
        }
        $apiModelCheck = trim((string)($input['model'] ?? $settings['model'] ?? ''));
        if ($apiModelCheck === '') {
            airJson(['success' => false, 'error' => 'API model ID is required (enter in settings; no default guessed).'], 400);
        }
        $base = trim((string)($settings['base_url'] ?? ''));
        if ($base === '' && ($settings['provider'] ?? '') === 'nvidia') $base = airNvidiaDefaultBaseUrl();
        $baseCheck = airValidateHttpsEndpoint($base);
        if (!$baseCheck['ok']) {
            airJson(['success' => false, 'error' => $baseCheck['error'] ?? 'Invalid API base URL'], 400);
        }
    }

    $chatId = (int)($input['chat_id'] ?? 0);
    $messageIds = $input['message_ids'] ?? [];
    if (!is_array($messageIds)) $messageIds = [];
    $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    $manualText = trim((string)($input['manual_text'] ?? ''));
    $model = $engine === 'api'
        ? trim((string)($input['model'] ?? $settings['model'] ?? ''))
        : trim((string)($input['model'] ?? $disc['default_model']));

    $snapshot = [];
    $sourceWaMsgId = null;
    $inputMode = 'stored_messages';
    $phoneInfo = ['phone' => null, 'source' => 'unknown'];
    $chatName = null;

    if ($manualText !== '' && !$messageIds) {
        $inputMode = 'manual_paste';
        $manualText = airBoundParentText($manualText);
        if ($manualText === '') airJson(['success' => false, 'error' => 'manual_text empty'], 400);
        $snapshot[] = ['id' => null, 'body' => $manualText, 'sender_name' => 'Manual paste', 'timestamp' => time()];
        $phoneInfo['source'] = 'manual_paste';
        $chatId = $chatId > 0 ? $chatId : 0;
    } else {
        if ($chatId <= 0) airJson(['success' => false, 'error' => 'chat_id required unless using manual_text'], 400);
        if (!$messageIds) airJson(['success' => false, 'error' => 'Select at least one inbound message, or provide manual_text'], 400);
        if (count($messageIds) > 30) airJson(['success' => false, 'error' => 'Too many messages (max 30)'], 400);

        $cSt = $db->prepare('SELECT id, name, wa_chat_id, chat_type FROM whatsapp_chats WHERE id = ?');
        $cSt->execute([$chatId]);
        $chat = $cSt->fetch(PDO::FETCH_ASSOC);
        if (!$chat) airJson(['success' => false, 'error' => 'Chat not found'], 404);
        $chatName = $chat['name'];
        $phoneInfo = airPhoneFromWaChatId($chat['wa_chat_id']);

        $in = implode(',', array_fill(0, count($messageIds), '?'));
        $params = $messageIds;
        array_unshift($params, $chatId);
        $st = $db->prepare("
            SELECT id, wa_msg_id, from_me, sender_name, body, timestamp
            FROM whatsapp_messages
            WHERE chat_id = ? AND id IN ({$in}) AND from_me = 0
            ORDER BY timestamp ASC, id ASC
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            airJson(['success' => false, 'error' => 'No matching inbound messages found for this chat'], 404);
        }
        foreach ($rows as $r) {
            $snapshot[] = [
                'id' => (int)$r['id'],
                'wa_msg_id' => $r['wa_msg_id'],
                'body' => airBoundParentText((string)$r['body'], 2000),
                'sender_name' => $r['sender_name'],
                'timestamp' => (int)$r['timestamp'],
            ];
            if (!$sourceWaMsgId && !empty($r['wa_msg_id']) && !str_starts_with((string)$r['wa_msg_id'], 'temp_')) {
                $sourceWaMsgId = $r['wa_msg_id'];
            }
        }
        $messageIds = array_map(fn($s) => $s['id'], $snapshot);
    }

    $bundleLines = [];
    foreach ($snapshot as $s) {
        $who = $s['sender_name'] ?: 'Parent';
        $bundleLines[] = "[{$who} @ {$s['timestamp']}] {$s['body']}";
    }
    $bundle = airBoundParentText(implode("\n", $bundleLines), 6000);
    $chatMeta = json_encode([
        'chat_id' => $chatId ?: null,
        'chat_name' => $chatName,
        'verified_phone' => $phoneInfo['phone'],
        'phone_source' => $phoneInfo['source'],
        'input_mode' => $inputMode,
        'message_count' => count($snapshot),
    ], JSON_UNESCAPED_UNICODE);
    $knowledge = airLoadKnowledgeSnippet();
    $prompt = airBuildAnalyzePrompt($bundle, $chatMeta, $knowledge);

    $analysisProvider = null;
    $analysisHost = null;
    if ($engine === 'api') {
        $apiKey = airLoadApiKey();
        if (!$apiKey) {
            airJson(['success' => false, 'error' => 'API key not configured'], 400);
        }
        $base = trim((string)($settings['base_url'] ?? ''));
        if ($base === '' && ($settings['provider'] ?? '') === 'nvidia') $base = airNvidiaDefaultBaseUrl();
        $gen = airCallOpenAiCompatible($base, $model, $apiKey, $prompt, 90);
        $analysisProvider = (string)($settings['provider'] ?? 'openai_compatible');
        $analysisHost = $gen['host'] ?? null;
        if (!$gen['ok']) {
            airJson([
                'success' => false,
                'error' => $gen['error'] ?? 'API analysis failed',
                'http_status' => $gen['http_status'] ?? null,
                'endpoint_host' => $analysisHost,
                'ms' => $gen['ms'] ?? null,
                'hint' => 'Local engine was not used (no silent fallback). Fix API settings or switch to Local.',
            ], 502);
        }
    } else {
        $gen = airCallOllamaGenerate($prompt, $model, 90);
        $analysisProvider = 'ollama_local';
        $analysisHost = '127.0.0.1';
        if (!$gen['ok']) {
            airJson([
                'success' => false,
                'error' => $gen['error'] ?? 'Local model call failed',
                'hint' => 'Ensure `ollama serve` is running and model "' . $model . '" is pulled. API engine was not used (no silent fallback).',
                'ai' => $disc,
                'ms' => $gen['ms'] ?? null,
            ], 503);
        }
    }
    $validated = airValidateModelJson($gen['raw'] ?? '');
    if (!$validated['ok'] && $engine === 'local') {
        $retryPrompt = $prompt . "\n\nIMPORTANT: Your previous answer was invalid. classification must be exactly one of: admission, not_admission, unclear.";
        $gen = airCallOllamaGenerate($retryPrompt, $model, 90);
        if (!$gen['ok']) {
            airJson([
                'success' => false,
                'error' => $gen['error'] ?? 'Local model retry failed',
                'ai' => $disc,
                'ms' => $gen['ms'] ?? null,
            ], 503);
        }
        $validated = airValidateModelJson($gen['raw'] ?? '');
    }
    if (!$validated['ok']) {
        airJson([
            'success' => false,
            'error' => 'Model output failed validation: ' . ($validated['error'] ?? 'unknown'),
            'raw_preview' => substr((string)($gen['raw'] ?? ''), 0, 400),
            'ms' => $gen['ms'] ?? null,
            'engine' => $engine,
        ], 422);
    }
    $data = $validated['data'];

    // Never invent phone — force from chat identity when available
    if ($phoneInfo['source'] === 'chat_identity' && $phoneInfo['phone']) {
        $data['contact_number'] = $phoneInfo['phone'];
        $data['contact_number_source'] = 'chat_identity';
    } elseif ($inputMode === 'manual_paste') {
        $data['contact_number'] = null;
        $data['contact_number_source'] = 'manual_paste';
    } else {
        $data['contact_number'] = null;
        $data['contact_number_source'] = 'unknown';
    }

    $status = ($data['classification'] === 'unclear' || !empty($data['requires_review']))
        ? 'needs_review'
        : 'draft';
    if ($data['classification'] === 'not_admission') {
        $status = 'draft';
    }

    // Version existing open draft for same chat+messages fingerprint
    $fingerprint = hash('sha256', json_encode([
        'chat_id' => $chatId ?: null,
        'ids' => $messageIds,
        'manual' => $inputMode === 'manual_paste' ? $bundle : null,
    ]));

    $adminId = (int)$auth['admin_id'];
    $ins = $db->prepare("
        INSERT INTO admission_ai_review_drafts
        (chat_id, source_message_ids, analyzed_snapshot, classification, explanation, evidence_quotes,
         parent_name, student_name, interested_class, academic_year, age_or_dob, contact_number,
         contact_number_source, question_summary, missing_details, suggested_reply, edited_reply,
         status, model_used, analysis_engine, analysis_provider, analysis_model, analysis_endpoint_host,
         analyzed_at, analyzed_by, source_wa_msg_id, input_mode, send_idempotency_key)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,NOW(),?,?,?,NULL)
    ");
    $ins->execute([
        $chatId > 0 ? $chatId : null,
        json_encode($messageIds, JSON_UNESCAPED_UNICODE),
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        $data['classification'],
        $data['explanation'],
        json_encode($data['evidence_quotes'], JSON_UNESCAPED_UNICODE),
        $data['parent_name'],
        $data['student_name'],
        $data['interested_class'],
        $data['academic_year'],
        $data['age_or_dob'],
        $data['contact_number'],
        $data['contact_number_source'],
        $data['question_summary'],
        json_encode($data['missing_details'], JSON_UNESCAPED_UNICODE),
        $data['suggested_reply'],
        $status,
        $gen['model'] ?? $model,
        $engine,
        $analysisProvider,
        $gen['model'] ?? $model,
        $analysisHost,
        $adminId,
        $sourceWaMsgId,
        $inputMode,
    ]);
    $newId = (int)$db->lastInsertId();

    // Store fingerprint in error_message? Better: update send_idempotency only on send.
    // Mark older drafts with same chat+ids as superseded note in explanation? Keep simple: return new id.

    $row = airDraftRow($db, $newId);
    airJson([
        'success' => true,
        'draft' => airFormatDraft($row),
        'model_used' => $gen['model'] ?? $model,
        'analysis_engine' => $engine,
        'analysis_provider' => $analysisProvider,
        'analysis_endpoint_host' => $analysisHost,
        'inference_ms' => $gen['ms'] ?? null,
        'fingerprint' => $fingerprint,
        'context_explained' => [
            'mode' => $inputMode,
            'chat_id' => $chatId ?: null,
            'message_ids' => $messageIds,
            'message_count' => count($snapshot),
            'phone_source' => $data['contact_number_source'],
        ],
    ]);
    break;

case 'save_draft':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    $id = (int)($input['id'] ?? 0);
    $row = airDraftRow($db, $id);
    if (!$row) airJson(['success' => false, 'error' => 'Draft not found'], 404);
    if (in_array($row['status'], ['sent'], true)) {
        airJson(['success' => false, 'error' => 'Sent drafts cannot be edited'], 409);
    }
    // Destination chat_id is immutable once set
    $fields = [
        'parent_name' => $input['parent_name'] ?? $row['parent_name'],
        'student_name' => $input['student_name'] ?? $row['student_name'],
        'interested_class' => $input['interested_class'] ?? $row['interested_class'],
        'academic_year' => $input['academic_year'] ?? $row['academic_year'],
        'age_or_dob' => $input['age_or_dob'] ?? $row['age_or_dob'],
        'question_summary' => $input['question_summary'] ?? $row['question_summary'],
        'edited_reply' => array_key_exists('edited_reply', $input) ? (string)$input['edited_reply'] : $row['edited_reply'],
        'suggested_reply' => array_key_exists('suggested_reply', $input) ? (string)$input['suggested_reply'] : $row['suggested_reply'],
    ];
    if (array_key_exists('missing_details', $input) && is_array($input['missing_details'])) {
        $missingJson = json_encode($input['missing_details'], JSON_UNESCAPED_UNICODE);
    } else {
        $missingJson = $row['missing_details'];
    }
    // Reject quiet destination change
    if (isset($input['chat_id']) && (int)$input['chat_id'] !== (int)($row['chat_id'] ?? 0) && $row['chat_id'] !== null) {
        airJson(['success' => false, 'error' => 'Destination chat is locked to the analyzed source chat'], 409);
    }
    $db->prepare("
        UPDATE admission_ai_review_drafts SET
          parent_name=?, student_name=?, interested_class=?, academic_year=?, age_or_dob=?,
          question_summary=?, edited_reply=?, suggested_reply=?, missing_details=?,
          status = IF(status='reviewed','draft',status),
          updated_at=NOW()
        WHERE id=?
    ")->execute([
        $fields['parent_name'], $fields['student_name'], $fields['interested_class'],
        $fields['academic_year'], $fields['age_or_dob'], $fields['question_summary'],
        $fields['edited_reply'], $fields['suggested_reply'], $missingJson, $id,
    ]);
    airJson(['success' => true, 'draft' => airFormatDraft(airDraftRow($db, $id))]);
    break;

case 'mark_reviewed':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    $id = (int)($input['id'] ?? 0);
    $row = airDraftRow($db, $id);
    if (!$row) airJson(['success' => false, 'error' => 'Draft not found'], 404);
    if ($row['classification'] === 'not_admission') {
        airJson(['success' => false, 'error' => 'Not-admission drafts do not need Send review. Archive or ignore them.'], 409);
    }
    $reply = trim((string)(($input['edited_reply'] ?? null) !== null ? $input['edited_reply'] : ($row['edited_reply'] ?: $row['suggested_reply'])));
    if ($reply === '') airJson(['success' => false, 'error' => 'Reply text is empty'], 400);
    if ($row['chat_id'] === null || (int)$row['chat_id'] <= 0) {
        airJson(['success' => false, 'error' => 'Draft has no bound WhatsApp chat. Link a chat or re-analyze from a stored chat before review/send.'], 409);
    }
    $db->prepare("
        UPDATE admission_ai_review_drafts
        SET edited_reply=?, status='reviewed', reviewed_at=NOW(), reviewed_by=?, updated_at=NOW()
        WHERE id=?
    ")->execute([$reply, (int)$auth['admin_id'], $id]);
    airJson(['success' => true, 'draft' => airFormatDraft(airDraftRow($db, $id))]);
    break;

case 'send':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    $id = (int)($input['id'] ?? 0);
    $row = airDraftRow($db, $id);
    if (!$row) airJson(['success' => false, 'error' => 'Draft not found'], 404);

    // Idempotent: already sent
    if ($row['status'] === 'sent' && !empty($row['send_queue_id'])) {
        airJson([
            'success' => true,
            'already_sent' => true,
            'queue_id' => (int)$row['send_queue_id'],
            'draft' => airFormatDraft($row),
            'message' => 'Already queued earlier — not duplicated.',
        ]);
    }
    if ($row['status'] !== 'reviewed') {
        airJson(['success' => false, 'error' => 'Mark the draft as Reviewed before Send', 'code' => 'not_reviewed'], 409);
    }
    $chatId = (int)($row['chat_id'] ?? 0);
    if ($chatId <= 0) {
        airJson(['success' => false, 'error' => 'No bound chat — cannot send'], 409);
    }
    // Reject destination override
    if (isset($input['chat_id']) && (int)$input['chat_id'] !== $chatId) {
        airJson(['success' => false, 'error' => 'Recipient is locked to the analyzed chat'], 409);
    }
    if (!airIsBridgeConnected($db)) {
        airJson([
            'success' => false,
            'error' => 'WhatsApp bridge is disconnected. Draft kept — start the bridge and scan QR, then Send again. Nothing was queued.',
            'code' => 'bridge_offline',
            'draft' => airFormatDraft($row),
        ], 503);
    }
    $body = trim((string)($row['edited_reply'] !== null && $row['edited_reply'] !== '' ? $row['edited_reply'] : $row['suggested_reply']));
    if ($body === '') airJson(['success' => false, 'error' => 'Empty reply'], 400);

    // Preview confirmation required from UI
    $confirmPreview = (string)($input['confirm_preview'] ?? '');
    if ($confirmPreview !== '1' && strtolower($confirmPreview) !== 'yes') {
        airJson([
            'success' => false,
            'error' => 'confirm_preview required',
            'preview' => [
                'chat_id' => $chatId,
                'body' => $body,
                'draft_id' => $id,
            ],
            'code' => 'confirm_required',
        ], 403);
    }

    $idem = $row['send_idempotency_key'];
    if (!$idem) {
        $idem = 'air_' . $id . '_' . hash('sha256', $chatId . '|' . $body);
        $db->prepare('UPDATE admission_ai_review_drafts SET send_idempotency_key=? WHERE id=? AND send_idempotency_key IS NULL')
           ->execute([$idem, $id]);
    }

    // Re-check race
    $row = airDraftRow($db, $id);
    if ($row['status'] === 'sent' && !empty($row['send_queue_id'])) {
        airJson(['success' => true, 'already_sent' => true, 'queue_id' => (int)$row['send_queue_id'], 'draft' => airFormatDraft($row)]);
    }

    try {
        $res = waEnqueueWhatsAppOutbound($db, [
            'chat_id' => $chatId,
            'msg_type' => 'text',
            'body' => $body,
            'priority' => 5,
            'dispatch_method' => 'manual',
            'origin' => 'manual_ui',
            'approved_by' => (int)$auth['admin_id'],
            'dispatched_by' => (int)$auth['admin_id'],
            'related_doc_type' => 'admission_ai_review',
            'quoted_msg_id' => null,
        ]);
    } catch (Throwable $e) {
        $db->prepare("UPDATE admission_ai_review_drafts SET status='failed', error_message=?, updated_at=NOW() WHERE id=?")
           ->execute([$e->getMessage(), $id]);
        airJson(['success' => false, 'error' => 'Enqueue failed: ' . $e->getMessage(), 'draft' => airFormatDraft(airDraftRow($db, $id))], 500);
    }

    $db->prepare("UPDATE admission_ai_review_drafts SET status='sent', send_queue_id=?, error_message=NULL, updated_at=NOW() WHERE id=?")
       ->execute([(int)$res['queue_id'], $id]);

    airJson([
        'success' => true,
        'queue_id' => (int)$res['queue_id'],
        'temp_msg_id' => $res['temp_msg_id'],
        'draft' => airFormatDraft(airDraftRow($db, $id)),
        'message' => 'Queued once for the bound chat. Bridge will send when online.',
    ]);
    break;

case 'create_enquiry':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    $id = (int)($input['id'] ?? 0);
    $row = airDraftRow($db, $id);
    if (!$row) airJson(['success' => false, 'error' => 'Draft not found'], 404);
    if ($row['classification'] !== 'admission') {
        airJson(['success' => false, 'error' => 'Only admission-classified drafts can create an enquiry'], 409);
    }
    $parent = trim((string)($input['parent_name'] ?? $row['parent_name'] ?? 'Parent'));
    $student = trim((string)($input['student_name'] ?? $row['student_name'] ?? ''));
    $class = trim((string)($input['interested_class'] ?? $row['interested_class'] ?? ''));
    $year = trim((string)($input['academic_year'] ?? $row['academic_year'] ?? '2026-27'));
    $phone = trim((string)($row['contact_number'] ?? ''));
    if ($class === '') airJson(['success' => false, 'error' => 'interested_class required'], 400);

    // Do not silently overwrite — if enquiry_id set, update only when user says so
    $forceUpdate = !empty($input['update_existing']) && !empty($row['enquiry_id']);
    if (!empty($row['enquiry_id']) && !$forceUpdate) {
        airJson([
            'success' => false,
            'error' => 'Draft already linked to enquiry #' . $row['enquiry_id'] . '. Pass update_existing=1 to update it.',
            'enquiry_id' => (int)$row['enquiry_id'],
            'code' => 'already_linked',
        ], 409);
    }

    $snap = json_decode($row['analyzed_snapshot'] ?: '[]', true) ?: [];
    $orig = '';
    foreach ($snap as $s) { $orig .= ($s['body'] ?? '') . "\n"; }
    $orig = trim($orig);

    if ($forceUpdate) {
        $eid = (int)$row['enquiry_id'];
        $db->prepare("UPDATE admission_enquiries SET parent_name=?, student_name=?, interested_class=?, academic_year=?, phone_number=?, phone_e164=?, chat_id=COALESCE(chat_id,?), updated_at=NOW() WHERE id=?")
           ->execute([$parent, $student ?: null, $class, $year, $phone ?: null, $phone ?: null, $row['chat_id'], $eid]);
    } else {
        $db->prepare("
            INSERT INTO admission_enquiries
            (parent_name, phone_number, phone_e164, student_name, interested_class, academic_year,
             original_message, enquiry_source, status, chat_id, created_by, created_by_name, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?, 'NEW', ?, ?, ?, NOW(), NOW())
        ")->execute([
            $parent, $phone ?: null, $phone ?: null, $student ?: null, $class, $year,
            $orig !== '' ? $orig : $row['question_summary'],
            'ai_review_draft',
            $row['chat_id'],
            (int)$auth['admin_id'],
            $auth['admin_name'] ?? 'Admin',
        ]);
        $eid = (int)$db->lastInsertId();
        $db->prepare('UPDATE admission_ai_review_drafts SET enquiry_id=? WHERE id=?')->execute([$eid, $id]);
    }
    airJson(['success' => true, 'enquiry_id' => $eid, 'draft' => airFormatDraft(airDraftRow($db, $id))]);
    break;

case 'delete_draft':
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') airJson(['success' => false, 'error' => 'POST required'], 405);
    airRequireCsrf($input);
    $id = (int)($input['id'] ?? 0);
    $row = airDraftRow($db, $id);
    if (!$row) airJson(['success' => false, 'error' => 'Draft not found'], 404);
    if ($row['status'] === 'sent') airJson(['success' => false, 'error' => 'Cannot delete a sent draft'], 409);
    $db->prepare('DELETE FROM admission_ai_review_drafts WHERE id=?')->execute([$id]);
    airJson(['success' => true]);
    break;

default:
    airJson(['success' => false, 'error' => 'Unknown or missing action'], 400);
}
} catch (Throwable $e) {
    airJson(['success' => false, 'error' => $e->getMessage()], 500);
}
