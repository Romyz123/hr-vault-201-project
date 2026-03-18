<?php
// ======================================================
// [FILE] public/test_backup_path.php
// [PURPOSE] AJAX endpoint to verify Backup Path write access
// ======================================================

require '../config/db.php';
session_start();

header('Content-Type: application/json');

// 1. SECURITY: Admin Only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (empty($_SESSION['csrf_token']) || empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Security token mismatch. Please refresh.']);
    exit;
}

$path = trim($_POST['path'] ?? '');

if (empty($path)) {
    echo json_encode(['status' => 'error', 'message' => 'No path provided.']);
    exit;
}

if (is_link($path)) {
    echo json_encode(['status' => 'error', 'message' => 'Symbolic links are not allowed for backup paths.']);
    exit;
}

$realPath = realpath($path);
$allowedBase = realpath(__DIR__ . '/../backups');

if (!$realPath || !is_dir($realPath)) {
    echo json_encode(['status' => 'error', 'message' => "The directory does not exist or is not accessible. Please create the folder on the server first."]);
    exit;
}

if ($allowedBase) {
    $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($realPath, $allowedPrefix, strlen($allowedPrefix)) !== 0) {
        echo json_encode(['status' => 'error', 'message' => 'The directory must be inside the allowed backup directory.']);
        exit;
    }
}

// 2. PERFORM WRITE TEST
$tempFile = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'hr_write_test_' . time() . '.tmp';
if (@file_put_contents($tempFile, 'test_write_access')) {
    @unlink($tempFile); // Cleanup immediately

    // [SECURITY] Check if saving to the same physical drive (Windows)
    if (PHP_OS_FAMILY === 'Windows') {
        $appRealPath = realpath(__DIR__);
        $targetRealPath = $realPath;
        if ($appRealPath !== false && $targetRealPath !== false) {
            $appDrive = strtoupper(substr($appRealPath, 0, 2));
            $targetDrive = strtoupper(substr($targetRealPath, 0, 2));
            if ($appDrive === $targetDrive) {
                echo json_encode(['status' => 'warning', 'message' => "Verified and writable!\n\nHOWEVER: You are saving backups to the same physical drive ($appDrive) as the system. If this drive fails, you will lose both the system and the backups. Consider using an external USB or network drive."]);
                exit;
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => "Verified! The server successfully wrote to the directory."]);
} else {
    echo json_encode(['status' => 'error', 'message' => "The directory exists, but the web server does not have write permissions. Please check folder security properties."]);
}
exit;
