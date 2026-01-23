<?php
// public/fix_images.php
require '../config/db.php';

echo "<h2>🔧 Fixing Missing Images...</h2>";

// 1. Get all employees
$stmt = $pdo->query("SELECT id, first_name, avatar_path FROM employees");
$employees = $stmt->fetchAll();

$fixedCount = 0;

foreach ($employees as $emp) {
    // Check if the file actually exists on the hard drive
    $filePath = __DIR__ . "/uploads/avatars/" . $emp['avatar_path'];

    // If file is missing OR if the path is empty
    if (!file_exists($filePath) || empty($emp['avatar_path'])) {
        
        // FORCE UPDATE to default.png
        $update = $pdo->prepare("UPDATE employees SET avatar_path = 'default.png' WHERE id = ?");
        $update->execute([$emp['id']]);
        
        echo "Fixed: " . $emp['first_name'] . " (Was: " . $emp['avatar_path'] . ") <br>";
        $fixedCount++;
    }
}

echo "<hr>";
echo "<h3>✅ DONE! Fixed $fixedCount missing images.</h3>";
echo "<a href='index.php'>Go back to Dashboard</a>";
?>