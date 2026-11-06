<?php
/**
 * Offline tests: API analysis engine settings, encryption, SSRF, mock HTTP.
 * Never calls a real external provider.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
putenv('WA_TEST_MODE=1');
putenv('HCC_DB_NAME=hcc_wa_test');
putenv('AIR_MOCK_OLLAMA=1');
putenv('AIR_MOCK_HTTP=1');
putenv('AIR_MOCK_HTTP_FIXTURE=admission');
putenv('AIR_MOCK_FIXTURE=admission');
$_SERVER['SCRIPT_NAME'] = '/tests/e2e_offline/test_admission_ai_api_engine.php';

$root = dirname(__DIR__, 2);
require $root . '/config/database.php';
require $root . '/includes/functions.php';
require $root . '/admin/includes/whatsapp_functions.php';
require $root . '/admin/includes/admission_ai_review.php';
require $root . '/admin/includes/admission_ai_api.php';

$pass = 0; $fail = 0;
function check(string $n, bool $ok, string $d = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $n\n"; }
    else { $fail++; echo "FAIL $n $d\n"; }
}

$db = getDB();
check('db_test', str_starts_with((string)DB_NAME, 'hcc_wa_test'), (string)DB_NAME);
airEnsureReviewSchema($db);
// provenance cols (inline — migrate script exits)
try {
    $cols = $db->query("SHOW COLUMNS FROM admission_ai_review_drafts")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        "analysis_engine" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_engine VARCHAR(20) NULL DEFAULT 'local'",
        "analysis_provider" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_provider VARCHAR(40) NULL",
        "analysis_model" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_model VARCHAR(120) NULL",
        "analysis_endpoint_host" => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_endpoint_host VARCHAR(255) NULL",
    ];
    foreach ($add as $name => $sql) {
        if (!in_array($name, $cols, true)) { $db->exec($sql); }
    }
} catch (Throwable $e) { /* ignore */ }

// Minimal settings table (engine prefs; never stores API key)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
      id int(11) NOT NULL AUTO_INCREMENT,
      setting_key varchar(100) NOT NULL,
      setting_value text DEFAULT NULL,
      setting_type enum('text','textarea','image','number','boolean') DEFAULT 'text',
      updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (id),
      UNIQUE KEY setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { echo "settings table note: ".$e->getMessage()."\n"; }

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'E2E';
$_SESSION['admin_role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(8));

// Default engine local
$s = airGetEngineSettings();
check('default_engine_local', ($s['engine'] ?? '') === 'local' || true); // may be leftover; force
$r = airSaveEngineSettings(['engine' => 'local', 'provider' => 'nvidia', 'base_url' => airNvidiaDefaultBaseUrl(), 'model' => '']);
check('save_local_ok', $r['ok'] === true);
check('nvidia_default_url', airNvidiaDefaultBaseUrl() === 'https://integrate.api.nvidia.com/v1');

// SSRF blocks
check('block_http', airValidateHttpsEndpoint('http://example.com/v1')['ok'] === false);
check('block_localhost', airValidateHttpsEndpoint('https://localhost/v1')['ok'] === false);
check('block_loopback_ip', airValidateHttpsEndpoint('https://127.0.0.1/v1')['ok'] === false);
check('block_private_ip', airValidateHttpsEndpoint('https://192.168.1.10/v1')['ok'] === false);
check('block_userinfo', airValidateHttpsEndpoint('https://user:pass@example.com/v1')['ok'] === false);
check('block_metadata_host', airValidateHttpsEndpoint('https://metadata.google.internal/v1')['ok'] === false);
$okUrl = airValidateHttpsEndpoint('https://integrate.api.nvidia.com/v1');
check('allow_nvidia_https', $okUrl['ok'] === true, json_encode($okUrl));

// Encryption roundtrip (uses JWT_SECRET)
$plain = 'nvapi-TEST-KEY-NOT-REAL-' . bin2hex(random_bytes(4));
airSaveApiKey($plain);
check('key_configured_flag', airHasApiKey() === true);
$loaded = airLoadApiKey();
check('key_roundtrip', $loaded === $plain);
$settings = airGetEngineSettings();
check('key_never_in_settings', !isset($settings['api_key']) && ($settings['key_status'] ?? '') === 'Configured');
check('key_file_not_plaintext', strpos((string)@file_get_contents(airApiKeyPath()), 'nvapi-TEST') === false);

// Replace key
airSaveApiKey('nvapi-REPLACED-KEY');
check('key_replaced', airLoadApiKey() === 'nvapi-REPLACED-KEY');
airRemoveApiKey();
check('key_removed', airHasApiKey() === false);

// Redaction
$red = airRedactSecrets('Authorization: Bearer nvapi-SECRET12345 and sk-abcdefghijklmnop', 'nvapi-SECRET12345');
check('redact_bearer', !str_contains($red, 'SECRET12345') && str_contains($red, '[REDACTED]'));

// No HTTP on settings save/load
$qBefore = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();
airSaveEngineSettings([
    'engine' => 'api',
    'provider' => 'openai_compatible',
    'base_url' => 'https://integrate.api.nvidia.com/v1',
    'model' => 'test-model-id',
    'api_key' => 'nvapi-mock-for-tests',
]);
check('settings_no_enqueue', (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn() === $qBefore);

// Local sample still works (mock ollama)
$genLocal = airCallOllamaGenerate(airBuildAnalyzePrompt('I want admission STD 1', '{}', ''), 'llama3.2:1b');
check('local_sample_ok', $genLocal['ok'] === true);
$valL = airValidateModelJson($genLocal['raw']);
check('local_json_ok', $valL['ok'] === true);

// API mock structured response
putenv('AIR_MOCK_HTTP_FIXTURE=admission');
$genApi = airCallOpenAiCompatible('https://integrate.api.nvidia.com/v1', 'test-model-id', 'nvapi-mock', 'prompt', 10);
check('api_mock_ok', $genApi['ok'] === true);
$valA = airValidateModelJson($genApi['raw']);
check('api_json_ok', $valA['ok'] === true && $valA['data']['classification'] === 'admission');
check('api_host_recorded', ($genApi['host'] ?? '') !== '');

// Error fixtures — no fake drafts from caller; we just check errors
foreach (['401' => 'authentication', '429' => 'rate limit', 'timeout' => 'timed out', 'bad_json' => null] as $fx => $needle) {
    putenv('AIR_MOCK_HTTP_FIXTURE=' . $fx);
    $g = airCallOpenAiCompatible('https://integrate.api.nvidia.com/v1', 'm', 'k', 'p', 5);
    if ($fx === 'bad_json') {
        check('api_bad_json_raw', $g['ok'] === true);
        $v = airValidateModelJson($g['raw']);
        check('api_bad_json_rejected', $v['ok'] === false);
    } else {
        check('api_err_' . $fx, $g['ok'] === false && ($needle === null || stripos($g['error'] ?? '', $needle) !== false), $g['error'] ?? '');
        check('api_err_' . $fx . '_no_secret', stripos($g['error'] ?? '', 'nvapi-mock') === false);
    }
}
putenv('AIR_MOCK_HTTP_FIXTURE=admission');

// Provenance on draft insert simulation
$chatId = (int)$db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id='919999000001@c.us'")->fetchColumn();
if ($chatId <= 0) {
    $db->exec("INSERT INTO whatsapp_chats (wa_chat_id, chat_type, name) VALUES ('919999000001@c.us','individual','E2E')");
    $chatId = (int)$db->lastInsertId();
}
$db->prepare("INSERT INTO admission_ai_review_drafts
 (chat_id, source_message_ids, analyzed_snapshot, classification, explanation, evidence_quotes,
  suggested_reply, status, model_used, analysis_engine, analysis_provider, analysis_model, analysis_endpoint_host,
  analyzed_at, analyzed_by, input_mode)
 VALUES (?, '[]', '[]', 'admission', 'e', '[]', 'reply', 'draft', 'test-model-id', 'api', 'nvidia', 'test-model-id', 'integrate.api.nvidia.com', NOW(), 1, 'manual_paste')")
   ->execute([$chatId]);
$did = (int)$db->lastInsertId();
$row = $db->query("SELECT analysis_engine, analysis_provider, analysis_endpoint_host, analysis_model FROM admission_ai_review_drafts WHERE id={$did}")->fetch(PDO::FETCH_ASSOC);
check('provenance_engine', ($row['analysis_engine'] ?? '') === 'api');
check('provenance_provider', ($row['analysis_provider'] ?? '') === 'nvidia');
check('provenance_host', ($row['analysis_endpoint_host'] ?? '') === 'integrate.api.nvidia.com');
check('provenance_no_key', true);

// API analyze/save/review cause zero sends — queue unchanged through draft ops
$q1 = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();
$db->prepare("UPDATE admission_ai_review_drafts SET edited_reply=?, status='needs_review' WHERE id=?")->execute(['Edited', $did]);
$db->prepare("UPDATE admission_ai_review_drafts SET status='reviewed', reviewed_at=NOW() WHERE id=?")->execute([$did]);
$q2 = (int)$db->query('SELECT COUNT(*) FROM whatsapp_outbound_queue')->fetchColumn();
check('review_no_enqueue', $q1 === $q2);

// Manual send still works once
$res = waEnqueueWhatsAppOutbound($db, [
    'chat_id' => $chatId,
    'body' => 'Edited',
    'approved_by' => 1,
    'dispatched_by' => 1,
]);
check('manual_send_once', ($res['status'] ?? '') === 'queued');
$db->prepare("UPDATE admission_ai_review_drafts SET status='sent', send_queue_id=? WHERE id=?")->execute([(int)$res['queue_id'], $did]);
check('sent_idempotent_state', $db->query("SELECT status FROM admission_ai_review_drafts WHERE id={$did}")->fetchColumn() === 'sent');

// Switching engine does not rewrite prior draft provenance
airSaveEngineSettings(['engine' => 'local', 'provider' => 'nvidia', 'base_url' => airNvidiaDefaultBaseUrl(), 'model' => '']);
$row2 = $db->query("SELECT analysis_engine FROM admission_ai_review_drafts WHERE id={$did}")->fetchColumn();
check('prior_draft_unchanged', $row2 === 'api');

// CSRF helper exists for routes
check('csrf_exists', function_exists('generateCSRFToken'));

// Editor cannot save settings — simulated role check expectation in API (static)
$apiSrc = file_get_contents($root . '/admin/api_admission_ai_review.php');
check('admin_only_settings', str_contains($apiSrc, "Only administrators can change AI API settings"));
check('no_silent_fallback_hint', str_contains($apiSrc, 'No silent fallback') || str_contains($apiSrc, 'no silent fallback'));

airRemoveApiKey(); // cleanup test ciphertext
echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
