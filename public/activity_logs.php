<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// SECURITY: Only Admin/HR can view logs
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'ADMIN' && $_SESSION['role'] !== 'HR')) {
    header("Location: index.php");
    exit;
}

// FETCH LOGS (Join with Users table to see WHO did it)
// We limit to the last 100 events to keep it fast
$sql = "SELECT logs.*, users.username, users.role 
        FROM activity_logs AS logs 
        JOIN users ON logs.user_id = users.id 
        ORDER BY logs.created_at DESC 
        LIMIT 100";
$stmt = $pdo->query($sql);
$logs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="bi bi-shield-lock-fill text-danger"></i> Audit Trail / Activity Logs</h3>
        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <div class="card shadow">
        <div class="card-body p-0">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): foreach ($logs as $log): ?>
                    <tr>
                        <td class="small text-muted"><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($log['username']); ?></td>
                        <td>
                            <span class="badge <?php echo ($log['role'] === 'ADMIN') ? 'bg-danger' : 'bg-primary'; ?>">
                                <?php echo $log['role']; ?>
                            </span>
                        </td>
                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($log['action']); ?></td>
                        <td><?php echo htmlspecialchars($log['details']); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">No activity recorded yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="text-center mt-3 text-muted small">Showing last 100 actions</div>
</div>

</body>
</html>