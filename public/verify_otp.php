<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// Redirect if no partial login session
if (!isset($_SESSION['partial_user_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";
$success = "";
$logger = new Logger($pdo);
$security = new Security($pdo); // [NEW] Init Security
$csrf_token = $security->generateCSRF(); // [SECURITY] Generate Token

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SECURITY] Rate Limit IP (15 req/min) to slow down automated attacks
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'], 15, 60)) {
        $error = "⚠️ Too many requests. Please wait a minute.";
    }
    // [SECURITY] CSRF Check
    elseif (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "❌ Security Token Mismatch. Please refresh and try again.";
    }
    // [NEW] Handle Resend Request
    elseif (isset($_POST['action']) && $_POST['action'] === 'resend') {
        $userId = $_SESSION['partial_user_id'];
        // [FIX] Use DB time difference to avoid Timezone issues (PHP time vs MySQL NOW)
        $stmt = $pdo->prepare("SELECT email, TIMESTAMPDIFF(SECOND, NOW(), otp_expires) as seconds_remaining FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();
        $email = $userRow['email'] ?? null;
        $secondsRemaining = $userRow['seconds_remaining'] ?? 0;

        // 900s (15m) expiry. We block resend for first 60s.
        // So if remaining > 840, we are in the block window.
        if ($secondsRemaining > 840) {
            $wait = $secondsRemaining - 840;
            $error = "⏳ Please wait $wait seconds before resending.";
        } elseif ($email) {
            $otp = random_int(100000, 999999);            // [FIX] Increased expiry to 15 Minutes (900 seconds)
            $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")->execute([$otp, $userId]);

            // Send Email
            mail($email, "Login OTP", "Your new code is: $otp");
            $success = "✅ New code sent to " . htmlspecialchars($email);
            $logger->log($userId, 'OTP_RESEND', "User requested new OTP");
        } else {
            $error = "❌ Error: Email not found.";
        }
    } elseif (isset($_POST['otp_code'])) {
        // [SECURITY] Max Attempts Check (Brute Force Protection)
        if (!isset($_SESSION['otp_attempts'])) $_SESSION['otp_attempts'] = 0;

        if ($_SESSION['otp_attempts'] >= 5) {
            $logger->log($_SESSION['partial_user_id'], 'OTP_FAIL_LIMIT', "Exceeded max OTP attempts");
            unset($_SESSION['partial_user_id']); // Invalidate session
            unset($_SESSION['otp_attempts']);
            header("Location: login.php?error=" . urlencode("❌ Too many failed attempts. Please login again."));
            exit;
        }

        $code = trim($_POST['otp_code'] ?? '');
        // must be exactly 6 digits
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            $error = "❌ Invalid OTP Code.";
        } else {
            $userId = $_SESSION['partial_user_id'];

            // Verify OTP
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND otp_code = ? AND otp_expires > NOW()");
            $stmt->execute([$userId, $code]);
            $user = $stmt->fetch();

            if ($user) {
                // SUCCESS: Log them in fully
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                unset($_SESSION['otp_attempts']); // [SECURITY] Reset counter on success

                // Clear OTP
                $sql = "UPDATE users SET otp_code = NULL, otp_expires = NULL";

                // [NEW] Handle "Trust Device" (Remember Me)
                if (isset($_POST['trust_device'])) {
                    $token = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $token);

                    // [MHI 5.1.3] Privileged users (ADMIN) limited to 18 hours. Others 30 hours.
                    $duration = ($user['role'] === 'ADMIN') ? (18 * 60 * 60) : (30 * 60 * 60);
                    $expires = date('Y-m-d H:i:s', time() + $duration);

                    $sql .= ", trusted_device_token = '$hash', trusted_device_expires = '$expires'";
                    setcookie('hr_trust_device', $token, time() + $duration, "/", "", false, true);
                }

                $pdo->prepare("$sql WHERE id = ?")->execute([$userId]);
                unset($_SESSION['partial_user_id']);

                $logger->log($user['id'], 'LOGIN_2FA', "2FA Verified Successfully");
                header("Location: index.php");
                exit;
            } else {
                $_SESSION['otp_attempts']++;
                $remaining = 5 - $_SESSION['otp_attempts'];
                $error = "❌ Invalid or Expired OTP Code. ($remaining attempts remaining)";
                $logger->log($userId, 'LOGIN_FAIL_2FA', "Failed 2FA attempt");
            }
        }
    }
}

// [NEW] Calculate Throttle Time for JS Timer (DB Based)
$stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), otp_expires) as seconds_remaining FROM users WHERE id = ?");
$stmt->execute([$_SESSION['partial_user_id']]);
$secondsRemaining = $stmt->fetchColumn();

$timeLeft = 0;
if ($secondsRemaining > 840) {
    $timeLeft = $secondsRemaining - 840;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Verify 2FA</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .card {
            width: 100%;
            max-width: 400px;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body>
    <div class="card p-4">
        <div class="text-center mb-4">
            <h4 class="fw-bold text-primary">Two-Factor Authentication</h4>
            <p class="text-muted small">An OTP code has been sent to your email.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger text-center py-2"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success text-center py-2"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="mb-3">
                <label class="form-label fw-bold">Enter OTP Code</label>
                <input type="text" name="otp_code" class="form-control text-center fs-4 letter-spacing-2" maxlength="6" placeholder="123456" required autofocus pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="trustDevice" name="trust_device">
                <label class="form-check-label small text-muted" for="trustDevice">Trust this device for 30 hours</label>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Verify & Login</button>
            </div>
        </form>
        <form method="POST" class="text-center mt-3">
            <input type="hidden" name="action" value="resend">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" id="resendBtn" class="btn btn-link text-decoration-none p-0 small">Resend Code</button>
        </form>
        <div class="text-center mt-3">
            <a href="login.php" class="text-decoration-none small text-muted">Back to Login</a>
        </div>
    </div>
    <script>
        // Countdown Timer for Resend
        let timeLeft = <?php echo (int)$timeLeft; ?>;
        const btn = document.getElementById('resendBtn');

        if (btn && timeLeft > 0) {
            btn.disabled = true;
            btn.classList.add('text-muted'); // Visual cue
            const originalText = btn.innerText;

            const timer = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    btn.disabled = false;
                    btn.classList.remove('text-muted');
                    btn.innerText = originalText;
                } else {
                    btn.innerText = `Resend available in ${timeLeft}s`;
                    timeLeft--;
                }
            }, 1000);

            // Initial set
            btn.innerText = `Resend available in ${timeLeft}s`;
        }
    </script>
</body>

</html>