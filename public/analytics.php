<?php
// ======================================================
// [FILE] public/analytics.php
// [STATUS] Phase 6: Full Analytics + Turnover Report
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- FILTER LOGIC ---
$jobSearch  = isset($_GET['job_search']) ? trim($_GET['job_search']) : '';
$deptFilter = isset($_GET['dept_filter']) ? trim($_GET['dept_filter']) : '';

// Base SQL for ACTIVE employees (Headcount)
$activeSQL = " WHERE status = 'Active' "; 
$params = [];

// Base SQL for INACTIVE employees (Turnover)
$inactiveSQL = " WHERE status IN ('Resigned', 'Terminated', 'AWOL', 'Retired') ";
$inactiveParams = [];

// Apply Filters to BOTH queries
if (!empty($jobSearch)) {
    $term = "%$jobSearch%";
    $activeSQL .= " AND job_title LIKE ? ";
    $params[] = $term;
    $inactiveSQL .= " AND job_title LIKE ? ";
    $inactiveParams[] = $term;
}
if (!empty($deptFilter)) {
    $activeSQL .= " AND dept = ? ";
    $params[] = $deptFilter;
    $inactiveSQL .= " AND dept = ? ";
    $inactiveParams[] = $deptFilter;
}

// ======================================================
// PART 1: ACTIVE MANPOWER ANALYTICS
// ======================================================

// A. Headcount by Department
$deptStmt = $pdo->prepare("SELECT dept, COUNT(*) as count FROM employees $activeSQL GROUP BY dept ORDER BY count DESC");
$deptStmt->execute($params);
$deptData = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
$deptLabels = json_encode(array_column($deptData, 'dept'));
$deptCounts = json_encode(array_column($deptData, 'count'));

// B. Employment Type (Regular vs Agency)
$typeStmt = $pdo->prepare("SELECT employment_type, COUNT(*) as count FROM employees $activeSQL GROUP BY employment_type");
$typeStmt->execute($params);
$typeData = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
$typeLabels = json_encode(array_column($typeData, 'employment_type'));
$typeCounts = json_encode(array_column($typeData, 'count'));

// C. Hiring Trend
$trendStmt = $pdo->prepare("SELECT YEAR(hire_date) as yr, COUNT(*) as count 
                          FROM employees 
                          $activeSQL AND hire_date IS NOT NULL AND hire_date != '0000-00-00'
                          GROUP BY yr 
                          ORDER BY yr ASC LIMIT 10");
$trendStmt->execute($params);
$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
$trendLabels = json_encode(array_column($trendData, 'yr'));
$trendCounts = json_encode(array_column($trendData, 'count'));

// D. Gender
$genderStmt = $pdo->prepare("SELECT gender, COUNT(*) as count FROM employees $activeSQL GROUP BY gender");
$genderStmt->execute($params);
$genderData = $genderStmt->fetchAll(PDO::FETCH_ASSOC);
$genderLabels = json_encode(array_column($genderData, 'gender'));
$genderCounts = json_encode(array_column($genderData, 'count'));

// ======================================================
// PART 2: ATTRITION & TURNOVER ANALYTICS (New!)
// ======================================================

// E. Reason for Leaving (Resigned vs Terminated)
$turnoverStmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM employees $inactiveSQL GROUP BY status");
$turnoverStmt->execute($inactiveParams);
$turnoverData = $turnoverStmt->fetchAll(PDO::FETCH_ASSOC);
$turnLabels = json_encode(array_column($turnoverData, 'status'));
$turnCounts = json_encode(array_column($turnoverData, 'count'));

// KPI Stats
$activeCount = $pdo->prepare("SELECT COUNT(*) FROM employees $activeSQL");
$activeCount->execute($params);
$activeCount = $activeCount->fetchColumn();

$leaversCount = $pdo->prepare("SELECT COUNT(*) FROM employees $inactiveSQL");
$leaversCount->execute($inactiveParams);
$leaversCount = $leaversCount->fetchColumn();

// Calculate Turnover Rate
$totalServed = $activeCount + $leaversCount;
$turnoverRate = ($totalServed > 0) ? round(($leaversCount / $totalServed) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GAG Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f4f6f9; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .filter-bar { background: #fff; padding: 15px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .chart-container { height: 300px; width: 100%; }
        .section-title { border-left: 5px solid #dc3545; padding-left: 10px; font-weight: bold; color: #444; margin: 30px 0 20px 0; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid px-4">
    <a class="navbar-brand" href="index.php">⬅ Dashboard</a>
    <span class="navbar-text text-white"> GAG Analytics</span>
  </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="filter-bar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="fw-bold small text-muted">Job Title</label>
                <input type="text" name="job_search" class="form-control" placeholder="e.g. Engineer" value="<?php echo htmlspecialchars($jobSearch); ?>">
            </div>
            <div class="col-md-3">
                <label class="fw-bold small text-muted">Department</label>
                <select name="dept_filter" class="form-select">
                    <option value="">All Departments</option>
                    <?php 
                    $depts = ['SQP','SIGCOM','PSS','OCS','ADMIN','HMS','RAS','TRS','LMS','DOS','CTS','BFS','WHS','GUNJIN'];
                    foreach($depts as $d) echo "<option value='$d' ".($deptFilter==$d?'selected':'').">$d</option>"; 
                    ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Analyze</button>
            </div>
            <div class="col-md-2">
                <a href="analytics.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 text-center border-start border-5 border-primary">
                <small class="text-muted text-uppercase fw-bold">Active Headcount</small>
                <h2 class="text-primary mb-0"><?php echo $activeCount; ?></h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 text-center border-start border-5 border-danger">
                <small class="text-muted text-uppercase fw-bold">Total Departures</small>
                <h2 class="text-danger mb-0"><?php echo $leaversCount; ?></h2>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 text-center border-start border-5 border-secondary">
                <small class="text-muted text-uppercase fw-bold">Turnover Rate</small>
                <h2 class="text-secondary mb-0"><?php echo $turnoverRate; ?>%</h2>
            </div>
        </div>
    </div>

    <h5 class="section-title" style="border-color: #0d6efd;">Manpower Distribution (Active)</h5>
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card p-4">
                <h6 class="fw-bold text-secondary text-center mb-3">Headcount by Department</h6>
                <div class="chart-container">
                    <canvas id="deptChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card p-4">
                <h6 class="fw-bold text-secondary text-center mb-3">Employment Type</h6>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card p-4">
                <h6 class="fw-bold text-secondary text-center mb-3">Hiring Growth (2020-2026)</h6>
                <div class="chart-container" style="height: 250px;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <h5 class="section-title">Attrition & Turnover Report</h5>
    <div class="row mb-5">
        <div class="col-md-6">
            <div class="card p-4">
                <h6 class="fw-bold text-danger text-center mb-3">Reason for Leaving</h6>
                <div class="chart-container">
                    <canvas id="turnoverChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-4 d-flex align-items-center justify-content-center bg-light h-100">
                <div class="text-center">
                    <h5 class="text-muted">Attrition Summary</h5>
                    <ul class="list-group list-group-flush mt-3 text-start" style="width: 300px;">
                        <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                            Total Resigned
                            <span class="badge bg-warning text-dark rounded-pill">
                                <?php 
                                    $res = 0; 
                                    foreach($turnoverData as $t) if($t['status']=='Resigned') $res=$t['count'];
                                    echo $res;
                                ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                            Terminated (Fired)
                            <span class="badge bg-danger rounded-pill">
                                <?php 
                                    $term = 0; 
                                    foreach($turnoverData as $t) if($t['status']=='Terminated') $term=$t['count'];
                                    echo $term;
                                ?>
                            </span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent">
                            AWOL
                            <span class="badge bg-dark rounded-pill">
                                <?php 
                                    $awol = 0; 
                                    foreach($turnoverData as $t) if($t['status']=='AWOL') $awol=$t['count'];
                                    echo $awol;
                                ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    // 1. DEPARTMENT
    new Chart(document.getElementById('deptChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $deptLabels; ?>,
            datasets: [{ label: 'Active', data: <?php echo $deptCounts; ?>, backgroundColor: '#0d6efd', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // 2. TYPE
    new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo $typeLabels; ?>,
            datasets: [{ data: <?php echo $typeCounts; ?>, backgroundColor: ['#198754', '#ffc107', '#6c757d', '#0dcaf0'] }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });

    // 3. TREND
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?php echo $trendLabels; ?>,
            datasets: [{ label: 'Hires', data: <?php echo $trendCounts; ?>, borderColor: '#6610f2', backgroundColor: 'rgba(102, 16, 242, 0.1)', fill: true, tension: 0.4 }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // 4. TURNOVER (The New Chart)
    new Chart(document.getElementById('turnoverChart'), {
        type: 'pie',
        data: {
            labels: <?php echo $turnLabels; ?>, // Resigned, Terminated, AWOL
            datasets: [{
                data: <?php echo $turnCounts; ?>,
                backgroundColor: [
                    '#ffc107', // Resigned (Yellow)
                    '#dc3545', // Terminated (Red)
                    '#212529', // AWOL (Black)
                    '#6c757d'  // Retired/Other (Gray)
                ]
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });
</script>

</body>
</html>