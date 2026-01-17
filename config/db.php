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

    $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset={$_ENV['DB_CHARSET']}";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);

} catch (\PDOException $e) {
    error_log($e->getMessage());
    die("Database connection error: " . $e->getMessage()); // Show error for debugging
}
?>