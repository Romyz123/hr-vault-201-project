<?php
// public/access_review.php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

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

    // Cast and sanitize inputs
    $targetId = (int)($_POST['user_id'] ?? 0);
    $status   = $_POST['status'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');

    // Validate target ID
    if ($targetId <= 0) {
        die("Invalid user specified.");
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $check->execute([$targetId]);
    if ($check->fetchColumn() == 0) {
        die("User not found.");
    }

    // Validate status against explicit whitelist
    $allowed = ['Confirmed', 'Revoked'];
    if (!in_array($status, $allowed, true)) {
        die("Invalid status value.");
    }

    // Log the review
    $stmt = $pdo->prepare("INSERT INTO access_reviews (reviewed_user_id, reviewer_id, review_date, status, notes) VALUES (?, ?, CURDATE(), ?, ?)");
    $stmt->execute([$targetId, $_SESSION['user_id'], $status, $notes]);

    // If revoked, downgrade user to STAFF
    if ($status === 'Revoked') {
        // Prevent self-revocation
        if ($targetId == $_SESSION['user_id']) {
            die("Cannot revoke your own access");
        }
        // Ensure at least one admin remains
        $adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn();
        $targetRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $targetRole->execute([$targetId]);
        if ($targetRole->fetchColumn() === 'ADMIN' && $adminCount <= 1) {
            die("Cannot revoke the last admin");
        }
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

// HANDLE INVENTORY VERIFICATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_inventory'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) die("Invalid CSRF");

    $targetId = (int)$_POST['user_id'];
    if ($targetId <= 0) {
        die("Invalid user specified.");
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $check->execute([$targetId]);
    if ($check->fetchColumn() == 0) {
        die("User not found.");
    }

    $pdo->prepare("UPDATE users SET last_verified_at = NOW() WHERE id = ?")->execute([$targetId]);

    $logger->log($_SESSION['user_id'], 'INVENTORY_VERIFY', "Verified business need for User ID $targetId");
    header("Location: access_review.php?tab=inventory&msg=" . urlencode("✅ User Verified"));
    exit;
}
// FETCH PRIVILEGED USERS (Admin/Manager)
$sql = "SELECT u.id, u.username, u.role, u.email, 
        (SELECT MAX(review_date) FROM access_reviews ar WHERE ar.reviewed_user_id = u.id) as last_review
        FROM users u 
        WHERE u.role IN ('ADMIN', 'MANAGER') 
        ORDER BY u.role ASC, u.username ASC";
$users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// FETCH ALL USERS FOR INVENTORY
$allUsers = $pdo->query("SELECT id, username, role, email, last_verified_at, is_shared, account_owner FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeTab = $_GET['tab'] ?? 'privileged';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Quarterly Access Review</title>
    <link rel="icon" href="uploads/tesp-logo.png?v=3" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-shield-check"></i> Quarterly Access Review</span>
                <span class="navbar-text text-white-50 ms-3 font-monospace small"><i class="bi bi-clock"></i> <span id="sessionTimer"></span></span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?php echo $activeTab == 'privileged' ? 'active' : ''; ?>" href="?tab=privileged">Privileged Access (Quarterly)</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $activeTab == 'inventory' ? 'active' : ''; ?>" href="?tab=inventory">Inventory Clearance (Annual)</a></li>
        </ul>

        <?php if ($activeTab == 'privileged'): ?>
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
                                        <div class="modal-content">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="review">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Review Access: <?php echo htmlspecialchars($u['username']); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Does this user still require <strong><?php echo htmlspecialchars($u['role']); ?></strong> access?</p>
                                                    <textarea name="notes" class="form-control mb-3" placeholder="Optional notes..."></textarea>
                                                </div>
                                                <div class="modal-footer d-flex gap-2">
                                                    <button type="submit" name="status" value="Confirmed" class="btn btn-success flex-grow-1">Confirm Access</button>
                                                    <button type="submit" name="status" value="Revoked" class="btn btn-outline-danger" onclick="return confirm('This will downgrade the user to STAFF. Continue?')">Revoke</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <!-- INVENTORY CLEARANCE TAB -->
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Annual User Inventory</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Last Verified</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allUsers as $u):
                                $lastVer = $u['last_verified_at'];
                                $days = $lastVer ? (time() - strtotime($lastVer)) / (60 * 60 * 24) : 999;
                                $status = ($days > 365) ? '<span class="text-danger fw-bold">Needs Verification</span>' : '<span class="text-success">Verified</span>';
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                                        <?php if ($u['is_shared']) echo "<br><small class='text-muted'>Owner: " . htmlspecialchars($u['account_owner']) . "</small>"; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['role']); ?></td>
                                    <td><?php echo $lastVer ? date('M d, Y', strtotime($lastVer)) : 'Never'; ?></td>
                                    <td><?php echo $status; ?></td>
                                    <td class="text-end">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" name="verify_inventory" class="btn btn-sm btn-outline-primary">Verify Need</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        // [SECURITY] Auto-Logout Timer
        const timeoutDuration = <?php echo $clientTimeout * 1000; ?>;
        let timeLeft = timeoutDuration;

        function updateTimer() {
            timeLeft -= 1000;
            if (timeLeft <= 0) window.location.href = 'logout.php';
            const m = Math.floor(timeLeft / 60000);
            const s = Math.floor((timeLeft % 60000) / 1000);
            document.getElementById('sessionTimer').innerText = `${m}:${s.toString().padStart(2, '0')}`;
        }
        document.addEventListener('mousemove', () => timeLeft = timeoutDuration);
        document.addEventListener('keypress', () => timeLeft = timeoutDuration);
        setInterval(updateTimer, 1000);
        updateTimer();
    </script>
    <script src="dark_mode.js"></script>
</body>

</html>