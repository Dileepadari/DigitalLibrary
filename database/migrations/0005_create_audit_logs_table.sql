-- Who did what to whom. Written for role changes, bans and every other
-- privileged action from here on. The admin viewer arrives with M8; the rows
-- start accumulating now, because a log that starts late is worth little.

-- @up
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(64) NOT NULL,
    `subject_type` VARCHAR(40) NULL,
    `subject_id` BIGINT UNSIGNED NULL,
    `before_state` JSON NULL,
    `after_state` JSON NULL,
    `ip_hash` CHAR(64) NULL,
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `audit_logs_actor_index` (`actor_id`, `created_at`),
    KEY `audit_logs_subject_index` (`subject_type`, `subject_id`),
    KEY `audit_logs_action_index` (`action`, `created_at`),
    CONSTRAINT `audit_logs_actor_fk` FOREIGN KEY (`actor_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `audit_logs`;
