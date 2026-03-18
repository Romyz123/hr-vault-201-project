<?php
// public/cron_backup.php
// [PURPOSE] Command-line script for Windows Task Scheduler to backup DB + Vault + Keys
// Usage: php C:\xampp\htdocs\hr 201\public\cron_backup.php

// Ensure we are in the right directory for relative includes
chdir(__DIR__);

// 1. SETUP ENVIRONMENT
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

define('CLI_MODE', php_sapi_name() === 'cli');
if (!CLI_MODE) {
    // If accessed via browser, require Admin login
    session_start();
    if (($_SESSION['role'] ?? '') !== 'ADMIN') {
        if ($isAjax) {
            echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
            exit;
        }
        die("Access Denied");
    }
}

require '../config/db.php';
require '../src/Logger.php';

// 2. LOAD SETTINGS
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Avoid leaking sensitive information in web mode
    if (CLI_MODE) {
        // CLI can show full details for troubleshooting
        die("Error loading settings: " . $e->getMessage());
    } else {
        // log the full exception and show generic message
        error_log("cron_backup settings load failure: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        die("Error loading settings");
    }
}

$customPath  = $settings['backup_path'] ?? '';
$zipPass     = $settings['backup_password'] ?? '';
$incVault    = ($settings['backup_include_vault'] ?? '0') === '1';
$alertEmail  = $settings['backup_alert_email'] ?? '';

// 3. PREPARE PATHS
$backupDir = (!empty($customPath) && is_dir($customPath)) ? $customPath : realpath(__DIR__ . '/../backups');
if (!$backupDir) {
    $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
}

$dateStr = date('Y-m-d_H-i-s');
$baseName = "AutoBackup_" . $dateStr;

// [MULTI-VOLUME LOGIC] Dynamic GB Limit
$maxSizeGB = (float)($settings['backup_max_size_gb'] ?? 1.9);
$maxSizeBytes = $maxSizeGB * 1024 * 1024 * 1024;
$currentBytes = 0;
$partNumber = 1;
$pendingUnlink = [];
$generatedZips = [];

$zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
$generatedZips[] = $zipFile;

if (CLI_MODE) echo "Starting backup to: $zipFile\n";

// 4. INITIALIZE ZIP & SQL
$tables = [];
$query = $pdo->query('SHOW TABLES');
while ($row = $query->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

// 5. CREATE ZIP
$success = true;
$errorMessage = '';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    $success = false;
    $errorMessage = "Could not create ZIP file ($zipFile).";
    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
}
if ($success) {
    // [OPTIMIZATION] Stream directly to a temporary file to save RAM
    $tmpSqlFile = tempnam(sys_get_temp_dir(), 'hr201_backup_');
    $pendingUnlink[] = $tmpSqlFile;
    $handle = fopen($tmpSqlFile, 'w');
    $sqlBytes = 0;

    $sqlBytes += fwrite($handle, "-- AUTOMATED BACKUP ($dateStr) PART {$partNumber}\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sqlBytes += fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n");

        // stream the rows instead of loading entire table
        $stmtRows = $pdo->prepare("SELECT * FROM `$table`");
        $stmtRows->execute();
        while ($r = $stmtRows->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? "NULL" : $pdo->quote($v), $r);
            $line = "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
            $len = strlen($line);

            // [SPLIT LOGIC] Trigger split if adding this line exceeds limit
            if ($currentBytes + $sqlBytes + $len > $maxSizeBytes) {
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
                fclose($handle);

                $sqlFileInZip = "database_Part{$partNumber}.sql";
                $zip->addFile($tmpSqlFile, $sqlFileInZip);
                if ($zipPass) $zip->setEncryptionName($sqlFileInZip, ZipArchive::EM_AES_256, $zipPass);

                $zip->close();
                foreach ($pendingUnlink as $f) @unlink($f);
                $pendingUnlink = [];

                $partNumber++;
                $currentBytes = 0;
                $zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
                $generatedZips[] = $zipFile;
                if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
                    $success = false;
                    $errorMessage = "Could not create ZIP file ($zipFile).";
                    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
                    goto backup_end;
                }

                $tmpSqlFile = tempnam(sys_get_temp_dir(), 'hr201_backup_');
                $pendingUnlink[] = $tmpSqlFile;
                $handle = fopen($tmpSqlFile, 'w');
                $sqlBytes = 0;
                $sqlBytes += fwrite($handle, "-- AUTOMATED BACKUP ($dateStr) PART {$partNumber}\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            }

            $sqlBytes += fwrite($handle, $line);
        }
        $sqlBytes += fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    $sqlFileInZip = "database_Part{$partNumber}.sql";
    $zip->addFile($tmpSqlFile, $sqlFileInZip);
    if ($zipPass) $zip->setEncryptionName($sqlFileInZip, ZipArchive::EM_AES_256, $zipPass);

    $currentBytes += $sqlBytes;

    // Add Vault & Key (CRITICAL for Encryption System)
    if ($incVault) {
        // [LOGICAL FIX] Backup the Encryption Key! Without this, vault files are permanently locked if server dies.
        $configPath = realpath(__DIR__ . '/../config/config.php');
        if ($configPath && file_exists($configPath)) {
            $fsize = filesize($configPath);
            if ($currentBytes + $fsize > $maxSizeBytes && $currentBytes > 0) {
                $zip->close();
                foreach ($pendingUnlink as $f) @unlink($f);
                $pendingUnlink = [];

                $partNumber++;
                $currentBytes = 0;
                $zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
                $generatedZips[] = $zipFile;
                if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
                    $success = false;
                    $errorMessage = "Could not create ZIP file ($zipFile).";
                    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
                    goto backup_end;
                }
            }

            $zip->addFile($configPath, 'config/config.php');
            if ($zipPass) $zip->setEncryptionName('config/config.php', ZipArchive::EM_AES_256, $zipPass);
            $currentBytes += $fsize;
        }

        // [MULTI-VOLUME FILE SPLIT] Compress Vault items and track bytes
        $configEnv = require __DIR__ . '/../config/config.php';
        $vaultPath = $configEnv['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
        if ($vaultPath && is_dir($vaultPath)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
            $syncCount = 0;
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $src = $file->getRealPath();
                    $fsize = filesize($src);

                    // [SPLIT LOGIC]
                    if ($currentBytes + $fsize > $maxSizeBytes && $currentBytes > 0) {
                        $zip->close();
                        foreach ($pendingUnlink as $f) @unlink($f);
                        $pendingUnlink = [];

                        $partNumber++;
                        $currentBytes = 0;
                        $zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
                        $generatedZips[] = $zipFile;
                        if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
                            $success = false;
                            $errorMessage = "Could not create ZIP file ($zipFile).";
                            if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
                            goto backup_end;
                        }
                    }

                    $relativePath = 'vault/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($src, strlen($vaultPath) + 1));
                    $zip->addFile($src, $relativePath);
                    if ($zipPass) $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256, $zipPass);
                    $currentBytes += $fsize;
                    $syncCount++;
                }
            }
        }
    }

    $zip->close();
    foreach ($pendingUnlink as $f) @unlink($f);
    $pendingUnlink = [];
}

backup_end:
if (isset($zip) && $zip instanceof ZipArchive) {
    $zip->close();
}
foreach ($pendingUnlink as $f) @unlink($f);
$pendingUnlink = [];

// 6. LOG & FINISH
// [EARLY WARNING CHECK] Validate generated zip files
$totalSize = 0;
$allValid = true;
foreach ($generatedZips as $gz) {
    if (!file_exists($gz) || filesize($gz) === 0) {
        $allValid = false;
        break;
    }
    $totalSize += filesize($gz);
}

if ($success && $allValid && count($generatedZips) > 0) {
    // Mark System Status as Healthy
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('backup_last_status', 'OK') ON DUPLICATE KEY UPDATE setting_value = 'OK'");

    // [RETENTION POLICY] Delete Backups older than 30 days to prevent server crash
    $deletedCount = 0;
    $files = glob(rtrim($backupDir, '/\\') . '/*.{zip,sql}', GLOB_BRACE);
    $cutoffTime = time() - (30 * 86400); // 30 Days
    foreach ($files as $f) {
        if (is_file($f) && filemtime($f) < $cutoffTime) {
            @unlink($f);
            $deletedCount++;
        }
    }

    $size = round($totalSize / 1024 / 1024, 2) . " MB (" . count($generatedZips) . " parts)";
    if (CLI_MODE) echo "✅ Backup Complete! Total Size: $size\n";
    if (CLI_MODE && $deletedCount > 0) echo "🧹 Cleaned up $deletedCount old backups.\n";

    // Log to DB if possible
    try {
        $logger = new Logger($pdo);
        $userId = CLI_MODE ? 0 : ($_SESSION['user_id'] ?? 0);
        $partCount = count($generatedZips);
        $partLabel = $partCount === 1 ? '1 part' : "$partCount parts";
        $logger->log($userId, 'AUTO_BACKUP_CLI', "Created backup: " . basename($generatedZips[0]) . " ($partLabel)");

        // [NEW] Add to Notification Center (if triggered via web)
        if (!CLI_MODE && isset($_SESSION['user_id'])) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Manual Backup', ?, 'success')")
                ->execute([$_SESSION['user_id'], "Manual backup created in $partLabel."]);
        }
    } catch (Exception $e) {
    }

    if ($isAjax) {
        $syncMsg = isset($syncCount) ? " (Vault Synced: $syncCount files)" : "";
        echo json_encode(['status' => 'success', 'message' => "Backup Complete! Size: $size. Removed $deletedCount old backups." . $syncMsg]);
        exit;
    } elseif (!CLI_MODE) {
        header("Location: settings.php?msg=" . urlencode("✅ Full Backup Complete! Size: $size"));
        exit;
    }
} else {
    // adjust error message if any part is missing/0 bytes
    if ($success && !$allValid) {
        $errorMessage = "Archive generation failed or one of the split parts resulted in 0 bytes.";
        $success = false;
    }

    // [EARLY WARNING ALERT] Log Failure to Dashboard
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('backup_last_status', 'FAILED') ON DUPLICATE KEY UPDATE setting_value = 'FAILED'");

    // failure path
    if (CLI_MODE) {
        $msg = "❌ Backup Failed.";
        if ($errorMessage) {
            $msg .= " Reason: $errorMessage";
        }
        echo $msg . "\n";
        exit(1);
    }
    if ($alertEmail) {
        $body = "Manual/Cron backup failed";
        if ($errorMessage) {
            $body .= ": $errorMessage";
        }
        $body .= "\n\nTime: " . date('Y-m-d H:i:s');
        mail($alertEmail, "⚠️ HR System Backup Failed", $body);

        if ($isAjax) {
            echo json_encode(['status' => 'error', 'message' => $errorMessage ?: "Backup execution failed."]);
            exit;
        }
        if (!CLI_MODE) {
            // in web mode redirect back with error message
            $redirectMsg = "❌ Backup Failed.";
            if ($errorMessage) {
                $redirectMsg .= " Reason: $errorMessage";
            }
            header("Location: settings.php?error=" . urlencode($redirectMsg));
            exit;
        }
        exit(1);
    } else {
        $redirectMsg = "❌ Backup Failed.";
        if ($errorMessage) {
            $redirectMsg .= " Reason: $errorMessage";
        }
        header("Location: settings.php?error=" . urlencode($redirectMsg));
        exit;
    }
}
