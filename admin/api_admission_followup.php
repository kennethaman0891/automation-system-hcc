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

$action = trim($_GET['action'] ?? ($_POST['action'] ?? ''));

function sendJson(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function verifyApiCSRF(): void {
    if (!verifyCSRFToken()) {
        sendJson(['success' => false, 'error' => 'Security token invalid or expired. Please refresh the page.'], 403);
    }
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

        if ($cleanPhone !== '') {
            // Find chat
            $chatStmt = $db->prepare("
                SELECT c.id, c.wa_chat_id, c.name, c.avatar_url, c.unread_count
                FROM whatsapp_chats c
                WHERE c.wa_chat_id LIKE ? OR c.wa_chat_id LIKE ?
                LIMIT 1
            ");
            $chatStmt->execute(["%{$cleanPhone}%", "%{$phone10}%"]);
            $chatInfo = $chatStmt->fetch(PDO::FETCH_ASSOC);

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
        }

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
        $exeSuffix = $isWin ? '.exe' : '';
        $binaryCandidates = [
            __DIR__ . '/../automation/bin/hcc-automation-engine' . $exeSuffix,
            __DIR__ . '/../automation/rust_engine/target/release/hcc-automation-engine' . $exeSuffix,
        ];
        if ($isWin) {
            $binaryCandidates[] = __DIR__ . '/../automation/bin/hcc-automation-engine';
            $binaryCandidates[] = __DIR__ . '/../automation/rust_engine/target/release/hcc-automation-engine';
        }
        $binaryPath = '';
        foreach ($binaryCandidates as $cand) {
            if (file_exists($cand) && ($isWin || is_executable($cand))) {
                $binaryPath = $cand;
                break;
            }
        }
        $hasBinary = ($binaryPath !== '');

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
                'queue' => [
                    'pending' => $pendingJobs,
                    'processing' => $processingJobs,
                    'completed' => $completedJobs,
                    'failed' => $failedJobs,
                ],
                'recent_executions' => $recentExecutions,
            ]
        ]);
    } catch (\Throwable $e) {
        sendJson(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'run_engine') {
    verifyApiCSRF();
    try {
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
        $binaryPath = '';
        foreach ($binaryCandidates as $cand) {
            if (file_exists($cand) && ($isWin || is_executable($cand))) {
                $binaryPath = $cand;
                break;
            }
        }

        $engineOutput = '';
        $returnCode = 0;

        if ($binaryPath !== '' && file_exists($binaryPath)) {
            $cmd = escapeshellcmd($binaryPath) . ' --once 2>&1';
            exec($cmd, $lines, $returnCode);
            $engineOutput = implode("\n", $lines);
        } else {
            // PHP fallback runner for pure-environment compatibility
            $engineOutput = runPhpFallbackEngine($db);
        }

        sendJson([
            'success' => $returnCode === 0,
            'output' => $engineOutput,
            'exit_code' => $returnCode,
            'message' => 'Automation engine executed successfully.'
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
            return "Namaste {$parent} ji, thank you for contacting Hindustani Covenant Church High School regarding admission for {$student} ({$std}). Our admissions team is delighted to assist you. We offer holistic curriculum, modern digital classrooms, sports facilities, and school transport across Solapur. Would you like us to share our school prospectus and fee breakdown?";
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

// Fallback PHP engine processor when invoked without standalone binary
function runPhpFallbackEngine(PDO $db): string {
    $now = date('Y-m-d H:i:s');
    // Recover stuck processing jobs
    $db->exec("UPDATE automation_jobs SET status = 'pending', locked_at = NULL, locked_by = NULL WHERE status = 'processing'");
    
    // Scan and enqueue due reminders
    $stmt = $db->query("
        SELECT id, parent_name, student_name, interested_class, followup_category, 
               next_followup_date, COALESCE(next_followup_time, '10:00:00') as next_followup_time,
               COALESCE(followup_reason, '') as followup_reason, priority
        FROM admission_enquiries
        WHERE is_completed = 0 AND is_cancelled = 0
          AND reminder_status IN ('pending', 'overdue')
          AND TIMESTAMP(next_followup_date, COALESCE(next_followup_time, '10:00:00')) <= NOW()
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

    return "PHP Engine processed: enqueued {$enqueued} due reminders.";
}

// Unknown action fallback
sendJson(['success' => false, 'error' => 'Invalid or missing API action: ' . htmlspecialchars($action)], 400);
