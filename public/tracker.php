<?php
// ======================================================
// [FILE] public/tracker.php
// [STATUS] Phase 3: Missing Document Tracker (Visual)
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// 2. CONFIGURATION
// Define the "Mandatory" categories you want to track
$REQUIRED_DOCS = [
    '201 Files'    => ['201', 'PDS', 'Data Sheet', 'Resume'], // Keywords to match
    'Valid ID'     => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
    'Contract'     => ['Contract', 'Appointment', 'Offer'],
    'Medical'      => ['Medical', 'Fit to Work', 'Exam'],
    'Clearance'    => ['NBI', 'Police', 'Barangay']
];

// 3. GET FILTERS
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
// [SECURITY] Limit & Sanitize Search
if (strlen($search) > 50) $search = substr($search, 0, 50);
$search = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $search);

// 4. FETCH EMPLOYEES
$sql = "SELECT emp_id, first_name, last_name, dept, job_title, status FROM employees WHERE 1=1";
$params = [];

if (!empty($dept)) {
    $sql .= " AND dept = ?";
    $params[] = $dept;
}
if (!empty($search)) {
    $sql .= " AND (emp_id LIKE ? OR last_name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
}
$sql .= " ORDER BY last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. FETCH ALL DOCUMENTS (Optimized: 1 Query)
// We fetch all docs and map them to employees in PHP to avoid 1000+ SQL queries.
$docSql = "SELECT employee_id, category, original_name FROM documents";
$docStmt = $pdo->query($docSql);
$allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// Map Docs:  $docsMap['1001'] = ['Medical' => true, 'Contract' => true]
$docsMap = [];
foreach ($allDocs as $d) {
    $empId = $d['employee_id'];
    $cat   = $d['category']; // e.g. "Medical"
    $name  = $d['original_name'];
    
    // Check against our Required List (Fuzzy Match)
    foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
        // If Category matches OR Filename matches keyword
        if (stripos($cat, $reqKey) !== false) {
            $docsMap[$empId][$reqKey] = true;
        } else {
            foreach ($keywords as $k) {
                if (stripos($name, $k) !== false || stripos($cat, $k) !== false) {
                    $docsMap[$empId][$reqKey] = true;
                    break;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document Tracker - TES HR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; font-size: 0.9rem; }
        .progress { height: 20px; border-radius: 10px; background-color: #e9ecef; }
        .icon-check { color: #198754; font-size: 1.2rem; } /* Green Check */
        .icon-cross { color: #dc3545; font-size: 1.2rem; opacity: 0.3; } /* Red X */
        .card-header { background: #2c3e50; color: white; }
        .table-hover tbody tr:hover { background-color: #f1f1f1; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid px-4">
    <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
    <span class="navbar-text text-white">Missing Document Tracker</span>
  </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="card shadow-sm mb-4">
        <div class="card-body py-3">
            <form class="row g-3 align-items-center">
                <div class="col-auto">
                    <label class="fw-bold">Filter Dept:</label>
                </div>
                <div class="col-auto">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php 
                        $depts = ['SQP','SIGCOM','PSS','OCS','ADMIN','HMS','RAS','TRS','LMS','DOS','CTS','BFS','WHS','GUNJIN'];
                        foreach($depts as $d) echo "<option value='$d' ".($dept==$d?'selected':'').">$d</option>"; 
                        ?>
                    </select>
                </div>
                <div class="col-auto ms-auto">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Name..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header d-flex justify-content-between">
            <h6 class="mb-0 pt-1">Compliance Matrix</h6>
            <span class="badge bg-light text-dark"><?php echo count($employees); ?> Employees</span>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-bordered table-hover mb-0 text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="text-start ps-3">Employee</th>
                        <th width="15%">Progress</th>
                        <th>201 File</th>
                        <th>Valid ID</th>
                        <th>Contract</th>
                        <th>Medical</th>
                        <th>Clearance</th>
                        <th width="10%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): 
                        $id = $emp['emp_id'];
                        
                        // Calculate Score
                        $totalReq = 5;
                        $have = 0;
                        
                        // Check each requirement
                        $has201      = isset($docsMap[$id]['201 Files']);
                        $hasID       = isset($docsMap[$id]['Valid ID']);
                        $hasContract = isset($docsMap[$id]['Contract']);
                        $hasMedical  = isset($docsMap[$id]['Medical']);
                        $hasClearance= isset($docsMap[$id]['Clearance']);
                        
                        if ($has201) $have++;
                        if ($hasID) $have++;
                        if ($hasContract) $have++;
                        if ($hasMedical) $have++;
                        if ($hasClearance) $have++;

                        $percent = ($have / $totalReq) * 100;
                        
                        // Color logic
                        $barColor = 'bg-danger';
                        if ($percent > 40) $barColor = 'bg-warning';
                        if ($percent > 80) $barColor = 'bg-info';
                        if ($percent == 100) $barColor = 'bg-success';
                    ?>
                    <tr>
                        <td class="text-start ps-3">
                            <div class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($emp['dept']); ?> | <?php echo htmlspecialchars($emp['job_title']); ?></div>
                        </td>
                        <td>
                            <div class="progress">
                                <div class="progress-bar <?php echo $barColor; ?>" style="width: <?php echo $percent; ?>%">
                                    <?php echo $percent; ?>%
                                </div>
                            </div>
                        </td>
                        <td><?php echo $has201 ? '<i class="bi bi-check-circle-fill icon-check"></i>' : '<i class="bi bi-x-circle-fill icon-cross"></i>'; ?></td>
                        <td><?php echo $hasID ? '<i class="bi bi-check-circle-fill icon-check"></i>' : '<i class="bi bi-x-circle-fill icon-cross"></i>'; ?></td>
                        <td><?php echo $hasContract ? '<i class="bi bi-check-circle-fill icon-check"></i>' : '<i class="bi bi-x-circle-fill icon-cross"></i>'; ?></td>
                        <td><?php echo $hasMedical ? '<i class="bi bi-check-circle-fill icon-check"></i>' : '<i class="bi bi-x-circle-fill icon-cross"></i>'; ?></td>
                        <td><?php echo $hasClearance ? '<i class="bi bi-check-circle-fill icon-check"></i>' : '<i class="bi bi-x-circle-fill icon-cross"></i>'; ?></td>
                        <td>
                            <?php if($percent == 100): ?>
                                <span class="badge bg-success">COMPLETE</span>
                            <?php elseif($percent == 0): ?>
                                <span class="badge bg-danger">EMPTY</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">INCOMPLETE</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>