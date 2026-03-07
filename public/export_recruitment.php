<?php
// public/export_recruitment.php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
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
    fputcsv($output, ['ERROR: Database table "candidates" is missing. Please run Auto-Fix in Database Status.']);
}
fclose($output);
exit;
