<?php
require '../config/db.php';
echo "<h2>🛠️ Updating Database Schema for Qualifications...</h2>";
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN education TEXT NULL DEFAULT NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN experience TEXT NULL DEFAULT NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN skills TEXT NULL DEFAULT NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN licenses TEXT NULL DEFAULT NULL");
    echo "✅ Added qualification columns (education, experience, skills, licenses).<br>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "ℹ️ Columns already exist.<br>";
    } else {
        echo "❌ Error: " . $e->getMessage() . "<br>";
    }
}
echo "<br><a href='index.php'>Go Back to Dashboard</a>";
