-- Migration: Add missing `groups` table and `employees.group` column
-- Required by:
--   * public/manage_hierarchy.php  (Groups column: SELECT/INSERT/DELETE on `groups`)
--   * employee forms/approvals     (store a `group` assignment on employees)
-- Run once during deployment (MariaDB/XAMPP syntax).

CREATE TABLE IF NOT EXISTS `groups` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS `group` varchar(255) DEFAULT NULL;
