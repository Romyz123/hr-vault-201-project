<?php
// public/cleanup_orphans.php
// [PURPOSE] Automatable script to delete files in /vault/ that have no database record.
// [USAGE] 
//   1. Browser: Log in as Admin -> Visit http://localhost/hr 201/public/cleanup_orphans.php
//   2. CLI: php public/cleanup_orphans.php

require '../config/db.php';
require '../src/Logger.php';

// 1. ENVIRONMENT CHECK
$isCli = (php_sapi_name() === 'cli');
$dryRun = false;

if (!$isCli) {
    session_start();
    // Security: Admin Only for Web Access
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
        die("Access Denied: Admin privileges required.");
    }

    // CSRF token handling
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    // Only execute cleanup if POST request with valid CSRF token
    $executeCleanup = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
            die("Access Denied: Invalid CSRF token.");
        }
        $executeCleanup = true;
    }

    $dryRun = !$executeCleanup;

    echo '<link href="assets/bootstrap.min.css" rel="stylesheet">';
    echo '<div class="container mt-4">';
    echo '<h3>Orphaned File Cleanup</h3>';
    echo '<div class="mb-3">';
    echo '<a href="cleanup_orphans.php" class="btn btn-warning me-2">Simulate (Dry Run)</a>';
    echo '<form method="POST" style="display:inline" onsubmit="return confirm(\'Permanently delete files?\');">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
    echo '<button type="submit" class="btn btn-danger">Execute Cleanup</button>';
    echo '</form></div>';
    echo "<pre class='bg-light p-3 border rounded'>";
} elseif (in_array('--dry-run', $argv)) {
    $dryRun = true;
}

// 2. CONFIGURATION
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// Resolve real path
$realVault = realpath($vaultPath);
if ($realVault) {
    $vaultPath = $realVault . DIRECTORY_SEPARATOR;
} else {
    // Try to create if missing (though unlikely for cleanup script)
    if (!is_dir($vaultPath)) {
        die("Error: Vault directory does not exist at: " . htmlspecialchars($vaultPath) . "\n");
    }
    $vaultPath = rtrim($vaultPath, '/\\') . DIRECTORY_SEPARATOR;
}

echo $dryRun ? "--- DRY RUN MODE (No files will be deleted) ---\n" : "--- ORPHAN CLEANUP STARTED ---\n";
echo "Scanning Vault: " . $vaultPath . "\n";

try {
    // 3. FETCH VALID FILES (Whitelist)
    // A. Standard Documents
    $dbFiles = $pdo->query("SELECT file_path FROM documents")->fetchAll(PDO::FETCH_COLUMN);

    // B. Disciplinary Files (Usually in uploads/, but whitelist just in case)
    $discFiles = $pdo->query("SELECT attachment_path FROM disciplinary_cases WHERE attachment_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

    // C. Avatars (Usually in uploads/, but whitelist just in case)
    $avatars = $pdo->query("SELECT avatar_path FROM employees WHERE avatar_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

    $validFiles = array_merge($dbFiles, $discFiles, $avatars);

    // Normalize to filenames only (remove paths if stored in DB)
    $validFiles = array_map('basename', $validFiles);

    // Add System Files
    $validFiles[] = 'manifest_DO_NOT_DELETE.txt';
    $validFiles[] = '.gitkeep';
    $validFiles[] = '.htaccess';
    $validFiles[] = 'tesp-logo-1.png';
    $validFiles[] = 'tesp logo 1.png';
    $validFiles[] = 'tesp-logo.png';

    // 4. SCAN & CLEAN
    $filesOnDisk = scandir($vaultPath);
    $deletedCount = 0;
    $wouldDeleteCount = 0;
    $errorCount = 0;

    foreach ($filesOnDisk as $file) {
        if ($file === '.' || $file === '..') continue;

        // If file is NOT in the whitelist, delete it
        if (!in_array($file, $validFiles)) {
            $fullPath = $vaultPath . $file;

            if (is_file($fullPath)) {
                if ($dryRun) {
                    echo "[DRY RUN] Would delete: $file\n";
                    $wouldDeleteCount++;
                } elseif (unlink($fullPath)) {
                    echo "Deleted: $file\n";
                    $deletedCount++;
                } else {
                    echo "Failed to delete: $file (Permission Denied)\n";
                    $errorCount++;
                }
            }
        }
    }
    // 4.5 SCAN & CLEAN AVATARS
    $avatarPath = __DIR__ . '/uploads/avatars/';
    if (is_dir($avatarPath)) {
        echo "\nScanning Avatars: " . $avatarPath . "\n";
        $avatarsOnDisk = scandir($avatarPath);

        // Whitelist for avatars specifically
        $validAvatars = array_map('basename', $avatars);
        $validAvatars[] = 'default.png';
        $validAvatars[] = '.gitkeep';
        $validAvatars[] = '.htaccess';
        $validAvatars[] = 'tesp-logo-1.png';
        $validAvatars[] = 'tesp logo 1.png';
        $validAvatars[] = 'tesp-logo.png';

        foreach ($avatarsOnDisk as $file) {
            if ($file === '.' || $file === '..') continue;

            if (!in_array($file, $validAvatars)) {
                $fullPath = $avatarPath . $file;
                if (is_file($fullPath)) {
                    if ($dryRun) {
                        echo "[DRY RUN] Would delete Avatar: $file\n";
                        $wouldDeleteCount++;
                    } elseif (unlink($fullPath)) {
                        echo "Deleted Avatar: $file\n";
                        $deletedCount++;
                    } else {
                        if (!$isCli) echo "</pre></div>";
                        $errorCount++;
                    }
                }
            }
        }
    }

    // 5. LOGGING
    if ($deletedCount > 0) {
        $logger = new Logger($pdo);
        $userId = $isCli ? 0 : ($_SESSION['user_id'] ?? 0);
        $logger->log($userId, 'AUTO_CLEANUP', "Deleted $deletedCount orphaned files from vault/uploads.");
    }

    echo "--- SUMMARY ---\n";
    if ($dryRun) {
        echo "Would Delete: $wouldDeleteCount\n";
    } else {
        echo "Deleted: $deletedCount\n";
    }
    echo "Errors:  $errorCount\n";
    echo "--- DONE ---\n";
} catch (Exception $e) {
    echo "Critical Error: " . $e->getMessage() . "\n";
}

if (!$isCli) echo "</pre>";
