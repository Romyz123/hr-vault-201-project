-- AUTOMATED FRIDAY BACKUP
-- Date: 2026-01-22 07:11:21

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `activity_logs` VALUES ('1', '1', 'ADD_EMPLOYEE', 'Added new employee: eyy awdawd', '::1', '2026-01-17 14:11:27');
INSERT INTO `activity_logs` VALUES ('2', '1', 'ADD_EMPLOYEE', 'Added new employee: jgjggj jhjhgjhgj', '::1', '2026-01-17 14:43:57');
INSERT INTO `activity_logs` VALUES ('3', '1', 'APPROVED_HIRE', 'Approved New Employee: asasa eididkid', '::1', '2026-01-17 15:53:35');
INSERT INTO `activity_logs` VALUES ('4', '1', 'APPROVED_EDIT', 'Approved Profile Edit for ID: 6', '::1', '2026-01-17 15:53:38');
INSERT INTO `activity_logs` VALUES ('5', '1', 'REJECTED_REQUEST', 'Rejected request type: UPLOAD_DOC', '::1', '2026-01-17 15:53:41');
INSERT INTO `activity_logs` VALUES ('6', '1', 'REJECTED_REQUEST', 'Rejected request type: UPLOAD_DOC', '::1', '2026-01-17 16:27:01');
INSERT INTO `activity_logs` VALUES ('7', '1', 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 16:27:45');
INSERT INTO `activity_logs` VALUES ('8', '1', 'APPROVED_EDIT', 'Approved Profile Edit for ID: 6', '::1', '2026-01-17 16:28:39');
INSERT INTO `activity_logs` VALUES ('9', '1', 'APPROVED_DOC', 'Approved Document: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 16:28:48');
INSERT INTO `activity_logs` VALUES ('10', '2', 'REQUEST_DOC', 'Submitted document for approval: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 16:31:04');
INSERT INTO `activity_logs` VALUES ('11', '2', 'REQUEST_DOC', 'Submitted document for approval: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 17:26:08');
INSERT INTO `activity_logs` VALUES ('12', '2', 'REQUEST_EDIT', 'Submitted profile edit request for ID: 15', '::1', '2026-01-17 17:26:34');
INSERT INTO `activity_logs` VALUES ('13', '1', 'APPROVED_DOC', 'Approved Document: xmas party program flow (2).pdf', '::1', '2026-01-17 17:36:14');
INSERT INTO `activity_logs` VALUES ('14', '1', 'APPROVED_EDIT', 'Approved Profile Edit for ID: 15', '::1', '2026-01-17 17:37:29');
INSERT INTO `activity_logs` VALUES ('15', '1', 'APPROVED_DOC', 'Approved Document: Print naaaaa yung invitation.pdf', '::1', '2026-01-17 17:38:22');
INSERT INTO `activity_logs` VALUES ('16', '1', 'EDIT_PROFILE', 'Directly edited profile of Employee ID: 15', '::1', '2026-01-17 17:41:46');
INSERT INTO `activity_logs` VALUES ('17', '1', 'REJECTED_REQUEST', 'Rejected request type: EDIT_PROFILE', '::1', '2026-01-17 18:35:12');
INSERT INTO `activity_logs` VALUES ('18', '1', 'APPROVED_DOC', 'Approved Document: image (4).png', '::1', '2026-01-17 18:37:50');
INSERT INTO `activity_logs` VALUES ('19', '1', 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 20:03:22');
INSERT INTO `activity_logs` VALUES ('20', '1', 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 20:03:27');
INSERT INTO `activity_logs` VALUES ('21', '1', 'REJECTED_REQUEST', 'Rejected request type: ADD_EMPLOYEE', '::1', '2026-01-17 20:03:34');
INSERT INTO `activity_logs` VALUES ('22', '1', 'REJECTED_REQUEST', 'Rejected request type: UPLOAD_DOC', '::1', '2026-01-17 20:03:47');
INSERT INTO `activity_logs` VALUES ('23', '1', 'UPLOAD_DOC', 'Directly uploaded: hindidapatsampleyungname.png', '::1', '2026-01-17 20:04:44');
INSERT INTO `activity_logs` VALUES ('24', '2', 'REQUEST_DOC', 'Submitted document: hindidapotlockinyungname.jpg', '::1', '2026-01-17 20:06:03');
INSERT INTO `activity_logs` VALUES ('25', '1', 'APPROVED_DOC', 'Approved Document: hindidapotlockinyungname.jpg', '::1', '2026-01-17 20:06:34');
INSERT INTO `activity_logs` VALUES ('26', '1', 'APPROVED_EDIT', 'Approved Profile Edit for ID: 5', '::1', '2026-01-17 20:06:56');
INSERT INTO `activity_logs` VALUES ('27', '1', 'UPLOAD_DOC', 'Directly uploaded: newcontract.jpg', '::1', '2026-01-17 20:13:36');
INSERT INTO `activity_logs` VALUES ('28', '1', 'ADD_EMPLOYEE', 'Added new employee: eqwqq wsasdq', '::1', '2026-01-17 20:24:07');
INSERT INTO `activity_logs` VALUES ('29', '1', 'EDIT_PROFILE', 'Photo updated', '::1', '2026-01-17 20:26:47');
INSERT INTO `activity_logs` VALUES ('30', '1', 'EDIT_PROFILE', 'Photo updated', '::1', '2026-01-17 20:27:14');
INSERT INTO `activity_logs` VALUES ('31', '1', 'EDIT_PROFILE', 'Photo updated', '::1', '2026-01-17 20:27:26');
INSERT INTO `activity_logs` VALUES ('32', '1', 'RESOLVED_ALERT', 'Marked alert as resolved: the file was done', '::1', '2026-01-17 21:03:31');
INSERT INTO `activity_logs` VALUES ('33', '2', 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 15', '::1', '2026-01-17 21:04:56');
INSERT INTO `activity_logs` VALUES ('34', '1', 'RESOLVED_ALERT', 'Marked alert as resolved: reporting', '::1', '2026-01-17 21:14:10');
INSERT INTO `activity_logs` VALUES ('35', '2', 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 21:14:48');
INSERT INTO `activity_logs` VALUES ('36', '2', 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 21:18:05');
INSERT INTO `activity_logs` VALUES ('37', '2', 'REQUEST_DOC', 'Submitted document: 999-01_201Files_1768655970.jpg', '::1', '2026-01-17 21:19:30');
INSERT INTO `activity_logs` VALUES ('38', '2', 'REQUEST_RESOLVE', 'Submitted resolution report for Doc ID: 11', '::1', '2026-01-17 21:23:05');
INSERT INTO `activity_logs` VALUES ('39', '1', 'UPLOAD_DOC', 'Directly uploaded: kggjh.pdf', '::1', '2026-01-19 15:41:03');
INSERT INTO `activity_logs` VALUES ('40', '1', 'UPLOAD_DOC', 'Directly uploaded: h.pdf', '::1', '2026-01-19 15:42:31');
INSERT INTO `activity_logs` VALUES ('41', '1', 'UPLOAD_DOC', 'Directly uploaded: eval.pdf', '::1', '2026-01-19 16:12:55');
INSERT INTO `activity_logs` VALUES ('42', '2', 'REQUEST_EDIT', 'Submitted profile edit request for ID: 12', '::1', '2026-01-19 17:42:04');
INSERT INTO `activity_logs` VALUES ('43', '1', 'APPROVED_EDIT', 'Approved Profile Edit for ID: 12', '::1', '2026-01-19 17:43:54');
INSERT INTO `activity_logs` VALUES ('44', '1', 'APPROVED_DOC', 'Approved Document: 999-01_201Files_1768655970.jpg', '::1', '2026-01-19 17:44:05');
INSERT INTO `activity_logs` VALUES ('45', '1', 'DELETE_DOC', 'Deleted file: kggjh.pdf', '::1', '2026-01-19 17:58:02');
INSERT INTO `activity_logs` VALUES ('46', '1', 'UPLOAD_DOC', 'Directly uploaded: eyy.pdf', '::1', '2026-01-19 18:12:23');
INSERT INTO `activity_logs` VALUES ('47', '2', 'REQUEST_DOC', 'Submitted document: pjqwpdjqwpajq_Medical_1768817692.pdf', '::1', '2026-01-19 18:14:52');
INSERT INTO `activity_logs` VALUES ('48', '2', 'REQUEST_EDIT', 'Submitted profile edit request for ID: 9', '::1', '2026-01-19 18:15:24');
INSERT INTO `activity_logs` VALUES ('49', '1', 'APPROVED_EDIT', 'Approved Profile Edit for ID: 9', '::1', '2026-01-19 18:16:29');
INSERT INTO `activity_logs` VALUES ('50', '1', 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-19 18:17:01');
INSERT INTO `activity_logs` VALUES ('51', '1', 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 15', '::1', '2026-01-19 18:17:31');
INSERT INTO `activity_logs` VALUES ('52', '1', 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 11', '::1', '2026-01-19 18:17:33');
INSERT INTO `activity_logs` VALUES ('53', '1', 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 11', '::1', '2026-01-19 18:17:34');
INSERT INTO `activity_logs` VALUES ('54', '1', 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 11', '::1', '2026-01-19 18:17:34');
INSERT INTO `activity_logs` VALUES ('55', '1', 'RESOLVED_ALERT', 'Marked alert as resolved: okay na', '::1', '2026-01-19 18:17:49');
INSERT INTO `activity_logs` VALUES ('56', '2', 'REQUEST_DOC', 'Submitted document: 999-01_Contract_1768818079.pdf', '::1', '2026-01-19 18:21:19');
INSERT INTO `activity_logs` VALUES ('57', '1', 'APPROVED_DOC', 'Approved Document: 999-01_Contract_1768818079.pdf', '::1', '2026-01-19 18:22:11');
INSERT INTO `activity_logs` VALUES ('58', '1', 'UPLOAD_DOC', 'Directly uploaded: nhtdhgdhdq_Medical_1768818161.pdf', '::1', '2026-01-19 18:22:41');
INSERT INTO `activity_logs` VALUES ('59', '1', 'UPLOAD_DOC', 'Directly uploaded: pjqwpdjqwpajq_VaccineCard_1768819253.png', '::1', '2026-01-19 18:40:53');
INSERT INTO `activity_logs` VALUES ('60', '1', 'UPLOAD_DOC', 'Directly uploaded: adawdwadad.png', '::1', '2026-01-19 18:48:05');
INSERT INTO `activity_logs` VALUES ('61', '1', 'DELETE_DOC', 'Deleted file: eval.pdf', '::1', '2026-01-19 18:57:20');
INSERT INTO `activity_logs` VALUES ('62', '1', 'UPLOAD_DOC', 'Directly uploaded: awqw_Eyyy_1768820868.jpg', '::1', '2026-01-19 19:07:48');
INSERT INTO `activity_logs` VALUES ('63', '1', 'DELETE_DOC', 'Deleted file: sample.png', '::1', '2026-01-20 09:04:39');
INSERT INTO `activity_logs` VALUES ('64', '1', 'DELETE_DOC', 'Deleted file: sample.png', '::1', '2026-01-20 09:04:45');
INSERT INTO `activity_logs` VALUES ('65', '1', 'DELETE_DOC', 'Deleted file: Print naaaaa yung invitation.pdf', '::1', '2026-01-20 09:06:03');
INSERT INTO `activity_logs` VALUES ('66', '1', 'UPLOAD_DOC', 'Directly uploaded: trffsf.png', '::1', '2026-01-20 09:11:36');
INSERT INTO `activity_logs` VALUES ('67', '1', 'DELETE_DOC', 'Deleted file: adawdwadad.png', '::1', '2026-01-20 13:22:46');
INSERT INTO `activity_logs` VALUES ('68', '1', 'APPROVED_DOC', 'Approved Document: pjqwpdjqwpajq_Medical_1768817692.pdf', '::1', '2026-01-20 18:01:16');
INSERT INTO `activity_logs` VALUES ('69', '1', 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 11:10:25');
INSERT INTO `activity_logs` VALUES ('70', '1', 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 11:10:34');
INSERT INTO `activity_logs` VALUES ('71', '1', 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 11:13:04');
INSERT INTO `activity_logs` VALUES ('72', '1', 'EDIT_PROFILE', 'Updated profile details', '::1', '2026-01-21 11:20:35');
INSERT INTO `activity_logs` VALUES ('73', '2', 'REQUEST_HIRE', 'Submitted request for new employee: sample sample', '::1', '2026-01-21 11:45:14');
INSERT INTO `activity_logs` VALUES ('74', '2', 'REQUEST_HIRE', 'Submitted request for new employee: asdasd asdas', '::1', '2026-01-21 12:00:16');
INSERT INTO `activity_logs` VALUES ('75', '2', 'REQUEST_HIRE', 'Submitted request for new employee: John Doe', '::1', '2026-01-21 13:14:45');
INSERT INTO `activity_logs` VALUES ('76', '1', 'REJECTED_REQUEST', 'Rejected/Disregarded request type: ADD_EMPLOYEE', '::1', '2026-01-21 13:15:52');
INSERT INTO `activity_logs` VALUES ('77', '1', 'REJECTED_REQUEST', 'Rejected/Disregarded request type: ADD_EMPLOYEE', '::1', '2026-01-21 13:15:54');
INSERT INTO `activity_logs` VALUES ('78', '1', 'APPROVED_HIRE', 'Approved New Employee: John Doe', '::1', '2026-01-21 13:15:59');
INSERT INTO `activity_logs` VALUES ('79', '2', 'REQUEST_EDIT', 'Submitted profile edit request for ID: 6', '::1', '2026-01-21 13:17:23');
INSERT INTO `activity_logs` VALUES ('80', '1', 'REJECTED_REQUEST', 'Rejected/Disregarded request type: EDIT_PROFILE', '::1', '2026-01-21 13:18:18');
INSERT INTO `activity_logs` VALUES ('81', '2', 'REQUEST_HIRE', 'Submitted request for new employee: j wawad', '::1', '2026-01-21 13:39:39');
INSERT INTO `activity_logs` VALUES ('82', '1', 'REJECTED_REQUEST', 'Rejected request: ADD_EMPLOYEE', '::1', '2026-01-21 13:43:36');
INSERT INTO `activity_logs` VALUES ('83', '2', 'REQUEST_HIRE', 'Submitted request for new employee: eyy DADAWD', '::1', '2026-01-21 14:06:02');
INSERT INTO `activity_logs` VALUES ('84', '1', 'REJECTED_REQUEST', 'Rejected request: ADD_EMPLOYEE', '::1', '2026-01-21 14:11:33');
INSERT INTO `activity_logs` VALUES ('85', '2', 'REQUEST_DOC', 'Submitted document: ohohoh.jpg', '::1', '2026-01-21 14:12:45');
INSERT INTO `activity_logs` VALUES ('86', '1', 'REJECTED_REQUEST', 'Rejected request: UPLOAD_DOC', '::1', '2026-01-21 14:13:14');
INSERT INTO `activity_logs` VALUES ('87', '2', 'REQUEST_DOC', 'Submitted document: temp-003_Lockin_1768976126.jpg', '::1', '2026-01-21 14:15:26');
INSERT INTO `activity_logs` VALUES ('88', '1', 'APPROVED_DOC', 'Approved Document: temp-003_Lockin_1768976126.jpg', '::1', '2026-01-21 14:16:09');
INSERT INTO `activity_logs` VALUES ('89', '2', 'REQUEST_DOC', 'Submitted document: eyy_Evaluation_1768976469.png', '::1', '2026-01-21 14:21:09');
INSERT INTO `activity_logs` VALUES ('90', '1', 'APPROVED_DOC', 'Approved Document: eyy_Evaluation_1768976469.png', '::1', '2026-01-21 14:21:29');
INSERT INTO `activity_logs` VALUES ('91', '2', 'REQUEST_DOC', 'Submitted document: cnbcnbcbcv.png', '::1', '2026-01-21 14:33:00');
INSERT INTO `activity_logs` VALUES ('92', '2', 'REQUEST_DOC', 'Submitted document: emp-0007_GovernmentIDs_1768978009.jpg', '::1', '2026-01-21 14:46:49');
INSERT INTO `activity_logs` VALUES ('93', '1', 'APPROVED_DOC', 'Approved Document: cnbcnbcbcv.png', '::1', '2026-01-21 14:47:13');
INSERT INTO `activity_logs` VALUES ('94', '1', 'APPROVED_DOC', 'Approved Document: emp-0007_GovernmentIDs_1768978009.jpg', '::1', '2026-01-21 14:47:14');
INSERT INTO `activity_logs` VALUES ('95', '2', 'REQUEST_RESOLUTION', 'Submitted resolution request for Doc ID: 34', '::1', '2026-01-21 15:14:15');
INSERT INTO `activity_logs` VALUES ('96', '1', 'APPROVED_RESOLUTION', 'Approved resolution for Doc ID 34', '::1', '2026-01-21 16:48:45');
INSERT INTO `activity_logs` VALUES ('97', '3', 'UPLOAD_DOC', 'Directly uploaded: emp-0007_GovernmentIDs_1768985846.jpg', '::1', '2026-01-21 16:57:26');
INSERT INTO `activity_logs` VALUES ('98', '3', 'DELETE_DOC', 'Deleted file: emp-0007_GovernmentIDs_1768985846.jpg', '::1', '2026-01-21 18:18:17');
INSERT INTO `activity_logs` VALUES ('99', '1', 'UPLOAD_DOC', 'Directly uploaded: eyy.jpg', '::1', '2026-01-21 18:19:05');
INSERT INTO `activity_logs` VALUES ('100', '2', 'REQUEST_DOC', 'Submitted document: awqw_GovernmentIDs_1768990836.png', '::1', '2026-01-21 18:20:36');
INSERT INTO `activity_logs` VALUES ('101', '3', 'REJECTED_REQUEST', 'Rejected request: UPLOAD_DOC', '::1', '2026-01-21 18:21:07');
INSERT INTO `activity_logs` VALUES ('102', '1', 'EXPORT_FILES', 'User downloaded Bulk ZIP. [Dept: ALL, Files: 27]', '::1', '2026-01-21 18:59:00');
INSERT INTO `activity_logs` VALUES ('103', '2', 'REQUEST_DOC', 'Submitted document: SampleofvaccineCard.jpg', '::1', '2026-01-22 07:56:10');
INSERT INTO `activity_logs` VALUES ('104', '1', 'UPLOAD_DOC', 'Directly uploaded: invitation.jpg', '::1', '2026-01-22 09:06:08');
INSERT INTO `activity_logs` VALUES ('105', '3', 'UPLOAD_DOC', 'Directly uploaded: trialsabagongdeletefunction.jpg', '::1', '2026-01-22 09:18:42');
INSERT INTO `activity_logs` VALUES ('106', '1', 'UPLOAD_DOC', 'Directly uploaded: sampleparamadelete.jpg', '::1', '2026-01-22 09:23:55');
INSERT INTO `activity_logs` VALUES ('107', '3', 'UPLOAD_DOC', 'Directly uploaded: trialulitparasadeletefucntion.jpg', '::1', '2026-01-22 09:29:04');
INSERT INTO `activity_logs` VALUES ('108', '3', 'UPLOAD_DOC', 'Directly uploaded: deletnatalagasya.jpg', '::1', '2026-01-22 09:30:16');
INSERT INTO `activity_logs` VALUES ('109', '3', 'REJECTED_REQUEST', 'Rejected request: UPLOAD_DOC', '::1', '2026-01-22 09:39:51');
INSERT INTO `activity_logs` VALUES ('110', '3', 'UPLOAD_DOC', 'Directly uploaded: tahimiklangakosaumpisa.jpg', '::1', '2026-01-22 10:47:42');
INSERT INTO `activity_logs` VALUES ('111', '3', 'UPLOAD_DOC', 'Directly uploaded: Warhammer.png', '::1', '2026-01-22 11:19:42');
INSERT INTO `activity_logs` VALUES ('112', '1', 'UPLOAD_DOC', 'Directly uploaded: warhammmer.png', '::1', '2026-01-22 11:51:14');
INSERT INTO `activity_logs` VALUES ('113', '3', 'UPLOAD_DOC', 'Directly uploaded: Warhammer.png', '::1', '2026-01-22 13:16:29');
INSERT INTO `activity_logs` VALUES ('114', '3', 'DELETE_DOC', 'Deleted file: Warhammer.png', '::1', '2026-01-22 13:16:38');
INSERT INTO `activity_logs` VALUES ('115', '1', 'SYSTEM_BACKUP', 'Admin downloaded full database backup.', '::1', '2026-01-22 13:21:22');
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `resolution_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_uuid` (`file_uuid`),
  KEY `fk_documents_employee` (`employee_id`),
  CONSTRAINT `fk_documents_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`emp_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `documents` VALUES ('2', 'EMP-001', '847fbef4-f200-11f0-8494-b4e9b890daba', 'SCORE SHEET PRINT.pdf', 'Violation', 'EMP-001_Others_1768474449.pdf', '2026-01-15', 'trial eme', '2026-01-15 18:54:09', '1', '1', 'the file was done');
INSERT INTO `documents` VALUES ('4', 'temp-003', '95f9c134-f352-11f0-8494-b4e9b890daba', 'image (4).png', '', 'temp-003_Certificate_1768619650.png', NULL, '', '2026-01-17 11:14:10', '1', '0', NULL);
INSERT INTO `documents` VALUES ('5', 'EMP-002', '724b8ccb-f354-11f0-8494-b4e9b890daba', 'perfect adttendacne  Awardings for tesp xmas party (1).png', '', 'EMP-002_201Files_1768620449.png', NULL, '', '2026-01-17 11:27:29', '1', '0', NULL);
INSERT INTO `documents` VALUES ('6', 'eheheh-001', '44244fd4-f358-11f0-8494-b4e9b890daba', 'host 2.jpg', '', 'eheheh-001_201Files_1768622089.jpg', NULL, '', '2026-01-17 11:54:49', '1', '0', NULL);
INSERT INTO `documents` VALUES ('7', 'EMP-002', 'b460aad2-f358-11f0-8494-b4e9b890daba', 'Christmas-Party-2022-Programme_FInal.pdf', '', 'EMP-002_201Files_1768622278.pdf', NULL, 'xmass partyyy', '2026-01-17 11:57:58', '1', '0', NULL);
INSERT INTO `documents` VALUES ('8', 'eheheh-001', '3d0b416e-f35d-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 12:30:25', '2', '0', NULL);
INSERT INTO `documents` VALUES ('9', 'EMP-001', '8a2c9c06-f37e-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', 'Contract', 'EMP-001_Contract_1768568260.pdf', NULL, '', '2026-01-17 16:28:48', '2', '0', NULL);
INSERT INTO `documents` VALUES ('10', 'eyy', 'f5c622e4-f387-11f0-8494-b4e9b890daba', 'xmas party program flow (2).pdf', '', 'sample.pdf', NULL, 'memo yern', '2026-01-17 17:36:14', '2', '0', NULL);
INSERT INTO `documents` VALUES ('11', ';ojo', '4214e628-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768641968.pdf', '2026-01-23', '', '2026-01-17 17:38:22', '2', '1', 'sumbiteed');
INSERT INTO `documents` VALUES ('12', ';ojo', '53a0b5e0-f388-11f0-8494-b4e9b890daba', 'Print naaaaa yung invitation.pdf', '', ';ojo_201Files_1768642731.pdf', NULL, '', '2026-01-17 17:38:51', '1', '0', NULL);
INSERT INTO `documents` VALUES ('14', 'eheheh-001', '9086f46b-f390-11f0-8494-b4e9b890daba', 'image (4).png', '', 'eheheh-001_201Files_1768622318.png', NULL, 'samploe', '2026-01-17 18:37:50', '2', '0', NULL);
INSERT INTO `documents` VALUES ('16', '999-01', 'b45e2fb6-f39c-11f0-8494-b4e9b890daba', 'hindidapatsampleyungname.png', '', 'hindidapatsampleyungname.png', NULL, 'hindi dapat', '2026-01-17 20:04:44', '1', '0', NULL);
INSERT INTO `documents` VALUES ('17', 'tesing', 'f5dc1982-f39c-11f0-8494-b4e9b890daba', 'hindidapotlockinyungname.jpg', 'Contract', 'hindidapotlockinyungname.jpg', '2026-01-29', 'Awdawdadw', '2026-01-17 20:06:34', '2', '1', 'okay na');
INSERT INTO `documents` VALUES ('18', 'EMP-001', 'f1aa2929-f39d-11f0-8494-b4e9b890daba', 'newcontract.jpg', 'Contract', 'newcontract.jpg', NULL, '', '2026-01-17 20:13:36', '1', '0', NULL);
INSERT INTO `documents` VALUES ('20', 'tesing', '692347b7-f50a-11f0-8cf7-b4e9b890daba', 'h.pdf', 'Late', 'h.pdf', NULL, 'sample po', '2026-01-19 15:42:31', '1', '0', NULL);
INSERT INTO `documents` VALUES ('22', '999-01', '6496c836-f51b-11f0-8cf7-b4e9b890daba', '999-01_201Files_1768655970.jpg', '', '999-01_201Files_1768655970.jpg', NULL, '', '2026-01-19 17:44:05', '2', '0', NULL);
INSERT INTO `documents` VALUES ('23', 'tesing', '5913367a-f51f-11f0-8cf7-b4e9b890daba', 'eyy.pdf', '', 'eyy.pdf', NULL, '', '2026-01-19 18:12:23', '1', '0', NULL);
INSERT INTO `documents` VALUES ('24', '999-01', 'b72e4592-f520-11f0-8cf7-b4e9b890daba', '999-01_Contract_1768818079.pdf', 'Contract', '999-01_Contract_1768818079.pdf', NULL, '', '2026-01-19 18:22:11', '2', '0', NULL);
INSERT INTO `documents` VALUES ('25', 'nhtdhgdhdq', 'c973a9e3-f520-11f0-8cf7-b4e9b890daba', 'nhtdhgdhdq_Medical_1768818161.pdf', '', 'nhtdhgdhdq_Medical_1768818161.pdf', NULL, '', '2026-01-19 18:22:41', '1', '0', NULL);
INSERT INTO `documents` VALUES ('26', 'pjqwpdjqwpajq', '53ff1a40-f523-11f0-8cf7-b4e9b890daba', 'pjqwpdjqwpajq_VaccineCard_1768819253.png', '', 'pjqwpdjqwpajq_VaccineCard_1768819253.png', NULL, 'sample submition aaedadawaawdadaw', '2026-01-19 18:40:53', '1', '0', NULL);
INSERT INTO `documents` VALUES ('28', 'awqw', '16978646-f527-11f0-8cf7-b4e9b890daba', 'awqw_Eyyy_1768820868.jpg', '', 'awqw_Eyyy_1768820868.jpg', NULL, '', '2026-01-19 19:07:48', '1', '0', NULL);
INSERT INTO `documents` VALUES ('29', 'tesing', 'f7465931-f59c-11f0-8cf7-b4e9b890daba', 'trffsf.png', '', 'trffsf.png', NULL, '', '2026-01-20 09:11:36', '1', '0', NULL);
INSERT INTO `documents` VALUES ('30', 'pjqwpdjqwpajq', 'f5beb121-f5e6-11f0-9d0c-b4e9b890daba', 'pjqwpdjqwpajq_Medical_1768817692.pdf', '', 'pjqwpdjqwpajq_Medical_1768817692.pdf', NULL, '', '2026-01-20 18:01:16', '2', '0', NULL);
INSERT INTO `documents` VALUES ('31', 'temp-003', 'ad069f42-f690-11f0-9d0c-b4e9b890daba', 'temp-003_Lockin_1768976126.jpg', '', 'temp-003_Lockin_1768976126.jpg', NULL, '', '2026-01-21 14:16:09', '2', '0', NULL);
INSERT INTO `documents` VALUES ('32', 'eyy', '6c01cd37-f691-11f0-9d0c-b4e9b890daba', 'eyy_Evaluation_1768976469.png', 'Evaluation', 'eyy_Evaluation_1768976469.png', NULL, '', '2026-01-21 14:21:29', '2', '0', NULL);
INSERT INTO `documents` VALUES ('33', 'emp-0007', '03f3aa84-f695-11f0-9d0c-b4e9b890daba', 'cnbcnbcbcv.png', '', 'cnbcnbcbcv.png', NULL, '', '2026-01-21 14:47:13', '2', '0', NULL);
INSERT INTO `documents` VALUES ('36', 'eheheh-001', '9d4a1295-f6b2-11f0-9d0c-b4e9b890daba', 'eyy.jpg', '', 'eyy.jpg', NULL, '', '2026-01-21 18:19:05', '1', '0', NULL);
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `emergency_address` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_id` (`emp_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `employees` VALUES ('1', 'EMP-001', 'Juan', '', 'Dela Cruz', 'ADMIN', 'GAG', 'taga kain', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', 'waqe213edds', 'Active', 'juan@example.com', NULL, '', NULL, 'asasa', 'asasxa', 'awdw', 'qwqwe', 'qwwqq', 'adasasasd', '0000-00-00', NULL, '0000-00-00', 'EMP-001-AWOL_1768467712.jpg', '2026-01-15 09:47:06', 'adawqa', 'd23e322', 'AWDAWDAd');
INSERT INTO `employees` VALUES ('2', 'EMP-002', 'Maria', '', 'Santos', 'ADMIN', 'GAG', 'HR Officer', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', '', 'Active', 'maria@example.com', NULL, '', NULL, '', '', '', '', '', '', '0000-00-00', NULL, '0000-00-00', 'EMP-002_1768470799.jpg', '2026-01-15 09:47:06', '', '', '');
INSERT INTO `employees` VALUES ('3', 'eyy', 'ronel', NULL, 'mordawdad', 'Operations', NULL, 'Techniain', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', NULL, 'Active', 'awaw@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-05', NULL, NULL, 'eyy_1768456487.jpg', '2026-01-15 13:54:47', NULL, NULL, NULL);
INSERT INTO `employees` VALUES ('4', 'eheheh-001', 'awit', NULL, 'ronwadw', 'Human Resources', NULL, 'taga tulog', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', NULL, 'Active', 'wadawd@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-10-12', NULL, NULL, 'eheheh-001_1768456567.jpg', '2026-01-15 13:56:07', NULL, NULL, NULL);
INSERT INTO `employees` VALUES ('5', 'temp-003', 'romyr', 'magalong', 'abes', 'SQP', 'IT', 'it staff', 'TESP Direct', 'TESP', 'TES PHILIPPINES, INC.', 'webtek', 'Active', 'eyy@werefecc.com', NULL, '0923123223334', NULL, 'adadwdaawdada', 'adawda3aeaxsas', '32eqeqeq2e', 'qeq22e211', '12121212', '12112313', '2025-12-05', NULL, '2025-07-22', 'temp-003_1768463069.jpg', '2026-01-15 15:44:29', 'eyy', '0932211122', '21qwdadadw');
INSERT INTO `employees` VALUES ('6', 'wewwfwfwe', 'wefwewe', 'WJFE\'j', 'efwe', 'LMS', 'LIGHT MAINTENANCE SECTION', 'wewweej3', 'TESP Direct', 'TESP', 'TES Philippines', 'weowed', 'Terminated', 'tes@gmail.com', NULL, 'wefwewfwe', NULL, 'woeihfoH;EOhfoh', 'OWHDABDA', 'adawdaw', 'aidaiwda', 'QoijdjWD', 'iqwhdiahwd', '2026-01-30', NULL, '2026-01-16', 'wewwfwfwe_477d83d43e23.png', '2026-01-16 20:12:52', 'adadaw', 'adawdawd', 'adawdaw');
INSERT INTO `employees` VALUES ('7', 'eqf', 'ADADAD', 'AEDAED', 'AEDASE', 'RAS', 'ROOT CAUSE ANALYSIS SECTION', 'WDAWD', 'Agency', 'Unisolutions', 'TES Philippines', 'ADADA', 'Active', 'Example@gmal.com', NULL, 'ADAEDED', NULL, 'AEDAEA', 'AEFAEF', 'ADAEAD', 'AEFEFSF', 'ADAEDEA', 'AEFEFAE', '2026-01-06', NULL, '2026-01-18', 'eqf_1768628447.jpg', '2026-01-17 13:41:33', 'AEDAEDA', 'AEDAEDA', 'ADAEDAEDD');
INSERT INTO `employees` VALUES ('8', 'kghhHSj', 'ADAWDAWDAD', 'AHBAJSBJ', 'AKDJBAKBD', 'PSS', 'POWER SUPPLY SECTION', 'TAGA TAMBAY', 'Agency', 'JORATECH', 'TES Philippines', 'DYAN SA TINDAHAN NI BUANG', 'Active', '', NULL, '', NULL, 'ALSDLAKDLAS', 'lkhaldhalkshalk', 'zihdaslhdha', 'iwhdahdaj', 'aiohdalhld', 'oiahldahsldh', '2026-01-05', NULL, '2026-01-06', 'kghhHSj_1768566942.jpg', '2026-01-17 13:48:17', 'akjgsdkahskjk', 'aishdjakshkja', 'ihakhkdjs');
INSERT INTO `employees` VALUES ('9', 'pjqwpdjqwpajq', 'romrttr', 'yfhfjfh', 'tiradores', 'SQP', 'QA', 'awdowahdaowdo', 'TESP Direct', 'TESP', 'TES Philippines', 'jyfjhgfhg', 'Active', 'exam@gmail.com', NULL, 'kgiugiuguyg', NULL, 'hghgcngfbgfxbgfxgf', 'iaugsluxgkxgas', 'sasxasxa', 'qwqsqs', 'ksjhckjgddka', 'qwsqwsqw', '2026-01-21', NULL, '2026-01-29', 'pjqwpdjqwpajq_1768629377.png', '2026-01-17 13:56:17', 'eahdld', 'ashdaohh', 'ishfkshksh');
INSERT INTO `employees` VALUES ('10', 'awqw', 'sxasxa', 'asxasxa', 'asxasxa', 'RAS', 'ROOT CAUSE ANALYSIS SECTION', 'qwq', 'Agency', 'Unisolutions', 'TES Philippines', 'qwqws', 'Active', 'gr@ald.com', NULL, 'axasxas', NULL, 'Axawa', 'adawdawd', 'adawdad', 'adawdwd', 'awdawd', 'awdawda', '2026-01-17', NULL, '2026-01-14', 'awqw_1768629486.jpg', '2026-01-17 13:58:06', 'adawd', 'awdad', 'adwadwa');
INSERT INTO `employees` VALUES ('11', '999-01', 'eyy', 'awdawdaw', 'awdawd', 'SQP', 'QA', 'taga cellphone', 'TESP Direct', 'TESP', 'TES Philippines', 'dotr', 'Active', 'wadadad@ss.com', NULL, 'awdawdaw', NULL, 'adawd', 'fjytrdrdhtr', 'ygfkyfjuy', 'jgjhjhj', 'qwsqwsq', 'yfjtfyt', '2026-01-20', NULL, '2026-01-22', '999-01_1768630287.jpg', '2026-01-17 14:11:27', 'awsaws', 'awsaw', 'awsaws');
INSERT INTO `employees` VALUES ('12', 'tesing', 'test', 'sampe', 'trial', 'TRS', 'TECHNICAL RESEARCH SECTION', 'test', 'TESP Direct', 'TESP', 'TES Philippines', 'awdawdad', 'Active', 'TEST@GMAIL.COM', NULL, 'qkwhlaHWSL', NULL, 'qwqw`', 'SQWDaw', 'IHLhsjwksa', 'lakdlakjsa', 'awawda', 'awsawa', '2026-02-04', NULL, '2026-02-03', 'tesing_1768631040.png', '2026-01-17 14:25:17', 'awdawd', 'awdawda', 'adhawdaw');
INSERT INTO `employees` VALUES ('13', 'nhtdhgdhdq', 'jgjggj', 'jgjjhgjhg', 'jhjhgjhgj', 'HMS', 'HEAVY MAINTENANCE SECTION', 'hthdgfs', 'TESP Direct', 'TESP', 'TES Philippines', 'k.gkjgjgjg', 'Active', 'sample@gmai.com', NULL, 'kyiukyiuy', NULL, 'aihlahw', 'aihaohd', 'asxas', 'axasxa', 'aasxa', 'axaxa', '2026-01-20', NULL, '2026-01-13', 'nhtdhgdhdq_1768632237.png', '2026-01-17 14:43:57', 'axaxsa', 'XAXAAX', 'ASXASXAX');
INSERT INTO `employees` VALUES ('14', 'axas', 'aawwa', 'aacacad', 'awxaw', 'BFS', 'BUILDING FACILITIES SECTION', 'wsaq', 'TESP Direct', 'TESP', 'TES Philippines', 'wsasawsawa', 'Active', 'agagaga@gmail.com', NULL, 'asxasxaww', NULL, 'hfhfytf', 'uyyffy', 'wqlkshqowahsqhaw', 'ahkajhxaskhxak', 'jhakjxhakhxak', 'jhakjsxhakshx', '2026-01-18', NULL, '2026-02-05', 'axas_1ffe9e6246a7.jpg', '2026-01-17 14:51:08', 'ajhxaskxhakjshkjxa', 'wsqwasa', 'sqwsqws');
INSERT INTO `employees` VALUES ('15', ';ojo', 'asasa', 'asaas', 'eididkid', 'SQP', 'SAFETY', 'wq', 'TESP Direct', 'TESP', 'TES Philippines', 'asasa', 'Active', 'habibi@gmail.com', NULL, 'iwiwiwqi', NULL, 'asaoaosaoa', 'saoaiiasiasiaiasiaisa', 'iiwisasiaias', 'asuasuausasau', 'auasuasuausa', 'aisiauasuaaussau', '2026-01-19', NULL, '2026-01-16', ';ojo_1768565768.jpg', '2026-01-17 15:53:35', 'ahahsahaha', 'fhshdhdhds', 'ahashashasha');
INSERT INTO `employees` VALUES ('19', '012919192qw09wq9', 'eqwqq', 'qwqwsq', 'wsasdq', 'ADMIN', 'GAG', 'qw8q09q9q9qw', 'Agency', 'OTHERS - SUBCONS', 'TES P', '0wiadaoisjoi', 'Active', 'SAMPLE@MAIL.COM', NULL, '09358329823811', NULL, 'asijdoaisjoiasojd', 'IJODJAISJDOAIJSODIA', 'ASDASDAS', 'ADASDASD', 'ASDASDA', 'ASDASDAS', '2026-01-11', NULL, '2026-01-18', '012919192qw09wq9_a5f5812ef1fc.png', '2026-01-17 20:24:07', 'ADASDD', 'ASDASD', 'ASDASDAD');
INSERT INTO `employees` VALUES ('20', 'emp-0007', 'John', 'm', 'Doe', 'ADMIN', 'ADMIN', 'samples', 'TESP Direct', 'TESP', 'TES Philippines', 'sample- sample', 'Active', '', NULL, '', NULL, 'awdawdwa', 'adawdawd', 'asdawdawd', 'awdawdaw', 'asdasda', 'sdasdas', '2026-01-22', NULL, '2026-01-30', 'emp-0007_823cf9a37629.jpg', '2026-01-21 13:15:59', 'adasda', 'asdasd', 'asdasd');
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(20) DEFAULT 'info',
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` VALUES ('10', '2', 'Request Rejected', 'Your request (UPLOAD_DOC) was rejected.\n\nReason: hindi na taggap\n', 'danger', '0', '2026-01-22 09:39:51');
DROP TABLE IF EXISTS `pending_requests`;
CREATE TABLE `pending_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emp_id` varchar(32) DEFAULT NULL,
  `request_type` enum('EDIT_INFO','UPLOAD_DOC') NOT NULL,
  `json_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`json_payload`)),
  `submitted_by` varchar(64) NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pending_requests` VALUES ('1', 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"140d2f54480898ca7dc3db3cb42a1782\",\"original_name\":\"Score sheets.pdf\",\"category\":\"Evaluation\",\"file_path\":\"140d2f54480898ca7dc3db3cb42a1782.pdf\"}', 'eyy', 'REJECTED', '2026-01-15 10:13:20');
INSERT INTO `pending_requests` VALUES ('2', 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"fed1f1954b270beb11b80204e56544d2\",\"original_name\":\"Score sheets.pdf\",\"category\":\"Evaluation\",\"file_path\":\"fed1f1954b270beb11b80204e56544d2.pdf\"}', 'eyy', 'REJECTED', '2026-01-15 10:14:41');
INSERT INTO `pending_requests` VALUES ('3', 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"f547c719db31941cca11bd6a73fb7e9c\",\"original_name\":\"Score sheets.pdf\",\"category\":\"Evaluation\",\"file_path\":\"f547c719db31941cca11bd6a73fb7e9c.pdf\"}', 'oy', 'APPROVED', '2026-01-15 10:26:16');
INSERT INTO `pending_requests` VALUES ('4', 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"ced3613f886fe8cb416f3b6b85253182\",\"original_name\":\"7.1 Logon password self-reset simple manual.pdf\",\"category\":\"Notice\",\"file_path\":\"ced3613f886fe8cb416f3b6b85253182.pdf\"}', 'ayy', 'PENDING', '2026-01-15 10:27:11');
INSERT INTO `pending_requests` VALUES ('5', 'EMP-001', 'UPLOAD_DOC', '{\"file_uuid\":\"4d78c1c38ab93767669729f2ec7b6cbf\",\"original_name\":\"7.1 Logon password self-reset simple manual.pdf\",\"category\":\"Contract\",\"file_path\":\"4d78c1c38ab93767669729f2ec7b6cbf.pdf\"}', 'admin', 'PENDING', '2026-01-15 13:40:15');
DROP TABLE IF EXISTS `rate_limits`;
CREATE TABLE `rate_limits` (
  `ip_address` varchar(45) NOT NULL,
  `request_count` int(11) DEFAULT 1,
  `last_request` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `rate_limits` VALUES ('::1', '145', '2026-01-22 14:09:19');
DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `request_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) NOT NULL,
  `json_payload` text NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  `admin_comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('ADMIN','STAFF','HR') NOT NULL DEFAULT 'STAFF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES ('1', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADMIN', '2026-01-15 17:58:12');
INSERT INTO `users` VALUES ('2', 'staff', '$2y$10$ifKktYCUd7chvP8VY.SRf.B9hZrH1ow4.JPh9M9CaHJmzIZjYnIh2', 'STAFF', '2026-01-16 19:24:44');
INSERT INTO `users` VALUES ('3', 'hr1', '$2y$10$XpBlW0hcNipX2uwjfEh6XePXP0jljgQ0wx7YBAwAviuBjUiGJQoqi', 'HR', '2026-01-19 17:45:08');
INSERT INTO `users` VALUES ('4', 'Staff1', '$2y$10$TTMdx029VEEkOcHJvSfODeCqDIv7S8FwQ6tkKkaGmvmSki9iuAcsa', 'STAFF', '2026-01-22 08:18:34');

SET FOREIGN_KEY_CHECKS=1;