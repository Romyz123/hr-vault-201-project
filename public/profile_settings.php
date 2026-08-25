<?php
require '../config/db.php';
require '../src/Security.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

$alertType = "";
$alertMsg = "";

// 1. FETCH CURRENT INFO
try {
    $stmt = $pdo->prepare("SELECT email, security_question, recovery_codes, totp_secret FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
} catch (PDOException $e) {
    $currentUser = [];
    $alertType = 'error';
    $alertMsg = 'Database columns missing. Settings may not load correctly.';
}
$currentEmail = $currentUser['email'] ?? '';
$currentQuestion = $currentUser['security_question'] ?? '';
$hasCodes = !empty($currentUser['recovery_codes']) && $currentUser['recovery_codes'] !== '[]';
$currentTotpSecret = $currentUser['totp_secret'] ?? '';
$issuer = 'TESP HR Vault';
$accountName = (string)($_SESSION['username'] ?? $currentEmail ?? 'User');

function buildTotpProvisioning(string $secret, string $issuer, string $accountName): array
{
    $label = $issuer . ':' . $accountName;
    $query = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ], '', '&', PHP_QUERY_RFC3986);

    return [
        'manualSecret' => $secret,
        'otpauthUrl' => 'otpauth://totp/' . rawurlencode($label) . '?' . $query,
    ];
}

// Generate QR Code URL if secret exists
$otpauthUrl = '';
$manualSecret = '';
if (!empty($currentTotpSecret)) {
    ['manualSecret' => $manualSecret, 'otpauthUrl' => $otpauthUrl] = buildTotpProvisioning($currentTotpSecret, $issuer, $accountName);
}

// 2. HANDLE EMAIL UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_email') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch. Please refresh.";
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $passwordStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $passwordStmt->execute([$_SESSION['user_id']]);
        $passwordHash = $passwordStmt->fetchColumn();
        if (!$passwordHash || !password_verify($currentPassword, $passwordHash)) {
            $alertType = "error";
            $alertMsg = "❌ Current password is incorrect.";
        }

        if ($alertType !== "error") {
            $new_email = trim($_POST['email'] ?? '');
            if (strlen($new_email) > 100) {
                $alertType = "error";
                $alertMsg = "❌ Email is too long (Max 100 characters).";
            } elseif (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                // Check uniqueness
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
}

// 3. HANDLE PASSWORD UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_pass') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch. Please refresh.";
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        // 1. Fetch current user data
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            // 2. Verify Old Password
            if (password_verify($current_pass, $user['password'])) {

                // 3. Check if new passwords match
                if ($new_pass === $confirm_pass) {

                    // 4. Validate strength (Match Admin Policy: 12 chars, number, symbol)
                    if (strlen($new_pass) < 15) {
                        $alertType = "error";
                        $alertMsg = "Password must be at least 15 characters (MHI Policy).";
                    } elseif (strlen($new_pass) > 128) {
                        $alertType = "error";
                        $alertMsg = "Password is too long (Max 128 characters).";
                    } elseif (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $new_pass)) {
                        $alertType = "error";
                        $alertMsg = "Password must contain Uppercase, Lowercase, Number, and Symbol.";
                    } elseif (!empty($_SESSION['username']) && stripos($new_pass, $_SESSION['username']) !== false) {
                        $alertType = "error";
                        $alertMsg = "Password cannot contain your Username.";
                    } elseif ($new_pass === $current_pass) {
                        $alertType = "error";
                        $alertMsg = "Security Policy: You cannot reuse your current password.";
                    } else {
                        // [MHI Security] Check Frequency (Max 1 change per 24h)
                        if (!$security->checkPasswordFrequency($_SESSION['user_id'])) {
                            $alertType = "error";
                            $alertMsg = "Security Policy: You cannot change your password more than once in 24 hours.";
                        }
                        // [MHI Security] Check History (No reuse of last 3)
                        elseif (!$security->checkPasswordHistory($_SESSION['user_id'], $new_pass)) {
                            $alertType = "error";
                            $alertMsg = "Security Policy: You cannot reuse any of your last 3 passwords.";
                        } else {
                            // 5. Update Password & History
                            $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                            $pdo->beginTransaction();
                            try {
                                $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), reset_token = NULL, reset_expires = NULL, trusted_device_token = NULL, trusted_device_expires = NULL WHERE id = ?")->execute([$new_hash, $_SESSION['user_id']]);
                                $security->logPasswordHistory($_SESSION['user_id'], $new_hash);
                                $pdo->commit();
                                $alertType = "success";
                                $alertMsg = "Password updated successfully!";
                            } catch (Exception $e) {
                                $pdo->rollBack();
                                $alertType = "error";
                                $alertMsg = "Failed to update password. Please try again.";
                            }
                        }
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

// 4. HANDLE SECURITY QUESTION UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_security_question') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch. Please refresh.";
    } else {
        $question = trim($_POST['security_question'] ?? '');
        if ($question === 'custom') {
            $question = trim($_POST['custom_question'] ?? '');
        }
        $answer = trim($_POST['security_answer'] ?? '');

        if (empty($question) || empty($answer)) {
            $alertType = "error";
            $alertMsg = "❌ Both Security Question and Answer are required.";
        } elseif (strlen($question) > 255 || strlen($answer) > 255) {
            $alertType = "error";
            $alertMsg = "❌ Question or Answer is too long (Max 255 characters).";
        } else {
            $hashed_answer = password_hash(strtolower($answer), PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET security_question = ?, security_answer = ? WHERE id = ?")->execute([$question, $hashed_answer, $_SESSION['user_id']]);
            $alertType = "success";
            $alertMsg = "✅ Security Question updated successfully.";
            $currentQuestion = $question;
        }
    }
}

// 5. GENERATE RECOVERY CODES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_codes') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch.";
    } else {
        $rawCodes = [];
        $hashedCodes = [];
        for ($i = 0; $i < 6; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)); // e.g. A1B2C3D4
            $rawCodes[] = $code;
            $hashedCodes[] = password_hash($code, PASSWORD_BCRYPT);
        }
        $pdo->prepare("UPDATE users SET recovery_codes = ? WHERE id = ?")->execute([json_encode($hashedCodes), $_SESSION['user_id']]);
        $_SESSION['new_recovery_codes'] = $rawCodes;
        $hasCodes = true;
        $alertType = "success";
        $alertMsg = "✅ 6 New Recovery Codes generated successfully!";
    }
}

// 6. SETUP/RESET GOOGLE AUTHENTICATOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup_2fa_app') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch.";
    } else {
        require_once '../src/GoogleAuthenticator.php';
        $newSecret = GoogleAuthenticator::generateSecret();
        $pdo->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([$newSecret, $_SESSION['user_id']]);
        $currentTotpSecret = $newSecret;
        ['manualSecret' => $manualSecret, 'otpauthUrl' => $otpauthUrl] = buildTotpProvisioning($currentTotpSecret, $issuer, $accountName);
        $alertType = "success";
        $alertMsg = "✅ Authenticator App Secret generated! Please scan the new QR code.";
    }
}

// 7. REMOVE TRUSTED DEVICE (if implemented)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_device') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alertType = "error";
        $alertMsg = "❌ Security Token Mismatch.";
    } else {
        $deviceId = trim($_POST['device_id'] ?? '');
        if (empty($deviceId)) {
            $alertType = "error";
            $alertMsg = "❌ Invalid device selected.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET trusted_device_token = NULL, trusted_device_expires = NULL WHERE id = ?");
            if ($stmt->execute([$_SESSION['user_id']])) {
                $alertType = "success";
                $alertMsg = "✅ Trusted device removed.";
            } else {
                $alertType = "error";
                $alertMsg = "❌ Failed to remove the trusted device.";
            }
        }
    }
}
?>
<?php include 'header.php'; ?>

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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="password" name="current_password" class="form-control mb-2" placeholder="Current password" autocomplete="current-password" required>
                        <div class="input-group">
                            <input type="email" name="email" class="form-control" placeholder="Enter your email..." value="<?php echo htmlspecialchars($currentEmail); ?>" maxlength="100" required>
                            <button class="btn btn-info text-white" type="submit">Save Email</button>
                        </div>
                        <div class="form-text">Used for "Forgot Password" recovery.</div>
                    </form>
                </div>
            </div>

            <!-- BACKUP CODES SETTINGS -->
            <div class="card shadow border-dark mb-4">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-lock"></i> Offline Recovery Codes</h5>
                    <?php if ($hasCodes): ?>
                        <span class="badge bg-success">Active</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Not Set</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Recovery codes allow you to securely recover your account if you forget your password. These are 100% offline and do not require SMS or Face ID.</p>

                    <?php if (isset($_SESSION['new_recovery_codes'])): ?>
                        <div class="alert alert-warning border-warning shadow-sm">
                            <h6 class="fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Save these codes now!</h6>
                            <p class="small mb-2">They will only be shown this one time. Print or copy them to a safe place. Each code can only be used once.</p>
                            <div class="row text-center font-monospace fs-5 fw-bold">
                                <?php foreach ($_SESSION['new_recovery_codes'] as $c): ?>
                                    <div class="col-6 mb-2"><span class="bg-white px-3 py-1 border rounded d-block"><?php echo $c; ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php unset($_SESSION['new_recovery_codes']); ?>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('Generate new codes? Any existing codes will immediately become invalid.');">
                        <input type="hidden" name="action" value="generate_codes">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="d-grid">
                            <button type="submit" class="btn btn-outline-dark fw-bold"><i class="bi bi-arrow-repeat"></i> Generate 6 New Codes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- SECURITY QUESTION SETTINGS -->
            <div class="card shadow mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-patch-question-fill"></i> Security Question</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_security_question">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Select a Question</label>
                            <select name="security_question" class="form-select" required>
                                <option value="" disabled <?php echo empty($currentQuestion) ? 'selected' : ''; ?>>-- Choose a question --</option>
                                <?php
                                $questions = [
                                    "What was the name of your first pet?",
                                    "What is your mother's maiden name?",
                                    "What was the name of your elementary school?",
                                    "What is the name of the town where you were born?",
                                    "What is your favorite childhood movie?"
                                ];
                                $isCustom = !empty($currentQuestion) && !in_array($currentQuestion, $questions);
                                foreach ($questions as $q) {
                                    $sel = ($currentQuestion === $q) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($q) . "\" $sel>" . htmlspecialchars($q) . "</option>";
                                }
                                ?>
                                <option value="custom" <?php echo $isCustom ? 'selected' : ''; ?>>-- Custom Question --</option>
                            </select>
                        </div>

                        <div class="mb-3" id="customQuestionDiv" style="<?php echo $isCustom ? 'display: block;' : 'display: none;'; ?>">
                            <label class="form-label fw-bold">Custom Question</label>
                            <input type="text" name="custom_question" id="customQuestionInput" class="form-control" placeholder="Type your own question..." maxlength="255" value="<?php echo $isCustom ? htmlspecialchars($currentQuestion) : ''; ?>" <?php echo $isCustom ? 'required' : ''; ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Your Answer</label>
                            <div class="input-group">
                                <input type="password" name="security_answer" id="secAnswer" class="form-control" placeholder="<?php echo !empty($currentQuestion) ? 'Enter new answer to update...' : 'Enter answer...'; ?>" required maxlength="255">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('secAnswer')"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text">Answers are not case-sensitive, but spaces matter.</div>
                        </div>

                        <div class="d-grid">
                            <button class="btn btn-warning fw-bold text-dark" type="submit">Save Security Question</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- GOOGLE AUTHENTICATOR SETTINGS -->
            <div class="card shadow border-primary mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-phone"></i> Authenticator App (2FA)</h5>
                    <?php if (!empty($currentTotpSecret)): ?>
                        <span class="badge bg-success">Configured</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Not Configured</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Use an app like Google Authenticator or Authy to generate secure codes for login and account recovery.</p>

                    <?php if (!empty($currentTotpSecret) && !empty($otpauthUrl)): ?>
                        <div class="text-center mb-3 d-flex flex-column align-items-center">
                            <!-- Dedicated container for local JS rendering -->
                            <div
                                id="qrcode"
                                data-otp="<?php echo htmlspecialchars($otpauthUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                class="p-2 bg-white border rounded mb-2 d-flex justify-content-center align-items-center"
                                style="width: 176px; height: 176px;">
                                <span class="small text-muted">Generating QR...</span>
                            </div>

                            <div class="mb-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="saveQRCode()">
                                    <i class="bi bi-download"></i> Save QR Code
                                </button>
                            </div>

                            <div class="p-2 bg-light border rounded small mt-2 mb-3 text-center">
                                <strong>Manual Setup Key:</strong><br>
                                <span class="font-monospace fs-5 fw-bold text-primary">
                                    <?php echo htmlspecialchars($manualSecret, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>

                            <p class="small text-danger fw-bold mb-0">
                                Scan this QR code with your authenticator app.
                            </p>
                        </div>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('Generate a new secret? Any existing Authenticator setup for this account will stop working.');">
                        <input type="hidden" name="action" value="setup_2fa_app">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="d-grid">
                            <button type="submit" class="btn btn-outline-primary fw-bold">
                                <i class="bi bi-qr-code-scan"></i> <?php echo empty($currentTotpSecret) ? 'Set Up Authenticator' : 'Reset Authenticator'; ?>
                            </button>
                        </div>
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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                                <input type="password" name="new_password" id="newPass" class="form-control" minlength="15" maxlength="128" required pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{15,}" title="Must be at least 15 characters, contain Uppercase, Lowercase, Number, and Symbol.">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('newPass')"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="progress mt-1" style="height: 5px;">
                                <div id="strengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                            </div>
                            <div class="mt-2 ps-1 small">
                                <div id="rule-len" class="text-muted mb-1"><i class="bi bi-circle"></i> At least 15 characters</div>
                                <div id="rule-let" class="text-muted mb-1"><i class="bi bi-circle"></i> Contains a letter</div>
                                <div id="rule-num" class="text-muted mb-1"><i class="bi bi-circle"></i> Contains a number (0-9)</div>
                                <div id="rule-sym" class="text-muted mb-1"><i class="bi bi-circle"></i> Contains a symbol (!@#$)</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confPass" class="form-control" minlength="15" maxlength="128" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confPass')"><i class="bi bi-eye"></i></button>
                            </div>
                            <div id="match-msg" class="small mt-1 fw-bold text-danger" style="display:none;">
                                <i class="bi bi-x-circle"></i> Passwords do not match
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" id="updatePassBtn" class="btn btn-success" disabled>Update Password</button>
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
            <div class="card shadow-sm border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill"></i> Security Advice</h5>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        <strong>User passwords are securely hashed.</strong> If you forget your password, you must contact the Admin Manager to reset it.
                    </p>
                    <ul class="text-muted small mb-0">
                        <li>Ensure your password is at least 15 characters long.</li>
                        <li>Avoid using easily guessable words like "123456" or your name.</li>
                        <li>Regularly updating your password helps protect the system.</li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

    <div class="row justify-content-center mt-2">
        <div class="col-md-11">
            <!-- BIOMETRICS SETTINGS -->
            <div class="card shadow border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-bounding-box"></i> Biometric Devices (Face ID / Touch ID) (FOR FUTURE UPGRADE AND DEVELOPMENT)</h5>
                    <button class="btn btn-light btn-sm fw-bold text-primary" onclick="registerBiometrics()"><i class="bi bi-plus-circle-fill"></i> Register This Device (FOR FUTURE UPGRADE AND DEVELOPMENT)</button>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Device Registration Date (FOR FUTURE UPGRADE AND DEVELOPMENT)</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $registeredDevices = []; ?>
                            <?php if (empty($registeredDevices)): ?>
                                <tr>
                                    <td colspan="2" class="text-center text-muted p-3">No biometric devices registered.</td>
                                </tr>
                                <?php else: foreach ($registeredDevices as $dev): ?>
                                    <tr>
                                        <td class="align-middle">Registered on <?php echo date('M d, Y h:i A', strtotime($dev['created_at'])); ?></td>
                                        <td class="text-end">
                                            <form method="POST" onsubmit="return confirm('Remove this device?')">
                                                <input type="hidden" name="action" value="remove_device">
                                                <input type="hidden" name="device_id" value="<?php echo htmlspecialchars($dev['id']); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap.bundle.min.js"></script>

<form id="webauthnForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="register_webauthn">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="credential_id" id="webauthn_cred_id">
</form>

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

    const secQuestionSelect = document.querySelector('select[name="security_question"]');
    const customQDiv = document.getElementById('customQuestionDiv');
    const customQInput = document.getElementById('customQuestionInput');

    if (secQuestionSelect) {
        secQuestionSelect.addEventListener('change', function() {
            if (this.value === 'custom') {
                customQDiv.style.display = 'block';
                customQInput.required = true;
            } else {
                customQDiv.style.display = 'none';
                customQInput.required = false;
            }
        });
    }

    <?php if ($alertMsg): ?>
        Swal.fire({
            icon: '<?php echo $alertType; ?>',
            title: '<?php echo ucfirst($alertType === "error" ? "Failed" : "Success"); ?>',
            text: <?php echo json_encode($alertMsg); ?>,
            confirmButtonColor: '<?php echo $alertType === "error" ? "#dc3545" : "#198754"; ?>'
        });
    <?php endif; ?>

    const p1 = document.getElementById('newPass');
    const p2 = document.getElementById('confPass');
    const btn = document.getElementById('updatePassBtn');
    const matchMsg = document.getElementById('match-msg');
    const strengthBar = document.getElementById('strengthBar');

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

            for (const key in rules) {
                const rule = rules[key];
                const icon = rule.el.querySelector('i');
                if (rule.regex.test(val)) {
                    rule.el.classList.add('text-success', 'fw-bold');
                    rule.el.classList.remove('text-muted');
                    icon.classList.replace('bi-circle', 'bi-check-circle-fill');
                    score++;
                } else {
                    rule.el.classList.remove('text-success', 'fw-bold');
                    rule.el.classList.add('text-muted');
                    icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                    allValid = false;
                }
            }

            let width = (score / 4) * 100;
            let color = 'red';
            if (score === 2) color = 'orange';
            if (score === 3) color = '#ffc107';
            if (score === 4) color = '#198754';

            strengthBar.style.width = width + '%';
            strengthBar.style.backgroundColor = color;

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

    function registerBiometrics() {
        Swal.fire({
            icon: 'info',
            title: 'Coming Soon',
            text: 'Biometric registration is not yet available. This feature will be enabled in a future release.',
            confirmButtonColor: '#0d6efd'
        });
    }
</script>

<?php if (!empty($currentTotpSecret) && !empty($otpauthUrl)): ?>
    <script src="assets/qrcode.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const qrContainer = document.getElementById('qrcode');
            const otpUrl = <?php echo json_encode($otpauthUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            if (!qrContainer || !otpUrl) return;

            if (typeof QRCode === 'undefined') {
                qrContainer.innerHTML = '<span class="text-danger small">QR library failed to load.</span>';
                return;
            }

            try {
                qrContainer.innerHTML = '';
                new QRCode(qrContainer, {
                    text: otpUrl,
                    width: 160,
                    height: 160,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });
            } catch (error) {
                console.error('QR generation failed:', error);
                qrContainer.innerHTML = '<span class="text-danger small">Failed to generate QR code.</span>';
            }
        });

        function saveQRCode() {
            const container = document.getElementById('qrcode');
            if (!container) return;

            const canvas = container.querySelector('canvas');
            const image = container.querySelector('img');
            const imageUrl = canvas ? canvas.toDataURL('image/png') : (image ? image.src : null);

            if (!imageUrl) {
                alert('The QR code has not finished rendering.');
                return;
            }

            const link = document.createElement('a');
            link.href = imageUrl;
            link.download = '2FA_QRCode.png';
            document.body.appendChild(link);
            link.click();
            link.remove();
        }
    </script>
<?php endif; ?>

</html>