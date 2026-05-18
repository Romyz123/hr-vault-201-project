<?php
require '../config/db.php';
session_start();
$msg = '';
// [FIX] Include global helper functions
if (!function_exists('h')) {
    require_once __DIR__ . '/../src/helpers.php';
}
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
        // generate CSRF token for reset form
        $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pass') {
    // CSRF validation for password reset form
    $csrfPosted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['reset_csrf']) || !hash_equals($_SESSION['reset_csrf'], $csrfPosted)) {
        $error = "Security token mismatch. Please try again.";
        $step = 'reset';
    }
    // clear token whether valid or not to prevent reuse
    unset($_SESSION['reset_csrf']);

    $pass = $_POST['password'];
    $confirm = $_POST['confirm'];
    $validToken = $_POST['token_check']; // Hidden field

    // Re-verify to be safe
    $stmt = $pdo->prepare("SELECT id, username, password_changed_at FROM users WHERE reset_token = ? AND reset_expires > ?");
    $stmt->execute([$validToken, date('Y-m-d H:i:s')]);
    $user = $stmt->fetch();

    if ($user) {
        // [SECURITY] 3. Restrict Password Change Frequency (24 Hours)
        if (!empty($user['password_changed_at'])) {
            $lastChange = new DateTime($user['password_changed_at']);
            $diff = time() - $lastChange->getTimestamp();
            if ($diff < 86400) { // 86400 seconds = 24 hours
                $error = "❌ Security Policy: You can only change your password once every 24 hours.";
                $step = 'reset';
            }
        }

        // only proceed with further validation if no error was triggered above
        if (empty($error)) {
            if ($pass !== $confirm) {
                $error = "Passwords do not match.";
                $step = 'reset';
            } elseif (strlen($pass) < 15 || strlen($pass) > 128 || !preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $pass)) {
                $error = "Password must be 15+ chars, with Uppercase, Lowercase, Number & Symbol.";
                $step = 'reset';
            } elseif (stripos($pass, $user['username']) !== false) {
                $error = "Password cannot contain your Username.";
                $step = 'reset';
            } else {
                // [SECURITY] 2. Password History Tracking (Last 3)
                // First check against current password
                $currentPwdStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $currentPwdStmt->execute([$user['id']]);
                $currentPwd = $currentPwdStmt->fetchColumn();

                $histStmt = $pdo->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
                $histStmt->execute([$user['id']]);
                $history = $histStmt->fetchAll(PDO::FETCH_COLUMN);

                $isReused = false;
                // Check against current password
                if ($currentPwd && password_verify($pass, $currentPwd)) {
                    $isReused = true;
                }

                // Check against historical passwords
                foreach ($history as $oldHash) {
                    if (password_verify($pass, $oldHash)) {
                        $isReused = true;
                        break;
                    }
                }
                if ($isReused) {
                    $error = "❌ Security Policy: You cannot reuse your current password or any of your last 3 passwords.";
                    $step = 'reset';
                } else {
                    $hash = password_hash($pass, PASSWORD_BCRYPT);
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), reset_token = NULL, reset_expires = NULL, failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$hash, $user['id']]);
                        // Record in History
                        $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)")->execute([$user['id'], $hash]);
                        $pdo->commit();

                        header("Location: login.php?msg=" . urlencode("✅ Password reset successful! You can now login."));
                        exit;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Database error during password update: " . htmlspecialchars($e->getMessage());
                        $step = 'reset';
                    }
                }
            }
        }
    } else {
        $error = "Session expired. Please request a new code.";
        $step = 'verify';
    }
}

// regenerate CSRF token for reset step if it was cleared earlier
if ($step === 'reset' && empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <link rel="icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="apple-touch-icon" href="../uploads/tesp-logo.png">
    <?php
    $fav = 'uploads/favicon.png';
    if (!file_exists($fav)) $fav = 'uploads/tesp-logo.png';
    ?>
    <link rel="icon" type="image/png" href="<?= h($fav) ?>">
    <link rel="shortcut icon" type="image/png" href="<?= h($fav) ?>">
    <link rel="apple-touch-icon" href="<?= h($fav) ?>">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        body {
            /* --- BACKGROUND THEMES (Uncomment the one you want to use) --- */

            /* OPTION 1: Original Deep Corporate Blue Gradient (Revert to this if needed) */
            /* background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); */

            /* OPTION 2: TESP Corporate Green Gradient */
            /* background: linear-gradient(135deg, #198754 0%, #146c43 100%); */

            /* OPTION 3: Clean Light Corporate Flat Color */
            background-color: #f4f6f9;

            /* OPTION 4: Background Image with Dark Overlay */
            /* background: linear-gradient(rgba(30, 60, 114, 0.8), rgba(42, 82, 152, 0.8)), url('uploads/company_bg.jpg') center/cover no-repeat fixed; */

            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .card {
            border: none;
            border-radius: 16px;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        .icon-circle {
            width: 70px;
            height: 70px;
            background: #e0e7ff;
            color: #4f46e5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 15px;
        }

        .otp-input {
            letter-spacing: 8px;
            font-size: 1.5rem;
            text-align: center;
            font-weight: bold;
        }

        .validation-item {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 2px;
            transition: color 0.3s;
        }

        .validation-item.valid {
            color: #198754;
            font-weight: 600;
        }

        .validation-item i {
            margin-right: 5px;
        }

        /* Strength Meter */
        .strength-meter {
            height: 5px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 5px;
            overflow: hidden;
            transition: all 0.3s;
        }
    </style>
</head>

<body class="bg-body-tertiary">
    <div class="position-absolute top-0 end-0 p-3">
        <button id="darkModeToggle" class="btn btn-sm btn-outline-secondary border-0" title="Toggle Dark Mode">
            <i class="bi bi-moon-stars-fill"></i>
        </button>
    </div>

    <div class="card" style="width: 420px;">
        <div class="card-header text-center bg-transparent border-0 pt-4">
            <div class="icon-circle"><i class="bi bi-shield-lock-fill"></i></div>
            <h4 class="fw-bold text-dark mb-1">Secure Reset</h4>
        </div>

        <div class="card-body px-4 pb-4">
            <?php if ($error): ?><div class="alert alert-danger py-2 small text-center"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <?php if (isset($_GET['sent'])): ?><div class="alert alert-success py-2 small text-center">📧 Code sent to your email!</div><?php endif; ?>

            <!-- STEP 1: ENTER CODE -->
            <?php if ($step === 'verify'): ?>
                <p class="text-center text-muted small">Enter the 6-digit code (OTP) sent to your email.</p>
                <form method="GET" action="reset_password.php">
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-secondary text-uppercase">OTP Code</label>
                        <input type="text" name="token" class="form-control otp-input" placeholder="000000" maxlength="6" required autofocus value="<?php echo htmlspecialchars($token); ?>" pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')" autocomplete="off">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">Verify Code</button>
                    </div>
                    <div class="text-center mt-3">
                        <span class="text-muted small">Didn't receive the code?</span>
                        <a href="forgot_password.php?email=<?php echo urlencode($email); ?>" id="resendLink" class="text-decoration-none small fw-bold">Resend Code</a>
                    </div>
                </form>

                <!-- STEP 2: SET NEW PASSWORD -->
            <?php elseif ($step === 'reset'): ?>
                <p class="text-center text-muted small">Code verified. Set your new password.</p>
                <form method="POST" id="resetForm">
                    <input type="hidden" name="action" value="save_pass">
                    <input type="hidden" name="token_check" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['reset_csrf'] ?? ''); ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary text-uppercase">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-key"></i></span>
                            <input type="password" name="password" id="pass1" class="form-control border-start-0" placeholder="Enter new password" required>
                            <button class="btn btn-outline-secondary border-start-0" type="button" onclick="togglePass('pass1')"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="strength-meter">
                            <div id="strength-bar" style="width: 0%; height: 100%; background: red;"></div>
                        </div>
                        <!-- Real-time Validation Checklist -->
                        <div class="mt-2 ps-1">
                            <div id="rule-len" class="validation-item"><i class="bi bi-circle"></i> At least 15 characters</div>
                            <div id="rule-let" class="validation-item"><i class="bi bi-circle"></i> Contains a letter</div>
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
        const strengthBar = document.getElementById('strength-bar');

        if (p1 && p2) {
            const rules = {
                len: {
                    el: document.getElementById('rule-len'),
                    regex: /^.{15,128}$/
                },
                let: {
                    el: document.getElementById('rule-let'),
                    regex: /[a-zA-Z]/
                },
                num: {
                    el: document.getElementById('rule-num'),
                    regex: /[0-9]/
                },
                sym: {
                    el: document.getElementById('rule-sym'),
                    regex: /[\W_]/
                }
            };

            function validate() {
                const val = p1.value;
                let allValid = true;
                let score = 0;

                // Check Complexity
                for (const key in rules) {
                    const rule = rules[key];
                    const icon = rule.el.querySelector('i');
                    if (rule.regex.test(val)) {
                        rule.el.classList.add('valid');
                        icon.classList.replace('bi-circle', 'bi-check-circle-fill');
                        score++;
                    } else {
                        rule.el.classList.remove('valid');
                        icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                        allValid = false;
                    }
                }

                // Update Strength Meter
                let width = (score / 4) * 100;
                let color = 'red';
                if (score === 2) color = 'orange';
                if (score === 3) color = '#ffc107'; // yellow
                if (score === 4) color = '#198754'; // green

                strengthBar.style.width = width + '%';
                strengthBar.style.background = color;

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

        // [NEW] Resend Timer
        <?php if (isset($_GET['sent'])): ?>
            const resendLink = document.getElementById('resendLink');
            if (resendLink) {
                let timeLeft = 60;
                resendLink.style.pointerEvents = 'none';
                resendLink.classList.add('text-muted');
                resendLink.innerText = `Resend in ${timeLeft}s`;

                const timer = setInterval(() => {
                    timeLeft--;
                    if (timeLeft < 0) {
                        clearInterval(timer);
                        resendLink.innerText = 'Resend Code';
                        resendLink.style.pointerEvents = 'auto';
                        resendLink.classList.remove('text-muted');
                    } else {
                        resendLink.innerText = `Resend in ${timeLeft}s`;
                    }
                }, 1000);
            }
        <?php endif; ?>
    </script>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/dark_mode.js"></script>
</body>

</html>