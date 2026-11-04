<?php
/**
 * HCC School WhatsApp Hub — Admission AI Assistant Database Migration
 * Creates tables for:
 *  - admission_enquiries
 *  - admission_ai_drafts
 *  - admission_knowledge_base
 * Seeds initial knowledge base from knowledge/approved files.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Starting Admission AI Assistant DB Migration...\n\n";

$tables = [
    'admission_enquiries' => "
        CREATE TABLE IF NOT EXISTS `admission_enquiries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `chat_id` BIGINT UNSIGNED NOT NULL,
            `contact_id` BIGINT UNSIGNED DEFAULT NULL,
            `parent_phone` VARCHAR(25) DEFAULT NULL,
            `parent_name` VARCHAR(255) DEFAULT NULL,
            `student_name` VARCHAR(255) DEFAULT NULL,
            `target_grade` VARCHAR(50) DEFAULT NULL,
            `academic_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
            `current_school` VARCHAR(255) DEFAULT NULL,
            `primary_intent` VARCHAR(100) DEFAULT 'Admission enquiry',
            `status` ENUM('new', 'ai_drafted', 'in_review', 'approved_sent', 'escalated', 'enrolled', 'closed') NOT NULL DEFAULT 'new',
            `escalation_required` BOOLEAN NOT NULL DEFAULT FALSE,
            `escalation_reason` TEXT DEFAULT NULL,
            `assigned_staff_id` INT UNSIGNED DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `last_message_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_chat_id` (`chat_id`),
            KEY `idx_status` (`status`),
            KEY `idx_parent_phone` (`parent_phone`),
            KEY `idx_escalation` (`escalation_required`),
            CONSTRAINT `fk_enq_chat` FOREIGN KEY (`chat_id`) REFERENCES `whatsapp_chats` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",

    'admission_ai_drafts' => "
        CREATE TABLE IF NOT EXISTS `admission_ai_drafts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `enquiry_id` BIGINT UNSIGNED DEFAULT NULL,
            `chat_id` BIGINT UNSIGNED NOT NULL,
            `trigger_msg_id` BIGINT UNSIGNED DEFAULT NULL,
            `intent` VARCHAR(100) NOT NULL,
            `confidence` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            `extracted_parent_details` JSON DEFAULT NULL,
            `extracted_student_details` JSON DEFAULT NULL,
            `relevant_knowledge_sources` JSON DEFAULT NULL,
            `response_draft` TEXT NOT NULL,
            `missing_information` JSON DEFAULT NULL,
            `escalation_required` BOOLEAN NOT NULL DEFAULT FALSE,
            `escalation_reason` TEXT DEFAULT NULL,
            `model_used` VARCHAR(100) NOT NULL DEFAULT 'llama3.2:1b',
            `inference_duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
            `safety_validated` BOOLEAN NOT NULL DEFAULT TRUE,
            `validation_notes` TEXT DEFAULT NULL,
            `admin_status` ENUM('pending', 'approved', 'edited', 'rejected', 'sent') NOT NULL DEFAULT 'pending',
            `admin_edited_response` TEXT DEFAULT NULL,
            `approved_by` INT UNSIGNED DEFAULT NULL,
            `approved_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_draft_chat` (`chat_id`),
            KEY `idx_draft_enquiry` (`enquiry_id`),
            KEY `idx_admin_status` (`admin_status`),
            KEY `idx_created_at` (`created_at`),
            CONSTRAINT `fk_draft_chat` FOREIGN KEY (`chat_id`) REFERENCES `whatsapp_chats` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_draft_enquiry` FOREIGN KEY (`enquiry_id`) REFERENCES `admission_enquiries` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_draft_msg` FOREIGN KEY (`trigger_msg_id`) REFERENCES `whatsapp_messages` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",

    'admission_knowledge_base' => "
        CREATE TABLE IF NOT EXISTS `admission_knowledge_base` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category` VARCHAR(100) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(100) NOT NULL UNIQUE,
            `content_json` JSON NOT NULL,
            `content_markdown` TEXT NOT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
            `version` VARCHAR(20) NOT NULL DEFAULT '1.0',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_category` (`category`),
            KEY `idx_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    "
];

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "✅ Created/verified table: $name\n";
    } catch (PDOException $e) {
        echo "❌ Error creating table $name: " . $e->getMessage() . "\n";
    }
}

// Add metadata column to whatsapp_chats for quick AI draft tracking if not present
try {
    $db->exec("ALTER TABLE `whatsapp_chats` ADD COLUMN IF NOT EXISTS `is_admission_enquiry` BOOLEAN NOT NULL DEFAULT FALSE");
    $db->exec("ALTER TABLE `whatsapp_chats` ADD COLUMN IF NOT EXISTS `latest_ai_draft_id` BIGINT UNSIGNED DEFAULT NULL");
    echo "✅ Ensured admission AI columns on whatsapp_chats\n";
} catch (PDOException $e) {
    echo "ℹ️  whatsapp_chats columns note: " . $e->getMessage() . "\n";
}

// Seed approved knowledge base files into MySQL
$knowledgeDir = __DIR__ . '/../knowledge/approved';
$seeds = [
    [
        'category' => 'prospectus',
        'title' => 'Admission Prospectus 2026-2027',
        'slug' => 'admission_prospectus',
        'file' => 'admission_prospectus.json'
    ],
    [
        'category' => 'fees',
        'title' => 'Approved Fee Structure 2026-2027',
        'slug' => 'fee_structure',
        'file' => 'fee_structure.json'
    ],
    [
        'category' => 'classes',
        'title' => 'Class Information & Sections',
        'slug' => 'class_information',
        'file' => 'class_information.json'
    ],
    [
        'category' => 'age_criteria',
        'title' => 'Age Requirements & Eligibility Criteria',
        'slug' => 'age_criteria',
        'file' => 'age_criteria.json'
    ],
    [
        'category' => 'documents',
        'title' => 'Admission Document Checklist',
        'slug' => 'admission_checklist',
        'file' => 'admission_checklist.json'
    ],
    [
        'category' => 'timings',
        'title' => 'School Timings & Visitor Hours',
        'slug' => 'school_timings',
        'file' => 'school_timings.json'
    ],
    [
        'category' => 'transport',
        'title' => 'Transport Facility & Bus Routes',
        'slug' => 'transport_details',
        'file' => 'transport_details.json'
    ],
    [
        'category' => 'contact',
        'title' => 'School Contact Information & Campus Visits',
        'slug' => 'contact_information',
        'file' => 'contact_information.json'
    ],
    [
        'category' => 'policies',
        'title' => 'Admission Policies & Regulations',
        'slug' => 'admission_policies',
        'file' => 'admission_policies.json'
    ]
];

$summaryMdPath = $knowledgeDir . '/SCHOOL_KNOWLEDGE_SUMMARY.md';
$summaryMd = file_exists($summaryMdPath) ? file_get_contents($summaryMdPath) : '';

$stmt = $db->prepare("
    INSERT INTO `admission_knowledge_base` (`category`, `title`, `slug`, `content_json`, `content_markdown`, `is_active`, `version`)
    VALUES (?, ?, ?, ?, ?, 1, '1.0')
    ON DUPLICATE KEY UPDATE 
        `title` = VALUES(`title`),
        `content_json` = VALUES(`content_json`),
        `content_markdown` = VALUES(`content_markdown`),
        `is_active` = 1,
        `updated_at` = NOW()
");

foreach ($seeds as $item) {
    $filePath = $knowledgeDir . '/' . $item['file'];
    if (file_exists($filePath)) {
        $jsonStr = file_get_contents($filePath);
        $stmt->execute([
            $item['category'],
            $item['title'],
            $item['slug'],
            $jsonStr,
            $summaryMd
        ]);
        echo "✅ Seeded knowledge topic: {$item['title']} ({$item['slug']})\n";
    } else {
        echo "⚠️ Missing file: $filePath\n";
    }
}

echo "\nMigration and knowledge seeding completed successfully!\n";
