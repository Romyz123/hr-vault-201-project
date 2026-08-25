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

$requestedSensitive = isset($_GET['exportSensitive']) && $_GET['exportSensitive'] === 'true';
$hasSensitivePermission = in_array(strtoupper(trim($_SESSION['role'] ?? '')), ['ADMIN', 'HR'], true);
$includeSensitive = $hasSensitivePermission && $requestedSensitive;

// Audit log for export requests
$logger = new Logger($pdo);
$logger->log(
    $_SESSION['user_id'],
    'EXPORT_EMPLOYEES',
    'Export requested; includeSensitive=' . ($requestedSensitive ? 'true' : 'false') . ', allowed=' . ($hasSensitivePermission ? 'true' : 'false')
);

function maskSensitive($value, $unmasked = 4)
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $value);
    $len = strlen($digits);
    if ($len <= $unmasked) {
        return str_repeat('*', $len);
    }
    $visible = substr($digits, -1 * $unmasked);
    return str_repeat('*', $len - $unmasked) . $visible;
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
$headers = [
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
    'Permanent Address'
];
if ($includeSensitive) {
    $headers = array_merge($headers, [
        'SSS Number',
        'TIN Number',
        'PhilHealth',
        'Pag-IBIG',
        'Emergency Contact Name',
        'Emergency Contact Number',
        'Emergency Address'
    ]);
}
$headers = array_merge($headers, ['Education', 'Experience', 'Licenses']);
fputcsv($output, $headers);

// B. Write Rows
foreach ($employees as $row) {
    // [SMART FIX] Redundancy Check
    // Only ADMIN and SQP show sub-sections. Others (OCS, PSS) hide it.
    $displaySection = $row['section'];
    if (!in_array(strtoupper($row['dept']), ['ADMIN', 'SQP'])) {
        $displaySection = '';
    }

    $rowData = [
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
        $row['permanent_address']
    ];

    if ($includeSensitive) {
        $rowData = array_merge($rowData, [
            maskSensitive($row['sss_no']),
            maskSensitive($row['tin_no']),
            maskSensitive($row['philhealth_no']),
            maskSensitive($row['pagibig_no']),
            $row['emergency_name'],
            maskSensitive($row['emergency_contact']),
            $row['emergency_address']
        ]);

        $logger->log(
            $_SESSION['user_id'],
            'EXPORT_EMPLOYEES_SENSITIVE',
            'Exported employee ' . $row['emp_id'] . ' with masked sensitive fields'
        );
    }

    $rowData = array_merge($rowData, [$row['education'], $row['experience'], $row['licenses']]);
    fputcsv($output, $rowData);
}

fclose($output);
exit;
