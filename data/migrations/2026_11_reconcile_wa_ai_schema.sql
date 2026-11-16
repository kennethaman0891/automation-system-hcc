-- 2026_11_reconcile_wa_ai_schema.sql
-- Idempotent reconciliation migration for WhatsApp Hub, Admission AI Review,
-- Admission Enquiries Follow-up, DOM Health Watchdog (#14), Inbound Signal Cards (#9),
-- and Daily Principal Briefings (#13). Target: MariaDB 10.6+ / 11.x (hcc_admin).

-- 1. Reconcile admission_ai_review_drafts provenance columns
ALTER TABLE admission_ai_review_drafts
    ADD COLUMN IF NOT EXISTS analysis_engine VARCHAR(20) NULL DEFAULT 'local' AFTER model_used,
    ADD COLUMN IF NOT EXISTS analysis_provider VARCHAR(40) NULL DEFAULT NULL AFTER analysis_engine,
    ADD COLUMN IF NOT EXISTS analysis_model VARCHAR(120) NULL DEFAULT NULL AFTER analysis_provider,
    ADD COLUMN IF NOT EXISTS analysis_endpoint_host VARCHAR(255) NULL DEFAULT NULL AFTER analysis_model;

-- 2. Reconcile whatsapp_outbound_queue human-in-the-loop provenance columns
ALTER TABLE whatsapp_outbound_queue
    ADD COLUMN IF NOT EXISTS origin VARCHAR(40) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_by INT(10) UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS confirm_nonce VARCHAR(64) NULL DEFAULT NULL;

-- 3. Reconcile whatsapp_sessions telemetry & DOM selector sanity watchdog (#14)
ALTER TABLE whatsapp_sessions
    ADD COLUMN IF NOT EXISTS last_ingest_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_skip_note VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_syncing TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS dom_health_status VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS dom_health_checked_at DATETIME NULL DEFAULT NULL;

-- 4. Store WhatsApp @lid opaque IDs cleanly on chats & contacts instead of fake phone_e164
ALTER TABLE whatsapp_chats
    ADD COLUMN IF NOT EXISTS wa_lid VARCHAR(120) NULL DEFAULT NULL AFTER wa_chat_id;

ALTER TABLE whatsapp_contacts
    ADD COLUMN IF NOT EXISTS wa_lid VARCHAR(120) NULL DEFAULT NULL AFTER wa_id;

-- 5. Reconcile admission_enquiries canonical follow-up & inbound tracking columns
ALTER TABLE admission_enquiries
    ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS phone_e164 VARCHAR(30) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS interested_class VARCHAR(50) NOT NULL DEFAULT 'Not specified',
    ADD COLUMN IF NOT EXISTS followup_category ENUM(
        'First response pending',
        'Admission details shared',
        'Parent requested more information',
        'Parent interested',
        'Campus visit pending',
        'Documents pending',
        'Application pending',
        'Admission decision pending',
        'Completed admission',
        'Enquiry closed'
    ) NOT NULL DEFAULT 'First response pending',
    ADD COLUMN IF NOT EXISTS next_followup_date DATE NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS next_followup_time TIME NULL DEFAULT '10:00:00',
    ADD COLUMN IF NOT EXISTS followup_reason VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    ADD COLUMN IF NOT EXISTS is_completed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS reminder_status ENUM('pending','reminded','overdue','completed','cancelled') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS assigned_staff_name VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS suggested_message TEXT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_inbound_at DATETIME NULL DEFAULT NULL;

-- 6. Automation #9: Inbound Signal Cards
CREATE TABLE IF NOT EXISTS inbound_signal_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chat_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL DEFAULT NULL,
    signal_type ENUM('leave','fee_claim','phone_change','ptm_confirm','doc_upload','result_query','other') NOT NULL DEFAULT 'other',
    matched_student_name VARCHAR(255) NULL DEFAULT NULL,
    matched_roll_no VARCHAR(50) NULL DEFAULT NULL,
    matched_class VARCHAR(100) NULL DEFAULT NULL,
    extracted_json LONGTEXT NULL DEFAULT NULL,
    confidence DECIMAL(4,3) NULL DEFAULT 0.000,
    suggested_reply TEXT NULL DEFAULT NULL,
    status ENUM('pending','applied','dismissed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_signal_message (message_id),
    KEY idx_signal_status_created (status, created_at),
    KEY idx_signal_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Automation #13: 8:30 AM Daily Principal Briefings
CREATE TABLE IF NOT EXISTS daily_briefings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    brief_date DATE NOT NULL,
    summary_markdown LONGTEXT NOT NULL,
    metrics_json LONGTEXT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brief_date (brief_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
