<?php
// public/api/search_suggestions.php
require '../../config/db.php';
session_start();

// 1. SECURITY CHECK (New)
// Stop access if not logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

header('Content-Type: application/json');

$q = $_GET['q'] ?? '';

// Require at least 2 characters
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// 2. SEARCH
$stmt = $pdo->prepare("SELECT emp_id, first_name, last_name FROM employees WHERE emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? LIMIT 5");
$term = "%$q%";
$stmt->execute([$term, $term, $term]);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
?>