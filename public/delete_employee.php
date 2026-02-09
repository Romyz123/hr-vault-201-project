<?php
// public/delete_employee.php
require '../config/db.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Admin/HR Only
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    die("ACCESS DENIED");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;

    // 2. GET EMPLOYEE INFO (To log the name)
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $emp = $stmt->fetch();

    if ($emp) {
        // 3. DELETE FROM DATABASE
        // Note: This might fail if you have documents linked (Error 1701). 
        // We use a try-catch block to handle that gracefully.
        try {
            $del = $pdo->prepare("DELETE FROM employees WHERE id = ?");
            $del->execute([$id]);

            // 4. LOG IT
            $logger = new Logger($pdo);
            // [IMPROVEMENT] Save full data snapshot for recovery
            $snapshot = json_encode($emp);
            $logger->log($_SESSION['user_id'], 'DELETE_EMPLOYEE', "Deleted: " . $emp['emp_id'] . " | DATA: " . $snapshot);

            header("Location: index.php?msg=" . urlencode("✅ Employee Deleted Successfully"));
            exit;
        } catch (PDOException $e) {
            // If linked data exists (Foreign Key Error)
            $error = "Cannot delete: This employee has linked documents/history. Remove those first.";
            header("Location: edit_employee.php?id=$id&error=" . urlencode($error));
            exit;
        }
    }
}
header("Location: index.php");
