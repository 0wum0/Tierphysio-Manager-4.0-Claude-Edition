CREATE TABLE IF NOT EXISTS `user_preferences` (
    `user_id`    INT          NOT NULL,
    `pref_key`   VARCHAR(100) NOT NULL,
    `pref_value` TEXT         NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
