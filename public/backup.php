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

// [NEW] MULTI-PART DOWNLOAD HANDLER
if (isset($_GET['download_part'])) {
    $requested = basename($_GET['download_part']);
    if (!preg_match('/^(FULL_SYSTEM_|Encrypted_Backup_)[A-Za-z0-9_-]+_Part\d+\.zip$/', $requested)) {
        http_response_code(404);
        exit;
    }
    $tempDir = realpath(__DIR__ . '/../backups/temp_downloads');
    $filePath = realpath($tempDir . DIRECTORY_SEPARATOR . $requested);
    if ($filePath && file_exists($filePath) && strpos($filePath, $tempDir) === 0) {
        while (ob_get_level()) ob_end_clean();
        if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');
        ignore_user_abort(true);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $requested . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
        exit;
    }
    die("Backup part not found or has expired.");
}

// [SECURITY] Verify CSRF Token
$security = new Security($pdo);
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die("Security Error: Invalid CSRF Token.");
}

// [NEW] Fetch System Settings
$config = require '../config/config.php';
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {
}

$maxSizeGB = (float)($settings['backup_max_size_gb'] ?? 1.9);
$maxSizeBytes = $maxSizeGB * 1024 * 1024 * 1024;

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
if ($tmpSqlFile === false) {
    die("Error: Failed to create temporary file for backup.");
}
$handle = fopen($tmpSqlFile, 'w');
if ($handle === false) {
    @unlink($tmpSqlFile);
    die("Error: Failed to open temporary file for writing.");
}
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
$generatedZips = [];

// [FIX] Force ZIP if password is set OR if vault is included
if ($useZip) {
    $tempDir = __DIR__ . '/../backups/temp_downloads';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);

    // Use a consistent name inside the zip
    $sql_filename_in_zip = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
    $baseFilename = ($incVault ? "FULL_SYSTEM_" : "Encrypted_Backup_") . date("Y-m-d_H-i-s");
    $partNumber = 1;
    $currentBytes = 0;
    $zip = null;

    $startNewZip = function () use (&$zip, &$generatedZips, $tempDir, $baseFilename, &$partNumber, &$currentBytes) {
        if ($zip instanceof ZipArchive) $zip->close();
        $path = $tempDir . DIRECTORY_SEPARATOR . $baseFilename . "_Part{$partNumber}.zip";
        $generatedZips[] = $path;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) die("Server Error: Could not create split ZIP.");
        $currentBytes = 0;
    };

    $startNewZip();

    $sqlSize = filesize($tmpSqlFile);
    if ($currentBytes > 0 && ($currentBytes + $sqlSize > $maxSizeBytes)) {
        $partNumber++;
        $startNewZip();
    }
    /** @var ZipArchive $zip */
    $zip->addFile($tmpSqlFile, $sql_filename_in_zip);
    if ($password) $zip->setEncryptionName($sql_filename_in_zip, ZipArchive::EM_AES_256, $password);
    $currentBytes += $sqlSize;

    // [NEW] Add Vault Files & Key
    if ($incVault) {
        $vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
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
                        $relativePath = substr($src, strlen($vaultPath) + 1);
                        $dest = $mirrorPath . DIRECTORY_SEPARATOR . $relativePath;
                        $destDir = dirname($dest);
                        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
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
                        $fsize = filesize($filePath);
                        if ($currentBytes > 0 && ($currentBytes + $fsize > $maxSizeBytes)) {
                            $partNumber++;
                            $startNewZip();
                        }
                        $relativePath = 'vault/' . substr($filePath, strlen($vaultPath) + 1);
                        /** @var ZipArchive $zip */
                        $zip->addFile($filePath, $relativePath);
                        if ($password) $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256, $password);
                        $currentBytes += $fsize;
                    }
                }
            }
        }
    }
    if ($zip instanceof ZipArchive) $zip->close();

    $final_filename = ($incVault ? "FULL_SYSTEM_" : "Encrypted_Backup_") . date("Y-m-d_H-i-s") . ".zip";
    $final_mimetype = 'application/zip';
} else {
    $final_filename = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
    $final_mimetype = 'application/octet-stream';
}

$logger = new Logger($pdo);

if ($mode === 'server') {
    // [FIX] Use path from settings or default
    $customPath = $settings['backup_path'] ?? '';
    $defaultBackupPath = __DIR__ . '/../backups';
    $primaryPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : (realpath($defaultBackupPath) ?: $defaultBackupPath);
    if (!is_dir($primaryPath)) @mkdir($primaryPath, 0755, true);
    $fullPath = rtrim($primaryPath, '/\\') . '/' . $final_filename;

    $saved = false;
    if ($useZip && !empty($generatedZips) && file_exists($generatedZips[0])) {
        $saved = copy($generatedZips[0], $fullPath);
    } else {
        $saved = copy($tmpSqlFile, $fullPath);
    }

    if ($saved) {
        $msg = "✅ Backup saved to Primary: " . basename($fullPath);
        $secondaryPath = null; // Removed ENV dependency for consistency
        if ($secondaryPath) {
            if (!is_dir($secondaryPath)) @mkdir($secondaryPath, 0755, true);
            $secFile = rtrim($secondaryPath, '/\\') . '/' . $final_filename;
            if ($useZip ? copy($generatedZips[0], $secFile) : copy($tmpSqlFile, $secFile)) {
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
    if ($useZip && !empty($generatedZips)) {
        foreach ($generatedZips as $gz) @unlink($gz);
    }
} else {
    $logger->log($_SESSION['user_id'], 'SYSTEM_BACKUP', 'Admin downloaded full database backup.');

    // [FIX] Clear output buffer to prevent ZIP corruption
    if (ob_get_length()) ob_end_clean();
    if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');

    // [NEW] Set cookie to tell the frontend to close the loading spinner
    setcookie("downloadToken", $_POST['csrf_token'] ?? '1', time() + 300, "/");

    if ($useZip && count($generatedZips) > 1) {
        // MULTI-PART UI & AUTO-DOWNLOADER
        $downloadLinks = [];
        foreach ($generatedZips as $path) {
            $downloadLinks[] = 'backup.php?download_part=' . urlencode(basename($path));
        }
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <title>Massive Backup Complete</title>
            <link href="assets/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
        </head>

        <body class="bg-light d-flex align-items-center justify-content-center vh-100">
            <div class="card shadow-sm p-4 text-center" style="max-width: 500px;">
                <h4 class="text-success mb-3"><i class="bi bi-check-circle-fill"></i> Backup Prepared</h4>
                <p>Your backup was massive and has been automatically split into <strong><?php echo count($generatedZips); ?></strong> parts to prevent timeouts.</p>

                <div id="statusText" class="text-primary mb-3 fw-bold"><span class="spinner-border spinner-border-sm"></span> Downloading Part 1...</div>

                <div class="d-grid gap-2 mb-3">
                    <?php foreach ($downloadLinks as $i => $link): ?>
                        <a href="<?php echo $link; ?>" class="btn btn-outline-dark" target="_blank"><i class="bi bi-file-zip"></i> Download Part <?php echo $i + 1; ?></a>
                    <?php endforeach; ?>
                </div>
                <p class="small text-muted mb-0">If the automatic downloads do not start, please click the buttons above.</p>
                <button class="btn btn-link mt-2" onclick="window.close()">Close Window</button>
            </div>
            <script>
                const files = <?php echo json_encode($downloadLinks); ?>;
                let i = 0;

                function dl() {
                    if (i < files.length) {
                        document.getElementById('statusText').innerHTML = `<span class="spinner-border spinner-border-sm"></span> Downloading Part ${i+1}...`;
                        let a = document.createElement('a');
                        a.href = files[i];
                        a.download = '';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        i++;
                        setTimeout(dl, 3000);
                    } else {
                        document.getElementById('statusText').innerText = "All parts downloaded!";
                        document.getElementById('statusText').classList.replace('text-primary', 'text-success');
                    }
                }
                setTimeout(dl, 1500);
            </script>
        </body>

        </html>
<?php
        exit;
    } elseif ($useZip && !empty($generatedZips) && file_exists($generatedZips[0])) {
        header('Content-Type: ' . $final_mimetype);
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"" . $final_filename . "\"");
        header('Content-Length: ' . filesize($generatedZips[0]));
        readfile($generatedZips[0]);
        @unlink($generatedZips[0]);
    } else {
        header('Content-Type: ' . $final_mimetype);
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"" . $final_filename . "\"");
        header('Content-Length: ' . filesize($tmpSqlFile));
        readfile($tmpSqlFile);
    }

    @unlink($tmpSqlFile);
    exit;
}
