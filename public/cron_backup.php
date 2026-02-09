<?php
// public/cron_backup.php
// [PURPOSE] Command-line script for Windows Task Scheduler to backup DB + Vault + Keys
// Usage: php C:\xampp\htdocs\hr 201\public\cron_backup.php

// Ensure we are in the right directory for relative includes
chdir(__DIR__);

// 1. SETUP ENVIRONMENT
define('CLI_MODE', php_sapi_name() === 'cli');
if (!CLI_MODE) {
    // If accessed via browser, require Admin login
    session_start();
    if (($_SESSION['role'] ?? '') !== 'ADMIN') die("Access Denied");
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
    die("Error loading settings: " . $e->getMessage());
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
$zipFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . ".zip";
$sqlFile = $baseName . ".sql";

if (CLI_MODE) echo "Starting backup to: $zipFile\n";

// 4. GENERATE SQL DUMP
$tables = [];
$query = $pdo->query('SHOW TABLES');
while ($row = $query->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

$sqlContent = "-- AUTOMATED BACKUP ($dateStr)\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach ($tables as $table) {
    $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    $sqlContent .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $vals = array_map(fn($v) => $v === null ? "NULL" : $pdo->quote($v), $r);
        $sqlContent .= "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n";
    }
    $sqlContent .= "\n";
}
$sqlContent .= "SET FOREIGN_KEY_CHECKS=1;";

// 5. CREATE ZIP
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: Could not create ZIP.\n\nTime: " . date('Y-m-d H:i:s'));
    die("Error: Cannot create ZIP file.");
}

// Add SQL
$zip->addFromString($sqlFile, $sqlContent);
if ($zipPass) $zip->setEncryptionName($sqlFile, ZipArchive::EM_TRAD_PKWARE, $zipPass);

// Add Vault & Key (CRITICAL for Encryption System)
if ($incVault) {
    $vaultPath = realpath(__DIR__ . '/../vault');
    if ($vaultPath && is_dir($vaultPath)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = 'vault/' . substr($filePath, strlen($vaultPath) + 1);
                // [NOTE] This backs up the ENCRYPTED file exactly as it is on disk.
                $zip->addFile($filePath, $relativePath);
                if ($zipPass) $zip->setEncryptionName($relativePath, ZipArchive::EM_TRAD_PKWARE, $zipPass);
            }
        }
    }

    // [CRITICAL] Backup the FileService.php because it contains the Encryption Key!
    $keyFile = realpath(__DIR__ . '/../src/FileService.php');
    if ($keyFile) {
        $zip->addFile($keyFile, 'src/FileService.php');
        if ($zipPass) $zip->setEncryptionName('src/FileService.php', ZipArchive::EM_TRAD_PKWARE, $zipPass);
    }
}

$zip->close();

// 6. LOG & FINISH
if (file_exists($zipFile)) {
    $size = round(filesize($zipFile) / 1024 / 1024, 2) . " MB";
    if (CLI_MODE) echo "✅ Backup Complete! Size: $size\n";

    // Log to DB if possible
    try {
        $logger = new Logger($pdo);
        $userId = CLI_MODE ? 0 : ($_SESSION['user_id'] ?? 0);
        $logger->log($userId, 'AUTO_BACKUP_CLI', "Created backup: " . basename($zipFile));

        // [NEW] Add to Notification Center (if triggered via web)
        if (!CLI_MODE && isset($_SESSION['user_id'])) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Manual Backup', ?, 'success')")
                ->execute([$_SESSION['user_id'], "Manual backup created: " . basename($zipFile)]);
        }
    } catch (Exception $e) {
    }

    if (!CLI_MODE) {
        header("Location: settings.php?msg=" . urlencode("✅ Full Backup Complete! Size: $size"));
        exit;
    }
} else {
    if (CLI_MODE) echo "❌ Backup Failed.\n";
    if ($alertEmail) mail($alertEmail, "⚠️ HR System Backup Failed", "Manual/Cron backup failed: ZIP file not found after creation attempt.\n\nTime: " . date('Y-m-d H:i:s'));
    else {
        header("Location: settings.php?error=" . urlencode("❌ Backup Failed."));
        exit;
    }
}
