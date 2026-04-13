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
$rawSearch = $search; // Preserve unsanitized version for display
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$invalidSearchAttempt = false;

// Fetch Summary Statistics
$statsToday = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
$statsMonth = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn() ?: 0;

$topContributor = ['name' => 'System', 'count' => 0];
try {
    $topStmt = $pdo->query("SELECT u.username, COUNT(a.id) as log_count 
                            FROM activity_logs a 
                            JOIN users u ON a.user_id = u.id 
                            GROUP BY a.user_id 
                            ORDER BY log_count DESC LIMIT 1");
    $rowTop = $topStmt->fetch();
    if ($rowTop) {
        $topContributor = ['name' => $rowTop['username'], 'count' => $rowTop['log_count']];
    }
} catch (Exception $e) {
}

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
if (strlen($search) > 50) {
    // Enforce character limit
    $search = substr($search, 0, 50);
    $invalidSearchAttempt = true;
}
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
$sql = "SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);

$paramIndex = 1;
foreach ($params as $val) {
    $stmt->bindValue($paramIndex++, $val);
}
$stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php require 'header.php'; ?>

<style>
    .log-navbar {
        background: #1a1d20;
        border-bottom: 3px solid var(--accent);
    }

    .stat-card {
        border: none;
        border-radius: 15px;
        transition: transform 0.2s;
    }

    .stat-card:hover {
        transform: translateY(-5px);
    }

    .badge-user-delete {
        background: #f8d7da;
        color: #842029;
        border: 1px solid #f5c2c7;
    }

    .badge-login {
        background: #cfe2ff;
        color: #084298;
        border: 1px solid #b6d4fe;
    }

    .badge-logout {
        background: #e2e3e5;
        color: #41464b;
        border: 1px solid #d3d6d8;
    }

    .badge-user-edit {
        background: #fff3cd;
        color: #664d03;
        border: 1px solid #ffecb5;
    }
</style>

<!-- [NAVBAR] -->
<nav class="navbar navbar-expand-lg log-navbar mb-4 shadow-sm">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center">
            <h5 class="text-white mb-0"><i class="bi bi-shield-fill-exclamation text-danger me-2"></i>System Activity Logs</h5>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-info"><i class="bi bi-archive"></i> Archive Old</button>
            <form method="POST" class="m-0" onsubmit="return confirm('Purge all logs older than 3 years?')">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            </form>
            <div class="btn btn-sm btn-dark border-secondary"><i class="bi bi-clock"></i> <?= date('H:i') ?></div>
            <a href="settings.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> Settings</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <!-- [SUMMARY CARDS] -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card shadow-soft bg-primary text-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase opacity-75">Activities Today</small>
                        <h2 class="mb-0 fw-bold"><?= number_format($statsToday) ?></h2>
                    </div>
                    <i class="bi bi-lightning-charge fs-1 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card shadow-soft bg-dark text-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase opacity-75">This Month</small>
                        <h2 class="mb-0 fw-bold"><?= number_format($statsMonth) ?></h2>
                    </div>
                    <i class="bi bi-calendar-check fs-1 opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card shadow-soft bg-success text-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-uppercase opacity-75">Top Contributor</small>
                        <h4 class="mb-0 fw-bold"><?= h($topContributor['name']) ?></h4>
                        <small class="opacity-75"><?= $topContributor['count'] ?> actions logged</small>
                    </div>
                    <i class="bi bi-trophy-fill fs-1 opacity-25"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- [SEARCH & EXPORT BAR] -->
    <div class="card shadow-soft rounded-3 mb-4">
        <div class="card-body bg-light rounded-3 py-3">
            <form class="row g-3 align-items-end" method="GET">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Quick Search <span id="searchCharCount" class="text-muted">(0/50)</span></label>
                    <input type="text" name="search" id="quickSearchInput" class="form-control border-0 shadow-sm" placeholder="e.g. John Doe or LOGIN" value="<?= h($rawSearch) ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ,]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Commas" oninput="updateSearchCharCount(this)">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">Category</label>
                    <select name="filter" class="form-select border-0 shadow-sm">
                        <option value="ALL">All Activity</option>
                        <option value="SECURITY" <?= $filter === 'SECURITY' ? 'selected' : '' ?>>🛡️ Security</option>
                        <option value="LOGINS" <?= $filter === 'LOGINS' ? 'selected' : '' ?>>🔑 Logins</option>
                        <option value="SYSTEM" <?= $filter === 'SYSTEM' ? 'selected' : '' ?>>⚙️ System</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Date Range</label>
                    <div class="input-group shadow-sm rounded">
                        <input type="date" name="date_from" class="form-control border-0" value="<?= h($dateFrom) ?>">
                        <span class="input-group-text bg-white border-0 text-muted small">to</span>
                        <input type="date" name="date_to" class="form-control border-0" value="<?= h($dateTo) ?>">
                    </div>
                </div>
                <div class="col-md-auto">
                    <div class="input-group">
                        <button type="submit" class="btn btn-primary px-4 shadow-sm"><i class="bi bi-search"></i> Search</button>
                        <button type="submit" formaction="export_activity_pdf.php" formtarget="_blank" class="btn btn-outline-danger shadow-sm" title="Download professional Audit Report with summary statistics"><i class="bi bi-file-earmark-pdf"></i> Audit Report</button>
                        <?php if ($search !== '' || $filter !== 'ALL' || $dateFrom !== '' || $dateTo !== ''): ?>
                            <a href="activity_logs.php" class="btn btn-outline-secondary border-0 border-start border-end shadow-sm d-flex align-items-center" title="Clear all filters and reset view"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset</a>
                        <?php endif; ?>

                        <ul class="dropdown-menu dropdown-menu-end shadow p-3" style="width: 200px;">
                            <li>
                            </li>
                            <li>
                                <p class="small text-muted mb-2 text-wrap">Download filtered logs.</p>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><button type="submit" formaction="export_activity_excel.php" class="dropdown-item"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Save as Excel</button></li>
                            <li><button type="submit" formaction="export_activity_pdf.php" formtarget="_blank" class="dropdown-item"><i class="bi bi-file-earmark-pdf text-danger me-2"></i> Print / PDF</button></li>
                        </ul>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- [MAIN DATA TABLE] -->
    <div class="card shadow-soft border-0 rounded-3 overflow-hidden">
        <div class="card-body p-0">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-4">Timestamp</th>
                        <th>Authorized User</th>
                        <th>Event Action</th>
                        <th width="40%">Event Details</th>
                        <th class="text-end pe-4">IP Address</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    <?php if (empty($logs)): ?><tr>
                            <td colspan="5" class="text-center py-5 text-muted">No matching logs found.</td>
                        </tr><?php else: ?>
                        <?php foreach ($logs as $log):
                                    $badge = match (true) {
                                        str_contains($log['action'], 'DELETE') => 'badge-user-delete',
                                        str_contains($log['action'], 'LOGIN')  => 'badge-login',
                                        str_contains($log['action'], 'LOGOUT') => 'badge-logout',
                                        str_contains($log['action'], 'EDIT')   => 'badge-user-edit',
                                        default => 'bg-secondary text-white'
                                    };
                        ?>
                            <tr>
                                <td class="ps-4 small text-muted"><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
                                <td>
                                    <span class="fw-bold"><?= h($log['username'] ?? 'Guest/System') ?></span>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?= $badge ?> px-3"><?= h(str_replace('_', ' ', $log['action'])) ?></span>
                                </td>
                                <td class="small text-secondary"><?= h($log['details']) ?></td>
                                <td>
                                    <div class="text-end pe-4 font-monospace extra-small text-muted"><?= h($log['ip_address'] ?? '0.0.0.0') ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- [PAGINATION] -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php
                $qs = $_GET;
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
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

<script>
    function updateSearchCharCount(input) {
        const countSpan = document.getElementById('searchCharCount');
        const maxLength = input.maxLength;
        const currentLength = input.value.length;

        if (countSpan) {
            countSpan.textContent = `(${currentLength}/${maxLength})`;
            if (currentLength >= maxLength) {
                countSpan.classList.replace('text-muted', 'text-danger');
            } else {
                countSpan.classList.replace('text-danger', 'text-muted');
            }
        }
    }

    // [UX STABILIZATION] Scroll Memory Helper
    // Prevents the page from jumping to the top after an action (e.g. search, pagination)
    const scrollKey = 'hr201_scroll_pos_' + window.location.pathname;
    window.addEventListener('beforeunload', () => {
        sessionStorage.setItem(scrollKey, window.scrollY);
    });
    const urlParamsForScroll = new URLSearchParams(window.location.search);
    if (urlParamsForScroll.has('msg') || urlParamsForScroll.has('error') || urlParamsForScroll.has('page') || urlParamsForScroll.has('filter') || urlParamsForScroll.has('search')) {
        const savedPos = sessionStorage.getItem(scrollKey);
        if (savedPos) window.scrollTo(0, parseInt(savedPos));
    }

    document.addEventListener("DOMContentLoaded", function() {
        const input = document.getElementById('quickSearchInput');
        if (input) updateSearchCharCount(input);

        <?php if ($invalidSearchAttempt): ?>
            Swal.fire({
                icon: 'error',
                title: 'Invalid Search Input',
                text: 'Special characters are restricted in activity search for security reasons.',
                confirmButtonColor: '#dc3545'
            });
        <?php endif; ?>
    });
</script>

<script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>
<?php // --- END: System Activity Logs Fix --- 
?>