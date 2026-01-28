<?php
// ======================================================
// [FILE] public/manager_dashboard.php
// [PURPOSE] Specialized Dashboard for Managers
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Only ADMIN and MANAGER
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// 2. FETCH KEY METRICS

// A. Pending Approvals
$reqStmt = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'PENDING'");
$pendingCount = $reqStmt->fetchColumn();

// B. Expiring Documents (Next 30 Days)
$expDate = date('Y-m-d', strtotime('+30 days'));
$expStmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE expiry_date <= ? AND is_resolved = 0 AND deleted_at IS NULL");
$expStmt->execute([$expDate]);
$expiringCount = $expStmt->fetchColumn();

// C. Headcount Stats
$activeCount = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();
$probationCount = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active' AND employment_type = 'TESP Direct' AND DATEDIFF(NOW(), hire_date) < 180")->fetchColumn();

// D. Recent Activities (Last 5)
$logStmt = $pdo->query("SELECT a.*, u.username FROM activity_logs a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 5");
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

// E. Recent Uploads (Last 5) - [WIDGET]
$uploadStmt = $pdo->query("
    SELECT d.original_name, d.category, d.uploaded_at, e.first_name, e.last_name, d.file_uuid 
    FROM documents d 
    LEFT JOIN employees e ON d.employee_id = e.emp_id 
    WHERE d.deleted_at IS NULL
    ORDER BY d.uploaded_at DESC 
    LIMIT 5
");
$recentUploads = $uploadStmt->fetchAll(PDO::FETCH_ASSOC);

// F. Failed Logins (Last 24h) - [ADMIN ONLY]
$failedLogins = 0;
if (($_SESSION['role'] ?? '') === 'ADMIN') {
    $failStmt = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN_FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $failedLogins = $failStmt->fetchColumn();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manager Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-primary mb-4 shadow">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-speedometer2"></i> Manager Command Center</a>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-house-door-fill"></i> Main Dashboard</a>
                <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">

        <!-- WELCOME BANNER -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm bg-white">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-1 text-primary">Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h4>
                            <p class="text-muted mb-0">Here is what requires your attention today.</p>
                        </div>
                        <div class="text-end">
                            <h2 class="fw-bold mb-0"><?php echo date('j'); ?></h2>
                            <span class="text-uppercase small text-muted"><?php echo date('F Y'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ACTION CARDS -->
        <div class="row g-4 mb-4">
            <!-- Approvals -->
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-start border-4 border-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-muted text-uppercase mb-0">Pending Requests</h6>
                            <div class="icon-shape bg-warning text-white rounded-circle p-2">
                                <i class="bi bi-inbox-fill"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-3"><?php echo $pendingCount; ?></h2>
                        <a href="admin_approval.php" class="btn btn-sm btn-outline-warning w-100">Review Requests</a>
                    </div>
                </div>
            </div>

            <!-- Expirations -->
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-start border-4 border-danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-muted text-uppercase mb-0">Expiring Docs</h6>
                            <div class="icon-shape bg-danger text-white rounded-circle p-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-3"><?php echo $expiringCount; ?></h2>
                        <a href="expiry_report.php" class="btn btn-sm btn-outline-danger w-100">View Forecast</a>
                    </div>
                </div>
            </div>

            <!-- Headcount -->
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-start border-4 border-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-muted text-uppercase mb-0">Active Workforce</h6>
                            <div class="icon-shape bg-success text-white rounded-circle p-2">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold mb-0"><?php echo $activeCount; ?></h2>
                        <small class="text-muted"><?php echo $probationCount; ?> Probationary</small>
                        <a href="analytics.php" class="btn btn-sm btn-outline-success w-100 mt-3">View Analytics</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- SYSTEM HEALTH WIDGET (ADMIN ONLY) -->
        <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm border-start border-4 border-danger">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase mb-1">System Health Alert</h6>
                                <div class="d-flex align-items-center">
                                    <h2 class="fw-bold text-danger mb-0 me-2"><?php echo $failedLogins; ?></h2>
                                    <span class="text-muted">Failed Login Attempts (Last 24h)</span>
                                </div>
                            </div>
                            <a href="security_audit.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-shield-check"></i> View Security Audit</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- RECENT UPLOADS WIDGET -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-bold">
                        <i class="bi bi-cloud-arrow-up"></i> Recent Uploads
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>File</th>
                                    <th>Employee</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentUploads)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center p-3 text-muted">No recent uploads.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentUploads as $up): ?>
                                        <tr>
                                            <td>
                                                <a href="view_doc.php?id=<?php echo $up['file_uuid']; ?>" target="_blank" class="text-decoration-none fw-bold text-dark">
                                                    <i class="bi bi-file-earmark-text text-secondary"></i> <?php echo htmlspecialchars($up['original_name']); ?>
                                                </a>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($up['category']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($up['first_name'] . ' ' . $up['last_name']); ?></td>
                                            <td class="small text-muted"><?php echo date('M d', strtotime($up['uploaded_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- QUICK LINKS -->
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-bold"><i class="bi bi-lightning-charge"></i> Management Tools</div>
                    <div class="card-body d-flex gap-3 flex-wrap">
                        <a href="add_employee.php" class="btn btn-outline-success"><i class="bi bi-person-plus-fill me-2"></i> Add Employee</a>
                        <a href="import_employees.php" class="btn btn-outline-success"><i class="bi bi-file-spreadsheet me-2"></i> Bulk Import</a>
                        <a href="tracker.php" class="btn btn-outline-info"><i class="bi bi-kanban me-2"></i> Compliance Tracker</a>
                        <a href="maintenance_log.php" class="btn btn-outline-dark"><i class="bi bi-tools me-2"></i> Maintenance Log</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>