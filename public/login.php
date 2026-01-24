<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

$alertType = '';
$alertMsg = '';

// 1. Handle "Unlock System" Request
if (isset($_POST['unlock_system'])) {
    $pdo->query("TRUNCATE TABLE rate_limits");
    $alertType = 'success';
    $alertMsg = "✅ System Unlocked! You can login now.";
}

// Capture success messages (e.g. from Reset Password)
if (isset($_GET['msg'])) {
    $alertType = 'success';
    $alertMsg = $_GET['msg'];
}

// 2. Handle Login Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // [SECURITY] Check if terms were agreed to (Server-side validation)
    if (!isset($_POST['terms_agreed'])) {
        $alertType = 'warning';
        $alertMsg = "⚠️ You must agree to the Confidentiality Pledge to login.";
    } else {
        $security = new Security($pdo);
        
        // Check Rate Limit
        if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
            $alertType = 'error';
            $alertMsg = "<strong>⛔ Too Many Requests!</strong><br>You are temporarily locked out.<form method='POST' class='mt-2'><button type='submit' name='unlock_system' class='btn btn-warning btn-sm w-100'><i class='bi bi-unlock-fill'></i> Dev Mode: Force Unlock</button></form>";
        } else {
            // Normal Login Logic
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                // [LOGGING] Record the login event
                $logger = new Logger($pdo);
                $logger->log($user['id'], 'LOGIN', "User logged in (IP: " . $_SERVER['REMOTE_ADDR'] . ")");
                
                header("Location: index.php");
                exit;
            } else {
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
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
                    <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                    <input type="password" name="password" id="loginPass" class="form-control" placeholder="Enter password" required minlength="6">
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