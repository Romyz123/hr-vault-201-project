<?php
// public/debug_upload.php
// USE THIS TO DIAGNOSE UPLOAD ISSUES

session_start();
header('Content-Type: text/plain');

echo "=== UPLOAD DIAGNOSTICS ===\n\n";

echo "1. POST Data:\n";
print_r($_POST);

echo "\n2. FILES Data:\n";
print_r($_FILES);

echo "\n3. Server Settings:\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "\n";

echo "\n4. Session ID: " . session_id() . "\n";
echo "User ID: " . ($_SESSION['user_id'] ?? 'Not Set') . "\n";
