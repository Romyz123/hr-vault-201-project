-- =====================================================================
-- HR 201 System Schema Migration: Fix Missing Columns & Catalog Tables
-- Run in phpMyAdmin (Select 'hr201_local' database -> SQL tab) or MySQL CLI
-- =====================================================================

-- 1. Fix missing college_degree column in employees table
ALTER TABLE `employees` ADD COLUMN `college_degree` VARCHAR(255) NULL AFTER `department`;

-- 2. Create missing college_courses catalog table
CREATE TABLE IF NOT EXISTS `college_courses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_name` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create missing college_degrees_catalog table
CREATE TABLE IF NOT EXISTS `college_degrees_catalog` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `degree_name` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

