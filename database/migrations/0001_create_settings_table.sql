-- Site settings as key/value JSON. Written by admins from M8; read from M0 so
-- the home page proves the whole config -> database -> view path works.

-- @up
CREATE TABLE `settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(191) NOT NULL,
    `value` JSON NOT NULL,
    `group` VARCHAR(64) NOT NULL DEFAULT 'general',
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `settings_key_unique` (`key`),
    KEY `settings_group_index` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`, `group`, `description`) VALUES ('site.name', '"Digital Library"', 'general', 'Shown in the header and in emails');
INSERT INTO `settings` (`key`, `value`, `group`, `description`) VALUES ('site.tagline', '"A community library anyone can add to."', 'general', 'Shown under the site name');
INSERT INTO `settings` (`key`, `value`, `group`, `description`) VALUES ('registration.mode', '"open"', 'accounts', 'open, invite or closed');
INSERT INTO `settings` (`key`, `value`, `group`, `description`) VALUES ('uploads.require_licence_evidence', 'true', 'moderation', 'Reviewers must confirm the licence basis before approving');

-- @down
DROP TABLE IF EXISTS `settings`;
