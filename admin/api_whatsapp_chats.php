<?php
/**
 * HCC WhatsApp Replica — Chat List API
 * GET /admin/api_whatsapp_chats.php?page=1&per_page=50&filter=all&search=&archived=false
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();

// Handle Chat Creation / Retrieval via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    waVerifyCSRF(is_array($input) ? $input : null);
    
    $chatType   = $input['chat_type'] ?? 'individual';
    $rawPhone   = trim((string)($input['phone'] ?? ($input['wa_chat_id'] ?? '')));
    $name       = trim((string)($input['name'] ?? ''));
    $roll       = trim((string)($input['student_roll'] ?? ($input['roll_no'] ?? '')));
    $classKey   = trim((string)($input['class_key'] ?? ''));
    $avatarUrl  = trim((string)($input['avatar_url'] ?? ''));
    $seedMsg    = trim((string)($input['seed_message'] ?? ''));
    $metadata   = $input['metadata'] ?? [];
    if (is_string($metadata)) {
        $metadata = json_decode($metadata, true) ?: [];
    }

    $chatId = null;
    $isNew = false;
    $phoneE164 = null;

    // Only placeholder ids. Never delete a chat that already has messages.
    try {
        $db->exec("
            DELETE c FROM whatsapp_chats c
            LEFT JOIN whatsapp_messages m ON m.chat_id = c.id
            WHERE c.wa_chat_id IN ('', '@c.us')
              AND m.id IS NULL
        ");
        $db->exec("DELETE FROM whatsapp_contacts WHERE wa_id IN ('', '@c.us')");
    } catch (Throwable $e) {}

    if ($chatType === 'individual') {
        $checked = waValidateWhatsAppPhone($rawPhone);
        if (!$checked['ok']) {
            waJsonResponse(['success' => false, 'error' => $checked['error']], 422);
        }
        $cleanDigits = $checked['digits'];
        $phoneE164 = $checked['e164'];
        $waChatId = $cleanDigits . '@c.us';
        $providedName = $name;

        if ($name === '') {
            $cCheck = $db->prepare("SELECT name FROM whatsapp_contacts WHERE wa_id = ? OR phone_e164 = ? LIMIT 1");
            $cCheck->execute([$waChatId, $phoneE164]);
            $foundName = $cCheck->fetchColumn();
            $name = ($foundName !== false && $foundName !== null && $foundName !== '')
                ? (string)$foundName
                : ('Contact +' . $cleanDigits);
        }

        if ($avatarUrl === '') {
            $avatarUrl = null;
        }

        try {
            $cStmt = $db->prepare("
                INSERT INTO whatsapp_contacts
                (wa_id, phone_e164, name, push_name, avatar_url, source, linked_student_roll, linked_class_key, updated_at)
                VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    phone_e164 = VALUES(phone_e164),
                    name = COALESCE(NULLIF(?, ''), name),
                    push_name = COALESCE(NULLIF(?, ''), push_name),
                    updated_at = NOW()
            ");
            $cStmt->execute([
                $waChatId,
                $phoneE164,
                $name,
                $name,
                $avatarUrl,
                $roll !== '' ? $roll : null,
                $classKey !== '' ? $classKey : null,
                $providedName,
                $providedName,
            ]);
        } catch (Throwable $e) {}

        $chStmt = $db->prepare("SELECT id FROM whatsapp_chats WHERE wa_chat_id = ?");
        $chStmt->execute([$waChatId]);
        $existingId = $chStmt->fetchColumn();

        if ($existingId) {
            $chatId = (int)$existingId;
            $db->prepare("UPDATE whatsapp_chats SET last_activity_at = NOW(), is_archived = 0 WHERE id = ?")->execute([$chatId]);
        } else {
            $isNew = true;
            if ($roll !== '') $metadata['student_roll'] = $roll;
            if ($classKey !== '') $metadata['class_key'] = $classKey;
            $metadata['parent_phone'] = $phoneE164;
            $metadata['student_name'] = $name;
            $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE);

            $ins = $db->prepare("
                INSERT INTO whatsapp_chats
                (wa_chat_id, chat_type, name, avatar_url, is_pinned, unread_count, metadata, linked_student_roll, linked_class_key, last_activity_at, created_at, updated_at)
                VALUES (?, 'individual', ?, ?, 0, 0, ?, ?, ?, NOW(), NOW(), NOW())
            ");
            try {
                $ins->execute([
                    $waChatId,
                    $name,
                    $avatarUrl,
                    $metaJson,
                    $roll !== '' ? $roll : null,
                    $classKey !== '' ? $classKey : null,
                ]);
                $chatId = (int)$db->lastInsertId();
            } catch (PDOException $e) {
                if ((string)$e->getCode() !== '23000') {
                    throw $e;
                }
                $chStmt->execute([$waChatId]);
                $chatId = (int)$chStmt->fetchColumn();
                if (!$chatId) {
                    throw $e;
                }
                $isNew = false;
                $db->prepare("UPDATE whatsapp_chats SET last_activity_at = NOW(), is_archived = 0 WHERE id = ?")->execute([$chatId]);
            }
        }
    } else {
        // Group or Broadcast Channel
        if ($name === '') {
            $name = $chatType === 'broadcast' ? "HCC Announcements Channel" : "Class Parents Group";
        }
        $groupKey = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($name));
        $waChatId = ($chatType === 'broadcast' ? 'channel_' : 'group_') . $groupKey . '_' . substr(md5(uniqid()), 0, 6) . '@g.us';
        if ($avatarUrl === '') {
            $avatarUrl = '../assets/images/hcclogo.jpg';
        }

        $metadata['is_group'] = true;
        $metadata['chat_type'] = $chatType;
        if ($classKey) $metadata['class_key'] = $classKey;
        $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE);

        $ins = $db->prepare("
            INSERT INTO whatsapp_chats 
            (wa_chat_id, chat_type, name, avatar_url, is_pinned, unread_count, metadata, linked_class_key, last_activity_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, 0, ?, ?, NOW(), NOW(), NOW())
        ");
        $ins->execute([$waChatId, $chatType, $name, $avatarUrl, $metaJson, $classKey ?: null]);
        $chatId = (int)$db->lastInsertId();
        $isNew = true;

        $initBody = $seedMsg ?: ($chatType === 'broadcast' 
            ? "📢 Welcome to {$name}. Official school announcements and events will be posted here."
            : "👥 Welcome to {$name}. This official group is for parent updates and school communication.");

        waInsertMessage($db, [
            'wa_msg_id'     => 'grp_' . time() . '_' . $chatId,
            'chat_id'       => $chatId,
            'from_me'       => true,
            'sender_id'     => 'principal@hcc.edu',
            'sender_name'   => 'HCC Administration',
            'sender_avatar' => '../assets/images/hcclogo.jpg',
            'msg_type'      => 'text',
            'body'          => $initBody,
            'status'        => 'delivered',
            'timestamp'     => time()
        ]);
    }

    // Fetch full chat representation
    $cStmt = $db->prepare("
        SELECT 
            c.id, c.wa_chat_id, c.chat_type, c.name, c.avatar_url,
            c.is_archived, c.is_pinned, c.is_muted, c.mute_until,
            c.unread_count, c.last_activity_at, c.metadata, c.linked_student_roll, c.linked_class_key,
            m.wa_msg_id as last_msg_id, m.body as last_msg_body, m.msg_type as last_msg_type,
            m.from_me as last_msg_from_me, m.timestamp as last_msg_timestamp,
            m.status as last_msg_status
        FROM whatsapp_chats c
        LEFT JOIN whatsapp_messages m ON c.last_message_id = m.id
        WHERE c.id = ?
    ");
    $cStmt->execute([$chatId]);
    $chatObj = $cStmt->fetch(PDO::FETCH_ASSOC);

    if ($chatObj) {
        $chatObj['id'] = (int)$chatObj['id'];
        if ($phoneE164 !== null) {
            $chatObj['phone_e164'] = $phoneE164;
        }
        if (!empty($chatObj['metadata']) && is_string($chatObj['metadata'])) {
            $chatObj['metadata'] = json_decode($chatObj['metadata'], true) ?: [];
        }
        $chatObj['last_message'] = $chatObj['last_msg_id'] ? [
            'wa_msg_id' => $chatObj['last_msg_id'],
            'body' => $chatObj['last_msg_body'],
            'type' => $chatObj['last_msg_type'],
            'from_me' => (bool)$chatObj['last_msg_from_me'],
            'timestamp' => (int)$chatObj['last_msg_timestamp'],
            'status' => $chatObj['last_msg_status']
        ] : null;
        unset($chatObj['last_msg_id'], $chatObj['last_msg_body'], $chatObj['last_msg_type'], 
              $chatObj['last_msg_from_me'], $chatObj['last_msg_timestamp'], $chatObj['last_msg_status']);

        if ($chatObj['chat_type'] === 'individual') {
            $contStmt = $db->prepare("SELECT id, wa_id, name, push_name, avatar_url, last_seen_at, phone_e164 FROM whatsapp_contacts WHERE wa_id = ?");
            $contStmt->execute([$chatObj['wa_chat_id']]);
            $cont = $contStmt->fetch(PDO::FETCH_ASSOC);
            if ($cont) $chatObj['contact'] = $cont;
        }
    }

    waJsonResponse([
        'success' => true,
        'created' => $isNew,
        'chat'    => $chatObj,
        'data'    => $chatObj
    ]);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(max(1, (int)($_GET['per_page'] ?? 50)), 100);
$filter = $_GET['filter'] ?? 'all'; // all, unread, groups, archived
$search = trim($_GET['search'] ?? '');
$archived = ($_GET['archived'] ?? 'false') === 'true';

$where = ['1=1'];
$params = [];

if ($archived) {
    $where[] = 'c.is_archived = 1';
} else {
    $where[] = 'c.is_archived = 0';
}

if ($filter === 'unread') {
    $where[] = 'c.unread_count > 0';
} elseif ($filter === 'groups') {
    $where[] = "c.chat_type IN ('group','broadcast')";
} elseif ($filter === 'pinned') {
    $where[] = 'c.is_pinned = 1';
} elseif ($filter === 'enquiries') {
    $where[] = "(c.is_admission_enquiry = 1 OR JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.is_enquiry')) = 'true' OR JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.enquiry_id')) IS NOT NULL)";
} elseif ($filter === 'starred') {
    $where[] = "EXISTS (SELECT 1 FROM whatsapp_messages m_star WHERE m_star.chat_id = c.id AND m_star.is_starred = 1)";
}

if ($search !== '') {
    $where[] = '(c.name LIKE ? OR c.wa_chat_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = implode(' AND ', $where);

// Get chats with last message preview
$countSql = "SELECT COUNT(*) FROM whatsapp_chats c WHERE $whereSql";
$dataSql = "
    SELECT 
        c.id, c.wa_chat_id, c.wa_lid, c.chat_type, c.name, c.avatar_url,
        c.is_archived, c.is_pinned, c.is_muted, c.mute_until, c.is_admission_enquiry,
        EXISTS (SELECT 1 FROM whatsapp_messages m_star WHERE m_star.chat_id = c.id AND m_star.is_starred = 1) AS has_starred,
        c.unread_count, c.last_activity_at, c.metadata, c.linked_student_roll, c.linked_class_key,
        m.wa_msg_id as last_msg_id, m.body as last_msg_body, m.msg_type as last_msg_type,
        m.from_me as last_msg_from_me, m.timestamp as last_msg_timestamp,
        m.status as last_msg_status
    FROM whatsapp_chats c
    LEFT JOIN whatsapp_messages m ON c.last_message_id = m.id
    WHERE $whereSql
    ORDER BY c.is_pinned DESC, c.last_activity_at DESC
    LIMIT ? OFFSET ?
";

$result = waPaginate($db, $countSql, $dataSql, $params, $page, $perPage);

// Format for frontend
foreach ($result['items'] as &$chat) {
    if (!empty($chat['metadata']) && is_string($chat['metadata'])) {
        $chat['metadata'] = json_decode($chat['metadata'], true) ?: [];
    }
    $chat['last_message'] = $chat['last_msg_id'] ? [
        'wa_msg_id' => $chat['last_msg_id'],
        'body' => $chat['last_msg_body'],
        'type' => $chat['last_msg_type'],
        'from_me' => (bool)$chat['last_msg_from_me'],
        'timestamp' => (int)$chat['last_msg_timestamp'],
        'status' => $chat['last_msg_status']
    ] : null;
    unset($chat['last_msg_id'], $chat['last_msg_body'], $chat['last_msg_type'], 
          $chat['last_msg_from_me'], $chat['last_msg_timestamp'], $chat['last_msg_status']);
    
    // Add contact info for individual chats
    if ($chat['chat_type'] === 'individual') {
        $stmt = $db->prepare("
            SELECT c.id, c.wa_id, c.name, c.push_name, c.avatar_url, c.last_seen_at
            FROM whatsapp_contacts c
            JOIN whatsapp_chats ch ON ch.wa_chat_id = c.wa_id
            WHERE ch.id = ?
        ");
        $stmt->execute([$chat['id']]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($contact) {
            $chat['contact'] = $contact;
        }
    }

    // Add admission enquiry info if linked
    if (!empty($chat['metadata']) && !empty($chat['metadata']['enquiry_id'])) {
        $enqStmt = $db->prepare("SELECT id, status, interested_class, academic_year, student_name, parent_name, assigned_staff_name FROM admission_enquiries WHERE id = ?");
        $enqStmt->execute([(int)$chat['metadata']['enquiry_id']]);
        $enqData = $enqStmt->fetch(PDO::FETCH_ASSOC);
        if ($enqData) {
            $chat['enquiry'] = $enqData;
        }
    }

    // Attach latest admission AI draft if present
    $aiStmt = $db->prepare("
        SELECT id, intent, confidence, admin_status, escalation_required, created_at 
        FROM admission_ai_drafts 
        WHERE chat_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $aiStmt->execute([$chat['id']]);
    $aiDraft = $aiStmt->fetch(PDO::FETCH_ASSOC);
    if ($aiDraft) {
        $chat['ai_draft'] = $aiDraft;
    }
}

waJsonResponse([
    'success' => true,
    'data' => $result['items'],
    'pagination' => $result['pagination']
]);
