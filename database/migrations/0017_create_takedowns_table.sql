-- Takedown notices and librarian applications.
--
-- Both are things a person asks for and an admin decides, so both get a queue
-- item; these tables hold the detail the queue does not.
--
-- A takedown can be raised by anyone, including someone with no account: a
-- rights holder should not have to join a library to ask it to stop hosting
-- their book. See PLAN.md section 9.

-- @up
CREATE TABLE `takedowns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `book_id` BIGINT UNSIGNED NULL,
    `subject_url` VARCHAR(500) NULL,
    `claimant_name` VARCHAR(160) NOT NULL,
    `claimant_email` VARCHAR(191) NOT NULL,
    `claimant_role` VARCHAR(160) NULL,
    `basis` VARCHAR(2000) NOT NULL,
    `status` ENUM('open', 'upheld', 'rejected') NOT NULL DEFAULT 'open',
    `outcome_note` VARCHAR(500) NULL,
    `handled_by` BIGINT UNSIGNED NULL,
    `handled_at` TIMESTAMP NULL DEFAULT NULL,
    `ip_hash` CHAR(64) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `takedowns_status_index` (`status`, `created_at`),
    KEY `takedowns_book_index` (`book_id`),
    CONSTRAINT `takedowns_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE SET NULL,
    CONSTRAINT `takedowns_handler_fk` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `librarian_applications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `statement` VARCHAR(2000) NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `decided_by` BIGINT UNSIGNED NULL,
    `decided_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `librarian_applications_user_index` (`user_id`, `status`),
    CONSTRAINT `librarian_applications_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `librarian_applications_decider_fk` FOREIGN KEY (`decided_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`, `group`, `description`) VALUES
    ('site.maintenance', 'false', 'general', 'Turns the site read-only for everyone but admins'),
    ('site.maintenance_message', '""', 'general', 'Shown on the maintenance page'),
    ('uploads.max_bytes', '209715200', 'uploads', 'Largest file the site accepts, in bytes'),
    ('uploads.default_quota', '2147483648', 'uploads', 'Storage a new account gets, in bytes'),
    ('features.reviews', 'true', 'features', 'Whether members can review books'),
    ('features.requests', 'true', 'features', 'Whether members can ask for books');

-- @down
DELETE FROM `settings` WHERE `key` IN (
    'site.maintenance', 'site.maintenance_message', 'uploads.max_bytes',
    'uploads.default_quota', 'features.reviews', 'features.requests'
);

DROP TABLE IF EXISTS `librarian_applications`;
DROP TABLE IF EXISTS `takedowns`;
