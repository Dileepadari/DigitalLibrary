-- The catalogue. A `book` is the bibliographic record; a `book_file` is a
-- downloadable artefact attached to it, so one book can carry a scanned PDF, a
-- clean PDF and an EPUB without duplicating the metadata.
--
-- Files are uploaded from M3; the table exists now so the book page and the
-- repositories are written against the real shape.

-- @up
CREATE TABLE `authors` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(160) NOT NULL,
    `slug` VARCHAR(180) NOT NULL,
    `bio` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `authors_slug_unique` (`slug`),
    KEY `authors_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `publishers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(160) NOT NULL,
    `slug` VARCHAR(180) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `publishers_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `books` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `subtitle` VARCHAR(255) NULL,
    `slug` VARCHAR(280) NOT NULL,
    `publisher_id` BIGINT UNSIGNED NULL,
    `published_year` SMALLINT UNSIGNED NULL,
    `edition` VARCHAR(60) NULL,
    `language` VARCHAR(10) NOT NULL DEFAULT 'en',
    `isbn10` VARCHAR(10) NULL,
    `isbn13` VARCHAR(13) NULL,
    `description` TEXT NULL,
    `content_type` ENUM('book', 'magazine', 'comic', 'academic_paper', 'notes', 'audiobook', 'video')
        NOT NULL DEFAULT 'book',
    `licence` ENUM('public_domain', 'cc_by', 'cc_by_sa', 'cc_other', 'author_permission', 'own_work', 'unknown')
        NOT NULL DEFAULT 'unknown',
    `licence_note` VARCHAR(255) NULL,
    `source_url` VARCHAR(500) NULL,
    `cover_path` VARCHAR(255) NULL,
    `page_count` INT UNSIGNED NULL,
    `status` ENUM('draft', 'pending', 'published', 'rejected', 'hidden') NOT NULL DEFAULT 'pending',
    `added_by` BIGINT UNSIGNED NULL,
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `books_slug_unique` (`slug`),
    KEY `books_status_published_index` (`status`, `published_at`),
    KEY `books_content_type_index` (`content_type`),
    KEY `books_language_index` (`language`),
    KEY `books_added_by_index` (`added_by`),
    KEY `books_isbn13_index` (`isbn13`),
    FULLTEXT KEY `books_fulltext` (`title`, `subtitle`, `description`),
    CONSTRAINT `books_publisher_fk` FOREIGN KEY (`publisher_id`) REFERENCES `publishers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `books_added_by_fk` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_authors` (
    `book_id` BIGINT UNSIGNED NOT NULL,
    `author_id` BIGINT UNSIGNED NOT NULL,
    `role` ENUM('author', 'editor', 'translator', 'illustrator') NOT NULL DEFAULT 'author',
    `position` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`book_id`, `author_id`, `role`),
    KEY `book_authors_author_index` (`author_id`),
    CONSTRAINT `book_authors_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
    CONSTRAINT `book_authors_author_fk` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_files` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `book_id` BIGINT UNSIGNED NOT NULL,
    `format` VARCHAR(10) NOT NULL,
    `storage_path` VARCHAR(255) NOT NULL,
    `sha256` CHAR(64) NOT NULL,
    `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `page_count` INT UNSIGNED NULL,
    `quality` ENUM('scan', 'clean', 'unknown') NOT NULL DEFAULT 'unknown',
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `uploaded_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `book_files_sha256_unique` (`sha256`),
    KEY `book_files_book_index` (`book_id`, `is_primary`),
    CONSTRAINT `book_files_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
    CONSTRAINT `book_files_uploader_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_texts` (
    `book_id` BIGINT UNSIGNED NOT NULL,
    `content` MEDIUMTEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`book_id`),
    FULLTEXT KEY `book_texts_fulltext` (`content`),
    CONSTRAINT `book_texts_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `book_texts`;
DROP TABLE IF EXISTS `book_files`;
DROP TABLE IF EXISTS `book_authors`;
DROP TABLE IF EXISTS `books`;
DROP TABLE IF EXISTS `publishers`;
DROP TABLE IF EXISTS `authors`;
