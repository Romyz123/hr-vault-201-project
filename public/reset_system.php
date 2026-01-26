<?php
require '../config/db.php';
session_start();

// 1. SECURITY: Only ADMIN can access this
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("ACCESS DENIED: Admins only.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    
    // --- A. DATABASE WIPE ---
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $tables = [
            'employees', 
            'documents', 
            'activity_logs', 
            'disciplinary_cases', 
            'notifications', 
            'requests', 
            'pending_requests', 
            'rate_limits',
            'employee_history'
        ];

        foreach ($tables as $table) {
            try {
                $pdo->exec("TRUNCATE TABLE `$table`");
            } catch (PDOException $e) {
                // Ignore if table doesn't exist
            }
        }
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (Exception $e) {
        die("DB Error: " . $e->getMessage());
    }

    // --- B. FILE WIPE ---
    // 1. Vault (Documents)
    $config = require '../config/config.php';
    $vaultPath = $config['VAULT_PATH'] ?? __DIR__ . '/../vault/';
    
    $files = glob($vaultPath . '*'); // get all file names
    foreach($files as $file){ 
        if(is_file($file)) unlink($file); 
    }

    // 2. Avatars (Keep default.png)
    $avatarPath = __DIR__ . '/uploads/avatars/';
    $avatars = glob($avatarPath . '*');
    foreach($avatars as $file){ 
        if(is_file($file) && basename($file) !== 'default.png') unlink($file); 
    }

    // 3. Disciplinary Uploads
    $uploadPath = __DIR__ . '/uploads/';
    $uploads = glob($uploadPath . '*');
    foreach($uploads as $file){ 
        if(is_file($file)) unlink($file); 
    }

    echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>
            <h1 style='color:green;'>✅ System Fresh Start Complete</h1>
            <p>All data and files have been wiped. Admin/Staff accounts are safe.</p>
            <a href='index.php'>Go to Dashboard</a>
          </div>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<body style="background:#f8d7da; display:flex; justify-content:center; align-items:center; height:100vh; font-family:sans-serif;">
    <div style="background:white; padding:40px; border-radius:10px; text-align:center; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h1 style="color:#dc3545;">⚠️ DANGER ZONE</h1>
        <p>You are about to <strong>DELETE ALL DATA</strong> (Employees, Documents, Logs).</p>
        <p><strong>Admin & Staff accounts will be SAVED.</strong></p>
        <p>This cannot be undone.</p>
        
        <form method="POST">
            <button type="submit" name="confirm_reset" style="background:#dc3545; color:white; border:none; padding:15px 30px; font-size:18px; cursor:pointer; border-radius:5px;">
                CONFIRM FRESH START
            </button>
        </form>
        <br>
        <a href="index.php" style="color:#666;">Cancel</a>
    </div>
</body>
</html>