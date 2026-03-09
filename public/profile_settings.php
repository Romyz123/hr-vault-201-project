<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

$alertType = "";
$alertMsg = "";

// 1. FETCH CURRENT INFO
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();
$currentEmail = $currentUser['email'] ?? '';

// 2. HANDLE EMAIL UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_email') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch. Please refresh.";
        goto end_post;
    }

    $new_email = trim($_POST['email']);
    if (strlen($new_email) > 100) {
        $alertType = "error";
        $alertMsg = "❌ Email is too long (Max 100 characters).";
    } elseif (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        // Check uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$new_email, $_SESSION['user_id']]);
        if ($chk->rowCount() > 0) {
            $alertType = "error";
            $alertMsg = "❌ Email is already in use by another account.";
        } else {
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$new_email, $_SESSION['user_id']]);
            $alertType = "success";
            $alertMsg = "✅ Email address updated successfully.";
            $currentEmail = $new_email;
        }
    } else {
        $alertType = "error";
        $alertMsg = "❌ Invalid email format.";
    }
}

// 3. HANDLE PASSWORD UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_pass') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch. Please refresh.";
        goto end_post;
    }

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
                if (strlen($new_pass) < 15) {
                    $alertType = "error";
                    $alertMsg = "Password must be at least 15 characters (MHI Policy).";
                } elseif (strlen($new_pass) > 128) {
                    $alertType = "error";
                    $alertMsg = "Password is too long (Max 128 characters).";
                } elseif (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $new_pass)) {
                    $alertType = "error";
                    $alertMsg = "Password must contain Uppercase, Lowercase, Number, and Symbol.";
                } else {
                    // [MHI Security] Check Frequency (Max 1 change per 24h)
                    if (!$security->checkPasswordFrequency($_SESSION['user_id'])) {
                        $alertType = "error";
                        $alertMsg = "Security Policy: You cannot change your password more than once in 24 hours.";
                    }
                    // [MHI Security] Check History (No reuse of last 3)
                    elseif (!$security->checkPasswordHistory($_SESSION['user_id'], $new_pass)) {
                        $alertType = "error";
                        $alertMsg = "Security Policy: You cannot reuse any of your last 3 passwords.";
                    } else {
                        // 5. Update Password & History
                        $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                        $pdo->beginTransaction();
                        try {
                            $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?")->execute([$new_hash, $_SESSION['user_id']]);
                            $security->logPasswordHistory($_SESSION['user_id'], $new_hash);
                            $pdo->commit();
                            $alertType = "success";
                            $alertMsg = "Password updated successfully!";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $alertType = "error";
                            $alertMsg = "Failed to update password. Please try again.";
                        }
                    }
                }
            } else {
                $alertType = "error";
                $alertMsg = "New passwords do not match.";
            }
        } else {
            $alertType = "error";
            $alertMsg = "Current password is incorrect.";
        }
    }
}

end_post:
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Profile Settings</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <span class="navbar-text text-white">My Profile Settings</span>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">

            <div class="col-md-6 mb-4">
                <!-- EMAIL SETTINGS -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-envelope-fill"></i> Recovery Email</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_email">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                                    <input type="password" name="new_password" id="newPass" class="form-control" minlength="15" maxlength="128" required pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{15,}" title="Must be at least 15 characters, contain Uppercase, Lowercase, Number, and Symbol.">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass')"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confPass" class="form-control" minlength="15" maxlength="128" required>
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
                            <li>Ensure your password is at least 12 characters long.</li>
                            <li>Avoid using easily guessable words like "123456" or your name.</li>
                            <li>Regularly updating your password helps protect the system.</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
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