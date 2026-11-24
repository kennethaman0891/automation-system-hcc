<?php
/**
 * HCC WhatsApp Replica — Shared Functions
 * Common helpers for all WhatsApp API endpoints
 */

if (!function_exists('waGetDB')) {
    function waGetDB(): PDO {
        return getDB();
    }
}

if (!function_exists('waBridgeIpcDir')) {
    /**
     * Directory the PHP app and whatsapp-bridge.js both use for QR, restart,
     * logout, and pairing flags. On Windows Apache and the bridge often run as
     * different users, so each user's temp folder is a different place. A
     * folder inside this project is visible to both. macOS and Linux keep
     * using /tmp, which is already shared.
     */
    function waBridgeIpcDir(): string {
        $fromEnv = getenv('WHATSAPP_IPC_DIR');
        if (is_string($fromEnv) && $fromEnv !== '' && is_dir($fromEnv)) {
            return rtrim($fromEnv, "/\\");
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'whatsapp' . DIRECTORY_SEPARATOR . 'ipc';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (is_dir($dir)) {
                return $dir;
            }
            return rtrim(sys_get_temp_dir(), "/\\");
        }
        if (is_dir('/tmp')) {
            return '/tmp';
        }
        return rtrim(sys_get_temp_dir(), "/\\");
    }
}

if (!function_exists('hccEnsureColumn')) {
    /**
     * Add a column on both MariaDB and MySQL. MariaDB accepts
     * ADD COLUMN IF NOT EXISTS; MySQL 8 rejects that statement.
     */
    function hccEnsureColumn(PDO $db, string $table, string $column, string $definition): void {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid SQL identifier');
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

if (!function_exists('hccEnsureIndex')) {
    function hccEnsureIndex(PDO $db, string $table, string $index, string $columnList): void {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $index)) {
            throw new InvalidArgumentException('Invalid SQL identifier');
        }
        if (!preg_match('/^[A-Za-z0-9_,` ]+$/', $columnList)) {
            throw new InvalidArgumentException('Invalid index column list');
        }
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([$index, $table]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $db->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (' . $columnList . ')');
    }
}

if (!function_exists('waRequireAuth')) {
    function waRequireAuth(): array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        // Real admin session only. No HTTP test-bypass headers or bridge-secret on send paths.
        // Offline tests set the session via the CLI test harness, never via HTTP flags.
        // Bridge secret is accepted only by waRequireBridgeOrAuth() on non-send APIs.
        if (!empty($GLOBALS['WA_AUTH_VIA_BRIDGE'])) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['success' => false, 'error' => 'Bridge cannot create sends — use the hub Send button']));
        }
        if (empty($_SESSION['admin_id'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['success' => false, 'error' => 'Unauthorized']));
        }
        return [
            'admin_id' => (int)$_SESSION['admin_id'],
            'admin_name' => $_SESSION['admin_name'] ?? 'Admin',
            'admin_role' => $_SESSION['admin_role'] ?? 'admin'
        ];
    }
}


if (!function_exists('waRequireBridgeOrAuth')) {
    /**
     * Bridge-facing endpoints: accept either a real admin session OR the
     * WHATSAPP_BRIDGE_SECRET via X-Bridge-Secret header. Never HTTP test-bypass headers.
     * Send endpoints must NOT use this — they require waRequireAuth() only.
     */
    function waRequireBridgeOrAuth(): array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $secret = defined('WHATSAPP_BRIDGE_SECRET') ? (string)WHATSAPP_BRIDGE_SECRET : '';
        $hdr = (string)($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
        if ($secret !== '' && strlen($secret) >= 16 && $hdr !== '' && hash_equals($secret, $hdr)) {
            return [
                'admin_id' => 0,
                'admin_name' => 'whatsapp-bridge',
                'admin_role' => 'bridge',
                'via' => 'bridge_secret',
            ];
        }
        return waRequireAuth() + ['via' => 'session'];
    }
}

if (!function_exists('waVerifyCSRF')) {
    /**
     * Validate CSRF token for session-authenticated mutating requests.
     * Reads X-CSRF-Token header, $_POST['csrf_token'], or optional JSON body token.
     */
    function waVerifyCSRF(?array $jsonBody = null): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $provided = (string)(
            $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf_token']
            ?? ($jsonBody['csrf_token'] ?? '')
        );
        if ($sessionToken === '' || $provided === '' || !hash_equals($sessionToken, $provided)) {
            waJsonResponse(['success' => false, 'error' => 'Invalid or missing CSRF token'], 403);
        }
    }
}

if (!function_exists('waJsonResponse')) {
    function waJsonResponse(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('waSanitize')) {
    function waSanitize(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('waValidateHMAC')) {
    function waValidateHMAC(string $payload, string $signature, string $secret): bool {
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}

if (!function_exists('waPhoneToWAID')) {
    function waPhoneToWAID(string $phone): string {
        // Normalize to WhatsApp ID format: 919876543210@c.us
        $clean = preg_replace('/\D/', '', $phone);
        if (str_starts_with($clean, '0')) $clean = substr($clean, 1);
        if (str_starts_with($clean, '91') && strlen($clean) === 12) {
            // Already has country code
        } elseif (strlen($clean) === 10) {
            $clean = '91' . $clean;
        }
        return $clean . '@c.us';
    }
}

if (!function_exists('waPhoneToE164')) {
    function waPhoneToE164(string $phone): string {
        $checked = waValidateWhatsAppPhone($phone);
        if ($checked['ok']) {
            return $checked['e164'];
        }
        $clean = preg_replace('/\D/', '', $phone);
        if (str_starts_with($clean, '0')) $clean = substr($clean, 1);
        if (str_starts_with($clean, '91') && strlen($clean) === 12) {
            return '+' . $clean;
        } elseif (strlen($clean) === 10) {
            return '+91' . $clean;
        }
        return '+' . $clean;
    }
}

if (!function_exists('waValidateWhatsAppPhone')) {
    /**
     * Validate a WhatsApp number and return digits for wa.me (no plus sign).
     * Accepts international numbers (8–15 digits) and 10-digit Indian mobiles.
     *
     * @return array{ok:bool,error:string,digits:string,e164:string}
     */
    function waValidateWhatsAppPhone(string $phone): array {
        $fail = [
            'ok' => false,
            'error' => 'Enter a valid WhatsApp number in international format, for example +91 98016 60000.',
            'digits' => '',
            'e164' => '',
        ];
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits !== '' && $digits[0] === '0') {
            $digits = ltrim($digits, '0');
        }
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return $fail;
        }
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return $fail;
        }
        if (str_starts_with($digits, '91') && strlen($digits) === 12 && !preg_match('/^91[6-9]\d{9}$/', $digits)) {
            return $fail;
        }
        return [
            'ok' => true,
            'error' => '',
            'digits' => $digits,
            'e164' => '+' . $digits,
        ];
    }
}

if (!function_exists('waValidatePhone')) {
    function waValidatePhone(string $phone): array {
        return waValidateWhatsAppPhone($phone);
    }
}

if (!function_exists('waGetOrCreateChat')) {
    function waGetOrCreateChat(PDO $db, string $waChatId, string $chatType = 'individual', ?string $name = null, ?string $avatar = null): int {
        $waLid = str_contains($waChatId, '@lid') ? $waChatId : null;
        $stmt = $db->prepare("
            INSERT INTO whatsapp_chats (wa_chat_id, wa_lid, chat_type, name, avatar_url, last_activity_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                wa_lid = COALESCE(wa_lid, VALUES(wa_lid)),
                name = COALESCE(NULLIF(VALUES(name), ''), name),
                avatar_url = COALESCE(NULLIF(VALUES(avatar_url), ''), avatar_url),
                last_activity_at = GREATEST(last_activity_at, NOW())
        ");
        $stmt->execute([$waChatId, $waLid, $chatType, $name, $avatar]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('waGetOrCreateContact')) {
    function waGetOrCreateContact(PDO $db, string $waId, ?string $phoneE164 = null, ?string $name = null, ?string $pushName = null, ?string $avatar = null, string $source = 'whatsapp'): int {
        $isLid = str_contains($waId, '@lid');
        $waLid = $isLid ? $waId : null;
        if ($isLid && $phoneE164 !== null) {
            $lidDigits = preg_replace('/\D/', '', $waId);
            $phoneDigits = preg_replace('/\D/', '', $phoneE164);
            if ($lidDigits !== '' && $phoneDigits === $lidDigits) {
                $phoneE164 = null;
            }
        }
        $stmt = $db->prepare("
            INSERT INTO whatsapp_contacts (wa_id, wa_lid, phone_e164, name, push_name, avatar_url, source)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                wa_lid = COALESCE(wa_lid, VALUES(wa_lid)),
                phone_e164 = COALESCE(NULLIF(VALUES(phone_e164), ''), phone_e164),
                name = COALESCE(NULLIF(VALUES(name), ''), name),
                push_name = COALESCE(NULLIF(VALUES(push_name), ''), push_name),
                avatar_url = COALESCE(NULLIF(VALUES(avatar_url), ''), avatar_url),
                updated_at = NOW()
        ");
        $stmt->execute([$waId, $waLid, $phoneE164, $name, $pushName, $avatar, $source]);
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('waSyncRosterToChats')) {
    function waSyncRosterToChats(PDO $db, string $academicYear, string $std, string $division, string $semester): array {
        // Ensure whatsapp_chats has the linked columns
        try {
            hccEnsureColumn($db, 'whatsapp_chats', 'linked_student_roll', 'VARCHAR(20) DEFAULT NULL');
            hccEnsureColumn($db, 'whatsapp_chats', 'linked_class_key', 'VARCHAR(100) DEFAULT NULL');
        } catch (Throwable $e) {
            // Ignore if already exists or permission issues
        }

        $classKey = "{$academicYear}|{$std}|{$division}|{$semester}";

        // Fetch unique students for this class from report_card
        $stmt = $db->prepare("
            SELECT 
                roll_no, 
                student_name, 
                MAX(NULLIF(parent_phone, '')) as parent_phone
            FROM report_card
            WHERE academic_year = ? AND std = ? AND division = ? AND semester = ?
            GROUP BY roll_no, student_name
            ORDER BY CAST(roll_no AS UNSIGNED), roll_no ASC
        ");
        $stmt->execute([$academicYear, $std, $division, $semester]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $syncedContacts = 0;
        $syncedChats = 0;
        $contactIds = [];

        $contactStmt = $db->prepare("
            INSERT INTO whatsapp_contacts 
                (wa_id, phone_e164, name, push_name, avatar_url, source, linked_student_roll, linked_class_key)
            VALUES 
                (?, ?, ?, ?, ?, 'student_link', ?, ?)
            ON DUPLICATE KEY UPDATE
                phone_e164 = VALUES(phone_e164),
                name = VALUES(name),
                push_name = VALUES(push_name),
                avatar_url = VALUES(avatar_url),
                source = 'student_link',
                linked_student_roll = VALUES(linked_student_roll),
                linked_class_key = VALUES(linked_class_key),
                updated_at = NOW()
        ");

        $chatStmt = $db->prepare("
            INSERT INTO whatsapp_chats 
                (wa_chat_id, chat_type, name, avatar_url, metadata, linked_student_roll, linked_class_key, last_activity_at)
            VALUES 
                (?, 'individual', ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                avatar_url = VALUES(avatar_url),
                metadata = VALUES(metadata),
                linked_student_roll = VALUES(linked_student_roll),
                linked_class_key = VALUES(linked_class_key),
                updated_at = NOW()
        ");

        foreach ($students as $stu) {
            $rollNo = trim((string)$stu['roll_no']);
            $studentName = trim((string)$stu['student_name']);
            $rawPhone = trim((string)($stu['parent_phone'] ?? ''));

            // Strict validation: never fabricate fake phone numbers or mutate report_card
            $vPhone = waValidateWhatsAppPhone($rawPhone);
            if (!$vPhone['ok']) {
                continue;
            }

            $cleanDigits = $vPhone['digits'];
            $waId = $cleanDigits . '@c.us';
            $phoneE164 = $vPhone['e164'];
            $displayName = $studentName;
            $pushName = 'Parent of ' . $studentName;
            // Local initials fallback rendered by UI; avoid external avatar APIs
            $avatarUrl = null;

            $metaJson = json_encode([
                'academic_year' => $academicYear,
                'std' => $std,
                'division' => $division,
                'semester' => $semester,
                'roll_no' => $rollNo,
                'student_name' => $studentName,
                'phone' => $cleanDigits
            ], JSON_UNESCAPED_UNICODE);

            // Upsert contact
            $contactStmt->execute([
                $waId,
                $phoneE164,
                $displayName,
                $pushName,
                $avatarUrl,
                $rollNo,
                $classKey
            ]);
            $syncedContacts++;

            // Upsert chat
            $chatStmt->execute([
                $waId,
                $displayName,
                $avatarUrl,
                $metaJson,
                $rollNo,
                $classKey
            ]);
            $syncedChats++;

            // Fetch contact ID
            $cIdStmt = $db->prepare("SELECT id FROM whatsapp_contacts WHERE wa_id = ?");
            $cIdStmt->execute([$waId]);
            $cId = $cIdStmt->fetchColumn();
            if ($cId) {
                $contactIds[] = (int)$cId;
            }
        }

        // Also create / update a class official group chat for convenient class announcements
        $cleanYear = preg_replace('/\W/', '', $academicYear);
        $groupChatId = sprintf("group_%s_std%s_%s@g.us", $cleanYear, $std, strtolower($division));
        $groupName = sprintf("STD %s-%s Official (%s)", $std, strtoupper($division), $academicYear);
        $groupAvatar = null;
        $groupMeta = json_encode([
            'academic_year' => $academicYear,
            'std' => $std,
            'division' => $division,
            'semester' => $semester,
            'is_class_group' => true
        ], JSON_UNESCAPED_UNICODE);

        $db->prepare("
            INSERT INTO whatsapp_chats 
                (wa_chat_id, chat_type, name, avatar_url, metadata, linked_class_key, last_activity_at)
            VALUES 
                (?, 'group', ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                avatar_url = VALUES(avatar_url),
                metadata = VALUES(metadata),
                linked_class_key = VALUES(linked_class_key),
                updated_at = NOW()
        ")->execute([$groupChatId, $groupName, $groupAvatar, $groupMeta, $classKey]);

        // Get group chat id
        $gcStmt = $db->prepare("SELECT id FROM whatsapp_chats WHERE wa_chat_id = ?");
        $gcStmt->execute([$groupChatId]);
        $groupChatDbId = (int)$gcStmt->fetchColumn();

        if ($groupChatDbId && !empty($contactIds)) {
            $partStmt = $db->prepare("
                INSERT IGNORE INTO whatsapp_group_participants (chat_id, contact_id, is_admin)
                VALUES (?, ?, 0)
            ");
            foreach ($contactIds as $cid) {
                $partStmt->execute([$groupChatDbId, $cid]);
            }
        }

        return [
            'academic_year' => $academicYear,
            'std' => $std,
            'division' => $division,
            'semester' => $semester,
            'class_key' => $classKey,
            'students_count' => count($students),
            'contacts_synced' => $syncedContacts,
            'chats_synced' => $syncedChats,
            'group_chat_id' => $groupChatDbId
        ];
    }
}

if (!function_exists('waInsertMessage')) {
    function waInsertMessage(PDO $db, array $msg): int {
        $metaVal = null;
        if (!empty($msg['metadata'])) {
            $metaVal = is_string($msg['metadata']) ? $msg['metadata'] : json_encode($msg['metadata']);
        }
        $fromMe = !empty($msg['from_me']) ? 1 : 0;
        $isFwd  = !empty($msg['is_forwarded']) ? 1 : 0;
        $isStar = !empty($msg['is_starred']) ? 1 : 0;
        $quoted = !empty($msg['quoted_msg_id']) ? (int)$msg['quoted_msg_id'] : null;

        $stmt = $db->prepare("
            INSERT INTO whatsapp_messages 
            (wa_msg_id, chat_id, from_me, sender_id, sender_name, sender_avatar, msg_type, body, 
             media_url, media_mime, media_size, media_width, media_height, media_duration, thumbnail_url,
             quoted_msg_id, is_forwarded, is_starred, status, timestamp, received_at, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN FIELD(VALUES(status), 'pending','sent','delivered','read','failed','revoke_pending','revoked','revoke_failed')
                       > FIELD(status, 'pending','sent','delivered','read','failed','revoke_pending','revoked','revoke_failed')
                    THEN VALUES(status)
                    ELSE status
                END,
                media_url = VALUES(media_url),
                thumbnail_url = VALUES(thumbnail_url),
                acknowledged_at = CASE WHEN VALUES(status) = 'read' THEN NOW() ELSE acknowledged_at END
        ");
        $stmt->execute([
            $msg['wa_msg_id'],
            $msg['chat_id'],
            $fromMe,
            $msg['sender_id'] ?? null,
            $msg['sender_name'] ?? null,
            $msg['sender_avatar'] ?? null,
            $msg['msg_type'] ?? 'text',
            $msg['body'] ?? null,
            $msg['media_url'] ?? null,
            $msg['media_mime'] ?? null,
            $msg['media_size'] ?? null,
            $msg['media_width'] ?? null,
            $msg['media_height'] ?? null,
            $msg['media_duration'] ?? null,
            $msg['thumbnail_url'] ?? null,
            $quoted,
            $isFwd,
            $isStar,
            $msg['status'] ?? 'pending',
            (int)($msg['timestamp'] ?? time()),
            $metaVal
        ]);
        $rawInsertId = (int)$db->lastInsertId();
        $wasInserted = $rawInsertId > 0;
        $msgId = $rawInsertId;
        // ON DUPLICATE KEY yields lastInsertId=0 — resolve the real row id.
        if ($msgId <= 0) {
            $find = $db->prepare('SELECT id FROM whatsapp_messages WHERE wa_msg_id = ? LIMIT 1');
            $find->execute([$msg['wa_msg_id']]);
            $msgId = (int)$find->fetchColumn();
        }

        // A later read can replace a scrape-time stamp or a noon day-placeholder.
        // A timestamp that was already a real clock is left alone.
        $parsedTs = (int)($msg['timestamp'] ?? 0);
        if ($msgId > 0 && !empty($msg['timestamp_parsed']) && $parsedTs > 1000000000 && $parsedTs < 20000000000) {
            try {
                $precision = '';
                if (is_array($msg['metadata'] ?? null)) {
                    $precision = (string)($msg['metadata']['time_precision'] ?? '');
                }
                $fix = $db->prepare("
                    UPDATE whatsapp_messages
                    SET timestamp = ?,
                        metadata = JSON_SET(COALESCE(CAST(metadata AS JSON), JSON_OBJECT()), '$.time_precision', ?)
                    WHERE id = ?
                      AND timestamp <> ?
                      AND (
                        timestamp BETWEEN UNIX_TIMESTAMP(received_at) - 3 AND UNIX_TIMESTAMP(received_at) + 3
                        OR (
                          MOD(timestamp + 19800, 86400) = 43200
                          AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.preview_only')) IN ('true', '1')
                        )
                      )
                ");
                $fix->execute([$parsedTs, $precision, $msgId, $parsedTs]);
            } catch (Throwable $e) {
                error_log('WA timestamp repair skipped: ' . $e->getMessage());
            }
        }

        $isBlankSync = (
            ($msg['msg_type'] ?? 'text') === 'system'
            && trim((string)($msg['body'] ?? '')) === ''
            && str_starts_with((string)($msg['wa_msg_id'] ?? ''), 'chat_sync_')
        );

        if ($msgId > 0) {
            $msgTs = (int)($msg['timestamp'] ?? time());
            if ($isBlankSync) {
                $db->prepare("
                    UPDATE whatsapp_chats
                    SET last_activity_at = COALESCE(last_activity_at, FROM_UNIXTIME(?))
                    WHERE id = ?
                ")->execute([$msgTs, $msg['chat_id']]);
            } else {
                // Only increment unread_count for genuinely newly-inserted incoming messages
                $incUnread = ($wasInserted && $fromMe === 0) ? 1 : 0;
                $db->prepare("
                    UPDATE whatsapp_chats 
                    SET last_message_id = ?,
                        last_activity_at = GREATEST(COALESCE(last_activity_at, FROM_UNIXTIME(?)), FROM_UNIXTIME(?)),
                        unread_count = unread_count + ?
                    WHERE id = ?
                ")->execute([$msgId, $msgTs, $msgTs, $incUnread, $msg['chat_id']]);
            }
        }
        
        return $msgId;
    }
}


if (!function_exists('waEnsureRevokeSchema')) {
    /**
     * Ensure outbound queue accepts msg_type=revoke and message status
     * includes revoke_pending / revoked / revoke_failed.
     */
    function waEnsureRevokeSchema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $msgType = (string)$db->query("SHOW COLUMNS FROM whatsapp_outbound_queue LIKE 'msg_type'")->fetchColumn(1);
            if ($msgType !== '' && stripos($msgType, "'revoke'") === false) {
                $db->exec("ALTER TABLE whatsapp_outbound_queue MODIFY COLUMN msg_type ENUM('text','image','video','audio','document','location','contact','vcard','revoke') NOT NULL DEFAULT 'text'");
            }
        } catch (Throwable $e) { /* leave as-is; caller will surface errors */ }
        try {
            $statusType = (string)$db->query("SHOW COLUMNS FROM whatsapp_messages LIKE 'status'")->fetchColumn(1);
            if ($statusType !== '' && stripos($statusType, "'revoke_pending'") === false) {
                $db->exec("ALTER TABLE whatsapp_messages MODIFY COLUMN status ENUM('pending','sent','delivered','read','failed','revoke_pending','revoked','revoke_failed') NOT NULL DEFAULT 'pending'");
            }
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('waRevokeWindowSec')) {
    function waRevokeWindowSec(): int {
        $env = getenv('WHATSAPP_REVOKE_WINDOW_SEC');
        if ($env !== false && $env !== '' && ctype_digit((string)$env) && (int)$env > 0) {
            return (int)$env;
        }
        return 48 * 60 * 60;
    }
}

if (!function_exists('waCanRevokeForEveryone')) {
    /** @return array{ok:bool,reason?:string,age_sec?:int,window_sec:int} */
    function waCanRevokeForEveryone(int $messageTimestampSec, ?int $nowSec = null, ?int $windowSec = null): array {
        $now = $nowSec ?? time();
        $window = $windowSec ?? waRevokeWindowSec();
        if ($messageTimestampSec <= 0) {
            return ['ok' => false, 'reason' => 'Message timestamp is missing or invalid', 'window_sec' => $window];
        }
        $sec = $messageTimestampSec > 1000000000000
            ? (int)floor($messageTimestampSec / 1000)
            : $messageTimestampSec;
        $age = max(0, $now - $sec);
        if ($age > $window) {
            return [
                'ok' => false,
                'reason' => "Message is older than the delete-for-everyone window ({$window}s)",
                'age_sec' => $age,
                'window_sec' => $window,
            ];
        }
        return ['ok' => true, 'age_sec' => $age, 'window_sec' => $window];
    }
}


if (!function_exists('waSlugifyTemplate')) {
    function waSlugifyTemplate(string $title): string {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? 'template';
        $slug = trim($slug, '_');
        if ($slug === '') $slug = 'template';
        return substr($slug, 0, 60);
    }
}

if (!function_exists('waFillTemplatePlaceholders')) {
    /** @param array<string,scalar|null> $vars */
    function waFillTemplatePlaceholders(string $body, array $vars): string {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string)($v ?? '');
        }
        // Default empty for unknown braces left as-is only if not in map
        return strtr($body, $map);
    }
}

if (!function_exists('waTemplateVarsFromChat')) {
    /** @return array<string,string> */
    function waTemplateVarsFromChat(PDO $db, int $chatId): array {
        $stmt = $db->prepare("SELECT * FROM whatsapp_chats WHERE id = ?");
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string)($chat['name'] ?? ''));
        $classKey = trim((string)($chat['linked_class_key'] ?? ''));
        $classLabel = $classKey;
        if (preg_match('/STD[_\s-]*([0-9IVXLC]+).*?(?:[_-]|)([A-Z0-9]+)?/i', $classKey, $m)) {
            $classLabel = 'STD ' . strtoupper($m[1]) . (isset($m[2]) && $m[2] !== '' ? (' ' . strtoupper($m[2])) : '');
        } elseif ($classKey === '' && preg_match('/STD\s*([0-9IVXLC]+)\s*([A-Z])?/i', $name, $m2)) {
            $classLabel = 'STD ' . strtoupper($m2[1]) . (isset($m2[2]) ? (' ' . strtoupper($m2[2])) : '');
        }
        $student = trim((string)($chat['linked_student_roll'] ?? ''));
        $chatType = (string)($chat['chat_type'] ?? 'individual');
        if ($chatType === 'group' || $chatType === 'broadcast') {
            $parentName = 'Parents';
            $studentName = 'students';
        } else {
            $studentName = $name !== '' ? $name : ($student !== '' ? ('Roll ' . $student) : 'Student');
            $parentName = $name !== '' ? ('Parent of ' . $name) : 'Parent';
        }
        return [
            'parent_name' => $parentName,
            'student_name' => $studentName,
            'class' => $classLabel !== '' ? $classLabel : 'your class',
            'date' => date('d M Y'),
            'announcement' => '',
        ];
    }
}

if (!function_exists('waEnqueueWhatsAppOutbound')) {
    /**
     * Enqueue one outbound WhatsApp message for the bridge.
     * ONLY for user-press endpoints (api_whatsapp_send / templates send / chat_actions revoke/retry).
     * Always inserts status=queued. Does not create draft/held/blocked_auto.
     * Requires an authenticated admin (approved_by / session admin_id).
     * @return array{queue_id:int,temp_msg_id:string,status:string}
     */
    function waEnqueueWhatsAppOutbound(PDO $db, array $opts): array {
        $chatId = (int)($opts['chat_id'] ?? 0);
        if ($chatId <= 0) {
            throw new InvalidArgumentException('chat_id required');
        }
        $msgType = (string)($opts['msg_type'] ?? 'text');
        $body = (string)($opts['body'] ?? '');
        $mediaPath = $opts['media_path'] ?? null;
        $mediaMime = $opts['media_mime'] ?? null;
        $caption = $opts['caption'] ?? null;
        $priority = min(max(1, (int)($opts['priority'] ?? 5)), 10);
        $quotedMsgId = !empty($opts['quoted_msg_id']) ? (int)$opts['quoted_msg_id'] : null;
        $relatedDocType = $opts['related_doc_type'] ?? null;
        $relatedStudentRoll = $opts['related_student_roll'] ?? null;
        $relatedClassKey = $opts['related_class_key'] ?? null;
        $adminId = (int)($opts['dispatched_by'] ?? ($opts['approved_by'] ?? ($_SESSION['admin_id'] ?? 0)));
        if ($adminId <= 0) {
            throw new RuntimeException('Authenticated admin required to enqueue a WhatsApp send');
        }
        $scheduledAt = $opts['scheduled_at'] ?? null; // stagger only for multi-chat press; still user-initiated
        $origin = 'manual_ui';
        $status = 'queued';
        $dispatchMethod = 'manual';

        $stmt = $db->prepare("
            INSERT INTO whatsapp_outbound_queue
            (chat_id, contact_id, msg_type, body, media_path, media_mime, caption,
             priority, status, dispatch_method, origin, approved_by, approved_at, confirm_nonce,
             dispatched_by, related_student_roll, related_class_key, related_doc_type, scheduled_at)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))
        ");
        $stmt->execute([
            $chatId, $msgType, $body, $mediaPath, $mediaMime, $caption,
            $priority, $status, $dispatchMethod, $origin,
            $adminId, $adminId, $relatedStudentRoll, $relatedClassKey, $relatedDocType, $scheduledAt
        ]);
        $queueId = (int)$db->lastInsertId();
        $tempMsgId = 'temp_' . $queueId . '_' . time();
        $meta = ['queue_id' => $queueId, 'temp' => true, 'origin' => $origin, 'enqueue_status' => $status];
        if (!empty($opts['template_id'])) $meta['template_id'] = (int)$opts['template_id'];
        waInsertMessage($db, [
            'wa_msg_id' => $tempMsgId,
            'chat_id' => $chatId,
            'from_me' => true,
            'sender_name' => 'You',
            'msg_type' => $msgType,
            'body' => $msgType === 'text' ? $body : ($caption ?? $body),
            'media_url' => $mediaPath,
            'media_mime' => $mediaMime,
            'quoted_msg_id' => $quotedMsgId,
            'status' => 'pending',
            'timestamp' => time(),
            'metadata' => $meta,
        ]);
        return ['queue_id' => $queueId, 'temp_msg_id' => $tempMsgId, 'status' => $status];
    }
}

if (!function_exists('waFailStaleOutbound')) {
    /**
     * On bridge dequeue: anything still queued that is older than $maxAgeSec
     * was created while the bridge was offline / not consuming. Mark failed
     * so the user must press Retry. This is send-on-press semantics, not a lock.
     * @return array{failed:int}
     */
    function waFailStaleOutbound(PDO $db, int $maxAgeSec = 120): array {
        $maxAgeSec = max(30, min(3600, $maxAgeSec));
        $procAgeSec = max(300, $maxAgeSec);
        $st = $db->prepare("
            UPDATE whatsapp_outbound_queue
            SET status = 'failed',
                error_message = 'not sent (bridge was offline) — press Retry',
                updated_at = NOW()
            WHERE (
                status = 'queued'
                AND GREATEST(created_at, COALESCE(scheduled_at, created_at)) < (NOW() - INTERVAL {$maxAgeSec} SECOND)
            ) OR (
                status = 'processing'
                AND GREATEST(created_at, updated_at) < (NOW() - INTERVAL {$procAgeSec} SECOND)
            )
        ");
        $st->execute();
        return ['failed' => $st->rowCount()];
    }
}


if (!function_exists('waPaginate')) {
    function waPaginate(PDO $db, string $countSql, string $dataSql, array $params, int $page, int $perPage): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;
        
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;
        
        $dataParams = array_merge($params, [$perPage, $offset]);
        $dataStmt = $db->prepare($dataSql);
        $dataStmt->execute($dataParams);
        $items = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages
            ]
        ];
    }
}

if (!function_exists('waDropSupersededPreviews')) {
/**
 * Remove chat-list preview stand-ins ("preview:" ids) in this chat whose text is the
 * start of a real incoming message. Our DB only; nothing is sent or changed in WhatsApp.
 */
function waDropSupersededPreviews(PDO $db, int $chatId, string $body): int {
    $norm = static function ($s): string {
        $s = preg_replace('/\s+/u', ' ', (string)$s);
        return trim((string)$s);
    };
    $real = $norm($body);
    if ($real === '') return 0;
    $stmt = $db->prepare("SELECT id, body FROM whatsapp_messages WHERE chat_id = ? AND from_me = 0 AND wa_msg_id LIKE 'preview:%' ORDER BY id DESC LIMIT 20");
    $stmt->execute([$chatId]);
    $del = $db->prepare("DELETE FROM whatsapp_messages WHERE id = ? AND wa_msg_id LIKE 'preview:%'");
    $dropped = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $p = $norm(preg_replace('/(\x{2026}|\.\.\.)$/u', '', (string)$row['body']));
        if ($p === '') continue;
        if ($p === $real || mb_strpos($real, $p) === 0) {
            $del->execute([(int)$row['id']]);
            $dropped += $del->rowCount();
        }
    }
    return $dropped;
}
}
