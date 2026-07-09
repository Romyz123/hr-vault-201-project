<?php
// Part of edit_employee.php
// This file is included from edit_employee.php and expects $pdo, $id, $_SESSION, $logger, and $emp to be set.

if (!isset($pdo, $id, $_SESSION['user_id'], $logger, $emp)) {
    die('This script is a component and cannot be accessed directly.');
}

// [FIX] Auto-repair: Ensure the employment_history table exists to prevent 500 errors.
try {
    $pdo->query("SELECT 1 FROM employment_history LIMIT 1");
} catch (PDOException $e) {
    // Table doesn't exist, create it.
    $createSql = "CREATE TABLE employment_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        event_title VARCHAR(100) NOT NULL,
        event_date DATE NOT NULL,
        department VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_emp_hist (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($createSql);
}

$action = $_POST['action'] ?? '';

// Handle Add History Event
if ($action === 'add_history') {
    if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
        $title = trim($_POST['event_title'] ?? '');
        $date  = trim($_POST['event_date'] ?? '');
        $dept  = trim($_POST['department'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (empty($title) || empty($date)) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Title and Date are required."));
            exit;
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $dateErrors = DateTime::getLastErrors();
        if (!$dateObj || $dateObj->format('Y-m-d') !== $date || $dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Invalid date format."));
            exit;
        }

        // Validate field lengths
        if (strlen($title) > 100) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Event title is too long (Max 100 chars)."));
            exit;
        }
        if (strlen($dept) > 100) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Department is too long (Max 100 chars)."));
            exit;
        }
        if (strlen($notes) > 1000) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Notes are too long (Max 1000 chars)."));
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO employment_history (employee_id, event_title, event_date, department, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $title, $date, $dept, $notes]);

        $logger->log($_SESSION['user_id'], 'ADD_HISTORY', "Added history event for {$emp['emp_id']}: $title");
        header("Location: edit_employee.php?id=$id&tab=history&msg=" . urlencode("✅ History event added."));
        exit;
    } else {
        // [FIX] Handle unauthorized attempts gracefully
        header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ You do not have permission to add history events."));
        exit;
    }
}
// Handle Delete History Event
if ($action === 'delete_history') {
    if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
        $histId = (int)$_POST['history_id'];
        $stmt = $pdo->prepare("DELETE FROM employment_history WHERE id = ? AND employee_id = ?");
        $stmt->execute([$histId, $id]);
        if ($stmt->rowCount() === 0) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ History event not found or access denied."));
            exit;
        }
        $logger->log($_SESSION['user_id'], 'DELETE_HISTORY', "Deleted history event ID $histId for employee ID $id");
        header("Location: edit_employee.php?id=$id&tab=history&msg=" . urlencode("✅ Event deleted."));
        exit;
    } else {
        header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ You do not have permission to delete history events."));
        exit;
    }
}
