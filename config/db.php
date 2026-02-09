<?php
// config/db.php

// Load settings directly from PHP file instead of .env to avoid permission errors
$_ENV = require 'config.php';

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
