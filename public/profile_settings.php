<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$alertType = "";
$alertMsg = "";

// 1. FETCH CURRENT INFO
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();
$currentEmail = $currentUser['email'] ?? '';

// 2. HANDLE EMAIL UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_email') {
    $new_email = trim($_POST['email']);
    if (strlen($new_email) > 100) {
        $alertType = "error"; $alertMsg = "❌ Email is too long (Max 100 characters).";
    } elseif (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        // Check uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$new_email, $_SESSION['user_id']]);
        if ($chk->rowCount() > 0) {
            $alertType = "error"; $alertMsg = "❌ Email is already in use by another account.";
        } else {
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$new_email, $_SESSION['user_id']]);
            $alertType = "success"; $alertMsg = "✅ Email address updated successfully.";
            $currentEmail = $new_email;
        }
    } else {
        $alertType = "error"; $alertMsg = "❌ Invalid email format.";
    }
}

// 3. HANDLE PASSWORD UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_pass') {
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
                
                // 4. Validate strength (Match Admin Policy: 12 chars, number, symbol)
                if (strlen($new_pass) < 12) {
                    $alertType = "error"; $alertMsg = "Password must be at least 12 characters.";
                } elseif (strlen($new_pass) > 128) {
                    $alertType = "error"; $alertMsg = "Password is too long (Max 128 characters).";
                } elseif (!preg_match('/[0-9]/', $new_pass)) {
                    $alertType = "error"; $alertMsg = "Password must contain at least one number.";
                } elseif (!preg_match('/[\W]/', $new_pass)) {
                    $alertType = "error"; $alertMsg = "Password must contain at least one symbol (!@#$%).";
                } else {
                    // 5. Update Password
                    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update->execute([$new_hash, $_SESSION['user_id']]);
                    
                    $alertType = "success"; $alertMsg = "Password updated successfully!";
                }

            } else {
                $alertType = "error"; $alertMsg = "New passwords do not match.";
            }
        } else {
            $alertType = "error"; $alertMsg = "Current password is incorrect.";
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            <!-- EMAIL SETTINGS -->
            <div class="card shadow mb-4">
                <div class="card-header bg-info text-white"><h5 class="mb-0"><i class="bi bi-envelope-fill"></i> Recovery Email</h5></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_email">
                        <div class="input-group">
                            <input type="email" name="email" class="form-control" placeholder="Enter your email..." value="<?php echo htmlspecialchars($currentEmail); ?>" maxlength="100" required>
                            <button class="btn btn-info text-white" type="submit">Save Email</button>
                        </div>
                        <div class="form-text">Used for "Forgot Password" recovery.</div>
                    </form>
                </div>
            </div>

            <!-- PASSWORD SETTINGS -->
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Change Password</h5>
                </div>
                <div class="card-body">
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="change_pass">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Current Password</label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="curPass" class="form-control" maxlength="128" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('curPass')"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label fw-bold">New Password</label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="newPass" class="form-control" minlength="12" maxlength="128" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass')"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confPass" class="form-control" minlength="12" maxlength="128" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confPass')"><i class="bi bi-eye"></i></button>
                            </div>
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
<script>
    function togglePass(id) {
        const input = document.getElementById(id);
        const icon = input.nextElementSibling.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    }

    <?php if ($alertMsg): ?>
    Swal.fire({
        icon: '<?php echo $alertType; ?>',
        title: '<?php echo ucfirst($alertType === "error" ? "Failed" : "Success"); ?>',
        text: '<?php echo $alertMsg; ?>',
        confirmButtonColor: '<?php echo $alertType === "error" ? "#dc3545" : "#198754"; ?>'
    });
    <?php endif; ?>
</script>
</body>
</html>