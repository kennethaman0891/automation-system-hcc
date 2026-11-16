-- HCC Academic & Daily Operations Tables Migration (2026-11)
-- Idempotent migration for hcc_admin

CREATE TABLE IF NOT EXISTS `students_master` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gr_no` VARCHAR(64) NOT NULL,
  `roll_no` INT NULL DEFAULT NULL,
  `student_name` VARCHAR(180) NOT NULL,
  `date_of_birth` DATE NULL DEFAULT NULL,
  `caste_category` VARCHAR(80) NOT NULL DEFAULT '',
  `parent_phone` VARCHAR(32) NOT NULL DEFAULT '',
  `std` VARCHAR(16) NOT NULL DEFAULT '',
  `division` VARCHAR(16) NOT NULL DEFAULT '',
  `academic_year` VARCHAR(20) NOT NULL DEFAULT '',
  `status` ENUM('active','promoted','alumni','left') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_students_master_gr` (`gr_no`),
  KEY `idx_students_master_cohort` (`academic_year`, `std`, `division`, `roll_no`),
  KEY `idx_students_master_name` (`student_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_timetable_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timetable_id` INT UNSIGNED NULL DEFAULT NULL,
  `std` VARCHAR(16) NOT NULL,
  `division` VARCHAR(16) NOT NULL DEFAULT 'A',
  `subject_name` VARCHAR(120) NOT NULL,
  `teacher_name` VARCHAR(160) NOT NULL,
  `teacher_phone` VARCHAR(32) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ttm_timetable` (`timetable_id`),
  KEY `idx_ttm_class_subj` (`std`, `division`, `subject_name`),
  KEY `idx_ttm_teacher` (`teacher_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_substitutions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sub_date` DATE NOT NULL,
  `period_label` VARCHAR(64) NOT NULL,
  `std` VARCHAR(16) NOT NULL,
  `division` VARCHAR(16) NOT NULL DEFAULT 'A',
  `absent_teacher` VARCHAR(160) NOT NULL,
  `substitute_teacher` VARCHAR(160) NOT NULL,
  `subject_name` VARCHAR(120) NOT NULL DEFAULT '',
  `whatsapp_notified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_daily_sub_slot` (`sub_date`, `period_label`, `std`, `division`),
  KEY `idx_daily_sub_date` (`sub_date`),
  KEY `idx_daily_sub_absent` (`sub_date`, `absent_teacher`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_seating_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timetable_id` INT UNSIGNED NULL DEFAULT NULL,
  `exam_date` DATE NOT NULL,
  `slot_label` VARCHAR(64) NOT NULL DEFAULT '',
  `room_name` VARCHAR(120) NOT NULL,
  `bench_capacity` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `plan_json` LONGTEXT NOT NULL,
  `created_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seating_date_room` (`exam_date`, `room_name`),
  KEY `idx_seating_timetable` (`timetable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
