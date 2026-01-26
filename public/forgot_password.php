<?php
require '../config/db.php';
session_start();

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    
    // 1. Check if email exists
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // 2. Generate 6-Digit OTP
        $token = (string)random_int(100000, 999999);
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // 3. Save to DB
        $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")->execute([$token, $expires, $user['id']]);

        // 4. Send Email (Uses PHP mail() - requires SMTP setup in php.ini)
        // NOTE: On Localhost XAMPP, mail() might fail without configuration.
        // We provide a fallback link for testing purposes.
        
        // Fix path slashes for Windows/XAMPP to ensure link works
        $path = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        $link = "http://" . $_SERVER['HTTP_HOST'] . $path . "/reset_password.php?token=$token&email=" . urlencode($email);
        
        $subject = "Reset Code: $token";
        
        // HTML Email Content
        $message = "<h3>Hi " . htmlspecialchars($user['username']) . ",</h3>";
        $message .= "<p>Use the code below to reset your password:</p>";
        $message .= "<h1 style='background:#eee; padding:10px; display:inline-block; letter-spacing:5px;'>$token</h1>";
        $message .= "<p>Or click this link: <a href='$link'>Reset Password</a></p>";
        $message .= "<p><small>Link expires in 1 hour. If you did not request this, please ignore this email.</small></p>";

        // HTML Headers
        $headers  = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: HR System <no-reply@hrsystem.com>" . "\r\n";

        $emailSent = @mail($email, $subject, $message, $headers);

        // [LOGIC] Handle Localhost vs Production
        if (in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'])) {
            $msg = "✅ <strong>(Localhost Demo):</strong> Your Code is <h1 class='display-4 fw-bold text-primary'>$token</h1>
                    <p>Since you are on localhost, the email might not send. You can enter this code manually.</p>
                    <a href='reset_password.php?token=$token&email=" . urlencode($email) . "' class='btn btn-success mt-2'>Go to Verification Page</a>";
        } 
        elseif ($emailSent) {
            // Redirect to the code entry page immediately
            header("Location: reset_password.php?sent=1&email=" . urlencode($email));
            exit;
        } 
        else {
            $error = "❌ Email failed to send. Please check your mail server settings.";
        }
    } else {
        $error = "❌ Email not found in our system.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">

<div class="card shadow" style="width: 400px;">
    <div class="card-header bg-dark text-white text-center">
        <h5 class="mb-0">Password Recovery</h5>
    </div>
    <div class="card-body">
        <?php if($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <p class="text-muted small">Enter your registered email address. We'll send you a code to reset your password.</p>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">Email Address</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Send the Code</button>
            </div>
        </form>
    </div>
    <div class="card-footer text-center">
        <a href="login.php" class="text-decoration-none small">Back to Login</a>
        <span class="mx-2 text-muted">|</span>
        <a href="reset_password.php" class="text-decoration-none small fw-bold">I already have a code</a>
    </div>
</div>

</body>
</html>