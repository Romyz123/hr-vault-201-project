<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: index.php?error=Access Denied");
    exit;
}

// 2. PAGINATION & SEARCH LOGIC
$search = $_GET['search'] ?? '';
// [SECURITY] Limit & Sanitize Search
if (strlen($search) > 50) $search = substr($search, 0, 50);
$search = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $search);

$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build Query
$whereSQL = "";
$params = [];

if (!empty($search)) {
    // FIX 1: Changed 'a.action_type' to 'a.action'
    $whereSQL = "WHERE u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ?";
    $term = "%$search%";
    $params = [$term, $term, $term];
}

// Fetch Total Count
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM activity_logs a 
    JOIN users u ON a.user_id = u.id 
    $whereSQL
");
$countStmt->execute($params);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

// Fetch Logs
$sql = "
    SELECT a.*, u.username, u.role 
    FROM activity_logs a 
    JOIN users u ON a.user_id = u.id 
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
        .badge-upload { background-color: #d1e7dd; color: #0f5132; }
        .badge-delete { background-color: #f8d7da; color: #842029; }
        .badge-login  { background-color: #cfe2ff; color: #084298; }
        .badge-other  { background-color: #e2e3e5; color: #41464b; }
    </style>
</head>
<body class="bg-light p-4">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-shield-lock-fill text-danger"></i> System Activity Logs</h2>
        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-10">
                    <input type="text" name="search" class="form-control" placeholder="Search logs (e.g. 'delete', 'admin', 'medical')..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
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
                        ?>
                        <tr>
                            <td class="text-muted small" style="width: 180px;">
                                <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                            </td>
                            <td class="fw-bold">
                                <?php echo htmlspecialchars($log['username']); ?>
                                <span class="badge bg-secondary ms-1" style="font-size:0.6rem"><?php echo $log['role']; ?></span>
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
                        <tr><td colspan="5" class="text-center p-4">No logs found.</td></tr>
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
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

</div>

</body>
</html>