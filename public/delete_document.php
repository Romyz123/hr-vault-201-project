<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Only Admin or HR can delete
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'ADMIN' && $_SESSION['role'] !== 'HR')) {
    header("Location: index.php?error=Access Denied");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file_uuid = $_POST['file_uuid'];
    $emp_id    = $_POST['emp_id']; // Needed for redirect

    // 2. FETCH FILE INFO
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE file_uuid = ?");
    $stmt->execute([$file_uuid]);
    $doc = $stmt->fetch();

    if ($doc) {
        // 3. DELETE ACTUAL FILE
        $filePath = __DIR__ . '/uploads/' . $doc['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // 4. DELETE DATABASE RECORD
        $delStmt = $pdo->prepare("DELETE FROM documents WHERE file_uuid = ?");
        $delStmt->execute([$file_uuid]);

        // 5. LOG ACTION
        $logger = new Logger($pdo);
        $logger->log($_SESSION['user_id'], 'DELETE_DOC', "Deleted file: " . $doc['original_name']);

        // Redirect back to the employee's profile
        // We use a small script to reload the modal or page
        header("Location: index.php?search=" . $emp_id . "&msg=File Deleted Successfully");
        exit;
    } else {
        header("Location: index.php?error=File not found");
        exit;
    }
}
?>