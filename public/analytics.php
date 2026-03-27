<?php
// ======================================================
// [FILE] public/analytics.php
// [STATUS] Matrix fixed (< 1 Yr shows), Column Totals at TOP,
//          As-of year logic, Safe labels, Print layout, Debug mode
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require 'options.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

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
$bdayMonth     = isset($_GET['bday_month']) ? (int)$_GET['bday_month'] : (int)date('m');
$dateFrom      = $_GET['date_from'] ?? '';
$dateTo        = $_GET['date_to'] ?? '';

// [NEW] Determine Date Range
if (!empty($dateFrom) && !empty($dateTo)) {
    $startDate = $dateFrom;
    $endDate   = $dateTo;
} else {
    $startDate = "$yearFilter-01-01";
    $endDate   = "$yearFilter-12-31";
}

$debug         = isset($_GET['debug']) ? (bool)$_GET['debug'] : false;
$includeDeleted = isset($_GET['include_deleted']) ? (bool)$_GET['include_deleted'] : false;

// --- 2. BUILD SQL (base WHERE reused by several queries) ---
$activeSQL = " WHERE status = 'Active' ";
$params = [];

$inactiveSQL = " WHERE status IN ('Resigned', 'Terminated', 'AWOL', 'Retired')
                 AND (
                     (exit_date BETWEEN ? AND ?) 
                     OR ((exit_date IS NULL OR exit_date = '0000-00-00') AND DATE(updated_at) BETWEEN ? AND ?)
                 ) ";
$inactiveParams = [$startDate, $endDate, $startDate, $endDate];

// Apply Filters (to both active & inactive where applicable)
if ($jobSearch !== '') {
    $term = "%$jobSearch%";
    $activeSQL   .= " AND job_title LIKE ? ";
    $params[] = $term;
    $inactiveSQL .= " AND job_title LIKE ? ";
    $inactiveParams[] = $term;
}
if ($deptFilter !== '') {
    $activeSQL   .= " AND dept = ? ";
    $params[] = $deptFilter;
    $inactiveSQL .= " AND dept = ? ";
    $inactiveParams[] = $deptFilter;
}
if ($genderFilter !== '') {
    $activeSQL .= " AND gender = ? ";
    $params[] = $genderFilter;
}
if ($agencyFilter !== '') {
    if ($agencyFilter === 'TESP_DIRECT') {
        $activeSQL   .= " AND (agency_name IS NULL OR agency_name = '' OR agency_name LIKE 'TESP%') ";
        $inactiveSQL .= " AND (agency_name IS NULL OR agency_name = '' OR agency_name LIKE 'TESP%') ";
    } else {
        $activeSQL   .= " AND agency_name = ? ";
        $params[] = $agencyFilter;
        $inactiveSQL .= " AND agency_name = ? ";
        $inactiveParams[] = $agencyFilter;
    }
}

// --- 3. AS-OF DATE for tenure bucketing ---
$today       = new DateTime('today');
$currentYear = (int)$today->format('Y');
// If viewing a past year, compute tenure as of Dec 31 of that year.
// If current/future, compute as of today.
if (!empty($dateTo)) {
    $asOf = new DateTime($dateTo);
    if ($asOf > $today) $asOf = $today;
} else {
    $asOf = ($yearFilter < $currentYear) ? new DateTime($yearFilter . '-12-31') : $today;
}
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
    SELECT DATE_FORMAT(COALESCE(NULLIF(exit_date, '0000-00-00'), updated_at, NOW()), '%Y-%m') AS ym, COUNT(*) AS count
    FROM employees
    $inactiveSQL
    GROUP BY ym
    ORDER BY ym ASC
";
// Re-use inactiveParams which already has the year bound
$attrTrendStmt = $pdo->prepare($attrTrendSQL);
$attrTrendStmt->execute($inactiveParams);
$attrTrendRaw = $attrTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 5c) TURNOVER BY DEPT (Inactive in selected year)
$deptTurnStmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(dept, ''), 'UNASSIGNED') as dept, COUNT(*) as count
    FROM employees
    $inactiveSQL
    GROUP BY dept
    ORDER BY count DESC
");
$deptTurnStmt->execute($inactiveParams);
$deptTurnData = $deptTurnStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$deptTurnLabels = json_encode(array_keys($deptTurnData));
$deptTurnCounts = json_encode(array_values($deptTurnData));

// 6) HIRING TREND (All hires in selected year)
// [FIX] Replace 'status = Active' with '1=1' so we correctly count employees 
// who were hired this year but may have also resigned this year.
$hireSQL = str_replace("status = 'Active'", "1=1", $activeSQL);
$trendSQL = "
    SELECT DATE_FORMAT(hire_date, '%Y-%m') AS ym, COUNT(*) AS count
    FROM employees
    $hireSQL
    AND hire_date BETWEEN ? AND ?
    GROUP BY ym
    ORDER BY ym ASC
";
$trendParams = array_merge($params, [$startDate, $endDate]);
$trendStmt = $pdo->prepare($trendSQL);
$trendStmt->execute($trendParams);
$trendRaw = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// [NEW] Generate Dynamic Labels for Trend Charts
$trendLabelsArr = [];
$trendDataArr   = [];
$attrDataArr    = [];
$netGrowthArr   = [];

$start    = new DateTime($startDate);
$end      = new DateTime($endDate);
$interval = DateInterval::createFromDateString('1 month');
$period   = new DatePeriod($start, $interval, $end->modify('+1 day')); // Inclusive

foreach ($period as $dt) {
    $key = $dt->format('Y-m');
    $label = $dt->format('M Y'); // e.g. "Jan 2024"

    $trendLabelsArr[] = $label;
    $trendDataArr[]   = isset($trendRaw[$key]) ? (int)$trendRaw[$key] : 0;
    $attrDataArr[]    = isset($attrTrendRaw[$key]) ? (int)$attrTrendRaw[$key] : 0;
    $netGrowthArr[]   = end($trendDataArr) - end($attrDataArr);
}

$trendLabels     = json_encode($trendLabelsArr);
$trendCounts     = json_encode($trendDataArr);
$attrTrendCounts = json_encode($attrDataArr);
$netGrowthCounts = json_encode($netGrowthArr);

// [NEW] Pre-calculate colors for Net Growth on the server to prevent JS syntax errors
$netGrowthColorsArr = [];
foreach ($netGrowthArr as $val) {
    $netGrowthColorsArr[] = $val >= 0 ? '#198754' : '#dc3545'; // Green for positive/zero, Red for negative
}
$netGrowthColors = json_encode($netGrowthColorsArr);

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

// 8) TURNOVER RATE CALCULATION (Phase 2 Feature)
// Formula: (Total Exits / Average Headcount) * 100
// Average Headcount = (Start of Year Headcount + End of Year Headcount) / 2

// A. Total Exits in Selected Year
$totalExits = array_sum($attrDataArr);

// B. Headcount at Start of Year (Approximate: Current Active + Exits this year - Hires this year)
// This is a simplified estimation. For exact precision, we'd need a daily snapshot table.
$hiresThisYear = array_sum($trendDataArr);
$startHeadcount = $totalHeadcount + $totalExits - $hiresThisYear;
$endHeadcount   = $totalHeadcount; // Assuming current state is end state for calculation
$avgHeadcount   = ($startHeadcount + $endHeadcount) / 2;

$turnoverRate = ($avgHeadcount > 0) ? round(($totalExits / $avgHeadcount) * 100, 2) : 0;

// 9) AVERAGE TENURE (Active)
$asOfDateStr = $asOf->format('Y-m-d');
$avgTenureStmt = $pdo->prepare("SELECT AVG(DATEDIFF(?, hire_date)) FROM employees $activeSQL AND hire_date IS NOT NULL AND hire_date != '0000-00-00'");
$avgParams = array_merge([$asOfDateStr], $params);
$avgTenureStmt->execute($avgParams);
$avgTenureDays = $avgTenureStmt->fetchColumn();
$avgTenureYears = $avgTenureDays ? round($avgTenureDays / 365.25, 1) : 0;

// 10) PERFORMANCE RATINGS (Latest per employee)
$perfLabels = '[]';
$perfCounts = '[]';
try {
    // Check if table exists
    $pdo->query("SELECT 1 FROM hr_performance_reviews LIMIT 1");

    $perfStmt = $pdo->prepare("
        SELECT rating, COUNT(*) as count
        FROM hr_performance_reviews r
        INNER JOIN (
            SELECT MAX(id) as max_id
            FROM hr_performance_reviews
                WHERE YEAR(review_date) = ?
            GROUP BY employee_id
        ) latest ON r.id = latest.max_id
        JOIN employees e ON r.employee_id = e.id
        $activeSQL
        GROUP BY rating
        ORDER BY rating DESC
    ");
    $perfParams = array_merge([$yearFilter], $params);
    $perfStmt->execute($perfParams);
    $perfData = $perfStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $ratingMap = [
        5 => '5 - Excellent',
        4 => '4 - Exceeds Expectations',
        3 => '3 - Meets Expectations',
        2 => '2 - Needs Improvement',
        1 => '1 - Unsatisfactory'
    ];
    $mappedPerfData = [];
    krsort($perfData); // Sort from 5 down to 1
    foreach ($perfData as $rating => $count) {
        $mappedLabel = isset($ratingMap[$rating]) ? $ratingMap[$rating] : "Rating $rating";
        $mappedPerfData[$mappedLabel] = $count;
    }
    $perfLabels = json_encode(array_keys($mappedPerfData));
    $perfCounts = json_encode(array_values($mappedPerfData));
} catch (Exception $e) {
}

// 7) DEMOGRAPHICS & TENURE MATRIX (Slug Strategy + Column Totals)

// Stable computation IDs
$bandOrder = ['b0', 'b1', 'b2', 'b3', 'b4'];
$bandLabels = [
    'b0' => '< 1 Yr',
    'b1' => '1-3 Yrs',
    'b2' => '3-5 Yrs',
    'b3' => '5-10 Yrs',
    'b4' => '10+ Yrs',
];

$rawStmt = $pdo->prepare("SELECT emp_id, dept, birth_date, hire_date, gender FROM employees $activeSQL");
$rawStmt->execute($params);
$rows = $rawStmt->fetchAll(PDO::FETCH_ASSOC);

// 11) VAULT COMPLIANCE SCORE
$complianceData = [
    'overall' => 0,
    'by_dept_labels' => '[]',
    'by_dept_data' => '[]',
];
try {
    // A. Fetch Requirements
    $REQUIRED_DOCS = [];
    $reqStmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY name ASC");
    while ($row = $reqStmt->fetch(PDO::FETCH_ASSOC)) {
        $REQUIRED_DOCS[$row['name']] = array_map('trim', explode(',', $row['keywords']));
    }
    $totalRequirements = count($REQUIRED_DOCS);

    if ($totalRequirements > 0 && !empty($rows)) {
        // B. Fetch all documents for the filtered active employees
        $empIdsForCompliance = array_column($rows, 'emp_id');
        $placeholders = implode(',', array_fill(0, count($empIdsForCompliance), '?'));
        $docSql = "SELECT d.employee_id, d.category, d.original_name 
                   FROM documents d
                   WHERE d.employee_id IN ($placeholders) AND d.deleted_at IS NULL";
        $docStmt = $pdo->prepare($docSql);
        $docStmt->execute($empIdsForCompliance);
        $allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

        $docsMap = [];
        foreach ($allDocs as $d) {
            $empId = $d['employee_id'];
            foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
                if (stripos($d['category'], $reqKey) !== false) {
                    $docsMap[$empId][$reqKey] = true;
                    continue;
                }
                foreach ($keywords as $k) {
                    if (stripos($d['original_name'], $k) !== false) {
                        $docsMap[$empId][$reqKey] = true;
                        break;
                    }
                }
            }
        }

        // C. Fetch all exemptions for the filtered active employees
        $exemptSql = "SELECT ex.employee_id, ex.requirement_name FROM document_exemptions ex WHERE ex.employee_id IN ($placeholders)";
        $exemptStmt = $pdo->prepare($exemptSql);
        $exemptStmt->execute($empIdsForCompliance);
        $exemptMap = [];
        while ($row = $exemptStmt->fetch(PDO::FETCH_ASSOC)) {
            $exemptMap[$row['employee_id']][$row['requirement_name']] = true;
        }

        // D. Calculate Compliance
        $complianceByDept = [];
        $totalScores = 0;
        foreach ($rows as $emp) {
            $empId = $emp['emp_id'];
            $dept = $emp['dept'] ?: 'UNASSIGNED';
            if (!isset($complianceByDept[$dept])) $complianceByDept[$dept] = ['total_score' => 0, 'employee_count' => 0];
            $have = 0;
            foreach ($REQUIRED_DOCS as $reqKey => $keywords) if (isset($docsMap[$empId][$reqKey]) || isset($exemptMap[$empId][$reqKey])) $have++;
            $percent = ($totalRequirements > 0) ? ($have / $totalRequirements) * 100 : 0;
            $complianceByDept[$dept]['total_score'] += $percent;
            $complianceByDept[$dept]['employee_count']++;
            $totalScores += $percent;
        }
        $complianceData['overall'] = count($rows) > 0 ? round($totalScores / count($rows)) : 0;
        ksort($complianceByDept);
        foreach ($complianceByDept as $dept => $data) $complianceChartData[] = ($data['employee_count'] > 0) ? round($data['total_score'] / $data['employee_count']) : 0;
        $complianceData['by_dept_labels'] = json_encode(array_keys($complianceByDept));
        $complianceData['by_dept_data'] = json_encode($complianceChartData ?? []);
    }
} catch (Exception $e) {
}

// 12) EXPIRY FORECAST (Next 6 Months)
$formattedExpLabels = [];
$expiryDatasets = [];
try {
    $expStmt = $pdo->query("
        SELECT DATE_FORMAT(d.expiry_date, '%Y-%m') as ym, d.category, COUNT(*) as count 
        FROM documents d 
        JOIN employees e ON d.employee_id = e.emp_id 
        WHERE d.expiry_date >= CURDATE() 
          AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
          AND d.deleted_at IS NULL 
          AND d.is_resolved = 0
          AND e.status = 'Active'
        GROUP BY ym, d.category
        ORDER BY ym ASC
    ");
    $rawExp = $expStmt->fetchAll(PDO::FETCH_ASSOC);
    $monthsMap = [];
    $categoriesFound = [];
    foreach ($rawExp as $row) {
        $ym = $row['ym'];
        $cat = $row['category'] ?: 'Uncategorized';
        if (!isset($monthsMap[$ym])) $monthsMap[$ym] = [];
        $monthsMap[$ym][$cat] = (int)$row['count'];
        $categoriesFound[$cat] = true;
    }
    $expiryLabels = array_keys($monthsMap);
    $formattedExpLabels = array_map(function ($ym) {
        return date('M Y', strtotime($ym . '-01'));
    }, $expiryLabels);

    $cats = array_keys($categoriesFound);
    $palette = ['#0dcaf0', '#ffc107', '#dc3545', '#198754', '#6610f2', '#fd7e14', '#20c997'];
    $cIdx = 0;
    foreach ($cats as $cat) {
        $data = [];
        foreach ($expiryLabels as $ym) {
            $data[] = $monthsMap[$ym][$cat] ?? 0;
        }
        $expiryDatasets[] = ['label' => $cat, 'data' => $data, 'backgroundColor' => $palette[$cIdx % count($palette)], 'borderRadius' => 4];
        $cIdx++;
    }
} catch (Exception $e) {
}
$expLabelsJson = json_encode($formattedExpLabels);
$expDatasetsJson = json_encode($expiryDatasets);

// 13) RECRUITMENT PIPELINE
$recruitLabels = '[]';
$recruitCounts = '[]';
try {
    $pdo->query("SELECT 1 FROM candidates LIMIT 1");
    $recStmt = $pdo->query("SELECT status, COUNT(*) as count FROM candidates GROUP BY status ORDER BY count DESC");
    $recData = $recStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $recruitLabels = json_encode(array_keys($recData));
    $recruitCounts = json_encode(array_values($recData));
} catch (Exception $e) {
}

// Aggregates
$ageBands          = ['18-25' => 0, '26-35' => 0, '36-45' => 0, '46-55' => 0, '56+' => 0];
$genderCounts      = ['Male' => 0, 'Female' => 0];
$tenureBandsCounts = array_fill_keys($bandOrder, 0);
$tenureMatrix      = []; // dept => [b0..b4]
$columnTotals      = array_fill_keys($bandOrder, 0);

// [OPTIMIZATION] Re-use $asOf date to ensure historical accuracy across Age Demographics
$evalDateObj = $asOf;

/**
 * Determine tenure band by months as of $asOf.
 * For past-year snapshots, rows with hire_date after $asOf are excluded (return null).
 */
function get_tenure_band_slug(string $hireDate, DateTime $asOf): ?string
{
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
    $empId = $r['emp_id']; // For compliance calculation reuse
    $g = ucfirst(strtolower(trim((string)$r['gender'])));
    if (isset($genderCounts[$g])) {
        $genderCounts[$g]++;
    }

    // Age (kept as-of today; switch to $asOf if you want snapshot ages)
    if (!empty($r['birth_date']) && $r['birth_date'] !== '0000-00-00') {
        $bDateObj = date_create($r['birth_date']);
        if ($bDateObj) {
            $age = date_diff($bDateObj, $evalDateObj)->y;
            if ($age <= 25) $ageBands['18-25']++;
            elseif ($age <= 35) $ageBands['26-35']++;
            elseif ($age <= 45) $ageBands['36-45']++;
            elseif ($age <= 55) $ageBands['46-55']++;
            else                  $ageBands['56+']++;
        }
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
$tenureLabels = json_encode($tenureChartLabelsArr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$tenureCounts = json_encode($tenureChartCountsArr);

// Calculate Grand Total early for Export/Display
$grandTotal = 0;
foreach ($bandOrder as $b) {
    $grandTotal += (int)$columnTotals[$b];
}

// [NEW] BIRTHDAYS QUERY
$bdayQuery = "SELECT emp_id, first_name, last_name, dept, job_title, birth_date
              FROM employees
              $activeSQL AND birth_date IS NOT NULL AND birth_date != '0000-00-00' AND MONTH(birth_date) = ?
              ORDER BY DAY(birth_date) ASC, last_name ASC";
$bdayStmt = $pdo->prepare($bdayQuery);
$bdayParams = array_merge($params, [$bdayMonth]);
$bdayStmt->execute($bdayParams);
$birthdayCelebrants = $bdayStmt->fetchAll(PDO::FETCH_ASSOC);
$monthName = date('F', mktime(0, 0, 0, $bdayMonth, 10));

// [NEW] BIRTHDAY DISTRIBUTION (Annual Forecast by Month)
$bdayDistData = array_fill(1, 12, 0);
$bdayDistStmt = $pdo->prepare("SELECT MONTH(birth_date) as m, COUNT(*) as count FROM employees $activeSQL AND birth_date IS NOT NULL AND birth_date != '0000-00-00' GROUP BY MONTH(birth_date)");
$bdayDistStmt->execute($params);
while ($row = $bdayDistStmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['m']) $bdayDistData[(int)$row['m']] = (int)$row['count'];
}
$bdayDistLabels = json_encode(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
$bdayDistCounts = json_encode(array_values($bdayDistData));

// [NEW] Handle Birthday Export
if (isset($_GET['export_birthdays'])) {
    $m = (int)$_GET['export_birthdays'];
    $monthNameExport = date('F', mktime(0, 0, 0, $m, 10));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Birthdays_' . $monthNameExport . '_' . date('Y') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Birth Date', 'Employee ID', 'Last Name', 'First Name', 'Department', 'Job Title']);

    $bdayQueryExp = "SELECT emp_id, last_name, first_name, dept, job_title, birth_date FROM employees $activeSQL AND birth_date IS NOT NULL AND birth_date != '0000-00-00' AND MONTH(birth_date) = ? ORDER BY DAY(birth_date) ASC, last_name ASC";
    $expParams = array_merge($params, [$m]);
    $stmt = $pdo->prepare($bdayQueryExp);
    $stmt->execute($expParams);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            date('M d', strtotime($row['birth_date'])),
            $row['emp_id'],
            $row['last_name'],
            $row['first_name'],
            $row['dept'],
            $row['job_title']
        ]);
    }
    fclose($output);
    exit;
}

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
    // [SECURITY] Restrict debug output to Admins only
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') die("Access Denied: Debug mode is restricted.");

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
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
    <script src="assets/chart.min.js"></script>
    <style>
        .card-header {
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .matrix-table th {
            font-size: 0.75rem;
            text-align: center;
            background-color: #f8f9fa;
        }

        .matrix-table td {
            font-size: 0.8rem;
            text-align: center;
            vertical-align: middle;
        }

        .matrix-dept {
            text-align: left !important;
            font-weight: bold;
            color: #495057;
        }

        .table thead th {
            white-space: nowrap;
        }

        /* (Optional) Sticky header + sticky totals row in header */
        .matrix-table thead tr:first-child th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8f9fa;
        }

        .matrix-table thead tr.thead-totals th {
            position: sticky;
            top: 38px;
            z-index: 1;
            background: #e9ecef;
        }

        /* PROFESSIONAL PRINT STYLES */
        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }

            body {
                background: white !important;
                font-size: 12px;
            }

            .no-print,
            .navbar,
            .btn,
            form {
                display: none !important;
            }

            .container-fluid {
                padding: 0 !important;
                max-width: 100% !important;
            }

            .row {
                display: flex !important;
                flex-wrap: wrap !important;
            }

            /* Force Bootstrap Grid to hold shape perfectly on paper */
            .col-md-2 {
                width: 16.666667% !important;
                flex: 0 0 16.666667% !important;
                padding: 0 10px !important;
            }

            .col-md-3 {
                width: 25% !important;
                flex: 0 0 25% !important;
                padding: 0 10px !important;
            }

            .col-md-4 {
                width: 33.333333% !important;
                flex: 0 0 33.333333% !important;
                padding: 0 10px !important;
            }

            .col-md-6 {
                width: 50% !important;
                flex: 0 0 50% !important;
                padding: 0 10px !important;
            }

            .col-md-8 {
                width: 66.666667% !important;
                flex: 0 0 66.666667% !important;
                padding: 0 10px !important;
            }

            .col-12 {
                width: 100% !important;
                flex: 0 0 100% !important;
                padding: 0 10px !important;
            }

            .card {
                border: 1px solid #ddd !important;
                box-shadow: none !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin-bottom: 20px !important;
            }

            .card-header {
                background-color: #f0f0f0 !important;
                color: black !important;
                font-size: 10pt;
                padding: 8px !important;
            }

            .card-body {
                padding: 10px !important;
            }

            canvas {
                max-height: 250px !important;
                width: 100% !important;
            }

            .table-responsive {
                overflow: visible !important;
            }

            .matrix-table {
                font-size: 9pt;
                width: 100%;
                border-collapse: collapse;
            }

            .matrix-table th,
            .matrix-table td {
                border: 1px solid #999 !important;
                padding: 4px 6px !important;
            }

            .matrix-dept {
                width: 20%;
            }
        }

        .bg-total {
            background-color: #ced4da !important;
        }

        .bg-black {
            background-color: #000000 !important;
            color: #ffffff !important;
        }

        @media print {
            body.print-matrix-only * {
                visibility: hidden;
            }

            body.print-matrix-only #matrixCard,
            body.print-matrix-only #matrixCard * {
                visibility: visible;
            }

            body.print-matrix-only #matrixCard {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                border: none;
            }
        }
    </style>
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold">📊 Workforce Intelligence</span>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">

        <div class="card shadow-sm mb-4 border-primary no-print">
            <div class="card-body py-2 rounded">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-auto"><i class="bi bi-funnel-fill text-muted"></i></div>
                    <div class="col-md-auto">
                        <select name="year" class="form-select form-select-sm fw-bold text-primary" onchange="document.getElementsByName('date_from')[0].value=''; document.getElementsByName('date_to')[0].value=''; this.form.submit()">
                            <?php $cur = (int)date('Y');
                            for ($y = $cur; $y >= 2000; $y--) echo "<option value='$y' " . ($y == $yearFilter ? 'selected' : '') . ">📅 " . htmlspecialchars($y) . "</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light">Range</span>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>" title="Start Date">
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>" title="End Date">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select name="agency_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Agencies</option>
                            <option value="TESP_DIRECT" <?php if ($agencyFilter === 'TESP_DIRECT') echo 'selected'; ?>>TESP Direct</option>
                            <?php foreach ($agencies as $a):
                                if (stripos($a, 'TESP') !== false) continue; // Skip TESP Direct as it is handled above
                            ?>
                                <option value="<?php echo htmlspecialchars($a); ?>" <?php if ($agencyFilter === $a) echo 'selected'; ?>><?php echo htmlspecialchars($a); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="prob_months" class="form-select form-select-sm" onchange="this.form.submit()" title="Set Probationary Period">
                            <option value="3" <?php if ($probMonths === 3) echo 'selected'; ?>>Probation: 3 Mos</option>
                            <option value="6" <?php if ($probMonths === 6) echo 'selected'; ?>>Probation: 6 Mos</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="bday_month" class="form-select form-select-sm" onchange="this.form.submit()" title="Filter Birthdays">
                            <?php
                            for ($m = 1; $m <= 12; $m++) {
                                $mName = date('F', mktime(0, 0, 0, $m, 10));
                                $sel = ($bdayMonth == $m) ? 'selected' : '';
                                echo "<option value='$m' $sel>🎂 $mName Birthdays</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="dept_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Depts</option>
                            <?php
                            $allDepts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE dept != '' ORDER BY dept ASC")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($allDepts as $d) {
                                $safe = htmlspecialchars($d);
                                $sel  = ($d === $deptFilter) ? 'selected' : '';
                                echo "<option value=\"$safe\" $sel>$safe</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="gender" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Genders</option>
                            <option value="Male" <?php if ($genderFilter === 'Male') echo 'selected'; ?>>Male</option>
                            <option value="Female" <?php if ($genderFilter === 'Female') echo 'selected'; ?>>Female</option>
                        </select>
                    </div>

                    <div class="col-auto">
                        <div class="form-check form-switch pt-1" title="Show historical data including deleted files">
                            <input class="form-check-input" type="checkbox" name="include_deleted" id="incDel" value="1" <?php if ($includeDeleted) echo 'checked'; ?> onchange="this.form.submit()">
                            <label class="form-check-label small fw-bold text-secondary" for="incDel">Show Deleted</label>
                        </div>
                    </div>

                    <div class="col-md-1">
                        <?php $allJobs = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title != '' ORDER BY job_title ASC")->fetchAll(PDO::FETCH_COLUMN); ?>
                        <input type="text" name="job_search" list="job_list" class="form-control form-select-sm" placeholder="Search Job..." value="<?php echo htmlspecialchars($jobSearch); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ \.\&\/\(\),]+" title="Allowed: Alphanumeric and . & / ( ) ,">
                        <datalist id="job_list">
                            <?php foreach ($allJobs as $j) echo "<option value=\"" . htmlspecialchars($j) . "\">"; ?>
                        </datalist>
                    </div>

                    <div class="col-auto ms-auto d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Apply</button>
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-dark"><i class="bi bi-printer"></i> Print</button>
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
                | Period: <?php echo htmlspecialchars($startDate . ' to ' . $endDate); ?>
            </p>
            <hr>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100 text-center border-0 bg-primary text-white">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h6 class="opacity-75">Active Headcount</h6>
                        <h1 class="display-3 fw-bold mb-0"><?php echo htmlspecialchars(number_format($totalHeadcount)); ?></h1>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning text-dark border-bottom-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-hourglass-split me-2"></i> Status (<?php echo htmlspecialchars($probMonths); ?>m)</span>
                        <div>
                            <span class="badge bg-dark text-white me-2"><?php echo htmlspecialchars($probCount); ?> Probie</span>
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
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100 border-danger">
                    <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-graph-down-arrow me-2"></i> Turnover Rate</span>
                    </div>
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h1 class="display-3 fw-bold mb-0 text-danger"><?php echo htmlspecialchars($turnoverRate); ?>%</h1>
                        <small class="text-muted">Based on <?php echo htmlspecialchars($totalExits); ?> exits in <?php echo htmlspecialchars($yearFilter); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100 border-info">
                    <div class="card-header bg-info text-dark border-bottom-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock-history me-2"></i> Avg Tenure</span>
                    </div>
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h1 class="display-3 fw-bold mb-0 text-info"><?php echo htmlspecialchars($avgTenureYears); ?></h1>
                        <small class="text-muted">Years</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card shadow-sm h-100 border-info">
                    <div class="card-header bg-info text-dark border-bottom-0">
                        <i class="bi bi-shield-check me-2"></i> Vault Compliance
                    </div>
                    <div class="card-body text-center d-flex flex-column justify-content-center">
                        <h1 class="display-3 fw-bold mb-0 text-info"><?php echo $complianceData['overall']; ?>%</h1>
                        <small class="text-muted">Overall Completion</small>
                    </div>
                </div>
            </div>
            <div class="col-md-9 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Compliance by Department</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('complianceChart', 'Compliance by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="complianceChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8 mb-3">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center bg-warning text-dark">
                        <span><i class="bi bi-calendar-x-fill me-1"></i> Expiry Forecast (6 Months)</span>
                        <div>
                            <button class="btn btn-sm btn-link text-dark p-0 me-1" onclick="downloadSpecificChart('expiryChart', 'Expiry_Forecast')" title="Download Image"><i class="bi bi-download"></i></button>
                            <button class="btn btn-sm btn-link text-dark p-0" onclick="openFullScreen('expiryChart', 'Contract & Document Expiries')"><i class="bi bi-arrows-fullscreen"></i></button>
                        </div>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="expiryChart"></canvas></div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm h-100 border-success">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center bg-success text-white">
                        <span><i class="bi bi-person-lines-fill me-1"></i> Recruitment ATS</span>
                        <div>
                            <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('recruitChart', 'Recruitment_Pipeline')" title="Download Image"><i class="bi bi-download"></i></button>
                            <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('recruitChart', 'Recruitment Pipeline')"><i class="bi bi-arrows-fullscreen"></i></button>
                        </div>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="recruitChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Agency Breakdown</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('agencyChart', 'Agency Breakdown')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="agencyChart"></canvas></div>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Headcount by Dept</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('deptChart', 'Headcount by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="deptChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 text-info d-flex justify-content-between align-items-center">
                        <span>Net Workforce Growth</span>
                        <button class="btn btn-sm btn-link text-info p-0" onclick="openFullScreen('trendChart', 'Net Workforce Growth')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Gender Split</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('genderChart', 'Gender Distribution')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="genderChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Performance Ratings</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('perfChart', 'Performance Ratings')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="perfChart"></canvas></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Age Demographics</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('ageChart', 'Age Demographics')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="ageChart"></canvas></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card shadow-sm h-100">
                    <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                        <span>Tenure Overview</span>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('tenureChart', 'Tenure Overview')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;"><canvas id="tenureChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card shadow-sm h-100 border-danger">
                    <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-pie-chart-fill me-1"></i> Attrition Status</span>
                        <div>
                            <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('turnoverChart', 'Attrition_Status')" title="Download Image"><i class="bi bi-download"></i></button>
                            <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('turnoverChart', 'Attrition Status')" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
                        </div>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;">
                        <canvas id="turnoverChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100 border-danger">
                    <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bar-chart-fill me-1"></i> Exits by Dept</span>
                        <div>
                            <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('deptTurnoverChart', 'Exits_By_Department')" title="Download Image"><i class="bi bi-download"></i></button>
                            <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('deptTurnoverChart', 'Exits by Department')" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
                        </div>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;">
                        <canvas id="deptTurnoverChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100 border-danger">
                    <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-graph-down-arrow me-1"></i> Monthly Trend</span>
                        <div>
                            <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('attritionTrendChart', 'Monthly_Attrition_Trend')" title="Download Image"><i class="bi bi-download"></i></button>
                            <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('attritionTrendChart', 'Monthly Attrition Trend')" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
                        </div>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;">
                        <canvas id="attritionTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100 border-danger">
                    <div class="card-header bg-danger text-white border-bottom-0">
                        <i class="bi bi-chat-quote-fill me-1"></i> Top Exit Reasons
                    </div>
                    <div class="card-body p-2">
                        <?php if (empty($reasonData)): ?>
                            <div class="text-center text-muted py-5">No data.</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($reasonData as $r): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-1 py-2">
                                        <span class="text-truncate me-2" title="<?php echo htmlspecialchars($r['exit_reason']); ?>"><?php echo htmlspecialchars($r['exit_reason']); ?></span>
                                        <span class="badge bg-danger rounded-pill"><?php echo htmlspecialchars((int)$r['count']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-lg-4 mb-4 mb-lg-0">
                <div class="card shadow-sm border-info h-100" id="birthdayCard">
                    <div class="card-header bg-info text-dark d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-gift-fill"></i> Birthdays - <?php echo htmlspecialchars($monthName); ?></span>
                        <div>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export_birthdays' => $bdayMonth])); ?>" class="btn btn-sm btn-light text-dark fw-bold no-print py-0 px-2" title="Export Excel">
                                <i class="bi bi-download"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0 table-responsive" style="max-height: 350px; overflow-y: auto;">
                        <table class="table table-hover table-striped mb-0 align-middle">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($birthdayCelebrants)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted p-4">No birthdays found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($birthdayCelebrants as $b): ?>
                                        <tr>
                                            <td class="fw-bold text-danger text-nowrap"><?php echo date('M d', strtotime($b['birth_date'])); ?></td>
                                            <td>
                                                <div class="fw-bold text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($b['last_name'] . ', ' . $b['first_name']); ?>"><?php echo htmlspecialchars($b['last_name'] . ', ' . $b['first_name']); ?></div>
                                                <div class="small text-muted text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($b['dept'] . ' - ' . $b['job_title']); ?>">
                                                    <?php echo htmlspecialchars($b['dept'] . ' - ' . $b['job_title']); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-4 mb-lg-0">
                <div class="card shadow-sm border-success h-100" id="anniversaryCard">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-award-fill"></i> Anniversaries - <?php echo htmlspecialchars($monthName); ?></span>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export_anniversaries' => $bdayMonth])); ?>" class="btn btn-sm btn-light text-success fw-bold no-print py-0 px-2" title="Export Excel">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                    <div class="card-body p-0 table-responsive" style="max-height: 350px; overflow-y: auto;">
                        <table class="table table-hover table-striped mb-0 align-middle">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Date</th>
                                    <th>Employee</th>
                                    <th>Years</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($workAnniversaries)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted p-4">No work anniversaries found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($workAnniversaries as $a):
                                        $yrs = $a['years_of_service'];
                                        $badge = 'bg-secondary';
                                        if ($yrs >= 10) $badge = 'bg-danger';
                                        elseif ($yrs >= 5) $badge = 'bg-warning text-dark';
                                        elseif ($yrs >= 3) $badge = 'bg-primary';
                                        elseif ($yrs >= 1) $badge = 'bg-success';
                                    ?>
                                        <tr>
                                            <td class="fw-bold text-success text-nowrap"><?php echo date('M d', strtotime($a['hire_date'])); ?></td>
                                            <td>
                                                <div class="fw-bold text-truncate" style="max-width: 130px;" title="<?php echo htmlspecialchars($a['last_name'] . ', ' . $a['first_name']); ?>"><?php echo htmlspecialchars($a['last_name'] . ', ' . $a['first_name']); ?></div>
                                                <div class="small text-muted text-truncate" style="max-width: 130px;" title="<?php echo htmlspecialchars($a['dept']); ?>"><?php echo htmlspecialchars($a['dept']); ?></div>
                                            </td>
                                            <td><span class="badge <?php echo $badge; ?> rounded-pill"><?php echo $yrs; ?> Yr<?php echo $yrs > 1 ? 's' : ''; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm border-info h-100">
                    <div class="card-header bg-info text-dark d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-bar-chart-fill"></i> Birthdays per Month</span>
                        <button class="btn btn-sm btn-link text-dark p-0" onclick="openFullScreen('bdayChart', 'Birthdays per Month')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                    <div class="card-body position-relative" style="min-height: 250px;">
                        <canvas id="bdayMonthChart"></canvas>
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
                                <tr>
                                    <th class="matrix-dept">Dept</th>
                                    <?php foreach ($bandOrder as $b): ?>
                                        <th><?php echo htmlspecialchars($bandLabels[$b], ENT_QUOTES); ?></th>
                                    <?php endforeach; ?>
                                    <th class="bg-black">Total</th>
                                    <th class="bg-black">% Share</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($tenureMatrix as $dept => $bands):
                                    $rowTotal = array_sum($bands);
                                ?>
                                    <tr>
                                        <td class="matrix-dept"><?php echo htmlspecialchars($dept, ENT_QUOTES); ?></td>

                                        <?php foreach ($bandOrder as $b):
                                            $val = (int)$bands[$b];
                                            $cls = $val > 0
                                                ? ($b === 'b4' ? 'fw-bold text-success' : 'fw-bold')
                                                : 'text-muted opacity-25';
                                        ?>
                                            <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($val); ?></td>
                                        <?php endforeach; ?>

                                        <td class="fw-bold bg-total"><?php echo htmlspecialchars($rowTotal); ?></td>
                                        <td class="fw-bold text-muted small"><?php echo htmlspecialchars(($grandTotal > 0) ? round(($rowTotal / $grandTotal) * 100, 1) : 0); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>
                </div>
            </div>
        </div>

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
                            Showing employees hired within the last <strong><?php echo htmlspecialchars($probMonths); ?> months</strong> (after <?php echo htmlspecialchars($probThresholdDate); ?>).
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
                                <?php foreach ($probList as $p): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?></td>
                                        <td><a href="index.php?search=<?php echo urlencode($p['emp_id']); ?>" target="_blank" class="text-decoration-none"><?php echo htmlspecialchars($p['emp_id']); ?></a></td>
                                        <td><?php echo htmlspecialchars($p['dept']); ?></td>
                                        <td><?php echo htmlspecialchars($p['hire_date']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($probList)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No probationary employees found.</td>
                                    </tr>
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

        <div class="modal fade" id="fullScreenModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header bg-light">
                        <h5 class="modal-title fw-bold text-primary" id="fsModalTitle">Chart View</h5>
                        <div class="ms-auto d-flex align-items-center gap-3">
                            <button type="button" class="btn btn-outline-primary btn-sm fw-bold" onclick="downloadChartImage()">
                                <i class="bi bi-download"></i> Download Image
                            </button>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body">
                        <div class="row h-100">
                            <div class="col-lg-8 d-flex align-items-center justify-content-center bg-white border-end">
                                <div style="width: 95%; height: 90%;">
                                    <canvas id="fsChartCanvas"></canvas>
                                </div>
                            </div>
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

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>

    <script>
        const colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6610f2', '#fd7e14'];
        const charts = {}; // Store chart instances for theme updates

        // [NEW] 100% Offline Custom DataLabels Plugin (No Internet Required)
        const offlineDataLabels = {
            id: 'offlineDataLabels',
            afterDatasetsDraw(chart, args, options) {
                const {
                    ctx
                } = chart;
                ctx.save();
                ctx.font = 'bold 12px Helvetica, Arial, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                chart.data.datasets.forEach((dataset, i) => {
                    const meta = chart.getDatasetMeta(i);
                    if (meta.hidden) return;

                    meta.data.forEach((element, index) => {
                        let dataVal = dataset.data[index];
                        if (dataVal === undefined || dataVal === null || Number(dataVal) === 0) return; // Hide zeros

                        let text = dataVal.toString();
                        if (dataset.label === 'Compliance %' || chart.canvas.id === 'complianceChart') text += '%';

                        if (chart.config.type === 'pie' || chart.config.type === 'doughnut') {
                            let total = dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                            let percent = Math.round((dataVal / total) * 100);
                            if (percent < 5) return; // Hide small slices
                            text = `${dataVal} (${percent}%)`;
                        }

                        if (typeof element.tooltipPosition !== 'function') return;
                        let pos = element.tooltipPosition();

                        let x = pos.x;
                        let y = pos.y;

                        // Check if it's a bar chart to center the text
                        if ((chart.config.type === 'bar' || meta.type === 'bar') && element.base !== undefined) {
                            y = (element.base + pos.y) / 2; // Center vertically inside bars
                        }

                        // Text Stroke (Outline)
                        ctx.strokeStyle = 'rgba(0, 0, 0, 0.75)';
                        ctx.lineWidth = 3;
                        ctx.strokeText(text, x, y);
                        // Text Fill
                        ctx.fillStyle = '#ffffff';
                        ctx.fillText(text, x, y);
                    });
                });
                ctx.restore();
            }
        };
        // Register the offline plugin globally
        Chart.register(offlineDataLabels);

        // Data Store for Full Screen Mode
        const chartData = {
            'statusChart': {
                labels: ['Regular', 'Probationary'],
                data: [<?php echo $regCount; ?>, <?php echo $probCount; ?>],
                type: 'doughnut',
                bg: ['#198754', '#ffc107']
            },
            'agencyChart': {
                labels: <?php echo $agencyLabels; ?>,
                data: <?php echo $agencyCounts; ?>,
                type: 'doughnut',
                bg: colors
            },
            'deptChart': {
                labels: <?php echo $deptLabels; ?>,
                data: <?php echo $deptCounts; ?>,
                type: 'bar',
                bg: '#198754'
            },
            'trendChart': {
                labels: <?php echo $trendLabels; ?>,
                data: <?php echo $netGrowthCounts; ?>,
                type: 'line',
                bg: 'rgba(13, 202, 240, 0.2)',
                border: '#0dcaf0'
            },
            'genderChart': {
                labels: <?php echo $genderLabels; ?>,
                data: <?php echo $genderData; ?>,
                type: 'doughnut',
                bg: ['#0d6efd', '#d63384']
            },
            'ageChart': {
                labels: <?php echo $ageLabels; ?>,
                data: <?php echo $ageCounts; ?>,
                type: 'bar',
                bg: '#ffc107'
            },
            'tenureChart': {
                labels: <?php echo $tenureLabels; ?>,
                data: <?php echo $tenureCounts; ?>,
                type: 'bar',
                bg: '#6610f2'
            },
            'turnoverChart': {
                labels: <?php echo $turnLabels; ?>,
                data: <?php echo $turnCounts; ?>,
                type: 'pie',
                bg: ['#ffc107', '#dc3545', '#212529', '#6c757d']
            },
            'attritionTrendChart': {
                labels: <?php echo $trendLabels; ?>,
                data: <?php echo $attrTrendCounts; ?>,
                type: 'bar',
                bg: '#dc3545'
            },
            'deptTurnoverChart': {
                labels: <?php echo $deptTurnLabels; ?>,
                data: <?php echo $deptTurnCounts; ?>,
                type: 'bar',
                bg: '#fd7e14'
            },
            'perfChart': {
                labels: <?php echo $perfLabels; ?>,
                data: <?php echo $perfCounts; ?>,
                type: 'bar',
                bg: ['#198754', '#0dcaf0', '#ffc107', '#fd7e14', '#dc3545']
            },
            'complianceChart': {
                labels: <?php echo $complianceData['by_dept_labels']; ?>,
                data: <?php echo $complianceData['by_dept_data']; ?>,
                type: 'bar',
                bg: '#0dcaf0'
            },
            'expiryChart': {
                labels: <?php echo $expLabelsJson; ?>,
                datasets: <?php echo $expDatasetsJson; ?>,
                type: 'stacked_bar'
            },
            'recruitChart': {
                labels: <?php echo $recruitLabels; ?>,
                data: <?php echo $recruitCounts; ?>,
                type: 'doughnut',
                bg: ['#6c757d', '#0dcaf0', '#ffc107', '#198754', '#dc3545', '#0d6efd']
            },
            'bdayChart': {
                labels: <?php echo $bdayDistLabels; ?>,
                data: <?php echo $bdayDistCounts; ?>,
                type: 'bar',
                bg: '#0dcaf0'
            }
        };

        let fsChartInstance = null;

        function openFullScreen(key, title) {
            const info = chartData[key];
            if (!info) return;

            document.getElementById('fsModalTitle').innerText = title;

            // 1. Render Table
            const tbody = document.querySelector('#fsDataTable tbody');
            tbody.innerHTML = '';
            let total = 0;

            if (info.type === 'stacked_bar') {
                info.labels.forEach((lbl, i) => {
                    let rowTotal = 0;
                    info.datasets.forEach(ds => {
                        rowTotal += Number(ds.data[i] || 0);
                    });
                    total += rowTotal;
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${lbl}</td><td class="text-end fw-bold">${rowTotal.toLocaleString()}</td>`;
                    tbody.appendChild(tr);
                });
            } else {
                info.labels.forEach((lbl, i) => {
                    const val = Number(info.data[i]);
                    total += val;
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${lbl}</td><td class="text-end fw-bold">${val.toLocaleString()}</td>`;
                    tbody.appendChild(tr);
                });
            }

            // Total Row
            const trTotal = document.createElement('tr');
            trTotal.className = 'table-secondary fw-bold';
            trTotal.innerHTML = `<td>TOTAL</td><td class="text-end">${total.toLocaleString()}</td>`;
            tbody.appendChild(trTotal);

            // 2. Render Chart
            const ctx = document.getElementById('fsChartCanvas').getContext('2d');
            if (fsChartInstance) fsChartInstance.destroy();

            const isLine = info.type === 'line';

            let datasets = [{
                label: info.data === chartData.trendChart.data ? 'Net Growth' : 'Count',
                data: info.data,
                backgroundColor: info.bg,
                borderColor: isLine ? info.border : '#fff',
                fill: isLine,
                tension: 0.3,
                borderRadius: info.type === 'bar' ? 4 : 0
            }];

            const chartType = (info.type === 'stacked_bar') ? 'bar' : info.type;
            fsChartInstance = new Chart(ctx, {
                type: chartType,
                data: {
                    labels: info.labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            display: info.type !== 'bar' || info.type === 'stacked_bar'
                        },
                        title: {
                            display: true,
                            text: title,
                            font: {
                                size: 16
                            }
                        }
                    },
                    scales: (info.type === 'bar' || info.type === 'line' || info.type === 'stacked_bar') ? {
                        x: info.type === 'stacked_bar' ? {
                            stacked: true
                        } : {},
                        y: {
                            beginAtZero: true,
                            stacked: info.type === 'stacked_bar'
                        }
                    } : {}
                }
            });

            new bootstrap.Modal(document.getElementById('fullScreenModal')).show();
        }

        function downloadChartImage() {
            const canvas = document.getElementById('fsChartCanvas');
            if (canvas) {
                const chartInst = Chart.getChart(canvas);
                if (chartInst) chartInst.update('none'); // Force immediate render before capture

                // Force white background for clean documentation exports
                const destinationCanvas = document.createElement("canvas");
                destinationCanvas.width = canvas.width;
                destinationCanvas.height = canvas.height;
                const destCtx = destinationCanvas.getContext('2d');
                destCtx.fillStyle = '#FFFFFF';
                destCtx.fillRect(0, 0, canvas.width, canvas.height);
                destCtx.drawImage(canvas, 0, 0);

                const link = document.createElement('a');
                link.download = 'Chart_Export.png';
                link.href = destinationCanvas.toDataURL('image/png');
                link.click();
            }
        }

        function downloadSpecificChart(canvasId, filename) {
            const canvas = document.getElementById(canvasId);
            if (canvas) {
                const chartInst = Chart.getChart(canvas);
                if (chartInst) chartInst.update('none'); // Force immediate render before capture

                // Force white background for clean documentation exports
                const destinationCanvas = document.createElement("canvas");
                destinationCanvas.width = canvas.width;
                destinationCanvas.height = canvas.height;
                const destCtx = destinationCanvas.getContext('2d');
                destCtx.fillStyle = '#FFFFFF';
                destCtx.fillRect(0, 0, canvas.width, canvas.height);
                destCtx.drawImage(canvas, 0, 0);

                const link = document.createElement('a');
                link.download = filename + '.png';
                link.href = destinationCanvas.toDataURL('image/png');
                link.click();
            }
        }

        // Status (Probationary vs Regular)
        charts.statusChart = new Chart(document.getElementById('statusChart'), {
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
                plugins: {
                    legend: {
                        display: false
                    }
                } // Hide legend to save space
            }
        });

        // Agency
        charts.agencyChart = new Chart(document.getElementById('agencyChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo $agencyLabels; ?>,
                datasets: [{
                    data: <?php echo $agencyCounts; ?>,
                    backgroundColor: colors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Departments
        charts.deptChart = new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $deptLabels; ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo $deptCounts; ?>,
                    backgroundColor: '#198754',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Turnover (Inactive breakdown)
        charts.turnoverChart = new Chart(document.getElementById('turnoverChart'), {
            type: 'pie',
            data: {
                labels: <?php echo $turnLabels; ?>,
                datasets: [{
                    data: <?php echo $turnCounts; ?>,
                    backgroundColor: ['#ffc107', '#dc3545', '#212529', '#6c757d']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });

        // Attrition Trend (Monthly)
        charts.attritionTrendChart = new Chart(document.getElementById('attritionTrendChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $trendLabels; ?>,
                datasets: [{
                    label: 'Exits',
                    data: <?php echo $attrTrendCounts; ?>,
                    backgroundColor: '#dc3545',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Turnover by Dept
        charts.deptTurnoverChart = new Chart(document.getElementById('deptTurnoverChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $deptTurnLabels; ?>,
                datasets: [{
                    label: 'Exits',
                    data: <?php echo $deptTurnCounts; ?>,
                    backgroundColor: '#fd7e14',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Net Workforce Growth
        charts.trendChart = new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?php echo $trendLabels; ?>,
                datasets: [{
                        label: 'New Hires',
                        data: <?php echo $trendCounts; ?>,
                        borderColor: '#0d6efd', // Blue
                        backgroundColor: 'rgba(13, 110, 253, 0.2)', // Light blue fill
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'Net Growth',
                        data: <?php echo $netGrowthCounts; ?>,
                        borderColor: '#198754', // Green
                        backgroundColor: 'rgba(25, 135, 84, 0.2)', // Light green fill
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'Exits',
                        data: <?php echo $attrTrendCounts; ?>,
                        borderColor: '#dc3545', // Red
                        backgroundColor: 'rgba(220, 53, 69, 0.2)', // Light red fill
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true // Show legend for multiple datasets
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // NEW: Compliance Chart
        charts.complianceChart = new Chart(document.getElementById('complianceChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $complianceData['by_dept_labels']; ?>,
                datasets: [{
                    label: 'Compliance %',
                    data: <?php echo $complianceData['by_dept_data']; ?>,
                    backgroundColor: '#0dcaf0',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: value => value + '%'
                        }
                    }
                }
            }
        });

        // Expiry Forecast (Stacked Bar)
        charts.expiryChart = new Chart(document.getElementById('expiryChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $expLabelsJson; ?>,
                datasets: <?php echo $expDatasetsJson; ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                },
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Recruitment ATS (Doughnut)
        charts.recruitChart = new Chart(document.getElementById('recruitChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo $recruitLabels; ?>,
                datasets: [{
                    data: <?php echo $recruitCounts; ?>,
                    backgroundColor: ['#6c757d', '#0dcaf0', '#ffc107', '#198754', '#dc3545', '#0d6efd']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Performance
        charts.perfChart = new Chart(document.getElementById('perfChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $perfLabels; ?>,
                datasets: [{
                    label: 'Employees',
                    data: <?php echo $perfCounts; ?>,
                    backgroundColor: '#0dcaf0',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        // Gender
        charts.genderChart = new Chart(document.getElementById('genderChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo $genderLabels; ?>,
                datasets: [{
                    data: <?php echo $genderData; ?>,
                    backgroundColor: ['#0d6efd', '#d63384']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        // Age
        charts.ageChart = new Chart(document.getElementById('ageChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $ageLabels; ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo $ageCounts; ?>,
                    backgroundColor: '#ffc107',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });

        // Tenure
        charts.tenureChart = new Chart(document.getElementById('tenureChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $tenureLabels; ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo $tenureCounts; ?>,
                    backgroundColor: '#6610f2',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Birthday Distribution (Annual)
        charts.bdayChart = new Chart(document.getElementById('bdayMonthChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $bdayDistLabels; ?>,
                datasets: [{
                    label: 'Birthdays',
                    data: <?php echo $bdayDistCounts; ?>,
                    backgroundColor: '#0dcaf0',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        function printMatrixOnly() {
            document.body.classList.add('print-matrix-only');
            window.print();
            document.body.classList.remove('print-matrix-only');
        }

        // [NEW] Dark Mode Adapter for Charts
        function updateChartsTheme() {
            const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            const textColor = isDark ? '#adb5bd' : '#6c757d';
            const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';

            Object.values(charts).forEach(chart => {
                // Update Scales (x/y)
                if (chart.options.scales) {
                    ['x', 'y'].forEach(axis => {
                        if (chart.options.scales[axis]) {
                            chart.options.scales[axis].ticks = chart.options.scales[axis].ticks || {};
                            chart.options.scales[axis].ticks.color = textColor;
                            chart.options.scales[axis].grid = chart.options.scales[axis].grid || {};
                            chart.options.scales[axis].grid.color = gridColor;
                        }
                    });
                }

                // Update Legend Labels (for pie/doughnut charts)
                if (chart.options.plugins && chart.options.plugins.legend) {
                    chart.options.plugins.legend.labels = chart.options.plugins.legend.labels || {};
                    chart.options.plugins.legend.labels.color = textColor;
                }
                chart.update();
            });

            // Also update the full screen chart if it's active
            if (typeof fsChartInstance !== 'undefined' && fsChartInstance) {
                if (fsChartInstance.options.scales) {
                    ['x', 'y'].forEach(axis => {
                        if (fsChartInstance.options.scales[axis]) {
                            fsChartInstance.options.scales[axis].ticks = fsChartInstance.options.scales[axis].ticks || {};
                            fsChartInstance.options.scales[axis].ticks.color = textColor;
                            fsChartInstance.options.scales[axis].grid = fsChartInstance.options.scales[axis].grid || {};
                            fsChartInstance.options.scales[axis].grid.color = gridColor;
                        }
                    });
                }
                if (fsChartInstance.options.plugins && fsChartInstance.options.plugins.legend) {
                    fsChartInstance.options.plugins.legend.labels = fsChartInstance.options.plugins.legend.labels || {};
                    fsChartInstance.options.plugins.legend.labels.color = textColor;
                }
                if (fsChartInstance.options.plugins && fsChartInstance.options.plugins.title) {
                    fsChartInstance.options.plugins.title.color = textColor;
                }
                fsChartInstance.update();
            }
        }

        new MutationObserver(updateChartsTheme).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-bs-theme']
        });
        updateChartsTheme(); // Initial check
    </script>

</body>

</html>