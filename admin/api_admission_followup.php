<?php
/**
 * HCC Automation System — Admission Follow-up Management API
 * 
 * Implements:
 *  - Follow-up enquiries CRUD & filtering
 *  - 8 Follow-up categories handling
 *  - Parent WhatsApp conversation thread retrieval
 *  - Suggested WhatsApp message generation & editing
 *  - Open WhatsApp action logging
 *  - Manual Send & Completion recording (with strict unverified delivery representation)
 *  - Full audit logging (created, edited, completed, cancelled)
 *  - Rust Automation Engine bridge & status checks
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/includes/admission_ai_review.php';
require_once __DIR__ . '/auth_check.php';

// Authenticate session
waRequireAuth();

header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
$currentUserId   = (int)($_SESSION['admin_id'] ?? 1);
$currentUserName = trim($_SESSION['admin_name'] ?? 'Administrator');
if ($currentUserName === '') {
    $currentUserName = 'Administrator';
}

$afRawBody = file_get_contents('php://input');
$afJsonBody = [];
if (is_string($afRawBody) && $afRawBody !== '') {
    $decoded = json_decode($afRawBody, true);
    if (is_array($decoded)) {
        $afJsonBody = $decoded;
    }
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? $afJsonBody['action'] ?? ''));

function sendJson(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function verifyApiCSRF(): void {
    global $afJsonBody;
    if (verifyCSRFToken()) {
        return;
    }
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $jsonToken = (string)($afJsonBody['csrf_token'] ?? '');
    if ($expected !== '' && $jsonToken !== '' && hash_equals($expected, $jsonToken)) {
        return;
    }
    sendJson(['success' => false, 'error' => 'Security token invalid or expired. Please refresh the page.'], 403);
}

function afFindEngineBinary(): string {
    $isWin = (PHP_OS_FAMILY === 'Windows');
    $exeSuffix = $isWin ? '.exe' : '';
    $binaryCandidates = [
        __DIR__ . '/../automation/bin/hcc-automation-engine' . $exeSuffix,
        __DIR__ . '/../automation/rust_engine/target/release/hcc-automation-engine' . $exeSuffix,
    ];
    if ($isWin) {
        $binaryCandidates[] = __DIR__ . '/../automation/bin/hcc-automation-engine';
        $binaryCandidates[] = __DIR__ . '/../automation/rust_engine/target/release/hcc-automation-engine';
    }
    foreach ($binaryCandidates as $cand) {
        if (file_exists($cand) && ($isWin || is_executable($cand))) {
            return $cand;
        }
    }
    return '';
}

function afBuildChildEnv(): array {
    $env = [];
    $inherited = getenv();
    if (is_array($inherited)) {
        foreach ($inherited as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $env[$k] = (string)$v;
            }
        }
    }
    $env['DB_HOST'] = defined('DB_HOST') ? (string)DB_HOST : ($env['DB_HOST'] ?? '127.0.0.1');
    $env['DB_PORT'] = defined('DB_PORT') ? (string)DB_PORT : ($env['DB_PORT'] ?? '3306');
    $env['DB_USER'] = defined('DB_USER') ? (string)DB_USER : ($env['DB_USER'] ?? 'hcc_app');
    $env['DB_PASS'] = defined('DB_PASS') ? (string)DB_PASS : ($env['DB_PASS'] ?? '');
    $env['DB_NAME'] = defined('DB_NAME') ? (string)DB_NAME : ($env['DB_NAME'] ?? 'hcc_admin');
    if (defined('DB_SOCKET') && (string)DB_SOCKET !== '') {
        $env['DB_SOCKET'] = (string)DB_SOCKET;
    }
    $aiSecret = airAiServiceSecret();
    if ($aiSecret !== '') {
        $env['AI_SERVICE_SECRET'] = $aiSecret;
    }
    return $env;
}

/**
 * Invoke hcc-automation-engine via proc_open with DB credentials in child environment (never CLI args).
 *
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function afRunAutomationEngine(string $binaryPath, string $flag = '--once'): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $childEnv = afBuildChildEnv();
    $proc = @proc_open([$binaryPath, $flag], $descriptors, $pipes, dirname($binaryPath), $childEnv);
    if (!is_resource($proc)) {
        return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'Unable to spawn hcc-automation-engine'];
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? (string)stream_get_contents($pipes[1]) : '';
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? (string)stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
    $exitCode = proc_close($proc);
    return [
        'exit_code' => $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. DASHBOARD & FILTER COUNTERS
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'get_dashboard_counts') {
    try {
        $today = date('Y-m-d');

        // Today's follow-ups
        $stmt = $db->prepare("SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date = ? AND is_completed = 0 AND is_cancelled = 0");
        $stmt->execute([$today]);
        $todayCount = (int)$stmt->fetchColumn();

        // Overdue follow-ups
        $stmt = $db->prepare("SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date < ? AND is_completed = 0 AND is_cancelled = 0");
        $stmt->execute([$today]);
        $overdueCount = (int)$stmt->fetchColumn();

        // Upcoming follow-ups
        $stmt = $db->prepare("SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date > ? AND is_completed = 0 AND is_cancelled = 0");
        $stmt->execute([$today]);
        $upcomingCount = (int)$stmt->fetchColumn();

        // High-priority enquiries (high or urgent)
        $stmt = $db->query("SELECT COUNT(*) FROM admission_enquiries WHERE priority IN ('high', 'urgent') AND is_completed = 0 AND is_cancelled = 0");
        $highPriorityCount = (int)$stmt->fetchColumn();

        // Parents awaiting responses (First response pending)
        $stmt = $db->query("SELECT COUNT(*) FROM admission_enquiries WHERE followup_category = 'First response pending' AND is_completed = 0 AND is_cancelled = 0");
        $awaitingResponsesCount = (int)$stmt->fetchColumn();

        // Interested parents
        $stmt = $db->query("SELECT COUNT(*) FROM admission_enquiries WHERE followup_category = 'Parent interested' AND is_completed = 0 AND is_cancelled = 0");
        $interestedCount = (int)$stmt->fetchColumn();

        // Completed admissions
        $stmt = $db->query("SELECT COUNT(*) FROM admission_enquiries WHERE followup_category = 'Completed admission' OR is_completed = 1");
        $completedCount = (int)$stmt->fetchColumn();

        // Queue counts
        $pendingJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'pending'")->fetchColumn();
        $processingJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'processing'")->fetchColumn();
        $failedJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'failed'")->fetchColumn();

        sendJson([
            'success' => true,
            'counts' => [
                'today' => $todayCount,
                'overdue' => $overdueCount,
                'upcoming' => $upcomingCount,
                'high_priority' => $highPriorityCount,
                'awaiting_responses' => $awaitingResponsesCount,
                'interested' => $interestedCount,
                'completed' => $completedCount,
                'pending_jobs' => $pendingJobs,
                'processing_jobs' => $processingJobs,
                'failed_jobs' => $failedJobs,
            ]
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. LIST FOLLOW-UP ENQUIRIES (WITH FILTERS & SEARCH)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $section = trim($_GET['section'] ?? 'all'); // today, overdue, upcoming, high_priority, awaiting, interested, completed, all
        $category = trim($_GET['category'] ?? '');
        $priority = trim($_GET['priority'] ?? '');
        $status = trim($_GET['reminder_status'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $staff = trim($_GET['staff'] ?? '');
        $today = date('Y-m-d');

        $where = ["1=1"];
        $params = [];

        // Apply Section Filter
        switch ($section) {
            case 'today':
                $where[] = "ae.next_followup_date = ? AND ae.is_completed = 0 AND ae.is_cancelled = 0";
                $params[] = $today;
                break;
            case 'overdue':
                $where[] = "ae.next_followup_date < ? AND ae.is_completed = 0 AND ae.is_cancelled = 0";
                $params[] = $today;
                break;
            case 'upcoming':
                $where[] = "ae.next_followup_date > ? AND ae.is_completed = 0 AND ae.is_cancelled = 0";
                $params[] = $today;
                break;
            case 'high_priority':
                $where[] = "ae.priority IN ('high', 'urgent') AND ae.is_completed = 0 AND ae.is_cancelled = 0";
                break;
            case 'awaiting':
            case 'awaiting_responses':
                $where[] = "ae.followup_category = 'First response pending' AND ae.is_completed = 0 AND ae.is_cancelled = 0";
                break;
            case 'interested':
                $where[] = "ae.followup_category = 'Parent interested' AND ae.is_completed = 0 AND ae.is_cancelled = 0";
                break;
            case 'completed':
                $where[] = "(ae.followup_category = 'Completed admission' OR ae.is_completed = 1)";
                break;
            case 'active':
                $where[] = "ae.is_completed = 0 AND ae.is_cancelled = 0";
                break;
        }

        if ($category !== '') {
            $where[] = "ae.followup_category = ?";
            $params[] = $category;
        }
        if ($priority !== '') {
            $where[] = "ae.priority = ?";
            $params[] = $priority;
        }
        if ($status !== '') {
            $where[] = "ae.reminder_status = ?";
            $params[] = $status;
        }
        if ($staff !== '') {
            $where[] = "ae.assigned_staff_name = ?";
            $params[] = $staff;
        }
        if ($search !== '') {
            $where[] = "(ae.parent_name LIKE ? OR ae.student_name LIKE ? OR ae.phone_number LIKE ? OR ae.phone_e164 LIKE ? OR ae.interested_class LIKE ? OR ae.followup_reason LIKE ?)";
            $searchTerm = "%{$search}%";
            array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        }

        $whereClause = implode(" AND ", $where);
        $orderClause = "CASE WHEN ae.priority = 'urgent' THEN 1 WHEN ae.priority = 'high' THEN 2 WHEN ae.priority = 'medium' THEN 3 ELSE 4 END ASC, ae.next_followup_date ASC, ae.next_followup_time ASC";

        $sql = "SELECT ae.*, 
                       DATEDIFF(ae.next_followup_date, CURDATE()) as days_diff
                FROM admission_enquiries ae
                WHERE {$whereClause}
                ORDER BY {$orderClause}
                LIMIT 200";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $enquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendJson([
            'success' => true,
            'count' => count($enquiries),
            'section' => $section,
            'enquiries' => $enquiries
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. GET SINGLE ENQUIRY DETAILS (WITH HISTORY & CONVERSATION)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'get') {
    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            sendJson(['success' => false, 'error' => 'Invalid enquiry ID'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
        $stmt->execute([$id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enquiry) {
            sendJson(['success' => false, 'error' => 'Admission enquiry not found'], 404);
        }

        // Fetch follow-up history
        $histStmt = $db->prepare("
            SELECT * FROM admission_followup_history 
            WHERE enquiry_id = ? 
            ORDER BY id DESC, created_at DESC
        ");
        $histStmt->execute([$id]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch parent WhatsApp conversation messages
        $phoneE164 = $enquiry['phone_e164'] ?? '';
        $cleanPhone = preg_replace('/\D/', '', $phoneE164);
        $phone10 = strlen($cleanPhone) > 10 ? substr($cleanPhone, -10) : $cleanPhone;

        $conversationMessages = [];
        $chatInfo = null;

        if (!empty($enquiry['chat_id'])) {
            $chatStmt = $db->prepare("
                SELECT c.id, c.wa_chat_id, c.name, c.avatar_url, c.unread_count
                FROM whatsapp_chats c
                WHERE c.id = ?
                LIMIT 1
            ");
            $chatStmt->execute([(int)$enquiry['chat_id']]);
            $chatInfo = $chatStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$chatInfo && $cleanPhone !== '') {
            // Find chat
            $chatStmt = $db->prepare("
                SELECT c.id, c.wa_chat_id, c.name, c.avatar_url, c.unread_count
                FROM whatsapp_chats c
                WHERE c.wa_chat_id LIKE ? OR c.wa_chat_id LIKE ?
                LIMIT 1
            ");
            $chatStmt->execute(["%{$cleanPhone}%", "%{$phone10}%"]);
            $chatInfo = $chatStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($chatInfo) {
            $msgStmt = $db->prepare("
                SELECT m.id, m.wa_msg_id, m.from_me, m.sender_name, m.msg_type, 
                       m.body, m.status, m.received_at
                FROM whatsapp_messages m
                WHERE m.chat_id = ?
                ORDER BY m.id ASC
                LIMIT 100
            ");
            $msgStmt->execute([(int)$chatInfo['id']]);
            $conversationMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Always merge admission_enquiry_messages into conversationMessages so all persistent messages appear
        $emStmt = $db->prepare("
            SELECT id, 'text' as msg_type, 
                   CASE WHEN message_type = 'incoming_message' THEN 0 ELSE 1 END as from_me,
                   body, 'delivered' as status, created_at as received_at, admin_name as sender_name, metadata
            FROM admission_enquiry_messages
            WHERE enquiry_id = ?
            ORDER BY id ASC
        ");
        $emStmt->execute([$id]);
        $enqMessages = $emStmt->fetchAll(PDO::FETCH_ASSOC);

        $existingBodies = [];
        foreach ($conversationMessages as $cm) {
            $b = trim((string)($cm['body'] ?? ''));
            if ($b !== '') $existingBodies[mb_strtolower($b)] = true;
        }
        foreach ($enqMessages as $em) {
            $b = trim((string)($em['body'] ?? ''));
            if ($b !== '' && !isset($existingBodies[mb_strtolower($b)])) {
                $conversationMessages[] = $em;
                $existingBodies[mb_strtolower($b)] = true;
            }
        }
        usort($conversationMessages, fn($a, $b) => strcmp((string)($a['received_at'] ?? ''), (string)($b['received_at'] ?? '')));

        // If no suggested message yet, generate a default based on category
        if (empty($enquiry['suggested_message'])) {
            $enquiry['suggested_message'] = generateDefaultSuggestedMessage(
                $enquiry['parent_name'] ?? 'Parent',
                $enquiry['student_name'] ?? 'your child',
                $enquiry['interested_class'] ?? 'STD',
                $enquiry['followup_category'] ?? 'First response pending',
                $enquiry['followup_reason'] ?? ''
            );
        }

        sendJson([
            'success' => true,
            'enquiry' => $enquiry,
            'history' => $history,
            'chat' => $chatInfo,
            'messages' => $conversationMessages
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. CREATE ADMISSION ENQUIRY WITH FOLLOW-UP
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'create') {
    verifyApiCSRF();
    try {
        $parentName = trim($_POST['parent_name'] ?? '');
        $studentName = trim($_POST['student_name'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['parent_email'] ?? '');
        $grade = trim($_POST['interested_class'] ?? '');
        $academicYear = trim($_POST['academic_year'] ?? '2026-2027');
        $category = trim($_POST['followup_category'] ?? 'First response pending');
        $followupDate = trim($_POST['next_followup_date'] ?? date('Y-m-d'));
        $followupTime = trim($_POST['next_followup_time'] ?? '10:00:00');
        $followupReason = trim($_POST['followup_reason'] ?? '');
        $staffName = trim($_POST['assigned_staff_name'] ?? 'Admissions Desk');
        $priority = trim($_POST['priority'] ?? 'medium');
        $internalNotes = trim($_POST['internal_notes'] ?? '');
        $recommendedAction = trim($_POST['next_recommended_action'] ?? '');
        $originalMessage = trim($_POST['original_message'] ?? '');

        if ($parentName === '' || $phone === '' || $grade === '') {
            sendJson(['success' => false, 'error' => 'Parent Name, Phone Number, and Class/Grade are required.'], 400);
        }

        $cleanDigits = preg_replace('/\D/', '', $phone);
        $phoneE164 = strpos($phone, '+') === 0 ? '+' . $cleanDigits : (strlen($cleanDigits) === 10 ? '+91' . $cleanDigits : '+' . $cleanDigits);

        if ($followupReason === '') {
            $followupReason = "Follow-up regarding admission for {$studentName} in {$grade}";
        }
        if ($recommendedAction === '') {
            $recommendedAction = "Review parent enquiry, share prospectus and schedule interaction.";
        }

        $suggestedMsg = generateDefaultSuggestedMessage($parentName, $studentName, $grade, $category, $followupReason);

        $sql = "INSERT INTO admission_enquiries (
            parent_name, phone_number, phone_e164, parent_email, student_name,
            interested_class, academic_year, original_message, followup_category,
            next_followup_date, next_followup_time, followup_reason, assigned_staff_name,
            priority, reminder_status, next_recommended_action, internal_notes,
            suggested_message, created_by, created_by_name
        ) VALUES (
            ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, 
            ?, ?, ?, ?, 
            ?, 'pending', ?, ?, 
            ?, ?, ?
        )";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $parentName, $phone, $phoneE164, $email, $studentName,
            $grade, $academicYear, $originalMessage, $category,
            $followupDate, $followupTime, $followupReason, $staffName,
            $priority, $recommendedAction, $internalNotes,
            $suggestedMsg, $currentUserId, $currentUserName
        ]);

        $enquiryId = (int)$db->lastInsertId();

        // Insert initial history
        $histStmt = $db->prepare("INSERT INTO admission_followup_history (
            enquiry_id, action, title, details, performed_by, performed_by_id, manual_sent_confirmed, delivery_verified
        ) VALUES (?, 'created', 'Admission Enquiry Registered', ?, ?, ?, 0, 0)");

        $histDetails = "Enquiry created for student {$studentName} applying for {$grade}. Follow-up scheduled for {$followupDate} at {$followupTime} ({$priority} priority). Category: {$category}.";
        $histStmt->execute([$enquiryId, $histDetails, $currentUserName, $currentUserId]);

        // Audit central log
        rcLogAudit('admission_followup_create', "enquiry_{$enquiryId}", "Created enquiry for {$parentName} ({$studentName}, {$grade}). Assigned: {$staffName}");

        sendJson([
            'success' => true,
            'message' => 'Admission enquiry created successfully.',
            'enquiry_id' => $enquiryId
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. UPDATE ADMISSION FOLLOW-UP RECORD (AUDIT RECORDED)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'update') {
    verifyApiCSRF();
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            sendJson(['success' => false, 'error' => 'Invalid enquiry ID'], 400);
        }

        $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            sendJson(['success' => false, 'error' => 'Enquiry not found'], 404);
        }

        $category = trim($_POST['followup_category'] ?? $existing['followup_category']);
        $followupDate = trim($_POST['next_followup_date'] ?? $existing['next_followup_date']);
        $followupTime = trim($_POST['next_followup_time'] ?? $existing['next_followup_time']);
        $followupReason = trim($_POST['followup_reason'] ?? $existing['followup_reason']);
        $staffName = trim($_POST['assigned_staff_name'] ?? $existing['assigned_staff_name']);
        $priority = trim($_POST['priority'] ?? $existing['priority']);
        $internalNotes = trim($_POST['internal_notes'] ?? $existing['internal_notes']);
        $recommendedAction = trim($_POST['next_recommended_action'] ?? $existing['next_recommended_action']);
        $suggestedMsg = trim($_POST['suggested_message'] ?? $existing['suggested_message']);

        // Check if rescheduled
        $isRescheduled = ($followupDate !== $existing['next_followup_date'] || $followupTime !== $existing['next_followup_time']);

        $updateSql = "UPDATE admission_enquiries SET
            followup_category = ?,
            next_followup_date = ?,
            next_followup_time = ?,
            followup_reason = ?,
            assigned_staff_name = ?,
            priority = ?,
            internal_notes = ?,
            next_recommended_action = ?,
            suggested_message = ?,
            reminder_status = IF(? < CURDATE(), 'overdue', 'pending'),
            updated_by = ?,
            updated_at = NOW()
            WHERE id = ?";

        $upStmt = $db->prepare($updateSql);
        $upStmt->execute([
            $category, $followupDate, $followupTime, $followupReason,
            $staffName, $priority, $internalNotes, $recommendedAction,
            $suggestedMsg, $followupDate, $currentUserName, $id
        ]);

        // Audit in history
        $actionType = $isRescheduled ? 'rescheduled' : 'updated';
        $title = $isRescheduled ? 'Follow-up Rescheduled' : 'Follow-up Details Updated';
        $details = "Follow-up updated by {$currentUserName}. Category: {$category}. Priority: {$priority}. Staff: {$staffName}. Notes updated.";

        $histStmt = $db->prepare("INSERT INTO admission_followup_history (
            enquiry_id, action, title, details, previous_date, previous_time, new_date, new_time,
            performed_by, performed_by_id, manual_sent_confirmed, delivery_verified
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");

        $histStmt->execute([
            $id, $actionType, $title, $details,
            $existing['next_followup_date'], $existing['next_followup_time'],
            $followupDate, $followupTime,
            $currentUserName, $currentUserId
        ]);

        rcLogAudit('admission_followup_update', "enquiry_{$id}", "Updated follow-up #{$id} ({$actionType}). Next date: {$followupDate}");

        sendJson([
            'success' => true,
            'message' => 'Follow-up record updated successfully.'
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. OPEN WHATSAPP ACTION (LOGGED & PREPARED)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'open_whatsapp') {
    verifyApiCSRF();
    try {
        $id = (int)($_POST['id'] ?? 0);
        $messageText = trim($_POST['message'] ?? '');

        $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
        $stmt->execute([$id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enquiry) {
            sendJson(['success' => false, 'error' => 'Enquiry not found'], 404);
        }

        if ($messageText === '') {
            $messageText = $enquiry['suggested_message'] ?? '';
        }

        $phoneCheck = waValidateWhatsAppPhone((string)($enquiry['phone_e164'] ?: $enquiry['phone_number']));
        if (!$phoneCheck['ok']) {
            sendJson(['success' => false, 'error' => $phoneCheck['error']], 422);
        }
        $cleanPhone = $phoneCheck['digits'];

        // Build WhatsApp URLs. Opening either link does not send the message.
        $encodedText = rawurlencode($messageText);
        $waWebUrl = "https://web.whatsapp.com/send?phone={$cleanPhone}&text={$encodedText}";
        $waMeUrl  = "https://wa.me/{$cleanPhone}?text={$encodedText}";

        // Log open action in history
        $histStmt = $db->prepare("INSERT INTO admission_followup_history (
            enquiry_id, action, title, details, performed_by, performed_by_id, manual_sent_confirmed, delivery_verified, delivery_disclaimer
        ) VALUES (?, 'whatsapp_opened', 'WhatsApp Dispatch Opened', ?, ?, ?, 0, 0, 'Action logged: Administrator launched WhatsApp interface. Manual send required in WhatsApp.')");

        $histDetails = "Administrator {$currentUserName} clicked Open WhatsApp to contact {$enquiry['parent_name']} ({$cleanPhone}). Message reviewed/edited. Administrator must manually press send.";
        $histStmt->execute([$id, $histDetails, $currentUserName, $currentUserId]);

        rcLogAudit('admission_followup_whatsapp_open', "enquiry_{$id}", "Administrator opened WhatsApp link for {$cleanPhone}.");

        sendJson([
            'success' => true,
            'whatsapp_web_url' => $waWebUrl,
            'whatsapp_me_url' => $waMeUrl,
            'phone' => $cleanPhone,
            'message' => 'WhatsApp link generated and dispatch action recorded.'
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. MARK FOLLOW-UP COMPLETED (STRICT UNVERIFIED DELIVERY DISCLAIMER)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'mark_completed') {
    verifyApiCSRF();
    try {
        $id = (int)($_POST['id'] ?? 0);
        $completionNotes = trim($_POST['completion_notes'] ?? '');
        $manualSentConfirmed = !empty($_POST['manual_sent_confirmed']) ? 1 : 0;
        $category = trim($_POST['final_category'] ?? '');

        $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
        $stmt->execute([$id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enquiry) {
            sendJson(['success' => false, 'error' => 'Enquiry not found'], 404);
        }

        $finalCat = $category !== '' ? $category : ($enquiry['followup_category'] === 'First response pending' ? 'Admission details shared' : $enquiry['followup_category']);

        // Strictly delivery_verified = 0: Prompt: "A manual sent confirmation must not be represented as verified WhatsApp delivery."
        $upStmt = $db->prepare("UPDATE admission_enquiries SET
            is_completed = 1,
            completed_at = NOW(),
            completed_by = ?,
            completed_by_id = ?,
            completion_notes = ?,
            manual_sent_confirmed = ?,
            delivery_verified = 0,
            reminder_status = 'completed',
            followup_category = ?,
            updated_at = NOW()
            WHERE id = ?");

        $upStmt->execute([
            $currentUserName, $currentUserId, $completionNotes,
            $manualSentConfirmed, $finalCat, $id
        ]);

        // Insert into history
        $actionTitle = $manualSentConfirmed ? 'Follow-up Completed (Manual Sent Confirmed)' : 'Follow-up Marked Completed';
        $actionDetails = "Follow-up marked completed by {$currentUserName}. " . 
            ($manualSentConfirmed ? "Staff confirmed manual WhatsApp message was pressed. " : "") . 
            ($completionNotes !== '' ? "Notes: {$completionNotes}. " : "") .
            "IMPORTANT NOTICE: Manual sent confirmation is NOT a verified WhatsApp delivery receipt.";

        $histStmt = $db->prepare("INSERT INTO admission_followup_history (
            enquiry_id, action, title, details, performed_by, performed_by_id, 
            manual_sent_confirmed, delivery_verified, delivery_disclaimer
        ) VALUES (?, 'completed', ?, ?, ?, ?, ?, 0, 'Unverified delivery: Manual confirmation does not verify WhatsApp delivery.')");

        $histStmt->execute([
            $id, $actionTitle, $actionDetails,
            $currentUserName, $currentUserId, $manualSentConfirmed
        ]);

        rcLogAudit('admission_followup_complete', "enquiry_{$id}", "Completed follow-up #{$id}. Manual send confirmed: {$manualSentConfirmed}. Delivery verified: 0 (Unverified).");

        sendJson([
            'success' => true,
            'message' => 'Follow-up marked completed successfully. Manual confirmation recorded as unverified delivery.'
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. CANCEL FOLLOW-UP ENQUIRY (AUDIT RECORDED)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    verifyApiCSRF();
    try {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['cancellation_reason'] ?? 'Parent withdrew enquiry or opted out');

        $stmt = $db->prepare("SELECT * FROM admission_enquiries WHERE id = ?");
        $stmt->execute([$id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enquiry) {
            sendJson(['success' => false, 'error' => 'Enquiry not found'], 404);
        }

        $upStmt = $db->prepare("UPDATE admission_enquiries SET
            is_cancelled = 1,
            cancelled_at = NOW(),
            cancelled_by = ?,
            cancelled_by_id = ?,
            cancellation_reason = ?,
            reminder_status = 'cancelled',
            updated_at = NOW()
            WHERE id = ?");

        $upStmt->execute([$currentUserName, $currentUserId, $reason, $id]);

        // Insert into history
        $histStmt = $db->prepare("INSERT INTO admission_followup_history (
            enquiry_id, action, title, details, performed_by, performed_by_id, manual_sent_confirmed, delivery_verified
        ) VALUES (?, 'cancelled', 'Follow-up Cancelled', ?, ?, ?, 0, 0)");

        $histStmt->execute([
            $id, "Enquiry cancelled by {$currentUserName}. Reason: {$reason}",
            $currentUserName, $currentUserId
        ]);

        rcLogAudit('admission_followup_cancel', "enquiry_{$id}", "Cancelled follow-up #{$id}. Reason: {$reason}");

        sendJson([
            'success' => true,
            'message' => 'Follow-up enquiry cancelled.'
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 9. RUST AUTOMATION ENGINE TRIGGER & HEALTH
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'engine_status') {
    try {
        $isWin = (PHP_OS_FAMILY === 'Windows');
        $binaryPath = afFindEngineBinary();
        $hasBinary = ($binaryPath !== '');

        $liveStatus = null;
        if ($hasBinary) {
            $statusExec = afRunAutomationEngine($binaryPath, '--status');
            if ($statusExec['exit_code'] === 0 && $statusExec['stdout'] !== '') {
                $decodedStatus = json_decode($statusExec['stdout'], true);
                if (is_array($decodedStatus)) {
                    $liveStatus = $decodedStatus;
                }
            }
        }

        // Fetch queue stats from MySQL
        $pendingJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'pending'")->fetchColumn();
        $processingJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'processing'")->fetchColumn();
        $completedJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'completed'")->fetchColumn();
        $failedJobs = (int)$db->query("SELECT COUNT(*) FROM automation_jobs WHERE status = 'failed'")->fetchColumn();

        // Recent job executions
        $recentExecutions = $db->query("
            SELECT * FROM automation_job_history
            ORDER BY id DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Check if daemon is running via pgrep (POSIX) or tasklist (Windows)
        $daemonRunning = false;
        $daemonPid = null;
        if (function_exists('exec')) {
            $output = [];
            if ($isWin) {
                @exec('tasklist /FI "IMAGENAME eq hcc-automation-engine.exe" /NH /FO CSV 2>NUL', $output);
                foreach ($output as $line) {
                    if (preg_match('/^"hcc-automation-engine\.exe","(\d+)"/i', trim($line), $m)) {
                        $daemonRunning = true;
                        $daemonPid = (int)$m[1];
                        break;
                    }
                }
            } else {
                @exec('pgrep -f "hcc-automation-engine --daemon"', $output);
                if (!empty($output) && is_numeric($output[0])) {
                    $daemonRunning = true;
                    $daemonPid = (int)$output[0];
                }
            }
        }

        sendJson([
            'success' => true,
            'engine' => [
                'has_binary' => $hasBinary,
                'binary_path' => $binaryPath,
                'daemon_running' => $daemonRunning,
                'daemon_pid' => $daemonPid,
                'live_status' => $liveStatus,
                'queue' => [
                    'pending' => $pendingJobs,
                    'processing' => $processingJobs,
                    'completed' => $completedJobs,
                    'failed' => $failedJobs,
                ],
                'recent_executions' => $recentExecutions,
            ],
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'run_engine') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        sendJson(['success' => false, 'error' => 'Method Not Allowed: POST required'], 405);
    }
    verifyApiCSRF();
    try {
        $binaryPath = afFindEngineBinary();
        $engineOutput = '';
        $returnCode = 0;

        if ($binaryPath !== '' && file_exists($binaryPath)) {
            $res = afRunAutomationEngine($binaryPath, '--once');
            $returnCode = (int)$res['exit_code'];
            $engineOutput = trim($res['stdout'] . ($res['stderr'] !== '' ? "\n" . $res['stderr'] : ''));
        } else {
            // PHP fallback runner for pure-environment compatibility
            $engineOutput = runPhpFallbackEngine($db);
        }

        sendJson([
            'success' => $returnCode === 0,
            'output' => $engineOutput,
            'exit_code' => $returnCode,
            'message' => $returnCode === 0 ? 'Automation engine executed successfully.' : 'Automation engine reported a failure.',
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 10. AUTOMATION #9: INBOUND PARENT SIGNAL ACTION CARDS
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'signal_cards' || $action === 'list_signal_cards' || $action === 'apply_signal_card' || $action === 'dismiss_signal_card') {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'inbound_signal_cards'")->fetchColumn();
        if (!$tableCheck) {
            sendJson(['success' => true, 'cards' => [], 'pending_count' => 0, 'migration_required' => true]);
        }

        $op = trim((string)($_GET['op'] ?? $_POST['op'] ?? $afJsonBody['op'] ?? 'list'));
        if ($action === 'list_signal_cards') $op = 'list';
        if ($action === 'apply_signal_card') $op = 'apply';
        if ($action === 'dismiss_signal_card') $op = 'dismiss';

        if ($op === 'list') {
            $statusFilter = trim((string)($_GET['status'] ?? 'pending'));
            if (!in_array($statusFilter, ['pending', 'applied', 'dismissed', 'all'], true)) {
                $statusFilter = 'pending';
            }
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
            $where = $statusFilter === 'all' ? '' : 'WHERE sc.status = ?';
            $sql = "
                SELECT sc.*,
                       c.name AS chat_name,
                       c.wa_chat_id,
                       c.linked_student_roll,
                       c.linked_class_key,
                       m.body AS message_body,
                       m.received_at AS message_received_at
                FROM inbound_signal_cards sc
                LEFT JOIN whatsapp_chats c ON c.id = sc.chat_id
                LEFT JOIN whatsapp_messages m ON m.id = sc.message_id
                {$where}
                ORDER BY FIELD(sc.status, 'pending', 'applied', 'dismissed'), sc.created_at DESC
                LIMIT {$limit}
            ";
            $st = $db->prepare($sql);
            $st->execute($statusFilter === 'all' ? [] : [$statusFilter]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['chat_id'] = (int)$r['chat_id'];
                $r['message_id'] = $r['message_id'] !== null ? (int)$r['message_id'] : null;
                $r['confidence'] = (float)($r['confidence'] ?? 0);
                $r['extracted'] = json_decode((string)($r['extracted_json'] ?: '{}'), true) ?: [];
            }
            unset($r);

            $pendingCount = (int)$db->query("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending'")->fetchColumn();

            sendJson([
                'success' => true,
                'cards' => $rows,
                'pending_count' => $pendingCount,
            ]);
        }

        if ($op === 'scan') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                sendJson(['success' => false, 'error' => 'Method Not Allowed: POST required'], 405);
            }
            verifyApiCSRF();
            $created = afScanInboundSignalsPhpFallback($db);
            $pendingCount = (int)$db->query("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending'")->fetchColumn();
            sendJson([
                'success' => true,
                'created' => $created,
                'pending_count' => $pendingCount,
            ]);
        }

        if ($op === 'apply') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                sendJson(['success' => false, 'error' => 'Method Not Allowed: POST required'], 405);
            }
            verifyApiCSRF();
            $cardId = (int)($_POST['id'] ?? $afJsonBody['id'] ?? 0);
            if ($cardId <= 0) {
                sendJson(['success' => false, 'error' => 'Valid signal card id is required'], 400);
            }

            $st = $db->prepare("
                SELECT sc.*, c.wa_chat_id, c.name AS chat_name
                FROM inbound_signal_cards sc
                LEFT JOIN whatsapp_chats c ON c.id = sc.chat_id
                WHERE sc.id = ?
                LIMIT 1
            ");
            $st->execute([$cardId]);
            $card = $st->fetch(PDO::FETCH_ASSOC);
            if (!$card) {
                sendJson(['success' => false, 'error' => 'Signal card not found'], 404);
            }

            $extracted = json_decode((string)($card['extracted_json'] ?: '{}'), true) ?: [];
            $signalType = (string)$card['signal_type'];
            $stuLabel = trim((string)($card['matched_student_name'] ?: ($card['chat_name'] ?: 'Student')));
            $actionNote = '';

            if ($signalType === 'phone_change') {
                $rawPhone = trim((string)($_POST['new_phone'] ?? $afJsonBody['new_phone'] ?? ($extracted['phone_mentioned'] ?? '')));
                $digits = preg_replace('/\D+/', '', $rawPhone);
                if (strlen($digits) >= 10) {
                    $ten = substr($digits, -10);
                    $e164 = '+91' . $ten;
                    $updatedTargets = [];

                    if (!empty($card['matched_roll_no'])) {
                        $cls = trim((string)($card['matched_class'] ?? ''));
                        if ($cls !== '') {
                            $rcUp = $db->prepare("UPDATE report_card SET parent_phone = ? WHERE roll_no = ? AND (CONCAT(std, '-', division) = ? OR std = ?)");
                            $rcUp->execute([$ten, $card['matched_roll_no'], $cls, $cls]);
                        } else {
                            $rcUp = $db->prepare("UPDATE report_card SET parent_phone = ? WHERE roll_no = ?");
                            $rcUp->execute([$ten, $card['matched_roll_no']]);
                        }
                        if ($rcUp->rowCount() > 0) {
                            $updatedTargets[] = 'report_card';
                        }
                    }

                    if (!empty($card['wa_chat_id'])) {
                        $wcUp = $db->prepare("UPDATE whatsapp_contacts SET phone_e164 = ? WHERE wa_id = ?");
                        $wcUp->execute([$e164, $card['wa_chat_id']]);
                        if ($wcUp->rowCount() > 0) {
                            $updatedTargets[] = 'whatsapp_contacts';
                        }
                    }

                    $aeUp = $db->prepare("UPDATE admission_enquiries SET phone_number = ?, phone_e164 = ?, updated_at = NOW() WHERE chat_id = ?");
                    $aeUp->execute([$ten, $e164, (int)$card['chat_id']]);
                    if ($aeUp->rowCount() > 0) {
                        $updatedTargets[] = 'admission_enquiries';
                    }

                    $actionNote = "Updated parent phone to {$ten}" . ($updatedTargets ? ' (' . implode(', ', $updatedTargets) . ')' : '') . '.';
                } else {
                    $actionNote = 'Phone update logged for manual verification (no valid 10-digit number found).';
                }

                $db->prepare("INSERT INTO system_notifications (title, message, type, link) VALUES (?, ?, 'signal_card', ?)")
                   ->execute([
                       "Phone Update Applied: {$stuLabel}",
                       $actionNote,
                       'whatsapp_share.php?view=replica&chat_id=' . (int)$card['chat_id'],
                   ]);
            } elseif ($signalType === 'leave') {
                $snippet = trim((string)($extracted['snippet'] ?? 'Parent notified student absence on WhatsApp.'));
                $actionNote = "Leave notice recorded for {$stuLabel} ({$card['matched_class']}).";
                $db->prepare("INSERT INTO system_notifications (title, message, type, link) VALUES (?, ?, 'leave_notice', ?)")
                   ->execute([
                       "Leave Note: {$stuLabel}",
                       mb_substr($snippet, 0, 240),
                       'whatsapp_share.php?view=replica&chat_id=' . (int)$card['chat_id'],
                   ]);
            } elseif ($signalType === 'ptm_confirm') {
                $actionNote = "PTM / Campus visit confirmation noted for {$stuLabel}.";
                $db->prepare("
                    UPDATE admission_enquiries
                    SET followup_category = 'Campus visit pending',
                        status = IF(status IN ('NEW','CONTACTED','INFORMATION_SHARED','INTERESTED'), 'VISIT_PLANNED', status),
                        updated_at = NOW()
                    WHERE chat_id = ? AND is_completed = 0 AND is_cancelled = 0
                ")->execute([(int)$card['chat_id']]);
                $db->prepare("INSERT INTO system_notifications (title, message, type, link) VALUES (?, ?, 'ptm_confirm', ?)")
                   ->execute([
                       "PTM / Visit Confirmed: {$stuLabel}",
                       $actionNote,
                       'admission_followups.php',
                   ]);
            } else {
                $labelMap = [
                    'fee_claim' => 'Fee payment claim',
                    'doc_upload' => 'Document submission',
                    'result_query' => 'Result / progress enquiry',
                    'other' => 'Parent message signal',
                ];
                $lbl = $labelMap[$signalType] ?? 'Parent signal';
                $actionNote = "{$lbl} acknowledged and logged for {$stuLabel}.";
                $db->prepare("INSERT INTO system_notifications (title, message, type, link) VALUES (?, ?, 'signal_card', ?)")
                   ->execute([
                       "{$lbl}: {$stuLabel}",
                       mb_substr((string)($extracted['snippet'] ?? $actionNote), 0, 240),
                       'whatsapp_share.php?view=replica&chat_id=' . (int)$card['chat_id'],
                   ]);
            }

            $db->prepare("UPDATE inbound_signal_cards SET status = 'applied', resolved_at = NOW() WHERE id = ?")
               ->execute([$cardId]);

            // Record history if active enquiry exists for chat
            $enqStmt = $db->prepare("SELECT id FROM admission_enquiries WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
            $enqStmt->execute([(int)$card['chat_id']]);
            if ($eid = (int)$enqStmt->fetchColumn()) {
                $db->prepare("
                    INSERT INTO admission_followup_history
                    (enquiry_id, action, title, details, performed_by, manual_sent_confirmed, delivery_verified)
                    VALUES (?, 'signal_applied', ?, ?, ?, 0, 0)
                ")->execute([
                    $eid,
                    "Applied Inbound Signal ({$signalType})",
                    $actionNote,
                    $currentUserName,
                ]);
            }

            sendJson([
                'success' => true,
                'id' => $cardId,
                'status' => 'applied',
                'action_note' => $actionNote,
                'suggested_reply' => $card['suggested_reply'],
                'send_on_press' => true,
            ]);
        }

        if ($op === 'dismiss') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                sendJson(['success' => false, 'error' => 'Method Not Allowed: POST required'], 405);
            }
            verifyApiCSRF();
            $cardId = (int)($_POST['id'] ?? $afJsonBody['id'] ?? 0);
            if ($cardId <= 0) {
                sendJson(['success' => false, 'error' => 'Valid signal card id is required'], 400);
            }
            $db->prepare("UPDATE inbound_signal_cards SET status = 'dismissed', resolved_at = NOW() WHERE id = ?")
               ->execute([$cardId]);
            sendJson(['success' => true, 'id' => $cardId, 'status' => 'dismissed']);
        }

        sendJson(['success' => false, 'error' => 'Invalid signal_cards op'], 400);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 11. AUTOMATION #13: 8:30 AM PRINCIPAL DAILY BRIEFING
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'principal_briefing') {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'daily_briefings'")->fetchColumn();
        if (!$tableCheck) {
            sendJson(['success' => true, 'briefing' => null, 'migration_required' => true]);
        }

        $op = trim((string)($_GET['op'] ?? $_POST['op'] ?? $afJsonBody['op'] ?? 'get'));
        $today = date('Y-m-d');

        if ($op === 'generate') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                sendJson(['success' => false, 'error' => 'Method Not Allowed: POST required'], 405);
            }
            verifyApiCSRF();

            $binaryPath = afFindEngineBinary();
            $engineRan = false;
            if ($binaryPath !== '') {
                $res = afRunAutomationEngine($binaryPath, '--once');
                $engineRan = ($res['exit_code'] === 0);
            }

            $briefing = afCompileAndSavePhpBriefing($db, $today, true);
            sendJson([
                'success' => true,
                'engine_ran' => $engineRan,
                'briefing' => $briefing,
            ]);
        }

        // op === 'get'
        $st = $db->prepare("SELECT * FROM daily_briefings WHERE brief_date = ? LIMIT 1");
        $st->execute([$today]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $briefing = afCompileAndSavePhpBriefing($db, $today, false);
        } else {
            $briefing = [
                'id' => (int)$row['id'],
                'brief_date' => $row['brief_date'],
                'summary_markdown' => $row['summary_markdown'],
                'metrics' => json_decode((string)($row['metrics_json'] ?: '{}'), true) ?: [],
                'created_at' => $row['created_at'],
            ];
        }

        sendJson([
            'success' => true,
            'briefing' => $briefing,
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Helper: Contextual suggested WhatsApp message generator
function generateDefaultSuggestedMessage(string $parentName, string $studentName, string $grade, string $category, string $reason): string {
    $parent = trim($parentName) !== '' ? trim($parentName) : 'Parent';
    $student = trim($studentName) !== '' ? trim($studentName) : 'your child';
    $std = trim($grade) !== '' ? trim($grade) : 'the upcoming grade';

    switch ($category) {
        case 'First response pending':
            return "Namaste {$parent} ji, thank you for contacting Hindustani Covenant Church High School regarding admission for {$student} ({$std}). Our admissions team is delighted to assist you. We offer the Maharashtra State Board (SSC) curriculum, modern digital classrooms, sports facilities, and school transport across Solapur. Would you like us to share our school prospectus and fee breakdown?";
        case 'Admission details shared':
            return "Namaste {$parent} ji, hope you had a chance to review the admission details and syllabus for {$student} in {$std}. We would be happy to schedule a campus walk-through and an interaction with our teaching faculty. Please let us know a convenient time this week.";
        case 'Parent requested more information':
            return "Namaste {$parent} ji, following up from HCC High School regarding your query about {$reason}. We have detailed information on our academic curriculum, extracurricular clubs, and transport routes ready for you. When would be a good time to discuss?";
        case 'Parent interested':
            return "Namaste {$parent} ji, we are glad to note your keen interest in HCC High School for {$student} ({$std}). Seats for the upcoming academic session are filling up rapidly. Would you like to reserve an interaction slot or collect the admission kit from our administrative office this week?";
        case 'Campus visit pending':
            return "Namaste {$parent} ji, gentle reminder regarding your campus visit to Hindustani Covenant Church High School for {$student}. Our campus is open Monday to Saturday from 9:00 AM to 2:00 PM. Please let us know if today or tomorrow suits your family.";
        case 'Documents pending':
            return "Namaste {$parent} ji, gentle reminder from HCC High School admissions desk regarding the pending documents (Birth Certificate / Previous School Transfer Certificate) for {$student}'s admission to {$std}. Kindly submit the copies at your earliest convenience to confirm the provisional seat.";
        case 'Application pending':
            return "Namaste {$parent} ji, we hope you are having a wonderful day. The admission application form for {$student} ({$std}) is currently awaiting completed submission. Please feel free to reach out if you need any assistance in completing the form.";
        case 'Admission decision pending':
            return "Namaste {$parent} ji, greetings from Hindustani Covenant Church High School. The admission evaluation for {$student} ({$std}) is under committee review. Our principal and admissions board would like to share the next steps with you. Kindly confirm if we may call you today.";
        default:
            return "Namaste {$parent} ji, following up from HCC High School admissions desk regarding admission for {$student} in {$std}. Please let us know how we can best assist your family.";
    }
}

// Pure PHP/MySQL fallback for Signal Cards (#9) + auto-advance (#12)
function afScanInboundSignalsPhpFallback(PDO $db): int {
    if (!$db->query("SHOW TABLES LIKE 'inbound_signal_cards'")->fetchColumn()) {
        return 0;
    }
    $rows = $db->query("
        SELECT m.id, m.chat_id, m.body, m.msg_type, m.sender_name, m.received_at,
               c.wa_chat_id, c.name AS chat_name, c.linked_student_roll, c.linked_class_key
        FROM whatsapp_messages m
        JOIN whatsapp_chats c ON c.id = m.chat_id
        WHERE m.from_me = 0
          AND m.msg_type != 'system'
          AND COALESCE(NULLIF(TRIM(m.body), ''), '') != ''
          AND m.received_at >= NOW() - INTERVAL 7 DAY
          AND NOT EXISTS (SELECT 1 FROM inbound_signal_cards sc WHERE sc.message_id = m.id)
        ORDER BY m.id DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    $serviceUrl = rtrim((string)(getenv('RUST_AI_SERVICE_URL') ?: 'http://127.0.0.1:8085'), '/');
    $created = 0;

    foreach ($rows as $row) {
        $body = trim((string)$row['body']);
        $lower = strtolower($body);
        $msgType = (string)$row['msg_type'];

        // Student lookup in report_card
        $stu = null;
        if (!empty($row['linked_student_roll'])) {
            $cls = trim((string)($row['linked_class_key'] ?? ''));
            if ($cls !== '') {
                $sst = $db->prepare("SELECT student_name, roll_no, CONCAT(std, '-', division) AS cls FROM report_card WHERE roll_no = ? AND (CONCAT(std, '-', division) = ? OR std = ?) ORDER BY academic_year DESC, semester DESC LIMIT 1");
                $sst->execute([$row['linked_student_roll'], $cls, $cls]);
                $stu = $sst->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$stu) {
                $sst = $db->prepare("SELECT student_name, roll_no, CONCAT(std, '-', division) AS cls FROM report_card WHERE roll_no = ? ORDER BY academic_year DESC, semester DESC LIMIT 1");
                $sst->execute([$row['linked_student_roll']]);
                $stu = $sst->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        $waChatId = (string)$row['wa_chat_id'];
        $last10 = '';
        if (!str_contains($waChatId, '@g.us') && !str_contains($waChatId, '@lid')) {
            $digits = preg_replace('/\D+/', '', explode('@', $waChatId)[0]);
            if (strlen($digits) >= 10) {
                $last10 = substr($digits, -10);
            }
        }
        if (!$stu && $last10 !== '') {
            $sst = $db->prepare("SELECT student_name, roll_no, CONCAT(std, '-', division) AS cls FROM report_card WHERE parent_phone LIKE ? ORDER BY academic_year DESC, semester DESC LIMIT 1");
            $sst->execute(['%' . $last10]);
            $stu = $sst->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Try Rust :8085 triage-inbound first with X-AI-Service-Secret
        $signalType = null;
        $confidence = 0.80;
        $suggestedReply = '';
        $extracted = ['snippet' => mb_substr($body, 0, 240)];

        $ch = curl_init($serviceUrl . '/api/triage-inbound');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => (int)$row['chat_id'],
                'message_id' => (int)$row['id'],
                'sender_name' => $row['sender_name'] ?: $row['chat_name'],
                'student_name' => $stu['student_name'] ?? null,
                'student_class' => $stu['cls'] ?? null,
                'msg_type' => $msgType,
                'message_text' => $body,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => airAiServiceHeaders(['Content-Type: application/json', 'Accept: application/json']),
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $resp) {
            $j = json_decode($resp, true);
            if (is_array($j) && !empty($j['signal_type'])) {
                $signalType = (string)$j['signal_type'];
                $confidence = (float)($j['confidence'] ?? 0.85);
                $suggestedReply = (string)($j['suggested_reply'] ?? '');
                if (is_array($j['extracted_json'] ?? null)) {
                    $extracted = array_merge($extracted, $j['extracted_json']);
                }
            }
        }

        if ($signalType === null) {
            preg_match('/\b([6-9]\d{9})\b/', $body, $pm);
            if (!empty($pm[1])) {
                $extracted['phone_mentioned'] = $pm[1];
            }
            if (preg_match('/\b(absent|leave|sick|fever|unwell|hospital|not coming)\b/i', $lower)) {
                $signalType = 'leave';
                $confidence = 0.91;
                $suggestedReply = "Respected Parent,\n\nThank you for informing the school regarding " . ($stu['student_name'] ?? 'your child') . "'s absence. We have noted the leave notice for the class teacher.\n\nWarm regards,\nHCC High School Office";
            } elseif (!empty($pm[1]) && preg_match('/\b(number|mobile|phone|change|update|new)\b/i', $lower)) {
                $signalType = 'phone_change';
                $confidence = 0.90;
                $suggestedReply = "Respected Parent,\n\nThank you for sharing the updated contact number ({$pm[1]}). Our office will verify and update the record.\n\nWarm regards,\nHCC High School Office";
            } elseif (preg_match('/\b(paid|payment|upi|utr|receipt|transaction|transferred|challan)\b/i', $lower)) {
                $signalType = 'fee_claim';
                $confidence = 0.89;
                $suggestedReply = "Respected Parent,\n\nThank you for sharing the fee payment update. Our accounts desk will verify the transaction shortly.\n\nWarm regards,\nHCC High School Accounts Desk";
            } elseif (preg_match('/\b(ptm|meeting|will attend|coming tomorrow|confirm)\b/i', $lower)) {
                $signalType = 'ptm_confirm';
                $confidence = 0.85;
                $suggestedReply = "Respected Parent,\n\nThank you for confirming your visit/PTM slot. Kindly carry a photo ID for campus entry.\n\nWarm regards,\nHCC High School Office";
            } elseif (preg_match('/\b(result|marks|report card|percentage|unit test|exam)\b/i', $lower)) {
                $signalType = 'result_query';
                $confidence = 0.87;
                $suggestedReply = "Respected Parent,\n\nWe are reviewing " . ($stu['student_name'] ?? 'the student') . "'s exam marks and will share the update shortly.\n\nWarm regards,\nHCC High School Office";
            } elseif (in_array($msgType, ['image', 'document'], true) || preg_match('/\b(aadhar|aadhaar|birth certificate|leaving certificate|document)\b/i', $lower)) {
                $signalType = 'doc_upload';
                $confidence = 0.84;
                $suggestedReply = "Respected Parent,\n\nThank you for sharing the document. Our admission desk will verify it against the checklist.\n\nWarm regards,\nHCC High School Office";
            } else {
                $signalType = 'other';
                $confidence = 0.60;
                $suggestedReply = "Respected Parent,\n\nThank you for your message. Our staff will respond during school office hours (09:00 AM to 03:30 PM).\n\nWarm regards,\nHCC High School Office";
            }
        }

        $status = $signalType === 'other' ? 'dismissed' : 'pending';
        $resolvedAt = $status === 'dismissed' ? date('Y-m-d H:i:s') : null;
        $ins = $db->prepare("
            INSERT IGNORE INTO inbound_signal_cards
            (chat_id, message_id, signal_type, matched_student_name, matched_roll_no, matched_class, extracted_json, confidence, suggested_reply, status, resolved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            (int)$row['chat_id'],
            (int)$row['id'],
            $signalType,
            $stu['student_name'] ?? null,
            $stu['roll_no'] ?? null,
            $stu['cls'] ?? null,
            json_encode($extracted, JSON_UNESCAPED_UNICODE),
            $confidence,
            $suggestedReply,
            $status,
            $resolvedAt,
        ]);
        if ($ins->rowCount() > 0 && $status === 'pending') {
            $created++;
        }
    }

    return $created;
}

// Pure PHP/MySQL Principal Briefing generator (#13)
function afCompileAndSavePhpBriefing(PDO $db, string $briefDate, bool $tryAi = false): array {
    $unrepliedChats = (int)$db->query("
        SELECT COUNT(*)
        FROM whatsapp_chats c
        JOIN whatsapp_messages m ON m.id = c.last_message_id
        WHERE c.chat_type = 'individual'
          AND m.from_me = 0
          AND c.last_activity_at >= NOW() - INTERVAL 7 DAY
    ")->fetchColumn();

    $todayFollowups = (int)$db->query("SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date = CURDATE() AND is_completed = 0 AND is_cancelled = 0")->fetchColumn();
    $overdueFollowups = (int)$db->query("SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date < CURDATE() AND is_completed = 0 AND is_cancelled = 0")->fetchColumn();
    $urgentEnquiries = (int)$db->query("SELECT COUNT(*) FROM admission_enquiries WHERE priority IN ('high', 'urgent') AND is_completed = 0 AND is_cancelled = 0")->fetchColumn();

    $pendingSignals = 0;
    $leaveSignals = 0;
    $feeSignals = 0;
    if ($db->query("SHOW TABLES LIKE 'inbound_signal_cards'")->fetchColumn()) {
        $pendingSignals = (int)$db->query("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending'")->fetchColumn();
        $leaveSignals = (int)$db->query("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending' AND signal_type = 'leave'")->fetchColumn();
        $feeSignals = (int)$db->query("SELECT COUNT(*) FROM inbound_signal_cards WHERE status = 'pending' AND signal_type = 'fee_claim'")->fetchColumn();
    }

    $domHealth = (string)($db->query("SELECT COALESCE(dom_health_status, 'unknown') FROM whatsapp_sessions ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 'unknown');

    $metrics = [
        'brief_date' => $briefDate,
        'unreplied_chats' => $unrepliedChats,
        'today_followups' => $todayFollowups,
        'overdue_followups' => $overdueFollowups,
        'urgent_enquiries' => $urgentEnquiries,
        'pending_signals' => $pendingSignals,
        'leave_signals' => $leaveSignals,
        'fee_signals' => $feeSignals,
        'dom_health_status' => $domHealth,
    ];

    $markdown = "### HCC High School — 8:30 AM Principal Daily Briefing ({$briefDate})\n"
        . "- **Un-replied Parent Chats (7d):** {$unrepliedChats}\n"
        . "- **Due Today Follow-ups:** {$todayFollowups} | **Overdue:** {$overdueFollowups} | **High/Urgent Active:** {$urgentEnquiries}\n"
        . "- **Pending Inbound Signal Cards:** {$pendingSignals} (Leave notices: {$leaveSignals}, Fee claims: {$feeSignals})\n"
        . "- **WhatsApp Bridge DOM Health:** {$domHealth}\n\n"
        . "_All parent replies require manual staff verification and Send-on-Press in WhatsApp Hub._";

    if ($tryAi) {
        $disc = airDiscoverLocalAi();
        if (!empty($disc['ollama'])) {
            $prompt = "Summarize these exact daily school metrics into 3 crisp executive bullet points for the School Principal. Never change or invent any numbers:\n"
                . json_encode($metrics)
                . "\nReturn plain text only (no HTML).";
            $gen = airCallOllamaGenerate($prompt, $disc['default_model'], 25);
            if (!empty($gen['ok']) && !empty($gen['raw']) && strlen(trim((string)$gen['raw'])) > 30) {
                $markdown .= "\n\n**AI Executive Focus:**\n" . trim((string)$gen['raw']);
            }
        }
    }

    $up = $db->prepare("
        INSERT INTO daily_briefings (brief_date, summary_markdown, metrics_json)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            summary_markdown = VALUES(summary_markdown),
            metrics_json = VALUES(metrics_json)
    ");
    $up->execute([$briefDate, $markdown, json_encode($metrics, JSON_UNESCAPED_UNICODE)]);

    $st = $db->prepare("SELECT * FROM daily_briefings WHERE brief_date = ? LIMIT 1");
    $st->execute([$briefDate]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return [
        'id' => $row ? (int)$row['id'] : 0,
        'brief_date' => $briefDate,
        'summary_markdown' => $markdown,
        'metrics' => $metrics,
        'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
    ];
}

// Fallback PHP engine processor when invoked without standalone binary
function runPhpFallbackEngine(PDO $db): string {
    // Recover only stale stuck processing jobs (>5 minutes)
    $db->exec("
        UPDATE automation_jobs
        SET status = 'pending', locked_at = NULL, locked_by = NULL
        WHERE status = 'processing'
          AND (locked_at < NOW() - INTERVAL 5 MINUTE OR locked_at IS NULL)
    ");

    // Scan and enqueue due reminders without re-scanning already-scheduled overdue rows
    $stmt = $db->query("
        SELECT id, parent_name, student_name, interested_class, followup_category,
               next_followup_date, COALESCE(next_followup_time, '10:00:00') as next_followup_time,
               COALESCE(followup_reason, '') as followup_reason, priority
        FROM admission_enquiries
        WHERE is_completed = 0 AND is_cancelled = 0
          AND next_followup_date IS NOT NULL
          AND TIMESTAMP(next_followup_date, COALESCE(next_followup_time, '10:00:00')) <= NOW()
          AND (
              reminder_status = 'pending'
              OR (
                  reminder_status = 'overdue'
                  AND NOT EXISTS (
                      SELECT 1 FROM automation_jobs aj
                      WHERE aj.dedup_key = CONCAT(
                          'reminder_',
                          admission_enquiries.id,
                          '_',
                          DATE_FORMAT(admission_enquiries.next_followup_date, '%Y-%m-%d'),
                          '_',
                          TIME_FORMAT(COALESCE(admission_enquiries.next_followup_time, '10:00:00'), '%H:%i:%s')
                      )
                  )
              )
          )
    ");
    $enqueued = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dedup = "reminder_{$row['id']}_{$row['next_followup_date']}_{$row['next_followup_time']}";
        $payload = json_encode(['enquiry_id' => (int)$row['id'], 'action' => 'generate_reminder']);
        $pVal = $row['priority'] === 'urgent' ? 30 : ($row['priority'] === 'high' ? 20 : 10);
        $ins = $db->prepare("INSERT IGNORE INTO automation_jobs (job_type, payload, dedup_key, status, priority, max_attempts, scheduled_at) VALUES ('admission_reminder', ?, ?, 'pending', ?, 3, NOW())");
        $ins->execute([$payload, $dedup, $pVal]);
        if ($ins->rowCount() > 0) $enqueued++;
    }

    $signals = afScanInboundSignalsPhpFallback($db);
    if ($db->query("SHOW TABLES LIKE 'daily_briefings'")->fetchColumn()) {
        afCompileAndSavePhpBriefing($db, date('Y-m-d'), false);
    }

    return "PHP Fallback Engine processed: enqueued {$enqueued} due reminders, triaged {$signals} new inbound signals.";
}

// Unknown action fallback
sendJson(['success' => false, 'error' => 'Invalid or missing API action: ' . htmlspecialchars($action)], 400);
