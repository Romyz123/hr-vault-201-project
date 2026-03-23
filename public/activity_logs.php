<?php
// ======================================================
// [FILE] public/activity_logs.php
// [PURPOSE] Security Audit Trail & System Event Logs
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Admin & Manager Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// 2. FILTERS & PAGINATION
$filter = $_GET['filter'] ?? 'ALL';
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($filter === 'SECURITY') {
    $where[] = "a.action IN ('ADMIN_PASSWORD_RESET', 'USER_UNLOCK', 'LOGIN_LOCKED', 'ACCOUNT_LOCKOUT', 'USER_RESET_2FA', 'LOGIN_FAILED', 'LOGIN_FAIL_2FA', 'OTP_FAIL_LIMIT')";
} elseif ($filter === 'LOGINS') {
    $where[] = "(a.action LIKE 'LOGIN%' OR a.action = 'LOGOUT')";
} elseif ($filter === 'DOCUMENTS') {
    $where[] = "(a.action LIKE '%DOC%' OR a.action LIKE '%VAULT%' OR a.action LIKE '%FILE%')";
} elseif ($filter === 'SYSTEM') {
    $where[] = "(a.action LIKE '%BACKUP%' OR a.action LIKE '%SETTINGS%')";
}

if ($search !== '') {
    $where[] = "(u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)";
    $t = "%$search%";
    array_push($params, $t, $t, $t, $t);
}

$whereSql = implode(' AND ', $where);

// Count Total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Fetch Data
$sql = "SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Audit Trail - HR System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="assets/images/tesp-logo-1.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
            <span class="navbar-text text-white fw-bold"><i class="bi bi-shield-lock-fill text-danger"></i> System Audit Trail</span>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="card shadow-sm mb-4">
            <div class="card-body py-3">
                <form class="row g-2 align-items-center" method="GET">
                    <div class="col-auto fw-bold">Filter By:</div>
                    <div class="col-auto">
                        <select name="filter" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="ALL" <?php echo $filter === 'ALL' ? 'selected' : ''; ?>>All Activity</option>
                            <option value="SECURITY" <?php echo $filter === 'SECURITY' ? 'selected' : ''; ?>>🛡️ Security & Access Events</option>
                            <option value="LOGINS" <?php echo $filter === 'LOGINS' ? 'selected' : ''; ?>>🔑 Logins & Logouts</option>
                            <option value="DOCUMENTS" <?php echo $filter === 'DOCUMENTS' ? 'selected' : ''; ?>>📄 Document Edits</option>
                            <option value="SYSTEM" <?php echo $filter === 'SYSTEM' ? 'selected' : ''; ?>>⚙️ System & Backups</option>
                        </select>
                    </div>
                    <div class="col-auto ms-auto">
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" class="form-control" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle small">
                    <thead class="table-dark">
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action Type</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No activity logs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log):
                                $actionColor = 'bg-secondary';
                                if (strpos($log['action'], 'DELETE') !== false || strpos($log['action'], 'FAIL') !== false || strpos($log['action'], 'LOCK') !== false) $actionColor = 'bg-danger';
                                elseif (strpos($log['action'], 'EDIT') !== false || strpos($log['action'], 'UPDATE') !== false || strpos($log['action'], 'RESET') !== false) $actionColor = 'bg-warning text-dark';
                                elseif (strpos($log['action'], 'ADD') !== false || strpos($log['action'], 'SUCCESS') !== false || strpos($log['action'], 'UNLOCK') !== false) $actionColor = 'bg-success';
                                elseif (strpos($log['action'], 'LOGIN') !== false) $actionColor = 'bg-primary';
                            ?>
                                <tr>
                                    <td class="text-nowrap text-muted"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($log['username'] ?? 'System / Guest'); ?></td>
                                    <td><span class="badge <?php echo $actionColor; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                    <td class="text-wrap text-break" style="max-width: 400px;"><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td class="font-monospace text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php
                    $qs = $_GET;
                    $start = max(1, $page - 3);
                    $end = min($totalPages, $page + 3);
                    for ($i = $start; $i <= $end; $i++) {
                        $qs['page'] = $i;
                        $active = ($page == $i) ? 'active' : '';
                        echo '<li class="page-item ' . $active . '"><a class="page-link" href="?' . http_build_query($qs) . '">' . $i . '</a></li>';
                    }
                    ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
</body>

</html>