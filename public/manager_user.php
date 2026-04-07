<?php
// ======================================================
// [FILE] public/manager_user.php
// [STATUS] MERGED: Disaster Recovery + Phase 2 Security
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [NEW] Force Browser Cache Clear for this page so users always see new buttons
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1. SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    $_SESSION['error'] = "Access Denied: Admin privileges required.";
    header("Location: index.php");
    exit;
}

// [NEW] Fetch Client-side session timeout from DB
$clientTimeout = 900; // Default 15 minutes
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val !== false && (int)$val > 0) {
        $clientTimeout = (int)$val;
    }
} catch (Exception $e) {
    // Settings table might not exist, use default
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

// --- VIEW BACKUP CONTENTS (AJAX) ---
if (isset($_GET['action']) && $_GET['action'] === 'view_backup') {
    header('Content-Type: application/json');
    $baseName = basename($_GET['base_name'] ?? '');
    $backupDir = realpath(__DIR__ . '/../backups');

    if (!$backupDir) {
        echo json_encode(['status' => 'error', 'message' => 'Backups directory not found.']);
        exit;
    }

    $backupDir .= DIRECTORY_SEPARATOR;
    $parts = [];
    foreach (scandir($backupDir) as $f) {
        if ($f === $baseName || strpos($f, $baseName . '_Part') === 0) {
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['zip', 'sql'])) {
                $parts[] = $backupDir . $f;
            }
        }
    }

    if (empty($parts)) {
        echo json_encode(['status' => 'error', 'message' => 'Backup files not found on disk.']);
        exit;
    }

    $summary = ['sql_files' => 0, 'vault_files' => 0, 'config_files' => 0, 'total_files' => 0, 'total_uncompressed' => 0];

    foreach ($parts as $filepath) {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($filepath) === TRUE) {
                $summary['total_files'] += $zip->numFiles;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $summary['total_uncompressed'] += $stat['size'];
                    $name = $stat['name'];
                    if (substr($name, -4) === '.sql') $summary['sql_files']++;
                    elseif (strpos($name, 'config.php') !== false) $summary['config_files']++;
                    elseif (strpos($name, 'vault/') === 0) $summary['vault_files']++;
                }
                $zip->close();
            }
        } elseif ($ext === 'sql') {
            $summary['total_files']++;
            $summary['sql_files']++;
            $summary['total_uncompressed'] += filesize($filepath);
        }
    }

    $sizeMB = round($summary['total_uncompressed'] / 1024 / 1024, 2);
    $html = "<ul class='list-group text-start shadow-sm'>";
    $html .= "<li class='list-group-item d-flex justify-content-between align-items-center'>Database SQL Files <span class='badge bg-primary rounded-pill'>{$summary['sql_files']}</span></li>";
    $html .= "<li class='list-group-item d-flex justify-content-between align-items-center'>Vault Documents <span class='badge bg-success rounded-pill'>{$summary['vault_files']}</span></li>";
    $html .= "<li class='list-group-item d-flex justify-content-between align-items-center'>System Configs <span class='badge bg-warning text-dark rounded-pill'>{$summary['config_files']}</span></li>";
    $html .= "<li class='list-group-item list-group-item-light fw-bold d-flex justify-content-between align-items-center'>Total Uncompressed Size <span>{$sizeMB} MB</span></li>";
    $html .= "</ul>";

    echo json_encode(['status' => 'success', 'html' => $html, 'parts' => count($parts)]);
    exit;
}

// --- VIEW SCHEMA (AJAX) ---
if (isset($_GET['action']) && $_GET['action'] === 'view_schema') {
    header('Content-Type: application/json');
    $html = '<div style="text-align: left;">';
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $safeTable = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $sql = htmlspecialchars($create['Create Table'] ?? $create['Create View'] ?? 'N/A');
        $html .= "
            <details class='mb-2 border rounded shadow-sm bg-white'>
                <summary class='fw-bold text-primary p-2 bg-light' style='cursor: pointer;'>
                    <i class='bi bi-table me-2'></i> {$safeTable}
                </summary>
                <div class='p-0 m-0'>
                    <pre class='bg-dark text-light p-3 m-0' style='font-size: 0.75rem; overflow-x: auto; border-top-left-radius:0; border-top-right-radius:0;'><code>$sql</code></pre>
                </div>
            </details>";
    }
    $html .= '</div>';
    echo json_encode(['status' => 'success', 'html' => $html]);
    exit;
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
        $confirm  = $_POST['confirm_password'] ?? '';
        $role     = $_POST['role'];
        $is_2fa   = isset($_POST['is_2fa']) ? 1 : 0;
        $is_shared = isset($_POST['is_shared']) ? 1 : 0;
        $owner    = trim($_POST['account_owner']);
        if ($owner === '') $owner = null; // [CONSISTENCY] Store NULL if empty

        // [PHASE 2 SECURITY] Strong Password Check
        if ($password !== $confirm) {
            $alertType = 'error';
            $alertMsg = "❌ Passwords do not match.";
        } elseif (strlen($password) < 15) {
            $alertType = 'error';
            $alertMsg = "❌ Password too short! Must be at least 15 characters (MHI Policy).";
        } elseif (strlen($username) > 50) {
            $alertType = 'error';
            $alertMsg = "❌ Username exceeds 50 characters.";
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $alertType = 'error';
            $alertMsg = "❌ Username must be alphanumeric (letters & numbers only).";
        } elseif (strlen($email) > 120) {
            $alertType = 'error';
            $alertMsg = "❌ Email exceeds 120 characters.";
        } elseif (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $password)) {
            $alertType = 'error';
            $alertMsg = "❌ Password must contain Uppercase, Lowercase, Number, and Symbol.";
        } elseif (stripos($password, $username) !== false) {
            $alertType = 'error';
            $alertMsg = "❌ Password cannot contain the Username.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $alertType = 'error';
            $alertMsg = "❌ Invalid email format.";
        } elseif ($is_shared && empty($owner)) {
            $alertType = 'error';
            $alertMsg = "❌ Shared Account requires an Account Owner name.";
        } elseif ($owner && strlen($owner) > 100) {
            $alertType = 'error';
            $alertMsg = "❌ Account Owner name too long (Max 100 chars).";
        } else {
            // Check Duplicate
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);

            if ($check->rowCount() > 0) {
                $alertType = 'warning';
                $alertMsg = "⚠️ Username or Email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, is_2fa_enabled, is_shared, account_owner) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$username, $email, $hashed, $role, $is_2fa, $is_shared, $owner])) {
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
        $is_shared = isset($_POST['is_shared']) ? 1 : 0;
        $owner    = trim($_POST['account_owner']);
        if ($owner === '') $owner = null; // [CONSISTENCY] Store NULL if empty
        $new_pass = $_POST['password']; // Optional

        // Check email uniqueness (ignore self)
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $id]);

        if ($chk->rowCount() > 0) {
            $alertType = 'error';
            $alertMsg = "❌ Email '$email' is already taken by another user.";
        } elseif (strlen($username) > 50) {
            $alertType = 'error';
            $alertMsg = "❌ Username exceeds 50 characters.";
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $alertType = 'error';
            $alertMsg = "❌ Username must be alphanumeric (letters & numbers only).";
        } elseif (strlen($email) > 120) {
            $alertType = 'error';
            $alertMsg = "❌ Email exceeds 120 characters.";
        } elseif ($is_shared && empty($owner)) {
            $alertType = 'error';
            $alertMsg = "❌ Shared Account requires an Account Owner name.";
        } elseif ($owner && strlen($owner) > 100) {
            $alertType = 'error';
            $alertMsg = "❌ Account Owner name too long (Max 100 chars).";
        } else {
            // [LOGICAL FIX] Prevent the last Admin from downgrading themselves, and do this in a transaction for atomicity
            $isAdminDowngrade = false;
            $transactionActive = false;

            try {
                $pdo->beginTransaction();
                $transactionActive = true;

                if ($role !== 'ADMIN') {
                    $chkAdmin = $pdo->prepare("SELECT role FROM users WHERE id = ? FOR UPDATE");
                    $chkAdmin->execute([$id]);
                    if ($chkAdmin->fetchColumn() === 'ADMIN') {
                        $adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' FOR UPDATE")->fetchColumn();
                        if ($adminCount <= 1) {
                            $isAdminDowngrade = true;
                            $alertType = 'error';
                            $alertMsg = "❌ Cannot downgrade the last Administrator. Please assign another Admin first.";
                        }
                    }
                }

                if (!$isAdminDowngrade) {
                    // Update Info
                    $sql = "UPDATE users SET username = ?, email = ?, role = ?, is_2fa_enabled = ?, is_shared = ?, account_owner = ? WHERE id = ?";
                    $params = [$username, $email, $role, $is_2fa, $is_shared, $owner, $id];

                    // If password changed, validate and hash it
                    if (!empty($new_pass)) {
                        $confirm = $_POST['confirm_password'] ?? '';
                        if ($new_pass !== $confirm) {
                            $alertType = 'error';
                            $alertMsg = "❌ Update Failed: Passwords do not match.";
                        } elseif (strlen($new_pass) < 15 || !preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $new_pass)) {
                            $alertType = 'error';
                            $alertMsg = "❌ Update Failed: Password must be 15+ chars with Uppercase, Lowercase, Number, and Symbol.";
                        } elseif (stripos($new_pass, $username) !== false) {
                            $alertType = 'error';
                            $alertMsg = "❌ Update Failed: Password cannot contain the Username.";
                        } else {
                            $sql = "UPDATE users SET username = ?, email = ?, role = ?, is_2fa_enabled = ?, is_shared = ?, account_owner = ?, password = ? WHERE id = ?";
                            $params = [$username, $email, $role, $is_2fa, $is_shared, $owner, password_hash($new_pass, PASSWORD_BCRYPT), $id];
                            $admin_reset_password_flag = true;
                        }
                    }

                    // [FIX] Only execute update if there were no validation errors (e.g. weak password)
                    if ($alertType !== 'error') {
                        $stmt = $pdo->prepare($sql);
                        if ($stmt->execute($params)) {
                            $logger->log($_SESSION['user_id'], 'USER_EDIT', "Updated User ID: $id");
                            if (isset($admin_reset_password_flag)) {
                                $logger->log($_SESSION['user_id'], 'ADMIN_PASSWORD_RESET', "Forced password reset for user: $username (ID: $id)");
                            }
                            $alertType = 'success';
                            $alertMsg = "✅ User details updated!";
                            if ($transactionActive && $pdo->inTransaction()) {
                                $pdo->commit();
                                $transactionActive = false;
                            }
                        } else {
                            $alertType = 'error';
                            $alertMsg = "❌ Failed to update user.";
                            if ($transactionActive && $pdo->inTransaction()) {
                                $pdo->rollBack();
                                $transactionActive = false;
                            }
                        }
                    } else {
                        if ($transactionActive && $pdo->inTransaction()) {
                            $pdo->rollBack();
                            $transactionActive = false;
                        }
                    }
                } else {
                    if ($transactionActive && $pdo->inTransaction()) {
                        $pdo->rollBack();
                        $transactionActive = false;
                    }
                }
            } catch (Exception $e) {
                if ($transactionActive && $pdo->inTransaction()) {
                    $pdo->rollBack();
                    $transactionActive = false;
                }
                $alertType = 'error';
                $alertMsg = "❌ Update Failed due to a concurrency exception. Please try again.";
                $logger->log($_SESSION['user_id'], 'USER_EDIT_ERROR', "Transaction error when updating user ID $id: " . $e->getMessage());
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

    // --- RESET 2FA (AUTHENTICATOR) ---
    if (isset($_POST['action']) && $_POST['action'] === 'reset_2fa') {
        $id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $adminPassword = $_POST['admin_password'] ?? '';

        if ($id <= 0) {
            $alertType = 'error';
            $alertMsg = "❌ Invalid user selected.";
        } elseif (empty($adminPassword)) {
            $alertType = 'error';
            $alertMsg = "❌ Admin password is required to reset 2FA.";
        } else {
            // Verify current admin password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($adminPassword, $admin['password'])) {
                $alertType = 'error';
                $alertMsg = "❌ Incorrect admin password.";
            } else {
                // Ensure the target user exists before resetting
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $alertType = 'error';
                    $alertMsg = "❌ User not found.";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        $logger->log($_SESSION['user_id'], 'USER_RESET_2FA', "Reset Authenticator App for User ID: $id");
                        $alertType = 'success';
                        $alertMsg = "✅ Authenticator app has been reset! The user will be required to scan a new QR code on their next login.";
                    } else {
                        $alertType = 'error';
                        $alertMsg = "❌ Failed to reset 2FA for the user.";
                    }
                }
            }
        }
    }

    // --- UNLOCK USER ---
    if (isset($_POST['action']) && $_POST['action'] === 'unlock') {
        $id = $_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
        if ($stmt->execute([$id])) {
            $logger->log($_SESSION['user_id'], 'USER_UNLOCK', "Unlocked User ID: $id");
            $alertType = 'success';
            $alertMsg = "✅ User account has been unlocked.";
        } else {
            $alertType = 'error';
            $alertMsg = "❌ Failed to unlock user.";
        }
    }


    // --- RESTORE DATABASE ---
    $hasRestoreFiles = false;
    if (isset($_FILES['restore_sql'])) {
        foreach ((array)$_FILES['restore_sql']['error'] as $err) {
            if ($err === UPLOAD_ERR_OK) {
                $hasRestoreFiles = true;
                break;
            }
        }
    }

    if ($hasRestoreFiles) {
        $restoreAllowed = true;
        // [SECURITY] Enforce Password Check
        if (empty($_POST['admin_password'])) {
            $alertType = 'error';
            $alertMsg = "❌ Restore Failed: Admin Password is required.";
            $restoreAllowed = false;
        } else {
            $passStmt = $pdo->prepare("SELECT password, email FROM users WHERE id = ?");
            $passStmt->execute([$_SESSION['user_id']]);
            $adminUser = $passStmt->fetch();
            if (!$adminUser || !password_verify($_POST['admin_password'], $adminUser['password'])) {
                $alertType = 'error';
                $alertMsg = "❌ Restore Failed: Incorrect Admin Password.";
                $restoreAllowed = false;
            }
        }

        if ($restoreAllowed) {
            $uploadList = [];
            $filesData = $_FILES['restore_sql'];
            if (is_array($filesData['name'])) {
                for ($i = 0; $i < count($filesData['name']); $i++) {
                    if ($filesData['error'][$i] === UPLOAD_ERR_OK) {
                        $uploadList[] = [
                            'name' => $filesData['name'][$i],
                            'tmp_name' => $filesData['tmp_name'][$i],
                            'ext' => pathinfo($filesData['name'][$i], PATHINFO_EXTENSION)
                        ];
                    }
                }
                // Sort by original filename to ensure Part1 executes before Part2
                usort($uploadList, function ($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
            } else {
                $uploadList[] = [
                    'name' => $filesData['name'],
                    'tmp_name' => $filesData['tmp_name'],
                    'ext' => pathinfo($filesData['name'], PATHINFO_EXTENSION)
                ];
            }

            try {
                set_time_limit(0); // Unlimited time for massive databases
                ignore_user_abort(true); // [CRITICAL] Continue background restore even if Chrome times out
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                // Begin transaction for the restore. Note: certain SQL statements (DDL) may force an implicit commit.
                $pdo->beginTransaction();

                $partsRestored = 0;
                $queriesExecuted = 0;

                foreach ($uploadList as $fileItem) {
                    $file = $fileItem['tmp_name'];
                    $ext = strtolower($fileItem['ext']);
                    $stream = null;
                    $zip = null;

                    // [FIX] Support ZIP uploads for restore
                    if ($ext === 'zip') {
                        $zip = new ZipArchive;
                        if ($zip->open($file) === TRUE) {
                            // Try to find SQL file
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $stat = $zip->statIndex($i);
                                if (substr($stat['name'], -4) === '.sql') {
                                    $stream = $zip->getStream($stat['name']);
                                    break;
                                }
                            }
                        } else {
                            throw new Exception("Failed to open ZIP file: " . $fileItem['name']);
                        }
                    } elseif ($ext === 'sql') {
                        $stream = fopen($file, 'r');
                    } else {
                        throw new Exception("Invalid file type. Please upload .sql or .zip");
                    }

                    if ($stream) {
                        // [OPTIMIZATION] Read and execute line-by-line (Zero RAM consumption)
                        // Use a small state machine to avoid stripping comment markers inside quoted strings.
                        $query = '';
                        $inBlockComment = false;
                        $inSingleQuote = false;
                        $inDoubleQuote = false;
                        while (($line = fgets($stream)) !== false) {
                            $len = strlen($line);
                            $cleanLine = '';

                            for ($i = 0; $i < $len; $i++) {
                                $ch = $line[$i];
                                $next = $line[$i + 1] ?? '';

                                if ($inBlockComment) {
                                    if ($ch === '*' && $next === '/') {
                                        $inBlockComment = false;
                                        $i++; // Skip '/'
                                    }
                                    continue;
                                }

                                if ($inSingleQuote) {
                                    if ($ch === "\\") {
                                        // Preserve escaped characters within strings
                                        $cleanLine .= $ch;
                                        if (isset($line[$i + 1])) {
                                            $cleanLine .= $line[++$i];
                                        }
                                        continue;
                                    }
                                    if ($ch === "'") {
                                        $inSingleQuote = false;
                                    }
                                    $cleanLine .= $ch;
                                    continue;
                                }

                                if ($inDoubleQuote) {
                                    if ($ch === "\\") {
                                        $cleanLine .= $ch;
                                        if (isset($line[$i + 1])) {
                                            $cleanLine .= $line[++$i];
                                        }
                                        continue;
                                    }
                                    if ($ch === '"') {
                                        $inDoubleQuote = false;
                                    }
                                    $cleanLine .= $ch;
                                    continue;
                                }

                                // Not inside a quote or comment
                                if ($ch === '-' && $next === '-') {
                                    // Only treat as a line comment if followed by whitespace or end-of-line
                                    $after = $line[$i + 2] ?? '';
                                    if ($after === '' || ctype_space($after)) {
                                        break; // ignore rest of the line
                                    }
                                }

                                if ($ch === '/' && $next === '*') {
                                    $inBlockComment = true;
                                    $i++; // Skip '*'
                                    continue;
                                }

                                if ($ch === "'") {
                                    $inSingleQuote = true;
                                    $cleanLine .= $ch;
                                    continue;
                                }

                                if ($ch === '"') {
                                    $inDoubleQuote = true;
                                    $cleanLine .= $ch;
                                    continue;
                                }

                                $cleanLine .= $ch;
                            }

                            $trimLine = trim($cleanLine);
                            if ($trimLine === '') {
                                continue;
                            }

                            $query .= $trimLine . "\n";
                            // Execute when we reach a statement terminator outside quotes
                            if (substr($trimLine, -1) === ';') {
                                if ($pdo->exec($query) === false) {
                                    $errorInfo = $pdo->errorInfo();
                                    throw new Exception("SQL Error: " . ($errorInfo[2] ?? 'Unknown error'));
                                }
                                $query = '';
                                $queriesExecuted++;
                            }
                        }
                        fclose($stream);
                        if ($zip) $zip->close();
                        $partsRestored++;
                    } else {
                        if ($zip) $zip->close();
                        throw new Exception("No SQL file found inside the uploaded ZIP: " . $fileItem['name']);
                    }
                }

                if ($queriesExecuted === 0) {
                    throw new Exception("The uploaded file is empty or contains no valid SQL data.");
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                $logger->log($_SESSION['user_id'], 'DB_RESTORE', "Restored database from $partsRestored uploaded parts.");
                $alertType = 'success';
                $alertMsg = "✅ Database restored successfully from $partsRestored file(s)!";

                // [NEW] Notify Admin on Success
                if (!empty($adminUser['email'])) {
                    @mail($adminUser['email'], "System Restore Complete", "The background database restore process has successfully completed from $partsRestored uploaded file(s).");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $alertType = 'error';
                $alertMsg = "❌ Restore Failed: " . $e->getMessage();

                // [NEW] Notify Admin on Failure
                if (!empty($adminUser['email'])) {
                    @mail($adminUser['email'], "System Restore FAILED", "The background database restore process failed.\n\nError: " . $e->getMessage());
                }
            }
        }
    }

    // --- RESTORE FROM SERVER AUTO-BACKUP ---
    if (isset($_POST['action']) && $_POST['action'] === 'restore_local') {
        $restoreAllowed = true;
        // [SECURITY] Enforce Password Check for Server Restore
        if (empty($_POST['admin_password'])) {
            $alertType = 'error';
            $alertMsg = "❌ Restore Failed: Admin Password is required.";
            $restoreAllowed = false;
        } else {
            $passStmt = $pdo->prepare("SELECT password, email FROM users WHERE id = ?");
            $passStmt->execute([$_SESSION['user_id']]);
            $adminUser = $passStmt->fetch();
            if (!$adminUser || !password_verify($_POST['admin_password'], $adminUser['password'])) {
                $alertType = 'error';
                $alertMsg = "❌ Restore Failed: Incorrect Admin Password.";
                $restoreAllowed = false;
            }
        }

        if ($restoreAllowed) {
            $baseName = basename($_POST['base_name']);
            $backupDir = __DIR__ . '/../backups/';

            $partsToRestore = [];
            $files = scandir($backupDir);
            foreach ($files as $f) {
                if ($f === $baseName || strpos($f, $baseName . '_Part') === 0) {
                    if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['zip', 'sql'])) {
                        $partsToRestore[] = $backupDir . $f;
                    }
                }
            }
            if (empty($partsToRestore)) {
                $alertType = 'error';
                $alertMsg = "❌ Restore Failed: Backup files not found.";
            } else {

                sort($partsToRestore); // Ensure sequential execution

                try {
                    set_time_limit(0);
                    ignore_user_abort(true); // [CRITICAL] Continue background restore even if Chrome times out
                    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $pdo->beginTransaction();
                    $queriesExecuted = 0;

                    $bkPass = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_password'")->fetchColumn();

                    foreach ($partsToRestore as $filepath) {
                        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
                        $stream = null;
                        $zip = null;

                        if ($ext === 'zip') {
                            $zip = new ZipArchive;
                            if ($zip->open($filepath) === TRUE) {
                                if ($bkPass) {
                                    $zip->setPassword($bkPass);
                                }
                                for ($i = 0; $i < $zip->numFiles; $i++) {
                                    $stat = $zip->statIndex($i);
                                    if (substr($stat['name'], -4) === '.sql') {
                                        $stream = $zip->getStream($stat['name']);
                                        break;
                                    }
                                }
                            } else {
                                throw new Exception("Failed to open ZIP archive: " . basename($filepath));
                            }
                        } elseif ($ext === 'sql') {
                            $stream = fopen($filepath, 'r');
                        }

                        if ($stream) {
                            // [PARSE] Use a small state machine to avoid stripping comment markers inside quoted strings.
                            $query = '';
                            $inBlockComment = false;
                            $inSingleQuote = false;
                            $inDoubleQuote = false;

                            while (($line = fgets($stream)) !== false) {
                                $len = strlen($line);
                                $cleanLine = '';

                                for ($i = 0; $i < $len; $i++) {
                                    $ch = $line[$i];
                                    $next = $line[$i + 1] ?? '';

                                    if ($inBlockComment) {
                                        if ($ch === '*' && $next === '/') {
                                            $inBlockComment = false;
                                            $i++;
                                        }
                                        continue;
                                    }

                                    if ($inSingleQuote) {
                                        if ($ch === "\\") {
                                            $cleanLine .= $ch;
                                            if (isset($line[$i + 1])) {
                                                $cleanLine .= $line[++$i];
                                            }
                                            continue;
                                        }
                                        if ($ch === "'") {
                                            $inSingleQuote = false;
                                        }
                                        $cleanLine .= $ch;
                                        continue;
                                    }

                                    if ($inDoubleQuote) {
                                        if ($ch === "\\") {
                                            $cleanLine .= $ch;
                                            if (isset($line[$i + 1])) {
                                                $cleanLine .= $line[++$i];
                                            }
                                            continue;
                                        }
                                        if ($ch === '"') {
                                            $inDoubleQuote = false;
                                        }
                                        $cleanLine .= $ch;
                                        continue;
                                    }

                                    if ($ch === '-' && $next === '-') {
                                        $after = $line[$i + 2] ?? '';
                                        if ($after === '' || ctype_space($after)) {
                                            break;
                                        }
                                    }

                                    if ($ch === '/' && $next === '*') {
                                        $inBlockComment = true;
                                        $i++;
                                        continue;
                                    }

                                    if ($ch === "'") {
                                        $inSingleQuote = true;
                                        $cleanLine .= $ch;
                                        continue;
                                    }

                                    if ($ch === '"') {
                                        $inDoubleQuote = true;
                                        $cleanLine .= $ch;
                                        continue;
                                    }

                                    $cleanLine .= $ch;
                                }

                                $trimLine = trim($cleanLine);
                                if ($trimLine === '') {
                                    continue;
                                }

                                $query .= $trimLine . "\n";
                                if (substr($trimLine, -1) === ';') {
                                    $pdo->exec($query);
                                    $query = '';
                                    $queriesExecuted++;
                                }
                            }

                            fclose($stream);
                            if ($zip) $zip->close();
                        } else {
                            if ($zip) $zip->close();
                            throw new Exception("No SQL file found in backup part: " . basename($filepath));
                        }
                    }

                    if ($queriesExecuted === 0) {
                        throw new Exception("The backup file is empty or contains no valid SQL data.");
                    }

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                    $logger->log($_SESSION['user_id'], 'DB_RESTORE', "Restored from server backup: $baseName (" . count($partsToRestore) . " parts)");
                    $alertType = 'success';
                    $alertMsg = "✅ Database successfully restored from " . count($partsToRestore) . " part(s)!";

                    // [NEW] Notify Admin on Success
                    if (!empty($adminUser['email'])) {
                        @mail($adminUser['email'], "System Restore Complete", "The background database restore process from server backup '$baseName' has successfully completed.");
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $alertType = 'error';
                    $alertMsg = "❌ Restore Failed: " . $e->getMessage();

                    // [NEW] Notify Admin on Failure
                    if (!empty($adminUser['email'])) {
                        @mail($adminUser['email'], "System Restore FAILED", "The background database restore process from server backup '$baseName' failed.\n\nError: " . $e->getMessage());
                    }
                }
            }
        }
    }

    // [SECURITY] Regenerate CSRF token after successful action to prevent replay attacks
    if ($alertType === 'success') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// 3. FETCH USERS
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// 4. FETCH SERVER BACKUPS
$backupDir = __DIR__ . '/../backups/';
$groupedBackups = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $f) {
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        if (in_array($ext, ['sql', 'zip'])) {
            $baseName = $f;
            $isMultiPart = false;
            if (preg_match('/^(.+)_Part\d+\.(zip|sql)$/i', $f, $matches)) {
                $baseName = $matches[1];
                $isMultiPart = true;
            }
            if (!isset($groupedBackups[$baseName])) {
                $groupedBackups[$baseName] = [
                    'base_name' => $baseName,
                    'display_name' => $isMultiPart ? $baseName . ' (Multi-Part)' : $f,
                    'parts' => [],
                    'total_size_bytes' => 0,
                    'date' => filemtime($backupDir . $f),
                    'is_multipart' => $isMultiPart
                ];
            }
            $groupedBackups[$baseName]['parts'][] = $f;
            $groupedBackups[$baseName]['total_size_bytes'] += filesize($backupDir . $f);
            if (filemtime($backupDir . $f) > $groupedBackups[$baseName]['date']) {
                $groupedBackups[$baseName]['date'] = filemtime($backupDir . $f);
            }
        }
    }
}
$serverBackups = [];
foreach ($groupedBackups as $b) {
    sort($b['parts']);
    $serverBackups[] = [
        'base_name' => $b['base_name'],
        'display_name' => $b['display_name'],
        'parts' => $b['parts'],
        'size' => round($b['total_size_bytes'] / 1024 / 1024, 2) . ' MB',
        'date' => date('M d, Y H:i', $b['date']),
        'part_count' => count($b['parts'])
    ];
}
// Sort desc by date
usort($serverBackups, function ($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});

// [NEW] Fetch default vault setting for checkboxes
$bkVaultSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_include_vault'")->fetchColumn();
$vaultChecked = ($bkVaultSetting === '1') ? 'checked' : '';
$bkMaxSize = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_max_size_gb'")->fetchColumn() ?: '1.9';

// [NEW] Fetch backup path for UI assurance
$configuredBackupPath = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_path'")->fetchColumn();
$actualBackupPath = (!empty($configuredBackupPath) && is_dir($configuredBackupPath)) ? realpath($configuredBackupPath) : realpath(__DIR__ . '/../backups');
if (!$actualBackupPath) $actualBackupPath = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'backups';
$isBackupWritable = is_writable($actualBackupPath);
?>
<?php require 'header.php'; ?>

<div class="container">

    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-danger shadow-sm">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0"><i class="bi bi-shield-exclamation"></i> Disaster Recovery Zone</h5>
                    <div>
                        <button class="btn btn-sm btn-outline-light me-2" data-bs-toggle="modal" data-bs-target="#restoreHelpModal"><i class="bi bi-question-circle"></i> How to Restore</button>
                        <small class="bg-white text-danger px-2 rounded fw-bold">ADMIN ONLY</small>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Backup Section -->
                        <div class="col-md-6 border-end">
                            <h6 class="fw-bold text-danger">1. System Backup</h6>
                            <p class="small text-muted">Download a full SQL dump of the database or save a copy to the server's backup drive. Includes an option to package the encrypted Vault files.</p>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#downloadBackupModal"><i class="bi bi-database-down"></i> Download Backup</button>
                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#serverBackupModal"><i class="bi bi-hdd-network"></i> Save to Server</button>
                                <a href="system_recovery.php" class="btn btn-sm btn-outline-dark"><i class="bi bi-tools"></i> Recovery Console</a>
                                <form action="system_recovery.php" method="POST" class="m-0" onsubmit="return confirm('WARNING: This will delete ALL orphaned files and broken database records. Ensure you have a backup first. Proceed?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <button type="submit" name="master_sync" class="btn btn-sm btn-primary shadow-sm"><i class="bi bi-arrow-repeat"></i> Run Master Sync</button>
                                </form>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-info fw-bold" onclick="viewSchema()"><i class="bi bi-filetype-sql"></i> View Database Schema</button>
                            </div>
                        </div>
                        <!-- Restore Section -->
                        <div class="col-md-6">
                            <h6 class="fw-bold text-danger">2. System Restore</h6>
                            <p class="small text-muted">Overwrite the current database by uploading a <code>.sql</code> or <code>.zip</code> file. This action is irreversible.</p>
                            <form method="POST" enctype="multipart/form-data" onsubmit="confirmRestore(event)">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Restore SQL/ZIP File(s)</label>
                                    <input type="file" name="restore_sql[]" class="form-control form-control-sm" accept=".sql,.zip" multiple required title="You can select multiple parts at once.">
                                </div>
                                <div class="input-group input-group-sm mb-2">
                                    <input type="password" name="admin_password" id="restoreAdminPass" class="form-control" placeholder="Confirm Admin Password" required maxlength="128" title="Enter your admin password to confirm">
                                    <button class="btn btn-outline-secondary bg-white" type="button" onclick="togglePass('restoreAdminPass')"><i class="bi bi-eye"></i></button>
                                </div>
                                <button type="submit" class="btn btn-danger w-100"><i class="bi bi-upload"></i> Restore Database</button>
                            </form>
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
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> <i class="bi bi-eye-fill"></i> Available Auto-Backups (Server)</h5>
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
                                            <tr id="row-<?php echo htmlspecialchars(str_replace('.', '-', $b['base_name'])); ?>">
                                                <td>
                                                    <?php echo htmlspecialchars($b['display_name']); ?>
                                                    <?php if ($b['part_count'] > 1): ?>
                                                        <span class="badge bg-info text-dark ms-2"><?php echo $b['part_count']; ?> Parts</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $b['date']; ?></td>
                                                <td><?php echo $b['size']; ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-info text-white fw-bold mb-1 w-100" onclick="viewBackupDetails('<?php echo htmlspecialchars($b['base_name'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($b['display_name'], ENT_QUOTES, 'UTF-8'); ?>')">
                                                        <i class="bi bi-search"></i> View Contents
                                                    </button>
                                                    <form method="POST" onsubmit="confirmServerRestore(event, <?php echo htmlspecialchars(json_encode('Restore from ' . $b['display_name'] . '? Current data will be replaced.'), ENT_QUOTES, 'UTF-8'); ?>)">
                                                        <input type="hidden" name="action" value="restore_local">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="base_name" value="<?php echo htmlspecialchars($b['base_name']); ?>">
                                                        <div class="input-group input-group-sm mb-1" style="width: 160px;">
                                                            <input type="password" name="admin_password" id="serverPass_<?php echo htmlspecialchars(str_replace('.', '-', $b['base_name'])); ?>" class="form-control" placeholder="Admin Password" required maxlength="128" title="Enter your admin password to confirm">
                                                            <button class="btn btn-outline-secondary bg-white" type="button" onclick="togglePass('serverPass_<?php echo htmlspecialchars(str_replace('.', '-', $b['base_name'])); ?>')"><i class="bi bi-eye"></i></button>
                                                        </div>
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
                    <form action="backup.php" method="POST" class="modal-content" onsubmit="showBackupLoader(this)">
                        <div class="modal-header">
                            <h5 class="modal-title">Download Database Backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="include_vault" value="1" id="dlVault" <?php echo $vaultChecked; ?>>
                                <label class="form-check-label fw-bold" for="dlVault">Include Vault Files (Images/PDFs)</label>
                                <div class="alert alert-warning small mb-0 mt-2 border-warning">
                                    <i class="bi bi-info-circle-fill"></i> <strong>Massive Data Reminder:</strong> If your backup exceeds the <strong><?php echo htmlspecialchars($bkMaxSize); ?> GB</strong> limit, the system will automatically split it into multiple volumes (Part 1, Part 2, etc.) and download them consecutively.
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password (Optional)</label>
                                <div class="input-group">
                                    <input type="password" name="backup_password" id="dlBackupPass" class="form-control" placeholder="Leave blank for unencrypted SQL" maxlength="50" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('dlBackupPass')"><i class="bi bi-eye"></i></button>
                                </div>
                                <div class="form-text">Creates a password-protected ZIP file.</div>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="submit" class="btn btn-primary">Download</button></div>
                    </form>
                </div>
            </div>
            <div class="modal fade" id="serverBackupModal" tabindex="-1">
                <div class="modal-dialog">
                    <form action="backup.php?mode=server" method="POST" class="modal-content" onsubmit="showBackupLoader(this)">
                        <div class="modal-header">
                            <h5 class="modal-title">Save Backup to Server</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <p>This will save a backup to the configured server paths. This is recommended for automated recovery.</p>

                            <div class="alert alert-<?php echo $isBackupWritable ? 'success' : 'danger'; ?> small py-2 mb-3 border-<?php echo $isBackupWritable ? 'success' : 'danger'; ?>">
                                <i class="bi bi-<?php echo $isBackupWritable ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
                                <strong>Target Directory:</strong><br>
                                <span class="font-monospace text-dark"><?php echo htmlspecialchars($actualBackupPath); ?></span><br>
                                Status: <?php echo $isBackupWritable ? '<span class="text-success fw-bold">Writable (OK)</span>' : '<span class="text-danger fw-bold">Not Writable / Missing Directory</span>'; ?>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="include_vault" value="1" id="svVault" <?php echo $vaultChecked; ?>>
                                <label class="form-check-label fw-bold" for="svVault">Include Vault Files (Images/PDFs)</label>
                                <div class="form-text text-muted mt-1" style="font-size: 0.75rem;">
                                    <i class="bi bi-info-circle"></i> Note: When saving to the server, Vault files are mirrored, not zipped.
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password (Optional)</label>
                                <div class="input-group">
                                    <input type="password" name="backup_password" id="svBackupPass" class="form-control" placeholder="Leave blank for unencrypted SQL" maxlength="50" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('svBackupPass')"><i class="bi bi-eye"></i></button>
                                </div>
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
                            <h6 class="fw-bold text-danger">Option 2: Full System Recovery (Manual & Split ZIPs)</h6>
                            <p class="small text-muted">Use this if the server crashed, you moved to a new PC, or you have a multi-part backup.</p>
                            <ol class="small mb-0">
                                <li class="mb-1"><strong>Database:</strong> Under <em>2. System Restore</em>, click the "Choose Files" button.</li>
                                <li class="mb-1"><strong>Upload:</strong> Browse to your backup file. If your backup is split into multiple parts (e.g., <code>Part1.zip</code>, <code>Part2.zip</code>), highlight and select <strong>ALL</strong> of them at the exact same time.</li>
                                <li class="mb-1"><strong>Confirm:</strong> Type in your Admin Password and click <strong>Restore Database</strong>. The server will automatically organize the parts, silently unpack the SQL inside them, and reconstruct your entire database!</li>
                                <li class="mb-1"><strong>Documents (Vault):</strong>
                                    <div class="text-muted fst-italic mb-1">Note: The ZIP files above only restore the database records.</div>
                                    <ul>
                                        <li>If your backup included Vault Files, open the ZIP file manually on your computer.</li>
                                        <li>Extract the <code>vault</code> folder from the ZIP.</li>
                                        <li>Paste it into your server's directory: <code>C:\xampp\htdocs\hr 201\vault\</code></li>
                                    </ul>
                                </li>
                                <li><strong>Encryption Key:</strong> Ensure <code>config/config.php</code> is restored if lost, as it contains your secure Vault Key.</li>
                            </ol>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-primary" onclick="downloadRestorationGuide()"><i class="bi bi-file-earmark-text"></i> Download .txt</button>
                            <button type="button" class="btn btn-outline-info" onclick="copyRestorationGuide()"><i class="bi bi-clipboard"></i> Copy</button>
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
                                    <div class="input-group">
                                        <input type="password" name="password" id="addPass" class="form-control" placeholder="Enter strong password..." required minlength="15" maxlength="128" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{15,}" title="Must be at least 15 characters, contain Uppercase, Lowercase, Number, and Symbol." oninput="updateStrength(this.value, 'addStrengthBar')">
                                        <button class="btn btn-outline-secondary bg-white" type="button" onclick="togglePass('addPass')"><i class="bi bi-eye"></i></button>
                                    </div>
                                    <div class="progress mt-1" style="height: 5px;">
                                        <div id="addStrengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div class="mt-2 ps-1 small pass-rules">
                                        <div class="rule-len text-muted mb-1"><i class="bi bi-circle"></i> At least 15 characters</div>
                                        <div class="rule-let text-muted mb-1"><i class="bi bi-circle"></i> Contains a letter</div>
                                        <div class="rule-num text-muted mb-1"><i class="bi bi-circle"></i> Contains a number (0-9)</div>
                                        <div class="rule-sym text-muted mb-1"><i class="bi bi-circle"></i> Contains a symbol (!@#$)</div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Confirm Password</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="addConfPass" class="form-control" placeholder="Repeat strong password..." required minlength="15" maxlength="128">
                                        <button class="btn btn-outline-secondary bg-white" type="button" onclick="togglePass('addConfPass')"><i class="bi bi-eye"></i></button>
                                    </div>
                                    <div class="match-msg small mt-1 fw-bold text-danger" style="display:none;">
                                        <i class="bi bi-x-circle"></i> Passwords do not match
                                    </div>
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
                                    <input class="form-check-input" type="checkbox" name="is_2fa" id="add2fa" checked>
                                    <label class="form-check-label fw-bold text-primary" for="add2fa">Enable 2FA (Authenticator App)</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_shared" id="addShared" onchange="document.getElementById('addOwnerDiv').style.display = this.checked ? 'block' : 'none'">
                                    <label class="form-check-label" for="addShared">Shared Account (MHI Regulated)</label>
                                </div>
                                <div class="mb-3" id="addOwnerDiv" style="display:none;">
                                    <label class="form-label fw-bold">Account Owner</label>
                                    <input type="text" name="account_owner" class="form-control" placeholder="Name of responsible person" maxlength="100">
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
                                        <th>Status / Attempts</th>
                                        <th>Created</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $u): ?>
                                        <tr>
                                            <?php
                                            $isLocked = false;
                                            if (!empty($u['locked_until'])) {
                                                try {
                                                    $isLocked = new DateTime($u['locked_until']) > new DateTime();
                                                } catch (Exception $e) {
                                                    $isLocked = false; // Treat invalid date as not locked
                                                }
                                            }
                                            ?> <td class="fw-bold">
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
                                                <?php if (!empty($u['is_shared'])): ?>
                                                    <span class="badge bg-dark border border-light" title="Owner: <?php echo htmlspecialchars($u['account_owner']); ?>"><i class="bi bi-people"></i> Shared</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isLocked): ?>
                                                    <span class="badge bg-danger">LOCKED</span>
                                                <?php elseif (($u['failed_attempts'] ?? 0) > 0): ?>
                                                    <span class="badge bg-warning text-dark"><?php echo (int)$u['failed_attempts']; ?> Failed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                            <td class="text-end">
                                                <?php if ($isLocked): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Unlock this user account?')">
                                                        <input type="hidden" name="action" value="unlock">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning fw-bold"><i class="bi bi-unlock-fill"></i> Unlock</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (!empty($u['totp_secret'])): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Reset Authenticator for this user? They will need to scan a new QR code on their next login.')">
                                                        <input type="hidden" name="action" value="reset_2fa">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                        <input type="password" name="admin_password" class="form-control form-control-sm d-inline-block me-1" placeholder="Admin password" required style="width: 170px;">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Reset Authenticator App"><i class="bi bi-phone-vibrate"></i></button>
                                                    </form>
                                                <?php endif; ?>
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
                                                                <label class="form-check-label" for="edit2fa<?php echo $u['id']; ?>">Enable 2FA (Authenticator App)</label>
                                                            </div>
                                                            <div class="form-check mb-2">
                                                                <input class="form-check-input" type="checkbox" name="is_shared" id="editShared<?php echo $u['id']; ?>" <?php echo (!empty($u['is_shared'])) ? 'checked' : ''; ?> onchange="document.getElementById('editOwnerDiv<?php echo $u['id']; ?>').style.display = this.checked ? 'block' : 'none'">
                                                                <label class="form-check-label" for="editShared<?php echo $u['id']; ?>">Shared Account</label>
                                                            </div>
                                                            <div class="mb-3" id="editOwnerDiv<?php echo $u['id']; ?>" style="display: <?php echo (!empty($u['is_shared'])) ? 'block' : 'none'; ?>;">
                                                                <label class="form-label fw-bold">Account Owner</label>
                                                                <input type="text" name="account_owner" class="form-control" value="<?php echo htmlspecialchars($u['account_owner'] ?? ''); ?>" maxlength="100">
                                                            </div>
                                                            <hr>
                                                            <div class="mb-3">
                                                                <label class="form-label text-danger fw-bold">Reset Password (Optional)</label>
                                                                <div class="input-group">
                                                                    <input type="password" name="password" id="resetPass<?php echo $u['id']; ?>" class="form-control" placeholder="New Password (Min 15 chars)" minlength="15" maxlength="128" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{15,}" title="Must be at least 15 characters, contain Uppercase, Lowercase, Number, and Symbol." oninput="updateStrength(this.value, 'strengthBar<?php echo $u['id']; ?>')">
                                                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('resetPass<?php echo $u['id']; ?>')"><i class="bi bi-eye"></i></button>
                                                                </div>
                                                                <div class="progress mt-1" style="height: 5px;">
                                                                    <div id="strengthBar<?php echo $u['id']; ?>" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                                                                </div>
                                                                <div class="mt-2 ps-1 small pass-rules">
                                                                    <div class="rule-len text-muted mb-1"><i class="bi bi-circle"></i> At least 15 characters</div>
                                                                    <div class="rule-let text-muted mb-1"><i class="bi bi-circle"></i> Contains a letter</div>
                                                                    <div class="rule-num text-muted mb-1"><i class="bi bi-circle"></i> Contains a number (0-9)</div>
                                                                    <div class="rule-sym text-muted mb-1"><i class="bi bi-circle"></i> Contains a symbol (!@#$)</div>
                                                                </div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label text-danger fw-bold">Confirm New Password</label>
                                                                <div class="input-group">
                                                                    <input type="password" name="confirm_password" id="resetConfPass<?php echo $u['id']; ?>" class="form-control" placeholder="Repeat new password" minlength="15" maxlength="128">
                                                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('resetConfPass<?php echo $u['id']; ?>')"><i class="bi bi-eye"></i></button>
                                                                </div>
                                                                <div class="match-msg small mt-1 fw-bold text-danger" style="display:none;">
                                                                    <i class="bi bi-x-circle"></i> Passwords do not match
                                                                </div>
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
        <script src="dark_mode.js"></script>
        <script>
            // ==========================================
            // [SECURITY] AUTO-LOGOUT (Client-Side)
            // ==========================================
            const INACTIVITY_LIMIT = <?php echo $clientTimeout * 1000; ?>; // Dynamic value in milliseconds
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
            <?php endif; ?>

            function confirmRestore(e) {
                e.preventDefault();
                const form = e.target;
                Swal.fire({
                    title: '⚠️ CRITICAL WARNING',
                    text: "This will OVERWRITE your current database. This cannot be undone. Are you sure?",
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    confirmButtonText: 'YES, OVERWRITE DATABASE'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Restoring Database...',
                            html: 'Please wait. The system is importing the data.<br><br><span class="text-danger fw-bold small">Note: Massive databases take time. If your browser shows a "Timeout" error after 5 minutes, DO NOT PANIC. The server will safely continue the restore in the background!</span>',
                            allowOutsideClick: false,
                            showConfirmButton: false,
                            allowEscapeKey: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        form.submit();
                    }
                });
            }

            function confirmServerRestore(e, msg) {
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
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Restoring Database...',
                            html: 'Please wait. The system is stitching and importing the data.<br><br><span class="text-danger fw-bold small">Note: Massive databases take time. If your browser shows a "Timeout" error after 5 minutes, DO NOT PANIC. The server will safely continue the restore in the background!</span>',
                            allowOutsideClick: false,
                            showConfirmButton: false,
                            allowEscapeKey: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        form.submit();
                    }
                });
            }

            function viewBackupDetails(baseName, displayName) {
                Swal.fire({
                    title: 'Analyzing Backup...',
                    text: 'Scanning ' + displayName + ', please wait.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('manager_user.php?action=view_backup&base_name=' + encodeURIComponent(baseName))
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire({
                                title: 'Backup Contents',
                                html: `<p class="text-muted small mb-3">${displayName} (${data.parts} part${data.parts > 1 ? 's' : ''})</p>` + data.html,
                                icon: 'info',
                                confirmButtonText: 'Close'
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to read backup.', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Network error occurred while reading the backup.', 'error');
                    });
            }

            function showBackupLoader(form) {
                const isServer = form.action.includes('mode=server');
                Swal.fire({
                    title: isServer ? 'Saving to Server...' : 'Generating Backup...',
                    html: `
                    <p class="text-muted small mb-3">Scanning files and compressing data. Please wait...</p>
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 100%"></div>
                    </div>
                    <span class="text-danger fw-bold small">This may take a few minutes. Do not close this window!</span>
                `,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false
                });

                const csrf = form.querySelector('[name="csrf_token"]').value;
                const checkCookie = setInterval(() => {
                    if (document.cookie.includes('downloadToken=' + csrf)) {
                        clearInterval(checkCookie);
                        Swal.close();
                        document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    }
                }, 1000);
            }

            function viewSchema() {
                Swal.fire({
                    title: 'Loading Schema...',
                    text: 'Fetching database structure',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('manager_user.php?action=view_schema')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            Swal.fire({
                                title: '<i class="bi bi-database"></i> Database Schema',
                                html: `<div style="max-height: 60vh; overflow-y: auto;">${data.html}</div>`,
                                width: '800px',
                                showConfirmButton: true,
                                confirmButtonText: 'Close',
                                confirmButtonColor: '#6c757d'
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to load schema.', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error', 'Network error occurred while fetching schema.', 'error');
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
            }

            const guideText = `RESTORATION GUIDE\n\nOption 1: Database Restore (Automatic)\nUse this to roll back data changes (e.g. accidental deletion).\n1. Locate a backup in the Available Auto-Backups list.\n2. Click the Restore This button.\n3. Enter your Admin Password to confirm.\n\nOption 2: Full System Recovery (Manual & Split ZIPs)\nUse this if the server crashed, you moved to a new PC, or you have a multi-part backup.\n1. Database: Under "2. System Restore", click the "Choose Files" button.\n2. Upload: Browse to your backup file. If your backup is split into multiple parts (e.g., Part1.zip, Part2.zip), highlight and select ALL of them at the exact same time.\n3. Confirm: Type in your Admin Password and click "Restore Database". The server will automatically organize the parts, silently unpack the SQL inside them, and reconstruct your entire database!\n4. Documents (Vault):\n   Note: The ZIP files above only restore the database records.\n   - If your backup included Vault Files, open the ZIP file manually on your computer.\n   - Extract the 'vault' folder from the ZIP.\n   - Paste it into your server's directory: C:\\xampp\\htdocs\\hr 201\\vault\\\n5. Encryption Key: Ensure config/config.php is restored if lost, as it contains your secure Vault Key.`;

            function downloadRestorationGuide() {
                const blob = new Blob([guideText], {
                    type: "text/plain;charset=utf-8"
                });
                const url = URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = "HR_System_Restoration_Guide.txt";
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Guide downloaded successfully!',
                    showConfirmButton: false,
                    timer: 2000
                });
            }

            function copyRestorationGuide() {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(guideText).then(() => {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Copied to clipboard!',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    });
                } else {
                    let textArea = document.createElement("textarea");
                    textArea.value = guideText;
                    textArea.style.position = "fixed";
                    textArea.style.left = "-999999px";
                    textArea.style.top = "-999999px";
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Copied to clipboard!',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    } catch (err) {
                        Swal.fire('Error', 'Failed to copy to clipboard. Please copy manually.', 'error');
                    }
                    textArea.remove();
                }
            }

            // [NEW] Global Listener for Admin Password Modals
            document.addEventListener('input', function(e) {
                if (e.target.matches('input[type="password"][name="password"], input[type="password"][name="confirm_password"]')) {
                    const form = e.target.closest('form');
                    if (!form) return;

                    const pass = form.querySelector('input[name="password"]');
                    const conf = form.querySelector('input[name="confirm_password"]');
                    const matchMsg = form.querySelector('.match-msg');
                    const submitBtn = form.querySelector('button[type="submit"]');

                    let allValid = true;
                    const wrapper = pass ? pass.closest('.mb-3') : null;

                    if (wrapper && pass) {
                        const ruleLen = wrapper.querySelector('.rule-len');
                        const ruleLet = wrapper.querySelector('.rule-let');
                        const ruleNum = wrapper.querySelector('.rule-num');
                        const ruleSym = wrapper.querySelector('.rule-sym');

                        if (ruleLen && ruleLet && ruleNum && ruleSym) {
                            const val = pass.value;
                            const checkRule = (el, regex) => {
                                const icon = el.querySelector('i');
                                if (val.length === 0) {
                                    el.classList.remove('text-success', 'fw-bold');
                                    el.classList.add('text-muted');
                                    icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                                    return false;
                                }
                                if (regex.test(val)) {
                                    el.classList.add('text-success', 'fw-bold');
                                    el.classList.remove('text-muted');
                                    icon.classList.replace('bi-circle', 'bi-check-circle-fill');
                                    return true;
                                } else {
                                    el.classList.remove('text-success', 'fw-bold');
                                    el.classList.add('text-muted');
                                    icon.classList.replace('bi-check-circle-fill', 'bi-circle');
                                    return false;
                                }
                            };

                            const vLen = checkRule(ruleLen, /^.{15,128}$/);
                            const vLet = checkRule(ruleLet, /[a-zA-Z]/);
                            const vNum = checkRule(ruleNum, /[0-9]/);
                            const vSym = checkRule(ruleSym, /[\W_]/);

                            allValid = (vLen && vLet && vNum && vSym);
                            if (!pass.hasAttribute('required') && val.length === 0) allValid = true;
                        }
                    }

                    let matchValid = true;
                    if (pass && conf && matchMsg) {
                        const match = (pass.value === conf.value);
                        if (conf.value && !match) {
                            matchMsg.style.display = 'block';
                            matchMsg.className = 'match-msg small mt-1 fw-bold text-danger';
                            matchMsg.innerHTML = '<i class="bi bi-x-circle"></i> Passwords do not match';
                            matchValid = false;
                        } else if (conf.value && match) {
                            matchMsg.style.display = 'block';
                            matchMsg.className = 'match-msg small mt-1 fw-bold text-success';
                            matchMsg.innerHTML = '<i class="bi bi-check-circle"></i> Passwords match';
                        } else {
                            matchMsg.style.display = 'none';
                            if (pass.hasAttribute('required')) matchValid = false;
                        }
                        if (!pass.hasAttribute('required') && pass.value.length === 0 && conf.value.length === 0) {
                            matchValid = true;
                            matchMsg.style.display = 'none';
                        }
                    }

                    if (submitBtn) {
                        submitBtn.disabled = !(allValid && matchValid);
                    }
                }
            });
        </script>
        <script src="main.js"></script>
        </body>

        </html>