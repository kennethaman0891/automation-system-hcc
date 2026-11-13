<?php
/**
 * HCC WhatsApp Replica — Inbound Message Ingestion
 * Receives messages from Puppeteer bridge via HMAC-authenticated POST
 * POST /admin/api_whatsapp_ingest.php
 * Headers: X-WA-Signature: <hmac_sha256(payload, secret)>
 * Body: JSON array of messages
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// HMAC only. Missing or short bridge secret is a hard 401 — no built-in fallback.
if (!defined('WHATSAPP_BRIDGE_SECRET') || !is_string(WHATSAPP_BRIDGE_SECRET) || strlen(WHATSAPP_BRIDGE_SECRET) < 16) {
    waJsonResponse(['success' => false, 'error' => 'Bridge secret is not configured'], 401);
}
$signature = (string)($_SERVER['HTTP_X_WA_SIGNATURE'] ?? '');
$payload = file_get_contents('php://input');
$expected = hash_hmac('sha256', $payload, WHATSAPP_BRIDGE_SECRET);
if ($signature === '' || !hash_equals($expected, $signature)) {
    waJsonResponse(['success' => false, 'error' => 'Invalid signature'], 401);
}

// A broken character in one WhatsApp bubble used to make PHP reject the whole
// batch, so the new message never reached the admission list.
$jsonFlags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
$data = json_decode($payload, true, 512, $jsonFlags);
if (is_string($data)) {
    $nested = json_decode($data, true, 512, $jsonFlags);
    if (is_array($nested)) $data = $nested;
}
if (is_array($data) && isset($data['wa_msg_id']) && !isset($data[0])) {
    $data = [$data];
}
if (!is_array($data) || !isset($data[0])) {
    error_log('WA ingest rejected: bytes=' . strlen((string)$payload) . ' json=' . json_last_error_msg());
    waJsonResponse(['success' => false, 'error' => 'Expected JSON array'], 400);
}

$db = waGetDB();
$inserted = 0;
$errors = [];

// null = unknown (try the flag update and ignore a missing column). false = skip.
$hasAdmissionFlag = null;
try {
    $flagChk = $db->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'whatsapp_chats'
          AND COLUMN_NAME = 'is_admission_enquiry'
        LIMIT 1
    ");
    $flagChk->execute();
    $hasAdmissionFlag = (bool)$flagChk->fetchColumn();
} catch (Throwable $e) {
    $hasAdmissionFlag = null;
}

$db->beginTransaction();
try {
    foreach ($data as $index => $msg) {
        try {
            // Validate required fields
            if (empty($msg['wa_msg_id']) || empty($msg['wa_chat_id']) || !isset($msg['from_me']) || empty($msg['timestamp'])) {
                $errors[] = "Message $index: missing required fields";
                continue;
            }
            
            $chatType = $msg['chat_type'] ?? 'individual';
            $waChatId = (string)$msg['wa_chat_id'];
            if ($chatType === 'individual') {
                $groupLike = stripos($waChatId, '@g.us') !== false
                    || stripos($waChatId, 'broadcast') !== false
                    || stripos($waChatId, 'channel') !== false;
                $isLid = stripos($waChatId, '@lid') !== false;
                if (!$groupLike && !$isLid) {
                    // Same rules as New chat: 10-digit Indian mobiles become 91xxxxxxxxxx.
                    $checked = waValidateWhatsAppPhone($waChatId);
                    if (!$checked['ok']) {
                        $errors[] = "Message $index: invalid wa_chat_id";
                        continue;
                    }
                    $waChatId = $checked['digits'] . '@c.us';
                }
                // @lid / title:@slug@lid kept as-is (no phone available)
                if ($isLid) {
                    $waChatId = strtolower(trim($waChatId));
                }
            }

            $chatId = waGetOrCreateChat($db, $waChatId, $chatType, $msg['chat_name'] ?? null, $msg['chat_avatar'] ?? null);
            
            // Get or create sender contact (for inbound messages)
            $senderId = null;
            if (!$msg['from_me'] && !empty($msg['sender_wa_id'])) {
                $senderId = waGetOrCreateContact(
                    $db,
                    $msg['sender_wa_id'],
                    $msg['sender_phone'] ?? null,
                    $msg['sender_name'] ?? null,
                    $msg['sender_push_name'] ?? null,
                    $msg['sender_avatar'] ?? null
                );
            }
            
            // Resolve quoted message ID
            $quotedMsgId = null;
            if (!empty($msg['quoted_wa_msg_id'])) {
                $stmt = $db->prepare("SELECT id FROM whatsapp_messages WHERE wa_msg_id = ?");
                $stmt->execute([$msg['quoted_wa_msg_id']]);
                $quotedMsgId = $stmt->fetchColumn();
            }
            
            // Insert message
            waInsertMessage($db, [
                'wa_msg_id' => $msg['wa_msg_id'],
                'chat_id' => $chatId,
                'from_me' => (bool)$msg['from_me'],
                'sender_id' => $msg['sender_wa_id'] ?? null,
                'sender_name' => $msg['sender_name'] ?? null,
                'sender_avatar' => $msg['sender_avatar'] ?? null,
                'msg_type' => $msg['msg_type'] ?? 'text',
                'body' => $msg['body'] ?? null,
                'media_url' => $msg['media_url'] ?? null,
                'media_mime' => $msg['media_mime'] ?? null,
                'media_size' => $msg['media_size'] ?? null,
                'media_width' => $msg['media_width'] ?? null,
                'media_height' => $msg['media_height'] ?? null,
                'media_duration' => $msg['media_duration'] ?? null,
                'thumbnail_url' => $msg['thumbnail_url'] ?? null,
                'quoted_msg_id' => $quotedMsgId,
                'is_forwarded' => $msg['is_forwarded'] ?? false,
                'is_starred' => $msg['is_starred'] ?? false,
                'status' => $msg['from_me'] ? 'sent' : 'delivered',
                'timestamp' => (int)$msg['timestamp'],
                'timestamp_parsed' => !empty($msg['timestamp_parsed']),
                'metadata' => $msg['metadata'] ?? null
            ]);
            $inserted++;

            // A chat-list preview (bridge could not open the chat) is a stand-in. When the
            // real message arrives, drop the stand-in so the hub does not show it twice.
            $isPreviewOnly = is_array($msg['metadata'] ?? null) && !empty($msg['metadata']['preview_only']);
            if (!$isPreviewOnly && empty($msg['from_me']) && trim((string)($msg['body'] ?? '')) !== '') {
                try {
                    waDropSupersededPreviews($db, (int)$chatId, (string)$msg['body']);
                } catch (Throwable $prevEx) {
                    error_log('Preview cleanup warning: ' . $prevEx->getMessage());
                }
            }

            // ── Automatically sync with Admission Enquiries & Follow-ups Pipeline ──
            // Incoming individual messages (including previews of a chat we tried to open)
            // sync so a prospective parent is not dropped. A sidebar-only preview is just
            // the latest line for the admission message list — it must not open an enquiry.
            $previewReason = '';
            if ($isPreviewOnly && is_array($msg['metadata'] ?? null)) {
                $previewReason = (string)($msg['metadata']['reason'] ?? '');
            }
            if (empty($msg['from_me']) && $previewReason !== 'sidebar') {
                try {
                    $db->exec('SAVEPOINT wa_enq_sync');
                    waSyncInboundAdmissionEnquiry($db, $msg, $chatId, $senderId, $waChatId, $chatType);
                    $db->exec('RELEASE SAVEPOINT wa_enq_sync');
                } catch (Throwable $enqEx) {
                    try {
                        $db->exec('ROLLBACK TO SAVEPOINT wa_enq_sync');
                    } catch (Throwable $ignored) {}
                    error_log('Admission enquiry sync warning: ' . $enqEx->getMessage());
                }
            } elseif (!empty($msg['from_me'])) {
                // Outgoing messages sent by school staff: record into enquiry conversation timeline
                try {
                    $db->exec('SAVEPOINT wa_out_sync');
                    waSyncOutboundAdmissionEnquiry($db, $msg, $chatId, $waChatId);
                    $db->exec('RELEASE SAVEPOINT wa_out_sync');
                } catch (Throwable $outEx) {
                    try {
                        $db->exec('ROLLBACK TO SAVEPOINT wa_out_sync');
                    } catch (Throwable $ignored) {}
                }
            }
            
        } catch (Exception $e) {
            $errors[] = "Message $index: " . $e->getMessage();
        }
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    waJsonResponse(['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()], 500);
}

waJsonResponse([
    'success' => true,
    'inserted' => $inserted,
    'errors' => $errors
]);

/**
 * Automatically syncs an incoming WhatsApp message with the Admission Enquiries pipeline:
 *  - If an enquiry already exists for this phone or chat, appends to admission_enquiry_messages,
 *    updates follow-up state, and logs history.
 *  - If no enquiry exists and this is an individual incoming chat, automatically captures a new enquiry
 *    in admission_enquiries, sets next_followup_date to today, and links it to whatsapp_chats.
 */
function waPhoneIsLinkedSchoolLine(PDO $db, string $phone10): bool {
    if (strlen($phone10) < 10) {
        return false;
    }
    try {
        $stmt = $db->query("SELECT phone_number FROM whatsapp_sessions WHERE session_name = 'default' LIMIT 1");
        $digits = preg_replace('/\D/', '', (string)$stmt->fetchColumn());
        if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits)) {
            $digits = '91' . $digits;
        }
        return $digits !== '' && str_ends_with($digits, $phone10);
    } catch (Throwable $e) {
        return false;
    }
}

function waPhoneOnSchoolRoster(PDO $db, string $phone10): bool {
    if (strlen($phone10) < 10) {
        return false;
    }
    try {
        $stmt = $db->prepare("SELECT parent_phone FROM report_card WHERE parent_phone LIKE ? LIMIT 20");
        $stmt->execute(['%' . $phone10]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $digits = preg_replace('/\D/', '', (string)($row['parent_phone'] ?? ''));
            if (str_ends_with($digits, $phone10)) {
                return true;
            }
        }
    } catch (Throwable $e) {}
    try {
        $chk = $db->query("SHOW TABLES LIKE 'teacher_contacts'");
        if ($chk && $chk->fetchColumn()) {
            $teacher = $db->prepare("SELECT phone FROM teacher_contacts WHERE phone LIKE ? LIMIT 20");
            $teacher->execute(['%' . $phone10]);
            while ($row = $teacher->fetch(PDO::FETCH_ASSOC)) {
                $digits = preg_replace('/\D/', '', (string)($row['phone'] ?? ''));
                if (str_ends_with($digits, $phone10)) {
                    return true;
                }
            }
        }
    } catch (Throwable $e) {}
    return false;
}

function waMessageHasAdmissionIntent(string $body): bool {
    $text = mb_strtolower($body);
    if (preg_match('/\b(admissions?|admit|pravesh|dakhila|fees?|cost|charges|nursery|lkg|ukg|playgroup|kindergarten|pre-kg|prospectus|brochure|form|forms|vacancy|vacant|seats?|available|open|apply|application|registration|register|enrol|enroll|enrollment|enrolment|joining|join|visit|tour|appointment|meeting|principal|office|timings?|syllabus|curriculum|board|cbse|state|inquiry|inquiries|enquiry|enquiries)\b/u', $text)) {
        return true;
    }
    if (preg_match('/\b(class|standard|std|grade)\s*([0-9]{1,2}|i{1,3}|iv|vi{0,3}|ix|x|xi|xii|nursery|lkg|ukg)\b/u', $text)) {
        return true;
    }
    if (preg_match('/\b([0-9]{1,2}(?:st|nd|rd|th)?|nursery|lkg|ukg)\s*(class|standard|std|grade)\b/u', $text)) {
        return true;
    }
    return false;
}

function waInboundIsBlankSync(array $msg, string $bodyText): bool {
    $msgType = strtolower((string)($msg['msg_type'] ?? 'text'));
    $meta = $msg['metadata'] ?? [];
    if (is_string($meta)) {
        $decoded = json_decode($meta, true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $waMsgId = (string)($msg['wa_msg_id'] ?? '');
    $isSync = $msgType === 'system' || !empty($meta['sync']) || str_starts_with($waMsgId, 'chat_sync_');
    $hasMedia = !empty($msg['media_url']) || in_array($msgType, ['image', 'video', 'audio', 'document', 'sticker'], true);
    return $isSync || ($bodyText === '' && !$hasMedia);
}

function waSyncInboundAdmissionEnquiry(PDO $db, array $msg, int $chatId, ?int $senderId, string $waChatId, string $chatType): ?int {
    if (!empty($msg['from_me']) || $chatType !== 'individual') {
        return null;
    }

    $bodyText = trim((string)($msg['body'] ?? ''));
    if (waInboundIsBlankSync($msg, $bodyText)) {
        return null;
    }
    $rawDigitsEarly = preg_replace('/\D/', '', $waChatId);
    $linkedTail = strlen($rawDigitsEarly) > 10 ? substr($rawDigitsEarly, -10) : $rawDigitsEarly;
    if (waPhoneIsLinkedSchoolLine($db, $linkedTail)) {
        return null;
    }
    $rawDigits = preg_replace('/\D/', '', $waChatId);
    if (str_starts_with($rawDigits, '0')) {
        $rawDigits = substr($rawDigits, 1);
    }
    if (strlen($rawDigits) === 10 && preg_match('/^[6-9]/', $rawDigits)) {
        $rawDigits = '91' . $rawDigits;
    }
    if (strlen($rawDigits) < 10) {
        return null;
    }

    $phoneE164 = '+' . $rawDigits;
    $phone10 = strlen($rawDigits) > 10 ? substr($rawDigits, -10) : $rawDigits;
    $formattedPhone = '+' . substr($rawDigits, 0, 2) . ' ' . substr($rawDigits, 2, 5) . ' ' . substr($rawDigits, 7);

    $parentName = trim((string)($msg['sender_name'] ?? ($msg['sender_push_name'] ?? '')));
    if ($parentName === '' || strtolower($parentName) === 'you') {
        $parentName = 'Parent (' . $phone10 . ')';
    }

    // 1. Check if an active enquiry already exists
    $enqStmt = $db->prepare("
        SELECT id, parent_name, student_name, interested_class, academic_year, status, followup_category, chat_id
        FROM admission_enquiries
        WHERE chat_id = ? 
           OR phone_e164 = ? 
           OR phone_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $enqStmt->execute([$chatId, $phoneE164, "%{$phone10}%"]);
    $existing = $enqStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $enquiryId = (int)$existing['id'];
        $waMsgId = (string)($msg['wa_msg_id'] ?? '');
        if ($waMsgId !== '') {
            $byId = $db->prepare("
                SELECT id FROM admission_enquiry_messages
                WHERE enquiry_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.wa_msg_id')) = ?
                LIMIT 1
            ");
            $byId->execute([$enquiryId, $waMsgId]);
            if ($byId->fetchColumn()) {
                return $enquiryId;
            }
        }
        if ($bodyText !== '') {
            // Deduplicate only identical message body received within 30 seconds (network duplicate prevention).
            // Do NOT use a multi-hour interval which drops valid conversational replies like 'yes', 'ok', 'thanks'.
            $byBody = $db->prepare("
                SELECT id FROM admission_enquiry_messages
                WHERE enquiry_id = ? AND message_type = 'incoming_message' AND body = ?
                  AND created_at >= (NOW() - INTERVAL 30 SECOND)
                LIMIT 1
            ");
            $byBody->execute([$enquiryId, $bodyText]);
            if ($byBody->fetchColumn()) {
                return $enquiryId;
            }
        }

        $msgCreatedAt = !empty($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : date('Y-m-d H:i:s');

        // Append to conversation timeline
        $msgIns = $db->prepare("
            INSERT INTO admission_enquiry_messages
            (enquiry_id, message_type, body, admin_name, metadata, created_at)
            VALUES (?, 'incoming_message', ?, ?, ?, ?)
        ");
        $metaJson = json_encode([
            'wa_msg_id' => $msg['wa_msg_id'] ?? null,
            'chat_id' => $chatId,
            'timestamp' => (int)($msg['timestamp'] ?? time()),
            'source' => 'whatsapp_inbound'
        ], JSON_UNESCAPED_UNICODE);
        $msgIns->execute([
            $enquiryId,
            $bodyText !== '' ? $bodyText : '[Media / Attachment received on WhatsApp]',
            $parentName,
            $metaJson,
            $msgCreatedAt
        ]);

        // Update enquiry activity timestamp and reset reminder if it was previously reminded
        $upStmt = $db->prepare("
            UPDATE admission_enquiries
            SET updated_at = NOW(),
                chat_id = COALESCE(chat_id, ?),
                contact_id = COALESCE(contact_id, ?),
                next_followup_date = CASE
                    WHEN COALESCE(is_completed, 0) = 0 AND COALESCE(is_cancelled, 0) = 0 THEN CURDATE()
                    ELSE next_followup_date
                END,
                reminder_status = CASE 
                    WHEN reminder_status = 'reminded' THEN 'pending' 
                    ELSE reminder_status 
                END
            WHERE id = ?
        ");
        $upStmt->execute([$chatId, $senderId, $enquiryId]);

        // Log follow-up history entry
        $histStmt = $db->prepare("
            INSERT INTO admission_followup_history
            (enquiry_id, action, title, details, performed_by, manual_sent_confirmed, delivery_verified, created_at)
            VALUES (?, 'note_added', 'Parent WhatsApp Message Received', ?, 'WhatsApp Ingest', 0, 0, ?)
        ");
        $snippet = $bodyText !== '' ? (mb_substr($bodyText, 0, 180) . (mb_strlen($bodyText) > 180 ? '...' : '')) : 'Media / Attachment received.';
        $histStmt->execute([$enquiryId, $snippet, $msgCreatedAt]);

        // Ensure whatsapp_chats is linked
        try {
            $db->prepare("
                UPDATE whatsapp_chats 
                SET is_admission_enquiry = 1,
                    metadata = JSON_SET(COALESCE(metadata, '{}'), '$.enquiry_id', ?)
                WHERE id = ?
            ")->execute([$enquiryId, $chatId]);
        } catch (Throwable $ignored) {}

        return $enquiryId;
    }

    // A roster parent who only says "ok" stays in the WhatsApp hub.
    // An unknown number, or a roster number asking about admission, becomes an enquiry.
    if (waPhoneOnSchoolRoster($db, $phone10) && !waMessageHasAdmissionIntent($bodyText)) {
        return null;
    }

    // 2. New Enquiry Auto-Capture
    // Detect grade / class interest from message text
    $detectedClass = 'General Enquiry';
    $gradePatterns = [
        '/\b(nursery|playgroup|pre-kg)\b/i' => 'Nursery',
        '/\b(lkg|junior kg|jr kg)\b/i' => 'LKG',
        '/\b(ukg|senior kg|sr kg)\b/i' => 'UKG',
        '/\b(1st|first|std 1|class 1|grade 1)\b/i' => 'STD I',
        '/\b(2nd|second|std 2|class 2|grade 2)\b/i' => 'STD II',
        '/\b(3rd|third|std 3|class 3|grade 3)\b/i' => 'STD III',
        '/\b(4th|fourth|std 4|class 4|grade 4)\b/i' => 'STD IV',
        '/\b(5th|fifth|std 5|class 5|grade 5)\b/i' => 'STD V',
        '/\b(6th|sixth|std 6|class 6|grade 6)\b/i' => 'STD VI',
        '/\b(7th|seventh|std 7|class 7|grade 7)\b/i' => 'STD VII',
        '/\b(8th|eighth|std 8|class 8|grade 8)\b/i' => 'STD VIII',
        '/\b(9th|ninth|std 9|class 9|grade 9)\b/i' => 'STD IX',
        '/\b(10th|tenth|std 10|class 10|grade 10)\b/i' => 'STD X',
        '/\b(11th|eleventh|std 11|class 11|grade 11|jr college|fyjc)\b/i' => 'STD XI',
        '/\b(12th|twelfth|std 12|class 12|grade 12|syjc)\b/i' => 'STD XII',
    ];
    foreach ($gradePatterns as $pat => $cls) {
        if (preg_match($pat, $bodyText)) {
            $detectedClass = $cls;
            break;
        }
    }

    // Check report_card for existing parent match
    $matchedParent = 0;
    $matchedRoll = null;
    $matchedClass = null;
    try {
        $rcStmt = $db->prepare("
            SELECT student_name, roll_no, std, division, academic_year 
            FROM report_card 
            WHERE parent_phone LIKE ? 
            LIMIT 1
        ");
        $rcStmt->execute(["%{$phone10}%"]);
        $rcMatch = $rcStmt->fetch(PDO::FETCH_ASSOC);
        if ($rcMatch) {
            $matchedParent = 1;
            $matchedRoll = $rcMatch['roll_no'];
            $matchedClass = 'STD ' . $rcMatch['std'] . ' ' . $rcMatch['division'];
        }
    } catch (Throwable $ignored) {}

    // Academic Year
    $academicYear = '2026-27';
    try {
        if (function_exists('getAllSettings')) {
            $st = getAllSettings();
            if (!empty($st['academic_year'])) $academicYear = $st['academic_year'];
        }
    } catch (Throwable $ignored) {}

    $suggestedMsg = "Namaste {$parentName}, thank you for contacting Hindustani Covenant Church High School. We would be pleased to assist you with admission details for {$detectedClass}. Could you please share the student's full name and current school?";

    $newEnqSql = "
        INSERT INTO admission_enquiries (
            parent_name, phone_number, phone_e164, student_name, interested_class, academic_year,
            original_message, enquiry_source, status, followup_category,
            next_followup_date, next_followup_time, priority, reminder_status,
            suggested_message, next_recommended_action,
            chat_id, contact_id, matched_student_roll, matched_student_class, matched_existing_parent,
            created_by_name, created_at, updated_at
        ) VALUES (
            ?, ?, ?, NULL, ?, ?,
            ?, 'whatsapp', 'NEW', 'First response pending',
            CURDATE(), '10:00:00', 'high', 'pending',
            ?, 'Review WhatsApp enquiry, share prospectus and schedule interaction.',
            ?, ?, ?, ?, ?,
            'WhatsApp Automation', NOW(), NOW()
        )
    ";
    $newStmt = $db->prepare($newEnqSql);
    $newStmt->execute([
        $parentName, $formattedPhone, $phoneE164, $detectedClass, $academicYear,
        $bodyText !== '' ? $bodyText : '[WhatsApp Inbound Enquiry]',
        $suggestedMsg,
        $chatId, $senderId, $matchedRoll, $matchedClass, $matchedParent
    ]);
    $newEnquiryId = (int)$db->lastInsertId();

    $msgCreatedAt = !empty($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : date('Y-m-d H:i:s');

    // Insert into admission_enquiry_messages
    $db->prepare("
        INSERT INTO admission_enquiry_messages
        (enquiry_id, message_type, body, admin_name, metadata, created_at)
        VALUES (?, 'incoming_message', ?, ?, ?, ?)
    ")->execute([
        $newEnquiryId,
        $bodyText !== '' ? $bodyText : '[WhatsApp Inbound Enquiry]',
        $parentName,
        json_encode([
            'source' => 'whatsapp',
            'phone' => $phoneE164,
            'timestamp' => (int)($msg['timestamp'] ?? time()),
            'wa_msg_id' => $msg['wa_msg_id'] ?? null
        ], JSON_UNESCAPED_UNICODE),
        $msgCreatedAt
    ]);

    // Insert into admission_followup_history
    $db->prepare("
        INSERT INTO admission_followup_history
        (enquiry_id, action, title, details, performed_by, manual_sent_confirmed, delivery_verified, created_at)
        VALUES (?, 'created', 'New WhatsApp Admission Enquiry Captured', ?, 'WhatsApp Automation', 0, 0, ?)
    ")->execute([
        $newEnquiryId,
        "Enquiry captured automatically from incoming WhatsApp message. Class: {$detectedClass}. Follow-up scheduled for today.",
        $msgCreatedAt
    ]);

    // Link chat metadata
    try {
        $db->prepare("
            UPDATE whatsapp_chats 
            SET is_admission_enquiry = 1,
                metadata = JSON_SET(COALESCE(metadata, '{}'), '$.enquiry_id', ?)
            WHERE id = ?
        ")->execute([$newEnquiryId, $chatId]);
    } catch (Throwable $ignored) {}

    return $newEnquiryId;
}

/**
 * Sync outbound WhatsApp messages sent by school staff into the admission enquiry timeline.
 * Preserves two-way conversations so staff can see parent queries and staff replies together.
 */
function waSyncOutboundAdmissionEnquiry(PDO $db, array $msg, int $chatId, string $waChatId): void {
    $bodyText = trim((string)($msg['body'] ?? ''));
    if ($bodyText === '' || waInboundIsBlankSync($msg, $bodyText)) {
        return;
    }
    $rawDigits = preg_replace('/\D/', '', $waChatId);
    $phone10 = strlen($rawDigits) > 10 ? substr($rawDigits, -10) : $rawDigits;
    $phoneE164 = '+' . $rawDigits;

    // Check if an active enquiry exists for this chat or phone
    $enqStmt = $db->prepare("
        SELECT id, status FROM admission_enquiries
        WHERE chat_id = ? OR phone_e164 = ? OR phone_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $enqStmt->execute([$chatId, $phoneE164, "%{$phone10}%"]);
    $existing = $enqStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return;
    }

    $enquiryId = (int)$existing['id'];
    $waMsgId = (string)($msg['wa_msg_id'] ?? '');

    // Check if already in timeline by wa_msg_id
    if ($waMsgId !== '') {
        $byId = $db->prepare("
            SELECT id FROM admission_enquiry_messages
            WHERE enquiry_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.wa_msg_id')) = ?
            LIMIT 1
        ");
        $byId->execute([$enquiryId, $waMsgId]);
        if ($byId->fetchColumn()) {
            return;
        }
    }

    // Check if duplicate outgoing body within 30 seconds
    $byBody = $db->prepare("
        SELECT id FROM admission_enquiry_messages
        WHERE enquiry_id = ? AND (message_type = 'outgoing_message' OR message_type = 'approved_response')
          AND body = ? AND created_at >= (NOW() - INTERVAL 30 SECOND)
        LIMIT 1
    ");
    $byBody->execute([$enquiryId, $bodyText]);
    if ($byBody->fetchColumn()) {
        return;
    }

    $senderName = trim((string)($msg['sender_name'] ?? 'School Staff'));
    if ($senderName === '' || strtolower($senderName) === 'you') {
        $senderName = 'School Staff';
    }
    $msgCreatedAt = !empty($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : date('Y-m-d H:i:s');

    $msgIns = $db->prepare("
        INSERT INTO admission_enquiry_messages
        (enquiry_id, message_type, body, admin_name, metadata, created_at)
        VALUES (?, 'outgoing_message', ?, ?, ?, ?)
    ");
    $msgIns->execute([
        $enquiryId,
        $bodyText,
        $senderName,
        json_encode([
            'wa_msg_id' => $waMsgId,
            'chat_id' => $chatId,
            'source' => 'whatsapp_outbound',
            'timestamp' => (int)($msg['timestamp'] ?? time())
        ], JSON_UNESCAPED_UNICODE),
        $msgCreatedAt
    ]);

    // Update enquiry activity timestamp
    $db->prepare("UPDATE admission_enquiries SET updated_at = NOW(), chat_id = COALESCE(chat_id, ?) WHERE id = ?")
       ->execute([$chatId, $enquiryId]);
}
