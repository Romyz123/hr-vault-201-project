<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit;
}

$security = new Security($pdo);
try {
    $security->checkCSRF($_POST['csrf_token'] ?? '');
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token Invalid']);
    exit;
}

$path = trim($_POST['path'] ?? '');
if (empty($path)) {
    echo json_encode(['status' => 'error', 'message' => 'Path cannot be empty.']);
    exit;
}

// 1. Block Traversal Sequences and protocol wrappers
if (strpos($path, '..') !== false || preg_match('/[<>"|?*]/', $path) || strpos($path, '://') !== false) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid path format.']);
    exit;
}

// 2. System Folder Blacklist (MHI Compliance)
$forbidden = [
    'C:\\Windows',
    'C:\\Program Files',
    'C:\\Users',
    'C:\\inetpub',
    '/etc',
    '/var',
    '/usr',
    '/bin',
    '/sbin',
    '/root',
    '/boot',
    '/dev'
];
$normPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
foreach ($forbidden as $f) {
    if (stripos($normPath, $f) === 0) {
        echo json_encode(['status' => 'error', 'message' => "Access Denied: '$f' is a sensitive system directory."]);
        exit;
    }
}

// 3. Check existence and writability
if (file_exists($path)) {
    if (!is_dir($path)) {
        echo json_encode(['status' => 'error', 'message' => 'The path exists but is not a directory.']);
    } elseif (!is_writable($path)) {
        echo json_encode(['status' => 'error', 'message' => 'The directory is not writable by the web server.']);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Path verified and writable!']);
    }
} else {
    // Attempt to see if we can create it (check parent)
    $parent = dirname($path);
    if (file_exists($parent) && is_dir($parent) && is_writable($parent)) {
        echo json_encode(['status' => 'warning', 'message' => 'The folder does not exist yet, but the parent directory is writable. The system will attempt to create it during the next backup.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'The path does not exist and the parent directory is not writable.']);
    }
}
