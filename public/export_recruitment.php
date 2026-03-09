<?php
// public/export_recruitment.php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    http_response_code(403);
    exit('Unauthorized');
}
// Prepare HTTP Headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Recruitment_Report_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');

// Write the Excel Column Headers
fputcsv($output, ['ID', 'First Name', 'Last Name', 'Position', 'Status', 'Application Date', 'Last Follow-up']);

// Fetch and write the data
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, position_applied, status, application_date, last_follow_up FROM candidates ORDER BY application_date DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
} catch (PDOException $e) {
    error_log('export_recruitment.php: Database error - ' . $e->getMessage());
    fputcsv($output, ['ERROR: Unable to retrieve recruitment data. Please contact the administrator.']);
}
fclose($output);
exit;
