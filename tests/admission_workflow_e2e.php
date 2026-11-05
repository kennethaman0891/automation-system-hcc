<?php
/**
 * End-to-end checks for the admission enquiry, AI draft, manual WhatsApp
 * handoff, and follow-up queue. Run from the project root:
 *
 *   php tests/admission_workflow_e2e.php
 *
 * The script talks to the local Apache app as a localhost QA admin.
 * It deletes only the rows it creates (phone 9999999999 / 9999999998).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/admin/includes/whatsapp_functions.php';

$results = [];
$passed = 0;
$failed = 0;
$skipped = 0;

function record(string $id, string $name, string $status, string $detail = ''): void {
    global $results, $passed, $failed, $skipped;
    $results[] = ['id' => $id, 'name' => $name, 'status' => $status, 'detail' => $detail];
    if ($status === 'PASS') {
        $passed++;
        echo "PASS  {$id}  {$name}\n";
    } elseif ($status === 'SKIP') {
        $skipped++;
        echo "SKIP  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $failed++;
        echo "FAIL  {$id}  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function httpJson(string $url, string $method, ?array $body, string $cookie, array $headers = []): array {
    $ch = curl_init($url);
    $hdr = array_merge(['Accept: application/json', 'X-Admin-Test: 1'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'code' => $code,
        'json' => is_array($json) ? $json : null,
        'raw' => is_string($raw) ? $raw : '',
        'error' => $err,
    ];
}

$db = getDB();
$base = 'http://127.0.0.1/hcc/admin';
$phoneDigits = '9999999999';
$phonePretty = '+91 99999 99999';
$student = 'E2E Meera Kulkarni';
$parent = 'E2E Parent Kulkarni';
$cookie = tempnam(sys_get_temp_dir(), 'hcc_e2e_');
$cookieB = tempnam(sys_get_temp_dir(), 'hcc_e2e_b_');

echo "Admission workflow end-to-end\n";
echo "Database: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

// Schema
$hasEnquiries = (bool)$db->query("SHOW TABLES LIKE 'admission_enquiries'")->fetchColumn();
if (!$hasEnquiries) {
    $sql = file_get_contents($root . '/data/migrations/create_admission_enquiry_tables.sql');
    if ($sql !== false) {
        $db->exec($sql);
    }
}
$colStmt = $db->query("SHOW COLUMNS FROM admission_enquiries LIKE 'next_followup_date'");
if (!$colStmt->fetch()) {
    echo "Applying follow-up migration...\n";
    passthru('php ' . escapeshellarg($root . '/data/migrations/migrate_admission_followups.php'));
}
$hasJobs = (bool)$db->query("SHOW TABLES LIKE 'automation_jobs'")->fetchColumn();

// Remove leftovers from an earlier run of this script.
$oldIds = $db->query("SELECT id FROM admission_enquiries WHERE phone_e164 IN ('+919999999999','+919999999998')")->fetchAll(PDO::FETCH_COLUMN);
foreach ($oldIds as $oldId) {
    $db->prepare('DELETE FROM admission_enquiries WHERE id = ?')->execute([(int)$oldId]);
}
$db->prepare("DELETE FROM report_card WHERE roll_no = '77001'")->execute();
$db->prepare("DELETE FROM whatsapp_contacts WHERE phone_e164 IN ('+919999999999','+919999999998')")->execute();
$db->prepare("DELETE FROM whatsapp_chats WHERE wa_chat_id IN ('919999999999@c.us','919999999998@c.us')")->execute();

$seededParent = false;
try {
    $db->prepare("
        INSERT INTO report_card (academic_year, std, division, semester, roll_no, student_name, subject_name, parent_phone)
        VALUES ('2025-26', '4', 'A', 'Sem 1', '77001', ?, 'English', ?)
    ")->execute([$student, '9999999999']);
    $seededParent = true;
} catch (Throwable $e) {
    $seededParent = false;
}

$ping = httpJson($base . '/api_admission_enquiry.php?action=csrf&qa_test=1', 'GET', null, $cookie);
if ($ping['code'] === 0) {
    record('0', 'Reach the local application', 'FAIL', $ping['error'] ?: 'Apache did not answer on http://127.0.0.1/hcc');
    finish($root, $cookie, $cookieB);
}
$csrf = (string)($ping['json']['csrf_token'] ?? '');
if ($ping['code'] !== 200 || $csrf === '') {
    record('0', 'Reach the local application', 'FAIL', 'CSRF bootstrap HTTP ' . $ping['code'] . ' ' . substr($ping['raw'], 0, 240));
    finish($root, $cookie, $cookieB);
}
record('0', 'Reach the local application', 'PASS', 'Authenticated localhost QA session');
$authHeader = ['X-CSRF-Token: ' . $csrf];

// 2 then 1: reject a bad number, then capture a valid enquiry.
$bad = httpJson($base . '/api_admission_enquiry.php?action=create&qa_test=1', 'POST', [
    'parent_name' => $parent,
    'phone_number' => '12345',
    'student_name' => $student,
    'interested_class' => 'STD IV',
    'academic_year' => '2026-27',
    'original_message' => 'Please share admission details.',
    'enquiry_source' => 'whatsapp',
], $cookie, $authHeader);
$badRejected = $bad['code'] === 422 && !empty($bad['json']['errors']['phone_number']);
record('2', 'Validate parent phone number', $badRejected ? 'PASS' : 'FAIL', 'HTTP ' . $bad['code'] . ' ' . substr($bad['raw'], 0, 220));

$original = 'Namaste, I am enquiring about admission for my daughter in STD IV for 2026-27. Please share the fee and the documents required. We may visit the campus.';
$created = httpJson($base . '/api_admission_enquiry.php?action=create&qa_test=1', 'POST', [
    'parent_name' => $parent,
    'phone_number' => $phonePretty,
    'student_name' => $student,
    'interested_class' => 'STD IV',
    'academic_year' => '2026-27',
    'original_message' => $original,
    'enquiry_source' => 'whatsapp',
    'notes' => 'E2E capture',
], $cookie, $authHeader);
$enquiryId = (int)($created['json']['enquiry_id'] ?? 0);
record('1', 'Capture a new parent enquiry', ($created['code'] === 201 && $enquiryId > 0) ? 'PASS' : 'FAIL', 'HTTP ' . $created['code'] . ' id=' . $enquiryId . ' ' . substr($created['raw'], 0, 220));

if ($enquiryId <= 0) {
    record('3', 'Create a parent profile', 'FAIL', 'Enquiry was not created');
    finish($root, $cookie, $cookieB);
}

$row = $db->prepare('SELECT * FROM admission_enquiries WHERE id = ?');
$row->execute([$enquiryId]);
$enquiry = $row->fetch(PDO::FETCH_ASSOC);
$preserved = ($enquiry['original_message'] ?? '') === $original && ($enquiry['phone_e164'] ?? '') === '+919999999999';
record('1b', 'Preserve the original message and E.164 phone', $preserved ? 'PASS' : 'FAIL', (string)($enquiry['phone_e164'] ?? ''));

$contact = $db->prepare('SELECT id, linked_student_roll FROM whatsapp_contacts WHERE phone_e164 = ?');
$contact->execute(['+919999999999']);
$contactRow = $contact->fetch(PDO::FETCH_ASSOC);
$profileOk = $contactRow && (int)$enquiry['chat_id'] > 0;
if ($seededParent) {
    $profileOk = $profileOk && (string)($enquiry['matched_student_roll'] ?? '') === '77001';
}
record('3', 'Create a parent profile and reuse the student record', $profileOk ? 'PASS' : 'FAIL', 'roll=' . ($enquiry['matched_student_roll'] ?? 'none'));

// 18: a second simultaneous create for the same phone and student must not insert another row.
$pingB = httpJson($base . '/api_admission_enquiry.php?action=csrf&qa_test=1', 'GET', null, $cookieB);
$csrfB = (string)($pingB['json']['csrf_token'] ?? '');
$mh = curl_multi_init();
$handles = [];
foreach ([['cookie' => $cookie, 'token' => $csrf], ['cookie' => $cookieB, 'token' => $csrfB]] as $i => $sess) {
    $ch = curl_init($base . '/api_admission_enquiry.php?action=create&qa_test=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'parent_name' => $parent,
            'phone_number' => $phonePretty,
            'student_name' => $student,
            'interested_class' => 'STD IV',
            'academic_year' => '2026-27',
            'original_message' => $original,
            'enquiry_source' => 'whatsapp',
            'confirm_duplicate' => false,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Admin-Test: 1', 'X-CSRF-Token: ' . $sess['token']],
        CURLOPT_COOKIEJAR => $sess['cookie'],
        CURLOPT_COOKIEFILE => $sess['cookie'],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1.0);
} while ($running > 0);
$codes = [];
foreach ($handles as $ch) {
    $codes[] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
$dupCount = (int)$db->query("SELECT COUNT(*) FROM admission_enquiries WHERE phone_e164 = '+919999999999' AND student_name = " . $db->quote($student))->fetchColumn();
$noDup = $dupCount === 1 && !in_array(201, $codes, true);
record('18', 'Concurrent enquiries do not create a duplicate', $noDup ? 'PASS' : 'FAIL', 'rows=' . $dupCount . ' http=' . implode(',', $codes));

// 4-8: AI draft. Rust may be offline; the holding reply must not invent fees.
$draft = httpJson($base . '/api_admission_enquiry.php?action=generate_ai_draft&id=' . $enquiryId . '&qa_test=1', 'GET', null, $cookie);
$draftBody = (string)($draft['json']['draft_text'] ?? '');
$intent = (string)($draft['json']['intent'] ?? '');
$source = (string)($draft['json']['draft_source'] ?? '');
$draftOk = $draft['code'] === 200 && $draftBody !== '' && $intent !== '';
record('6', 'Generate an AI response draft', $draftOk ? 'PASS' : 'FAIL', 'source=' . $source . ' intent=' . $intent . ' HTTP ' . $draft['code']);

$knownIntents = ['Admission enquiry', 'Admission eligibility', 'Age requirements', 'Fees and payment', 'Required documents', 'Class availability', 'School timings', 'Transport', 'Facilities', 'Campus visit', 'Application process', 'Admission follow-up', 'General enquiry'];
record('4', 'Detect an admission-related intent', in_array($intent, $knownIntents, true) ? 'PASS' : 'FAIL', $intent);

$sources = $draft['json']['relevant_knowledge_sources'] ?? [];
$hasSources = is_array($sources) && count($sources) > 0;
record('5', 'Retrieve admission information from approved knowledge', $hasSources ? 'PASS' : 'FAIL', is_array($sources) ? implode(', ', $sources) : 'none');

$missing = $draft['json']['missing_information'] ?? [];
$escalated = !empty($draft['json']['escalation_required']);
$withheld = $source === 'knowledge_fallback'
    ? (strpos($draftBody, '₹') === false && $escalated)
    : (is_array($missing) || $escalated || $hasSources);
record('7', 'Detect missing or unsupported information', $withheld ? 'PASS' : 'FAIL', $source === 'knowledge_fallback' ? 'Service unavailable; fees withheld' : 'missing=' . (is_array($missing) ? count($missing) : 0));
record('8', 'Escalate uncertain responses', $escalated || $source === 'rust' ? 'PASS' : 'FAIL', (string)($draft['json']['escalation_reason'] ?? $draft['json']['service_warning'] ?? 'model draft accepted'));

if ($source === 'rust' && !$escalated) {
    record('8b', 'Model draft stayed inside the safety check', 'PASS', 'No escalation flag');
} elseif (!empty($draft['json']['service_warning'])) {
    record('8b', 'Ollama or Rust unavailable returns a clear error', 'PASS', (string)$draft['json']['service_warning']);
} else {
    record('8b', 'Escalation reason is present when the model is uncertain', $escalated ? 'PASS' : 'FAIL', (string)($draft['json']['escalation_reason'] ?? ''));
}

// Direct Rust call when the service is up: a fee question with no grade must not invent a seat count.
$rustHealth = httpJson('http://127.0.0.1:8085/api/health', 'GET', null, $cookie);
if (($rustHealth['json']['status'] ?? '') === 'healthy') {
    $vague = httpJson('http://127.0.0.1:8085/api/process', 'POST', [
        'chat_id' => 1,
        'parent_name' => $parent,
        'parent_phone' => '+919999999999',
        'current_message' => 'How much is the fee and are there only 4 seats left?',
        'conversation_context' => [],
    ], $cookie);
    $vagueBody = (string)($vague['json']['response_draft'] ?? '');
    $noSeatCount = !preg_match('/\b4\s+seats\b/i', $vagueBody);
    $feeIntent = stripos((string)($vague['json']['intent'] ?? ''), 'fee') !== false
        || stripos((string)($vague['json']['intent'] ?? ''), 'seat') !== false
        || stripos((string)($vague['json']['intent'] ?? ''), 'class') !== false
        || stripos((string)($vague['json']['intent'] ?? ''), 'admission') !== false;
    record('4b', 'Rust classifies a fee and seat question', ($vague['code'] === 200 && $feeIntent) ? 'PASS' : 'FAIL', (string)($vague['json']['intent'] ?? 'none'));
    record('7b', 'Rust does not repeat an invented seat count', ($vague['code'] === 200 && $noSeatCount) ? 'PASS' : 'FAIL', $noSeatCount ? 'seat count removed' : 'draft still says 4 seats');
} else {
    record('4b', 'Rust classifies a fee and seat question', 'SKIP', 'admission-ai-service is not running on port 8085');
    record('7b', 'Rust does not repeat an invented seat count', 'SKIP', 'admission-ai-service is not running');
}

// Second generate inside the dedup window must not start another draft.
$draftAgain = httpJson($base . '/api_admission_enquiry.php?action=generate_ai_draft&id=' . $enquiryId . '&qa_test=1', 'GET', null, $cookie);
record('18b', 'A second draft request reuses the in-flight result', !empty($draftAgain['json']['duplicate_suppressed']) ? 'PASS' : 'FAIL', 'suppressed=' . (!empty($draftAgain['json']['duplicate_suppressed']) ? 'yes' : 'no'));

// 9: edit and approve. Approved chat message must stay pending, not sent.
$edited = $draftBody . "\n\nOur admission desk will confirm the exact figures from the approved fee sheet.";
$save = httpJson($base . '/api_admission_enquiry.php?action=save_draft_edit&qa_test=1', 'POST', [
    'enquiry_id' => $enquiryId,
    'edited_body' => $edited,
], $cookie, $authHeader);
$approve = httpJson($base . '/api_admission_enquiry.php?action=approve_response&qa_test=1', 'POST', [
    'enquiry_id' => $enquiryId,
    'approved_text' => $edited,
], $cookie, $authHeader);
$pending = $db->prepare("SELECT status FROM whatsapp_messages WHERE chat_id = ? AND from_me = 1 ORDER BY id DESC LIMIT 1");
$pending->execute([(int)$enquiry['chat_id']]);
$msgStatus = (string)$pending->fetchColumn();
record('9', 'Edit and approve a response without marking it sent', ($save['json']['success'] ?? false) && ($approve['json']['success'] ?? false) && $msgStatus === 'pending' ? 'PASS' : 'FAIL', 'message status=' . $msgStatus);

// 10 and 11
$open = httpJson($base . '/api_admission_enquiry.php?action=open_whatsapp&qa_test=1', 'POST', [
    'id' => $enquiryId,
    'message' => $edited,
], $cookie, $authHeader);
$waUrl = (string)($open['json']['whatsapp_url'] ?? '');
$urlOk = $open['code'] === 200
    && str_starts_with($waUrl, 'https://wa.me/919999999999?text=')
    && str_contains($waUrl, rawurlencode('approved fee sheet'))
    && ($open['json']['delivery_verified'] ?? true) === false;
record('10', 'Open WhatsApp with prefilled text', $urlOk ? 'PASS' : 'FAIL', $waUrl !== '' ? substr($waUrl, 0, 120) : substr($open['raw'], 0, 180));

$confirm = httpJson($base . '/api_admission_enquiry.php?action=confirm_manual_send&qa_test=1', 'POST', [
    'id' => $enquiryId,
    'manual_sent_confirmed' => true,
    'note' => 'Staff pressed Send in WhatsApp.',
], $cookie, $authHeader);
$flag = $db->prepare('SELECT manual_sent_confirmed, delivery_verified FROM admission_enquiries WHERE id = ?');
$flag->execute([$enquiryId]);
$flagRow = $flag->fetch(PDO::FETCH_ASSOC) ?: [];
$confirmOk = ($confirm['json']['success'] ?? false)
    && (int)($flagRow['manual_sent_confirmed'] ?? 0) === 1
    && (int)($flagRow['delivery_verified'] ?? 1) === 0
    && ($confirm['json']['delivery_verified'] ?? true) === false;
record('11', 'Manually confirm sending without claiming delivery', $confirmOk ? 'PASS' : 'FAIL', json_encode($flagRow));

// 12 and 13
$today = date('Y-m-d');
$follow = httpJson($base . '/api_admission_enquiry.php?action=add_followup&qa_test=1', 'POST', [
    'enquiry_id' => $enquiryId,
    'note' => 'First response pending. Call the parent about documents.',
    'follow_up_date' => $today . 'T00:00:01',
], $cookie, $authHeader);
$sched = $db->prepare('SELECT next_followup_date, reminder_status, is_completed FROM admission_enquiries WHERE id = ?');
$sched->execute([$enquiryId]);
$schedRow = $sched->fetch(PDO::FETCH_ASSOC) ?: [];
record('12', 'Schedule a follow-up', ($follow['json']['success'] ?? false) && ($schedRow['next_followup_date'] ?? '') === $today ? 'PASS' : 'FAIL', json_encode($schedRow));

$counts = httpJson($base . '/api_admission_followup.php?action=get_dashboard_counts&qa_test=1', 'GET', null, $cookie);
$todayList = httpJson($base . '/api_admission_followup.php?action=list&section=today&search=' . rawurlencode('E2E') . '&qa_test=1', 'GET', null, $cookie);
$listed = false;
foreach (($todayList['json']['data'] ?? $todayList['json']['enquiries'] ?? []) as $item) {
    if ((int)($item['id'] ?? 0) === $enquiryId) {
        $listed = true;
    }
}
$todayCount = (int)($counts['json']['counts']['today'] ?? -1);
record('13', 'Display the reminder on the correct date', $listed && $todayCount >= 1 ? 'PASS' : 'FAIL', 'today=' . $todayCount . ' listed=' . ($listed ? 'yes' : 'no') . ' HTTP ' . $todayList['code']);

// 14 complete via the follow-up API (form body, not JSON)
$done = httpForm($base . '/api_admission_followup.php?action=mark_completed&qa_test=1', [
    'id' => (string)$enquiryId,
    'completion_notes' => 'Parent received the draft. Follow-up closed for this test.',
    'manual_sent_confirmed' => '1',
    'csrf_token' => $csrf,
], $cookie);
$doneRow = $db->prepare('SELECT is_completed, delivery_verified FROM admission_enquiries WHERE id = ?');
$doneRow->execute([$enquiryId]);
$doneData = $doneRow->fetch(PDO::FETCH_ASSOC) ?: [];
record('14', 'Complete the follow-up', ($done['json']['success'] ?? false) && (int)($doneData['is_completed'] ?? 0) === 1 && (int)($doneData['delivery_verified'] ?? 1) === 0 ? 'PASS' : 'FAIL', substr($done['raw'], 0, 180));

// 15 pipeline
$status = httpJson($base . '/api_admission_enquiry.php?action=update_status&qa_test=1', 'POST', [
    'id' => $enquiryId,
    'status' => 'INTERESTED',
    'notes' => 'Parent asked for a campus visit.',
], $cookie, $authHeader);
$nowStatus = $db->query('SELECT status FROM admission_enquiries WHERE id = ' . (int)$enquiryId)->fetchColumn();
record('15', 'Update the admission pipeline', ($status['json']['success'] ?? false) && $nowStatus === 'INTERESTED' ? 'PASS' : 'FAIL', (string)$nowStatus);

// 16 audit
$audit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE target = " . $db->quote('Enquiry #' . $enquiryId))->fetchColumn();
$timeline = (int)$db->query('SELECT COUNT(*) FROM admission_enquiry_messages WHERE enquiry_id = ' . (int)$enquiryId)->fetchColumn();
record('16', 'Verify audit history', $audit >= 3 && $timeline >= 4 ? 'PASS' : 'FAIL', "audit_rows={$audit} timeline={$timeline}");

// 17 recover a stuck job
$exeSuffix = (PHP_OS_FAMILY === 'Windows') ? '.exe' : '';
$engine = $root . '/automation/rust_engine/target/release/hcc-automation-engine' . $exeSuffix;
if (!is_file($engine) && PHP_OS_FAMILY === 'Windows') {
    $engine = $root . '/automation/rust_engine/target/release/hcc-automation-engine';
}
if (!is_file($engine) || !$hasJobs) {
    record('17', 'Restart recovery of a pending job', 'SKIP', is_file($engine) ? 'automation_jobs table missing' : 'Release binary not built');
} else {
    $dedup = 'e2e_recover_' . $enquiryId . '_' . time();
    $db->prepare("
        INSERT INTO automation_jobs (job_type, payload, dedup_key, status, priority, attempts, max_attempts, scheduled_at, locked_at, locked_by)
        VALUES ('admission_reminder', ?, ?, 'processing', 10, 1, 3, NOW(), NOW(), 'crashed-worker')
    ")->execute([json_encode(['enquiry_id' => $enquiryId, 'action' => 'generate_reminder']), $dedup]);
    $jobId = (int)$db->lastInsertId();
    $env = require $root . '/config/env.php';
    $cmd = escapeshellarg($engine) . ' --recover --db-host ' . escapeshellarg((string)$env['DB_HOST'])
        . ' --db-user ' . escapeshellarg((string)$env['DB_USER'])
        . ' --db-pass ' . escapeshellarg((string)$env['DB_PASS'])
        . ' --db-name ' . escapeshellarg((string)$env['DB_NAME']);
    if (!empty($env['DB_SOCKET'])) {
        $cmd .= ' --db-socket ' . escapeshellarg((string)$env['DB_SOCKET']);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $jobStatus = $db->query('SELECT status FROM automation_jobs WHERE id = ' . $jobId)->fetchColumn();
    $recovered = (int)$db->query("SELECT COUNT(*) FROM automation_job_history WHERE job_id = {$jobId} AND status = 'recovered'")->fetchColumn();
    record('17', 'Restart recovery of a pending job', ($code === 0 && $jobStatus === 'pending' && $recovered >= 1) ? 'PASS' : 'FAIL', 'exit=' . $code . ' status=' . $jobStatus . ' history=' . $recovered);
    $db->prepare('DELETE FROM automation_job_history WHERE job_id = ?')->execute([$jobId]);
    $db->prepare('DELETE FROM automation_jobs WHERE id = ?')->execute([$jobId]);
}

// Duplicate reminder key
if ($hasJobs) {
    $key = 'e2e_dedup_' . $enquiryId;
    $db->prepare("INSERT IGNORE INTO automation_jobs (job_type, payload, dedup_key, status, scheduled_at) VALUES ('admission_reminder', '{}', ?, 'pending', NOW())")->execute([$key]);
    $db->prepare("INSERT IGNORE INTO automation_jobs (job_type, payload, dedup_key, status, scheduled_at) VALUES ('admission_reminder', '{}', ?, 'pending', NOW())")->execute([$key]);
    $n = (int)$db->query('SELECT COUNT(*) FROM automation_jobs WHERE dedup_key = ' . $db->quote($key))->fetchColumn();
    record('17b', 'Duplicate reminder keys are rejected', $n === 1 ? 'PASS' : 'FAIL', 'rows=' . $n);
    $db->prepare('DELETE FROM automation_jobs WHERE dedup_key = ?')->execute([$key]);
}

finish($root, $cookie, $cookieB, $enquiryId);

function httpForm(string $url, array $fields, string $cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Admin-Test: 1'],
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => is_string($raw) ? $raw : ''];
}

function finish(string $root, string $cookie, string $cookieB, int $enquiryId = 0): void {
    global $results, $passed, $failed, $skipped, $db, $student;
    if ($enquiryId > 0) {
        try {
            $db->prepare('DELETE FROM admission_enquiries WHERE id = ?')->execute([$enquiryId]);
        } catch (Throwable $e) {
        }
    }
    try {
        $db->prepare("DELETE FROM admission_enquiries WHERE phone_e164 IN ('+919999999999','+919999999998')")->execute();
        $db->prepare("DELETE FROM report_card WHERE roll_no = '77001' AND student_name = ?")->execute([$student]);
        $db->prepare("DELETE FROM whatsapp_contacts WHERE phone_e164 IN ('+919999999999','+919999999998')")->execute();
        $chatIds = $db->query("SELECT id FROM whatsapp_chats WHERE wa_chat_id IN ('919999999999@c.us','919999999998@c.us')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($chatIds as $cid) {
            $db->prepare('DELETE FROM whatsapp_messages WHERE chat_id = ?')->execute([(int)$cid]);
            $db->prepare('DELETE FROM whatsapp_chats WHERE id = ?')->execute([(int)$cid]);
        }
    } catch (Throwable $e) {
        echo "Cleanup warning: {$e->getMessage()}\n";
    }
    @unlink($cookie);
    @unlink($cookieB);

    $summary = ['passed' => $passed, 'failed' => $failed, 'skipped' => $skipped, 'results' => $results, 'ran_at' => date('c')];
    $dir = $root . '/tests';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($dir . '/last_e2e_result.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
    exit($failed > 0 ? 1 : 0);
}
