<?php
// [FIX] Start buffering immediately to catch any whitespace/BOM from includes
ob_start();
require '../config/db.php';
require '../src/Logger.php';
require '../src/FileService.php';
session_start();

if (!isset($_SESSION['user_id'])) die("Access Denied");

// Load vault path from config
$config    = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

$emp_id = $_GET['emp_id'] ?? '';
if (!$emp_id) die("Invalid ID");

// SECURITY: Verify authorization - user can only download for their own employee record or if they have admin/hr role
$authorized = false;
$userRoles = $_SESSION['role'] ?? '';
$isAdmin = in_array($userRoles, ['ADMIN', 'MANAGER', 'HR']);

if ($isAdmin) {
    // Admins, managers, HR can download any employee's files
    $authorized = true;
} else {
    // Regular staff can only download their own files
    $stmt = $pdo->prepare("SELECT emp_id FROM employees WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userEmp = $stmt->fetch();
    if ($userEmp && $userEmp['emp_id'] == $emp_id) {
        $authorized = true;
    }
}

if (!$authorized) {
    http_response_code(403);
    die("You do not have permission to download files for this employee.");
}

// Fetch files
$stmt = $pdo->prepare("SELECT * FROM documents WHERE employee_id = ?");
$stmt->execute([$emp_id]);
$files = $stmt->fetchAll();

if (!$files) die("No files found for this employee.");

// Create ZIP
$zipname = "Documents_" . $emp_id . ".zip";
// Sanitize filename to prevent HTTP header injection
$zipname = preg_replace('/[^a-zA-Z0-9_.-]/', '', $zipname);
if (empty($zipname)) {
    $zipname = "Documents.zip"; // Safe fallback
}

$zip = new ZipArchive;
$tmp_file = tempnam(sys_get_temp_dir(), 'zip');

$fileService = new FileService($vaultPath);

if ($zip->open($tmp_file, ZipArchive::CREATE) === TRUE) {
    foreach ($files as $file) {
        // [FIX] Decrypt file content before adding to ZIP
        $content = $fileService->getFileContent($file['file_path']);

        // Fallback for unencrypted Disciplinary files in uploads/
        if ($content === false && file_exists(__DIR__ . '/uploads/' . $file['file_path'])) {
            $content = file_get_contents(__DIR__ . '/uploads/' . $file['file_path']);
        }

        if ($content !== false) {
            $zip->addFromString($file['original_name'], $content);
        }
    }

    // Capture file count BEFORE closing
    $fileCount = $zip->numFiles;
    $zip->close();

    // Check if ZIP has any files
    if ($fileCount === 0) {
        unlink($tmp_file);
        http_response_code(404);
        die("No valid files to download. All files may have been deleted.");
    }

    // [LOGGING]
    $logger = new Logger($pdo);
    $logger->log($_SESSION['user_id'], 'DOWNLOAD_ZIP', "Downloaded files for Employee ID: $emp_id");

    // [FIX] Aggressively clean ALL output buffers (Loop until empty)
    while (ob_get_level()) ob_end_clean();

    // [FIX] Disable compression to prevent "Corrupted" errors on download
    if (ini_get('zlib.output_compression')) {
        ini_set('zlib.output_compression', 'Off');
    }

    // Serve file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipname . '"');
    header('Content-Length: ' . filesize($tmp_file));
    readfile($tmp_file);
    unlink($tmp_file); // Clean up
} else {
    if (file_exists($tmp_file)) {
        unlink($tmp_file);
    }
    http_response_code(500);
}
