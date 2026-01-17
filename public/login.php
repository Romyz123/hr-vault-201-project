<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

$message = '';

// 1. Handle "Unlock System" Request (The Fix)
if (isset($_POST['unlock_system'])) {
    $pdo->query("TRUNCATE TABLE rate_limits");
    $message = "<div class='alert alert-success'>✅ System Unlocked! You can login now.</div>";
}

// 2. Handle Login Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $security = new Security($pdo);
    
    // Check Rate Limit (Returns false if blocked)
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        $message = "
        <div class='alert alert-danger'>
            <strong>⛔ Too Many Requests!</strong><br>
            You are temporarily locked out.
            <form method='POST' class='mt-2'>
                <button type='submit' name='unlock_system' class='btn btn-warning btn-sm w-100'>
                    <i class='bi bi-unlock-fill'></i> Dev Mode: Force Unlock
                </button>
            </form>
        </div>";
    } else {
        // Normal Login Logic
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php");
            exit;
        } else {
            $message = "<div class='alert alert-danger'>❌ Invalid Username or Password</div>";
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
        
        <?php echo $message; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label text-secondary">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                    <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" name="login" class="btn btn-success btn-lg shadow-sm">
                    Secure Login <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>

        <div class="text-center mt-3">
            <small class="text-muted">Authorized Personnel Only</small>
        </div>
    </div>
</div>

</body>
</html>