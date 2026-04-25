<?php
// public/api/export_master_list.php
require '../../config/db.php';
session_start();

// [SECURITY] HR, Manager, and Admin only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    http_response_code(403);
    die("Access Denied");
}

$format = $_GET['format'] ?? 'csv';
$filename = "HR_Master_List_" . date('Y-m-d') . "." . ($format === 'xlsx' ? 'xlsx' : 'csv');

try {
    // Select columns for the master grid
    $sql = "SELECT emp_id, first_name, last_name, job_title, dept, section, agency_name, hire_date, status 
            FROM employees 
            WHERE deleted_at IS NULL 
            ORDER BY last_name ASC";

    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) fputcsv($output, $row);
        }
        fclose($output);
    } elseif ($format === 'xlsx') {
        // Simple XLSX export using basic headers if no library available
        // For full XLSX support, using a library like PhpSpreadsheet is recommended.
        // As a stable refactor without new libraries, we provide a CSV with .xlsx extension
        // which modern Excel opens with a warning, or a formal CSV download.

        // [NOTE] Reverting to CSV for maximum stability if no XLSX library is present on server.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('.xlsx', '.csv', $filename) . '"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) fputcsv($output, $row);
        }
        fclose($output);
    }
} catch (PDOException $e) {
    error_log("Export Error: " . $e->getMessage());
    http_response_code(500);
    die("Server Error: Unable to generate export.");
}
