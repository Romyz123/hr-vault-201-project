<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Validator.php';
session_start();

// 1. SECURITY: Only ADMIN and MANAGER can access
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php?error=Access Denied");
    exit;
}

$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

// [NEW] HANDLE MANUAL LOG ENTRY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual_log'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    $action  = strtoupper(trim($_POST['log_action']));
    $details = trim($_POST['log_details']);
    $logDate = $_POST['log_date'];

    if (strlen($action) > 50) die("Action type too long (Max 50 chars)");
    if (strlen($details) > 1000) die("Details too long (Max 1000 chars)");

    if ($action && $details && $logDate) {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $action, $details, $_SERVER['REMOTE_ADDR'], $logDate]);
        header("Location: activity_logs.php?msg=Manual log entry added");
        exit;
    }
}

// [NEW] HANDLE ARCHIVING (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_logs']) && $_SESSION['role'] === 'ADMIN') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    try {
        // 1. Create Archive Table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs_archive LIKE activity_logs");

        // 2. Define Cutoff (1 Year Ago)
        $cutoff = date('Y-m-d H:i:s', strtotime('-1 year'));

        // 3. Move & Delete
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO activity_logs_archive SELECT * FROM activity_logs WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        $count = $stmt->rowCount();

        $pdo->prepare("DELETE FROM activity_logs WHERE created_at < ?")->execute([$cutoff]);
        $pdo->commit();

        header("Location: activity_logs.php?msg=" . urlencode("✅ Archived $count logs older than 1 year."));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: activity_logs.php?error=" . urlencode("Archive Failed: " . $e->getMessage()));
        exit;
    }
}

// [NEW] HANDLE CLEAR OLD LOGS (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_old_logs']) && $_SESSION['role'] === 'ADMIN') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    try {
        // Define Cutoff (30 Days Ago)
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        $count = $stmt->rowCount();

        header("Location: activity_logs.php?msg=" . urlencode("✅ Cleared $count logs older than 30 days."));
        exit;
    } catch (Exception $e) {
        header("Location: activity_logs.php?error=" . urlencode("Clear Failed: " . $e->getMessage()));
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
$search = Validator::sanitizeSearch($_GET['search'] ?? '');
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';

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
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
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

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-shield-lock-fill text-danger"></i> System Activity Logs</span>
        </div>
    </nav>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_GET['msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>
                // [FIX] Clear URL parameters to prevent message from reappearing on refresh
                if (window.history.replaceState) {
                    window.history.replaceState(null, null, window.location.pathname);
                }
            </script>
        <?php endif; ?>
        <div class="d-flex justify-content-end align-items-center mb-4">
            <div>
                <?php if ($_SESSION['role'] === 'ADMIN'): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('This will move logs older than 1 year to the archive table. Proceed?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="archive_logs" value="1">
                        <button type="submit" class="btn btn-outline-secondary me-2"><i class="bi bi-archive-fill"></i> Archive Old</button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return confirm('This will PERMANENTLY DELETE logs older than 30 days. This cannot be undone. Proceed?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="clear_old_logs" value="1">
                        <button type="submit" class="btn btn-outline-danger me-2"><i class="bi bi-trash3-fill"></i> Clear >30 Days</button>
                    </form>
                <?php endif; ?>
                <button class="btn btn-warning me-2" data-bs-toggle="modal" data-bs-target="#manualLogModal"><i class="bi bi-pencil-square"></i> Add Note</button>
                <span class="badge bg-white text-dark border me-2"><i class="bi bi-clock"></i> Server Time: <?php echo date('H:i'); ?></span>
                <a href="settings.php" class="btn btn-outline-dark me-2"><i class="bi bi-gear-fill"></i> Settings</a>
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
                        <input type="text" name="search" class="form-control" placeholder="Search logs (e.g. 'delete', 'admin', 'medical')..." value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores">
                    </div>
                    <div class="col-md-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light">From</span>
                            <input type="datetime-local" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date, ENT_QUOTES); ?>">
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
            <div class="card-body p-0 table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-nowrap">Date & Time</th>
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
                                    <td class="text-muted small text-nowrap">
                                        <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['created_at']))); ?>
                                    </td>
                                    <td class="fw-bold">
                                        <?php echo htmlspecialchars($log['username'] ?? 'Unknown (ID:' . $log['user_id'] . ')'); ?>
                                        <span class="badge bg-secondary ms-1" style="font-size:0.6rem"><?php echo $log['role'] ?? '?'; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $class; ?> border">
                                            <?php echo htmlspecialchars($actionVal, ENT_QUOTES); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars($log['ip_address'], ENT_QUOTES); ?></td>
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
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white py-3">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">Previous</a>
                            </li>
                            <?php
                            $range = 2;
                            $start = max(1, $page - $range);
                            $end   = min($totalPages, $page + $range);

                            if ($start > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?page=1&search=' . urlencode($search) . '&start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date) . '">1</a></li>';
                                if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }

                            for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor;

                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . '&search=' . urlencode($search) . '&start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date) . '">' . $totalPages . '</a></li>';
                            }
                            ?>
                            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="add_manual_log" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Action Type</label>
                        <input type="text" name="log_action" class="form-control" list="action_suggestions" placeholder="e.g. SYSTEM_RESTORE" required maxlength="50" pattern="[A-Z0-9_]+">
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
                        <textarea name="log_details" class="form-control" rows="3" placeholder="Describe what happened..." required maxlength="1000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Add Entry</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>