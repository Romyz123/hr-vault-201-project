<?php
require '../config/db.php';

echo "<h2>🛠️ Updating Database Schema...</h2>";

try {
    // Add 'deleted_at' column to documents table if it doesn't exist
    $sql = "ALTER TABLE documents ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER resolution_note";
    $pdo->exec($sql);
    echo "✅ Added 'deleted_at' column to 'documents' table.<br>";
} catch (PDOException $e) {
    // Ignore error if column already exists (Error 1060)
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "ℹ️ Column 'deleted_at' already exists.<br>";
    } else {
        echo "❌ Error: " . $e->getMessage() . "<br>";
    }
}
echo "<br><a href='index.php'>Go Back to Dashboard</a>";
?>