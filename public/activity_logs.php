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
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

function isValidDate($date)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    list($year, $month, $day) = explode('-', $date);
    return checkdate((int)$month, (int)$day, (int)$year);
}

if (!isValidDate($dateFrom)) {
    $dateFrom = '';
}
if (!isValidDate($dateTo)) {
    $dateTo = '';
}

// [SECURITY] Sanitize search input and escape SQL LIKE wildcards
$search = preg_replace('/[^a-zA-Z0-9\-_ ,]/', '', $search);
$search = str_replace(['%', '_'], ['\\%', '\\_'], $search);

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
} elseif ($filter === 'GENERATED') {
    $where[] = "a.action IN ('AUTO_SAVE_COPY', 'GENERATE_DOC', 'BULK_PRINT', 'AUTO_CASE_FILE')";
}

// [NEW] Date Range Filter logic
if ($dateFrom) {
    $where[] = "a.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = "a.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
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
<?php require 'header.php'; ?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0 text-dark fw-bold"><i class="bi bi-shield-check text-primary me-2"></i>System Activity Logs</h4>
    </div>

    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-body bg-light rounded-3 py-3">
            <form class="row g-3 align-items-center" method="GET">
                <div class="col-md-auto fw-bold text-muted">
                    Filter Logs:
                </div>
                <div class="col-md-3">
                    <select name="filter" class="form-select border-0 shadow-sm" onchange="this.form.submit()">
                        <option value="ALL" <?php echo $filter === 'ALL' ? 'selected' : ''; ?>>All Activity</option>
                        <option value="SECURITY" <?php echo $filter === 'SECURITY' ? 'selected' : ''; ?>>🛡️ Security & Access Events</option>
                        <option value="LOGINS" <?php echo $filter === 'LOGINS' ? 'selected' : ''; ?>>🔑 Logins & Logouts</option>
                        <option value="DOCUMENTS" <?php echo $filter === 'DOCUMENTS' ? 'selected' : ''; ?>>📄 Document Edits</option>
                        <option value="SYSTEM" <?php echo $filter === 'SYSTEM' ? 'selected' : ''; ?>>⚙️ System & Backups</option>
                        <option value="GENERATED" <?php echo $filter === 'GENERATED' ? 'selected' : ''; ?>>📂 View Saved Copies</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm shadow-sm rounded">
                        <span class="input-group-text bg-white border-0 text-muted"><i class="bi bi-calendar-range"></i></span>
                        <input type="date" name="date_from" class="form-control border-0" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        <span class="input-group-text bg-white border-0 text-muted">to</span>
                        <input type="date" name="date_to" class="form-control border-0" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                </div>
                <div class="col-md-auto ms-auto">
                    <div class="input-group shadow-sm rounded">
                        <span class="input-group-text bg-white border-0 text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control border-0" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>" maxlength="100" pattern="[a-zA-Z0-9\-_ ,]+" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ ,]/g, '')">
                        <button type="submit" class="btn btn-primary px-3 border-0">Search</button>
                        <?php if ($search || $dateFrom || $dateTo || $filter !== 'ALL'): ?>
                            <a href="activity_logs.php" class="btn btn-light text-danger border-0" title="Reset Filters"><i class="bi bi-x-circle-fill"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 text-sm">
                <thead class="table-light text-muted" style="border-bottom: 2px solid #e9ecef;">
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action Type</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-light"></i>
                                No activity logs found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log):
                            // UI UPGRADE: Modern Soft Badges
                            $actionColor = 'bg-secondary bg-opacity-10 text-secondary border border-secondary';
                            if (strpos($log['action'], 'DELETE') !== false || strpos($log['action'], 'FAIL') !== false || strpos($log['action'], 'LOCK') !== false) {
                                $actionColor = 'bg-danger bg-opacity-10 text-danger border border-danger';
                            } elseif (strpos($log['action'], 'EDIT') !== false || strpos($log['action'], 'UPDATE') !== false || strpos($log['action'], 'RESET') !== false) {
                                $actionColor = 'bg-warning bg-opacity-10 text-warning border border-warning';
                            } elseif (strpos($log['action'], 'ADD') !== false || strpos($log['action'], 'SUCCESS') !== false || strpos($log['action'], 'UNLOCK') !== false) {
                                $actionColor = 'bg-success bg-opacity-10 text-success border border-success';
                            } elseif (strpos($log['action'], 'LOGIN') !== false) {
                                $actionColor = 'bg-primary bg-opacity-10 text-primary border border-primary';
                            }
                        ?>
                            <tr>
                                <td class="px-4 text-nowrap text-muted" style="font-size: 0.9rem;">
                                    <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                                    <span class="ms-1 fw-bold"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-primary fw-bold me-2" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                            <?php echo strtoupper(substr($log['username'] ?? 'S', 0, 1)); ?>
                                        </div>
                                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($log['username'] ?? 'System / Guest'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?php echo $actionColor; ?> px-3 py-2 fw-normal" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                        <?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?>
                                    </span>
                                </td>
                                <td class="text-wrap text-break text-secondary" style="max-width: 400px; font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($log['details']); ?>
                                </td>
                                <td class="px-4 text-end">
                                    <span class="font-monospace text-muted bg-light px-2 py-1 rounded" style="font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-4 d-flex justify-content-center">
            <ul class="pagination pagination-sm shadow-sm">
                <?php
                $qs = $_GET;

                // Previous Button
                $qs['page'] = max(1, $page - 1);
                $prevDisabled = ($page <= 1) ? 'disabled' : '';
                echo '<li class="page-item ' . $prevDisabled . '"><a class="page-link px-3 text-dark border-0" href="?' . http_build_query($qs) . '" aria-label="Previous"><i class="bi bi-chevron-left"></i></a></li>';

                $start = max(1, $page - 3);
                $end = min($totalPages, $page + 3);
                for ($i = $start; $i <= $end; $i++) {
                    $qs['page'] = $i;
                    $active = ($page == $i) ? 'active' : '';
                    echo '<li class="page-item ' . $active . '"><a class="page-link px-3 text-dark border-0" href="?' . http_build_query($qs) . '">' . $i . '</a></li>';
                }

                // Next Button
                $qs['page'] = min($totalPages, $page + 1);
                $nextDisabled = ($page >= $totalPages) ? 'disabled' : '';
                echo '<li class="page-item ' . $nextDisabled . '"><a class="page-link px-3 text-dark border-0" href="?' . http_build_query($qs) . '" aria-label="Next"><i class="bi bi-chevron-right"></i></a></li>';
                ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>
<script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>