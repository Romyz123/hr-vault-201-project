<?php
// public/backup.php
ob_start();
require '../config/db.php';
require '../src/Logger.php';
require '../src/Security.php';

// Ensure session is running before modifying/checking session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '1024M');

// Cleanup any leftover temp backup parts from previous sessions
if (!empty($_SESSION['backup_temp_files']) && is_array($_SESSION['backup_temp_files'])) {
    foreach ($_SESSION['backup_temp_files'] as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    unset($_SESSION['backup_temp_files']);
}

// 1. SECURITY: Only ADMIN can download backups
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    die("ACCESS DENIED: You do not have permission to download backups.");
}

// MULTI-PART DOWNLOAD HANDLER
if (isset($_GET['download_part'])) {
    $security = new Security($pdo);
    $token = $_GET['csrf_token'] ?? '';
    try {
        $security->checkCSRF($token);
    } catch (Exception $e) {
        http_response_code(403);
        exit;
    }

    $requested = basename($_GET['download_part']);
    if (!preg_match('/^(FULL_SYSTEM_|Encrypted_Backup_)[A-Za-z0-9_-]+_Part\d+\.zip$/', $requested)) {
        http_response_code(404);
        exit;
    }
    $tempDir = __DIR__ . '/../backups/temp_downloads';
    if (!is_dir($tempDir)) {
        if (!mkdir($tempDir, 0700, true)) {
            http_response_code(500);
            exit("Server Error: Cannot create temp directory.");
        }
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\n");
    }
    $tempDirReal = realpath($tempDir);

    $filePath = realpath($tempDirReal . DIRECTORY_SEPARATOR . $requested);
    $dirPrefix = rtrim($tempDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!$tempDirReal || !$filePath || strncmp($filePath, $dirPrefix, strlen($dirPrefix)) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit;
    }

    while (ob_get_level()) ob_end_clean();
    if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');

    session_write_close();

    ignore_user_abort(true);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $requested . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    exit;
}

// Verify CSRF Token
$security = new Security($pdo);
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die("Security Error: Invalid CSRF Token.");
}

// Fetch System Settings
$config = require '../config/config.php';
$backupRoot = rtrim((string)($config['BACKUP_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups'), '/\\');
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) {
    http_response_code(500);
    exit('Backup directory is unavailable.');
}
if (!is_writable($backupRoot)) {
    http_response_code(500);
    exit('Backup directory is not writable.');
}

$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
}

$maxSizeGB = (float)($settings['backup_max_size_gb'] ?? 1.9);
$maxSizeBytes = $maxSizeGB * 1024 * 1024 * 1024;

// CONFIGURATION & TABLES
$tables = [];
$query = $pdo->query('SHOW TABLES');
while ($row = $query->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

$mode = $_GET['mode'] ?? 'download';
$password = !empty($_POST['backup_password']) ? trim($_POST['backup_password']) : '';
if (strlen($password) > 50) {
    die("Error: Password is too long (Max 50 characters).");
}

$incVault = isset($_POST['include_vault']);
$useZip = ($password || $incVault);
$generatedZips = [];
$pendingUnlink = [];

// Generate Database SQL Dump File safely
$tmpSqlFile = tempnam(sys_get_temp_dir(), 'hr201_bk_');
$pendingUnlink[] = $tmpSqlFile;
$handle = fopen($tmpSqlFile, 'w');
if (!$handle) {
    die("Server Error: Unable to create temporary storage for database dump.");
}

fwrite($handle, "-- MANUAL BACKUP\nSET FOREIGN_KEY_CHECKS=0;\n\n");
foreach ($tables as $table) {
    $q = $pdo->query("SHOW CREATE TABLE `$table` ");
    $res = $q ? $q->fetch(PDO::FETCH_NUM) : false;
    if (!$res) continue;

    fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n" . $res[1] . ";\n\n");

    $stmt = $pdo->prepare("SELECT * FROM `$table` ");
    $stmt->execute();
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map(fn($v) => ($v === null) ? "NULL" : $pdo->quote((string)$v), $r);
        fwrite($handle, "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n");
    }
    fwrite($handle, "\n");
}
fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;");
fflush($handle);
fclose($handle);
$handle = null;

clearstatcache(true, $tmpSqlFile);

if ($useZip) {
    $tempDir = __DIR__ . '/../backups/temp_downloads';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0700, true);
        @file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\n");
    }

    $sql_filename_in_zip = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
    $baseFilename = ($incVault ? "FULL_SYSTEM_" : "Encrypted_Backup_") . date("Y-m-d_H-i-s");
    $partNumber = 1;
    $currentBytes = 0;
    $zip = null;

    $cleanupOnError = function ($msg) use (&$generatedZips, &$pendingUnlink) {
        foreach ($generatedZips as $f) {
            if (file_exists($f)) @unlink($f);
        }
        foreach ($pendingUnlink as $f) {
            if (file_exists($f)) @unlink($f);
        }
        exit($msg);
    };

    $startNewZip = function () use (&$zip, &$generatedZips, $tempDir, $baseFilename, &$partNumber, &$currentBytes, $cleanupOnError) {
        if ($zip instanceof ZipArchive) {
            @$zip->close();
        }
        $path = $tempDir . DIRECTORY_SEPARATOR . $baseFilename . "_Part{$partNumber}.zip";
        if ($path === '' || !is_dir($tempDir)) {
            $cleanupOnError("Server Error: Invalid ZIP target path.");
        }
        $generatedZips[] = $path;
        if (!isset($_SESSION['backup_temp_files']) || !is_array($_SESSION['backup_temp_files'])) {
            $_SESSION['backup_temp_files'] = [];
        }
        $_SESSION['backup_temp_files'][] = $path;
        $zip = new ZipArchive();
        if (!$zip instanceof ZipArchive || $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            $zip = null;
            $cleanupOnError("Server Error: Could not create split ZIP.");
        }
        $currentBytes = 0;
    };

    $sqlSize = filesize($tmpSqlFile);

    if ($zip === null) {
        $startNewZip();
    }

    if ($zip instanceof ZipArchive && file_exists($tmpSqlFile) && $sqlSize > 0) {
        $zip->addFile($tmpSqlFile, $sql_filename_in_zip);
        if ($password) {
            if (!$zip->setEncryptionName($sql_filename_in_zip, ZipArchive::EM_AES_256, $password)) {
                $cleanupOnError("Server Error: Encryption failed for $sql_filename_in_zip.");
            }
        }
    } else {
        $cleanupOnError("Server Error: Temporary SQL file is missing or invalid.");
    }
    $currentBytes += $sqlSize;

    if ($incVault) {
        $vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
        if ($mode === 'server') {
            $customPath = $settings['backup_path'] ?? '';
            $primaryPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : $backupRoot;
            $mirrorPath = rtrim($primaryPath, '/\\') . DIRECTORY_SEPARATOR . 'vault_mirror';
            if (!is_dir($mirrorPath)) @mkdir($mirrorPath, 0755, true);

            if ($vaultPath && is_dir($vaultPath)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $src = $file->getRealPath();
                        $relativePath = substr($src, strlen($vaultPath) + 1);
                        $dest = $mirrorPath . DIRECTORY_SEPARATOR . $relativePath;
                        if (!file_exists($dest) || filemtime($src) > filemtime($dest) || filesize($src) !== filesize($dest)) {
                            if (!@copy($src, $dest)) {
                                error_log("Failed to copy vault file: $src to $dest");
                            }
                        }
                    }
                }
            }
        } else {
            if ($vaultPath && is_dir($vaultPath)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $fsize = filesize($filePath);

                        if ($currentBytes > 0 && ($currentBytes + $fsize > $maxSizeBytes) && $zip instanceof ZipArchive) {
                            $partNumber++;
                            $startNewZip();
                        }
                        $relativePath = 'vault/' . substr($filePath, strlen($vaultPath) + 1);

                        if ($zip instanceof ZipArchive) {
                            $zip->addFile($filePath, $relativePath);
                            if ($password) {
                                if (!$zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256, $password)) {
                                    $cleanupOnError("Encryption error for $relativePath.");
                                }
                            }
                        }
                        $currentBytes += $fsize;
                    }
                }
            }
        }
    }

    if ($zip instanceof ZipArchive) {
        @$zip->close();
        $zip = null;
    }

    $final_filename = ($incVault ? "FULL_SYSTEM_" : "Encrypted_Backup_") . date("Y-m-d_H-i-s") . ".zip";
    $final_mimetype = 'application/zip';
} else {
    $final_filename = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
    $final_mimetype = 'application/octet-stream';
}

$logger = new Logger($pdo);
setcookie("downloadToken", $_POST['csrf_token'] ?? '1', time() + 300, "/");

if ($mode === 'server') {
    $customPath = $settings['backup_path'] ?? '';
    $defaultBackupPath = $backupRoot;
    $primaryPath = !empty($customPath) ? $customPath : (realpath($defaultBackupPath) ?: $defaultBackupPath);

    if (!is_dir($primaryPath)) {
        @mkdir($primaryPath, 0755, true);
    }
    $primaryPath = realpath($primaryPath);
    $fullPath = rtrim($primaryPath, '/\\') . '/' . $final_filename;

    $saved = false;
    $savedDests = [];
    if ($useZip && !empty($generatedZips)) {
        $saved = true;
        $totalParts = count($generatedZips);
        $savedDests = [];
        foreach ($generatedZips as $idx => $src) {
            if (!file_exists($src)) {
                $saved = false;
                break;
            }

            $dest = $fullPath;
            if ($totalParts > 1) {
                $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
                $base = basename($fullPath, $ext ? ".{$ext}" : '');
                $dest = dirname($fullPath) . DIRECTORY_SEPARATOR . $base . '_Part' . ($idx + 1) . ($ext ? ".{$ext}" : '');
            }

            if (!copy($src, $dest)) {
                $saved = false;
                foreach ($savedDests as $d) {
                    if (file_exists($d)) @unlink($d);
                }
                break;
            }
            $savedDests[] = $dest;
        }
    } else {
        $saved = copy($tmpSqlFile, $fullPath);
    }

    if ($saved) {
        $msg = "✅ Backup saved to Primary: " . basename($fullPath);

        $secondaryPath = $settings['secondary_backup_path'] ?? '';
        if ($secondaryPath) {
            if (!is_dir($secondaryPath) && !@mkdir($secondaryPath, 0755, true)) {
                error_log("BACKUP ERROR: Could not create secondary directory: $secondaryPath");
            }
            $secSuccess = true;
            foreach ($savedDests as $d) {
                $secFile = rtrim($secondaryPath, '/\\') . DIRECTORY_SEPARATOR . basename($d);
                if (!@copy($d, $secFile)) {
                    $secSuccess = false;
                    error_log("BACKUP ERROR: Failed to mirror file to secondary path: $secFile.");
                }
            }
            if ($secSuccess) $msg .= " AND Secondary Location.";
        }

        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('backup_last_status', 'OK') ON DUPLICATE KEY UPDATE setting_value = 'OK'");

        if ($useZip && !empty($generatedZips)) {
            foreach ($generatedZips as $gz) {
                if (file_exists($gz)) @unlink($gz);
            }
        }
        if (file_exists($tmpSqlFile)) @unlink($tmpSqlFile);

        $logger->log($_SESSION['user_id'], 'MANUAL_BACKUP_SERVER', "Triggered manual backup to server drives.");
        header("Location: manager_user.php?msg=" . urlencode($msg));
        exit;
    } else {
        if ($useZip && !empty($generatedZips)) {
            foreach ($generatedZips as $gz) {
                if (file_exists($gz)) @unlink($gz);
            }
        }
        if (file_exists($tmpSqlFile)) @unlink($tmpSqlFile);

        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('backup_last_status', 'FAILED') ON DUPLICATE KEY UPDATE setting_value = 'FAILED'");

        header("Location: manager_user.php?error=" . urlencode("❌ Failed to write to backup path. Check folder permissions."));
        exit;
    }
} else {
    $logger->log($_SESSION['user_id'], 'SYSTEM_BACKUP', 'Admin downloaded full database backup.');

    if (ob_get_length()) ob_end_clean();
    if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');

    session_write_close();

    if ($useZip && count($generatedZips) > 1) {
        $downloadLinks = [];
        foreach ($generatedZips as $path) {
            $downloadLinks[] = 'backup.php?download_part=' . urlencode(basename($path)) . '&csrf_token=' . urlencode($_POST['csrf_token'] ?? '');
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
                    <button type="button" id="startDlBtn" class="btn btn-primary fw-bold" onclick="dl()"><i class="bi bi-download"></i> Start Automatic Downloads</button>
                    <hr>
                    <?php foreach ($downloadLinks as $idx => $link): ?>
                        <a href="<?php echo $link; ?>" class="btn btn-outline-dark" target="_blank"><i class="bi bi-file-zip"></i> Download Part <?php echo $idx + 1; ?></a>
                    <?php endforeach; ?>
                </div>
                <p class="small text-muted mb-0">If the automatic downloads are blocked, please click the buttons above individually.</p>
                <button class="btn btn-link mt-2" onclick="window.close()">Close Window</button>
            </div>
            <script>
                const files = <?php echo json_encode($downloadLinks); ?>;
                let dlIdx = 0;

                function dl() {
                    if (document.getElementById('startDlBtn')) document.getElementById('startDlBtn').style.display = 'none';
                    if (dlIdx < files.length) {
                        document.getElementById('statusText').innerHTML = `<span class="spinner-border spinner-border-sm"></span> Downloading Part ${dlIdx+1}...`;
                        let a = document.createElement('a');
                        a.href = files[dlIdx];
                        a.download = '';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        dlIdx++;
                        setTimeout(dl, 4000);
                    } else {
                        document.getElementById('statusText').innerText = "All parts downloaded!";
                        document.getElementById('statusText').classList.replace('text-primary', 'text-success');
                    }
                }
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
        if (file_exists($generatedZips[0])) {
            @unlink($generatedZips[0]);
        }
    } else {
        header('Content-Type: ' . $final_mimetype);
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"" . $final_filename . "\"");
        header('Content-Length: ' . filesize($tmpSqlFile));
        readfile($tmpSqlFile);
    }

    if (file_exists($tmpSqlFile)) {
        @unlink($tmpSqlFile);
    }
    exit;
}
