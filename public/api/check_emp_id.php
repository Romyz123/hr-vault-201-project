<?php
// ======================================================
// [FILE] public/api/check_emp_id.php
// [PURPOSE] Real-time partial ID matching for Add/Edit Employee
// ======================================================

require_once '../../config/db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$id = trim($_GET['id'] ?? '');
if (strlen($id) < 3) exit(json_encode(['similar' => []]));

$stmt = $pdo->prepare("SELECT emp_id, last_name FROM employees WHERE emp_id LIKE ? AND deleted_at IS NULL LIMIT 5");
$stmt->execute([$id . '%']);
echo json_encode(['similar' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
