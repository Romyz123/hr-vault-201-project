<?php
// ======================================================
// [FILE] public/export_employees.php
// [STATUS] FINAL: Old Column Order + Fixed Logic
// ======================================================

require '../config/db.php';
require '../src/Logger.php';
require '../src/Validator.php';
session_start();

// 1. SECURITY CHECK
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 2. GET FILTERS
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_type   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filter_dept   = isset($_GET['dept'])   ? trim($_GET['dept'])   : '';
$search_query  = Validator::sanitizeSearch($_GET['search'] ?? '');

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
    $terms = preg_split('/[\s,]+/', $search_query, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
        $t = "%{$term}%";
        array_push($params, $t, $t, $t);
    }
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
    'First Name',
    'Middle Name',
    'Last Name',
    'Gender',
    'Birth Date',
    'Department',
    'Section',
    'Job Title',
    'System Role',
    'Status',
    'Employment Type',
    'Agency Name',
    'Date Hired',
    'Email',
    'Contact Number',
    'Present Address',
    'Permanent Address',
    'SSS Number',
    'TIN Number',
    'PhilHealth',
    'Pag-IBIG',
    'Emergency Contact Name',
    'Emergency Contact Number',
    'Emergency Address',
    'Education',
    'Experience',
    'Licenses'
]);

// B. Write Rows
foreach ($employees as $row) {
    // [SMART FIX] Redundancy Check
    // Only ADMIN and SQP show sub-sections. Others (OCS, PSS) hide it.
    $displaySection = $row['section'];
    if (!in_array(strtoupper($row['dept']), ['ADMIN', 'SQP'])) {
        $displaySection = '';
    }

    fputcsv($output, [
        $row['emp_id'],
        $row['first_name'],
        $row['middle_name'],
        $row['last_name'],
        $row['gender'],
        $row['birth_date'],
        $row['dept'],
        $displaySection,
        $row['job_title'],
        $row['system_role'],
        $row['status'],
        $row['employment_type'],
        $row['agency_name'],
        $row['hire_date'],
        $row['email'],
        $row['contact_number'],
        $row['present_address'],
        $row['permanent_address'],
        $row['sss_no'],
        $row['tin_no'],
        $row['philhealth_no'],
        $row['pagibig_no'],
        $row['emergency_name'],
        $row['emergency_contact'],
        $row['emergency_address'],
        $row['education'],
        $row['experience'],
        $row['licenses']
    ]);
}

fclose($output);
exit;
