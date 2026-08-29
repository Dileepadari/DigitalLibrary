-- Book requests: "does anyone have this?"
--
-- A request is upvotable so the queue can be worked in order of demand rather
-- than order of arrival, and it can be claimed so two people do not go and scan
-- the same book. Fulfilment is a link to the record that satisfied it, which is
-- what lets an approved upload close the request and tell everyone who wanted
-- it.

-- @up
CREATE TABLE `book_requests` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `author` VARCHAR(160) NULL,
    `isbn` VARCHAR(17) NULL,
    `note` VARCHAR(1000) NULL,
    `language` VARCHAR(10) NOT NULL DEFAULT 'en',
    `requester_id` BIGINT UNSIGNED NULL,
    `status` ENUM('open', 'claimed', 'fulfilled', 'unavailable', 'duplicate', 'rejected')
        NOT NULL DEFAULT 'open',
    `claimed_by` BIGINT UNSIGNED NULL,
    `claimed_at` TIMESTAMP NULL DEFAULT NULL,
    `fulfilled_by_book_id` BIGINT UNSIGNED NULL,
    `closed_by` BIGINT UNSIGNED NULL,
    `closed_at` TIMESTAMP NULL DEFAULT NULL,
    `close_reason` VARCHAR(255) NULL,
    `vote_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `book_requests_status_votes_index` (`status`, `vote_count`),
    KEY `book_requests_requester_index` (`requester_id`),
    KEY `book_requests_claimed_index` (`claimed_by`),
    FULLTEXT KEY `book_requests_fulltext` (`title`, `author`, `note`),
    CONSTRAINT `book_requests_requester_fk` FOREIGN KEY (`requester_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `book_requests_claimer_fk` FOREIGN KEY (`claimed_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `book_requests_closer_fk` FOREIGN KEY (`closed_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `book_requests_book_fk` FOREIGN KEY (`fulfilled_by_book_id`)
        REFERENCES `books` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_request_votes` (
    `request_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`request_id`, `user_id`),
    KEY `book_request_votes_user_index` (`user_id`),
    CONSTRAINT `book_request_votes_request_fk` FOREIGN KEY (`request_id`)
        REFERENCES `book_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `book_request_votes_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS `book_request_votes`;
DROP TABLE IF EXISTS `book_requests`;
