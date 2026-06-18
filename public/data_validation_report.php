<?php
// ======================================================
// [FILE] public/data_validation_report.php
// [PURPOSE] Identify employees with missing IDs or Photos
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

require 'header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><i class="bi bi-shield-exclamation text-danger"></i> Data Validation Report</h4>
        <div class="btn-group">
            <button onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer"></i> Print Report</button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-start border-danger border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Total Flagged Employees</h6>
                    <h2 class="mb-0"><?php echo $stats['total']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-warning border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Incomplete Govt IDs</h6>
                    <h2 class="mb-0"><?php echo $stats['missing_ids']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-info border-4 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted small text-uppercase fw-bold">Missing Profile Photos</h6>
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