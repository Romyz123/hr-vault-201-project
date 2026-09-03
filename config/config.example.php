<?php
return [
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => (int)(getenv('DB_PORT') ?: 3306),
    'DB_NAME' => getenv('DB_NAME') ?: 'hr201_prod',
    'DB_USER' => getenv('DB_USER') ?: 'hr_app_user',
    'DB_PASS' => getenv('DB_PASS') ?: '',
    'DB_CHARSET' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'DB_SSL_CA' => getenv('DB_SSL_CA') ?: null,
    'VAULT_PATH' => getenv('VAULT_PATH') ?: __DIR__ . '/../vault/',
    'BACKUP_PATH' => getenv('BACKUP_PATH') ?: __DIR__ . '/../backups/',
    'MAX_UPLOAD_BYTES' => (int)(getenv('MAX_UPLOAD_BYTES') ?: 52428800),
    'VAULT_KEY' => getenv('VAULT_KEY') ?: '',
    'LEGACY_VAULT_KEY' => getenv('LEGACY_VAULT_KEY') ?: ''
];
