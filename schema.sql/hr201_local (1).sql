-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 21, 2026 at 12:27 PM
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'ADD_EMPLOYEE', 'Added new employee: eyy awdawd', '::1', '2026-01-17 06:11:27'),
(2, 1, 'ADD_EMPLOYEE', 'Added new employee: jgjggj jhjhgjhgj', '::1', '2026-01-17 06:43:57'),
(3, 1, 'APPROVED_HIRE', 'Approved New Employee: asasa eididkid', '::1', '2026-01-17 07:53:35'),
(4, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 6', '::1', '2026-01-17 07:53:38'),
(5, 1, 'REJECTED_REQUEST', 'Rejected request type: UPLOAD_DOC', '::1', '2026-01-17 07:53:41'),
(6, 1, 'REJECTED_REQUEST', 'Rejected request type: UPLOAD_DOC', '::1', '2026-01-17 08:27:01'),
(7, 1, 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 08:27:45'),
(8, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 6', '::1', '2026-01-17 08:28:39'),
(9, 1, 'APPROVED_DOC', 'Approved Document: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 08:28:48'),
(10, 2, 'REQUEST_DOC', 'Submitted document for approval: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 08:31:04'),
(11, 2, 'REQUEST_DOC', 'Submitted document for approval: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 09:26:08'),
(12, 2, 'REQUEST_EDIT', 'Submitted profile edit request for ID: 15', '::1', '2026-01-17 09:26:34'),
(13, 1, 'APPROVED_DOC', 'Approved Document: xmas party program flow (2).pdf', '::1', '2026-01-17 09:36:14'),
(14, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 15', '::1', '2026-01-17 09:37:29'),
(15, 1, 'APPROVED_DOC', 'Approved Document: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 09:38:22'),
(16, 1, 'EDIT_PROFILE', 'Directly edited profile of Employee ID: 15', '::1', '2026-01-17 09:41:46'),
(17, 1, 'REJECTED_REQUEST', 'Rejected request type: EDIT_PROFILE', '::1', '2026-01-17 10:35:12'),
(18, 1, 'APPROVED_DOC', 'Approved Document: image (4).png', '::1', '2026-01-17 10:37:50'),
(19, 1, 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 12:03:22'),
(20, 1, 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 12:03:27'),
(21, 1, 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 12:03:34'),
(22, 1, 'REJECTED_REQUEST', 'Rejected request type: UPLOAD_DOC', '::1', '2026-01-17 12:03:47'),
(23, 1, 'UPLOAD_DOC', 'Directly uploaded: hindidapatsampleyungname.png', '::1', '2026-01-17 12:04:44'),
(24, 2, 'REQUEST_DOC', 'Submitted document: hindidapotlockinyungname.jpg', '::1', '2026-01-17 12:06:03'),
(25, 1, 'APPROVED_DOC', 'Approved Document: hindidapotlockinyungname.jpg', '::1', '2026-01-17 12:06:34'),
(26, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 5', '::1', '2026-01-17 12:06:56'),
(27, 1, 'UPLOAD_DOC', 'Directly uploaded: newcontract.jpg', '::1', '2026-01-17 12:13:36'),
(28, 1, 'ADD_EMPLOYEE', 'Added new employee: eqwqq wsasdq', '::1', '2026-01-17 12:24:07'),
(29, 1, 'EDIT_PROFILE', 'Photo updated', '::1', '2026-01-17 12:26:47'),
(30, 1, 'EDIT_PROFILE', 'Photo updated', '::1', '2026-01-17 12:27:14'),
(31, 1, 'EDIT_PROFILE', 'Photo updated', '::1', '2026-01-17 12:27:26'),
(32, 1, 'RESOLVED_ALERT', 'Marked alert as resolved: the file was done', '::1', '2026-01-17 13:03:31'),
(33, 2, 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 15', '::1', '2026-01-17 13:04:56'),
(34, 1, 'RESOLVED_ALERT', 'Marked alert as resolved: reporting', '::1', '2026-01-17 13:14:10'),
(35, 2, 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 13:14:48'),
(36, 2, 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 13:18:05'),
(37, 2, 'REQUEST_DOC', 'Submitted document: 999-01_201Files_1768655970.jpg', '::1', '2026-01-17 13:19:30'),
(38, 2, 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 13:23:05'),
(39, 1, 'UPLOAD_DOC', 'Directly uploaded: kggjh.pdf', '::1', '2026-01-19 07:41:03'),
(40, 1, 'UPLOAD_DOC', 'Directly uploaded: h.pdf', '::1', '2026-01-19 07:42:31'),
(41, 1, 'UPLOAD_DOC', 'Directly uploaded: eval.pdf', '::1', '2026-01-19 08:12:55'),
(42, 2, 'REQUEST_EDIT', 'Submitted profile edit request for ID: 12', '::1', '2026-01-19 09:42:04'),
(43, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 12', '::1', '2026-01-19 09:43:54'),
(44, 1, 'APPROVED_DOC', 'Approved Document: 999-01_201Files_1768655970.jpg', '::1', '2026-01-19 09:44:05'),
(45, 1, 'DELETE_DOC', 'Deleted file: kggjh.pdf', '::1', '2026-01-19 09:58:02'),
(46, 1, 'UPLOAD_DOC', 'Directly uploaded: eyy.pdf', '::1', '2026-01-19 10:12:23'),
(47, 2, 'REQUEST_DOC', 'Submitted document: pjqwpdjqwpajq_Medical_1768817692.pdf', '::1', '2026-01-19 10:14:52'),
(48, 2, 'REQUEST_EDIT', 'Submitted profile edit request for ID: 9', '::1', '2026-01-19 10:15:24'),
(49, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 9', '::1', '2026-01-19 10:16:29'),
(50, 1, 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-19 10:17:01'),
(51, 1, 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 15', '::1', '2026-01-19 10:17:31'),
(52, 1, 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 11', '::1', '2026-01-19 10:17:33'),
(53, 1, 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 11', '::1', '2026-01-19 10:17:34'),
(54, 1, 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 11', '::1', '2026-01-19 10:17:34'),
(55, 1, 'RESOLVED_ALERT', 'Marked alert as resolved: okay na', '::1', '2026-01-19 10:17:49'),
(56, 2, 'REQUEST_DOC', 'Submitted document: 999-01_Contract_1768818079.pdf', '::1', '2026-01-19 10:21:19'),
(57, 1, 'APPROVED_DOC', 'Approved Document: 999-01_Contract_1768818079.pdf', '::1', '2026-01-19 10:22:11'),
(58, 1, 'UPLOAD_DOC', 'Directly uploaded: nhtdhgdhdq_Medical_1768818161.pdf', '::1', '2026-01-19 10:22:41'),
(59, 1, 'UPLOAD_DOC', 'Directly uploaded: pjqwpdjqwpajq_VaccineCard_1768819253.png', '::1', '2026-01-19 10:40:53'),
(60, 1, 'UPLOAD_DOC', 'Directly uploaded: adawdwadad.png', '::1', '2026-01-19 10:48:05'),
(61, 1, 'DELETE_DOC', 'Deleted file: eval.pdf', '::1', '2026-01-19 10:57:20'),
(62, 1, 'UPLOAD_DOC', 'Directly uploaded: awqw_Eyyy_1768820868.jpg', '::1', '2026-01-19 11:07:48'),
(63, 1, 'DELETE_DOC', 'Deleted file: sample.png', '::1', '2026-01-20 01:04:39'),
(64, 1, 'DELETE_DOC', 'Deleted file: sample.png', '::1', '2026-01-20 01:04:45'),
(65, 1, 'DELETE_DOC', 'Deleted file: Print naaaaa yung invitation.pdf', '::1', '2026-01-20 01:06:03'),
(66, 1, 'UPLOAD_DOC', 'Directly uploaded: trffsf.png', '::1', '2026-01-20 01:11:36'),
(67, 1, 'DELETE_DOC', 'Deleted file: adawdwadad.png', '::1', '2026-01-20 05:22:46'),
(68, 1, 'APPROVED_DOC', 'Approved Document: pjqwpdjqwpajq_Medical_1768817692.pdf', '::1', '2026-01-20 10:01:16'),
(69, 1, 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 03:10:25'),
(70, 1, 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 03:10:34'),
(71, 1, 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 03:13:04'),
(72, 1, 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 03:20:35'),
(73, 2, 'REQUEST_HIRE', 'Submitted request for new employee: sample sample', '::1', '2026-01-21 03:45:14'),
(74, 2, 'REQUEST_HIRE', 'Submitted request for new employee: asdasd asdas', '::1', '2026-01-21 04:00:16'),
(75, 2, 'REQUEST_HIRE', 'Submitted request for new employee: John Doe', '::1', '2026-01-21 05:14:45'),
(76, 1, 'REJECTED_REQUEST', 'Rejected/Disregarded request type: ADD_EMPLOYEE', '::1', '2026-01-21 05:15:52'),
(77, 1, 'REJECTED_REQUEST', 'Rejected/Disregarded request type: ADD_EMPLOYEE', '::1', '2026-01-21 05:15:54'),
(78, 1, 'APPROVED_HIRE', 'Approved New Employee: John Doe', '::1', '2026-01-21 05:15:59'),
(79, 2, 'REQUEST_EDIT', 'Submitted profile edit request for ID: 6', '::1', '2026-01-21 05:17:23'),
(80, 1, 'REJECTED_REQUEST', 'Rejected/Disregarded request type: EDIT_PROFILE', '::1', '2026-01-21 05:18:18'),
(81, 2, 'REQUEST_HIRE', 'Submitted request for new employee: j wawad', '::1', '2026-01-21 05:39:39'),
(82, 1, 'REJECTED_REQUEST', 'Rejected request: ADD_EMPLOYEE', '::1', '2026-01-21 05:43:36'),
(83, 2, 'REQUEST_HIRE', 'Submitted request for new employee: eyy DADAWD', '::1', '2026-01-21 06:06:02'),
(84, 1, 'REJECTED_REQUEST', 'Rejected request: ADD_EMPLOYEE', '::1', '2026-01-21 06:11:33'),
(85, 2, 'REQUEST_DOC', 'Submitted document: ohohoh.jpg', '::1', '2026-01-21 06:12:45'),
(86, 1, 'REJECTED_REQUEST', 'Rejected request: UPLOAD_DOC', '::1', '2026-01-21 06:13:14'),
(87, 2, 'REQUEST_DOC', 'Submitted document: temp-003_Lockin_1768976126.jpg', '::1', '2026-01-21 06:15:26'),
(88, 1, 'APPROVED_DOC', 'Approved Document: temp-003_Lockin_1768976126.jpg', '::1', '2026-01-21 06:16:09'),
(89, 2, 'REQUEST_DOC', 'Submitted document: eyy_Evaluation_1768976469.png', '::1', '2026-01-21 06:21:09'),
(90, 1, 'APPROVED_DOC', 'Approved Document: eyy_Evaluation_1768976469.png', '::1', '2026-01-21 06:21:29'),
(91, 2, 'REQUEST_DOC', 'Submitted document: cnbcnbcbcv.png', '::1', '2026-01-21 06:33:00'),
(92, 2, 'REQUEST_DOC', 'Submitted document: emp-0007_GovernmentIDs_1768978009.jpg', '::1', '2026-01-21 06:46:49'),
(93, 1, 'APPROVED_DOC', 'Approved Document: cnbcnbcbcv.png', '::1', '2026-01-21 06:47:13'),
(94, 1, 'APPROVED_DOC', 'Approved Document: emp-0007_GovernmentIDs_1768978009.jpg', '::1', '2026-01-21 06:47:14'),
(95, 2, 'REQUEST_RESOLUTION', 'Submitted resolution request for Doc ID: 34', '::1', '2026-01-21 07:14:15'),
(96, 1, 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 34', '::1', '2026-01-21 08:48:45'),
(97, 3, 'UPLOAD_DOC', 'Directly uploaded: emp-0007_GovernmentIDs_1768985846.jpg', '::1', '2026-01-21 08:57:26'),
(98, 3, 'DELETE_DOC', 'Deleted file: emp-0007_GovernmentIDs_1768985846.jpg', '::1', '2026-01-21 10:18:17'),
(99, 1, 'UPLOAD_DOC', 'Directly uploaded: eyy.jpg', '::1', '2026-01-21 10:19:05'),
(100, 2, 'REQUEST_DOC', 'Submitted document: awqw_GovernmentIDs_1768990836.png', '::1', '2026-01-21 10:20:36'),
(101, 3, 'REJECTED_REQUEST', 'Rejected request: UPLOAD_DOC', '::1', '2026-01-21 10:21:07'),
(102, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ALL, Files: 27]', '::1', '2026-01-21 10:59:00');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(32) NOT NULL,
  `file_uuid` varchar(64) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `category` enum('Violation','Late','Promotion','Notice','Contract','Evaluation') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(11) DEFAULT NULL,
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolution_note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `employee_id`, `file_uuid`, `original_name`, `category`, `file_path`, `expiry_date`, `description`, `uploaded_at`, `uploaded_by`, `is_resolved`, `resolution_note`) VALUES
(2, 'EMP-001', '847fbef4-f200-11f0-8494-b4e9b890daba', 'SCORE SHEET PRINT.pdf', 'Violation', 'EMP-001_Others_1768474449.pdf', '2026-01-15', 'trial eme', '2026-01-15 10:54:09', 1, 1, 'the file was done'),
(4, 'temp-003', '95f9c134-f352-11f0-8494-b4e9b890daba', 'image (4).png', '', 'temp-003_Certificate_1768619650.png', NULL, '', '2026-01-17 03:14:10', 1, 0, NULL),
(5, 'EMP-002', '724b8ccb-f354-11f0-8494-b4e9b890daba', 'perfect adttendacne  Awardings for tesp xmas party (1).png', '', 'EMP-002_201Files_1768620449.png', NULL, '', '2026-01-17 03:27:29', 1, 0, NULL),
(6, 'eheheh-001', '44244fd4-f358-11f0-8494-b4e9b890daba', 'host 2.jpg', '', 'eheheh-001_201Files_1768622089.jpg', NULL, '', '2026-01-17 03:54:49', 1, 0, NULL),
(7, 'EMP-002', 'b460aad2-f358-11f0-8494-b4e9b890daba', 'Christmas-Party-2022-Programme_FInal.pdf', '', 'EMP-002_201Files_1768622278.pdf', NULL, 'xmass partyyy', '2026-01-17 03:57:58', 1, 0, NULL),
(8, 'eheheh-001', '3d0b416e-f35d-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 04:30:25', 2, 0, NULL),
(9, 'EMP-001', '8a2c9c06-f37e-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', 'Contract', 'EMP-001_Contract_1768568260.pdf', NULL, '', '2026-01-17 08:28:48', 2, 0, NULL),
(10, 'eyy', 'f5c622e4-f387-11f0-8494-b4e9b890daba', 'xmas party program flow (2).pdf', '', 'sample.pdf', NULL, 'memo yern', '2026-01-17 09:36:14', 2, 0, NULL),
(11, ';ojo', '4214e628-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768641968.pdf', '2026-01-23', '', '2026-01-17 09:38:22', 2, 1, 'sumbiteed'),
(12, ';ojo', '53a0b5e0-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768642731.pdf', NULL, '', '2026-01-17 09:38:51', 1, 0, NULL),
(14, 'eheheh-001', '9086f46b-f390-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 10:37:50', 2, 0, NULL),
(16, '999-01', 'b45e2fb6-f39c-11f0-8494-b4e9b890daba', 'hindidapatsampleyungname.png', '', 'hindidapatsampleyungname.png', NULL, 'hindi dapat', '2026-01-17 12:04:44', 1, 0, NULL),
(17, 'tesing', 'f5dc1982-f39c-11f0-8494-b4e9b890daba', 'hindidapotlockinyungname.jpg', 'Contract', 'hindidapotlockinyungname.jpg', '2026-01-29', 'Awdawdadw', '2026-01-17 12:06:34', 2, 1, 'okay na'),
(18, 'EMP-001', 'f1aa2929-f39d-11f0-8494-b4e9b890daba', 'newcontract.jpg', 'Contract', 'newcontract.jpg', NULL, '', '2026-01-17 12:13:36', 1, 0, NULL),
(20, 'tesing', '692347b7-f50a-11f0-8cf7-b4e9b890daba', 'h.pdf', 'Late', 'h.pdf', NULL, 'sample po', '2026-01-19 07:42:31', 1, 0, NULL),
(22, '999-01', '6496c836-f51b-11f0-8cf7-b4e9b890daba', '999-01_201Files_1768655970.jpg', '', '999-01_201Files_1768655970.jpg', NULL, '', '2026-01-19 09:44:05', 2, 0, NULL),
(23, 'tesing', '5913367a-f51f-11f0-8cf7-b4e9b890daba', 'eyy.pdf', '', 'eyy.pdf', NULL, '', '2026-01-19 10:12:23', 1, 0, NULL),
(24, '999-01', 'b72e4592-f520-11f0-8cf7-b4e9b890daba', '999-01_Contract_1768818079.pdf', 'Contract', '999-01_Contract_1768818079.pdf', NULL, '', '2026-01-19 10:22:11', 2, 0, NULL),
(25, 'nhtdhgdhdq', 'c973a9e3-f520-11f0-8cf7-b4e9b890daba', 'nhtdhgdhdq_Medical_1768818161.pdf', '', 'nhtdhgdhdq_Medical_1768818161.pdf', NULL, '', '2026-01-19 10:22:41', 1, 0, NULL),
(26, 'pjqwpdjqwpajq', '53ff1a40-f523-11f0-8cf7-b4e9b890daba', 'pjqwpdjqwpajq_VaccineCard_1768819253.png', '', 'pjqwpdjqwpajq_VaccineCard_1768819253.png', NULL, 'sample submition aaedadawaawdadaw', '2026-01-19 10:40:53', 1, 0, NULL),
(28, 'awqw', '16978646-f527-11f0-8cf7-b4e9b890daba', 'awqw_Eyyy_1768820868.jpg', '', 'awqw_Eyyy_1768820868.jpg', NULL, '', '2026-01-19 11:07:48', 1, 0, NULL),
(29, 'tesing', 'f7465931-f59c-11f0-8cf7-b4e9b890daba', 'trffsf.png', '', 'trffsf.png', NULL, '', '2026-01-20 01:11:36', 1, 0, NULL),
(30, 'pjqwpdjqwpajq', 'f5beb121-f5e6-11f0-9d0c-b4e9b890daba', 'pjqwpdjqwpajq_Medical_1768817692.pdf', '', 'pjqwpdjqwpajq_Medical_1768817692.pdf', NULL, '', '2026-01-20 10:01:16', 2, 0, NULL),
(31, 'temp-003', 'ad069f42-f690-11f0-9d0c-b4e9b890daba', 'temp-003_Lockin_1768976126.jpg', '', 'temp-003_Lockin_1768976126.jpg', NULL, '', '2026-01-21 06:16:09', 2, 0, NULL),
(32, 'eyy', '6c01cd37-f691-11f0-9d0c-b4e9b890daba', 'eyy_Evaluation_1768976469.png', 'Evaluation', 'eyy_Evaluation_1768976469.png', NULL, '', '2026-01-21 06:21:29', 2, 0, NULL),
(33, 'emp-0007', '03f3aa84-f695-11f0-9d0c-b4e9b890daba', 'cnbcnbcbcv.png', '', 'cnbcnbcbcv.png', NULL, '', '2026-01-21 06:47:13', 2, 0, NULL),
(34, 'emp-0007', '04b101cc-f695-11f0-9d0c-b4e9b890daba', 'emp-0007_GovernmentIDs_1768978009.jpg', '', 'emp-0007_GovernmentIDs_1768978009.jpg', '2026-01-21', '', '2026-01-21 06:47:14', 2, 1, 'eyy'),
(36, 'eheheh-001', '9d4a1295-f6b2-11f0-9d0c-b4e9b890daba', 'eyy.jpg', '', 'eyy.jpg', NULL, '', '2026-01-21 10:19:05', 1, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `emp_id` varchar(32) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `dept` varchar(100) NOT NULL,
  `section` varchar(100) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `employment_type` enum('TESP Direct','Agency','Subcon') DEFAULT 'TESP Direct',
  `agency_name` varchar(50) DEFAULT 'TESP',
  `company_name` varchar(100) DEFAULT 'TES PHILIPPINES, INC.',
  `previous_company` varchar(150) DEFAULT NULL,
  `status` enum('Active','Agency Separation','Sick Leave','Vacation','Resigned','Terminated','AWOL') DEFAULT 'Active',
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `sss_no` varchar(20) DEFAULT NULL,
  `tin_no` varchar(20) DEFAULT NULL,
  `pagibig_no` varchar(20) DEFAULT NULL,
  `philhealth_no` varchar(20) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT 'default.png',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(50) DEFAULT NULL,
  `emergency_address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `emp_id`, `first_name`, `middle_name`, `last_name`, `dept`, `section`, `job_title`, `employment_type`, `agency_name`, `company_name`, `previous_company`, `status`, `email`, `phone`, `contact_number`, `address`, `present_address`, `permanent_address`, `sss_no`, `tin_no`, `pagibig_no`, `philhealth_no`, `hire_date`, `gender`, `birthdate`, `avatar_path`, `created_at`, `emergency_name`, `emergency_contact`, `emergency_address`) VALUES
(1, 'EMP-001', 'Juan', '', 'Dela Cruz', 'ADMIN', 'GAG', 'taga kain', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', 'waqe213edds', 'Active', 'juan@example.com', NULL, '', NULL, 'asasa', 'asasxa', 'awdw', 'qwqwe', 'qwwqq', 'adasasasd', '0000-00-00', NULL, '0000-00-00', 'EMP-001-AWOL_1768467712.jpg', '2026-01-15 01:47:06', 'adawqa', 'd23e322', 'AWDAWDAd'),
(2, 'EMP-002', 'Maria', '', 'Santos', 'ADMIN', 'GAG', 'HR Officer', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', '', 'Active', 'maria@example.com', NULL, '', NULL, '', '', '', '', '', '', '0000-00-00', NULL, '0000-00-00', 'EMP-002_1768470799.jpg', '2026-01-15 01:47:06', '', '', ''),
(3, 'eyy', 'ronel', NULL, 'mordawdad', 'Operations', NULL, 'Techniain', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', NULL, 'Active', 'awaw@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-05', NULL, NULL, 'eyy_1768456487.jpg', '2026-01-15 05:54:47', NULL, NULL, NULL),
(4, 'eheheh-001', 'awit', NULL, 'ronwadw', 'Human Resources', NULL, 'taga tulog', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', NULL, 'Active', 'wadawd@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-12', NULL, NULL, 'eheheh-001_1768456567.jpg', '2026-01-15 05:56:07', NULL, NULL, NULL),
(5, 'temp-003', 'romyr', 'magalong', 'abes', 'SQP', 'IT', 'it staff', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', 'webtek', 'Active', 'eyy@werefecc.com', NULL, '0923123223334', NULL, 'adadwdaawdada', 'adawda3aeaxsas', '32eqeqeq2e', 'qeq22e211', '12121212', '12112313', '2025-12-05', NULL, '2025-07-22', 'temp-003_1768463069.jpg', '2026-01-15 07:44:29', 'eyy', '0932211122', '21qwdadadw'),
(6, 'wewwfwfwe', 'wefwewe', 'WJFE\'j', 'efwe', 'LMS', 'LIGHT MAINTENANCE SECTION', 'wewweej3', 'TESP Direct', 'TESP', 'TES Philippines', 'weowed', 'Terminated', 'tes@gmail.com', NULL, 'wefwewfwe', NULL, 'woeihfoH;EOhfoh', 'OWHDABDA', 'adawdaw', 'aidaiwda', 'QoijdjWD', 'iqwhdiahwd', '2026-01-30', NULL, '2026-01-16', 'wewwfwfwe_477d83d43e23.png', '2026-01-16 12:12:52', 'adadaw', 'adawdawd', 'adawdaw'),
(7, 'eqf', 'ADADAD', 'AEDAED', 'AEDASE', 'RAS', 'ROOT CAUSE ANALYSIS SECTION', 'WDAWD', 'Agency', 'Unisolutions', 'TES Philippines', 'ADADA', 'Active', 'Example@gmal.com', NULL, 'ADAEDED', NULL, 'AEDAEA', 'AEFAEF', 'ADAEAD', 'AEFEFSF', 'ADAEDEA', 'AEFEFAE', '2026-01-06', NULL, '2026-01-18', 'eqf_1768628447.jpg', '2026-01-17 05:41:33', 'AEDAEDA', 'AEDAEDA', 'ADAEDAEDD'),
(8, 'kghhHSj', 'ADAWDAWDAD', 'AHBAJSBJ', 'AKDJBAKBD', 'PSS', 'POWER SUPPLY SECTION', 'TAGA TAMBAY', 'Agency', 'JORATECH', 'TES Philippines', 'DYAN SA TINDAHAN NI BUANG', 'Active', '', NULL, '', NULL, 'ALSDLAKDLAS', 'lkhaldhalkshalk', 'zihdaslhdha', 'iwhdahdaj', 'aiohdalhld', 'oiahldahsldh', '2026-01-05', NULL, '2026-01-06', 'kghhHSj_1768566942.jpg', '2026-01-17 05:48:17', 'akjgsdkahskjk', 'aishdjakshkja', 'ihakhkdjs'),
(9, 'pjqwpdjqwpajq', 'romrttr', 'yfhfjfh', 'tiradores', 'SQP', 'QA', 'awdowahdaowdo', 'TESP Direct', 'TESP', 'TES Philippines', 'jyfjhgfhg', 'Active', 'exam@gmail.com', NULL, 'kgiugiuguyg', NULL, 'hghgcngfbgfxbgfxgf', 'iaugsluxgkxgas', 'sasxasxa', 'qwqsqs', 'ksjhckjgddka', 'qwsqwsqw', '2026-01-21', NULL, '2026-01-29', 'pjqwpdjqwpajq_1768629377.png', '2026-01-17 05:56:17', 'eahdld', 'ashdaohh', 'ishfkshksh'),
(10, 'awqw', 'sxasxa', 'asxasxa', 'asxasxa', 'RAS', 'ROOT CAUSE ANALYSIS SECTION', 'qwq', 'Agency', 'Unisolutions', 'TES Philippines', 'qwqws', 'Active', 'gr@ald.com', NULL, 'axasxas', NULL, 'Axawa', 'adawdawd', 'adawdad', 'adawdwd', 'awdawd', 'awdawda', '2026-01-17', NULL, '2026-01-14', 'awqw_1768629486.jpg', '2026-01-17 05:58:06', 'adawd', 'awdad', 'adwadwa'),
(11, '999-01', 'eyy', 'awdawdaw', 'awdawd', 'SQP', 'QA', 'taga cellphone', 'TESP Direct', 'TESP', 'TES Philippines', 'dotr', 'Active', 'wadadad@ss.com', NULL, 'awdawdaw', NULL, 'adawd', 'fjytrdrdhtr', 'ygfkyfjuy', 'jgjhjhj', 'qwsqwsq', 'yfjtfyt', '2026-01-20', NULL, '2026-01-22', '999-01_1768630287.jpg', '2026-01-17 06:11:27', 'awsaws', 'awsaw', 'awsaws'),
(12, 'tesing', 'test', 'sampe', 'trial', 'TRS', 'TECHNICAL RESEARCH SECTION', 'test', 'TESP Direct', 'TESP', 'TES Philippines', 'awdawdad', 'Active', 'TEST@GMAIL.COM', NULL, 'qkwhlaHWSL', NULL, 'qwqw`', 'SQWDaw', 'IHLhsjwksa', 'lakdlakjsa', 'awawda', 'awsawa', '2026-02-04', NULL, '2026-02-03', 'tesing_1768631040.png', '2026-01-17 06:25:17', 'awdawd', 'awdawda', 'adhawdaw'),
(13, 'nhtdhgdhdq', 'jgjggj', 'jgjjhgjhg', 'jhjhgjhgj', 'HMS', 'HEAVY MAINTENANCE SECTION', 'hthdgfs', 'TESP Direct', 'TESP', 'TES Philippines', 'k.gkjgjgjg', 'Active', 'sample@gmai.com', NULL, 'kyiukyiuy', NULL, 'aihlahw', 'aihaohd', 'asxas', 'axasxa', 'aasxa', 'axaxa', '2026-01-20', NULL, '2026-01-13', 'nhtdhgdhdq_1768632237.png', '2026-01-17 06:43:57', 'axaxsa', 'XAXAAX', 'ASXASXAX'),
(14, 'axas', 'aawwa', 'aacacad', 'awxaw', 'BFS', 'BUILDING FACILITIES SECTION', 'wsaq', 'TESP Direct', 'TESP', 'TES Philippines', 'wsasawsawa', 'Active', 'agagaga@gmail.com', NULL, 'asxasxaww', NULL, 'hfhfytf', 'uyyffy', 'wqlkshqowahsqhaw', 'ahkajhxaskhxak', 'jhakjxhakhxak', 'jhakjsxhakshx', '2026-01-18', NULL, '2026-02-05', 'axas_1ffe9e6246a7.jpg', '2026-01-17 06:51:08', 'ajhxaskxhakjshkjxa', 'wsqwasa', 'sqwsqws'),
(15, ';ojo', 'asasa', 'asaas', 'eididkid', 'SQP', 'SAFETY', 'wq', 'TESP Direct', 'TESP', 'TES Philippines', 'asasa', 'Active', 'habibi@gmail.com', NULL, 'iwiwiwqi', NULL, 'asaoaosaoa', 'saoaiiasiasiaiasiaisa', 'iiwisasiaias', 'asuasuausasau', 'auasuasuausa', 'aisiauasuaaussau', '2026-01-19', NULL, '2026-01-16', ';ojo_1768565768.jpg', '2026-01-17 07:53:35', 'ahahsahaha', 'fhshdhdhds', 'ahashashasha'),
(19, '012919192qw09wq9', 'eqwqq', 'qwqwsq', 'wsasdq', 'ADMIN', 'GAG', 'qw8q09q9q9qw', 'Agency', 'OTHERS - SUBCONS', 'TES P', '0wiadaoisjoi', 'Active', 'SAMPLE@MAIL.COM', NULL, '09358329823811', NULL, 'asijdoaisjoiasojd', 'IJODJAISJDOAIJSODIA', 'ASDASDAS', 'ADASDASD', 'ASDASDA', 'ASDASDAS', '2026-01-11', NULL, '2026-01-18', '012919192qw09wq9_a5f5812ef1fc.png', '2026-01-17 12:24:07', 'ADASDD', 'ASDASD', 'ASDASDAD'),
(20, 'emp-0007', 'John', 'm', 'Doe', 'ADMIN', 'ADMIN', 'samples', 'TESP Direct', 'TESP', 'TES Philippines', 'sample- sample', 'Active', '', NULL, '', NULL, 'awdawdwa', 'adawdawd', 'asdawdawd', 'awdawdaw', 'asdasda', 'sdasdas', '2026-01-22', NULL, '2026-01-30', 'emp-0007_823cf9a37629.jpg', '2026-01-21 05:15:59', 'adasda', 'asdasd', 'asdasd');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_requests`
--

CREATE TABLE `pending_requests` (
  `id` int(11) NOT NULL,
  `emp_id` varchar(32) DEFAULT NULL,
  `request_type` enum('EDIT_INFO','UPLOAD_DOC') NOT NULL,
  `json_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`json_payload`)),
  `submitted_by` varchar(64) NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pending_requests`
--

INSERT INTO `pending_requests` (`id`, `emp_id`, `request_type`, `json_payload`, `submitted_by`, `status`, `created_at`) VALUES
(1, 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"140d2f54480898ca7dc3db3cb42a1782\",\"original_name\":\"Score sheets.pdf\",\"category\":\"Evaluation\",\"file_path\":\"140d2f54480898ca7dc3db3cb42a1782.pdf\"}', 'eyy', 'REJECTED', '2026-01-15 02:13:20'),
(2, 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"fed1f1954b270beb11b80204e56544d2\",\"original_name\":\"Score sheets.pdf\",\"category\":\"Evaluation\",\"file_path\":\"fed1f1954b270beb11b80204e56544d2.pdf\"}', 'eyy', 'REJECTED', '2026-01-15 02:14:41'),
(3, 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"f547c719db31941cca11bd6a73fb7e9c\",\"original_name\":\"Score sheets.pdf\",\"category\":\"Evaluation\",\"file_path\":\"f547c719db31941cca11bd6a73fb7e9c.pdf\"}', 'oy', 'APPROVED', '2026-01-15 02:26:16'),
(4, 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"ced3613f886fe8cb416f3b6b85253182\",\"original_name\":\"7.1 Logon password self-reset simple manual.pdf\",\"category\":\"Notice\",\"file_path\":\"ced3613f886fe8cb416f3b6b85253182.pdf\"}', 'ayy', 'PENDING', '2026-01-15 02:27:11'),
(5, 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"4d78c1c38ab93767669729f2ec7b6cbf\",\"original_name\":\"7.1 Logon password self-reset simple manual.pdf\",\"category\":\"Contract\",\"file_path\":\"4d78c1c38ab93767669729f2ec7b6cbf.pdf\"}', 'admin', 'PENDING', '2026-01-15 05:40:15');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `ip_address` varchar(45) NOT NULL,
  `request_count` int(11) DEFAULT 1,
  `last_request` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`ip_address`, `request_count`, `last_request`) VALUES
('::1', 423, '2026-01-21 10:58:51');

-- --------------------------------------------------------

--
-- Table structure for table `requests`
--

CREATE TABLE `requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `request_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) NOT NULL,
  `json_payload` text NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  `admin_comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','STAFF','HR') NOT NULL DEFAULT 'STAFF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADMIN', '2026-01-15 09:58:12'),
(2, 'staff', '$2y$10$ifKktYCUd7chvP8VY.SRf.B9hZrH1ow4.JPh9M9CaHJmzIZjYnIh2', 'STAFF', '2026-01-16 11:24:44'),
(3, 'hr1', '$2y$10$XpBlW0hcNipX2uwjfEh6XePXP0jljgQ0wx7YBAwAviuBjUiGJQoqi', 'HR', '2026-01-19 09:45:08');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `file_uuid` (`file_uuid`),
  ADD KEY `fk_documents_employee` (`employee_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `emp_id` (`emp_id`);

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
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`ip_address`);

--
-- Indexes for table `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=103;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`emp_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
