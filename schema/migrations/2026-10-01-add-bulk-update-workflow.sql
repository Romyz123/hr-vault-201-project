-- Bulk update audit, rollback, approval, and CSV import workflow
-- Run once during deployment with a restricted migration account.

CREATE TABLE IF NOT EXISTS `bulk_update_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_uuid` CHAR(36) NOT NULL,
    `requested_by` INT NOT NULL,
    `reason` VARCHAR(200) NULL,
    `source` ENUM('UI', 'CSV') NOT NULL DEFAULT 'UI',
    `status` ENUM('APPLIED', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'ROLLED_BACK') NOT NULL DEFAULT 'APPLIED',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_by` INT NULL,
    `approved_at` DATETIME NULL,
    `rolled_back_by` INT NULL,
    `rolled_back_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bulk_batch_uuid` (`batch_uuid`),
    KEY `idx_bulk_batch_status` (`status`),
    KEY `idx_bulk_batch_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bulk_update_changes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `employee_id` INT NOT NULL,
    `field_name` VARCHAR(50) NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bulk_change_batch` (`batch_id`),
    KEY `idx_bulk_change_employee` (`employee_id`),
    CONSTRAINT `fk_bulk_change_batch` FOREIGN KEY (`batch_id`) REFERENCES `bulk_update_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bulk_update_approvals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `reviewer_id` INT NOT NULL,
    `decision` ENUM('APPROVED', 'REJECTED') NOT NULL,
    `review_note` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bulk_approval_batch` (`batch_id`),
    CONSTRAINT `fk_bulk_approval_batch` FOREIGN KEY (`batch_id`) REFERENCES `bulk_update_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
