<?php
// public/api/search_suggestions.php
require '../../config/db.php';

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Search for matches in First Name, Last Name, or ID
// LIMIT 10 to keep it fast
$stmt = $pdo->prepare("
    SELECT id, emp_id, first_name, last_name, avatar_path 
    FROM employees 
    WHERE first_name LIKE ? OR last_name LIKE ? OR emp_id LIKE ?
    LIMIT 10
");

$term = "%$query%";
$stmt->execute([$term, $term, $term]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
?>
