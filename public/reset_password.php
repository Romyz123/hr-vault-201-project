<?php
require '../config/db.php';
$msg = ''; 
$error = '';
$step = 'verify'; // Default step: Ask for code

// 1. CAPTURE INPUTS
$token = $_REQUEST['token'] ?? '';
$email = $_REQUEST['email'] ?? '';

// 2. VERIFY TOKEN (If provided via Link or Form)
if ($token) {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE reset_token = ? AND reset_expires > ?");
    $stmt->execute([$token, date('Y-m-d H:i:s')]);
    $user = $stmt->fetch();

    if ($user) {
        $step = 'reset'; // Token is valid, move to reset step
    } else {
        $error = "Invalid or expired code. Please try again.";
        $step = 'verify'; // Stay on verify step
    }
}

// 3. HANDLE PASSWORD UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pass') {
    $pass = $_POST['password'];
    $confirm = $_POST['confirm'];
    $validToken = $_POST['token_check']; // Hidden field

    // Re-verify to be safe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > ?");
    $stmt->execute([$validToken, date('Y-m-d H:i:s')]);
    $user = $stmt->fetch();

    if ($user) {
        if ($pass !== $confirm) {
            $error = "Passwords do not match.";
            $step = 'reset';
        } elseif (strlen($pass) < 12 || !preg_match('/[0-9]/', $pass) || !preg_match('/[\W]/', $pass)) {
            $error = "Password must be 12+ chars, with a number & symbol.";
            $step = 'reset';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")->execute([$hash, $user['id']]);
            header("Location: login.php?msg=" . urlencode("✅ Password reset successful! You can now login."));
            exit;
        }
    } else {
        $error = "Session expired. Please request a new code.";
        $step = 'verify';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(rgba(15, 23, 42, 0.6), rgba(15, 23, 42, 0.6)), url('https://images.unsplash.com/photo-1497215728101-856f4ea42174?auto=format&fit=crop&w=1920&q=80');
            background-size: cover;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .card { border: none; border-radius: 16px; backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.95); box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        .icon-circle { width: 70px; height: 70px; background: #e0e7ff; color: #4f46e5; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 15px; }
        .otp-input { letter-spacing: 8px; font-size: 1.5rem; text-align: center; font-weight: bold; }
        .validation-item { font-size: 0.85rem; color: #6c757d; margin-bottom: 2px; transition: color 0.3s; }
        .validation-item.valid { color: #198754; font-weight: 600; }
        .validation-item i { margin-right: 5px; }
    </style>
</head>
<body>

<div class="card" style="width: 420px;">
    <div class="card-header text-center bg-transparent border-0 pt-4">
        <div class="icon-circle"><i class="bi bi-shield-lock-fill"></i></div>
        <h4 class="fw-bold text-dark mb-1">Secure Reset</h4>
    </div>
    
    <div class="card-body px-4 pb-4">
        <?php if($error): ?><div class="alert alert-danger py-2 small text-center"><?php echo $error; ?></div><?php endif; ?>
        <?php if(isset($_GET['sent'])): ?><div class="alert alert-success py-2 small text-center">📧 Code sent to your email!</div><?php endif; ?>

        <!-- STEP 1: ENTER CODE -->
        <?php if ($step === 'verify'): ?>
            <p class="text-center text-muted small">Enter the 6-digit code sent to your email.</p>
            <form method="GET">
                <div class="mb-4">
                    <input type="text" name="token" class="form-control otp-input" placeholder="000000" maxlength="6" required autofocus value="<?php echo htmlspecialchars($token); ?>">
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">Verify Code</button>
                </div>
                <div class="text-center mt-3">
                    <a href="forgot_password.php" class="text-decoration-none small">Resend Code</a>
                </div>
            </form>

        <!-- STEP 2: SET NEW PASSWORD -->
        <?php elseif ($step === 'reset'): ?>
            <p class="text-center text-muted small">Code verified. Set your new password.</p>
            <form method="POST" id="resetForm">
                <input type="hidden" name="action" value="save_pass">
                <input type="hidden" name="token_check" value="<?php echo htmlspecialchars($token); ?>">
                
                <div class="mb-3">
                    <label class="form-label fw-bold small text-secondary text-uppercase">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-key"></i></span>
                        <input type="password" name="password" id="pass1" class="form-control border-start-0" placeholder="Enter new password" required>
                        <button class="btn btn-outline-secondary border-start-0" type="button" onclick="togglePass('pass1')"><i class="bi bi-eye"></i></button>
                    </div>
                    <!-- Real-time Validation Checklist -->
                    <div class="mt-2 ps-1">
                        <div id="rule-len" class="validation-item"><i class="bi bi-circle"></i> At least 12 characters</div>
                        <div id="rule-num" class="validation-item"><i class="bi bi-circle"></i> Contains a number (0-9)</div>
                        <div id="rule-sym" class="validation-item"><i class="bi bi-circle"></i> Contains a symbol (!@#$)</div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold small text-secondary text-uppercase">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-check2-circle"></i></span>
                        <input type="password" name="confirm" id="pass2" class="form-control border-start-0" placeholder="Repeat password" required>
                    </div>
                    <div id="match-msg" class="small mt-1 fw-bold text-danger" style="display:none;">
                        <i class="bi bi-x-circle"></i> Passwords do not match
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" id="submitBtn" class="btn btn-primary shadow-sm" disabled>Update Password</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

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

    const p1 = document.getElementById('pass1');
    const p2 = document.getElementById('pass2');
    const btn = document.getElementById('submitBtn');
    const matchMsg = document.getElementById('match-msg');

    if (p1 && p2) {
        const rules = {
            len: { el: document.getElementById('rule-len'), regex: /.{12,}/ },
            num: { el: document.getElementById('rule-num'), regex: /[0-9]/ },
            sym: { el: document.getElementById('rule-sym'), regex: /[\W_]/ }
        };

        function validate() {
            const val = p1.value;
            let allValid = true;

            // Check Complexity
            for (const key in rules) {
                const rule = rules[key];
                const icon = rule.el.querySelector('i');
                if (rule.regex.test(val)) {
                    rule.el.classList.add('valid');
                    icon.classList.replace('bi-circle', 'bi-check-circle-fill');
                } else {
                    rule.el.classList.remove('valid');
                    icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                    allValid = false;
                }
            }

            // Check Match
            const match = p2.value && p1.value === p2.value;
            if (p2.value && !match) {
                matchMsg.style.display = 'block';
                matchMsg.className = 'small mt-1 fw-bold text-danger';
                matchMsg.innerHTML = '<i class="bi bi-x-circle"></i> Passwords do not match';
            } else if (match) {
                matchMsg.style.display = 'block';
                matchMsg.className = 'small mt-1 fw-bold text-success';
                matchMsg.innerHTML = '<i class="bi bi-check-circle"></i> Passwords match';
            } else {
                matchMsg.style.display = 'none';
            }

            btn.disabled = !(allValid && match);
        }

        p1.addEventListener('input', validate);
        p2.addEventListener('input', validate);
    }
</script>

</body>
</html>