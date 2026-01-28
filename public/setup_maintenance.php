<?php
require '../config/db.php';

session_start();
// 1. SECURITY: Only ADMIN can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    die("ACCESS DENIED");
}

echo "<h2>🛠️ Setting up Maintenance Logs...</h2>";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(32) NOT NULL,
        equipment_type VARCHAR(50) NOT NULL,
        issue VARCHAR(255) NOT NULL,
        action_taken TEXT NOT NULL,
        maintenance_date DATE NOT NULL,
        performed_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_emp (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✅ Table 'maintenance_logs' created successfully.<br>";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}

echo "<br><a href='maintenance_log.php'>👉 Go to Maintenance Logs</a>";
