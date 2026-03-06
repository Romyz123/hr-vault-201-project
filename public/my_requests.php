<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Validator.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Pagination Settings
$perPage = 10; // Number of requests per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page > 1) ? ($page * $perPage) - $perPage : 0;
$userId = $_SESSION['user_id'];
$search = Validator::sanitizeSearch($_GET['search'] ?? '');
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';


// [1] Get total items
$countSql = "SELECT COUNT(*) FROM requests WHERE user_id = :uid";
if ($search) {
    $countSql .= " AND (request_type LIKE :search OR created_at LIKE :search2)";
}
if ($statusFilter) {
    $countSql .= " AND status = :status";
}
$countStmt = $pdo->prepare($countSql);
$countStmt->bindValue(':uid', $userId);
if ($search) {
    $countStmt->bindValue(':search', "%$search%");
    $countStmt->bindValue(':search2', "%$search%");
}
if ($statusFilter) {
    $countStmt->bindValue(':status', $statusFilter);
}
$countStmt->execute();
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

// [2] Fetch Limited Data
$sql = "SELECT * FROM requests WHERE user_id = :uid";
if ($search) {
    $sql .= " AND (request_type LIKE :search OR created_at LIKE :search2)";
}
if ($statusFilter) {
    $sql .= " AND status = :status";
}
$sql .= " ORDER BY created_at DESC LIMIT :start, :perPage";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':uid', $userId);
if ($search) {
    $stmt->bindValue(':search', "%$search%");
    $stmt->bindValue(':search2', "%$search%");
}
if ($statusFilter) {
    $stmt->bindValue(':status', $statusFilter);
}
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Requests</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        body {
            background: #f4f6f9;
        }

        .status-PENDING {
            background-color: #ffc107;
            color: #000;
        }

        .status-APPROVED {
            background-color: #198754;
            color: #fff;
        }

        .status-REJECTED {
            background-color: #dc3545;
            color: #fff;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
            <span class="navbar-text text-white">My Request History</span>
        </div>
    </nav>

    <div class="container">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Submitted Requests</h5>
            </div>
            <div class="card-body border-bottom p-3">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-auto">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="PENDING" <?php echo ($statusFilter === 'PENDING') ? 'selected' : ''; ?>>Pending</option>
                            <option value="APPROVED" <?php echo ($statusFilter === 'APPROVED') ? 'selected' : ''; ?>>Approved</option>
                            <option value="REJECTED" <?php echo ($statusFilter === 'REJECTED') ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-auto flex-grow-1">
                        <input type="text" name="search" class="form-control" placeholder="Search by Type or Date (YYYY-MM-DD)..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                        <?php if ($search || $statusFilter): ?><a href="my_requests.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Admin Comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No requests found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $r):
                                    $payload = json_decode($r['json_payload'], true);
                                    $details = "";
                                    if ($r['request_type'] === 'ADD_EMPLOYEE') {
                                        $details = "Add: " . htmlspecialchars($payload['first_name'] . ' ' . $payload['last_name']);
                                    } elseif ($r['request_type'] === 'EDIT_PROFILE') {
                                        $details = "Edit Profile (ID: " . htmlspecialchars($r['target_id']) . ")";
                                    }

                                    // Add note if present
                                    if (!empty($payload['request_note'])) {
                                        $details .= "<br><small class='text-muted'>Note: " . htmlspecialchars($payload['request_note']) . "</small>";
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($r['created_at']))); ?></td>
                                        <td>
                                            <?php if ($r['request_type'] === 'ADD_EMPLOYEE'): ?>
                                                <span class="badge bg-primary">New Hire</span>
                                            <?php else: ?>
                                                <span class="badge bg-info text-dark">Profile Update</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $details; ?></td>
                                        <td>
                                            <span class="badge status-<?php echo htmlspecialchars($r['status']); ?>">
                                                <?php echo htmlspecialchars($r['status']); ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted fst-italic">
                                            <?php echo htmlspecialchars($r['admin_comment'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php if ($total > $perPage): ?>
        <nav aria-label="Request pagination">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php if ($page <= 1) {
                                            echo 'disabled';
                                        } ?>">
                    <a class="page-link" href="?page=<?php echo htmlspecialchars($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item <?php if ($page == $i) {
                                                echo 'active';
                                            } ?>">
                        <a class="page-link" href="?page=<?php echo htmlspecialchars($i); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>"> <?php echo htmlspecialchars($i); ?> </a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php if ($page >= $pages) {
                                            echo 'disabled';
                                        } ?>">
                    <a class="page-link" href="?page=<?php echo htmlspecialchars($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($statusFilter); ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>






</body>

</html>