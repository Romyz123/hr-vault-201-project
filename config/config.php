<?php
// config/config.php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'hr201_local',
    'DB_USER' => 'root',
    'DB_PASS' => '', // Empty for default XAMPP
    'DB_CHARSET' => 'utf8mb4',
    'DB_SSL_CA' => null,
    'VAULT_PATH' => __DIR__ . '/../vault/',

    // [SECURITY] Encryption Key
    'VAULT_KEY' => 'Super_Secret_MHI_Vault_Key_2026_Change_This!'
];
