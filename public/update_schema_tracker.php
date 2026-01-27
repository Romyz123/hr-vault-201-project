<?php
require '../config/db.php';

echo "<h2>🛠️ Updating Database for Tracker...</h2>";

try {
    $sql = "CREATE TABLE IF NOT EXISTS document_requirements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        keywords TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);

    // Seed with defaults if empty
    $count = $pdo->query("SELECT COUNT(*) FROM document_requirements")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("INSERT INTO document_requirements (name, keywords) VALUES 
            ('201 Files', '201, PDS, Data Sheet, Resume'),
            ('Valid ID', 'ID, Passport, License, SSS, PhilHealth'),
            ('Contract', 'Contract, Appointment, Offer'),
            ('Medical', 'Medical, Fit to Work, Exam'),
            ('Clearance', 'NBI, Police, Barangay')");
        echo "✅ Seeded default requirements.<br>";
    }

    echo "✅ Table 'document_requirements' is ready.<br>";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
echo "<br><a href='tracker.php'>Go to Tracker</a>";
