-- Accounts. One primary role per user; individual permission grants and revokes
-- live in user_permissions so an admin can adjust one person without promoting
-- them.

-- @up
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `username` VARCHAR(40) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('member', 'librarian', 'admin') NOT NULL DEFAULT 'member',
    `status` ENUM('active', 'muted', 'banned') NOT NULL DEFAULT 'active',
    `bio` VARCHAR(500) NULL,
    `avatar_path` VARCHAR(255) NULL,
    `reputation` INT NOT NULL DEFAULT 0,
    `storage_used` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `storage_quota` BIGINT UNSIGNED NOT NULL DEFAULT 2147483648,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `status_reason` VARCHAR(255) NULL,
    `status_until` TIMESTAMP NULL DEFAULT NULL,
    `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`),
    UNIQUE KEY `users_username_unique` (`username`),
    KEY `users_role_status_index` (`role`, `status`),
    KEY `users_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `users`;
