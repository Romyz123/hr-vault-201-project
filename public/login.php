<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

$alertType = '';
$alertMsg = '';

// Capture success messages (e.g. from Reset Password)
if (isset($_GET['msg'])) {
    $alertType = 'success';
    $alertMsg = htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['error'])) {
    $alertType = 'error';
    $alertMsg = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
}

// 2. Handle Login Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'] ?? ''; // DON'T trim password - users might have trailing spaces intentionally

    // [SECURITY] Input Validation & Character Limits
    if (strlen($username) > 50) {
        $alertType = 'error';
        $alertMsg = "❌ Username exceeds 50 characters.";
    } elseif (strlen($password) > 128) {
        $alertType = 'error';
        $alertMsg = "❌ Password exceeds 128 characters.";
    } elseif (!isset($_POST['terms_agreed'])) {
        $alertType = 'warning';
        $alertMsg = "⚠️ You must agree to the Confidentiality Pledge to login.";
    } else {
        $security = new Security($pdo);

        // Check Rate Limit
        if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'], 20, 60)) { // [FIX] Increased to 20 for testing
            $alertType = 'error';
            $alertMsg = "<strong>⛔ Too Many Requests!</strong><br>You are temporarily locked out. Please try again in a minute.";
        } else {
            // Normal Login Logic
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                // [NEW] 1. Check Password Expiry (45 Days)
                $lastChange = new DateTime($user['password_changed_at'] ?? $user['created_at']); // Fallback to created_at
                $today = new DateTime();
                $daysDiff = $today->diff($lastChange)->days;

                if ($daysDiff > 45) {
                    $_SESSION['temp_user_id'] = $user['id']; // Temporary session
                    header("Location: change_password_forced.php?reason=expired");
                    exit;
                }

                // [CHECK] Maintenance Mode
                $maintStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'");
                $isMaint = ($maintStmt->fetchColumn() === '1');

                if ($isMaint && $user['role'] !== 'ADMIN') {
                    $alertType = 'warning';
                    $alertMsg = "🛠️ <strong>System Under Maintenance</strong><br>Only Administrators can log in at this time. Please try again later.";
                } else {
                    // [NEW] 2FA Check
                    if (!empty($user['is_2fa_enabled'])) {
                        // Check for Trusted Device Cookie
                        if (isset($_COOKIE['hr_trust_device'])) {
                            $tokenHash = hash('sha256', $_COOKIE['hr_trust_device']);
                            // Verify against DB
                            if ($user['trusted_device_token'] === $tokenHash && new DateTime($user['trusted_device_expires']) > new DateTime()) {
                                // Trust valid - Skip OTP
                                goto login_success;
                            }
                        }

                        // No trust or expired - Send OTP
                        $otp = random_int(100000, 999999); // Use cryptographically secure random
                        $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")->execute([$otp, $user['id']]);

                        // Send Email - Check return value
                        $emailSent = mail($user['email'], "Login OTP", "Your code is: $otp");
                        if (!$emailSent) {
                            error_log("OTP_EMAIL_FAILED: Could not send OTP email to " . $user['email']);
                            $alertType = 'error';
                            $alertMsg = "❌ Failed to send OTP email. Please contact support.";
                        } else {
                            $_SESSION['partial_user_id'] = $user['id'];
                            header("Location: verify_otp.php");
                            exit;
                        }
                    }

                    login_success:
                    // [SECURITY] Regenerate session ID to prevent fixation attacks
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    // [LOGGING] Record the login event - Sanitize username in logs
                    $logger = new Logger($pdo);
                    $logUsername = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $user['username']); // Safety filter
                    $logger->log($user['id'], 'LOGIN', "User '$logUsername' logged in (IP: " . $_SERVER['REMOTE_ADDR'] . ")");

                    header("Location: index.php");
                    exit;
                }
            } else {
                // [NEW] Log Failed Attempt
                $logger = new Logger($pdo);
                // If user exists (wrong password), log ID. If not (wrong username), log 0.
                $failedId = $user ? $user['id'] : 0;
                $failDetails = $user ? "Failed login (Wrong Password)" : "Failed login (Unknown User: $username)";
                $logger->log($failedId, 'LOGIN_FAILED', $failDetails);

                $alertType = 'error';
                $alertMsg = "❌ Invalid Username or Password";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login - TES Philippines HR</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #198754 0%, #0d6efd 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            background: #fff;
            padding-top: 2rem;
            border-bottom: none;
            text-align: center;
        }

        .logo-icon {
            font-size: 3rem;
            color: #198754;
        }

        /* Disable button style */
        .btn-disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* SweetAlert2 Brand Customization */
        .swal2-popup {
            border-top: 5px solid #198754;
            /* Brand Green */
            border-radius: 15px;
        }

        .swal2-confirm {
            background-color: #198754 !important;
            /* Brand Green */
            box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.2) !important;
        }

        .swal2-cancel {
            background-color: #6c757d !important;
            /* Grey */
        }
    </style>
</head>

<body>

    <div class="card login-card">
        <div class="card-header">
            <i class="bi bi-building-lock logo-icon"></i>
            <h3 class="mt-2 fw-bold text-dark">HR 201 Vault</h3>
            <p class="text-muted">TES Philippines, Inc.</p>
        </div>
        <div class="card-body p-4 bg-white">

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label text-secondary">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="Enter username" required maxlength="50" pattern="[a-zA-Z0-9]+" title="Only letters and numbers allowed" oninput="this.value = this.value.replace(/[^a-zA-Z0-9]/g, '')">
                    </div>
                    <div class="form-text text-muted small">Special characters are not allowed.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                        <input type="password" name="password" id="loginPass" class="form-control" placeholder="Enter password" required minlength="6" maxlength="128">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleLoginPass(this)"><i class="bi bi-eye"></i></button>
                    </div>
                    <div id="capsLockWarning" class="form-text text-danger fw-bold mt-1" style="display: none;">
                        <i class="bi bi-capslock-fill"></i> Caps Lock is ON
                    </div>
                </div>

                <div class="text-end mb-3">
                    <a href="forgot_password.php" class="text-decoration-none small text-primary fw-bold">Forgot Password?</a>
                </div>

                <div class="mb-4 form-check bg-light p-3 rounded border">
                    <input type="checkbox" name="terms_agreed" class="form-check-input" id="termsCheck">
                    <label class="form-check-label small text-muted lh-sm" for="termsCheck">
                        <strong>Confidentiality Pledge:</strong><br>
                        I promise to keep all accessed data strictly confidential and adhere to MHI Security Policies.
                    </label>
                </div>

                <div class="d-grid">
                    <button type="submit" name="login" id="loginBtn" class="btn btn-success btn-lg shadow-sm" disabled>
                        Secure Login <i class="bi bi-lock-fill"></i>
                    </button>
                </div>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">Authorized Personnel Only</small>
            </div>
        </div>
    </div>

    <script>
        // [SCRIPT] Toggle Login Button based on Checkbox
        const termsCheck = document.getElementById('termsCheck');
        const loginBtn = document.getElementById('loginBtn');
        const icon = loginBtn.querySelector('i');

        termsCheck.addEventListener('change', function() {
            if (this.checked) {
                loginBtn.disabled = false;
                loginBtn.innerHTML = 'Secure Login <i class="bi bi-arrow-right"></i>';
            } else {
                loginBtn.disabled = true;
                loginBtn.innerHTML = 'Secure Login <i class="bi bi-lock-fill"></i>';
            }
        });

        // [SCRIPT] Toggle Password Visibility
        function toggleLoginPass(btn) {
            const input = document.getElementById('loginPass');
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }

        // [SCRIPT] Caps Lock Warning
        const loginPassInput = document.getElementById('loginPass');
        const capsWarning = document.getElementById('capsLockWarning');

        loginPassInput.addEventListener('keyup', function(event) {
            if (event.getModifierState('CapsLock')) {
                capsWarning.style.display = 'block';
            } else {
                capsWarning.style.display = 'none';
            }
        });

        // [SCRIPT] SweetAlert2 Trigger
        <?php if ($alertMsg): ?>
            Swal.fire({
                icon: '<?php echo $alertType; ?>',
                title: '<?php echo ucfirst($alertType); ?>',
                html: <?php echo json_encode($alertMsg); ?>,
                confirmButtonColor: '#198754'
            });
        <?php endif; ?>
    </script>

</body>

</html>