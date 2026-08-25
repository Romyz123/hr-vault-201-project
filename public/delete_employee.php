<?php
// public/delete_employee.php
require '../config/db.php';
require '../src/Logger.php';
require '../src/Security.php';
session_start();


// 1. SECURITY: Admin/HR Only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'], true)) {
    die("ACCESS DENIED");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $security = new Security($pdo);
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security Token Mismatch.";
        header("Location: index.php");
        exit;
    }

    $id = $_POST['id'] ?? 0;

    // 2. GET EMPLOYEE INFO (To log the name)
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $emp = $stmt->fetch();

    if ($emp) {
        try {
            $pdo->beginTransaction(); // [SECURITY] Wrap in transaction

            // [FIX] Soft Delete instead of Hard Delete to allow for accidental recovery.
            // Physical files and document records are preserved in the vault and database.
            // Associated documents are automatically hidden from the main directory via the deleted_at check.
            $del = $pdo->prepare("UPDATE employees SET deleted_at = NOW() WHERE id = ?");
            $del->execute([$id]);

            // 4. LOG IT
            $logger = new Logger($pdo);
            // [IMPROVEMENT] Save full data snapshot for recovery
            $snapshot = json_encode($emp);
            $logger->log($_SESSION['user_id'], 'SOFT_DELETE_EMPLOYEE', "Moved to Recycle Bin: " . $emp['emp_id'] . " | DATA: " . $snapshot);

            $pdo->commit();

            header("Location: index.php?msg=" . urlencode("🗑️ Employee moved to Recovery Console (Soft-Deleted)"));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // If linked data exists (Foreign Key Error)
            $errorMsg = "Failed to soft-delete: " . $e->getMessage();
            header("Location: edit_employee.php?id=$id&error=" . urlencode($errorMsg));
            exit;
        }
    }
}
header("Location: index.php");
