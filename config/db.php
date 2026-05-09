<?php
//// ---------- 1) ERROR HANDLING & HEADERS ----------// config/db.php
// [SECURITY] Production Error Handling
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php_error.log');
error_reporting(E_ALL);

// [FIX] Set default timezone to Philippines to ensure backup filenames and logs have the correct local time
date_default_timezone_set('Asia/Manila');

// Used if you later implement CSP headers in HTML templates
$cspNonce = bin2hex(random_bytes(16));

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ---------- 2) ENVIRONMENT LOAD ----------
// Load settings directly from PHP file instead of .env to avoid permission errors
$configPath = __DIR__ . '/config.php';
$_ENV = file_exists($configPath) ? require $configPath : [];

// ---------- 3) GLOBAL INPUT SANITIZATION ----------
// ✅ FIX for Intelephense P1132: add param type + return type
function sanitize_global_input(array &$array): void
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
            $value = trim($value); // Store raw data to DB to prevent double-escaping
        }
    }
    unset($value); // break reference
}
sanitize_global_input($_POST);
sanitize_global_input($_GET);

// ---------- 4) SECURITY PROTOCOLS (HTTPS/SESSION) ----------
// [MHI 5.4] Enforce HTTPS (Skip for Localhost or CLI to avoid ERR_SSL_PROTOCOL_ERROR)
$isLocal = (php_sapi_name() === 'cli' || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true));

// [FIX] Robust HTTPS detection including support for Reverse Proxies
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? 80) == 443) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);

// [FIX] Disable forced HTTPS redirection for local/network development environments
// if (!$isLocal && !$isHttps) {
//     $location = 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
//     header('HTTP/1.1 301 Moved Permanently');
//     header('Location: ' . $location);
//     exit;
// }

// [MHI 5.3] Secure Session Parameters (HttpOnly, Secure, SameSite)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
}

// ---------- 5) DATABASE CONNECTION ----------
try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Optionally enable SSL/TLS if a CA path is configured in config.php
    if (!empty($_ENV['DB_SSL_CA'])) {
        $sslCaPath = (string)$_ENV['DB_SSL_CA'];
        $realSslCa = realpath($sslCaPath);
        if ($realSslCa !== false) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $realSslCa;
        } else {
            // Don't fail hard; just warn in logs so deployments without CA don't break
            error_log("DB_SSL_CA path configured but not found: " . $sslCaPath);
        }
    }

    // [FIX] Windows/XAMPP often fails with 'localhost' due to IPv6. Force 127.0.0.1 if localhost is set.
    $envHost = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
    $dbHost  = ($envHost === 'localhost') ? '127.0.0.1' : $envHost;

    $port    = (int)($_ENV['DB_PORT'] ?? 3307);
    $dbName  = (string)($_ENV['DB_NAME'] ?? '');
    $charset = (string)($_ENV['DB_CHARSET'] ?? 'utf8mb4');
    $dbUser  = (string)($_ENV['DB_USER'] ?? '');
    $dbPass  = (string)($_ENV['DB_PASS'] ?? '');

    $dsn = "mysql:host={$dbHost};port={$port};dbname={$dbName};charset={$charset}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);

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

    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', (string)$server_timeout);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    // Provide a more helpful error message for XAMPP users
    if (strpos($e->getMessage(), 'actively refused') !== false) {
        die("Database connection error: Target machine actively refused connection.<br>" .
            "<strong>Solution:</strong> Ensure MySQL is running in XAMPP Control Panel. " .
            "If it is running, check if it's using Port 3306 or 3307 and update config/config.php.");
    }
    die("Database connection error: " . $e->getMessage());
}

// ---------- 6) SESSION TIMEOUT LOGIC ----------
// ✅ FIX for Intelephense P1132: add PDO type + nullable int type + return type
function checkSessionTimeout(PDO $pdo, ?int $serverTimeout = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        return;
    }

    $timeout = 1800; // Default 30 mins

    if ($serverTimeout !== null && $serverTimeout > 0) {
        $timeout = $serverTimeout;
    } else {
        try {
            $val = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_server'")
                ->fetchColumn();
            if ($val) {
                $timeout = (int)$val;
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > $timeout)) {
        session_unset();
        session_destroy();
        header("Location: login.php?msg=" . urlencode("Session expired due to inactivity."));
        exit;
    }

    $_SESSION['last_activity'] = time();

    // [SECURITY] Ensure CSRF token is generated for every active session
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // [MHI 5.1.3 Req.5] Absolute Session Expiry (Max Duration)
    if (isset($_SESSION['login_time'])) {
        $role = strtoupper((string)($_SESSION['role'] ?? 'STAFF'));
        // Admins: 18 hours (64,800s), Others: 30 hours (108,000s)
        $maxLife = ($role === 'ADMIN') ? 64800 : 108000;

        if ((time() - (int)$_SESSION['login_time']) > $maxLife) {
            session_unset();
            session_destroy();
            header("Location: login.php?msg=" . urlencode("Security Policy: Maximum session duration reached. Please log in again."));
            exit;
        }
    }

    // [SECURITY] Ensure account recovery is configured for all authenticated users
    if (!empty($_SESSION['user_id'])) {
        enforceSecurityQuestionSetup($pdo);
    }
}

/**
 * Enforce that authenticated users have a security question configured.
 * If not, redirect them to profile_settings.php to complete setup.
 */
// ✅ FIX for Intelephense P1132: add PDO + bool type + return type
function enforceSecurityQuestionSetup(PDO $pdo, bool $ignoreWhitelist = false): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (empty($_SESSION['user_id'])) return;

    $currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $whitelist   = ['profile_settings.php', 'logout.php', 'login.php'];

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
