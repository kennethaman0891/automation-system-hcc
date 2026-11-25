-- Universal Admin Auto-Save Drafts Table
-- Persists in-progress figure/form edits across all admin pages to survive sudden power cuts.

CREATE TABLE IF NOT EXISTS admin_autosave_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT NOT NULL,
    page_key VARCHAR(80) NOT NULL,
    form_key VARCHAR(120) NOT NULL,
    context_key VARCHAR(255) NOT NULL DEFAULT '',
    page_url VARCHAR(500) NOT NULL DEFAULT '',
    payload_json MEDIUMTEXT NOT NULL,
    field_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_autosave_scope (admin_id, page_key, form_key, context_key),
    KEY idx_admin_autosave_page (admin_id, page_key, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
