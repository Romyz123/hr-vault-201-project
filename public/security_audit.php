<?php
// ======================================================
// [FILE] public/security_audit.php
// [PURPOSE] Monitor failed logins and security events
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: ADMIN ONLY
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

// 2. FETCH SECURITY LOGS
// We filter for specific action types related to security
$sql = "SELECT a.*, u.username 
        FROM activity_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        WHERE a.action IN ('LOGIN_FAILED', 'ACCESS_DENIED', 'SECURITY_VIOLATION', 'Rate Limit Exceeded') 
        ORDER BY a.created_at DESC 
        LIMIT 100";
$logs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 3. STATS
$failCount = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN_FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Security Audit Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-danger mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Dashboard</a>
            <span class="navbar-text text-white fw-bold"><i class="bi bi-shield-exclamation"></i> Security Audit</span>
        </div>
    </nav>

    <div class="container">

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-start border-4 border-danger">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase small">Failed Logins (24h)</h6>
                        <h2 class="fw-bold text-danger mb-0"><?php echo $failCount; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="alert alert-warning h-100 d-flex align-items-center">
                    <div>
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Security Tip:</strong> If you see multiple failed attempts from the same IP address, consider blocking it in your firewall or server config.
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-list-ul"></i> Recent Security Events
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Time</th>
                            <th>IP Address</th>
                            <th>Action</th>
                            <th>User (If Known)</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center p-4 text-muted">✅ No security incidents found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log):
                                $badge = 'bg-secondary';
                                if ($log['action'] === 'LOGIN_FAILED') $badge = 'bg-danger';
                                if ($log['action'] === 'ACCESS_DENIED') $badge = 'bg-warning text-dark';
                            ?>
                                <tr>
                                    <td class="small text-muted"><?php echo date('M d, H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td class="font-monospace small"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                    <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                    <td>
                                        <?php if ($log['user_id'] > 0): ?>
                                            <span class="fw-bold"><?php echo htmlspecialchars($log['username']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-danger small fw-bold"><?php echo htmlspecialchars($log['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</body>

</html>