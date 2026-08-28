-- Reputation and badges.
--
-- `users.reputation` is a running total, but the events are what it is made of:
-- without them nobody could answer "why do I have 47 points?", and a badge
-- threshold would have nothing to count.
--
-- A badge is earned by doing one kind of thing enough times, so it is data
-- rather than code: `action` names the event and `threshold` says how many.

-- @up
CREATE TABLE `reputation_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `action` VARCHAR(40) NOT NULL,
    `points` INT NOT NULL,
    `subject_type` VARCHAR(40) NULL,
    `subject_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `reputation_events_user_index` (`user_id`, `created_at`),
    KEY `reputation_events_action_index` (`user_id`, `action`),
    CONSTRAINT `reputation_events_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `badges` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(40) NOT NULL,
    `name` VARCHAR(60) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `action` VARCHAR(40) NOT NULL,
    `threshold` INT UNSIGNED NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `badges_key_unique` (`key`),
    KEY `badges_action_index` (`action`, `threshold`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_badges` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `badge_id` INT UNSIGNED NOT NULL,
    `awarded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `badge_id`),
    KEY `user_badges_badge_index` (`badge_id`),
    CONSTRAINT `user_badges_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `user_badges_badge_fk` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `badges` (`key`, `name`, `description`, `action`, `threshold`, `sort_order`) VALUES
    ('first-upload', 'First contribution', 'Had an upload accepted into the library.', 'upload.accepted', 1, 10),
    ('ten-uploads', 'Ten times over', 'Had ten uploads accepted.', 'upload.accepted', 10, 20),
    ('fifty-uploads', 'Shelf filler', 'Had fifty uploads accepted.', 'upload.accepted', 50, 30),
    ('first-answer', 'Answered a request', 'Found a book someone had asked for.', 'request.fulfilled', 1, 40),
    ('five-answers', 'Finder of books', 'Answered five requests.', 'request.fulfilled', 5, 50),
    ('first-review', 'Said something', 'Wrote a review.', 'review.written', 1, 60),
    ('ten-reviews', 'Well read', 'Wrote ten reviews.', 'review.written', 10, 70),
    ('helpful-reviewer', 'Worth reading', 'Reviews marked helpful twenty times.', 'review.helpful', 20, 80),
    ('curator', 'Curator', 'Had a collection published.', 'collection.published', 1, 90),
    ('librarian-hundred', 'On the desk', 'Decided a hundred items in the queue.', 'moderation.decided', 100, 100);

-- @down
DROP TABLE IF EXISTS `user_badges`;
DROP TABLE IF EXISTS `badges`;
DROP TABLE IF EXISTS `reputation_events`;
