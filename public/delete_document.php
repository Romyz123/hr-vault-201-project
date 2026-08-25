<?php
// public/delete_document.php
require '../config/db.php';
require '../src/Logger.php';
require '../src/Security.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// 1. SECURITY: Allow ADMIN and HR only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    $_SESSION['error'] = "Access Denied: You do not have permission to delete files.";
    header("Location: index.php");
    exit;
}

// 2. INPUT CHECK: We expect POST from the Dashboard button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $security = new Security($pdo);
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security Token Mismatch. Please try again.";
        header("Location: index.php");
        exit;
    }

    $uuid = $_POST['file_uuid'] ?? '';
    $empId = $_POST['emp_id'] ?? '';
    $action = $_POST['action'] ?? 'soft_delete'; // Default to soft delete

    // [NEW] HANDLE EMPTY BIN (Bulk Permanent Delete)
    if ($action === 'empty_bin') {
        $logger = new Logger($pdo);

        // 1. Fetch all deleted files to remove from disk
        $stmt = $pdo->query("SELECT file_path, category FROM documents WHERE deleted_at IS NOT NULL");
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $f) {
            $path = $vaultPath . $f['file_path'];

            // Check for Disciplinary files in uploads/ (Fallback)
            if (!file_exists($path)) {
                $altPath = __DIR__ . '/uploads/' . $f['file_path'];
                if (file_exists($altPath)) {
                    $path = $altPath;
                }
            }

            if (file_exists($path)) {
                @unlink($path);
            }
        }

        // 2. Delete from DB
        $count = $pdo->exec("DELETE FROM documents WHERE deleted_at IS NOT NULL");

        $logger->log($_SESSION['user_id'], "EMPTY_BIN", "Emptied Recycle Bin ($count files)");

        header("Location: recycle_bin.php?msg=" . urlencode("Recycle Bin Emptied ($count files deleted)"));
        exit;
    }

    // [NEW] HANDLE RESTORE ALL (Bulk Restore)
    if ($action === 'restore_all') {
        $logger = new Logger($pdo);
        $count = $pdo->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NOT NULL")->fetchColumn();

        if ($count > 0) {
            $pdo->exec("UPDATE documents SET deleted_at = NULL WHERE deleted_at IS NOT NULL");
            $logger->log($_SESSION['user_id'], "RESTORE_ALL", "Restored all files ($count) from Recycle Bin");
            $msg = "Successfully restored $count files.";
        } else {
            $msg = "Recycle Bin is empty.";
        }
        header("Location: recycle_bin.php?msg=" . urlencode($msg));
        exit;
    }

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

            // [FIX] Check uploads folder (Disciplinary) if not in vault
            if (!file_exists($filePath)) {
                $altPath = __DIR__ . '/uploads/' . $file['file_path'];
                // Resolve real path and ensure it's within allowed directory
                $realAltPath = realpath($altPath);
                $uploadsDir = realpath(__DIR__ . '/uploads');
                if ($realAltPath && $uploadsDir) {
                    // Ensure trailing directory separator so sibling dirs with same prefix don't bypass check
                    $uploadsDirWithSep = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    if (strpos($realAltPath, $uploadsDirWithSep) === 0) {
                        $filePath = $realAltPath;
                    }
                }
            }

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
