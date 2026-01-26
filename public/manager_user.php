<?php
// ======================================================
// [FILE] public/manager_user.php
// [STATUS] MERGED: Disaster Recovery + Phase 2 Security
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    $_SESSION['error'] = "Access Denied: Admin privileges required.";
    header("Location: index.php");
    exit;
}

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$logger = new Logger($pdo);
$alertType = "";
$alertMsg = "";

// Capture session errors passed from redirects
if (isset($_SESSION['error'])) {
    $alertType = 'error'; $alertMsg = $_SESSION['error'];
    unset($_SESSION['error']);
}

// 2. HANDLE ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // [SECURITY] Verify CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: Invalid Token. Please refresh the page.");
    }
    
    // --- ADD NEW USER ---
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $username = trim($_POST['username']);
        $email    = trim($_POST['email']);
        $password = $_POST['password'];
        $role     = $_POST['role'];

        // [PHASE 2 SECURITY] Strong Password Check
        if (strlen($password) < 12) {
            $alertType = 'error'; $alertMsg = "❌ Password too short! Must be at least 12 characters.";
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $alertType = 'error'; $alertMsg = "❌ Username must be alphanumeric (letters & numbers only).";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $alertType = 'error'; $alertMsg = "❌ Password must contain at least one number.";
        } elseif (!preg_match('/[\W_]/', $password)) {
            $alertType = 'error'; $alertMsg = "❌ Password must contain at least one symbol (!@#$%).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $alertType = 'error'; $alertMsg = "❌ Invalid email format.";
        } else {
            // Check Duplicate
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            
            if ($check->rowCount() > 0) {
                $alertType = 'warning'; $alertMsg = "⚠️ Username or Email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$username, $email, $hashed, $role])) {
                    $logger->log($_SESSION['user_id'], 'USER_ADD', "Created user: $username ($role)");
                    $alertType = 'success'; $alertMsg = "✅ User '$username' created successfully!";
                }
            }
        }
    }

    // --- EDIT USER (Reset Password / Change Role) ---
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id       = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email    = trim($_POST['email']);
        $role     = $_POST['role'];
        $new_pass = $_POST['password']; // Optional

        // Check email uniqueness (ignore self)
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $id]);
        
        if ($chk->rowCount() > 0) {
            $alertType = 'error'; $alertMsg = "❌ Email '$email' is already taken by another user.";
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $alertType = 'error'; $alertMsg = "❌ Username must be alphanumeric (letters & numbers only).";
        } else {
            // Update Info
            $sql = "UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?";
            $params = [$username, $email, $role, $id];
            
            // If password changed, validate and hash it
            if (!empty($new_pass)) {
                if (strlen($new_pass) < 12 || !preg_match('/[0-9]/', $new_pass) || !preg_match('/[\W_]/', $new_pass)) {
                    $alertType = 'error'; $alertMsg = "❌ Update Failed: New password is too weak (Min 12 chars, 1 number, 1 symbol).";
                } else {
                    $sql = "UPDATE users SET username = ?, email = ?, role = ?, password = ? WHERE id = ?";
                    $params = [$username, $email, $role, password_hash($new_pass, PASSWORD_BCRYPT), $id];
                }
            }
            
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                $logger->log($_SESSION['user_id'], 'USER_EDIT', "Updated User ID: $id");
                $alertType = 'success'; $alertMsg = "✅ User details updated!";
            }
        }
    }

    // --- DELETE USER ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = $_POST['user_id'];

        if ($id == $_SESSION['user_id']) {
            $alertType = 'error'; $alertMsg = "⛔ You cannot delete your own account!";
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $logger->log($_SESSION['user_id'], 'USER_DELETE', "Deleted User ID: $id");
            $alertType = 'success'; $alertMsg = "🗑️ User deleted successfully.";
        }
    }
}

// 3. FETCH USERS
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage System Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
    <span class="navbar-text text-white"><i class="bi bi-shield-lock"></i> User Management Console</span>
  </div>
</nav>

<div class="container">
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-danger shadow-sm">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-shield-exclamation"></i> Disaster Recovery Zone</span>
                    <small class="bg-white text-danger px-2 rounded fw-bold">ADMIN ONLY</small>
                </div>
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title text-danger fw-bold">Database Backup</h5>
                        <p class="card-text text-muted mb-0">
                            Download a full SQL dump. Use this to restore data if the server crashes.
                        </p>
                    </div>
                    <button onclick="Swal.fire('Coming Soon', 'Backup feature coming in next update!', 'info')" class="btn btn-outline-danger btn-lg">
                        <i class="bi bi-database-down"></i> Download Backup (.sql)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white"><h5 class="mb-0"><i class="bi bi-person-plus-fill"></i> Add New User</h5></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="e.g. hr_officer" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="user@company.com" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="Min 12 chars, 1# and 1@" required>
                            <div class="form-text text-muted small">Must be 12+ chars, include number & symbol.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Role Permission</label>
                            <select name="role" class="form-select" required>
                                <option value="STAFF">Staff (Encoder - Add/Edit Only)</option>
                                <option value="HR">HR Officer (Full Edit + Reports)</option>
                                <option value="ADMIN">Admin Manager (Full System Access)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Create Account</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white border-bottom"><h5 class="mb-0 text-primary"><i class="bi bi-people-fill"></i> Authorized Users</h5></div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="fw-bold">
                                    <?php echo htmlspecialchars($u['username']); ?>
                                    <?php if($u['id'] == $_SESSION['user_id']) echo ' <span class="badge bg-info text-dark ms-1">You</span>'; ?>
                                </td>
                                <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                <td>
                                    <?php 
                                    $badge = match($u['role']) { 'ADMIN'=>'bg-danger', 'HR'=>'bg-primary', default=>'bg-secondary' };
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo $u['role']; ?></span>
                                </td>
                                <td class="small text-muted"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUser<?php echo $u['id']; ?>">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>

                                    <?php if($u['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this user?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <div class="modal fade" id="editUser<?php echo $u['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title">Edit User: <?php echo htmlspecialchars($u['username']); ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Username</label>
                                                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($u['username']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Role</label>
                                                    <select name="role" class="form-select">
                                                        <option value="STAFF" <?php if($u['role']=='STAFF') echo 'selected'; ?>>Staff</option>
                                                        <option value="HR" <?php if($u['role']=='HR') echo 'selected'; ?>>HR Officer</option>
                                                        <option value="ADMIN" <?php if($u['role']=='ADMIN') echo 'selected'; ?>>Admin Manager</option>
                                                    </select>
                                                </div>
                                                <hr>
                                                <div class="mb-3">
                                                    <label class="form-label text-danger fw-bold">Reset Password (Optional)</label>
                                                    <input type="password" name="password" class="form-control" placeholder="New Password (Min 12 chars)">
                                                    <div class="form-text">Leave blank if you don't want to change it.</div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
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
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==========================================
// [SECURITY] AUTO-LOGOUT (Client-Side)
// ==========================================
const INACTIVITY_LIMIT = 1800000; // 30 Minutes
let autoLogoutTimer;

function resetTimer() {
    clearTimeout(autoLogoutTimer);
    autoLogoutTimer = setTimeout(doLogout, INACTIVITY_LIMIT);
}

function doLogout() {
    window.location.href = 'logout.php?msg=Session_Expired_Auto';
}

window.onload = resetTimer;
document.addEventListener('mousemove', resetTimer);
document.addEventListener('keydown', resetTimer);
document.addEventListener('click', resetTimer);
document.addEventListener('scroll', resetTimer);
</script>
<script>
<?php if ($alertMsg): ?>
Swal.fire({
    icon: '<?php echo $alertType; ?>',
    html: <?php echo json_encode($alertMsg); ?>
});
<?php endif; ?>
</script>
</body>
</html>