<?php
// ======================================================
// [FILE] public/maintenance_log.php
// [PURPOSE] Record hardware maintenance for employees
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Admin Only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

// [AUTO-REPAIR] Check if table exists, create if not
try {
    $pdo->query("SELECT 1 FROM maintenance_logs LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(32) NOT NULL,
        equipment_type VARCHAR(50) NOT NULL,
        issue VARCHAR(255) NOT NULL,
        action_taken TEXT NOT NULL,
        maintenance_date DATE NOT NULL,
        performed_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_emp (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// [AUTO-REPAIR] Ensure vendor_name column exists (Supply Chain Security)
$chkCol = $pdo->query("SHOW COLUMNS FROM maintenance_logs LIKE 'vendor_name'");
if ($chkCol->rowCount() == 0) {
    $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN vendor_name VARCHAR(100) DEFAULT NULL AFTER performed_by");
}

// [NEW] [AUTO-REPAIR] Add status column
try {
    $chkCol = $pdo->query("SHOW COLUMNS FROM maintenance_logs LIKE 'status'");
    if ($chkCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER action_taken");
    }
} catch (PDOException $e) {
    // Ignore if table doesn't exist, it will be created by other check
}

// Ensure audit table exists for recording admin maintenance actions
try {
    $pdo->query("SELECT 1 FROM maintenance_actions LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        target_type VARCHAR(50) NULL,
        target_id VARCHAR(100) NULL,
        details TEXT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        CONSTRAINT fk_maintenance_actions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$logger = new Logger($pdo);
$security = new Security($pdo);
$security->generateCSRF();
$msg = "";
$msgType = "";

// [FIX] Capture message from URL (Post-Redirect-Get)
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'info';
}

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    // Rate limit based on IP
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        http_response_code(429);
        $msg = 'Too many requests. Please wait a moment and try again.';
        $msgType = 'danger';
    } else {
        // CSRF Validation
        try {
            $security->checkCSRF($_POST['csrf_token'] ?? '');
        } catch (Exception $e) {
            $msg = 'CSRF validation failed.';
            $msgType = 'danger';
        }
    }

    if (empty($msg)) {
        // Sanitize inputs
        $data = $security->sanitizeInput($_POST);
        $emp_id = $data['employee_id'] ?? '';
        $equip  = $data['equipment_type'] ?? '';
        $issue  = $data['issue'] ?? '';
        $action = $data['action_taken'] ?? '';
        $date   = $data['maintenance_date'] ?? '';
        $vendor = substr($data['vendor_name'] ?? '', 0, 100);
        $admin_pw = $_POST['admin_password'] ?? ''; // password should not be HTML-escaped for verification

        // Basic validation
        if (!$emp_id || !$equip || !$issue) {
            $msg = 'Please fill in the required fields.';
            $msgType = 'danger';
        }
        // [SECURITY] Length Validation
        elseif (strlen($equip) > 50) {
            $msg = "Equipment type too long (Max 50 chars).";
            $msgType = 'danger';
        } elseif (strlen($issue) > 255) {
            $msg = "Issue description too long (Max 255 chars).";
            $msgType = 'danger';
        } elseif (strlen($action) > 1000) {
            $msg = "Action taken too long (Max 1000 chars).";
            $msgType = 'danger';
        } elseif (strlen($vendor) > 100) {
            $msg = "Vendor name too long (Max 100 chars).";
            $msgType = 'danger';
        }


        // Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            $msg = 'Invalid maintenance date provided.';
            $msgType = 'danger';
        } else {
            $date = $dateObj->format('Y-m-d');
        }

        // Verify employee exists and is active
        if (empty($msg)) {
            $chk = $pdo->prepare("SELECT emp_id FROM employees WHERE emp_id = ? AND status = 'Active'");
            $chk->execute([$emp_id]);
            if (!$chk->fetchColumn()) {
                $msg = 'Selected employee not found or inactive.';
                $msgType = 'danger';
            }
        }

        // Require current user's password to confirm (re-auth)
        if (empty($msg)) {
            $pwStmt = $pdo->prepare("SELECT password, password_changed_at, created_at FROM users WHERE id = ?");
            $pwStmt->execute([$_SESSION['user_id']]);
            $user = $pwStmt->fetch(PDO::FETCH_ASSOC);
            if (!($user && password_verify($admin_pw, $user['password']))) {
                $msg = 'Authentication failed. Please enter your account password to confirm this action.';
                $msgType = 'danger';

                // Log failed attempt to maintenance_actions
                try {
                    $insFail = $pdo->prepare("INSERT INTO maintenance_actions (user_id, action, target_type, details, ip, user_agent) VALUES (?, 'AUTH_FAIL', 'add_maintenance', ?, ?, ?)");
                    $insFail->execute([$_SESSION['user_id'], json_encode(['employee_id' => $emp_id, 'equipment' => $equip]), $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                } catch (Exception $e) {
                    // Ignore audit failures
                }
            } else {
                // [SECURITY] Enforce Password Age (45 Days) for sensitive actions
                $lastChangeDate = $user['password_changed_at'] ?? $user['created_at'];
                if (!$lastChangeDate) {
                    $msg = 'Action blocked: Unable to verify password age. Please contact administrator.';
                    $msgType = 'danger';
                } else {
                    $lastChange = new DateTime($lastChangeDate);
                    $today = new DateTime();
                    if ($today->diff($lastChange)->days > 45) {
                        $msg = 'Action blocked: Your password has expired (older than 45 days). Please change it in Profile Settings.';
                        $msgType = 'danger';
                    }
                }
            }
        }

        $performedBy = $_SESSION['username'] ?? 'Unknown';
        if (empty($msg)) {
            try {
                $pdo->beginTransaction();

                $ins = $pdo->prepare("INSERT INTO maintenance_logs (employee_id, equipment_type, issue, action_taken, maintenance_date, performed_by, vendor_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$emp_id, $equip, $issue, $action, $date, $_SESSION['username'], $vendor]);
                $newId = $pdo->lastInsertId();

                // Audit log
                $details = json_encode(['employee_id' => $emp_id, 'equipment_type' => $equip, 'issue' => $issue, 'action_taken' => $action, 'vendor' => $vendor]);
                $aud = $pdo->prepare("INSERT INTO maintenance_actions (user_id, action, target_type, target_id, details, ip, user_agent) VALUES (?, 'ADD_MAINTENANCE', 'maintenance_log', ?, ?, ?, ?)");
                $aud->execute([$_SESSION['user_id'], $newId, $details, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

                $pdo->commit();

                $logger->log($_SESSION['user_id'], 'MAINTENANCE_LOG', "Recorded maintenance for $emp_id ($equip)");
                header("Location: maintenance_log.php?msg=" . urlencode("✅ Maintenance record added successfully.") . "&type=success");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Error adding maintenance record: ' . $e->getMessage());
                $msg = 'An error occurred while saving the record. Please contact the administrator.';
                $msgType = 'danger';
            }
        }
    }
}

// [NEW] HANDLE EDIT LOG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_log'])) {
    // Rate limit based on IP
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        http_response_code(429);
        $msg = 'Too many requests. Please wait a moment and try again.';
        $msgType = 'danger';
    } else {
        // CSRF Validation
        try {
            $security->checkCSRF($_POST['csrf_token'] ?? '');
        } catch (Exception $e) {
            $msg = 'CSRF validation failed.';
            $msgType = 'danger';
        }
    }

    if (empty($msg)) {
        // Sanitize inputs
        $data = $security->sanitizeInput($_POST);
        $logId  = $data['log_id'] ?? 0;
        $emp_id = $data['employee_id'] ?? '';
        $equip  = $data['equipment_type'] ?? '';
        $issue  = $data['issue'] ?? '';
        $action = $data['action_taken'] ?? '';
        $date   = $data['maintenance_date'] ?? '';
        $status = $data['status'] ?? 'Pending';
        $vendor = substr($data['vendor_name'] ?? '', 0, 100);
        $admin_pw = $_POST['admin_password'] ?? '';

        // Basic validation
        if (!$emp_id || !$equip || !$issue) {
            $msg = 'Please fill in the required fields.';
            $msgType = 'danger';
        } elseif (strlen($equip) > 50) {
            $msg = "Equipment type too long (Max 50 chars).";
            $msgType = 'danger';
        } elseif (strlen($issue) > 255) {
            $msg = "Issue description too long (Max 255 chars).";
            $msgType = 'danger';
        } elseif (strlen($action) > 1000) {
            $msg = "Action taken too long (Max 1000 chars).";
            $msgType = 'danger';
        } elseif (strlen($vendor) > 100) {
            $msg = "Vendor name too long (Max 100 chars).";
            $msgType = 'danger';
        } elseif (!in_array($status, ['Pending', 'Resolved'])) {
            $msg = "Invalid status selected.";
            $msgType = 'danger';
        }

        // Auth Check
        if (empty($msg)) {
            $pwStmt = $pdo->prepare("SELECT password, password_changed_at, created_at FROM users WHERE id = ?");
            $pwStmt->execute([$_SESSION['user_id']]);
            $user = $pwStmt->fetch(PDO::FETCH_ASSOC);
            if (!($user && password_verify($admin_pw, $user['password']))) {
                $msg = 'Authentication failed. Incorrect password.';
                $msgType = 'danger';
            } else {
                // [SECURITY] Enforce Password Age (45 Days) for sensitive actions
                $lastChangeDate = $user['password_changed_at'] ?? $user['created_at'];
                if (!$lastChangeDate) {
                    $msg = 'Action blocked: Unable to verify password age. Please contact administrator.';
                    $msgType = 'danger';
                } else {
                    $lastChange = new DateTime($lastChangeDate);
                    $today = new DateTime();
                    if ($today->diff($lastChange)->days > 45) {
                        $msg = 'Action blocked: Your password has expired (older than 45 days). Please change it in Profile Settings.';
                        $msgType = 'danger';
                    }
                }
            }
        }

        if (empty($msg)) {
            try {
                $pdo->beginTransaction();
                $upd = $pdo->prepare("UPDATE maintenance_logs SET employee_id=?, equipment_type=?, issue=?, action_taken=?, status=?, maintenance_date=?, vendor_name=? WHERE id=?");
                $upd->execute([$emp_id, $equip, $issue, $action, $status, $date, $vendor, $logId]);

                // Audit log
                $details = json_encode(['log_id' => $logId, 'employee_id' => $emp_id, 'equipment' => $equip]);
                $aud = $pdo->prepare("INSERT INTO maintenance_actions (user_id, action, target_type, target_id, details, ip, user_agent) VALUES (?, 'EDIT_MAINTENANCE', 'maintenance_log', ?, ?, ?, ?)");
                $aud->execute([$_SESSION['user_id'], $logId, $details, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

                $pdo->commit();
                $logger->log($_SESSION['user_id'], 'MAINTENANCE_EDIT', "Updated maintenance log ID: $logId");
                header("Location: maintenance_log.php?msg=" . urlencode("✅ Maintenance record updated successfully.") . "&type=success");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                // Log full exception for debugging without exposing details to the user
                error_log('MAINTENANCE_LOG_EDIT_ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                $msg = 'A database error occurred. Please contact support.';
                $msgType = 'danger';
            }
        }
    }
}

// [NEW] HANDLE DELETE LOG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    // Rate limit based on IP
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        http_response_code(429);
        $msg = 'Too many requests. Please wait a moment and try again.';
        $msgType = 'danger';
    } else {
        try {
            $security->checkCSRF($_POST['csrf_token'] ?? '');
        } catch (Exception $e) {
            $msg = 'CSRF validation failed.';
            $msgType = 'danger';
        }
    }

    if (empty($msg)) {
        $logId = filter_var($_POST['log_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($logId === false || $logId <= 0) {
            $msg = 'Invalid log ID.';
            $msgType = 'danger';
        }
    }

    if (empty($msg)) {
        try {
            $stmt = $pdo->prepare("DELETE FROM maintenance_logs WHERE id = ?");
            $stmt->execute([$logId]);

            $logger->log($_SESSION['user_id'], 'MAINTENANCE_DELETE', "Deleted maintenance log ID: $logId");
            header("Location: maintenance_log.php?msg=" . urlencode("🗑️ Record deleted successfully.") . "&type=success");
            exit;
        } catch (Exception $e) {
            error_log('Error deleting maintenance record: ' . $e->getMessage());
            $msg = 'An error occurred while deleting the record.';
            $msgType = 'danger';
        }
    }
}
// 3. FETCH LOGS
$search = Validator::sanitizeSearch($_GET['search'] ?? '');
// [NEW] Date Filters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
// [FIX] Define status filter variable (Logical Error Fix)
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql = "SELECT m.*, e.first_name, e.last_name, e.dept 
        FROM maintenance_logs m 
        LEFT JOIN employees e ON m.employee_id = e.emp_id 
        WHERE 1=1";
$params = [];

if ($search) {
    // [FIX] Improved Search: Added First Name & Issue, and split terms for smarter matching
    $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $sql .= " AND (m.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR m.equipment_type LIKE ? OR m.issue LIKE ?)";
        $t = "%$term%";
        array_push($params, $t, $t, $t, $t, $t);
    }
}

if ($filter_status) {
    $sql .= " AND m.status = ?";
    $params[] = $filter_status;
}

if ($dateFrom) {
    $sql .= " AND m.maintenance_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo) {
    $sql .= " AND m.maintenance_date <= ?";
    $params[] = $dateTo;
}

$sql .= " ORDER BY m.maintenance_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. FETCH AUDIT TRAIL (MHI Requirement: Supply Chain Visibility)
$auditLogs = $pdo->query("SELECT a.*, u.username FROM maintenance_actions a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Employees for Dropdown
$emps = $pdo->query("SELECT emp_id, first_name, last_name FROM employees WHERE status = 'Active' ORDER BY last_name ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Hardware Maintenance Log</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-tools"></i> Hardware Maintenance Log</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert alert-<?php echo htmlspecialchars($msgType ?: 'info'); ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-8">
                <form class="d-flex flex-wrap gap-2 align-items-center" method="GET">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?php echo ($filter_status === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Resolved" <?php echo ($filter_status === 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                    <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" style="max-width: 180px;" maxlength="50">
                    <div class="input-group" style="width: auto;">
                        <span class="input-group-text text-secondary small">From</span>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>" title="Start Date">
                    </div>
                    <div class="input-group" style="width: auto;">
                        <span class="input-group-text text-secondary small">To</span>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>" title="End Date">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                    <?php if ($search || $dateFrom || $dateTo || $filter_status): ?>
                        <a href="maintenance_log.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-outline-dark me-2" data-bs-toggle="modal" data-bs-target="#auditModal">
                    <i class="bi bi-shield-check"></i> Audit Trail
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addLogModal">
                    <i class="bi bi-plus-lg"></i> Record Maintenance
                </button>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Equipment</th>
                            <th>Status</th>
                            <th>Issue / Details</th>
                            <th>Action Taken</th>
                            <th>Tech</th>
                            <th>Vendor/Support</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($log['maintenance_date'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['last_name'] . ', ' . $log['first_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($log['employee_id']); ?></small>
                                </td>
                                <td><span class="badge bg-dark"><?php echo htmlspecialchars($log['equipment_type']); ?></span></td>
                                <td>
                                    <?php
                                    $status_class = $log['status'] === 'Resolved' ? 'bg-success' : 'bg-warning text-dark';
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($log['status']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($log['issue']); ?></td>
                                <td><?php echo htmlspecialchars($log['action_taken']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($log['performed_by']); ?></td>
                                <td class="small text-info"><?php echo htmlspecialchars($log['vendor_name'] ?? 'Internal'); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($log), ENT_QUOTES, 'UTF-8'); ?>)' title="Edit"><i class="bi bi-pencil-square"></i></button>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this log?');" class="d-inline">
                                        <input type="hidden" name="delete_log" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="9" class="text-center p-4 text-muted">No maintenance records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ADD MODAL -->
    <div class="modal fade" id="addLogModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Record Maintenance</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_log" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Employee</label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($emps as $e): ?>
                                <option value="<?php echo $e['emp_id']; ?>"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Equipment</label><input type="text" name="equipment_type" class="form-control" placeholder="e.g. Laptop Dell Latitude" required maxlength="50" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')"></div>
                    <div class="mb-3"><label class="form-label">Issue</label><input type="text" name="issue" class="form-control" placeholder="e.g. Slow performance, Battery replacement" required maxlength="255" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')"></div>
                    <div class="mb-3"><label class="form-label">Action Taken</label><textarea name="action_taken" class="form-control" rows="2" placeholder="e.g. Replaced battery, Re-imaged OS" maxlength="1000"></textarea></div>
                    <div class="mb-3"><label class="form-label">Date</label><input type="date" name="maintenance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="mb-3"><label class="form-label">External Vendor (Optional)</label><input type="text" name="vendor_name" class="form-control" placeholder="e.g. Dell Support, HP Technician" maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')"></div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" name="admin_password" id="add_admin_password" class="form-control" placeholder="Enter your account password to confirm" required maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('add_admin_password')"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text text-muted small"><i class="bi bi-shield-lock"></i> Required for security audit logging.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal fade" id="editLogModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Maintenance Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_log" value="1">
                    <input type="hidden" name="log_id" id="edit_log_id">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Employee</label>
                        <select name="employee_id" id="edit_employee_id" class="form-select" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($emps as $e): ?>
                                <option value="<?php echo $e['emp_id']; ?>"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Equipment</label><input type="text" name="equipment_type" id="edit_equipment_type" class="form-control" required maxlength="50" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')"></div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" id="edit_status" class="form-select" required>
                            <option value="Pending">Pending</option>
                            <option value="Resolved">Resolved</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Issue</label><input type="text" name="issue" id="edit_issue" class="form-control" required maxlength="255" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')"></div>
                    <div class="mb-3"><label class="form-label">Action Taken</label><textarea name="action_taken" id="edit_action_taken" class="form-control" rows="2" maxlength="1000"></textarea></div>
                    <div class="mb-3"><label class="form-label">Date</label><input type="date" name="maintenance_date" id="edit_maintenance_date" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">External Vendor (Optional)</label><input type="text" name="vendor_name" id="edit_vendor_name" class="form-control" maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')"></div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" name="admin_password" id="edit_admin_password" class="form-control" placeholder="Enter your account password to confirm" required maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('edit_admin_password')"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text text-muted small"><i class="bi bi-shield-lock"></i> Required for security audit logging.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- AUDIT TRAIL MODAL (MHI Compliance) -->
    <div class="modal fade" id="auditModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-lock"></i> Supply Chain Audit Log</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 table-responsive">
                    <table class="table table-striped table-sm mb-0 small">
                        <thead class="table-secondary sticky-top">
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details / Target</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td><?php echo date('M d H:i', strtotime($log['created_at'])); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td class="text-muted text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($log['details']); ?>">
                                        <?php echo htmlspecialchars($log['details']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        function openEditModal(data) {
            const modal = new bootstrap.Modal(document.getElementById('editLogModal'));
            document.getElementById('edit_log_id').value = data.id;
            document.getElementById('edit_employee_id').value = data.employee_id;
            document.getElementById('edit_equipment_type').value = data.equipment_type;
            document.getElementById('edit_status').value = data.status;
            document.getElementById('edit_issue').value = data.issue;
            document.getElementById('edit_action_taken').value = data.action_taken;
            document.getElementById('edit_maintenance_date').value = data.maintenance_date;
            document.getElementById('edit_vendor_name').value = data.vendor_name || '';
            document.getElementById('edit_admin_password').value = '';
            modal.show();
        }

        function togglePass(id) {
            const input = document.getElementById(id);
            const icon = input.nextElementSibling.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }
    </script>
    <script src="dark_mode.js"></script>
</body>

</html>