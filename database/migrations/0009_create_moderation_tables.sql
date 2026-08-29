-- The approval engine from PLAN.md section 5.
--
-- One table, one state machine, many subject types: a file upload, a category
-- proposal, a collection publication, a librarian application and a takedown all
-- move through the same states, so the queue screen and the audit trail are the
-- same code for all of them.
--
-- subject_type is a string rather than an enum because the list grows every
-- milestone and an ALTER on an enum is a schema change for what is really data.

-- @up
CREATE TABLE `moderation_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_type` VARCHAR(40) NOT NULL,
    `subject_id` BIGINT UNSIGNED NULL,
    `submitter_id` BIGINT UNSIGNED NULL,
    `assignee_id` BIGINT UNSIGNED NULL,
    `status` ENUM('draft', 'pending', 'under_review', 'changes_requested', 'approved', 'rejected', 'withdrawn')
        NOT NULL DEFAULT 'pending',
    `title` VARCHAR(255) NOT NULL,
    `payload` JSON NULL,
    `reason` VARCHAR(255) NULL,
    `claimed_until` TIMESTAMP NULL DEFAULT NULL,
    `decided_at` TIMESTAMP NULL DEFAULT NULL,
    `decided_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `moderation_status_type_index` (`status`, `subject_type`, `created_at`),
    KEY `moderation_subject_index` (`subject_type`, `subject_id`),
    KEY `moderation_submitter_index` (`submitter_id`, `status`),
    KEY `moderation_assignee_index` (`assignee_id`, `status`),
    CONSTRAINT `moderation_submitter_fk` FOREIGN KEY (`submitter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `moderation_assignee_fk` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `moderation_decider_fk` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `moderation_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` BIGINT UNSIGNED NOT NULL,
    `actor_id` BIGINT UNSIGNED NULL,
    `from_status` VARCHAR(24) NULL,
    `to_status` VARCHAR(24) NOT NULL,
    `note` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `moderation_events_request_index` (`request_id`, `id`),
    CONSTRAINT `moderation_events_request_fk` FOREIGN KEY (`request_id`)
        REFERENCES `moderation_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `moderation_events_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `moderation_comments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` BIGINT UNSIGNED NOT NULL,
    `author_id` BIGINT UNSIGNED NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `moderation_comments_request_index` (`request_id`, `id`),
    CONSTRAINT `moderation_comments_request_fk` FOREIGN KEY (`request_id`)
        REFERENCES `moderation_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `moderation_comments_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `moderation_comments`;
DROP TABLE IF EXISTS `moderation_events`;
DROP TABLE IF EXISTS `moderation_requests`;
