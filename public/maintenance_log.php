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

$logger = new Logger($pdo);
$msg = "";

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $emp_id = $_POST['employee_id'];
    $equip  = $_POST['equipment_type'];
    $issue  = $_POST['issue'];
    $action = $_POST['action_taken'];
    $date   = $_POST['maintenance_date'];
    $by     = $_SESSION['username'];

    if ($emp_id && $equip && $issue) {
        $stmt = $pdo->prepare("INSERT INTO maintenance_logs (employee_id, equipment_type, issue, action_taken, maintenance_date, performed_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$emp_id, $equip, $issue, $action, $date, $by]);

        $logger->log($_SESSION['user_id'], 'MAINTENANCE_LOG', "Recorded maintenance for $emp_id ($equip)");
        $msg = "✅ Maintenance record added successfully.";
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

// Fetch Employees for Dropdown
$emps = $pdo->query("SELECT emp_id, first_name, last_name FROM employees WHERE status = 'Active' ORDER BY last_name ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Hardware Maintenance Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
            <div class="alert alert-success alert-dismissible fade show">
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
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="text-center p-4 text-muted">No maintenance records found.</td>
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
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>