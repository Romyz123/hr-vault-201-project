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

// [NEW] Dynamically calculate total drive space to use as a realistic cap
$vaultPathForDisk = realpath(__DIR__ . '/../vault') ?: __DIR__;
$diskTotalBytes = @disk_total_space($vaultPathForDisk);
$diskTotalGB = $diskTotalBytes ? floor($diskTotalBytes / 1024 / 1024 / 1024) : 1000;
if ($diskTotalGB < 1) $diskTotalGB = 1; // Fallback minimum

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $security->checkCSRF($_POST['csrf_token']);

        // [FIX] Define checkboxes and handle unchecked states (which aren't sent in POST)
        $checkboxes = ['maintenance_mode', 'backup_include_vault', 'staff_direct_approval'];

        // Fetch current backup password once so we can preserve it if the form submits an empty value
        $currentBackupPassword = '';
        try {
            $stmt_pass = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_password'");
            $stmt_pass->execute();
            $currentBackupPassword = $stmt_pass->fetchColumn() ?: '';
        } catch (Exception $e) {
            // ignore; it may not exist yet
        }

        $errors = [];
        $updates = [];

        // [FIX 1] Explicitly process checkboxes first. Browsers do not send unchecked boxes in POST.
        foreach ($checkboxes as $cb) {
            $updates[$cb] = isset($_POST['settings'][$cb]) ? '1' : '0';
        }

        // [FIX] Ensure approval_widgets is saved as an empty array if all boxes are unchecked
        if (isset($_POST['settings']) && !isset($_POST['settings']['approval_widgets'])) {
            $_POST['settings']['approval_widgets'] = [];
        }

        // Validate posted settings first
        if (isset($_POST['settings']) && is_array($_POST['settings'])) {
            foreach ($_POST['settings'] as $key => $value) {
                // Basic validation
                $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key); // Sanitize key

                // Skip checkboxes as they are already handled safely above
                if (in_array($key, $checkboxes, true)) continue;

                $value = trim((string)$value);

                // Handle backup password
                if ($key === 'backup_password') {
                    if (isset($_POST['clear_backup_password'])) {
                        $value = '';
                    } elseif ($value === '') {
                        // Preserve existing password if user left it blank
                        $value = $currentBackupPassword;
                    } else {
                        if (strlen($value) > 50) {
                            $errors[] = "ZIP Password is too long (Max 50 chars).";
                        } elseif (strlen($value) < 8) {
                            $errors[] = "ZIP Password must be at least 8 characters.";
                        }
                    }
                }

                // Validate backup path
                if ($key === 'backup_path') {
                    $clean = str_replace("\0", '', $value);
                    if ($clean === '') {
                        $value = ''; // [FIX 2] Allow empty values to use default system path
                    } elseif (strpos($clean, '..') !== false) {
                        $errors[] = "Backup path must not contain '..' sequences.";
                        // [FIX] Removed colon (:) from regex so Windows drive letters (C:\) are accepted
                    } elseif (preg_match('/[<>"|?*]/', $clean) || strpos($clean, '://') !== false) {
                        $errors[] = "Backup path contains invalid characters or protocol wrappers (e.g., < > \" | ? *).";
                    } else {
                        // [FIX] Loosen validation: Don't require the directory to exist yet.
                        // Only check for writability if it *does* exist.
                        if (file_exists($clean)) {
                            if (!is_dir($clean)) {
                                $errors[] = "Backup path exists but is not a directory.";
                            } elseif (!is_writable($clean)) {
                                $errors[] = "Backup path exists but is not writable by the web server.";
                            }
                        }
                        $value = $clean; // Use the user's input directly after sanitization
                    }
                }

                // Validate Alert Email Length and Format
                if ($key === 'backup_alert_email') {
                    if (strlen($value) > 100) {
                        $errors[] = "Alert Email is too long (Max 100 chars).";
                    } elseif (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Invalid Alert Email format.";
                    }
                }

                // Specific validation for timeouts (must be numeric, in seconds)
                if (strpos($key, 'timeout') !== false || strpos($key, 'interval') !== false) {
                    if (!is_numeric($value) || (int)$value < 10) {
                        $errors[] = "Timeout/Interval values must be numeric and at least 10 seconds.";
                    }
                    $value = (int)$value;
                    if ($key === 'auto_refresh_interval' && $value > 3600) {
                        $value = 3600; // Cap at 3600s
                    }
                }

                // Specific validation for margins (Max 500, numbers only)
                if (strpos($key, 'margin') !== false) {
                    $value = preg_replace('/[^0-9]/', '', (string)$value);
                    if ($value === '' || (int)$value > 500) {
                        $value = '500';
                    }
                }

                // [NEW] Handle approval widgets
                if ($key === 'approval_widgets') {
                    // Value will be an array from the form, so we json_encode it.
                    // If it's not set (all unchecked), it will be an empty array.
                    $value = json_encode($value ?? []);
                }

                // Validate font size
                if ($key === 'document_font_size') {
                    $value = preg_replace('/[^0-9\.]/', '', (string)$value);
                    if ($value === '' || (float)$value < 8 || (float)$value > 24) {
                        $value = '11';
                    }
                }

                // Validate Vault Size Limit (GB)
                if ($key === 'vault_size_limit_gb') {
                    $value = (float)$value;
                    if ($value < 0) $value = 0; // Minimum 0 (Unlimited)
                    if ($value > $diskTotalGB) $value = $diskTotalGB; // [FIX] Cap at actual drive size
                }

                // Validate Max Backup Size (GB)
                if ($key === 'backup_max_size_gb') {
                    // [NEW] Calculate limits based on the newly submitted backup path (the "other" drive)
                    $newBackupPath = rtrim(trim($_POST['settings']['backup_path'] ?? ''), '\\/');
                    $valBackupPathForDisk = (!empty($newBackupPath) && file_exists($newBackupPath)) ? realpath($newBackupPath) : realpath(__DIR__ . '/../backups');
                    if (!$valBackupPathForDisk) $valBackupPathForDisk = __DIR__;
                    $valBackupDiskBytes = @disk_total_space($valBackupPathForDisk);
                    $valBackupDiskGB = $valBackupDiskBytes ? floor($valBackupDiskBytes / 1024 / 1024 / 1024) : 1000;
                    if ($valBackupDiskGB < 1) $valBackupDiskGB = 1;

                    $value = (float)$value;
                    if ($value < 0.1) $value = 0.1; // Minimum 100MB
                    if ($value > $valBackupDiskGB) $value = $valBackupDiskGB; // [FIX] Cap at Backup Drive size
                }

                // Queue this setting for update
                $updates[$key] = $value;
            }
        }

        if (empty($errors)) {
            // Persist all validated settings
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            foreach ($updates as $k => $v) {
                $valStr = (string)$v; // [FIX] Force string cast to prevent strict DB float rejection
                $stmt->execute([$k, $valStr, $valStr]);
            }

            $msg = "✅ Settings updated successfully!";
            $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', 'System settings were updated.');
            header("Location: settings.php?msg=" . urlencode($msg));
            exit;
        } else {
            $error = implode('<br>', $errors); // [FIX] Allow line breaks so the alert is readable
        }
    } catch (Exception $e) {
        $error = "Error: " . htmlspecialchars($e->getMessage());
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
$vaultLimitGB = $settings['vault_size_limit_gb'] ?? '1'; // Default 1GB
$maintMode = $settings['maintenance_mode'] ?? '0';

// [NEW from user code]
$staffDirect = ($settings['staff_direct_approval'] ?? '0') === '1' || ($settings['staff_direct_approval'] ?? '0') === 1;
$defProject  = $settings['default_project_name'] ?? '';
$defPlace    = $settings['default_notice_place'] ?? '';
$marginL     = $settings['bulk_margin_left'] ?? '30';
$marginR     = $settings['bulk_margin_right'] ?? '20';
$approvalWidgets = json_decode($settings['approval_widgets'] ?? '[]', true);
$docFontSize = $settings['document_font_size'] ?? '11';

$backupDay = $settings['backup_day'] ?? 'Fri';
$backupTime = $settings['backup_time'] ?? '00:00';
$backupPath = $settings['backup_path'] ?? '';
$secondaryPath = $settings['secondary_backup_path'] ?? '';
$backupPass = $settings['backup_password'] ?? '';
$backupVault = $settings['backup_include_vault'] ?? '0';
$backupEmail = $settings['backup_alert_email'] ?? '';
$backupMaxSize = $settings['backup_max_size_gb'] ?? '1.9'; // Default to 1.9GB

// [FIX] If there was a validation error, restore the user's typed values so they don't lose their changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings'])) {
    $p = $_POST['settings'];
    $serverTimeout = $p['session_timeout_server'] ?? $serverTimeout;
    $clientTimeout = $p['session_timeout_client'] ?? $clientTimeout;
    $refreshInterval = $p['auto_refresh_interval'] ?? $refreshInterval;
    $vaultLimitGB = $p['vault_size_limit_gb'] ?? $vaultLimitGB;
    $maintMode = isset($p['maintenance_mode']) ? '1' : '0';
    $staffDirect = isset($p['staff_direct_approval']);
    $defProject = $p['default_project_name'] ?? $defProject;
    $defPlace = $p['default_notice_place'] ?? $defPlace;
    $marginL = $p['bulk_margin_left'] ?? $marginL;
    $marginR = $p['bulk_margin_right'] ?? $marginR;
    $approvalWidgets = $p['approval_widgets'] ?? []; // This will be an array from the form
    $docFontSize = $p['document_font_size'] ?? $docFontSize;
    $backupDay = $p['backup_day'] ?? $backupDay;
    $backupTime = $p['backup_time'] ?? $backupTime;
    $backupPath = $p['backup_path'] ?? $backupPath;
    $secondaryPath = $p['secondary_backup_path'] ?? $secondaryPath;
    $backupVault = isset($p['backup_include_vault']) ? '1' : '0';
    $backupEmail = $p['backup_alert_email'] ?? $backupEmail;
    $backupMaxSize = $p['backup_max_size_gb'] ?? $backupMaxSize;
}

// [NEW] Calculate capacity specifically for the backup drive
$actualBackupPathForDisk = (!empty($backupPath) && file_exists($backupPath)) ? realpath($backupPath) : realpath(__DIR__ . '/../backups');
if (!$actualBackupPathForDisk) $actualBackupPathForDisk = __DIR__;
$backupDiskTotalBytes = @disk_total_space($actualBackupPathForDisk);
$backupDiskTotalGB = $backupDiskTotalBytes ? floor($backupDiskTotalBytes / 1024 / 1024 / 1024) : 1000;
if ($backupDiskTotalGB < 1) $backupDiskTotalGB = 1;

// [NEW] Detect if Backup Path is on the same drive as the app (Windows only)
$isSameDrive = false;
$targetDrive = '';
if (PHP_OS_FAMILY === 'Windows') {
    $appDrive = strtoupper(substr(realpath(__DIR__), 0, 2));
    $actualBackupPath = (!empty($backupPath) && file_exists($backupPath)) ? realpath($backupPath) : realpath(__DIR__ . '/../backups');
    if ($actualBackupPath) {
        $targetDrive = strtoupper(substr($actualBackupPath, 0, 2));
        $isSameDrive = ($appDrive === $targetDrive);
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-sliders"></i> System Settings</span>
                <span class="navbar-text text-white-50 ms-3 font-monospace small"><i class="bi bi-clock"></i> <span id="sessionTimer"></span></span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger shadow-sm border-danger border-2">
                <strong><i class="bi bi-exclamation-triangle-fill"></i> Settings could not be saved:</strong><br>
                <?php echo $error; ?>
            </div>
            <!-- [FIX] Explicitly trigger SweetAlert so the user doesn't miss the validation failure -->
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Save Failed',
                        html: <?php echo json_encode($error); ?>
                    });
                });
            </script>
        <?php endif; ?>

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

                    <!-- [NEW] Approval Center Widgets -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Approval Center Widgets</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">Select which request types to display on the Admin Approval Center dashboard.</p>
                            <?php
                            $allWidgets = [
                                'hires' => 'New Hires',
                                'edits' => 'Profile Edits',
                                'docs' => 'Document Uploads',
                                'doc-edits' => 'Document Edits',
                                'tickets' => 'Ticket Resolutions'
                            ];
                            // If setting is empty, default to all checked
                            $enabledWidgets = !empty($approvalWidgets) ? $approvalWidgets : array_keys($allWidgets);
                            foreach ($allWidgets as $key => $label):
                            ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="settings[approval_widgets][]" value="<?php echo $key; ?>" id="widget_<?php echo $key; ?>" <?php echo in_array($key, $enabledWidgets) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="widget_<?php echo $key; ?>"><?php echo $label; ?></label>
                                </div>
                            <?php endforeach; ?>
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
                            <div class="mb-3">
                                <label class="form-label fw-bold">Default Notice Place</label>
                                <input type="text" name="settings[default_notice_place]" class="form-control" value="<?php echo htmlspecialchars($defPlace); ?>" placeholder="e.g. Quezon City" maxlength="100">
                                <div class="form-text">Auto-fills the "Place of Incident" in disciplinary notices.</div>
                            </div>
                            <div class="row g-2">
                                <div class="col-4"><label class="form-label fw-bold">Bulk Print Margin (Left)</label><input type="number" name="settings[bulk_margin_left]" class="form-control" value="<?php echo htmlspecialchars($marginL); ?>" min="0" max="500" oninput="validateMargin(this)"></div>
                                <div class="col-4"><label class="form-label fw-bold">Bulk Print Margin (Right)</label><input type="number" name="settings[bulk_margin_right]" class="form-control" value="<?php echo htmlspecialchars($marginR); ?>" min="0" max="500" oninput="validateMargin(this)"></div>
                                <div class="col-4"><label class="form-label fw-bold">Document Font Size (pt)</label><input type="number" step="0.5" name="settings[document_font_size]" class="form-control" value="<?php echo htmlspecialchars($docFontSize); ?>" min="8" max="24" oninput="validateFontSize(this)"></div>
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
                                <div class="col-md-6 mb-3">
                                    <label for="refresh_interval" class="form-label fw-bold">Dashboard Auto-Refresh Interval (seconds)</label>
                                    <input type="number" id="refresh_interval" name="settings[auto_refresh_interval]" class="form-control" value="<?php echo htmlspecialchars($refreshInterval); ?>" min="10" max="3600" oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value.length > 4) this.value = this.value.slice(0, 4); if(parseInt(this.value) > 3600) this.value = '3600';">
                                    <div class="form-text">How often the dashboard checks for new notifications. Min 10s, Max 3600s (1 hour).</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="vault_size_limit_gb" class="form-label fw-bold">Vault Size Limit (GB)</label>
                                    <input type="number" step="0.1" id="vault_size_limit_gb" name="settings[vault_size_limit_gb]" class="form-control" value="<?php echo htmlspecialchars($vaultLimitGB); ?>" min="0" max="<?php echo $diskTotalGB; ?>" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if(this.value.split('.').length > 2) this.value = this.value.replace(/\.+$/, ''); if(parseFloat(this.value) > <?php echo $diskTotalGB; ?>) this.value = '<?php echo $diskTotalGB; ?>';">
                                    <div class="form-text">Maximum allowed storage (Server Drive Capacity: <strong><?php echo $diskTotalGB; ?> GB</strong>). Set to 0 for unlimited.</div>
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
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Backup Day</label>
                                    <select name="settings[backup_day]" class="form-select">
                                        <?php $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
                                        <?php foreach ($days as $day): ?>
                                            <option value="<?php echo $day; ?>" <?php echo ($backupDay === $day) ? 'selected' : ''; ?>><?php echo date('l', strtotime($day)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Day of the week to run the automated backup.</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Backup Time</label>
                                    <input type="time" name="settings[backup_time]" class="form-control" value="<?php echo htmlspecialchars($backupTime); ?>">
                                    <div class="form-text">Time of day to run the backup (24-hour format).</div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label fw-bold">Max Split Size (GB)</label>
                                    <input type="number" step="0.1" name="settings[backup_max_size_gb]" class="form-control" value="<?php echo htmlspecialchars($backupMaxSize); ?>" min="0.1" max="<?php echo $backupDiskTotalGB; ?>">
                                </div>
                                <div class="col-md-3 mb-3 d-flex align-items-center pt-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="settings[backup_include_vault]" value="1" id="incVault" <?php echo ($backupVault === '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="incVault">Include Vault Files</label>
                                        <div class="form-text text-danger mt-1" style="font-size: 0.75rem;"><i class="bi bi-exclamation-triangle"></i> Uncheck if vault > 2GB.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Backup Path (Optional)</label>
                                <div class="input-group">
                                    <input type="text" name="settings[backup_path]" id="backupPathInput" class="form-control" value="<?php echo htmlspecialchars($backupPath); ?>" placeholder="e.g. C:\backups\" maxlength="255">
                                    <button type="button" class="btn btn-outline-secondary" onclick="testBackupPath(this)" title="Verify Path Access"><i class="bi bi-folder-check"></i> Test Path</button>
                                </div>
                                <div class="form-text">Absolute path to a custom backup folder. Leave blank to use default `backups/` folder.</div>
                                <?php if ($isSameDrive): ?>
                                    <div class="alert alert-danger small mt-2 mb-0 border-danger border-2">
                                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>Critical Warning:</strong> Your backups are currently being saved to the exact same physical drive (<strong><?php echo htmlspecialchars($targetDrive); ?></strong>) as the main application. If this drive crashes, both your system and backups will be lost. Please attach an external drive and update this path.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Secondary Backup Path (Redundancy)</label>
                                <div class="input-group">
                                    <input type="text" name="settings[secondary_backup_path]" id="secondaryPathInput" class="form-control" value="<?php echo htmlspecialchars($secondaryPath); ?>" placeholder="e.g. D:\backups_mirror\">
                                    <button type="button" class="btn btn-outline-secondary" onclick="testSecondaryPath(this)" title="Verify Mirror Path"><i class="bi bi-folder-check"></i> Test Path</button>
                                </div>
                                <div class="form-text text-info small"><i class="bi bi-info-circle"></i> Recommended: Use a different physical drive or a network mapped drive (UNC path).</div>
                            </div>
                            <!-- [NEW from user code] Backup Password -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">ZIP Password</label>
                                <div class="input-group">
                                    <input type="password" name="settings[backup_password]" id="backupPassInput" class="form-control" placeholder="Enter new to change" minlength="8" maxlength="50" autocomplete="new-password" oninput="updateStrength(this.value, 'backupStrengthBar')">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('backupPassInput')"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="testZipPassword(this)" title="Verify Password"><i class="bi bi-check-circle"></i> Test</button>
                                    <div class="input-group-text bg-white">
                                        <input class="form-check-input mt-0" type="checkbox" name="clear_backup_password" value="1" aria-label="Clear password">
                                        <span class="ms-2 small">Clear</span>
                                    </div>
                                </div>
                                <div class="progress mt-1" style="height: 5px;">
                                    <div id="backupStrengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="form-text">Encrypts the backup ZIP file. Max 50 characters. (Leave blank to keep current)</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Failure Alert Email</label>
                                <input type="email" name="settings[backup_alert_email]" class="form-control" value="<?php echo htmlspecialchars($backupEmail); ?>" placeholder="admin@example.com" maxlength="100">
                                <div class="form-text">Email address to notify if the automated backup fails.</div>
                            </div>
                        </div>
                    </div>

                    <!-- [NEW from user code] Manual Trigger -->
                    <div class="alert alert-info mt-3">
                        <h6 class="fw-bold"><i class="bi bi-robot"></i> Automatic System Backup</h6>
                        <p class="small mb-2">The system will automatically run a backup when an <strong>Admin logs in</strong> on <strong><?php echo htmlspecialchars($backupDay); ?></strong> after <strong><?php echo htmlspecialchars($backupTime); ?></strong>.</p>
                        <hr><button type="button" class="btn btn-sm btn-dark mt-1 fw-bold" id="manualBackupBtn" onclick="runManualBackup()"><i class="bi bi-play-fill"></i> Run Full Backup Now</button>
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
    <script src="main.js"></script>
    <script>
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

        function testBackupPath(btn) {
            const input = document.getElementById('backupPathInput');
            const path = input.value.trim();

            if (!path) {
                Swal.fire('Input Required', 'Please enter a custom backup path to test. (Leaving it blank safely uses the default system folder).', 'info');
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            const formData = new FormData();
            formData.append('path', path);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('test_backup_path.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') Swal.fire('Verified', data.message, 'success');
                    else if (data.status === 'warning') Swal.fire('Warning', data.message, 'warning');
                    else Swal.fire('Test Failed', data.message, 'error');
                    updateAllPathStatus();
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

        function testSecondaryPath(btn) {
            const input = document.getElementById('secondaryPathInput');
            const path = input.value.trim();
            if (!path) {
                Swal.fire('Input Required', 'Please enter a path to test.', 'info');
                return;
            }
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            const formData = new FormData();
            formData.append('path', path);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            fetch('test_backup_path.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') Swal.fire('Verified', data.message, 'success');
                    else if (data.status === 'warning') Swal.fire('Warning', data.message, 'warning');
                    else Swal.fire('Test Failed', data.message, 'error');
                    updateAllPathStatus();
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        }

        function updateStrength(val, barId) {
            const bar = document.getElementById(barId);
            if (!bar) return;
            let score = 0;
            if (val.length >= 8) score++;
            if (val.length >= 12) score++;
            if (val.length >= 15) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[a-z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            let pct = Math.min(100, (score / 7) * 100);
            bar.style.width = pct + '%';
            bar.className = 'progress-bar ' + (score > 5 ? 'bg-success' : (score > 3 ? 'bg-warning' : 'bg-danger'));
            if (val.length === 0) bar.style.width = '0%';
        }

        function runManualBackup() {
            const btn = document.getElementById('manualBackupBtn');
            const ogText = btn.innerHTML;

            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Packing Data... Please wait.';
            btn.disabled = true;

            fetch('cron_backup.php?ajax=1')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('Success!', data.message, 'success').then(() => window.location.reload());
                    } else {
                        Swal.fire('Backup Failed', data.message, 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire('Network Error', 'An unexpected error occurred while communicating with the server.', 'error');
                })
                .finally(() => {
                    btn.innerHTML = ogText;
                    btn.disabled = false;
                });
        }

        function validateMargin(input) {
            // [FIX] Strict Validation: Numbers only, 3 digits length, max 500
            input.value = input.value.replace(/[^0-9]/g, '');
            if (input.value.length > 3) input.value = input.value.slice(0, 3);
            if (input.value !== '' && parseInt(input.value) > 500) input.value = '500';
        }

        function validateFontSize(input) {
            input.value = input.value.replace(/[^0-9\.]/g, ''); // Numbers and dots only
            if ((input.value.match(/\./g) || []).length > 1) input.value = input.value.replace(/\.$/, ''); // Prevent double dots
            if (input.value.length > 4) input.value = input.value.slice(0, 4); // Max 4 chars (e.g., 24.5)
            if (parseFloat(input.value) > 24) input.value = '24'; // Hard cap at 24pt
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
    <script src="dark_mode.js"></script>
</body>

</html>