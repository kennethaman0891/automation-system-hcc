-- =====================================================================
-- HCC School WhatsApp Hub — Admission Follow-up Management Schema
-- =====================================================================

-- 1. Create Follow-up History table
CREATE TABLE IF NOT EXISTS `admission_followup_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `enquiry_id` BIGINT UNSIGNED NOT NULL,
    `action` ENUM(
        'created',
        'updated',
        'rescheduled',
        'reminder_generated',
        'whatsapp_opened',
        'manual_sent_confirmed',
        'completed',
        'cancelled',
        'note_added'
    ) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `details` TEXT NULL,
    `previous_date` DATE NULL,
    `previous_time` TIME NULL,
    `new_date` DATE NULL,
    `new_time` TIME NULL,
    `performed_by` VARCHAR(100) NOT NULL DEFAULT 'Admin',
    `performed_by_id` INT NULL,
    `manual_sent_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
    `delivery_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `delivery_disclaimer` VARCHAR(255) DEFAULT 'Unverified delivery: Manual confirmation does not guarantee or verify WhatsApp delivery.',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_enquiry` (`enquiry_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_followup_enquiry` FOREIGN KEY (`enquiry_id`) REFERENCES `admission_enquiries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Durable MySQL Job Queue for Automation Engine
CREATE TABLE IF NOT EXISTS `automation_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_type` VARCHAR(50) NOT NULL,
    `payload` JSON NOT NULL,
    `dedup_key` VARCHAR(191) NOT NULL UNIQUE,
    `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `priority` INT NOT NULL DEFAULT 10,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
    `scheduled_at` DATETIME NOT NULL,
    `locked_at` DATETIME NULL,
    `locked_by` VARCHAR(100) NULL,
    `last_error` TEXT NULL,
    `completed_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_queue` (`status`, `scheduled_at`),
    INDEX `idx_type` (`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Execution History for Automation Jobs
CREATE TABLE IF NOT EXISTS `automation_job_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` BIGINT UNSIGNED NOT NULL,
    `job_type` VARCHAR(50) NOT NULL,
    `attempt_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('success', 'retrying', 'failed', 'recovered') NOT NULL,
    `worker_id` VARCHAR(100) NOT NULL,
    `execution_duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `output_summary` TEXT NULL,
    `error_details` TEXT NULL,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_job` (`job_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_time` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
