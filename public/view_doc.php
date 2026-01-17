<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Check Login
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 2. VALIDATE INPUT
$file_uuid = $_GET['id'] ?? '';
if (!preg_match('/^[a-zA-Z0-9-]+$/', $file_uuid)) {
    die("Invalid File ID");
}

// 3. FETCH FILE INFO
$stmt = $pdo->prepare("SELECT file_path, original_name FROM documents WHERE file_uuid = ?");
$stmt->execute([$file_uuid]);
$file = $stmt->fetch();

if (!$file) die("File entry not found in database.");

// 4. LOCATE FILE (Relative to this script)
// We assume 'uploads' is in the same folder as this script (public/uploads)
$uploadDir = __DIR__ . '/uploads/';
$fullPath = $uploadDir . $file['file_path'];

// 5. VERIFY FILE EXISTS
if (!file_exists($fullPath)) {
    die("Error: Physical file not found on server.");
}

// 6. SECURITY: Directory Traversal Check
$realPath = realpath($fullPath);
if ($realPath === false || strpos($realPath, realpath($uploadDir)) !== 0) {
    die("Security Violation: File Access Denied.");
}

// 7. DETERMINE CONTENT TYPE (MIME)
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime_type = 'application/octet-stream'; // Default

switch ($ext) {
    case 'pdf': $mime_type = 'application/pdf'; break;
    case 'jpg': 
    case 'jpeg': $mime_type = 'image/jpeg'; break;
    case 'png': $mime_type = 'image/png'; break;
}

// 8. STREAM THE FILE
header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
?>