<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

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
    } elseif (strlen($pass) < 12 || !preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $pass)) {
        $error = "❌ Password must be 12+ chars, with Uppercase, Lowercase, Number & Symbol.";
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        // Update password and reset the timer (password_changed_at)
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?");
        if ($stmt->execute([$hash, $_SESSION['temp_user_id']])) {
            unset($_SESSION['temp_user_id']);
            header("Location: login.php?msg=" . urlencode("✅ Password updated! Please login."));
            exit;
        } else {
            $error = "❌ Database error.";
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
    <link href="assets/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center justify-content-center vh-100">
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
                <input type="password" name="password" class="form-control" required minlength="12" placeholder="Min 12 chars, Upper, Lower, #, Symbol" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{12,}" title="Must be at least 12 characters, contain Uppercase, Lowercase, Number, and Symbol.">
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm" class="form-control" required minlength="12">
            </div>
            <button type="submit" class="btn btn-primary w-100">Update Password</button>
        </form>
    </div>
</body>

</html>