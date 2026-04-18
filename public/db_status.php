<?php
// ======================================================
// [FILE] public/db_status.php
// [PURPOSE] Automated Database Schema Verification & Fixer
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// [SECURITY] CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

// 1. SECURITY: ADMIN ONLY
if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$msg = "";
$masterVersion = "2.1.0"; // Master Schema Version

// ------------------------------------------------------
// PRODUCTION LAUNCH CHECKLIST LOGIC
// ------------------------------------------------------
$config = require '../config/config.php';

// 1. Check Dev Files
$devFiles = [
    'utils/install.php',
    'auth_login.php',
    'create_admin.php',
    'test_vault.php',
    'debug_whitespace.php',
    'test_system.php',
    'test_email.php',
    'test_email_alert.php',
    'test_zip_password.php',
    'debug_vault.php',
    'debug_upload.php',
    'ValidatorTest.php',
    'download_assets.php',
    'stress_test_backup.php',
    'stress_test_vault.php',
    'migrate_favicon.php',
    'system_diagnostics.php',
    'qa_test.php',
    'process_approval.php',
    'process_edit_employee.php',
    'process_add_employee.php',
    'generate_test_data.php'
];
$basePath = __DIR__ . '/';
$devFilesExist = false;
foreach ($devFiles as $f) {
    if (file_exists($basePath . $f)) $devFilesExist = true;
}

// [NEW] Handle Dev File Cleanup directly from Checklist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_dev_files'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
    $deletedCount = 0;
    foreach ($devFiles as $f) {
        if (file_exists($basePath . $f)) {
            @unlink($basePath . $f);
            $deletedCount++;
        }
    }
    $msg = "✅ Cleanup Complete: $deletedCount development files removed.";
    $devFilesExist = false; // Reset state
}

// 2. Check Error Reporting
$errorsOff = (ini_get('display_errors') == 0 || ini_get('display_errors') === 'Off' || ini_get('display_errors') === '');

// 3. Check Vault Key
$vaultKey = $config['VAULT_KEY'] ?? '';
$keySecure = (strlen($vaultKey) >= 32 && $vaultKey !== 'GENERATE_RANDOM_32_CHAR_STRING_HERE!!');

// 4. Check Directory Permissions
$vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
$backupsPath = realpath(__DIR__ . '/../backups');
if (!$backupsPath && !is_dir(__DIR__ . '/../backups')) @mkdir(__DIR__ . '/../backups', 0755, true);
$backupsPath = realpath(__DIR__ . '/../backups');
$permsOk = (is_writable($vaultPath) && is_writable(__DIR__ . '/uploads') && is_writable($backupsPath));

// 5. HTTPS
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false)
    || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
);

// 6. Cron Backup (Informational - we can't check Windows Task Scheduler from PHP easily)
$cronStatus = "Manual verification required.";


// ------------------------------------------------------
// SCHEMA DEFINITIONS
// ------------------------------------------------------

// 1. Existing Tables needing New Columns
$columnSchema = [
    'users' => [
        'is_2fa_enabled' => "TINYINT(1) DEFAULT 0",
        'totp_secret' => "VARCHAR(255) NULL",
        'recovery_codes' => "TEXT NULL",
        'otp_code' => "VARCHAR(6) NULL",
        'otp_expires' => "DATETIME NULL",
        'last_otp_sent' => "DATETIME NULL",
        'password_changed_at' => "DATETIME NULL",
        'trusted_device_token' => "VARCHAR(64) NULL DEFAULT NULL",
        'trusted_device_expires' => "DATETIME NULL DEFAULT NULL",
        'reset_token' => "VARCHAR(64) NULL DEFAULT NULL",
        'reset_expires' => "DATETIME NULL DEFAULT NULL",
        'failed_attempts' => "INT DEFAULT 0",
        'locked_until' => "DATETIME NULL",
        'is_shared' => "TINYINT(1) DEFAULT 0",
        'account_owner' => "VARCHAR(100) NULL DEFAULT NULL",
        'last_verified_at' => "DATETIME NULL",
        'security_question' => "VARCHAR(255) NULL",
        'security_answer' => "VARCHAR(255) NULL"
    ],
    'employees' => [
        'system_role' => "VARCHAR(50) DEFAULT 'Staff'",
        'last_reminded' => "DATETIME NULL",
        'exit_date' => "DATE NULL",
        'exit_reason' => "VARCHAR(255) NULL",
        'updated_at' => "DATETIME NULL",
        'deleted_at' => "DATETIME NULL",
        'import_batch' => "VARCHAR(50) NULL",
        'agency_name' => "VARCHAR(100) NULL",
        'employment_type' => "VARCHAR(50) NULL"
    ],
    'documents' => [
        'deleted_at' => "DATETIME NULL",
        'updated_at' => "DATETIME NULL",
        'updated_by' => "INT NULL",
        'uploaded_by' => "INT NULL",
        'is_resolved' => "TINYINT(1) DEFAULT 0",
        'resolution_note' => "TEXT NULL"
    ],
    'disciplinary_cases' => [
        'violation_type' => "VARCHAR(255) NOT NULL",
        'rule_violated' => "VARCHAR(255) NULL AFTER violation_type"
    ],
    'candidates' => [
        'email' => "VARCHAR(100) NULL",
        'phone_number' => "VARCHAR(25) NULL",
        'rejection_reason' => "TEXT NULL",
        'interview_date' => "DATETIME NULL",
        'is_blacklisted' => "TINYINT(1) DEFAULT 0"
    ],
    'maintenance_logs' => [
        'vendor_name' => "VARCHAR(100) NULL",
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'Pending'"
    ]
];

// 2. New Tables to Create
$tableSchema = [
    'document_requirements' => "CREATE TABLE IF NOT EXISTS `document_requirements` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `keywords` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'document_exemptions' => "CREATE TABLE IF NOT EXISTS `document_exemptions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` VARCHAR(50) NOT NULL,
        `requirement_name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_exemption` (`employee_id`, `requirement_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'rate_limits' => "CREATE TABLE IF NOT EXISTS `rate_limits` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ip_address` VARCHAR(45) NOT NULL,
        `request_count` INT DEFAULT 1,
        `last_request` DATETIME NOT NULL,
        UNIQUE KEY `idx_ip` (`ip_address`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'maintenance_logs' => "CREATE TABLE IF NOT EXISTS `maintenance_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` VARCHAR(32) NOT NULL,
        `equipment_type` VARCHAR(50) NOT NULL,
        `issue` VARCHAR(255) NOT NULL,
        `action_taken` TEXT NOT NULL,
        `maintenance_date` DATE NOT NULL,
        `performed_by` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_emp` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'maintenance_actions' => "CREATE TABLE IF NOT EXISTS `maintenance_actions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NULL,
        `action` VARCHAR(100) NOT NULL,
        `target_type` VARCHAR(50) NULL,
        `target_id` VARCHAR(100) NULL,
        `details` TEXT NULL,
        `ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'hr_performance_reviews' => "CREATE TABLE IF NOT EXISTS `hr_performance_reviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `reviewer_id` INT NOT NULL,
        `custom_reviewer` VARCHAR(100) NULL,
        `review_date` DATE NOT NULL,
        `rating` INT NOT NULL DEFAULT 3,
        `strengths` TEXT NULL,
        `weaknesses` TEXT NULL,
        `goals` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_emp_id` (`employee_id`),
        KEY `idx_reviewer_id` (`reviewer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'performance_evaluations' => "CREATE TABLE IF NOT EXISTS `performance_evaluations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `eval_date` DATE NOT NULL,
        `score` INT NOT NULL,
        `rating` VARCHAR(50) NOT NULL,
        `remarks` TEXT,
        `evaluator` VARCHAR(100),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_emp_eval` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'disciplinary_violations' => "CREATE TABLE IF NOT EXISTS `disciplinary_violations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category` VARCHAR(50) NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT NULL,
        UNIQUE KEY `unique_viol` (`category`, `name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'company_rules' => "CREATE TABLE IF NOT EXISTS `company_rules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL UNIQUE,
        `description` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'disciplinary_cases' => "CREATE TABLE IF NOT EXISTS `disciplinary_cases` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` VARCHAR(50) NOT NULL,
        `violation_type` VARCHAR(100) NOT NULL,
        `incident_date` DATE NOT NULL,
        `action_taken` VARCHAR(100) NOT NULL,
        `description` TEXT,
        `attachment_path` VARCHAR(255),
        `status` VARCHAR(20) DEFAULT 'Open',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_emp_case` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'system_settings' => "CREATE TABLE IF NOT EXISTS `system_settings` (
        `setting_key` VARCHAR(50) PRIMARY KEY,
        `setting_value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'activity_logs' => "CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `action` VARCHAR(50) NOT NULL,
        `details` TEXT,
        `ip_address` VARCHAR(45),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'requests' => "CREATE TABLE IF NOT EXISTS `requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `request_type` VARCHAR(50) NOT NULL,
        `target_id` INT DEFAULT 0,
        `json_payload` LONGTEXT,
        `status` VARCHAR(20) DEFAULT 'PENDING',
        `admin_comment` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'notifications' => "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `title` VARCHAR(100) NOT NULL,
        `message` TEXT NOT NULL,
        `type` VARCHAR(20) DEFAULT 'info',
        `is_read` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'password_history' => "CREATE TABLE IF NOT EXISTS `password_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ph_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'access_reviews' => "CREATE TABLE IF NOT EXISTS `access_reviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `reviewed_user_id` INT NOT NULL,
        `reviewer_id` INT NOT NULL,
        `review_date` DATE NOT NULL,
        `status` VARCHAR(20) NOT NULL,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'employment_history' => "CREATE TABLE IF NOT EXISTS `employment_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `event_title` VARCHAR(100) NOT NULL,
        `event_date` DATE NOT NULL,
        `department` VARCHAR(100),
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_emp_hist` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'import_rollbacks' => "CREATE TABLE IF NOT EXISTS `import_rollbacks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `import_batch` VARCHAR(50) NOT NULL,
        `old_data` LONGTEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_batch` (`import_batch`),
        KEY `idx_emp` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'candidates' => "CREATE TABLE IF NOT EXISTS `candidates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `first_name` VARCHAR(50) NOT NULL,
        `last_name` VARCHAR(50) NOT NULL,
        `position_applied` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NULL,
        `phone_number` VARCHAR(25) NULL,
        `rejection_reason` TEXT NULL,
        `interview_date` DATETIME NULL,
        `is_blacklisted` TINYINT(1) DEFAULT 0,
        `status` VARCHAR(50) DEFAULT 'New Applicant',
        `application_date` DATE NOT NULL,
        `last_follow_up` DATE DEFAULT NULL,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'courses_catalog' => "CREATE TABLE IF NOT EXISTS `courses_catalog` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL UNIQUE,
        `category` VARCHAR(50) NOT NULL,
        `provider` VARCHAR(100) NULL,
        `validity_months` INT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    'employee_training' => "CREATE TABLE IF NOT EXISTS `employee_training` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` VARCHAR(50) NOT NULL,
        `course_id` INT NOT NULL,
        `completion_date` DATE NOT NULL,
        `expiry_date` DATE NULL,
        `certificate_path` VARCHAR(255) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`course_id`) REFERENCES `courses_catalog`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

// 3. New Indexes to Create (Performance)
$indexSchema = [
    'employees' => [
        'idx_emp_id' => "INDEX `idx_emp_id` (`emp_id`)",
        'idx_status_dept' => "INDEX `idx_status_dept` (`status`, `dept`)",
        'idx_deleted_at' => "INDEX `idx_deleted_at` (`deleted_at`)"
    ],
    'documents' => [
        'idx_doc_emp' => "INDEX `idx_doc_emp` (`employee_id`)",
        'idx_doc_file_uuid' => "INDEX `idx_doc_file_uuid` (`file_uuid`)",
        'idx_doc_deleted' => "INDEX `idx_doc_deleted` (`deleted_at`)"
    ],
    'requests' => [
        'idx_req_status' => "INDEX `idx_req_status` (`status`)"
    ],
    'activity_logs' => [
        'idx_log_action_time' => "INDEX `idx_log_action_time` (`action`, `created_at`)"
    ]
];

// ------------------------------------------------------
// HANDLE AUTO-FIX
// ------------------------------------------------------
$dryRunLogs = []; // Store dry run results

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['auto_fix']) || isset($_POST['dry_run']))) {
    // CSRF Validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }

    $updates = 0;
    $errors = [];
    $isDryRun = isset($_POST['dry_run']);

    // 1. Create Missing Tables
    foreach ($tableSchema as $tableName => $sql) {
        try {
            // Check if table exists
            $check = $pdo->query("SHOW TABLES LIKE '$tableName'");
            if ($check->rowCount() == 0) {
                if ($isDryRun) {
                    $dryRunLogs[] = "[CREATE TABLE] $tableName";
                } else {
                    $pdo->exec($sql);
                    $updates++;
                }
            }
        } catch (Exception $e) {
            $errors[] = "Failed to create $tableName: " . $e->getMessage();
        }
    }

    // 2. Add Missing Columns
    foreach ($columnSchema as $table => $cols) {
        foreach ($cols as $col => $def) {
            try {
                // Check if column exists
                $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                if ($stmt->rowCount() == 0) {
                    if ($isDryRun) {
                        $dryRunLogs[] = "[ADD COLUMN] ALTER TABLE `$table` ADD COLUMN `$col` $def";
                    } else {
                        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                        $updates++;
                    }
                }
            } catch (Exception $e) {
                // Ignore if table doesn't exist (handled by step 1)
                if (strpos($e->getMessage(), "doesn't exist") === false) {
                    $errors[] = "Failed to add $col to $table: " . $e->getMessage();
                }
            }
        }
    }

    // 3. Add Missing Indexes
    foreach ($indexSchema as $table => $indexes) {
        foreach ($indexes as $idxName => $def) {
            try {
                $stmt = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$idxName'");
                if ($stmt->rowCount() == 0) {
                    if ($isDryRun) {
                        $dryRunLogs[] = "[ADD INDEX] ALTER TABLE `$table` ADD $def";
                    } else {
                        $pdo->exec("ALTER TABLE `$table` ADD $def");
                        $updates++;
                    }
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "doesn't exist") === false) {
                    $errors[] = "Failed to add index $idxName to $table: " . $e->getMessage();
                }
            }
        }
    }

    // 4. Seed Default Settings
    if (!$isDryRun) {
        try {
            $defaultSettings = [
                'session_timeout_server' => '1800',
                'session_timeout_client' => '900',
                'auto_refresh_interval' => '60',
                'company_president' => 'JUNJI FURUYA',
                'vault_size_limit_gb' => '1',
                'backup_day' => 'Fri',
                'backup_time' => '00:00',
                'backup_path' => '',
                'secondary_backup_path' => '',
                'backup_max_size_gb' => '1.9',
                'backup_include_vault' => '0',
                'backup_alert_email' => '',
                'maintenance_mode' => '0',
                'staff_direct_approval' => '0',
                'default_project_name' => 'MRT-3 REHABILITATION PROJECT',
                'default_notice_place' => 'QUEZON CITY',
                'bulk_margin_left' => '30',
                'bulk_margin_right' => '20',
                'document_font_size' => '11',
                'approval_widgets' => '["hires","edits","docs","doc-edits","tickets"]'
            ];
            $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            foreach ($defaultSettings as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            $updates++;
        } catch (Exception $e) {
            // Table might not exist yet if create failed
        }
    }

    if ($isDryRun) {
        if (empty($dryRunLogs)) {
            $msg = "ℹ️ Simulation complete. No missing schema elements detected.";
        } else {
            $msg = "ℹ️ Simulation complete. Found " . count($dryRunLogs) . " missing elements.";
        }
    } else {
        if (empty($errors)) {
            $msg = "✅ Database updated! ($updates changes applied)";
        } else {
            $safeErrors = array_map(function ($e) {
                return htmlspecialchars($e, ENT_QUOTES, 'UTF-8');
            }, $errors);
            $msg = "⚠️ Update completed with errors:<br>" . implode("<br>", $safeErrors);
        }
    }
}

// ------------------------------------------------------
// PRE-SCAN FOR STATUS SUMMARY
// ------------------------------------------------------
$issuesCount = 0;
foreach ($tableSchema as $table => $sql) {
    try {
        $res = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($res->rowCount() == 0) $issuesCount++;
    } catch (Exception $e) {
    }
}
foreach ($columnSchema as $table => $cols) {
    foreach ($cols as $col => $def) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
            if ($stmt->rowCount() == 0) $issuesCount++;
        } catch (Exception $e) {
        }
    }
}

foreach ($indexSchema as $table => $indexes) {
    foreach ($indexes as $idxName => $def) {
        try {
            $stmt = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$idxName'");
            if ($stmt->rowCount() == 0) $issuesCount++;
        } catch (Exception $e) {
        }
    }
}
?>
<?php include 'header.php'; ?>

<style>
    /* =========================================
           PRINT STYLES FOR COMPLIANCE REPORT
           ========================================= */
    @media print {
        @page {
            size: portrait;
            margin: 0.5in;
        }

        nav,
        .btn,
        form,
        .alert-info {
            display: none !important;
        }

        body {
            background: white !important;
        }

        .container {
            width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
        }

        body::before {
            content: "Database Schema Compliance Report - v<?php echo $masterVersion; ?>";
            display: block;
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 20px;
            border-bottom: 2px solid #666;
            padding-bottom: 10px;
        }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    }
</style>

<div class="container">
    <div class="mb-4 text-end">
        <small class="text-muted">Master Schema Version: <strong><?php echo $masterVersion; ?></strong></small>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-info"><?php echo $msg; ?></div>
    <?php endif; ?>

    <?php if ($issuesCount === 0): ?>
        <div class="alert alert-success shadow-sm"><i class="bi bi-check-circle-fill"></i> <strong>System is Up-to-Date.</strong> Your database matches the master schema.</div>
    <?php else: ?>
        <div class="alert alert-warning shadow-sm"><i class="bi bi-exclamation-triangle-fill"></i> <strong>Updates Available.</strong> Found <?php echo $issuesCount; ?> missing items.</div>
    <?php endif; ?>

    <!-- DRY RUN RESULTS DISPLAY -->
    <?php if (!empty($dryRunLogs)): ?>
        <div class="alert alert-info border-info shadow-sm mb-4">
            <h5 class="alert-heading"><i class="bi bi-terminal"></i> Simulation Results (Dry Run)</h5>
            <p class="mb-2 text-dark">The following SQL changes <strong>would be applied</strong> if you run Auto-Fix. No data has been modified.</p>
            <div class="bg-dark text-light p-3 rounded font-monospace small" style="max-height: 250px; overflow-y: auto;">
                <?php foreach ($dryRunLogs as $log): ?>
                    <div><?php echo htmlspecialchars($log); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- PRODUCTION LAUNCH CHECKLIST -->
    <div class="card shadow-sm mb-4 border-primary">
        <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-rocket-takeoff-fill"></i> Final Launch Checklist</span>
            <span class="badge bg-light text-primary">Pre-Flight Checks</span>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush">
                <!-- 1. Dev Files -->
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>1. Delete Development Files</strong><br>
                        <small class="text-muted">Ensure installation and backdoor scripts are removed.</small>
                    </div>
                    <?php if (!$devFilesExist): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-check-circle-fill"></i> Secure</span>
                    <?php else: ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-danger rounded-pill px-3 py-2"><i class="bi bi-x-circle-fill"></i> Files Found</span>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete all development files permanently?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" name="cleanup_dev_files" class="btn btn-sm btn-danger py-0"><i class="bi bi-trash"></i> Fix Now</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </li>
                <!-- 2. Error Reporting -->
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>2. Lock Down config/db.php</strong><br>
                        <small class="text-muted">Ensure <code>display_errors = 0</code> to prevent path leakage.</small>
                    </div>
                    <?php if ($errorsOff): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-check-circle-fill"></i> Hidden</span>
                    <?php else: ?>
                        <span class="badge bg-danger rounded-pill px-3 py-2"><i class="bi bi-x-circle-fill"></i> Exposed</span>
                    <?php endif; ?>
                </li>
                <!-- 3. Vault Key -->
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>3. Generate Final VAULT_KEY</strong><br>
                        <small class="text-muted">Ensure AES-256 encryption key is set and not default.</small>
                    </div>
                    <?php if ($keySecure): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-check-circle-fill"></i> Secure</span>
                    <?php else: ?>
                        <span class="badge bg-danger rounded-pill px-3 py-2"><i class="bi bi-x-circle-fill"></i> Default Key</span>
                    <?php endif; ?>
                </li>
                <!-- 4. Permissions -->
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>4. Set Directory Permissions</strong><br>
                        <small class="text-muted">Verify Write access for <code>vault/</code>, <code>uploads/</code>, and <code>backups/</code>.</small>
                    </div>
                    <?php if ($permsOk): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-check-circle-fill"></i> Writable</span>
                    <?php else: ?>
                        <span class="badge bg-danger rounded-pill px-3 py-2"><i class="bi bi-x-circle-fill"></i> Permission Denied</span>
                    <?php endif; ?>
                </li>
                <!-- 5. HTTPS -->
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>5. Enforce HTTPS (SSL)</strong><br>
                        <small class="text-muted">Verify active SSL certificate to protect network traffic.</small>
                    </div>
                    <?php if ($isHttps): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-check-circle-fill"></i> Encrypted</span>
                    <?php elseif ($isLocal): ?>
                        <span class="badge bg-info text-dark rounded-pill px-3 py-2"><i class="bi bi-info-circle-fill"></i> Localhost</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark rounded-pill px-3 py-2"><i class="bi bi-exclamation-triangle-fill"></i> Insecure (HTTP)</span>
                    <?php endif; ?>
                </li>
                <!-- 6. Cron Jobs -->
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>6. Activate Automated Backups</strong><br>
                        <small class="text-muted">Configure Windows Task Scheduler to run <code>cron_backup.php</code></small>
                    </div>
                    <span class="badge bg-secondary rounded-pill px-3 py-2"><i class="bi bi-search"></i> Manual Check Req.</span>
                </li>
            </ul>
        </div>
    </div>

    <!-- SCHEMA VERIFICATION -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span>Schema Verification</span>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="bi bi-printer"></i> Print Report</button>
                <a href="db_status.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-repeat"></i> Check for Updates</a>
                <?php if ($issuesCount > 0): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" name="dry_run" value="1" class="btn btn-sm btn-info text-white fw-bold me-1">
                            <i class="bi bi-eye"></i> Simulate
                        </button>
                        <button type="submit" name="auto_fix" value="1" class="btn btn-sm btn-success fw-bold">
                            <i class="bi bi-magic"></i> Auto-Fix All Issues
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Table / Column</th>
                        <th>Status</th>
                        <th>Action Required</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- CHECK TABLES -->
                    <tr class="table-secondary">
                        <td colspan="3" class="fw-bold">Tables</td>
                    </tr>
                    <?php foreach ($tableSchema as $table => $sql):
                        $exists = false;
                        try {
                            $res = $pdo->query("SHOW TABLES LIKE '$table'");
                            $exists = ($res->rowCount() > 0);
                        } catch (Exception $e) {
                        }
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($table); ?></td>
                            <td>
                                <?php if ($exists): ?>
                                    <span class="badge bg-success">Exists</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $exists ? 'None' : 'Click Auto-Fix'; ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- CHECK COLUMNS -->
                    <tr class="table-secondary">
                        <td colspan="3" class="fw-bold">Columns</td>
                    </tr>
                    <?php foreach ($columnSchema as $table => $cols):
                        foreach ($cols as $col => $def):
                            $colExists = false;
                            $tableExists = true;
                            try {
                                $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                                $colExists = ($stmt->rowCount() > 0);
                            } catch (Exception $e) {
                                $tableExists = false;
                            }
                    ?>
                            <tr>
                                <td><?php echo htmlspecialchars("$table.$col"); ?></td>
                                <td>
                                    <?php if (!$tableExists): ?>
                                        <span class="badge bg-secondary">Table Missing</span>
                                    <?php elseif ($colExists): ?>
                                        <span class="badge bg-success">Exists</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ($tableExists && !$colExists) ? 'Click Auto-Fix' : 'None'; ?></td>
                            </tr>
                    <?php endforeach;
                    endforeach; ?>

                    <!-- CHECK INDEXES -->
                    <tr class="table-secondary">
                        <td colspan="3" class="fw-bold"><i class="bi bi-lightning-charge-fill text-warning"></i> Indexes (Performance)</td>
                    </tr>
                    <?php foreach ($indexSchema as $table => $indexes):
                        foreach ($indexes as $idxName => $def):
                            $idxExists = false;
                            $tableExists = true;
                            try {
                                $stmt = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$idxName'");
                                $idxExists = ($stmt->rowCount() > 0);
                            } catch (Exception $e) {
                                $tableExists = false;
                            }
                    ?>
                            <tr>
                                <td><?php echo htmlspecialchars("$table.$idxName"); ?></td>
                                <td>
                                    <?php if (!$tableExists): ?>
                                        <span class="badge bg-secondary">Table Missing</span>
                                    <?php elseif ($idxExists): ?>
                                        <span class="badge bg-success">Exists</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ($tableExists && !$idxExists) ? 'Click Auto-Fix' : 'None'; ?></td>
                            </tr>
                    <?php endforeach;
                    endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>