-- Tags: the flat descriptive layer. Anyone may propose one, but a proposed tag
-- stays 'pending' and out of the suggestions until a librarian approves it,
-- which is what stops the catalogue fragmenting into sci-fi / scifi / science
-- fiction. Aliases fold the synonyms that arrive anyway.

-- @up
CREATE TABLE `tags` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(60) NOT NULL,
    `slug` VARCHAR(70) NOT NULL,
    `status` ENUM('active', 'pending', 'rejected') NOT NULL DEFAULT 'pending',
    `usage_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `proposed_by` BIGINT UNSIGNED NULL,
    `approved_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tags_slug_unique` (`slug`),
    KEY `tags_status_index` (`status`, `usage_count`),
    CONSTRAINT `tags_proposer_fk` FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `tags_approver_fk` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tag_aliases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `alias` VARCHAR(70) NOT NULL,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tag_aliases_alias_unique` (`alias`),
    KEY `tag_aliases_tag_index` (`tag_id`),
    CONSTRAINT `tag_aliases_tag_fk` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_tags` (
    `book_id` BIGINT UNSIGNED NOT NULL,
    `tag_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`book_id`, `tag_id`),
    KEY `book_tags_tag_index` (`tag_id`),
    CONSTRAINT `book_tags_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
    CONSTRAINT `book_tags_tag_fk` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tags` (`name`, `slug`, `status`) VALUES
    ('Fiction', 'fiction', 'active'),
    ('Non-fiction', 'non-fiction', 'active'),
    ('Romance', 'romance', 'active'),
    ('Science', 'science', 'active'),
    ('Science fiction', 'science-fiction', 'active'),
    ('Fantasy', 'fantasy', 'active'),
    ('Thriller', 'thriller', 'active'),
    ('History', 'history', 'active'),
    ('Biography', 'biography', 'active'),
    ('Poetry', 'poetry', 'active'),
    ('Philosophy', 'philosophy', 'active'),
    ('Mathematics', 'mathematics', 'active'),
    ('Self help', 'self-help', 'active'),
    ('UPSC', 'upsc', 'active'),
    ('NCERT', 'ncert', 'active'),
    ('Hindi', 'hindi', 'active'),
    ('English', 'english', 'active'),
    ('Classic', 'classic', 'active');

INSERT INTO `tag_aliases` (`alias`, `tag_id`) SELECT 'sci-fi', `id` FROM `tags` WHERE `slug` = 'science-fiction';
INSERT INTO `tag_aliases` (`alias`, `tag_id`) SELECT 'scifi', `id` FROM `tags` WHERE `slug` = 'science-fiction';
INSERT INTO `tag_aliases` (`alias`, `tag_id`) SELECT 'nonfiction', `id` FROM `tags` WHERE `slug` = 'non-fiction';
INSERT INTO `tag_aliases` (`alias`, `tag_id`) SELECT 'maths', `id` FROM `tags` WHERE `slug` = 'mathematics';

-- @down
DROP TABLE IF EXISTS `book_tags`;
DROP TABLE IF EXISTS `tag_aliases`;
DROP TABLE IF EXISTS `tags`;
