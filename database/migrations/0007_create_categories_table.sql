-- Categories: the fixed shelf structure, curated by librarians. Hierarchical,
-- with a materialised `path` so a subtree is one indexed prefix match and a
-- breadcrumb needs no recursion.
--
-- A member can propose one, which arrives with status 'pending' and is invisible
-- until a librarian activates it.

-- @up
CREATE TABLE `categories` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` BIGINT UNSIGNED NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(140) NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `description` VARCHAR(500) NULL,
    `status` ENUM('active', 'pending', 'hidden') NOT NULL DEFAULT 'active',
    `sort_order` INT NOT NULL DEFAULT 0,
    `book_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `proposed_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `categories_path_unique` (`path`),
    UNIQUE KEY `categories_parent_slug_unique` (`parent_id`, `slug`),
    KEY `categories_status_index` (`status`),
    KEY `categories_sort_index` (`parent_id`, `sort_order`),
    CONSTRAINT `categories_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `categories_proposer_fk` FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_categories` (
    `book_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`book_id`, `category_id`),
    KEY `book_categories_category_index` (`category_id`),
    CONSTRAINT `book_categories_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
    CONSTRAINT `book_categories_category_fk` FOREIGN KEY (`category_id`)
        REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `description`, `sort_order`) VALUES
    (NULL, 'Stories', 'stories', '/stories/', 0, 'Fiction of every length, for every age.', 10),
    (NULL, 'Kids', 'kids', '/kids/', 0, 'Picture books, early readers and learning material.', 20),
    (NULL, 'Movies', 'movies', '/movies/', 0, 'Screenplays, film writing and everything about cinema.', 30),
    (NULL, 'Academics', 'academics', '/academics/', 0, 'School, university and competitive exam material.', 40),
    (NULL, 'Comics', 'comics', '/comics/', 0, 'Comics and graphic novels.', 50),
    (NULL, 'Magazines', 'magazines', '/magazines/', 0, 'Periodicals and current affairs.', 60),
    (NULL, 'Religion', 'religion', '/religion/', 0, 'Scripture, commentary and religious history.', 70),
    (NULL, 'Technology', 'technology', '/technology/', 0, 'Computing, engineering and the sciences applied.', 80);

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Folk Tales', 'folk-tales', CONCAT(`path`, 'folk-tales/'), 1, 10 FROM `categories` WHERE `path` = '/stories/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Short Stories', 'short-stories', CONCAT(`path`, 'short-stories/'), 1, 20 FROM `categories` WHERE `path` = '/stories/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Novels', 'novels', CONCAT(`path`, 'novels/'), 1, 30 FROM `categories` WHERE `path` = '/stories/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Picture Books', 'picture-books', CONCAT(`path`, 'picture-books/'), 1, 10 FROM `categories` WHERE `path` = '/kids/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Learning', 'learning', CONCAT(`path`, 'learning/'), 1, 20 FROM `categories` WHERE `path` = '/kids/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Screenplays', 'screenplays', CONCAT(`path`, 'screenplays/'), 1, 10 FROM `categories` WHERE `path` = '/movies/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Film Studies', 'film-studies', CONCAT(`path`, 'film-studies/'), 1, 20 FROM `categories` WHERE `path` = '/movies/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'School', 'school', CONCAT(`path`, 'school/'), 1, 10 FROM `categories` WHERE `path` = '/academics/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Competitive Exams', 'competitive-exams', CONCAT(`path`, 'competitive-exams/'), 1, 20 FROM `categories` WHERE `path` = '/academics/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Reference', 'reference', CONCAT(`path`, 'reference/'), 1, 30 FROM `categories` WHERE `path` = '/academics/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'UPSC', 'upsc', CONCAT(`path`, 'upsc/'), 2, 10 FROM `categories` WHERE `path` = '/academics/competitive-exams/';

INSERT INTO `categories` (`parent_id`, `name`, `slug`, `path`, `depth`, `sort_order`)
SELECT `id`, 'Banking', 'banking', CONCAT(`path`, 'banking/'), 2, 20 FROM `categories` WHERE `path` = '/academics/competitive-exams/';

-- @down
DROP TABLE IF EXISTS `book_categories`;
DROP TABLE IF EXISTS `categories`;
