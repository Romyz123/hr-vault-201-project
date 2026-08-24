<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

$security = new Security($pdo);
$security->requireAuthentication();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$alertType = "";
$alertMsg = "";

function generateAuthenticatorSecret(int $length = 16): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $secret;
}

try {
    $pdo->query("ALTER TABLE users ADD COLUMN authenticator_secret VARCHAR(32) NULL AFTER is_2fa_enabled");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false && stripos($e->getMessage(), 'already exists') === false) {
        throw $e;
    }
}

// 1. FETCH CURRENT INFO
$stmt = $pdo->prepare("SELECT email, authenticator_secret, is_2fa_enabled FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();
$currentEmail = $currentUser['email'] ?? '';
$authenticatorSecret = $currentUser['authenticator_secret'] ?? '';
$authenticatorEnabled = !empty($currentUser['is_2fa_enabled']);

// 2. HANDLE EMAIL UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_email') {
    try {
        $security->checkCSRF($_POST['csrf_token'] ?? '');
    } catch (Exception $e) {
        $alertType = 'error';
        $alertMsg = 'Security token mismatch. Please refresh and try again.';
    }

    if ($alertType === '' && $alertMsg === '') {
        $new_email = trim($_POST['email'] ?? '');
        if (strlen($new_email) > 100) {
            $alertType = "error";
            $alertMsg = "❌ Email is too long (Max 100 characters).";
        } elseif (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$new_email, $_SESSION['user_id']]);
            if ($chk->rowCount() > 0) {
                $alertType = "error";
                $alertMsg = "❌ Email is already in use by another account.";
            } else {
                $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$new_email, $_SESSION['user_id']]);
                $alertType = "success";
                $alertMsg = "✅ Email address updated successfully.";
                $currentEmail = $new_email;
            }
        } else {
            $alertType = "error";
            $alertMsg = "❌ Invalid email format.";
        }
    }
}

// 3. HANDLE PASSWORD UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_pass') {
    try {
        $security->checkCSRF($_POST['csrf_token'] ?? '');
    } catch (Exception $e) {
        $alertType = 'error';
        $alertMsg = 'Security token mismatch. Please refresh and try again.';
    }

    if ($alertType === '' && $alertMsg === '') {
        $current_pass = $_POST['current_password'];
        $new_pass     = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            if (password_verify($current_pass, $user['password'])) {
                if ($new_pass === $confirm_pass) {
                    if (strlen($new_pass) < 12) {
                        $alertType = "error";
                        $alertMsg = "Password must be at least 12 characters.";
                    } elseif (strlen($new_pass) > 128) {
                        $alertType = "error";
                        $alertMsg = "Password is too long (Max 128 characters).";
                    } elseif (!preg_match('/[0-9]/', $new_pass)) {
                        $alertType = "error";
                        $alertMsg = "Password must contain at least one number.";
                    } elseif (!preg_match('/[\W]/', $new_pass)) {
                        $alertType = "error";
                        $alertMsg = "Password must contain at least one symbol (!@#$%).";
                    } else {
                        $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $update->execute([$new_hash, $_SESSION['user_id']]);
                        $alertType = "success";
                        $alertMsg = "Password updated successfully!";
                    }
                } else {
                    $alertType = "error";
                    $alertMsg = "New passwords do not match.";
                }
            } else {
                $alertType = "error";
                $alertMsg = "Current password is incorrect.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enable_authenticator') {
    try {
        $security->checkCSRF($_POST['csrf_token'] ?? '');
    } catch (Exception $e) {
        $alertType = 'error';
        $alertMsg = 'Security token mismatch. Please refresh and try again.';
    }
    if ($alertType === '' && $alertMsg === '') {
        $secret = $_POST['authenticator_secret'] ?? generateAuthenticatorSecret();
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $secret = substr($secret, 0, 32);
        $pdo->prepare("UPDATE users SET authenticator_secret = ?, is_2fa_enabled = 1 WHERE id = ?")
            ->execute([$secret, $_SESSION['user_id']]);
        $authenticatorSecret = $secret;
        $authenticatorEnabled = true;
        $alertType = 'success';
        $alertMsg = 'Authenticator app enabled successfully.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'disable_authenticator') {
    try {
        $security->checkCSRF($_POST['csrf_token'] ?? '');
    } catch (Exception $e) {
        $alertType = 'error';
        $alertMsg = 'Security token mismatch. Please refresh and try again.';
    }
    if ($alertType === '' && $alertMsg === '') {
        $pdo->prepare("UPDATE users SET authenticator_secret = NULL, is_2fa_enabled = 0 WHERE id = ?")
            ->execute([$_SESSION['user_id']]);
        $authenticatorSecret = '';
        $authenticatorEnabled = false;
        $alertType = 'success';
        $alertMsg = 'Authenticator app reset successfully.';
    }
}

if (!$authenticatorSecret) {
    $authenticatorSecret = generateAuthenticatorSecret();
}

$issuer = 'TESP HR Vault';
$accountName = $_SESSION['username'] ?? $currentEmail ?? 'User';
if ($accountName === '') {
    $accountName = 'User';
}

$otpauthLabel = $issuer . ':' . $accountName;
$otpauthUrl = 'otpauth://totp/' . rawurlencode($otpauthLabel) . '?secret=' . $authenticatorSecret . '&issuer=' . rawurlencode($issuer);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Profile Settings</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <script src="assets/qrcode.min.js"></script>
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
            <span class="navbar-text text-white">My Profile Settings</span>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row justify-content-center">

            <div class="col-md-6 mb-4">
                <!-- EMAIL SETTINGS -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-envelope-fill"></i> Recovery Email</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_email">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="input-group">
                                <input type="email" name="email" class="form-control" placeholder="Enter your email..." value="<?php echo htmlspecialchars($currentEmail); ?>" maxlength="100" required>
                                <button class="btn btn-info text-white" type="submit">Save Email</button>
                            </div>
                            <div class="form-text">Used for "Forgot Password" recovery.</div>
                        </form>
                    </div>
                </div>

                <!-- PASSWORD SETTINGS -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Change Password</h5>
                    </div>
                    <div class="card-body">

                        <form method="POST">
                            <input type="hidden" name="action" value="change_pass">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Current Password</label>
                                <div class="input-group">
                                    <input type="password" name="current_password" id="curPass" class="form-control" maxlength="128" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('curPass')"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label fw-bold">New Password</label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="newPass" class="form-control" minlength="12" maxlength="128" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass')"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confPass" class="form-control" minlength="12" maxlength="128" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confPass')"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success">Update Password</button>
                                <a href="index.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>

                    </div>
                </div>

                <div class="text-center mt-3 text-muted">
                    <small>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></small>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card shadow-sm border-info mb-4">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-shield-lock-fill"></i> Authenticator App (2FA)</h5>
                        <?php if ($authenticatorEnabled): ?>
                            <span class="badge bg-success rounded-pill">Configured</span>
                        <?php else: ?>
                            <span class="badge bg-secondary rounded-pill">Not configured</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">Use an app like Google Authenticator or Authy to generate secure codes for login and account recovery.</p>

                        <div class="text-center mb-3">
                            <div class="d-inline-block p-3 bg-white border rounded" id="authenticator-qr" style="width: 220px; height: 220px; display: flex; align-items: center; justify-content: center;">
                                <div class="small text-muted">Loading QR…</div>
                            </div>
                        </div>

                        <div class="text-center mb-3">
                            <form method="POST">
                                <input type="hidden" name="action" value="enable_authenticator">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="authenticator_secret" value="<?php echo htmlspecialchars($authenticatorSecret, ENT_QUOTES, 'UTF-8'); ?>">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-download"></i> Save QR Code</button>
                            </form>
                        </div>

                        <div class="p-3 border rounded bg-light text-center">
                            <strong class="d-block mb-2">Manual Setup Key:</strong>
                            <code class="fs-5 text-primary"><?php echo htmlspecialchars($authenticatorSecret, ENT_QUOTES, 'UTF-8'); ?></code>
                        </div>

                        <p class="mt-3 mb-3 text-center text-danger fw-semibold">Scan this QR code with your authenticator app.</p>

                        <form method="POST" class="mt-3">
                            <input type="hidden" name="action" value="disable_authenticator">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-arrow-counterclockwise"></i> Reset Authenticator</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill"></i> Security Advice</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">
                            <strong>User passwords are securely hashed.</strong> If you forget your password, you must contact the Admin Manager to reset it.
                        </p>
                        <ul class="text-muted small mb-0">
                            <li>Ensure your password is at least 12 characters long.</li>
                            <li>Avoid using easily guessable words like "123456" or your name.</li>
                            <li>Regularly updating your password helps protect the system.</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
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

        document.addEventListener('DOMContentLoaded', function () {
            const qrContainer = document.getElementById('authenticator-qr');
            if (!qrContainer) return;

            const qrValue = <?php echo json_encode($otpauthUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

            if (typeof QRCode === 'undefined') {
                qrContainer.innerHTML = '<div class="alert alert-warning mb-0 small">QR library failed to load.</div>';
                return;
            }

            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: qrValue,
                width: 190,
                height: 190,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        });

        <?php if ($alertMsg): ?>
            Swal.fire({
                icon: '<?php echo $alertType; ?>',
                title: '<?php echo ucfirst($alertType === "error" ? "Failed" : "Success"); ?>',
                text: '<?php echo $alertMsg; ?>',
                confirmButtonColor: '<?php echo $alertType === "error" ? "#dc3545" : "#198754"; ?>'
            });
        <?php endif; ?>
    </script>
</body>

</html>