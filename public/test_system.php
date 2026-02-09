<?php
// public/test_system.php
// Run this in your browser: http://localhost/hr 201/public/test_system.php

require '../config/db.php';
session_start(); // [NEW] Start session for Admin checks

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Health Check</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light p-5">
    <div class="container bg-white p-5 rounded shadow-sm">
        <h2 class="mb-4 border-bottom pb-2"><i class="bi bi-tools text-primary"></i> System Health & QA Check</h2>

        <?php
        // 1. DATABASE CONNECTION
        if ($pdo) {
            echo "<div class='alert alert-success'><i class='bi bi-check-circle-fill'></i> Database Connection: <strong>SUCCESS</strong></div>";
        } else {
            die("<div class='alert alert-danger'><i class='bi bi-x-circle-fill'></i> Database Connection: <strong>FAILED</strong></div>");
        }

        // 2. CHECK REQUIRED COLUMNS
        $checks = [
            'users' => ['is_2fa_enabled', 'otp_code', 'password_changed_at', 'trusted_device_token', 'trusted_device_expires'],
            'employees' => ['system_role', 'last_reminded', 'exit_date', 'exit_reason', 'updated_at'],
            'documents' => ['deleted_at', 'updated_at', 'updated_by']
        ];

        echo "<h5 class='mt-4'><i class='bi bi-table'></i> Database Schema Check</h5><ul class='list-group mb-3'>";
        foreach ($checks as $table => $columns) {
            foreach ($columns as $col) {
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE '$col'");
                    if ($stmt->rowCount() > 0) {
                        echo "<li class='list-group-item list-group-item-success'><i class='bi bi-check-lg'></i> Table <code>$table</code> has column <code>$col</code></li>";
                    } else {
                        echo "<li class='list-group-item list-group-item-danger'><i class='bi bi-exclamation-triangle-fill'></i> Table <code>$table</code> is MISSING column <code>$col</code> (Run SQL Script!)</li>";
                    }
                } catch (Exception $e) {
                    echo "<li class='list-group-item list-group-item-danger'><i class='bi bi-exclamation-triangle-fill'></i> Table <code>$table</code> does not exist!</li>";
                }
            }
        }
        echo "</ul>";

        // 3. CHECK NEW TABLES
        $tables = ['document_exemptions', 'rate_limits', 'document_requirements'];
        echo "<h5 class='mt-4'><i class='bi bi-database-add'></i> New Tables Check</h5><ul class='list-group mb-3'>";
        foreach ($tables as $t) {
            try {
                $pdo->query("SELECT 1 FROM $t LIMIT 1");
                echo "<li class='list-group-item list-group-item-success'><i class='bi bi-check-lg'></i> Table <code>$t</code> exists.</li>";
            } catch (Exception $e) {
                echo "<li class='list-group-item list-group-item-danger'><i class='bi bi-exclamation-triangle-fill'></i> Table <code>$t</code> is MISSING!</li>";
            }
        }
        echo "</ul>";

        // 4. CHECK ROLES LOGIC
        echo "<h5 class='mt-4'><i class='bi bi-person-badge'></i> Logic Check: Roles</h5>";
        try {
            // Try to insert a dummy employee with role 'Driver' to see if ENUM/VARCHAR allows it
            $pdo->beginTransaction();
            $dummyId = 'TEST-DRIVER-' . time();
            $sql = "INSERT INTO employees (emp_id, first_name, last_name, dept, system_role) VALUES (?, 'Test', 'Driver', 'ADMIN', 'Driver')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$dummyId]);

            echo "<div class='alert alert-success'><i class='bi bi-check-circle-fill'></i> Database accepts 'Driver' role (Insert Successful).</div>";

            // Clean up
            $pdo->rollBack();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='alert alert-danger'><i class='bi bi-x-circle-fill'></i> Database REJECTED 'Driver' role. Error: " . $e->getMessage() . "</div>";
            echo "<p><em>Hint: You might need to update the `system_role` column to VARCHAR(50) or update the ENUM list.</em></p>";
        }

        // 5. CHECK FILE PERMISSIONS
        echo "<h5 class='mt-4'><i class='bi bi-folder2-open'></i> File System Check</h5>";
        $uploadDir = __DIR__ . '/uploads';
        if (is_writable($uploadDir)) {
            echo "<div class='alert alert-success'><i class='bi bi-check-circle-fill'></i> Uploads folder is writable.</div>";
        } else {
            echo "<div class='alert alert-danger'><i class='bi bi-x-circle-fill'></i> Uploads folder is NOT writable. Check permissions.</div>";
        }

        // 6. CHECK PHP EXTENSIONS
        echo "<h5 class='mt-4'><i class='bi bi-puzzle'></i> PHP Extensions</h5>";
        $exts = ['zip', 'gd', 'pdo_mysql', 'fileinfo'];
        echo "<ul class='list-group mb-3'>";
        foreach ($exts as $ext) {
            if (extension_loaded($ext)) {
                echo "<li class='list-group-item list-group-item-success'><i class='bi bi-check-lg'></i> Extension <code>$ext</code> is loaded.</li>";
            } else {
                echo "<li class='list-group-item list-group-item-danger'><i class='bi bi-exclamation-triangle-fill'></i> Extension <code>$ext</code> is MISSING.</li>";
            }
        }
        echo "</ul>";

        // 7. CHECK ASSETS (OFFLINE MODE)
        echo "<h5 class='mt-4'><i class='bi bi-box-seam'></i> Offline Assets Diagnostic</h5>";
        $assetDir = __DIR__ . '/assets';
        $requiredFiles = [
            'bootstrap.min.css',
            'bootstrap.bundle.min.js',
            'sweetalert2.all.min.js',
            'chart.min.js'
        ];

        // Normalize slashes for display
        $displayDir = str_replace('/', '\\', $assetDir);

        if (!is_dir($assetDir)) {
            echo "<div class='alert alert-danger'>
                <i class='bi bi-exclamation-triangle-fill'></i> <strong>'assets' Folder Not Found!</strong><br>
                PHP looked for: <code>" . htmlspecialchars($displayDir) . "</code><br>
                <hr>
                <strong>Current contents of 'public' folder:</strong><br><ul>";

            // List public folder to help user find where they put it
            $publicFiles = scandir(__DIR__);
            foreach ($publicFiles as $f) {
                if ($f !== '.' && $f !== '..' && is_dir(__DIR__ . '/' . $f)) {
                    echo "<li>📂 " . htmlspecialchars($f) . "</li>";
                }
            }
            echo "</ul></div>";
        } else {
            // Folder exists, check specific file
            $missing = [];
            foreach ($requiredFiles as $f) {
                if (!file_exists($assetDir . '/' . $f)) {
                    $missing[] = $f;
                }
            }
            // Check icons specifically
            if (!file_exists($assetDir . '/icons/bootstrap-icons.css')) {
                $missing[] = 'icons/bootstrap-icons.css';
            }
            if (!file_exists($assetDir . '/icons/fonts/bootstrap-icons.woff')) {
                $missing[] = 'icons/fonts/bootstrap-icons.woff (Required for icons)';
            }
            if (!file_exists($assetDir . '/icons/fonts/bootstrap-icons.woff2')) {
                $missing[] = 'icons/fonts/bootstrap-icons.woff2 (Required for icons)';
            }

            if (empty($missing)) {
                echo "<div class='alert alert-success'><i class='bi bi-check-circle-fill'></i> <strong>All Assets Found!</strong><br>CSS, JS, and Fonts are present. System is ready for offline use.<br><small>To verify: Disconnect your internet and refresh the dashboard.</small></div>";
            } else {
                echo "<div class='alert alert-warning'>
                <i class='bi bi-info-circle-fill'></i> <strong>'assets' folder exists, but some files are missing.</strong><br>
                Missing: <ul>" . implode('', array_map(fn($m) => "<li>❌ $m</li>", $missing)) . "</ul>
                <hr>
                <strong>Files actually found inside 'assets':</strong><br><ul>";

                $files = scandir($assetDir);
                $foundAny = false;
                foreach ($files as $f) {
                    if ($f !== '.' && $f !== '..') {
                        $foundAny = true;
                        $type = is_dir($assetDir . '/' . $f) ? '📂 Folder' : '📄 File';
                        echo "<li>$type: " . htmlspecialchars($f) . "</li>";
                    }
                }
                if (!$foundAny) echo "<li><em>(Folder is empty)</em></li>";
                echo "</ul></div>";

                // [NEW] Link to Downloader
                echo "<div class='mt-3'><a href='download_assets.php' class='btn btn-primary'><i class='bi bi-cloud-download'></i> Attempt Auto-Download Assets</a></div>";
            }
        }

        // 8. VAULT SECURITY CHECK
        echo "<h5 class='mt-4'><i class='bi bi-shield-lock'></i> Vault Security Check</h5>";
        $vaultDir = __DIR__ . '/../vault';
        $htaccess = $vaultDir . '/.htaccess';

        // Handle Fix Action
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_vault'])) {
            if (!is_dir($vaultDir)) mkdir($vaultDir, 0755, true);
            file_put_contents($htaccess, "Order Deny,Allow\nDeny from all");
            echo "<div class='alert alert-success'>✅ <strong>Fixed:</strong> Created .htaccess in vault. Refreshing...</div><meta http-equiv='refresh' content='2'>";
        }

        if (!is_dir($vaultDir)) {
            echo "<div class='alert alert-warning'>
                <i class='bi bi-exclamation-circle'></i> <strong>Vault Directory Missing</strong><br>
                It will be created automatically when you upload the first document.
            </div>";
        } else {
            if (file_exists($htaccess)) {
                $content = file_get_contents($htaccess);
                if (strpos($content, 'Deny from all') !== false) {
                    echo "<div class='alert alert-success'><i class='bi bi-check-circle-fill'></i> <strong>Vault Secured:</strong> Access is blocked via .htaccess.</div>";
                } else {
                    echo "<div class='alert alert-danger'>
                        <i class='bi bi-exclamation-triangle-fill'></i> <strong>Vault Vulnerable!</strong><br>
                        .htaccess exists but does not contain 'Deny from all'.
                        <form method='POST' class='mt-2'><input type='hidden' name='fix_vault' value='1'><button class='btn btn-sm btn-danger'>🔒 Fix Security</button></form>
                    </div>";
                }
            } else {
                echo "<div class='alert alert-danger'>
                    <i class='bi bi-exclamation-triangle-fill'></i> <strong>Vault Unsecured!</strong><br>
                    Files in <code>/vault/</code> might be accessible via direct URL.
                    <form method='POST' class='mt-2'><input type='hidden' name='fix_vault' value='1'><button class='btn btn-sm btn-danger'>🔒 Secure Vault Now</button></form>
                </div>";
            }
        }

        echo "<hr>";
        echo "<h6>Next Steps:</h6>";
        echo "<ol>";
        echo "<li>If you see any errors above, run the SQL script provided in the previous chat.</li>";
        echo "<li>If everything is GREEN, proceed with the Manual Testing Checklist (A-E).</li>";
        echo "<li><strong>Delete this file (`test_system.php`) before going live!</strong></li>";
        echo "</ol>";

        // [NEW] QA SIMULATION TOOLS (For Admins)
        if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'ADMIN') {
            echo "<hr><h5 class='text-warning'><i class='bi bi-radioactive'></i> QA Simulation Tools (Admin Only)</h5>";
            echo "<p>Use these buttons to force specific scenarios for testing:</p>";

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sim_action'])) {
                $action = $_POST['sim_action'];
                if ($action === 'delete_self') {
                    if (unlink(__FILE__)) {
                        header("Location: index.php?msg=" . urlencode("System Check file deleted successfully."));
                        exit;
                    } else {
                        echo "<div class='alert alert-danger'>Could not delete file. Please delete manually.</div>";
                    }
                }
                if ($action === 'expire_password') {
                    $pdo->prepare("UPDATE users SET password_changed_at = DATE_SUB(NOW(), INTERVAL 46 DAY) WHERE id = ?")->execute([$_SESSION['user_id']]);
                    echo "<div class='alert alert-info'><strong>Success:</strong> Your password has been marked as expired (46 days old). <a href='logout.php'>Logout now</a> to test the forced change screen.</div>";
                }
                if ($action === 'enable_2fa') {
                    $pdo->prepare("UPDATE users SET is_2fa_enabled = 1 WHERE id = ?")->execute([$_SESSION['user_id']]);
                    echo "<div class='alert alert-info'><strong>Success:</strong> 2FA enabled for your account. <a href='logout.php'>Logout now</a> to test the OTP email.</div>";
                }
                if ($action === 'disable_2fa') {
                    $pdo->prepare("UPDATE users SET is_2fa_enabled = 0 WHERE id = ?")->execute([$_SESSION['user_id']]);
                    echo "<div class='alert alert-info'><strong>Success:</strong> 2FA disabled for your account.</div>";
                }
            }

            echo '<form method="POST" class="d-flex gap-2">
        <button type="submit" name="sim_action" value="expire_password" class="btn btn-warning"><i class="bi bi-hourglass-bottom"></i> Force My Password Expiry</button>
        <button type="submit" name="sim_action" value="enable_2fa" class="btn btn-primary"><i class="bi bi-shield-lock"></i> Enable My 2FA</button>
        <button type="submit" name="sim_action" value="disable_2fa" class="btn btn-secondary"><i class="bi bi-unlock"></i> Disable My 2FA</button>
        <button type="submit" name="sim_action" value="delete_self" class="btn btn-danger" onclick="return confirm(\'Are you sure? This will delete this test file.\')"><i class="bi bi-trash"></i> Delete This File</button>
    </form>';
        }
        ?>
    </div>
</body>

</html>