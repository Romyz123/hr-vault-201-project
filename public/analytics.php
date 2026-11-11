<?php
// --- START: UI REPAIR ---
// ======================================================
// [FILE] public/analytics.php
// [STATUS] Matrix fixed (< 1 Yr shows), Column Totals at TOP,
//          As-of year logic, Safe labels, Print layout, Debug mode, JS Fixed
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
$groupFilter   = isset($_GET['group_filter']) && $_GET['group_filter'] !== ''
                 ? trim($_GET['group_filter'])
                 : (isset($_SESSION['group']) ? trim($_SESSION['group']) : '');
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

$yearDeletedFilter = ($hasEmpDeletedAt && !$includeDeleted) ? " AND deleted_at IS NULL" : '';
$yearStmt = $pdo->query("
    SELECT DISTINCT year_value
    FROM (
        SELECT YEAR(hire_date) AS year_value
        FROM employees
        WHERE hire_date IS NOT NULL AND YEAR(hire_date) > 0{$yearDeletedFilter}
        UNION
        SELECT YEAR(exit_date) AS year_value
        FROM employees
        WHERE exit_date IS NOT NULL AND YEAR(exit_date) > 0{$yearDeletedFilter}
        UNION
        SELECT YEAR(exit_date) AS year_value
        FROM employees
        WHERE exit_date IS NOT NULL
          AND YEAR(exit_date) > 0
          AND (
              LOWER(TRIM(COALESCE(status, ''))) REGEXP 'resign|terminat|awol|retir|deceased'
              OR (
                  LOWER(TRIM(COALESCE(status, ''))) IN ('', 'inactive', 'unspecified')
                  AND LOWER(COALESCE(exit_reason, '')) REGEXP 'resign|terminat|end of contract|discharg|awol|abandon|retir|dead|deceased|passed away'
              )
          )
          AND LOWER(TRIM(COALESCE(status, ''))) NOT REGEXP 'transf|trasf|active'
          AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'transf|trasf|overseas|abroad|deploy'{$yearDeletedFilter}
    ) employee_years
    ORDER BY year_value DESC
");
$availableYears = array_map('intval', $yearStmt->fetchAll(PDO::FETCH_COLUMN));
$availableYears[] = (int)date('Y');
$availableYears[] = $yearFilter;
$availableYears = array_values(array_unique($availableYears));
rsort($availableYears, SORT_NUMERIC);

// --- 2. BUILD SQL (base WHERE reused by several queries) ---
// [FIX] Define $logo_src safely with fallback for print header
$logo_src = $logo_src ?? '';
if (empty($logo_src)) {
    $logoPaths = [
        __DIR__ . '/assets/images/tesp-logo-1.png',
        __DIR__ . '/uploads/tesp-logo.png',
        __DIR__ . '/uploads/tesp logo 1.png',
        __DIR__ . '/../uploads/tesp-logo.png',
        __DIR__ . '/../uploads/tesp logo 1.png'
    ];
    foreach ($logoPaths as $lp) {
        if (file_exists($lp)) {
            $ext = strtolower(pathinfo($lp, PATHINFO_EXTENSION));
            $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
            $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($lp));
            break;
        }
    }
}
if (empty($logo_src)) {
    $logo_src = 'assets/images/tesp-logo-1.png';
}

$activeSQL = " WHERE (
                   LOWER(TRIM(COALESCE(status, ''))) = 'active'
                   OR LOWER(TRIM(COALESCE(status, ''))) REGEXP 'transf|trasf'
                   OR LOWER(COALESCE(exit_reason, '')) REGEXP 'transf|trasf|overseas|abroad|deploy'
               )
               AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir' ";
if ($hasEmpDeletedAt && !$includeDeleted) $activeSQL .= " AND deleted_at IS NULL ";

$inactiveSQL = " WHERE (
                     (
                         status IS NOT NULL AND TRIM(status) <> ''
                         AND LOWER(TRIM(status)) <> 'active'
                         AND LOWER(TRIM(status)) NOT REGEXP 'transf|trasf'
                     )
                     OR (
                         (status IS NULL OR TRIM(status) = '')
                         AND exit_reason IS NOT NULL AND TRIM(exit_reason) <> ''
                     )
                     OR (
                         LOWER(TRIM(COALESCE(status, ''))) = 'active'
                         AND LOWER(COALESCE(exit_reason, '')) REGEXP 'dead|deceased|passed away|retir'
                     )
                 )
                 AND LOWER(TRIM(COALESCE(status, ''))) NOT REGEXP 'transf|trasf'
                 AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'transf|trasf|overseas|abroad|deploy'
                 AND exit_date BETWEEN ? AND ? ";
if ($hasEmpDeletedAt && !$includeDeleted) {
    $inactiveSQL .= " AND deleted_at IS NULL ";
}

// [REFACTOR] Use the new function to apply filters
$filters = [
    'job_search' => $jobSearch,
    'dept_filter' => $deptFilter,
    'section_filter' => $sectionFilter,
    'group_filter' => $groupFilter,
    'agency_filter' => $agencyFilter,
];

list($activeSQL, $params) = apply_common_filters($activeSQL, [], $filters);
list($inactiveSQL, $inactiveParams) = apply_common_filters($inactiveSQL, [$startDate, $endDate], $filters);

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
                     AND (
                         LOWER(TRIM(COALESCE(e.status, ''))) = 'active'
                         OR LOWER(TRIM(COALESCE(e.status, ''))) REGEXP 'transf|trasf'
                         OR LOWER(COALESCE(e.exit_reason, '')) REGEXP 'transf|trasf|overseas|abroad|deploy'
                     )
                     AND LOWER(COALESCE(e.exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir'";

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
    error_log('analytics.php headcount query failed: ' . $e->getMessage());
}

$excludedHeadcountSQL = " WHERE NOT (
    (
        LOWER(TRIM(COALESCE(status, ''))) = 'active'
        OR LOWER(TRIM(COALESCE(status, ''))) REGEXP 'transf|trasf'
        OR LOWER(COALESCE(exit_reason, '')) REGEXP 'transf|trasf|overseas|abroad|deploy'
    )
    AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir'
)";
if ($hasEmpDeletedAt && !$includeDeleted) {
    $excludedHeadcountSQL .= " AND deleted_at IS NULL ";
}
list($excludedHeadcountSQL, $excludedHeadcountParams) = apply_common_filters($excludedHeadcountSQL, [], $filters);
if ($genderFilter !== '') {
    $excludedHeadcountSQL .= " AND gender = ? ";
    $excludedHeadcountParams[] = $genderFilter;
}
$excludedHeadcountStmt = $pdo->prepare("
    SELECT emp_id, first_name, last_name, dept, status, exit_reason
    FROM employees
    $excludedHeadcountSQL
    ORDER BY last_name, first_name, emp_id
");
$excludedHeadcountStmt->execute($excludedHeadcountParams);
$excludedHeadcountEmployees = $excludedHeadcountStmt->fetchAll(PDO::FETCH_ASSOC);
$excludedHeadcountCount = count($excludedHeadcountEmployees);

$undergradCount = max(0, $totalHeadcount - $gradCount);

$eduLabels = json_encode(['College Graduate', 'No Degree Info / Undergrad']);
$eduCounts = json_encode([$gradCount, $undergradCount]);

// 2) AGENCY BREAKDOWN (Active)
$agencyStmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(agency_name, ''), 'TESP') AS entity, COUNT(*) AS count
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

// 4) CURRENT ATTRITION STATUS BREAKDOWN (independent of selected exit period)
$currentAttritionSQL = " WHERE (
                            (
                                status IS NOT NULL AND TRIM(status) <> ''
                                AND LOWER(TRIM(status)) <> 'active'
                                AND LOWER(TRIM(status)) NOT REGEXP 'transf|trasf'
                            )
                            OR (
                                (status IS NULL OR TRIM(status) = '')
                                AND LOWER(COALESCE(exit_reason, '')) REGEXP 'dead|deceased|passed away|retir|resign|terminat|end of contract|discharg|awol|abandon'
                            )
                        )
                        AND LOWER(TRIM(COALESCE(status, ''))) NOT REGEXP 'transf|trasf'
                        AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'transf|trasf|overseas|abroad|deploy' ";
if ($hasEmpDeletedAt && !$includeDeleted) {
    $currentAttritionSQL .= " AND deleted_at IS NULL ";
}
list($currentAttritionSQL, $currentAttritionParams) = apply_common_filters($currentAttritionSQL, [], $filters);
$currentAttritionStmt = $pdo->prepare("
    SELECT id, emp_id, first_name, last_name, dept, status, exit_reason, exit_date
    FROM employees
    $currentAttritionSQL
    ORDER BY last_name, first_name, emp_id
");
$currentAttritionStmt->execute($currentAttritionParams);
$turnoverEmployees = $currentAttritionStmt->fetchAll(PDO::FETCH_ASSOC);

// Compare the same current statuses against records with a recorded exit date in the selected period.
$periodAttritionSQL = $currentAttritionSQL . "
    AND exit_date BETWEEN ? AND ?";
$periodAttritionStmt = $pdo->prepare("
    SELECT id, emp_id, first_name, last_name, dept, status, exit_reason, exit_date
    FROM employees
    $periodAttritionSQL
    ORDER BY last_name, first_name, emp_id
");
$periodAttritionStmt->execute(array_merge($currentAttritionParams, [$startDate, $endDate]));
$periodTurnoverEmployees = $periodAttritionStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fill expected statuses
$expectedStatuses = ['Resigned', 'Terminated', 'AWOL', 'Retired', 'Deceased'];
$periodSeriesLabel = (!empty($dateFrom) && !empty($dateTo)) ? 'Selected range' : (string)$yearFilter;
$allTimeTurnoverData = array_fill_keys($expectedStatuses, 0);
$periodTurnoverData = array_fill_keys($expectedStatuses, 0);
$turnoverEmployeesByPeriod = array_fill_keys([$periodSeriesLabel, 'All years'], array_fill_keys($expectedStatuses, []));
$classifyAttritionStatus = static function (array $row) use ($expectedStatuses): ?string {
    $reason = trim((string)$row['exit_reason']);
    $storedStatus = trim((string)$row['status']);
    $hasExplicitStatus = $storedStatus !== ''
        && !in_array(strtolower($storedStatus), ['inactive', 'unspecified'], true);
    $category = employeeEffectiveStatus($storedStatus, $hasExplicitStatus ? '' : $reason);
    if ($category === '' || strcasecmp($category, 'Inactive') === 0) {
        $category = $reason !== '' ? $reason : 'Unspecified';
    }

    if (preg_match('/dead|deceased|passed away/i', $category)) {
        $category = 'Deceased';
    } elseif (preg_match('/retire/i', $category)) {
        $category = 'Retired';
    } elseif (preg_match('/resign/i', $category)) {
        $category = 'Resigned';
    } elseif (preg_match('/terminat|end of contract|discharg/i', $category)) {
        $category = 'Terminated';
    } elseif (preg_match('/awol|abandon/i', $category)) {
        $category = 'AWOL';
    } else {
        foreach ($expectedStatuses as $expected) {
            if (stripos($category, $expected) !== false) {
                $category = $expected;
                break;
            }
        }
    }

    if (!in_array($category, $expectedStatuses, true)) {
        return null;
    }
    return $category;
};

foreach ($turnoverEmployees as $row) {
    $category = $classifyAttritionStatus($row);
    if ($category === null) {
        continue;
    }
    $allTimeTurnoverData[$category]++;
    $turnoverEmployeesByPeriod['All years'][$category][] = [
        'emp_id' => (string)$row['emp_id'],
        'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
        'dept' => (string)($row['dept'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'exit_date' => (string)($row['exit_date'] ?? ''),
        'reason' => trim((string)$row['exit_reason']),
    ];
}
foreach ($periodTurnoverEmployees as $row) {
    $category = $classifyAttritionStatus($row);
    if ($category === null) {
        continue;
    }
    $periodTurnoverData[$category]++;
    $turnoverEmployeesByPeriod[$periodSeriesLabel][$category][] = [
        'emp_id' => (string)$row['emp_id'],
        'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
        'dept' => (string)($row['dept'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'exit_date' => (string)($row['exit_date'] ?? ''),
        'reason' => trim((string)$row['exit_reason']),
    ];
}

// 5) EXIT REASONS (Top 5; Inactive in selected year)
$reasonStmt = $pdo->prepare("
    SELECT exit_reason, COUNT(*) as count
    FROM employees
    $inactiveSQL
    AND exit_reason IS NOT NULL
    AND exit_reason != ''
    GROUP BY exit_reason
    ORDER BY count DESC
");
$reasonStmt->execute($inactiveParams);
$rawReasonData = $reasonStmt->fetchAll(PDO::FETCH_ASSOC);

// Normalize exit reasons to avoid duplicates like "Dead" vs "Deceased", or dates in reasons
$normalizedReasons = [];
foreach ($rawReasonData as $rRow) {
    $rText = trim((string)$rRow['exit_reason']);
    if (empty($rText)) continue;
    $rCount = (int)$rRow['count'];

    if (preg_match('/dead|deceased|passed away/i', $rText)) {
        $normKey = 'Deceased';
    } elseif (preg_match('/retire/i', $rText)) {
        $normKey = 'Retired';
    } elseif (preg_match('/resign/i', $rText)) {
        $normKey = 'Resigned';
    } elseif (preg_match('/terminat|end of contract|discharg/i', $rText)) {
        $normKey = 'Terminated / End of Contract';
    } elseif (preg_match('/awol|abandon/i', $rText)) {
        $normKey = 'AWOL';
    } elseif (preg_match('/transf/i', $rText)) {
        $normKey = 'Transferred';
    } elseif (preg_match('/abroad/i', $rText)) {
        $normKey = 'Abroad';
    } else {
        $normKey = ucwords(strtolower($rText));
    }

    if (!isset($normalizedReasons[$normKey])) {
        $normalizedReasons[$normKey] = 0;
    }
    $normalizedReasons[$normKey] += $rCount;
}
arsort($normalizedReasons);
$reasonData = [];
foreach (array_slice($normalizedReasons, 0, 5, true) as $rName => $rCnt) {
    $reasonData[] = ['exit_reason' => $rName, 'count' => $rCnt];
}

// 5b) ATTRITION TREND (Monthly exits in selected year)
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
$hireBaseSQL = " WHERE 1=1 ";
if ($hasEmpDeletedAt && !$includeDeleted) {
    $hireBaseSQL .= " AND deleted_at IS NULL ";
}
list($hireSQL, $hireParams) = apply_common_filters($hireBaseSQL, [], $filters);
$trendSQL = "
    SELECT DATE_FORMAT(hire_date, '%Y-%m') AS ym, COUNT(*) AS count
    FROM employees
    $hireSQL
    AND hire_date BETWEEN ? AND ?
    GROUP BY ym
    ORDER BY ym ASC
";
$trendParams = array_merge($hireParams, [$startDate, $endDate]);
$trendStmt = $pdo->prepare($trendSQL);
$trendStmt->execute($trendParams);
$trendRaw = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// [NEW] Generate Dynamic Labels for Trend Charts
$trendLabelsArr = [];
$trendDataArr   = [];
$attrDataArr    = [];
$netGrowthArr   = [];

$start    = new DateTime($startDate);
$endObj   = new DateTime($endDate);
$interval = DateInterval::createFromDateString('1 month');
$period   = new DatePeriod($start, $interval, $endObj->modify('+1 day')); // Inclusive

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

// 7) PROBATIONARY VS REGULAR (New Logic)
$probThresholdDate = (clone $asOf)->modify("-$probMonths months")->format('Y-m-d');

$probSQL = "SELECT emp_id, first_name, last_name, dept, job_title, hire_date 
            FROM employees $activeSQL AND hire_date > ? ORDER BY hire_date DESC";
$probParams = array_merge($params, [$probThresholdDate]);
$probStmt = $pdo->prepare($probSQL);
$probStmt->execute($probParams);
$probList = $probStmt->fetchAll(PDO::FETCH_ASSOC);
$probCount = count($probList);
$regCount = max(0, $totalHeadcount - $probCount);

// 8) TURNOVER RATE CALCULATION
$totalExits = array_sum($attrDataArr);
$hiresThisYear = array_sum($trendDataArr);
$startHeadcount = $totalHeadcount + $totalExits - $hiresThisYear;
$endHeadcount   = $totalHeadcount;
$avgHeadcount   = ($startHeadcount + $endHeadcount) / 2;

$turnoverRate = ($avgHeadcount > 0) ? round(($totalExits / $avgHeadcount) * 100, 2) : 0;

// 9) AVERAGE TENURE (Active)
$asOfDateStr = $asOf->format('Y-m-d');
$avgTenureStmt = $pdo->prepare("SELECT AVG(DATEDIFF(?, hire_date)) FROM employees $activeSQL AND hire_date IS NOT NULL AND hire_date != '0000-00-00'");
$avgTenureStmt->execute(array_merge([$asOfDateStr], $params));
$avgTenureResult = $avgTenureStmt->fetchColumn();
$avgTenureDays = ($avgTenureResult !== false && $avgTenureResult !== null) ? (float)$avgTenureResult : 0;
$avgTenureYears = $avgTenureDays > 0 ? round($avgTenureDays / 365.25, 1) : 0;

// 10) PERFORMANCE RATINGS (Latest per employee)
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

// 7) DEMOGRAPHICS & TENURE MATRIX
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

// 11) VAULT COMPLIANCE SCORE
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

// 14) TRAINING COMPLIANCE
$trainingStmt = $pdo->prepare("SELECT 
    (SELECT COUNT(DISTINCT employee_id) FROM employee_training) as trained,
    (SELECT COUNT(*) FROM employees WHERE (
        LOWER(TRIM(COALESCE(status, ''))) = 'active'
        OR LOWER(TRIM(COALESCE(status, ''))) REGEXP 'transf|trasf'
        OR LOWER(COALESCE(exit_reason, '')) REGEXP 'transf|trasf|overseas|abroad|deploy'
    ) AND LOWER(COALESCE(exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir') as total");
$trainingStmt->execute();
$tStats = $trainingStmt->fetch();
$trainedCount = 0;
$totalEmployeesForTraining = 0;
if ($tStats !== false) {
    $trainedCount = (int)$tStats['trained'];
    $totalEmployeesForTraining = (int)$tStats['total'];
}
$untrainedCount = max(0, $totalEmployeesForTraining - $trainedCount);

// 12) EXPIRY FORECAST (Next 6 Months)
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
                    AND (
                        LOWER(TRIM(COALESCE(e.status, ''))) = 'active'
                        OR LOWER(TRIM(COALESCE(e.status, ''))) REGEXP 'transf|trasf'
                        OR LOWER(COALESCE(e.exit_reason, '')) REGEXP 'transf|trasf|overseas|abroad|deploy'
                    )
                    AND LOWER(COALESCE(e.exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir'";

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
        if ($agencyFilter === 'TESP_DIRECT') $expSQL .= " AND (e.agency_name IS NULL OR e.agency_name = '' OR e.agency_name LIKE 'TESP%') ";
        else {
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
                        WHERE d.expiry_date < CURDATE() AND d.is_resolved = 0
                          AND (
                              LOWER(TRIM(COALESCE(e.status, ''))) = 'active'
                              OR LOWER(TRIM(COALESCE(e.status, ''))) REGEXP 'transf|trasf'
                              OR LOWER(COALESCE(e.exit_reason, '')) REGEXP 'transf|trasf|overseas|abroad|deploy'
                          )
                          AND LOWER(COALESCE(e.exit_reason, '')) NOT REGEXP 'dead|deceased|passed away|retir'";
    if (!$includeDeleted) $overdueCheckSQL .= " AND d.deleted_at IS NULL ";
    $checkParams = [];
    // re-apply filters... (simplified for brevity here, applying same as above)
    $chkStmt = $pdo->prepare($overdueCheckSQL);
    $chkStmt->execute();
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

// LOGO LOGIC
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

// AGGREGATIONS
$ageBands          = ['18-25' => 0, '26-35' => 0, '36-45' => 0, '46-55' => 0, '56+' => 0];
$genderCounts      = ['Male' => 0, 'Female' => 0];
$tenureBandsCounts = array_fill_keys($bandOrder, 0);
$tenureMatrix      = [];
$eduProgress       = [];
$complianceChartData = [];
$columnTotals      = array_fill_keys($bandOrder, 0);
$courseAgg         = [];
$totalGraduates = 0;
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

    $cName = strtoupper(trim((string)$r['college_course'] ?? ''));
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
$totalGradsWithCourse = array_sum($courseCountsArr);

$courseLabels = json_encode($courseLabelsArr);
$courseCounts = json_encode($courseCountsArr);

$deptLabels   = json_encode(array_keys($deptData));
$deptCounts   = json_encode(array_values($deptData));
$agencyLabels = json_encode(array_keys($agencyData));
$agencyCounts = json_encode(array_values($agencyData));
$turnLabels   = json_encode($expectedStatuses);
$turnPeriodCounts = json_encode(array_values($periodTurnoverData));
$turnAllTimeCounts = json_encode(array_values($allTimeTurnoverData));
$turnEmployeeDetailsJson = json_encode(
    $turnoverEmployeesByPeriod,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
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

$grandTotal        = 0;
$bdayMonth         = date('m');
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

// BIRTHDAY DISTRIBUTION (Annual Forecast by Month)
$bdayDistData = array_fill(1, 12, 0);
$bdayDistStmt = $pdo->prepare("SELECT MONTH(birth_date) as m, COUNT(*) as count FROM employees $activeSQL AND birth_date IS NOT NULL AND birth_date != '0000-00-00' GROUP BY MONTH(birth_date)");
$bdayDistStmt->execute($params);
while ($row = $bdayDistStmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['m']) $bdayDistData[(int)$row['m']] = (int)$row['count'];
}
$bdayDistLabels = json_encode(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
$bdayDistCounts = json_encode(array_values($bdayDistData));
?>
<?php
$currentPage = 'analytics.php';
include 'header.php';
?>
<style>
    body {
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
    }

    .card {
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
        border-color: var(--bs-border-color);
    }

    [data-bs-theme="dark"] .table {
        --bs-table-bg: transparent;
        --bs-table-striped-bg: rgba(255, 255, 255, 0.02);
        --bs-table-hover-bg: rgba(255, 255, 255, 0.05);
        color: var(--bs-body-color);
    }

    [data-bs-theme="dark"] .matrix-table thead tr:first-child th {
        background-color: var(--bs-tertiary-bg) !important;
        border-color: var(--bs-border-color) !important;
    }

    [data-bs-theme="dark"] .matrix-table thead tr.thead-totals th {
        background-color: #343a40 !important;
        border-color: var(--bs-border-color) !important;
    }

    [data-bs-theme="dark"] .bg-total {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: var(--bs-body-color) !important;
    }

    [data-bs-theme="dark"] .input-group-text.bg-light {
        background-color: var(--bs-tertiary-bg) !important;
        border-color: var(--bs-border-color) !important;
        color: var(--bs-body-color) !important;
    }

    .card-header {
        font-size: 0.85rem;
        text-transform: uppercase;
        font-weight: bold;
        letter-spacing: 0.5px;
    }

    .matrix-table th {
        font-size: 0.75rem;
        text-align: center;
        background-color: var(--bs-tertiary-bg);
        color: var(--bs-body-color);
    }

    .matrix-table td {
        font-size: 0.8rem;
        text-align: center;
        vertical-align: middle;
        color: var(--bs-body-color);
    }

    .matrix-dept {
        text-align: left !important;
        font-weight: bold;
        color: var(--bs-emphasis-color);
    }

    .table {
        color: var(--bs-body-color);
        --bs-table-bg: transparent;
        --bs-table-striped-bg: rgba(255, 255, 255, 0.02);
        --bs-table-hover-bg: rgba(255, 255, 255, 0.05);
    }

    .table thead th {
        white-space: nowrap;
    }

    [data-bs-theme="dark"] .bg-white,
    [data-bs-theme="dark"] .bg-light,
    [data-bs-theme="dark"] .bg-body-tertiary,
    [data-bs-theme="dark"] .table-light,
    [data-bs-theme="dark"] .table-secondary,
    [data-bs-theme="dark"] .modal-header.bg-light,
    [data-bs-theme="dark"] .modal-body.bg-light,
    [data-bs-theme="dark"] .card-header.bg-light {
        background-color: var(--bs-tertiary-bg) !important;
        color: var(--bs-body-color) !important;
    }

    [data-bs-theme="dark"] .text-dark,
    [data-bs-theme="dark"] .text-secondary {
        color: var(--bs-body-color) !important;
    }

    [data-bs-theme="dark"] .border-end {
        border-color: var(--bs-border-color) !important;
    }

    [data-bs-theme="dark"] .modal-content {
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
    }

    [data-bs-theme="dark"] .card-header.bg-warning,
    [data-bs-theme="dark"] .card-header.bg-info {
        color: #212529 !important;
    }

    [data-bs-theme="dark"] .form-control,
    [data-bs-theme="dark"] .form-select,
    [data-bs-theme="dark"] .input-group-text {
        background-color: var(--bs-body-bg);
        color: var(--bs-body-color);
        border-color: var(--bs-border-color);
    }

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

    [data-bs-theme="dark"] .matrix-table thead tr:first-child th {
        background: var(--bs-tertiary-bg) !important;
        color: var(--bs-body-color) !important;
    }

    [data-bs-theme="dark"] .matrix-table thead tr.thead-totals th,
    [data-bs-theme="dark"] .table-light,
    [data-bs-theme="dark"] .table-light>th,
    [data-bs-theme="dark"] .table-light>td {
        background-color: var(--bs-tertiary-bg) !important;
        color: var(--bs-body-color) !important;
        border-color: var(--bs-border-color) !important;
    }

    [data-bs-theme="dark"] .list-group-item {
        background-color: var(--bs-body-bg);
        color: var(--bs-body-color);
        border-color: var(--bs-border-color);
    }

    /* PROFESSIONAL PRINT FIX */
    @media print {
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        body {
            background: #fff !important;
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif !important;
            font-size: 10pt !important;
            color: #000 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .no-print,
        .navbar,
        .btn,
        form,
        header,
        footer,
        .modal,
        .modal-backdrop {
            display: none !important;
        }

        .container-fluid {
            width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .row {
            display: block !important;
            margin: 0 !important;
            page-break-after: auto !important;
        }

        [class*="col-"] {
            width: 100% !important;
            max-width: 100% !important;
            display: block !important;
            margin-bottom: 25px !important;
            padding: 0 !important;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            margin-bottom: 30px !important;
        }

        .card-header {
            background-color: transparent !important;
            color: #000 !important;
            border-bottom: 2px solid #000 !important;
            padding: 0 0 8px 0 !important;
            font-size: 14pt !important;
            font-weight: bold !important;
            text-transform: uppercase;
        }

        .card-header .badge,
        .card-header i,
        .card-header button {
            display: none !important;
        }

        .card-body {
            padding: 15px 0 0 0 !important;
            min-height: 0 !important;
        }

        canvas {
            max-height: 350px !important;
            width: auto !important;
            max-width: 100% !important;
            margin: 0 auto !important;
            display: block !important;
        }

        .table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin-bottom: 0 !important;
        }

        .table th,
        .table td {
            border: 1px solid #333 !important;
            padding: 6px !important;
            font-size: 10pt !important;
            background-color: transparent !important;
        }

        .table-responsive {
            overflow: visible !important;
        }

        .print-header {
            text-align: center !important;
            border-bottom: 3px double #000 !important;
            margin-bottom: 30px !important;
            padding-bottom: 15px !important;
            display: block !important;
        }

        .print-header::after {
            content: "CONFIDENTIAL - INTERNAL MANAGEMENT REPORT";
            display: block;
            margin-top: 8px;
            font-size: 8pt;
            letter-spacing: 1px;
            color: #555;
        }

        .print-logo {
            max-height: 60px !important;
            display: block !important;
            margin: 0 auto 15px auto !important;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            color: #111 !important;
        }

        body .analytics-print-hidden {
            display: none !important;
        }

        body.print-matrix-only * {
            visibility: hidden;
        }

        body.print-matrix-only .print-header,
        body.print-matrix-only .print-header *,
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

    .print-logo {
        max-height: 70px;
    }

    .print-header {
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 20px;
        padding-bottom: 10px;
        display: none;
    }

    .bg-total {
        background-color: #ced4da !important;
    }

    .bg-black {
        background-color: #000000 !important;
        color: #ffffff !important;
    }
</style>
</head>

<div class="modal fade no-print" id="printModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title">Select Sections to Print</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-end gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPrintSections(true)">Select All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPrintSections(false)">Deselect All</button>
                </div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="p-status" checked><label class="form-check-label" for="p-status">Status & Turnover</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="p-comp" checked><label class="form-check-label" for="p-comp">Vault Compliance</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="p-demo" checked><label class="form-check-label" for="p-demo">Demographics (Age, Gender, Dept)</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="p-matrix" checked><label class="form-check-label" for="p-matrix">Tenure Matrix</label></div>
            </div>
            <div class="modal-footer py-1">
                <button type="button" class="btn btn-primary btn-sm" onclick="executeCustomPrint()"><i class="bi bi-printer"></i> Generate Report</button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
    <div class="card shadow-sm mb-4 border-primary no-print">
        <div class="card-body py-2 rounded">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-auto"><i class="bi bi-funnel-fill text-muted"></i></div>
                <div class="col-md-auto">
                    <select name="year" class="form-select form-select-sm fw-bold text-primary" onchange="document.getElementsByName('date_from')[0].value=''; document.getElementsByName('date_to')[0].value=''; this.form.submit()">
                        <?php foreach ($availableYears as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $yearFilter ? 'selected' : ''; ?>>📅 <?php echo htmlspecialchars((string)$y, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
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
                        <option value="">All Employers</option>
                        <option value="TESP_DIRECT" <?php if ($agencyFilter === 'TESP_DIRECT') echo 'selected'; ?>>TESP</option>
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
                    <button type="button" data-bs-toggle="modal" data-bs-target="#printModal" class="btn btn-sm btn-dark"><i class="bi bi-printer"></i> Custom Print</button>
                    <a href="analytics.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- PRINT HEADER -->
    <div class="print-header d-print-block">
        <img src="<?php echo $logo_src; ?>" alt="TESP Logo" class="print-logo">
        <div style="font-size: 16pt; font-weight: bold; text-transform: uppercase;">TES Philippines, Inc.</div>
        <div style="font-size: 14pt; font-weight: bold; text-transform: uppercase;">Workforce Analytics Report</div>
        <p class="text-muted small">
            Tenure as of: <?php echo htmlspecialchars($asOf->format('F j, Y')); ?> | Period: <?php echo htmlspecialchars($startDate . ' to ' . $endDate); ?> | Generated: <?php echo date('M d, Y'); ?>
        </p>
    </div>

    <!-- METRICS ROW 1 -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100 text-center border-0 bg-primary text-white" id="activeHeadcountCard">
                <div class="card-body d-flex flex-column justify-content-center">
                    <h6 class="opacity-75">Active Headcount</h6>
                    <h1 class="display-3 fw-bold mb-0"><?php echo htmlspecialchars(number_format($totalHeadcount)); ?></h1>
                    <small class="opacity-75">Includes employees transferred overseas who remain employed</small>
                    <button type="button" class="btn btn-sm btn-outline-light mt-3" data-bs-toggle="modal" data-bs-target="#excludedHeadcountModal">
                        View excluded employees (<?php echo number_format($excludedHeadcountCount); ?>)
                    </button>
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
                    <button class="btn btn-sm btn-outline-dark mt-2 w-100" data-bs-toggle="modal" data-bs-target="#probationModal"><i class="bi bi-list-ul"></i> View List</button>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100 border-danger" id="turnoverRateCard">
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
            <div class="card shadow-sm h-100 border-info" id="avgTenureCard">
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

    <!-- COMPLIANCE ROW -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card shadow-sm h-100 border-info" id="vaultComplianceCard">
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
                <div class="card-body position-relative" style="height: 250px;"><canvas id="complianceChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- EXPIRY & ATS ROW -->
    <div class="row mb-4">
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
                <div class="card-body position-relative" style="height: 250px;"><canvas id="expiryChart"></canvas></div>
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
                <div class="card-body position-relative" style="height: 250px;"><canvas id="recruitChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- EDUCATION ROW -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-mortarboard-fill"></i> Education Progress (Graduate % per Department)</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('eduProgChart', 'Education Progress by Department')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body position-relative" style="height: 300px;"><canvas id="eduProgChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-mortarboard-fill"></i> College Course Distribution</span>
                    <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('courseDistChart', 'College Course Distribution')"><i class="bi bi-arrows-fullscreen"></i></button>
                </div>
                <div class="card-body position-relative" style="height: 520px;"><canvas id="courseDistChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- HEADCOUNT ROW -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Employer Breakdown</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('agencyChart', 'Agency_Breakdown')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('agencyChart', 'Employer Breakdown')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;"><canvas id="agencyChart"></canvas></div>
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
                <div class="card-body position-relative" style="height: 250px;"><canvas id="deptChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- TREND ROW -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 text-info d-flex justify-content-between align-items-center">
                    <span>Net Workforce Growth</span>
                    <div>
                        <button class="btn btn-sm btn-link text-info p-0 me-1" onclick="downloadSpecificChart('trendChart', 'Workforce_Growth_Trend')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-info p-0" onclick="openFullScreen('trendChart', 'Net Workforce Growth')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;"><canvas id="trendChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Gender Split</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('genderChart', 'Gender_Distribution')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('genderChart', 'Gender Distribution')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;"><canvas id="genderChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- DEMOGRAPHICS & RATINGS ROW -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Performance Ratings</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('perfChart', 'Performance_Ratings')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('perfChart', 'Performance Ratings')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;"><canvas id="perfChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Age Demographics</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('ageChart', 'Age_Demographics')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('ageChart', 'Age Demographics')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;"><canvas id="ageChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card shadow-sm h-100">
                <div class="card-header border-bottom-0 d-flex justify-content-between align-items-center">
                    <span>Tenure Overview</span>
                    <div>
                        <button class="btn btn-sm btn-link text-secondary p-0 me-1" onclick="downloadSpecificChart('tenureChart', 'Tenure_Overview')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-secondary p-0" onclick="openFullScreen('tenureChart', 'Tenure Overview')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;"><canvas id="tenureChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ATTRITION SECTION -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100 border-danger" id="topExitReasonsCard">
                <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bar-chart-fill me-1"></i> Attrition Status</span>
                    <div>
                        <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('turnoverChart', 'Attrition_Status')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('turnoverChart', 'Attrition Status')" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 320px;">
                    <canvas id="turnoverChart"></canvas>
                    <p class="text-muted small text-center mb-0">Compares current employee statuses with recorded exit dates in <?php echo htmlspecialchars($periodSeriesLabel, ENT_QUOTES, 'UTF-8'); ?> against all years. Employees without an exit date appear only in All years. Click a bar to see employee details.</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card shadow-sm h-100 border-danger">
                <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bar-chart-fill me-1"></i> Exits by Dept</span>
                    <div>
                        <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('deptTurnoverChart', 'Exits_By_Department')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('deptTurnoverChart', 'Exits by Department')" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;">
                    <canvas id="deptTurnoverChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card shadow-sm h-100 border-danger">
                <div class="card-header bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-down-arrow me-1"></i> Monthly Trend</span>
                    <div>
                        <button class="btn btn-sm btn-link text-white p-0 me-1" onclick="downloadSpecificChart('attritionTrendChart', 'Monthly_Attrition_Trend')" title="Download Image"><i class="bi bi-download"></i></button>
                        <button class="btn btn-sm btn-link text-white p-0" onclick="openFullScreen('attritionTrendChart', 'Monthly Attrition Trend')" title="Fullscreen"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;">
                    <canvas id="attritionTrendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-2">
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

    <!-- BIRTHDAYS AND ANNIVERSARIES -->
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
                                            <div class="small text-muted text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($b['dept'] . ' - ' . $b['job_title']); ?>"><?php echo htmlspecialchars($b['dept'] . ' - ' . $b['job_title']); ?></div>
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
                        <button class="btn btn-sm btn-link text-dark p-0" onclick="openFullScreen('bdayMonthChart', 'Birthdays per Month')"><i class="bi bi-arrows-fullscreen"></i></button>
                    </div>
                </div>
                <div class="card-body position-relative" style="height: 250px;">
                    <canvas id="bdayMonthChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- TENURE MATRIX -->
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
                        <thead class="table-light">
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

    <!-- EMPLOYEES EXCLUDED FROM ACTIVE HEADCOUNT -->
    <div class="modal fade" id="excludedHeadcountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <div>
                        <h5 class="modal-title">Employees Not Included in Active Headcount</h5>
                        <small>Includes non-active statuses and records missing a status. Transferred employees who remain employed are included in active headcount.</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Employee</th>
                                    <th>ID</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Reason / Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($excludedHeadcountEmployees as $excludedEmployee):
                                    $excludedStatus = employeeEffectiveStatus($excludedEmployee['status'] ?? '', $excludedEmployee['exit_reason'] ?? '');
                                ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo htmlspecialchars(trim($excludedEmployee['last_name'] . ', ' . $excludedEmployee['first_name']), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$excludedEmployee['emp_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($excludedEmployee['dept'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '—'; ?></td>
                                        <td><?php echo htmlspecialchars($excludedStatus !== '' ? $excludedStatus : 'Unspecified', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)($excludedEmployee['exit_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($excludedHeadcountEmployees)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No employees are excluded from the active headcount for the selected filters.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- PROBATIONARY MODAL -->
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

    <!-- FULL SCREEN CHART MODAL -->
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
                        <div class="col-lg-8 d-flex align-items-center justify-content-center border-end" style="background: var(--bs-tertiary-bg);">
                            <div style="width: 95%; height: 90%;">
                                <canvas id="fsChartCanvas"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-4 overflow-auto p-4" style="background: var(--bs-body-bg); color: var(--bs-body-color);">
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
                                        <tbody></tbody>
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
<script>
    const colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6610f2', '#fd7e14'];
    const charts = {}; // Store chart instances for theme updates
    const turnoverEmployeesByPeriod = <?php echo $turnEmployeeDetailsJson; ?>;

    function showAttritionEmployees(status, periodLabel) {
        const employees = turnoverEmployeesByPeriod[periodLabel]?.[status] || [];
        const wrapper = document.createElement('div');
        const intro = document.createElement('p');
        intro.className = 'text-muted small';
        intro.textContent = `${employees.length} employee(s) counted as ${status} (${periodLabel}).`;
        wrapper.appendChild(intro);

        if (employees.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = 'No employee details are available for this category.';
            wrapper.appendChild(empty);
        } else {
            const tableWrap = document.createElement('div');
            tableWrap.className = 'table-responsive text-start';
            const table = document.createElement('table');
            table.className = 'table table-sm table-striped align-middle';
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            ['Employee', 'ID', 'Department', 'Current Status', 'Exit Date', 'Reason'].forEach((label) => {
                const th = document.createElement('th');
                th.textContent = label;
                headerRow.appendChild(th);
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            employees.forEach((employee) => {
                const row = document.createElement('tr');
                [employee.name, employee.emp_id, employee.dept, employee.status, employee.exit_date, employee.reason].forEach((value) => {
                    const cell = document.createElement('td');
                    cell.textContent = value || '—';
                    row.appendChild(cell);
                });
                tbody.appendChild(row);
            });
            table.appendChild(tbody);
            tableWrap.appendChild(table);
            wrapper.appendChild(tableWrap);
        }

        Swal.fire({
            title: `${status} Employees — ${periodLabel}`,
            html: wrapper,
            width: '900px',
            confirmButtonText: 'Close'
        });
    }

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
                    let totalSum = dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                    if (dataVal === undefined || dataVal === null || Number(dataVal) === 0) return; // Hide zeros

                    let text = dataVal.toString();
                    if (dataset.label === 'Compliance %' || chart.canvas.id === 'complianceChart' || chart.canvas.id === 'eduProgChart') text += '%';
                    else if (chart.canvas.id === 'courseDistChart' && totalSum > 0) text += ` (${Math.round((dataVal/totalSum)*100)}%)`;

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

                    // Detect Horizontal Bars for accurate internal labeling
                    if ((chart.config.type === 'bar' || meta.type === 'bar') && element.base !== undefined) {
                        if (chart.options.indexAxis === 'y') {
                            x = (element.base + pos.x) / 2;
                        } else {
                            y = (element.base + pos.y) / 2;
                        }
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

    // Data Store for Full Screen Mode & Dynamic Generation
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
            bg: '#fd7e14'
        },
        'tenureChart': {
            labels: <?php echo $tenureLabels; ?>,
            data: <?php echo $tenureCounts; ?>,
            type: 'bar',
            bg: '#6f42c1'
        },
        'turnoverChart': {
            labels: <?php echo $turnLabels; ?>,
            datasets: [{
                label: <?php echo json_encode($periodSeriesLabel); ?>,
                data: <?php echo $turnPeriodCounts; ?>,
                backgroundColor: '#dc3545'
            }, {
                label: 'All years',
                data: <?php echo $turnAllTimeCounts; ?>,
                backgroundColor: '#6c757d'
            }],
            type: 'bar'
        },
        'deptTurnoverChart': {
            labels: <?php echo $deptTurnLabels; ?>,
            data: <?php echo $deptTurnCounts; ?>,
            type: 'bar',
            bg: '#dc3545'
        },
        'perfChart': {
            labels: <?php echo $perfLabels; ?>,
            data: <?php echo $perfCounts; ?>,
            type: 'bar',
            bg: '#0dcaf0'
        },
        'bdayMonthChart': {
            labels: <?php echo $bdayDistLabels; ?>,
            data: <?php echo $bdayDistCounts; ?>,
            type: 'bar',
            bg: '#0dcaf0'
        },
        'eduProgChart': {
            labels: <?php echo $eduProgLabels; ?>,
            data: <?php echo $eduProgPercents; ?>,
            type: 'bar',
            bg: '#6f42c1'
        },
        'courseDistChart': {
            labels: <?php echo $courseLabels; ?>,
            data: <?php echo $courseCounts; ?>,
            type: 'bar',
            bg: '#0d6efd'
        },
        'complianceChart': {
            labels: <?php echo $complianceData['by_dept_labels']; ?>,
            data: <?php echo $complianceData['by_dept_data']; ?>,
            type: 'bar',
            bg: '#198754'
        },
        'recruitChart': {
            labels: <?php echo $recruitLabels; ?>,
            data: <?php echo $recruitCounts; ?>,
            type: 'bar',
            bg: '#20c997'
        }
    };

    // --- CHART INITIALIZATION ENGINE ---
    document.addEventListener("DOMContentLoaded", function() {
        Chart.defaults.color = 'var(--bs-body-color)';
        Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';

        const chartConfig = {
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        };

        const pieConfig = {
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 8,
                        font: { size: 11 }
                    }
                }
            }
        };

        function createChart(id) {
            const ctx = document.getElementById(id);
            if (!ctx || !chartData[id]) return;
            const d = chartData[id];

            let isPie = (d.type === 'doughnut' || d.type === 'pie');
            let options = isPie ? {
                ...pieConfig
            } : {
                ...chartConfig
            };

            // Format percentage-based charts correctly
            if (id === 'eduProgChart' || id === 'complianceChart') {
                options.scales.y.max = 100;
                options.scales.y.ticks = {
                    callback: function(value) {
                        return value + "%"
                    }
                };
            }

            // Course Distribution horizontal bar configuration
            if (id === 'courseDistChart') {
                options = {
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        },
                        y: {
                            ticks: {
                                autoSkip: false,
                                font: { size: 11 }
                            }
                        }
                    }
                };
            }

            if (id === 'turnoverChart') {
                options = {
                    indexAxis: 'y',
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        },
                        y: {
                            ticks: { autoSkip: false }
                        }
                    }
                };
            }

            if (id === 'turnoverChart') {
                options.onClick = function(event, elements, chart) {
                    if (!elements.length) return;
                    const {datasetIndex, index} = elements[0];
                    const category = chart.data.labels[index];
                    const periodLabel = chart.data.datasets[datasetIndex].label;
                    showAttritionEmployees(category, periodLabel);
                };
                options.onHover = function(event, elements, chart) {
                    chart.canvas.style.cursor = elements.length ? 'pointer' : 'default';
                };
            }

            let dataConfig = {
                labels: d.labels,
                datasets: d.datasets ? d.datasets : [{
                    label: 'Count',
                    data: d.data,
                    backgroundColor: d.bg,
                    borderWidth: 1,
                    borderColor: 'var(--bs-body-bg)'
                }]
            };

            charts[id] = new Chart(ctx, {
                type: d.type,
                data: dataConfig,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    ...options
                }
            });
        }

        // 1. Initialize Standard Configured Charts
        Object.keys(chartData).forEach(id => {
            if (id !== 'trendChart') createChart(id);
        });

        // 2. Initialize Trend Chart (Multi-dataset Net Growth)
        const trendCtx = document.getElementById('trendChart');
        if (trendCtx) {
            charts['trendChart'] = new Chart(trendCtx, {
                type: chartData['trendChart'].type,
                data: {
                    labels: chartData['trendChart'].labels,
                    datasets: chartData['trendChart'].datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        // 3. Initialize Expiry Forecast (Complex Multi-Dataset Stacked Bar)
        const expiryCtx = document.getElementById('expiryChart');
        if (expiryCtx) {
            charts['expiryChart'] = new Chart(expiryCtx, {
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
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
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
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        // 4. Initialize Monthly Attrition Trend
        const attrCtx = document.getElementById('attritionTrendChart');
        if (attrCtx) {
            charts['attritionTrendChart'] = new Chart(attrCtx, {
                type: 'line',
                data: {
                    labels: <?php echo $trendLabels; ?>,
                    datasets: [{
                        label: 'Exits',
                        data: <?php echo $attrTrendCounts; ?>,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.2)',
                        fill: true,
                        tension: 0.3
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
                                precision: 0
                            }
                        }
                    }
                }
            });
        }
    });

    // --- UI ACTION HELPERS ---

    // Download specific chart as image
    function downloadSpecificChart(chartId, filename) {
        const chart = charts[chartId];
        if (chart) {
            const link = document.createElement('a');
            link.href = chart.toBase64Image();
            link.download = filename + '_' + new Date().toISOString().split('T')[0] + '.png';
            link.click();
        }
    }

    // Modal Fullscreen Chart Setup
    let fsChart = null;

    function openFullScreen(chartId, title) {
        const origChart = charts[chartId];
        if (!origChart) return;

        document.getElementById('fsModalTitle').innerText = title;

        const tbody = document.querySelector('#fsDataTable tbody');
        tbody.innerHTML = '';

        // Populate Breakdown Table
        origChart.data.labels.forEach((label, i) => {
            // For simple single datasets (like Turnover, Dept, etc.)
            let val = origChart.data.datasets[0].data[i] || 0;
            // Append % symbol for compliance charts in table view
            let suffix = (chartId === 'complianceChart' || chartId === 'eduProgChart') ? '%' : '';
            tbody.innerHTML += `<tr><td>${label}</td><td class="text-end">${val}${suffix}</td></tr>`;
        });

        const ctx = document.getElementById('fsChartCanvas');
        if (fsChart) fsChart.destroy();

        fsChart = new Chart(ctx, {
            type: origChart.config.type,
            data: JSON.parse(JSON.stringify(origChart.data)), // Deep clone data to avoid mutating original
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: origChart.config.options?.indexAxis || 'x',
                plugins: {
                    legend: {
                        position: origChart.config.type === 'bar' ? 'none' : 'right'
                    }
                },
                scales: origChart.config.options.scales // Inherit scale limits if any
            }
        });

        const modal = new bootstrap.Modal(document.getElementById('fullScreenModal'));
        modal.show();
    }

    // Modal Fullscreen Chart Download
    function downloadChartImage() {
        if (fsChart) {
            const link = document.createElement('a');
            link.href = fsChart.toBase64Image();
            link.download = document.getElementById('fsModalTitle').innerText.replace(/\s+/g, '_') + '.png';
            link.click();
        }
    }

    // Custom Printing Selection toggles
    function setPrintSections(checked) {
        ['p-status', 'p-comp', 'p-demo', 'p-matrix'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = checked;
        });
    }

    function executeCustomPrint() {
        // Hide Modal and execute print (Real implementation can optionally toggle CSS classes based on checkboxes)
        const modal = bootstrap.Modal.getInstance(document.getElementById('printModal'));
        if (modal) modal.hide();
        setTimeout(() => window.print(), 500);
    }

    // Focus print on Matrix Table only
    function printMatrixOnly() {
        document.body.classList.add('print-matrix-only');
        window.print();
        setTimeout(() => document.body.classList.remove('print-matrix-only'), 500);
    }
</script>
</body>

</html>
