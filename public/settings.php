<?php
// public/settings.php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Admin Only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$security = new Security($pdo);
$logger = new Logger($pdo);
$csrf_token = $security->generateCSRF();

$msg = "";
$error = "";

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $security->checkCSRF($_POST['csrf_token']);

        // [FIX] Define checkboxes and handle unchecked states (which aren't sent in POST)
        $checkboxes = ['maintenance_mode', 'backup_include_vault', 'staff_direct_approval'];
        foreach ($checkboxes as $cb) {
            $value = isset($_POST['settings'][$cb]) ? '1' : '0';
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$cb, $value, $value]);
        }

        // Loop through all posted settings
        foreach ($_POST['settings'] as $key => $value) {
            // Basic validation
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key); // Sanitize key
            $value = trim($value);

            // [FIX] Skip checkboxes as they are already handled above
            if (in_array($key, $checkboxes)) {
                continue;
            }

            // [NEW from user code] Handle backup password
            if ($key === 'backup_password') {
                if (isset($_POST['clear_backup_password'])) {
                    $value = '';
                } elseif (!empty($value)) {
                    if (strlen($value) > 50) {
                        $error = "ZIP Password is too long (Max 50 chars).";
                        continue;
                    }
                    if (strlen($value) < 8) {
                        $error = "ZIP Password must be at least 8 characters.";
                        continue;
                    }
                } else {
                    // Keep existing if empty and not cleared
                    $stmt_pass = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_password'");
                    $stmt_pass->execute();
                    $value = $stmt_pass->fetchColumn() ?: '';
                }
            }

            // Specific validation for timeouts (must be numeric, in seconds)
            if (strpos($key, 'timeout') !== false || strpos($key, 'interval') !== false) {
                if (!is_numeric($value) || (int)$value < 10) {
                    $error = "Timeout/Interval values must be numeric and at least 10 seconds.";
                    continue; // Skip this setting
                }
                $value = (int)$value;
            }

            // Use INSERT ... ON DUPLICATE KEY UPDATE for efficiency
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        if (empty($error)) {
            $msg = "✅ Settings updated successfully!";
            $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', 'System settings were updated.');
            header("Location: settings.php?msg=" . urlencode($msg));
            exit;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// 3. FETCH CURRENT SETTINGS
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist, use defaults
    $error = "Could not load settings. Please run DB Status check from the Admin dashboard.";
}

// Set defaults if not in DB
$serverTimeout = $settings['session_timeout_server'] ?? 1800;
$clientTimeout = $settings['session_timeout_client'] ?? 900;
$refreshInterval = $settings['auto_refresh_interval'] ?? 60;
$maintMode = $settings['maintenance_mode'] ?? '0';

// [NEW from user code]
$staffDirect = ($settings['staff_direct_approval'] ?? '0') === '1';
$defProject  = $settings['default_project_name'] ?? '';
$marginL     = $settings['bulk_margin_left'] ?? '30';
$marginR     = $settings['bulk_margin_right'] ?? '20';

$backupDay = $settings['backup_day'] ?? 'Fri';
$backupTime = $settings['backup_time'] ?? '00:00';
$backupPath = $settings['backup_path'] ?? '';
$backupPass = $settings['backup_password'] ?? '';
$backupVault = $settings['backup_include_vault'] ?? '0';
$backupEmail = $settings['backup_alert_email'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-sliders"></i> System Settings</span>
            <span class="navbar-text text-white-50 ms-3 font-monospace small"><i class="bi bi-clock"></i> <span id="sessionTimer"></span></span>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="row">
                <div class="col-lg-12">
                    <!-- Session Settings -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Session & Inactivity Timeouts</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="server_timeout" class="form-label fw-bold">Server Session Lifetime</label>
                                    <select id="server_timeout" name="settings[session_timeout_server]" class="form-select">
                                        <option value="1800" <?php echo ($serverTimeout == 1800) ? 'selected' : ''; ?>>30 Minutes (MHI Standard)</option>
                                        <option value="3600" <?php echo ($serverTimeout == 3600) ? 'selected' : ''; ?>>60 Minutes</option>
                                        <option value="7200" <?php echo ($serverTimeout == 7200) ? 'selected' : ''; ?>>2 Hours</option>
                                        <option value="14400" <?php echo ($serverTimeout == 14400) ? 'selected' : ''; ?>>4 Hours</option>
                                    </select>
                                    <div class="form-text">
                                        The maximum time a session is valid on the server. After this, the user is forced to log in again, regardless of activity.
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="client_timeout" class="form-label fw-bold">Client Inactivity Timer</label>
                                    <select id="client_timeout" name="settings[session_timeout_client]" class="form-select">
                                        <option value="600" <?php echo ($clientTimeout == 600) ? 'selected' : ''; ?>>10 Minutes</option>
                                        <option value="900" <?php echo ($clientTimeout == 900) ? 'selected' : ''; ?>>15 Minutes (Recommended)</option>
                                        <option value="1200" <?php echo ($clientTimeout == 1200) ? 'selected' : ''; ?>>20 Minutes</option>
                                        <option value="1800" <?php echo ($clientTimeout == 1800) ? 'selected' : ''; ?>>30 Minutes</option>
                                    </select>
                                    <div class="form-text">
                                        The time of user inactivity (no mouse/keyboard) before the dashboard automatically logs them out.
                                        <br>
                                        <span class="text-danger">Warning:</span> This must be less than the Server Session Lifetime.
                                        If set higher, the server will log out the user before the client-side timer.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- [NEW from user code] Permissions -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-shield-check"></i> Permissions & Access</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="staffDirect" name="settings[staff_direct_approval]" value="1" <?php echo $staffDirect ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="staffDirect">Allow Staff Direct Edit/Add</label>
                                <div class="form-text text-muted">
                                    If <strong>ON</strong>: Staff changes are saved immediately.<br>
                                    If <strong>OFF</strong>: Staff changes create a "Request" that requires Admin approval.
                                </div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="maintMode" name="settings[maintenance_mode]" value="1" <?php echo ($maintMode === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-danger" for="maintMode">Enable Maintenance Mode</label>
                                <div class="form-text text-muted">If <strong>ON</strong>: Only ADMINS can log in. All other users will be blocked.</div>
                            </div>
                        </div>
                    </div>

                    <!-- [NEW from user code] Document Defaults -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-file-earmark-ruled"></i> Document Defaults</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Default Project Name</label>
                                <input type="text" name="settings[default_project_name]" class="form-control" value="<?php echo htmlspecialchars($defProject); ?>" placeholder="e.g. MRT-3 Rehabilitation Project" maxlength="100" pattern="[a-zA-Z0-9\s\-\.\(\)]+" title="Allowed: Letters, Numbers, Spaces, - . ( )">
                                <div class="form-text">Auto-fills the Project Name in contracts.</div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label fw-bold">Bulk Print Margin (Left)</label><input type="number" name="settings[bulk_margin_left]" class="form-control" value="<?php echo htmlspecialchars($marginL); ?>" min="0" max="200"></div>
                                <div class="col-6"><label class="form-label fw-bold">Bulk Print Margin (Right)</label><input type="number" name="settings[bulk_margin_right]" class="form-control" value="<?php echo htmlspecialchars($marginR); ?>" min="0" max="200"></div>
                            </div>
                            <div class="form-text mb-3">Adjusts the side spacing for bulk printed contracts (in pixels).</div>
                        </div>
                    </div>

                    <!-- General Settings -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-gear-wide-connected"></i> General Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="refresh_interval" class="form-label fw-bold">Dashboard Auto-Refresh Interval (seconds)</label>
                                    <input type="number" id="refresh_interval" name="settings[auto_refresh_interval]" class="form-control" value="<?php echo htmlspecialchars($refreshInterval); ?>" min="10">
                                    <div class="form-text">How often the dashboard checks for new notifications. Minimum 10 seconds.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Backup Settings -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-server"></i> Automated Backup</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold">Backup Day</label>
                                    <select name="settings[backup_day]" class="form-select">
                                        <?php $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
                                        <?php foreach ($days as $day): ?>
                                            <option value="<?php echo $day; ?>" <?php echo ($backupDay === $day) ? 'selected' : ''; ?>><?php echo date('l', strtotime($day)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Day of the week to run the automated backup.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold">Backup Time</label>
                                    <input type="time" name="settings[backup_time]" class="form-control" value="<?php echo htmlspecialchars($backupTime); ?>">
                                    <div class="form-text">Time of day to run the backup (24-hour format).</div>
                                </div>
                                <div class="col-md-4 mb-3 d-flex align-items-center pt-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="settings[backup_include_vault]" value="1" id="incVault" <?php echo ($backupVault === '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="incVault">Include Vault Files</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Backup Path (Optional)</label>
                                <input type="text" name="settings[backup_path]" class="form-control" value="<?php echo htmlspecialchars($backupPath); ?>" placeholder="e.g. C:\backups\">
                                <input type="text" name="settings[backup_path]" class="form-control" value="<?php echo htmlspecialchars($backupPath); ?>" placeholder="e.g. C:\backups\" maxlength="255">
                                <div class="form-text">Absolute path to a custom backup folder. Leave blank to use default `backups/` folder.</div>
                            </div>
                            <!-- [NEW from user code] Backup Password -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">ZIP Password</label>
                                <div class="input-group">
                                    <input type="password" name="settings[backup_password]" id="backupPassInput" class="form-control" placeholder="Enter new to change" minlength="8" maxlength="50" autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" onclick="testZipPassword(this)" title="Verify Password"><i class="bi bi-check-circle"></i> Test</button>
                                    <div class="input-group-text bg-white">
                                        <input class="form-check-input mt-0" type="checkbox" name="clear_backup_password" value="1" aria-label="Clear password">
                                        <span class="ms-2 small">Clear</span>
                                    </div>
                                </div>
                                <div class="form-text">Encrypts the backup ZIP file. Max 50 characters. (Leave blank to keep current)</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Failure Alert Email</label>
                                <input type="email" name="settings[backup_alert_email]" class="form-control" value="<?php echo htmlspecialchars($backupEmail); ?>" placeholder="admin@example.com">
                                <div class="form-text">Email address to notify if the automated backup fails.</div>
                            </div>
                        </div>
                    </div>

                    <!-- [NEW from user code] Manual Trigger -->
                    <div class="alert alert-info mt-3">
                        <h6 class="fw-bold"><i class="bi bi-robot"></i> Automatic System Backup</h6>
                        <p class="small mb-2">The system will automatically run a backup when an <strong>Admin logs in</strong> on <strong><?php echo htmlspecialchars($backupDay); ?></strong> after <strong><?php echo htmlspecialchars($backupTime); ?></strong>.</p>
                        <hr><a href="cron_backup.php" class="btn btn-sm btn-dark mt-1"><i class="bi bi-play-fill"></i> Run Full Backup Now</a>
                    </div>
                </div>
            </div>

            <div class="text-center my-4">
                <button type="submit" class="btn btn-lg btn-success shadow-sm"><i class="bi bi-check-circle-fill"></i> Save All Settings</button>
            </div>
        </form>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/sweetalert2.all.min.js"></script>
    <script>
        // [NEW] SweetAlert for Success/Error Messages
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('msg')) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: urlParams.get('msg'),
                    timer: 2000,
                    showConfirmButton: false
                });
                window.history.replaceState(null, null, window.location.pathname);
            }
            if (urlParams.has('error')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: urlParams.get('error')
                });
                window.history.replaceState(null, null, window.location.pathname);
            }
        });

        function testZipPassword(btn) {
            const input = document.getElementById('backupPassInput');
            const pass = input.value;

            if (!pass) {
                Swal.fire('Input Required', 'Please enter a password in the field to test it.', 'warning');
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            const formData = new FormData();
            formData.append('password', pass);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('test_zip_password.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('Verified', data.message, 'success');
                    } else {
                        Swal.fire('Test Failed', data.message, 'error');
                    }
                })
                .catch(e => {
                    console.error(e);
                    Swal.fire('Error', 'Network or server error occurred.', 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        }

        function testAlertEmail(btn) {
            // This function is not fully implemented in the user's code, but I'll add the skeleton.
            // It would require a backend script `test_email_alert.php`.
            const input = document.querySelector('input[name="settings[backup_alert_email]"]');
            const email = input.value;

            if (!email) {
                Swal.fire('Input Required', 'Please enter an email address to test.', 'warning');
                return;
            }

            Swal.fire({
                title: 'Sending Test Email...',
                text: `A test email will be sent to ${email}.`,
                didOpen: () => {
                    Swal.showLoading()
                }
            });
            // In a real scenario, you'd fetch a test endpoint here.
        }

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
    </script>
</body>

</html>