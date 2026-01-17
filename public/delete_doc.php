<?php
require '../config/db.php';
session_start();

// Security: Only Admins can delete
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("ACCESS DENIED");
}

if (isset($_GET['id'])) {
    $uuid = $_GET['id'];

    // 1. Get file path
    $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE file_uuid = ?");
    $stmt->execute([$uuid]);
    $file = $stmt->fetch();

    if ($file) {
        // 2. Delete Physical File
        $fullPath = $_ENV['VAULT_PATH'] . $file['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        // 3. Delete Database Record
        $del = $pdo->prepare("DELETE FROM documents WHERE file_uuid = ?");
        $del->execute([$uuid]);

        header("Location: index.php?msg=File Deleted Successfully");
        exit;
    }
}
header("Location: index.php?error=File not found");
?>