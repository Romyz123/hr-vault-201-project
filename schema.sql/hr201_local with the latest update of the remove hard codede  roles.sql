-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Feb 09, 2026 at 11:14 AM
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
(1, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-02-07 03:09:52'),
(2, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260207_040952', '::1', '2026-02-07 04:09:14'),
(3, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-02-07 04:09:33'),
(4, 1, 'BULK_PRINT', 'Generated project contracts for 19 employees.', '::1', '2026-02-07 04:10:29'),
(5, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260207_050933', '::1', '2026-02-07 05:11:59'),
(6, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-02-07 05:12:26'),
(7, 1, 'GENERATE_DOC', 'Generated project for Delfin Dineros Jr. Sicad (PE-415)', '::1', '2026-02-07 05:13:26'),
(8, 1, 'UPLOAD_DOC', 'Directly uploaded: NBI Clearance.png', '::1', '2026-02-07 05:48:36'),
(9, 1, 'REQUEST_EDIT_DOC', 'Requested edit for Doc ID 1', '::1', '2026-02-07 05:49:38'),
(10, 1, 'REQUEST_EDIT_DOC', 'Requested edit for Doc ID 1', '::1', '2026-02-07 05:49:59'),
(11, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 05:51:54'),
(12, 1, 'REQUEST_EDIT_DOC', 'Requested edit for Doc ID 1', '::1', '2026-02-07 05:53:49'),
(13, 1, 'REQUEST_EDIT_DOC', 'Requested edit for Doc ID 1', '::1', '2026-02-07 05:54:25'),
(14, 1, 'APPROVED_EDIT_DOC', 'Approved edit for Doc ID: 1', '::1', '2026-02-07 05:54:43'),
(15, 1, 'APPROVED_EDIT_DOC', 'Approved edit for Doc ID: 1', '::1', '2026-02-07 05:57:21'),
(16, 1, 'APPROVED_EDIT_DOC', 'Approved edit for Doc ID: 1', '::1', '2026-02-07 05:57:23'),
(17, 1, 'APPROVED_EDIT_DOC', 'Approved edit for Doc ID: 1', '::1', '2026-02-07 05:57:24'),
(18, 1, 'EDIT_DOC', 'Updated Doc ID 1 (kunwari  Clearance.png)', '::1', '2026-02-07 06:03:26'),
(19, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate.png', '::1', '2026-02-07 06:04:45'),
(20, 1, 'UPLOAD_DOC', 'Directly uploaded: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 06:49:44'),
(21, 1, 'UPLOAD_DOC', 'Directly uploaded: Certificate.png', '::1', '2026-02-07 07:03:00'),
(22, 1, 'UPLOAD_DOC', 'Directly uploaded: thumb-1920-1287814.png', '::1', '2026-02-07 07:31:46'),
(23, 1, 'UPLOAD_DOC', 'Directly uploaded: lock 2 in.jpg', '::1', '2026-02-07 07:40:38'),
(24, 1, 'UPLOAD_DOC', 'Directly uploaded: Contract.jpg', '::1', '2026-02-07 07:46:16'),
(25, 1, 'UPLOAD_DOC', 'Directly uploaded: NBI Clearance.jpg', '::1', '2026-02-07 07:50:11'),
(26, 1, 'LOGOUT', 'User logged out (Session_Expired_Auto)', '::1', '2026-02-07 08:09:46'),
(27, 1, 'LOGIN', 'User \'admin\' logged in (IP: ::1)', '::1', '2026-02-07 08:16:55'),
(28, 1, 'UPLOAD_DOC', 'Directly uploaded: sample.jpg', '::1', '2026-02-07 08:41:38'),
(29, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-424', '::1', '2026-02-07 08:45:32'),
(30, 1, 'UPLOAD_DOC', 'Directly uploaded: SAMPLE IMAGE.jpg', '::1', '2026-02-07 08:46:17'),
(31, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate.png', '::1', '2026-02-07 08:57:22'),
(32, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 08:59:09'),
(33, 1, 'UPLOAD_DOC', 'Directly uploaded: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 09:01:29'),
(34, 1, 'UPLOAD_DOC', 'Directly uploaded: Print naaaaa yung invitation.pdf', '::1', '2026-02-07 09:01:42'),
(35, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 09:03:52'),
(36, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-424', '::1', '2026-02-07 09:46:29'),
(37, 1, 'TRASH_DOC', 'Moved to Recycle Bin: NBI Clearance.jpg', '::1', '2026-02-07 09:46:53'),
(38, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for P-015', '::1', '2026-02-07 09:47:12'),
(39, 1, 'TRASH_DOC', 'Moved to Recycle Bin: kunwari  Clearance.png', '::1', '2026-02-07 09:47:24'),
(40, 1, 'TRASH_DOC', 'Moved to Recycle Bin: kunwari  Clearance.png', '::1', '2026-02-07 09:47:25'),
(41, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260207_061225', '::1', '2026-02-07 09:49:54'),
(42, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-02-07 10:11:24'),
(43, 1, 'UPLOAD_DOC', 'Directly uploaded: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 10:12:25'),
(44, 1, 'UPLOAD_DOC', 'Directly uploaded: memo.png', '::1', '2026-02-07 10:13:04'),
(45, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:13:27'),
(46, 1, 'UPLOAD_DOC', 'Directly uploaded: evaluation.pdf', '::1', '2026-02-07 10:14:28'),
(47, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:14:39'),
(48, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 10:15:32'),
(49, 1, 'UPLOAD_DOC', 'Directly uploaded: Sir benji sulsa.jpg', '::1', '2026-02-07 10:15:50'),
(50, 1, 'PERM_DELETE', 'Permanently deleted: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 10:16:17'),
(51, 1, 'PERM_DELETE', 'Permanently deleted: memo.png', '::1', '2026-02-07 10:16:21'),
(52, 1, 'PERM_DELETE', 'Permanently deleted: evaluation.pdf', '::1', '2026-02-07 10:16:24'),
(53, 1, 'PERM_DELETE', 'Permanently deleted: kunwari  Clearance.png', '::1', '2026-02-07 10:16:28'),
(54, 1, 'PERM_DELETE', 'Permanently deleted: sample.jpg', '::1', '2026-02-07 10:16:36'),
(55, 1, 'PERM_DELETE', 'Permanently deleted: Print naaaaa yung invitation.pdf', '::1', '2026-02-07 10:16:39'),
(56, 1, 'RESTORE_DOC', 'Restored file: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 10:16:42'),
(57, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate.png', '::1', '2026-02-07 10:16:46'),
(58, 1, 'PERM_DELETE', 'Permanently deleted: SAMPLE IMAGE.jpg', '::1', '2026-02-07 10:16:51'),
(59, 1, 'PERM_DELETE', 'Permanently deleted: NBI Clearance.jpg', '::1', '2026-02-07 10:16:55'),
(60, 1, 'PERM_DELETE', 'Permanently deleted: Contract.jpg', '::1', '2026-02-07 10:16:58'),
(61, 1, 'PERM_DELETE', 'Permanently deleted: lock 2 in.jpg', '::1', '2026-02-07 10:17:01'),
(62, 1, 'PERM_DELETE', 'Permanently deleted: thumb-1920-1287814.png', '::1', '2026-02-07 10:17:05'),
(63, 1, 'PERM_DELETE', 'Permanently deleted: Certificate.png', '::1', '2026-02-07 10:17:07'),
(64, 1, 'PERM_DELETE', 'Permanently deleted: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 10:17:10'),
(65, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate.png', '::1', '2026-02-07 10:17:13'),
(66, 1, 'TRASH_DOC', 'Moved to Recycle Bin: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 10:17:22'),
(67, 1, 'PERM_DELETE', 'Permanently deleted: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 10:17:27'),
(68, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:17:48'),
(69, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 10:18:10'),
(70, 1, 'UPLOAD_DOC', 'Directly uploaded: eyyyyy.pdf', '::1', '2026-02-07 10:21:25'),
(71, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:21:35'),
(72, 1, 'PERM_DELETE', 'Permanently deleted: Sir benji sulsa.jpg', '::1', '2026-02-07 10:22:04'),
(73, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:22:52'),
(74, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 10:43:38'),
(75, 1, 'UPLOAD_DOC', 'Directly uploaded: SCORE SHEET PRINT.pdf', '::1', '2026-02-07 10:44:09'),
(76, 1, 'UPLOAD_DOC', 'Directly uploaded: Print naaaaa yung invitation.pdf', '::1', '2026-02-07 10:49:55'),
(77, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:50:05'),
(78, 1, 'PERM_DELETE', 'Permanently deleted: eyyyyy.pdf', '::1', '2026-02-07 10:55:37'),
(79, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 10:55:48'),
(80, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 10:56:06'),
(81, 1, 'UPLOAD_DOC', 'Directly uploaded: qr-code.png', '::1', '2026-02-07 10:56:26'),
(82, 1, 'UPLOAD_DOC', 'Directly uploaded: Certificate (1).pdf', '::1', '2026-02-07 11:00:55'),
(83, 1, 'UPLOAD_DOC', 'Directly uploaded: Certificate (2).jpg', '::1', '2026-02-07 11:00:55'),
(84, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 11:02:50'),
(85, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 11:05:08'),
(86, 1, 'PERM_DELETE', 'Permanently deleted: qr-code.png', '::1', '2026-02-07 11:05:25'),
(87, 1, 'PERM_DELETE', 'Permanently deleted: Certificate (1).pdf', '::1', '2026-02-07 11:05:30'),
(88, 1, 'PERM_DELETE', 'Permanently deleted: Certificate (2).jpg', '::1', '2026-02-07 11:05:38'),
(89, 1, 'PERM_DELETE', 'Permanently deleted: SCORE SHEET PRINT.pdf', '::1', '2026-02-07 11:05:43'),
(90, 1, 'PERM_DELETE', 'Permanently deleted: Print naaaaa yung invitation.pdf', '::1', '2026-02-07 11:08:36'),
(91, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (1).png', '::1', '2026-02-07 11:09:26'),
(92, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (2).png', '::1', '2026-02-07 11:09:26'),
(93, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (3).jpg', '::1', '2026-02-07 11:09:26'),
(94, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (4).jpg', '::1', '2026-02-07 11:09:26'),
(95, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (5).jpg', '::1', '2026-02-07 11:09:26'),
(96, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (6).pdf', '::1', '2026-02-07 11:09:26'),
(97, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (7).jpg', '::1', '2026-02-07 11:09:26'),
(98, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (8).jpg', '::1', '2026-02-07 11:09:26'),
(99, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (9).jpg', '::1', '2026-02-07 11:09:26'),
(100, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (10).jpg', '::1', '2026-02-07 11:09:26'),
(101, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (11).jpg', '::1', '2026-02-07 11:09:26'),
(102, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (12).jpg', '::1', '2026-02-07 11:09:26'),
(103, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (13).jpg', '::1', '2026-02-07 11:09:26'),
(104, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (14).png', '::1', '2026-02-07 11:09:26'),
(105, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (15).png', '::1', '2026-02-07 11:09:26'),
(106, 1, 'UPLOAD_DOC', 'Directly uploaded: Medical Certificate (16).png', '::1', '2026-02-07 11:09:26'),
(107, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 11:09:37'),
(108, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 11:15:55'),
(109, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (1).png', '::1', '2026-02-07 11:16:03'),
(110, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (2).png', '::1', '2026-02-07 11:16:06'),
(111, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (3).jpg', '::1', '2026-02-07 11:16:11'),
(112, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (4).jpg', '::1', '2026-02-07 11:16:41'),
(113, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (5).jpg', '::1', '2026-02-07 11:16:45'),
(114, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (6).pdf', '::1', '2026-02-07 11:16:48'),
(115, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (7).jpg', '::1', '2026-02-07 11:17:01'),
(116, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (8).jpg', '::1', '2026-02-07 11:17:10'),
(117, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (9).jpg', '::1', '2026-02-07 11:17:15'),
(118, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (10).jpg', '::1', '2026-02-07 11:17:19'),
(119, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (11).jpg', '::1', '2026-02-07 11:17:23'),
(120, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (12).jpg', '::1', '2026-02-07 11:17:27'),
(121, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (13).jpg', '::1', '2026-02-07 11:17:31'),
(122, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (14).png', '::1', '2026-02-07 11:17:34'),
(123, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (15).png', '::1', '2026-02-07 11:17:36'),
(124, 1, 'PERM_DELETE', 'Permanently deleted: Medical Certificate (16).png', '::1', '2026-02-07 11:17:39'),
(125, 1, 'UPLOAD_DOC', 'Directly uploaded: image (4).png', '::1', '2026-02-07 11:23:43'),
(126, 1, 'UPLOAD_DOC', 'Directly uploaded: perfect adttendacne  Awardings for tesp xmas party (1).png', '::1', '2026-02-07 11:23:43'),
(127, 1, 'UPLOAD_DOC', 'Directly uploaded: Sir benji sulsa.jpg', '::1', '2026-02-07 11:23:43'),
(128, 1, 'UPLOAD_DOC', 'Directly uploaded: sig com .2.jpg', '::1', '2026-02-07 11:23:43'),
(129, 1, 'UPLOAD_DOC', 'Directly uploaded: sig com.jpg', '::1', '2026-02-07 11:23:43'),
(130, 1, 'UPLOAD_DOC', 'Directly uploaded: SCORE SHEET PRINT.pdf', '::1', '2026-02-07 11:23:43'),
(131, 1, 'UPLOAD_DOC', 'Directly uploaded: 7a3cf604-ddb6-42ac-8b2b-67e886e07db5.jpg', '::1', '2026-02-07 11:23:43'),
(132, 1, 'UPLOAD_DOC', 'Directly uploaded: 9af3c684-8653-40db-9dac-edb331cbe547.jpg', '::1', '2026-02-07 11:23:43'),
(133, 1, 'UPLOAD_DOC', 'Directly uploaded: 47578b7d-5734-47d7-b19c-d3e0c52fb24b.jpg', '::1', '2026-02-07 11:23:43'),
(134, 1, 'UPLOAD_DOC', 'Directly uploaded: 568da3c4-8cf0-4305-b3a3-a69eae6493cc.jpg', '::1', '2026-02-07 11:23:43'),
(135, 1, 'UPLOAD_DOC', 'Directly uploaded: forms 3.jpg', '::1', '2026-02-07 11:23:43'),
(136, 1, 'UPLOAD_DOC', 'Directly uploaded: forms 2.jpg', '::1', '2026-02-07 11:23:43'),
(137, 1, 'UPLOAD_DOC', 'Directly uploaded: forms.jpg', '::1', '2026-02-07 11:23:43'),
(138, 1, 'UPLOAD_DOC', 'Directly uploaded: qr-code.png', '::1', '2026-02-07 11:23:43'),
(139, 1, 'UPLOAD_DOC', 'Directly uploaded: talent logo - Edited.png', '::1', '2026-02-07 11:23:43'),
(140, 1, 'UPLOAD_DOC', 'Directly uploaded: qr-code for juding.png', '::1', '2026-02-07 11:23:43'),
(141, 1, 'UPLOAD_DOC', 'Directly uploaded: Print naaaaa yung invitation.pdf', '::1', '2026-02-07 11:23:43'),
(142, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 11:27:33'),
(143, 1, 'UPLOAD_DOC', 'Directly uploaded: sig com.jpg', '::1', '2026-02-07 11:42:50'),
(144, 1, 'UPLOAD_DOC', 'Directly uploaded: qr-code.png', '::1', '2026-02-07 11:42:50'),
(145, 1, 'UPLOAD_DOC', 'Directly uploaded: Host other image.jpg', '::1', '2026-02-07 11:42:50'),
(146, 1, 'EXPORT_ZIP', 'Exported 1 folders (Bulk/Single Download).', '::1', '2026-02-07 11:42:57'),
(147, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-12-45.zip', '::1', '2026-02-07 12:12:45'),
(148, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-12-50.zip', '::1', '2026-02-07 12:12:50'),
(149, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-13-14.zip', '::1', '2026-02-07 12:13:14'),
(150, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-18-02.zip', '::1', '2026-02-07 12:18:02'),
(151, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-19-01.zip', '::1', '2026-02-07 12:19:01'),
(152, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-22-47.zip', '::1', '2026-02-07 12:22:47'),
(153, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-23-14.zip', '::1', '2026-02-07 12:23:14'),
(154, 1, 'SETTINGS_UPDATE', 'Changed \'Backup Day\' to Sat', '::1', '2026-02-07 12:31:46'),
(155, 1, 'SETTINGS_UPDATE', 'Changed \'Backup Time\' to 20:32', '::1', '2026-02-07 12:31:46'),
(156, 1, 'AUTO_BACKUP_CLI', 'Created backup: AutoBackup_2026-02-07_13-33-25.zip', '::1', '2026-02-07 12:33:25'),
(157, 1, 'SYSTEM_BACKUP', 'Admin downloaded full database backup.', '::1', '2026-02-07 12:54:00'),
(158, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-415', '::1', '2026-02-07 13:25:27'),
(159, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for PE-426', '::1', '2026-02-07 13:25:37'),
(160, 1, 'EMPTY_BIN', 'Emptied Recycle Bin (20 files)', '::1', '2026-02-07 13:28:01'),
(161, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260207_111123', '::1', '2026-02-07 13:28:21'),
(162, 1, 'IMPORT_SUCCESS', 'Imported 2 (TESP)', '::1', '2026-02-09 01:29:46'),
(163, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 01:30:06'),
(164, 1, 'EDIT_PROFILE', 'Updated 2026-002', '::1', '2026-02-09 01:30:42'),
(165, 1, 'UPLOAD_DOC', 'Directly uploaded: RESUME.jpg', '::1', '2026-02-09 01:58:54'),
(166, 1, 'UPLOAD_DOC', 'Directly uploaded: resume.jpg', '::1', '2026-02-09 02:00:02'),
(167, 1, 'UPLOAD_DOC', 'Directly uploaded: resume_2.jpg', '::1', '2026-02-09 02:00:48'),
(168, 1, 'EDIT_DOC', 'Updated Doc ID 62 (resume_2.jpg)', '::1', '2026-02-09 02:06:26'),
(169, 1, 'EDIT_DOC', 'Updated Doc ID 60 (RESUME.jpg)', '::1', '2026-02-09 02:06:41'),
(170, 1, 'UPLOAD_DOC', 'Directly uploaded: Marriage Contract.png', '::1', '2026-02-09 02:13:55'),
(171, 1, 'EDIT_DOC', 'Updated Doc ID 63 (Marriage Contract.png)', '::1', '2026-02-09 02:15:36'),
(172, 1, 'UPLOAD_DOC', 'Directly uploaded: sample.jpg', '::1', '2026-02-09 02:22:44'),
(173, 1, 'EDIT_DOC', 'Updated Doc ID 64 (sample.jpg)', '::1', '2026-02-09 02:24:10'),
(174, 1, 'EDIT_DOC', 'Updated Doc ID 64 (sample.jpg)', '::1', '2026-02-09 02:36:46'),
(175, 1, 'TRASH_DOC', 'Moved to Recycle Bin: sample.jpg', '::1', '2026-02-09 02:38:00'),
(176, 1, 'UPLOAD_DOC', 'Directly uploaded: image (4).png', '::1', '2026-02-09 02:38:41'),
(177, 1, 'TRASH_DOC', 'Moved to Recycle Bin: image (4).png', '::1', '2026-02-09 02:46:41'),
(178, 1, 'PERM_DELETE', 'Permanently deleted: image (4).png', '::1', '2026-02-09 02:46:46'),
(179, 1, 'PERM_DELETE', 'Permanently deleted: sample.jpg', '::1', '2026-02-09 02:46:49'),
(180, 1, 'EDIT_DOC', 'Updated Doc ID 63 (Marriage Contract.png)', '::1', '2026-02-09 03:03:34'),
(181, 1, 'EDIT_DOC', 'Updated Doc ID 62 (resume_2.jpg)', '::1', '2026-02-09 03:15:40'),
(182, 1, 'EDIT_DOC', 'Updated Doc ID 63 (Marriage Contract.png)', '::1', '2026-02-09 03:16:00'),
(183, 1, 'EDIT_DOC', 'Updated Doc ID 63 (Marriage Contract.png)', '::1', '2026-02-09 03:19:07'),
(184, 1, 'EDIT_DOC', 'Updated Doc ID 63 (Contract.png)', '::1', '2026-02-09 03:41:37'),
(185, 1, 'EDIT_DOC', 'Updated Doc ID 63 (Contract.png)', '::1', '2026-02-09 03:50:06'),
(186, 1, 'EDIT_DOC', 'Updated Doc ID 61 (resume.jpg)', '::1', '2026-02-09 05:44:36'),
(187, 1, 'GENERATE_DOC', 'Generated project for Maria Santos (2026-002)', '::1', '2026-02-09 06:55:20'),
(188, 1, 'GENERATE_DOC', 'Generated project for Maria Santos (2026-002)', '::1', '2026-02-09 06:55:30'),
(189, 1, 'GENERATE_DOC', 'Generated project for Maria Santos (2026-002)', '::1', '2026-02-09 06:56:03'),
(190, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260209_022946', '::1', '2026-02-09 08:00:41'),
(191, 1, 'IMPORT_SUCCESS', 'Imported 2 (TESP)', '::1', '2026-02-09 08:01:05'),
(192, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 08:09:28'),
(193, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 08:09:41'),
(194, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for 2026-001', '::1', '2026-02-09 09:29:06'),
(195, 1, 'DELETE_ALL_DOCS', 'Deleted all documents for 2026-002', '::1', '2026-02-09 09:29:13'),
(196, 1, 'EMPTY_BIN', 'Emptied Recycle Bin (4 files)', '::1', '2026-02-09 09:29:20'),
(197, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:33:17'),
(198, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:35:26'),
(199, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:35:53'),
(200, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:36:06'),
(201, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:36:22'),
(202, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:36:46'),
(203, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:37:06'),
(204, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:37:18'),
(205, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:37:28'),
(206, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:38:04'),
(207, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:38:37'),
(208, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:39:04'),
(209, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:39:17'),
(210, 1, 'GENERATE_DOC', 'Generated probationary for . Santos Maria (2026-002)', '::1', '2026-02-09 09:44:49'),
(211, 1, 'BULK_PRINT', 'Generated project contracts for 2 employees.', '::1', '2026-02-09 09:45:24'),
(212, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:45:56'),
(213, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:46:42'),
(214, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:46:58'),
(215, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:47:08'),
(216, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:47:53'),
(217, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:48:58'),
(218, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:49:14'),
(219, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:51:34'),
(220, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:52:34'),
(221, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:53:18'),
(222, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:54:53'),
(223, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:55:19'),
(224, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:55:42'),
(225, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:56:28'),
(226, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:56:55'),
(227, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:58:27'),
(228, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:59:42'),
(229, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 09:59:56'),
(230, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:00:11'),
(231, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:01:51'),
(232, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:02:23'),
(233, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:02:50'),
(234, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:03:13'),
(235, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:05:01'),
(236, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:05:51'),
(237, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:06:40'),
(238, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:06:53'),
(239, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:10:29'),
(240, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:10:50'),
(241, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:11:04'),
(242, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:11:24'),
(243, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:11:38'),
(244, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:11:53'),
(245, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:12:12'),
(246, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:12:37'),
(247, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:12:51'),
(248, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:13:14'),
(249, 1, 'BULK_PRINT', 'Generated probationary contracts for 2 employees.', '::1', '2026-02-09 10:13:40');

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs_archive`
--

CREATE TABLE `activity_logs_archive` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agencies`
--

CREATE TABLE `agencies` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agencies`
--

INSERT INTO `agencies` (`id`, `name`) VALUES
(2, 'GUNJIN'),
(3, 'JORATECH'),
(5, 'OTHERS - SUBCONS'),
(1, 'TESP DIRECT'),
(6, 'TESTING'),
(4, 'UNLISOLUTIONS');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`) VALUES
(2, 'ADMIN'),
(14, 'BFS'),
(13, 'CTS'),
(12, 'DOS'),
(8, 'HMS'),
(11, 'LMS'),
(7, 'MHI'),
(6, 'OCS'),
(3, 'OP'),
(5, 'PSS'),
(9, 'RAS'),
(4, 'SIGCOM'),
(1, 'SQP'),
(17, 'SUBCONS-OTHERS'),
(10, 'TRS'),
(15, 'WHS');

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
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
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
(5, 'Clearance', 'NBI, Police, Barangay', '2026-01-28 09:57:33'),
(6, 'Tor / Diploma', 'diploma,Diploma, Certificate of Completement', '2026-01-31 05:51:38'),
(7, 'Drug Test', 'Drug Test, Methamphetamine, THC', '2026-01-31 06:18:58'),
(8, 'NBI Clearance', 'NBI', '2026-01-31 06:18:58');

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
  `system_role` varchar(50) DEFAULT 'Staff',
  `dept` varchar(50) DEFAULT NULL,
  `section` varchar(255) DEFAULT NULL,
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
  `resignation_label` varchar(50) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT 'default.png',
  `import_batch` varchar(50) DEFAULT NULL,
  `last_reminded` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `emp_id`, `first_name`, `middle_name`, `last_name`, `job_title`, `system_role`, `dept`, `section`, `employment_type`, `agency_name`, `company_name`, `previous_company`, `hire_date`, `gender`, `birth_date`, `contact_number`, `email`, `present_address`, `permanent_address`, `sss_no`, `tin_no`, `pagibig_no`, `philhealth_no`, `emergency_name`, `emergency_contact`, `emergency_address`, `education`, `experience`, `skills`, `licenses`, `status`, `exit_date`, `exit_reason`, `avatar_path`, `import_batch`, `last_reminded`, `created_at`, `updated_at`, `deleted_at`) VALUES
(2368, '2026-001', 'Juan', NULL, 'Dela Cruz', 'Staff', 'Staff', 'SQP', 'IT', 'TESP Direct', 'TESP', 'TES Philippines', NULL, '2023-01-10', 'Male', '1990-05-15', '0917-123-4567', '', 'To be updated', NULL, '00-0000000-0', '000-000-000', '0000-0000-0000', '00-000000000-0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', NULL, NULL, 'default.png', 'BATCH_20260209_090105', NULL, '2026-02-09 08:01:05', NULL, NULL),
(2369, '2026-002', '.', NULL, 'Santos Maria', 'Staff', 'Staff', 'HMS', 'HEAVY MAINTENANCE', 'TESP Direct', 'TESP', 'TES Philippines', NULL, '2024-02-15', 'Male', '1995-12-01', '0918-123-4567', '', 'To be updated', NULL, '11-1111111-1', '111-111-111', '1111-1111-1111', '11-111111111-1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Active', NULL, NULL, 'default.png', 'BATCH_20260209_090105', NULL, '2026-02-09 08:01:05', NULL, NULL);

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

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(5, 1, 'Manual Backup', 'Manual backup created: AutoBackup_2026-02-07_13-22-47.zip', 'success', 0, '2026-02-07 12:22:47'),
(6, 1, 'Manual Backup', 'Manual backup created: AutoBackup_2026-02-07_13-23-14.zip', 'success', 0, '2026-02-07 12:23:14'),
(7, 1, 'Manual Backup', 'Manual backup created: AutoBackup_2026-02-07_13-33-25.zip', 'success', 0, '2026-02-07 12:33:25');

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

--
-- Dumping data for table `performance_evaluations`
--

INSERT INTO `performance_evaluations` (`id`, `employee_id`, `eval_date`, `score`, `rating`, `remarks`, `evaluator`, `created_at`) VALUES
(11, 8173, '2026-01-31', 78, 'Satisfactory', 'OAIHXOshDOXILjsiDXJLSAD PADOIAHSKHCLKZXHCLKJZXLKJCLKZXJCLKZXHKJCHLZKXHCLKJZXHCLKHZ', 'admin', '2026-01-31 11:09:23');

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
('::1', 1, '2026-02-07 01:16:55');

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
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `department_id`, `name`) VALUES
(1, 1, 'GENERAL'),
(5, 1, 'IT'),
(4, 1, 'PLANNING'),
(3, 1, 'QA'),
(2, 1, 'SAFETY'),
(10, 2, 'ACG'),
(12, 2, 'CLEANERS'),
(7, 2, 'GAG'),
(6, 2, 'GENERAL'),
(11, 2, 'MED'),
(9, 2, 'PCG'),
(8, 2, 'TKG'),
(13, 3, 'OFFICE OF THE PRESIDENT'),
(14, 4, 'SIGNALING & COMMUNICATION'),
(15, 5, 'POWER SUPPLY SECTION'),
(16, 6, 'OVERHEAD CATENARY SYSTEM'),
(17, 7, 'MITSUBISHI HEAVY INDUSTRIES'),
(18, 8, 'HEAVY MAINTENANCE SECTION'),
(19, 9, 'ROOT CAUSE ANALYSIS'),
(20, 10, 'TECHNICAL RESEARCH SECTION'),
(21, 11, 'LIGHT MAINTENANCE SECTION'),
(23, 12, 'CCRE'),
(25, 12, 'DOS_OFF'),
(22, 12, 'GENERAL'),
(26, 12, 'GEN_SUP'),
(24, 12, 'SHUNTER'),
(27, 13, 'CIVIL TRACKS SECTION'),
(30, 14, 'CONVEY'),
(29, 14, 'DEPOT_EQ'),
(28, 14, 'GENERAL'),
(31, 14, 'MOTOR'),
(32, 15, 'WAREHOUSE SECTION'),
(35, 17, 'OTHERS');

-- --------------------------------------------------------

--
-- Table structure for table `system_roles`
--

CREATE TABLE `system_roles` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `duties` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_roles`
--

INSERT INTO `system_roles` (`id`, `name`, `duties`) VALUES
(1, 'Manager', NULL),
(2, 'Head', NULL),
(3, 'Advisor', NULL),
(4, 'Engineer', NULL),
(5, 'Technician', NULL),
(6, 'Officer', NULL),
(7, 'IT', NULL),
(8, 'Driver', NULL),
(9, 'Staff', NULL),
(10, 'Maintenance', NULL);

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
('auto_refresh_interval', '60'),
('backup_day', 'Sat'),
('backup_include_vault', '0'),
('backup_password', ''),
('backup_path', ''),
('backup_time', '20:32'),
('bulk_margin_left', '50'),
('bulk_margin_right', '0'),
('default_notice_place', ''),
('default_project_name', 'trial'),
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_2fa_enabled` tinyint(1) DEFAULT 0,
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT current_timestamp(),
  `trusted_device_token` varchar(64) DEFAULT NULL,
  `trusted_device_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `reset_token`, `reset_expires`, `last_otp_sent`, `created_at`, `is_2fa_enabled`, `otp_code`, `otp_expires`, `password_changed_at`, `trusted_device_token`, `trusted_device_expires`) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$rAjQx/6YQvi68HLn/pCqeONdasAERCWD0gTUI7Hxy2B8tlHcOHa.e', 'ADMIN', NULL, NULL, NULL, '2026-01-28 09:57:33', 0, NULL, NULL, '2026-01-28 09:57:33', NULL, NULL),
(2, 'manager', 'manager@example.com', '$2y$10$uUBSNVgiojGhFx4qSeD.lOaXbZMiSh5adcT4EoooN5mqPUvRqOy46', 'MANAGER', NULL, NULL, NULL, '2026-01-28 09:57:34', 0, NULL, NULL, '2026-01-28 09:57:34', NULL, NULL),
(3, 'hr', 'hr@example.com', '$2y$10$p1OgslhRy7P9pE16DzK//.k29A842hJNfAi2Qs0AHazUR2fGUdwae', 'HR', NULL, NULL, NULL, '2026-01-28 09:57:34', 0, NULL, NULL, '2026-01-28 09:57:34', NULL, NULL),
(4, 'staff', 'staff@example.com', '$2y$10$QXizGOtDSBAHhhI82BkVvOeUMyFNEPV3Gc3fpzaio8sMjXJH5/JjW', 'STAFF', NULL, NULL, NULL, '2026-01-28 09:57:34', 0, NULL, NULL, '2026-01-28 09:57:34', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `activity_logs_archive`
--
ALTER TABLE `activity_logs_archive`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `agencies`
--
ALTER TABLE `agencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

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
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_section` (`department_id`,`name`);

--
-- Indexes for table `system_roles`
--
ALTER TABLE `system_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=250;

--
-- AUTO_INCREMENT for table `activity_logs_archive`
--
ALTER TABLE `activity_logs_archive`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `agencies`
--
ALTER TABLE `agencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `disciplinary_cases`
--
ALTER TABLE `disciplinary_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `document_exemptions`
--
ALTER TABLE `document_exemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `document_requirements`
--
ALTER TABLE `document_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2370;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `system_roles`
--
ALTER TABLE `system_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_employees` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`emp_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
