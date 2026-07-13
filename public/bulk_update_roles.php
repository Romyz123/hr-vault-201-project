<?php
// ======================================================
// [FILE] public/bulk_update_roles.php
// [PURPOSE] Bulk update System Roles and Job Titles
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
// [FIX] Include global helper functions
if (!function_exists('h')) {
    require_once __DIR__ . '/../src/helpers.php';
}
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

// 1. SECURITY: Admin, Manager & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

$logger = new Logger($pdo);
$msg = "";
$error = "";
// [FIX] Defensive initialization
$agencies = [];
$deptMap = [];
$system_roles = [];
$dryRunResults = null; // Store preview data

// [NEW] Load Centralized Options
require __DIR__ . '/options.php';

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. HANDLE BULK UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_roles']) || isset($_POST['dry_run']))) {
    // CSRF Check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: Invalid Token.");
    }

    $ids = $_POST['employee_ids'] ?? [];
    // Validate $ids: filter for numeric values and cast to int
    $ids = array_filter($ids, function ($id) {
        return is_numeric($id);
    });
    $ids = array_map('intval', $ids);

    $new_role = trim($_POST['new_system_role'] ?? '');
    $new_job  = trim($_POST['new_job_title'] ?? '');
    $new_dept = trim($_POST['new_dept'] ?? '');
    $new_section = trim($_POST['new_section'] ?? '');
    $new_gender = trim($_POST['new_gender'] ?? '');
    $new_agency = trim($_POST['new_agency'] ?? '');

    // Validate against allowlists
    $allowedRoles = $system_roles;
    $allowedGenders = ['Male', 'Female', 'Other'];
    $allowedDepts = array_keys($deptMap);

    if (!empty($new_role) && !in_array($new_role, $allowedRoles)) {
        $error = "❌ Invalid role selected.";
    } elseif (!empty($new_gender) && !in_array($new_gender, $allowedGenders)) {
        $error = "❌ Invalid gender selected.";
    } elseif (!empty($new_dept)) {
        $selectedDepts = array_map('trim', explode(',', $new_dept));
        foreach ($selectedDepts as $sd) {
            if (!in_array($sd, $allowedDepts)) {
                $error = "❌ Invalid department selected: " . htmlspecialchars($sd);
                break;
            }
        }
    }

    if (empty($ids)) {
        $error = "❌ No employees selected.";
    } else {
        // Validation
        $valid = true;
        if ($new_job !== '') {
            if (strlen($new_job) > 50) {
                $error = "❌ Job Title is too long (Max 50 chars).";
                $valid = false;
            } elseif (!preg_match('/^[a-zA-Z0-9\s\-\.\,\(\)\/]+$/', $new_job)) {
                $error = "❌ Job Title contains invalid characters.";
                $valid = false;
            }
        }

        if ($valid) {
            // [NEW] DRY RUN LOGIC
            if (isset($_POST['dry_run'])) {
                $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
                $sql = "SELECT id, emp_id, first_name, last_name, job_title, dept, section, gender, system_role FROM employees WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $dryRunResults = [];
                foreach ($targets as $t) {
                    $changes = [];
                    $oldRole = htmlspecialchars($t['system_role'], ENT_QUOTES, 'UTF-8');
                    $oldJob = htmlspecialchars($t['job_title'], ENT_QUOTES, 'UTF-8');
                    $oldDept = htmlspecialchars($t['dept'], ENT_QUOTES, 'UTF-8');
                    $oldSection = htmlspecialchars($t['section'], ENT_QUOTES, 'UTF-8');
                    $oldGender = htmlspecialchars($t['gender'], ENT_QUOTES, 'UTF-8');
                    $oldAgency = htmlspecialchars($t['agency_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldEmpType = htmlspecialchars($t['employment_type'] ?? '', ENT_QUOTES, 'UTF-8');

                    if ($new_role && $t['system_role'] !== $new_role) {
                        $changes[] = "Role: <s>$oldRole</s> &rarr; <strong>" . htmlspecialchars($new_role, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_job) {
                        $fmtJob = ucwords(strtolower($new_job));
                        if ($t['job_title'] !== $fmtJob) {
                            $changes[] = "Job: <s>$oldJob</s> &rarr; <strong>" . htmlspecialchars($fmtJob, ENT_QUOTES, 'UTF-8') . "</strong>";
                        }
                    }
                    if ($new_dept && $t['dept'] !== $new_dept) {
                        $changes[] = "Dept: <s>$oldDept</s> &rarr; <strong>" . htmlspecialchars($new_dept, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_section && $t['section'] !== $new_section) {
                        $changes[] = "Section: <s>$oldSection</s> &rarr; <strong>" . htmlspecialchars($new_section, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_gender && $t['gender'] !== $new_gender) {
                        $changes[] = "Gender: <s>$oldGender</s> &rarr; <strong>" . htmlspecialchars($new_gender, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_agency) {
                        $newEmpType = (stripos($new_agency, 'TESP') !== false) ? 'TESP Direct' : 'Agency';
                        if (($t['agency_name'] ?? '') !== $new_agency) {
                            $changes[] = "Agency: <s>$oldAgency</s> &rarr; <strong>" . htmlspecialchars($new_agency, ENT_QUOTES, 'UTF-8') . "</strong>";
                        }
                        // Also update employment_type if it's inconsistent
                        if ($oldEmpType !== $newEmpType) {
                            $changes[] = "Type: <s>$oldEmpType</s> &rarr; <strong>$newEmpType</strong>";
                        }
                    }

                    if (!empty($changes)) {
                        $dryRunResults[] = [
                            'name' => $t['first_name'] . ' ' . $t['last_name'],
                            'changes' => $changes
                        ];
                    }
                }
            } else {
                try {
                    $pdo->beginTransaction();

                    $sql = "UPDATE employees SET ";
                    $params = [];
                    $updates = [];

                    if (!empty($new_role)) {
                        $updates[] = "system_role = ?";
                        $params[] = $new_role;
                    }
                    if ($new_job !== '') {
                        $updates[] = "job_title = ?";
                        $params[] = ucwords(strtolower($new_job)); // Auto-capitalize
                    }
                    if (!empty($new_dept)) {
                        $updates[] = "dept = ?";
                        $params[] = $new_dept;
                    }
                    if (!empty($new_section)) {
                        $updates[] = "section = ?";
                        $params[] = $new_section;
                    }
                    if (!empty($new_gender)) {
                        $updates[] = "gender = ?";
                        $params[] = $new_gender;
                    }
                    if (!empty($new_agency)) {
                        $updates[] = "agency_name = ?";
                        $params[] = $new_agency;
                        $updates[] = "employment_type = ?";
                        $params[] = (stripos($new_agency, 'TESP') !== false) ? 'TESP Direct' : 'Agency';
                    }

                    if (empty($updates)) {
                        $error = "⚠️ No changes specified. Please select a field to update.";
                        $pdo->rollBack();
                    } else {
                        $updates[] = "updated_at = NOW()";
                        $sql .= implode(", ", $updates);
                        $sql .= " WHERE id = ?";

                        $stmt = $pdo->prepare($sql);
                        $count = 0;
                        foreach ($ids as $id) {
                            $execParams = $params;
                            $execParams[] = $id;
                            $stmt->execute($execParams);
                            $count++;
                        }

                        $pdo->commit();
                        $logger->log($_SESSION['user_id'], 'BULK_UPDATE_ROLE', "Updated details for $count employees.");
                        header("Location: bulk_update_roles.php?msg=" . urlencode("✅ Successfully updated $count employees."));
                        exit;
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    // Log the full error server-side
                    error_log('Bulk update error: ' . $e->getMessage());
                    // Show generic error to user
                    $error = "An internal error occurred while updating roles. Please try again later.";
                }
            }
        }
    }
}

// [FIX] Capture message from URL (Post-Redirect-Get)
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// 3. FETCH EMPLOYEES
$search = Validator::sanitizeSearch($_GET['search'] ?? '');

$dept = isset($_GET['dept']) ? $_GET['dept'] : '';

$sql = "SELECT id, emp_id, first_name, last_name, job_title, dept, section, system_role, agency_name, employment_type FROM employees WHERE status = 'Active'";
$params = [];

if ($search) {
    $sql .= " AND (emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($dept) {
    $sql .= " AND dept LIKE ?";
    $params[] = "%{$dept}%";
}

$sql .= " ORDER BY last_name ASC LIMIT 100"; // Limit for performance

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// [NEW] Fuzzy Search Logic
$didYouMean = null;
$didYouMeanLink = "#";
if (empty($employees) && !empty($search)) {
    $closest = SearchHelper::findBestMatch($pdo, $search);
    if ($closest) {
        $didYouMean = $closest;
        $didYouMeanLink = "bulk_update_roles.php?search=" . urlencode($closest);
    }
}

// Departments for filter
$depts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE status='Active' ORDER BY dept")->fetchAll(PDO::FETCH_COLUMN);

// Fetch History Logs
$historyLogs = $pdo->query("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE action = 'BULK_UPDATE_ROLE' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bulk Update Roles</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <link rel="icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="apple-touch-icon" href="../uploads/tesp-logo.png">
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-people-fill"></i> Bulk Update Roles</span>
                <span class="navbar-text text-white-50 ms-3 font-monospace small"><i class="bi bi-clock"></i> <span id="sessionTimer"></span></span>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- FILTERS -->
        <div class="card shadow-sm mb-4">
            <!-- DRY RUN RESULTS DISPLAY -->
            <?php if ($dryRunResults !== null): ?>
                <div class="alert alert-info border-info shadow-sm mb-4">
                    <h5 class="alert-heading"><i class="bi bi-eye"></i> Simulation Results (Dry Run)</h5>
                    <p class="mb-2">The following changes <strong>would be applied</strong> if you click "Apply Changes". No data has been modified yet.</p>
                    <?php if (empty($dryRunResults)): ?>
                        <div class="text-muted fst-italic">No changes detected based on your selection.</div>
                    <?php else: ?>
                        <div class="table-responsive bg-white border rounded">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Proposed Changes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dryRunResults as $res): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($res['name']); ?></td>
                                            <td><?php echo implode('<br>', $res['changes']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-3">
                        <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($depts as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($dept === $d) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" class="form-control" placeholder="Search Name or ID..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ ]/g, '')">
                            <?php if ($search): ?>
                                <a href="bulk_update_roles.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Search</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($didYouMean): ?>
            <div class="alert alert-info text-center shadow-sm mb-4">
                <i class="bi bi-lightbulb-fill me-2"></i> Did you mean:
                <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo htmlspecialchars($didYouMean); ?></a>?
            </div>
        <?php endif; ?>

        <form method="POST" id="bulkUpdateForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <!-- UPDATE PANEL -->
            <div class="card shadow-sm mb-4 border-warning">
                <div class="card-header bg-warning text-dark fw-bold">
                    <i class="bi bi-pencil-square"></i> Update Selected Employees
                </div>
                <div class="card-body bg-white">
                    <div class="row g-3 align-items-end justify-content-center">
                        <div class="col-md-2">
                            <label class="form-label fw-bold">New System Role</label>
                            <select name="new_system_role" class="form-select">
                                <option value="">-- No Change --</option>
                                <?php foreach ($system_roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">New Job Title</label>
                            <input type="text" name="new_job_title" class="form-control" list="job_suggestions" placeholder="Leave blank to keep current" maxlength="50" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/]/g, '')">
                            <datalist id="job_suggestions">
                                <?php
                                $allJobs = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title != '' AND status = 'Active' ORDER BY job_title ASC")->fetchAll(PDO::FETCH_COLUMN);
                                foreach ($allJobs as $j) echo "<option value=\"" . htmlspecialchars($j) . "\">"; ?>
                            </datalist>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">New Department(s)</label>
                            <div class="input-group">
                                <input type="text" name="new_dept" id="new_dept" class="form-control bg-white" readonly placeholder="No Change">
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('new_dept').value = ''; updateNewSections();"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <select id="deptPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addDept(this.value)">
                                <option value="">+ Add Department...</option>
                                <?php foreach (array_keys($deptMap) as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">New Section(s)</label>
                            <div class="input-group">
                                <input type="text" name="new_section" id="new_section" class="form-control bg-white" readonly placeholder="No Change" maxlength="255">
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('new_section').value = ''"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <select id="sectionPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addSection(this.value)">
                                <option value="">+ Add Section...</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">New Gender</label>
                            <select name="new_gender" class="form-select">
                                <option value="">-- No Change --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">New Agency</label>
                            <select name="new_agency" class="form-select">
                                <option value="">-- No Change --</option>
                                <?php foreach ($agencies as $agency): ?>
                                    <option value="<?php echo htmlspecialchars($agency); ?>"><?php echo htmlspecialchars($agency); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid gap-2 pt-3">
                            <button type="submit" name="dry_run" value="1" class="btn btn-info text-white fw-bold btn-sm" formnovalidate>Simulate</button>
                            <button type="submit" name="update_roles" id="applyBtn" class="btn btn-success fw-bold btn-sm">Apply</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLE -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.emp-check').forEach(c => c.checked = this.checked)"></th>
                                <th>Name</th>
                                <th>ID</th>
                                <th>Dept</th>
                                <th>Section</th>
                                <th>Current Job Title</th>
                                <th>Current Agency</th>
                                <th>Current Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($employees)): ?>
                                <tr>
                                    <td colspan="7" class="text-center p-4 text-muted">No employees found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $e): ?>
                                    <tr>
                                        <td><input type="checkbox" name="employee_ids[]" value="<?php echo $e['id']; ?>" class="form-check-input emp-check"></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></td>
                                        <td><?php echo htmlspecialchars($e['emp_id']); ?></td>
                                        <td><?php echo htmlspecialchars($e['dept']); ?></td>
                                        <td><?php echo htmlspecialchars($e['section']); ?></td>
                                        <td><?php echo htmlspecialchars($e['job_title']); ?></td>
                                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($e['agency_name'] ?: $e['employment_type']); ?></span></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($e['system_role']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>

        <!-- HISTORY LOG -->
        <?php if (!empty($historyLogs)): ?>
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-clock-history"></i> Recent Bulk Updates</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>User</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historyLogs as $log): ?>
                                <tr>
                                    <td><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['username']); ?></td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
    <script>
        <?php if ($msg): ?>
            Swal.fire('Success', <?php echo json_encode($msg); ?>, 'success');
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        <?php endif; ?>
        <?php if ($error): ?>
            Swal.fire('Error', <?php echo json_encode($error); ?>, 'error');
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        <?php endif; ?>

        // Dynamic Section Logic
        const deptMap = <?php echo json_encode($deptMap); ?>;

        // [NEW] Multi-Department Logic
        function addDept(val) {
            if (!val) return;
            const input = document.getElementById('new_dept');
            let current = input.value;
            if (current) {
                if (!current.includes(val)) input.value = current + ', ' + val;
            } else {
                input.value = val;
            }
            document.getElementById('deptPicker').value = "";
            updateNewSections(); // Refresh sections based on new dept list
        }

        function updateNewSections() {
            const depts = document.getElementById('new_dept').value.split(',').map(s => s.trim()).filter(s => s !== '');
            const sect = document.getElementById('sectionPicker');
            sect.innerHTML = '<option value="">+ Add Section...</option>';

            // Loop through ALL selected departments
            depts.forEach(dept => {
                if (dept && deptMap[dept]) {
                    // Add Optgroup for clarity
                    const group = document.createElement('optgroup');
                    group.label = dept;

                    deptMap[dept].forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s;
                        opt.text = s;
                        group.appendChild(opt);
                    });
                    sect.appendChild(group);
                }
            });
        }

        function addSection(val) {
            const picker = document.getElementById('sectionPicker');

            if (val) appendSectionValue(val);
            picker.value = "";
        }

        function appendSectionValue(text) {
            const input = document.getElementById('new_section');
            let current = input.value;
            if (current) {
                if (!current.includes(text)) input.value = current + ', ' + text;
            } else {
                input.value = text;
            }
        }

        // [NEW] Confirmation Popup Logic
        document.getElementById('applyBtn').addEventListener('click', function(e) {
            e.preventDefault();

            const form = document.getElementById('bulkUpdateForm');
            const checkboxes = document.querySelectorAll('input[name="employee_ids[]"]:checked');

            if (checkboxes.length === 0) {
                Swal.fire('No Selection', 'Please select at least one employee.', 'warning');
                return;
            }

            const role = document.querySelector('select[name="new_system_role"]').value;
            const job = document.querySelector('input[name="new_job_title"]').value.trim();
            const dept = document.querySelector('input[name="new_dept"]').value;
            const section = document.querySelector('input[name="new_section"]').value;
            const gender = document.querySelector('select[name="new_gender"]').value;
            const agency = document.querySelector('select[name="new_agency"]').value;

            if (!role && !job && !dept && !section && !gender && !agency) {
                Swal.fire('No Changes', 'Please select at least one field to update.', 'warning');
                return;
            }

            let summary = `<ul class="text-start">`;
            if (role) summary += `<li><strong>Role:</strong> ${document.createElement('div').appendChild(document.createTextNode(role)).parentNode.textContent}</li>`;
            if (job) summary += `<li><strong>Job Title:</strong> ${document.createElement('div').appendChild(document.createTextNode(job)).parentNode.textContent}</li>`;
            if (dept) summary += `<li><strong>Department:</strong> ${document.createElement('div').appendChild(document.createTextNode(dept)).parentNode.textContent}</li>`;
            if (section) summary += `<li><strong>Section:</strong> ${document.createElement('div').appendChild(document.createTextNode(section)).parentNode.textContent}</li>`;
            if (gender) summary += `<li><strong>Gender:</strong> ${document.createElement('div').appendChild(document.createTextNode(gender)).parentNode.textContent}</li>`;
            if (agency) summary += `<li><strong>Agency:</strong> ${document.createElement('div').appendChild(document.createTextNode(agency)).parentNode.textContent}</li>`;
            summary += `</ul>`;

            Swal.fire({
                title: `Update ${checkboxes.length} Employees?`,
                html: `<p>The following changes will be applied:</p>${summary}<p class="text-danger small mt-2">This action cannot be undone.</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Apply Updates'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create hidden input to simulate button click
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'update_roles';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);
                    form.submit();
                }
            });
        });

        // [NEW] Scroll Memory Logic
        const scrollKey = 'hr201_scroll_pos_' + window.location.pathname;
        window.addEventListener('beforeunload', () => {
            sessionStorage.setItem(scrollKey, window.scrollY);
        });

        const urlParamsForScroll = new URLSearchParams(window.location.search);
        if (urlParamsForScroll.has('msg') || urlParamsForScroll.has('search') || urlParamsForScroll.has('dept')) {
            const savedPos = sessionStorage.getItem(scrollKey);
            if (savedPos) window.scrollTo(0, parseInt(savedPos));
        }
    </script>
</body>

</html>
<?php require 'footer.php'; ?>