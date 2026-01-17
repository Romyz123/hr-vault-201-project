<?php
require '../config/db.php';

echo "<h1>🛠️ System Repair Tool</h1>";

try {
    // 1. DROP the table (Delete old broken data)
    $pdo->exec("DROP TABLE IF EXISTS users");
    echo "✅ Old 'users' table deleted.<br>";

    // 2. RE-CREATE table (Ensure correct column length)
    $sql = "CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL, -- Must be 255 for security hashes
        role ENUM('ADMIN', 'STAFF') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    
    $pdo->exec($sql);
    echo "✅ New 'users' table created (Structure fixed).<br>";

    // 3. INSERT Fresh Admin User
    $pass = 'admin123';
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'ADMIN')");
    $stmt->execute([$hash]);
    
    // 4. INSERT Fresh Staff User
    $passStaff = 'staff123';
    $hashStaff = password_hash($passStaff, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('staff', ?, 'STAFF')");
    $stmt->execute([$hashStaff]);

    echo "✅ Users created successfully.<br><br>";
    echo "<h3>👇 You can now login with:</h3>";
    echo "<ul>";
    echo "<li><strong>User:</strong> admin <br> <strong>Pass:</strong> admin123</li>";
    echo "<li><strong>User:</strong> staff <br> <strong>Pass:</strong> staff123</li>";
    echo "</ul>";
    echo "<a href='login.php' style='font-size:20px; font-weight:bold;'>👉 Click here to Login</a>";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>