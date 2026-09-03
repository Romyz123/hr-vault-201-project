<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/GoogleAuthenticator.php';
session_start();

// OTP verification is disabled by default. Only allow access when an explicit 2FA flow was started.
if (!isset($_SESSION['partial_user_id']) || !isset($_SESSION['partial_login_at']) || time() - (int)$_SESSION['partial_login_at'] > 300) {
    unset($_SESSION['partial_user_id'], $_SESSION['partial_login_at'], $_SESSION['pending_totp_secret']);
    header("Location: login.php");
    exit;
}

// If the user account did not explicitly opt in to 2FA, do not allow this page to be used.
$userId = $_SESSION['partial_user_id'];
$stmt = $pdo->prepare("SELECT id, totp_secret, is_2fa_enabled FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userState = $stmt->fetch();
if (!$userState || (empty($userState['totp_secret']) && empty($userState['is_2fa_enabled']))) {
    unset($_SESSION['partial_user_id'], $_SESSION['partial_login_at'], $_SESSION['pending_totp_secret']);
    header("Location: login.php");
    exit;
}

$error = "";
$success = "";
$logger = new Logger($pdo);
$security = new Security($pdo); // [NEW] Init Security
$csrf_token = $security->generateCSRF(); // [SECURITY] Generate Token

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: login.php");
    exit;
}

$isFirstTimeSetup = false;
$otpauthUrl = '';
$manualSecret = '';
$issuer = 'TESP HR Vault';
$accountName = (string)($user['username'] ?? 'User');

// Generate a new secret if they don't have one yet (do not persist until verified)
$secret = $user['totp_secret'] ?? '';
if (empty($secret)) {
    $isFirstTimeSetup = true;
    // Only generate a new secret if we don't already have a pending one in the session
    if (empty($_SESSION['pending_totp_secret'])) {
        $_SESSION['pending_totp_secret'] = GoogleAuthenticator::generateSecret();
    }
    $secret = $_SESSION['pending_totp_secret'];
}

if ($isFirstTimeSetup) {
    $qrData = GoogleAuthenticator::getQRCodeDataUri($accountName, $secret, $issuer);
    $manualSecret = $qrData['secret'];
    $otpauthUrl = $qrData['uri'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SECURITY] Rate Limit IP (15 req/min) to slow down automated attacks
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'], 15, 60)) {
        $_SESSION['otp_error'] = "⚠️ Too many requests. Please wait a minute.";
        header("Location: verify_otp.php");
        exit;
    }
    // [SECURITY] CSRF Check
    elseif (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['otp_error'] = "❌ Security Token Mismatch. Please refresh and try again.";
        header("Location: verify_otp.php");
        exit;
    } elseif (isset($_POST['otp_code'])) {
        // [SECURITY] Max Attempts Check (Database-Backed Brute Force Protection)
        if ($user && ($user['failed_attempts'] ?? 0) >= 10) {
            $logger->log($userId, 'ACCOUNT_LOCKOUT', "Account locked after 10 failed 2FA attempts");

            // Lock account completely
            $pdo->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 10 YEAR) WHERE id = ?")->execute([$userId]);

            unset($_SESSION['partial_user_id']);
            header("Location: login.php?error=" . urlencode("❌ Account Locked due to too many failed attempts. Contact Administrator."));
            exit;
        }

        $code = strtoupper(trim($_POST['otp_code'] ?? ''));
        $isValid = false;
        $isBackupCode = false;

        // 1. Check if it is a 6-digit Authenticator Code
        if (strlen($code) === 6 && ctype_digit($code)) {
            $totpSecret = $_SESSION['pending_totp_secret'] ?? $user['totp_secret'];
            if ($totpSecret && GoogleAuthenticator::verifyCode($totpSecret, $code)) {
                $isValid = true;
                // Persist secret only after successful verification
                if (isset($_SESSION['pending_totp_secret'])) {
                    $pdo->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([$_SESSION['pending_totp_secret'], $userId]);
                    unset($_SESSION['pending_totp_secret']);
                }
            }
        }
        // 2. Check if it is an 8-character Backup Code
        elseif (strlen($code) === 8 && ctype_alnum($code)) {
            $recoveryCodes = json_decode($user['recovery_codes'] ?? '[]', true);
            if (is_array($recoveryCodes)) {
                foreach ($recoveryCodes as $index => $hash) {
                    if (password_verify($code, $hash)) {
                        $isValid = true;
                        $isBackupCode = true;
                        // Remove the used code so it cannot be used again
                        unset($recoveryCodes[$index]);
                        $recoveryCodes = array_values($recoveryCodes);
                        $pdo->prepare("UPDATE users SET recovery_codes = ? WHERE id = ?")->execute([json_encode($recoveryCodes), $userId]);
                        break;
                    }
                }
            }
        }

        if (!$isValid) {
            // Increment DB failed attempts
            $attempts = ($user['failed_attempts'] ?? 0) + 1;
            $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")->execute([$attempts, $userId]);
            $user['failed_attempts'] = $attempts; // Update local state

            $remaining = 10 - $attempts;
            $_SESSION['otp_error'] = "❌ Invalid OTP or Backup Code. ($remaining attempts remaining before lockout)";
            $logger->log($userId, 'LOGIN_FAIL_2FA', "Failed 2FA attempt");
            header("Location: verify_otp.php");
            exit;
        } else {
            // [SECURITY] Enforce 45-Day Password Expiry here as well
            try {
                $lastChange = new DateTime($user['password_changed_at'] ?? $user['created_at'] ?? 'now');
            } catch (Exception $e) {
                $logger->log($userId, '2FA_DATE_ERROR', 'Invalid password_changed_at/created_at: ' . ($user['password_changed_at'] ?? $user['created_at'] ?? 'empty'));
                unset($_SESSION['partial_user_id']);
                $_SESSION['temp_user_id'] = $user['id']; // Temp session
                header("Location: change_password_forced.php?reason=expired");
                exit;
            }

            $today = new DateTime();
            if ($today->diff($lastChange)->days > 45) {
                unset($_SESSION['partial_user_id']);
                $_SESSION['temp_user_id'] = $user['id']; // Temp session
                header("Location: change_password_forced.php?reason=expired");
                exit;
            }

            // SUCCESS: Log them in fully
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            unset($_SESSION['otp_attempts']); // [SECURITY] Reset counter on success

            // [NEW] Handle "Trust Device" (Remember Me)
            if (isset($_POST['trust_device'])) {
                $token = bin2hex(random_bytes(32));
                $hash = hash('sha256', $token);

                // [MHI 5.1.3] Privileged users (ADMIN) limited to 18 hours. Others 30 hours.
                $duration = ($user['role'] === 'ADMIN') ? (18 * 60 * 60) : (30 * 60 * 60);
                $expires = date('Y-m-d H:i:s', time() + $duration);
                $pdo->prepare("UPDATE users SET trusted_device_token = ?, trusted_device_expires = ? WHERE id = ?")
                    ->execute([$hash, $expires, $userId]);
                setcookie('hr_trust_device', $token, time() + $duration, "/", "", true, true);
            }

            // Reset failed attempts upon successful 2FA
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$userId]);

            unset($_SESSION['partial_user_id'], $_SESSION['partial_login_at']);

            $logMsg = $isBackupCode ? "2FA Verified Successfully using Backup Code" : "2FA Verified Successfully";
            $logger->log($user['id'], 'LOGIN_2FA', $logMsg);

            enforceSecurityQuestionSetup($pdo, true);

            header("Location: index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Verify 2FA</title>
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

<body class="bg-body-tertiary">
    <div class="position-absolute top-0 end-0 p-3">
        <button id="darkModeToggle" class="btn btn-sm btn-outline-secondary border-0" title="Toggle Dark Mode">
            <i class="bi bi-moon-stars-fill"></i>
        </button>
    </div>
    <div class="card p-4">
        <div class="text-center mb-4">
            <h4 class="fw-bold text-primary"><i class="bi bi-phone"></i> Authenticator App</h4>
            <?php if ($isFirstTimeSetup): ?>
                <p class="text-muted small"><strong>First Time Setup:</strong> Scan this QR code using an Authenticator app (Google Authenticator, Authy, or Microsoft Authenticator).</p>
                <div class="mb-3 d-flex flex-column align-items-center">
                    <div id="qrcode" class="p-2 bg-white border rounded" style="min-width: 160px; min-height: 160px;">
                        <noscript>Enable JavaScript to generate the QR code locally, or enter the key below manually.</noscript>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="saveQRCode()"><i class="bi bi-download"></i> Save QR Code</button>
                    </div>
                </div>
                <div class="mb-3 p-2 bg-light border rounded small text-center">
                    <strong>Can't scan?</strong> Enter this key manually:<br>
                    <span class="font-monospace fs-5 fw-bold text-primary letter-spacing-2"><?php echo htmlspecialchars($manualSecret); ?></span>
                </div>
                <p class="small text-danger fw-bold">Save this in your app before continuing!</p>
            <?php else: ?>
                <p class="text-muted small">Open your Authenticator app to get your code.</p>
            <?php endif; ?>
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
                <label class="form-label fw-bold">Enter 6-Digit Code or Backup Code</label>
                <input type="text" name="otp_code" class="form-control text-center fs-4 letter-spacing-2" maxlength="8" placeholder="123456 or A1B2C3D4" required autofocus autocomplete="off" style="text-transform: uppercase;">
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="trustDevice" name="trust_device">
                <label class="form-check-label small text-muted" for="trustDevice">Trust this device for 30 hours</label>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Verify & Login</button>
            </div>
        </form>
        <div class="text-center mt-3">
            <a href="logout.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Cancel Login</a>
        </div>
    </div>

    <?php if ($isFirstTimeSetup): ?>
        <script src="assets/qrcode.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Generate QR Code Offline
                var qrCodeDiv = document.getElementById("qrcode");
                var otpUrl = <?php echo json_encode($otpauthUrl); ?>;

                if (qrCodeDiv && otpUrl && typeof QRCode !== 'undefined') {
                    try {
                        // Create a temporary element to render the new QR
                        var temp = document.createElement('div');
                        new QRCode(temp, {
                            text: otpUrl,
                            width: 160,
                            height: 160,
                            colorDark: "#000000",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.M
                        });
                        qrCodeDiv.innerHTML = "";
                        while (temp.firstChild) qrCodeDiv.appendChild(temp.firstChild);
                    } catch (e) {
                        console.error("Local QR Render failed.", e);
                    }
                }
            });

            function saveQRCode() {
                var canvas = document.querySelector('#qrcode canvas');
                if (canvas) {
                    var link = document.createElement('a');
                    link.download = '2FA_QRCode.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                }
            }
        </script>
    <?php endif; ?>
    <script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>