<?php
// --- START: Setup Automation ---

/**
 * [FILE] public/auto_setup.php
 * [PURPOSE] Automated database initialization for local environment migration.
 * [SECURITY] RESTRICTED TO LOCALHOST. DELETE AFTER USE.
 */

// 1. SECURITY GATE: Localhost only
$allowed_ips = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips, true)) {
    die("Access Denied: This setup script can only be run from localhost for security reasons.");
}

require_once __DIR__ . '/../config/db.php';

$message = "";
$status = "info";

try {
    // 2. DATABASE SETUP: Build tables from schema.sql
    clearstatcache();
    $rootPath = dirname(__DIR__);
    $schemaPath = $rootPath . DIRECTORY_SEPARATOR . 'schema.sql';

    $sql = "";
    $message_suffix = "";

    // Try to load from external file
    if (file_exists($schemaPath)) {
        $sql = @file_get_contents($schemaPath);
    }

    if ($sql === false || trim($sql) === '') {
        // Fallback: Internal Core Schema to allow Admin login and use db_status.php for full repair
        $sql = "
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(50) NOT NULL UNIQUE,
                `password` VARCHAR(255) NOT NULL,
                `role` VARCHAR(20) DEFAULT 'STAFF',
                `email` VARCHAR(100) DEFAULT NULL,
                `is_2fa_enabled` TINYINT(1) DEFAULT 0,
                `totp_secret` VARCHAR(255) DEFAULT NULL,
                `recovery_codes` TEXT DEFAULT NULL,
                `failed_attempts` INT DEFAULT 0,
                `locked_until` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `system_settings` (
                `setting_key` VARCHAR(50) PRIMARY KEY,
                `setting_value` TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            INSERT IGNORE INTO `users` (username, password, role) 
            VALUES ('admin', '" . password_hash('Admin@12345', PASSWORD_BCRYPT) . "', 'ADMIN');
        ";
        $message_suffix = " (Using internal core fallback)";
    }

    // Execute the schema
    $pdo->exec($sql);

    // 3. RECOVERY: Reset Admin account for local migration recovery
    $tempPass = password_hash('Admin@12345', PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password = ?, is_2fa_enabled = 0, totp_secret = NULL, failed_attempts = 0, locked_until = NULL, password_changed_at = NOW() WHERE role = 'ADMIN'")->execute([$tempPass]);

    $message = "✅ Database initialized" . $message_suffix . " and Admin account has been reset (Password: Admin@12345, 2FA Disabled).";
    $status = "success";
} catch (Exception $e) {
    $message = "❌ Error during setup: " . $e->getMessage();
    $status = "danger";
}

// 3. USER FEEDBACK: Clean HTML Output
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>HR Vault 201 - System Setup</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        body {
            background-color: #f4f6f9;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .setup-card {
            max-width: 500px;
            width: 100%;
            border: none;
            border-radius: 12px;
        }
    </style>
</head>

<body>
    <div class="card setup-card shadow-lg">
        <div class="card-header bg-dark text-white text-center py-3">
            <h4 class="mb-0"><i class="bi bi-gear-fill"></i> Environment Bootstrap</h4>
        </div>
        <div class="card-body p-4 text-center">
            <div class="alert alert-<?= $status ?> mb-4">
                <?= $message ?>
            </div>

            <?php if ($status === 'success'): ?>
                <div class="alert alert-warning small text-start">
                    <i class="bi bi-exclamation-triangle-fill"></i> <b>Administrative Note:</b>
                    <p class="mb-0 mt-1">For account recovery on this local instance, you can manually reset the 2FA secret for an account by clearing the <code>totp_secret</code> column in the <code>users</code> table via your database console.</p>
                </div>

                <div class="d-grid mt-4">
                    <a href="login.php" class="btn btn-primary btn-lg">Go to Login <i class="bi bi-box-arrow-in-right"></i></a>
                </div>
                <p class="text-danger fw-bold mt-3 small"><i class="bi bi-trash"></i> DELETE THIS FILE (auto_setup.php) IMMEDIATELY!</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
<?php // --- END: Setup Automation --- 
?>