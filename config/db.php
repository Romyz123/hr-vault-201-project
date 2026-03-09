<?php
// config/db.php

// [SECURITY] Production Error Handling
// Hide errors from users, log them to server instead
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Load settings directly from PHP file instead of .env to avoid permission errors
$_ENV = require 'config.php';

// [MHI 5.4] Enforce HTTPS (Skip for Localhost to avoid ERR_SSL_PROTOCOL_ERROR)
$isLocal = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1']);
if (!$isLocal && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === "off")) {
    $location = 'https://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $location);
    exit;
}
// [MHI 5.3] Secure Session Parameters (HttpOnly, Secure, SameSite)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $isLocal ? 0 : 1);
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
