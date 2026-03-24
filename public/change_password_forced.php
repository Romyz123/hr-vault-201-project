<?php
require '../config/db.php';
require '../src/Security.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

if (!isset($_SESSION['temp_user_id'])) {
    header("Location: login.php");
    exit;
}

$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "❌ Security Token Mismatch.";
        goto end_post;
    }

    if (!isset($_POST['password'], $_POST['confirm'])) {
        $error = "❌ Missing required fields.";
        goto end_post;
    }

    $pass = $_POST['password'];
    $confirm = $_POST['confirm'];
    if ($pass !== $confirm) {
        $error = "❌ Passwords do not match.";
    } elseif (strlen($pass) > 128) {
        $error = "❌ Password is too long (Max 128 characters).";
    } elseif (strlen($pass) < 15 || !preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $pass)) {
        $error = "❌ Password must be 15+ chars, with Uppercase, Lowercase, Number & Symbol.";
    } else {
        // Fetch username to check against password
        $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $uStmt->execute([$_SESSION['temp_user_id']]);
        $uName = $uStmt->fetchColumn();

        if ($uName && stripos($pass, $uName) !== false) {
            $error = "❌ Password cannot contain your Username.";
        } else {
            // [MHI Security] Check History
            if (!$security->checkPasswordHistory($_SESSION['temp_user_id'], $pass)) {
                $error = "❌ Security Policy: You cannot reuse any of your last 3 passwords.";
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                // Update password and reset the timer (password_changed_at)
                $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?");
                if ($stmt->execute([$hash, $_SESSION['temp_user_id']])) {
                    $security->logPasswordHistory($_SESSION['temp_user_id'], $hash);
                    unset($_SESSION['temp_user_id']);
                    header("Location: login.php?msg=" . urlencode("✅ Password updated! Please login."));
                    exit;
                } else {
                    $error = "❌ Database error.";
                }
            }
        }
    }
}
end_post:
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Update Password</title>
    <link rel="icon" href="assets/images/tesp-logo.png" type="image/png">
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
        }
    </style>
</head>

<body class="d-flex align-items-center justify-content-center vh-100">
    <div class="position-absolute top-0 end-0 p-3 text-muted small font-monospace"><i class="bi bi-clock"></i> <span id="sessionTimer"></span></div>

    <div class="card shadow p-4" style="width: 400px;">
        <h4 class="mb-3 text-center text-danger">Password Expired</h4>
        <p class="text-muted small text-center">Your password is older than 45 days. Please update it to continue.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <div class="input-group">
                    <input type="password" name="password" id="newPass" class="form-control" required minlength="15" maxlength="128" placeholder="Min 15 chars, Upper, Lower, #, Symbol" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{15,}" title="Must be at least 15 characters, contain Uppercase, Lowercase, Number, and Symbol." oninput="updateStrength(this.value, 'strengthBar')">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass')"><i class="bi bi-eye"></i></button>
                </div>
                <div class="progress mt-1" style="height: 5px;">
                    <div id="strengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <div class="input-group">
                    <input type="password" name="confirm" id="confPass" class="form-control" required minlength="15" maxlength="128">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confPass')"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Update Password</button>
        </form>
    </div>
    <script>
        // [SECURITY] Auto-Logout Timer
        const timeoutDuration = <?php echo $clientTimeout * 1000; ?>;
        let timeLeft = timeoutDuration;

        function updateTimer() {
            timeLeft -= 1000;
            if (timeLeft <= 0) window.location.href = 'logout.php';
            const m = Math.floor(timeLeft / 60000);
            const s = Math.floor((timeLeft % 60000) / 1000);
            document.getElementById('sessionTimer').innerText = `${m}:${s.toString().padStart(2, '0')}`;
        }
        document.addEventListener('mousemove', () => timeLeft = timeoutDuration);
        document.addEventListener('keypress', () => timeLeft = timeoutDuration);
        setInterval(updateTimer, 1000);
        updateTimer();

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
            if (val.length >= 15) score++; // Length bonus
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++; // Symbol

            let pct = Math.min(100, (score / 6) * 100);
            bar.style.width = pct + '%';
            bar.className = 'progress-bar ' + (score > 4 ? 'bg-success' : (score > 2 ? 'bg-warning' : 'bg-danger'));
        }
    </script>
</body>

</html>