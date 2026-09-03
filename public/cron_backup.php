<?php
// public/cron_backup.php
// [PURPOSE] Command-line script for Windows Task Scheduler to backup DB + Vault + Keys
// Usage: php C:\xampp\htdocs\hr-vault\public\cron_backup.php

// Ensure we are in the right directory for relative includes
chdir(__DIR__);

// [FIX] Prevent timeouts and memory exhaustion for scheduled background tasks
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '1024M');

// 1. SETUP ENVIRONMENT
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

define('CLI_MODE', php_sapi_name() === 'cli');

// Load DB and settings FIRST so ini_set() executes before session_start()
require '../config/db.php';
require '../src/Logger.php';

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

    // [FIX] Release session lock so the dashboard remains responsive while the backup generates in the background
    session_write_close();
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
$secondaryPath = $settings['secondary_backup_path'] ?? '';

$requestedRunId = trim((string)($_POST['run_id'] ?? ''));
$runId = preg_match('/^[a-f0-9-]{16,64}$/i', $requestedRunId)
    ? $requestedRunId
    : bin2hex(random_bytes(16));

// [FIX] Ensure ZipArchive extension is available
if (!class_exists('ZipArchive')) {
    $success = false;
    $errorMessage = "PHP ZipArchive extension is not enabled. Cannot create ZIP backups.";
    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
    goto backup_end;
}

// 3. PREPARE PATHS
$config = require __DIR__ . '/../config/config.php';
$defaultBackupDir = rtrim((string)($config['BACKUP_PATH'] ?? __DIR__ . '/../backups'), '/\\');
$backupDir = !empty($customPath) ? $customPath : $defaultBackupDir;
if (!$backupDir) {
    $backupDir = __DIR__ . '/../backups';
}

if ($backupDir && !is_dir($backupDir)) @mkdir($backupDir, 0700, true);

if ($backupDir) {
    $real = realpath($backupDir);
    $backupDir = $real !== false ? $real : $backupDir;
}

if (!$backupDir || !is_writable($backupDir)) {
    $success = false;
    $errorMessage = 'Backup directory is unavailable or not writable.';
    goto backup_end;
}

if (CLI_MODE) {
    $scheduleDay = $settings['backup_day'] ?? 'Fri';
    $scheduleTime = $settings['backup_time'] ?? '00:00';
    $scheduledDay = date('D');
    $scheduledTime = date('H:i');
    if ($scheduledDay !== $scheduleDay || $scheduledTime < $scheduleTime) {
        echo "Backup skipped: scheduled for {$scheduleDay} at {$scheduleTime}.\n";
        exit(0);
    }
}

$lockName = 'hr201_backup_execution';
$lockStmt = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0)");
if ((int)$lockStmt->fetchColumn() !== 1) {
    $message = 'Another backup is already running.';
    if ($isAjax) {
        echo json_encode(['status' => 'error', 'code' => 'BACKUP_RUNNING', 'message' => $message]);
    } else {
        echo $message . "\n";
    }
    exit(CLI_MODE ? 2 : 0);
}

register_shutdown_function(function () use ($pdo, $lockName) {
    try {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
    } catch (Throwable $e) {
    }
});

$runStartedAt = date('Y-m-d H:i:s');
try {
    $statusStmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $statusStmt->execute(['backup_run_id', $runId]);
    $statusStmt->execute(['backup_run_started_at', $runStartedAt]);
    $statusStmt->execute(['backup_last_status', 'RUNNING']);
} catch (Exception $e) {
    error_log('cron_backup status initialization failed: ' . $e->getMessage());
}

$dateStr = date('Y-m-d_H-i-s');
$baseName = "AutoBackup_" . $dateStr;

if (CLI_MODE && !empty(glob(rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . 'AutoBackup_' . date('Y-m-d') . '*.*'))) {
    echo "Backup skipped: a backup already exists for today.\n";
    exit(0);
}

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

// [INFO] Maintenance cleanup from cron_backup has been moved to public/cleanup_resolved_expiry.php

// 4. INITIALIZE ZIP & SQL
$tables = [];
$query = $pdo->query('SHOW TABLES');
if ($query) {
    while ($row = $query->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];
}

// 5. CREATE ZIP
$success = true;
$errorMessage = '';
$zip = new ZipArchive();
if (!$zip instanceof ZipArchive || $zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    $zip = null;
    $success = false;
    $errorMessage = "Could not create ZIP file ($zipFile).";
    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
}
if ($success && $zip instanceof ZipArchive) {
    // [OPTIMIZATION] Stream directly to a local temporary folder to bypass C:\Users restrictions
    $localTempDir = __DIR__ . '/../backups/temp';
    if (!is_dir($localTempDir)) @mkdir($localTempDir, 0700, true);
    $tmpSqlFile = tempnam($localTempDir, 'hr201_backup_');
    $pendingUnlink[] = $tmpSqlFile;
    $handle = fopen($tmpSqlFile, 'w');
    if (!$handle) {
        $success = false;
        $errorMessage = "Could not create temporary SQL file. Check server permissions.";
        goto backup_end;
    }
    $sqlBytes = 0;

    $sqlBytes += fwrite($handle, "-- AUTOMATED BACKUP ($dateStr) PART {$partNumber}\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $q = $pdo->query("SHOW CREATE TABLE `" . str_replace("`", "``", $table) . "`");
        $row = $q ? $q->fetch(PDO::FETCH_NUM) : false;
        if (!$row) continue;
        $sqlBytes += fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n");

        // stream the rows instead of loading entire table
        $stmtRows = $pdo->prepare("SELECT * FROM `" . str_replace("`", "``", $table) . "` ");
        $stmtRows->execute();
        while ($r = $stmtRows->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? "NULL" : $pdo->quote($v), $r);
            $line = "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
            $len = strlen($line);

            // [SPLIT LOGIC] Trigger split if adding this line exceeds limit
            if ($currentBytes + $sqlBytes + $len > $maxSizeBytes) {
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
                fclose($handle);
                $handle = null;

                $sqlFileInZip = "database_Part{$partNumber}.sql";
                if ($zip instanceof ZipArchive) {
                    if (!empty($tmpSqlFile) && file_exists($tmpSqlFile)) {
                        if (!$zip->addFile($tmpSqlFile, $sqlFileInZip)) {
                            $success = false;
                            $errorMessage = "Failed to add SQL file to ZIP archive.";
                            goto backup_end;
                        }
                    } else {
                        $success = false;
                        $errorMessage = "SQL file missing for ZIP archive.";
                        goto backup_end;
                    }
                    if ($zipPass) $zip->setEncryptionName($sqlFileInZip, ZipArchive::EM_AES_256, $zipPass);
                    if (!$zip->close()) {
                        $success = false;
                        $errorMessage = "Failed to finalize ZIP archive.";
                        goto backup_end;
                    }
                }

                foreach ($pendingUnlink as $f) @unlink($f);
                $pendingUnlink = [];

                $partNumber++;
                $currentBytes = 0;
                $zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
                $generatedZips[] = $zipFile;
                $zip = new ZipArchive();
                if (!$zip instanceof ZipArchive || $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    $zip = null;
                    $success = false;
                    $errorMessage = "Could not create ZIP file ($zipFile).";
                    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
                    goto backup_end;
                }

                $tmpSqlFile = tempnam(sys_get_temp_dir(), 'hr201_backup_');
                $pendingUnlink[] = $tmpSqlFile;
                $handle = fopen($tmpSqlFile, 'w');
                if (!$handle) {
                    $success = false;
                    goto backup_end;
                }
                $sqlBytes = 0;
                $sqlBytes += fwrite($handle, "-- AUTOMATED BACKUP ($dateStr) PART {$partNumber}\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            }

            $sqlBytes += fwrite($handle, $line);
        }
        $sqlBytes += fwrite($handle, "\n");
    }

    if (isset($handle) && is_resource($handle)) {
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        $handle = null;
    }

    $sqlFileInZip = "database_Part{$partNumber}.sql";
    if ($zip instanceof ZipArchive) {
        if (!empty($tmpSqlFile) && file_exists($tmpSqlFile)) {
            if (!$zip->addFile($tmpSqlFile, $sqlFileInZip)) {
                $success = false;
                $errorMessage = "Failed to add final SQL file to ZIP.";
                goto backup_end;
            }
        } else {
            $success = false;
            $errorMessage = "Final SQL file missing for ZIP archive.";
            goto backup_end;
        }
        if ($zipPass) $zip->setEncryptionName($sqlFileInZip, ZipArchive::EM_AES_256, $zipPass);
    }

    $currentBytes += $sqlBytes;

    // Add Vault & Key (CRITICAL for Encryption System)
    if ($incVault) {
        // [LOGICAL FIX] Backup the Encryption Key! Without this, vault files are permanently locked if server dies.
        $configPath = realpath(__DIR__ . '/../config/config.php');
        if ($configPath && is_file($configPath)) {
            $fsize = filesize($configPath);
            if ($fsize === false) {
                $success = false;
                $errorMessage = "Could not read config file size for ZIP archive.";
                goto backup_end;
            }
            if ($currentBytes + $fsize > $maxSizeBytes && $currentBytes > 0) {
                if ($zip instanceof ZipArchive) $zip->close();
                foreach ($pendingUnlink as $f) @unlink($f);
                $pendingUnlink = [];

                $partNumber++;
                $currentBytes = 0;
                $zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
                $generatedZips[] = $zipFile;
                $zip = new ZipArchive();
                if (!$zip instanceof ZipArchive || $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    $zip = null;
                    $success = false;
                    $errorMessage = "Could not create ZIP file ($zipFile).";
                    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
                    goto backup_end;
                }
            }

            if ($zip instanceof ZipArchive) {
                if (!$zip->addFile($configPath, 'config/config.php')) {
                    $success = false;
                    $errorMessage = "Failed to add config file to ZIP archive.";
                    goto backup_end;
                }
                if ($zipPass) $zip->setEncryptionName('config/config.php', ZipArchive::EM_AES_256, $zipPass);
            }
            $currentBytes += $fsize;
        } else {
            $success = false;
            $errorMessage = "Config file is missing or has an invalid path.";
            goto backup_end;
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
                    if ($src === false || !is_file($src)) {
                        $success = false;
                        $errorMessage = "Vault file has an invalid path.";
                        goto backup_end;
                    }
                    $fsize = filesize($src);
                    if ($fsize === false) {
                        $success = false;
                        $errorMessage = "Could not read vault file size: $src";
                        goto backup_end;
                    }

                    // [SPLIT LOGIC]
                    if ($currentBytes + $fsize > $maxSizeBytes && $currentBytes > 0) {
                        if ($zip instanceof ZipArchive) @$zip->close();
                        foreach ($pendingUnlink as $f) @unlink($f);
                        $pendingUnlink = [];

                        $partNumber++;
                        $currentBytes = 0;
                        $zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . "_Part{$partNumber}.zip";
                        $generatedZips[] = $zipFile;
                        $zip = new ZipArchive();
                        if (!$zip instanceof ZipArchive || $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                            $zip = null;
                            $success = false;
                            $errorMessage = "Could not create ZIP file ($zipFile).";
                            if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: $errorMessage\n\nTime: " . date('Y-m-d H:i:s'));
                            goto backup_end;
                        }
                    }

                    $relativePath = 'vault/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($src, strlen($vaultPath) + 1));
                    if (!$zip instanceof ZipArchive || !$zip->addFile($src, $relativePath)) {
                        $success = false;
                        $errorMessage = "Failed to add vault file to ZIP archive: $src";
                        goto backup_end;
                    }
                    if ($zipPass) {
                        $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256, $zipPass);
                    }
                    $currentBytes += $fsize;
                    $syncCount++;
                }
            }
        }
    }

    if ($zip instanceof ZipArchive && !empty($zip->filename)) {
        @$zip->close();
    }
    $zip = null; // Mark as finished
    foreach ($pendingUnlink as $f) @unlink($f);
    $pendingUnlink = [];
}

backup_end:
if (isset($zip) && $zip instanceof ZipArchive && !empty($zip->filename)) {
    @$zip->close();
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

        // [NEW] Secondary Path Redundancy
        if (!empty($secondaryPath)) {
            if (!is_dir($secondaryPath) && !@mkdir($secondaryPath, 0755, true)) {
                error_log("CRON BACKUP ERROR: Could not create secondary directory: $secondaryPath");
            }
            foreach ($generatedZips as $gz) {
                $dest = rtrim($secondaryPath, '/\\') . DIRECTORY_SEPARATOR . basename($gz);
                if (!@copy($gz, $dest)) {
                    error_log("CRON BACKUP ERROR: Failed to mirror to secondary path. Source: $gz | Dest: $dest");
                }
            }
        }

        // [FIX] Always create a notification for Admins so the result is visible in the UI
        $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'ADMIN'")->fetchAll(PDO::FETCH_COLUMN);
        $notifTitle = CLI_MODE ? "Automated Backup" : "Manual Backup";
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'success')");
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([$adminId, $notifTitle, "System backup created successfully in $partLabel: " . basename($generatedZips[0])]);
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

    // [FIX] Always create a notification for Admins so the failure is visible in the UI
    try {
        $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'ADMIN'")->fetchAll(PDO::FETCH_COLUMN);
        $notifTitle = CLI_MODE ? "Automated Backup Failed" : "Manual Backup Failed";
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'danger')");
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([$adminId, $notifTitle, "System backup failed: " . ($errorMessage ?: "Internal server error")]);
        }
    } catch (Exception $e) {
    }

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
    }

    if ($isAjax) {
        echo json_encode(['status' => 'error', 'message' => $errorMessage ?: "Backup execution failed."]);
        exit;
    }
    if (!CLI_MODE) {
        $redirectMsg = "❌ Backup Failed.";
        if ($errorMessage) $redirectMsg .= " Reason: $errorMessage";
        header("Location: settings.php?error=" . urlencode($redirectMsg));
        exit;
    }
    exit(1);
}
