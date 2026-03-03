CREATE TABLE IF NOT EXISTS `patient_invite_tokens` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token`         VARCHAR(64)  NOT NULL,
    `email`         VARCHAR(255) NOT NULL DEFAULT '',
    `phone`         VARCHAR(60)  NOT NULL DEFAULT '',
    `note`          TEXT         NOT NULL,
    `status`        ENUM('offen','angenommen','abgelaufen') NOT NULL DEFAULT 'offen',
    `sent_via`      ENUM('email','whatsapp','both') NOT NULL DEFAULT 'email',
    `created_by`    INT UNSIGNED NULL DEFAULT NULL,
    `accepted_patient_id` INT UNSIGNED NULL DEFAULT NULL,
    `accepted_owner_id`   INT UNSIGNED NULL DEFAULT NULL,
    `expires_at`    DATETIME     NOT NULL,
    `accepted_at`   DATETIME     NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    INDEX `idx_status` (`status`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
