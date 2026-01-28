-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 28, 2026 at 11:58 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hr201_local`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'USER_EDIT', 'Updated User ID: 1', '::1', '2026-01-28 10:07:27'),
(2, 1, 'USER_EDIT', 'Updated User ID: 1', '::1', '2026-01-28 10:08:47'),
(3, 1, 'USER_EDIT', 'Updated User ID: 4', '::1', '2026-01-28 10:10:50'),
(4, 1, 'USER_EDIT', 'Updated User ID: 2', '::1', '2026-01-28 10:16:56'),
(5, 1, 'LOGOUT', 'User logged out (Manual Logout)', '::1', '2026-01-28 10:28:10'),
(6, 1, 'LOGIN', 'User logged in (IP: ::1)', '::1', '2026-01-28 10:28:24'),
(7, 1, 'LOGOUT', 'User logged out (Manual Logout)', '::1', '2026-01-28 10:28:35'),
(8, 2, 'LOGIN', 'User logged in (IP: ::1)', '::1', '2026-01-28 10:28:47'),
(9, 2, 'LOGOUT', 'User logged out (Manual Logout)', '::1', '2026-01-28 10:29:02'),
(10, 1, 'LOGIN', 'User logged in (IP: ::1)', '::1', '2026-01-28 10:29:17'),
(11, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-28 10:42:03'),
(12, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-28 10:42:32'),
(13, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-28 10:42:54'),
(14, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260128_114254', '::1', '2026-01-28 10:51:39'),
(15, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260128_114232', '::1', '2026-01-28 10:51:41'),
(16, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260128_114203', '::1', '2026-01-28 10:51:44'),
(17, 1, 'LOGIN', 'User logged in (IP: ::1)', '::1', '2026-01-28 10:55:58');

-- --------------------------------------------------------

--
-- Table structure for table `disciplinary_cases`
--

CREATE TABLE `disciplinary_cases` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(32) NOT NULL,
  `violation_type` varchar(100) NOT NULL,
  `incident_date` date NOT NULL,
  `action_taken` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` enum('Open','Closed') DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `file_uuid` varchar(64) DEFAULT NULL,
  `employee_id` varchar(32) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolution_note` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_exemptions`
--

CREATE TABLE `document_exemptions` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(32) NOT NULL,
  `requirement_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_requirements`
--

CREATE TABLE `document_requirements` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `keywords` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_requirements`
--

INSERT INTO `document_requirements` (`id`, `name`, `keywords`, `created_at`) VALUES
(1, '201 Files', '201, PDS, Data Sheet, Resume', '2026-01-28 09:57:33'),
(2, 'Valid ID', 'ID, Passport, License, SSS, PhilHealth', '2026-01-28 09:57:33'),
(3, 'Contract', 'Contract, Appointment, Offer', '2026-01-28 09:57:33'),
(4, 'Medical', 'Medical, Fit to Work, Exam', '2026-01-28 09:57:33'),
(5, 'Clearance', 'NBI, Police, Barangay', '2026-01-28 09:57:33');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `emp_id` varchar(32) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `job_title` varchar(50) DEFAULT NULL,
  `dept` varchar(50) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `employment_type` varchar(50) DEFAULT NULL,
  `agency_name` varchar(50) DEFAULT NULL,
  `company_name` varchar(50) DEFAULT 'TES Philippines',
  `previous_company` varchar(100) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `sss_no` varchar(20) DEFAULT NULL,
  `tin_no` varchar(20) DEFAULT NULL,
  `pagibig_no` varchar(20) DEFAULT NULL,
  `philhealth_no` varchar(20) DEFAULT NULL,
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(20) DEFAULT NULL,
  `emergency_address` text DEFAULT NULL,
  `education` text DEFAULT NULL,
  `experience` text DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `licenses` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `exit_date` date DEFAULT NULL,
  `exit_reason` varchar(255) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT 'default.png',
  `import_batch` varchar(50) DEFAULT NULL,
  `last_reminded` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_history`
--

CREATE TABLE `employee_history` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `changed_by` varchar(50) NOT NULL,
  `change_date` datetime DEFAULT current_timestamp(),
  `details` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_logs`
--

CREATE TABLE `maintenance_logs` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(32) NOT NULL,
  `equipment_type` varchar(50) NOT NULL,
  `issue` varchar(255) NOT NULL,
  `action_taken` text NOT NULL,
  `maintenance_date` date NOT NULL,
  `performed_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(20) DEFAULT 'info',
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_requests`
--

CREATE TABLE `pending_requests` (
  `id` int(11) NOT NULL,
  `emp_id` varchar(32) DEFAULT NULL,
  `request_type` varchar(50) NOT NULL,
  `json_payload` longtext DEFAULT NULL,
  `submitted_by` varchar(64) DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `performance_evaluations`
--

CREATE TABLE `performance_evaluations` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `eval_date` date NOT NULL,
  `score` int(11) NOT NULL,
  `rating` varchar(20) NOT NULL,
  `remarks` text DEFAULT NULL,
  `evaluator` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `ip_address` varchar(45) NOT NULL,
  `request_count` int(11) DEFAULT 1,
  `last_request` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`ip_address`, `request_count`, `last_request`) VALUES
('::1', 45, '2026-01-28 10:08:50');

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `request_type` varchar(50) NOT NULL,
  `target_id` int(11) NOT NULL DEFAULT 0,
  `json_payload` text NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  `admin_comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('maintenance_mode', '0'),
('staff_direct_approval', '0');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','MANAGER','HR','STAFF') NOT NULL DEFAULT 'STAFF',
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `last_otp_sent` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `reset_token`, `reset_expires`, `last_otp_sent`, `created_at`) VALUES
(1, 'admin', 'romyrabes64@gmail.com', '$2y$10$jmtH/iDE84jCZOUeeieLReHZBpvNK2W6qmzdrr5lbj5aL75IvKzSG', 'ADMIN', NULL, NULL, NULL, '2026-01-28 09:57:33'),
(2, 'manager', 'chrisvaleza2@gmail.com', '$2y$10$uUBSNVgiojGhFx4qSeD.lOaXbZMiSh5adcT4EoooN5mqPUvRqOy46', 'MANAGER', NULL, NULL, NULL, '2026-01-28 09:57:34'),
(3, 'hr', 'hr@demo.com', '$2y$10$p1OgslhRy7P9pE16DzK//.k29A842hJNfAi2Qs0AHazUR2fGUdwae', 'HR', NULL, NULL, NULL, '2026-01-28 09:57:34'),
(4, 'staff', 'staff@demo.com', '$2y$10$RExEy8WOfCr9.FcjmJufb.41nFmcUkblmLLtO6Ys4lmpoYNWNHBLW', 'STAFF', NULL, NULL, NULL, '2026-01-28 09:57:34');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `disciplinary_cases`
--
ALTER TABLE `disciplinary_cases`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `document_exemptions`
--
ALTER TABLE `document_exemptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_exemption` (`employee_id`,`requirement_name`);

--
-- Indexes for table `document_requirements`
--
ALTER TABLE `document_requirements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `emp_id` (`emp_id`);

--
-- Indexes for table `employee_history`
--
ALTER TABLE `employee_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emp` (`employee_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pending_requests`
--
ALTER TABLE `pending_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`ip_address`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `disciplinary_cases`
--
ALTER TABLE `disciplinary_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_exemptions`
--
ALTER TABLE `document_exemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_requirements`
--
ALTER TABLE `document_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=687;

--
-- AUTO_INCREMENT for table `employee_history`
--
ALTER TABLE `employee_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
