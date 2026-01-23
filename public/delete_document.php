<?php
// public/delete_document.php
require '../config/db.php';
require '../src/Logger.php'; 
session_start();

// 1. SECURITY: Allow ADMIN and HR only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    $_SESSION['error'] = "Access Denied: You do not have permission to delete files.";
    header("Location: index.php");
    exit;
}

// 2. INPUT CHECK: We expect POST from the Dashboard button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_uuid'])) {
    
    $uuid = $_POST['file_uuid'];
    $empId = $_POST['emp_id'] ?? ''; 

    // 3. GET FILE PATH & NAME (Needed for logging)
    $stmt = $pdo->prepare("SELECT file_path, original_name FROM documents WHERE file_uuid = ?");
    $stmt->execute([$uuid]);
    $file = $stmt->fetch();

    if ($file) {
        // 4. DELETE PHYSICAL FILE
        $filePath = "uploads/" . $file['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath); 
        }

        // 5. DELETE DATABASE RECORD
        $del = $pdo->prepare("DELETE FROM documents WHERE file_uuid = ?");
        $del->execute([$uuid]);

        // ---------------------------------------------------------
        // [FIX] ENABLE LOGGING
        // ---------------------------------------------------------
        $logger = new Logger($pdo);
        $logger->log($_SESSION['user_id'], "DELETE_DOC", "Deleted file: " . $file['original_name']);
        // ---------------------------------------------------------

        // 6. REDIRECT
        $redirectUrl = "index.php?msg=File Deleted Successfully";
        if (!empty($empId)) {
            $redirectUrl .= "&search=" . urlencode($empId); 
        }
        header("Location: " . $redirectUrl);
        exit;
    } else {
        $_SESSION['error'] = "Error: File record not found in database.";
    }
} else {
    $_SESSION['error'] = "Invalid Request.";
}

header("Location: index.php");
?>