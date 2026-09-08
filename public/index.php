<?php
// ======================================================
// TESP HR 201 System - Dashboard & Notification Center
// ======================================================

// ---------- 1) SYSTEM IMPORTS, SECURITY, SESSION, AND DATA FETCHING FOR HEADER ----------
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';
require_once '../src/helpers.php'; // [FIX] Include global helpers for h() and other functions
// [FIX] Locate the company logo and convert to Base64 for reliable display and printing
$logo_paths = [
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $mime = pathinfo($p, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg';
        $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
}
if (empty($logo_src)) {
    $logo_src = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><text y="30" font-size="14" fill="#333">TESP</text></svg>');
}

// [FIX] Defensive initialization for variables from options.php (moved before require)
$agencies = [];
$deptMap = [];
$system_roles = [];
require_once 'options.php'; // [NEW] Load dynamic options
session_start();

// [NEW] Fetch Session Timeout settings (required before enforcing timeout)
$serverTimeout = 1800; // Default 30 mins
$clientTimeout = 900;  // Default 15 mins
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('session_timeout_server', 'session_timeout_client')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['setting_key'] === 'session_timeout_server' && (int)$row['setting_value'] > 0) {
            $serverTimeout = (int)$row['setting_value'];
        }
        if ($row['setting_key'] === 'session_timeout_client' && (int)$row['setting_value'] > 0) {
            $clientTimeout = (int)$row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Settings table might not exist, use defaults
}

if (function_exists('checkSessionTimeout')) {
    checkSessionTimeout($pdo, $serverTimeout); // [SECURITY] Enforce Timeout
}

// Redirect guests to login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Normalize role to uppercase (handles 'hr', 'HR', etc.)
$userRole = strtoupper(trim($_SESSION['role'] ?? 'STAFF'));

$security = new Security($pdo);
$logger   = new Logger($pdo);

// [SECURITY] Validate role integrity
$validRoles = ['ADMIN', 'MANAGER', 'HR', 'STAFF'];
if (!in_array($userRole, $validRoles)) {
    $logger->log($_SESSION['user_id'] ?? 0, 'INVALID_SESSION', "Invalid role detected: $userRole");
    session_destroy();
    header('Location: login.php?error=' . urlencode('Session invalid. Please log in again.'));
    exit;
}

// [NEW] Fetch Auto-Refresh Interval
$refreshInterval = 60; // Default
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_refresh_interval'");
    $val = $stmt->fetchColumn();
    if ($val !== false) {
        $refreshInterval = max(10, (int)$val);
    }
} catch (Exception $e) {
}

// ---------- 2) AUTOMATED BACKUP SYSTEM ----------
// Scheduled backups are handled exclusively by cron_backup.php. Keeping the
// dashboard request free of backup work prevents page-load timeouts and duplicate runs.
if ($userRole === 'ADMIN') {
    // Fetch Backup Settings
    $bkSettings = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'backup_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $bkSettings[$row['setting_key']] = $row['setting_value'];
    } catch (Exception $e) {
    }

    $scheduleDay = $bkSettings['backup_day'] ?? 'Fri';
    $scheduleTime = $bkSettings['backup_time'] ?? '00:00';
    $customPath  = $bkSettings['backup_path'] ?? '';
    $zipPass     = $bkSettings['backup_password'] ?? '';
    $incVault    = ($bkSettings['backup_include_vault'] ?? '0') === '1';
    $alertEmail  = $bkSettings['backup_alert_email'] ?? '';

    // Determine Target Path
    $primaryBackupPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';

    // Ensure primary path exists
    if (!is_dir($primaryBackupPath)) {
        @mkdir($primaryBackupPath, 0755, true);
    }

    // Check Schedule & Existence
    $todayStr = date('Y-m-d'); // e.g. 2023-10-27
    $todayDay = date('D');     // e.g. Fri
    $currentTime = date('H:i');

    // [FIX] Robust Schedule Check:
    // 1. Is it the scheduled day?
    // 2. Is it past the scheduled time?
    // 3. Has a backup already been performed TODAY?

    $isScheduledDay = ($todayDay === $scheduleDay);
    $isPastTime = ($currentTime >= $scheduleTime);

    // Look for any backup matching today's date
    $backupsToday = glob(rtrim($primaryBackupPath, '/\\') . DIRECTORY_SEPARATOR . 'AutoBackup_' . $todayStr . '*.*');

    if ($isScheduledDay && $isPastTime && empty($backupsToday)) {
        // START BACKUP PROCESS
        ini_set('memory_limit', '512M');
        set_time_limit(0); // [FIX] Remove time limit to prevent "Network Error" on large backups
        ignore_user_abort(true); // [FIX] Ensure backup finishes even if the page load is cancelled

        $baseFilename = 'AutoBackup_' . $todayStr . '_' . date('His');
        $sqlFilename  = $baseFilename . '.sql';

        $tables = [];
        $query  = $pdo->query('SHOW TABLES');
        while ($row = $query->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $content  = "-- AUTOMATED FRIDAY BACKUP\n";
        $content .= "-- Date: " . date("Y-m-d H:i:s") . "\n\n";
        $content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row  = $stmt->fetch(PDO::FETCH_NUM);
            $content .= "DROP TABLE IF EXISTS `$table`;\n" . $row[1] . ";\n\n";

            $stmt = $pdo->query("SELECT * FROM `$table`");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($r as $v) {
                    if ($v === null) {
                        $values[] = "NULL";
                        continue;
                    }
                    // Use PDO quote instead of addslashes for safe escaping
                    $values[] = $pdo->quote((string)$v);
                }
                $content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $content .= "\n";
        }
        $content .= "\nSET FOREIGN_KEY_CHECKS=1;";

        // ZIP CREATION
        $zip = new ZipArchive();
        $zipFile = rtrim($primaryBackupPath, '/\\') . '/' . $baseFilename . '.zip';

        if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
            // Add SQL
            $zip->addFromString($sqlFilename, $content);
            if ($zipPass) $zip->setEncryptionName($sqlFilename, ZipArchive::EM_AES_256, $zipPass);

            // Add Vault (if enabled)
            if ($incVault) {
                // [LOGICAL FIX] Include config.php to preserve VAULT_KEY
                $configPath = realpath(__DIR__ . '/../config/config.php');
                if ($configPath && file_exists($configPath)) {
                    $zip->addFile($configPath, 'config/config.php');
                    if ($zipPass) $zip->setEncryptionName('config/config.php', ZipArchive::EM_AES_256, $zipPass);
                }

                // [PHP SMART SYNC] Mirror Vault instead of Zipping
                $vaultPath = realpath(__DIR__ . '/../vault');
                $mirrorPath = rtrim($primaryBackupPath, '/\\') . DIRECTORY_SEPARATOR . 'vault_mirror';
                if (!is_dir($mirrorPath)) @mkdir($mirrorPath, 0755, true);

                if ($vaultPath && is_dir($vaultPath)) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vaultPath), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $src = $file->getRealPath();
                            $dest = $mirrorPath . DIRECTORY_SEPARATOR . $file->getFilename();
                            // Delta Sync: Only copy if missing or modified
                            if (!file_exists($dest) || filemtime($src) > filemtime($dest) || filesize($src) !== filesize($dest)) {
                                @copy($src, $dest);
                            }
                        }
                    }
                }
            }

            $zip->close(); // Correct placement: inside if ($zip->open(...))

            if (file_exists($zipFile)) { // Correct placement: inside if ($zip->open(...))
                $logger->log($_SESSION['user_id'], 'AUTO_BACKUP', "Backup created: " . basename($zipFile));
                $_SESSION['backup_msg'] = "✅ Automated Backup Completed (" . basename($zipFile) . ")";

                // [NEW] Add to Notification Center (Bell Icon)
                $admins = $pdo->query("SELECT id FROM users WHERE role IN ('ADMIN', 'MANAGER')")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($admins)) {
                    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'System Backup', ?, 'success')");
                    $notifMsg = "Automated backup created successfully: " . basename($zipFile);
                    foreach ($admins as $adminId) {
                        $notifStmt->execute([$adminId, $notifMsg]);
                    }
                    // Clear notification cache for live updates
                    $pdo->exec("DELETE FROM rate_limits WHERE ip_address = 'SYSTEM_NOTIF_CACHE'");
                }
            } else { // Else for if (file_exists($zipFile))
                $logger->log($_SESSION['user_id'], 'AUTO_BACKUP_FAIL', "Backup failed: ZIP file not created.");
            }
        } else { // Else for if ($zip->open(...))
            if ($alertEmail) {
                mail($alertEmail, "Q⚠️ HR System Backup Failed", "Could not open/create ZIP archive.\n\nTime: " . date('Y-m-d H:i:s'));
            }
        }
    }
}

// ---------- 3) HELPERS ----------
if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get and sanitize GET param with a max length (prevents oversized values)
 */
function getQueryParamSafe(string $key, int $maxLen = 100, string $default = ''): string // [FIX] Added type hint for $default
{
    $val = isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    if (mb_strlen($val) > $maxLen) {
        $val = mb_substr($val, 0, $maxLen);
    }
    return $val;
}

/**
 * Keep existing GET params while overriding given keys
 */
function keepQuery(array $override = []): string // [FIX] Added type hint for $override
{
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ('?' . $qs) : '';
}

// ---------- 4) INPUTS / FILTERS / SORT / PAGINATION ----------
$filter_status = getQueryParamSafe('status', 24, '');
$filter_type   = getQueryParamSafe('type',   40, ''); // can match employment_type or agency_name
$filter_dept   = getQueryParamSafe('dept',   32, '');
$filter_section = getQueryParamSafe('section', 50, '');
$search_query  = Validator::sanitizeSearch($_GET['search'] ?? '');
$filter_doc_cat = getQueryParamSafe('doc_cat', 50, '');
$sort_option   = getQueryParamSafe('sort',   24, 'newest');

// [NEW] Recent Searches Logic (Cookie-based)
$recentSearches = isset($_COOKIE['recent_searches']) ? json_decode($_COOKIE['recent_searches'], true) : [];
if (!is_array($recentSearches)) $recentSearches = [];

if ($search_query !== '') {
    // Remove if exists (to move to top)
    $key = array_search($search_query, $recentSearches);
    if ($key !== false) {
        unset($recentSearches[$key]);
    }
    // Add to front
    array_unshift($recentSearches, $search_query);
    // Limit to 5
    $recentSearches = array_slice($recentSearches, 0, 5);
    // Save cookie (30 days)
    setcookie('recent_searches', json_encode($recentSearches), [
        'expires'  => time() + (86400 * 30),
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false, // JS reads this cookie for suggestions
        'samesite' => 'Lax',
    ]);
}
// Sort whitelist (prevents SQL injection)
// [FIX] Handle empty hire_date by pushing them to the end (CASE WHEN)
$sortWhitelist = [
    'newest'   => 'CASE WHEN hire_date IS NULL OR hire_date = "" THEN 1 ELSE 0 END, hire_date DESC',
    'oldest'   => 'CASE WHEN hire_date IS NULL OR hire_date = "" THEN 1 ELSE 0 END, hire_date ASC',
    'alpha_az' => 'last_name ASC, first_name ASC',
    'alpha_za' => 'last_name DESC, first_name DESC'
];
$orderBy = $sortWhitelist[$sort_option] ?? $sortWhitelist['newest'];

// Pagination inputs, bounded to reasonable values
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 24);
$perPage = max(6, min(48, $perPage));
$offset  = ($page - 1) * $perPage;

// ---------- 5) NOTIFICATIONS (DB + Expiry alerts) ----------
// [FIX] Check if 'deleted_at' column exists for soft-delete feature
$hasDeletedAtColumn = false;
try {
    $checkCols = $pdo->query("SHOW COLUMNS FROM `documents` LIKE 'deleted_at'");
    if ($checkCols && $checkCols->rowCount() > 0) {
        $hasDeletedAtColumn = true;
    }
} catch (PDOException $e) {
    // Table might not exist, or other error. Safely assume no column.
}

// [FIX] Check if 'deleted_at' column exists for employees
$hasEmpDeletedAt = false;
try {
    $checkCols = $pdo->query("SHOW COLUMNS FROM `employees` LIKE 'deleted_at'");
    if ($checkCols && $checkCols->rowCount() > 0) {
        $hasEmpDeletedAt = true;
    }
} catch (PDOException $e) {
}

// Handle "Clear Messages" (only clears DB notifications for this user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_notifs'])) {
    // CSRF validation
    $formToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $formToken)) {
        // silently ignore if token mismatch (or handle as you prefer)
    } else {
        $delStmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
        $delStmt->execute([$_SESSION['user_id']]);
        header('Location: index.php');
        exit;
    }
}

// [NEW] Handle Dev File Cleanup (Admin Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_dev_files']) && $userRole === 'ADMIN') {
    // CSRF validation
    $formToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $formToken)) {
        die("Invalid CSRF token");
    }

    $filesToDelete = [
        'utils/install.php',
        'auth_login.php',
        'create_admin.php',
        'test_vault.php',
        'debug_whitespace.php',
        'test_system.php',
        'test_email.php',
        'test_email_alert.php',
        'test_zip_password.php',
        'debug_vault.php',
        'debug_upload.php',
        'ValidatorTest.php',
        'download_assets.php',
        'stress_test_backup.php',
        'stress_test_vault.php',
        'migrate_favicon.php',
        'system_diagnostics.php',
        'qa_test.php',
        'process_approval.php',
        'process_edit_employee.php',
        'process_add_employee.php',
        'generate_test_data.php'
    ];

    $deletedCount = 0;
    foreach ($filesToDelete as $f) {
        if (file_exists($f)) {
            @unlink($f);
            $deletedCount++;
        }
    }

    $logger->log($_SESSION['user_id'], 'CLEANUP_DEV', "Deleted $deletedCount development files.");
    header("Location: index.php?msg=" . urlencode("✅ Cleanup Complete: $deletedCount files removed."));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_move_dept') {
    if (!in_array(strtoupper($_SESSION['role'] ?? ''), ['ADMIN', 'MANAGER', 'HR'], true)) {
        if (isset($logger)) {
            $logger->log($_SESSION['user_id'] ?? 0, 'AUTH_FAIL', 'Unauthorized bulk_move_dept attempt.');
        }
        http_response_code(403);
        header('Location: index.php?error=' . urlencode('Access denied.'));
        exit;
    }

    $formToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $formToken)) {
        die('Invalid CSRF token');
    }

    $selectedIds = $_POST['selected_ids'] ?? [];
    if (!is_array($selectedIds)) {
        $selectedIds = [];
    }
    $selectedIds = array_values(array_filter(array_map('intval', $selectedIds), function ($id) {
        return $id > 0;
    }));

    $targetDept = trim($_POST['target_dept'] ?? '');
    $targetSection = trim($_POST['target_section'] ?? '');
    $targetStatus = trim($_POST['target_status'] ?? '');
    $targetRole = trim($_POST['target_role'] ?? '');
    $targetAgency = trim($_POST['target_agency'] ?? '');
    $redirectQuery = trim($_POST['redirect_query'] ?? '');

    $redirectUrl = 'index.php';
    if ($redirectQuery !== '') {
        $redirectUrl = 'index.php?' . ltrim($redirectQuery, '?&');
    }

    $separator = (strpos($redirectUrl, '?') === false) ? '?' : '&';
    if (empty($selectedIds)) {
        header('Location: ' . $redirectUrl . $separator . 'error=' . urlencode('No employees were selected.'));
        exit;
    }

    $updates = [];
    $params = [];
    if ($targetDept !== '') {
        $updates[] = 'dept = ?';
        $params[] = $targetDept;
    }
    if ($targetSection !== '') {
        $updates[] = 'section = ?';
        $params[] = $targetSection;
    }
    if ($targetStatus !== '') {
        $updates[] = 'status = ?';
        $params[] = $targetStatus;
    }
    if ($targetRole !== '') {
        $updates[] = 'system_role = ?';
        $params[] = $targetRole;
    }
    if ($targetAgency !== '') {
        $updates[] = 'agency_name = ?';
        $params[] = $targetAgency;
        $updates[] = 'employment_type = ?';
        $params[] = (stripos($targetAgency, 'TESP') !== false) ? 'TESP Direct' : 'Agency';
    }

    if (!empty($updates)) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $sql = 'UPDATE employees SET ' . implode(', ', $updates) . ' WHERE id IN (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $selectedIds));
        $count = $stmt->rowCount();
        $logger->log($_SESSION['user_id'], 'BULK_EMP_UPDATE', "Updated $count employee(s) using bulk action.");
        header('Location: ' . $redirectUrl . $separator . 'msg=' . urlencode("Bulk update applied to $count employee(s)."));
        exit;
    }

    header('Location: ' . $redirectUrl . $separator . 'msg=' . urlencode('No changes were applied. Select at least one update option.'));
    exit;
}

// (Source 1) User-specific DB notifications
$notifStmt = $pdo->prepare("
    SELECT id, title, message, type, created_at, 'db_msg' as source, NULL as link_id
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$notifStmt->execute([$_SESSION['user_id']]);
$db_notifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$doc_alerts = [];
try {
    // (Source 2) Expiring docs within next 30 days (unresolved)
    $alertDate = date('Y-m-d', strtotime('+30 days'));
    $docQuery  = "SELECT d.id, d.original_name, d.expiry_date, e.emp_id AS real_emp_id FROM documents d JOIN employees e ON d.employee_id = e.emp_id WHERE d.is_resolved = 0 AND d.expiry_date IS NOT NULL AND d.expiry_date <= ?";
    if ($hasDeletedAtColumn) $docQuery .= " AND d.deleted_at IS NULL";
    if (!in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)) $docQuery .= " AND d.uploaded_by = " . (int)$_SESSION['user_id'];

    $notifyStmt = $pdo->prepare($docQuery);
    $notifyStmt->execute([$alertDate]);
    $raw_alerts = $notifyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($raw_alerts as $d) {
        $daysLeft = (int)floor((strtotime($d['expiry_date']) - time()) / 86400);
        $status   = ($daysLeft < 0) ? 'EXPIRED' : ($daysLeft . ' days left');
        $doc_alerts[] = ['id' => 'doc_' . $d['id'], 'title' => "Document Expiring: {$status}", 'message' => 'File: ' . $d['original_name'], 'type' => 'warning', 'created_at' => date('Y-m-d H:i:s'), 'source' => 'expiry', 'link_id' => $d['id'], 'doc_name' => $d['original_name'], 'emp_search' => $d['real_emp_id']];
    }
} catch (Throwable $e) {
    error_log("Dashboard notification error: " . $e->getMessage());
}

// (Source 3) Pending Requests (For ADMIN/HR only)
if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)) {
    $pendCount = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'PENDING'")->fetchColumn();
    if ($pendCount > 0) {
        $doc_alerts[] = [
            'id'         => 'pending_reqs',
            'title'      => "Approval Center",
            'message'    => "$pendCount request(s) waiting for review.",
            'type'       => 'info',
            'created_at' => date('Y-m-d H:i:s'), // Show at top
            'source'     => 'request',
            'link'       => 'admin_approval.php'
        ];
    }
}

// Merge & sort notifications (newest first)
$all_notifications = array_merge($db_notifs, $doc_alerts);
usort($all_notifications, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$msgCount = count($db_notifs);
$actionCount = count($doc_alerts);
$notifCount = $msgCount + $actionCount;

// ---------- 5.5) FETCH DYNAMIC REQUIREMENTS FOR ANALYTICS AND FILTERS ----------
$REQUIRED_DOCS = [];
try {
    $reqStmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY id ASC");
    $reqList = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    // Failsafe fallback if the database table is suddenly missing
    $REQUIRED_DOCS = [
        '201 Files' => ['201', 'PDS', 'Data Sheet', 'Resume'],
        'Valid ID'  => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
        'Contract'  => ['Contract', 'Appointment', 'Offer'],
        'Medical'   => ['Medical', 'Fit to Work', 'Exam'],
        'Clearance' => ['NBI', 'Police', 'Barangay']
    ];
}

// ---------- 6) BUILD FILTER SQL ----------
$where  = ['1=1'];
$params = [];

// [FIX] Exclude soft-deleted employees
if ($hasEmpDeletedAt) {
    $where[] = 'deleted_at IS NULL';
}

// Status filter (exact match)
if ($filter_status !== '') {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}

// Type filter: match either employment_type or agency_name (exact to value)
if ($filter_type !== '') {
    $where[]  = '(employment_type = ? OR agency_name = ?)';
    $params[] = $filter_type;
    $params[] = $filter_type;
}

// [LOGICAL FIX] Department filter (partial match for multi-dept employees)
if ($filter_dept !== '') {
    $where[]  = 'dept LIKE ?';
    $params[] = "%{$filter_dept}%";
}

// Section filter (Partial match for multi-section support)
if ($filter_section !== '') {
    $where[]  = 'section LIKE ?';
    $params[] = "%$filter_section%";
}

// Document Category filter (from Chart click)
if ($filter_doc_cat !== '') {
    if ($filter_doc_cat === 'Uncategorized' || $filter_doc_cat === 'Documents for Employee') {
        // [FIX] Standardize Uncategorized logic to find documents matching NO requirement rules
        $matchOrs = [];
        foreach ($REQUIRED_DOCS as $name => $keys) {
            $matchOrs[] = "category = " . $pdo->quote($name);
            foreach ($keys as $k) {
                if ($k === '') continue;
                $qK = $pdo->quote("%$k%");
                $matchOrs[] = "original_name LIKE $qK";
                $matchOrs[] = "category LIKE $qK";
            }
        }
        $categorizedSql = !empty($matchOrs) ? implode(' OR ', $matchOrs) : "1=0";
        $docTableFilter = $hasDeletedAtColumn ? "deleted_at IS NULL" : "1=1";

        $where[] = "emp_id IN (SELECT employee_id FROM documents WHERE $docTableFilter AND NOT ($categorizedSql))";
    } else {
        // [FIX] Standardize category filtering to match Dashboard and Tracker logic
        $subConditions = ["category = ?", "category LIKE ?"];
        $subParams = [$filter_doc_cat, "%$filter_doc_cat%"];
        $keywords = $REQUIRED_DOCS[$filter_doc_cat] ?? [];
        foreach ($keywords as $k) { // [FIX] Trim keyword to avoid issues with whitespace
            if (empty(trim($k))) continue;
            $subConditions[] = "original_name LIKE ?";
            $subConditions[] = "category LIKE ?";
            $subParams[] = "%$k%";
            $subParams[] = "%$k%";
        }
        $docTableFilter = $hasDeletedAtColumn ? "deleted_at IS NULL AND " : "";
        $where[] = "EXISTS (SELECT 1 FROM documents WHERE documents.employee_id = employees.emp_id AND $docTableFilter (" . implode(' OR ', $subConditions) . "))";
        $params = array_merge($params, $subParams);
    }
}

// Search (Any Order: "John Doe", "Doe John", "2023 John")
if ($search_query !== '') {
    $terms = preg_split('/[\s,]+/', $search_query, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
        $t = "%{$term}%";
        array_push($params, $t, $t, $t);
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// ---------- 7) TOTAL COUNT ----------
$countSql  = "SELECT COUNT(*) FROM employees {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$countResult = $countStmt->fetchColumn();
$totalRows  = ($countResult !== false && $countResult !== null) ? (int)$countResult : 0;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// ---------- 8) FETCH EMPLOYEES (FIXED: Pure Positional Parameters) ----------
$empSql = "SELECT * FROM employees {$whereSql} ORDER BY {$orderBy} LIMIT ? OFFSET ?";
$empStmt = $pdo->prepare($empSql);

// 1. Bind the WHERE params dynamically
$paramIndex = 1;
foreach ($params as $val) {
    $empStmt->bindValue($paramIndex++, $val);
}

// 2. Bind LIMIT and OFFSET as Integers (Strictly required for LIMIT)
$empStmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$empStmt->bindValue($paramIndex++, $offset,  PDO::PARAM_INT);

$empStmt->execute();
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// [OPTIMIZATION] Spreadsheet data has been removed from initial page load.
// This data should be loaded asynchronously via a dedicated API to prevent memory issues.
$spreadsheetJson     = '[]';
$existingOptColsJson = '[]';

// ---------- 8.7) FETCH RECENT ACTIVITY (Recent Uploads) ----------
$recentActivity = [];
try {
    $recentActSql = "SELECT d.original_name, e.first_name, e.last_name 
                     FROM documents d 
                     JOIN employees e ON d.employee_id = e.emp_id ";
    $actWhere = ["1=1"];
    if ($hasDeletedAtColumn) $actWhere[] = "d.deleted_at IS NULL";
    if ($hasEmpDeletedAt) $actWhere[] = "e.deleted_at IS NULL";

    $recentActSql .= " WHERE " . implode(" AND ", $actWhere);
    $recentActSql .= " ORDER BY d.uploaded_at DESC LIMIT 5";
    $stmt = $pdo->query($recentActSql);
    $recentActivity = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    error_log("Recent Activity Fetch Error: " . $e->getMessage());
}
// [NEW] Fuzzy Search Logic (Did you mean?)
$didYouMean = null;
$didYouMeanLink = "#";
if (empty($employees) && !empty($search_query)) {
    $closest = SearchHelper::findBestMatch($pdo, $search_query);
    if ($closest) {
        $didYouMean = $closest;
        $didYouMeanLink = "index.php?search=" . urlencode($closest);
    }
}

// ---------- 9) BATCH FETCH DOCUMENTS for visible employees ----------
$filesByEmp = [];
if (!empty($employees)) {
    $empIds = array_map(fn($e) => $e['emp_id'], $employees);
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $docsSql = "SELECT * FROM documents WHERE employee_id IN ($placeholders)";
    if ($hasDeletedAtColumn) {
        $docsSql .= " AND deleted_at IS NULL";
    }
    $docsSql .= " ORDER BY uploaded_at DESC";
    $docsStmt = $pdo->prepare($docsSql);
    $docsStmt->execute($empIds);
    $docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($docs as $d) {
        $filesByEmp[$d['employee_id']][] = $d;
    }
}

// [NEW] Fetch Dynamic Categories for Filters/Export
$dynamicCats = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT name FROM document_requirements ORDER BY name ASC");
    $dynamicCats = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $dynamicCats = array_filter($dynamicCats, function ($cat) {
        return strcasecmp(trim($cat), 'Others') !== 0;
    });
} catch (Exception $e) {
    $dynamicCats = ['201 Files', 'Contract', 'Government IDs', 'Medical', 'Memo / DA', 'Evaluation', 'Certificate', 'Training Record'];
}

// ---------- 10) CHART DATA (simple counts by category) ----------
// Fetch all active documents for active employees
$stats = array_fill_keys(array_keys($REQUIRED_DOCS), 0); // Initialize all categories with 0
$stats['Uncategorized'] = 0; // Ensure Uncategorized is always present

// [OPTIMIZATION] Build a single SQL query to categorize and count in the database
$caseSql = "SELECT CASE \n";
foreach ($REQUIRED_DOCS as $reqName => $keywords) {
    $conditions = [];
    $conditions[] = "d.category = " . $pdo->quote($reqName);
    foreach ($keywords as $k) {
        if (empty(trim($k))) continue;
        $conditions[] = "d.original_name LIKE " . $pdo->quote('%' . trim($k) . '%');
        $conditions[] = "d.category LIKE " . $pdo->quote('%' . trim($k) . '%');
    }
    $caseSql .= "    WHEN " . implode(" OR ", $conditions) . " THEN " . $pdo->quote($reqName) . "\n";
}
$caseSql .= "    ELSE 'Uncategorized' \nEND";

$docTableFilter = $hasDeletedAtColumn ? " AND d.deleted_at IS NULL" : "";
$empTableFilter = $hasEmpDeletedAt ? " AND e.deleted_at IS NULL" : "";

$statsSql = "
    SELECT ($caseSql) as doc_group, COUNT(*) as doc_count
    FROM documents d
    INNER JOIN employees e ON d.employee_id = e.emp_id
    WHERE 1=1 $empTableFilter $docTableFilter
    GROUP BY doc_group
";

$dbStats = $pdo->query($statsSql)->fetchAll(PDO::FETCH_KEY_PAIR);

// Merge DB results into our initialized array to ensure all categories are present
$stats = array_merge($stats, $dbStats);

$labels = json_encode(array_values(array_keys($stats)), JSON_UNESCAPED_UNICODE);
$data   = json_encode(array_values($stats),            JSON_UNESCAPED_UNICODE);

// ---------- 11) TARGETS FROM NOTIFICATION (for auto-open) ----------
$targetDocId = getQueryParamSafe('resolve_doc', 32, '');
$targetEmpId = getQueryParamSafe('search',      150, '');

// [NEW] Disk Usage Check for Warning
$vaultPathForDisk = realpath(__DIR__ . '/../vault') ?: __DIR__;
$diskTotal = @disk_total_space($vaultPathForDisk);
$diskFree  = @disk_free_space($vaultPathForDisk);
$diskPercent = ($diskTotal > 0) ? round((($diskTotal - $diskFree) / $diskTotal) * 100) : 0;

// [NEW] Vault Size Quota Check for Dashboard Alert
$vaultLimitGB = 1; // Default 1GB
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'vault_size_limit_gb'");
    $val = $stmt->fetchColumn();
    if ($val !== false) $vaultLimitGB = (float)$val;
} catch (Exception $e) {
}

$currentVaultBytes = 0;
$configEnv = require '../config/config.php';
$vaultPath = $configEnv['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
if ($vaultPath && is_dir($vaultPath)) {
    $iterator = new FileSystemIterator($vaultPath, FileSystemIterator::SKIP_DOTS);
    foreach ($iterator as $f) {
        if ($f->isFile()) $currentVaultBytes += $f->getSize();
    }
}
$currentVaultGB = round($currentVaultBytes / 1024 / 1024 / 1024, 2);
$vaultQuotaPercent = ($vaultLimitGB > 0) ? min(100, round(($currentVaultGB / $vaultLimitGB) * 100)) : 0;

$backupLastStatus = $bkSettings['backup_last_status'] ?? 'OK';
?>
<?php require 'header.php'; ?>

<div class="container">

    <!-- [SECURITY] Production Readiness & MHI Audit Checks -->
    <?php if ($userRole === 'ADMIN'):
        $riskFiles = [
            'utils/install.php' => 'Installation script (Risk of reset)',
            'auth_login.php' => 'Insecure login bypass (MHI Violation)',
            'create_admin.php' => 'Admin creation backdoor (Critical Risk)',
            'test_vault.php' => 'Vault test script (No Auth - Critical)',
            'debug_whitespace.php' => 'Debug script (No Auth - Critical)',
            'test_system.php' => 'System test script (Info Disclosure)',
            'test_email.php' => 'Email test script',
            'test_email_alert.php' => 'Email test script',
            'test_zip_password.php' => 'Password test script',
            'debug_vault.php' => 'Vault debug script',
            'debug_upload.php' => 'Upload debug script',
            'ValidatorTest.php' => 'Unit test script',
            'download_assets.php' => 'Asset downloader',
            'stress_test_backup.php' => 'Stress test script',
            'stress_test_vault.php' => 'Vault stress test script',
            'migrate_favicon.php' => 'Utility migration script',
            'system_diagnostics.php' => 'System diagnostics tool',
            'process_approval.php' => 'Legacy deprecated script',
            'process_edit_employee.php' => 'Legacy deprecated script',
            'process_add_employee.php' => 'Legacy deprecated script',
            'generate_test_data.php' => 'Test data generator (Pollutes DB)'
        ];

        $foundRisks = [];
        foreach ($riskFiles as $file => $desc) {
            if (file_exists($file)) {
                $foundRisks[] = "<strong>$file</strong>: $desc";
            }
        }
        if (!empty($foundRisks)):
    ?>
            <div class="alert alert-danger shadow-sm fw-bold mb-4 border-danger border-3 no-print">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-shield-exclamation fs-3 me-3"></i>
                        <div>
                            <h5 class="mb-0">Production Security Alert</h5>
                            <span class="small fw-normal">The following development files must be deleted before production use:</span>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <button type="submit" name="cleanup_dev_files" class="btn btn-danger btn-sm fw-bold" onclick="return confirm('Are you sure? This will permanently delete these files.');">
                            <i class="bi bi-trash-fill"></i> Delete All
                        </button>
                    </form>
                </div>
                <ul class="mb-0 small text-danger">
                    <?php foreach ($foundRisks as $risk) echo "<li>$risk</li>"; ?>
                </ul>
            </div>
    <?php endif;
    endif; ?>

    <!-- [SECURITY] Automated Backup Failure Alert -->
    <?php if ($userRole === 'ADMIN' && $backupLastStatus === 'FAILED'): ?>
        <div class="alert alert-danger shadow-sm fw-bold mb-4 border-danger border-3 no-print">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-octagon-fill fs-2 me-3"></i>
                <div>
                    <h5 class="mb-0 text-danger">CRITICAL WARNING: Automated Backup Failed</h5>
                    <span class="small fw-normal text-dark">The scheduled database backup failed last night or generated a 0-byte file. Please check server storage or <a href="settings.php" class="text-danger text-decoration-underline">run a manual backup</a> immediately.</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- [SECURITY] Password Expiry Warning (5 Days Notice) -->
    <?php
    $stmt = $pdo->prepare("SELECT password_changed_at, created_at, security_question FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if ($u && ($u['password_changed_at'] || $u['created_at'])) {
        $lastChange = new DateTime($u['password_changed_at'] ?? $u['created_at']);
        $today = new DateTime();
        $daysDiff = $today->diff($lastChange)->days;
        $daysRemaining = 45 - $daysDiff;

        if ($daysRemaining <= 5 && $daysRemaining >= 0):
    ?>
            <div class="alert alert-warning shadow-sm fw-bold d-flex align-items-center mb-4 no-print">
                <i class="bi bi-hourglass-split fs-4 me-3"></i>
                <div>
                    <strong>Action Required:</strong> Your password will expire in <?php echo $daysRemaining; ?> day(s).
                    <br><span class="small fw-normal">Please <a href="profile_settings.php" class="alert-link">change your password</a> now to avoid interruption.</span>
                </div>
            </div>
    <?php endif;
    } ?>

    <!-- [SECURITY] Missing Security Question Alert -->
    <?php if ($u && empty($u['security_question'])): ?>
        <div class="alert alert-danger shadow-sm fw-bold d-flex align-items-center mb-4 border-danger border-3 no-print">
            <i class="bi bi-patch-question-fill fs-3 me-3 text-danger"></i>
            <div>
                <h5 class="mb-0 text-danger">Security Vulnerability: Missing Recovery Data</h5>
                <span class="small fw-normal text-dark">You have not set up your Account Recovery Security Question. Please <a href="profile_settings.php" class="alert-link text-decoration-underline">configure it now</a> to ensure you don't lose access to your account.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- [SECURITY] Admin Password Reset Notification -->
    <?php
    $adminResetStmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE action = 'ADMIN_PASSWORD_RESET' AND details LIKE ? ORDER BY created_at DESC LIMIT 1");
    $adminResetStmt->execute(["%(ID: {$_SESSION['user_id']})%"]);
    $lastAdminReset = $adminResetStmt->fetchColumn();
    if ($lastAdminReset && (time() - strtotime($lastAdminReset) < 259200)): // Show for 3 days (72 hours)
    ?>
        <div class="alert alert-info shadow-sm fw-bold d-flex align-items-center mb-4 border-info border-3 no-print">
            <i class="bi bi-info-circle-fill fs-3 me-3 text-info"></i>
            <div>
                <h5 class="mb-0 text-info">Security Notice: Password Reset</h5>
                <span class="small fw-normal text-dark">Your password was recently reset by an Administrator on <strong><?php echo date('F j, Y, g:i a', strtotime($lastAdminReset)); ?></strong>. If you did not request this, please <a href="profile_settings.php" class="alert-link text-decoration-underline">change your password</a> immediately to secure your account.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- [ADMIN] System Health & Server Config (Professional View) -->
    <?php if ($userRole === 'ADMIN'): ?>
        <div class="card shadow-sm mb-4 border-info no-print">
            <div class="card-header bg-info text-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cpu-fill me-2"></i> System Health & Configuration</span>
                <div>
                    <a href="error_logs.php" class="btn btn-sm btn-light text-danger fw-bold me-2"><i class="bi bi-bug-fill"></i> View Error Logs</a>
                    <a href="db_status.php" class="btn btn-sm btn-light text-info fw-bold"><i class="bi bi-arrow-repeat"></i> Check DB Updates</a>
                </div>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3 border-end">
                        <small class="text-muted d-block text-uppercase"><i class="bi bi-code-slash"></i> PHP Version</small>
                        <span class="fw-bold"><?php echo phpversion(); ?></span>
                    </div>
                    <div class="col-md-3 border-end">
                        <small class="text-muted d-block text-uppercase"><i class="bi bi-cloud-arrow-up"></i> Max Upload</small>
                        <span class="fw-bold"><?php echo ini_get('upload_max_filesize'); ?></span>
                    </div>
                    <div class="col-md-3 border-end">
                        <small class="text-muted d-block text-uppercase"><i class="bi bi-file-earmark-arrow-up"></i> Max POST</small>
                        <span class="fw-bold"><?php echo ini_get('post_max_size'); ?></span>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block text-uppercase"><i class="bi bi-memory"></i> Memory Limit</small>
                        <span class="fw-bold"><?php echo ini_get('memory_limit'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($diskPercent > 90): ?>
        <div class="alert alert-danger shadow-sm fw-bold d-flex align-items-center mb-4 no-print">
            <i class="bi bi-hdd-fill fs-4 me-3"></i>
            <div>
                <strong>Server Load High!</strong> Disk usage is at <?php echo $diskPercent; ?>%.
                <br><span class="small fw-normal">Please clear old files or backups immediately to prevent system failure.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($vaultLimitGB > 0 && $vaultQuotaPercent >= 90): ?>
        <div class="alert alert-warning shadow-sm fw-bold d-flex align-items-center mb-4 border-warning border-3 no-print">
            <i class="bi bi-hdd-network fs-3 me-3 text-warning"></i>
            <div>
                <strong>Vault Storage Warning:</strong> Your document vault is at <strong><?php echo $vaultQuotaPercent; ?>%</strong> capacity (<?php echo number_format($currentVaultGB, 2); ?> GB / <?php echo number_format($vaultLimitGB, 2); ?> GB).
                <br><span class="small fw-normal">Please use the <strong>Storage Optimization</strong> tool in the Recovery Console to archive old files, or increase your limit in <a href="settings.php" class="alert-link">Settings</a>.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php // ---------- 10) DASHBOARD WIDGETS ---------- 
    ?>
    <div class="row mb-4" id="dashboard-widgets">
        <div class="col-lg-8 mb-3 mb-lg-0">
            <div class="card h-100 shadow-soft">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-graph-up-arrow me-2 text-primary"></i>
                    <span class="fw-semibold">Document Analytics</span>
                </div>
                <div class="card-body position-relative">
                    <canvas id="hrChart" style="width: 100%; height: 100%; min-height: 300px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4 d-flex flex-column gap-3">
            <div class="card h-100 shadow-soft">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-clock-history me-2 text-info"></i>
                    <span class="fw-semibold">Recent Activity</span>
                </div>
                <div class="card-body p-0">
                    <ul id="recent-activity-list" class="list-group list-group-flush small">
                        <?php foreach ($recentActivity as $act): ?>
                            <li class="list-group-item border-0 border-bottom">
                                <strong><?= h($act['first_name'] . ' ' . $act['last_name']) ?></strong> uploaded <span class="text-primary"><?= h($act['original_name']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="card shadow-soft">
                <div class="card-body d-grid gap-2">
                    <a href="upload_form.php" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload Document</a>

                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER'], true)): ?>
                        <a href="manager_dashboard.php" class="btn btn-info text-white fw-bold"><i class="bi bi-speedometer2"></i> Manager Dashboard</a>
                    <?php endif; ?>

                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR', 'STAFF'], true)): ?>
                        <a href="add_employee.php" class="btn btn-success"><i class="bi bi-person-plus-fill"></i> Add Employee</a>
                    <?php endif; ?>

                    <?php if ($userRole === 'STAFF'): ?>
                        <a href="my_requests.php" class="btn btn-outline-primary"><i class="bi bi-clock-history"></i> My Requests</a>
                        <a href="profile_settings.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-lock"></i> Change Password</a>
                        <a href="help.php" class="btn btn-outline-info btn-sm"><i class="bi bi-question-circle"></i> User Manual</a>
                    <?php endif; ?>

                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                        <a href="import_employees.php" class="btn btn-outline-success" title="Upload CSV">
                            <i class="bi bi-file-spreadsheet"></i> Bulk Import
                        </a>
                        <a href="analytics.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-graph-up"></i> Analytics
                        </a>
                        <a href="recruitment.php" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-person-lines-fill"></i> Recruitment
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR', 'STAFF'], true)): ?>
                        <a href="tracker.php" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-kanban"></i> Missing Docs Tracker
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                        <a href="performance_review.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-clipboard2-data"></i> Performance Reviews
                        </a>
                        <a href="evaluation_report.php" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-bar-chart-line"></i> Evaluation Report
                        </a>
                        <a href="disciplinary.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-exclamation-triangle"></i> Disciplinary Cases
                        </a>
                        <?php if ($userRole === 'ADMIN'): ?>
                            <a href="maintenance_log.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-tools"></i> Hardware Maintenance
                            </a>
                        <?php endif; ?>
                        <a href="bulk_update_roles.php" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-people-fill"></i> Bulk Update Roles
                        </a>
                        <a href="bulk_contract.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-printer-fill"></i> Bulk Contract Print
                        </a>
                        <a href="bulk_archive.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-archive-fill"></i> Bulk Archive Inactive
                        </a>
                        <a href="manage_options.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-list-check"></i> Manage Options
                        </a>
                        <button type="button" class="btn btn-warning fw-bold text-dark btn-sm" data-bs-toggle="modal" data-bs-target="#exportModal">
                            <i class="bi bi-file-earmark-zip-fill"></i> Export Files (ZIP)
                        </button>
                    <?php endif; ?>

                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                        <a href="expiry_report.php" class="btn btn-outline-info w-100 mt-2">
                            <i class="bi bi-binoculars-fill"></i> Expiry Forecast
                        </a>
                        <a href="missing_fields_report.php" class="btn btn-outline-warning w-100 mt-2">
                            <i class="bi bi-exclamation-triangle-fill"></i> Missing Fields Report
                        </a>
                    <?php endif; ?>
                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                        <a href="admin_approval.php" class="btn btn-outline-danger">
                            <i class="bi bi-shield-lock"></i> Approval Center
                        </a>
                    <?php endif; ?>
                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                        <a href="recycle_bin.php" class="btn btn-outline-secondary mt-2">
                            <i class="bi bi-trash3"></i> Recycle Bin
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Directory Search / Filters -->
    <?php // ---------- 11) DIRECTORY SEARCH & FILTERS ---------- 
    ?>
    <div class="card mb-4 shadow-soft" id="directory-search-bar">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <div class="d-flex align-items-center gap-3">
                    <h5 class="text-muted mb-0"><i class="bi bi-funnel-fill"></i> Directory Search</h5>
                    <div class="btn-group btn-group-sm shadow-sm">
                        <button type="button" class="btn btn-outline-primary" id="btn-view-cards" onclick="switchView('cards')"><i class="bi bi-grid-fill"></i> Cards</button>
                        <button type="button" class="btn btn-outline-primary" id="btn-view-list" onclick="switchView('list')"><i class="bi bi-list-ul"></i> List</button>
                    </div>
                    <button type="button" id="btn-print-list" class="btn btn-sm btn-dark shadow-sm d-none" onclick="window.print()">
                        <i class="bi bi-printer-fill"></i> Print List
                    </button>
                </div>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset Filters</a>
            </div>

            <form action="index.php" method="GET" class="row g-2">
                <!-- Status filter -->
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php
                        $statuses = [
                            'Active'     => 'Active',
                            'Resigned'   => 'Resigned',
                            'Terminated' => 'Terminated',
                            'AWOL'       => 'AWOL'
                        ];
                        foreach ($statuses as $val => $label) {
                            $sel = ($filter_status === $val) ? 'selected' : '';
                            echo '<option value="' . h($val) . '" ' . $sel . '>' . h($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- Employment Type/Agency filter -->
                <div class="col-md-2">
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <?php
                        foreach ($agencies as $val) {
                            $sel = ($filter_type === $val) ? 'selected' : '';
                            // Use value as label for simplicity, or map if needed
                            echo '<option value="' . h($val) . '" ' . $sel . '>' . h($val) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- Department filter (FIX: correct name="dept") -->
                <div class="col-md-2">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php
                        foreach (array_keys($deptMap) as $d) {
                            $sel = ($filter_dept === $d) ? 'selected' : '';
                            echo '<option value="' . h($d) . '" ' . $sel . '>' . h($d) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- Section filter -->
                <div class="col-md-2">
                    <select name="section" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Sections</option>
                        <?php
                        // Only show sections if a department is selected
                        if ($filter_dept && isset($deptMap[$filter_dept])) {
                            foreach ($deptMap[$filter_dept] as $s) {
                                $sel = ($filter_section === $s) ? 'selected' : '';
                                echo '<option value="' . h($s) . '" ' . $sel . '>' . h($s) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <!-- Sort options -->
                <div class="col-md-2">
                    <select name="sort" class="form-select form-select-sm fw-bold text-primary" onchange="this.form.submit()">
                        <option value="newest" <?php echo ($sort_option === 'newest') ? 'selected' : ''; ?>>Newest</option>
                        <option value="oldest" <?php echo ($sort_option === 'oldest') ? 'selected' : ''; ?>>Oldest</option>
                        <option value="alpha_az" <?php echo ($sort_option === 'alpha_az') ? 'selected' : ''; ?>>Name (A-Z)</option>
                        <option value="alpha_za" <?php echo ($sort_option === 'alpha_za') ? 'selected' : ''; ?>>Name (Z-A)</option>
                    </select>
                </div>

                <div class="col-md-auto d-flex align-items-center">
                    <button type="button" id="selectAllBtn" class="btn btn-sm btn-outline-secondary fw-bold shadow-sm" onclick="toggleSelectAllEmployees()">
                        <i class="bi bi-check-all"></i> Select All
                    </button>
                    <button type="button" id="clearSelectionBtn" class="btn btn-sm btn-outline-danger fw-bold shadow-sm ms-2 d-none" onclick="clearEmployeeSelection()">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                </div>

                <!-- [NEW] Filter for employees with uncategorized files -->
                <div class="col-md-auto d-flex align-items-center">
                    <?php $isUncatActive = ($filter_doc_cat === 'Uncategorized'); ?>
                    <a href="index.php<?php echo $isUncatActive ? h(keepQuery(['doc_cat' => null, 'page' => 1])) : h(keepQuery(['doc_cat' => 'Uncategorized', 'page' => 1])); ?>"
                        class="btn btn-sm <?php echo $isUncatActive ? 'btn-danger' : 'btn-outline-danger'; ?> fw-bold shadow-sm" title="Show only employees with unclassified documents">
                        <i class="bi bi-tag-fill me-1"></i> <?php echo $isUncatActive ? 'Showing Uncategorized' : 'Filter Uncategorized'; ?>
                    </a>
                </div>

                <!-- [NEW] Bulk Actions Button -->
                <div class="col-md-auto d-flex align-items-center">
                    <button type="button" id="bulkActionBtn" class="btn btn-sm btn-dark fw-bold shadow-sm d-none" data-bs-toggle="modal" data-bs-target="#bulkActionModal">
                        <i class="bi bi-layers-half me-1"></i> Bulk Actions(<span id="selectedCount">0</span>)
                    </button>
                </div>

                <!-- Search box (with maxlength for UX) -->
                <div class="col-md-3 position-relative">
                    <div class="input-group input-group-sm">
                        <input type="text" id="mainSearch" name="search" class="form-control"
                            placeholder="Search by ID / First / Last..." value="<?php echo h($search_query); ?>"
                            autocomplete="off" aria-label="Search employees" maxlength="50" pattern="[a-zA-Z0-9\-_ ,]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Commas">
                        <?php if (!empty($search_query)): ?>
                            <a href="index.php" class="btn btn-outline-secondary border-start-0" title="Clear Search"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit" aria-label="Submit search"><i class="bi bi-search"></i></button>

                        <!-- Export dropdown trigger (uses current filters) -->
                        <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Export options">
                            <i class="bi bi-download"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow p-3" style="width: 260px;">
                            <li>
                                <h6 class="dropdown-header text-primary"><i class="bi bi-info-circle"></i> Export Options</h6>
                            </li>
                            <li>
                                <p class="small text-muted mb-2 text-wrap">Download the currently filtered list.</p>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><button type="submit" formaction="export_employees.php" class="dropdown-item"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Save as Excel</button></li>
                            <li><button type="submit" formaction="print_list.php" formtarget="_blank" class="dropdown-item"><i class="bi bi-file-earmark-pdf text-danger me-2"></i> Print / PDF</button></li>
                        </ul>
                    </div>
                    <div id="suggestionBox" class="list-group position-absolute w-100 shadow" style="z-index: 1000; display: none; top: 35px;"></div>
                </div>

                <!-- Keep paging inputs -->
                <!-- [FIX] Removed 'page' input so filters reset to Page 1 automatically -->
                <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">

                <?php if ($filter_doc_cat !== ''): ?>
                    <input type="hidden" name="doc_cat" value="<?php echo h($filter_doc_cat); ?>">
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- [FIX] Wrapper for Card View - Moved up to include alerts for total separation -->
    <?php // ---------- 12) EMPLOYEE DIRECTORY RESULTS ---------- 
    ?>

    <!-- ============================================================ CARD VIEW (shown by default) ============================================================ -->
    <div id="view-cards">
        <?php if (empty($employees)): ?>
            <div class="alert alert-warning text-center shadow-sm">No employees found matching your search.</div>
            <?php if ($didYouMean): ?>
                <div class="alert alert-info text-center shadow-sm mt-2">
                    <i class="bi bi-lightbulb-fill me-2"></i> Did you mean:
                    <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo h($didYouMean); ?></a>?
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($filter_doc_cat !== ''): ?>
            <div class="alert alert-info alert-dismissible fade show shadow-sm mb-4" role="alert">
                <i class="bi bi-funnel-fill me-2"></i>
                Filtering by Document Category: <strong><?php echo h($filter_doc_cat); ?></strong>
                <a href="index.php" class="btn-close" aria-label="Close"></a>
            </div>
        <?php endif; ?>

        <?php if (empty($employees)): ?>
            <div class="alert alert-warning text-center shadow-sm">No employees found matching your search.</div>
            <?php if ($didYouMean): ?>
                <div class="alert alert-info text-center shadow-sm mt-2">
                    <i class="bi bi-lightbulb-fill me-2"></i> Did you mean:
                    <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo h($didYouMean); ?></a>?
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="row" id="directory-results">
            <?php foreach ($employees as $emp):
                $statusClass = match ($emp['status']) {
                    'Active'     => 'status-active',
                    'Resigned'   => 'status-agency',
                    'Terminated' => 'status-terminated',
                    default      => 'border-secondary'
                };
                $statusBadge = match ($emp['status']) {
                    'Active'     => 'bg-success',
                    'Resigned'   => 'bg-warning',
                    'Terminated' => 'bg-dark',
                    default      => 'bg-secondary'
                };

                // [NEW] System Role Badge Colors
                $sysRole = $emp['system_role'] ?? 'Staff';
                $roleBadge = match (strtoupper($sysRole)) {
                    'MANAGER', 'HEAD' => 'bg-danger',
                    'ENGINEER', 'ADVISOR' => 'bg-primary',
                    'IT' => 'bg-dark',
                    'OFFICER', 'SUPERVISOR' => 'bg-info text-dark',
                    'MAINTENANCE', 'TECHNICIAN' => 'bg-warning text-dark',
                    'DRIVER' => 'bg-secondary',
                    default => 'bg-light text-dark border'
                };

                // Color-coded employer badges
                $agName = strtoupper($emp['agency_name'] ?? '');
                if (($emp['employment_type'] ?? '') === 'TESP Direct') {
                    $employerBadge = '<span class="badge bg-primary">TESP DIRECT</span>';
                } elseif ($agName === 'JORATECH') {
                    $employerBadge = '<span class="badge bg-success">JORATECH</span>';
                } elseif ($agName === 'UNLISOLUTIONS') {
                    $employerBadge = '<span class="badge bg-warning text-dark">UNLISOLUTIONS</span>';
                } elseif ($agName === 'GUNJIN') {
                    $employerBadge = '<span class="badge bg-danger">GUNJIN</span>';
                } else {
                    $employerBadge = '<span class="badge bg-secondary">' . h($agName ?: 'AGENCY') . '</span>';
                }
                $deptDisplay = h($emp['dept']);
                if (!empty($emp['section']) && $emp['section'] !== 'Main Unit') {
                    $deptDisplay .= ' &gt; ' . h($emp['section']);
                }
                if (!empty($emp['group'])) {
                    $deptDisplay .= ' (' . h($emp['group']) . ')';
                }
                $files      = $filesByEmp[$emp['emp_id']] ?? [];
                $modalId    = 'viewModal' . (int)$emp['id'];
                $previewBoxId = 'preview-' . (int)$emp['id'];

                // [NEW] Identify Employees with Uncategorized Files for the label
                $hasUncategorized = false;
                foreach ($files as $f) {
                    $matched = false;
                    $cat = trim($f['category'] ?? '');
                    foreach ($REQUIRED_DOCS as $reqName => $keywords) {
                        if (strcasecmp($cat, $reqName) === 0) {
                            $matched = true;
                            break;
                        }
                        foreach ($keywords as $k) {
                            if ($k !== '' && (stripos($f['original_name'], $k) !== false || stripos($cat, $k) !== false)) {
                                $matched = true;
                                break 2;
                            }
                        }
                    }
                    if (!$matched) {
                        $hasUncategorized = true;
                        break;
                    }
                }

                // [NEW] Check Completeness & Recency
                $missingFields = [];
                $requiredFields = ['sss_no', 'tin_no', 'philhealth_no', 'pagibig_no', 'contact_number', 'present_address', 'emergency_name', 'emergency_contact'];
                foreach ($requiredFields as $field) {
                    if (empty($emp[$field])) $missingFields[] = $field;
                }
                $isComplete = empty($missingFields);

                // Check if updated in last 7 days
                $isRecentlyUpdated = false;
                // Note: Ensure 'updated_at' is selected in your SQL query if it exists
                if (!empty($emp['updated_at'] ?? null)) {
                    if (strtotime($emp['updated_at']) > strtotime('-7 days')) {
                        $isRecentlyUpdated = true;
                    }
                }
            ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 employee-card <?php echo $statusClass; ?>"
                        role="button"
                        data-bs-toggle="modal"
                        data-bs-target="#<?php echo h($modalId); ?>"
                        data-emp-id-str="<?php echo h($emp['emp_id']); ?>">
                        <!-- [NEW] Selection Checkbox -->
                        <div class="position-absolute top-0 start-0 p-2" style="z-index: 10;">
                            <input type="checkbox" class="form-check-input emp-select-check" value="<?php echo (int)$emp['id']; ?>" onclick="event.stopPropagation(); setEmployeeSelected(this.value, this.checked);">
                        </div>

                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="me-3">
                                    <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>"
                                        class="card-img-top avatar-circle"
                                        alt="Profile"
                                        onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-1 fw-bold"><?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h5>
                                    <small class="text-muted d-block mb-1"><?php echo $deptDisplay; ?></small>
                                    <?php if ($hasUncategorized): ?>
                                        <div class="mb-1"><span class="badge bg-danger-subtle text-danger border border-danger-subtle extra-small"><i class="bi bi-exclamation-triangle-fill"></i> Uncategorized Files</span></div>
                                    <?php endif; ?>
                                    <span class="badge <?php echo $statusBadge; ?> rounded-pill"><?php echo h($emp['status']); ?></span>
                                    <span class="badge <?php echo $roleBadge; ?> rounded-pill ms-1" title="System Role"><i class="bi bi-person-badge"></i> <?php echo h($sysRole); ?></span>
                                </div>
                                <div class="d-flex flex-column align-items-end">
                                    <div class="mb-2"><?php echo $employerBadge; ?></div>
                                    <a href="print_employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn btn-sm btn-outline-dark py-0 px-2 mt-1" target="_blank" onclick="event.stopPropagation();" aria-label="Print employee">
                                        <i class="bi bi-printer-fill"></i>
                                    </a>
                                    <?php if (isset($_SESSION['user_id'])): ?>
                                        <a href="edit_employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 mt-1" onclick="event.stopPropagation();" aria-label="Edit employee">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div><!-- /#view-cards -->

    <!-- ============================================================ COMPACT LIST VIEW (New Reliable Alternative) ============================================================ -->
    <style>
        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }

            /* [NEW] Confidential Watermark Style */
            body::before {
                content: "CONFIDENTIAL";
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-45deg);
                font-size: 100pt;
                color: rgba(0, 0, 0, 0.05) !important;
                z-index: -1;
                pointer-events: none;
            }

            .navbar,
            .btn,
            .btn-group,
            form,
            #pagination-container,
            .no-print,
            #refreshToggle,
            #darkModeToggle,
            #dashboard-widgets,
            #directory-search-bar,
            #view-cards,
            #view-spreadsheet {
                display: none !important;
            }

            body {
                background: white !important;
                font-size: 10pt;
            }

            .container {
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .card {
                border: none !important;
                box-shadow: none !important;
            }

            .table {
                width: 100% !important;
                border-collapse: collapse !important;
            }

            .table th,
            .table td {
                border: 1px solid #dee2e6 !important;
                padding: 4px !important;
                font-size: 9pt !important;
                vertical-align: middle !important;
            }

            .badge {
                border: 1px solid #000 !important;
                color: black !important;
                background: transparent !important;
            }

            #view-list {
                display: block !important;
            }

            #view-list .list-avatar {
                width: 30px !important;
                height: 30px !important;
                display: block !important;
            }

            .employee-row {
                cursor: default !important;
            }
        }
    </style>
    <div id="view-list" style="display: none;">
        <!-- PRINT HEADER (Only visible on paper) -->
        <div class="d-none d-print-block text-center mb-4">
            <img src="<?= $logo_src ?>" alt="TESP Logo" style="max-height: 55px; margin-bottom: 5px;">
            <h4 class="fw-bold mb-1">Employee Master Directory</h4>
            <div class="text-uppercase small fw-bold">TES Philippines, Inc.</div>
            <p class="text-muted small mt-1">Generated: <?= date('M d, Y') ?> | <span class="text-dark">Print for the Employee</span></p>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;" class="no-print"></th>
                                <th style="width: 65px;"></th>
                                <th>Employee Name</th>
                                <th>ID</th>
                                <th>Dept / Section</th>
                                <th>Job Title</th>
                                <th>Employer</th>
                                <th>Status</th>
                                <th>SSS No</th>
                                <th>TIN No</th>
                                <th>PhilHealth</th>
                                <th>Pag-IBIG</th>
                                <th>Address</th>
                                <th>College Course</th>
                                <th class="text-end no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr class="employee-row" style="cursor:pointer;" data-bs-toggle="modal" data-bs-target="#viewModal<?= (int)$emp['id'] ?>">
                                    <td class="no-print">
                                        <input type="checkbox" class="form-check-input emp-select-check" value="<?php echo (int)$emp['id']; ?>" onclick="event.stopPropagation(); setEmployeeSelected(this.value, this.checked);">
                                    </td>
                                    <td>
                                        <img src="uploads/avatars/<?= h($emp['avatar_path'] ?: 'default.png') ?>"
                                            class="rounded-circle border shadow-sm list-avatar"
                                            width="45" height="45"
                                            style="object-fit:cover; cursor: zoom-in;"
                                            title="Click to view full size"
                                            onclick="event.stopPropagation(); Swal.fire({title: '<?= h($emp['first_name'] . ' ' . $emp['last_name']) ?>', imageUrl: this.src, imageAlt: 'Profile', showConfirmButton: false, showCloseButton: true});"
                                            onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">
                                    </td>
                                    <td class="fw-bold" style="font-size: 1.05rem;"><?= h($emp['last_name'] . ', ' . $emp['first_name']) ?></td>
                                    <td class="font-monospace small"><?= h($emp['emp_id']) ?></td>
                                    <td><span class="small text-muted"><?= h($emp['dept']) ?></span></td>
                                    <td class="small"><?= h($emp['job_title']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= h($emp['agency_name'] ?: $emp['employment_type']) ?></span></td>
                                    <td><span class="badge rounded-pill <?= $emp['status'] === 'Active' ? 'bg-success' : 'bg-warning' ?>"><?= h($emp['status']) ?></span></td>
                                    <td class="font-monospace small"><?= h($emp['sss_no'] ?: 'N/A') ?></td>
                                    <td class="font-monospace small"><?= h($emp['tin_no'] ?: 'N/A') ?></td>
                                    <td class="font-monospace small"><?= h($emp['philhealth_no'] ?: 'N/A') ?></td>
                                    <td class="font-monospace small"><?= h($emp['pagibig_no'] ?: 'N/A') ?></td>
                                    <td class="small"><?= h($emp['present_address'] ?: 'N/A') ?></td>
                                    <td class="small"><?= h($emp['college_course'] ?: 'N/A') ?></td>
                                    <td class="text-end no-print">
                                        <div class="btn-group btn-group-sm shadow-sm">
                                            <button type="button" class="btn btn-outline-primary" title="View Profile" data-bs-toggle="modal" data-bs-target="#viewModal<?= (int)$emp['id'] ?>" onclick="event.stopPropagation();"><i class="bi bi-eye"></i></button>
                                            <a href="print_employee.php?id=<?= (int)$emp['id'] ?>" target="_blank" class="btn btn-outline-dark" title="Print" onclick="event.stopPropagation();"><i class="bi bi-printer"></i></a>
                                            <a href="edit_employee.php?id=<?= (int)$emp['id'] ?>" class="btn btn-outline-secondary" title="Edit" onclick="event.stopPropagation();"><i class="bi bi-pencil"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- PRINT FOOTER SUMMARY -->
            <div class="d-none d-print-block mt-3 border-top pt-2">
                <div class="d-flex justify-content-between align-items-center fw-bold" style="font-size: 10pt;">
                    <span>*** END OF REPORT ***</span>
                    <span>TOTAL HEADCOUNT: <?php echo number_format($totalRows); ?> employee(s)</span>
                </div>
            </div>
        </div>
    </div><!-- /#view-list -->

    <div id="pagination-container">
        <?php if ($totalPages > 1): ?>
            <nav class="mt-3" aria-label="Employee pagination">
                <ul class="pagination justify-content-center">
                    <?php $prevDisabled = ($page <= 1) ? ' disabled' : '';
                    $nextDisabled = ($page >= $totalPages) ? ' disabled' : ''; ?>
                    <li class="page-item<?php echo $prevDisabled; ?>">
                        <a class="page-link" href="<?php echo h(keepQuery(['page' => max(1, $page - 1)])); ?>#directory-results" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>
                    </li>
                    <?php
                    $window = 2;
                    $start = max(1, $page - $window);
                    $end   = min($totalPages, $page + $window);
                    if ($start > 1) {
                        echo '<li class="page-item"><a class="page-link" href="' . h(keepQuery(['page' => 1])) . '#directory-results">1</a></li>';
                        if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    for ($p = $start; $p <= $end; $p++) {
                        $active = ($p === $page) ? ' active' : '';
                        echo '<li class="page-item' . $active . '"><a class="page-link" href="' . h(keepQuery(['page' => $p])) . '#directory-results">' . (int)$p . '</a></li>';
                    }
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="' . h(keepQuery(['page' => $totalPages])) . '#directory-results">' . (int)$totalPages . '</a></li>';
                    }
                    ?>
                    <li class="page-item<?php echo $nextDisabled; ?>">
                        <a class="page-link" href="<?php echo h(keepQuery(['page' => min($totalPages, $page + 1)])); ?>#directory-results" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
                    </li>
                </ul>
                <p class="text-center text-muted small mb-0">
                    Showing <strong><?php echo htmlspecialchars((int)count($employees)); ?></strong> of <strong><?php echo htmlspecialchars((int)$totalRows); ?></strong> employees — Page <?php echo htmlspecialchars((int)$page); ?> / <?php echo htmlspecialchars((int)$totalPages); ?>
                </p>
            </nav>
        <?php endif; ?>
    </div><!-- /#pagination-container -->

    <!-- ============================================================ SHARED MODALS LOOP (Sibling to containers) ============================================================ -->
    <div id="shared-modals-container">
        <?php foreach ($employees as $emp):
            $modalId    = 'viewModal' . (int)$emp['id'];
            $files      = $filesByEmp[$emp['emp_id']] ?? [];
            $previewBoxId = 'preview-' . (int)$emp['id'];

            // Recalculate basic modal flags
            $missingFields = [];
            $requiredFields = ['sss_no', 'tin_no', 'philhealth_no', 'pagibig_no', 'contact_number', 'present_address', 'emergency_name', 'emergency_contact'];
            foreach ($requiredFields as $field) if (empty($emp[$field])) $missingFields[] = $field;
            $isComplete = empty($missingFields);
            $isRecentlyUpdated = (!empty($emp['updated_at']) && strtotime($emp['updated_at']) > strtotime('-7 days'));
        ?>
            <div class="modal fade" id="<?php echo h($modalId); ?>" tabindex="-1" aria-hidden="true" data-emp-id-str="<?php echo h($emp['emp_id']); ?>">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header modal-header-custom p-4">
                            <div class="d-flex align-items-center w-100">
                                <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>"
                                    class="rounded-circle border border-3 border-white shadow-sm"
                                    width="100" height="100"
                                    style="object-fit:cover; cursor: zoom-in;"
                                    title="Click to view full size"
                                    onclick="Swal.fire({title: '<?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?>', imageUrl: this.src, imageAlt: 'Profile', showConfirmButton: false, showCloseButton: true});"
                                    onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';" alt="Avatar">
                                <div class="ms-3 flex-grow-1">
                                    <h3 class="mb-0 fw-bold"><?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h3>
                                    <div class="badge bg-light text-dark mt-1"><?php echo h($emp['emp_id']); ?></div>
                                    <div class="badge bg-white text-dark mt-1"><?php echo h($emp['job_title']); ?></div>
                                    <?php if ($isRecentlyUpdated): ?><span class="badge bg-info text-dark mt-1"><i class="bi bi-stars"></i> Recently Updated</span><?php endif; ?>
                                    <?php if (!$isComplete): ?><span class="badge bg-warning text-dark mt-1"><i class="bi bi-exclamation-triangle"></i> Incomplete</span><?php else: ?><span class="badge bg-success mt-1"><i class="bi bi-check-circle"></i> Complete</span><?php endif; ?>
                                </div>
                                <button type="button" class="btn-close btn-close-white align-self-start" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                        </div>
                        <div class="modal-body p-0">
                            <div class="d-flex h-100">
                                <div class="nav flex-column nav-pills p-3 border-end" style="width: 260px;">
                                    <button class="nav-link active text-start mb-2" data-bs-toggle="pill" data-bs-target="#info-<?php echo (int)$emp['id']; ?>">Profile</button>
                                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#files-<?php echo (int)$emp['id']; ?>">Documents (<?php echo (int)count($files); ?>)</button>
                                </div>
                                <div class="tab-content flex-grow-1 p-4">
                                    <div class="tab-pane fade show active" id="info-<?php echo (int)$emp['id']; ?>">
                                        <h6 class="text-primary fw-bold mb-3 border-bottom pb-2"><i class="bi bi-briefcase"></i> Work Information</h6>
                                        <div class="row g-3 mb-4">
                                            <div class="col-6"><span class="info-label">Dept:</span><br><span class="fw-medium"><?php echo h($emp['dept']); ?></span></div>
                                            <div class="col-6"><span class="info-label">Section:</span><br><span class="fw-medium"><?php echo h($emp['section']); ?></span></div>
                                            <div class="col-6"><span class="info-label">Group:</span><br><span class="fw-medium"><?php echo h($emp['group'] ?: 'N/A'); ?></span></div>
                                            <div class="col-6"><span class="info-label">Hired:</span><br><span class="fw-medium"><?php echo h($emp['hire_date'] ? date('M d, Y', strtotime($emp['hire_date'])) : 'N/A'); ?></span></div>
                                        </div>
                                        <h6 class="text-primary fw-bold mb-3 border-bottom pb-2"><i class="bi bi-person-lines-fill"></i> Contact Details</h6>
                                        <div class="row g-3">
                                            <div class="col-6"><span class="info-label">Phone:</span><br><span class="fw-medium"><?php echo h($emp['contact_number']); ?></span></div>
                                            <div class="col-6"><span class="info-label">Email:</span><br><span class="fw-medium"><?php echo h($emp['email'] ?: 'N/A'); ?></span></div>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="files-<?php echo (int)$emp['id']; ?>">
                                        <div class="row h-100">
                                            <div class="col-4 border-end">
                                                <div class="list-group">
                                                    <?php foreach ($files as $file):
                                                        $previewUrl = "view_doc.php?id=" . $file['file_uuid'] . "&embed=1";
                                                        $type = (stripos($file['original_name'], '.pdf') !== false) ? 'pdf' : 'img';
                                                        $previewTarget = 'preview-' . (int)$emp['id'];
                                                    ?>
                                                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-2">
                                                            <a href="javascript:void(0);" class="text-decoration-none text-body text-truncate w-75"
                                                                onclick="showPreview('<?php echo h($previewUrl); ?>', '<?php echo h($type); ?>', '<?php echo h($previewTarget); ?>'); return false;">
                                                                <strong><?php echo h($file['original_name']); ?></strong><br>
                                                                <small class="text-secondary"><?php echo h($file['category']); ?></small>
                                                            </a>
                                                            <a href="view_doc.php?id=<?php echo $file['file_uuid']; ?>&download=1" class="btn btn-sm btn-outline-primary border-0 ms-1"><i class="bi bi-download"></i></a>
                                                            <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                                                                <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="confirmDelete(<?php echo htmlspecialchars(json_encode($file['file_uuid']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($emp['emp_id']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="bi bi-trash"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($files)): ?>
                                                        <div class="text-center p-4 text-muted small fst-italic">No documents uploaded.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-8">
                                                <div id="<?php echo h($previewBoxId); ?>" class="preview-box">Select a file to preview</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div><!-- /#shared-modals-container -->

    <!-- Delete confirmation modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <div class="text-danger mb-3">
                        <i class="bi bi-trash3-fill" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold">Are you sure?</h5>
                    <p class="text-muted">Do you really want to permanently delete this file?<br>This process cannot be undone.</p>
                    <form action="delete_document.php" method="POST">
                        <input type="hidden" name="file_uuid" id="del_file_uuid">
                        <input type="hidden" name="emp_id" id="del_emp_id">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="d-flex justify-content-center gap-2 mt-4">
                            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger px-4">Yes, Delete It</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Resolve/Report Modal (single instance) -->
    <div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="submit_resolution.php" method="POST" class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-clipboard2-check me-2"></i> Report Action Taken</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="doc_id" id="res_doc_id">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <p>Resolving alert for: <strong id="res_cat_name"></strong></p>
                    <textarea name="resolution_note" id="res_note" class="form-control" rows="3" required placeholder="Action taken..." maxlength="500"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Submit Report</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Export Modal (single instance) -->
    <div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="export_files.php" method="POST" class="modal-content" onsubmit="showExportLoader(this)">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-archive-fill"></i> Bulk Export</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                    <div class="mb-3 p-2 bg-light border rounded position-relative">
                        <label class="form-label fw-bold text-primary">Search Employee (Optional)</label>
                        <input type="text" id="exportSearch" name="search" class="form-control" placeholder="Type Name or ID..." autocomplete="off" maxlength="50" pattern="[a-zA-Z0-9\-_ ,]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Commas">
                        <div id="exportSuggestionBox" class="list-group position-absolute w-100 shadow" style="display:none; z-index:2000; top:75px;"></div>
                        <div class="form-text small">Typing a name makes "Department" optional.</div>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Department</label>
                        <select name="dept" id="exportDept" class="form-select">
                            <option value="" selected>-- Select Scope --</option>
                            <?php foreach (array_keys($deptMap) as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Section (Filtered by Dept)</label>
                        <select name="section" id="exportSection" class="form-select" disabled>
                            <option value="">-- All Sections --</option>
                            <?php foreach ($deptMap as $d => $sections): ?>
                                <optgroup label="<?php echo htmlspecialchars($d); ?>">
                                    <?php foreach ($sections as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Agency</label>
                            <select name="employment_type" class="form-select">
                                <option value="">-- All --</option>
                                <option value="TESP DIRECT">TESP DIRECT</option>
                                <option value="GUNJIN">GUNJIN</option>
                                <option value="JORATECH">JORATECH</option>
                                <option value="UNLISOLUTIONS">UNLISOLUTIONS</option>
                                <option value="OTHERS - SUBCONS">OTHERS - SUBCONS</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select name="category" class="form-select">
                                <option value="">-- All --</option>
                                <?php foreach ($dynamicCats as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                    </div>

                    <hr>
                    <div class="mb-2">
                        <label class="form-label fw-bold text-danger">ZIP Password (Optional)</label>
                        <div class="input-group">
                            <input type="password" name="zip_password" id="exportZipPass" class="form-control" placeholder="Leave blank for no password" maxlength="50">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePass('exportZipPass')"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text">Sets a password to open the downloaded ZIP file.</div>
                    </div>

                    <div class="alert alert-warning small mb-0 mt-3 border-warning">
                        <i class="bi bi-info-circle-fill"></i> <strong>Massive Data Reminder:</strong> If your requested export exceeds the <strong>1.9 GB</strong> limit, the system will automatically split it into multiple volumes (Part 1, Part 2, etc.) and download them consecutively.
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Download ZIP</button>
                </div>
            </form>
        </div>
    </div>

    <!-- [FIX] UNIFIED BULK ACTION MODAL -->
    <div class="modal fade" id="bulkActionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content" id="bulkActionForm">
                <input type="hidden" name="action" value="bulk_move_dept">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="redirect_query" value="<?php echo h($_SERVER['QUERY_STRING']); ?>">
                <div id="bulkMoveIdsContainer"></div>

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="bi bi-layers-half"></i> Bulk Actions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Update <strong id="modalSelectedCount">0</strong> selected employees:</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Change Department</label>
                        <select name="target_dept" id="bulkTargetDept" class="form-select" onchange="updateBulkSections()">
                            <option value="">-- No Change --</option>
                            <?php foreach (array_keys($deptMap) as $d): ?>
                                <option value="<?php echo h($d); ?>"><?php echo h($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Change Section</label>
                        <select name="target_section" id="bulkTargetSection" class="form-select">
                            <option value="">-- All Sections --</option>
                        </select>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Change System Role</label>
                        <select name="target_role" id="bulkTargetRole" class="form-select">
                            <option value="">-- No Change --</option>
                            <?php foreach ($system_roles as $role): ?>
                                <option value="<?php echo h($role); ?>"><?php echo h($role); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Change Agency</label>
                        <select name="target_agency" id="bulkTargetAgency" class="form-select">
                            <option value="">-- No Change --</option>
                            <?php foreach ($agencies as $agency): ?>
                                <option value="<?php echo h($agency); ?>"><?php echo h($agency); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Update Status</label>
                        <select name="target_status" class="form-select">
                            <option value="">-- No Change --</option>
                            <option value="Active">Active</option>
                            <option value="Resigned">Resigned</option>
                            <option value="Terminated">Terminated</option>
                            <option value="AWOL">AWOL</option>
                        </select>
                    </div>
                    <hr>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success fw-bold" onclick="confirmBulkAction()">Apply Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SINGLE Bootstrap bundle include -->
    <script src="assets/bootstrap.bundle.min.js?v=3"></script>

    <script>
        // [FIX] Data for Bulk Modal Dropdowns
        const deptMapData = <?php echo json_encode($deptMap); ?>;

        // ---------- Chart ----------
        document.addEventListener('DOMContentLoaded', () => {
            // [NEW] 100% Offline Custom DataLabels Plugin
            const offlineDataLabels = {
                id: 'offlineDataLabels',
                afterDatasetsDraw(chart, args, options) {
                    const {
                        ctx
                    } = chart;
                    ctx.save();
                    ctx.font = 'bold 12px Helvetica, Arial, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';

                    chart.data.datasets.forEach((dataset, i) => {
                        const meta = chart.getDatasetMeta(i);
                        if (meta.hidden) return;

                        meta.data.forEach((element, index) => {
                            let dataVal = dataset.data[index];
                            if (dataVal === undefined || dataVal === null || Number(dataVal) === 0) return;

                            let text = dataVal.toString();
                            if (chart.config.type === 'pie' || chart.config.type === 'doughnut') {
                                let total = dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                                let percent = Math.round((dataVal / total) * 100);
                                if (percent < 5) return;
                                text = `${dataVal} (${percent}%)`;
                            }

                            if (typeof element.tooltipPosition !== 'function') return;
                            let pos = element.tooltipPosition();
                            let x = pos.x;
                            let y = pos.y;
                            if ((chart.config.type === 'bar' || meta.type === 'bar') && element.base !== undefined) {
                                y = (element.base + pos.y) / 2;
                            }
                            ctx.strokeStyle = 'rgba(0, 0, 0, 0.75)';
                            ctx.lineWidth = 3;
                            ctx.strokeText(text, x, y);
                            ctx.fillStyle = '#ffffff';
                            ctx.fillText(text, x, y);
                        });
                    });
                    ctx.restore();
                }
            };
            Chart.register(offlineDataLabels);

            const ctx = document.getElementById('hrChart');
            if (!ctx) return;

            const labels = <?php echo $labels ?: '[]'; ?>;
            const values = <?php echo $data   ?: '[]'; ?>;

            // [NEW] Custom Color Palette Mapping - Edit hex codes here to customize colors
            const categoryColorMap = {
                '201 Files': '#4BC0C0',
                'Contract': '#36A2EB',
                'Valid ID': '#FFCE56',
                'Medical': '#9966FF',
                'Clearance': '#FF9F40',
                'Uncategorized': '#dc3545' // Keep Red for attention or change to any Hex
            };
            const defaultPalette = ['#4BC0C0', '#36A2EB', '#FFCE56', '#9966FF', '#FF9F40', '#FF6384'];

            window.hrChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Documents',
                        data: values,
                        backgroundColor: (ctx) => {
                            if (ctx.dataIndex != null) {
                                const lbl = ctx.chart.data.labels[ctx.dataIndex];
                                return categoryColorMap[lbl] || defaultPalette[ctx.dataIndex % defaultPalette.length];
                            }
                            return '#36A2EB';
                        },
                        borderRadius: 6,
                        barPercentage: 0.6, // Controls bar width (0.5 = thin, 0.9 = wide)
                        categoryPercentage: 1.0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: '#6c757d'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                color: '#6c757d'
                            },
                            grid: {
                                color: 'rgba(0,0,0,.05)'
                            }
                        }
                    },
                    onClick: (e, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = window.hrChartInstance.data.labels[index];
                            // [NEW] Redirect Uncategorized clicks directly to the Quick Fix tool in the tracker
                            if (label === 'Uncategorized' || label === 'Documents for Employee') {
                                window.location.href = 'tracker.php?report=quick_fix';
                            } else {
                                window.location.href = `index.php?doc_cat=${encodeURIComponent(label)}`;
                            }
                        }
                    },
                    onHover: (event, chartElement) => {
                        event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                    }
                }
            });

            // [NEW] Dark Mode Adapter for Chart
            function updateChartTheme() {
                const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                const textColor = isDark ? '#adb5bd' : '#6c757d';
                const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';

                if (window.hrChartInstance) {
                    window.hrChartInstance.options.scales.x.ticks.color = textColor;
                    window.hrChartInstance.options.scales.y.ticks.color = textColor;
                    window.hrChartInstance.options.scales.y.grid.color = gridColor;

                    if (window.hrChartInstance.options.plugins && window.hrChartInstance.options.plugins.legend) {
                        window.hrChartInstance.options.plugins.legend.labels = window.hrChartInstance.options.plugins.legend.labels || {};
                        window.hrChartInstance.options.plugins.legend.labels.color = textColor;
                    }
                    window.hrChartInstance.update();
                }
            }

            // Watch for theme changes
            new MutationObserver(updateChartTheme).observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-bs-theme']
            });
            updateChartTheme(); // Initial check
        });

        // ---------- Typeahead Suggestions (Directory Search) ----------
        (() => {
            const searchInput = document.getElementById('mainSearch');
            const suggestionBox = document.getElementById('suggestionBox');
            if (!searchInput || !suggestionBox) return;

            // [NEW] Recent Searches Data
            const recentSearches = <?php echo json_encode($recentSearches); ?>;

            function showRecent() {
                if (searchInput.value.trim() === '' && recentSearches.length > 0) {
                    suggestionBox.innerHTML = '<div class="list-group-item list-group-item-secondary small fw-bold text-muted"><i class="bi bi-clock-history me-1"></i> Recent Searches</div>';
                    recentSearches.forEach(term => {
                        const a = document.createElement('a');
                        a.href = `index.php?search=${encodeURIComponent(term)}`;
                        a.className = 'list-group-item list-group-item-action small';
                        a.textContent = term;
                        suggestionBox.appendChild(a);
                    });
                    suggestionBox.style.display = 'block';
                } else if (searchInput.value.trim() === '') {
                    suggestionBox.style.display = 'none';
                }
            }

            let debounceTimer = null;

            searchInput.addEventListener('focus', showRecent);

            searchInput.addEventListener('input', function() {
                const q = this.value.trim();
                if (q.length < 2) {
                    if (q.length === 0) showRecent();
                    else {
                        suggestionBox.innerHTML = '';
                        suggestionBox.style.display = 'none';
                    }
                    return;
                }
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
                        .then(r => r.json())
                        .then(data => {
                            suggestionBox.innerHTML = '';
                            if (Array.isArray(data) && data.length > 0) {
                                suggestionBox.style.display = 'block';
                                data.slice(0, 8).forEach(emp => {
                                    const a = document.createElement('a');
                                    a.href = `index.php?search=${encodeURIComponent(emp.emp_id)}`;
                                    a.className = 'list-group-item list-group-item-action d-flex align-items-center';

                                    // Create img element safely
                                    const img = document.createElement('img');
                                    img.src = `uploads/avatars/${(emp.avatar_path || 'default.png').replace(/[^a-zA-Z0-9._-]/g, '')}`;
                                    img.width = 30;
                                    img.height = 30;
                                    img.className = 'rounded-circle me-2';
                                    img.onerror = function() {
                                        this.onerror = null;
                                        this.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';
                                    };
                                    a.appendChild(img);

                                    // Create name/id div safely
                                    const div = document.createElement('div');
                                    const strong = document.createElement('strong');
                                    strong.textContent = (emp.first_name || '') + ' ' + (emp.last_name || '');
                                    const small = document.createElement('small');
                                    small.className = 'text-muted';
                                    small.textContent = emp.emp_id || '';
                                    const br = document.createElement('br');
                                    div.appendChild(strong);
                                    div.appendChild(br);
                                    div.appendChild(small);
                                    a.appendChild(div);

                                    suggestionBox.appendChild(a);
                                });
                            } else {
                                suggestionBox.style.display = 'none';
                            }
                        })
                        .catch(() => {});
                }, 180);
            });

            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
                    suggestionBox.style.display = 'none';
                }
            });
        })();

        // ---------- Export modal helpers (smart section filter + suggestions) ----------
        document.addEventListener('DOMContentLoaded', function() {
            const deptSelect = document.getElementById('exportDept');
            const sectSelect = document.getElementById('exportSection');
            if (deptSelect && sectSelect) {
                const groups = sectSelect.querySelectorAll('optgroup');
                deptSelect.addEventListener('change', function() {
                    const sel = this.value;
                    if (sel && sel !== 'ALL') {
                        sectSelect.disabled = false;
                        sectSelect.value = "";
                        groups.forEach(g => {
                            g.style.display = (g.label === sel) ? '' : 'none';
                        });
                    } else {
                        sectSelect.disabled = true;
                        sectSelect.value = "";
                        groups.forEach(g => {
                            g.style.display = 'none';
                        });
                    }
                });
                // init hide all grouped sections
                groups.forEach(g => {
                    g.style.display = 'none';
                });
            }

            const input = document.getElementById('exportSearch');
            const box = document.getElementById('exportSuggestionBox');
            if (input && box) {
                let timer;
                input.addEventListener('input', function() {
                    const q = this.value.trim();
                    if (q.length < 2) {
                        box.style.display = 'none';
                        return;
                    }
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
                            .then(r => r.json())
                            .then(data => {
                                box.innerHTML = '';
                                if (Array.isArray(data) && data.length > 0) {
                                    box.style.display = 'block';
                                    data.slice(0, 8).forEach(emp => {
                                        const item = document.createElement('a');
                                        item.className = 'list-group-item list-group-item-action';
                                        item.style.cursor = 'pointer';

                                        // Create name display safely using textContent
                                        const strong = document.createElement('strong');
                                        strong.textContent = (emp.first_name || '') + ' ' + (emp.last_name || '');
                                        const small = document.createElement('small');
                                        small.className = 'text-muted';
                                        small.textContent = emp.emp_id || '';

                                        item.appendChild(strong);
                                        item.appendChild(document.createTextNode(' '));
                                        item.appendChild(small);

                                        item.onclick = function() {
                                            input.value = emp.emp_id || '';
                                            box.style.display = 'none';
                                        };
                                        box.appendChild(item);
                                    });
                                } else {
                                    box.style.display = 'none';
                                }
                            })
                            .catch(() => {
                                box.style.display = 'none';
                            });
                    }, 200);
                });
                document.addEventListener('click', (e) => {
                    if (!input.contains(e.target) && !box.contains(e.target)) {
                        box.style.display = 'none';
                    }
                });
            }
        });

        // ---------- Document Preview ----------
        function showPreview(url, type, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '<div class="d-flex justify-content-center align-items-center h-100 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div> Loading...</div>';
            setTimeout(() => {
                container.innerHTML = '';
                if (type === 'pdf') {
                    const iframe = document.createElement('iframe');
                    iframe.src = url;
                    iframe.className = 'preview-iframe';
                    container.appendChild(iframe);
                } else {
                    const img = document.createElement('img');
                    img.src = url;
                    img.className = 'preview-img';
                    img.alt = 'Preview';
                    container.appendChild(img);
                }
            }, 200);
        }

        // ---------- Delete confirmation ----------
        function confirmDelete(uuid, empId) {
            document.getElementById('del_file_uuid').value = uuid;
            document.getElementById('del_emp_id').value = empId;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        // ---------- Resolve Modal ----------
        function openResolveModal(id, fileName, currentNote = '') {
            const idField = document.getElementById('res_doc_id');
            const nameField = document.getElementById('res_cat_name');
            const noteField = document.getElementById('res_note');
            const modalEl = document.getElementById('resolveModal');
            if (!idField || !nameField || !modalEl) return;

            idField.value = String(id);
            nameField.innerText = fileName;
            if (noteField) noteField.value = currentNote;
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }

        // [NEW] Show Export Loader
        function showExportLoader(form) {
            Swal.fire({
                title: 'Compiling Data...',
                html: `
                    <p class="text-muted small mb-3">Scanning files and building the ZIP archive. Please wait...</p>
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
            let attempts = 0;
            const maxAttempts = 300; // 5 minutes
            const checkCookie = setInterval(() => {
                attempts++;
                if (document.cookie.includes('downloadToken=' + csrf)) {
                    clearInterval(checkCookie);
                    Swal.close();
                    document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    return;
                }
                if (attempts >= maxAttempts) {
                    clearInterval(checkCookie);
                    Swal.close();
                    document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    Swal.fire({
                        icon: 'error',
                        title: 'Timeout',
                        text: 'The export did not start within a few minutes. Please try again or check your browser settings.'
                    });
                }
            }, 1000);
        }

        // ---------- Bulk Selection Helpers ----------
        const selectionStorageKey = 'hr201_selected_employees';
        let selectedEmployeeIds = new Set();

        function loadSelectedEmployees() {
            const stored = localStorage.getItem(selectionStorageKey);
            if (!stored) return;
            try {
                const ids = JSON.parse(stored);
                if (Array.isArray(ids)) {
                    selectedEmployeeIds = new Set(ids.map(id => String(id)).filter(id => id !== ''));
                }
            } catch (e) {
                selectedEmployeeIds = new Set();
            }
        }

        function saveSelectedEmployees() {
            localStorage.setItem(selectionStorageKey, JSON.stringify(Array.from(selectedEmployeeIds)));
        }

        function updateSelectionCount() {
            const selectedCount = document.getElementById('selectedCount');
            const bulkBtn = document.getElementById('bulkActionBtn');
            const clearBtn = document.getElementById('clearSelectionBtn');
            const count = selectedEmployeeIds.size;
            if (selectedCount) selectedCount.innerText = count;
            if (bulkBtn) bulkBtn.classList.toggle('d-none', count === 0);
            if (clearBtn) clearBtn.classList.toggle('d-none', count === 0);
            buildBulkIdsInputs();
        }

        function setEmployeeSelected(id, selected) {
            if (!id) return;
            if (selected) {
                selectedEmployeeIds.add(String(id));
            } else {
                selectedEmployeeIds.delete(String(id));
            }
            saveSelectedEmployees();
            updateSelectionCount();
        }

        function clearEmployeeSelection() {
            selectedEmployeeIds.clear();
            document.querySelectorAll('.emp-select-check').forEach(cb => cb.checked = false);
            saveSelectedEmployees();
            updateSelectionCount();
        }

        function syncSelectionCheckboxes() {
            document.querySelectorAll('.emp-select-check').forEach(cb => {
                cb.checked = selectedEmployeeIds.has(String(cb.value));
            });
            updateSelectionCount();
        }

        function toggleSelectAllEmployees() {
            const checkboxes = Array.from(document.querySelectorAll('.emp-select-check'));
            if (checkboxes.length === 0) return;
            const allChecked = checkboxes.every(cb => cb.checked);
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
                if (!cb.disabled) {
                    if (!allChecked) {
                        selectedEmployeeIds.add(String(cb.value));
                    } else {
                        selectedEmployeeIds.delete(String(cb.value));
                    }
                }
            });
            saveSelectedEmployees();
            updateSelectionCount();
        }

        function collectSelectedEmployeeIds() {
            return Array.from(selectedEmployeeIds);
        }

        function buildBulkIdsInputs() {
            const container = document.getElementById('bulkMoveIdsContainer');
            if (!container) return;
            container.innerHTML = '';
            collectSelectedEmployeeIds().forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ids[]';
                input.value = id;
                container.appendChild(input);
            });
            const modalCount = document.getElementById('modalSelectedCount');
            if (modalCount) modalCount.innerText = collectSelectedEmployeeIds().length;
        }

        /**
         * [FIX] Dynamic Section Filter for Bulk Modal
         */
        function updateBulkSections() {
            const deptSelect = document.getElementById('bulkTargetDept');
            const sectSelect = document.getElementById('bulkTargetSection');
            if (!deptSelect || !sectSelect) return;

            const selectedDept = deptSelect.value;
            sectSelect.innerHTML = '<option value="">-- All Sections --</option>';

            if (selectedDept && deptMapData[selectedDept]) {
                deptMapData[selectedDept].forEach(section => {
                    const opt = document.createElement('option');
                    opt.value = section;
                    opt.textContent = section;
                    sectSelect.appendChild(opt);
                });
            }
        }

        /**
         * [FIX] Confirmation popup that summarizes changes for safety
         */
        function confirmBulkAction() {
            const form = document.getElementById('bulkActionForm');
            const count = selectedEmployeeIds.size;

            const dept = document.getElementById('bulkTargetDept').value;
            const sect = document.getElementById('bulkTargetSection').value;
            const role = document.getElementById('bulkTargetRole').value;
            const agency = document.getElementById('bulkTargetAgency').value;
            const status = form.querySelector('select[name="target_status"]').value;

            if (!dept && !sect && !role && !agency && !status) {
                Swal.fire('No Changes', 'Please select at least one field to update.', 'info');
                return;
            }

            let summary = '<ul class="text-start small">';
            if (dept) summary += `<li>Department: <strong>${dept}</strong></li>`;
            if (sect) summary += `<li>Section: <strong>${sect}</strong></li>`;
            if (role) summary += `<li>System Role: <strong>${role}</strong></li>`;
            if (agency) summary += `<li>Agency: <strong>${agency}</strong></li>`;
            if (status) summary += `<li>Status: <strong>${status}</strong></li>`;
            summary += '</ul>';

            Swal.fire({
                title: `Update ${count} Employees?`,
                html: `<p>The following changes will be applied to all selected profiles:</p>${summary}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                confirmButtonText: 'Yes, Apply Changes'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadSelectedEmployees();
            syncSelectionCheckboxes();

            // [FIX] Ensure bulk modal checkbox list is built when opening
            const bulkModal = document.getElementById('bulkActionModal');
            if (bulkModal) {
                bulkModal.addEventListener('show.bs.modal', buildBulkIdsInputs);
            }
        });

        // Residual Spreadsheet Logic removed for stability.

        function switchView(mode) {
            const cardsView = document.getElementById('view-cards');
            const listView = document.getElementById('view-list');
            const btnCardsView = document.getElementById('btn-view-cards');
            const btnListView = document.getElementById('btn-view-list');
            const btnPrintList = document.getElementById('btn-print-list');

            if (mode === 'list') {
                if (cardsView) cardsView.style.display = 'none';
                if (listView) listView.style.display = 'block';
                if (btnPrintList) btnPrintList.classList.remove('d-none');
                if (btnListView) btnListView.classList.add('active');
                if (btnCardsView) btnCardsView.classList.remove('active');
                localStorage.setItem('hr_preferred_view', 'list');
            } else {
                if (cardsView) cardsView.style.display = 'block';
                if (listView) listView.style.display = 'none';
                if (btnPrintList) btnPrintList.classList.add('d-none');
                if (btnCardsView) btnCardsView.classList.add('active');
                if (btnListView) btnListView.classList.remove('active');
                localStorage.setItem('hr_preferred_view', 'cards');
            }
        }

        // [FIX] Global helper for HTML escaping
        const h = (str) => {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        };

        // --- AUTO-REFRESH SYSTEM ---
        let isPaused = false;

        function refreshSystem() {
            if (isPaused) return;
            const spinner = document.getElementById('sync-spinner');
            if (spinner) spinner.style.display = 'inline-block';

            fetch('api/get_updates.php?_=' + new Date().getTime())
                .then(response => response.json())
                .then(data => {
                    const notifBadge = document.getElementById('notifyBadge');
                    const notifList = document.getElementById('notifyList');
                    if (notifBadge) {
                        notifBadge.innerText = data.count;
                        const badgeClass = (data.msgCount > 0) ? 'bg-danger' : 'bg-warning text-dark';
                        notifBadge.className = `position-absolute top-0 start-100 translate-middle badge rounded-pill ${badgeClass}`;
                        notifBadge.style.display = (data.count > 0) ? '' : 'none';
                    }
                    if (notifList && data.html) notifList.innerHTML = data.html;
                    if (window.hrChartInstance && data.chartLabels && data.chartValues) {
                        window.hrChartInstance.data.labels = data.chartLabels;
                        window.hrChartInstance.data.datasets[0].data = data.chartValues;
                        window.hrChartInstance.update();
                    }
                })
                .catch(() => {})
                .finally(() => {
                    if (spinner) spinner.style.display = 'none';
                });
        }

        document.addEventListener("DOMContentLoaded", function() {
            // [FIX] Restore View Preference on Load
            const preferredView = localStorage.getItem('hr_preferred_view') || 'cards';
            switchView(preferredView);

            const toggleBtn = document.getElementById('refreshToggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    isPaused = !isPaused;
                    this.innerHTML = isPaused ? '<i class="bi bi-play-circle-fill text-warning"></i>' : '<i class="bi bi-pause-circle"></i>';
                    this.title = isPaused ? "Resume Dashboard Updates" : "Pause Dashboard Updates";
                    if (!isPaused) refreshSystem();
                });
            }
            setInterval(refreshSystem, <?php echo (int)$refreshInterval * 1000; ?>);

            refreshSystem(); // Run once on load
        });

        // --- SweetAlert2 for PHP Session Messages ---
        <?php if (!empty($_SESSION['backup_msg'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'System Update',
                text: <?= json_encode($_SESSION['backup_msg']) ?>,
                timer: 3000,
                showConfirmButton: false
            });
            <?php unset($_SESSION['backup_msg']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Action Failed',
                text: <?= json_encode($_SESSION['error']) ?>
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // [NEW] Handle URL Messages (Success/Error) on Page Load
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('msg')) {
            const msgText = urlParams.get('msg');
            const isError = msgText.toLowerCase().includes('error') || msgText.toLowerCase().includes('failed');
            Swal.fire({
                icon: isError ? 'error' : 'success',
                title: isError ? 'Action Failed' : 'Success',
                text: msgText,
                timer: isError ? undefined : 3000,
                showConfirmButton: isError
            });
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('msg');
                window.history.replaceState(null, null, url.toString());
            }
        }
        if (urlParams.has('error')) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: urlParams.get('error')
            });
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('error');
                window.history.replaceState(null, null, url.toString());
            }
        }

        // [NEW] Scroll Memory Logic
        const scrollKey = 'hr201_scroll_pos_' + window.location.pathname;
        window.addEventListener('beforeunload', () => {
            sessionStorage.setItem(scrollKey, window.scrollY);
        });

        const urlParamsForScroll = new URLSearchParams(window.location.search);
        // Restore scroll if a message, error, page change, or search was performed
        if (urlParamsForScroll.has('msg') || urlParamsForScroll.has('error') || urlParamsForScroll.has('page') || urlParamsForScroll.has('search') || urlParamsForScroll.has('doc_cat')) {
            const savedPos = sessionStorage.getItem(scrollKey);
            if (savedPos) window.scrollTo(0, parseInt(savedPos));
        }

        // Auto-adjust layout before printing
        window.addEventListener('beforeprint', function() {
            const tableResponsive = document.querySelector('.table-responsive');
            if (tableResponsive) {
                tableResponsive.style.overflowX = 'visible';
                tableResponsive.style.overflow = 'visible';
            }

            const table = document.querySelector('.table');
            if (table) {
                table.style.tableLayout = 'auto';
                table.style.width = '100%';
            }

            // Reduce container padding
            const container = document.querySelector('.container');
            if (container) {
                container.style.padding = '0 10mm';
            }
        });

        // Restore after printing
        window.addEventListener('afterprint', function() {
            const tableResponsive = document.querySelector('.table-responsive');
            if (tableResponsive) {
                tableResponsive.style.overflowX = 'auto';
            }
        });

        function optimizeForPrintThenPrint() {
            const tableResponsive = document.querySelector('.table-responsive');
            if (tableResponsive) {
                tableResponsive.style.overflowX = 'visible';
            }
            window.print();
        }
    </script>


    </body>

    </html>