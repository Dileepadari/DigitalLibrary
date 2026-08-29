-- Columns the upload pipeline needs on tables that already exist.
--
-- book_files.status tracks where a file is: `quarantined` means it is in
-- storage/quarantine and reachable by nobody but a reviewer, `published` means
-- it has been hard linked into storage/library, `rejected` means it is waiting
-- for the quarantine sweep to delete it.

-- @up
ALTER TABLE `book_files`
    ADD COLUMN `status` ENUM('quarantined', 'published', 'rejected') NOT NULL DEFAULT 'quarantined' AFTER `quality`,
    ADD COLUMN `original_name` VARCHAR(255) NULL AFTER `format`,
    ADD COLUMN `mime_type` VARCHAR(120) NULL AFTER `original_name`,
    ADD COLUMN `rejected_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`,
    ADD KEY `book_files_status_index` (`status`, `rejected_at`);

ALTER TABLE `users`
    ADD COLUMN `strikes` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `reputation`;

-- @down
ALTER TABLE `users` DROP COLUMN `strikes`;

ALTER TABLE `book_files`
    DROP KEY `book_files_status_index`,
    DROP COLUMN `rejected_at`,
    DROP COLUMN `mime_type`,
    DROP COLUMN `original_name`,
    DROP COLUMN `status`;
