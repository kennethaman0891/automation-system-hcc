<?php
/**
 * HCC Automation System — Admission Enquiry Management API
 * 
 * Handles:
 *  - Enquiry creation with server-side validation & duplicate detection
 *  - Reusing existing student/parent records
 *  - Persistent conversation management (incoming msgs, AI drafts, staff edits, approved responses, follow-up)
 *  - Admission pipeline status updates (authorized admins)
 *  - Configurable duplicate matching rules
 *  - WhatsApp Web chat synchronization
 *  - Comprehensive audit trail
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

// Ensure user is authenticated (admin or editor)
waRequireAuth();

header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$currentUserRole = $_SESSION['admin_role'] ?? 'editor';
$currentUserId   = (int)($_SESSION['admin_id'] ?? 1);
$currentUserName = $_SESSION['admin_name'] ?? 'Administrator';

$action = trim($_GET['action'] ?? ($_POST['action'] ?? ''));

// Helper function to validate CSRF on POST actions
function checkApiCSRF(): void {
    if (!verifyCSRFToken()) {
        waJsonResponse(['success' => false, 'error' => 'Security token invalid or expired. Please refresh the page.'], 403);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. GET CONFIGURABLE MATCHING RULES
// ─────────────────────────────────────────────────────────────────────────────
function getMatchingRules(PDO $db): array {
    $stmt = $db->query("SELECT * FROM admission_matching_rules ORDER BY id ASC");
    $rules = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rules[$row['rule_key']] = [
            'id'          => (int)$row['id'],
            'rule_key'    => $row['rule_key'],
            'rule_name'   => $row['rule_name'],
            'description' => $row['description'],
            'is_enabled'  => (bool)$row['is_enabled'],
            'rule_value'  => $row['rule_value'],
            'action'      => $row['action'] // 'warn' or 'block'
        ];
    }
    return $rules;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. CHECK DUPLICATES & MATCH EXISTING RECORDS
// ─────────────────────────────────────────────────────────────────────────────
function checkDuplicatesAndMatches(PDO $db, string $phoneE164, ?string $studentName, string $academicYear, string $interestedClass, ?int $excludeId = null): array {
    $rules = getMatchingRules($db);
    $duplicates = [];
    $matchedRecords = [];
    $isBlocked = false;
    $isWarned = false;
    $blockReasons = [];
    $warnReasons = [];

    $cleanDigits = preg_replace('/\D/', '', $phoneE164);
    $phoneWithoutCc = strlen($cleanDigits) > 10 ? substr($cleanDigits, -10) : $cleanDigits;

    // Window days
    $windowDays = 90;
    if (!empty($rules['duplicate_window_days']['is_enabled']) && !empty($rules['duplicate_window_days']['rule_value'])) {
        $windowDays = max(0, (int)$rules['duplicate_window_days']['rule_value']);
    }

    $timeClause = "";
    if ($windowDays > 0) {
        $timeClause = " AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowDays} DAY)";
    }

    // A. Scan admission_enquiries for duplicates
    // Rule: match_phone_academic_year
    if (!empty($rules['match_phone_academic_year']['is_enabled'])) {
        $sql = "SELECT id, parent_name, student_name, interested_class, academic_year, status, phone_number, created_at, assigned_staff_name
                FROM admission_enquiries 
                WHERE (phone_e164 = ? OR phone_number LIKE ?) AND academic_year = ? {$timeClause}";
        $params = [$phoneE164, "%$phoneWithoutCc%", $academicYear];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $duplicates[$r['id']] = $r;
            $act = $rules['match_phone_academic_year']['action'];
            $reason = "Enquiry #{$r['id']} ({$r['parent_name']}) already exists for {$academicYear} with status '{$r['status']}'.";
            if ($act === 'block') { $isBlocked = true; $blockReasons[] = $reason; }
            else { $isWarned = true; $warnReasons[] = $reason; }
        }
    }

    // Rule: match_phone_active
    if (!empty($rules['match_phone_active']['is_enabled'])) {
        $sql = "SELECT id, parent_name, student_name, interested_class, academic_year, status, phone_number, created_at, assigned_staff_name
                FROM admission_enquiries 
                WHERE (phone_e164 = ? OR phone_number LIKE ?) AND status NOT IN ('CLOSED', 'ENROLLED') {$timeClause}";
        $params = [$phoneE164, "%$phoneWithoutCc%"];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $duplicates[$r['id']] = $r;
            $act = $rules['match_phone_active']['action'];
            $reason = "Active enquiry #{$r['id']} for {$r['student_name']} ({$r['interested_class']}) is in progress with status '{$r['status']}'.";
            if ($act === 'block') { $isBlocked = true; $blockReasons[] = $reason; }
            else { $isWarned = true; $warnReasons[] = $reason; }
        }
    }

    // Rule: match_phone_student
    if (!empty($rules['match_phone_student']['is_enabled']) && !empty(trim((string)$studentName))) {
        $sql = "SELECT id, parent_name, student_name, interested_class, academic_year, status, phone_number, created_at, assigned_staff_name
                FROM admission_enquiries 
                WHERE (phone_e164 = ? OR phone_number LIKE ?) AND LOWER(TRIM(student_name)) = LOWER(TRIM(?)) {$timeClause}";
        $params = [$phoneE164, "%$phoneWithoutCc%", $studentName];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $duplicates[$r['id']] = $r;
            $act = $rules['match_phone_student']['action'];
            $reason = "Exact match on phone and student name '{$r['student_name']}' in enquiry #{$r['id']}.";
            if ($act === 'block') { $isBlocked = true; $blockReasons[] = $reason; }
            else { $isWarned = true; $warnReasons[] = $reason; }
        }
    }

    // Rule: match_student_class_year
    if (!empty($rules['match_student_class_year']['is_enabled']) && !empty(trim((string)$studentName))) {
        $sql = "SELECT id, parent_name, student_name, interested_class, academic_year, status, phone_number, created_at, assigned_staff_name
                FROM admission_enquiries 
                WHERE LOWER(TRIM(student_name)) = LOWER(TRIM(?)) AND interested_class = ? AND academic_year = ? {$timeClause}";
        $params = [$studentName, $interestedClass, $academicYear];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $duplicates[$r['id']] = $r;
            $act = $rules['match_student_class_year']['action'];
            $reason = "Student '{$r['student_name']}' already enquired for class '{$interestedClass}' ({$academicYear}) in enquiry #{$r['id']}.";
            if ($act === 'block') { $isBlocked = true; $blockReasons[] = $reason; }
            else { $isWarned = true; $warnReasons[] = $reason; }
        }
    }

    // B. Search existing student/parent records in report_card & unit_test_results & whatsapp_contacts
    // Reuse existing records wherever possible!
    try {
        // Search by phone in report_card
        $rcStmt = $db->prepare("
            SELECT DISTINCT roll_no, student_name, std, division, academic_year, parent_phone 
            FROM report_card 
            WHERE parent_phone LIKE ? OR parent_phone LIKE ?
            ORDER BY id DESC LIMIT 5
        ");
        $rcStmt->execute(["%$phoneWithoutCc%", "%$cleanDigits%"]);
        while ($rc = $rcStmt->fetch(PDO::FETCH_ASSOC)) {
            $matchedRecords[] = [
                'source'        => 'report_card',
                'student_name'  => $rc['student_name'],
                'roll_no'       => (string)$rc['roll_no'],
                'class'         => "STD {$rc['std']} {$rc['division']}",
                'academic_year' => $rc['academic_year'],
                'parent_phone'  => $rc['parent_phone'],
                'label'         => "Current/Former Student: {$rc['student_name']} (STD {$rc['std']} {$rc['division']}, Roll #{$rc['roll_no']})"
            ];
        }

        // Search by student name in report_card if provided
        if (!empty(trim((string)$studentName))) {
            $stNameStmt = $db->prepare("
                SELECT DISTINCT roll_no, student_name, std, division, academic_year, parent_phone 
                FROM report_card 
                WHERE student_name LIKE ?
                ORDER BY id DESC LIMIT 3
            ");
            $stNameStmt->execute(["%" . trim($studentName) . "%"]);
            while ($sn = $stNameStmt->fetch(PDO::FETCH_ASSOC)) {
                // Avoid duplicating if already in matchedRecords
                $exists = false;
                foreach ($matchedRecords as $mr) {
                    if ($mr['student_name'] === $sn['student_name'] && $mr['roll_no'] === (string)$sn['roll_no']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $matchedRecords[] = [
                        'source'        => 'report_card_name_match',
                        'student_name'  => $sn['student_name'],
                        'roll_no'       => (string)$sn['roll_no'],
                        'class'         => "STD {$sn['std']} {$sn['division']}",
                        'academic_year' => $sn['academic_year'],
                        'parent_phone'  => $sn['parent_phone'],
                        'label'         => "Sibling/Student Match: {$sn['student_name']} (STD {$sn['std']} {$sn['division']}, Roll #{$sn['roll_no']})"
                    ];
                }
            }
        }

        // Search in whatsapp_contacts
        $wcStmt = $db->prepare("
            SELECT id, wa_id, name, push_name, phone_e164, linked_student_roll, linked_class_key
            FROM whatsapp_contacts
            WHERE phone_e164 LIKE ? OR wa_id LIKE ?
            LIMIT 1
        ");
        $wcStmt->execute(["%$phoneWithoutCc%", "%$phoneWithoutCc%"]);
        $wc = $wcStmt->fetch(PDO::FETCH_ASSOC);
        if ($wc) {
            $matchedRecords[] = [
                'source'        => 'whatsapp_contacts',
                'contact_id'    => (int)$wc['id'],
                'contact_name'  => $wc['name'],
                'wa_id'         => $wc['wa_id'],
                'roll_no'       => $wc['linked_student_roll'] ?? '',
                'class'         => $wc['linked_class_key'] ?? '',
                'label'         => "Saved WhatsApp Contact: {$wc['name']} ({$wc['phone_e164']})"
            ];
        }
    } catch (Throwable $ignored) {}

    return [
        'is_duplicate'    => !empty($duplicates),
        'is_blocked'      => $isBlocked,
        'is_warned'       => $isWarned,
        'block_reasons'   => array_unique($blockReasons),
        'warn_reasons'    => array_unique($warnReasons),
        'duplicates'      => array_values($duplicates),
        'matched_records' => $matchedRecords
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. AI DRAFT RESPONSE GENERATOR (PHP -> Rust admission-ai-service -> Ollama)
// ─────────────────────────────────────────────────────────────────────────────
function hccRustAiBaseUrl(): string {
    $fromEnv = getenv('RUST_AI_SERVICE_URL');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return rtrim(trim($fromEnv), '/');
    }
    return 'http://127.0.0.1:8085';
}

function hccReleaseDbLock(PDO $db, string $lockName): void {
    try {
        $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    } catch (Throwable $e) {
        // The connection closing also releases a named lock.
    }
}

/**
 * Holding reply used only when the Rust/Ollama service cannot run.
 * It quotes contact details from the approved knowledge file and does not
 * state fees, seats, dates, eligibility, or document lists.
 *
 * @return array<string,mixed>
 */
function hccSafeUnavailableDraft(array $enquiry, string $reason): array {
    $contactPath = dirname(__DIR__) . '/knowledge/approved/contact_information.json';
    $contact = [];
    if (is_readable($contactPath)) {
        $decoded = json_decode((string)file_get_contents($contactPath), true);
        if (is_array($decoded)) {
            $contact = $decoded;
        }
    }
    $helpline = trim((string)($contact['admission_helpline'] ?? ''));
    $whatsapp = trim((string)($contact['admission_whatsapp'] ?? ''));
    $parent = trim((string)($enquiry['parent_name'] ?? 'Parent'));
    if ($parent === '') {
        $parent = 'Parent';
    }
    $grade = trim((string)($enquiry['interested_class'] ?? ''));
    $gradeBit = $grade !== '' ? " for {$grade}" : '';
    $reach = $helpline !== '' ? "the admission helpline {$helpline}" : 'the school admission office';
    if ($whatsapp !== '') {
        $reach .= " or WhatsApp {$whatsapp}";
    }

    $text = "Respected {$parent},\n\n"
        . "Thank you for your admission enquiry{$gradeBit}. "
        . "A staff member still has to confirm fees, seat availability, dates, eligibility, and documents from the approved school records. "
        . "This note does not include those details.\n\n"
        . "Please contact {$reach}, and the admission desk will reply after checking the records.\n\n"
        . "Warm regards,\nAdmission Office\nHindustani Covenant Church High School, Solapur";

    return [
        'intent' => 'General enquiry',
        'confidence' => 0.2,
        'response_draft' => $text,
        'missing_information' => [
            'Local AI service is unavailable. Fees, seats, dates, eligibility, and document lists were withheld.',
        ],
        'escalation_required' => true,
        'escalation_reason' => $reason,
        'relevant_knowledge_sources' => ['contact_information.json'],
        'extracted_parent_details' => [
            'name' => $enquiry['parent_name'] ?? null,
            'phone' => $enquiry['phone_e164'] ?? ($enquiry['phone_number'] ?? null),
        ],
        'extracted_student_details' => [
            'name' => $enquiry['student_name'] ?? null,
            'grade_applying_for' => $enquiry['interested_class'] ?? null,
            'academic_year' => $enquiry['academic_year'] ?? null,
        ],
    ];
}

/**
 * @return array{ok:bool,error:string,draft:array<string,mixed>}
 */
function hccCallRustDraft(array $enquiry): array {
    $message = trim((string)($enquiry['original_message'] ?? ''));
    $contextLine = sprintf(
        "Parent name: %s. Phone: %s. Student: %s. Interested class: %s. Academic year: %s. Original WhatsApp message: %s",
        (string)($enquiry['parent_name'] ?? ''),
        (string)($enquiry['phone_e164'] ?? ($enquiry['phone_number'] ?? '')),
        (string)($enquiry['student_name'] ?? ''),
        (string)($enquiry['interested_class'] ?? ''),
        (string)($enquiry['academic_year'] ?? ''),
        $message
    );
    $payload = [
        'chat_id' => (int)($enquiry['chat_id'] ?? 0),
        'message_id' => null,
        'parent_phone' => $enquiry['phone_e164'] ?? ($enquiry['phone_number'] ?? null),
        'parent_name' => $enquiry['parent_name'] ?? null,
        'current_message' => $contextLine,
        'conversation_context' => [[
            'from_me' => false,
            'sender_name' => $enquiry['parent_name'] ?? 'Parent',
            'body' => $message,
            'timestamp' => time(),
        ]],
        'model' => null,
    ];

    $ch = curl_init(hccRustAiBaseUrl() . '/api/process');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 75,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0 || !is_string($raw) || $raw === '' || $http !== 200) {
        $why = $errno !== 0
            ? "Local AI service is unavailable ({$err}). Start admission-ai-service and Ollama, then generate the draft again."
            : "Local AI service returned HTTP {$http}. Check that Ollama is running and only one model is loaded.";
        return ['ok' => false, 'error' => $why, 'draft' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || trim((string)($data['response_draft'] ?? '')) === '') {
        return [
            'ok' => false,
            'error' => 'Local AI service returned an unusable draft. Staff must answer from the approved knowledge files.',
            'draft' => [],
        ];
    }
    return ['ok' => true, 'error' => '', 'draft' => $data];
}

/**
 * @param array<string,mixed> $draft
 */
function generateContextualDraft(array $enquiry): string {
    $draft = hccSafeUnavailableDraft($enquiry, 'Use the Rust admission assistant for a knowledge-backed draft.');
    return (string)$draft['response_draft'];
}

function hccDraftResponsePayload(array $draft, string $source, ?string $warning, bool $duplicateSuppressed, ?int $draftMsgId): array {
    return [
        'success' => true,
        'draft_text' => (string)($draft['response_draft'] ?? ''),
        'draft_source' => $source,
        'service_warning' => $warning,
        'intent' => $draft['intent'] ?? 'General enquiry',
        'confidence' => $draft['confidence'] ?? 0,
        'missing_information' => $draft['missing_information'] ?? [],
        'escalation_required' => !empty($draft['escalation_required']),
        'escalation_reason' => $draft['escalation_reason'] ?? null,
        'relevant_knowledge_sources' => $draft['relevant_knowledge_sources'] ?? [],
        'extracted_parent_details' => $draft['extracted_parent_details'] ?? [],
        'extracted_student_details' => $draft['extracted_student_details'] ?? [],
        'duplicate_suppressed' => $duplicateSuppressed,
        'draft_msg_id' => $draftMsgId,
        'delivery_verified' => false,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// ROUTE ACTIONS
// ─────────────────────────────────────────────────────────────────────────────

// Action: check_duplicates (live check from UI)
if ($action === 'check_duplicates') {
    $phone = trim((string)($_GET['phone'] ?? ($_POST['phone'] ?? '')));
    $studentName = trim((string)($_GET['student_name'] ?? ($_POST['student_name'] ?? '')));
    $academicYear = trim((string)($_GET['academic_year'] ?? ($_POST['academic_year'] ?? '2025-26')));
    $interestedClass = trim((string)($_GET['interested_class'] ?? ($_POST['interested_class'] ?? 'STD 1')));
    $excludeId = !empty($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;

    $phoneE164 = waPhoneToE164($phone);
    $result = checkDuplicatesAndMatches($db, $phoneE164, $studentName, $academicYear, $interestedClass, $excludeId);

    waJsonResponse([
        'success' => true,
        'data'    => $result
    ]);
}

// Action: get_rules
if ($action === 'get_rules') {
    $rules = getMatchingRules($db);
    waJsonResponse([
        'success' => true,
        'data'    => array_values($rules)
    ]);
}

// Action: update_rules (Admin only)
if ($action === 'update_rules') {
    checkApiCSRF();
    if ($currentUserRole !== 'admin') {
        waJsonResponse(['success' => false, 'error' => 'Unauthorized: Administrator role required to modify matching rules.'], 403);
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ruleKey = trim((string)($input['rule_key'] ?? ''));
    $isEnabled = !empty($input['is_enabled']) ? 1 : 0;
    $actionType = in_array($input['action'] ?? '', ['warn', 'block'], true) ? $input['action'] : 'warn';
    $ruleValue = isset($input['rule_value']) ? trim((string)$input['rule_value']) : null;

    if ($ruleKey === '') {
        waJsonResponse(['success' => false, 'error' => 'Missing rule_key.'], 400);
    }

    $stmt = $db->prepare("UPDATE admission_matching_rules SET is_enabled = ?, action = ?, rule_value = ?, updated_at = NOW() WHERE rule_key = ?");
    $stmt->execute([$isEnabled, $actionType, $ruleValue, $ruleKey]);

    rcLogAudit('admission_rules_update', 'admission_matching_rules', "Updated rule: {$ruleKey} (Enabled: {$isEnabled}, Action: {$actionType}, Value: {$ruleValue})");

    waJsonResponse([
        'success' => true,
        'message' => 'Rule updated successfully.'
    ]);
}

// Action: get_staff_list
if ($action === 'get_staff_list') {
    $stmt = $db->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    waJsonResponse([
        'success' => true,
        'data'    => $staff
    ]);
}

// Action: list (with filters, pagination, and status funnel counts)
if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(max(1, (int)($_GET['per_page'] ?? 25)), 100);
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $yearFilter = trim((string)($_GET['academic_year'] ?? ''));
    $classFilter = trim((string)($_GET['interested_class'] ?? ''));
    $staffFilter = !empty($_GET['assigned_staff_id']) ? (int)$_GET['assigned_staff_id'] : 0;
    $search = trim((string)($_GET['search'] ?? ''));

    $where = ['1=1'];
    $params = [];

    if ($statusFilter !== '' && $statusFilter !== 'ALL') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($yearFilter !== '') {
        $where[] = 'academic_year = ?';
        $params[] = $yearFilter;
    }
    if ($classFilter !== '') {
        $where[] = 'interested_class = ?';
        $params[] = $classFilter;
    }
    if ($staffFilter > 0) {
        $where[] = 'assigned_staff_id = ?';
        $params[] = $staffFilter;
    }
    if ($search !== '') {
        $where[] = '(parent_name LIKE ? OR student_name LIKE ? OR phone_number LIKE ? OR phone_e164 LIKE ? OR original_message LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSql = implode(' AND ', $where);

    // Count funnel stats across all statuses
    $statusCounts = [
        'ALL'                   => 0,
        'NEW'                   => 0,
        'CONTACTED'             => 0,
        'INFORMATION_SHARED'    => 0,
        'INTERESTED'            => 0,
        'VISIT_PLANNED'         => 0,
        'DOCUMENTS_PENDING'     => 0,
        'APPLICATION_SUBMITTED' => 0,
        'ENROLLED'              => 0,
        'CLOSED'                => 0
    ];
    try {
        $scSql = "SELECT status, COUNT(*) as cnt FROM admission_enquiries GROUP BY status";
        $scStmt = $db->query($scSql);
        while ($sc = $scStmt->fetch(PDO::FETCH_ASSOC)) {
            $st = $sc['status'];
            $cnt = (int)$sc['cnt'];
            if (isset($statusCounts[$st])) {
                $statusCounts[$st] = $cnt;
            }
            $statusCounts['ALL'] += $cnt;
        }
    } catch (Throwable $e) {}

    // Pagination query
    $countSql = "SELECT COUNT(*) FROM admission_enquiries WHERE $whereSql";
    $cStmt = $db->prepare($countSql);
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;

    $dataSql = "
        SELECT ae.*, 
               (SELECT COUNT(*) FROM admission_enquiry_messages WHERE enquiry_id = ae.id) as message_count,
               (SELECT MAX(created_at) FROM admission_enquiry_messages WHERE enquiry_id = ae.id) as last_interaction_at
        FROM admission_enquiries ae
        WHERE $whereSql
        ORDER BY ae.id DESC
        LIMIT ? OFFSET ?
    ";
    $dStmt = $db->prepare($dataSql);
    $execParams = array_merge($params, [$perPage, $offset]);
    $dStmt->execute($execParams);
    $items = $dStmt->fetchAll(PDO::FETCH_ASSOC);

    waJsonResponse([
        'success'       => true,
        'data'          => $items,
        'status_counts' => $statusCounts,
        'pagination'    => [
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => $totalPages
        ]
    ]);
}

// Action: get (single enquiry with full timeline & persistent conversation)
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        waJsonResponse(['success' => false, 'error' => 'Valid enquiry id is required.'], 400);
    }

    $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
    $stmt->execute([$id]);
    $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enquiry) {
        waJsonResponse(['success' => false, 'error' => 'Enquiry not found.'], 404);
    }

    // Fetch conversation & interaction timeline
    $mStmt = $db->prepare("
        SELECT id, enquiry_id, message_type, body, ai_draft_body, staff_edited_body, 
               old_status, new_status, follow_up_date, admin_id, admin_name, metadata, created_at
        FROM admission_enquiry_messages
        WHERE enquiry_id = ?
        ORDER BY id ASC
    ");
    $mStmt->execute([$id]);
    $timeline = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch matching existing student/parent records
    $matches = checkDuplicatesAndMatches($db, $enquiry['phone_e164'], $enquiry['student_name'], $enquiry['academic_year'], $enquiry['interested_class'], $enquiry['id']);

    waJsonResponse([
        'success'         => true,
        'data'            => $enquiry,
        'timeline'        => $timeline,
        'matched_records' => $matches['matched_records'],
        'duplicates'      => $matches['duplicates']
    ]);
}

// Action: create (Capture New Enquiry from WhatsApp)
if ($action === 'create' || ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '')) {
    checkApiCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // Field extraction & sanitization
    $parentName      = trim((string)($input['parent_name'] ?? ''));
    $rawPhone        = trim((string)($input['phone_number'] ?? ($input['phone'] ?? '')));
    $studentName     = trim((string)($input['student_name'] ?? ''));
    $interestedClass = trim((string)($input['interested_class'] ?? ''));
    $academicYear    = trim((string)($input['academic_year'] ?? '2025-26'));
    $originalMessage = trim((string)($input['original_message'] ?? ''));
    $enquirySource   = trim((string)($input['enquiry_source'] ?? 'whatsapp'));
    $assignedStaffId = !empty($input['assigned_staff_id']) ? (int)$input['assigned_staff_id'] : $currentUserId;
    $notes           = trim((string)($input['notes'] ?? ''));
    $confirmDuplicate= !empty($input['confirm_duplicate']);

    // ── Server-Side Validation ──────────────────────────────────────────────
    $errors = [];
    if ($parentName === '') {
        $errors['parent_name'] = 'Parent name is required.';
    } elseif (mb_strlen($parentName) > 255) {
        $errors['parent_name'] = 'Parent name must not exceed 255 characters.';
    }

    $phoneCheck = waValidateWhatsAppPhone($rawPhone);
    if (!$phoneCheck['ok']) {
        $errors['phone_number'] = $phoneCheck['error'];
    }

    if ($interestedClass === '') {
        $errors['interested_class'] = 'Interested class / grade is required.';
    }

    if ($academicYear === '') {
        $errors['academic_year'] = 'Academic year is required.';
    }

    if ($originalMessage === '') {
        $errors['original_message'] = "Parent's original WhatsApp message is required.";
    }

    $allowedSources = ['whatsapp', 'walk_in', 'website', 'referral', 'phone_call', 'social_media', 'other'];
    if (!in_array($enquirySource, $allowedSources, true)) {
        $enquirySource = 'whatsapp';
    }

    if (!empty($errors)) {
        waJsonResponse(['success' => false, 'errors' => $errors, 'error' => 'Validation failed.'], 422);
    }

    $phoneE164 = $phoneCheck['e164'];
    $waChatId  = $phoneCheck['digits'] . '@c.us';

    // Serialize creates for the same phone + student so two clicks cannot both pass the duplicate check.
    $lockName = 'hcc_enq_' . substr(hash('sha256', $phoneE164 . '|' . mb_strtolower($studentName)), 0, 24);
    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 8)');
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        waJsonResponse(['success' => false, 'error' => 'Another enquiry for this parent is being saved. Please retry.'], 409);
    }
    register_shutdown_function(static function () use ($db, $lockName): void {
        hccReleaseDbLock($db, $lockName);
    });

    // ── Duplicate Detection using Configurable Rules ─────────────────────────
    $dupCheck = checkDuplicatesAndMatches($db, $phoneE164, $studentName, $academicYear, $interestedClass);

    if ($dupCheck['is_blocked']) {
        waJsonResponse([
            'success'     => false,
            'blocked'     => true,
            'error'       => 'Duplicate Enquiry Blocked: ' . implode(' ', $dupCheck['block_reasons']),
            'duplicates'  => $dupCheck['duplicates']
        ], 409);
    }

    if ($dupCheck['is_warned'] && !$confirmDuplicate) {
        waJsonResponse([
            'success'     => false,
            'warn'        => true,
            'error'       => 'Potential Duplicate Enquiry Detected.',
            'reasons'     => $dupCheck['warn_reasons'],
            'duplicates'  => $dupCheck['duplicates']
        ], 200); // 200 with warn flag allows UI to prompt confirmation
    }

    // ── Resolve Staff Name ───────────────────────────────────────────────────
    $assignedStaffName = $currentUserName;
    if ($assignedStaffId > 0) {
        $uStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
        $uStmt->execute([$assignedStaffId]);
        $uName = $uStmt->fetchColumn();
        if ($uName) $assignedStaffName = $uName;
    }

    // ── Check Existing Student / Parent Records to Reuse ─────────────────────
    $matchedRoll = null;
    $matchedClass = null;
    $matchedParent = 0;

    if (!empty($dupCheck['matched_records'])) {
        foreach ($dupCheck['matched_records'] as $rec) {
            if (!empty($rec['roll_no'])) {
                $matchedRoll = $rec['roll_no'];
                $matchedClass = $rec['class'] ?? '';
                $matchedParent = 1;
                break;
            }
        }
    }

    // ── Synchronize with WhatsApp Contacts & Chats Tables ────────────────────
    $contactId = null;
    $chatId = null;

    try {
        // 1. WhatsApp Contact
        $cStmt = $db->prepare("SELECT id FROM whatsapp_contacts WHERE wa_id = ? OR phone_e164 = ?");
        $cStmt->execute([$waChatId, $phoneE164]);
        $cId = $cStmt->fetchColumn();

        if ($cId) {
            $contactId = (int)$cId;
            $db->prepare("UPDATE whatsapp_contacts SET name = COALESCE(name, ?), updated_at = NOW() WHERE id = ?")->execute([$parentName, $contactId]);
        } else {
            $avatarUrl = "https://api.dicebear.com/7.x/initials/svg?seed=" . urlencode($parentName);
            $insC = $db->prepare("
                INSERT INTO whatsapp_contacts (wa_id, phone_e164, name, push_name, avatar_url, source, linked_student_roll, linked_class_key, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, NOW(), NOW())
            ");
            $insC->execute([$waChatId, $phoneE164, $parentName, $parentName, $avatarUrl, $matchedRoll, $matchedClass]);
            $contactId = (int)$db->lastInsertId();
        }

        // 2. WhatsApp Chat
        $chStmt = $db->prepare("SELECT id, metadata FROM whatsapp_chats WHERE wa_chat_id = ?");
        $chStmt->execute([$waChatId]);
        $chatRow = $chStmt->fetch(PDO::FETCH_ASSOC);

        $chatMeta = [
            'is_enquiry'        => true,
            'status'            => 'NEW',
            'interested_class'  => $interestedClass,
            'academic_year'     => $academicYear,
            'student_name'      => $studentName,
            'parent_name'       => $parentName
        ];

        if ($chatRow) {
            $chatId = (int)$chatRow['id'];
            $existingMeta = json_decode($chatRow['metadata'] ?? '[]', true) ?: [];
            $mergedMeta = array_merge($existingMeta, $chatMeta);
            $db->prepare("
                UPDATE whatsapp_chats 
                SET last_activity_at = NOW(), metadata = ?, is_archived = 0, name = ?
                WHERE id = ?
            ")->execute([json_encode($mergedMeta, JSON_UNESCAPED_UNICODE), $parentName, $chatId]);
        } else {
            $avatarUrl = "https://api.dicebear.com/7.x/initials/svg?seed=" . urlencode($parentName);
            $insCh = $db->prepare("
                INSERT INTO whatsapp_chats 
                (wa_chat_id, chat_type, name, avatar_url, is_pinned, unread_count, metadata, linked_student_roll, linked_class_key, last_activity_at, created_at, updated_at)
                VALUES (?, 'individual', ?, ?, 0, 1, ?, ?, ?, NOW(), NOW(), NOW())
            ");
            $insCh->execute([$waChatId, $parentName, $avatarUrl, json_encode($chatMeta, JSON_UNESCAPED_UNICODE), $matchedRoll, $matchedClass]);
            $chatId = (int)$db->lastInsertId();
        }

        // 3. Insert incoming message into whatsapp_messages to preserve WhatsApp-replica view
        $waMsgId = 'enq_in_' . time() . '_' . rand(100, 999);
        $insMsg = $db->prepare("
            INSERT INTO whatsapp_messages 
            (wa_msg_id, chat_id, from_me, sender_id, sender_name, msg_type, body, status, timestamp, received_at)
            VALUES (?, ?, 0, ?, ?, 'text', ?, 'delivered', UNIX_TIMESTAMP(), NOW())
        ");
        $insMsg->execute([$waMsgId, $chatId, $waChatId, $parentName, $originalMessage]);
        $msgId = (int)$db->lastInsertId();

        $db->prepare("UPDATE whatsapp_chats SET last_message_id = ? WHERE id = ?")->execute([$msgId, $chatId]);
    } catch (Throwable $e) {
        error_log('WhatsApp chat sync notice: ' . $e->getMessage());
    }

    // ── Insert Admission Enquiry Record ──────────────────────────────────────
    $sql = "
        INSERT INTO admission_enquiries 
        (parent_name, phone_number, phone_e164, student_name, interested_class, academic_year,
         original_message, enquiry_source, assigned_staff_id, assigned_staff_name, notes, status,
         chat_id, contact_id, matched_student_roll, matched_student_class, matched_existing_parent,
         created_by, created_by_name, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NEW', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ";
    $insStmt = $db->prepare($sql);
    $insStmt->execute([
        $parentName, $rawPhone, $phoneE164, $studentName ?: null, $interestedClass, $academicYear,
        $originalMessage, $enquirySource, $assignedStaffId, $assignedStaffName, $notes ?: null,
        $chatId, $contactId, $matchedRoll, $matchedClass, $matchedParent,
        $currentUserId, $currentUserName
    ]);
    $enquiryId = (int)$db->lastInsertId();

    // ── Insert Initial Persistent Conversation Record ────────────────────────
    $imStmt = $db->prepare("
        INSERT INTO admission_enquiry_messages 
        (enquiry_id, message_type, body, admin_id, admin_name, metadata, created_at)
        VALUES (?, 'incoming_message', ?, NULL, ?, ?, NOW())
    ");
    $imStmt->execute([
        $enquiryId,
        $originalMessage,
        "Parent ({$parentName})",
        json_encode(['source' => $enquirySource, 'phone' => $phoneE164], JSON_UNESCAPED_UNICODE)
    ]);

    // Update metadata on whatsapp_chats to link enquiry_id
    if ($chatId) {
        try {
            $chMetaUpdate = $db->prepare("
                UPDATE whatsapp_chats 
                SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.enquiry_id', ?)
                WHERE id = ?
            ");
            $chMetaUpdate->execute([$enquiryId, $chatId]);
        } catch (Throwable $e) {}
    }

    // ── Audit Log ────────────────────────────────────────────────────────────
    rcLogAudit(
        'admission_enquiry_create',
        "Enquiry #{$enquiryId}",
        "Captured enquiry from WhatsApp: Parent: {$parentName}, Phone: {$phoneE164}, Student: {$studentName}, Class: {$interestedClass}, Year: {$academicYear}"
    );

    waJsonResponse([
        'success'    => true,
        'message'    => 'Admission enquiry captured successfully.',
        'enquiry_id' => $enquiryId,
        'chat_id'    => $chatId,
        'data'       => [
            'id'               => $enquiryId,
            'parent_name'      => $parentName,
            'phone_e164'       => $phoneE164,
            'student_name'     => $studentName,
            'interested_class' => $interestedClass,
            'academic_year'    => $academicYear,
            'status'           => 'NEW',
            'chat_id'          => $chatId
        ]
    ], 201);
}

// Action: update_status (Authorized administrators)
if ($action === 'update_status') {
    checkApiCSRF();

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? 0);
    $newStatus = trim((string)($input['status'] ?? ''));
    $statusNotes = trim((string)($input['notes'] ?? ''));

    $validStatuses = [
        'NEW',
        'CONTACTED',
        'INFORMATION_SHARED',
        'INTERESTED',
        'VISIT_PLANNED',
        'DOCUMENTS_PENDING',
        'APPLICATION_SUBMITTED',
        'ENROLLED',
        'CLOSED'
    ];

    if (!in_array($newStatus, $validStatuses, true)) {
        waJsonResponse(['success' => false, 'error' => "Invalid status '{$newStatus}'. Allowed: " . implode(', ', $validStatuses)], 422);
    }

    $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
    $stmt->execute([$id]);
    $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enquiry) {
        waJsonResponse(['success' => false, 'error' => 'Enquiry not found.'], 404);
    }

    $oldStatus = $enquiry['status'];
    if ($oldStatus === $newStatus && $statusNotes === '') {
        waJsonResponse(['success' => true, 'message' => 'Status unchanged.', 'status' => $newStatus]);
    }

    // Update status in admission_enquiries
    $upStmt = $db->prepare("UPDATE admission_enquiries SET status = ?, updated_at = NOW() WHERE id = ?");
    $upStmt->execute([$newStatus, $id]);

    // Insert persistent conversation event
    $msgBody = $statusNotes !== '' 
        ? "Status updated from {$oldStatus} to {$newStatus}. Note: {$statusNotes}"
        : "Status updated from {$oldStatus} to {$newStatus}.";

    $imStmt = $db->prepare("
        INSERT INTO admission_enquiry_messages 
        (enquiry_id, message_type, body, old_status, new_status, admin_id, admin_name, metadata, created_at)
        VALUES (?, 'status_change', ?, ?, ?, ?, ?, ?, NOW())
    ");
    $imStmt->execute([
        $id,
        $msgBody,
        $oldStatus,
        $newStatus,
        $currentUserId,
        $currentUserName,
        json_encode(['notes' => $statusNotes], JSON_UNESCAPED_UNICODE)
    ]);

    // Update WhatsApp Chat metadata if linked
    if (!empty($enquiry['chat_id'])) {
        try {
            $db->prepare("
                UPDATE whatsapp_chats 
                SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.status', ?)
                WHERE id = ?
            ")->execute([$newStatus, $enquiry['chat_id']]);
        } catch (Throwable $e) {}
    }

    // Audit Log
    rcLogAudit(
        'admission_enquiry_status_change',
        "Enquiry #{$id}",
        "Status updated: {$oldStatus} -> {$newStatus} by {$currentUserName} ({$currentUserRole}). Notes: {$statusNotes}"
    );

    waJsonResponse([
        'success'    => true,
        'message'    => "Admission pipeline status updated to {$newStatus}.",
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ]);
}

// Action: generate_ai_draft
if ($action === 'generate_ai_draft') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));

    $enquiryData = [];
    if ($id > 0) {
        $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
        $stmt->execute([$id]);
        $enquiryData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (empty($enquiryData)) {
        $enquiryData = [
            'parent_name'      => $input['parent_name'] ?? 'Parent',
            'student_name'     => $input['student_name'] ?? '',
            'interested_class' => $input['interested_class'] ?? '',
            'academic_year'    => $input['academic_year'] ?? '',
            'original_message' => $input['original_message'] ?? '',
            'phone_number'     => $input['phone_number'] ?? '',
        ];
    }

    $draftMsgId = null;
    $duplicateSuppressed = false;
    if ($id > 0) {
        $aiLock = 'hcc_ai_' . $id;
        $aiLockStmt = $db->prepare('SELECT GET_LOCK(?, 20)');
        $aiLockStmt->execute([$aiLock]);
        if ((int)$aiLockStmt->fetchColumn() !== 1) {
            waJsonResponse([
                'success' => false,
                'error' => 'A draft for this enquiry is already being generated. Please wait.',
            ], 409);
        }
        register_shutdown_function(static function () use ($db, $aiLock): void {
            hccReleaseDbLock($db, $aiLock);
        });

        $recent = $db->prepare("
            SELECT id, body, metadata
            FROM admission_enquiry_messages
            WHERE enquiry_id = ? AND message_type = 'ai_draft'
              AND created_at >= (NOW() - INTERVAL 8 SECOND)
            ORDER BY id DESC
            LIMIT 1
        ");
        $recent->execute([$id]);
        $existing = $recent->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $stored = json_decode((string)($existing['metadata'] ?? ''), true);
            if (!is_array($stored)) {
                $stored = ['response_draft' => $existing['body']];
            }
            $stored['response_draft'] = $existing['body'];
            waJsonResponse(hccDraftResponsePayload($stored, 'recent_draft', null, true, (int)$existing['id']));
        }
    }

    $call = hccCallRustDraft($enquiryData);
    $warning = null;
    $source = 'rust';
    if ($call['ok']) {
        $structured = $call['draft'];
    } else {
        $warning = $call['error'];
        $source = 'knowledge_fallback';
        $structured = hccSafeUnavailableDraft($enquiryData, $warning);
    }
    $draftText = (string)$structured['response_draft'];

    if ($id > 0) {
        $insDraft = $db->prepare("
            INSERT INTO admission_enquiry_messages
            (enquiry_id, message_type, body, ai_draft_body, admin_id, admin_name, metadata, created_at)
            VALUES (?, 'ai_draft', ?, ?, ?, ?, ?, NOW())
        ");
        $insDraft->execute([
            $id,
            $draftText,
            $draftText,
            $currentUserId,
            "AI Assistant ({$currentUserName})",
            json_encode($structured, JSON_UNESCAPED_UNICODE),
        ]);
        $draftMsgId = (int)$db->lastInsertId();
        rcLogAudit(
            'admission_enquiry_ai_draft',
            "Enquiry #{$id}",
            "Draft source {$source}. Escalation: " . (!empty($structured['escalation_required']) ? 'yes' : 'no')
        );
    }

    waJsonResponse(hccDraftResponsePayload($structured, $source, $warning, $duplicateSuppressed, $draftMsgId));
}

// Action: save_draft_edit (Staff edits an AI draft)
if ($action === 'save_draft_edit') {
    checkApiCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['enquiry_id'] ?? 0);
    $aiDraftBody = trim((string)($input['ai_draft_body'] ?? ''));
    $editedBody  = trim((string)($input['edited_body'] ?? ''));

    if (!$id || $editedBody === '') {
        waJsonResponse(['success' => false, 'error' => 'Enquiry ID and edited message body are required.'], 400);
    }

    $stmt = $db->prepare("
        INSERT INTO admission_enquiry_messages 
        (enquiry_id, message_type, body, ai_draft_body, staff_edited_body, admin_id, admin_name, created_at)
        VALUES (?, 'staff_edit', ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$id, $editedBody, $aiDraftBody, $editedBody, $currentUserId, $currentUserName]);
    $msgId = (int)$db->lastInsertId();

    rcLogAudit('admission_enquiry_staff_edit', "Enquiry #{$id}", "Staff {$currentUserName} edited AI draft response.");

    waJsonResponse([
        'success'    => true,
        'message_id' => $msgId,
        'body'       => $editedBody
    ]);
}

// Action: approve_response (Staff approves response to send)
if ($action === 'approve_response') {
    checkApiCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['enquiry_id'] ?? 0);
    $approvedText = trim((string)($input['approved_text'] ?? ''));

    if (!$id || $approvedText === '') {
        waJsonResponse(['success' => false, 'error' => 'Enquiry ID and approved response text are required.'], 400);
    }

    $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
    $stmt->execute([$id]);
    $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enquiry) {
        waJsonResponse(['success' => false, 'error' => 'Enquiry not found.'], 404);
    }

    // Insert approved response in persistent timeline
    $imStmt = $db->prepare("
        INSERT INTO admission_enquiry_messages 
        (enquiry_id, message_type, body, admin_id, admin_name, created_at)
        VALUES (?, 'approved_response', ?, ?, ?, NOW())
    ");
    $imStmt->execute([$id, $approvedText, $currentUserId, $currentUserName]);
    $msgId = (int)$db->lastInsertId();

    // Also store in whatsapp_messages as outbound approved response so staff sees it in the conversation pane
    if (!empty($enquiry['chat_id'])) {
        try {
            $waMsgId = 'appr_out_' . time() . '_' . rand(100, 999);
            $insMsg = $db->prepare("
                INSERT INTO whatsapp_messages 
                (wa_msg_id, chat_id, from_me, sender_id, sender_name, msg_type, body, status, timestamp, received_at)
                VALUES (?, ?, 1, ?, ?, 'text', ?, 'pending', UNIX_TIMESTAMP(), NOW())
            ");
            $insMsg->execute([$waMsgId, $enquiry['chat_id'], 'school_staff', $currentUserName, $approvedText]);
            $wId = (int)$db->lastInsertId();
            $db->prepare("UPDATE whatsapp_chats SET last_message_id = ?, last_activity_at = NOW() WHERE id = ?")->execute([$wId, $enquiry['chat_id']]);
        } catch (Throwable $e) {}
    }

    // Auto-advance status to CONTACTED if it was NEW
    if ($enquiry['status'] === 'NEW') {
        $db->prepare("UPDATE admission_enquiries SET status = 'CONTACTED', updated_at = NOW() WHERE id = ?")->execute([$id]);
        $advStmt = $db->prepare("
            INSERT INTO admission_enquiry_messages 
            (enquiry_id, message_type, body, old_status, new_status, admin_id, admin_name, created_at)
            VALUES (?, 'status_change', 'Status auto-progressed to CONTACTED following response approval.', 'NEW', 'CONTACTED', ?, ?, NOW())
        ");
        $advStmt->execute([$id, $currentUserId, $currentUserName]);
    }

    rcLogAudit('admission_enquiry_response_approved', "Enquiry #{$id}", "Approved response recorded by {$currentUserName}. Not sent on WhatsApp.");

    waJsonResponse([
        'success'    => true,
        'message_id' => $msgId,
        'delivery_verified' => false,
        'message'    => 'Response approved as a draft. It has not been sent. Use Open WhatsApp, press Send yourself, then mark it manually confirmed.'
    ]);
}

// Action: add_followup (Log follow-up history or internal note)
if ($action === 'add_followup') {
    checkApiCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['enquiry_id'] ?? 0);
    $followupNote = trim((string)($input['note'] ?? ($input['body'] ?? '')));
    $followupDate = !empty($input['follow_up_date']) ? $input['follow_up_date'] : null;
    $newStatus    = trim((string)($input['new_status'] ?? ''));

    if (!$id || $followupNote === '') {
        waJsonResponse(['success' => false, 'error' => 'Enquiry ID and follow-up note are required.'], 400);
    }

    $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
    $stmt->execute([$id]);
    $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enquiry) {
        waJsonResponse(['success' => false, 'error' => 'Enquiry not found.'], 404);
    }

    $imStmt = $db->prepare("
        INSERT INTO admission_enquiry_messages 
        (enquiry_id, message_type, body, follow_up_date, admin_id, admin_name, metadata, created_at)
        VALUES (?, 'follow_up', ?, ?, ?, ?, ?, NOW())
    ");
    $imStmt->execute([
        $id,
        $followupNote,
        $followupDate,
        $currentUserId,
        $currentUserName,
        json_encode(['scheduled_date' => $followupDate], JSON_UNESCAPED_UNICODE)
    ]);

    // Schedule the durable follow-up used by the dashboard and the Rust reminder engine.
    if ($followupDate) {
        $datePart = substr((string)$followupDate, 0, 10);
        $timePart = '10:00:00';
        if (preg_match('/T(\d{2}:\d{2})/', (string)$followupDate, $timeMatch)) {
            $timePart = $timeMatch[1] . ':00';
        } elseif (preg_match('/ (\d{2}:\d{2}(?::\d{2})?)/', (string)$followupDate, $timeMatch)) {
            $timePart = strlen($timeMatch[1]) === 5 ? $timeMatch[1] . ':00' : $timeMatch[1];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart)) {
            try {
                $sched = $db->prepare("
                    UPDATE admission_enquiries
                    SET next_followup_date = ?, next_followup_time = ?, followup_reason = ?,
                        reminder_status = 'pending', is_completed = 0, updated_at = NOW()
                    WHERE id = ?
                ");
                $sched->execute([$datePart, $timePart, mb_substr($followupNote, 0, 255), $id]);
            } catch (Throwable $e) {
                error_log('Follow-up schedule columns unavailable: ' . $e->getMessage());
            }
        }
    }

    // Optional status update alongside follow-up
    if ($newStatus !== '' && $newStatus !== $enquiry['status']) {
        $db->prepare("UPDATE admission_enquiries SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatus, $id]);
        $scStmt = $db->prepare("
            INSERT INTO admission_enquiry_messages 
            (enquiry_id, message_type, body, old_status, new_status, admin_id, admin_name, created_at)
            VALUES (?, 'status_change', ?, ?, ?, ?, ?, NOW())
        ");
        $scStmt->execute([
            $id,
            "Status updated to {$newStatus} during follow-up: {$followupNote}",
            $enquiry['status'],
            $newStatus,
            $currentUserId,
            $currentUserName
        ]);
    }

    rcLogAudit('admission_enquiry_followup', "Enquiry #{$id}", "Follow-up logged by {$currentUserName}: {$followupNote}");

    waJsonResponse([
        'success' => true,
        'message' => 'Follow-up logged successfully.'
    ]);
}

// Action: export_csv
if ($action === 'export_csv') {
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $yearFilter = trim((string)($_GET['academic_year'] ?? ''));
    $classFilter = trim((string)($_GET['interested_class'] ?? ''));

    $where = ['1=1'];
    $params = [];
    if ($statusFilter !== '' && $statusFilter !== 'ALL') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($yearFilter !== '') {
        $where[] = 'academic_year = ?';
        $params[] = $yearFilter;
    }
    if ($classFilter !== '') {
        $where[] = 'interested_class = ?';
        $params[] = $classFilter;
    }

    $whereSql = implode(' AND ', $where);
    $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE $whereSql ORDER BY id DESC");
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=admission_enquiries_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Enquiry ID', 'Parent Name', 'Phone Number', 'Phone E.164', 'Student Name',
        'Interested Class', 'Academic Year', 'Source', 'Status', 'Assigned Staff',
        'Matched Student Roll', 'Matched Class', 'Created By', 'Created At'
    ]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['parent_name'],
            $row['phone_number'],
            $row['phone_e164'],
            $row['student_name'] ?? '—',
            $row['interested_class'],
            $row['academic_year'],
            $row['enquiry_source'],
            $row['status'],
            $row['assigned_staff_name'] ?? '—',
            $row['matched_student_roll'] ?? '—',
            $row['matched_student_class'] ?? '—',
            $row['created_by_name'] ?? '—',
            $row['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// Session token for same-origin clients and the local end-to-end test.
if ($action === 'csrf') {
    waJsonResponse(['success' => true, 'csrf_token' => generateCSRFToken()]);
}

// Open WhatsApp click-to-chat. This does not send, deliver, or mark the message read.
if ($action === 'open_whatsapp') {
    checkApiCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? ($input['enquiry_id'] ?? 0));
    $messageText = trim((string)($input['message'] ?? ''));

    $stmt = $db->prepare('SELECT * FROM admission_enquiries WHERE id = ?');
    $stmt->execute([$id]);
    $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enquiry) {
        waJsonResponse(['success' => false, 'error' => 'Enquiry not found.'], 404);
    }
    if ($messageText === '') {
        $messageText = trim((string)($enquiry['suggested_message'] ?? ''));
    }
    if ($messageText === '') {
        waJsonResponse(['success' => false, 'error' => 'Write or approve a message before opening WhatsApp.'], 422);
    }

    $phoneCheck = waValidateWhatsAppPhone((string)($enquiry['phone_e164'] ?: $enquiry['phone_number']));
    if (!$phoneCheck['ok']) {
        waJsonResponse(['success' => false, 'error' => $phoneCheck['error']], 422);
    }

    $waUrl = 'https://wa.me/' . $phoneCheck['digits'] . '?text=' . rawurlencode($messageText);
    $webUrl = 'https://web.whatsapp.com/send?phone=' . $phoneCheck['digits'] . '&text=' . rawurlencode($messageText);

    $note = $db->prepare("
        INSERT INTO admission_enquiry_messages
        (enquiry_id, message_type, body, admin_id, admin_name, metadata, created_at)
        VALUES (?, 'note', ?, ?, ?, ?, NOW())
    ");
    $note->execute([
        $id,
        "Opened WhatsApp for {$enquiry['parent_name']} ({$phoneCheck['e164']}). The message was prefilled only. Staff must press Send in WhatsApp.",
        $currentUserId,
        $currentUserName,
        json_encode([
            'action' => 'whatsapp_opened',
            'phone' => $phoneCheck['e164'],
            'delivery_verified' => false,
        ], JSON_UNESCAPED_UNICODE),
    ]);

    try {
        $hist = $db->prepare("
            INSERT INTO admission_followup_history
            (enquiry_id, action, title, details, performed_by, performed_by_id, manual_sent_confirmed, delivery_verified, delivery_disclaimer)
            VALUES (?, 'whatsapp_opened', 'WhatsApp chat opened', ?, ?, ?, 0, 0, ?)
        ");
        $hist->execute([
            $id,
            "{$currentUserName} opened a prefilled WhatsApp chat. Send was not pressed by the application.",
            $currentUserName,
            $currentUserId,
            'Unverified: opening WhatsApp does not send or deliver the message.',
        ]);
    } catch (Throwable $e) {
        error_log('Follow-up history unavailable: ' . $e->getMessage());
    }

    rcLogAudit('admission_enquiry_whatsapp_open', "Enquiry #{$id}", "Opened wa.me for {$phoneCheck['e164']}. Not sent.");

    waJsonResponse([
        'success' => true,
        'whatsapp_url' => $waUrl,
        'whatsapp_web_url' => $webUrl,
        'phone' => $phoneCheck['digits'],
        'delivery_verified' => false,
        'message' => 'WhatsApp link ready. Press Send yourself in WhatsApp. This is not a delivery receipt.',
    ]);
}

// Staff confirmation that they pressed Send. This is not a WhatsApp delivery or read receipt.
if ($action === 'confirm_manual_send') {
    checkApiCSRF();
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($input['id'] ?? ($input['enquiry_id'] ?? 0));
    $confirmed = !empty($input['manual_sent_confirmed']);
    $noteText = trim((string)($input['note'] ?? ''));

    if (!$confirmed) {
        waJsonResponse([
            'success' => false,
            'error' => 'Confirm only after you have pressed Send in WhatsApp yourself.',
        ], 422);
    }

    $stmt = $db->prepare('SELECT * FROM admission_enquiries WHERE id = ?');
    $stmt->execute([$id]);
    $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enquiry) {
        waJsonResponse(['success' => false, 'error' => 'Enquiry not found.'], 404);
    }

    try {
        $up = $db->prepare("
            UPDATE admission_enquiries
            SET manual_sent_confirmed = 1, delivery_verified = 0, updated_at = NOW()
            WHERE id = ?
        ");
        $up->execute([$id]);
    } catch (Throwable $e) {
        error_log('manual_sent columns unavailable: ' . $e->getMessage());
    }

    $body = "Manual send confirmed by {$currentUserName}. This is an unverified staff confirmation, not a WhatsApp delivery or read receipt.";
    if ($noteText !== '') {
        $body .= ' Note: ' . $noteText;
    }
    $note = $db->prepare("
        INSERT INTO admission_enquiry_messages
        (enquiry_id, message_type, body, admin_id, admin_name, metadata, created_at)
        VALUES (?, 'note', ?, ?, ?, ?, NOW())
    ");
    $note->execute([
        $id,
        $body,
        $currentUserId,
        $currentUserName,
        json_encode([
            'action' => 'manual_sent_confirmed',
            'manual_sent_confirmed' => true,
            'delivery_verified' => false,
        ], JSON_UNESCAPED_UNICODE),
    ]);

    try {
        $hist = $db->prepare("
            INSERT INTO admission_followup_history
            (enquiry_id, action, title, details, performed_by, performed_by_id, manual_sent_confirmed, delivery_verified, delivery_disclaimer)
            VALUES (?, 'manual_sent_confirmed', 'Manual send confirmed', ?, ?, ?, 1, 0, ?)
        ");
        $hist->execute([
            $id,
            $body,
            $currentUserName,
            $currentUserId,
            'Unverified delivery: manual confirmation does not verify WhatsApp delivery or read status.',
        ]);
    } catch (Throwable $e) {
        error_log('Follow-up history unavailable: ' . $e->getMessage());
    }

    rcLogAudit('admission_enquiry_manual_send', "Enquiry #{$id}", $body);

    waJsonResponse([
        'success' => true,
        'manual_sent_confirmed' => true,
        'delivery_verified' => false,
        'message' => 'Manual send recorded. WhatsApp delivery and read status were not verified.',
    ]);
}

if (PHP_SAPI !== 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'api_admission_enquiry.php') {
    waJsonResponse(['success' => false, 'error' => 'Invalid API action.'], 400);
}
