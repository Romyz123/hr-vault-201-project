<?php
require '../config/db.php';
session_start();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    // [VALIDATION]
    if (strlen($email) > 100) {
        $error = "❌ Email is too long (Max 100 characters).";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "❌ Invalid email format.";
    } else {
        // 1. Check User
        try {
            $stmt = $pdo->prepare("SELECT id, username, last_otp_sent FROM users WHERE email = ?");
            $stmt->execute([$email]);
        } catch (PDOException $e) {
            // [AUTO-FIX] Missing columns? Add them.
            $pdo->exec("ALTER TABLE users ADD COLUMN last_otp_sent DATETIME NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL DEFAULT NULL");
            // Retry
            $stmt = $pdo->prepare("SELECT id, username, last_otp_sent FROM users WHERE email = ?");
            $stmt->execute([$email]);
        }
        $user = $stmt->fetch();

        if ($user) {
            // 2. Rate Limit (60 seconds)
            $canSend = true;
            if ($user['last_otp_sent']) {
                $last = strtotime($user['last_otp_sent']);
                $diff = time() - $last;
                if ($diff < 60) {
                    $canSend = false;
                    $wait = 60 - $diff;
                    $error = "⏳ Please wait $wait seconds before requesting a new code.";
                }
            }

            if ($canSend) {
                // 3. Generate OTP
                $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                // 4. Update DB
                $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ?, last_otp_sent = NOW() WHERE id = ?")
                    ->execute([$otp, $expires, $user['id']]);

                // 5. Send Email
                $subject = "Password Reset Code - HR System";
                $message = "Your OTP code is: $otp\n\nThis code expires in 15 minutes.";
                $headers = "From: HR System <no-reply@hrsystem.com>";

                if (@mail($email, $subject, $message, $headers)) {
                    header("Location: reset_password.php?email=" . urlencode($email) . "&sent=1");
                    exit;
                } else {
                    $error = "❌ Failed to send email. Server configuration issue.";
                }
            }
        } else {
            $error = "❌ No account found with that email address.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f4f6f9;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }
    </style>
</head>

<body>
    <div class="card shadow" style="width: 400px;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-envelope-paper-fill text-primary" style="font-size: 3rem;"></i>
                <h4 class="fw-bold mt-2">Forgot Password?</h4>
                <p class="text-muted small">Enter your email to receive a reset code.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-warning text-center small"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <input type="email" name="email" class="form-control" placeholder="Enter your email" required autofocus value="<?php echo htmlspecialchars($_GET['email'] ?? ''); ?>" maxlength="100">
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Send Code</button>
                    <a href="login.php" class="btn btn-link text-decoration-none mt-2">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</body>

</html>