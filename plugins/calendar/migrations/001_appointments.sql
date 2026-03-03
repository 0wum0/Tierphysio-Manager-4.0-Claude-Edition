CREATE TABLE IF NOT EXISTS `appointments` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`             VARCHAR(255) NOT NULL,
    `description`       TEXT NULL,
    `start_at`          DATETIME NOT NULL,
    `end_at`            DATETIME NOT NULL,
    `all_day`           TINYINT(1) NOT NULL DEFAULT 0,
    `status`            ENUM('scheduled','confirmed','cancelled','completed','noshow') NOT NULL DEFAULT 'scheduled',
    `color`             VARCHAR(7) NULL DEFAULT NULL,
    `patient_id`        INT UNSIGNED NULL DEFAULT NULL,
    `owner_id`          INT UNSIGNED NULL DEFAULT NULL,
    `treatment_type_id` INT UNSIGNED NULL DEFAULT NULL,
    `user_id`           INT UNSIGNED NULL DEFAULT NULL,
    `recurrence_rule`   VARCHAR(512) NULL DEFAULT NULL,
    `recurrence_parent` INT UNSIGNED NULL DEFAULT NULL,
    `notes`             TEXT NULL,
    `reminder_sent`     TINYINT(1) NOT NULL DEFAULT 0,
    `reminder_minutes`  SMALLINT UNSIGNED NULL DEFAULT 60,
    `invoice_id`        INT UNSIGNED NULL DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_start_at` (`start_at`),
    INDEX `idx_status` (`status`),
    INDEX `idx_patient` (`patient_id`),
    INDEX `idx_owner` (`owner_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appointment_waitlist` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `patient_id`    INT UNSIGNED NULL DEFAULT NULL,
    `owner_id`      INT UNSIGNED NULL DEFAULT NULL,
    `treatment_type_id` INT UNSIGNED NULL DEFAULT NULL,
    `preferred_date` DATE NULL DEFAULT NULL,
    `notes`         TEXT NULL,
    `status`        ENUM('waiting','scheduled','cancelled') NOT NULL DEFAULT 'waiting',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
