<?php
// ======================================================
// [FILE] public/db_status.php
// [PURPOSE] Automated Database Schema Verification & Fixer
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: ADMIN ONLY
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$msg = "";
$masterVersion = "2.1.0"; // Master Schema Version

// ------------------------------------------------------
// SCHEMA DEFINITIONS
// ------------------------------------------------------

// 1. Existing Tables needing New Columns
$columnSchema = [
    'users' => [
        'is_2fa_enabled' => "TINYINT(1) DEFAULT 0",
        'otp_code' => "VARCHAR(6) NULL",
        'otp_expires' => "DATETIME NULL",
        'last_otp_sent' => "DATETIME NULL",
        'password_changed_at' => "DATETIME NULL",
        'trusted_device_token' => "VARCHAR(64) NULL DEFAULT NULL",
        'trusted_device_expires' => "DATETIME NULL DEFAULT NULL",
        'reset_token' => "VARCHAR(64) NULL DEFAULT NULL",
        'reset_expires' => "DATETIME NULL DEFAULT NULL"
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
        'is_resolved' => "TINYINT(1) DEFAULT 0",
        'resolution_note' => "TEXT NULL"
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

// ------------------------------------------------------
// HANDLE AUTO-FIX
// ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_fix'])) {
    $updates = 0;
    $errors = [];

    // 1. Create Missing Tables
    foreach ($tableSchema as $tableName => $sql) {
        try {
            // Check if table exists
            $check = $pdo->query("SHOW TABLES LIKE '$tableName'");
            if ($check->rowCount() == 0) {
                $pdo->exec($sql);
                $updates++;
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
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                    $updates++;
                }
            } catch (Exception $e) {
                // Ignore if table doesn't exist (handled by step 1)
                if (strpos($e->getMessage(), "doesn't exist") === false) {
                    $errors[] = "Failed to add $col to $table: " . $e->getMessage();
                }
            }
        }
    }

    if (empty($errors)) {
        $msg = "✅ Database updated! ($updates changes applied)";
    } else {
        $msg = "⚠️ Update completed with errors:<br>" . implode("<br>", $errors);
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Database Status</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-0"><i class="bi bi-database-check text-primary"></i> Database Status</h3>
                <small class="text-muted">Master Schema Version: <strong><?php echo $masterVersion; ?></strong></small>
            </div>
            <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-info"><?php echo $msg; ?></div>
        <?php endif; ?>

        <?php if ($issuesCount === 0): ?>
            <div class="alert alert-success shadow-sm"><i class="bi bi-check-circle-fill"></i> <strong>System is Up-to-Date.</strong> Your database matches the master schema.</div>
        <?php else: ?>
            <div class="alert alert-warning shadow-sm"><i class="bi bi-exclamation-triangle-fill"></i> <strong>Updates Available.</strong> Found <?php echo $issuesCount; ?> missing items.</div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span>Schema Verification</span>
                <div class="d-flex gap-2">
                    <a href="db_status.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-repeat"></i> Check for Updates</a>
                    <?php if ($issuesCount > 0): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="auto_fix" class="btn btn-sm btn-success fw-bold">
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
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>