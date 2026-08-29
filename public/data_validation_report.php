<?php
// ======================================================
// [FILE] public/data_validation_report.php
// [PURPOSE] Identify employees with missing IDs or Photos + Excel Export
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require_once __DIR__ . '/../src/helpers.php'; // Centralized helper functions
require_once __DIR__ . '/options.php';

// [FIX] Defensive initialization to satisfy IDE/Intelephense diagnostics
$deptMap = $deptMap ?? [];
$agencies = $agencies ?? [];

session_start();
checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id']) || !in_array(strtoupper($_SESSION['role'] ?? ''), ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

// --- Filters ---
$deptFilter = $_GET['dept'] ?? '';
$agencyFilter = $_GET['agency'] ?? '';

$sql = "SELECT id, emp_id, first_name, last_name, dept, agency_name, sss_no, tin_no, philhealth_no, pagibig_no, avatar_path 
        FROM employees 
        WHERE status = 'Active' AND deleted_at IS NULL";
$params = [];

if ($deptFilter) {
    $sql .= " AND dept = ?";
    $params[] = $deptFilter;
}
if ($agencyFilter) {
    $sql .= " AND agency_name = ?";
    $params[] = $agencyFilter;
}

$sql .= " ORDER BY last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Document Presence Check ---
$mandatoryDocs = ['Birth Certificate', 'SSS ID', 'PhilHealth ID', 'Pag-IBIG ID', 'TIN ID', 'NBI Clearance'];
$empIds = array_column($employees, 'emp_id');
$docsMap = [];
if (!empty($empIds)) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $docStmt = $pdo->prepare("SELECT employee_id, category FROM documents WHERE employee_id IN ($placeholders) AND deleted_at IS NULL");
    $docStmt->execute($empIds);
    while ($d = $docStmt->fetch(PDO::FETCH_ASSOC)) {
        $docsMap[$d['employee_id']][] = strtoupper(trim($d['category']));
    }
}

$flagged = [];
$stats = ['total' => 0, 'missing_ids' => 0, 'missing_photos' => 0, 'missing_docs' => 0];

foreach ($employees as $emp) {
    $idIssues = [];
    if (empty($emp['sss_no'])) $idIssues[] = 'SSS';
    if (empty($emp['tin_no'])) $idIssues[] = 'TIN';
    if (empty($emp['philhealth_no'])) $idIssues[] = 'PhilHealth';
    if (empty($emp['pagibig_no'])) $idIssues[] = 'Pag-IBIG';

    $missingDocs = [];
    $empDocs = $docsMap[$emp['emp_id']] ?? [];
    foreach ($mandatoryDocs as $mDoc) {
        if (!in_array(strtoupper($mDoc), $empDocs)) $missingDocs[] = $mDoc;
    }

    $has_photo_issue = (empty($emp['avatar_path']) || $emp['avatar_path'] === 'default.png');

    if (!empty($idIssues) || $has_photo_issue || !empty($missingDocs)) {
        $flagged[] = [
            'data' => $emp,
            'missing_id_nums' => $idIssues,
            'missing_photo' => $has_photo_issue,
            'missing_docs' => $missingDocs
        ];
        if (!empty($idIssues)) $stats['missing_ids']++;
        if ($has_photo_issue) $stats['missing_photos']++;
        if (!empty($missingDocs)) $stats['missing_docs']++;
    }
}
$stats['total'] = count($flagged);

// --- Handle Excel (CSV) Export Request ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Clear output buffers to ensure clean CSV stream
    while (ob_get_level()) {
        ob_end_clean();
    }

    $filename = "Data_Validation_Report_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM for proper Excel character encoding compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // CSV Header row
    fputcsv($output, ['Employee ID', 'Last Name', 'First Name', 'Department', 'Agency', 'Photo Status', 'Missing Government IDs', 'Missing 201 Files']);

    // CSV Data rows
    foreach ($flagged as $f) {
        fputcsv($output, [
            $f['data']['emp_id'],
            $f['data']['last_name'],
            $f['data']['first_name'],
            $f['data']['dept'],
            $f['data']['agency_name'] ?? 'N/A',
            $f['missing_photo'] ? 'Missing' : 'OK',
            implode(', ', $f['missing_id_nums']),
            implode(', ', $f['missing_docs'])
        ]);
    }

    fclose($output);
    exit;
}

require 'header.php';
?>

<style>
    /* Professional Print / Excel Table Styles */
    @media print {

        nav,
        .breadcrumb,
        .card-header form,
        .btn,
        .no-print,
        th:last-child,
        td:last-child {
            display: none !important;
        }

        body {
            background-color: #ffffff !important;
            color: #000000 !important;
            font-family: Arial, sans-serif !important;
            font-size: 10pt !important;
        }

        .container {
            width: 100% !important;
            max-width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        .print-header h2 {
            font-size: 16pt;
            font-weight: bold;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }

        .print-header p {
            font-size: 9pt;
            color: #555;
            margin: 0;
        }

        .row.g-3.mb-4 {
            display: flex !important;
            flex-wrap: nowrap !important;
            gap: 10px !important;
            margin-bottom: 15px !important;
        }

        .row.g-3.mb-4 .col-md-3 {
            flex: 1 !important;
            max-width: 25% !important;
        }

        .card {
            border: 1px solid #ccc !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }

        .card-body {
            padding: 8px !important;
        }

        .card-body h6 {
            font-size: 8pt !important;
            color: #333 !important;
        }

        .card-body h2 {
            font-size: 14pt !important;
        }

        .card.shadow-sm {
            border: none !important;
            box-shadow: none !important;
        }

        .table-responsive {
            overflow: visible !important;
        }

        .table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin-top: 10px;
        }

        .table th {
            background-color: #f2f2f2 !important;
            color: #000000 !important;
            border: 1px solid #000000 !important;
            font-size: 9pt !important;
            font-weight: bold;
            text-align: left;
            padding: 6px !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .table td {
            border: 1px solid #cccccc !important;
            padding: 5px 6px !important;
            font-size: 9pt !important;
            vertical-align: middle !important;
        }

        .badge {
            border: 1px solid #999 !important;
            background: transparent !important;
            color: #000 !important;
            font-size: 8pt !important;
            padding: 2px 4px !important;
            font-weight: normal !important;
        }
    }

    .print-header {
        display: none;
    }
</style>

<div class="container mt-4">
    <div class="print-header">
        <h2>TES Philippines, Inc. - HR Department</h2>
        <p>Employee Data Validation & Compliance Report | Generated on: <?php echo date('F d, Y'); ?></p>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><i class="bi bi-shield-exclamation text-danger"></i> Data Validation Report</h4>
        <div class="btn-group gap-2">
            <!-- New Excel Export Button -->
            <?php
            // Preserve current filters for the excel export link
            $exportQuery = $_GET;
            $exportQuery['export'] = 'excel';
            $exportUrl = 'data_validation_report.php?' . http_build_query($exportQuery);
            ?>
            <a href="<?php echo $exportUrl; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Export to Excel</a>
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i> Print Report</button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-start border-danger border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Total Flagged</h6>
                    <h2 class="mb-0"><?php echo $stats['total']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-warning border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Incomplete IDs</h6>
                    <h2 class="mb-0"><?php echo $stats['missing_ids']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-info border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Missing Photos</h6>
                    <h2 class="mb-0"><?php echo $stats['missing_photos']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-dark border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Missing Documents</h6>
                    <h2 class="mb-0"><?php echo $stats['missing_docs']; ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($deptMap as $d => $s): ?>
                            <option value="<?= h($d) ?>" <?= $deptFilter === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="agency" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Agencies</option>
                        <?php foreach ($agencies as $a): ?>
                            <option value="<?= h($a) ?>" <?= $agencyFilter === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 text-end">
                    <small class="text-muted">Showing active employees only.</small>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Photo</th>
                        <th>Missing ID #</th>
                        <th>Missing 201 Docs</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($flagged)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-check-circle fs-1 text-success"></i><br>
                                Great! No data inconsistencies found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($flagged as $f): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= h($f['data']['last_name'] . ', ' . $f['data']['first_name']) ?></div>
                                    <small class="text-muted"><?= h($f['data']['emp_id']) ?></small>
                                </td>
                                <td><?= h($f['data']['dept']) ?></td>
                                <td>
                                    <?php if ($f['missing_photo']): ?>
                                        <span class="badge bg-info-subtle text-info border border-info"><i class="bi bi-camera-fill"></i> Missing</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check"></i> OK</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($f['missing_id_nums'] as $idName): ?>
                                        <span class="badge bg-warning-subtle text-dark border border-warning me-1"><?= $idName ?></span>
                                    <?php endforeach; ?>
                                    <?php if (empty($f['missing_id_nums'])): ?>
                                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check"></i> OK</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($f['missing_docs'] as $docName): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger me-1"><?= h($docName) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (empty($f['missing_docs'])): ?>
                                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check"></i> OK</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="edit_employee.php?id=<?= $f['data']['id'] ?>" class="btn btn-sm btn-primary">Update Profile</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require 'footer.php'; ?>