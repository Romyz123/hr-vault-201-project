<?php
// config/config.php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'hr201_local',
    'DB_USER' => 'root',
    'DB_PASS' => '', // Empty for default XAMPP
    'DB_CHARSET' => 'utf8mb4',
    // Path to SSL CA certificate for MySQL TLS. Set to null to disable SSL CA lookup for local dev.
    'DB_SSL_CA' => null,
    'VAULT_PATH' => __DIR__ . '/../vault/',
    'VAULT_KEY' => getenv('VAULT_KEY') ?: ($_ENV['VAULT_KEY'] ?? 'hr201-dev-local-secret-change-me'),
];