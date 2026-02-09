<?php
// public/api/search_suggestions.php
require '../../config/db.php';

// Set header to JSON
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$q = $_GET['q'] ?? '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Search by ID, First Name, or Last Name (Active employees only)
$stmt = $pdo->prepare("SELECT emp_id, first_name, last_name FROM employees WHERE (emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?) AND status = 'Active' LIMIT 10");
$term = "%$q%";
$stmt->execute([$term, $term, $term]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
