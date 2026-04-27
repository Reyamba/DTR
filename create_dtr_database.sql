-- DTR System database schema
-- Run this in MySQL or phpMyAdmin to create the database and tables.

CREATE DATABASE IF NOT EXISTS `dtr_system`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `dtr_system`;

CREATE TABLE IF NOT EXISTS `dtr_uploads` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `upload_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `march_total_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `april_total_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `grand_total_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `march_total_text` VARCHAR(64) NOT NULL,
  `april_total_text` VARCHAR(64) NOT NULL,
  `grand_total_text` VARCHAR(64) NOT NULL,
  `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  INDEX (`upload_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dtr_daily_records` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `upload_id` INT UNSIGNED NOT NULL,
  `record_month` ENUM('March','April') NOT NULL,
  `row_number` INT UNSIGNED NOT NULL,
  `raw_day_label` VARCHAR(128) NULL,
  `am_in` VARCHAR(16) NULL,
  `am_out` VARCHAR(16) NULL,
  `pm_in` VARCHAR(16) NULL,
  `pm_out` VARCHAR(16) NULL,
  `daily_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `daily_text` VARCHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`upload_id`),
  INDEX (`record_month`),
  CONSTRAINT `fk_dtr_daily_records_upload`
    FOREIGN KEY (`upload_id`) REFERENCES `dtr_uploads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
