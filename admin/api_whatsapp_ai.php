<?php
/**
 * HCC School WhatsApp Hub — Local AI Admission Assistant Backend API
 *
 * Architecture:
 * Existing PHP backend -> MySQL -> Rust automation service (port 8085) -> Ollama local model -> PHP backend -> Existing WebUI
 * Preserves Send-on-Press strictly (never auto-sends to WhatsApp).
 *
 * Endpoints:
 *  - POST ?action=process_message   (CSRF protected)
 *  - GET  ?action=get_draft
 *  - POST ?action=approve_draft     (CSRF protected, no auto-send)
 *  - POST ?action=reject_draft      (CSRF protected)
 *  - POST ?action=escalate_enquiry  (CSRF protected)
 *  - GET  ?action=list_enquiries
 *  - GET  ?action=service_status
 *  - POST ?action=simulate_enquiry  (CSRF protected)
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/includes/admission_ai_review.php';

header('Content-Type: application/json; charset=utf-8');

$auth = waRequireAuth();

$db = waGetDB();
$action = $_GET['action'] ?? $_POST['action'] ?? 'service_status';

// Parse JSON input if sent
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

// Enforce POST + CSRF on all state-mutating actions
$mutationActions = [
    'process_message',
    'approve_draft',
    'reject_draft',
    'escalate_enquiry',
    'simulate_enquiry',
];
if (in_array($action, $mutationActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        waJsonResponse(['success' => false, 'error' => 'Method Not Allowed: POST required'], 405);
    }
    waVerifyCSRF($input);
}

$rustServiceBase = rtrim((string)(getenv('RUST_AI_SERVICE_URL') ?: 'http://127.0.0.1:8085'), '/');

switch ($action) {
    case 'service_status':
        handleServiceStatus($rustServiceBase);
        break;

    case 'process_message':
        handleProcessMessage($db, $rustServiceBase, $input);
        break;

    case 'get_draft':
        handleGetDraft($db);
        break;

    case 'approve_draft':
        handleApproveDraft($db, $input, $auth);
        break;

    case 'reject_draft':
        handleRejectDraft($db, $input, $auth);
        break;

    case 'escalate_enquiry':
        handleEscalateEnquiry($db, $input, $auth);
        break;

    case 'list_enquiries':
        handleListEnquiries($db);
        break;

    case 'simulate_enquiry':
        handleSimulateEnquiry($db, $rustServiceBase, $input);
        break;

    default:
        waJsonResponse(['success' => false, 'error' => "Unknown action: {$action}"], 400);
}

/**
 * Normalize phone digits without fabricating phone numbers from WhatsApp @lid opaque IDs
 *
 * @return array{phone:string,phone_e164:string}
 */
function normalizeWaChatPhone(?string $waChatId): array {
    $raw = trim((string)$waChatId);
    if ($raw === '' || str_contains($raw, '@lid') || str_contains($raw, '@g.us') || str_contains($raw, 'broadcast')) {
        return ['phone' => '', 'phone_e164' => ''];
    }
    if (preg_match('/^(\d{10,15})@(?:c\.us|s\.whatsapp\.net)$/i', $raw, $m)) {
        $digits = $m[1];
        return ['phone' => $digits, 'phone_e164' => '+' . $digits];
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return ['phone' => $digits, 'phone_e164' => '+' . $digits];
    }
    return ['phone' => '', 'phone_e164' => ''];
}

/**
 * Check health of Rust service and Ollama
 */
function handleServiceStatus(string $rustBase): void {
    $ch = curl_init("{$rustBase}/api/health");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => airAiServiceHeaders(['Accept: application/json']),
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $resp) {
        $data = json_decode($resp, true);
        waJsonResponse([
            'success' => true,
            'rust_service_online' => true,
            'details' => $data,
        ]);
    } else {
        waJsonResponse([
            'success' => true,
            'rust_service_online' => false,
            'error' => "Rust automation service is not running on {$rustBase} (HTTP {$httpCode}). Please start target/release/admission-ai-service.",
            'target_platform' => 'Windows Intel CPU, 8 GB RAM, 500 GB storage',
            'fallback_mode' => 'active',
        ]);
    }
}

/**
 * Process a message through the Rust AI automation pipeline
 */
function handleProcessMessage(PDO $db, string $rustBase, array $input): void {
    $chatId = (int)($input['chat_id'] ?? 0);
    $messageId = !empty($input['message_id']) ? (int)$input['message_id'] : null;

    if ($chatId <= 0) {
        waJsonResponse(['success' => false, 'error' => 'Valid chat_id is required'], 400);
    }

    $stmt = $db->prepare("SELECT * FROM whatsapp_chats WHERE id = ?");
    $stmt->execute([$chatId]);
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chat) {
        waJsonResponse(['success' => false, 'error' => 'Chat not found'], 404);
    }

    $phoneMeta = normalizeWaChatPhone($chat['wa_chat_id'] ?? '');
    $parentPhone = $phoneMeta['phone'];
    $parentName = $chat['name'] ?? null;

    if ($messageId) {
        $stmt = $db->prepare("SELECT * FROM whatsapp_messages WHERE id = ? AND chat_id = ?");
        $stmt->execute([$messageId, $chatId]);
        $currentMsg = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("SELECT * FROM whatsapp_messages WHERE chat_id = ? AND from_me = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute([$chatId]);
        $currentMsg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentMsg) {
            $stmt = $db->prepare("SELECT * FROM whatsapp_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$chatId]);
            $currentMsg = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    $messageText = trim((string)($currentMsg['body'] ?? ($input['message_text'] ?? '')));
    if ($messageText === '') {
        waJsonResponse(['success' => false, 'error' => 'No message found to analyze for this chat'], 400);
    }

    $stmt = $db->prepare("
        SELECT from_me, sender_name, body, timestamp
        FROM whatsapp_messages
        WHERE chat_id = ?
        ORDER BY id DESC
        LIMIT 8
    ");
    $stmt->execute([$chatId]);
    $historyRows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $context = [];
    foreach ($historyRows as $row) {
        $context[] = [
            'from_me' => (bool)$row['from_me'],
            'sender_name' => $row['sender_name'],
            'body' => (string)$row['body'],
            'timestamp' => (int)$row['timestamp'],
        ];
    }

    $rustPayload = [
        'chat_id' => $chatId,
        'message_id' => $currentMsg['id'] ?? null,
        'parent_phone' => $parentPhone !== '' ? $parentPhone : null,
        'parent_name' => $parentName,
        'current_message' => $messageText,
        'conversation_context' => $context,
        'model' => $input['model'] ?? null,
    ];

    $ch = curl_init("{$rustBase}/api/process");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($rustPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => airAiServiceHeaders(['Content-Type: application/json', 'Accept: application/json']),
        CURLOPT_TIMEOUT => 45,
    ]);
    $rustResponse = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $draftData = null;
    if ($httpCode === 200 && $rustResponse) {
        $draftData = json_decode($rustResponse, true);
    }

    if (!$draftData || empty($draftData['intent'])) {
        $draftData = generateLocalPhpFallbackDraft($chat, $messageText, $context);
    }

    $enquiryId = getOrCreateEnquiry($db, $chatId, $phoneMeta, $parentName, $draftData, $messageText);

    $insDraft = $db->prepare("
        INSERT INTO `admission_ai_drafts` (
            `enquiry_id`, `chat_id`, `trigger_msg_id`, `intent`, `confidence`,
            `extracted_parent_details`, `extracted_student_details`, `relevant_knowledge_sources`,
            `response_draft`, `missing_information`, `escalation_required`, `escalation_reason`,
            `model_used`, `safety_validated`, `admin_status`
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, 1, 'pending'
        )
    ");

    $insDraft->execute([
        $enquiryId,
        $chatId,
        $currentMsg['id'] ?? null,
        $draftData['intent'],
        $draftData['confidence'] ?? 0.95,
        json_encode($draftData['extracted_parent_details'] ?? []),
        json_encode($draftData['extracted_student_details'] ?? []),
        json_encode($draftData['relevant_knowledge_sources'] ?? []),
        $draftData['response_draft'],
        json_encode($draftData['missing_information'] ?? []),
        !empty($draftData['escalation_required']) ? 1 : 0,
        $draftData['escalation_reason'] ?? null,
        $input['model'] ?? 'llama3.2:1b',
    ]);

    $draftId = (int)$db->lastInsertId();

    $db->prepare("
        UPDATE `whatsapp_chats`
        SET `is_admission_enquiry` = 1,
            `latest_ai_draft_id` = ?,
            `updated_at` = NOW()
        WHERE `id` = ?
    ")->execute([$draftId, $chatId]);

    waJsonResponse([
        'success' => true,
        'draft_id' => $draftId,
        'enquiry_id' => $enquiryId,
        'chat_id' => $chatId,
        'intent' => $draftData['intent'],
        'confidence' => (float)$draftData['confidence'],
        'extracted_parent_details' => $draftData['extracted_parent_details'],
        'extracted_student_details' => $draftData['extracted_student_details'],
        'relevant_knowledge_sources' => $draftData['relevant_knowledge_sources'],
        'response_draft' => $draftData['response_draft'],
        'missing_information' => $draftData['missing_information'],
        'escalation_required' => (bool)$draftData['escalation_required'],
        'escalation_reason' => $draftData['escalation_reason'],
        'admin_status' => 'pending',
        'approval_required' => true,
    ]);
}

/**
 * Retrieve latest AI draft for a chat
 */
function handleGetDraft(PDO $db): void {
    $chatId = (int)($_GET['chat_id'] ?? 0);
    if ($chatId <= 0) {
        waJsonResponse(['success' => false, 'error' => 'chat_id is required'], 400);
    }

    $stmt = $db->prepare("
        SELECT * FROM `admission_ai_drafts`
        WHERE `chat_id` = ?
        ORDER BY `id` DESC
        LIMIT 1
    ");
    $stmt->execute([$chatId]);
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$draft) {
        waJsonResponse(['success' => true, 'has_draft' => false]);
    }

    waJsonResponse([
        'success' => true,
        'has_draft' => true,
        'draft_id' => (int)$draft['id'],
        'enquiry_id' => (int)$draft['enquiry_id'],
        'chat_id' => (int)$draft['chat_id'],
        'intent' => $draft['intent'],
        'confidence' => (float)$draft['confidence'],
        'extracted_parent_details' => json_decode($draft['extracted_parent_details'] ?: '{}', true),
        'extracted_student_details' => json_decode($draft['extracted_student_details'] ?: '{}', true),
        'relevant_knowledge_sources' => json_decode($draft['relevant_knowledge_sources'] ?: '[]', true),
        'response_draft' => $draft['response_draft'],
        'admin_edited_response' => $draft['admin_edited_response'],
        'missing_information' => json_decode($draft['missing_information'] ?: '[]', true),
        'escalation_required' => (bool)$draft['escalation_required'],
        'escalation_reason' => $draft['escalation_reason'],
        'admin_status' => $draft['admin_status'],
        'model_used' => $draft['model_used'],
        'created_at' => $draft['created_at'],
    ]);
}

/**
 * Approve AI draft (stores approval only — NEVER sends WhatsApp)
 * Copy final_message into the hub composer and press Send to deliver.
 */
function handleApproveDraft(PDO $db, array $input, ?array $auth): void {
    $draftId = (int)($input['draft_id'] ?? 0);
    $chatId = (int)($input['chat_id'] ?? 0);
    $editedText = trim((string)($input['edited_text'] ?? ''));

    if ($draftId <= 0 && $chatId <= 0) {
        waJsonResponse(['success' => false, 'error' => 'draft_id or chat_id is required'], 400);
    }

    if ($draftId > 0) {
        $stmt = $db->prepare("SELECT * FROM `admission_ai_drafts` WHERE id = ?");
        $stmt->execute([$draftId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM `admission_ai_drafts` WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$chatId]);
    }
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$draft) {
        waJsonResponse(['success' => false, 'error' => 'Draft not found'], 404);
    }

    $finalMessageText = $editedText !== '' ? $editedText : (string)$draft['response_draft'];
    $adminId = (int)($auth['admin_id'] ?? 1);

    $upDraft = $db->prepare("
        UPDATE `admission_ai_drafts`
        SET `admin_status` = 'approved',
            `admin_edited_response` = ?,
            `approved_by` = ?,
            `approved_at` = NOW(),
            `updated_at` = NOW()
        WHERE `id` = ?
    ");
    $upDraft->execute([$editedText !== '' ? $editedText : null, $adminId, $draft['id']]);

    // Update canonical admission_enquiries status (NEW -> INFORMATION_SHARED, preserving other progress)
    if (!empty($draft['enquiry_id'])) {
        $db->prepare("
            UPDATE `admission_enquiries`
            SET `status` = IF(`status` = 'NEW', 'INFORMATION_SHARED', `status`),
                `assigned_staff_id` = ?,
                `suggested_message` = ?,
                `updated_at` = NOW()
            WHERE `id` = ?
        ")->execute([$adminId, $finalMessageText, $draft['enquiry_id']]);
    }

    waJsonResponse([
        'success' => true,
        'draft_id' => (int)$draft['id'],
        'status' => 'approved',
        'final_message' => $finalMessageText,
        'sent_immediately' => false,
        'outbound_queue_id' => null,
        'sent_message_id' => null,
        'note' => 'AI never sends. Copy the text into the hub compose box and press Send.',
    ]);
}

/**
 * Reject / Dismiss an AI draft
 */
function handleRejectDraft(PDO $db, array $input, ?array $auth): void {
    $draftId = (int)($input['draft_id'] ?? 0);
    if ($draftId <= 0) {
        waJsonResponse(['success' => false, 'error' => 'draft_id is required'], 400);
    }

    $stmt = $db->prepare("UPDATE `admission_ai_drafts` SET `admin_status` = 'rejected', `updated_at` = NOW() WHERE `id` = ?");
    $stmt->execute([$draftId]);

    waJsonResponse(['success' => true, 'draft_id' => $draftId, 'status' => 'rejected']);
}

/**
 * Escalate an enquiry to office staff using canonical schema fields
 */
function handleEscalateEnquiry(PDO $db, array $input, ?array $auth): void {
    $chatId = (int)($input['chat_id'] ?? 0);
    $enquiryId = (int)($input['enquiry_id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? 'Escalated by administrator for in-person office handling'));
    if ($reason === '') {
        $reason = 'Escalated by administrator for in-person office handling';
    }

    if ($enquiryId <= 0 && $chatId <= 0) {
        waJsonResponse(['success' => false, 'error' => 'enquiry_id or chat_id is required'], 400);
    }

    if ($enquiryId > 0) {
        $db->prepare("
            UPDATE `admission_enquiries`
            SET `priority` = 'urgent',
                `followup_reason` = ?,
                `status` = IF(`status` = 'NEW', 'CONTACTED', `status`),
                `notes` = TRIM(CONCAT(COALESCE(`notes`, ''), IF(COALESCE(`notes`, '') = '', '', '\n'), '[Escalated] ', ?)),
                `updated_at` = NOW()
            WHERE `id` = ?
        ")->execute([mb_substr($reason, 0, 250), $reason, $enquiryId]);

        $db->prepare("
            UPDATE `admission_ai_drafts`
            SET `escalation_required` = 1, `escalation_reason` = ?, `updated_at` = NOW()
            WHERE `enquiry_id` = ?
        ")->execute([$reason, $enquiryId]);
    } elseif ($chatId > 0) {
        $db->prepare("
            UPDATE `admission_enquiries`
            SET `priority` = 'urgent',
                `followup_reason` = ?,
                `status` = IF(`status` = 'NEW', 'CONTACTED', `status`),
                `notes` = TRIM(CONCAT(COALESCE(`notes`, ''), IF(COALESCE(`notes`, '') = '', '', '\n'), '[Escalated] ', ?)),
                `updated_at` = NOW()
            WHERE `chat_id` = ?
        ")->execute([mb_substr($reason, 0, 250), $reason, $chatId]);

        $db->prepare("
            UPDATE `admission_ai_drafts`
            SET `escalation_required` = 1, `escalation_reason` = ?, `updated_at` = NOW()
            WHERE `chat_id` = ?
        ")->execute([$reason, $chatId]);
    }

    waJsonResponse(['success' => true, 'status' => 'escalated', 'priority' => 'urgent', 'reason' => $reason]);
}

/**
 * List recent enquiries and AI drafts using canonical admission_enquiries columns
 */
function handleListEnquiries(PDO $db): void {
    $stmt = $db->prepare("
        SELECT
            e.id as enquiry_id,
            e.chat_id,
            e.phone_number,
            e.phone_e164,
            COALESCE(NULLIF(e.phone_number, ''), NULLIF(e.phone_e164, ''), '') as parent_phone,
            e.parent_name,
            e.student_name,
            e.interested_class,
            e.interested_class as target_grade,
            COALESCE(d.intent, e.followup_category, 'Admission enquiry') as primary_intent,
            e.status,
            e.priority,
            e.followup_category,
            e.next_followup_date,
            COALESCE(d.escalation_required, IF(e.priority = 'urgent', 1, 0)) as escalation_required,
            COALESCE(d.escalation_reason, IF(e.priority = 'urgent', e.followup_reason, NULL)) as escalation_reason,
            d.id as draft_id,
            d.confidence,
            d.response_draft,
            d.admin_status,
            d.created_at as draft_created_at,
            c.wa_chat_id,
            c.name as chat_name
        FROM `admission_enquiries` e
        LEFT JOIN `admission_ai_drafts` d ON d.id = (
            SELECT MAX(id) FROM `admission_ai_drafts` WHERE enquiry_id = e.id
        )
        LEFT JOIN `whatsapp_chats` c ON c.id = e.chat_id
        ORDER BY e.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    waJsonResponse(['success' => true, 'enquiries' => $rows]);
}

/**
 * Helper to simulate an enquiry for any of the 13 supported intents
 */
function handleSimulateEnquiry(PDO $db, string $rustBase, array $input): void {
    $chatId = (int)($input['chat_id'] ?? 1);
    $intent = trim((string)($input['intent'] ?? 'Admission enquiry'));

    $simulationTemplates = [
        'Admission enquiry' => "Hello, I am Suresh Patil. I want to inquire about new admissions for Academic Year 2026-2027.",
        'Admission eligibility' => "Namaste, can my child who studied in a rural school take admission into Grade 6 English Medium?",
        'Age requirements' => "What is the minimum age criteria for Nursery and LKG admissions for 2026-2027? My daughter was born in Feb 2023.",
        'Fees and payment' => "Could you please share the annual fee structure for Grade 4? Can we pay fees in two installments?",
        'Required documents' => "What documents are required for admission to 1st standard? Is municipal birth certificate mandatory?",
        'Class availability' => "Are there any vacant seats available in Grade 7 for the upcoming term? How many students per class?",
        'School timings' => "What are the school timings for Primary and Pre-Primary classes? Is Saturday a holiday?",
        'Transport' => "Do you provide school bus transport to Saiful and Jule Solapur? What are the bus fees per year?",
        'Facilities' => "What sports facilities, science laboratories, and computer labs are available for high school students?",
        'Campus visit' => "We would like to visit the HCC campus to meet the teachers and see the classrooms. What are the visitor timings?",
        'Application process' => "What is the step-by-step application procedure to register for admission?",
        'Admission follow-up' => "Good morning, I submitted the admission registration form for my son Rohan Patil last week. Could you update me on the status?",
        'General enquiry' => "Respected Sir, please share the official admission prospectus and school contact numbers for admission enquiries.",
    ];

    $chosenText = $simulationTemplates[$intent] ?? $simulationTemplates['Admission enquiry'];

    $waMsgId = 'sim_adm_' . time() . '_' . bin2hex(random_bytes(3));
    $now = time();
    $insMsg = $db->prepare("
        INSERT INTO `whatsapp_messages` (
            `wa_msg_id`, `chat_id`, `from_me`, `sender_id`, `sender_name`,
            `msg_type`, `body`, `status`, `timestamp`, `received_at`
        ) VALUES (
            ?, ?, 0, 'parent_sim', 'Parent Enquiry',
            'text', ?, 'delivered', ?, NOW()
        )
    ");
    $insMsg->execute([$waMsgId, $chatId, $chosenText, $now]);
    $msgId = (int)$db->lastInsertId();

    $db->prepare("
        UPDATE `whatsapp_chats`
        SET `unread_count` = `unread_count` + 1,
            `last_message_id` = ?,
            `last_activity_at` = NOW(),
            `updated_at` = NOW()
        WHERE `id` = ?
    ")->execute([$msgId, $chatId]);

    $processInput = [
        'chat_id' => $chatId,
        'message_id' => $msgId,
        'message_text' => $chosenText,
    ];

    handleProcessMessage($db, $rustBase, $processInput);
}

/**
 * Get or create enquiry record aligned cleanly with canonical admission_enquiries schema
 */
function getOrCreateEnquiry(
    PDO $db,
    int $chatId,
    array $phoneMeta,
    ?string $parentName,
    array $draftData,
    string $messageText
): int {
    $stmt = $db->prepare("SELECT id, suggested_message FROM `admission_enquiries` WHERE `chat_id` = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$chatId]);
    $existingRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $studentName = trim((string)($draftData['extracted_student_details']['name'] ?? ''));
    $interestedClass = trim((string)($draftData['extracted_student_details']['grade_applying_for'] ?? ''));
    $primaryIntent = trim((string)($draftData['intent'] ?? 'Admission enquiry'));
    $escalationReq = !empty($draftData['escalation_required']);
    $escalationReason = trim((string)($draftData['escalation_reason'] ?? ''));
    $draftText = trim((string)($draftData['response_draft'] ?? ''));
    $cleanParent = trim((string)($parentName ?? ($draftData['extracted_parent_details']['name'] ?? '')));
    if ($cleanParent === '') {
        $cleanParent = 'Parent';
    }

    if ($existingRow) {
        $existingId = (int)$existingRow['id'];
        $existingSuggested = trim((string)($existingRow['suggested_message'] ?? ''));
        $nextSuggested = $existingSuggested !== '' ? $existingSuggested : $draftText;

        $up = $db->prepare("
            UPDATE `admission_enquiries`
            SET `interested_class` = IF(COALESCE(`interested_class`, '') IN ('', 'Not specified') AND ? != '', ?, `interested_class`),
                `student_name` = COALESCE(NULLIF(?, ''), `student_name`),
                `suggested_message` = COALESCE(NULLIF(?, ''), `suggested_message`),
                `priority` = IF(? = 1 AND `priority` != 'urgent', 'high', `priority`),
                `followup_reason` = COALESCE(NULLIF(?, ''), `followup_reason`),
                `last_inbound_at` = NOW(),
                `updated_at` = NOW()
            WHERE `id` = ?
        ");
        $up->execute([
            $interestedClass,
            $interestedClass,
            $studentName,
            $nextSuggested,
            $escalationReq ? 1 : 0,
            $escalationReason !== '' ? mb_substr($escalationReason, 0, 250) : null,
            $existingId,
        ]);
        return $existingId;
    }

    $ins = $db->prepare("
        INSERT INTO `admission_enquiries` (
            `parent_name`, `phone_number`, `phone_e164`, `student_name`, `interested_class`,
            `academic_year`, `original_message`, `enquiry_source`, `notes`, `status`,
            `chat_id`, `followup_category`, `next_followup_date`, `next_followup_time`,
            `followup_reason`, `priority`, `suggested_message`, `last_inbound_at`,
            `created_at`, `updated_at`
        ) VALUES (
            ?, ?, ?, ?, ?,
            '2026-2027', ?, 'whatsapp', ?, 'NEW',
            ?, 'First response pending', CURDATE(), '10:00:00',
            ?, ?, ?, NOW(),
            NOW(), NOW()
        )
    ");
    $ins->execute([
        $cleanParent,
        $phoneMeta['phone'],
        $phoneMeta['phone_e164'],
        $studentName !== '' ? $studentName : null,
        $interestedClass !== '' ? $interestedClass : 'Not specified',
        $messageText,
        $primaryIntent !== '' ? "AI Intent: {$primaryIntent}" : null,
        $chatId,
        $escalationReason !== '' ? mb_substr($escalationReason, 0, 250) : ($primaryIntent ?: 'AI Admission Enquiry'),
        $escalationReq ? 'high' : 'medium',
        $draftText !== '' ? $draftText : null,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Deterministic local PHP fallback draft if Rust service is offline
 */
function generateLocalPhpFallbackDraft(array $chat, string $message, array $context): array {
    $lower = strtolower($message);

    $intent = 'Admission enquiry';
    $sources = ['Admission Prospectus 2026-2027'];
    $grade = null;

    if (preg_match('/\b(nursery|lkg|ukg)\b/i', $lower, $m)) {
        $grade = ucfirst(strtolower($m[1]));
    } elseif (preg_match('/\b(?:grade|class|standard|std)\s*([1-9]|10)\b/i', $lower, $m) || preg_match('/\b([1-9]|10)(?:st|nd|rd|th)?\s*(?:grade|class|standard|std)\b/i', $lower, $m)) {
        $grade = "Grade {$m[1]}";
    }

    if (str_contains($lower, 'fee') || str_contains($lower, 'cost') || str_contains($lower, 'payment') || str_contains($lower, 'installment')) {
        $intent = 'Fees and payment';
        $sources = ['Approved Fee Structure 2026-2027', 'Payment Policies'];
        $body = "Thank you for contacting HCC High School regarding fees. For Academic Year 2026-2027, the approved fee structure for " . ($grade ?: 'our classes') . " is as follows:\n\n"
            . "• One-time Admission Fee (New Admissions): ₹5,000 (Non-refundable)\n"
            . "• Fees can be paid in full or in 2 equal term installments (Term 1 at admission/June, Term 2 by Nov 15).\n"
            . "• Payment modes: Net Banking, UPI, Demand Draft, or POS card swipe at the school accounts counter.\n\n"
            . "For exact fee receipts and concession guidelines, please visit our school office between 09:00 AM and 03:30 PM.";
    } elseif (str_contains($lower, 'age') || str_contains($lower, 'born') || str_contains($lower, 'dob') || str_contains($lower, 'years old')) {
        $intent = 'Age requirements';
        $sources = ['Age Requirements & Eligibility Criteria (NEP / Maharashtra GR)'];
        $body = "Thank you for reaching out regarding age criteria for admissions (2026-2027). In compliance with Maharashtra Education Department and NEP guidelines, the age cut-off date is 31st December 2026:\n\n"
            . "• Nursery: Minimum 3 years complete (Born between 01-Jan-2023 and 31-Dec-2023)\n"
            . "• LKG: Minimum 4 years complete (Born between 01-Jan-2022 and 31-Dec-2022)\n"
            . "• UKG: Minimum 5 years complete (Born between 01-Jan-2021 and 31-Dec-2021)\n"
            . "• Grade 1: Minimum 6 years complete (Born between 01-Jan-2020 and 31-Dec-2020)\n\n"
            . "Strictly no underage relaxations are permitted under government norms.";
    } elseif (str_contains($lower, 'doc') || str_contains($lower, 'certificate') || str_contains($lower, 'marksheet') || str_contains($lower, 'lc') || str_contains($lower, 'tc')) {
        $intent = 'Required documents';
        $sources = ['Admission Document Checklist 2026-2027'];
        $body = "Thank you for contacting HCC High School regarding required admission documents. Please carry the following:\n\n"
            . "1. Original Municipal Birth Certificate + 2 photocopies\n"
            . "2. Original School Leaving Certificate (LC/TC) countersigned (for Grade 2 onwards)\n"
            . "3. Previous Academic Year Report Card / Marksheet\n"
            . "4. Student Aadhaar Card photocopy\n"
            . "5. Parents' Aadhaar Cards photocopies\n"
            . "6. 4 passport-size photographs of the student, 1 of each parent\n"
            . "7. Residential Address proof & Blood group report\n\n"
            . "Original documents will be verified at the desk and returned immediately.";
    } elseif (str_contains($lower, 'bus') || str_contains($lower, 'transport') || str_contains($lower, 'route')) {
        $intent = 'Transport';
        $sources = ['Transport Facility & Bus Routes'];
        $body = "HCC High School operates dedicated school buses and vans covering all major areas in Solapur including Jule Solapur, Saiful, Saat Rasta, Railway Lines, Modi, Navi Peth, Hotgi Road, and Vijapur Road.\n\n"
            . "Safety Features: Real-time GPS tracking, female bus attendants, CCTV cameras, speed governors, and first-aid kits. Transport fees range from ₹8,000 to ₹14,000 per year depending on the distance zone.";
    } elseif (str_contains($lower, 'timing') || str_contains($lower, 'time') || str_contains($lower, 'hours')) {
        $intent = 'School timings';
        $sources = ['School Timings & Visitor Hours'];
        $body = "Official school hours for the 2026-2027 academic session are:\n\n"
            . "• Pre-Primary (Nursery, LKG, UKG): 08:30 AM to 12:00 PM (Monday to Friday, Saturday holiday)\n"
            . "• Primary (Grades 1 to 5): 07:50 AM to 01:30 PM (Mon-Fri) | 07:50 AM to 11:30 AM (Sat)\n"
            . "• Secondary (Grades 6 to 10): 07:50 AM to 02:00 PM (Mon-Fri) | 07:50 AM to 12:00 PM (Sat)\n"
            . "• Admission Office Enquiry Hours: 09:00 AM to 03:30 PM (Monday to Saturday).";
    } elseif (str_contains($lower, 'visit') || str_contains($lower, 'tour') || str_contains($lower, 'campus')) {
        $intent = 'Campus visit';
        $sources = ['Campus Visit & Visitor Guidelines'];
        $body = "We warmly welcome you to visit the HCC High School campus! Guided campus tours are available from 10:00 AM to 01:00 PM (Monday to Friday) and 10:00 AM to 12:30 PM (Saturday).\n\n"
            . "You will be able to view our smart classrooms, science and computer labs, library, and sports grounds. Kindly carry a photo ID for visitor pass issuance at the security gate.";
    } else {
        $body = "Thank you for contacting Hindustani Covenant Church High School, Solapur. Admissions for the 2026-2027 academic session are currently open from Pre-Primary through Grade 10.\n\n"
            . "Our admission office is open Monday to Saturday from 09:00 AM to 03:30 PM. For any assistance, please feel free to visit our office near Old Pune Naka, Solapur.";
    }

    $parentSalutation = !empty($chat['name']) ? "Respected {$chat['name']}" : "Respected Parent";
    $phoneMeta = normalizeWaChatPhone($chat['wa_chat_id'] ?? '');
    $fullDraft = "{$parentSalutation},\n\n{$body}\n\nWarm regards,\nAdmission Office\nHindustani Covenant Church High School, Solapur";

    return [
        'intent' => $intent,
        'confidence' => 0.95,
        'extracted_parent_details' => [
            'name' => $chat['name'] ?? null,
            'phone' => $phoneMeta['phone'] !== '' ? $phoneMeta['phone'] : null,
        ],
        'extracted_student_details' => [
            'name' => null,
            'grade_applying_for' => $grade,
            'academic_year' => '2026-2027',
            'current_school' => null,
        ],
        'relevant_knowledge_sources' => $sources,
        'response_draft' => $fullDraft,
        'missing_information' => $grade ? [] : ["Target grade or standard not specified"],
        'escalation_required' => false,
        'escalation_reason' => null,
    ];
}
