-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 17, 2026 at 02:59 PM
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
(38, 2, 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 13:23:05');

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
(2, 'EMP-001', '847fbef4-f200-11f0-8494-b4e9b890daba', 'SCORE SHEET PRINT.pdf', '', 'EMP-001_Others_1768474449.pdf', '2026-01-15', 'trial eme', '2026-01-15 10:54:09', 1, 1, 'the file was done'),
(3, 'temp-003', '9283fc4d-f206-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', 'Contract', 'temp-003_Contract_1768477049.pdf', NULL, 'tfuyfjjh', '2026-01-15 11:37:29', 1, 0, NULL),
(4, 'temp-003', '95f9c134-f352-11f0-8494-b4e9b890daba', 'image (4).png', '', 'temp-003_Certificate_1768619650.png', NULL, '', '2026-01-17 03:14:10', 1, 0, NULL),
(5, 'EMP-002', '724b8ccb-f354-11f0-8494-b4e9b890daba', 'perfect adttendacne  Awardings for tesp xmas party (1).png', '', 'EMP-002_201Files_1768620449.png', NULL, '', '2026-01-17 03:27:29', 1, 0, NULL),
(6, 'eheheh-001', '44244fd4-f358-11f0-8494-b4e9b890daba', 'host 2.jpg', '', 'eheheh-001_201Files_1768622089.jpg', NULL, '', '2026-01-17 03:54:49', 1, 0, NULL),
(7, 'EMP-002', 'b460aad2-f358-11f0-8494-b4e9b890daba', 'Christmas-Party-2022-Programme_FInal.pdf', '', 'EMP-002_201Files_1768622278.pdf', NULL, 'xmass partyyy', '2026-01-17 03:57:58', 1, 0, NULL),
(8, 'eheheh-001', '3d0b416e-f35d-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 04:30:25', 2, 0, NULL),
(9, 'EMP-001', '8a2c9c06-f37e-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', 'Contract', 'EMP-001_Contract_1768568260.pdf', NULL, '', '2026-01-17 08:28:48', 2, 0, NULL),
(10, 'eyy', 'f5c622e4-f387-11f0-8494-b4e9b890daba', 'xmas party program flow (2).pdf', '', 'sample.pdf', NULL, 'memo yern', '2026-01-17 09:36:14', 2, 0, NULL),
(11, ';ojo', '4214e628-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768641968.pdf', '2026-01-23', '', '2026-01-17 09:38:22', 2, 0, NULL),
(12, ';ojo', '53a0b5e0-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768642731.pdf', NULL, '', '2026-01-17 09:38:51', 1, 0, NULL),
(13, ';ojo', '73eab3c7-f390-11f0-8494-b4e9b890daba', 'sample.png', '', 'eyyy.png', NULL, 'ahshahhahaah', '2026-01-17 10:37:02', 1, 0, NULL),
(14, 'eheheh-001', '9086f46b-f390-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 10:37:50', 2, 0, NULL),
(15, ';ojo', 'c3e076b3-f390-11f0-8494-b4e9b890daba', 'sample.png', '', 'hindioriniganlname.png', '2026-01-18', 'h', '2026-01-17 10:39:16', 1, 1, 'reporting'),
(16, '999-01', 'b45e2fb6-f39c-11f0-8494-b4e9b890daba', 'hindidapatsampleyungname.png', '', 'hindidapatsampleyungname.png', NULL, 'hindi dapat', '2026-01-17 12:04:44', 1, 0, NULL),
(17, 'tesing', 'f5dc1982-f39c-11f0-8494-b4e9b890daba', 'hindidapotlockinyungname.jpg', '', 'hindidapotlockinyungname.jpg', '2026-01-29', 'Awdawdadw', '2026-01-17 12:06:34', 2, 0, NULL),
(18, 'EMP-001', 'f1aa2929-f39d-11f0-8494-b4e9b890daba', 'newcontract.jpg', 'Contract', 'newcontract.jpg', NULL, '', '2026-01-17 12:13:36', 1, 0, NULL);

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
(5, 'temp-003', 'romyr', 'magalong', 'abes', 'SQP', 'IT', 'it staff', 'Agency', 'Unisolutions', 'TES PHILIPPINES, INC.', 'webtek', 'Active', 'eyy@werefecc.com', NULL, '0923123223334', NULL, 'adadwdaawdada', 'adawda3aeaxsas', '32eqeqeq2e', 'qeq22e211', '12121212', '12112313', '2025-12-05', NULL, '2025-07-22', 'temp-003_1768463069.jpg', '2026-01-15 07:44:29', 'eyy', '0932211122', '21qwdadadw'),
(6, 'wewwfwfwe', 'wefwewe', 'WJFE\'j', 'efwe', 'LMS', 'LIGHT MAINTENANCE SECTION', 'wewweej3', 'TESP Direct', 'TESP', 'TES Philippines', 'weowed', 'Terminated', 'tes@gmail.com', NULL, 'wefwewfwe', NULL, 'woeihfoH;EOhfoh', 'OWHDABDA', 'adawdaw', 'aidaiwda', 'QoijdjWD', 'iqwhdiahwd', '2026-01-30', NULL, '2026-01-16', 'wewwfwfwe_477d83d43e23.png', '2026-01-16 12:12:52', 'adadaw', 'adawdawd', 'adawdaw'),
(7, 'eqf', 'ADADAD', 'AEDAED', 'AEDASE', 'RAS', 'ROOT CAUSE ANALYSIS SECTION', 'WDAWD', 'Agency', 'Unisolutions', 'TES Philippines', 'ADADA', 'Active', 'Example@gmal.com', NULL, 'ADAEDED', NULL, 'AEDAEA', 'AEFAEF', 'ADAEAD', 'AEFEFSF', 'ADAEDEA', 'AEFEFAE', '2026-01-06', NULL, '2026-01-18', 'eqf_1768628447.jpg', '2026-01-17 05:41:33', 'AEDAEDA', 'AEDAEDA', 'ADAEDAEDD'),
(8, 'kghhHSj', 'ADAWDAWDAD', 'AHBAJSBJ', 'AKDJBAKBD', 'PSS', 'POWER SUPPLY SECTION', 'TAGA TAMBAY', 'Agency', 'M8 Manpower', 'TES Philippines', 'DYAN SA TINDAHAN NI BUANG', 'Active', '', NULL, '', NULL, 'ALSDLAKDLAS', 'lkhaldhalkshalk', 'zihdaslhdha', 'iwhdahdaj', 'aiohdalhld', 'oiahldahsldh', '2026-01-05', NULL, '2026-01-06', 'kghhHSj_1768566942.jpg', '2026-01-17 05:48:17', 'akjgsdkahskjk', 'aishdjakshkja', 'ihakhkdjs'),
(9, 'pjqwpdjqwpajq', 'romrttr', 'yfhfjfh', 'hfhgdfhgd', 'SQP', 'QA', 'awdowahdaowdo', 'TESP Direct', 'TESP', 'TES Philippines', 'jyfjhgfhg', 'Active', 'exam@gmail.com', NULL, 'kgiugiuguyg', NULL, 'hghgcngfbgfxbgfxgf', 'iaugsluxgkxgas', 'sasxasxa', 'qwqsqs', 'ksjhckjgddka', 'qwsqwsqw', '2026-01-21', NULL, '2026-01-29', 'pjqwpdjqwpajq_1768629377.png', '2026-01-17 05:56:17', 'eahdld', 'ashdaohh', 'ishfkshksh'),
(10, 'awqw', 'sxasxa', 'asxasxa', 'asxasxa', 'RAS', 'ROOT CAUSE ANALYSIS SECTION', 'qwq', 'Agency', 'Unisolutions', 'TES Philippines', 'qwqws', 'Active', 'gr@ald.com', NULL, 'axasxas', NULL, 'Axawa', 'adawdawd', 'adawdad', 'adawdwd', 'awdawd', 'awdawda', '2026-01-17', NULL, '2026-01-14', 'awqw_1768629486.jpg', '2026-01-17 05:58:06', 'adawd', 'awdad', 'adwadwa'),
(11, '999-01', 'eyy', 'awdawdaw', 'awdawd', 'SQP', 'QA', 'taga cellphone', 'TESP Direct', 'TESP', 'TES Philippines', 'dotr', 'Active', 'wadadad@ss.com', NULL, 'awdawdaw', NULL, 'adawd', 'fjytrdrdhtr', 'ygfkyfjuy', 'jgjhjhj', 'qwsqwsq', 'yfjtfyt', '2026-01-20', NULL, '2026-01-22', '999-01_1768630287.jpg', '2026-01-17 06:11:27', 'awsaws', 'awsaw', 'awsaws'),
(12, 'tesing', 'test', 'test', 'test', 'TRS', 'TECHNICAL RESEARCH SECTION', 'test', 'TESP Direct', 'TESP', 'TES Philippines', 'awdawdad', 'Active', 'TEST@GMAIL.COM', NULL, 'qkwhlaHWSL', NULL, 'qwqw`', 'SQWDaw', 'IHLhsjwksa', 'lakdlakjsa', 'awawda', 'awsawa', '2026-02-04', NULL, '2026-02-03', 'tesing_1768631040.png', '2026-01-17 06:25:17', 'awdawd', 'awdawda', 'adhawdaw'),
(13, 'nhtdhgdhdq', 'jgjggj', 'jgjjhgjhg', 'jhjhgjhgj', 'HMS', 'HEAVY MAINTENANCE SECTION', 'hthdgfs', 'TESP Direct', 'TESP', 'TES Philippines', 'k.gkjgjgjg', 'Active', 'sample@gmai.com', NULL, 'kyiukyiuy', NULL, 'aihlahw', 'aihaohd', 'asxas', 'axasxa', 'aasxa', 'axaxa', '2026-01-20', NULL, '2026-01-13', 'nhtdhgdhdq_1768632237.png', '2026-01-17 06:43:57', 'axaxsa', 'XAXAAX', 'ASXASXAX'),
(14, 'axas', 'aawwa', 'aacacad', 'awxaw', 'BFS', 'BUILDING FACILITIES SECTION', 'wsaq', 'TESP Direct', 'TESP', 'TES Philippines', 'wsasawsawa', 'Active', 'agagaga@gmail.com', NULL, 'asxasxaww', NULL, 'hfhfytf', 'uyyffy', 'wqlkshqowahsqhaw', 'ahkajhxaskhxak', 'jhakjxhakhxak', 'jhakjsxhakshx', '2026-01-18', NULL, '2026-02-05', 'axas_1ffe9e6246a7.jpg', '2026-01-17 06:51:08', 'ajhxaskxhakjshkjxa', 'wsqwasa', 'sqwsqws'),
(15, ';ojo', 'asasa', 'asaas', 'eididkid', 'SQP', 'SAFETY', 'wq', 'TESP Direct', 'TESP', 'TES Philippines', 'asasa', 'Active', 'habibi@gmail.com', NULL, 'iwiwiwqi', NULL, 'asaoaosaoa', 'saoaiiasiasiaiasiaisa', 'iiwisasiaias', 'asuasuausasau', 'auasuasuausa', 'aisiauasuaaussau', '2026-01-19', NULL, '2026-01-16', ';ojo_1768565768.jpg', '2026-01-17 07:53:35', 'ahahsahaha', 'fhshdhdhds', 'ahashashasha'),
(19, '012919192qw09wq9', 'eqwqq', 'qwqwsq', 'wsasdq', 'ADMIN', 'GAG', 'qw8q09q9q9qw', 'Agency', 'M8 Manpower', 'TES Phx0U', '0wiadaoisjoi', 'Active', 'SAMPLE@MAIL.COM', NULL, '09358329823811', NULL, 'asijdoaisjoiasojd', 'IJODJAISJDOAIJSODIA', 'ASDASDAS', 'ADASDASD', 'ASDASDA', 'ASDASDAS', '2026-01-11', NULL, '2026-01-18', '012919192qw09wq9_a5f5812ef1fc.png', '2026-01-17 12:24:07', 'ADASDD', 'ASDASD', 'ASDASDAD');

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
('::1', 296, '2026-01-17 13:23:22');

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

--
-- Dumping data for table `requests`
--

INSERT INTO `requests` (`id`, `user_id`, `request_type`, `target_id`, `json_payload`, `status`, `admin_comment`, `created_at`) VALUES
(19, 2, 'RESOLVE_ALERT', 15, '{\"doc_id\":\"15\",\"note\":\"done na sya\",\"resolved_by\":2}', 'PENDING', NULL, '2026-01-17 13:04:56'),
(20, 2, 'RESOLVE_ALERT', 11, '{\"doc_id\":\"11\",\"note\":\"eyy done na sya\",\"resolved_by\":2}', 'PENDING', NULL, '2026-01-17 13:14:48'),
(21, 2, 'RESOLVE_ALERT', 11, '{\"doc_id\":\"11\",\"note\":\"done\",\"resolved_by\":2}', 'PENDING', NULL, '2026-01-17 13:18:05'),
(22, 2, 'UPLOAD_DOC', 0, '{\"employee_id\":\"999-01\",\"original_name\":\"999-01_201Files_1768655970.jpg\",\"file_path\":\"999-01_201Files_1768655970.jpg\",\"category\":\"201 Files\",\"expiry_date\":null,\"description\":\"\",\"uploaded_by\":2}', 'PENDING', NULL, '2026-01-17 13:19:30'),
(23, 2, 'RESOLVE_ALERT', 11, '{\"doc_id\":\"11\",\"note\":\"sumbiteed\",\"resolved_by\":2}', 'PENDING', NULL, '2026-01-17 13:23:05');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','STAFF') DEFAULT 'STAFF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADMIN', '2026-01-15 09:58:12'),
(2, 'staff', '$2y$10$ifKktYCUd7chvP8VY.SRf.B9hZrH1ow4.JPh9M9CaHJmzIZjYnIh2', 'STAFF', '2026-01-16 11:24:44');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
