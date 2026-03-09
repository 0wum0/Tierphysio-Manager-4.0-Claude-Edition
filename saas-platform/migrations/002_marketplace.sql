-- Migration: 002_marketplace
-- Adds Plugin Marketplace tables

-- ------------------------------------------------------------
-- Marketplace: Plugin-Angebote
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marketplace_plugins` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug`         VARCHAR(100) NOT NULL UNIQUE,
  `name`         VARCHAR(200) NOT NULL,
  `description`  TEXT,
  `long_desc`    LONGTEXT,
  `category`     VARCHAR(100) DEFAULT 'Allgemein',
  `icon`         VARCHAR(100) DEFAULT 'bi-puzzle',
  `price`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_type`   ENUM('one_time','monthly','free') NOT NULL DEFAULT 'one_time',
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `is_featured`  TINYINT(1) NOT NULL DEFAULT 0,
  `version`      VARCHAR(20) DEFAULT '1.0.0',
  `screenshots`  JSON COMMENT 'Array von Bild-URLs',
  `requirements` JSON COMMENT 'Mindest-Abo-Plan etc.',
  `sort_order`   INT NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Marketplace: Käufe / freigeschaltete Plugins pro Tenant
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marketplace_purchases` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `plugin_id`      INT UNSIGNED NOT NULL,
  `status`         ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
  `payment_method` ENUM('stripe','paypal','manual') NOT NULL DEFAULT 'manual',
  `payment_ref`    VARCHAR(200) COMMENT 'Stripe/PayPal Transaction ID',
  `amount_paid`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `plugin_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Tenant can toggle plugin on/off',
  `activated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`     DATETIME COMMENT 'NULL = lifetime',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tenant_plugin` (`tenant_id`, `plugin_id`),
  CONSTRAINT `fk_mp_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mp_plugin` FOREIGN KEY (`plugin_id`) REFERENCES `marketplace_plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
