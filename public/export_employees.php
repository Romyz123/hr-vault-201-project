<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) { die("Access Denied"); }

// 1. CAPTURE FILTERS (Same as index.php)
$filter_status = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$filter_type   = isset($_GET['type'])   ? htmlspecialchars($_GET['type'])   : '';
$filter_dept   = isset($_GET['dept'])   ? htmlspecialchars($_GET['dept'])   : '';
$search_query  = isset($_GET['search']) ? trim($_GET['search']) : '';

// 2. BUILD QUERY
$sql = "SELECT emp_id, last_name, first_name, job_title, dept, section, employment_type, hire_date, status 
        FROM employees WHERE 1=1";
$params = [];

if (!empty($filter_status)) { $sql .= " AND status = ?"; $params[] = $filter_status; }
if (!empty($filter_type))   { $sql .= " AND employment_type = ?"; $params[] = $filter_type; }
if (!empty($filter_dept))   { $sql .= " AND dept = ?"; $params[] = $filter_dept; }
if (!empty($search_query))  { 
    $sql .= " AND (emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $term = "%$search_query%";
    $params[] = $term; $params[] = $term; $params[] = $term;
}

$sql .= " ORDER BY last_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. GENERATE CSV FILE
// Headers to force download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=HR_Report_' . date('Y-m-d') . '.csv');

// Open PHP output stream
$output = fopen('php://output', 'w');

// Add Column Headers
fputcsv($output, ['Employee ID', 'Last Name', 'First Name', 'Job Title', 'Department', 'Section', 'Type', 'Date Hired', 'Status']);

// Add Rows
foreach ($rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
?>