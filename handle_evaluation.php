<?php
// Part of edit_employee.php
// This file is included from edit_employee.php and expects $pdo, $id, and $_SESSION to be set.

if (!isset($pdo, $id, $_SESSION['user_id'], $logger)) {
    die('This script is a component and cannot be accessed directly.');
}

$action = $_POST['action'] ?? '';

// Handle Add Evaluation
if ($action === 'add_eval') {
    $eval_date = $_POST['eval_date'];
    $score = (int)$_POST['score'];
    $remarks = trim($_POST['remarks']);
    $evaluator = trim($_POST['evaluator']);

    // [SECURITY] Validation
    if ($score < 1 || $score > 100) {
        header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Score must be between 1 and 100."));
        exit;
    }
    if (strlen($evaluator) > 100) {
        header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Evaluator name is too long (Max 100 chars)."));
        exit;
    }
    if (!preg_match('/^[a-zA-Z\s\-\.\,]+$/', $evaluator)) {
        header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Evaluator name contains invalid characters (Letters only)."));
        exit;
    }
    if (strlen($remarks) > 1000) {
        header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Remarks are too long (Max 1000 chars)."));
        exit;
    }

    // Auto-Rating
    $rating = 'Poor';
    if ($score >= 90) $rating = 'Excellent';
    elseif ($score >= 80) $rating = 'Very Good';
    elseif ($score >= 70) $rating = 'Satisfactory';
    elseif ($score >= 60) $rating = 'Needs Improvement';

    $stmt = $pdo->prepare("INSERT INTO performance_evaluations (employee_id, eval_date, score, rating, remarks, evaluator) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $eval_date, $score, $rating, $remarks, $evaluator]);
    header("Location: edit_employee.php?id=$id&tab=eval&msg=" . urlencode("✅ Evaluation Added"));
    exit;
}

// Handle Edit Evaluation
if ($action === 'edit_eval') {
    $eval_id = (int)$_POST['eval_id'];
    $eval_date = $_POST['eval_date'];
    $score = (int)$_POST['score'];
    $remarks = trim($_POST['remarks']);
    $evaluator = trim($_POST['evaluator']);

    // [SECURITY] Validation
    if ($score < 1 || $score > 100) {
        header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Score must be between 1 and 100."));
        exit;
    }
    if (!preg_match('/^[a-zA-Z\s\-\.\,]+$/', $evaluator)) {
        header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Evaluator name contains invalid characters (Letters only)."));
        exit;
    }

    // Auto-Rating
    $rating = 'Poor';
    if ($score >= 90) $rating = 'Excellent';
    elseif ($score >= 80) $rating = 'Very Good';
    elseif ($score >= 70) $rating = 'Satisfactory';
    elseif ($score >= 60) $rating = 'Needs Improvement';

    $stmt = $pdo->prepare("UPDATE performance_evaluations SET eval_date = ?, score = ?, rating = ?, remarks = ?, evaluator = ? WHERE id = ?");
    $stmt->execute([$eval_date, $score, $rating, $remarks, $evaluator, $eval_id]);
    header("Location: edit_employee.php?id=$id&tab=eval&msg=" . urlencode("✅ Evaluation Updated"));
    exit;
}

// Handle Delete Evaluation
if ($action === 'delete_eval') {
    if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
        $delEvalId = (int)$_POST['eval_id'];
        $pdo->prepare("DELETE FROM performance_evaluations WHERE id = ?")->execute([$delEvalId]);
        header("Location: edit_employee.php?id=$id&tab=eval&msg=" . urlencode("✅ Evaluation Deleted"));
        exit;
    }
}
