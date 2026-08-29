-- Collections: the folders anyone can build, to any depth.
--
--   UPSC Preparation
--   +-- Prelims
--   |   +-- History
--   |   |   +-- Ancient India   -> 12 books
--   |   |   \-- Modern India    -> 21 books
--   |   \-- Polity
--   \-- Previous Year Papers    -> 40 books
--
-- Same materialised `path` trick as categories, so a subtree is one indexed
-- prefix match and the URL is the path. `root_id` points every node at the top
-- of its own tree, which is what makes "publish this whole collection" and
-- "who follows this collection" single queries.
--
-- Two axes, deliberately separate: `visibility` is who can see it now, and
-- `review_status` is where its request to become public has got to. A private
-- collection is nobody's business but its owner's and never enters the queue.

-- @up
CREATE TABLE `collections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` BIGINT UNSIGNED NULL,
    `root_id` BIGINT UNSIGNED NULL,
    `owner_id` BIGINT UNSIGNED NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(140) NOT NULL,
    `path` VARCHAR(700) NOT NULL,
    `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `description` VARCHAR(500) NULL,
    `visibility` ENUM('private', 'unlisted', 'public') NOT NULL DEFAULT 'private',
    `review_status` ENUM('none', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'none',
    `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `follower_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `forked_from_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `collections_path_unique` (`path`),
    KEY `collections_parent_sort_index` (`parent_id`, `sort_order`),
    KEY `collections_root_index` (`root_id`),
    KEY `collections_owner_index` (`owner_id`, `visibility`),
    KEY `collections_visibility_index` (`visibility`, `review_status`),
    CONSTRAINT `collections_parent_fk` FOREIGN KEY (`parent_id`)
        REFERENCES `collections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `collections_owner_fk` FOREIGN KEY (`owner_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collection_items` (
    `collection_id` BIGINT UNSIGNED NOT NULL,
    `book_id` BIGINT UNSIGNED NOT NULL,
    `position` INT UNSIGNED NOT NULL DEFAULT 0,
    `note` VARCHAR(255) NULL,
    `added_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`collection_id`, `book_id`),
    KEY `collection_items_book_index` (`book_id`),
    KEY `collection_items_order_index` (`collection_id`, `position`),
    CONSTRAINT `collection_items_collection_fk` FOREIGN KEY (`collection_id`)
        REFERENCES `collections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `collection_items_book_fk` FOREIGN KEY (`book_id`)
        REFERENCES `books` (`id`) ON DELETE CASCADE,
    CONSTRAINT `collection_items_adder_fk` FOREIGN KEY (`added_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collection_maintainers` (
    `collection_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `invited_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`collection_id`, `user_id`),
    KEY `collection_maintainers_user_index` (`user_id`),
    CONSTRAINT `collection_maintainers_collection_fk` FOREIGN KEY (`collection_id`)
        REFERENCES `collections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `collection_maintainers_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `collection_followers` (
    `collection_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`collection_id`, `user_id`),
    KEY `collection_followers_user_index` (`user_id`),
    CONSTRAINT `collection_followers_collection_fk` FOREIGN KEY (`collection_id`)
        REFERENCES `collections` (`id`) ON DELETE CASCADE,
    CONSTRAINT `collection_followers_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `collection_followers`;
DROP TABLE IF EXISTS `collection_maintainers`;
DROP TABLE IF EXISTS `collection_items`;
DROP TABLE IF EXISTS `collections`;
