-- Where someone got to, and the places they marked.
--
-- `position` is a string rather than a number because it means different things
-- per format: a page number for a PDF, an EPUB CFI for an EPUB, seconds for an
-- audiobook. The reader that wrote it is the only thing that has to understand
-- it; `percent` is the part everything else can use.

-- @up
CREATE TABLE `reading_progress` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `book_file_id` BIGINT UNSIGNED NOT NULL,
    `position` VARCHAR(255) NOT NULL DEFAULT '1',
    `percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reading_progress_unique` (`user_id`, `book_file_id`),
    KEY `reading_progress_recent_index` (`user_id`, `last_read_at`),
    CONSTRAINT `reading_progress_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `reading_progress_file_fk` FOREIGN KEY (`book_file_id`)
        REFERENCES `book_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bookmarks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `book_file_id` BIGINT UNSIGNED NOT NULL,
    `position` VARCHAR(255) NOT NULL,
    `label` VARCHAR(120) NULL,
    `note` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `bookmarks_user_file_index` (`user_id`, `book_file_id`),
    CONSTRAINT `bookmarks_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `bookmarks_file_fk` FOREIGN KEY (`book_file_id`)
        REFERENCES `book_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `bookmarks`;
DROP TABLE IF EXISTS `reading_progress`;
