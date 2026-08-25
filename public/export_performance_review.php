<?php
require '../config/db.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Prepare HTTP Headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Performance_Reviews_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel

// Write the Excel Column Headers
fputcsv($output, ['Review Date', 'Employee ID', 'Last Name', 'First Name', 'Rating', 'Strengths', 'Weaknesses', 'Goals', 'Reviewer']);

// Fetch and write the data
try {
    $sql = "SELECT 
                p.review_date, 
                e.emp_id, 
                e.last_name, 
                e.first_name, 
                p.rating, 
                p.strengths, 
                p.weaknesses, 
                p.goals,
                p.custom_reviewer,
                u.username,
                u.account_owner
            FROM hr_performance_reviews p
            JOIN employees e ON p.employee_id = e.id
            LEFT JOIN users u ON p.reviewer_id = u.id
            ORDER BY p.review_date DESC";

    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Logic: Custom Reviewer > Account Owner > Username
        $reviewer = !empty($row['custom_reviewer']) ? $row['custom_reviewer'] : (!empty($row['account_owner']) ? $row['account_owner'] : $row['username']);
        fputcsv($output, [
            $row['review_date'],
            $row['emp_id'],
            $row['last_name'],
            $row['first_name'],
            $row['rating'],
            $row['strengths'],
            $row['weaknesses'],
            $row['goals'],
            $reviewer
        ]);
    }
} catch (PDOException $e) {
    error_log('export_performance_review.php: Database error - ' . $e->getMessage());
    fputcsv($output, ['ERROR: Unable to retrieve data. Please contact the administrator.']);
}

fclose($output);
exit;
