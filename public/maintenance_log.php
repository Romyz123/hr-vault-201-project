<?php
// ======================================================
// [FILE] public/maintenance_log.php
// [PURPOSE] Record hardware maintenance for employees
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Admin & Manager Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
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
                $lastChange = new DateTime($user['password_changed_at'] ?? $user['created_at']);
                $today = new DateTime();
                if ($today->diff($lastChange)->days > 45) {
                    $msg = 'Action blocked: Your password has expired (older than 45 days). Please change it in Profile Settings.';
                    $msgType = 'danger';
                }
            }
        }

        // If all checks pass, insert record inside a transaction and write an audit entry
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
                $msg = '✅ Maintenance record added successfully.';
                $msgType = 'success';
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Error adding maintenance record: ' . $e->getMessage());
                $msg = 'An error occurred while saving the record. Please contact the administrator.';
                $msgType = 'danger';
            }
        }
    }
}

// 3. FETCH LOGS
$search = $_GET['search'] ?? '';
$sql = "SELECT m.*, e.first_name, e.last_name, e.dept 
        FROM maintenance_logs m 
        LEFT JOIN employees e ON m.employee_id = e.emp_id 
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (m.employee_id LIKE ? OR e.last_name LIKE ? OR m.equipment_type LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term];
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
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="manager_dashboard.php">⬅ Manager Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-tools"></i> Hardware Maintenance Log</span>
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
                <form class="d-flex gap-2">
                    <input type="text" name="search" class="form-control" placeholder="Search Employee or Equipment..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
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
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Employee</th>
                            <th>Equipment</th>
                            <th>Issue / Details</th>
                            <th>Action Taken</th>
                            <th>Tech</th>
                            <th>Vendor/Support</th>
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
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($log['equipment_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($log['issue']); ?></td>
                                <td><?php echo htmlspecialchars($log['action_taken']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($log['performed_by']); ?></td>
                                <td class="small text-info"><?php echo htmlspecialchars($log['vendor_name'] ?? 'Internal'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center p-4 text-muted">No maintenance records found.</td>
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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? $security->generateCSRF()); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Employee</label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($emps as $e): ?>
                                <option value="<?php echo $e['emp_id']; ?>"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Equipment</label><input type="text" name="equipment_type" class="form-control" placeholder="e.g. Laptop Dell Latitude" required></div>
                    <div class="mb-3"><label class="form-label">Issue</label><input type="text" name="issue" class="form-control" placeholder="e.g. Slow performance, Battery replacement" required></div>
                    <div class="mb-3"><label class="form-label">Action Taken</label><textarea name="action_taken" class="form-control" rows="2" placeholder="e.g. Replaced battery, Re-imaged OS"></textarea></div>
                    <div class="mb-3"><label class="form-label">Date</label><input type="date" name="maintenance_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="mb-3"><label class="form-label">External Vendor (Optional)</label><input type="text" name="vendor_name" class="form-control" placeholder="e.g. Dell Support, HP Technician"></div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="admin_password" class="form-control" placeholder="Enter your account password to confirm" required>
                        <div class="form-text text-muted small"><i class="bi bi-shield-lock"></i> Required for security audit logging.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save Record</button>
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
                <div class="modal-body p-0">
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
</body>

</html>