<?php
// --- START: UI REPAIR ---
// ======================================================
// [FILE] public/analytics.php
// [STATUS] Matrix fixed (< 1 Yr shows), Column Totals at TOP,
//          As-of year logic, Safe labels, Print layout, Debug mode,
//          Selective Print Modal & Summary Export added.
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require_once __DIR__ . '/../src/helpers.php'; // Centralized helper functions
require 'options.php';
// [FIX] Defensive initialization for variables from options.php
$agencies = $agencies ?? [];
$deptMap = $deptMap ?? [];
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
$sectionFilter = isset($_GET['section_filter']) ? trim($_GET['section_filter']) : '';
$groupFilter   = isset($_GET['group_filter']) ? trim($_GET['group_filter']) : '';
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

// [NEW] Reusable filter function to reduce code duplication
function apply_common_filters(string $sql, array $params, array $filters): array
{
    if (!empty($filters['job_search'])) {
        $sql .= " AND job_title LIKE ? ";
        $params[] = "%{$filters['job_search']}%";
    }
    if (!empty($filters['dept_filter'])) {
        $sql .= " AND dept = ? ";
        $params[] = $filters['dept_filter'];
    }
    if (!empty($filters['section_filter'])) {
        $sql .= " AND section = ? ";
        $params[] = $filters['section_filter'];
    }
    if (!empty($filters['group_filter'])) {
        $sql .= " AND `group` = ? ";
        $params[] = $filters['group_filter'];
    }
    // Note: Gender is applied separately as it only affects active queries
    if (!empty($filters['agency_filter'])) {
        if ($filters['agency_filter'] === 'TESP_DIRECT') {
            $sql .= " AND (agency_name IS NULL OR agency_name = '' OR agency_name LIKE 'TESP%') ";
        } else {
            $sql .= " AND agency_name = ? ";
            $params[] = $filters['agency_filter'];
        }
    }
    return [$sql, $params];
}

$debug         = isset($_GET['debug']) ? (bool)$_GET['debug'] : false;
$includeDeleted = isset($_GET['include_deleted']) ? (bool)$_GET['include_deleted'] : false;

// [FIX] Check for deleted_at column existence to maintain consistency with index.php
$hasEmpDeletedAt = false;
try {
    $checkCols = $pdo->query("SHOW COLUMNS FROM `employees` LIKE 'deleted_at'");
    if ($checkCols && $checkCols->rowCount() > 0) {
        $hasEmpDeletedAt = true;
    }
} catch (PDOException $e) {
}

// --- 2. BUILD SQL (base WHERE reused by several queries) ---
$activeSQL = " WHERE status = 'Active' ";
if ($hasEmpDeletedAt && !$includeDeleted) $activeSQL .= " AND deleted_at IS NULL ";

$inactiveSQL = " WHERE status IN ('Resigned', 'Terminated', 'AWOL', 'Retired')
                 AND (
                     (exit_date BETWEEN ? AND ?) 
                     OR ((exit_date IS NULL OR exit_date = '0000-00-00') AND DATE(updated_at) BETWEEN ? AND ?)
                 ) ";

// [REFACTOR] Use the new function to apply filters
$filters = [
    'job_search' => $jobSearch,
    'dept_filter' => $deptFilter,
    'section_filter' => $sectionFilter,
    'group_filter' => $groupFilter,
    'agency_filter' => $agencyFilter,
];

list($activeSQL, $params) = apply_common_filters($activeSQL, [], $filters);
list($inactiveSQL, $inactiveParams) = apply_common_filters($inactiveSQL, [$startDate, $endDate, $startDate, $endDate], $filters);

// Apply gender filter only to active SQL (as per original logic)
if ($genderFilter !== '') {
    $activeSQL .= " AND gender = ? ";
    $params[] = $genderFilter;
}


// [NEW] Handle Overdue Export
if (isset($_GET['export_overdue'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Overdue_Documents_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Expiry Date', 'Employee ID', 'Name', 'Category', 'Document Name', 'Department']);

    // Re-use active filters for the export
    $overdueSQL = "SELECT d.expiry_date, d.employee_id, e.first_name, e.last_name, d.category, d.original_name, e.dept 
                   FROM documents d 
                   JOIN employees e ON d.employee_id = e.emp_id 
                   WHERE d.expiry_date < CURDATE() 
                     AND d.deleted_at IS NULL 
                     AND d.is_resolved = 0 
                     AND e.status = 'Active'";

    $exportParams = [];
    if ($jobSearch !== '') {
        $overdueSQL .= " AND e.job_title LIKE ? ";
        $exportParams[] = "%$jobSearch%";
    }
    if ($deptFilter !== '') {
        $overdueSQL .= " AND e.dept = ? ";
        $exportParams[] = $deptFilter;
    }
    if ($sectionFilter !== '') {
        $overdueSQL .= " AND e.section = ? ";
        $exportParams[] = $sectionFilter;
    }
    if ($groupFilter !== '') {
        $overdueSQL .= " AND e.`group` = ? ";
        $exportParams[] = $groupFilter;
    }
    if ($agencyFilter !== '') {
        if ($agencyFilter === 'TESP_DIRECT') {
            $overdueSQL .= " AND (e.agency_name IS NULL OR e.agency_name = '' OR e.agency_name LIKE 'TESP%') ";
        } else {
            $overdueSQL .= " AND e.agency_name = ? ";
            $exportParams[] = $agencyFilter;
        }
    }
    $overdueSQL .= " ORDER BY d.expiry_date ASC";
    $stmt = $pdo->prepare($overdueSQL);
    $stmt->execute($exportParams);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['expiry_date'], $row['employee_id'], $row['last_name'] . ', ' . $row['first_name'], $row['category'], $row['original_name'], $row['dept']]);
    }
    fclose($output);
    exit;
}

// --- 3. AS-OF DATE for tenure bucketing ---
$today       = new DateTime('today');
$currentYear = (int)$today->format('Y');
if (!empty($dateTo)) {
    $asOf = new DateTime($dateTo);
    if ($asOf > $today) $asOf = $today;
} else {
    $asOf = ($yearFilter < $currentYear) ? new DateTime($yearFilter . '-12-31') : $today;
}
$asOfDateStr = $asOf->format('Y-m-d');

// DATA FETCHING FOR METRICS (Headcounts, etc. needed for summary export)
$totalHeadcount = 0;
$gradCount = 0;
$hasCollegeDegree = false;
$hasCollegeCourse = false;
try {
    $checkCols = $pdo->query("SHOW COLUMNS FROM `employees` LIKE 'college_degree'");
    $hasCollegeDegree = $checkCols && $checkCols->rowCount() > 0;
    $checkCols = $pdo->query("SHOW COLUMNS FROM `employees` LIKE 'college_course'");
    $hasCollegeCourse = $checkCols && $checkCols->rowCount() > 0;
} catch (PDOException $e) {
}

try {
    $headcountSQL = $hasCollegeDegree
        ? "SELECT COUNT(*) as total, SUM(CASE WHEN college_degree IS NOT NULL AND college_degree != '' THEN 1 ELSE 0 END) as graduates FROM employees $activeSQL"
        : "SELECT COUNT(*) as total FROM employees $activeSQL";
    $countStmt = $pdo->prepare($headcountSQL);
    $countStmt->execute($params);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    if ($counts) {
        $totalHeadcount = (int)$counts['total'];
        $gradCount = $hasCollegeDegree ? (int)$counts['graduates'] : 0;
    }
} catch (Exception $e) {
}
$undergradCount = max(0, $totalHeadcount - $gradCount);

// AGENCY BREAKDOWN
$agencyStmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(agency_name, ''), 'TESP Direct') AS entity, COUNT(*) AS count
    FROM employees
    $activeSQL
    GROUP BY entity
    ORDER BY count DESC
");
$agencyStmt->execute($params);
$agencyData = $agencyStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// DEPARTMENT BREAKDOWN
$deptStmt = $pdo->prepare("
    SELECT dept, COUNT(*) AS count
    FROM employees
    $activeSQL
    GROUP BY dept
    ORDER BY count DESC
");
$deptStmt->execute($params);
$deptData = $deptStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// TURNOVER STATUS BREAKDOWN
$turnStmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM employees $inactiveSQL GROUP BY status");
$turnStmt->execute($inactiveParams);
$turnoverData = $turnStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// EXIT REASONS
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

// ATTRITION TREND
$attrTrendSQL = "
    SELECT DATE_FORMAT(COALESCE(NULLIF(exit_date, '0000-00-00'), updated_at, NOW()), '%Y-%m') AS ym, COUNT(*) AS count
    FROM employees
    $inactiveSQL
    GROUP BY ym
    ORDER BY ym ASC
";
$attrTrendStmt = $pdo->prepare($attrTrendSQL);
$attrTrendStmt->execute($inactiveParams);
$attrTrendRaw = $attrTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// TURNOVER BY DEPT
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

// HIRING TREND
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

$trendLabelsArr = [];
$trendDataArr   = [];
$attrDataArr    = [];
$netGrowthArr   = [];

$start    = new DateTime($startDate);
$endObj   = new DateTime($endDate);
$interval = DateInterval::createFromDateString('1 month');
$period   = new DatePeriod($start, $interval, $endObj->modify('+1 day'));

foreach ($period as $dt) {
    $key = $dt->format('Y-m');
    $label = $dt->format('M Y');
    $trendLabelsArr[] = $label;
    $hires = isset($trendRaw[$key]) ? (int)$trendRaw[$key] : 0;
    $exits = isset($attrTrendRaw[$key]) ? (int)$attrTrendRaw[$key] : 0;

    $trendDataArr[]   = $hires;
    $attrDataArr[]    = $exits;
    $netGrowthArr[]   = $hires - $exits;
}

$trendLabels     = json_encode($trendLabelsArr);
$trendCounts     = json_encode($trendDataArr);
$attrTrendCounts = json_encode($attrDataArr);
$netGrowthCounts = json_encode($netGrowthArr);

$netGrowthColorsArr = [];
foreach ($netGrowthArr as $val) {
    $netGrowthColorsArr[] = $val >= 0 ? '#198754' : '#dc3545';
}
$netGrowthColors = json_encode($netGrowthColorsArr);

// PROBATIONARY VS REGULAR
$probThresholdDate = (clone $asOf)->modify("-$probMonths months")->format('Y-m-d');
$probSQL = "SELECT emp_id, first_name, last_name, dept, job_title, hire_date 
            FROM employees $activeSQL AND hire_date > ? ORDER BY hire_date DESC";
$probParams = array_merge($params, [$probThresholdDate]);
$probStmt = $pdo->prepare($probSQL);
$probStmt->execute($probParams);
$probList = $probStmt->fetchAll(PDO::FETCH_ASSOC);
$probCount = count($probList);
$regCount = max(0, $totalHeadcount - $probCount);

// TURNOVER RATE CALCULATION
$totalExits = array_sum($attrDataArr);
$hiresThisYear = array_sum($trendDataArr);
$startHeadcount = $totalHeadcount + $totalExits - $hiresThisYear;
$endHeadcount   = $totalHeadcount;
$avgHeadcount   = ($startHeadcount + $endHeadcount) / 2;
$turnoverRate = ($avgHeadcount > 0) ? round(($totalExits / $avgHeadcount) * 100, 2) : 0;

// AVERAGE TENURE
$avgTenureStmt = $pdo->prepare("SELECT AVG(DATEDIFF(?, hire_date)) FROM employees $activeSQL AND hire_date IS NOT NULL AND hire_date != '0000-00-00'");
$avgTenureStmt->execute(array_merge([$asOfDateStr], $params));
$avgTenureResult = $avgTenureStmt->fetchColumn();
$avgTenureDays = ($avgTenureResult !== false && $avgTenureResult !== null) ? (float)$avgTenureResult : 0;
$avgTenureYears = $avgTenureDays > 0 ? round($avgTenureDays / 365.25, 1) : 0;

// PERFORMANCE RATINGS
$perfLabels = '[]';
$perfCounts = '[]';
try {
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
    krsort($perfData);
    foreach ($perfData as $rating => $count) {
        $mappedLabel = isset($ratingMap[$rating]) ? $ratingMap[$rating] : "Rating $rating";
        $mappedPerfData[$mappedLabel] = $count;
    }
    $perfLabels = json_encode(array_keys($mappedPerfData));
    $perfCounts = json_encode(array_values($mappedPerfData));
} catch (Exception $e) {
}

$bandLabels = [
    'b0' => '< 1 Yr',
    'b1' => '1-3 Yrs',
    'b2' => '3-5 Yrs',
    'b3' => '5-10 Yrs',
    'b4' => '10+ Yrs',
];
$bandOrder = array_keys($bandLabels);

$selectCols = "emp_id, dept, birth_date, hire_date, gender";
if ($hasCollegeDegree) $selectCols .= ", college_degree";
if ($hasCollegeCourse) $selectCols .= ", college_course";

$rawStmt = $pdo->prepare("SELECT $selectCols FROM employees $activeSQL");
$rawStmt->execute($params);
$rows = $rawStmt->fetchAll(PDO::FETCH_ASSOC);

// VAULT COMPLIANCE SCORE
$complianceData = [
    'overall' => 0,
    'by_dept_labels' => '[]',
    'by_dept_data' => '[]',
];
try {
    $REQUIRED_DOCS = [];
    $reqStmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY id ASC");
    while ($row = $reqStmt->fetch(PDO::FETCH_ASSOC)) {
        $REQUIRED_DOCS[$row['name']] = array_map('trim', explode(',', $row['keywords']));
    }
    $totalRequirements = count($REQUIRED_DOCS);

    if ($totalRequirements > 0 && !empty($rows)) {
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
                if (strcasecmp(trim($d['category']), $reqKey) === 0) {
                    $docsMap[$empId][$reqKey] = true;
                    continue;
                }
                foreach ($keywords as $k) {
                    $k = trim($k);
                    if ($k === '') continue;
                    if (stripos($d['original_name'], $k) !== false) {
                        $docsMap[$empId][$reqKey] = true;
                        break;
                    }
                }
            }
        }

        $exemptSql = "SELECT ex.employee_id, ex.requirement_name FROM document_exemptions ex WHERE ex.employee_id IN ($placeholders)";
        $exemptStmt = $pdo->prepare($exemptSql);
        $exemptStmt->execute($empIdsForCompliance);
        $exemptMap = [];
        while ($row = $exemptStmt->fetch(PDO::FETCH_ASSOC)) {
            $exemptMap[$row['employee_id']][$row['requirement_name']] = true;
        }

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

// TRAINING & EXPIRY FORECAST
$formattedExpLabels = [];
$expiryDatasets = [];
try {
    $expiryLabels = [];
    for ($i = 0; $i <= 6; $i++) {
        $expiryLabels[] = date('Y-m', strtotime("+$i months"));
    }
    $expSQL = "
        SELECT 
          CASE WHEN d.expiry_date < DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN DATE_FORMAT(CURDATE(), '%Y-%m')
          ELSE DATE_FORMAT(d.expiry_date, '%Y-%m') END as ym,
          d.category, COUNT(*) as count 
        FROM documents d 
        JOIN employees e ON d.employee_id = e.emp_id 
        WHERE d.expiry_date <= LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 6 MONTH))
                    AND d.is_resolved = 0
                    AND e.status = 'Active'";

    if (!$includeDeleted) $expSQL .= " AND d.deleted_at IS NULL ";

    $expParams = [];
    if ($jobSearch !== '') {
        $expSQL .= " AND e.job_title LIKE ? ";
        $expParams[] = "%$jobSearch%";
    }
    if ($deptFilter !== '') {
        $expSQL .= " AND e.dept = ? ";
        $expParams[] = $deptFilter;
    }
    if ($sectionFilter !== '') {
        $expSQL .= " AND e.section = ? ";
        $expParams[] = $sectionFilter;
    }
    if ($groupFilter !== '') {
        $expSQL .= " AND e.`group` = ? ";
        $expParams[] = $groupFilter;
    }
    if ($agencyFilter !== '') {
        if ($agencyFilter === 'TESP_DIRECT') {
            $expSQL .= " AND (e.agency_name IS NULL OR e.agency_name = '' OR e.agency_name LIKE 'TESP%') ";
        } else {
            $expSQL .= " AND e.agency_name = ? ";
            $expParams[] = $agencyFilter;
        }
    }
    if ($genderFilter !== '') {
        $expSQL .= " AND e.gender = ? ";
        $expParams[] = $genderFilter;
    }
    $expSQL .= " GROUP BY ym, d.category ";
    $expStmt = $pdo->prepare($expSQL);
    $expStmt->execute($expParams);
    $rawExp = $expStmt->fetchAll(PDO::FETCH_ASSOC);
    $monthsMap = [];
    $categoriesFound = [];

    foreach ($expiryLabels as $ym) $monthsMap[$ym] = [];
    foreach ($rawExp as $row) {
        $ym = $row['ym'];
        $cat = $row['category'] ?: 'Uncategorized';
        $monthsMap[$ym][$cat] = (int)$row['count'];
        $categoriesFound[$cat] = true;
    }

    $formattedExpLabels = array_map(function ($ym) {
        return date('M Y', strtotime($ym . '-01'));
    }, $expiryLabels);

    $overdueCheckSQL = "SELECT COUNT(*) FROM documents d JOIN employees e ON d.employee_id = e.emp_id 
                        WHERE d.expiry_date < CURDATE() AND d.is_resolved = 0 AND e.status = 'Active'";
    if (!$includeDeleted) $overdueCheckSQL .= " AND d.deleted_at IS NULL ";
    $chkStmt = $pdo->prepare($overdueCheckSQL);
    $chkStmt->execute($params);
    $hasOverdue = $chkStmt->fetchColumn() > 0;

    $cats = array_keys($categoriesFound);
    $palette = ['#0dcaf0', '#ffc107', '#dc3545', '#198754', '#6610f2', '#fd7e14', '#20c997'];
    $cIdx = 0;
    foreach ($cats as $cat) {
        $data = [];
        $borderColors = [];
        $borderWidths = [];
        $i = 0;
        foreach ($expiryLabels as $ym) {
            $val = $monthsMap[$ym][$cat] ?? 0;
            $data[] = $val;
            if ($i === 0 && $hasOverdue && $val > 0) {
                $borderColors[] = '#ff0000';
                $borderWidths[] = 3;
            } else {
                $borderColors[] = 'rgba(0,0,0,0)';
                $borderWidths[] = 0;
            }
            $i++;
        }
        $expiryDatasets[] = ['label' => $cat, 'data' => $data, 'backgroundColor' => $palette[$cIdx % count($palette)], 'borderColor' => $borderColors, 'borderWidth' => $borderWidths, 'borderRadius' => 4];
        $cIdx++;
    }
} catch (Exception $e) {
}
$expLabelsJson = json_encode($formattedExpLabels);
$expDatasetsJson = json_encode($expiryDatasets);

// RECRUITMENT PIPELINE
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

// LOGO PREPARATION
$logo_paths = [
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $mime = pathinfo($p, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg';
        $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
}
if (empty($logo_src)) {
    $logo_src = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><text y="30" font-size="14" fill="#333">TES</text></svg>');
}

$ageBands          = ['18-25' => 0, '26-35' => 0, '36-45' => 0, '46-55' => 0, '56+' => 0];
$genderCounts      = ['Male' => 0, 'Female' => 0];
$tenureBandsCounts = array_fill_keys($bandOrder, 0);
$tenureMatrix      = [];
$eduProgress       = [];
$complianceChartData = [];
$columnTotals      = array_fill_keys($bandOrder, 0);
$courseAgg         = [];

$evalDateObj = $asOf;

function get_tenure_band_slug(string $hireDate, DateTime $asOf): ?string
{
    if (empty($hireDate) || $hireDate === '0000-00-00') return null;
    try {
        $start = new DateTime($hireDate);
        if ($start > $asOf) return null;
        $diff   = $start->diff($asOf);
        $months = ($diff->y * 12) + $diff->m + ($diff->d >= 15 ? 1 : 0);

        if ($months < 12)   return 'b0';
        if ($months < 36)   return 'b1';
        if ($months < 60)   return 'b2';
        if ($months < 120)  return 'b3';
        return 'b4';
    } catch (Exception $e) {
        return null;
    }
}

foreach ($rows as $r) {
    $empId = $r['emp_id'];
    $dept = strtoupper(trim((string)$r['dept'])) ?: 'UNASSIGNED';

    $g = ucfirst(strtolower(trim((string)$r['gender'])));
    if (isset($genderCounts[$g])) $genderCounts[$g]++;

    if (!empty($r['birth_date']) && $r['birth_date'] !== '0000-00-00') {
        $bDateObj = date_create($r['birth_date']);
        if ($bDateObj) {
            $age = date_diff($bDateObj, $evalDateObj)->y;
            if ($age <= 25) $ageBands['18-25']++;
            elseif ($age <= 35) $ageBands['26-35']++;
            elseif ($age <= 45) $ageBands['36-45']++;
            elseif ($age <= 55) $ageBands['46-55']++;
            else $ageBands['56+']++;
        }
    }

    if (!isset($eduProgress[$dept])) $eduProgress[$dept] = ['total' => 0, 'graduates' => 0];
    $eduProgress[$dept]['total']++;
    if (!empty($r['college_degree'])) $eduProgress[$dept]['graduates']++;

    $cName = strtoupper(trim((string)$r['college_course']));
    if ($cName !== '') $courseAgg[$cName] = ($courseAgg[$cName] ?? 0) + 1;

    $slug = get_tenure_band_slug((string)$r['hire_date'], $asOf);
    if ($slug !== null) {
        $tenureBandsCounts[$slug]++;
        if (!isset($tenureMatrix[$dept])) $tenureMatrix[$dept] = array_fill_keys($bandOrder, 0);
        $tenureMatrix[$dept][$slug]++;
        $columnTotals[$slug]++;
    }
}

ksort($tenureMatrix, SORT_STRING);
ksort($eduProgress, SORT_STRING);

$eduProgLabelsArr = [];
$eduProgPercentsArr = [];
foreach ($eduProgress as $d => $counts) {
    $eduProgLabelsArr[] = $d;
    $eduProgPercentsArr[] = ($counts['total'] > 0) ? round(($counts['graduates'] / $counts['total']) * 100, 1) : 0;
}
$eduProgLabels   = json_encode($eduProgLabelsArr);
$eduProgPercents = json_encode($eduProgPercentsArr);

arsort($courseAgg);
$courseLabelsArr = array_keys($courseAgg);
$courseCountsArr = array_values($courseAgg);

$courseLabels = json_encode($courseLabelsArr);
$courseCounts = json_encode($courseCountsArr);

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

$tenureChartLabelsArr = [];
$tenureChartCountsArr = [];
foreach ($bandOrder as $b) {
    $tenureChartLabelsArr[] = $bandLabels[$b];
    $tenureChartCountsArr[] = (int)$tenureBandsCounts[$b];
}
$tenureLabels = json_encode($tenureChartLabelsArr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$tenureCounts = json_encode($tenureChartCountsArr);

$grandTotal = 0;
foreach ($bandOrder as $b) {
    $grandTotal += (int)$columnTotals[$b];
}

// BIRTHDAYS QUERY
$bdayQuery = "SELECT emp_id, first_name, last_name, dept, job_title, birth_date
              FROM employees
              $activeSQL AND birth_date IS NOT NULL AND birth_date != '0000-00-00' AND MONTH(birth_date) = ?
              ORDER BY DAY(birth_date) ASC, last_name ASC";
$bdayStmt = $pdo->prepare($bdayQuery);
$bdayParams = array_merge($params, [$bdayMonth]);
$bdayStmt->execute($bdayParams);
$birthdayCelebrants = $bdayStmt->fetchAll(PDO::FETCH_ASSOC);
$monthName = date('F', mktime(0, 0, 0, $bdayMonth, 10));

// WORK ANNIVERSARIES QUERY
$annivQuery = "SELECT emp_id, first_name, last_name, dept, job_title, hire_date, 
               TIMESTAMPDIFF(YEAR, hire_date, ?) AS years_of_service
               FROM employees
               $activeSQL AND hire_date IS NOT NULL AND hire_date != '0000-00-00' AND MONTH(hire_date) = ?
               ORDER BY DAY(hire_date) ASC, last_name ASC";
$annivStmt = $pdo->prepare($annivQuery);
$annivStmt->execute(array_merge([$asOfDateStr], $params, [$bdayMonth]));
$workAnniversaries = $annivStmt->fetchAll(PDO::FETCH_ASSOC);

// BIRTHDAY DISTRIBUTION
$bdayDistData = array_fill(1, 12, 0);
$bdayDistStmt = $pdo->prepare("SELECT MONTH(birth_date) as m, COUNT(*) as count FROM employees $activeSQL AND birth_date IS NOT NULL AND birth_date != '0000-00-00' GROUP BY MONTH(birth_date)");
$bdayDistStmt->execute($params);
while ($row = $bdayDistStmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['m']) $bdayDistData[(int)$row['m']] = (int)$row['count'];
}
$bdayDistLabels = json_encode(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
$bdayDistCounts = json_encode(array_values($bdayDistData));

// --- EXPORT HANDLERS ---

// [NEW] Handle Comprehensive Summary Export to Excel
if (isset($_GET['export_summary'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Workforce_Analytics_Summary_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel encoding

    fputcsv($output, ['TES Philippines, Inc. - Workforce Analytics Summary']);
    fputcsv($output, ['Generated As Of:', $asOf->format('F j, Y')]);
    fputcsv($output, ['Period:', $startDate . ' to ' . $endDate]);
    fputcsv($output, []);

    // Key Metrics Table
    fputcsv($output, ['EXECUTIVE METRICS', '']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Active Headcount', $totalHeadcount]);
    fputcsv($output, ['Probationary Count', $probCount]);
    fputcsv($output, ['Regular Count', $regCount]);
    fputcsv($output, ['Turnover Rate (%)', $turnoverRate . '%']);
    fputcsv($output, ['Average Tenure (Years)', $avgTenureYears]);
    fputcsv($output, ['Overall Vault Compliance (%)', $complianceData['overall'] . '%']);
    fputcsv($output, []);

    // Department Breakdown
    fputcsv($output, ['DEPARTMENT HEADCOUNT BREAKDOWN', '']);
    fputcsv($output, ['Department', 'Count']);
    foreach ($deptData as $deptName => $cnt) {
        fputcsv($output, [$deptName, $cnt]);
    }
    fputcsv($output, []);

    // Agency Breakdown
    fputcsv($output, ['AGENCY BREAKDOWN', '']);
    fputcsv($output, ['Agency', 'Count']);
    foreach ($agencyData as $agencyName => $cnt) {
        fputcsv($output, [$agencyName, $cnt]);
    }

    fclose($output);
    exit;
}

// Handle Anniversary Export
if (isset($_GET['export_anniversaries'])) {
    $m = (int)$_GET['export_anniversaries'];
    $monthNameExport = date('F', mktime(0, 0, 0, $m, 10));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Work_Anniversaries_' . $monthNameExport . '_' . date('Y') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Hire Date', 'Years of Service', 'Employee ID', 'Last Name', 'First Name', 'Department', 'Job Title']);

    $annivQueryExp = "SELECT emp_id, last_name, first_name, dept, job_title, hire_date, TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) AS years_of_service FROM employees $activeSQL AND hire_date IS NOT NULL AND hire_date != '0000-00-00' AND MONTH(hire_date) = ? ORDER BY DAY(hire_date) ASC, last_name ASC";
    $expParams = array_merge($params, [$m]);
    $stmt = $pdo->prepare($annivQueryExp);
    $stmt->execute($expParams);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            date('M d, Y', strtotime($row['hire_date'])),
            $row['years_of_service'],
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
    fwrite($output, "\xEF\xBB\xBF");

    $headers = ['Dept'];
    foreach ($bandOrder as $b) $headers[] = $bandLabels[$b];
    $headers[] = 'Total';
    $headers[] = '% Share';
    fputcsv($output, $headers);

    $totalsRow = ['TOTAL'];
    foreach ($bandOrder as $b) $totalsRow[] = $columnTotals[$b];
    $totalsRow[] = $grandTotal;
    $totalsRow[] = '100%';
    fputcsv($output, $totalsRow);

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

// Optional debug block
if ($debug) {
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

// Include the global system header
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">

<style>
    .print-logo {
        max-height: 60px;
        width: auto;
    }
</style>

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
                            if (stripos($a, 'TESP') !== false) continue;
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
                    <select name="section_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Sections</option>
                        <?php
                        $allSections = $pdo->query("SELECT DISTINCT section FROM employees WHERE section IS NOT NULL AND section != '' ORDER BY section ASC")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($allSections as $s) {
                            $sel = ($s === $sectionFilter) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($s) . "\" $sel>" . htmlspecialchars($s) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="group_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Groups</option>
                        <?php
                        $allGroups = $pdo->query("SELECT DISTINCT `group` FROM employees WHERE `group` IS NOT NULL AND `group` != '' ORDER BY `group` ASC")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($allGroups as $g) {
                            $sel = ($g === $groupFilter) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($g) . "\" $sel>" . htmlspecialchars($g) . "</option>";
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
                    <button type="button" class="btn btn-sm btn-success" onclick="window.location.href='?<?php echo http_build_query(array_merge($_GET, ['export_summary' => 1])); ?>'"><i class="bi bi-file-earmark-spreadsheet"></i> Export Summary</button>
                    <button type="button" class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#printOptionsModal"><i class="bi bi-printer"></i> Print Report</button>
                    <a href="analytics.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- // --- START: PRINT HEADER --- -->
    <div class="print-header d-none d-print-block">
        <img src="<?php echo $logo_src; ?>" alt="TESP Logo" class="print-logo">
        <div style="font-size: 16pt; font-weight: bold; text-transform: uppercase;">TES Philippines, Inc.</div>
        <div style="font-size: 14pt; font-weight: bold; text-transform: uppercase;">Workforce Analytics Report</div>
        <p class="text-muted small">
            Tenure as of: <?php echo htmlspecialchars($asOf->format('F j, Y')); ?> | Period: <?php echo htmlspecialchars($startDate . ' to ' . $endDate); ?> | Generated: <?php echo date('M d, Y'); ?>
        </p>
    </div>
    <!-- // --- END: PRINT HEADER --- -->

    <!-- SECTION: KPIs -->
    <div class="row mb-4 print-section-kpis">
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
                        <button class="btn btn-sm btn-link text-dark p-0 me-1" onclick="downloadSpecificChart('statusChart', 'Employment_Status')" title="Download Image"><i class="bi bi-download"></i></button>
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

    <!-- SECTION: COMPLIANCE & EXPIRY -->
    <div class="row mb-4 print-section-compliance">
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
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('complianceChart', 'Vault_Compliance_By_Dept')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('complianceChart', 'Compliance by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="complianceChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4 print-section-compliance">
        <div class="col-md-8 mb-3">
            <div class="card shadow-sm h-100 border-warning">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center bg-warning text-dark">
                    <span><i class="bi bi-calendar-x-fill me-1"></i> Expiry Forecast (6 Months)</span>
                    <div>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['export_overdue' => 1])); ?>" class="btn btn-sm btn-danger fw-bold no-print me-2" title="Download Overdue CSV">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Overdue List
                        </a>
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

    <!-- SECTION: DEMOGRAPHICS & CHARTS -->
    <div class="row mb-4 print-section-demographics">
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-mortarboard-fill"></i> Education Progress (Graduate % per Department)</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('eduProgChart', 'Education Progress by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body position-relative" style="min-height: 300px;"><canvas id="eduProgChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4 print-section-demographics">
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-mortarboard-fill"></i> College Course Distribution</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('courseDistChart', 'College Course Distribution')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body position-relative" style="min-height: 400px;"><canvas id="courseDistChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4 print-section-demographics">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Agency Breakdown</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('agencyChart', 'Agency_Breakdown')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('agencyChart', 'Agency Breakdown')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="agencyChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Headcount by Dept</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('deptChart', 'Headcount_By_Dept')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('deptChart', 'Headcount by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="deptChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4 print-section-demographics">
        <div class="col-md-8 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 text-info d-flex justify-content-between align-items-center">
                    <span>Net Workforce Growth</span>
                    <div>
                        <button class="btn btn-sm btn-link text-info p-0 me-1" onclick="downloadSpecificChart('trendChart', 'Workforce_Growth_Trend')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-info p-0" onclick="openFullScreen('trendChart', 'Net Workforce Growth')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="trendChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Gender Split</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('genderChart', 'Gender_Distribution')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('genderChart', 'Gender Distribution')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="genderChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4 print-section-demographics">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Performance Ratings</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('perfChart', 'Performance_Ratings')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('perfChart', 'Performance Ratings')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="perfChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Age Demographics</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('ageChart', 'Age_Demographics')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('ageChart', 'Age Demographics')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="ageChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-2 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Tenure Overview</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('tenureChart', 'Tenure_Overview')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('tenureChart', 'Tenure Overview')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;"><canvas id="tenureChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row mb-4 print-section-demographics">
        <div class="col-md-3 mb-3">
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
        <div class="col-md-3 mb-3">
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
        <div class="col-md-3 mb-3">
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
        <div class="col-md-3 mb-3">
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

    <!-- SECTION: BIRTHDAYS & ANNIVERSARIES -->
    <div class="row mb-5 print-section-birthdays">
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
                    <div>
                        <button class="btn btn-sm btn-link text-dark p-0 me-1" onclick="downloadSpecificChart('bdayMonthChart', 'Birthday_Distribution_Annual')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-dark p-0" onclick="openFullScreen('bdayChart', 'Birthdays per Month')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="min-height: 250px;">
                    <canvas id="bdayMonthChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION: TENURE MATRIX -->
    <div class="row mb-5 print-section-matrix">
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

    <!-- MODALS -->
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

    <!-- Print Options Modal -->
    <div class="modal fade" id="printOptionsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-printer-fill me-2"></i> Select Sections to Print</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="printSelectionForm">
                        <div class="form-check mb-2">
                            <input class="form-check-input print-section-checkbox" type="checkbox" value="kpis" id="chkKpis" checked>
                            <label class="form-check-label fw-bold" for="chkKpis">Executive KPIs & Summary Cards</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input print-section-checkbox" type="checkbox" value="compliance" id="chkCompliance" checked>
                            <label class="form-check-label fw-bold" for="chkCompliance">Vault Compliance & Expiry Forecast</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input print-section-checkbox" type="checkbox" value="demographics" id="chkDemographics" checked>
                            <label class="form-check-label fw-bold" for="chkDemographics">Demographics & Charts (Gender, Age, Dept, etc.)</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input print-section-checkbox" type="checkbox" value="birthdays" id="chkBirthdays" checked>
                            <label class="form-check-label fw-bold" for="chkBirthdays">Birthdays & Work Anniversaries</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input print-section-checkbox" type="checkbox" value="matrix" id="chkMatrix" checked>
                            <label class="form-check-label fw-bold" for="chkMatrix">Tenure Matrix Table</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="executeSelectivePrint()"><i class="bi bi-printer me-1"></i> Generate Print Report</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/dark_mode.js"></script>

<script>
    const colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6610f2', '#fd7e14'];
    const charts = {};

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
                    let totalSum = dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                    if (dataVal === undefined || dataVal === null || Number(dataVal) === 0) return;

                    let text = dataVal.toString();
                    if (dataset.label === 'Compliance %' || chart.canvas.id === 'complianceChart' || chart.canvas.id === 'eduProgChart') text += '%';
                    else if (chart.canvas.id === 'courseDistChart' && totalSum > 0) text += ` (${Math.round((dataVal/totalSum)*100)}%)`;

                    if (chart.config.type === 'pie' || chart.config.type === 'doughnut') {
                        let total = dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                        let percent = Math.round((dataVal / total) * 100);
                        if (percent < 5) return;
                        text = `${dataVal} (${percent}%)`;
                    }

                    if (typeof element.tooltipPosition !== 'function') return;
                    let pos = element.tooltipPosition();
                    let x = pos.x;
                    let y = pos.y;

                    if ((chart.config.type === 'bar' || meta.type === 'bar') && element.base !== undefined) {
                        if (chart.options.indexAxis === 'y') {
                            x = (element.base + pos.x) / 2;
                        } else {
                            y = (element.base + pos.y) / 2;
                        }
                    }

                    ctx.strokeStyle = 'rgba(0, 0, 0, 0.75)';
                    ctx.lineWidth = 3;
                    ctx.strokeText(text, x, y);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillText(text, x, y);
                });
            });
            ctx.restore();
        }
    };
    Chart.register(offlineDataLabels);

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
            type: 'line',
            datasets: [{
                    label: 'New Hires',
                    data: <?php echo $trendCounts; ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.2)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 5
                },
                {
                    label: 'Net Growth',
                    data: <?php echo $netGrowthCounts; ?>,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.2)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 5
                },
                {
                    label: 'Exits',
                    data: <?php echo $attrTrendCounts; ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }
            ]
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
        },
        'eduProgChart': {
            labels: <?php echo $eduProgLabels; ?>,
            data: <?php echo $eduProgPercents; ?>,
            type: 'bar',
            bg: '#0d6efd'
        },
        'courseDistChart': {
            labels: <?php echo $courseLabels; ?>,
            data: <?php echo $courseCounts; ?>,
            type: 'bar',
            bg: '#0d6efd'
        }
    };

    let fsChartInstance = null;

    function openFullScreen(key, title) {
        const info = chartData[key];
        if (!info) return;

        document.getElementById('fsModalTitle').innerText = title;

        const theadTr = document.querySelector('#fsDataTable thead tr');
        theadTr.innerHTML = '';

        const categoryTh = document.createElement('th');
        categoryTh.textContent = 'Category';
        theadTr.appendChild(categoryTh);

        if (info.datasets && !info.data) {
            info.datasets.forEach(ds => {
                const dsTh = document.createElement('th');
                dsTh.classList.add('text-end');
                dsTh.textContent = ds.label || 'Value';
                theadTr.appendChild(dsTh);
            });
            if (info.type === 'stacked_bar') {
                const totalTh = document.createElement('th');
                totalTh.classList.add('text-end', 'bg-light');
                totalTh.textContent = 'Total';
                theadTr.appendChild(totalTh);
            }
        } else {
            const countTh = document.createElement('th');
            countTh.classList.add('text-end');
            countTh.textContent = 'Count';
            theadTr.appendChild(countTh);
        }

        const tbody = document.querySelector('#fsDataTable tbody');
        tbody.innerHTML = '';
        let total = 0;

        if (info.datasets && !info.data) {
            let colTotals = new Array(info.datasets.length).fill(0);

            info.labels.forEach((lbl, i) => {
                const tr = document.createElement('tr');
                const lblTd = document.createElement('td');
                lblTd.textContent = lbl;
                tr.appendChild(lblTd);

                let rowTotal = 0;
                info.datasets.forEach((ds, dsIndex) => {
                    const val = Number(ds.data[i] || 0);
                    colTotals[dsIndex] += val;
                    rowTotal += val;

                    const td = document.createElement('td');
                    td.textContent = val.toLocaleString();
                    td.classList.add('text-end');
                    if (info.type === 'stacked_bar' && val === 0) {
                        td.classList.add('text-muted', 'opacity-50');
                    } else if (val < 0) {
                        td.classList.add('text-danger', 'fw-bold');
                    } else {
                        td.classList.add('fw-bold');
                    }
                    tr.appendChild(td);
                });

                if (info.type === 'stacked_bar') {
                    const totalTd = document.createElement('td');
                    totalTd.textContent = rowTotal.toLocaleString();
                    totalTd.classList.add('text-end', 'fw-bold', 'bg-light');
                    tr.appendChild(totalTd);
                    total += rowTotal;
                }

                tbody.appendChild(tr);
            });

            const trTotal = document.createElement('tr');
            trTotal.className = 'table-secondary fw-bold';
            const totalLabelTd = document.createElement('td');
            totalLabelTd.textContent = 'TOTAL';
            trTotal.appendChild(totalLabelTd);

            colTotals.forEach((colTotal) => {
                const td = document.createElement('td');
                td.textContent = colTotal.toLocaleString();
                td.classList.add('text-end');
                if (colTotal < 0) td.classList.add('text-danger');
                trTotal.appendChild(td);
            });

            if (info.type === 'stacked_bar') {
                const td = document.createElement('td');
                td.textContent = total.toLocaleString();
                td.classList.add('text-end');
                trTotal.appendChild(td);
            }
            tbody.appendChild(trTotal);

        } else {
            info.labels.forEach((lbl, i) => {
                const val = Number(info.data[i]);
                total += val;
                const tr = document.createElement('tr');

                const lblTd = document.createElement('td');
                lblTd.textContent = lbl;
                tr.appendChild(lblTd);

                const valTd = document.createElement('td');
                valTd.textContent = val.toLocaleString();
                valTd.classList.add('text-end', 'fw-bold');
                tr.appendChild(valTd);

                tbody.appendChild(tr);
            });

            const trTotal = document.createElement('tr');
            trTotal.className = 'table-secondary fw-bold';
            const totalLabelTd = document.createElement('td');
            totalLabelTd.textContent = 'TOTAL';
            trTotal.appendChild(totalLabelTd);

            const totalValTd = document.createElement('td');
            totalValTd.textContent = total.toLocaleString();
            totalValTd.classList.add('text-end');
            trTotal.appendChild(totalValTd);

            tbody.appendChild(trTotal);
        }

        const ctx = document.getElementById('fsChartCanvas').getContext('2d');
        if (fsChartInstance) fsChartInstance.destroy();

        const isLine = info.type === 'line';
        let datasets = [];
        if (info.type === 'stacked_bar' && Array.isArray(info.datasets)) {
            datasets = info.datasets.map((ds) => ({
                label: ds.label || 'Count',
                data: ds.data || [],
                backgroundColor: ds.backgroundColor || '#198754',
                borderColor: ds.borderColor || ds.backgroundColor || '#198754',
                borderWidth: ds.borderWidth || 0,
                borderRadius: ds.borderRadius || 0,
                stack: ds.stack || 'stack1'
            }));
        } else if (isLine && Array.isArray(info.datasets)) {
            datasets = info.datasets.map((ds) => ({
                label: ds.label || 'Count',
                data: ds.data || [],
                backgroundColor: ds.backgroundColor || 'rgba(13, 110, 253, 0.2)',
                borderColor: ds.borderColor || '#0d6efd',
                fill: ds.fill !== undefined ? ds.fill : true,
                tension: ds.tension !== undefined ? ds.tension : 0.3,
                pointRadius: ds.pointRadius !== undefined ? ds.pointRadius : 3,
                pointHoverRadius: ds.pointHoverRadius !== undefined ? ds.pointHoverRadius : 5
            }));
        } else if (info.data) {
            datasets = [{
                label: 'Count',
                data: info.data,
                backgroundColor: info.bg || '#198754',
                borderColor: isLine ? info.border || '#0dcaf0' : '#fff',
                fill: isLine,
                tension: 0.3,
                borderRadius: info.type === 'bar' ? 4 : 0
            }];
        }

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
        const title = document.getElementById('fsModalTitle')?.innerText || 'Chart';
        const safeFilename = title.replace(/[^a-z0-9]/gi, '_');

        if (canvas) {
            try {
                const destinationCanvas = document.createElement("canvas");
                destinationCanvas.width = canvas.width;
                destinationCanvas.height = canvas.height;
                const destCtx = destinationCanvas.getContext('2d');
                destCtx.fillStyle = '#FFFFFF';
                destCtx.fillRect(0, 0, canvas.width, canvas.height);
                destCtx.drawImage(canvas, 0, 0);

                const link = document.createElement('a');
                link.style.display = 'none';
                link.download = safeFilename + '_' + new Date().toISOString().split('T')[0] + '.png';
                link.href = destinationCanvas.toDataURL('image/png');

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                if (window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Chart downloaded!',
                        showConfirmButton: false,
                        timer: 2000
                    });
                }
            } catch (e) {
                console.error("Download failed", e);
            }
        }
    }

    window.downloadSpecificChart = function(canvasId, filename) {
        const canvas = document.getElementById(canvasId);
        if (canvas) {
            try {
                const destinationCanvas = document.createElement("canvas");
                destinationCanvas.width = canvas.width;
                destinationCanvas.height = canvas.height;
                const destCtx = destinationCanvas.getContext('2d');
                destCtx.fillStyle = '#FFFFFF';
                destCtx.fillRect(0, 0, canvas.width, canvas.height);
                destCtx.drawImage(canvas, 0, 0);

                const link = document.createElement('a');
                link.style.display = 'none';
                link.download = filename + '_' + new Date().toISOString().split('T')[0] + '.png';
                link.href = destinationCanvas.toDataURL('image/png');

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                if (window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'Chart downloaded!',
                        showConfirmButton: false,
                        timer: 2000
                    });
                }
            } catch (e) {
                console.error("Download failed", e);
            }
        }
    }

    // Chart initializations
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
            }
        }
    });

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

    charts.trendChart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?php echo $trendLabels; ?>,
            datasets: [{
                    label: 'New Hires',
                    data: <?php echo $trendCounts; ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.2)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 5
                },
                {
                    label: 'Net Growth',
                    data: <?php echo $netGrowthCounts; ?>,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.2)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 5
                },
                {
                    label: 'Exits',
                    data: <?php echo $attrTrendCounts; ?>,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.2)',
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
                    display: true
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

    charts.eduProgChart = new Chart(document.getElementById('eduProgChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $eduProgLabels; ?>,
            datasets: [{
                label: 'Graduate Percentage',
                data: <?php echo $eduProgPercents; ?>,
                backgroundColor: '#0d6efd',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: value => value + '%'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });

    charts.courseDistChart = new Chart(document.getElementById('courseDistChart'), {
        type: 'bar',
        data: {
            labels: <?php echo $courseLabels; ?>,
            datasets: [{
                label: 'Employees',
                data: <?php echo $courseCounts; ?>,
                backgroundColor: '#6610f2',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

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

    function executeSelectivePrint() {
        const modalEl = document.getElementById('printOptionsModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        document.querySelectorAll('.print-section-checkbox').forEach(chk => {
            const sectionClass = '.print-section-' + chk.value;
            document.querySelectorAll(sectionClass).forEach(el => {
                if (chk.checked) {
                    el.classList.remove('force-hide-print');
                } else {
                    el.classList.add('force-hide-print');
                }
            });
        });

        setTimeout(() => {
            window.print();
        }, 300);
    }

    function updateChartsTheme() {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const textColor = isDark ? '#adb5bd' : '#6c757d';
        const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';

        Object.values(charts).forEach(chart => {
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

            if (chart.options.plugins && chart.options.plugins.legend) {
                chart.options.plugins.legend.labels = chart.options.plugins.legend.labels || {};
                chart.options.plugins.legend.labels.color = textColor;
            }
            chart.update();
        });

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
    updateChartsTheme();
</script>

<!-- Professional Print CSS Styling -->
<style>
    @media print {
        @page {
            size: landscape;
            margin: 10mm;
        }

        body {
            background: white !important;
            color: #000 !important;
            font-size: 10pt;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .no-print,
        .navbar,
        .btn,
        form,
        .modal {
            display: none !important;
        }

        .force-hide-print {
            display: none !important;
        }

        .container-fluid {
            padding: 0 !important;
            max-width: 100% !important;
        }

        .print-header {
            text-align: center !important;
            border-bottom: 2px solid #000 !important;
            margin-bottom: 15px !important;
            padding-bottom: 8px !important;
            display: block !important;
        }

        .print-logo {
            max-height: 55px !important;
            width: auto !important;
            display: block !important;
            margin: 0 auto 8px auto !important;
        }

        .card {
            border: 1px solid #bbb !important;
            box-shadow: none !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            margin-bottom: 12px !important;
        }

        .card-header {
            background-color: #e9ecef !important;
            color: #000 !important;
            font-size: 9pt;
            padding: 6px 10px !important;
            border-bottom: 1px solid #bbb !important;
        }

        .card-body {
            padding: 8px !important;
        }

        canvas {
            max-height: 180px !important;
            width: 100% !important;
        }

        .matrix-table {
            font-size: 8.5pt;
            width: 100%;
            border-collapse: collapse;
        }

        .matrix-table th,
        .matrix-table td {
            border: 1px solid #777 !important;
            padding: 3px 5px !important;
        }
    }
</style>

</body>

</html>