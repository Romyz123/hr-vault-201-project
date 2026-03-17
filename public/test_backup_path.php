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

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'Security token mismatch. Please refresh.']);
    exit;
}

$path = trim($_POST['path'] ?? '');

if (empty($path)) {
    echo json_encode(['status' => 'error', 'message' => 'No path provided.']);
    exit;
}

if (strpos($path, '..') !== false) {
    echo json_encode(['status' => 'error', 'message' => 'Path must not contain ".." sequences.']);
    exit;
}

if (!file_exists($path)) {
    echo json_encode(['status' => 'error', 'message' => "The directory does not exist. Please create the folder on the server first."]);
    exit;
}

if (!is_dir($path)) {
    echo json_encode(['status' => 'error', 'message' => "The provided path exists but is not a directory."]);
    exit;
}

// 2. PERFORM WRITE TEST
$tempFile = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'hr_write_test_' . time() . '.tmp';
if (@file_put_contents($tempFile, 'test_write_access')) {
    @unlink($tempFile); // Cleanup immediately

    // [SECURITY] Check if saving to the same physical drive (Windows)
    if (PHP_OS_FAMILY === 'Windows') {
        $appDrive = strtoupper(substr(realpath(__DIR__), 0, 2));
        $targetDrive = strtoupper(substr(realpath($path), 0, 2));
        if ($appDrive === $targetDrive) {
            echo json_encode(['status' => 'warning', 'message' => "Verified and writable!\n\nHOWEVER: You are saving backups to the same physical drive ($appDrive) as the system. If this drive fails, you will lose both the system and the backups. Consider using an external USB or network drive."]);
            exit;
        }
    }
    echo json_encode(['status' => 'success', 'message' => "Verified! The server successfully wrote to the directory."]);
} else {
    echo json_encode(['status' => 'error', 'message' => "The directory exists, but the web server does not have write permissions. Please check folder security properties."]);
}
exit;
