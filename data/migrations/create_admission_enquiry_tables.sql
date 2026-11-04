-- ============================================================================
-- HCC Admission Enquiry Management System Migration
-- Tables: admission_enquiries, admission_enquiry_messages, admission_matching_rules
-- ============================================================================

CREATE TABLE IF NOT EXISTS `admission_enquiries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_name` VARCHAR(255) NOT NULL,
    `phone_number` VARCHAR(30) NOT NULL,
    `phone_e164` VARCHAR(30) NOT NULL,
    `student_name` VARCHAR(255) DEFAULT NULL,
    `interested_class` VARCHAR(50) NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL,
    `original_message` TEXT NOT NULL,
    `enquiry_source` VARCHAR(50) NOT NULL DEFAULT 'whatsapp',
    `assigned_staff_id` INT DEFAULT NULL,
    `assigned_staff_name` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('NEW','CONTACTED','INFORMATION_SHARED','INTERESTED','VISIT_PLANNED','DOCUMENTS_PENDING','APPLICATION_SUBMITTED','ENROLLED','CLOSED') NOT NULL DEFAULT 'NEW',
    `chat_id` BIGINT UNSIGNED DEFAULT NULL,
    `contact_id` BIGINT UNSIGNED DEFAULT NULL,
    `matched_student_roll` VARCHAR(50) DEFAULT NULL,
    `matched_student_class` VARCHAR(100) DEFAULT NULL,
    `matched_existing_parent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT DEFAULT NULL,
    `created_by_name` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_phone_e164` (`phone_e164`),
    INDEX `idx_academic_year` (`academic_year`),
    INDEX `idx_interested_class` (`interested_class`),
    INDEX `idx_assigned_staff` (`assigned_staff_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_chat_id` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admission_enquiry_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `enquiry_id` BIGINT UNSIGNED NOT NULL,
    `message_type` ENUM('incoming_message','ai_draft','staff_edit','approved_response','follow_up','status_change','note') NOT NULL,
    `body` TEXT NOT NULL,
    `ai_draft_body` TEXT DEFAULT NULL,
    `staff_edited_body` TEXT DEFAULT NULL,
    `old_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) DEFAULT NULL,
    `follow_up_date` DATETIME DEFAULT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_name` VARCHAR(100) DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_enquiry_created` (`enquiry_id`, `created_at`),
    INDEX `idx_type` (`message_type`),
    CONSTRAINT `fk_aem_enquiry` FOREIGN KEY (`enquiry_id`) REFERENCES `admission_enquiries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admission_matching_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rule_key` VARCHAR(50) NOT NULL UNIQUE,
    `rule_name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `rule_value` VARCHAR(255) DEFAULT NULL,
    `action` ENUM('warn','block') NOT NULL DEFAULT 'warn',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admission_matching_rules` (`rule_key`, `rule_name`, `description`, `is_enabled`, `rule_value`, `action`) VALUES
('match_phone_academic_year', 'Match Phone + Academic Year', 'Flag duplicate if same WhatsApp phone enquiries for the same academic year', 1, NULL, 'warn'),
('match_phone_active', 'Match Phone in Active Enquiries', 'Flag duplicate if same WhatsApp phone has an active open enquiry (not Closed or Enrolled)', 1, NULL, 'warn'),
('match_phone_student', 'Match Phone + Student Name', 'Flag duplicate if same phone number and student name match', 1, NULL, 'block'),
('match_student_class_year', 'Match Student Name + Class + Year', 'Flag duplicate if same student name is already enquired for the same class and year', 1, NULL, 'warn'),
('duplicate_window_days', 'Duplicate Detection Timeframe (Days)', 'Window in days to scan for duplicates (0 for all-time)', 1, '90', 'warn'),
('default_action', 'Default Enforcement Policy', 'Global policy when a duplicate enquiry is detected (warn staff vs block creation)', 1, 'warn', 'warn');
