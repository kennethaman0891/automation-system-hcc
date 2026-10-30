-- ============================================================================
-- Migration: Create Timetable Module Tables & Seed Data
-- Database: hcc_admin
-- Hindustani Covenant Church High School & Jr. College, Solapur
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Table: timetables
CREATE TABLE IF NOT EXISTS `timetables` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL DEFAULT 'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)',
  `school_name` VARCHAR(255) NOT NULL DEFAULT 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JR. COLLEGE, SOLAPUR.',
  `standards_label` VARCHAR(100) NOT NULL DEFAULT 'STD V-X October 2026',
  `academic_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
  `exam_type` VARCHAR(50) NOT NULL DEFAULT 'Semester Exam',
  `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
  `notes` TEXT NULL,
  `grid_data_json` LONGTEXT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_year_status` (`academic_year`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table: timetable_columns
CREATE TABLE IF NOT EXISTS `timetable_columns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `timetable_id` INT UNSIGNED NOT NULL,
  `column_key` VARCHAR(50) NOT NULL,
  `column_name` VARCHAR(100) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX `idx_col_tt` (`timetable_id`, `sort_order`),
  CONSTRAINT `fk_tt_col` FOREIGN KEY (`timetable_id`) REFERENCES `timetables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table: timetable_rows
CREATE TABLE IF NOT EXISTS `timetable_rows` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `timetable_id` INT UNSIGNED NOT NULL,
  `exam_date` DATE NOT NULL,
  `day_name` VARCHAR(20) NOT NULL,
  `time_slot` VARCHAR(100) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX `idx_row_tt` (`timetable_id`, `sort_order`, `exam_date`),
  CONSTRAINT `fk_tt_row` FOREIGN KEY (`timetable_id`) REFERENCES `timetables` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Table: timetable_cells
CREATE TABLE IF NOT EXISTS `timetable_cells` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `timetable_id` INT UNSIGNED NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `column_id` INT UNSIGNED NOT NULL,
  `subject_name` VARCHAR(150) NOT NULL DEFAULT '--------',
  `room_or_note` VARCHAR(100) NULL,
  UNIQUE KEY `uq_row_col` (`row_id`, `column_id`),
  CONSTRAINT `fk_tt_cell_tt` FOREIGN KEY (`timetable_id`) REFERENCES `timetables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_cell_row` FOREIGN KEY (`row_id`) REFERENCES `timetable_rows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tt_cell_col` FOREIGN KEY (`column_id`) REFERENCES `timetable_columns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Table: timetable_presets
CREATE TABLE IF NOT EXISTS `timetable_presets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category` ENUM('time_slot', 'standard', 'subject') NOT NULL,
  `preset_value` VARCHAR(100) NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_preset` (`category`, `preset_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA: timetable_presets
-- ============================================================================

INSERT INTO `timetable_presets` (`category`, `preset_value`, `sort_order`) VALUES
-- Time Slots
('time_slot', '8.00 AM - 10.15 AM', 1),
('time_slot', '8.00 AM - 11.15 AM', 2),
('time_slot', '8.00 AM - 10.00 AM', 3),
('time_slot', '11.30 AM - 1.45 PM', 4),
('time_slot', '1.30 PM - 3.45 PM', 5),

-- Standards
('standard', 'STD V', 1),
('standard', 'STD VI-VIII', 2),
('standard', 'STD IX & X', 3),
('standard', 'STD I-IV', 4),
('standard', 'STD XI & XII', 5),

-- Subjects
('subject', 'ALGEBRA(M-1)', 1),
('subject', 'ENGLISH', 2),
('subject', 'MARATHI', 3),
('subject', 'HINDI', 4),
('subject', 'HINDI/SANSKRIT', 5),
('subject', 'HINDI / SANSKRIT', 6),
('subject', 'EVS - I', 7),
('subject', 'SCIENCE', 8),
('subject', 'SCIENCE-1', 9),
('subject', 'MATHS', 10),
('subject', 'GEOMETRY', 11),
('subject', 'COMP. SCI.', 12),
('subject', 'SCIENCE-2', 13),
('subject', 'EVS-II', 14),
('subject', 'HISTORY', 15),
('subject', 'DRAWING', 16),
('subject', 'GEOGRAPHY', 17),
('subject', '--------', 18)
ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`);

-- ============================================================================
-- SEED DATA: Timetable #1 - First Semester Examination Time-Table
-- ============================================================================

INSERT INTO `timetables` (
  `id`,
  `title`,
  `school_name`,
  `standards_label`,
  `academic_year`,
  `exam_type`,
  `status`,
  `notes`,
  `grid_data_json`,
  `created_by`,
  `created_at`,
  `updated_at`
) VALUES (
  1,
  'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)',
  'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JR. COLLEGE, SOLAPUR.',
  'STD V-X October 2026',
  '2026-2027',
  'Semester Exam',
  'published',
  '1. Students must be present in their respective classrooms 15 minutes before exam commencement.\n2. Borrowing of writing material / stationery is strictly prohibited.\n3. School uniform and hall ticket/ID are compulsory.',
  '{"title":"FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)","school_name":"HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JR. COLLEGE, SOLAPUR.","standards_label":"STD V-X October 2026","academic_year":"2026-2027","exam_type":"Semester Exam","columns":[{"key":"std_v","name":"STD V","sort_order":1},{"key":"std_vi_viii","name":"STD VI-VIII","sort_order":2},{"key":"std_ix_x","name":"STD IX & X","sort_order":3}],"rows":[{"sort_order":1,"exam_date":"2026-10-23","day_name":"Friday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"--------","std_vi_viii":"--------","std_ix_x":"ALGEBRA(M-1)"}},{"sort_order":2,"exam_date":"2026-10-26","day_name":"Monday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"ENGLISH","std_vi_viii":"ENGLISH","std_ix_x":"--------"}},{"sort_order":3,"exam_date":"2026-10-26","day_name":"Monday","time_slot":"8.00 AM - 11.15 AM","cells":{"std_v":"--------","std_vi_viii":"--------","std_ix_x":"ENGLISH"}},{"sort_order":4,"exam_date":"2026-10-27","day_name":"Tuesday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"MARATHI","std_vi_viii":"MARATHI","std_ix_x":"--------"}},{"sort_order":5,"exam_date":"2026-10-27","day_name":"Tuesday","time_slot":"8.00 AM - 11.15 AM","cells":{"std_v":"--------","std_vi_viii":"--------","std_ix_x":"MARATHI"}},{"sort_order":6,"exam_date":"2026-10-28","day_name":"Wednesday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"HINDI","std_vi_viii":"HINDI / SANSKRIT","std_ix_x":"--------"}},{"sort_order":7,"exam_date":"2026-10-28","day_name":"Wednesday","time_slot":"8.00 AM - 11.15 AM","cells":{"std_v":"--------","std_vi_viii":"--------","std_ix_x":"HINDI / SANSKRIT"}},{"sort_order":8,"exam_date":"2026-10-29","day_name":"Thursday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"EVS - I","std_vi_viii":"SCIENCE","std_ix_x":"SCIENCE-1"}},{"sort_order":9,"exam_date":"2026-10-30","day_name":"Friday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"MATHS","std_vi_viii":"MATHS","std_ix_x":"GEOMETRY"}},{"sort_order":10,"exam_date":"2026-11-02","day_name":"Monday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"COMP. SCI.","std_vi_viii":"COMP. SCI.","std_ix_x":"SCIENCE-2"}},{"sort_order":11,"exam_date":"2026-11-03","day_name":"Tuesday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"EVS-II","std_vi_viii":"HISTORY","std_ix_x":"HISTORY"}},{"sort_order":12,"exam_date":"2026-11-04","day_name":"Wednesday","time_slot":"8.00 AM - 10.15 AM","cells":{"std_v":"DRAWING","std_vi_viii":"GEOGRAPHY","std_ix_x":"GEOGRAPHY"}}]}',
  1,
  NOW(),
  NOW()
)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `school_name` = VALUES(`school_name`),
  `standards_label` = VALUES(`standards_label`),
  `academic_year` = VALUES(`academic_year`),
  `exam_type` = VALUES(`exam_type`),
  `status` = VALUES(`status`),
  `notes` = VALUES(`notes`),
  `grid_data_json` = VALUES(`grid_data_json`),
  `updated_at` = NOW();

-- Columns for Timetable #1
INSERT INTO `timetable_columns` (`id`, `timetable_id`, `column_key`, `column_name`, `sort_order`) VALUES
(1, 1, 'std_v', 'STD V', 1),
(2, 1, 'std_vi_viii', 'STD VI-VIII', 2),
(3, 1, 'std_ix_x', 'STD IX & X', 3)
ON DUPLICATE KEY UPDATE
  `timetable_id` = VALUES(`timetable_id`),
  `column_key` = VALUES(`column_key`),
  `column_name` = VALUES(`column_name`),
  `sort_order` = VALUES(`sort_order`);

-- Rows for Timetable #1
INSERT INTO `timetable_rows` (`id`, `timetable_id`, `exam_date`, `day_name`, `time_slot`, `sort_order`) VALUES
(1,  1, '2026-10-23', 'Friday',    '8.00 AM - 10.15 AM', 1),
(2,  1, '2026-10-26', 'Monday',    '8.00 AM - 10.15 AM', 2),
(3,  1, '2026-10-26', 'Monday',    '8.00 AM - 11.15 AM', 3),
(4,  1, '2026-10-27', 'Tuesday',   '8.00 AM - 10.15 AM', 4),
(5,  1, '2026-10-27', 'Tuesday',   '8.00 AM - 11.15 AM', 5),
(6,  1, '2026-10-28', 'Wednesday', '8.00 AM - 10.15 AM', 6),
(7,  1, '2026-10-28', 'Wednesday', '8.00 AM - 11.15 AM', 7),
(8,  1, '2026-10-29', 'Thursday',  '8.00 AM - 10.15 AM', 8),
(9,  1, '2026-10-30', 'Friday',    '8.00 AM - 10.15 AM', 9),
(10, 1, '2026-11-02', 'Monday',    '8.00 AM - 10.15 AM', 10),
(11, 1, '2026-11-03', 'Tuesday',   '8.00 AM - 10.15 AM', 11),
(12, 1, '2026-11-04', 'Wednesday', '8.00 AM - 10.15 AM', 12)
ON DUPLICATE KEY UPDATE
  `timetable_id` = VALUES(`timetable_id`),
  `exam_date` = VALUES(`exam_date`),
  `day_name` = VALUES(`day_name`),
  `time_slot` = VALUES(`time_slot`),
  `sort_order` = VALUES(`sort_order`);

-- Cells for Timetable #1 (mapping Row x Column -> Subject)
INSERT INTO `timetable_cells` (`timetable_id`, `row_id`, `column_id`, `subject_name`, `room_or_note`) VALUES
-- Row 1: 2026-10-23 (Fri, 8.00 AM - 10.15 AM)
(1, 1, 1, '--------', NULL),
(1, 1, 2, '--------', NULL),
(1, 1, 3, 'ALGEBRA(M-1)', NULL),

-- Row 2: 2026-10-26 (Mon, 8.00 AM - 10.15 AM)
(1, 2, 1, 'ENGLISH', NULL),
(1, 2, 2, 'ENGLISH', NULL),
(1, 2, 3, '--------', NULL),

-- Row 3: 2026-10-26 (Mon, 8.00 AM - 11.15 AM)
(1, 3, 1, '--------', NULL),
(1, 3, 2, '--------', NULL),
(1, 3, 3, 'ENGLISH', NULL),

-- Row 4: 2026-10-27 (Tue, 8.00 AM - 10.15 AM)
(1, 4, 1, 'MARATHI', NULL),
(1, 4, 2, 'MARATHI', NULL),
(1, 4, 3, '--------', NULL),

-- Row 5: 2026-10-27 (Tue, 8.00 AM - 11.15 AM)
(1, 5, 1, '--------', NULL),
(1, 5, 2, '--------', NULL),
(1, 5, 3, 'MARATHI', NULL),

-- Row 6: 2026-10-28 (Wed, 8.00 AM - 10.15 AM)
(1, 6, 1, 'HINDI', NULL),
(1, 6, 2, 'HINDI / SANSKRIT', NULL),
(1, 6, 3, '--------', NULL),

-- Row 7: 2026-10-28 (Wed, 8.00 AM - 11.15 AM)
(1, 7, 1, '--------', NULL),
(1, 7, 2, '--------', NULL),
(1, 7, 3, 'HINDI / SANSKRIT', NULL),

-- Row 8: 2026-10-29 (Thu, 8.00 AM - 10.15 AM)
(1, 8, 1, 'EVS - I', NULL),
(1, 8, 2, 'SCIENCE', NULL),
(1, 8, 3, 'SCIENCE-1', NULL),

-- Row 9: 2026-10-30 (Fri, 8.00 AM - 10.15 AM)
(1, 9, 1, 'MATHS', NULL),
(1, 9, 2, 'MATHS', NULL),
(1, 9, 3, 'GEOMETRY', NULL),

-- Row 10: 2026-11-02 (Mon, 8.00 AM - 10.15 AM)
(1, 10, 1, 'COMP. SCI.', NULL),
(1, 10, 2, 'COMP. SCI.', NULL),
(1, 10, 3, 'SCIENCE-2', NULL),

-- Row 11: 2026-11-03 (Tue, 8.00 AM - 10.15 AM)
(1, 11, 1, 'EVS-II', NULL),
(1, 11, 2, 'HISTORY', NULL),
(1, 11, 3, 'HISTORY', NULL),

-- Row 12: 2026-11-04 (Wed, 8.00 AM - 10.15 AM)
(1, 12, 1, 'DRAWING', NULL),
(1, 12, 2, 'GEOGRAPHY', NULL),
(1, 12, 3, 'GEOGRAPHY', NULL)
ON DUPLICATE KEY UPDATE
  `timetable_id` = VALUES(`timetable_id`),
  `subject_name` = VALUES(`subject_name`),
  `room_or_note` = VALUES(`room_or_note`);
