<?php
// public/backup.php
// [FIX] Start buffering immediately to catch any whitespace/BOM from includes
ob_start();
require '../config/db.php';
require '../src/Logger.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Only ADMIN can download backups
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("ACCESS DENIED: You do not have permission to download backups.");
}

// [SECURITY] Verify CSRF Token
$security = new Security($pdo);
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die("Security Error: Invalid CSRF Token.");
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

// [OPTIMIZATION] Stream directly to a temporary file to save RAM
$tmpSqlFile = tempnam(sys_get_temp_dir(), 'hr201_manual_');
$handle = fopen($tmpSqlFile, 'w');

fwrite($handle, "-- TESP HR SYSTEM BACKUP\n");
fwrite($handle, "-- Generated: " . date("Y-m-d H:i:s") . "\n");
fwrite($handle, "-- By User ID: " . $_SESSION['user_id'] . "\n\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

// 4. LOOP THROUGH TABLES
foreach ($tables as $table) {
    // A. Get Create Table structure
    $stmt = $pdo->query("SHOW CREATE TABLE $table");
    $row = $stmt->fetch(PDO::FETCH_NUM);

    fwrite($handle, "\n\n-- Structure for table `$table` --\n");
    fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
    fwrite($handle, $row[1] . ";\n\n");

    // B. Get Table Data
    $stmt = $pdo->query("SELECT * FROM $table");

    fwrite($handle, "-- Dumping data for table `$table` --\n");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = "NULL";
            } else {
                // [FIX] Use PDO::quote for safer and consistent SQL escaping
                $values[] = $pdo->quote((string)$value);
            }
        }
        fwrite($handle, "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n");
    }
}

fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
fclose($handle);

$mode = $_GET['mode'] ?? 'download';
$password = !empty($_POST['backup_password']) ? trim($_POST['backup_password']) : '';
if (strlen($password) > 50) {
    die("Error: Password is too long (Max 50 characters).");
}

$incVault = isset($_POST['include_vault']); // Checkbox from modal

$useZip = ($password || $incVault);
$tempZipPath = '';

// [FIX] Force ZIP if password is set OR if vault is included
if ($useZip) {
    $zip = new ZipArchive();
    $tempZipPath = tempnam(sys_get_temp_dir(), 'zip');
    // Use a consistent name inside the zip
    $sql_filename_in_zip = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";

    if ($zip->open($tempZipPath, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($tmpSqlFile, $sql_filename_in_zip);
        if ($password) $zip->setEncryptionName($sql_filename_in_zip, ZipArchive::EM_AES_256, $password);

        // [NEW] Add Vault Files & Key
        if ($incVault) {
            $vaultPath = realpath(__DIR__ . '/../vault');
            if ($mode === 'server') {
                // MIRROR MODE (When saving directly to server)
                $customPath = $settings['backup_path'] ?? '';
                $primaryPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : realpath(__DIR__ . '/../backups');
                $mirrorPath = rtrim($primaryPath, '/\\') . DIRECTORY_SEPARATOR . 'vault_mirror';
                if (!is_dir($mirrorPath)) @mkdir($mirrorPath, 0755, true);

                if ($vaultPath && is_dir($vaultPath)) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $src = $file->getRealPath();
                            $dest = $mirrorPath . DIRECTORY_SEPARATOR . $file->getFilename();
                            if (!file_exists($dest) || filemtime($src) > filemtime($dest) || filesize($src) !== filesize($dest)) {
                                @copy($src, $dest);
                            }
                        }
                    }
                }
            } else {
                // DOWNLOAD MODE: Standard ZIP Addition
                if ($vaultPath && is_dir($vaultPath)) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = 'vault/' . substr($filePath, strlen($vaultPath) + 1);
                            $zip->addFile($filePath, $relativePath);
                            if ($password) $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256, $password);
                        }
                    }
                }
            }
        }
        $zip->close();

        $final_filename = ($incVault ? "FULL_SYSTEM_" : "Encrypted_Backup_") . date("Y-m-d_H-i-s") . ".zip";
        $final_mimetype = 'application/zip';
    } else {
        $error_msg = "❌ Failed to create ZIP archive.";
        if ($mode === 'server') {
            header("Location: manager_user.php?error=" . urlencode($error_msg));
            exit;
        } else {
            die($error_msg);
        }
    }
} else {
    $final_filename = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
    $final_mimetype = 'application/octet-stream';
}

$logger = new Logger($pdo);

if ($mode === 'server') {
    // [FIX] Use path from settings or default
    $customPath = $settings['backup_path'] ?? '';
    $primaryPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : realpath(__DIR__ . '/../backups');
    if (!is_dir($primaryPath)) @mkdir($primaryPath, 0755, true);
    $fullPath = rtrim($primaryPath, '/\\') . '/' . $final_filename;

    $saved = false;
    if ($useZip && file_exists($tempZipPath)) {
        $saved = copy($tempZipPath, $fullPath);
    } else {
        $saved = copy($tmpSqlFile, $fullPath);
    }

    if ($saved) {
        $msg = "✅ Backup saved to Primary: " . basename($fullPath);
        $secondaryPath = null; // Removed ENV dependency for consistency
        if ($secondaryPath) {
            if (!is_dir($secondaryPath)) @mkdir($secondaryPath, 0755, true);
            $secFile = rtrim($secondaryPath, '/\\') . '/' . $final_filename;
            if ($useZip ? copy($tempZipPath, $secFile) : copy($tmpSqlFile, $secFile)) {
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
    if ($useZip && file_exists($tempZipPath)) {
        unlink($tempZipPath);
    }
} else {
    $logger->log($_SESSION['user_id'], 'SYSTEM_BACKUP', 'Admin downloaded full database backup.');

    // [FIX] Clear output buffer to prevent ZIP corruption
    if (ob_get_length()) ob_end_clean();

    header('Content-Type: ' . $final_mimetype);
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"" . $final_filename . "\"");

    if ($useZip && file_exists($tempZipPath)) {
        header('Content-Length: ' . filesize($tempZipPath));
        readfile($tempZipPath);
        unlink($tempZipPath);
    } else {
        header('Content-Length: ' . filesize($tmpSqlFile));
        readfile($tmpSqlFile);
    }

    @unlink($tmpSqlFile);
    exit;
}
