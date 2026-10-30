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

    // Clean up any corrupted '@c.us' or empty wa_chat_id rows from prior bugs
    try {
        $db->exec("DELETE FROM whatsapp_chats WHERE wa_chat_id = '@c.us' OR wa_chat_id = '' OR wa_chat_id IS NULL");
        $db->exec("DELETE FROM whatsapp_contacts WHERE wa_id = '@c.us' OR wa_id = '' OR wa_id IS NULL");
    } catch (Throwable $e) {}

    if ($chatType === 'individual') {
        $cleanDigits = preg_replace('/\D/', '', $rawPhone);

        // If no phone digits provided or phone looks like a name, try finding student in DB
        if (strlen($cleanDigits) < 5) {
            $searchQuery = $name !== '' ? $name : $rawPhone;
            $matchedStu = null;
            if ($searchQuery !== '') {
                $stuStmt = $db->prepare("
                    SELECT roll_no, student_name, parent_phone, std, division, academic_year, semester
                    FROM report_card
                    WHERE student_name LIKE ? OR roll_no = ? OR parent_phone LIKE ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stuStmt->execute(["%$searchQuery%", $searchQuery, "%$searchQuery%"]);
                $matchedStu = $stuStmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($matchedStu) {
                if ($name === '') $name = $matchedStu['student_name'];
                if ($roll === '') $roll = (string)$matchedStu['roll_no'];
                if ($classKey === '') $classKey = "{$matchedStu['academic_year']}|{$matchedStu['std']}|{$matchedStu['division']}|{$matchedStu['semester']}";
                $stuPhone = preg_replace('/\D/', '', (string)($matchedStu['parent_phone'] ?? ''));
                if (strlen($stuPhone) >= 10) {
                    $cleanDigits = $stuPhone;
                } else {
                    $cleanDigits = '98765' . str_pad($roll ?: '00001', 5, '0', STR_PAD_LEFT);
                }
            } else {
                // Check in whatsapp_contacts
                $cMatch = null;
                if ($searchQuery !== '') {
                    $cStmt = $db->prepare("SELECT wa_id, phone_e164, name, linked_student_roll, linked_class_key FROM whatsapp_contacts WHERE name LIKE ? OR wa_id = ? OR phone_e164 LIKE ? LIMIT 1");
                    $cStmt->execute(["%$searchQuery%", $searchQuery, "%$searchQuery%"]);
                    $cMatch = $cStmt->fetch(PDO::FETCH_ASSOC);
                }
                if ($cMatch) {
                    $name = $cMatch['name'];
                    $cleanDigits = preg_replace('/\D/', '', $cMatch['phone_e164'] ?: $cMatch['wa_id']);
                    if ($roll === '') $roll = $cMatch['linked_student_roll'] ?? '';
                    if ($classKey === '') $classKey = $cMatch['linked_class_key'] ?? '';
                } else {
                    if ($name === '') $name = $rawPhone !== '' ? $rawPhone : 'Direct Contact';
                    $hashNum = abs(crc32($name)) % 90000 + 10000;
                    $cleanDigits = '98765' . $hashNum;
                }
            }
        }

        if (str_starts_with($cleanDigits, '0')) $cleanDigits = substr($cleanDigits, 1);
        if (strlen($cleanDigits) === 10) {
            $cleanDigits = '91' . $cleanDigits;
        } elseif (strlen($cleanDigits) < 10) {
            $cleanDigits = '9198765' . str_pad($cleanDigits, 5, '0', STR_PAD_LEFT);
        }

        $waChatId = $cleanDigits . '@c.us';
        $phoneE164 = '+' . $cleanDigits;

        // If name wasn't provided, try resolving from contacts or cleanDigits
        if ($name === '') {
            $cCheck = $db->prepare("SELECT name, linked_student_roll, linked_class_key FROM whatsapp_contacts WHERE wa_id = ? OR phone_e164 = ? LIMIT 1");
            $cCheck->execute([$waChatId, $phoneE164]);
            $foundC = $cCheck->fetch(PDO::FETCH_ASSOC);
            if ($foundC && !empty($foundC['name'])) {
                $name = $foundC['name'];
                if ($roll === '' && !empty($foundC['linked_student_roll'])) $roll = $foundC['linked_student_roll'];
                if ($classKey === '' && !empty($foundC['linked_class_key'])) $classKey = $foundC['linked_class_key'];
            } else {
                $name = "Contact +" . $cleanDigits;
            }
        }

        if ($avatarUrl === '') {
            $avatarUrl = "https://api.dicebear.com/7.x/initials/svg?seed=" . urlencode($name);
        }

        // Upsert contact in whatsapp_contacts
        try {
            $cStmt = $db->prepare("
                INSERT INTO whatsapp_contacts 
                (wa_id, phone_e164, name, push_name, avatar_url, source, linked_student_roll, linked_class_key, updated_at)
                VALUES (?, ?, ?, ?, ?, 'direct_chat', ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    name = COALESCE(NULLIF(VALUES(name), ''), name),
                    updated_at = NOW()
            ");
            $cStmt->execute([$waChatId, $phoneE164, $name, $name, $avatarUrl, $roll ?: null, $classKey ?: null]);
        } catch (Throwable $e) {}

        // Check if chat exists
        $chStmt = $db->prepare("SELECT id FROM whatsapp_chats WHERE wa_chat_id = ?");
        $chStmt->execute([$waChatId]);
        $existingId = $chStmt->fetchColumn();

        if ($existingId) {
            $chatId = (int)$existingId;
            $db->prepare("UPDATE whatsapp_chats SET last_activity_at = NOW(), is_archived = 0 WHERE id = ?")->execute([$chatId]);
        } else {
            $isNew = true;
            if ($roll) $metadata['student_roll'] = $roll;
            if ($classKey) $metadata['class_key'] = $classKey;
            $metadata['parent_phone'] = $phoneE164;
            $metadata['student_name'] = $name;
            $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE);

            $ins = $db->prepare("
                INSERT INTO whatsapp_chats 
                (wa_chat_id, chat_type, name, avatar_url, is_pinned, unread_count, metadata, linked_student_roll, linked_class_key, last_activity_at, created_at, updated_at)
                VALUES (?, 'individual', ?, ?, 0, 0, ?, ?, ?, NOW(), NOW(), NOW())
            ");
            $ins->execute([$waChatId, $name, $avatarUrl, $metaJson, $roll ?: null, $classKey ?: null]);
            $chatId = (int)$db->lastInsertId();

            // Insert initial welcome message so conversation thread is immediately active
            $initialText = $seedMsg ?: "Dear Parent, welcome to HCC WhatsApp Communication Portal for {$name}. Academic notices, report cards, and school communications will be shared here.";
            waInsertMessage($db, [
                'wa_msg_id'     => 'init_' . time() . '_' . $chatId,
                'chat_id'       => $chatId,
                'from_me'       => true,
                'sender_id'     => 'admin@hcc.edu',
                'sender_name'   => 'HCC High School',
                'sender_avatar' => '../assets/images/hcclogo.jpg',
                'msg_type'      => 'text',
                'body'          => $initialText,
                'status'        => 'delivered',
                'timestamp'     => time()
            ]);
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
    $where[] = 'is_archived = 1';
} else {
    $where[] = 'is_archived = 0';
}

if ($filter === 'unread') {
    $where[] = 'unread_count > 0';
} elseif ($filter === 'groups') {
    $where[] = "chat_type IN ('group','broadcast')";
} elseif ($filter === 'pinned') {
    $where[] = 'is_pinned = 1';
}

if ($search !== '') {
    $where[] = '(name LIKE ? OR wa_chat_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = implode(' AND ', $where);

// Get chats with last message preview
$countSql = "SELECT COUNT(*) FROM whatsapp_chats WHERE $whereSql";
$dataSql = "
    SELECT 
        c.id, c.wa_chat_id, c.chat_type, c.name, c.avatar_url,
        c.is_archived, c.is_pinned, c.is_muted, c.mute_until,
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
}

waJsonResponse([
    'success' => true,
    'data' => $result['items'],
    'pagination' => $result['pagination']
]);
