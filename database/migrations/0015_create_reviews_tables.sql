-- Reviews and ratings.
--
-- One review per person per book: the unique key is the rule, not a check in
-- the service. A review carries a rating and, optionally, something to say.
-- `books.rating_average` and `rating_count` are denormalised because every
-- listing shows them and nothing else in a book card needs a join.

-- @up
CREATE TABLE `reviews` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `book_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `rating` TINYINT UNSIGNED NOT NULL,
    `body` VARCHAR(4000) NULL,
    `status` ENUM('visible', 'hidden', 'removed') NOT NULL DEFAULT 'visible',
    `hidden_reason` VARCHAR(255) NULL,
    `helpful_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reviews_book_user_unique` (`book_id`, `user_id`),
    KEY `reviews_book_status_index` (`book_id`, `status`, `helpful_count`),
    KEY `reviews_user_index` (`user_id`, `created_at`),
    CONSTRAINT `reviews_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
    CONSTRAINT `reviews_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `review_votes` (
    `review_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`review_id`, `user_id`),
    KEY `review_votes_user_index` (`user_id`),
    CONSTRAINT `review_votes_review_fk` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE,
    CONSTRAINT `review_votes_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `books`
    ADD COLUMN `rating_average` DECIMAL(3,2) NOT NULL DEFAULT 0 AFTER `download_count`,
    ADD COLUMN `rating_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `rating_average`,
    ADD KEY `books_rating_index` (`rating_average`, `rating_count`);

-- @down
ALTER TABLE `books`
    DROP KEY `books_rating_index`,
    DROP COLUMN `rating_count`,
    DROP COLUMN `rating_average`;

DROP TABLE IF EXISTS `review_votes`;
DROP TABLE IF EXISTS `reviews`;
