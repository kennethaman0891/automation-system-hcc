-- Per-user admin text size and profile photo.
-- The app also adds these columns on first use if they are missing.
ALTER TABLE users
    ADD COLUMN ui_font_percent SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL;
