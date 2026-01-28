<?php
// public/delete_document.php
require '../config/db.php';
require '../src/Logger.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? __DIR__ . '/../vault/';

// 1. SECURITY: Allow ADMIN and HR only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    $_SESSION['error'] = "Access Denied: You do not have permission to delete files.";
    header("Location: index.php");
    exit;
}

// 2. INPUT CHECK: We expect POST from the Dashboard button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $uuid = $_POST['file_uuid'] ?? '';
    $empId = $_POST['emp_id'] ?? '';
    $action = $_POST['action'] ?? 'soft_delete'; // Default to soft delete

    // 3. GET FILE PATH & NAME (Needed for logging)
    $stmt = $pdo->prepare("SELECT file_path, original_name FROM documents WHERE file_uuid = ?");
    $stmt->execute([$uuid]);
    $file = $stmt->fetch();

    if ($file) {
        $logger = new Logger($pdo);

        // --- A. SOFT DELETE (Move to Recycle Bin) ---
        if ($action === 'soft_delete') {
            $upd = $pdo->prepare("UPDATE documents SET deleted_at = NOW() WHERE file_uuid = ?");
            $upd->execute([$uuid]);

            $logger->log($_SESSION['user_id'], "TRASH_DOC", "Moved to Recycle Bin: " . $file['original_name']);
            $msg = "File moved to Recycle Bin (Restorable for 30 days)";
        }

        // --- B. RESTORE (From Recycle Bin) ---
        elseif ($action === 'restore') {
            $upd = $pdo->prepare("UPDATE documents SET deleted_at = NULL WHERE file_uuid = ?");
            $upd->execute([$uuid]);

            $logger->log($_SESSION['user_id'], "RESTORE_DOC", "Restored file: " . $file['original_name']);
            $msg = "File Restored Successfully";
            // Redirect back to Recycle Bin if restoring from there
            header("Location: recycle_bin.php?msg=" . urlencode($msg));
            exit;
        }

        // --- C. PERMANENT DELETE (Hard Delete) ---
        elseif ($action === 'permanent_delete') {
            // 1. Delete Physical File
            $filePath = $vaultPath . $file['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            // 2. Delete DB Record
            $del = $pdo->prepare("DELETE FROM documents WHERE file_uuid = ?");
            $del->execute([$uuid]);

            $logger->log($_SESSION['user_id'], "PERM_DELETE", "Permanently deleted: " . $file['original_name']);
            $msg = "File Permanently Deleted";

            header("Location: recycle_bin.php?msg=" . urlencode($msg));
            exit;
        }

        // 6. REDIRECT
        $redirectUrl = "index.php?msg=" . urlencode($msg);
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
