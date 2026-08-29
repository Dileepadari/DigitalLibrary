-- Single-use tokens for email verification and password resets, plus the failed
-- login record the throttle reads. Only the hash of a token is stored, so a leak
-- of this table does not hand out working links.

-- @up
CREATE TABLE `auth_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `type` ENUM('email_verification', 'password_reset') NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `auth_tokens_hash_unique` (`token_hash`),
    KEY `auth_tokens_user_type_index` (`user_id`, `type`),
    CONSTRAINT `auth_tokens_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(191) NOT NULL,
    `ip_hash` CHAR(64) NOT NULL,
    `successful` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `login_attempts_identifier_index` (`identifier`, `created_at`),
    KEY `login_attempts_ip_index` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `auth_tokens`;
