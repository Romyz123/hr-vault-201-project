<?php
// ======================================================
// [FILE] public/manager_user.php
// [STATUS] MERGED: Disaster Recovery + Phase 2 Security
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    $_SESSION['error'] = "Access Denied: Admin privileges required.";
    header("Location: index.php");
    exit;
}

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$logger = new Logger($pdo);
$alertType = "";
$alertMsg = "";

// Capture session errors passed from redirects
if (isset($_SESSION['error'])) {
    $alertType = 'error';
    $alertMsg = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Capture URL messages (from backup.php)
if (isset($_GET['msg'])) {
    $alertType = 'success';
    $alertMsg = htmlspecialchars($_GET['msg']);
} elseif (isset($_GET['error'])) {
    $alertType = 'error';
    $alertMsg = htmlspecialchars($_GET['error']);
}

// 2. HANDLE ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // [SECURITY] Verify CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: Invalid Token. Please refresh the page.");
    }

    // --- ADD NEW USER ---
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $username = trim($_POST['username']);
        $email    = trim($_POST['email']);
        $password = $_POST['password'];
        $role     = $_POST['role'];
        $is_2fa   = isset($_POST['is_2fa']) ? 1 : 0;

        // [PHASE 2 SECURITY] Strong Password Check
        if (strlen($password) < 12) {
            $alertType = 'error';
            $alertMsg = "❌ Password too short! Must be at least 12 characters.";
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $alertType = 'error';
            $alertMsg = "❌ Username must be alphanumeric (letters & numbers only).";
        } elseif (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $password)) {
            $alertType = 'error';
            $alertMsg = "❌ Password must contain Uppercase, Lowercase, Number, and Symbol.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $alertType = 'error';
            $alertMsg = "❌ Invalid email format.";
        } else {
            // Check Duplicate
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);

            if ($check->rowCount() > 0) {
                $alertType = 'warning';
                $alertMsg = "⚠️ Username or Email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, is_2fa_enabled) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$username, $email, $hashed, $role, $is_2fa])) {
                    $logger->log($_SESSION['user_id'], 'USER_ADD', "Created user: $username ($role)");
                    $alertType = 'success';
                    $alertMsg = "✅ User '$username' created successfully!";
                }
            }
        }
    }

    // --- EDIT USER (Reset Password / Change Role) ---
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id       = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email    = trim($_POST['email']);
        $role     = $_POST['role'];
        $is_2fa   = isset($_POST['is_2fa']) ? 1 : 0;
        $new_pass = $_POST['password']; // Optional

        // Check email uniqueness (ignore self)
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $id]);

        if ($chk->rowCount() > 0) {
            $alertType = 'error';
            $alertMsg = "❌ Email '$email' is already taken by another user.";
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $alertType = 'error';
            $alertMsg = "❌ Username must be alphanumeric (letters & numbers only).";
        } else {
            // Update Info
            $sql = "UPDATE users SET username = ?, email = ?, role = ?, is_2fa_enabled = ? WHERE id = ?";
            $params = [$username, $email, $role, $is_2fa, $id];

            // If password changed, validate and hash it
            if (!empty($new_pass)) {
                if (strlen($new_pass) < 12 || !preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $new_pass)) {
                    $alertType = 'error';
                    $alertMsg = "❌ Update Failed: Password must contain Uppercase, Lowercase, Number, and Symbol.";
                } else {
                    $sql = "UPDATE users SET username = ?, email = ?, role = ?, is_2fa_enabled = ?, password = ? WHERE id = ?";
                    $params = [$username, $email, $role, $is_2fa, password_hash($new_pass, PASSWORD_BCRYPT), $id];
                }
            }

            // [FIX] Only execute update if there were no validation errors (e.g. weak password)
            if ($alertType !== 'error') {
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute($params)) {
                    $logger->log($_SESSION['user_id'], 'USER_EDIT', "Updated User ID: $id");
                    $alertType = 'success';
                    $alertMsg = "✅ User details updated!";
                }
            }
        }
    }

    // --- DELETE USER ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = $_POST['user_id'];

        if ($id == $_SESSION['user_id']) {
            $alertType = 'error';
            $alertMsg = "⛔ You cannot delete your own account!";
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $logger->log($_SESSION['user_id'], 'USER_DELETE', "Deleted User ID: $id");
            $alertType = 'success';
            $alertMsg = "🗑️ User deleted successfully.";
        }
    }

    // --- RESTORE DATABASE ---
    // [SECURITY FIX] Password validation must happen before restore
    if (isset($_POST['admin_password'])) {
        $passStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $passStmt->execute([$_SESSION['user_id']]);
        $adminUser = $passStmt->fetch();
        if (!$adminUser || !password_verify($_POST['admin_password'], $adminUser['password'])) {
            $alertType = 'error';
            $alertMsg = "❌ Restore Failed: Incorrect Admin Password.";
            // We must stop execution here to prevent the restore from running
            goto end_of_post;
        }
    }
    if (isset($_FILES['restore_sql']) && $_FILES['restore_sql']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['restore_sql']['tmp_name'];
        $ext = pathinfo($_FILES['restore_sql']['name'], PATHINFO_EXTENSION);
        $sqlContent = '';

        // [FIX] Support ZIP uploads for restore
        if (strtolower($ext) === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($file) === TRUE) {
                // Try to find SQL file
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if (str_ends_with($stat['name'], '.sql')) {
                        $sqlContent = $zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
            } else {
                $alertType = 'error';
                $alertMsg = "❌ Failed to open ZIP file.";
                goto end_of_post;
            }
        } elseif (strtolower($ext) === 'sql') {
            $sqlContent = file_get_contents($file);
        } else {
            $alertType = 'error';
            $alertMsg = "❌ Invalid file type. Please upload .sql or .zip";
            goto end_of_post;
        }

        if ($sqlContent) {
            try {
                // [FIX] Increase limits for large restores
                set_time_limit(1800); // 30 minutes max
                ini_set('memory_limit', '2G'); // 2GB max
                // [FIX] Enable emulation to allow multiple statements in one go
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

                // Disable foreign key checks to allow dropping tables
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                // Execute the SQL dump
                $pdo->exec($sqlContent);

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                // [FIX] Revert emulation setting
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                $logger->log($_SESSION['user_id'], 'DB_RESTORE', "Restored database from backup.");
                $alertType = 'success';
                $alertMsg = "✅ Database restored successfully!";
            } catch (PDOException $e) {
                $alertType = 'error';
                $alertMsg = "❌ Restore Failed: " . $e->getMessage();
            }
        } else {
            $alertType = 'error';
            $alertMsg = "❌ No SQL file found inside the uploaded ZIP.";
        }
    }

    // --- RESTORE FROM SERVER AUTO-BACKUP ---
    if (isset($_POST['action']) && $_POST['action'] === 'restore_local') {
        $filename = basename($_POST['filename']);
        $filepath = __DIR__ . '/../backups/' . $filename;

        if (file_exists($filepath)) {
            $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
            $sqlContent = '';

            if ($ext === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($filepath) === TRUE) {
                    // Fetch backup password if any
                    $bkPass = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_password'")->fetchColumn();
                    if ($bkPass) {
                        $zip->setPassword($bkPass);
                    }

                    // Find the .sql file inside
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        if (str_ends_with($stat['name'], '.sql')) {
                            $sqlContent = $zip->getFromIndex($i);
                            break;
                        }
                    }
                    $zip->close();
                } else {
                    $alertType = 'error';
                    $alertMsg = "❌ Failed to open ZIP archive.";
                    goto end_of_post;
                }
            } elseif ($ext === 'sql') {
                $sqlContent = file_get_contents($filepath);
            }

            if ($sqlContent) {
                try {
                    // [FIX] Increase limits for large restores
                    set_time_limit(1800); // 30 minutes max
                    ini_set('memory_limit', '2G'); // 2GB max
                    // [FIX] Enable emulation for multi-statement execution
                    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $pdo->exec($sqlContent);
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                    $logger->log($_SESSION['user_id'], 'DB_RESTORE', "Restored from auto-backup: $filename");
                    $alertType = 'success';
                    $alertMsg = "✅ Database successfully restored from auto-backup: $filename";
                } catch (PDOException $e) {
                    $alertType = 'error';
                    $alertMsg = "❌ Restore Failed: " . $e->getMessage();
                }
            } else {
                $alertType = 'error';
                $alertMsg = "❌ No SQL file found in backup (or wrong password).";
            }
        }
    }

    end_of_post: // Label to jump to if password fails

    // [SECURITY] Regenerate CSRF token after successful action to prevent replay attacks
    if ($alertType === 'success') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// 3. FETCH USERS
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// 4. FETCH SERVER BACKUPS
$backupDir = __DIR__ . '/../backups/';
$serverBackups = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $f) {
        if (in_array(pathinfo($f, PATHINFO_EXTENSION), ['sql', 'zip'])) {
            $serverBackups[] = [
                'name' => $f,
                'size' => round(filesize($backupDir . $f) / 1024, 2) . ' KB',
                'date' => date('M d, Y H:i', filemtime($backupDir . $f))
            ];
        }
    }
    // Sort by name desc (usually date desc for Y-m-d filenames)
    rsort($serverBackups);
}

// [NEW] Fetch default vault setting for checkboxes
$bkVaultSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_include_vault'")->fetchColumn();
$vaultChecked = ($bkVaultSetting === '1') ? 'checked' : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage System Users</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-shield-lock"></i> User Management Console</span>
        </div>
    </nav>

    <div class="container">

        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-danger shadow-sm">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-shield-exclamation"></i> Disaster Recovery Zone</span>
                        <div>
                            <button class="btn btn-sm btn-outline-light me-2" data-bs-toggle="modal" data-bs-target="#restoreHelpModal"><i class="bi bi-question-circle"></i> How to Restore</button>
                            <small class="bg-white text-danger px-2 rounded fw-bold">ADMIN ONLY</small>
                        </div>
                    </div>
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div class="w-50">
                            <h5 class="card-title text-danger fw-bold">Database Backup</h5>
                            <p class="card-text text-muted mb-0">
                                Download a full SQL dump. Use this to restore data if the server crashes.
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#downloadBackupModal">
                                <i class="bi bi-database-down"></i> Download Backup
                            </button>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#serverBackupModal">
                                <i class="bi bi-hdd-network"></i> Save to Server
                            </button>
                            <a href="system_recovery.php" class="btn btn-outline-dark">
                                <i class="bi bi-tools"></i> Recovery Console
                            </a>

                            <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-2 border-start ps-3">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div>
                                    <label class="form-label small fw-bold text-muted mb-0">Restore SQL</label>
                                    <input type="file" name="restore_sql" class="form-control form-control-sm" accept=".sql,.zip" required>
                                </div>
                                <input type="password" name="admin_password" class="form-control form-control-sm mt-2" placeholder="Confirm Admin Password" required maxlength="128" title="Enter your admin password to confirm">
                                <button type="submit" class="btn btn-danger btn-sm mt-2 w-100" onclick="confirmRestore(event)"><i class="bi bi-upload"></i> Restore</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AUTO-BACKUPS LIST -->
        <?php if (!empty($serverBackups)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Available Auto-Backups (Server)</h5>
                        </div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Filename</th>
                                        <th>Date Created</th>
                                        <th>Size</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($serverBackups as $b): ?>
                                        <tr id="row-<?php echo htmlspecialchars(str_replace('.', '-', $b['name'])); ?>">
                                            <td><?php echo htmlspecialchars($b['name']); ?></td>
                                            <td><?php echo $b['date']; ?></td>
                                            <td><?php echo $b['size']; ?></td>
                                            <td>
                                                <form method="POST" onsubmit="confirmForm(event, <?php echo htmlspecialchars(json_encode('Restore from ' . $b['name'] . '? Current data will be replaced.'), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <input type="hidden" name="action" value="restore_local">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['name']); ?>">
                                                    <input type="password" name="admin_password" class="form-control form-control-sm mb-1" placeholder="Admin Password" required maxlength="128" style="width: 140px;" title="Enter your admin password to confirm">
                                                    <button type="submit" class="btn btn-sm btn-warning fw-bold w-100">Restore This</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- MODALS FOR BACKUP -->
        <div class="modal fade" id="downloadBackupModal" tabindex="-1">
            <div class="modal-dialog">
                <form action="backup.php" method="POST" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Download Database Backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="include_vault" value="1" id="dlVault" <?php echo $vaultChecked; ?>>
                            <label class="form-check-label fw-bold" for="dlVault">Include Vault Files (Images/PDFs)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password (Optional)</label>
                            <input type="password" name="backup_password" class="form-control" placeholder="Leave blank for unencrypted SQL" maxlength="50" autocomplete="new-password">
                            <div class="form-text">Creates a password-protected ZIP file.</div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-primary">Download</button></div>
                </form>
            </div>
        </div>
        <div class="modal fade" id="serverBackupModal" tabindex="-1">
            <div class="modal-dialog">
                <form action="backup.php?mode=server" method="POST" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Save Backup to Server</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <p>This will save a backup to the configured server paths. This is recommended for automated recovery.</p>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="include_vault" value="1" id="svVault" <?php echo $vaultChecked; ?>>
                            <label class="form-check-label fw-bold" for="svVault">Include Vault Files (Images/PDFs)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password (Optional)</label>
                            <input type="password" name="backup_password" class="form-control" placeholder="Leave blank for unencrypted SQL" maxlength="50" autocomplete="new-password">
                            <div class="form-text">Creates a password-protected ZIP file on the server. <strong>Note:</strong> This may complicate automated restores.</div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-danger">Save to Server</button></div>
                </form>
            </div>
        </div>

        <!-- RESTORE HELP MODAL -->
        <div class="modal fade" id="restoreHelpModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="bi bi-life-preserver"></i> Restoration Guide</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <h6 class="fw-bold text-primary">Option 1: Database Restore (Automatic)</h6>
                        <p class="small text-muted">Use this to roll back data changes (e.g. accidental deletion).</p>
                        <ol class="small">
                            <li>Locate a backup in the <strong>Available Auto-Backups</strong> list.</li>
                            <li>Click the <strong>Restore This</strong> button.</li>
                            <li>Enter your Admin Password to confirm.</li>
                        </ol>
                        <hr>
                        <h6 class="fw-bold text-danger">Option 2: Full System Recovery (Manual)</h6>
                        <p class="small text-muted">Use this if the server crashed or you moved to a new PC.</p>
                        <ol class="small">
                            <li><strong>Database:</strong> Upload your <code>.zip</code> or <code>.sql</code> backup file using the "Restore SQL" form on this page. This restores employee records.</li>
                            <li><strong>Documents (Vault):</strong>
                                <ul>
                                    <li>Open your Backup ZIP file.</li>
                                    <li>Extract the <code>vault</code> folder.</li>
                                    <li>Copy it to your server folder: <code>C:\xampp\htdocs\hr 201\vault\</code></li>
                                </ul>
                            </li>
                            <li><strong>Encryption Key:</strong> Ensure <code>src/FileService.php</code> is restored if lost, as it contains the secret key.</li>
                        </ol>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-person-plus-fill"></i> Add New User</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Username</label>
                                <input type="text" name="username" class="form-control" placeholder="e.g. hrofficer" required
                                    maxlength="50"
                                    pattern="[a-zA-Z0-9]+"
                                    title="Only letters and numbers are allowed. No spaces or special characters."
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9]/g, '')">
                                <div class="form-text text-muted small">Max 50 chars. Letters & numbers only. No spaces allowed.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="user@company.com" required
                                    maxlength="120"
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9@._%+-]/g, '')">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Enter strong password..." required
                                    minlength="12" maxlength="128"
                                    pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{12,}" title="Must be at least 12 characters, contain Uppercase, Lowercase, Number, and Symbol.">
                                <div class="form-text text-muted small">Requirements: 12+ chars, Uppercase, Lowercase, Number, Symbol.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Role Permission</label>
                                <select name="role" class="form-select" required>
                                    <option value="STAFF">Staff (Encoder - Add/Edit Only)</option>
                                    <option value="HR">HR Officer (Full Edit + Reports)</option>
                                    <option value="MANAGER">Manager (HR Head - Approvals + Logs)</option>
                                    <option value="ADMIN">Admin Manager (Full System Access)</option>
                                </select>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="is_2fa" id="add2fa">
                                <label class="form-check-label" for="add2fa">Enable 2FA (Email OTP)</label>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Create Account</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0 text-primary"><i class="bi bi-people-fill"></i> Authorized Users</h5>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <?php echo htmlspecialchars($u['username']); ?>
                                            <?php if ($u['id'] == $_SESSION['user_id']) echo ' <span class="badge bg-info text-dark ms-1">You</span>'; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                        <td>
                                            <?php
                                            $badge = match ($u['role']) {
                                                'ADMIN' => 'bg-danger',
                                                'HR' => 'bg-primary',
                                                'MANAGER' => 'bg-warning text-dark',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge <?php echo $badge; ?>"><?php echo $u['role']; ?></span>
                                            <?php if (!empty($u['is_2fa_enabled'])): ?>
                                                <span class="badge bg-info text-dark" title="2FA Enabled"><i class="bi bi-shield-lock"></i> 2FA</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUser<?php echo $u['id']; ?>">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </button>

                                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                <form method="POST" class="d-inline" onsubmit="confirmForm(event, 'Permanently delete this user?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="editUser<?php echo $u['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title">Edit User: <?php echo htmlspecialchars($u['username']); ?></h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="action" value="edit">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">

                                                        <div class="mb-3">
                                                            <label class="form-label">Username</label>
                                                            <input type="text" name="username" class="form-control"
                                                                value="<?php echo htmlspecialchars($u['username']); ?>"
                                                                required
                                                                maxlength="50"
                                                                pattern="[a-zA-Z0-9]+"
                                                                title="Only letters and numbers are allowed. No spaces or special characters."
                                                                oninput="this.value = this.value.replace(/[^a-zA-Z0-9]/g, '')">
                                                            <div class="form-text small text-muted">* Max 50 chars. No spaces allowed.</div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" name="email" class="form-control"
                                                                value="<?php echo htmlspecialchars($u['email'] ?? ''); ?>"
                                                                required
                                                                maxlength="120"
                                                                title="Please enter a valid email address"
                                                                oninput="this.value = this.value.replace(/[^a-zA-Z0-9@._%+-]/g, '')">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Role</label>
                                                            <select name="role" class="form-select">
                                                                <option value="STAFF" <?php if ($u['role'] == 'STAFF') echo 'selected'; ?>>Staff</option>
                                                                <option value="HR" <?php if ($u['role'] == 'HR') echo 'selected'; ?>>HR Officer</option>
                                                                <option value="MANAGER" <?php if ($u['role'] == 'MANAGER') echo 'selected'; ?>>Manager</option>
                                                                <option value="ADMIN" <?php if ($u['role'] == 'ADMIN') echo 'selected'; ?>>Admin Manager</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-check mb-3">
                                                            <input class="form-check-input" type="checkbox" name="is_2fa" id="edit2fa<?php echo $u['id']; ?>" <?php echo (!empty($u['is_2fa_enabled'])) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="edit2fa<?php echo $u['id']; ?>">Enable 2FA (Email OTP)</label>
                                                        </div>
                                                        <hr>
                                                        <div class="mb-3">
                                                            <label class="form-label text-danger fw-bold">Reset Password (Optional)</label>
                                                            <input type="password" name="password" class="form-control" placeholder="New Password (Min 12 chars)"
                                                                minlength="12" maxlength="128"
                                                                pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{12,}" title="Must be at least 12 characters, contain Uppercase, Lowercase, Number, and Symbol.">
                                                            <div class="form-text">Optional. Requirements: 12+ chars, Upper, Lower, #, Symbol.</div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        // ==========================================
        // [SECURITY] AUTO-LOGOUT (Client-Side)
        // ==========================================
        const INACTIVITY_LIMIT = 900000; // 15 Minutes
        let autoLogoutTimer;

        function resetTimer() {
            clearTimeout(autoLogoutTimer);
            autoLogoutTimer = setTimeout(doLogout, INACTIVITY_LIMIT);
        }

        function doLogout() {
            window.location.href = 'logout.php?msg=Session_Expired_Auto';
        }

        window.onload = resetTimer;
        document.addEventListener('mousemove', resetTimer);
        document.addEventListener('keydown', resetTimer);
        document.addEventListener('click', resetTimer);
        document.addEventListener('scroll', resetTimer);
    </script>
    <script>
        <?php if ($alertMsg): ?>
            Swal.fire({
                icon: '<?php echo $alertType; ?>',
                html: <?php echo json_encode($alertMsg); ?>
            });
            // [FIX] Clear URL parameters to prevent message from reappearing on refresh
            if (window.history.replaceState && window.location.search) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        <?php endif; ?>

        function confirmForm(e, msg) {
            e.preventDefault();
            const form = e.target;
            Swal.fire({
                title: 'Are you sure?',
                text: msg,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, proceed!'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        }

        function confirmRestore(e) {
            e.preventDefault();
            const form = e.target.closest('form');
            Swal.fire({
                title: '⚠️ CRITICAL WARNING',
                text: "This will OVERWRITE your current database. This cannot be undone. Are you sure?",
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'YES, OVERWRITE DATABASE'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        }

        // [NEW] Auto-scroll to target backup if requested
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            const target = urlParams.get('restore_target');
            if (target) {
                const rowId = 'row-' + target.replace(/\./g, '-');
                const row = document.getElementById(rowId);
                if (row) {
                    row.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    row.classList.add('table-warning'); // Highlight
                    Swal.fire({
                        icon: 'info',
                        title: 'Restore Backup',
                        text: 'Please enter your Admin Password in the highlighted row to confirm restoration.',
                        timer: 5000
                    });
                }
            }
        });
    </script>
</body>

</html>