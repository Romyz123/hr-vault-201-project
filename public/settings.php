<?php
// public/settings.php
require '../config/db.php';
require '../src/Security.php';
// ---------- 1) SYSTEM INITIALIZATION ----------
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
$error = "";

// [NEW] Dynamically calculate total drive space to use as a realistic cap
$vaultPathForDisk = realpath(__DIR__ . '/../vault') ?: __DIR__;
$diskTotalBytes = @disk_total_space($vaultPathForDisk);
$diskTotalGB = $diskTotalBytes ? floor($diskTotalBytes / 1024 / 1024 / 1024) : 1000;
if ($diskTotalGB < 1) $diskTotalGB = 1; // Fallback minimum

// 2. HANDLE FORM SUBMISSION
// ---------- 2) SETTINGS PROCESSING ----------
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

        // [NEW] Handle Company Logo Upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $logoFile = $_FILES['company_logo'];
            $allowedTypes = ['image/png', 'image/jpeg'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($logoFile['tmp_name']);

            if (!in_array($mime, $allowedTypes)) {
                $errors[] = "Logo must be a PNG or JPG image.";
            } elseif ($logoFile['size'] > 2 * 1024 * 1024) {
                $errors[] = "Logo file size must be less than 2MB.";
            } else {
                $destDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
                if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

                $dest = $destDir . DIRECTORY_SEPARATOR . 'tesp-logo.png';
                if (move_uploaded_file($logoFile['tmp_name'], $dest)) {
                    // Also sync to public/uploads/ for pages that use that path directly
                    $publicDest = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tesp-logo.png';
                    if (!is_dir(dirname($publicDest))) @mkdir(dirname($publicDest), 0755, true);
                    @copy($dest, $publicDest);
                    $logger->log($_SESSION['user_id'], 'LOGO_UPDATE', 'Company logo was updated.');
                } else {
                    $errors[] = "Failed to save the uploaded logo.";
                }
            }
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

                // [FIX] Handle array inputs (like approval_widgets) correctly to prevent conversion warnings
                if (is_array($value)) {
                    $value = json_encode(array_map(fn($v) => trim((string)$v), $value));
                    // Skip the trim/string cast below for arrays
                    $updates[$key] = $value;
                    continue;
                } else {
                    $value = trim((string)$value);
                }

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

                // [SECURITY] Validate Backup Schedule Format
                if ($key === 'backup_time') {
                    if (!empty($value) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
                        $errors[] = "Invalid Backup Time format. Expected HH:MM (24-hour).";
                    }
                }
                if ($key === 'backup_day') {
                    $allowedDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    if (!empty($value) && !in_array($value, $allowedDays)) {
                        $errors[] = "Invalid Backup Day selected.";
                    }
                }

                // [SECURITY] Strict Path Validation for Backups (Prevent Directory Traversal)
                if ($key === 'backup_path' || $key === 'secondary_backup_path') {
                    $clean = str_replace("\0", '', $value);
                    if ($clean === '') {
                        $value = '';
                    } else {
                        // 1. Block Traversal Sequences and protocol wrappers
                        if (strpos($clean, '..') !== false || preg_match('/[<>"|?*]/', $clean) || strpos($clean, '://') !== false) {
                            $errors[] = "Invalid path format: Directory traversal or protocol wrappers detected.";
                        } else {
                            // 2. System Folder Blacklist (MHI Security Requirement)
                            $forbidden = [
                                'C:\\Windows',
                                'C:\\Program Files',
                                'C:\\Users',
                                'C:\\inetpub',
                                '/etc',
                                '/var',
                                '/usr',
                                '/bin',
                                '/sbin',
                                '/root',
                                '/boot',
                                '/dev'
                            ];

                            $normPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
                            foreach ($forbidden as $f) {
                                if (stripos($normPath, $f) === 0) {
                                    $errors[] = "Access Denied: Cannot target sensitive system directory '$f'.";
                                    break;
                                }
                            }
                        }

                        // 3. Resolve and verify if path exists (only if no errors found yet)
                        if (empty($errors) && file_exists($clean)) {
                            $resolved = realpath($clean);
                            if ($resolved) {
                                // Deep check resolved path against forbidden list
                                foreach ($forbidden as $f) {
                                    if (stripos($resolved, $f) === 0) {
                                        $errors[] = "Resolved backup path targets a forbidden system directory.";
                                        break;
                                    }
                                }

                                if (empty($errors)) {
                                    if (!is_dir($resolved)) {
                                        $errors[] = "Backup path exists but is not a directory.";
                                    } elseif (!is_writable($resolved)) {
                                        $errors[] = "Backup path exists but is not writable by the web server.";
                                    }
                                }
                            }
                        }
                        $value = $clean;
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

                // Specific validation for timeouts
                if (strpos($key, 'timeout') !== false || strpos($key, 'interval') !== false) {
                    if (!is_numeric($value) || (int)$value < 10) {
                        $errors[] = "Timeout/Interval values must be numeric and at least 10 seconds.";
                    }
                    $value = (int)$value;
                    if ($key === 'auto_refresh_interval' && $value > 3600) {
                        $value = 3600; // Cap at 3600s
                    }
                }

                // Specific validation for margins
                if (strpos($key, 'margin') !== false) {
                    $value = preg_replace('/[^0-9]/', '', (string)$value);
                    if ($value === '' || (int)$value > 500) {
                        $value = '500';
                    }
                }

                // [NEW] Handle approval widgets
                if ($key === 'approval_widgets') {
                    $value = json_encode($value ?? []);
                }

                // Validate font size
                if ($key === 'document_font_size') {
                    $value = preg_replace('/[^0-9\.]/', '', (string)$value);
                    if ($value === '' || (float)$value < 8 || (float)$value > 24) {
                        $value = '11';
                    }
                }

                // Validate Vault Size Limit
                if ($key === 'vault_size_limit_gb') {
                    $value = (float)$value;
                    if ($value < 0) $value = 0;
                    if ($value > $diskTotalGB) $value = $diskTotalGB;
                }

                // Validate Max Backup Size
                if ($key === 'backup_max_size_gb') {
                    $newBackupPath = rtrim(trim($_POST['settings']['backup_path'] ?? ''), '\\/');
                    $valBackupPathForDisk = (!empty($newBackupPath) && file_exists($newBackupPath)) ? realpath($newBackupPath) : realpath(__DIR__ . '/../backups');
                    if (!$valBackupPathForDisk) $valBackupPathForDisk = __DIR__;
                    $valBackupDiskBytes = @disk_total_space($valBackupPathForDisk);
                    $valBackupDiskGB = $valBackupDiskBytes ? floor($valBackupDiskBytes / 1024 / 1024 / 1024) : 1000;
                    if ($valBackupDiskGB < 1) $valBackupDiskGB = 1;

                    $value = (float)$value;
                    if ($value < 0.01) $value = 0.01;
                    if ($value > $valBackupDiskGB) $value = $valBackupDiskGB;
                }

                $updates[$key] = $value;
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            foreach ($updates as $k => $v) {
                // [FIX] Correctly handle array values (like approval_widgets) during database save.
                // This prevents storing the literal string "Array" which causes 500 errors on the dashboard.
                $valStr = is_array($v) ? json_encode($v) : (string)$v;

                $stmt->execute([$k, $valStr, $valStr]);
            }

            $msg = "✅ Settings updated successfully!";
            $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', 'System settings were updated.');
            header("Location: settings.php?msg=" . urlencode($msg));
            exit;
        } else {
            $error = implode('<br>', $errors);
        }
    } catch (Exception $e) {
        $error = "Error: " . htmlspecialchars($e->getMessage());
    }
}

// 3. FETCH AND PREPARE DATA
// ---------- 3) DATA PREPARATION ----------
$currentSettings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $currentSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $error = "Could not load settings. Please run DB Status check from the Admin dashboard.";
}

// Set defaults
$serverTimeout = $currentSettings['session_timeout_server'] ?? 1800;
$clientTimeout = $currentSettings['session_timeout_client'] ?? 900;
$refreshInterval = $currentSettings['auto_refresh_interval'] ?? 60;
$companyPresident = $currentSettings['company_president'] ?? 'JUNJI FURUYA';
$vaultLimitGB = $currentSettings['vault_size_limit_gb'] ?? '1';
$maintMode = $currentSettings['maintenance_mode'] ?? '0';

$staffDirect = ($currentSettings['staff_direct_approval'] ?? '0') === '1';
$defProject  = $currentSettings['default_project_name'] ?? '';
$defPlace    = $currentSettings['default_notice_place'] ?? '';
$marginL     = $currentSettings['bulk_margin_left'] ?? '30';
$marginR     = $currentSettings['bulk_margin_right'] ?? '20';
$approvalWidgetsJson = json_decode($currentSettings['approval_widgets'] ?? '["hires","edits","docs","doc-edits","tickets"]', true);
$docFontSize = $currentSettings['document_font_size'] ?? '11';

$backupDay = $currentSettings['backup_day'] ?? 'Fri';
$backupTime = $currentSettings['backup_time'] ?? '00:00';
$backupPath = $currentSettings['backup_path'] ?? '';
$secondaryPath = $currentSettings['secondary_backup_path'] ?? '';
$backupVault = $currentSettings['backup_include_vault'] ?? '0';
$backupEmail = $currentSettings['backup_alert_email'] ?? '';
$backupMaxSize = $currentSettings['backup_max_size_gb'] ?? '1.9';

// Calculate capacity specifically for the backup drive
$actualBackupPathForDisk = (!empty($backupPath) && file_exists($backupPath)) ? realpath($backupPath) : realpath(__DIR__ . '/../backups');
if (!$actualBackupPathForDisk) $actualBackupPathForDisk = __DIR__;
$backupDiskTotalBytes = @disk_total_space($actualBackupPathForDisk);
$backupDiskTotalGB = $backupDiskTotalBytes ? floor($backupDiskTotalBytes / 1024 / 1024 / 1024) : 1000;
if ($backupDiskTotalGB < 1) $backupDiskTotalGB = 1;

// Detect if Backup Path is on the same drive as the app (Windows only)
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

// Grab the message from the URL if it exists
$msg = $_GET['msg'] ?? "";

include 'header.php';
?>

<?php // ---------- 4) SETTINGS UI ---------- 
?>
<div class="container">
    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger shadow-sm border-danger border-2">
            <strong><i class="bi bi-exclamation-triangle-fill"></i> Settings could not be saved:</strong><br>
            <?= $error ?>
        </div>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Save Failed',
                    html: <?= json_encode($error) ?>
                });
            });
        </script>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Session & Inactivity Timeouts</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="server_timeout" class="form-label fw-bold">Server Session Lifetime</label>
                                <select id="server_timeout" name="settings[session_timeout_server]" class="form-select">
                                    <option value="1800" <?= ($serverTimeout == 1800) ? 'selected' : '' ?>>30 Minutes (MHI Standard)</option>
                                    <option value="3600" <?= ($serverTimeout == 3600) ? 'selected' : '' ?>>60 Minutes</option>
                                    <option value="7200" <?= ($serverTimeout == 7200) ? 'selected' : '' ?>>2 Hours</option>
                                    <option value="14400" <?= ($serverTimeout == 14400) ? 'selected' : '' ?>>4 Hours</option>
                                </select>
                                <div class="form-text">The maximum time a session is valid on the server. After this, the user is forced to log in again.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="client_timeout" class="form-label fw-bold">Client Inactivity Timer</label>
                                <select id="client_timeout" name="settings[session_timeout_client]" class="form-select">
                                    <option value="600" <?php echo ($clientTimeout == 600) ? 'selected' : ''; ?>>10 Minutes</option>
                                    <option value="900" <?= ($clientTimeout == 900) ? 'selected' : '' ?>>15 Minutes (Recommended)</option>
                                    <option value="1200" <?= ($clientTimeout == 1200) ? 'selected' : '' ?>>20 Minutes</option>
                                    <option value="1800" <?= ($clientTimeout == 1800) ? 'selected' : '' ?>>30 Minutes</option>
                                </select>
                                <div class="form-text">
                                    The time of user inactivity before automatic logout.<br>
                                    <span class="text-danger">Warning:</span> Must be less than Server Session Lifetime.
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Company Favicon (Tab Icon)</label>
                            <div class="d-flex align-items-center gap-3">
                                <?php
                                $faviconUrl = 'uploads/favicon.png';
                                if (!file_exists($faviconUrl)) $faviconUrl = '../uploads/favicon.png'; // Fallback to root
                                if (!file_exists($faviconUrl)) $faviconUrl = 'uploads/tesp-logo.png'; // Fallback to logo
                                ?>
                                <img src="<?= $faviconUrl ?>?v=<?= time() ?>" id="faviconPreview" class="border rounded p-1" style="height: 32px; width: 32px; background: #f8f9fa;" alt="Current Favicon">
                                <div class="flex-grow-1">
                                    <input type="file" name="company_favicon" class="form-control" accept=".png,.ico,.jpg,.jpeg" onchange="previewFavicon(this)">
                                    <div class="form-text">Recommended: 32x32 or 64x64 PNG. Max 512KB.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Permissions & Access</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="staffDirect" name="settings[staff_direct_approval]" value="1" <?= $staffDirect ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="staffDirect">Allow Staff Direct Edit/Add</label>
                            <div class="form-text text-muted">If <strong>ON</strong>: Changes saved immediately. If <strong>OFF</strong>: Creates a Request for Admin.</div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="maintMode" name="settings[maintenance_mode]" value="1" <?= ($maintMode === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-danger" for="maintMode">Enable Maintenance Mode</label>
                            <div class="form-text text-muted">If <strong>ON</strong>: Only ADMINS can log in. All other users blocked.</div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Approval Center Widgets</h5>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">Select which request types to display on the Admin dashboard.</p>
                        <?php
                        $allWidgets = [
                            'hires' => 'New Hires',
                            'edits' => 'Profile Edits',
                            'docs' => 'Document Uploads',
                            'doc-edits' => 'Document Edits',
                            'tickets' => 'Ticket Resolutions'
                        ];
                        $enabledWidgets = is_array($approvalWidgetsJson) ? $approvalWidgetsJson : array_keys($allWidgets);
                        foreach ($allWidgets as $key => $label):
                        ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="settings[approval_widgets][]" value="<?= $key ?>" id="widget_<?= $key ?>" <?= in_array($key, $enabledWidgets) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="widget_<?php echo $key; ?>"><?php echo $label; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="bi bi-palette"></i> Company Branding</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Company Logo</label>
                            <div class="d-flex align-items-center gap-3">
                                <?php
                                $logoUrl = 'uploads/tesp-logo.png';
                                if (!file_exists($logoUrl)) $logoUrl = '../uploads/tesp-logo.png'; // Fallback to root
                                ?>
                                <img src="<?= $logoUrl ?>?v=<?= time() ?>" id="logoPreview" class="border rounded p-1" style="height: 80px; width: auto; background: #f8f9fa;" alt="Current Logo">
                                <div class="flex-grow-1">
                                    <input type="file" name="company_logo" class="form-control" accept=".png,.jpg,.jpeg" onchange="previewLogo(this)">
                                    <div class="form-text">Recommended: PNG with transparent background. Max 2MB.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-ruled"></i> Document Defaults</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Company President</label>
                            <input type="text" name="settings[company_president]" class="form-control" value="<?= htmlspecialchars($companyPresident) ?>" placeholder="e.g. JUNJI FURUYA" maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Default Project Name</label>
                            <input type="text" name="settings[default_project_name]" class="form-control" value="<?= htmlspecialchars($defProject) ?>" placeholder="e.g. MRT-3 Rehabilitation Project" maxlength="100" pattern="[a-zA-Z0-9\s\-\.\(\)]+" title="Allowed: Letters, Numbers, Spaces, - . ( )">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Default Notice Place</label>
                            <input type="text" name="settings[default_notice_place]" class="form-control" value="<?= htmlspecialchars($defPlace) ?>" placeholder="e.g. Quezon City" maxlength="100">
                        </div>
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label fw-bold">Margin (Left)</label>
                                <input type="number" name="settings[bulk_margin_left]" class="form-control" value="<?= htmlspecialchars($marginL) ?>" min="0" max="500" oninput="validateMargin(this)">
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-bold">Margin (Right)</label>
                                <input type="number" name="settings[bulk_margin_right]" class="form-control" value="<?= htmlspecialchars($marginR) ?>" min="0" max="500" oninput="validateMargin(this)">
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-bold">Font Size (pt)</label>
                                <input type="number" step="0.5" name="settings[document_font_size]" class="form-control" value="<?= htmlspecialchars($docFontSize) ?>" min="8" max="24" oninput="validateFontSize(this)">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear-wide-connected"></i> General Settings</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="refresh_interval" class="form-label fw-bold">Dashboard Refresh Interval (seconds)</label>
                                <input type="number" id="refresh_interval" name="settings[auto_refresh_interval]" class="form-control" value="<?= htmlspecialchars($refreshInterval) ?>" min="10" max="3600" oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(parseInt(this.value) > 3600) this.value = '3600';">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="vault_size_limit_gb" class="form-label fw-bold">Vault Size Limit (GB)</label>
                                <input type="number" step="0.1" id="vault_size_limit_gb" name="settings[vault_size_limit_gb]" class="form-control" value="<?= htmlspecialchars($vaultLimitGB) ?>" min="0" max="<?= $diskTotalGB ?>" oninput="this.value = this.value.replace(/[^0-9\.]/g, ''); if(parseFloat(this.value) > <?= $diskTotalGB ?>) this.value = '<?= $diskTotalGB ?>';">
                                <div class="form-text">Maximum allowed storage (Capacity: <strong><?= $diskTotalGB ?> GB</strong>). Set 0 for unlimited.</div>
                            </div>
                        </div>
                    </div>
                </div>

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
                                        <option value="<?= $day ?>" <?= ($backupDay === $day) ? 'selected' : '' ?>><?= date('l', strtotime($day)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-bold">Backup Time</label>
                                <input type="time" name="settings[backup_time]" class="form-control" value="<?= htmlspecialchars($backupTime) ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label fw-bold">Max Split Size (GB)</label>
                                <input type="number" step="0.1" name="settings[backup_max_size_gb]" class="form-control" value="<?= htmlspecialchars($backupMaxSize) ?>" min="0.1" max="<?= $backupDiskTotalGB ?>">
                            </div>
                            <div class="col-md-3 mb-3 d-flex align-items-center pt-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="settings[backup_include_vault]" value="1" id="incVault" <?= ($backupVault === '1') ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="incVault">Include Vault Files</label>
                                    <div class="form-text text-danger mt-1" style="font-size: 0.75rem;"><i class="bi bi-exclamation-triangle"></i> Uncheck if vault > 2GB.</div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Backup Path (Optional) <span id="primaryStatus"></span></label>
                            <div class="input-group">
                                <input type="text" name="settings[backup_path]" id="backupPathInput" class="form-control" value="<?= htmlspecialchars($backupPath) ?>" placeholder="e.g. C:\backups\" maxlength="255">
                                <button type="button" class="btn btn-outline-secondary" onclick="testBackupPath(this)" title="Verify Path Access"><i class="bi bi-folder-check"></i> Test Path</button>
                            </div>
                            <div class="form-text">Leave blank to use default `backups/` folder.</div>
                            <?php if ($isSameDrive): ?>
                                <div class="alert alert-danger small mt-2 mb-0 border-danger border-2">
                                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Warning:</strong> Backups are saving to the same drive (<strong><?= htmlspecialchars($targetDrive) ?></strong>) as the app.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Secondary Backup Path (Redundancy) <span id="secondaryStatus"></span></label>
                            <div class="input-group">
                                <input type="text" name="settings[secondary_backup_path]" id="secondaryPathInput" class="form-control" value="<?= htmlspecialchars($secondaryPath) ?>" placeholder="e.g. D:\backups_mirror\">
                                <button type="button" class="btn btn-outline-secondary" onclick="testSecondaryPath(this)" title="Verify Mirror Path"><i class="bi bi-folder-check"></i> Test Path</button>
                            </div>
                        </div>
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
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Failure Alert Email</label>
                            <input type="email" name="settings[backup_alert_email]" class="form-control" value="<?= htmlspecialchars($backupEmail) ?>" placeholder="admin@example.com" maxlength="100">
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mt-3">
                    <h6 class="fw-bold"><i class="bi bi-robot"></i> Automatic System Backup</h6>
                    <p class="small mb-2">The system will automatically run a backup when an <strong>Admin logs in</strong> on <strong><?= htmlspecialchars($backupDay) ?></strong> after <strong><?= htmlspecialchars($backupTime) ?></strong>.</p>
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
    <?php // ---------- 5) JAVASCRIPT SETTINGS LOGIC ---------- 
    ?>

    function togglePass(id) {
        const input = document.getElementById(id);
        if (!input) return;
        const btn = input.nextElementSibling;
        const icon = btn ? btn.querySelector('i') : null;
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            input.type = 'password';
            if (icon) icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    }

    function updateAllPathStatus() {
        const p = document.getElementById('backupPathInput')?.value.trim() || '';
        const s = document.getElementById('secondaryPathInput')?.value.trim() || '';
        if (document.getElementById('primaryStatus')) document.getElementById('primaryStatus').innerHTML = p !== "" ? '<i class="bi bi-check-circle-fill text-success" title="Custom path active"></i>' : '';
        if (document.getElementById('secondaryStatus')) document.getElementById('secondaryStatus').innerHTML = s !== "" ? '<i class="bi bi-check-circle-fill text-success" title="Mirror path active"></i>' : '';
    }

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
                if (data.status === 'success') Swal.fire('Verified', data.message, 'success');
                else Swal.fire('Test Failed', data.message, 'error');
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

    function testBackupPath(btn) {
        const input = document.getElementById('backupPathInput');
        const path = input.value.trim();
        if (!path) {
            Swal.fire('Input Required', 'Please enter a custom backup path to test.', 'info');
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
            Swal.fire('Input Required', 'Please enter a secondary backup path to test.', 'info');
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

        Swal.fire({
            title: 'Running Full Backup...',
            html: `
                <p class="text-muted small mb-3">The system is packing the database and files into secure volumes. Please wait...</p>
                <div class="progress mb-3" style="height: 25px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 100%"></div>
                </div>
                <span class="text-danger fw-bold small">This may take a few minutes. Do not close this window!</span>
            `,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false
        });

        const formData = new FormData();
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
        fetch('cron_backup.php?ajax=1', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (data.status === 'success') {
                    Swal.fire('Success!', data.message, 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Backup Failed', data.message, 'error');
                }
            })
            .catch(err => {
                Swal.close();
                console.error(err);
                // [FIX] Replace generic Network Error with helpful guidance
                Swal.fire({
                    icon: 'warning',
                    title: 'Backup Task Continuing...',
                    html: `
                        <p>The connection timed out, but the server is still packing your backup in the background.</p>
                        <p class="small text-muted">Please check the <b>Disaster Recovery</b> table in Manage Users in 5-10 minutes to verify the new files.</p>
                    `,
                    confirmButtonText: 'Understood'
                });
            })
            .finally(() => {
                btn.innerHTML = ogText;
                btn.disabled = false;
            });
    }

    function validateMargin(input) {
        input.value = input.value.replace(/[^0-9]/g, '');
        if (input.value.length > 3) input.value = input.value.slice(0, 3);
        if (input.value !== '' && parseInt(input.value) > 500) input.value = '500';
    }

    function validateFontSize(input) {
        input.value = input.value.replace(/[^0-9\.]/g, '');
        if ((input.value.match(/\./g) || []).length > 1) input.value = input.value.replace(/\.$/, '');
        if (input.value.length > 4) input.value = input.value.slice(0, 4);
        if (parseFloat(input.value) > 24) input.value = '24';
    }

    function previewLogo(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('logoPreview').src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function previewFavicon(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('faviconPreview').src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    document.addEventListener('DOMContentLoaded', updateAllPathStatus);
</script>
</body>

</html>