<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_pass = $_POST['current_password'];
    $new_pass     = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    // 1. Fetch current user data
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        // 2. Verify Old Password
        if (password_verify($current_pass, $user['password'])) {
            
            // 3. Check if new passwords match
            if ($new_pass === $confirm_pass) {
                
                // 4. Validate strength (Optional: min 6 chars)
                if (strlen($new_pass) < 6) {
                    $error = "Password must be at least 6 characters long.";
                } else {
                    // 5. Update Password
                    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update->execute([$new_hash, $_SESSION['user_id']]);
                    
                    $msg = "Password updated successfully! Please login again.";
                }

            } else {
                $error = "New passwords do not match.";
            }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
    <span class="navbar-text text-white">My Profile Settings</span>
  </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        
        <div class="col-md-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Change Password</h5>
                </div>
                <div class="card-body">
                    
                    <?php if($msg): ?>
                        <div class="alert alert-success"><?php echo $msg; ?></div>
                    <?php endif; ?>
                    
                    <?php if($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label fw-bold">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success">Update Password</button>
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                </div>
            </div>
            
            <div class="text-center mt-3 text-muted">
                <small>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></small>
            </div>
        </div>

        <div class="col-md-5">
            <div class="card shadow-sm border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill"></i> Security Advice</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        <strong>User passwords are securely hashed.</strong> If you forget your password, you must contact the Admin Manager to reset it.
                    </p>
                    <ul class="text-muted small mb-0">
                        <li>Ensure your password is at least 6 characters long.</li>
                        <li>Avoid using easily guessable words like "123456" or your name.</li>
                        <li>Regularly updating your password helps protect the system.</li>
                    </ul>
                </div>
            </div>
        </div>

    </div> </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>