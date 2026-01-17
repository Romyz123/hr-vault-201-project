<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_pass = $_POST['current_password'];
    $new_pass     = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    // 1. Get current user info
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    // 2. Verify Old Password
    if (!password_verify($current_pass, $user['password'])) {
        $message = "<div class='alert alert-danger'>❌ Incorrect Current Password.</div>";
    } 
    // 3. Check if new passwords match
    elseif ($new_pass !== $confirm_pass) {
        $message = "<div class='alert alert-danger'>❌ New passwords do not match.</div>";
    } 
    // 4. Update Password
    else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$hashed, $_SESSION['user_id']]);
        $message = "<div class='alert alert-success'>✅ Password changed successfully!</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-5">
  <div class="container">
    <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
    <span class="navbar-text text-white">Profile Settings</span>
  </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <?php echo $message; ?>
            
            <div class="card shadow">
                <div class="card-header bg-white">
                    <h4 class="mb-0">Change My Password</h4>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Update Password</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="text-center mt-3">
                <p class="text-muted">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo $_SESSION['role']; ?>)</p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
                User passwords are securely hashed. If you forget your password, please contact the system administrator to reset it.
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill"></i> Important Security Notice</h5>
                </div>
                <div class="card-body">
                    <p class="mb-0">For security reasons, please ensure that your password is strong and unique. Avoid using common words or easily guessable information. Regularly updating your password helps protect your account from unauthorized access.</p>
                </div>
            </div>
        </div>
    </div>