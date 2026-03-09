CREATE TABLE IF NOT EXISTS `tax_export_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(120) NOT NULL,
    `setting_value` TEXT         NOT NULL DEFAULT '',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_tax_export_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tax_export_logs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `export_type`   ENUM('csv','zip','pdf') NOT NULL DEFAULT 'csv',
    `date_from`     DATE         NOT NULL,
    `date_to`       DATE         NOT NULL,
    `status_filter` VARCHAR(30)  NOT NULL DEFAULT '',
    `row_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `exported_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_tax_export_logs_exported_at` (`exported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `tax_export_settings` (`setting_key`, `setting_value`) VALUES
    ('csv_delimiter',    ';'),
    ('filename_schema',  'steuerexport-{year}-{month}'),
    ('company_tax_info', ''),
    ('consider_paid_at', '0')
