<?php
// public/backup.php
require '../config/db.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Only ADMIN can download backups
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("ACCESS DENIED: You do not have permission to download backups.");
}

// [NEW] Fetch System Settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {
}

// 2. CONFIGURATION
$backup_name = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
$tables = [];

// 3. GET ALL TABLES
$query = $pdo->query('SHOW TABLES');
while ($row = $query->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

$content = "-- TESP HR SYSTEM BACKUP\n";
$content .= "-- Generated: " . date("Y-m-d H:i:s") . "\n";
$content .= "-- By User ID: " . $_SESSION['user_id'] . "\n\n";
$content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

// 4. LOOP THROUGH TABLES
foreach ($tables as $table) {
    // A. Get Create Table structure
    $stmt = $pdo->query("SHOW CREATE TABLE $table");
    $row = $stmt->fetch(PDO::FETCH_NUM);

    $content .= "\n\n-- Structure for table `$table` --\n";
    $content .= "DROP TABLE IF EXISTS `$table`;\n";
    $content .= $row[1] . ";\n\n";

    // B. Get Table Data
    $stmt = $pdo->query("SELECT * FROM $table");
    $rowCount = $stmt->rowCount();

    if ($rowCount > 0) {
        $content .= "-- Dumping data for table `$table` --\n";

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = "NULL";
                } else {
                    $value = addslashes($value);
                    $value = str_replace("\n", "\\n", $value);
                    $values[] = "'$value'";
                }
            }
            $content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
        }
    }
}

$content .= "\nSET FOREIGN_KEY_CHECKS=1;";

$mode = $_GET['mode'] ?? 'download';
$password = !empty($_POST['backup_password']) ? trim($_POST['backup_password']) : '';
if (strlen($password) > 50) {
    die("Error: Password is too long (Max 50 characters).");
}

$incVault = isset($_POST['include_vault']); // Checkbox from modal

$final_content = $content;
$final_filename = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
$final_mimetype = 'application/octet-stream';

// [FIX] Force ZIP if password is set OR if vault is included
if ($password || $incVault) {
    $zip = new ZipArchive();
    $tempZipPath = tempnam(sys_get_temp_dir(), 'zip');
    // Use a consistent name inside the zip
    $sql_filename_in_zip = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";

    if ($zip->open($tempZipPath, ZipArchive::CREATE) === TRUE) {
        $zip->addFromString($sql_filename_in_zip, $content);
        if ($password) $zip->setEncryptionName($sql_filename_in_zip, ZipArchive::EM_TRAD_PKWARE, $password);

        // [NEW] Add Vault Files & Key
        if ($incVault) {
            $vaultPath = realpath(__DIR__ . '/../vault');
            if ($vaultPath && is_dir($vaultPath)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = 'vault/' . substr($filePath, strlen($vaultPath) + 1);
                        $zip->addFile($filePath, $relativePath);
                        if ($password) $zip->setEncryptionName($relativePath, ZipArchive::EM_TRAD_PKWARE, $password);
                    }
                }
            }
            // [CRITICAL] Backup Encryption Key
            $keyFile = realpath(__DIR__ . '/../src/FileService.php');
            if ($keyFile) {
                $zip->addFile($keyFile, 'src/FileService.php');
                if ($password) $zip->setEncryptionName('src/FileService.php', ZipArchive::EM_TRAD_PKWARE, $password);
            }
        }
        $zip->close();

        $final_content = file_get_contents($tempZipPath);
        $final_filename = ($incVault ? "FULL_SYSTEM_" : "Encrypted_Backup_") . date("Y-m-d_H-i-s") . ".zip";
        $final_mimetype = 'application/zip';

        unlink($tempZipPath);
    } else {
        $error_msg = "❌ Failed to create ZIP archive.";
        if ($mode === 'server') {
            header("Location: manager_user.php?error=" . urlencode($error_msg));
            exit;
        } else {
            die($error_msg);
        }
    }
}

$logger = new Logger($pdo);

if ($mode === 'server') {
    // [FIX] Use path from settings or default
    $customPath = $settings['backup_path'] ?? '';
    $primaryPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : realpath(__DIR__ . '/../backups');
    if (!is_dir($primaryPath)) @mkdir($primaryPath, 0755, true);
    $fullPath = rtrim($primaryPath, '/\\') . '/' . $final_filename;

    if (file_put_contents($fullPath, $final_content) !== false) {
        $msg = "✅ Backup saved to Primary: " . basename($fullPath);
        $secondaryPath = null; // Removed ENV dependency for consistency
        if ($secondaryPath) {
            if (!is_dir($secondaryPath)) @mkdir($secondaryPath, 0755, true);
            $secFile = rtrim($secondaryPath, '/\\') . '/' . $final_filename;
            if (file_put_contents($secFile, $final_content) !== false) {
                $msg .= " AND Secondary Location.";
            }
        }
        $logger->log($_SESSION['user_id'], 'MANUAL_BACKUP_SERVER', "Triggered manual backup to server drives.");
        header("Location: manager_user.php?msg=" . urlencode($msg));
        exit;
    } else {
        header("Location: manager_user.php?error=" . urlencode("❌ Failed to write to backup path. Check folder permissions."));
        exit;
    }
} else {
    $logger->log($_SESSION['user_id'], 'SYSTEM_BACKUP', 'Admin downloaded full database backup.');

    // [FIX] Clear output buffer to prevent ZIP corruption
    if (ob_get_length()) ob_end_clean();

    header('Content-Type: ' . $final_mimetype);
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"" . $final_filename . "\"");
    header('Content-Length: ' . strlen($final_content));
    echo $final_content;
    exit;
}
