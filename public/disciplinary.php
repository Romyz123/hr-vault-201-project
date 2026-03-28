<?php
// ======================================================
// [FILE] public/disciplinary.php
// [STATUS] FINAL: Standard UUIDs + Auto-Repair Logic
// ======================================================

require '../config/db.php';
require '../src/Logger.php';
require '../src/Security.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    $_SESSION['error'] = "Access Denied.";
    header("Location: index.php");
    exit;
}

// [SECURITY] Check Maintenance Mode
if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $chkMaint = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
    if ($chkMaint === '1') {
        header("Location: login.php?msg=" . urlencode("🛠️ System is under maintenance."));
        exit;
    }
}

$logger = new Logger($pdo);
$alertType = "";
$alertMsg = "";

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_case') {
    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ Security Error: Invalid Session Token. Please refresh the page and try again.");
    }

    // [FIX] Support Multiple Employees
    $emp_ids = [];
    if (isset($_POST['employee_ids']) && is_array($_POST['employee_ids'])) {
        $emp_ids = $_POST['employee_ids'];
    } elseif (isset($_POST['employee_id'])) {
        $emp_ids = [$_POST['employee_id']];
    }

    $type       = trim($_POST['violation_type']);
    $date       = $_POST['incident_date'];
    $action     = trim($_POST['action_taken']);
    $desc       = trim($_POST['description']);

    // [SECURITY] Input Validation
    if (strlen($type) > 100) {
        $alertType = 'error';
        $alertMsg = "❌ Violation Type is too long (Max 100 chars).";
        $isValid = false;
    } elseif (!preg_match('/^[a-zA-Z0-9\s\-\(\)\.\,]+$/', $type)) {
        $alertType = 'error';
        $alertMsg = "❌ Violation Type contains invalid characters. Allowed: Letters, Numbers, () - . ,";
        $isValid = false;
    } elseif (strlen($action) > 100) {
        $alertType = 'error';
        $alertMsg = "❌ Action Taken is too long (Max 100 chars).";
        $isValid = false;
    } elseif (empty($date) || !strtotime($date)) {
        $alertType = 'error';
        $alertMsg = "❌ Invalid Incident Date.";
        $isValid = false;
    } elseif (strlen($desc) > 5000) {
        $alertType = 'error';
        $alertMsg = "❌ Description is too long (Max 5000 chars).";
        $isValid = false;
    }

    $dbFilePath = null;
    $syncStatus = "Skipped (No File)";
    // Only proceed if validation passed
    $isValid    = ($alertType !== 'error');
    $uploadedFile = null; // Store path for copying

    // --- FILE UPLOAD LOGIC ---
    if (!empty($_FILES['attachment']['name'])) {

        $targetDir = "uploads/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true); // [FIX] Ensure folder exists
        $originalName = basename($_FILES['attachment']['name']);

        // [SECURITY] Validate File Type (Allow only PDF & Images)
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $alertType = 'error';
            $alertMsg = "❌ <strong>Upload Failed:</strong> Only PDF, JPG, or PNG files are allowed.";
            $isValid = false; // Stop the process
        }

        // Clean filename to prevent issues
        $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
        $fileName  = "DISCIPLINARY_" . time() . "_" . $cleanName;
        $targetFile = $targetDir . $fileName;

        if ($isValid && move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) {
            // [NEW] PDF Corruption Check
            if ($ext === 'pdf') {
                // [OPTIMIZED] Read only header/footer instead of loading whole file into RAM
                $handle = fopen($targetFile, 'rb');
                if ($handle === false) {
                    unlink($targetFile);
                    $alertType = 'error';
                    $alertMsg = "❌ <strong>Upload Failed:</strong> Unable to verify the PDF file.";
                    $isValid = false;
                } else {
                    $fileSize = filesize($targetFile);
                    $chunkSize = min(1024, $fileSize);

                    $header = fread($handle, $chunkSize);

                    // Only seek if file is larger than chunk size
                    if ($fileSize > $chunkSize) {
                        fseek($handle, -$chunkSize, SEEK_END);
                        $footer = fread($handle, $chunkSize);
                    } else {
                        $footer = $header; // Small file: header and footer overlap
                    }
                    fclose($handle);

                    // Valid PDF must start with %PDF- and end with %%EOF
                    if (strpos($header, '%PDF-') !== 0 || strpos($footer, '%%EOF') === false) {
                        unlink($targetFile); // Delete corrupted file immediately
                        $alertType = 'error';
                        $alertMsg = "❌ <strong>Upload Failed:</strong> The PDF file appears to be corrupted or incomplete.";
                        $isValid = false;
                    }
                }
            }
            if ($isValid) {
                $dbFilePath = $fileName;
                $uploadedFile = $targetFile; // Keep full path for copying
            }
        } else {
            if ($isValid) $syncStatus = "❌ FAILED (File Permission Error)";
        }
    }

    // INSERT CASE RECORDS (Loop through all selected employees)
    if ($isValid) {
        try {
            $count = 0;
            foreach ($emp_ids as $index => $e_id) {
                $thisFilePath = $dbFilePath;

                // If multiple employees and file exists, copy file for independence
                if ($dbFilePath && $index > 0) {
                    $ext = pathinfo($dbFilePath, PATHINFO_EXTENSION);
                    $newFileName = "DISCIPLINARY_" . time() . "_" . $index . "_" . preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
                    if (copy($uploadedFile, "uploads/" . $newFileName)) {
                        $thisFilePath = $newFileName;
                    }
                }

                // 1. Insert Case
                $stmt = $pdo->prepare("INSERT INTO disciplinary_cases (employee_id, violation_type, incident_date, action_taken, description, attachment_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$e_id, $type, $date, $action, $desc, $thisFilePath]);

                // 2. Sync to Documents (If file exists)
                if ($thisFilePath) {
                    try {
                        // Check columns (Simplified for speed, assuming standard schema now)
                        $docStmt = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by) VALUES (UUID(), ?, ?, ?, 'Disciplinary', ?)");
                        $docStmt->execute([$e_id, $originalName, $thisFilePath, $_SESSION['user_id']]);
                        $syncStatus = "✅ Synced";
                    } catch (Exception $e) {
                        // Ignore duplicate entry errors if any
                    }
                }
                $count++;
            }

            if ($count > 0) {
                $logger->log($_SESSION['user_id'], 'CASE_ADD', "Filed $count cases for: $type");
                header("Location: disciplinary.php?msg=" . urlencode("$count Cases Filed Successfully!"));
                exit;
            }
        } catch (PDOException $e) {
            $alertType = 'error';
            $alertMsg = "Database Error: " . $e->getMessage();
        }
    }
}

// 3. CLOSE CASE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_case') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }
    $pdo->prepare("UPDATE disciplinary_cases SET status = 'Closed' WHERE id = ?")->execute([$_POST['case_id']]);
    header("Location: disciplinary.php?msg=" . urlencode("Case marked as Closed."));
    exit;
}

// 3.5 REOPEN CASE (UNDO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reopen_case') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }
    $pdo->prepare("UPDATE disciplinary_cases SET status = 'Open' WHERE id = ?")->execute([$_POST['case_id']]);
    header("Location: disciplinary.php?msg=" . urlencode("Case successfully reopened."));
    exit;
}

// 4. DELETE CASE (Cleanup)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_case') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    // Only ADMIN can delete
    if ($_SESSION['role'] !== 'ADMIN') die("Access Denied");

    $delId = $_POST['case_id'];

    // Fetch info to delete file
    $stmt = $pdo->prepare("SELECT attachment_path FROM disciplinary_cases WHERE id = ?");
    $stmt->execute([$delId]);
    $case = $stmt->fetch();

    if ($case) {
        // A. Delete Physical File
        if (!empty($case['attachment_path'])) {
            $filePath = __DIR__ . "/uploads/" . $case['attachment_path'];
            if (file_exists($filePath)) unlink($filePath);

            // B. Remove from Documents Sync (if it exists there)
            $pdo->prepare("DELETE FROM documents WHERE file_path = ? AND category = 'Disciplinary'")->execute([$case['attachment_path']]);
        }

        // C. Delete Record
        $pdo->prepare("DELETE FROM disciplinary_cases WHERE id = ?")->execute([$delId]);

        header("Location: disciplinary.php?msg=Deleted Successfully");
        exit;
    }
}

// 4. FETCH DATA
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (strlen($search) > 50) $search = substr($search, 0, 50);
$search = preg_replace('/[^a-zA-Z0-9\s\-\.\,]/', '', $search);
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';

$sql = "SELECT d.*, e.id AS emp_pk, e.first_name, e.last_name, e.dept FROM disciplinary_cases d JOIN employees e ON d.employee_id = e.emp_id WHERE 1=1";
$params = [];

if (!empty($filter_status)) {
    $sql .= " AND d.status = ?";
    $params[] = $filter_status;
}
if (!empty($filter_dept)) {
    $sql .= " AND e.dept = ?";
    $params[] = $filter_dept;
}

if (!empty($search)) {
    $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $sql .= " AND (e.last_name LIKE ? OR e.first_name LIKE ? OR e.emp_id LIKE ? OR d.violation_type LIKE ?)";
        $t = "%$term%";
        array_push($params, $t, $t, $t, $t);
    }
}
$sql .= " ORDER BY d.incident_date DESC";
$cases = $pdo->prepare($sql);
$cases->execute($params);
$cases = $cases->fetchAll(PDO::FETCH_ASSOC);

// [NEW] Fuzzy Search Logic
$didYouMean = null;
$didYouMeanLink = "#";
if (empty($cases) && !empty($search)) {
    $closest = SearchHelper::findBestMatch($pdo, $search);
    if ($closest) {
        $didYouMean = $closest;
        $didYouMeanLink = "disciplinary.php?search=" . urlencode($closest);
    }
}

$emps = $pdo->query("SELECT emp_id, last_name, first_name FROM employees ORDER BY last_name ASC")->fetchAll();
$allDepts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE dept != '' ORDER BY dept ASC")->fetchAll(PDO::FETCH_COLUMN);

// Capture Success Message
if (isset($_GET['msg'])) {
    $alertType = 'success';
    $alertMsg = htmlspecialchars($_GET['msg']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Disciplinary Management</title>
    <link rel="icon" href="uploads/tesp-logo.png?v=3" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <style>
        .status-Open {
            background-color: #ffeeba;
            color: #856404;
        }

        .status-Closed {
            background-color: #d4edda;
            color: #155724;
        }
    </style>
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center">
                <a class="navbar-brand" href="index.php">Back to Dashboard</a>
                <span class="navbar-text text-white ms-3 border-start ps-3">Disciplinary Console</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
                    <a href="settings.php" class="btn btn-outline-light btn-sm"><i class="bi bi-gear-fill"></i> Settings</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row mb-4 align-items-center">
            <div class="col-md-8">
                <form method="GET" class="d-flex gap-2">
                    <select name="dept" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($allDepts as $d): ?>
                            <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($filter_dept === $d) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="Open" <?php echo ($filter_status === 'Open') ? 'selected' : ''; ?>>Open Only</option>
                        <option value="Closed" <?php echo ($filter_status === 'Closed') ? 'selected' : ''; ?>>Closed Only</option>
                    </select>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Search violation or name..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\s\-\.\,]+" title="Allowed: Letters, Numbers, Spaces, - . ," list="disc_suggestions" autocomplete="off">
                        <datalist id="disc_suggestions">
                            <?php foreach ($emps as $e): ?>
                                <option value="<?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name'] . ' (' . $e['emp_id'] . ')'); ?>">
                                <?php endforeach; ?>
                                <option value="Tardiness">
                                <option value="AWOL">
                                <option value="Insubordination">
                                <option value="Misconduct">
                        </datalist>
                        <?php if ($search): ?>
                            <a href="disciplinary.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-secondary"><i class="bi bi-search"></i></button>
                    <?php if ($search || $filter_status || $filter_dept): ?>
                        <a href="disciplinary.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addCaseModal">
                    <i class="bi bi-file-earmark-medical"></i> File Case(s)
                </button>
            </div>
        </div>

        <?php if ($didYouMean): ?>
            <div class="alert alert-info text-center shadow-sm mb-4">
                <i class="bi bi-lightbulb-fill me-2"></i> Did you mean:
                <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo htmlspecialchars($didYouMean); ?></a>?
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                            <th>Date <span id="selection-count" class="badge bg-primary ms-1" style="display:none">0</span></th>
                            <th>Employee</th>
                            <th>Violation</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th>Manage / Docs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input case-checkbox" value="<?php echo $c['id']; ?>"></td>
                                <td><?php echo date('M d', strtotime($c['incident_date'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['last_name']); ?></strong>, <?php echo htmlspecialchars($c['first_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($c['dept']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($c['violation_type']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo $c['action_taken']; ?></span></td>
                                <td><span class="badge status-<?php echo $c['status']; ?>"><?php echo $c['status']; ?></span></td>
                                <td>
                                    <div class="btn-group mb-1">
                                        <button type="button" class="btn btn-sm btn-warning"
                                            data-emp-id="<?php echo $c['emp_pk']; ?>"
                                            data-date="<?php echo $c['incident_date']; ?>"
                                            data-violation="<?php echo htmlspecialchars($c['violation_type']); ?>"
                                            data-desc="<?php echo htmlspecialchars($c['description']); ?>"
                                            onclick="prepDocModal(this, 'notice_to_explain')">
                                            <i class="bi bi-file-earmark-text"></i> NTE
                                        </button>
                                        <button type="button" class="btn btn-sm btn-dark"
                                            data-emp-id="<?php echo $c['emp_pk']; ?>"
                                            data-date="<?php echo $c['incident_date']; ?>"
                                            data-violation="<?php echo htmlspecialchars($c['violation_type']); ?>"
                                            data-action="<?php echo htmlspecialchars($c['action_taken']); ?>"
                                            onclick="prepDocModal(this, 'notice_of_decision')">
                                            <i class="bi bi-gavel"></i> NOD
                                        </button>
                                    </div>
                                    <br>
                                    <?php if ($c['attachment_path']): ?>
                                        <a href="uploads/<?php echo $c['attachment_path']; ?>" target="_blank" class="btn btn-sm btn-primary">View PDF</a>
                                    <?php else: ?> - <?php endif; ?>

                                    <?php if ($c['status'] == 'Open'): ?>
                                        <form method="POST" class="d-inline" onsubmit="confirmAction(event, 'Are you sure you want to close this case?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="close_case">
                                            <input type="hidden" name="case_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Close</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="confirmAction(event, 'Are you sure you want to undo and reopen this case?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="reopen_case">
                                            <input type="hidden" name="case_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Undo / Reopen Case"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($_SESSION['role'] === 'ADMIN'): ?>
                                        <form method="POST" class="d-inline" onsubmit="confirmAction(event, 'Permanently delete this case and file? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="delete_case">
                                            <input type="hidden" name="case_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                <i class="bi bi-trash"></i>
                                            </button>
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

    <div class="modal fade" id="addCaseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">File Case</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" onsubmit="showLoadingSpinner(this)">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_case">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3 border p-2 rounded bg-light">
                            <label class="fw-bold mb-1">Select Employees (Multi-Select)</label>
                            <input type="text" id="empSearch" class="form-control form-control-sm mb-2" placeholder="Type to filter list..." onkeyup="filterEmployees()" maxlength="50">
                            <select name="employee_ids[]" id="empSelect" class="form-select" multiple required style="height: 150px;">
                                <?php foreach ($emps as $e): ?>
                                    <option value="<?php echo $e['emp_id']; ?>"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small text-muted">Hold <strong>Ctrl</strong> (Windows) or <strong>Cmd</strong> (Mac) to select multiple people.</div>
                        </div>
                        <div class="mb-3">
                            <label>Violation</label>
                            <input type="text" name="violation_type" class="form-control" required placeholder="Tardiness or Company Policy Violation" spellcheck="true" lang="en" maxlength="100" pattern="[a-zA-Z0-9\s\-\(\)\.\,]+" title="Allowed: Letters, Numbers, () - . ," oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\.\,]/g, '')">
                        </div>
                        <div class="mb-3">
                            <label>Date</label>
                            <input type="date" name="incident_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="mb-3">
                            <label>Action</label>
                            <select name="action_taken" class="form-select">
                                <option>Pending</option>
                                <option>Written Warning</option>
                                <option>Suspension</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3" spellcheck="true" lang="en" maxlength="5000" oninput="this.value = this.value.replace(/[<>]/g, '')"></textarea>
                        </div>
                        <div class="mb-3 border p-2 bg-warning bg-opacity-10">
                            <label class="fw-bold">Attach Evidence</label>
                            <input type="file" name="attachment" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-danger">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- GENERATE DOCUMENT MODAL (Custom Input) -->
    <div class="modal fade" id="genDocModal" tabindex="-1">
        <div class="modal-dialog">
            <form action="generate_document.php" method="GET" target="_blank" class="modal-content" onsubmit="showDocSpinner(this)">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="genDocTitle">Generate Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="gen_emp_id">
                    <input type="hidden" name="type" id="gen_type">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Date of Notice</label>
                        <input type="date" name="notice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        <div class="form-text small">The date printed on the document header.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Incident Date</label>
                        <input type="date" name="incident_date" id="gen_date" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Violation / Rule</label>
                        <textarea name="rule_violated" id="gen_violation" class="form-control" rows="2" maxlength="500" style="text-align: justify;" placeholder="e.g. Rule V. Section 3 - Insubordination" required spellcheck="true" lang="en"></textarea>
                        <!-- For NOD, we map this to 'violation' param in JS -->
                        <input type="hidden" name="violation" id="gen_violation_hidden">
                    </div>

                    <!-- NTE Specific -->
                    <div id="group_nte">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nature of Allegation</label>
                            <textarea name="allegation" id="gen_allegation" class="form-control" rows="6" maxlength="2000" style="text-align: justify;" placeholder="Describe the incident in detail..." required spellcheck="true" lang="en"></textarea>
                            <div class="form-text text-end small">Max 2000 characters</div>
                        </div>
                    </div>

                    <!-- NOD Specific -->
                    <div id="group_nod" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Decision / Sanction</label>
                            <textarea name="decision" id="gen_decision" class="form-control" rows="6" maxlength="2000" style="text-align: justify;" placeholder="State the decision and penalty..." required spellcheck="true" lang="en"></textarea>
                            <div class="form-text text-end small">Max 2000 characters</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Generate PDF</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
    <script>
        <?php if ($alertMsg): ?>
            Swal.fire({
                icon: '<?php echo $alertType; ?>',
                html: <?php echo json_encode($alertMsg); ?>
            });
            // [FIX] Clear URL parameters to prevent message from reappearing on refresh
            if (window.history.replaceState && window.location.search) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        <?php endif; ?>

        function filterEmployees() {
            const input = document.getElementById('empSearch');
            const filter = input.value.toLowerCase();
            const select = document.getElementById('empSelect');
            const options = select.getElementsByTagName('option');
            for (let i = 0; i < options.length; i++) {
                const txt = options[i].text.toLowerCase();
                if (txt.includes(filter)) {
                    options[i].style.display = "";
                    options[i].hidden = false;
                    options[i].disabled = false;
                } else {
                    options[i].style.display = "none";
                    options[i].hidden = true;
                    options[i].disabled = true;
                }
            }
        }

        function updateCount() {
            const count = document.querySelectorAll('.case-checkbox:checked').length;
            const badge = document.getElementById('selection-count');
            if (badge) {
                badge.innerText = count;
                badge.style.display = count > 0 ? 'inline-block' : 'none';
            }
        }

        // Select All Logic
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                document.querySelectorAll('.case-checkbox').forEach(cb => {
                    // [FIX] Only select visible rows
                    if (cb.offsetParent !== null) cb.checked = this.checked;
                });
                updateCount();
            });
            document.querySelectorAll('.case-checkbox').forEach(cb => {
                cb.addEventListener('change', updateCount);
            });
        }

        // Prepare Document Modal
        function prepDocModal(btn, type) {
            const empId = btn.getAttribute('data-emp-id');
            const date = btn.getAttribute('data-date');
            const violation = btn.getAttribute('data-violation');

            document.getElementById('gen_emp_id').value = empId;
            document.getElementById('gen_type').value = type;
            document.getElementById('gen_date').value = date;
            document.getElementById('gen_violation').value = violation;
            document.getElementById('gen_violation_hidden').value = violation; // Sync for NOD

            // Helper to toggle visibility AND validation (disabled inputs are not required)
            const toggleGroup = (id, show) => {
                const el = document.getElementById(id);
                el.style.display = show ? 'block' : 'none';
                el.querySelectorAll('textarea, input').forEach(i => i.disabled = !show);
            };

            if (type === 'notice_to_explain') {
                document.getElementById('genDocTitle').innerText = 'Generate Notice to Explain';
                toggleGroup('group_nte', true);
                toggleGroup('group_nod', false);
                document.getElementById('gen_allegation').value = btn.getAttribute('data-desc');
            } else {
                document.getElementById('genDocTitle').innerText = 'Generate Notice of Decision';
                toggleGroup('group_nte', false);
                toggleGroup('group_nod', true);
                document.getElementById('gen_decision').value = btn.getAttribute('data-action');
            }

            new bootstrap.Modal(document.getElementById('genDocModal')).show();
        }

        // Generic SweetAlert Confirmation for Forms
        function confirmAction(e, msg) {
            e.preventDefault();
            const form = e.target;
            Swal.fire({
                title: 'Confirm Action',
                text: msg,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, proceed!'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        }

        // Sync violation fields for NOD (since param name differs)
        document.getElementById('gen_violation').addEventListener('input', function() {
            document.getElementById('gen_violation_hidden').value = this.value;
        });

        // Prevent double-clicks on File Case submission
        function showLoadingSpinner(form) {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Processing...';
            }
            return true;
        }

        // Prevent double-clicks on Generate PDF (resets after 3s because it opens in a new tab)
        function showDocSpinner(form) {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Generating...';
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }, 3000);
            }
            return true;
        }
    </script>
</body>

</html>