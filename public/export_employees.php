<?php
// ======================================================
// [FILE] public/export_employees.php
// [STATUS] FINAL: Old Column Order + Fixed Logic
// ======================================================

require '../config/db.php';
require '../src/Logger.php'; 
session_start();

// 1. SECURITY CHECK
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 2. GET FILTERS
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_type   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filter_dept   = isset($_GET['dept'])   ? trim($_GET['dept'])   : '';
$search_query  = isset($_GET['search']) ? trim($_GET['search']) : '';

// 3. BUILD QUERY
$where = ['1=1'];
$params = [];

// Status
if ($filter_status !== '') { 
    $where[] = 'status = ?';           
    $params[] = $filter_status; 
}

// Type (THE FIX: Checks Agency Name too)
if ($filter_type !== '') { 
    $where[] = '(employment_type = ? OR agency_name = ?)'; 
    $params[] = $filter_type; 
    $params[] = $filter_type; 
}

// Department
if ($filter_dept !== '') { 
    $where[] = 'dept = ?'; 
    $params[] = $filter_dept; 
}

// Search
if ($search_query !== '') {
    $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $term = "%{$search_query}%";
    array_push($params, $term, $term, $term);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// 4. FETCH DATA
$sql = "SELECT * FROM employees {$whereSql} ORDER BY last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. SET HEADERS
$filename = "employee_list_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// 6. OUTPUT DATA
$output = fopen('php://output', 'w');

// Add BOM for Excel (Fixes weird symbols)
fwrite($output, "\xEF\xBB\xBF");

// A. Write Column Headers (MATCHING OLD ORDER)
fputcsv($output, [
    'Employee ID', 
    'First Name',      // Restored Order
    'Last Name',       // Restored Order
    'Department', 
    'Section', 
    'Job Title', 
    'Status', 
    'Employment Type', 
    'Agency Name',     // [NEW] Added so you can see the Agency
    'Date Hired',
    'Email',
    'Contact Number'
]);

// B. Write Rows
foreach ($employees as $row) {
    fputcsv($output, [
        $row['emp_id'],
        $row['first_name'], // Restored Order
        $row['last_name'],  // Restored Order
        $row['dept'],
        $row['section'],
        $row['job_title'],
        $row['status'],
        $row['employment_type'],
        $row['agency_name'], // [NEW]
        $row['hire_date'],
        $row['email'],
        $row['contact_number']
    ]);
}

fclose($output);
exit;
?>