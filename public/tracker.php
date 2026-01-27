<?php
// ======================================================
// [FILE] public/tracker.php
// [STATUS] Phase 3: Missing Document Tracker (Visual)
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. CONFIGURATION
// Define the "Mandatory" categories you want to track
$REQUIRED_DOCS = [
    '201 Files'    => ['201', 'PDS', 'Data Sheet', 'Resume'], // Keywords to match
    'Valid ID'     => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
    'Contract'     => ['Contract', 'Appointment', 'Offer'],
    'Medical'      => ['Medical', 'Fit to Work', 'Exam'],
    'Clearance'    => ['NBI', 'Police', 'Barangay']
];
// 2. HANDLE ACTIONS (Add/Edit/Delete Requirements)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_req') {
            try {
                $name = trim($_POST['req_name']);
                $keys = trim($_POST['req_keywords']);
                if ($name && $keys) {
                    // [NEW] Duplicate Block: Check if name exists
                    $chk = $pdo->prepare("SELECT id FROM document_requirements WHERE name = ?");
                    $chk->execute([$name]);
                    if ($chk->rowCount() > 0) {
                        header("Location: tracker.php?error=" . urlencode("Requirement '$name' already exists."));
                        exit;
                    }
                    $pdo->prepare("INSERT INTO document_requirements (name, keywords) VALUES (?, ?)")->execute([$name, $keys]);
                }
            } catch (PDOException $e) { /* Ignore if table missing */
            }
        } elseif ($_POST['action'] === 'delete_req') {
            try {
                $id = $_POST['req_id'];
                $pdo->prepare("DELETE FROM document_requirements WHERE id = ?")->execute([$id]);
            } catch (PDOException $e) {
            }
        } elseif ($_POST['action'] === 'edit_req') {
            try {
                $id = $_POST['req_id'];
                $name = trim($_POST['req_name']);
                $keys = trim($_POST['req_keywords']);
                if ($name && $keys) {
                    $pdo->prepare("UPDATE document_requirements SET name = ?, keywords = ? WHERE id = ?")->execute([$name, $keys, $id]);
                }
            } catch (PDOException $e) {
            }
        }
        header("Location: tracker.php");
        exit;
    }
}

// 3. GET FILTERS
// 3. FETCH CONFIGURATION (Dynamic)
$REQUIRED_DOCS = [];
$reqList = []; // For the management modal

try {
    $stmt = $pdo->query("SELECT * FROM document_requirements ORDER BY id ASC");
    $reqList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    // [AUTO-FIX] Table missing? Create it and seed defaults immediately.
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_requirements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        keywords TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO document_requirements (name, keywords) VALUES ('201 Files', '201, PDS, Data Sheet, Resume'),('Valid ID', 'ID, Passport, License, SSS, PhilHealth'),('Contract', 'Contract, Appointment, Offer'),('Medical', 'Medical, Fit to Work, Exam'),('Clearance', 'NBI, Police, Barangay')");

    // Retry fetch
    $stmt = $pdo->query("SELECT * FROM document_requirements ORDER BY id ASC");
    $reqList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
}
if (empty($REQUIRED_DOCS) && empty($reqList)) {
    // If DB is empty but exists, we might want to show nothing or defaults. 
    // Let's respect the empty DB (user deleted all).
}

// 4. GET FILTERS
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$compliance = isset($_GET['compliance']) ? trim($_GET['compliance']) : '';
// [SECURITY] Limit & Sanitize Search
if (strlen($search) > 50) $search = substr($search, 0, 50);
$search = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $search);

// 4. FETCH EMPLOYEES
$sql = "SELECT emp_id, first_name, last_name, dept, job_title, status, email FROM employees WHERE 1=1";
$params = [];

if (!empty($dept)) {
    $sql .= " AND dept = ?";
    $params[] = $dept;
}
if (!empty($compliance)) {
    // We filter compliance in PHP later, but we keep this param for form persistence
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
// [FIX] Only fetch active documents (exclude soft-deleted ones)
$hasDeletedAt = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'");
    if ($chk->rowCount() > 0) $hasDeletedAt = true;
} catch (Exception $e) {
}

$docSql = "SELECT employee_id, category, original_name FROM documents";
if ($hasDeletedAt) $docSql .= " WHERE deleted_at IS NULL";

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

// 6. FILTER BY COMPLIANCE (PHP Side)
if ($compliance !== '') {
    $filtered = [];
    foreach ($employees as $emp) {
        $id = $emp['emp_id'];
        $have = 0;
        $totalReq = count($REQUIRED_DOCS);
        foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
            if (isset($docsMap[$id][$reqKey])) $have++;
        }
        $percent = ($totalReq > 0) ? ($have / $totalReq) * 100 : 0;

        if ($compliance === 'complete' && $percent >= 100) $filtered[] = $emp;
        elseif ($compliance === 'incomplete' && $percent < 100) $filtered[] = $emp;
    }
    $employees = $filtered;
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
        body {
            background: #f8f9fa;
            font-size: 0.9rem;
        }

        .progress {
            height: 20px;
            border-radius: 10px;
            background-color: #e9ecef;
        }

        .icon-check {
            color: #198754;
            font-size: 1.2rem;
        }

        /* Green Check */
        .icon-cross {
            color: #dc3545;
            font-size: 1.2rem;
            opacity: 0.3;
        }

        /* Red X */
        .card-header {
            background: #2c3e50;
            color: white;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f1f1;
        }
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
                            $depts = ['SQP', 'SIGCOM', 'PSS', 'OCS', 'ADMIN', 'HMS', 'RAS', 'TRS', 'LMS', 'DOS', 'CTS', 'BFS', 'WHS', 'GUNJIN'];
                            foreach ($depts as $d) echo "<option value='$d' " . ($dept == $d ? 'selected' : '') . ">$d</option>";
                            ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <select name="compliance" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <option value="complete" <?php echo ($compliance == 'complete' ? 'selected' : ''); ?>>✅ Complete (100%)</option>
                            <option value="incomplete" <?php echo ($compliance == 'incomplete' ? 'selected' : ''); ?>>⚠️ Incomplete</option>
                        </select>
                    </div>
                    <div class="col-auto ms-auto">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Name..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    </div>
                    <?php if (in_array($_SESSION['role'], ['ADMIN', 'HR'])): ?>
                        <div class="col-auto ms-2 border-start ps-3">
                            <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#manageReqModal"><i class="bi bi-gear-fill"></i> Manage Requirements</button>
                        </div>
                    <?php endif; ?>
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
                            <?php foreach ($REQUIRED_DOCS as $catName => $k): ?>
                                <th><?php echo htmlspecialchars($catName); ?></th>
                            <?php endforeach; ?>
                            <th width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp):
                            $id = $emp['emp_id'];

                            // Calculate Score
                            $totalReq = count($REQUIRED_DOCS);
                            $have = 0;
                            $rowCells = [];

                            // Check each requirement
                            foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
                                $isPresent = isset($docsMap[$id][$reqKey]);
                                if ($isPresent) $have++;
                                $rowCells[] = $isPresent;
                            }

                            $percent = ($totalReq > 0) ? ($have / $totalReq) * 100 : 0;

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
                                            <?php echo round($percent); ?>%
                                        </div>
                                    </div>
                                </td>
                                <?php foreach ($rowCells as $has): ?>
                                    <td><?php echo $has ? '<i class="bi bi-check-circle-fill icon-check"></i>' : '<i class="bi bi-x-circle-fill icon-cross"></i>'; ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if ($percent == 100): ?>
                                        <span class="badge bg-success">COMPLETE</span>
                                    <?php elseif ($percent == 0): ?>
                                        <span class="badge bg-danger">EMPTY</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">INCOMPLETE</span>
                                    <?php endif; ?>

                                    <?php if ($percent < 100 && in_array($_SESSION['role'], ['ADMIN', 'HR'])): ?>
                                        <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Send email reminder to <?php echo htmlspecialchars($emp['first_name']); ?>?');">
                                            <input type="hidden" name="action" value="send_reminder">
                                            <input type="hidden" name="emp_id" value="<?php echo htmlspecialchars($emp['emp_id']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Email Reminder"><i class="bi bi-envelope"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MANAGE REQUIREMENTS MODAL -->
    <div class="modal fade" id="manageReqModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-gear-fill"></i> Manage Requirements</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <strong>How it works:</strong> Add a category name and keywords. If an employee has a document matching ANY of the keywords (in category or filename), it counts as "Submitted".
                    </div>

                    <!-- LIST -->
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Requirement Name</th>
                                <th>Keywords (Comma Separated)</th>
                                <th width="100">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reqList as $r): ?>
                                <tr>
                                    <td><input type="text" id="name_<?php echo $r['id']; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['name']); ?>" required></td>
                                    <td><input type="text" id="keys_<?php echo $r['id']; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['keywords']); ?>" required></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="editReq(<?php echo $r['id']; ?>)" title="Save Changes"><i class="bi bi-save"></i></button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteReq(<?php echo $r['id']; ?>)" title="Delete Requirement"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($reqList)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No custom requirements found. Add one below.</td>
                                </tr>
                            <?php endif; ?>
                            <!-- ADD NEW ROW -->
                            <tr class="table-warning">
                                <td>
                                    <input type="text" id="new_req_name" class="form-control form-control-sm" placeholder="New Requirement" list="req_suggestions" required>
                                    <datalist id="req_suggestions">
                                        <option value="Tor / Diploma">
                                        <option value="Certificate of Employment">
                                        <option value="Marriage Contract">
                                        <option value="Birth Certificate">
                                        <option value="Health Card">
                                    </datalist>
                                </td>
                                <td><input type="text" id="new_req_keywords" class="form-control form-control-sm" placeholder="e.g. Cert, Diploma" required></td>
                                <td><button type="button" class="btn btn-sm btn-success w-100" onclick="addReq()"><i class="bi bi-plus-lg"></i> Add</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <form id="delReqForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete_req">
        <input type="hidden" name="req_id" id="delReqId">
    </form>

    <form id="editReqForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="edit_req">
        <input type="hidden" name="req_id" id="editReqId">
        <input type="hidden" name="req_name" id="editReqName">
        <input type="hidden" name="req_keywords" id="editReqKeys">
    </form>

    <form id="addReqForm" method="POST" style="display:none;">
        <input type="hidden" name="action" value="add_req">
        <input type="hidden" name="req_name" id="addReqName">
        <input type="hidden" name="req_keywords" id="addReqKeys">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function deleteReq(id) {
            if (confirm('Remove this requirement?')) {
                document.getElementById('delReqId').value = id;
                document.getElementById('delReqForm').submit();
            }
        }

        function editReq(id) {
            const name = document.getElementById('name_' + id).value;
            const keys = document.getElementById('keys_' + id).value;
            document.getElementById('editReqId').value = id;
            document.getElementById('editReqName').value = name;
            document.getElementById('editReqKeys').value = keys;
            document.getElementById('editReqForm').submit();
        }

        function addReq() {
            const name = document.getElementById('new_req_name').value.trim();
            const keys = document.getElementById('new_req_keywords').value.trim();
            if (name && keys) {
                document.getElementById('addReqName').value = name;
                document.getElementById('addReqKeys').value = keys;
                document.getElementById('addReqForm').submit();
            } else {
                Swal.fire('Error', 'Please fill in both Name and Keywords.', 'warning');
            }
        }

        // [NEW] Show Error Alerts (e.g. Duplicates)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('error')) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: urlParams.get('error')
            });
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    </script>
</body>

</html>