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
    $schemaPath = realpath(__DIR__ . '/../schema.sql');
    if (!$schemaPath || !file_exists($schemaPath)) {
        throw new Exception("Schema file (schema.sql) not found in the root directory.");
    }

    $sql = @file_get_contents($schemaPath);
    if ($sql === false || trim($sql) === '') {
        throw new Exception("Failed to read schema.sql. Check if the file is empty or if your Windows user has restricted read permissions on the file.");
    }

    // Execute the schema
    $pdo->exec($sql);

    // 3. 2FA BYPASS: Reset Admin account for local migration recovery
    $pdo->exec("UPDATE users SET is_2fa_enabled = 0, totp_secret = NULL WHERE role = 'ADMIN'");

    $message = "✅ Database initialized and Admin 2FA has been disabled for recovery.";
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