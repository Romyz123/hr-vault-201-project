-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 24, 2026 at 04:20 PM
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
(102, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ALL, Files: 27]', '::1', '2026-01-21 10:59:00'),
(103, 2, 'REQUEST_DOC', 'Submitted document: SampleofvaccineCard.jpg', '::1', '2026-01-21 23:56:10'),
(104, 1, 'UPLOAD_DOC', 'Directly uploaded: invitation.jpg', '::1', '2026-01-22 01:06:08'),
(105, 3, 'UPLOAD_DOC', 'Directly uploaded: trialsabagongdeletefunction.jpg', '::1', '2026-01-22 01:18:42'),
(106, 1, 'UPLOAD_DOC', 'Directly uploaded: sampleparamadelete.jpg', '::1', '2026-01-22 01:23:55'),
(107, 3, 'UPLOAD_DOC', 'Directly uploaded: trialulitparasadeletefucntion.jpg', '::1', '2026-01-22 01:29:04'),
(108, 3, 'UPLOAD_DOC', 'Directly uploaded: deletnatalagasya.jpg', '::1', '2026-01-22 01:30:16'),
(109, 3, 'REJECTED_REQUEST', 'Rejected request: UPLOAD_DOC', '::1', '2026-01-22 01:39:51'),
(110, 3, 'UPLOAD_DOC', 'Directly uploaded: tahimiklangakosaumpisa.jpg', '::1', '2026-01-22 02:47:42'),
(111, 3, 'UPLOAD_DOC', 'Directly uploaded: Warhammer.png', '::1', '2026-01-22 03:19:42'),
(112, 1, 'UPLOAD_DOC', 'Directly uploaded: warhammmer.png', '::1', '2026-01-22 03:51:14'),
(113, 3, 'UPLOAD_DOC', 'Directly uploaded: Warhammer.png', '::1', '2026-01-22 05:16:29'),
(114, 3, 'DELETE_DOC', 'Deleted file: Warhammer.png', '::1', '2026-01-22 05:16:38'),
(115, 1, 'SYSTEM_BACKUP', 'Admin downloaded full database backup.', '::1', '2026-01-22 05:21:22'),
(116, 1, 'AUTO_BACKUP', 'Weekly Friday Backup created successfully.', '::1', '2026-01-22 06:11:21'),
(117, 1, 'UPLOAD_DOC', 'Directly uploaded: awdwadawdaw.jpg', '::1', '2026-01-22 06:40:13'),
(118, 1, 'DELETE_DOC', 'Deleted file: awdwadawdaw.jpg', '::1', '2026-01-22 06:40:31'),
(119, 1, 'BULK_IMPORT', 'Imported 125 employees. Errors: 1', '::1', '2026-01-22 08:59:05'),
(120, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 09:52:30'),
(121, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 09:56:20'),
(122, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 09:56:42'),
(123, 1, 'EDIT_PROFILE', 'Updated SUP-016', '::1', '2026-01-22 10:08:21'),
(124, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 10:27:46'),
(125, 1, 'UPLOAD_DOC', 'Directly uploaded: utdjtydy.jpeg', '::1', '2026-01-22 11:01:05'),
(126, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: All, Files: 2, Search: \'temp-003\']', '::1', '2026-01-22 11:02:06'),
(127, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 11:23:18'),
(128, 1, 'BULK_IMPORT', 'Imported 0 employees. Errors: 126', '::1', '2026-01-22 11:35:22'),
(129, 1, 'BULK_IMPORT', 'Imported 0 employees. Errors: 126', '::1', '2026-01-22 11:35:32'),
(130, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 11:35:49'),
(131, 1, 'DELETE_DOC', 'Deleted file: utdjtydy.jpeg', '::1', '2026-01-22 11:41:00'),
(132, 1, 'ADD_EMPLOYEE', 'Added Romyr Chyrsfer Abes (temp)', '::1', '2026-01-22 12:48:45'),
(133, 1, 'ERROR', 'Edit employee failed: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'request_note\' in \'field list\'', '::1', '2026-01-22 13:11:07'),
(134, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:28:01'),
(135, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:39:05'),
(136, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 146', '::1', '2026-01-22 13:40:38'),
(137, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:41:41'),
(138, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:42:39'),
(139, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:42:45'),
(140, 1, 'APPROVED_EDIT', 'Approved Profile Edit for ID: 146', '::1', '2026-01-22 13:43:45'),
(141, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:45:26'),
(142, 1, 'EDIT_PROFILE', 'Updated temp', '::1', '2026-01-22 13:45:35'),
(143, 1, 'IMPORT_SUCCESS', 'Imported 125 (JORATECH) Batch: BATCH_20260122_152815', '::1', '2026-01-22 14:28:15'),
(144, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260122_152815 (125 records removed)', '::1', '2026-01-22 14:29:57'),
(145, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS) Batch: BATCH_20260122_153402', '::1', '2026-01-22 14:34:02'),
(146, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260122_153402 (125 records removed)', '::1', '2026-01-22 14:35:56'),
(147, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-22 14:41:31'),
(148, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260122_154130', '::1', '2026-01-22 14:42:00'),
(149, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260122_154130', '::1', '2026-01-22 23:50:35'),
(150, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-22 23:50:57'),
(151, 1, 'AUTO_BACKUP', 'Weekly Friday Backup created successfully.', '::1', '2026-01-22 23:59:07'),
(152, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_005056', '::1', '2026-01-23 00:04:41'),
(153, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_005056', '::1', '2026-01-23 00:13:18'),
(154, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 00:13:59'),
(155, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_011358', '::1', '2026-01-23 00:25:39'),
(156, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 00:27:07'),
(157, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_012706', '::1', '2026-01-23 00:27:10'),
(158, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 00:28:36'),
(159, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_012836', '::1', '2026-01-23 00:32:40'),
(160, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 00:43:17'),
(161, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_014317', '::1', '2026-01-23 00:46:17'),
(162, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 00:48:50'),
(163, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_014849', '::1', '2026-01-23 00:51:31'),
(164, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_014849', '::1', '2026-01-23 00:51:34'),
(165, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 00:51:52'),
(166, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_015151', '::1', '2026-01-23 00:57:37'),
(167, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 01:04:03'),
(168, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_020403', '::1', '2026-01-23 01:04:56'),
(169, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 01:05:06'),
(170, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_005056', '::1', '2026-01-23 01:05:49'),
(171, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_020506', '::1', '2026-01-23 01:05:55'),
(172, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_020506', '::1', '2026-01-23 01:05:59'),
(173, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260122_153402', '::1', '2026-01-23 01:06:06'),
(174, 1, 'ADD_EMPLOYEE', 'Added Buang Sya (ey)', '::1', '2026-01-23 01:12:51'),
(175, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 01:13:27'),
(176, 1, 'EDIT_PROFILE', 'Updated JC-24-0923MD', '::1', '2026-01-23 01:16:40'),
(177, 1, 'EDIT_PROFILE', 'Updated JC-24-0923MD', '::1', '2026-01-23 01:17:23'),
(178, 1, 'EDIT_PROFILE', 'Updated JC-24-0923MD', '::1', '2026-01-23 01:18:00'),
(179, 1, 'EDIT_PROFILE', 'Updated JC-24-0701JG', '::1', '2026-01-23 01:18:13'),
(180, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_021326', '::1', '2026-01-23 01:24:59'),
(181, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_020506', '::1', '2026-01-23 01:25:05'),
(182, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_020506', '::1', '2026-01-23 01:25:08'),
(183, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 01:28:26'),
(184, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_022826', '::1', '2026-01-23 01:39:33'),
(185, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 01:39:52'),
(186, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_023952', '::1', '2026-01-23 01:43:07'),
(187, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 01:43:36'),
(188, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_024335', '::1', '2026-01-23 01:45:14'),
(189, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 01:50:47'),
(190, 1, 'EDIT_PROFILE', 'Updated TC-234', '::1', '2026-01-23 01:52:09'),
(191, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_025047', '::1', '2026-01-23 02:03:39'),
(192, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_025047', '::1', '2026-01-23 02:03:55'),
(193, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 02:04:18'),
(194, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_030417', '::1', '2026-01-23 02:05:24'),
(195, 1, 'IMPORT_SUCCESS', 'Imported 125 (TESP)', '::1', '2026-01-23 02:07:02'),
(196, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_030702', '::1', '2026-01-23 02:07:32'),
(197, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 02:07:48'),
(198, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_030747', '::1', '2026-01-23 02:09:39'),
(199, 1, 'EDIT_PROFILE', 'Updated ey', '::1', '2026-01-23 02:18:18'),
(200, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 02:35:48'),
(201, 1, 'EDIT_PROFILE', 'Updated PE-415', '::1', '2026-01-23 02:36:17'),
(202, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_033548', '::1', '2026-01-23 02:38:24'),
(203, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 02:38:48'),
(204, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_033548', '::1', '2026-01-23 02:38:59'),
(205, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_033848', '::1', '2026-01-23 02:43:12'),
(206, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 03:14:35'),
(207, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_041434', '::1', '2026-01-23 03:14:37'),
(208, 1, 'ADD_EMPLOYEE', 'Added Sample Sample (Sqp-001)', '::1', '2026-01-23 03:17:42'),
(209, 1, 'EDIT_PROFILE', 'Updated Sqp-001', '::1', '2026-01-23 03:17:52'),
(210, 1, 'EDIT_PROFILE', 'Updated Sqp-001', '::1', '2026-01-23 03:18:25'),
(211, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_041434', '::1', '2026-01-23 03:24:43'),
(212, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_024335', '::1', '2026-01-23 03:27:26'),
(213, 1, 'IMPORT_SUCCESS', 'Imported 4 (TESP)', '::1', '2026-01-23 03:28:29'),
(214, 1, 'EDIT_PROFILE', 'Updated TEST-PSS', '::1', '2026-01-23 03:31:08'),
(215, 1, 'EDIT_PROFILE', 'Updated TEST-PSS', '::1', '2026-01-23 03:32:10'),
(216, 1, 'UPLOAD_DOC', 'Directly uploaded: trialcontract.jpg', '::1', '2026-01-23 06:05:04'),
(217, 1, 'UPLOAD_DOC', 'Directly uploaded: awhdliuawdliuaw.jpg', '::1', '2026-01-23 06:05:35'),
(218, 1, 'UPLOAD_DOC', 'Directly uploaded: ey_GovernmentIDs_1769148365.jpg', '::1', '2026-01-23 06:06:05'),
(219, 1, 'UPLOAD_DOC', 'Directly uploaded: TEST-SQP_Contract_1769148381.jpg', '::1', '2026-01-23 06:06:21'),
(220, 1, 'UPLOAD_DOC', 'Directly uploaded: email.jpg', '::1', '2026-01-23 06:06:41'),
(221, 1, 'UPLOAD_DOC', 'Directly uploaded: wahammer.png', '::1', '2026-01-23 06:07:05'),
(222, 1, 'UPLOAD_DOC', 'Directly uploaded: TEST-CLN_Medical_1769148444.jpg', '::1', '2026-01-23 06:07:24'),
(223, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ADMIN, Files: 1]', '::1', '2026-01-23 06:09:21'),
(224, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ALL, Files: 7]', '::1', '2026-01-23 06:12:31'),
(225, 1, 'EDIT_PROFILE', 'Updated TEMP-003', '::1', '2026-01-23 06:12:57'),
(226, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ALL, Files: 7]', '::1', '2026-01-23 06:13:14'),
(227, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ADMIN, Files: 1]', '::1', '2026-01-23 06:13:51'),
(228, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ALL, Files: 1]', '::1', '2026-01-23 06:19:47'),
(229, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ADMIN, Files: 1]', '::1', '2026-01-23 06:20:43'),
(230, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: All, Files: 1, Search: \'TEST-PSS\']', '::1', '2026-01-23 06:22:42'),
(231, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: All, Files: 1, Search: \'.MS\']', '::1', '2026-01-23 06:23:22'),
(232, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: All, Files: 7, Search: \'.\']', '::1', '2026-01-23 06:23:33'),
(233, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: All, Files: 7, Search: \'.\']', '::1', '2026-01-23 06:23:50'),
(234, 1, 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: All, Files: 3, Search: \'.MR\']', '::1', '2026-01-23 06:24:05'),
(235, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 06:41:43'),
(236, 1, 'EXPORT_ZIP', 'Exported 132 employee folders.', '::1', '2026-01-23 06:42:06'),
(237, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_074143', '::1', '2026-01-23 06:43:10'),
(238, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_042829', '::1', '2026-01-23 06:43:17'),
(239, 1, 'IMPORT_SUCCESS', 'Imported 4 (TESP)', '::1', '2026-01-23 06:48:16'),
(240, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_074816', '::1', '2026-01-23 06:48:46'),
(241, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 06:57:06'),
(242, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_075706', '::1', '2026-01-23 06:57:12'),
(243, 1, 'EXPORT_ZIP', 'Exported 3 folders.', '::1', '2026-01-23 06:57:31'),
(244, 1, 'EDIT_PROFILE', 'Updated TEMP-003', '::1', '2026-01-23 06:59:49'),
(245, 1, 'USER_ADD', 'Created user: STAFF2 (STAFF)', '::1', '2026-01-23 08:05:53'),
(252, 1, 'UPLOAD_DOC', 'Directly uploaded: medicalkodaw.jpeg', '::1', '2026-01-23 08:33:58'),
(253, 1, 'CASE_ADD', 'Filed case against TEMP-003: Negligence of Duty', '::1', '2026-01-23 08:38:35'),
(254, 1, 'CASE_CLOSE', 'Closed Case ID: 1', '::1', '2026-01-23 08:38:54'),
(255, 1, 'CASE_ADD', 'Filed case against ey: Habitual Tardiness', '::1', '2026-01-23 08:40:42'),
(256, 1, 'CASE_ADD', 'Filed case: sexual harassment', '::1', '2026-01-23 08:51:00'),
(257, 1, 'CASE_ADD', 'Filed case: ;klhgftyrtreytrstd', '::1', '2026-01-23 08:54:02'),
(258, 1, 'CASE_ADD', 'Filed case: ;klhgftyrtreytrstd', '::1', '2026-01-23 08:59:17'),
(259, 1, 'CASE_ADD', 'Filed case: tykuyrjuytrtsfdsgdhjkhjlk/', '::1', '2026-01-23 09:04:32'),
(260, 1, 'UPLOAD_DOC', 'Directly uploaded: dimploma.png', '::1', '2026-01-23 09:07:44'),
(261, 1, 'DELETE_DOC', 'Deleted file: medicalkodaw.jpeg', '::1', '2026-01-23 09:07:57'),
(262, 1, 'CASE_ADD', 'Filed case: tardiness', '::1', '2026-01-23 09:14:45'),
(263, 1, 'DELETE_DOC', 'Deleted file: trial foo stub.pdf', '::1', '2026-01-23 09:19:25'),
(264, 1, 'DELETE_DOC', 'Deleted file: Print naaaaa yung invitation.pdf', '::1', '2026-01-23 09:19:30'),
(265, 1, 'DELETE_DOC', 'Deleted file: trialcontract.jpg', '::1', '2026-01-23 09:19:53'),
(266, 1, 'DELETE_DOC', 'Deleted file: awhdliuawdliuaw.jpg', '::1', '2026-01-23 09:20:11'),
(267, 1, 'RESOLVED_ALERT', 'Marked alert as resolved: updated', '::1', '2026-01-23 09:20:27'),
(268, 1, 'RESOLVED_ALERT', 'Marked alert as resolved: expoired', '::1', '2026-01-23 09:20:42'),
(269, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 10:13:42'),
(270, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 10:14:30'),
(271, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 10:14:41'),
(272, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_111441', '::1', '2026-01-23 11:29:35'),
(273, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_111430', '::1', '2026-01-23 11:29:38'),
(274, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_111341', '::1', '2026-01-23 11:29:41'),
(275, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 11:32:00'),
(276, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-23 11:32:08'),
(277, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-23 11:32:25'),
(278, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_111441', '::1', '2026-01-23 23:41:43'),
(279, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_123225', '::1', '2026-01-23 23:41:46'),
(280, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_123208', '::1', '2026-01-23 23:41:48'),
(281, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260123_123159', '::1', '2026-01-23 23:41:50'),
(282, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-23 23:42:10'),
(283, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_004209', '::1', '2026-01-24 00:09:37'),
(284, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-24 00:13:55'),
(285, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-24 00:14:42'),
(286, 1, 'IMPORT_SUCCESS', 'Imported 113 (JORATECH)', '::1', '2026-01-24 00:14:52'),
(287, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_011451', '::1', '2026-01-24 00:17:03'),
(288, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_011442', '::1', '2026-01-24 00:17:06'),
(289, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_011354', '::1', '2026-01-24 00:17:08'),
(290, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-24 00:17:17'),
(291, 1, 'IMPORT_SUCCESS', 'Imported 125 (UNLISOLUTIONS)', '::1', '2026-01-24 00:17:27'),
(292, 1, 'IMPORT_SUCCESS', 'Imported 166 (JORATECH)', '::1', '2026-01-24 00:17:37'),
(293, 1, 'EDIT_PROFILE', 'Updated PE-424', '::1', '2026-01-24 00:32:37'),
(294, 1, 'EDIT_PROFILE', 'Updated TEMP-003', '::1', '2026-01-24 00:32:48'),
(295, 1, 'EDIT_PROFILE', 'Updated PE-415', '::1', '2026-01-24 00:33:05'),
(296, 1, 'EDIT_PROFILE', 'Updated MHI-015', '::1', '2026-01-24 00:33:29'),
(297, 1, 'EDIT_PROFILE', 'Updated P-084', '::1', '2026-01-24 01:25:03'),
(298, 1, 'EDIT_PROFILE', 'Updated Sqp-001', '::1', '2026-01-24 01:26:04'),
(299, 1, 'EDIT_PROFILE', 'Updated ey', '::1', '2026-01-24 01:26:16'),
(300, 1, 'EDIT_PROFILE', 'Updated ey', '::1', '2026-01-24 01:26:20'),
(301, 1, 'EDIT_PROFILE', 'Updated ey', '::1', '2026-01-24 01:26:29'),
(302, 1, 'EDIT_PROFILE', 'Updated Sqp-001', '::1', '2026-01-24 02:29:05'),
(303, 1, 'EDIT_PROFILE', 'Updated Sqp-001', '::1', '2026-01-24 02:29:58'),
(304, 1, 'EDIT_PROFILE', 'Updated P-084', '::1', '2026-01-24 02:30:53'),
(305, 1, 'EDIT_PROFILE', 'Updated ey', '::1', '2026-01-24 02:32:11'),
(306, 1, 'EDIT_PROFILE', 'Updated Sqp-001', '::1', '2026-01-24 02:37:14'),
(307, 1, 'EDIT_PROFILE', 'Updated PE-415', '::1', '2026-01-24 02:37:53'),
(308, 1, 'IMPORT_SUCCESS', 'Imported 113 (JORATECH)', '::1', '2026-01-24 02:52:27'),
(309, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_035226', '::1', '2026-01-24 05:43:34'),
(310, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_011737', '::1', '2026-01-24 05:43:37'),
(311, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_011727', '::1', '2026-01-24 05:43:39'),
(312, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_011716', '::1', '2026-01-24 05:43:41'),
(313, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-24 06:15:43'),
(314, 1, 'USER_EDIT', 'Updated User ID: 1', '::1', '2026-01-24 07:53:07'),
(315, 1, 'ADD_EMPLOYEE', 'Added Test Test (emp-001)', '::1', '2026-01-24 07:56:21'),
(316, 1, 'EDIT_PROFILE', 'Updated emp-001', '::1', '2026-01-24 07:58:16'),
(317, 1, 'DELETE_EMPLOYEE', 'Deleted: emp-001', '::1', '2026-01-24 07:59:06'),
(318, 1, 'ADD_EMPLOYEE', 'Added Test Abes (test-001)', '::1', '2026-01-24 08:16:05'),
(319, 1, 'ADD_EMPLOYEE', 'Added Test Sample (emp-001)', '::1', '2026-01-24 08:21:42'),
(320, 1, 'ADD_EMPLOYEE', 'Added Test Sample (emp-007)', '::1', '2026-01-24 08:24:20'),
(321, 1, 'LOGOUT', 'User logged out (Manual Logout)', '::1', '2026-01-24 08:35:42'),
(322, 1, 'LOGIN', 'User logged in (IP: ::1)', '::1', '2026-01-24 08:59:19'),
(323, 1, 'LOGIN', 'User logged in (IP: ::1)', '::1', '2026-01-24 09:13:55'),
(324, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_071543', '::1', '2026-01-24 09:14:19'),
(325, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_071543', '::1', '2026-01-24 09:16:07'),
(326, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_071543', '::1', '2026-01-24 09:16:12'),
(327, 1, 'EDIT_PROFILE', 'Updated emp-007', '::1', '2026-01-24 10:45:05'),
(328, 1, 'IMPORT_SUCCESS', 'Imported 125 (TESP)', '::1', '2026-01-24 15:08:24'),
(329, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_160824', '::1', '2026-01-24 15:09:20'),
(330, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-24 15:09:35'),
(331, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260124_160935', '::1', '2026-01-24 15:14:29');

-- --------------------------------------------------------

--
-- Table structure for table `disciplinary_cases`
--

CREATE TABLE `disciplinary_cases` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `violation_type` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `action_taken` enum('Verbal Warning','Written Warning','Suspension','Termination','Pending') DEFAULT 'Pending',
  `incident_date` date NOT NULL,
  `status` enum('Open','Closed') DEFAULT 'Open',
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `disciplinary_cases`
--

INSERT INTO `disciplinary_cases` (`id`, `employee_id`, `violation_type`, `description`, `action_taken`, `incident_date`, `status`, `attachment_path`, `created_at`) VALUES
(1, 'TEMP-003', 'Negligence of Duty', 'khgfjtydhrdtgsgrdn', 'Written Warning', '2026-01-23', 'Closed', '1769157515_615255911_1178480907828204_162102133835023395_n.jpg', '2026-01-23 08:38:35'),
(2, 'ey', 'Habitual Tardiness', 'late sya lagi', 'Verbal Warning', '2026-01-23', 'Closed', '1769157642_615255911_1178480907828204_162102133835023395_n.jpg', '2026-01-23 08:40:42'),
(3, 'Sqp-001', 'sexual harassment', 'eyyyyy', 'Pending', '2026-01-23', 'Open', 'disciplinary/1769158260_615255911_1178480907828204_162102133835023395_n.jpg', '2026-01-23 08:51:00'),
(4, 'Sqp-001', ';klhgftyrtreytrstd', 'ihouyfrii', 'Pending', '2026-01-23', 'Open', 'disciplinary/1769158442_Print naaaaa yung invitation.pdf', '2026-01-23 08:54:02'),
(5, 'Sqp-001', ';klhgftyrtreytrstd', 'ihouyfrii', 'Pending', '2026-01-23', 'Open', 'disciplinary/1769158757_Print naaaaa yung invitation.pdf', '2026-01-23 08:59:17'),
(6, 'Sqp-001', 'tykuyrjuytrtsfdsgdhjkhjlk/', '', 'Suspension', '2026-01-23', 'Open', 'DISCIPLINARY_1769159072_trial foo stub.pdf', '2026-01-23 09:04:32'),
(7, 'Sqp-001', 'tardiness', '', 'Written Warning', '2026-01-23', 'Open', 'DISCIPLINARY_1769159685_xmaspartyprogramflow2.pdf', '2026-01-23 09:14:45');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(32) NOT NULL,
  `file_uuid` varchar(255) DEFAULT NULL,
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
(50, 'ey', '98e28051-f821-11f0-9d0c-b4e9b890daba', 'ey_GovernmentIDs_1769148365.jpg', '', 'ey_GovernmentIDs_1769148365.jpg', '2026-01-21', 'eyyyy te', '2026-01-23 06:06:05', 1, 1, 'updated'),
(56, 'Sqp-001', 'ff7edb67073ef614a9ef44c09a6ca839', '615255911_1178480907828204_162102133835023395_n.jpg', '', 'disciplinary/1769158260_615255911_1178480907828204_162102133835023395_n.jpg', NULL, NULL, '2026-01-23 08:51:00', 1, 0, NULL),
(60, 'TEMP-003', 'f95ed5f2-f83a-11f0-9d0c-b4e9b890daba', 'dimploma.png', 'Contract', 'dimploma.png', '2026-01-23', '', '2026-01-23 09:07:44', 1, 1, 'expoired'),
(61, 'Sqp-001', 'e589cc7175d91a303976434b9f2ae337', 'xmas party program flow (2).pdf', '', 'DISCIPLINARY_1769159685_xmaspartyprogramflow2.pdf', NULL, NULL, '2026-01-23 09:14:45', 1, 0, NULL);

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
  `exit_date` date DEFAULT NULL,
  `exit_reason` varchar(255) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT 'default.png',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `emergency_name` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(50) DEFAULT NULL,
  `emergency_address` text DEFAULT NULL,
  `import_batch` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `emp_id`, `first_name`, `middle_name`, `last_name`, `dept`, `section`, `job_title`, `employment_type`, `agency_name`, `company_name`, `previous_company`, `status`, `email`, `phone`, `contact_number`, `address`, `present_address`, `permanent_address`, `sss_no`, `tin_no`, `pagibig_no`, `philhealth_no`, `hire_date`, `exit_date`, `exit_reason`, `gender`, `birth_date`, `birthdate`, `avatar_path`, `created_at`, `emergency_name`, `emergency_contact`, `emergency_address`, `import_batch`) VALUES
(146, 'TEMP-003', 'Romy Chyrsfer', 'Magalong', 'Abes', 'ADMIN', 'ADMIN', 'It Staff', 'Agency', 'UNLISOLUTIONS', 'TES Philippines', 'WEBTEK', 'Active', 'romyr@abes.com', NULL, '091212399009', NULL, 'sample addres', 'sample address', '09291909', '219919', '819828', '192091921', '2024-12-05', NULL, NULL, 'Male', '2001-06-22', NULL, 'temp_b26a4eb0.jpg', '2026-01-22 12:48:45', 'Sample Person', '092188212121122', 'qwqqwqwq', NULL),
(3165, 'ey', 'Buang', 'Daw', 'Sya', 'DOS', 'DOS', 'Taga Tulog', 'TESP Direct', 'TESP', 'TES Philippines', 'ayyyy taga gising', 'AWOL', 'Buang@daw.com', NULL, '09218128117', NULL, 'AOHDAWHDOIHAOID', 'AOIHDOIAWHDOIAHW', 'AWDAWDAW', 'AEDAWDAWD', 'WDADWADAW', 'DADAWDAWD', '2026-01-23', '2026-01-30', 'may kabit sa office', 'Male', '2024-11-22', NULL, 'ey_aeba2154.jpg', '2026-01-23 01:12:51', 'Adawdawd', '091212121231231212', 'ADAWDDAWD', NULL),
(5248, 'Sqp-001', 'Sample', 'Sample', 'Sample', 'SQP', 'IT', 'It', 'Agency', 'JORATECH', 'TES Philippines', 'sample', 'Terminated', 'Sample@example.com', NULL, '09121312312', NULL, 'awdawdawd', 'AWDAWDAWD', '8217123812389719872', '18239127398123987127', '89712987312987123978', '8712387912387912', '2026-01-23', '2026-01-30', 'bounce na ako boss', 'Male', '2023-11-16', NULL, 'Sqp-001_b7d2dfa1.jpg', '2026-01-23 03:17:42', 'Sample', '091289982919812', 'sample', NULL),
(10044, 'test-001', 'Test', 'Test', 'Abes', 'SQP', 'SAFETY', 'Test', 'TESP Direct', 'TESP', 'TES Philippines', 'adasdasd', 'Active', 'chrisvaleza2@gmail.com', NULL, '09322323132232323232', NULL, 'eyyyy', 'eyyyyy', 'asdadqweqweqwe', 'qeqweqweqwe', 'qeqweqweq', 'wqeqweqweqe', '2026-01-24', NULL, NULL, 'Female', '2000-07-24', NULL, 'test-001_6d21ad5920fd.jpg', '2026-01-24 08:16:00', 'Qweqweqew', '09990000021211221111', 'dasdqweqweqw', NULL),
(10045, 'emp-001', 'Test', 'Tes', 'Sample', 'SQP', 'IT', 'Test', 'TESP Direct', 'TESP', 'TES Philippines', 'TEST', 'Active', 'chrisvaleza2@gmail.com', NULL, '91202989109011', NULL, 'sample', 'sample', '9912901290192910', '92109029102912901099', '92109211920190190191', '90219021091911901912', '2026-01-24', NULL, NULL, 'Male', '2000-07-24', NULL, 'default.png', '2026-01-24 08:21:36', 'Sample', '21921012121121129', 'asasdadasasda', NULL),
(10046, 'emp-007', 'Test', 'Tes', 'Sample', 'LMS', 'LIGHT MAINTENANCE', 'Test', 'TESP Direct', 'TESP', 'TES Philippines', 'TEST', 'Active', 'chrisvaleza2@gmail.com', NULL, '91202989109011', NULL, 'sample', 'sample', '9912901290192910', '92109029102912901099', '92109211920190190191', '90219021091911901912', '2026-01-24', NULL, '', 'Male', '2000-07-24', NULL, 'default.png', '2026-01-24 08:24:15', 'Sample', '21921012121121129', 'asasdadasasda', NULL);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employee_history`
--

INSERT INTO `employee_history` (`id`, `employee_id`, `changed_by`, `change_date`, `details`) VALUES
(1, 'CTS-018', 'admin', '2026-01-22 18:27:46', 'Department: ADMIN -> CTS'),
(2, 'SAS-028', 'admin', '2026-01-22 19:23:18', 'Department: ADMIN -> SQP'),
(3, 'SAS-028', 'admin', '2026-01-22 19:35:49', 'Department: ADMIN -> SQP');

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(12, 2, 'Update Approved', 'Your profile update request was approved.', 'success', 0, '2026-01-22 13:43:45');

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
('::1', 177, '2026-01-24 15:14:38');

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
  `email` varchar(100) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `email`, `reset_token`, `reset_expires`, `created_at`) VALUES
(1, 'admin', '$2y$10$inHNZtMdWM5z0/fsf2j02eH/K8BJygx.sD1TO6smGXcwIO/.qr0KK', 'ADMIN', 'romyrabes64@gmail.com', '167287', '2026-01-24 11:13:14', '2026-01-15 09:58:12'),
(2, 'staff', '$2y$10$ifKktYCUd7chvP8VY.SRf.B9hZrH1ow4.JPh9M9CaHJmzIZjYnIh2', 'STAFF', NULL, NULL, NULL, '2026-01-16 11:24:44'),
(3, 'hr1', '$2y$10$XpBlW0hcNipX2uwjfEh6XePXP0jljgQ0wx7YBAwAviuBjUiGJQoqi', 'HR', NULL, NULL, NULL, '2026-01-19 09:45:08'),
(4, 'Staff1', '$2y$10$TTMdx029VEEkOcHJvSfODeCqDIv7S8FwQ6tkKkaGmvmSki9iuAcsa', 'STAFF', NULL, NULL, NULL, '2026-01-22 00:18:34'),
(6, 'STAFF2', '$2y$10$36mO4TenkbQwDMVGhrcu7uG9/pT/PG.x9H1Uy.9Q08felFATlAaUa', 'STAFF', NULL, NULL, NULL, '2026-01-23 08:05:53');

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
-- Indexes for table `disciplinary_cases`
--
ALTER TABLE `disciplinary_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

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
  ADD UNIQUE KEY `emp_id` (`emp_id`),
  ADD KEY `import_batch` (`import_batch`);

--
-- Indexes for table `employee_history`
--
ALTER TABLE `employee_history`
  ADD PRIMARY KEY (`id`);

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
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=332;

--
-- AUTO_INCREMENT for table `disciplinary_cases`
--
ALTER TABLE `disciplinary_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10567;

--
-- AUTO_INCREMENT for table `employee_history`
--
ALTER TABLE `employee_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `disciplinary_cases`
--
ALTER TABLE `disciplinary_cases`
  ADD CONSTRAINT `disciplinary_cases_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`emp_id`) ON DELETE CASCADE;

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
