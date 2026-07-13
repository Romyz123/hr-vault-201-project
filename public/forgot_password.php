<?php
require '../config/db.php';
require '../src/Security.php';
require_once '../src/Logger.php';
// [FIX] Include global helper functions
if (!function_exists('h')) {
    require_once __DIR__ . '/../src/helpers.php';
}
session_start();

$security = new Security($pdo);
$logger = new Logger($pdo);
$csrf_token = $security->generateCSRF();

$error = '';
$success = '';
$wait = 0; // Initialize wait variable for frontend countdown

// [UX FIX] Reset the wizard if the user just navigates to the page normally via link/button
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['forgot_step'], $_SESSION['forgot_user_id'], $_SESSION['forgot_question'], $_SESSION['forgot_username']);
    $step = 1;
} else {
    $step = $_SESSION['forgot_step'] ?? 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SECURITY] Strict Rate Limiting (Prevent Brute Force)
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'], 5, 60)) {
        $error = "⛔ Too many requests. Please wait 60 seconds before trying again.";
    } elseif (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "❌ Security Token Mismatch.";
    } elseif (isset($_POST['try_another'])) {
        $_SESSION['forgot_step'] = 'methods';
        $step = 'methods';
    } else {
        $posted_step = $_POST['step'] ?? '1';

        if ($posted_step === '1') {
            // STEP 1: Verify Identity
            $username = trim($_POST['username'] ?? '');
            usleep(rand(200000, 400000)); // Prevent timing attacks

            // [SECURITY] Validate Input Length
            if (strlen($username) > 100) {
                $error = "❌ Username or Email exceeds maximum length.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id, username, security_question, totp_secret FROM users WHERE username = ? OR email = ?");
                    $stmt->execute([$username, $username]);
                    $user = $stmt->fetch();

                    if ($user) {
                        $_SESSION['forgot_user_id'] = $user['id'];
                        $_SESSION['forgot_username'] = $user['username'];
                        $_SESSION['forgot_question'] = $user['security_question'] ?? '';
                        $_SESSION['forgot_has_auth'] = !empty($user['totp_secret']);

                        if (empty($user['security_question'])) {
                            $_SESSION['forgot_step'] = 'methods';
                            $step = 'methods';
                        } else {
                            $_SESSION['forgot_step'] = 2;
                            $step = 2;
                        }
                    } else {
                        error_log('Forgot password lookup failed for identifier: ' . $username . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                        $error = "If an account with that username or email exists, you will be prompted to verify your identity.";
                    }
                } catch (PDOException $e) {
                    $error = "❌ Database error. Missing security columns.";
                }
            }
        } elseif ($posted_step === '2' && isset($_SESSION['forgot_user_id'])) {
            // STEP 2: Verify Answer (Case-sensitive)
            $answer = trim($_POST['security_answer'] ?? '');

            // [SECURITY] Tighten rate limiting for answer attempts
            if (!isset($_SESSION['forgot_answer_attempts']) || !is_array($_SESSION['forgot_answer_attempts'])) {
                $_SESSION['forgot_answer_attempts'] = ['count' => 0, 'ts' => time()];
            }
            if (time() - $_SESSION['forgot_answer_attempts']['ts'] > 300) {
                // Reset counter after 5 minutes
                $_SESSION['forgot_answer_attempts'] = ['count' => 0, 'ts' => time()];
            }
            if ($_SESSION['forgot_answer_attempts']['count'] >= 5) {
                $error = "⛔ Too many attempts. Please wait a few minutes before trying again.";
                $step = 2;
            } elseif (strlen($answer) > 255) {
                $error = "❌ Answer exceeds maximum length.";
                $step = 2;
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT security_answer FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['forgot_user_id']]);
                    $hash = $stmt->fetchColumn();

                    if ($hash && password_verify(strtolower($answer), $hash)) {
                        session_regenerate_id(true);
                        $_SESSION['forgot_step'] = 3;
                        $step = 3;
                    } else {
                        $_SESSION['forgot_answer_attempts']['count']++;
                        error_log('Forgot password answer failed for user_id: ' . ($_SESSION['forgot_user_id'] ?? 'unknown') . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' attempt_count=' . $_SESSION['forgot_answer_attempts']['count']);
                        $error = "❌ Incorrect answer.";
                        $step = 2;
                    }
                } catch (PDOException $e) {
                    $error = "❌ Database error during verification.";
                    $step = 2;
                }
            }
        } elseif ($posted_step === 'methods') {
            // Handle Alternative Method Selection
            $method = $_POST['method'] ?? '';
            if ($method === 'question') {
                if (empty($_SESSION['forgot_question'])) {
                    $error = "❌ Security question not configured.";
                    $step = 'methods';
                } else {
                    $_SESSION['forgot_step'] = 2;
                    $step = 2;
                }
            } elseif ($method === 'backup_code') {
                $_SESSION['forgot_step'] = 'backup_code';
                $step = 'backup_code';
            } elseif ($method === 'authenticator') {
                $_SESSION['forgot_step'] = 'authenticator';
                $step = 'authenticator';
            }
        } elseif ($posted_step === 'backup_code') {
            if (empty($_SESSION['forgot_user_id'])) {
                $error = "Session expired. Please start the recovery process again.";
                $step = 1;
            } else {
                $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['backup_code'] ?? ''));
                try {
                    $stmt = $pdo->prepare("SELECT recovery_codes FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['forgot_user_id']]);
                    $json = $stmt->fetchColumn();
                    $hashes = $json ? json_decode($json, true) : [];

                    $valid = false;
                    if (is_array($hashes)) {
                        foreach ($hashes as $index => $hash) {
                            if (password_verify($code, $hash)) {
                                $valid = true;
                                unset($hashes[$index]); // Remove used code so it can't be used twice
                                $pdo->prepare("UPDATE users SET recovery_codes = ? WHERE id = ?")->execute([json_encode(array_values($hashes)), $_SESSION['forgot_user_id']]);
                                break;
                            }
                        }
                    }

                    if ($valid) {
                        $_SESSION['forgot_step'] = 3;
                        $step = 3;
                    } else {
                        $error = "❌ Invalid or already used Backup Code.";
                        $step = 'backup_code';
                    }
                } catch (PDOException $e) {
                    $error = "❌ Database error during verification.";
                    $step = 'backup_code';
                }
            }
        } elseif ($posted_step === 'authenticator') {
            if (empty($_SESSION['forgot_user_id'])) {
                $error = "Session expired. Please start the recovery process again.";
                $step = 1;
            } else {
                $code = trim($_POST['auth_code'] ?? '');
                try {
                    $stmt = $pdo->prepare("SELECT totp_secret FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['forgot_user_id']]);
                    $secret = $stmt->fetchColumn();

                    require_once '../src/GoogleAuthenticator.php';
                    if ($secret && GoogleAuthenticator::verifyCode($secret, $code)) {
                        $_SESSION['forgot_step'] = 3;
                        $step = 3;
                        $logger->log($_SESSION['forgot_user_id'], 'PASSWORD_RECOVERY_AUTH_VERIFIED', "Authenticator code verified for password recovery.");
                    } else {
                        $error = "❌ Invalid Authenticator code.";
                        $step = 'authenticator';
                    }
                } catch (PDOException $e) {
                    $error = "❌ Database error during verification.";
                    $step = 'authenticator';
                }
            }
        } elseif ($posted_step === '3' && isset($_SESSION['forgot_user_id'])) {
            // STEP 3: Reset Password
            $new_pass = $_POST['new_password'] ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';
            $username = $_SESSION['forgot_username'] ?? '';

            if ($new_pass !== $confirm_pass) {
                $error = "❌ Update Failed: Passwords do not match.";
                $step = 3;
            } elseif (strlen($new_pass) > 128) {
                $error = "❌ Update Failed: Password is too long (Max 128 characters).";
                $step = 3;
            } elseif (strlen($new_pass) < 15 || !preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $new_pass)) {
                $error = "❌ Update Failed: Password must be 15+ chars with Uppercase, Lowercase, Number, and Symbol.";
                $step = 3;
            } elseif (stripos($new_pass, $username) !== false) {
                $error = "❌ Update Failed: Password cannot contain your Username.";
                $step = 3;
            } else {
                // Log password reset
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?")->execute([$hashed, $_SESSION['forgot_user_id']]);
                unset($_SESSION['forgot_step'], $_SESSION['forgot_user_id'], $_SESSION['forgot_question'], $_SESSION['forgot_username']);
                header("Location: login.php?msg=" . urlencode("✅ Password has been reset successfully. You may now log in."));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password | TESP HR 201 System</title>
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
            /* Active background theme */
            background-color: #f4f6f9;

            /* Optional static background image (file should be in assets/images and set by admin):
               background: linear-gradient(rgba(30, 60, 114, 0.8), rgba(42, 82, 152, 0.8)), url('assets/images/company_bg.jpg') center/cover no-repeat fixed; */

            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
    </style>
</head>

<body class="bg-body-tertiary d-flex align-items-center justify-content-center vh-100">
    <div class="position-absolute top-0 end-0 p-3">
        <button id="darkModeToggle" class="btn btn-sm btn-outline-secondary border-0" title="Toggle Dark Mode">
            <i class="bi bi-moon-stars-fill"></i>
        </button>
    </div>
    <div class="card shadow" style="width: 400px;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-shield-lock-fill text-primary" style="font-size: 3rem;"></i>
                <h4 class="fw-bold mt-2">Recovery Portal</h4>
                <p class="text-muted small">Select a secure method to verify your identity.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-warning text-center small"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success text-center small"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <div class="alert alert-info small border-info text-start">
                    <i class="bi bi-info-circle-fill text-info"></i> <strong>How it works:</strong>
                    <ul class="mb-0 ps-3 mt-1 text-dark">
                        <li>Enter your <strong>Username</strong> or <strong>Email</strong> to locate your account.</li>
                        <li>You will be prompted to verify your identity using your preferred secure method (e.g., Authenticator App, Backup Codes, or Security Question).</li>
                        <li>Once verified, you can securely reset your password.</li>
                    </ul>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="step" value="1">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Username or Email</label>
                        <input type="text" name="username" class="form-control" placeholder="Enter your username" required autofocus maxlength="100">
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">Continue <i class="bi bi-arrow-right"></i></button>
                        <a href="login.php" class="btn btn-link text-decoration-none text-muted small mt-2">Back to Login</a>
                    </div>
                </form>
            <?php elseif ($step === 2): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="step" value="2">
                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-primary">Security Question:</label>
                        <div class="p-3 bg-light border rounded text-dark mb-3"><i class="bi bi-patch-question-fill me-1"></i> <?php echo htmlspecialchars($_SESSION['forgot_question'] ?? ''); ?></div>
                        <input type="text" name="security_answer" class="form-control form-control-lg" placeholder="Your Answer" required autofocus autocomplete="off" maxlength="255">
                    </div>
                    <div class="d-flex gap-2">
                        <a href="login.php" class="btn btn-secondary w-50">Cancel</a>
                        <button type="submit" class="btn btn-primary w-50">Verify <i class="bi bi-check-circle"></i></button>
                    </div>
                    <div class="text-center mt-3 border-top pt-2">
                        <button type="submit" name="try_another" class="btn btn-link text-decoration-none small text-muted p-0" formnovalidate>Try another way</button>
                    </div>
                </form>
            <?php elseif ($step === 'methods'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="step" value="methods">
                    <p class="fw-bold text-center mb-3">Choose how you want to sign in:</p>
                    <div class="d-grid gap-2 mb-3">
                        <?php if (!empty($_SESSION['forgot_question'])): ?>
                            <button type="submit" name="method" value="question" class="btn btn-outline-primary text-start px-3 py-2"><i class="bi bi-patch-question me-2"></i> Answer Security Question</button>
                        <?php endif; ?>
                        <?php if (!empty($_SESSION['forgot_has_auth'])): ?>
                            <button type="submit" name="method" value="authenticator" class="btn btn-outline-primary text-start px-3 py-2"><i class="bi bi-phone me-2"></i> Use Authenticator App</button>
                        <?php endif; ?>
                        <button type="submit" name="method" value="backup_code" class="btn btn-outline-dark text-start px-3 py-2"><i class="bi bi-file-earmark-lock me-2"></i> Enter an Offline Recovery Code</button>
                    </div>
                    <div class="d-grid">
                        <a href="login.php" class="btn btn-link text-muted text-decoration-none small">Cancel</a>
                    </div>
                </form>
            <?php elseif ($step === 'backup_code'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="step" value="backup_code">
                    <div class="mb-3 text-center">
                        <label class="form-label fw-bold">Enter 8-Character Recovery Code</label>
                        <input type="text" name="backup_code" class="form-control text-center fs-4 letter-spacing-2 font-monospace" maxlength="8" placeholder="A1B2C3D4" required autofocus style="text-transform: uppercase;">
                        <div class="form-text small mt-2">You generated these codes in your Profile Settings.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="login.php" class="btn btn-secondary w-50">Cancel</a>
                        <button type="submit" class="btn btn-dark w-50">Verify <i class="bi bi-check-circle"></i></button>
                    </div>
                </form>
            <?php elseif ($step === 'authenticator'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="step" value="authenticator">
                    <div class="mb-3 text-center">
                        <label class="form-label fw-bold">Enter Authenticator Code</label>
                        <input type="text" name="auth_code" class="form-control text-center fs-4 letter-spacing-2 font-monospace" maxlength="6" placeholder="123456" required autofocus pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <div class="form-text small mt-2">Open your Authenticator app to get the 6-digit code.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="login.php" class="btn btn-secondary w-50">Cancel</a>
                        <button type="submit" class="btn btn-primary w-50">Verify <i class="bi bi-check-circle"></i></button>
                    </div>
                </form>
            <?php elseif ($step === 3): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="step" value="3">
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Password</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="newPass" class="form-control" placeholder="Min 15 characters" required minlength="15" maxlength="128" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{15,}" oninput="updateStrength(this.value, 'strengthBar')">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass')"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="progress mt-1" style="height: 5px;">
                            <div id="strengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div class="form-text small text-muted">Requirements: 15+ chars, Uppercase, Lowercase, Number, Symbol.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confPass" class="form-control" placeholder="Repeat new password" required minlength="15" maxlength="128">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confPass')"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="match-msg" class="small mt-1 fw-bold text-danger" style="display:none;">
                            <i class="bi bi-x-circle"></i> Passwords do not match
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="login.php" class="btn btn-secondary w-50">Cancel</a>
                        <button type="submit" class="btn btn-success w-50"><i class="bi bi-shield-lock-fill"></i> Reset</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        <?php if ($wait > 0): ?>
            let timeLeft = <?php echo $wait; ?>;
            const timerEl = document.getElementById('countdown');

            if (timerEl) {
                const interval = setInterval(() => {
                    timeLeft--;
                    if (timeLeft <= 0) {
                        clearInterval(interval);
                        document.querySelector('.alert-warning').innerHTML = "✅ You can now request a new code.";
                    } else {
                        timerEl.innerText = timeLeft;
                    }
                }, 1000);
            }
        <?php endif; ?>

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

        function updateStrength(val, barId) {
            const bar = document.getElementById(barId);
            if (!bar) return;
            let score = 0;
            if (val.length >= 8) score++;
            if (val.length >= 12) score++;
            if (val.length >= 15) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[a-z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            let pct = Math.min(100, (score / 7) * 100);
            bar.style.width = pct + '%';
            bar.className = 'progress-bar ' + (score > 5 ? 'bg-success' : (score > 3 ? 'bg-warning' : 'bg-danger'));
        }

        const p1 = document.getElementById('newPass');
        const p2 = document.getElementById('confPass');
        const matchMsg = document.getElementById('match-msg');
        const btn = document.querySelector('button.btn-success');

        if (p1 && p2) {
            function checkMatch() {
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
                if (btn) btn.disabled = !match;
            }
            p1.addEventListener('input', checkMatch);
            p2.addEventListener('input', checkMatch);
        }
    </script>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
</body>

</html>