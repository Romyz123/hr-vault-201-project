
<?php
// ======================================================
// [FILE] public/analytics.php
// [STATUS] Matrix fixed (< 1 Yr shows), Column Totals at TOP,
//          As-of year logic, Safe labels, Print layout, Debug mode
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// --- 1. FILTER LOGIC ---
$jobSearch     = isset($_GET['job_search']) ? trim($_GET['job_search']) : '';
// [SECURITY] Limit & Sanitize Search
if (strlen($jobSearch) > 50) $jobSearch = substr($jobSearch, 0, 50);
$jobSearch = preg_replace('/[^a-zA-Z0-9\-_ \.\&\/\(\),]/', '', $jobSearch);
$deptFilter    = isset($_GET['dept_filter']) ? trim($_GET['dept_filter']) : '';
$genderFilter  = isset($_GET['gender']) ? trim($_GET['gender']) : '';
$agencyFilter  = isset($_GET['agency_filter']) ? trim($_GET['agency_filter']) : '';
$yearFilter    = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$probMonths    = isset($_GET['prob_months']) ? (int)$_GET['prob_months'] : 6; // Default 6 months
$debug         = isset($_GET['debug']) ? (bool)$_GET['debug'] : false;

// --- 2. BUILD SQL (base WHERE reused by several queries) ---
$activeSQL = " WHERE status = 'Active' ";
$params = [];

$inactiveSQL = " WHERE status IN ('Resigned', 'Terminated', 'AWOL', 'Retired')
                 AND (exit_date IS NOT NULL AND YEAR(exit_date) = ?) ";
$inactiveParams = [$yearFilter];

// Apply Filters (to both active & inactive where applicable)
if ($jobSearch !== '') {
    $term = "%$jobSearch%";
    $activeSQL   .= " AND job_title LIKE ? "; $params[] = $term;
    $inactiveSQL .= " AND job_title LIKE ? "; $inactiveParams[] = $term;
}
if ($deptFilter !== '') {
    $activeSQL   .= " AND dept = ? "; $params[] = $deptFilter;
    $inactiveSQL .= " AND dept = ? "; $inactiveParams[] = $deptFilter;
}
if ($genderFilter !== '') {
    $activeSQL .= " AND gender = ? "; $params[] = $genderFilter;
}
if ($agencyFilter !== '') {
    if ($agencyFilter === 'TESP_DIRECT') {
        $activeSQL   .= " AND (agency_name IS NULL OR agency_name = '' OR agency_name LIKE 'TESP%') ";
        $inactiveSQL .= " AND (agency_name IS NULL OR agency_name = '' OR agency_name LIKE 'TESP%') ";
    } else {
        $activeSQL   .= " AND agency_name = ? "; $params[] = $agencyFilter;
        $inactiveSQL .= " AND agency_name = ? "; $inactiveParams[] = $agencyFilter;
    }
}

// --- 3. AS-OF DATE for tenure bucketing ---
$today       = new DateTime('today');
$currentYear = (int)$today->format('Y');
// If viewing a past year, compute tenure as of Dec 31 of that year.
// If current/future, compute as of today.
$asOf = ($yearFilter < $currentYear)
    ? new DateTime($yearFilter . '-12-31')
    : $today;

// ============================================================
// DATA FETCHING
// ============================================================

// 1) HEADCOUNTS (Active)
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees $activeSQL");
$countStmt->execute($params);
$totalHeadcount = (int)$countStmt->fetchColumn();

// 2) AGENCY BREAKDOWN (Active)
$agencyStmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(agency_name, ''), 'TESP Direct') AS entity, COUNT(*) AS count
    FROM employees
    $activeSQL
    GROUP BY entity
    ORDER BY count DESC
");
$agencyStmt->execute($params);
$agencyData = $agencyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 3) DEPARTMENT BREAKDOWN (Active)
$deptStmt = $pdo->prepare("
    SELECT dept, COUNT(*) AS count
    FROM employees
    $activeSQL
    GROUP BY dept
    ORDER BY count DESC
");
$deptStmt->execute($params);
$deptData = $deptStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 4) TURNOVER STATUS BREAKDOWN (Inactive in selected year)
$turnStmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM employees $inactiveSQL GROUP BY status");
$turnStmt->execute($inactiveParams);
$turnoverData = $turnStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 5) EXIT REASONS (Top 5; Inactive in selected year)
$reasonStmt = $pdo->prepare("
    SELECT exit_reason, COUNT(*) as count
    FROM employees
    $inactiveSQL
    AND exit_reason IS NOT NULL
    AND exit_reason != ''
    GROUP BY exit_reason
    ORDER BY count DESC
    LIMIT 5
");
$reasonStmt->execute($inactiveParams);
$reasonData = $reasonStmt->fetchAll(PDO::FETCH_ASSOC);

// 5b) ATTRITION TREND (Monthly exits in selected year)
$attrTrendSQL = "
    SELECT MONTH(exit_date) AS month, COUNT(*) AS count
    FROM employees
    $inactiveSQL
    GROUP BY month
    ORDER BY month ASC
";
// Re-use inactiveParams which already has the year bound
$attrTrendStmt = $pdo->prepare($attrTrendSQL);
$attrTrendStmt->execute($inactiveParams);
$attrTrendRaw = $attrTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$attrTrendData = [];
for ($i = 1; $i <= 12; $i++) { $attrTrendData[] = isset($attrTrendRaw[$i]) ? (int)$attrTrendRaw[$i] : 0; }
$attrTrendCounts = json_encode($attrTrendData);

// 6) HIRING TREND (Active with hire_date in selected year)
$trendSQL = "
    SELECT MONTH(hire_date) AS month, COUNT(*) AS count
    FROM employees
    $activeSQL
    AND YEAR(hire_date) = ?
    GROUP BY month
    ORDER BY month ASC
";
$trendParams = array_merge($params, [$yearFilter]);
$trendStmt = $pdo->prepare($trendSQL);
$trendStmt->execute($trendParams);
$trendRaw = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendData = [];
for ($i = 1; $i <= 12; $i++) { $trendData[] = isset($trendRaw[$i]) ? (int)$trendRaw[$i] : 0; }

// 7) PROBATIONARY VS REGULAR (New Logic)
// Threshold: Dynamic months prior to the "As Of" date
$probThresholdDate = (clone $asOf)->modify("-$probMonths months")->format('Y-m-d');

$probSQL = "SELECT emp_id, first_name, last_name, dept, job_title, hire_date 
            FROM employees $activeSQL AND hire_date > ? ORDER BY hire_date DESC";
$probParams = array_merge($params, [$probThresholdDate]);
$probStmt = $pdo->prepare($probSQL);
$probStmt->execute($probParams);
$probList = $probStmt->fetchAll(PDO::FETCH_ASSOC);
$probCount = count($probList);
$regCount = max(0, $totalHeadcount - $probCount);

// 7) DEMOGRAPHICS & TENURE MATRIX (Slug Strategy + Column Totals)

// Stable computation IDs
$bandOrder = ['b0','b1','b2','b3','b4'];
$bandLabels = [
    'b0' => '< 1 Yr',
    'b1' => '1-3 Yrs',
    'b2' => '3-5 Yrs',
    'b3' => '5-10 Yrs',
    'b4' => '10+ Yrs',
];

$rawStmt = $pdo->prepare("SELECT dept, birth_date, hire_date, gender FROM employees $activeSQL");
$rawStmt->execute($params);
$rows = $rawStmt->fetchAll(PDO::FETCH_ASSOC);

// Aggregates
$ageBands          = ['18-25'=>0, '26-35'=>0, '36-45'=>0, '46-55'=>0, '56+'=>0];
$genderCounts      = ['Male'=>0, 'Female'=>0];
$tenureBandsCounts = array_fill_keys($bandOrder, 0);
$tenureMatrix      = []; // dept => [b0..b4]
$columnTotals      = array_fill_keys($bandOrder, 0);

/**
 * Determine tenure band by months as of $asOf.
 * For past-year snapshots, rows with hire_date after $asOf are excluded (return null).
 */
function get_tenure_band_slug(string $hireDate, DateTime $asOf): ?string {
    if (empty($hireDate) || $hireDate === '0000-00-00') return null;
    try {
        $start = new DateTime($hireDate);
        // For a snapshot, exclude hires after the as-of date.
        if ($start > $asOf) return null;

        $diff   = $start->diff($asOf);
        // Mild rounding: if >= 15 days, count as another month to reduce boundary disputes.
        $months = ($diff->y * 12) + $diff->m + ($diff->d >= 15 ? 1 : 0);

        if ($months < 12)   return 'b0'; // < 1 year
        if ($months < 36)   return 'b1'; // 1–3
        if ($months < 60)   return 'b2'; // 3–5
        if ($months < 120)  return 'b3'; // 5–10
        return 'b4';                      // 10+
    } catch (Exception $e) {
        return null;
    }
}

foreach ($rows as $r) {
    // Gender
    $g = ucfirst(strtolower(trim((string)$r['gender'])));
    if (isset($genderCounts[$g])) { $genderCounts[$g]++; }

    // Age (kept as-of today; switch to $asOf if you want snapshot ages)
    if (!empty($r['birth_date']) && $r['birth_date'] !== '0000-00-00') {
        $age = date_diff(date_create($r['birth_date']), date_create('today'))->y;
        if      ($age <= 25) $ageBands['18-25']++;
        elseif  ($age <= 35) $ageBands['26-35']++;
        elseif  ($age <= 45) $ageBands['36-45']++;
        elseif  ($age <= 55) $ageBands['46-55']++;
        else                  $ageBands['56+']++;
    }

    // Tenure by slug (as-of selected year)
    $slug = get_tenure_band_slug((string)$r['hire_date'], $asOf);
    if ($slug === null) continue;

    $tenureBandsCounts[$slug]++;

    // Department key
    $dept = strtoupper(trim((string)$r['dept']));
    if ($dept === '') $dept = 'UNASSIGNED';

    if (!isset($tenureMatrix[$dept])) {
        $tenureMatrix[$dept] = array_fill_keys($bandOrder, 0);
    }
    $tenureMatrix[$dept][$slug]++;
    $columnTotals[$slug]++;
}

ksort($tenureMatrix, SORT_STRING);

// --- JSON Encode for Charts (safe for <script> embedding) ---
$deptLabels   = json_encode(array_keys($deptData));
$deptCounts   = json_encode(array_values($deptData));

$agencyLabels = json_encode(array_keys($agencyData));
$agencyCounts = json_encode(array_values($agencyData));

$turnLabels   = json_encode(array_keys($turnoverData));
$turnCounts   = json_encode(array_values($turnoverData));

$trendLabels  = json_encode(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']);
$trendCounts  = json_encode($trendData);

$ageLabels    = json_encode(array_keys($ageBands));
$ageCounts    = json_encode(array_values($ageBands));

$genderLabels = json_encode(array_keys($genderCounts));
$genderData   = json_encode(array_values($genderCounts));

// Tenure labels/counts in band order; escape special chars for script embedding
$tenureChartLabelsArr = [];
$tenureChartCountsArr = [];
foreach ($bandOrder as $b) {
    $tenureChartLabelsArr[] = $bandLabels[$b];
    $tenureChartCountsArr[] = (int)$tenureBandsCounts[$b];
}
$tenureLabels = json_encode($tenureChartLabelsArr, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$tenureCounts = json_encode($tenureChartCountsArr);

// Calculate Grand Total early for Export/Display
$grandTotal = 0;
foreach ($bandOrder as $b) { $grandTotal += (int)$columnTotals[$b]; }

// Handle Matrix Export
if (isset($_GET['export_matrix'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Tenure_Matrix_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel

    // Header
    $headers = ['Dept'];
    foreach ($bandOrder as $b) $headers[] = $bandLabels[$b];
    $headers[] = 'Total';
    $headers[] = '% Share';
    fputcsv($output, $headers);

    // Totals Row (Top)
    $totalsRow = ['TOTAL'];
    foreach ($bandOrder as $b) $totalsRow[] = $columnTotals[$b];
    $totalsRow[] = $grandTotal;
    $totalsRow[] = '100%';
    fputcsv($output, $totalsRow);

    // Data Rows
    foreach ($tenureMatrix as $dept => $bands) {
        $row = [$dept];
        $rowTotal = array_sum($bands);
        foreach ($bandOrder as $b) $row[] = $bands[$b];
        $row[] = $rowTotal;
        $row[] = ($grandTotal > 0) ? round(($rowTotal / $grandTotal) * 100, 1) . '%' : '0%';
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Optional debug block (visit analytics.php?debug=1)
if ($debug) {
    header('Content-Type: text/plain');
    echo "DEBUG: As-Of = " . $asOf->format('Y-m-d') . "\n\n";
    echo "First 5 departments with b0..b4 counts\n";
    $i = 0;
    foreach ($tenureMatrix as $d => $bands) {
        echo $d . ' => ' . json_encode($bands) . "\n";
        if (++$i >= 5) break;
    }
    echo "\nColumn totals: " . json_encode($columnTotals) . "\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HR Analytics Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card-header { font-size: 0.85rem; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px; }
        .matrix-table th { font-size: 0.75rem; text-align: center; background-color: #f8f9fa; }
        .matrix-table td { font-size: 0.8rem; text-align: center; vertical-align: middle; }
        .matrix-dept { text-align: left !important; font-weight: bold; color: #495057; }
        .table thead th { white-space: nowrap; }

        /* (Optional) Sticky header + sticky totals row in header */
        .matrix-table thead tr:first-child th { position: sticky; top: 0; z-index: 2; background: #f8f9fa; }
        .matrix-table thead tr.thead-totals th { position: sticky; top: 38px; z-index: 1; background: #e9ecef; }

        /* PROFESSIONAL PRINT STYLES */
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { background: white !important; font-size: 12px; }
            .no-print, .navbar, .btn, form { display: none !important; }
            .container-fluid { padding: 0 !important; max-width: 100% !important; }
            .row { display: flex !important; flex-wrap: wrap !important; page-break-inside: avoid; }
            .col-md-3, .col-md-4, .col-md-5, .col-md-6, .col-md-8 { float: left !important; width: 50% !important; padding: 6px !important; }
            .col-12 { width: 100% !important; }
            .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; margin-bottom: 10px; }
            .card-header { background-color: #f0f0f0 !important; color: black !important; font-size: 10pt; padding: 5px; }
            .card-body { padding: 10px !important; }
            canvas { max-height: 220px !important; width: 100% !important; }
            .table-responsive { overflow: visible !important; }
            .matrix-table { font-size: 9pt; width: 100%; border-collapse: collapse; }
            .matrix-table th, .matrix-table td { border: 1px solid #999 !important; padding: 4px 6px !important; }
            .matrix-dept { width: 20%; }
        }
        .bg-total { background-color: #ced4da !important; }
        .bg-black { background-color: #000000 !important; color: #ffffff !important; }

        @media print {
            body.print-matrix-only * { visibility: hidden; }
            body.print-matrix-only #matrixCard, body.print-matrix-only #matrixCard * { visibility: visible; }
            body.print-matrix-only #matrixCard { position: absolute; left: 0; top: 0; width: 100%; margin: 0; border: none; }
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 no-print">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php"><i class="bi bi-arrow-left-circle me-2"></i> Dashboard</a>
        <span class="navbar-text text-white fw-bold">📊 Workforce Intelligence</span>
    </div>
</nav>

<div class="container-fluid px-4">

    <div class="card shadow-sm mb-4 border-primary no-print">
        <div class="card-body py-2 bg-white rounded">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-auto"><i class="bi bi-funnel-fill text-muted"></i></div>
                <div class="col-md-2">
                    <select name="year" class="form-select form-select-sm fw-bold text-primary" onchange="this.form.submit()">
                        <?php $cur=(int)date('Y'); for($y=$cur;$y>=2000;$y--) echo "<option value='$y' ".($y==$yearFilter?'selected':'').">📅 $y</option>"; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="agency_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Agencies</option>
                        <option value="TESP_DIRECT" <?php if($agencyFilter==='TESP_DIRECT') echo 'selected'; ?>>TESP Direct</option>
                        <option value="JORATECH" <?php if($agencyFilter==='JORATECH') echo 'selected'; ?>>Joratech</option>
                        <option value="UNLISOLUTIONS" <?php if($agencyFilter==='UNLISOLUTIONS') echo 'selected'; ?>>UnliSolutions</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="prob_months" class="form-select form-select-sm" onchange="this.form.submit()" title="Set Probationary Period">
                        <option value="3" <?php if($probMonths===3) echo 'selected'; ?>>Probation: 3 Mos</option>
                        <option value="6" <?php if($probMonths===6) echo 'selected'; ?>>Probation: 6 Mos</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="dept_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Depts</option>
                        <?php
                        $allDepts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE dept != '' ORDER BY dept ASC")->fetchAll(PDO::FETCH_COLUMN);
                        foreach($allDepts as $d) {
                            $safe = htmlspecialchars($d);
                            $sel  = ($d === $deptFilter)?'selected':'';
                            echo "<option value=\"$safe\" $sel>$safe</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="gender" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Genders</option>
                        <option value="Male"   <?php if($genderFilter==='Male') echo 'selected'; ?>>Male</option>
                        <option value="Female" <?php if($genderFilter==='Female') echo 'selected'; ?>>Female</option>
                    </select>
                </div>

                <div class="col-md-1">
                    <?php $allJobs = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title != '' ORDER BY job_title ASC")->fetchAll(PDO::FETCH_COLUMN); ?>
                    <input type="text" name="job_search" list="job_list" class="form-control form-select-sm" placeholder="Search Job..." value="<?php echo htmlspecialchars($jobSearch); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ \.\&\/\(\),]+" title="Allowed: Alphanumeric and . & / ( ) ,">
                    <datalist id="job_list">
                        <?php foreach($allJobs as $j) echo "<option value=\"" . htmlspecialchars($j) . "\">"; ?>
                    </datalist>
                </div>

                <div class="col-auto ms-auto d-flex gap-2">
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-dark"><i class="bi bi-printer"></i> Print Report</button>
                    <a href="analytics.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="d-none d-print-block mb-3">
        <h3>HR Analytics Report</h3>
        <p class="text-muted small">
            Generated on: <?php echo date('F j, Y'); ?>
            | Tenure as of: <?php echo htmlspecialchars($asOf->format('F j, Y')); ?>
            | Year filter: <?php echo (int)$yearFilter; ?>
        </p>
        <hr>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card shadow-sm h-100 text-center border-0 bg-primary text-white">
                <div class="card-body d-flex flex-column justify-content-center">
                    <h6 class="opacity-75">Active Headcount</h6>
                    <h1 class="display-3 fw-bold mb-0"><?php echo number_format($totalHeadcount); ?></h1>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card shadow-sm h-100 border-warning">
                <div class="card-header bg-warning text-dark border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-hourglass-split me-2"></i> Status (<?php echo $probMonths; ?>m)</span>
                    <div>
                        <span class="badge bg-dark text-white me-2"><?php echo $probCount; ?> Probie</span>
                        <button class="btn btn-sm btn-link text-dark p-0" onclick="openFullScreen('statusChart', 'Employment Status')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body text-center position-relative">
                    <div style="height: 120px;"><canvas id="statusChart"></canvas></div>
                    <button class="btn btn-sm btn-outline-dark mt-2 w-100" data-bs-toggle="modal" data-bs-target="#probationModal">
                        <i class="bi bi-list-ul"></i> View List
                    </button>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Agency Breakdown</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('agencyChart', 'Agency Breakdown')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body"><canvas id="agencyChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Headcount by Dept</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('deptChart', 'Headcount by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body"><canvas id="deptChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 text-info d-flex justify-content-between align-items-center">
                    <span>Hiring Trend (<?php echo (int)$yearFilter; ?>)</span>
                    <button class="btn btn-sm btn-link text-info p-0" onclick="openFullScreen('trendChart', 'Hiring Trend')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body"><canvas id="trendChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Gender Split</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('genderChart', 'Gender Distribution')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body"><canvas id="genderChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Age Demographics</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('ageChart', 'Age Demographics')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body"><canvas id="ageChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Tenure Overview</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('tenureChart', 'Tenure Overview')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body"><canvas id="tenureChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-danger">
                <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pie-chart-fill me-2"></i> Attrition Status</span>
                    <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('turnoverChart', 'Attrition Status')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body">
                    <canvas id="turnoverChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-danger">
                <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-down-arrow me-2"></i> Monthly Attrition (<?php echo (int)$yearFilter; ?>)</span>
                    <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('attritionTrendChart', 'Monthly Attrition Trend')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body">
                    <canvas id="attritionTrendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-danger">
                <div class="card-header bg-danger text-white border-bottom-0">
                    <i class="bi bi-chat-quote-fill me-2"></i> Top Reasons for Leaving
                </div>
                <div class="card-body">
                    <?php if(empty($reasonData)): ?>
                        <div class="text-center text-muted py-5">No data.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach($reasonData as $r): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <?php echo htmlspecialchars($r['exit_reason']); ?>
                                    <span class="badge bg-danger rounded-pill"><?php echo (int)$r['count']; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-5">
        <div class="col-12">
            <div class="card shadow-sm" id="matrixCard">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span>Tenure by Department (Matrix)</span>
                    <div>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export_matrix' => 1])); ?>" class="btn btn-sm btn-success fw-bold no-print me-2">
                            <i class="bi bi-file-earmark-spreadsheet-fill"></i> Export Excel
                        </a>
                        <button type="button" class="btn btn-sm btn-light text-dark fw-bold no-print" onclick="printMatrixOnly()">
                            <i class="bi bi-printer-fill"></i> Print Table
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0 matrix-table">
                        <thead>
                            <!-- Header labels row -->
                            <tr>
                                <th class="matrix-dept">Dept</th>
                                <?php foreach ($bandOrder as $b): ?>
                                    <th><?php echo htmlspecialchars($bandLabels[$b]); ?></th>
                                <?php endforeach; ?>
                                <th class="bg-black">Total</th>
                                <th class="bg-black">% Share</th>
                            </tr>

                            <!-- Totals-at-top row -->
                            <tr class="table-secondary thead-totals">
                                <th class="matrix-dept text-uppercase small">Total</th>
                                <?php foreach ($bandOrder as $b): ?>
                                    <th class="fw-bold"><?php echo (int)$columnTotals[$b]; ?></th>
                                <?php endforeach; ?>
                                <th class="fw-bold"><?php echo (int)$grandTotal; ?></th>
                                <th class="fw-bold">100%</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($tenureMatrix as $dept => $bands):
                                $rowTotal = array_sum($bands);
                            ?>
                            <tr>
                                <td class="matrix-dept"><?php echo htmlspecialchars($dept); ?></td>

                                <?php foreach ($bandOrder as $b):
                                    $val = (int)$bands[$b];
                                    $cls = $val > 0
                                        ? ($b === 'b4' ? 'fw-bold text-success' : 'fw-bold')
                                        : 'text-muted opacity-25';
                                ?>
                                    <td class="<?php echo $cls; ?>"><?php echo $val; ?></td>
                                <?php endforeach; ?>

                                <td class="fw-bold bg-total"><?php echo $rowTotal; ?></td>
                                <td class="fw-bold text-muted small"><?php echo ($grandTotal > 0) ? round(($rowTotal / $grandTotal) * 100, 1) : 0; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <!-- No <tfoot> totals at the bottom -->
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- PROBATIONARY LIST MODAL -->
    <div class="modal fade" id="probationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-hourglass-split"></i> Probationary Employees</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="alert alert-light m-0 border-bottom small text-muted">
                        <i class="bi bi-info-circle-fill me-1"></i> 
                        Showing employees hired within the last <strong><?php echo $probMonths; ?> months</strong> (after <?php echo $probThresholdDate; ?>).
                    </div>
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Name</th>
                                <th>ID</th>
                                <th>Department</th>
                                <th>Date Hired</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($probList as $p): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?></td>
                                <td><a href="index.php?search=<?php echo urlencode($p['emp_id']); ?>" target="_blank" class="text-decoration-none"><?php echo htmlspecialchars($p['emp_id']); ?></a></td>
                                <td><?php echo htmlspecialchars($p['dept']); ?></td>
                                <td><?php echo htmlspecialchars($p['hire_date']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($probList)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No probationary employees found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- FULL SCREEN CHART MODAL -->
    <div class="modal fade" id="fullScreenModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold text-primary" id="fsModalTitle">Chart View</h5>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="downloadChartImage()">
                            <i class="bi bi-download"></i> Download Image
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row h-100">
                        <!-- Chart Side -->
                        <div class="col-lg-8 d-flex align-items-center justify-content-center bg-white border-end">
                            <div style="width: 95%; height: 90%;">
                                <canvas id="fsChartCanvas"></canvas>
                            </div>
                        </div>
                        <!-- Data Table Side -->
                        <div class="col-lg-4 overflow-auto bg-light p-4">
                            <h5 class="mb-3 border-bottom pb-2"><i class="bi bi-table"></i> Data Breakdown</h5>
                            <div class="card shadow-sm">
                                <div class="card-body p-0">
                                    <table class="table table-striped table-hover mb-0" id="fsDataTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Category</th>
                                                <th class="text-end">Count</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- JS will populate this -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Required for Modals -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6610f2', '#fd7e14'];
    
    // Data Store for Full Screen Mode
    const chartData = {
        'statusChart': { 
            labels: ['Regular', 'Probationary'], 
            data: [<?php echo $regCount; ?>, <?php echo $probCount; ?>], 
            type: 'doughnut', bg: ['#198754', '#ffc107'] 
        },
        'agencyChart': { 
            labels: <?php echo $agencyLabels; ?>, data: <?php echo $agencyCounts; ?>, 
            type: 'doughnut', bg: colors 
        },
        'deptChart': { 
            labels: <?php echo $deptLabels; ?>, data: <?php echo $deptCounts; ?>, 
            type: 'bar', bg: '#198754' 
        },
        'trendChart': { 
            labels: <?php echo $trendLabels; ?>, data: <?php echo $trendCounts; ?>, 
            type: 'line', bg: 'rgba(13, 202, 240, 0.1)', border: '#0dcaf0' 
        },
        'genderChart': { 
            labels: <?php echo $genderLabels; ?>, data: <?php echo $genderData; ?>, 
            type: 'doughnut', bg: ['#0d6efd', '#d63384'] 
        },
        'ageChart': { 
            labels: <?php echo $ageLabels; ?>, data: <?php echo $ageCounts; ?>, 
            type: 'bar', bg: '#ffc107' 
        },
        'tenureChart': { 
            labels: <?php echo $tenureLabels; ?>, data: <?php echo $tenureCounts; ?>, 
            type: 'bar', bg: '#6610f2' 
        },
        'turnoverChart': { 
            labels: <?php echo $turnLabels; ?>, data: <?php echo $turnCounts; ?>, 
            type: 'pie', bg: ['#ffc107', '#dc3545', '#212529', '#6c757d'] 
        },
        'attritionTrendChart': { 
            labels: <?php echo $trendLabels; ?>, data: <?php echo $attrTrendCounts; ?>, 
            type: 'bar', bg: '#dc3545' 
        }
    };

    let fsChartInstance = null;

    function openFullScreen(key, title) {
        const info = chartData[key];
        if(!info) return;

        document.getElementById('fsModalTitle').innerText = title;
        
        // 1. Render Table
        const tbody = document.querySelector('#fsDataTable tbody');
        tbody.innerHTML = '';
        let total = 0;
        
        info.labels.forEach((lbl, i) => {
            const val = Number(info.data[i]);
            total += val;
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${lbl}</td><td class="text-end fw-bold">${val.toLocaleString()}</td>`;
            tbody.appendChild(tr);
        });
        
        // Total Row
        const trTotal = document.createElement('tr');
        trTotal.className = 'table-secondary fw-bold';
        trTotal.innerHTML = `<td>TOTAL</td><td class="text-end">${total.toLocaleString()}</td>`;
        tbody.appendChild(trTotal);

        // 2. Render Chart
        const ctx = document.getElementById('fsChartCanvas').getContext('2d');
        if(fsChartInstance) fsChartInstance.destroy();

        const isLine = info.type === 'line';
        
        fsChartInstance = new Chart(ctx, {
            type: info.type,
            data: {
                labels: info.labels,
                datasets: [{
                    label: 'Count',
                    data: info.data,
                    backgroundColor: info.bg,
                    borderColor: isLine ? info.border : '#fff',
                    fill: isLine,
                    tension: 0.3,
                    borderRadius: info.type === 'bar' ? 4 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'top', display: info.type !== 'bar' },
                    title: { display: true, text: title, font: { size: 16 } }
                },
                scales: (info.type === 'bar' || info.type === 'line') ? { y: { beginAtZero: true } } : {}
            }
        });

        new bootstrap.Modal(document.getElementById('fullScreenModal')).show();
    }

    function downloadChartImage() {
        const canvas = document.getElementById('fsChartCanvas');
        if (canvas) {
            const link = document.createElement('a');
            link.download = 'Chart_Export.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        }
    }

    // Status (Probationary vs Regular)
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Regular', 'Probationary'],
            datasets: [{ 
                data: [<?php echo $regCount; ?>, <?php echo $probCount; ?>], 
                backgroundColor: ['#198754', '#ffc107'] 
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { display: false } } // Hide legend to save space
        }
    });

    // Agency
    new Chart(document.getElementById('agencyChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo $agencyLabels; ?>,
            datasets: [{ data: <?php echo $agencyCounts; ?>, backgroundColor: colors }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });

    // Departments
    new Chart(document.getElementById('deptChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $deptLabels; ?>,
            datasets: [{ label: 'Count', data: <?php echo $deptCounts; ?>, backgroundColor: '#198754', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    // Turnover (Inactive breakdown)
    new Chart(document.getElementById('turnoverChart'), {
        type: 'pie',
        data: {
            labels: <?php echo $turnLabels; ?>,
            datasets: [{ data: <?php echo $turnCounts; ?>, backgroundColor: ['#ffc107', '#dc3545', '#212529', '#6c757d'] }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });

    // Attrition Trend (Monthly)
    new Chart(document.getElementById('attritionTrendChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $trendLabels; ?>,
            datasets: [{ label: 'Exits', data: <?php echo $attrTrendCounts; ?>, backgroundColor: '#dc3545', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Hiring trend
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?php echo $trendLabels; ?>,
            datasets: [{ label: 'New Hires', data: <?php echo $trendCounts; ?>, borderColor: '#0dcaf0', backgroundColor: 'rgba(13, 202, 240, 0.1)', fill: true, tension: 0.3 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Gender
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo $genderLabels; ?>,
            datasets: [{ data: <?php echo $genderData; ?>, backgroundColor: ['#0d6efd', '#d63384'] }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Age
    new Chart(document.getElementById('ageChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $ageLabels; ?>,
            datasets: [{ label: 'Count', data: <?php echo $ageCounts; ?>, backgroundColor: '#ffc107', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Tenure
    new Chart(document.getElementById('tenureChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $tenureLabels; ?>,
            datasets: [{ label: 'Count', data: <?php echo $tenureCounts; ?>, backgroundColor: '#6610f2', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    function printMatrixOnly() {
        document.body.classList.add('print-matrix-only');
        window.print();
        document.body.classList.remove('print-matrix-only');
    }
</script>

</body>
</html>
