<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) { die("Access Denied"); }

// 1. CAPTURE FILTERS
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
$employees = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Master List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* 1. Force A4 Landscape */
    @page {
        size: A4 landscape;
        margin: 10mm; /* Small margin for printer limits */
    }

    @media print {
        .no-print { display: none !important; }
        body { -webkit-print-color-adjust: exact; background: white; }
        .page { box-shadow: none; margin: 0; width: 100%; }
        /* Fix Table Borders for printing */
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
    }

    body { background: #eee; }
    .page { 
        background: white; 
        width: 297mm; /* A4 Landscape Width */
        min-height: 210mm; 
        margin: 20px auto; 
        padding: 10mm; 
        box-shadow: 0 0 10px rgba(0,0,0,0.3); 
    }
    .table-sm { font-size: 0.8rem; } /* Slightly smaller text to fit everything */
</style>
</head>
<body>

<div class="text-center py-3 no-print">
    <button onclick="window.print()" class="btn btn-primary btn-lg fw-bold">🖨️ Print / Save as PDF</button>
    <button onclick="window.close()" class="btn btn-secondary btn-lg">Close</button>
</div>

<div class="page">
    <div class="d-flex justify-content-between align-items-end mb-4 border-bottom pb-2">
        <div>
            <h2 class="fw-bold mb-0">TES PHILIPPINES</h2>
            <h5 class="text-muted">Master Employee List</h5>
        </div>
        <div class="text-end">
            <small class="text-muted">Generated on: <?php echo date('M d, Y'); ?></small><br>
            <small class="text-muted">Total Records: <strong><?php echo count($employees); ?></strong></small>
        </div>
    </div>

    <table class="table table-bordered table-striped table-sm">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Job Title</th>
                <th>Dept</th>
                <th>Section</th>
                <th>Type</th>
                <th>Hired Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp): ?>
            <tr>
                <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                <td class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></td>
                <td><?php echo htmlspecialchars($emp['job_title']); ?></td>
                <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                <td><?php echo htmlspecialchars($emp['section']); ?></td>
                <td><?php echo htmlspecialchars($emp['employment_type']); ?></td>
                <td><?php echo htmlspecialchars($emp['hire_date']); ?></td>
                <td><?php echo htmlspecialchars($emp['status']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="mt-4 text-center no-print">
        <small class="text-muted">-- End of Report --</small>
    </div>
</div>

</body>
</html>