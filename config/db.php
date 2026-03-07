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
ini_set('session.gc_maxlifetime', 1800); // 30 Minutes
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

    $dsn = "mysql:host={$_ENV['DB_HOST']};port=3307;dbname={$_ENV['DB_NAME']};charset={$_ENV['DB_CHARSET']}";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);
} catch (\PDOException $e) {
    error_log($e->getMessage());
    die("Database connection error. Please try again later.");
}
