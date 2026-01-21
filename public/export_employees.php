<?php
require '../config/db.php';
require '../src/Logger.php'; 
session_start();

// 1. SECURITY CHECK
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 2. GET FILTERS (Exact same logic as index.php)
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_type   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filter_dept   = isset($_GET['dept'])   ? trim($_GET['dept'])   : '';
$search_query  = isset($_GET['search']) ? trim($_GET['search']) : '';

// 3. BUILD QUERY
$where = ['1=1'];
$params = [];

if ($filter_status !== '') { $where[] = 'status = ?';           $params[] = $filter_status; }
if ($filter_type   !== '') { $where[] = 'employment_type = ?';  $params[] = $filter_type;   }
if ($filter_dept   !== '') { $where[] = 'dept = ?';             $params[] = $filter_dept;   }

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

// 5. SET HEADERS TO FORCE DOWNLOAD
$filename = "employee_list_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// 6. OUTPUT DATA
$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility (Optional but recommended)
fwrite($output, "\xEF\xBB\xBF");

// A. Write Column Headers
fputcsv($output, [
    'Employee ID', 
    'First Name', 
    'Last Name', 
    'Department', 
    'Section', 
    'Job Title', 
    'Status', 
    'Employment Type', 
    'Date Hired',
    'Email',
    'Contact Number'
]);

// B. Write Rows
foreach ($employees as $row) {
    fputcsv($output, [
        $row['emp_id'],
        $row['first_name'],
        $row['last_name'],
        $row['dept'],
        $row['section'],
        $row['job_title'],
        $row['status'],
        $row['employment_type'],
        $row['hire_date'],
        $row['email'],
        $row['contact_number']
    ]);
}

fclose($output);
exit;
?>    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>