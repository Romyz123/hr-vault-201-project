
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
$deptFilter    = isset($_GET['dept_filter']) ? trim($_GET['dept_filter']) : '';
$genderFilter  = isset($_GET['gender']) ? trim($_GET['gender']) : '';
$agencyFilter  = isset($_GET['agency_filter']) ? trim($_GET['agency_filter']) : '';
$yearFilter    = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
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

                <div class="col-md-2">
                    <input type="text" name="job_search" class="form-control form-select-sm" placeholder="Search Job..." value="<?php echo htmlspecialchars($jobSearch); ?>">
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
        <div class="col-md-3">
            <div class="card shadow-sm h-100 text-center border-0 bg-primary text-white">
                <div class="card-body d-flex flex-column justify-content-center">
                    <h6 class="opacity-75">Active Headcount</h6>
                    <h1 class="display-3 fw-bold mb-0"><?php echo number_format($totalHeadcount); ?></h1>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0">Agency Breakdown</div>
                <div class="card-body"><canvas id="agencyChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0">Headcount by Dept</div>
                <div class="card-body"><canvas id="deptChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0 text-info">Hiring Trend (<?php echo (int)$yearFilter; ?>)</div>
                <div class="card-body"><canvas id="trendChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0">Gender Split</div>
                <div class="card-body"><canvas id="genderChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0">Age Demographics</div>
                <div class="card-body"><canvas id="ageChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white border-bottom-0">Tenure Overview</div>
                <div class="card-body"><canvas id="tenureChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100 border-danger">
                <div class="card-header bg-danger text-white border-bottom-0">
                    <i class="bi bi-door-open-fill me-2"></i> Attrition Breakdown (<?php echo (int)$yearFilter; ?>)
                </div>
                <div class="card-body">
                    <canvas id="turnoverChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
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

</div>

<script>
    const colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6610f2', '#fd7e14'];

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
