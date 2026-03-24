<?php
// ======================================================
// TESP HR 201 System - Dashboard & Notification Center
// (Refactored with fixes, comments, and input length guards)
// ======================================================

// ---------- 1) SYSTEM IMPORTS, SECURITY, SESSION ----------
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';
require 'options.php'; // [NEW] Load dynamic options
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

checkSessionTimeout($pdo, $serverTimeout); // [SECURITY] Enforce Timeout

// Redirect guests to login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Normalize role to uppercase (handles 'hr', 'HR', etc.)
$userRole = isset($_SESSION['role']) ? strtoupper((string)$_SESSION['role']) : '';

$security = new Security($pdo);
$logger   = new Logger($pdo);

// CSRF token for forms on this page
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

// ---------- 2) AUTOMATED BACKUP SYSTEM (ADMIN only) ----------
// Fetches settings from DB and runs if today matches the scheduled day
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
    $primaryBackupPath = (!empty($customPath) && is_dir($customPath)) ? $customPath : realpath(__DIR__ . '/../backups');

    // Ensure primary path exists
    if ($primaryBackupPath && !is_dir($primaryBackupPath)) {
        @mkdir($primaryBackupPath, 0755, true);
    }

    // Check Schedule & Existence
    $todayStr = date('Y-m-d'); // e.g. 2023-10-27
    $todayDay = date('D');     // e.g. Fri

    // Look for ANY backup made today (zip or sql)
    $existingBackups = glob(rtrim($primaryBackupPath, '/\\') . '/AutoBackup_' . $todayStr . '*.*');

    if ($todayDay === $scheduleDay && empty($existingBackups)) {
        // [NEW] Check Time Requirement
        if (date('H:i') < $scheduleTime) {
            // Too early, skip backup for now
            goto skip_backup;
        }

        // START BACKUP PROCESS
        ini_set('memory_limit', '-1');
        set_time_limit(600); // 10 minutes

        $baseFilename = 'AutoBackup_' . date('Y-m-d_H-i-s');
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

            $zip->close();
            // Cleanup Temp File
            @unlink($tmpSqlFile);

            if (file_exists($zipFile)) {
                $logger->log($_SESSION['user_id'], 'AUTO_BACKUP', "Backup created: " . basename($zipFile));
                $_SESSION['backup_msg'] = "✅ Automated Backup Completed (" . basename($zipFile) . ")";

                // [NEW] Add to Notification Center (Bell Icon)
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'System Backup', ?, 'success')")
                    ->execute([$_SESSION['user_id'], "Automated backup created successfully: " . basename($zipFile)]);
            } else {
                // [NEW] Failure Alert
                if ($alertEmail) {
                    mail($alertEmail, "⚠️ HR System Backup Failed", "The automated backup process failed to create the ZIP file on server.\n\nTime: " . date('Y-m-d H:i:s'));
                }
                $logger->log($_SESSION['user_id'], 'AUTO_BACKUP_FAIL', "Backup failed: ZIP file not created.");
            }
        } else {
            if ($alertEmail) {
                mail($alertEmail, "⚠️ HR System Backup Failed", "Could not open/create ZIP archive.\n\nTime: " . date('Y-m-d H:i:s'));
            }
        }
    }
    skip_backup:
}

// ---------- 3) HELPERS ----------
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Get and sanitize GET param with a max length (prevents oversized values)
 */
function getQueryParamSafe(string $key, int $maxLen = 100, $default = ''): string
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
function keepQuery(array $override = []): string
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
        'system_diagnostics.php',
        'qa_test.php',
        'process_approval.php',
        'process_edit_employee.php',
        'process_add_employee.php'
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

// (Source 1) User-specific DB notifications
$notifStmt = $pdo->prepare("
    SELECT id, title, message, type, created_at, 'db_msg' as source, NULL as link_id
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$notifStmt->execute([$_SESSION['user_id']]);
$db_notifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// (Source 2) Expiring docs within next 30 days (unresolved)
$alertDate = date('Y-m-d', strtotime('+30 days'));
$docQuery  = "
    SELECT d.id, d.original_name, d.expiry_date, e.emp_id AS real_emp_id
    FROM documents d
    JOIN employees e ON d.employee_id = e.emp_id
    WHERE d.is_resolved = 0
      AND d.expiry_date IS NOT NULL
      AND d.expiry_date <= ?
";
if ($hasDeletedAtColumn) {
    $docQuery .= " AND d.deleted_at IS NULL";
}
if (!in_array($userRole, ['ADMIN', 'HR'], true)) {
    // scope to files uploaded by current user
    $docQuery .= " AND d.uploaded_by = " . (int)$_SESSION['user_id'];
}
$notifyStmt = $pdo->prepare($docQuery);
$notifyStmt->execute([$alertDate]);
$raw_alerts = $notifyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$doc_alerts = [];
foreach ($raw_alerts as $d) {
    $daysLeft = (int)floor((strtotime($d['expiry_date']) - time()) / 86400);
    $status   = ($daysLeft < 0) ? 'EXPIRED' : ($daysLeft . ' days left');
    $doc_alerts[] = [
        'id'         => 'doc_' . $d['id'],
        'title'      => "Document Expiring: {$status}",
        'message'    => 'File: ' . $d['original_name'],
        'type'       => 'warning',
        'created_at' => date('Y-m-d H:i:s'),
        'source'     => 'expiry',
        'link_id'    => $d['id'],
        'doc_name'   => $d['original_name'],
        'emp_search' => $d['real_emp_id']
    ];
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
    if ($filter_doc_cat === 'Documents for Employee') {
        $where[] = 'emp_id IN (SELECT employee_id FROM documents WHERE category IS NULL OR TRIM(category) = \'\')';
    } else {
        $where[] = 'emp_id IN (SELECT employee_id FROM documents WHERE category = ?)';
        $params[] = $filter_doc_cat;
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
$totalRows  = (int)$countStmt->fetchColumn();
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
$statsSql = "
    SELECT COALESCE(NULLIF(TRIM(category), ''), 'Documents for Employee'), COUNT(*) 
    FROM documents";
if ($hasDeletedAtColumn) {
    $statsSql .= " WHERE deleted_at IS NULL";
}
$statsSql .= " GROUP BY 1";
$statsQuery = $pdo->query($statsSql);
$stats = $statsQuery->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
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
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TESP HR 201 System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="assets/images/tesp-logo.png" type="image/png"> <!-- Single includes only -->
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
    <script src="assets/chart.min.js" defer></script>
    <script src="assets/sweetalert2.all.min.js"></script>
    <style>
        :root {
            --bg: #f4f6f9;
            --card-border: #e9ecef;
            --accent: #2a5298;
        }

        [data-bs-theme=dark] {
            --bg: #212529;
            --card-border: #495057;
            --accent: #6ea8fe;
        }

        body {
            background: var(--bg);
        }

        .container {
            max-width: 1200px;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: .2px;
        }

        .shadow-soft {
            box-shadow: 0 10px 30px rgba(0, 0, 0, .05);
        }

        .avatar-circle {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 6px 12px rgba(0, 0, 0, .12);
            background: #fff;
        }

        .employee-card {
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease;
            border: 1px solid var(--card-border);
        }

        .employee-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, .08);
        }

        .status-active {
            border-top: 6px solid #198754;
        }

        .status-agency {
            border-top: 6px solid #ffc107;
        }

        .status-sick {
            border-top: 6px solid #dc3545;
        }

        .status-terminated {
            border-top: 6px solid #000;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, #1e3c72 0%, var(--accent) 100%);
            color: #fff;
        }

        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: .8rem;
            text-transform: uppercase;
        }

        .preview-box {
            height: 520px;
            border: 2px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: .5rem;
            color: #6c757d;
        }

        .preview-iframe {
            width: 100%;
            height: 100%;
            border: 0;
            border-radius: .5rem;
        }

        .preview-img {
            max-width: 100%;
            max-height: 100%;
            border-radius: .5rem;
        }

        .page-link {
            border-radius: .4rem;
        }

        .dropdown-menu {
            border-radius: .75rem;
        }

        .card {
            border-radius: .75rem;
        }

        .white-space-normal {
            white-space: normal;
        }

        .extra-small {
            font-size: .75rem;
        }

        .highlight-target {
            background: #fff3cd !important;
            border-color: #ffecb5 !important;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 px-3">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php"><i class="bi bi-building"></i> TES Philippines HR</a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarContent">
                <div class="d-flex align-items-center ms-auto mt-3 mt-lg-0">
                    <!-- Session Timer -->
                    <div class="text-white me-3 small d-none d-md-block" title="Time until auto-logout">
                        <i class="bi bi-hourglass-split"></i> <span id="sessionTimer" class="fw-bold font-monospace"><?php echo floor($clientTimeout / 60) . ':' . str_pad($clientTimeout % 60, 2, '0', STR_PAD_LEFT); ?></span>
                    </div>

                    <!-- Auto-Refresh Toggle -->
                    <button id="refreshToggle" class="btn btn-sm btn-outline-light me-3 border-0" title="Pause Dashboard Updates">
                        <i class="bi bi-pause-circle"></i>
                    </button>

                    <!-- Dark Mode Toggle -->
                    <button id="darkModeToggle" class="btn btn-sm btn-outline-light me-3 border-0" title="Toggle Dark Mode">
                        <i class="bi bi-moon-stars-fill"></i>
                    </button>

                    <!-- [NEW] Sync Spinner -->
                    <div id="sync-spinner" class="spinner-border spinner-border-sm text-warning me-3" role="status" style="display:none;" title="Syncing Data...">
                        <span class="visually-hidden">Loading...</span>
                    </div>

                    <!-- Notifications dropdown -->
                    <div class="dropdown me-3">
                        <a class="text-white position-relative" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell-fill fs-5"></i>
                            <?php if ($notifCount > 0): ?>
                                <?php
                                // [UX] Color code the badge: Red for new messages, Yellow for pending actions only
                                $badgeClass = ($msgCount > 0) ? 'bg-danger' : 'bg-warning text-dark';
                                ?>
                                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill <?php echo $badgeClass; ?>">
                                    <?php echo $notifCount; ?>
                                </span>
                            <?php else: ?>
                                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none">0</span>
                            <?php endif; ?>
                        </a>

                        <ul id="notifyList" class="dropdown-menu dropdown-menu-end shadow" style="width: 350px; max-height: 400px; overflow-y: auto;">
                            <li class="dropdown-header d-flex justify-content-between align-items-center">
                                <span>Notifications</span>
                                <?php if (count($db_notifs) > 0): ?>
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                        <button name="clear_notifs" class="btn btn-link btn-sm text-decoration-none p-0" style="font-size: 0.8rem;">Clear Read</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                            <?php if (count($doc_alerts) > 0): ?>
                                <li class="bg-light p-2 text-center small fw-bold text-danger border-bottom border-top">
                                    <i class="bi bi-exclamation-circle-fill"></i> Action Required (<?php echo count($doc_alerts); ?>)
                                </li>
                            <?php endif; ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>

                            <?php if ($notifCount > 0): ?>
                                <?php foreach ($all_notifications as $n): ?>
                                    <?php
                                    $backupFile = null;
                                    if (($n['source'] ?? '') === 'expiry') {
                                        $icon = "bi-exclamation-triangle-fill text-warning";
                                        $link = "index.php?search=" . urlencode($n['emp_search']) . "&resolve_doc=" . urlencode((string)$n['link_id']) . "&doc_name=" . urlencode($n['doc_name']);
                                        $clickableClass = "list-group-item-action";
                                    } elseif (($n['source'] ?? '') === 'request') {
                                        $icon = "bi-clipboard-data-fill text-primary";
                                        $link = "admin_approval.php";
                                        $clickableClass = "list-group-item-action";
                                    } elseif (($n['type'] ?? '') === 'success') {
                                        $icon = "bi-check-circle-fill text-success";
                                        $link = "#";
                                        $clickableClass = "";

                                        // [NEW] Check for Backup Notification to add Restore Button
                                        if (strpos($n['title'], 'Backup') !== false && preg_match('/:\s*([a-zA-Z0-9_\-\.]+\.zip)/', $n['message'], $matches)) {
                                            $backupFile = $matches[1];
                                        }
                                    } else {
                                        $icon = "bi-info-circle-fill text-info";
                                        $link = "#";
                                        $clickableClass = "";
                                    }
                                    ?>
                                    <li>
                                        <div class="dropdown-item white-space-normal <?php echo $clickableClass; ?>">
                                            <div class="d-flex align-items-start">
                                                <i class="bi <?php echo $icon; ?> fs-4 me-2"></i>
                                                <div class="w-100">
                                                    <h6 class="mb-0 small fw-bold"><?php echo h($n['title']); ?></h6>
                                                    <p class="mb-1 small text-muted" style="font-size: 0.85rem;"><?php echo h($n['message']); ?></p>
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-secondary" style="font-size: 0.7rem;">
                                                            <?php echo (($n['source'] ?? '') === 'expiry') ? 'Action Required' : date('M d, h:i A', strtotime($n['created_at'])); ?>
                                                        </small>
                                                        <?php if ($backupFile && $userRole === 'ADMIN'): ?>
                                                            <a href="manager_user.php?restore_target=<?php echo urlencode($backupFile); ?>" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.7rem;">
                                                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="p-3 text-center text-muted"><small>No new notifications</small></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- User Menu -->
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                            <strong><?php echo h($_SESSION['username'] ?? 'User'); ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <?php if (in_array($userRole, ['ADMIN', 'MANAGER'])): ?>
                                <li><a class="dropdown-item fw-bold text-primary" href="manager_dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Manager Dashboard</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                            <?php endif; ?>
                            <?php if ($userRole === 'ADMIN'): ?>
                                <li><a class="dropdown-item" href="settings.php"><i class="bi bi-sliders me-2"></i> System Settings</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="profile_settings.php"><i class="bi bi-gear me-2"></i> Change Password</a></li>
                            <?php if ($userRole === 'ADMIN'): ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="manager_user.php"><i class="bi bi-people-fill me-2"></i> Manage Users</a></li>
                                <li><a class="dropdown-item" href="access_review.php"><i class="bi bi-shield-check me-2"></i> Access Reviews</a></li>
                            <?php endif; ?>
                            <?php if (in_array($userRole, ['ADMIN', 'MANAGER'])): ?>
                                <li><a class="dropdown-item" href="activity_logs.php"><i class="bi bi-shield-lock-fill me-2 text-danger"></i> Activity Logs</a></li>
                            <?php endif; ?>
                            <?php if ($userRole === 'STAFF'): ?>
                                <li><a class="dropdown-item" href="my_requests.php"><i class="bi bi-clock-history me-2 text-primary"></i> My Requests</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle-fill me-2 text-info"></i> User Manual</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

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
                'system_diagnostics.php' => 'System diagnostics tool',
                'process_approval.php' => 'Legacy deprecated script',
                'process_edit_employee.php' => 'Legacy deprecated script',
                'process_add_employee.php' => 'Legacy deprecated script'
            ];

            $foundRisks = [];
            foreach ($riskFiles as $file => $desc) {
                if (file_exists($file)) {
                    $foundRisks[] = "<strong>$file</strong>: $desc";
                }
            }
            if (!empty($foundRisks)):
        ?>
                <div class="alert alert-danger shadow-sm fw-bold mb-4 border-danger border-3">
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
            <div class="alert alert-danger shadow-sm fw-bold mb-4 border-danger border-3">
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
                <div class="alert alert-warning shadow-sm fw-bold d-flex align-items-center mb-4">
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
            <div class="alert alert-danger shadow-sm fw-bold d-flex align-items-center mb-4 border-danger border-3">
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
            <div class="alert alert-info shadow-sm fw-bold d-flex align-items-center mb-4 border-info border-3">
                <i class="bi bi-info-circle-fill fs-3 me-3 text-info"></i>
                <div>
                    <h5 class="mb-0 text-info">Security Notice: Password Reset</h5>
                    <span class="small fw-normal text-dark">Your password was recently reset by an Administrator on <strong><?php echo date('F j, Y, g:i a', strtotime($lastAdminReset)); ?></strong>. If you did not request this, please <a href="profile_settings.php" class="alert-link text-decoration-underline">change your password</a> immediately to secure your account.</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- [ADMIN] System Health & Server Config (Professional View) -->
        <?php if ($userRole === 'ADMIN'): ?>
            <div class="card shadow-sm mb-4 border-info">
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
            <div class="alert alert-danger shadow-sm fw-bold d-flex align-items-center mb-4">
                <i class="bi bi-hdd-fill fs-4 me-3"></i>
                <div>
                    <strong>Server Load High!</strong> Disk usage is at <?php echo $diskPercent; ?>%.
                    <br><span class="small fw-normal">Please clear old files or backups immediately to prevent system failure.</span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($vaultLimitGB > 0 && $vaultQuotaPercent >= 90): ?>
            <div class="alert alert-warning shadow-sm fw-bold d-flex align-items-center mb-4 border-warning border-3">
                <i class="bi bi-hdd-network fs-3 me-3 text-warning"></i>
                <div>
                    <strong>Vault Storage Warning:</strong> Your document vault is at <strong><?php echo $vaultQuotaPercent; ?>%</strong> capacity (<?php echo number_format($currentVaultGB, 2); ?> GB / <?php echo number_format($vaultLimitGB, 2); ?> GB).
                    <br><span class="small fw-normal">Please use the <strong>Storage Optimization</strong> tool in the Recovery Console to archive old files, or increase your limit in <a href="settings.php" class="alert-link">Settings</a>.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
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
            <div class="col-lg-4">
                <div class="card h-100 shadow-soft">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-lightning-charge-fill me-2 text-warning"></i>
                        <span class="fw-semibold">Actions</span>
                    </div>
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
        <div class="card mb-4 shadow-soft" id="directory-search-bar">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted mb-0"><i class="bi bi-funnel-fill"></i> Directory Search</h5>
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

        <!-- Results -->
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
                $files      = $filesByEmp[$emp['emp_id']] ?? [];
                $modalId    = 'viewModal' . (int)$emp['id'];
                $previewBoxId = 'preview-' . (int)$emp['id'];

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

                    <!-- EMPLOYEE MODAL -->
                    <div class="modal fade" id="<?php echo h($modalId); ?>" tabindex="-1" aria-hidden="true" data-emp-id-str="<?php echo h($emp['emp_id']); ?>">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header modal-header-custom p-4">
                                    <div class="d-flex align-items-center w-100">
                                        <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>" class="rounded-circle border border-3 border-white shadow-sm" width="100" height="100" onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';" alt="Avatar">
                                        <div class="ms-3 flex-grow-1">
                                            <h3 class="mb-0 fw-bold"><?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h3>
                                            <div class="badge bg-light text-dark mt-1"><?php echo h($emp['emp_id']); ?></div>
                                            <div class="badge bg-white text-dark mt-1"><?php echo h($emp['job_title']); ?></div>

                                            <?php if ($isRecentlyUpdated): ?>
                                                <span class="badge bg-info text-dark mt-1"><i class="bi bi-stars"></i> Recently Updated</span>
                                            <?php endif; ?>
                                            <?php if (!$isComplete): ?>
                                                <span class="badge bg-warning text-dark mt-1" title="Missing: <?php echo htmlspecialchars(count($missingFields)); ?> fields"><i class="bi bi-exclamation-triangle"></i> Incomplete Profile</span>
                                            <?php else: ?>
                                                <span class="badge bg-success mt-1"><i class="bi bi-check-circle"></i> Profile Complete</span>
                                            <?php endif; ?>
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
                                                    <div class="col-6"><span class="info-label">Department:</span><br><span class="fw-medium"><?php echo h($emp['dept']); ?></span></div>
                                                    <div class="col-6"><span class="info-label">Section:</span><br><span class="fw-medium"><?php echo h($emp['section']); ?></span></div>
                                                    <div class="col-6"><span class="info-label">Employment Type:</span><br><span class="fw-medium"><?php echo h($emp['employment_type']); ?></span></div>
                                                    <div class="col-6"><span class="info-label">Date Hired:</span><br><span class="fw-medium"><?php echo h($emp['hire_date'] ? date('M d, Y', strtotime($emp['hire_date'])) : 'Not specified'); ?></span></div>
                                                </div>

                                                <h6 class="text-primary fw-bold mb-3 border-bottom pb-2"><i class="bi bi-person-lines-fill"></i> Personal Details</h6>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-6"><span class="info-label">Contact:</span><br><span class="fw-medium"><?php echo h($emp['contact_number']); ?></span></div>
                                                    <div class="col-6"><span class="info-label">Email:</span><br><span class="fw-medium"><?php echo h($emp['email'] ?: 'N/A'); ?></span></div>
                                                    <div class="col-12"><span class="info-label">Present Address:</span><br><span class="fw-medium"><?php echo h($emp['present_address']); ?></span></div>
                                                </div>

                                                <h6 class="text-primary fw-bold mb-3 border-bottom pb-2"><i class="bi bi-card-checklist"></i> Government IDs</h6>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-6"><span class="info-label">SSS No:</span><br><span class="fw-medium font-monospace"><?php echo h($emp['sss_no'] ?: 'N/A'); ?></span></div>
                                                    <div class="col-6"><span class="info-label">TIN:</span><br><span class="fw-medium font-monospace"><?php echo h($emp['tin_no'] ?: 'N/A'); ?></span></div>
                                                    <div class="col-6"><span class="info-label">PhilHealth:</span><br><span class="fw-medium font-monospace"><?php echo h($emp['philhealth_no'] ?: 'N/A'); ?></span></div>
                                                    <div class="col-6"><span class="info-label">Pag-IBIG:</span><br><span class="fw-medium font-monospace"><?php echo h($emp['pagibig_no'] ?: 'N/A'); ?></span></div>
                                                </div>

                                                <h6 class="text-primary fw-bold mb-3 border-bottom pb-2"><i class="bi bi-mortarboard"></i> Qualifications</h6>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-12"><span class="info-label">Education:</span><br><span class="fw-medium"><?php echo !empty($emp['education']) ? nl2br(h($emp['education'])) : '<span class="text-muted fst-italic">Not specified</span>'; ?></span></div>
                                                    <div class="col-12"><span class="info-label">Experience:</span><br><span class="fw-medium"><?php echo !empty($emp['experience']) ? nl2br(h($emp['experience'])) : '<span class="text-muted fst-italic">Not specified</span>'; ?></span></div>
                                                    <div class="col-12"><span class="info-label">Licenses / Certifications:</span><br><span class="fw-medium"><?php echo !empty($emp['licenses']) ? nl2br(h($emp['licenses'])) : '<span class="text-muted fst-italic">Not specified</span>'; ?></span></div>
                                                </div>

                                                <h6 class="text-danger fw-bold mb-3 border-bottom pb-2"><i class="bi bi-heart-pulse"></i> Emergency Contact</h6>
                                                <div class="row g-3">
                                                    <div class="col-6"><span class="info-label">Name:</span><br><span class="fw-medium"><?php echo h($emp['emergency_name'] ?: 'N/A'); ?></span></div>
                                                    <div class="col-6"><span class="info-label">Contact No:</span><br><span class="fw-medium"><?php echo h($emp['emergency_contact'] ?: 'N/A'); ?></span></div>
                                                </div>
                                            </div>
                                            <div class="tab-pane fade" id="files-<?php echo (int)$emp['id']; ?>">
                                                <div class="row h-100">
                                                    <div class="col-4 border-end">
                                                        <div class="d-grid gap-2 mb-3">
                                                            <a href="upload_form.php?emp_id=<?php echo h($emp['emp_id']); ?>" class="btn btn-primary btn-sm">
                                                                <i class="bi bi-cloud-arrow-up-fill"></i> Upload New File
                                                            </a>
                                                        </div>
                                                        <div class="list-group">
                                                            <?php foreach ($files as $file):
                                                                $previewUrl     = "view_doc.php?id=" . $file['file_uuid'] . "&embed=1";
                                                                $type           = (stripos($file['original_name'], '.pdf') !== false) ? 'pdf' : 'img';
                                                                $previewTarget  = 'preview-' . (int)$emp['id'];
                                                                $isTarget       = ($targetDocId !== '' && (string)$targetDocId === (string)$file['id']);
                                                                $rowClass       = $isTarget ? 'highlight-target' : '';
                                                            ?>
                                                                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-2 <?php echo $rowClass; ?>">
                                                                    <a href="javascript:void(0);" class="text-decoration-none text-body text-truncate w-75"
                                                                        onclick="showPreview('<?php echo h($previewUrl); ?>', '<?php echo h($type); ?>', '<?php echo h($previewTarget); ?>'); return false;">
                                                                        <?php if ($isTarget): ?>
                                                                            <span class="badge bg-danger me-1"><i class="bi bi-exclamation-triangle-fill"></i> ACTION REQUIRED</span>
                                                                        <?php endif; ?>
                                                                        <strong><?php echo h($file['original_name']); ?></strong><br>
                                                                        <small class="text-secondary"><?php echo h($file['category']); ?></small>
                                                                        <?php if (!empty($file['is_resolved']) && !empty($file['resolution_note'])): ?>
                                                                            <br><span class="badge bg-success mt-1" style="font-size: 0.70rem; white-space: normal; cursor: pointer;" title="Edit Resolution Note" onclick="event.stopPropagation(); openResolveModal(<?php echo (int)$file['id']; ?>, <?php echo htmlspecialchars(json_encode($file['original_name']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($file['resolution_note']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="bi bi-check-circle-fill"></i> Resolved: <?php echo h($file['resolution_note']); ?> <i class="bi bi-pencil ms-1"></i></span>
                                                                        <?php endif; ?>
                                                                    </a>

                                                                    <?php if ((int)$file['is_resolved'] === 0 && !empty($file['expiry_date']) && $file['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))): ?>
                                                                        <button class="btn btn-warning btn-sm ms-2 shadow-sm"
                                                                            title="Fix Issue"
                                                                            onclick="event.stopPropagation(); openResolveModal(<?php echo (int)$file['id']; ?>, <?php echo htmlspecialchars(json_encode($file['original_name']), ENT_QUOTES, 'UTF-8'); ?>)">
                                                                            <i class="bi bi-wrench-adjustable-circle-fill"></i> Fix
                                                                        </button>
                                                                    <?php endif; ?>

                                                                    <a href="view_doc.php?id=<?php echo $file['file_uuid']; ?>&download=1" class="btn btn-sm btn-outline-primary border-0 ms-1" title="Download">
                                                                        <i class="bi bi-download"></i>
                                                                    </a>

                                                                    <?php if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)): ?>
                                                                        <button type="button" class="btn btn-sm btn-outline-danger border-0"
                                                                            onclick="confirmDelete(<?php echo htmlspecialchars(json_encode($file['file_uuid']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($emp['emp_id']), ENT_QUOTES, 'UTF-8'); ?>)"
                                                                            title="Delete File">
                                                                            <i class="bi bi-trash"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-8">
                                                        <div id="<?php echo h($previewBoxId); ?>" class="preview-box">Select a file to preview</div>
                                                    </div>
                                                </div>
                                            </div> <!-- /tab -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div> <!-- /modal -->
                </div>
            <?php endforeach; ?>
        </div>

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

    </div>

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

    <!-- SINGLE Bootstrap bundle include -->
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>

    <script>
        // ---------- Chart ----------
        document.addEventListener('DOMContentLoaded', () => {
            const ctx = document.getElementById('hrChart');
            if (!ctx) return;

            const labels = <?php echo $labels ?: '[]'; ?>;
            const values = <?php echo $data   ?: '[]'; ?>;

            window.hrChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Documents',
                        data: values,
                        backgroundColor: (ctx) => {
                            const palette = ['#4BC0C0', '#36A2EB', '#FFCE56', '#9966FF', '#FF9F40', '#FF6384'];
                            if (ctx.dataIndex != null) {
                                // Access current labels dynamically to support live updates
                                const lbl = ctx.chart.data.labels[ctx.dataIndex];
                                if (lbl === 'Documents for Employee') return '#dc3545'; // Distinct Red
                                return palette[ctx.dataIndex % palette.length];
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
                            window.location.href = `index.php?doc_cat=${encodeURIComponent(label)}`;
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

        // ---------- Prevent "stuck" screen with nested modals ----------
        document.addEventListener('hidden.bs.modal', function() {
            const anyOpen = document.querySelectorAll('.modal.show').length > 0;
            if (anyOpen) {
                document.body.classList.add('modal-open');
            } else {
                document.body.classList.remove('modal-open');
            }
        });

        // ---------- Auto-open target modal from notification & restore list on cancel ----------
        document.addEventListener('DOMContentLoaded', function() {

            // [NEW] Prevent page from jumping to top when filtering
            if (window.location.search && !window.location.hash && !window.location.search.includes('msg=')) {
                const searchBar = document.getElementById('directory-search-bar');
                if (searchBar) {
                    setTimeout(() => {
                        searchBar.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }, 100);
                }
            }

            const params = new URLSearchParams(window.location.search);
            const targetDoc = params.get('resolve_doc');
            const targetEmp = params.get('search');

            if (targetDoc && targetEmp) {
                // Find the modal for the targeted employee on this page
                const modalEl = document.querySelector(`.modal[data-emp-id-str="${CSS.escape(targetEmp)}"]`);
                if (modalEl) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();

                    // When user closes the modal, go back to full list (no ?search=)
                    modalEl.addEventListener('hidden.bs.modal', function onHide() {
                        modalEl.removeEventListener('hidden.bs.modal', onHide);
                        window.location.href = 'index.php';
                    }, {
                        once: true
                    });
                }

                // Clean noisy params from URL immediately to avoid refresh issues
                const cleanUrl = window.location.pathname; // no query
                window.history.replaceState({}, document.title, cleanUrl);
            }
        });

        // ---------- [SECURITY] AUTO-LOGOUT (Client-Side) ----------
        const INACTIVITY_LIMIT_MS = <?php echo $clientTimeout * 1000; ?>;
        let remainingMs = INACTIVITY_LIMIT_MS; // Dynamic value in milliseconds

        function updateTimer() {
            remainingMs -= 1000;

            if (remainingMs <= 0) {
                window.location.href = 'logout.php?msg=Session_Expired_Auto';
                return;
            }

            // Format MM:SS
            const totalSeconds = Math.floor(remainingMs / 1000);
            const m = Math.floor(totalSeconds / 60);
            const s = totalSeconds % 60;
            const text = `${m}:${s.toString().padStart(2, '0')}`;

            const timerEl = document.getElementById('sessionTimer');
            if (timerEl) {
                timerEl.innerText = text;
                // Turn red if < 2 mins
                if (remainingMs < 120000) timerEl.classList.add('text-danger');
                else timerEl.classList.remove('text-danger');
            }
        }

        function resetTimer() {
            remainingMs = INACTIVITY_LIMIT_MS;
        }

        // Start loop & Listeners
        setInterval(updateTimer, 1000);
        window.onload = resetTimer;
        document.addEventListener('mousemove', resetTimer);
        document.addEventListener('keydown', resetTimer);
        document.addEventListener('click', resetTimer);
        document.addEventListener('scroll', resetTimer);
    </script>

    <!-- SweetAlert2 for PHP Session Messages -->
    <script>
        <?php if (!empty($_SESSION['backup_msg'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'System Update',
                text: '<?php echo h($_SESSION['backup_msg']); ?>',
                timer: 3000,
                showConfirmButton: false
            });
            // Clean URL
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('backup_msg'); // Assuming session param mapped to url sometimes
                // If using pure session, this might be redundant but safe
            }
            <?php unset($_SESSION['backup_msg']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Action Failed',
                text: '<?php echo h($_SESSION['error']); ?>'
            });
            // Clean URL
            if (window.history.replaceState) {
                // Session error usually doesn't need URL clean, but if it did:
            }
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </script>


    <!-- ---------- AUTO-REFRESH SYSTEM (Notifications + Dashboard Numbers) ---------- -->

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            let isPaused = false;
            const toggleBtn = document.getElementById('refreshToggle');

            function refreshSystem() {
                if (isPaused) return;

                // Show spinner
                const spinner = document.getElementById('sync-spinner');
                if (spinner) spinner.style.display = 'inline-block';

                // [FIX] Add timestamp to prevent browser caching of old numbers
                fetch('api/get_updates.php?_=' + new Date().getTime())
                    .then(response => response.json())
                    .then(data => {
                        // 1. Update Notifications (Your Existing Feature)
                        const notifBadge = document.getElementById('notifyBadge');
                        const notifList = document.getElementById('notifyList');

                        if (notifBadge) {
                            notifBadge.innerText = data.count;
                            // [UX] Update Badge Color: Red if messages exist, Yellow if only actions
                            const badgeClass = (data.msgCount > 0) ? 'bg-danger' : 'bg-warning text-dark';
                            notifBadge.className = `position-absolute top-0 start-100 translate-middle badge rounded-pill ${badgeClass}`;
                            notifBadge.style.display = (data.count > 0) ? '' : 'none';
                        }

                        // [FIX] Update the dropdown list content (This makes the 'Clear Read' button appear dynamically)
                        if (notifList && data.html) {
                            notifList.innerHTML = data.html;
                        }

                        // 2. Update Chart (Live Animation)
                        if (window.hrChartInstance && data.chartLabels && data.chartValues) {
                            window.hrChartInstance.data.labels = data.chartLabels;
                            window.hrChartInstance.data.datasets[0].data = data.chartValues;
                            window.hrChartInstance.update();
                        }
                    })
                    .catch(err => console.log('Syncing...'))
                    .finally(() => {
                        // Hide spinner
                        if (spinner) spinner.style.display = 'none';
                    });
            }

            // Toggle Logic
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    isPaused = !isPaused;
                    if (isPaused) {
                        this.innerHTML = '<i class="bi bi-play-circle-fill text-warning"></i>';
                        this.title = "Resume Dashboard Updates";
                    } else {
                        this.innerHTML = '<i class="bi bi-pause-circle"></i>';
                        this.title = "Pause Dashboard Updates";
                        refreshSystem(); // Trigger immediately
                    }
                });
            }

            // Run based on settings (Default 60s)
            setInterval(refreshSystem, <?php echo $refreshInterval * 1000; ?>);
            refreshSystem(); // Run once on load
        });
    </script>




</body>

</html>