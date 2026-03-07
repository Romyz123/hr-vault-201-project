<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Only ADMIN or MANAGER
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// [SECURITY] Check Maintenance Mode
if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $chkMaint = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
    if ($chkMaint === '1') {
        header("Location: login.php?msg=" . urlencode("🛠️ System is under maintenance."));
        exit;
    }
}

$logger = new Logger($pdo);

// [SECURITY] Generate CSRF token for form submissions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. HANDLE SAVE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SECURITY] CSRF Token Validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $logger->log($_SESSION['user_id'] ?? 0, 'SECURITY_ALERT', "CSRF validation failed");
        die("CSRF validation failed");
    }

    // Fetch old settings first for comparison and fallback
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings");
    $stmt->execute();
    $oldSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $staff_direct = isset($_POST['staff_direct_approval']) ? '1' : '0';
    $maint_mode   = isset($_POST['maintenance_mode']) ? '1' : '0';
    $def_proj     = trim($_POST['default_project_name'] ?? '');
    $def_proj     = preg_replace('/[^a-zA-Z0-9\s\-\.\(\)]/', '', $def_proj); // [SECURITY] Enforce pattern
    if (strlen($def_proj) > 100) $def_proj = substr($def_proj, 0, 100); // [SECURITY] Enforce length
    $margin_l     = trim($_POST['bulk_margin_left'] ?? '30');
    $margin_r     = trim($_POST['bulk_margin_right'] ?? '20');

    // [NEW] Auto-Refresh Interval
    $refresh_int  = (int)($_POST['auto_refresh_interval'] ?? 60);
    if ($refresh_int < 10) $refresh_int = 10; // Minimum 10s
    if ($refresh_int > 3600) $refresh_int = 3600; // Maximum 1hr

    // [SECURITY] Validate backup_day against whitelist
    $validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $backup_day = $_POST['backup_day'] ?? 'Fri';
    if (!in_array($backup_day, $validDays)) {
        $backup_day = 'Fri';
    }

    // [NEW] Validate backup_time
    $backup_time = $_POST['backup_time'] ?? '00:00';
    if (!preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $backup_time)) {
        $backup_time = '00:00';
    }

    // [SECURITY] Validate and sanitize backup_path
    $backup_path = trim($_POST['backup_path'] ?? '');
    if (strlen($backup_path) > 255) {
        header("Location: settings.php?error=" . urlencode("Backup Path is too long (Max 255 chars)."));
        exit;
    }
    $backup_path = preg_replace('/[^a-zA-Z0-9_\-\:\\\\\/\. ]/', '', $backup_path);

    // [SECURITY] Validate and store backup_pass encrypted
    $new_pass = trim($_POST['backup_password'] ?? '');
    $clear_pass = isset($_POST['clear_backup_password']);

    if ($clear_pass) {
        $backup_pass = '';
    } elseif (!empty($new_pass)) {
        if (strlen($new_pass) > 50) {
            header("Location: settings.php?error=" . urlencode("ZIP Password is too long (Max 50 chars)."));
            exit;
        }
        if (strlen($new_pass) < 8) {
            header("Location: settings.php?error=" . urlencode("ZIP Password must be at least 8 characters for security."));
            exit;
        }
        $backup_pass = $new_pass;
    } else {
        // Keep existing if empty and not cleared
        $backup_pass = $oldSettings['backup_password'] ?? '';
    }

    $backup_vault = isset($_POST['backup_include_vault']) ? '1' : '0';

    // [NEW] Validate Alert Email
    $alert_email = trim($_POST['backup_alert_email'] ?? '');
    if (!empty($alert_email) && !filter_var($alert_email, FILTER_VALIDATE_EMAIL)) {
        header("Location: settings.php?error=" . urlencode("Invalid Alert Email format."));
        exit;
    }

    // Update or Insert
    $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES 
            ('staff_direct_approval', ?),
            ('maintenance_mode', ?),
            ('default_project_name', ?),
            ('bulk_margin_left', ?),
            ('bulk_margin_right', ?),
            ('backup_day', ?),
            ('backup_time', ?),
            ('backup_path', ?),
            ('backup_password', ?),
            ('backup_include_vault', ?),
            ('backup_alert_email', ?),
            ('auto_refresh_interval', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $pdo->prepare($sql)->execute([$staff_direct, $maint_mode, $def_proj, $margin_l, $margin_r, $backup_day, $backup_time, $backup_path, $backup_pass, $backup_vault, $alert_email, $refresh_int]);

    // [AUDIT LOG] Only log if changed
    if (($oldSettings['staff_direct_approval'] ?? '0') !== $staff_direct) {
        $status = ($staff_direct === '1') ? 'ON' : 'OFF';
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Staff Direct Approval' to $status");
    }

    if (($oldSettings['maintenance_mode'] ?? '0') !== $maint_mode) {
        $status = ($maint_mode === '1') ? 'ON' : 'OFF';
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Maintenance Mode' to $status");
    }

    if (($oldSettings['backup_day'] ?? 'Fri') !== $backup_day) {
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Backup Day' to $backup_day");
    }

    if (($oldSettings['backup_time'] ?? '00:00') !== $backup_time) {
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Backup Time' to $backup_time");
    }

    if (($oldSettings['backup_path'] ?? '') !== $backup_path) {
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Backup Path' setting");
    }

    if (!empty($backup_pass) && ($oldSettings['backup_password'] ?? '') !== $backup_pass) {
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Backup Password' setting");
    }

    if (($oldSettings['backup_include_vault'] ?? '0') !== $backup_vault) {
        $status = ($backup_vault === '1') ? 'ON' : 'OFF';
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Include Vault in Backup' to $status");
    }

    if (($oldSettings['backup_alert_email'] ?? '') !== $alert_email) {
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Backup Alert Email' to $alert_email");
    }

    if (($oldSettings['auto_refresh_interval'] ?? '60') != $refresh_int) {
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Auto-Refresh' to $refresh_int seconds");
    }

    // [SECURITY] Regenerate CSRF token after successful submission
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    header("Location: settings.php?msg=" . urlencode("✅ Settings updated successfully."));
    exit;
}

// 3. FETCH CURRENT SETTINGS
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Fallback if table missing
}

$staffDirect = ($settings['staff_direct_approval'] ?? '0') === '1';
$maintMode   = ($settings['maintenance_mode'] ?? '0') === '1';
$defProject  = $settings['default_project_name'] ?? '';
$marginL     = $settings['bulk_margin_left'] ?? '30';
$marginR     = $settings['bulk_margin_right'] ?? '20';
$backupDay   = $settings['backup_day'] ?? 'Fri';
$backupTime  = $settings['backup_time'] ?? '00:00';
$backupPath  = $settings['backup_path'] ?? '';
$backupPass  = $settings['backup_password'] ?? '';
$backupVault = ($settings['backup_include_vault'] ?? '0') === '1';
$alertEmail  = $settings['backup_alert_email'] ?? '';
$refreshInt  = $settings['auto_refresh_interval'] ?? '60';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-sliders"></i> System Configuration</h5>
                        <a href="index.php" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <h6 class="border-bottom pb-2 mb-3 text-primary">Permissions & Access</h6>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="staffDirect" name="staff_direct_approval" value="1" <?php echo $staffDirect ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="staffDirect">Allow Staff Direct Edit/Add</label>
                                <div class="form-text text-muted">
                                    If <strong>ON</strong>: Staff changes are saved immediately.<br>
                                    If <strong>OFF</strong>: Staff changes create a "Request" that requires Admin approval.
                                </div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="maintMode" name="maintenance_mode" value="1" <?php echo $maintMode ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-danger" for="maintMode">Maintenance Mode</label>
                                <div class="form-text text-muted">
                                    If <strong>ON</strong>: Only ADMINS can log in. All other users will be blocked.<br>
                                    Use this when performing system updates.
                                </div>
                            </div>

                            <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary">Document Defaults</h6>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Default Project Name</label>
                                <input type="text" name="default_project_name" class="form-control" value="<?php echo htmlspecialchars($defProject); ?>" placeholder="e.g. MRT-3 Rehabilitation Project"
                                    maxlength="100" pattern="[a-zA-Z0-9\s\-\.\(\)]+" title="Allowed: Letters, Numbers, Spaces, - . ( )"
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\(\)]/g, '')">
                                <div class="form-text">Auto-fills the Project Name in contracts.</div>
                            </div>

                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label fw-bold">Bulk Print Margin (Left)</label>
                                    <input type="number" name="bulk_margin_left" class="form-control" value="<?php echo htmlspecialchars($marginL); ?>" min="0" max="200" oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>3) this.value=this.value.slice(0,3); if(this.value>200) this.value=200;">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-bold">Bulk Print Margin (Right)</label>
                                    <input type="number" name="bulk_margin_right" class="form-control" value="<?php echo htmlspecialchars($marginR); ?>" min="0" max="200" oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length>3) this.value=this.value.slice(0,3); if(this.value>200) this.value=200;">
                                </div>
                                <div class="col-12 mt-2">
                                    <label class="form-label fw-bold">Dashboard Auto-Refresh (Seconds)</label>
                                    <input type="number" name="auto_refresh_interval" class="form-control" value="<?php echo htmlspecialchars($refreshInt); ?>" min="10" max="3600">
                                    <div class="form-text">How often the dashboard updates live data (Min: 10s).</div>
                                </div>
                            </div>
                            <div class="form-text mb-3">Adjusts the side spacing for bulk printed contracts (in pixels).</div>

                            <h6 class="border-bottom pb-2 mb-3 mt-4 text-danger">Automated Backup Configuration</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Day</label>
                                    <select name="backup_day" class="form-select">
                                        <?php
                                        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                        foreach ($days as $d) {
                                            $sel = ($backupDay === $d) ? 'selected' : '';
                                            echo "<option value='$d' $sel>$d</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Start Time</label>
                                    <input type="time" name="backup_time" class="form-control" value="<?php echo htmlspecialchars($backupTime); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Custom Backup Path</label>
                                    <input type="text" name="backup_path" class="form-control" placeholder="e.g. D:\Backups" value="<?php echo htmlspecialchars($backupPath); ?>" maxlength="255">
                                    <div class="form-text">Leave blank to use default server folder. Ensure the drive is connected.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">ZIP Password</label>
                                    <div class="input-group">
                                        <input type="password" name="backup_password" id="backupPassInput" class="form-control" placeholder="Enter new to change" minlength="8" maxlength="50" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary" onclick="testZipPassword(this)" title="Verify Password"><i class="bi bi-check-circle"></i> Test</button>
                                        <div class="input-group-text bg-white">
                                            <input class="form-check-input mt-0" type="checkbox" name="clear_backup_password" value="1" aria-label="Clear password">
                                            <span class="ms-2 small">Clear</span>
                                        </div>
                                    </div>
                                    <div class="form-text">Encrypts the backup ZIP file. Max 50 characters. (Leave blank to keep current)</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Alert Email (On Failure)</label>
                                    <div class="input-group">
                                        <input type="email" name="backup_alert_email" id="alertEmailInput" class="form-control" placeholder="admin@example.com" value="<?php echo htmlspecialchars($alertEmail); ?>" maxlength="100">
                                        <button type="button" class="btn btn-outline-secondary" onclick="testAlertEmail(this)" title="Send Test Email"><i class="bi bi-envelope-check"></i> Test</button>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="form-check form-switch mt-3">
                                        <input class="form-check-input" type="checkbox" id="incVault" name="backup_include_vault" value="1" <?php echo $backupVault ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="incVault">Include Vault (Files)</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="alert alert-info small mb-0">
                                        <i class="bi bi-info-circle"></i> Backups run automatically on the selected day when an Admin visits the dashboard.
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info mt-3">
                                <h6 class="fw-bold"><i class="bi bi-robot"></i> Automatic System Backup</h6>
                                <p class="small mb-2">
                                    Since external automation is restricted, the system will automatically run a backup when an <strong>Admin logs in</strong> on <strong><?php echo htmlspecialchars($backupDay); ?></strong> after <strong><?php echo htmlspecialchars($backupTime); ?></strong>.
                                </p>
                                <hr>
                                <div>
                                    <strong>Manual Trigger:</strong><br>
                                    <a href="cron_backup.php" class="btn btn-sm btn-dark mt-1"><i class="bi bi-play-fill"></i> Run Full Backup Now</a>
                                </div>
                            </div>

                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
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
            const input = document.getElementById('alertEmailInput');
            const email = input.value;

            if (!email) {
                Swal.fire('Input Required', 'Please enter an email address to test.', 'warning');
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            const formData = new FormData();
            formData.append('email', email);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('test_email_alert.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('Sent', data.message, 'success');
                    } else {
                        Swal.fire('Failed', data.message, 'error');
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
    </script>
</body>

</html>