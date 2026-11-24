<?php
/**
 * HCC WhatsApp Replica — Database Migration
 * Run once to create all required tables
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';

$db = getDB();

$tables = [
    'whatsapp_chats' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_chats` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wa_chat_id` VARCHAR(100) NOT NULL,
            `chat_type` ENUM('individual','group','broadcast') NOT NULL DEFAULT 'individual',
            `name` VARCHAR(255) DEFAULT NULL,
            `avatar_url` TEXT DEFAULT NULL,
            `is_archived` BOOLEAN NOT NULL DEFAULT FALSE,
            `is_pinned` BOOLEAN NOT NULL DEFAULT FALSE,
            `is_muted` BOOLEAN NOT NULL DEFAULT FALSE,
            `mute_until` TIMESTAMP NULL DEFAULT NULL,
            `unread_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_message_id` BIGINT UNSIGNED DEFAULT NULL,
            `last_activity_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `metadata` JSON DEFAULT NULL,
            `linked_student_roll` VARCHAR(20) DEFAULT NULL,
            `linked_class_key` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_wa_chat_id` (`wa_chat_id`),
            KEY `idx_last_activity` (`last_activity_at`),
            KEY `idx_archived` (`is_archived`),
            KEY `idx_pinned` (`is_pinned`),
            KEY `idx_muted` (`is_muted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    
    'whatsapp_messages' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wa_msg_id` VARCHAR(100) NOT NULL,
            `chat_id` BIGINT UNSIGNED NOT NULL,
            `from_me` BOOLEAN NOT NULL,
            `sender_id` VARCHAR(100) DEFAULT NULL,
            `sender_name` VARCHAR(255) DEFAULT NULL,
            `sender_avatar` TEXT DEFAULT NULL,
            `msg_type` ENUM('text','image','video','audio','document','sticker','location','contact','vcard','call','revoked','reaction','poll','order','payment','system') NOT NULL DEFAULT 'text',
            `body` TEXT DEFAULT NULL,
            `media_url` TEXT DEFAULT NULL,
            `media_mime` VARCHAR(100) DEFAULT NULL,
            `media_size` BIGINT UNSIGNED DEFAULT NULL,
            `media_width` INT UNSIGNED DEFAULT NULL,
            `media_height` INT UNSIGNED DEFAULT NULL,
            `media_duration` INT UNSIGNED DEFAULT NULL,
            `thumbnail_url` TEXT DEFAULT NULL,
            `quoted_msg_id` BIGINT UNSIGNED DEFAULT NULL,
            `is_forwarded` BOOLEAN NOT NULL DEFAULT FALSE,
            `is_starred` BOOLEAN NOT NULL DEFAULT FALSE,
            `status` ENUM('pending','sent','delivered','read','failed','revoke_pending','revoked','revoke_failed') NOT NULL DEFAULT 'pending',
            `timestamp` INT UNSIGNED NOT NULL,
            `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `acknowledged_at` TIMESTAMP NULL DEFAULT NULL,
            `metadata` JSON DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_chat_timestamp` (`chat_id`, `timestamp`),
            UNIQUE KEY `uk_wa_msg_id` (`wa_msg_id`),
            KEY `idx_from_me` (`from_me`),
            KEY `idx_status` (`status`),
            KEY `idx_timestamp` (`timestamp`),
            KEY `idx_quoted` (`quoted_msg_id`),
            CONSTRAINT `fk_msg_chat` FOREIGN KEY (`chat_id`) REFERENCES `whatsapp_chats` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    
    'whatsapp_media' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_media` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wa_media_key` VARCHAR(200) NOT NULL,
            `msg_id` BIGINT UNSIGNED DEFAULT NULL,
            `local_path` VARCHAR(500) DEFAULT NULL,
            `mime_type` VARCHAR(100) DEFAULT NULL,
            `file_size` BIGINT UNSIGNED DEFAULT NULL,
            `width` INT UNSIGNED DEFAULT NULL,
            `height` INT UNSIGNED DEFAULT NULL,
            `duration` INT UNSIGNED DEFAULT NULL,
            `thumbnail_path` VARCHAR(500) DEFAULT NULL,
            `download_status` ENUM('pending','downloaded','failed') NOT NULL DEFAULT 'pending',
            `expires_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_wa_media_key` (`wa_media_key`),
            KEY `idx_msg_id` (`msg_id`),
            KEY `idx_download_status` (`download_status`),
            CONSTRAINT `fk_media_msg` FOREIGN KEY (`msg_id`) REFERENCES `whatsapp_messages` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    
    'whatsapp_contacts' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_contacts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `wa_id` VARCHAR(100) NOT NULL,
            `phone_e164` VARCHAR(20) DEFAULT NULL,
            `name` VARCHAR(255) DEFAULT NULL,
            `push_name` VARCHAR(255) DEFAULT NULL,
            `avatar_url` TEXT DEFAULT NULL,
            `is_business` BOOLEAN NOT NULL DEFAULT FALSE,
            `business_info` JSON DEFAULT NULL,
            `is_blocked` BOOLEAN NOT NULL DEFAULT FALSE,
            `is_saved` BOOLEAN NOT NULL DEFAULT FALSE,
            `labels` JSON DEFAULT NULL,
            `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
            `source` ENUM('whatsapp','manual','import','student_link') NOT NULL DEFAULT 'whatsapp',
            `linked_student_roll` VARCHAR(20) DEFAULT NULL,
            `linked_class_key` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_wa_id` (`wa_id`),
            KEY `idx_phone` (`phone_e164`),
            KEY `idx_linked_student` (`linked_student_roll`, `linked_class_key`),
            KEY `idx_source` (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    
    'whatsapp_group_participants' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_group_participants` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `chat_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED NOT NULL,
            `is_admin` BOOLEAN NOT NULL DEFAULT FALSE,
            `is_super_admin` BOOLEAN NOT NULL DEFAULT FALSE,
            `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_chat_contact` (`chat_id`, `contact_id`),
            KEY `idx_contact` (`contact_id`),
            CONSTRAINT `fk_gp_chat` FOREIGN KEY (`chat_id`) REFERENCES `whatsapp_chats` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gp_contact` FOREIGN KEY (`contact_id`) REFERENCES `whatsapp_contacts` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    
    'whatsapp_sessions' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_sessions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_name` VARCHAR(50) NOT NULL DEFAULT 'default',
            `user_data_dir` VARCHAR(500) DEFAULT NULL,
            `qr_code` TEXT DEFAULT NULL,
            `qr_expires_at` TIMESTAMP NULL DEFAULT NULL,
            `is_connected` BOOLEAN NOT NULL DEFAULT FALSE,
            `connected_at` TIMESTAMP NULL DEFAULT NULL,
            `last_poll_at` TIMESTAMP NULL DEFAULT NULL,
            `phone_number` VARCHAR(50) DEFAULT NULL,
            `device_name` VARCHAR(100) DEFAULT NULL,
            `platform` VARCHAR(50) DEFAULT NULL,
            `cookies` JSON DEFAULT NULL,
            `local_storage` JSON DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_session_name` (`session_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    
    'whatsapp_outbound_queue' => "
        CREATE TABLE IF NOT EXISTS `whatsapp_outbound_queue` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `chat_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED DEFAULT NULL,
            `msg_type` ENUM('text','image','video','audio','document','location','contact','vcard','revoke') NOT NULL DEFAULT 'text',
            `body` TEXT DEFAULT NULL,
            `media_path` VARCHAR(500) DEFAULT NULL,
            `media_mime` VARCHAR(100) DEFAULT NULL,
            `caption` TEXT DEFAULT NULL,
            `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `status` ENUM('queued','processing','sent','delivered','read','failed','cancelled') NOT NULL DEFAULT 'queued',
            `error_message` TEXT DEFAULT NULL,
            `scheduled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at` TIMESTAMP NULL DEFAULT NULL,
            `delivered_at` TIMESTAMP NULL DEFAULT NULL,
            `read_at` TIMESTAMP NULL DEFAULT NULL,
            `dispatch_method` ENUM('auto','manual','companion','api') NOT NULL DEFAULT 'auto',
            `dispatched_by` INT UNSIGNED DEFAULT NULL,
            `related_student_roll` VARCHAR(20) DEFAULT NULL,
            `related_class_key` VARCHAR(100) DEFAULT NULL,
            `related_doc_type` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status_priority` (`status`, `priority`, `scheduled_at`),
            KEY `idx_dispatched_by` (`dispatched_by`),
            KEY `idx_chat` (`chat_id`),
            KEY `idx_contact` (`contact_id`),
            CONSTRAINT `fk_obq_chat` FOREIGN KEY (`chat_id`) REFERENCES `whatsapp_chats` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_obq_contact` FOREIGN KEY (`contact_id`) REFERENCES `whatsapp_contacts` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
];

echo "Starting WhatsApp Replica Migration...

";

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "✅ Created table: $name
";
    } catch (PDOException $e) {
        echo "❌ Failed to create $name: " . $e->getMessage() . "
";
    }
}

// Ensure columns in existing whatsapp_chats table
try {
    hccEnsureColumn($db, 'whatsapp_chats', 'linked_student_roll', 'VARCHAR(20) DEFAULT NULL');
    hccEnsureColumn($db, 'whatsapp_chats', 'linked_class_key', 'VARCHAR(100) DEFAULT NULL');
    hccEnsureIndex($db, 'whatsapp_chats', 'idx_chat_linked_student', '`linked_student_roll`, `linked_class_key`');
    echo "✅ Ensured student link columns on whatsapp_chats\n";
} catch (Throwable $e) {
    echo "ℹ️  Student link columns info: " . $e->getMessage() . "\n";
}

// Add foreign key for last_message_id (after both tables exist)
try {
    $db->exec("
        ALTER TABLE `whatsapp_chats` 
        ADD CONSTRAINT `fk_chat_last_msg` 
        FOREIGN KEY (`last_message_id`) REFERENCES `whatsapp_messages` (`id`) ON DELETE SET NULL
    ");
    echo "✅ Added FK: whatsapp_chats.last_message_id\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false || strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), '1005') !== false) {
        echo "ℹ️  FK already exists\n";
    } else {
        echo "⚠️  FK add note: " . $e->getMessage() . "\n";
    }
}

// Migrate existing dispatch_log data to outbound_queue
try {
    $db->exec("
        INSERT IGNORE INTO `whatsapp_outbound_queue` 
        (`chat_id`, `contact_id`, `msg_type`, `body`, `media_path`, `media_mime`, `caption`, 
         `priority`, `status`, `scheduled_at`, `sent_at`, `delivered_at`, `read_at`, 
         `dispatch_method`, `dispatched_by`, `related_student_roll`, `related_class_key`, `related_doc_type`)
        SELECT 
            NULL, NULL, 'document', `message_text`, NULL, NULL, NULL,
            5, 
            CASE `status` WHEN 'sent' THEN 'sent' WHEN 'failed' THEN 'failed' ELSE 'queued' END,
            `dispatched_at`, `dispatched_at`, `dispatched_at`, NULL,
            'auto', NULL, `student_roll`, NULL, `doc_type`
        FROM `whatsapp_dispatch_log`
    ");
    echo "✅ Migrated existing dispatch_log data\n";
} catch (PDOException $e) {
    echo "ℹ️  Migration skipped (table may be empty or columns differ): " . $e->getMessage() . "\n";
}

echo "
✅ Migration complete!
";
