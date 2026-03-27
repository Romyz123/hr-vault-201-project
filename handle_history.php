<?php
// Part of edit_employee.php
// This file is included from edit_employee.php and expects $pdo, $id, $_SESSION, $logger, and $emp to be set.

if (!isset($pdo, $id, $_SESSION['user_id'], $logger, $emp)) {
    die('This script is a component and cannot be accessed directly.');
}

$action = $_POST['action'] ?? '';

// Handle Add History Event
if ($action === 'add_history') {
    if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
        $title = trim($_POST['event_title']);
        $date  = $_POST['event_date'];
        $dept  = trim($_POST['department']);
        $notes = trim($_POST['notes']);

        if (empty($title) || empty($date)) {
            header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Title and Date are required."));
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
    }
}
// Handle Delete History Event
if ($action === 'delete_history') {
    if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
        $histId = (int)$_POST['history_id'];
        $pdo->prepare("DELETE FROM employment_history WHERE id = ?")->execute([$histId]);
        header("Location: edit_employee.php?id=$id&tab=history&msg=" . urlencode("✅ Event deleted."));
        exit;
    }
}
