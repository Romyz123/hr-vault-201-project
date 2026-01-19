<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("ACCESS DENIED: Only Admin Managers can manage system users.");
}

$message = "";

// 2. HANDLE ACTIONS (ALL POST REQUESTS NOW)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- ADD NEW USER ---
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $role     = $_POST['role'];

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        
        if ($check->rowCount() > 0) {
            $message = "<div class='alert alert-danger'>❌ Username already taken!</div>";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            if ($stmt->execute([$username, $hashed, $role])) {
                $message = "<div class='alert alert-success'>✅ User created successfully!</div>";
            }
        }
    }

    // --- EDIT USER (Update Role / Reset Password) ---
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id       = $_POST['user_id'];
        $username = trim($_POST['username']);
        $role     = $_POST['role'];
        $new_pass = $_POST['password']; // Optional

        // 1. Update Username & Role
        $sql = "UPDATE users SET username = ?, role = ? WHERE id = ?";
        $params = [$username, $role, $id];

        // 2. If Password is provided, hash and update it too
        if (!empty($new_pass)) {
            $sql = "UPDATE users SET username = ?, role = ?, password = ? WHERE id = ?";
            $params = [$username, $role, password_hash($new_pass, PASSWORD_DEFAULT), $id];
        }

        $stmt = $pdo->prepare($sql);
        if ($stmt->execute($params)) {
            $message = "<div class='alert alert-success'>✅ User details updated!</div>";
        }
    }

    // --- DELETE USER (MOVED TO POST FOR SAFETY) ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = $_POST['user_id'];

        if ($id == $_SESSION['user_id']) {
            $message = "<div class='alert alert-danger'>❌ You cannot delete yourself!</div>";
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $message = "<div class='alert alert-warning'>⚠️ User deleted successfully.</div>";
        }
    }
}

// 3. FETCH USERS
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage System Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
    <span class="navbar-text text-white">System User Management</span>
  </div>
</nav>

<div class="container">
    <?php echo $message; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white"><h5 class="mb-0">Add New User</h5></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label>Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Role</label>
                            <select name="role" class="form-select" required>
                                <option value="STAFF">Staff (Encoder)</option>
                                <option value="HR">HR Officer</option>
                                <option value="ADMIN">Admin Manager</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Create User</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><h5 class="mb-0">Existing Users</h5></div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($u['username']); ?></td>
                                <td>
                                    <?php 
                                    $badge = match($u['role']) { 'ADMIN'=>'bg-danger', 'HR'=>'bg-primary', 'STAFF'=>'bg-secondary', default=>'bg-light text-dark' };
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo $u['role']; ?></span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUser<?php echo $u['id']; ?>">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>

                                    <?php if($u['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this user?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <div class="modal fade" id="editUser<?php echo $u['id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit User: <?php echo htmlspecialchars($u['username']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label>Username</label>
                                                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($u['username']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label>Role</label>
                                                    <select name="role" class="form-select">
                                                        <option value="STAFF" <?php if($u['role']=='STAFF') echo 'selected'; ?>>Staff</option>
                                                        <option value="HR" <?php if($u['role']=='HR') echo 'selected'; ?>>HR Officer</option>
                                                        <option value="ADMIN" <?php if($u['role']=='ADMIN') echo 'selected'; ?>>Admin Manager</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="text-danger fw-bold">Reset Password</label>
                                                    <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                                                    <div class="form-text">Only type here if you want to change the password.</div>
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
</body>
</html>