<?php
// ======================================================
// [FILE] public/activity_logs.php
// [PURPOSE] Immutable Security Audit Trail & Event Logs
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// --- MANAGEMENT ACTIONS ---
// [REMOVED] delete_log action to maintain audit integrity.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $security = new Security($pdo);
    try {
        $security->checkCSRF($_POST['csrf_token'] ?? '');
    } catch (Exception $e) {
        http_response_code(403);
        exit('Forbidden');
    }
    if ($_POST['action'] === 'archive_logs') {
        // This moves older data to a secondary storage table if needed, 
        // but keeps the records in the system for compliance.
        $stmt = $pdo->prepare("INSERT INTO activity_logs_archive SELECT * FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
        $stmt->execute();
        $_SESSION['msg'] = "Logs older than 1 year have been moved to archives.";
    }
    header("Location: activity_logs.php");
    exit;
}

// FILTERS & PAGINATION
$filter    = $_GET['filter'] ?? 'ALL';
$period    = $_GET['period'] ?? 'ALL';
$search    = trim($_GET['search'] ?? '');
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

// Period Logic
if ($period === 'DAILY') {
    $where[] = "DATE(a.created_at) = CURDATE()";
} elseif ($period === 'MONTHLY') {
    $where[] = "MONTH(a.created_at) = MONTH(CURRENT_DATE()) AND YEAR(a.created_at) = YEAR(CURRENT_DATE())";
}

// New Detailed Category Logic
if ($filter === 'SECURITY') {
    $where[] = "a.action IN ('LOGIN_SUCCESS', 'LOGIN_FAILED', 'ACCOUNT_LOCKOUT', 'PASSWORD_RESET', 'USER_UNLOCK')";
} elseif ($filter === 'EDITS') {
    // Specifically for modifications and new entries
    $where[] = "(a.action LIKE '%ADD%' OR a.action LIKE '%EDIT%' OR a.action LIKE '%UPDATE%' OR a.action LIKE '%CHANGE%')";
} elseif ($filter === 'DOCUMENTATION') {
    // Specifically for generated files, reports, and COE
    $where[] = "(a.action LIKE '%GENERATE%' OR a.action LIKE '%PRINT%' OR a.action LIKE '%REPORT%' OR a.action LIKE '%COE%')";
} elseif ($filter === 'VAULT') {
    $where[] = "(a.action LIKE '%VAULT%' OR a.action LIKE '%FILE%')";
}

// Search with 100 char limit validation
if ($search !== '') {
    $search = substr(preg_replace('/[^a-zA-Z0-9\-_ ,]/', '', $search), 0, 100);
    $where[] = "(u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ?)";
    $t = "%$search%";
    array_push($params, $t, $t, $t);
}

if ($dateFrom) {
    $where[] = "a.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = "a.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

// Fetch
$stmt = $pdo->prepare("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRows = $pdo->prepare("SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql");
$totalRows->execute($params);
$totalRows = $totalRows->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
?>

<?php require 'header.php'; ?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 text-dark fw-bold"><i class="bi bi-journal-text text-primary me-2"></i>Audit Trail</h4>
            <small class="text-muted">Tracking all system events for TESP HR Vault</small>
        </div>
        <div class="d-flex gap-2">
            <button onclick="exportCSV()" class="btn btn-outline-success btn-sm border-0 shadow-sm"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
            <button onclick="window.print()" class="btn btn-outline-danger btn-sm border-0 shadow-sm"><i class="bi bi-file-earmark-pdf"></i> PDF Report</button>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-body bg-white rounded-3">
            <form class="row g-3 align-items-center" method="GET">
                <div class="col-md-auto">
                    <div class="btn-group btn-group-sm shadow-sm">
                        <a href="?period=ALL" class="btn btn-<?php echo $period == 'ALL' ? 'primary' : 'light'; ?>">All Time</a>
                        <a href="?period=DAILY" class="btn btn-<?php echo $period == 'DAILY' ? 'primary' : 'light'; ?>">Today</a>
                        <a href="?period=MONTHLY" class="btn btn-<?php echo $period == 'MONTHLY' ? 'primary' : 'light'; ?>">This Month</a>
                    </div>
                </div>

                <div class="col-md-2">
                    <select name="filter" class="form-select form-select-sm border-0 bg-light" onchange="this.form.submit()">
                        <option value="ALL">All Categories</option>
                        <option value="SECURITY" <?php echo $filter == 'SECURITY' ? 'selected' : ''; ?>>Security & Logins</option>
                        <option value="EDITS" <?php echo $filter == 'EDITS' ? 'selected' : ''; ?>>Data Entry & Edits</option>
                        <option value="DOCUMENTATION" <?php echo $filter == 'DOCUMENTATION' ? 'selected' : ''; ?>>Document Generation</option>
                        <option value="VAULT" <?php echo $filter == 'VAULT' ? 'selected' : ''; ?>>Vault Access</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <div class="input-group input-group-sm border rounded bg-light">
                        <input type="date" name="date_from" class="form-control border-0 bg-transparent" value="<?php echo $dateFrom; ?>">
                        <span class="input-group-text bg-transparent border-0 small text-muted">to</span>
                        <input type="date" name="date_to" class="form-control border-0 bg-transparent" value="<?php echo $dateTo; ?>">
                    </div>
                </div>

                <div class="col-md-3 ms-auto">
                    <div class="position-relative">
                        <input type="text" name="search" id="searchInput" class="form-control form-control-sm ps-4 border-0 bg-light"
                            placeholder="Search keyword..." value="<?php echo htmlspecialchars($search); ?>">
                        <i class="bi bi-search position-absolute start-0 top-50 translate-middle-y ms-2 text-muted small"></i>
                        <small id="charCount" class="position-absolute end-0 top-50 translate-middle-y me-2 text-muted" style="font-size: 0.65rem;">0/100</small>
                    </div>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm px-4">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0" id="logsTable">
                <thead class="bg-light text-muted small uppercase">
                    <tr>
                        <th class="ps-4">Date & Time</th>
                        <th>User Account</th>
                        <th>Event Action</th>
                        <th>Description / Details</th>
                        <th class="text-center">IP Address</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">No audit records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="ps-4 text-nowrap">
                                    <span class="text-dark fw-bold"><?php echo date('M d, Y', strtotime($log['created_at'])); ?></span><br>
                                    <span class="text-muted"><?php echo date('h:i A', strtotime($log['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px; font-size: 0.7rem;">
                                            <i class="bi bi-person"></i>
                                        </div>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($log['username'] ?? 'SYSTEM'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-opacity-10 border <?php echo getLogBadge($log['action']); ?> px-2 py-1 fw-normal">
                                        <?php echo str_replace('_', ' ', $log['action']); ?>
                                    </span>
                                </td>
                                <td class="text-muted" style="max-width: 450px;"><?php echo htmlspecialchars($log['details']); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark fw-normal border"><?php echo $log['ip_address']; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $qs = $_GET; // Current filters

                // Previous
                $qs['page'] = max(1, $page - 1);
                $prevUrl = '?' . http_build_query($qs);
                echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link shadow-sm" href="' . $prevUrl . '">&laquo; Prev</a></li>';

                // Numbers (Windowed)
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++) {
                    $qs['page'] = $i;
                    $url = '?' . http_build_query($qs);
                    $active = ($page == $i) ? 'active' : '';
                    echo '<li class="page-item ' . $active . '"><a class="page-link shadow-sm" href="' . $url . '">' . $i . '</a></li>';
                }

                // Next
                $qs['page'] = min($totalPages, $page + 1);
                $nextUrl = '?' . http_build_query($qs);
                echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link shadow-sm" href="' . $nextUrl . '">Next &raquo;</a></li>';
                ?>
            </ul>
            <p class="text-center text-muted small mt-2">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRows); ?> total records)</p>
        </nav>
    <?php endif; ?>
</div>

<script>
    // Search character counter and validation
    const searchInput = document.getElementById('searchInput');
    const charCount = document.getElementById('charCount');

    searchInput.addEventListener('input', function() {
        let val = this.value;
        if (val.length > 100) this.value = val.substring(0, 100);
        charCount.textContent = `${this.value.length}/100`;
        // Validation: Strips illegal characters for SQL safety
        this.value = this.value.replace(/[^a-zA-Z0-9\-_ ,]/g, '');
    });

    function exportCSV() {
        let table = document.getElementById("logsTable");
        let rows = Array.from(table.querySelectorAll("tr"));
        let csvContent = rows.map(row => {
            return Array.from(row.querySelectorAll("th, td")).map(cell => `"${cell.innerText.replace(/"/g, '""')}"`).join(",");
        }).join("\n");

        let blob = new Blob([csvContent], {
            type: "text/csv;charset=utf-8;"
        });
        let link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = "TESP_AuditTrail_<?php echo date('Y-m-d'); ?>.csv";
        link.click();
    }
</script>
<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/dark_mode.js"></script>
</body>

</html>
<?php
function getLogBadge($action)
{
    if (strpos($action, 'FAIL') !== false || strpos($action, 'LOCK') !== false) return 'bg-danger text-danger border-danger';
    if (strpos($action, 'EDIT') !== false || strpos($action, 'UPDATE') !== false) return 'bg-warning text-warning border-warning';
    if (strpos($action, 'ADD') !== false || strpos($action, 'GENERATE') !== false) return 'bg-success text-success border-success';
    if (strpos($action, 'LOGIN') !== false) return 'bg-primary text-primary border-primary';
    return 'bg-secondary text-secondary border-secondary';
}
?>