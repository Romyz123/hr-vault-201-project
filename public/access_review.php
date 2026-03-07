<?php
// public/access_review.php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$logger = new Logger($pdo);
$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

// HANDLE REVIEW ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    $targetId = $_POST['user_id'];
    $status   = $_POST['status']; // 'Confirmed' or 'Revoked'
    $notes    = trim($_POST['notes']);

    // Log the review
    $stmt = $pdo->prepare("INSERT INTO access_reviews (reviewed_user_id, reviewer_id, review_date, status, notes) VALUES (?, ?, CURDATE(), ?, ?)");
    $stmt->execute([$targetId, $_SESSION['user_id'], $status, $notes]);

    // If revoked, downgrade user to STAFF
    if ($status === 'Revoked') {
        $pdo->prepare("UPDATE users SET role = 'STAFF' WHERE id = ?")->execute([$targetId]);
        $logger->log($_SESSION['user_id'], 'ACCESS_REVOKED', "Revoked privileges for User ID $targetId");
        $msg = "✅ Access Revoked. User downgraded to Staff.";
    } else {
        $logger->log($_SESSION['user_id'], 'ACCESS_CONFIRMED', "Confirmed privileges for User ID $targetId");
        $msg = "✅ Access Confirmed.";
    }

    header("Location: access_review.php?msg=" . urlencode($msg));
    exit;
}

// FETCH PRIVILEGED USERS (Admin/Manager)
$sql = "SELECT u.id, u.username, u.role, u.email, 
        (SELECT MAX(review_date) FROM access_reviews ar WHERE ar.reviewed_user_id = u.id) as last_review
        FROM users u 
        WHERE u.role IN ('ADMIN', 'MANAGER') 
        ORDER BY u.role ASC, u.username ASC";
$users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Quarterly Access Review</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-shield-check"></i> Quarterly Access Review</span>
        </div>
    </nav>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Privileged User Audit</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Last Review</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $lastReview = $u['last_review'];
                            $daysSince = $lastReview ? (time() - strtotime($lastReview)) / (60 * 60 * 24) : 999;
                            $statusClass = ($daysSince > 90) ? 'text-danger fw-bold' : 'text-success';
                            $statusText = ($daysSince > 90) ? 'Review Overdue' : 'Compliant';
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($u['username']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($u['email']); ?></small>
                                </td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['role']); ?></span></td>
                                <td><?php echo $lastReview ? date('M d, Y', strtotime($lastReview)) : 'Never'; ?></td>
                                <td class="<?php echo $statusClass; ?>"><?php echo $statusText; ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $u['id']; ?>">
                                        <i class="bi bi-check-lg"></i> Review
                                    </button>
                                </td>
                            </tr>

                            <!-- Modal -->
                            <div class="modal fade" id="reviewModal<?php echo $u['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <form method="POST" class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Review Access: <?php echo htmlspecialchars($u['username']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <p>Does this user still require <strong><?php echo htmlspecialchars($u['role']); ?></strong> access?</p>
                                            <textarea name="notes" class="form-control mb-3" placeholder="Optional notes..." required></textarea>
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="status" value="Confirmed" name="action" class="btn btn-success flex-grow-1">Confirm Access</button>
                                                <button type="submit" name="status" value="Revoked" name="action" class="btn btn-outline-danger" onclick="return confirm('This will downgrade the user to STAFF. Continue?')">Revoke</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>