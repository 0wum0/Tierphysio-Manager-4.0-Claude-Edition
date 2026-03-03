-- Tierphysio Manager 3.0 - Treatment Types
-- Migration 002

CREATE TABLE IF NOT EXISTS `treatment_types` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `color`       VARCHAR(7)   NOT NULL DEFAULT '#4f7cff',
    `price`       DECIMAL(10,2) NULL,
    `description` TEXT NULL,
    `active`      TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `patient_timeline`
    ADD COLUMN IF NOT EXISTS `treatment_type_id` INT UNSIGNED NULL AFTER `type`,
    ADD CONSTRAINT `fk_timeline_treatment` FOREIGN KEY (`treatment_type_id`) REFERENCES `treatment_types` (`id`) ON DELETE SET NULL;

INSERT IGNORE INTO `treatment_types` (`name`, `color`, `price`, `sort_order`) VALUES
    ('Physiotherapie',        '#4f7cff', NULL, 1),
    ('Massage',               '#a855f7', NULL, 2),
    ('Akupunktur',            '#22c55e', NULL, 3),
    ('Hydrotherapie',         '#06b6d4', NULL, 4),
    ('Elektrotherapie',       '#f59e0b', NULL, 5),
    ('Manuelle Therapie',     '#ef4444', NULL, 6),
    ('Kontrolle',             '#9090b0', NULL, 7);
