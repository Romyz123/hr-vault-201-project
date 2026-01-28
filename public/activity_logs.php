<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Only ADMIN and MANAGER can access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php?error=Access Denied");
    exit;
}

// [NEW] HANDLE MANUAL LOG ENTRY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual_log'])) {
    $action  = strtoupper(trim($_POST['log_action']));
    $details = trim($_POST['log_details']);
    $logDate = $_POST['log_date'];

    if ($action && $details && $logDate) {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $action, $details, $_SERVER['REMOTE_ADDR'], $logDate]);
        header("Location: activity_logs.php?msg=Manual log entry added");
        exit;
    }
}

// 2. DASHBOARD STATS (Visual Cards)
$todayCount = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$monthCount = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")->fetchColumn();
$topUserStmt = $pdo->query("SELECT u.username, COUNT(*) as c FROM activity_logs a JOIN users u ON a.user_id = u.id GROUP BY a.user_id ORDER BY c DESC LIMIT 1");
$topUser = $topUserStmt->fetch(PDO::FETCH_ASSOC);
$topUserName = $topUser ? $topUser['username'] : 'N/A';

// 3. PAGINATION, SEARCH & FILTER LOGIC
$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';

// [SECURITY] Limit & Sanitize Search
if (strlen($search) > 50) $search = substr($search, 0, 50);
$search = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $search);

$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build Query
$conditions = [];
$params = [];

if (!empty($search)) {
    $conditions[] = "(u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ?)";
    $term = "%$search%";
    array_push($params, $term, $term, $term);
}

if (!empty($start_date) && !empty($end_date)) {
    // [FIX] Support specific Time filtering if 'T' is present (datetime-local format)
    if (strlen($start_date) > 10 || strlen($end_date) > 10) {
        $conditions[] = "a.created_at BETWEEN ? AND ?";

        // Normalize format (remove T) and ensure seconds are covered
        $s = str_replace('T', ' ', $start_date);
        $e = str_replace('T', ' ', $end_date);
        if (strlen($s) <= 16) $s .= ':00';
        if (strlen($e) <= 16) $e .= ':59';

        array_push($params, $s, $e);
    } else {
        $conditions[] = "DATE(a.created_at) BETWEEN ? AND ?";
        array_push($params, $start_date, $end_date);
    }
}

$whereSQL = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// 4. HANDLE EXPORT (CSV)
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Audit_Logs_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');

    // BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date Time', 'User', 'Role', 'Action', 'Details', 'IP Address']);

    $sqlExport = "SELECT a.*, u.username, u.role FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id $whereSQL ORDER BY a.created_at DESC";
    $stmtExport = $pdo->prepare($sqlExport);
    $stmtExport->execute($params);

    while ($row = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['created_at'],
            $row['username'] ?? 'Unknown (ID:' . $row['user_id'] . ')',
            $row['role'] ?? 'N/A',
            $row['action'] ?? $row['action_type'] ?? 'UNKNOWN',
            $row['details'],
            $row['ip_address']
        ]);
    }
    fclose($out);
    exit;
}

// Fetch Total Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM activity_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    $whereSQL
");
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Fetch Logs
$sql = "
    SELECT a.*, u.username, u.role 
    FROM activity_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    $whereSQL
    ORDER BY a.created_at DESC 
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Activity Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .badge-upload {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .badge-delete {
            background-color: #f8d7da;
            color: #842029;
        }

        .badge-login {
            background-color: #cfe2ff;
            color: #084298;
        }

        .badge-other {
            background-color: #e2e3e5;
            color: #41464b;
        }

        .badge-print {
            background-color: #fff3cd;
            color: #856404;
        }
    </style>
</head>

<body class="bg-light p-4">

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_GET['msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-shield-lock-fill text-danger"></i> System Activity Logs</h2>
            <div>
                <button class="btn btn-warning me-2" data-bs-toggle="modal" data-bs-target="#manualLogModal"><i class="bi bi-pencil-square"></i> Add Note</button>
                <span class="badge bg-white text-dark border me-2"><i class="bi bi-clock"></i> Server Time: <?php echo date('H:i'); ?></span>
                <a href="settings.php" class="btn btn-outline-dark me-2"><i class="bi bi-gear-fill"></i> Settings</a>
                <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <!-- Visual Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-primary border-start border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase mb-1">Activities Today</h6>
                                <h2 class="fw-bold text-primary mb-0"><?php echo number_format($todayCount); ?></h2>
                            </div>
                            <div class="fs-1 text-primary opacity-25"><i class="bi bi-calendar-check"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-success border-start border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase mb-1">This Month</h6>
                                <h2 class="fw-bold text-success mb-0"><?php echo number_format($monthCount); ?></h2>
                            </div>
                            <div class="fs-1 text-success opacity-25"><i class="bi bi-graph-up-arrow"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-warning border-start border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase mb-1">Top Contributor</h6>
                                <h2 class="fw-bold text-warning mb-0"><?php echo htmlspecialchars($topUserName); ?></h2>
                            </div>
                            <div class="fs-1 text-warning opacity-25"><i class="bi bi-trophy-fill"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search logs (e.g. 'delete', 'admin', 'medical')..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores">
                    </div>
                    <div class="col-md-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light">From</span>
                            <input type="datetime-local" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light">To</span>
                            <input type="datetime-local" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex gap-1">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                        <?php if (!empty($search) || !empty($start_date)): ?>
                            <a href="activity_logs.php" class="btn btn-outline-secondary w-100" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                        <button type="submit" name="export" value="1" class="btn btn-success w-100" title="Export CSV"><i class="bi bi-download"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow">
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Date & Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($logs) > 0): ?>
                            <?php foreach ($logs as $log):
                                // FIX 2: Changed '$log['action_type']' to '$log['action']'
                                // Use a fallback '??' just in case
                                $actionVal = $log['action'] ?? $log['action_type'] ?? 'UNKNOWN';

                                // Determine Color
                                $type = strtoupper($actionVal);
                                $class = 'badge-other';
                                if (strpos($type, 'UPLOAD') !== false) $class = 'badge-upload';
                                if (strpos($type, 'DELETE') !== false) $class = 'badge-delete';
                                if (strpos($type, 'LOGIN') !== false)  $class = 'badge-login';
                                if (strpos($type, 'LOGOUT') !== false) $class = 'bg-secondary text-white';
                                if (strpos($type, 'GENERATE') !== false || strpos($type, 'PRINT') !== false) $class = 'badge-print';
                                if (strpos($type, 'SETTINGS') !== false) $class = 'bg-warning text-dark';
                            ?>
                                <tr>
                                    <td class="text-muted small" style="width: 180px;">
                                        <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td class="fw-bold">
                                        <?php echo htmlspecialchars($log['username'] ?? 'Unknown (ID:' . $log['user_id'] . ')'); ?>
                                        <span class="badge bg-secondary ms-1" style="font-size:0.6rem"><?php echo $log['role'] ?? '?'; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $class; ?> border">
                                            <?php echo htmlspecialchars($actionVal); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center p-4">No logs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </div>

    <!-- MANUAL LOG MODAL -->
    <div class="modal fade" id="manualLogModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-journal-plus"></i> Add Manual Log Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small">
                        Use this to document offline actions (e.g., "Restored DB via phpMyAdmin") or system events that weren't captured automatically.
                    </div>
                    <input type="hidden" name="add_manual_log" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Action Type</label>
                        <input type="text" name="log_action" class="form-control" list="action_suggestions" placeholder="e.g. SYSTEM_RESTORE" required>
                        <datalist id="action_suggestions">
                            <option value="SYSTEM_RESTORE">
                            <option value="MANUAL_FIX">
                            <option value="DATA_CORRECTION">
                            <option value="OFFLINE_MAINTENANCE">
                        </datalist>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Date & Time of Event</label>
                        <input type="datetime-local" name="log_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Details</label>
                        <textarea name="log_details" class="form-control" rows="3" placeholder="Describe what happened..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Add Entry</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>