-- ============================================================
-- Tierphysio Tenant Schema (Shared DB, Table Prefix)
-- {{PREFIX}} wird zur Laufzeit durch das echte Präfix ersetzt
-- Beispiel: tpm1_users, tpm2_users, ...
-- Dieses Schema spiegelt exakt die Praxissoftware-Migrationen 001-010
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── users ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}users` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `email`      VARCHAR(255) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `role`       ENUM('admin','mitarbeiter') NOT NULL DEFAULT 'mitarbeiter',
    `active`     TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── owners ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}owners` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name`  VARCHAR(100) NOT NULL,
    `email`      VARCHAR(255) NULL,
    `phone`      VARCHAR(50) NULL,
    `birth_date` DATE NULL,
    `street`     VARCHAR(255) NULL,
    `zip`        VARCHAR(10) NULL,
    `city`       VARCHAR(100) NULL,
    `notes`      TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_last_name` (`last_name`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── patients ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}patients` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_id`      INT UNSIGNED NOT NULL,
    `name`          VARCHAR(255) NOT NULL,
    `species`       VARCHAR(100) NULL,
    `breed`         VARCHAR(100) NULL,
    `birth_date`    DATE NULL,
    `gender`        ENUM('männlich','weiblich','kastriert','sterilisiert','unbekannt') NULL DEFAULT 'unbekannt',
    `color`         VARCHAR(100) NULL,
    `chip_number`   VARCHAR(50) NULL,
    `photo`         VARCHAR(255) NULL,
    `status`        VARCHAR(50) NOT NULL DEFAULT 'aktiv',
    `deceased_date` DATE NULL,
    `notes`         TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_owner_id` (`owner_id`),
    INDEX `idx_name` (`name`),
    CONSTRAINT `fk_{{PREFIX}}patients_owner` FOREIGN KEY (`owner_id`) REFERENCES `{{PREFIX}}owners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── treatment_types ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}treatment_types` (
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

-- ── patient_timeline ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}patient_timeline` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`        INT UNSIGNED NOT NULL,
    `user_id`           INT UNSIGNED NULL,
    `type`              ENUM('note','treatment','photo','document','other') NOT NULL DEFAULT 'note',
    `treatment_type_id` INT UNSIGNED NULL,
    `title`             VARCHAR(255) NOT NULL DEFAULT '',
    `content`           TEXT NULL,
    `status_badge`      VARCHAR(100) NULL,
    `attachment`        VARCHAR(255) NULL,
    `entry_date`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_entry_date` (`entry_date`),
    CONSTRAINT `fk_{{PREFIX}}timeline_patient` FOREIGN KEY (`patient_id`) REFERENCES `{{PREFIX}}patients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_{{PREFIX}}timeline_user`    FOREIGN KEY (`user_id`)    REFERENCES `{{PREFIX}}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── invoices ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}invoices` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `owner_id`       INT UNSIGNED NOT NULL,
    `patient_id`     INT UNSIGNED NULL,
    `status`         ENUM('draft','open','paid','overdue') NOT NULL DEFAULT 'draft',
    `issue_date`     DATE NOT NULL,
    `due_date`       DATE NULL,
    `total_net`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_tax`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_gross`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `notes`          TEXT NULL,
    `payment_terms`  TEXT NULL,
    `payment_method` ENUM('rechnung','bar') NOT NULL DEFAULT 'rechnung',
    `paid_at`        DATETIME NULL,
    `email_sent_at`  DATETIME NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_owner_id`       (`owner_id`),
    INDEX `idx_patient_id`     (`patient_id`),
    INDEX `idx_status`         (`status`),
    INDEX `idx_issue_date`     (`issue_date`),
    INDEX `idx_payment_method` (`payment_method`),
    CONSTRAINT `fk_{{PREFIX}}invoices_owner`   FOREIGN KEY (`owner_id`)   REFERENCES `{{PREFIX}}owners` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_{{PREFIX}}invoices_patient` FOREIGN KEY (`patient_id`) REFERENCES `{{PREFIX}}patients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── invoice_positions ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}invoice_positions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id`  INT UNSIGNED NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `unit_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax_rate`    DECIMAL(5,2)  NOT NULL DEFAULT 19.00,
    `total`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `sort_order`  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_invoice_id` (`invoice_id`),
    CONSTRAINT `fk_{{PREFIX}}positions_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `{{PREFIX}}invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── settings ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}settings` (
    `key`        VARCHAR(100) NOT NULL,
    `value`      TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_preferences ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `{{PREFIX}}user_preferences` (
    `user_id`    INT          NOT NULL,
    `pref_key`   VARCHAR(100) NOT NULL,
    `pref_value` TEXT         NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed: settings defaults ────────────────────────────────────────────────
INSERT IGNORE INTO `{{PREFIX}}settings` (`key`, `value`) VALUES
('company_name',         ''),
('company_street',       ''),
('company_zip',          ''),
('company_city',         ''),
('company_phone',        ''),
('company_email',        ''),
('company_website',      ''),
('company_logo',         ''),
('bank_name',            ''),
('bank_iban',            ''),
('bank_bic',             ''),
('tax_number',           ''),
('vat_number',           ''),
('default_tax_rate',     '19'),
('payment_terms',        'Bitte überweisen Sie den Betrag innerhalb von 14 Tagen.'),
('invoice_prefix',       'RE'),
('invoice_start_number', '1000'),
('smtp_host',            'localhost'),
('smtp_port',            '587'),
('smtp_username',        ''),
('smtp_password',        ''),
('smtp_encryption',      'tls'),
('mail_from_address',    ''),
('mail_from_name',       ''),
('default_language',     'de'),
('default_theme',        'dark'),
('tenant_uuid',          ''),
('license_token',        ''),
('license_checked_at',   ''),
('db_version',           '1');

-- ── Seed: default treatment types ─────────────────────────────────────────
INSERT IGNORE INTO `{{PREFIX}}treatment_types` (`name`, `color`, `price`, `sort_order`) VALUES
('Physiotherapie',    '#4f7cff', NULL, 1),
('Massage',           '#a855f7', NULL, 2),
('Akupunktur',        '#22c55e', NULL, 3),
('Hydrotherapie',     '#06b6d4', NULL, 4),
('Elektrotherapie',   '#f59e0b', NULL, 5),
('Manuelle Therapie', '#ef4444', NULL, 6),
('Kontrolle',         '#9090b0', NULL, 7);

SET FOREIGN_KEY_CHECKS = 1;
