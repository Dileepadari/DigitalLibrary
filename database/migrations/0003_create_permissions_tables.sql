-- The permission matrix from PLAN.md section 3. Roles get permissions through
-- role_permissions; user_permissions overrides that for one person, in either
-- direction (`grant` adds, `revoke` takes away).

-- @up
CREATE TABLE `permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `area` VARCHAR(32) NOT NULL DEFAULT 'general',
    PRIMARY KEY (`id`),
    UNIQUE KEY `permissions_key_unique` (`key`),
    KEY `permissions_area_index` (`area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
    `role` ENUM('member', 'librarian', 'admin') NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role`, `permission_id`),
    CONSTRAINT `role_permissions_permission_fk` FOREIGN KEY (`permission_id`)
        REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_permissions` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `effect` ENUM('grant', 'revoke') NOT NULL DEFAULT 'grant',
    `granted_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `permission_id`),
    KEY `user_permissions_permission_index` (`permission_id`),
    CONSTRAINT `user_permissions_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `user_permissions_permission_fk` FOREIGN KEY (`permission_id`)
        REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`key`, `description`, `area`) VALUES
    ('catalog.browse', 'Browse and search the catalogue', 'catalog'),
    ('book.read', 'Open a book in the reader', 'catalog'),
    ('book.download', 'Download a book file', 'catalog'),
    ('book.upload', 'Submit a book to the review queue', 'contribute'),
    ('book.publish', 'Publish a book without review', 'moderate'),
    ('book.edit.own', 'Edit a book you submitted', 'contribute'),
    ('book.edit.any', 'Edit any book', 'moderate'),
    ('book.delete.soft', 'Soft delete a book', 'moderate'),
    ('book.delete.hard', 'Permanently delete a book and its files', 'admin'),
    ('request.create', 'Raise a book request', 'contribute'),
    ('request.vote', 'Upvote a book request', 'contribute'),
    ('request.fulfil', 'Fulfil a book request', 'contribute'),
    ('request.close', 'Close any book request', 'moderate'),
    ('taxonomy.propose', 'Propose a category or tag', 'contribute'),
    ('taxonomy.manage', 'Create, edit and merge categories and tags', 'moderate'),
    ('collection.create.private', 'Build a private collection', 'contribute'),
    ('collection.propose.public', 'Propose a collection for publication', 'contribute'),
    ('collection.approve', 'Approve a public collection', 'moderate'),
    ('review.write', 'Write reviews and ratings', 'community'),
    ('review.moderate', 'Hide or remove reviews', 'moderate'),
    ('moderation.queue', 'Open the moderation queue and decide items', 'moderate'),
    ('librarian.apply', 'Apply to become a librarian', 'contribute'),
    ('librarian.approve', 'Approve librarian applications', 'admin'),
    ('user.mute', 'Mute a user for a period', 'moderate'),
    ('user.manage', 'Change roles, ban users and set quotas', 'admin'),
    ('settings.manage', 'Change site settings and feature flags', 'admin'),
    ('takedown.triage', 'Triage takedown and abuse reports', 'moderate'),
    ('takedown.decide', 'Decide takedown requests', 'admin'),
    ('audit.view.own', 'See your own audit trail', 'general'),
    ('audit.view.all', 'See the whole audit log', 'admin');

INSERT INTO `role_permissions` (`role`, `permission_id`)
SELECT 'member', `id` FROM `permissions` WHERE `key` IN (
    'catalog.browse', 'book.read', 'book.download', 'book.upload', 'book.edit.own',
    'request.create', 'request.vote', 'request.fulfil', 'taxonomy.propose',
    'collection.create.private', 'collection.propose.public', 'review.write',
    'librarian.apply', 'audit.view.own'
);

INSERT INTO `role_permissions` (`role`, `permission_id`)
SELECT 'librarian', `id` FROM `permissions` WHERE `key` IN (
    'catalog.browse', 'book.read', 'book.download', 'book.upload', 'book.publish',
    'book.edit.own', 'book.edit.any', 'book.delete.soft', 'request.create',
    'request.vote', 'request.fulfil', 'request.close', 'taxonomy.propose',
    'taxonomy.manage', 'collection.create.private', 'collection.propose.public',
    'collection.approve', 'review.write', 'review.moderate', 'moderation.queue',
    'user.mute', 'takedown.triage', 'audit.view.own'
);

INSERT INTO `role_permissions` (`role`, `permission_id`) SELECT 'admin', `id` FROM `permissions`;

-- @down
DROP TABLE IF EXISTS `user_permissions`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
