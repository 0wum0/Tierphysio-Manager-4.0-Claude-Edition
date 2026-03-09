-- ============================================================
-- Tierphysio SaaS Platform - Initial Schema
-- Migration 001
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Abo-Pläne
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`        VARCHAR(50)  NOT NULL UNIQUE,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT,
  `price_month` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_year`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_users`   INT NOT NULL DEFAULT 1 COMMENT '-1 = unbegrenzt',
  `features`    JSON NOT NULL COMMENT 'Liste freigeschalteter Features',
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Kunden (Praxen / Tenants)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`          CHAR(36)     NOT NULL UNIQUE,
  `practice_name` VARCHAR(200) NOT NULL,
  `owner_name`    VARCHAR(200) NOT NULL,
  `email`         VARCHAR(200) NOT NULL UNIQUE,
  `phone`         VARCHAR(50),
  `address`       VARCHAR(300),
  `city`          VARCHAR(100),
  `zip`           VARCHAR(20),
  `country`       CHAR(2) NOT NULL DEFAULT 'DE',
  `plan_id`       INT UNSIGNED NOT NULL,
  `status`        ENUM('pending','active','paused','cancelled','suspended') NOT NULL DEFAULT 'pending',
  `table_prefix`  VARCHAR(30)  COMMENT 'Tabellen-Präfix in der gemeinsamen DB, z.B. tpm1_',
  `db_created`    TINYINT(1) NOT NULL DEFAULT 0,
  `admin_created` TINYINT(1) NOT NULL DEFAULT 0,
  `trial_ends_at` DATETIME,
  `notes`         TEXT,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_tenants_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Abonnements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `plan_id`        INT UNSIGNED NOT NULL,
  `billing_cycle`  ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `status`         ENUM('active','past_due','cancelled','trialing') NOT NULL DEFAULT 'active',
  `started_at`     DATETIME NOT NULL,
  `ends_at`        DATETIME,
  `cancelled_at`   DATETIME,
  `next_billing`   DATETIME,
  `amount`         DECIMAL(10,2) NOT NULL,
  `currency`       CHAR(3) NOT NULL DEFAULT 'EUR',
  `payment_method` VARCHAR(100),
  `external_id`    VARCHAR(200) COMMENT 'ID bei Stripe/PayPal etc.',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sub_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_plan`   FOREIGN KEY (`plan_id`)   REFERENCES `plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Zahlungen / Rechnungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id`   INT UNSIGNED NOT NULL,
  `sub_id`      INT UNSIGNED,
  `amount`      DECIMAL(10,2) NOT NULL,
  `currency`    CHAR(3) NOT NULL DEFAULT 'EUR',
  `status`      ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `method`      VARCHAR(100),
  `external_id` VARCHAR(200),
  `invoice_nr`  VARCHAR(50),
  `paid_at`     DATETIME,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_pay_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_sub`    FOREIGN KEY (`sub_id`)    REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Lizenz-Tokens (für Offline-Prüfung)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `license_tokens` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `token_hash`   VARCHAR(255) NOT NULL UNIQUE COMMENT 'SHA-256 des ausgestellten Tokens',
  `issued_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME NOT NULL,
  `revoked`      TINYINT(1) NOT NULL DEFAULT 0,
  `revoked_at`   DATETIME,
  `last_seen_at` DATETIME,
  `ip_address`   VARCHAR(45),
  CONSTRAINT `fk_lic_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SaaS-Admin-Benutzer (Plattform-Betreiber)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_admins` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(200) NOT NULL,
  `email`      VARCHAR(200) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('superadmin','admin','support') NOT NULL DEFAULT 'admin',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` DATETIME,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Rechtliche Dokumente
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `legal_documents` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`       VARCHAR(100) NOT NULL UNIQUE,
  `title`      VARCHAR(300) NOT NULL,
  `content`    LONGTEXT NOT NULL,
  `version`    VARCHAR(20) NOT NULL DEFAULT '1.0',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Zustimmungen zu rechtlichen Dokumenten
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `legal_acceptances` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id`   INT UNSIGNED NOT NULL,
  `document_id` INT UNSIGNED NOT NULL,
  `version`     VARCHAR(20) NOT NULL,
  `ip_address`  VARCHAR(45),
  `accepted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_acc_tenant` FOREIGN KEY (`tenant_id`)   REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acc_doc`    FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Aktivitäts-Log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `actor_type` ENUM('saas_admin','tenant','system') NOT NULL DEFAULT 'system',
  `actor_id`   INT UNSIGNED,
  `action`     VARCHAR(200) NOT NULL,
  `subject`    VARCHAR(200),
  `subject_id` INT UNSIGNED,
  `details`    JSON,
  `ip_address` VARCHAR(45),
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Einstellungen der SaaS-Plattform
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `saas_settings` (
  `key`        VARCHAR(100) PRIMARY KEY,
  `value`      LONGTEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Standard-Pläne einfügen
-- ------------------------------------------------------------
INSERT IGNORE INTO `plans` (`slug`, `name`, `description`, `price_month`, `price_year`, `max_users`, `features`, `sort_order`) VALUES
('basic', 'Basic', 'Ideal für einzelne Therapeuten', 29.00, 290.00, 1,
 '["invoices","appointments","patients","owners"]', 1),
('pro', 'Pro', 'Für wachsende Praxen mit mehreren Mitarbeitern', 59.00, 590.00, 10,
 '["invoices","appointments","patients","owners","dashboard","waitlist","intake","staff"]', 2),
('praxis', 'Praxis', 'Voller Funktionsumfang ohne Einschränkungen', 99.00, 990.00, -1,
 '["invoices","appointments","patients","owners","dashboard","waitlist","intake","staff","reports","premium"]', 3);

-- ------------------------------------------------------------
-- Standard-Rechtsdokumente
-- ------------------------------------------------------------
INSERT IGNORE INTO `legal_documents` (`slug`, `title`, `content`, `version`) VALUES
('datenschutz', 'Datenschutzerklärung',
'# Datenschutzerklärung\n\n## 1. Verantwortlicher\n\nVerantwortlicher im Sinne der DSGVO ist der Betreiber dieser SaaS-Plattform.\n\n## 2. Erhobene Daten\n\nWir erheben folgende personenbezogene Daten:\n- Name und Anschrift\n- E-Mail-Adresse\n- Telefonnummer\n- Zahlungsdaten\n\n## 3. Zweck der Verarbeitung\n\nDie Daten werden zur Bereitstellung der SaaS-Dienste verarbeitet.\n\n## 4. Speicherdauer\n\nDaten werden nach Kündigung des Vertrags innerhalb von 30 Tagen gelöscht.\n\n## 5. Ihre Rechte\n\nSie haben das Recht auf Auskunft, Berichtigung, Löschung und Einschränkung der Verarbeitung.',
'1.0'),
('agb', 'Allgemeine Geschäftsbedingungen',
'# Allgemeine Geschäftsbedingungen\n\n## 1. Geltungsbereich\n\nDiese AGB gelten für alle Verträge über die Nutzung der Tierphysio Manager SaaS-Plattform.\n\n## 2. Vertragsschluss\n\nDer Vertrag kommt mit der Aktivierung des Abonnements zustande.\n\n## 3. Leistungsumfang\n\nDer Funktionsumfang richtet sich nach dem gebuchten Tarif (Basic, Pro, Praxis).\n\n## 4. Laufzeit und Kündigung\n\nMonatliche Abonnements sind monatlich kündbar. Jahresabonnements enden nach 12 Monaten.\n\n## 5. Preise und Zahlung\n\nDie aktuellen Preise sind auf der Website einsehbar. Die Zahlung erfolgt monatlich oder jährlich im Voraus.\n\n## 6. Datenschutz\n\nDie Verarbeitung personenbezogener Daten erfolgt gemäß unserer Datenschutzerklärung.',
'1.0'),
('av-vertrag', 'Auftragsverarbeitungsvertrag (AVV)',
'# Auftragsverarbeitungsvertrag\n\ngemäß Art. 28 DSGVO\n\n## 1. Gegenstand\n\nDieser Vertrag regelt die Verarbeitung personenbezogener Daten durch den Auftragsverarbeiter (Plattformbetreiber) im Auftrag des Verantwortlichen (Kunden).\n\n## 2. Art der Daten\n\nVerarbeitet werden Daten von Tierhaltern und Patienten (Tieren) der therapeutischen Einrichtung.\n\n## 3. Weisungsgebundenheit\n\nDer Auftragsverarbeiter verarbeitet Daten ausschließlich auf dokumentierte Weisung des Verantwortlichen.\n\n## 4. Vertraulichkeit\n\nDer Auftragsverarbeiter gewährleistet, dass Personen, die Zugang zu den Daten haben, zur Vertraulichkeit verpflichtet sind.\n\n## 5. Technische und organisatorische Maßnahmen\n\nDer Auftragsverarbeiter implementiert geeignete TOMs gemäß Art. 32 DSGVO.\n\n## 6. Unterauftragsverarbeiter\n\nEine Weitergabe an Unterauftragsverarbeiter bedarf der vorherigen schriftlichen Genehmigung.\n\n## 7. Löschung\n\nNach Vertragsende werden alle Daten innerhalb von 30 Tagen gelöscht oder zurückgegeben.',
'1.0');
