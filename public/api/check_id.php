<?php
require '../../config/db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = strtoupper(trim($_GET['id'] ?? ''));

if (empty($id)) {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $pdo->prepare("SELECT deleted_at FROM employees WHERE emp_id = ?");
$stmt->execute([$id]);
$res = $stmt->fetch();

echo json_encode([
    'exists' => (bool)$res,
    'status' => $res ? ($res['deleted_at'] ? 'deleted' : 'active') : null
]);
