-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 27, 2026 at 01:06 PM
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
(1, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-27 01:22:02'),
(2, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 01:22:27'),
(3, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 01:22:57'),
(4, 1, 'EDIT_PROFILE', 'Updated PE-424', '::1', '2026-01-27 01:23:34'),
(5, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 01:23:46'),
(6, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 01:24:00'),
(7, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 01:25:09'),
(8, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_022201', '::1', '2026-01-27 01:25:36'),
(9, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-27 02:00:04'),
(10, 1, 'GENERATE_DOC', 'Generated confidentiality for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 02:00:48'),
(11, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_030004', '::1', '2026-01-27 02:23:49'),
(12, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_030004', '::1', '2026-01-27 02:26:30'),
(13, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_030004', '::1', '2026-01-27 02:26:34'),
(14, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-27 02:57:33'),
(15, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_035733', '::1', '2026-01-27 03:02:28'),
(16, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-27 03:02:39'),
(17, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_022201', '::1', '2026-01-27 05:03:27'),
(18, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_040238', '::1', '2026-01-27 06:07:28'),
(19, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-27 06:07:39'),
(20, 1, 'GENERATE_DOC', 'Generated confidentiality for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 06:08:05'),
(21, 1, 'GENERATE_DOC', 'Generated memo_general for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 06:15:19'),
(22, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 06:15:25'),
(23, 1, 'GENERATE_DOC', 'Generated tool_clearance for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 06:15:31'),
(24, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:16:21'),
(25, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:18:59'),
(26, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:19:27'),
(27, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:19:41'),
(28, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:19:42'),
(29, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:20:06'),
(30, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:20:18'),
(31, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:20:44'),
(32, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:20:54'),
(33, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:21:03'),
(34, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:21:04'),
(35, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:21:19'),
(36, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:21:30'),
(37, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:21:31'),
(38, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:21:40'),
(39, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:23:20'),
(40, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:23:21'),
(41, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:23:46'),
(42, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:26:21'),
(43, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:26:21'),
(44, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:27:09'),
(45, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:28:23'),
(46, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:28:27'),
(47, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:28:32'),
(48, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:29:16'),
(49, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:29:46'),
(50, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:29:47'),
(51, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:29:48'),
(52, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:29:48'),
(53, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:30:09'),
(54, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:31:48'),
(55, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:31:48'),
(56, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:31:49'),
(57, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:31:51'),
(58, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:32:40'),
(59, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:33:51'),
(60, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:33:51'),
(61, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:33:51'),
(62, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:44:02'),
(63, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:45:06'),
(64, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:45:36'),
(65, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:46:03'),
(66, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:47:22'),
(67, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:50:41'),
(68, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:51:25'),
(69, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:51:40'),
(70, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:51:56'),
(71, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:52:12'),
(72, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:52:23'),
(73, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:52:49'),
(74, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:53:53'),
(75, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:54:33'),
(76, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:54:48'),
(77, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:54:59'),
(78, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 06:59:59'),
(79, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:00:23'),
(80, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:00:51'),
(81, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:01:09'),
(82, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:01:58'),
(83, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:02:29'),
(84, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:02:42'),
(85, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:03:31'),
(86, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:03:52'),
(87, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:04:12'),
(88, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:04:31'),
(89, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:04:45'),
(90, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:05:00'),
(91, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:06:17'),
(92, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:06:30'),
(93, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:11:51'),
(94, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:12:24'),
(95, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:13:54'),
(96, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:14:19'),
(97, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:14:20'),
(98, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:14:21'),
(99, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:14:34'),
(100, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:15:02'),
(101, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:15:34'),
(102, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:15:45'),
(103, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:16:28'),
(104, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:17:02'),
(105, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:17:26'),
(106, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:23:18'),
(107, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:23:21'),
(108, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:24:18'),
(109, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:27:29'),
(110, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:27:43'),
(111, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:28:41'),
(112, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:29:07'),
(113, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:29:50'),
(114, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:30:08'),
(115, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:30:29'),
(116, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:30:55'),
(117, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:31:54'),
(118, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:32:47'),
(119, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:33:00'),
(120, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:33:32'),
(121, 1, 'GENERATE_DOC', 'Generated confidentiality for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:35:51'),
(122, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:37:11'),
(123, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:38:00'),
(124, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:38:21'),
(125, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:39:47'),
(126, 1, 'GENERATE_DOC', 'Generated confidentiality for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:40:14'),
(127, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:43:05'),
(128, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:43:39'),
(129, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:44:09'),
(130, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:44:10'),
(131, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:44:20'),
(132, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:44:26'),
(133, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:45:00'),
(134, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:45:09'),
(135, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 07:45:54'),
(136, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 07:46:22'),
(137, 1, 'GENERATE_DOC', 'Generated probationary_lms for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 07:46:47'),
(138, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:47:13'),
(139, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:49:15'),
(140, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:51:12'),
(141, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:51:23'),
(142, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:51:47'),
(143, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:51:59'),
(144, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:52:21'),
(145, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:52:40'),
(146, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:56:05'),
(147, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:56:06'),
(148, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 07:57:17'),
(149, 1, 'GENERATE_DOC', 'Generated confidentiality for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 08:09:56'),
(150, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 08:10:43'),
(151, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 08:13:56'),
(152, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 08:14:20'),
(153, 1, 'GENERATE_DOC', 'Generated project for Samantha Kylie Maranan Anzures (PE-424)', '::1', '2026-01-27 08:23:13'),
(154, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_070739', '::1', '2026-01-27 08:48:34'),
(155, 1, 'IMPORT_SUCCESS', 'Imported 394 (TESP)', '::1', '2026-01-27 08:48:50'),
(156, 1, 'GENERATE_DOC', 'Generated project for Delfin Dineros Jr. Sicad (PE-415)', '::1', '2026-01-27 08:58:42'),
(157, 1, 'GENERATE_DOC', 'Generated project for Delfin Dineros Jr. Sicad (PE-415)', '::1', '2026-01-27 08:59:23'),
(158, 1, 'GENERATE_DOC', 'Generated probationary_lms for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:03:17'),
(159, 1, 'GENERATE_DOC', 'Generated probationary_lms for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:03:23'),
(160, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:03:43'),
(161, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:03:59'),
(162, 1, 'GENERATE_DOC', 'Generated project for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:04:40'),
(163, 1, 'GENERATE_DOC', 'Generated probationary_lms for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:05:21'),
(164, 1, 'GENERATE_DOC', 'Generated probationary_lms for Christian S. Arcenal (PE-426)', '::1', '2026-01-27 10:05:39'),
(165, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_094848', '::1', '2026-01-27 12:01:09'),
(166, 1, 'IMPORT_UNDO', 'Undid batch BATCH_20260127_094848', '::1', '2026-01-27 12:01:12');

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
  `resolution_note` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_requirements`
--

CREATE TABLE `document_requirements` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `keywords` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_requirements`
--

INSERT INTO `document_requirements` (`id`, `name`, `keywords`, `created_at`) VALUES
(6, 'Tor / Diploma', 'diploma,DIPLOMA,DIP,TOR,tor,diploma', '2026-01-27 11:43:22'),
(7, 'Certificate of Employment', 'coe,COE,,Certificate of Employment', '2026-01-27 11:43:51'),
(8, 'Birth Certificate', 'Birth Certificate,BIRTH, BIRTHCERTIFICATE', '2026-01-27 11:45:18'),
(9, 'NBI/POLICE CLEANRANCE', 'NBI,POLICE, CLERANCE, NBI CLERANCE, POLICE CLEARANCE', '2026-01-27 11:46:51');

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
  `import_batch` varchar(50) DEFAULT NULL,
  `education` text DEFAULT NULL,
  `experience` text DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `licenses` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `performance_evaluations`
--

CREATE TABLE `performance_evaluations` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `eval_date` date NOT NULL,
  `score` int(11) NOT NULL,
  `rating` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `evaluator` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
('::1', 74, '2026-01-27 12:01:23');

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
(1, 'admin', '$2y$10$inHNZtMdWM5z0/fsf2j02eH/K8BJygx.sD1TO6smGXcwIO/.qr0KK', 'ADMIN', 'romyrabes64@gmail.com', '196745', '2026-01-26 11:42:59', '2026-01-15 09:58:12'),
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
-- Indexes for table `document_requirements`
--
ALTER TABLE `document_requirements`
  ADD PRIMARY KEY (`id`);

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
-- Indexes for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=167;

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
-- AUTO_INCREMENT for table `document_requirements`
--
ALTER TABLE `document_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5911;

--
-- AUTO_INCREMENT for table `employee_history`
--
ALTER TABLE `employee_history`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- Constraints for table `performance_evaluations`
--
ALTER TABLE `performance_evaluations`
  ADD CONSTRAINT `performance_evaluations_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
