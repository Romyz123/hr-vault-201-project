-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 19, 2026 at 01:02 PM
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
(3, 'temp-003', '9283fc4d-f206-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', 'Contract', 'temp-003_Contract_1768477049.pdf', NULL, 'tfuyfjjh', '2026-01-15 11:37:29', 1, 0, NULL),
(4, 'temp-003', '95f9c134-f352-11f0-8494-b4e9b890daba', 'image (4).png', '', 'temp-003_Certificate_1768619650.png', NULL, '', '2026-01-17 03:14:10', 1, 0, NULL),
(5, 'EMP-002', '724b8ccb-f354-11f0-8494-b4e9b890daba', 'perfect adttendacne  Awardings for tesp xmas party (1).png', '', 'EMP-002_201Files_1768620449.png', NULL, '', '2026-01-17 03:27:29', 1, 0, NULL),
(6, 'eheheh-001', '44244fd4-f358-11f0-8494-b4e9b890daba', 'host 2.jpg', '', 'eheheh-001_201Files_1768622089.jpg', NULL, '', '2026-01-17 03:54:49', 1, 0, NULL),
(7, 'EMP-002', 'b460aad2-f358-11f0-8494-b4e9b890daba', 'Christmas-Party-2022-Programme_FInal.pdf', '', 'EMP-002_201Files_1768622278.pdf', NULL, 'xmass partyyy', '2026-01-17 03:57:58', 1, 0, NULL),
(8, 'eheheh-001', '3d0b416e-f35d-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 04:30:25', 2, 0, NULL),
(9, 'EMP-001', '8a2c9c06-f37e-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', 'Contract', 'EMP-001_Contract_1768568260.pdf', NULL, '', '2026-01-17 08:28:48', 2, 0, NULL),
(10, 'eyy', 'f5c622e4-f387-11f0-8494-b4e9b890daba', 'xmas party program flow (2).pdf', '', 'sample.pdf', NULL, 'memo yern', '2026-01-17 09:36:14', 2, 0, NULL),
(11, ';ojo', '4214e628-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768641968.pdf', '2026-01-23', '', '2026-01-17 09:38:22', 2, 1, 'sumbiteed'),
(12, ';ojo', '53a0b5e0-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768642731.pdf', NULL, '', '2026-01-17 09:38:51', 1, 0, NULL),
(13, ';ojo', '73eab3c7-f390-11f0-8494-b4e9b890daba', 'sample.png', '', 'eyyy.png', NULL, 'ahshahhahaah', '2026-01-17 10:37:02', 1, 0, NULL),
(14, 'eheheh-001', '9086f46b-f390-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 10:37:50', 2, 0, NULL),
(15, ';ojo', 'c3e076b3-f390-11f0-8494-b4e9b890daba', 'sample.png', '', 'hindioriniganlname.png', '2026-01-18', 'h', '2026-01-17 10:39:16', 1, 1, 'done na sya'),
(16, '999-01', 'b45e2fb6-f39c-11f0-8494-b4e9b890daba', 'hindidapatsampleyungname.png', '', 'hindidapatsampleyungname.png', NULL, 'hindi dapat', '2026-01-17 12:04:44', 1, 0, NULL),
(17, 'tesing', 'f5dc1982-f39c-11f0-8494-b4e9b890daba', 'hindidapotlockinyungname.jpg', 'Contract', 'hindidapotlockinyungname.jpg', '2026-01-29', 'Awdawdadw', '2026-01-17 12:06:34', 2, 1, 'okay na'),
(18, 'EMP-001', 'f1aa2929-f39d-11f0-8494-b4e9b890daba', 'newcontract.jpg', 'Contract', 'newcontract.jpg', NULL, '', '2026-01-17 12:13:36', 1, 0, NULL),
(20, 'tesing', '692347b7-f50a-11f0-8cf7-b4e9b890daba', 'h.pdf', 'Late', 'h.pdf', NULL, 'sample po', '2026-01-19 07:42:31', 1, 0, NULL),
(22, '999-01', '6496c836-f51b-11f0-8cf7-b4e9b890daba', '999-01_201Files_1768655970.jpg', '', '999-01_201Files_1768655970.jpg', NULL, '', '2026-01-19 09:44:05', 2, 0, NULL),
(23, 'tesing', '5913367a-f51f-11f0-8cf7-b4e9b890daba', 'eyy.pdf', '', 'eyy.pdf', NULL, '', '2026-01-19 10:12:23', 1, 0, NULL),
(24, '999-01', 'b72e4592-f520-11f0-8cf7-b4e9b890daba', '999-01_Contract_1768818079.pdf', 'Contract', '999-01_Contract_1768818079.pdf', NULL, '', '2026-01-19 10:22:11', 2, 0, NULL),
(25, 'nhtdhgdhdq', 'c973a9e3-f520-11f0-8cf7-b4e9b890daba', 'nhtdhgdhdq_Medical_1768818161.pdf', '', 'nhtdhgdhdq_Medical_1768818161.pdf', NULL, '', '2026-01-19 10:22:41', 1, 0, NULL),
(26, 'pjqwpdjqwpajq', '53ff1a40-f523-11f0-8cf7-b4e9b890daba', 'pjqwpdjqwpajq_VaccineCard_1768819253.png', '', 'pjqwpdjqwpajq_VaccineCard_1768819253.png', NULL, 'sample submition aaedadawaawdadaw', '2026-01-19 10:40:53', 1, 0, NULL),
(27, 'axas', '5593436a-f524-11f0-8cf7-b4e9b890daba', 'adawdwadad.png', '', 'adawdwadad.png', '2026-01-20', 'adawdadawd', '2026-01-19 10:48:05', 1, 0, NULL),
(28, 'awqw', '16978646-f527-11f0-8cf7-b4e9b890daba', 'awqw_Eyyy_1768820868.jpg', '', 'awqw_Eyyy_1768820868.jpg', NULL, '', '2026-01-19 11:07:48', 1, 0, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `file_uuid` (`file_uuid`),
  ADD KEY `fk_documents_employee` (`employee_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`emp_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
