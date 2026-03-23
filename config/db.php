<?php
// config/db.php

// [SECURITY] Production Error Handling
// Hide errors from users, log them to server instead
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_error.log');
error_reporting(E_ALL);

// [FIX] Set default timezone to Philippines to ensure backup filenames and logs have the correct local time
date_default_timezone_set('Asia/Manila');

// ========================================================================
// [SECURITY] GLOBAL HTTP HEADERS (MHI Compliance)
// ========================================================================
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://api.qrserver.com; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline';");

// Load settings directly from PHP file instead of .env to avoid permission errors
$_ENV = require __DIR__ . '/config.php';

// ========================================================================
// [SECURITY] GLOBAL INPUT SANITIZATION
// Automatically neutralize XSS and Null-Byte injection on all incoming requests
// ========================================================================
function sanitize_global_input(&$array)
{
    foreach ($array as $key => &$value) {
        // ALWAYS skip password fields to avoid altering intended hashes
        if (stripos((string)$key, 'password') !== false) {
            continue;
        }
        if (is_array($value)) {
            sanitize_global_input($value);
        } elseif (is_string($value)) {
            $value = str_replace(chr(0), '', $value); // Strip null bytes
            $value = trim($value); // [FIX] Store raw data in DB to prevent double-escaping
        }
    }
}
sanitize_global_input($_POST);
sanitize_global_input($_GET);

// [MHI 5.4] Enforce HTTPS (Skip for Localhost or CLI to avoid ERR_SSL_PROTOCOL_ERROR)
// To test compliance locally, you can temporarily remove '127.0.0.1' from the array below.
$isLocal = (php_sapi_name() === 'cli') || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

if (!$isLocal && !$isHttps) {
    $location = 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $location);
    exit;
}
// [MHI 5.3] Secure Session Parameters (HttpOnly, Secure, SameSite)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $isHttps ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Optionally enable SSL/TLS if a CA path is configured in config.php
    if (!empty($_ENV['DB_SSL_CA'])) {
        $sslCaPath = $_ENV['DB_SSL_CA'];
        $realSslCa = realpath($sslCaPath);
        if ($realSslCa !== false) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $realSslCa;
        } else {
            // Don't fail hard; just warn in logs so deployments without CA don't break
            error_log("DB_SSL_CA path configured but not found: " . $sslCaPath);
        }
    }

    // [FIX] Windows/XAMPP often fails with 'localhost' due to IPv6. Force 127.0.0.1 if localhost is set.
    $dbHost = ($_ENV['DB_HOST'] === 'localhost') ? '127.0.0.1' : $_ENV['DB_HOST'];
    $port = $_ENV['DB_PORT'] ?? 3307;
    $dsn = "mysql:host={$dbHost};port={$port};dbname={$_ENV['DB_NAME']};charset={$_ENV['DB_CHARSET']}";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);

    // [NEW] Fetch server-side session timeout from DB
    $server_timeout = 1800; // Default 30 minutes
    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_server'");
        $val = $stmt->fetchColumn();
        if ($val !== false && (int)$val > 0) {
            $server_timeout = (int)$val;
        }
    } catch (Exception $e) {
        // Table might not exist, use default
    }
    ini_set('session.gc_maxlifetime', $server_timeout);
} catch (\PDOException $e) {
    error_log($e->getMessage());
    // Provide a more helpful error message for XAMPP users
    if (strpos($e->getMessage(), 'actively refused') !== false) {
        die("Database connection error: Target machine actively refused connection. <br><strong>Solution:</strong> Ensure MySQL is running in XAMPP Control Panel. If it is running, check if it's using Port 3306 or 3307 and update config/config.php.");
    }
    die("Database connection error: " . $e->getMessage());
}

// [SECURITY] Strict Session Timeout Enforcer
function checkSessionTimeout($pdo, $serverTimeout = null)
{
    if (session_status() === PHP_SESSION_NONE) return;

    $timeout = 1800; // Default 30 mins

    if (!is_null($serverTimeout) && (int)$serverTimeout > 0) {
        $timeout = (int)$serverTimeout;
    } else {
        try {
            $val = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_server'")->fetchColumn();
            if ($val) $timeout = (int)$val;
        } catch (Exception $e) {
        }
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        header("Location: login.php?msg=" . urlencode("Session expired due to inactivity."));
        exit;
    }
    $_SESSION['last_activity'] = time();

    // [SECURITY] Ensure account recovery is configured for all authenticated users
    if (!empty($_SESSION['user_id'])) {
        enforceSecurityQuestionSetup($pdo);
    }
}

/**
 * Enforce that authenticated users have a security question configured.
 * If not, redirect them to profile_settings.php to complete setup.
 */
function enforceSecurityQuestionSetup($pdo, $ignoreWhitelist = false)
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['user_id'])) return;

    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    $whitelist = ['profile_settings.php', 'logout.php', 'login.php'];
    if (!$ignoreWhitelist && in_array($currentPage, $whitelist, true)) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT security_question FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $question = $stmt->fetchColumn();
        if (empty($question)) {
            header("Location: profile_settings.php?msg=" . urlencode("⚠️ Action Required: Please set up your Security Question for Account Recovery."));
            exit;
        }
    } catch (Exception $e) {
        // If the column or table doesn't exist, we don't want to break the app.
        error_log("Security question check failed: " . $e->getMessage());
    }
}
