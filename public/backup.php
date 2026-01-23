<?php
// public/backup.php
require '../config/db.php';
require '../src/Logger.php'; 
session_start();

// 1. SECURITY: Only ADMIN can download backups
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die("ACCESS DENIED: You do not have permission to download backups.");
}

// 2. CONFIGURATION
$backup_name = "TESP_HR_BACKUP_" . date("Y-m-d_H-i-s") . ".sql";
$tables = [];

// 3. GET ALL TABLES
$query = $pdo->query('SHOW TABLES');
while ($row = $query->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

$content = "-- TESP HR SYSTEM BACKUP\n";
$content .= "-- Generated: " . date("Y-m-d H:i:s") . "\n";
$content .= "-- By User ID: " . $_SESSION['user_id'] . "\n\n";
$content .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

// 4. LOOP THROUGH TABLES
foreach ($tables as $table) {
    // A. Get Create Table structure
    $stmt = $pdo->query("SHOW CREATE TABLE $table");
    $row = $stmt->fetch(PDO::FETCH_NUM);
    
    $content .= "\n\n-- Structure for table `$table` --\n";
    $content .= "DROP TABLE IF EXISTS `$table`;\n";
    $content .= $row[1] . ";\n\n";

    // B. Get Table Data
    $stmt = $pdo->query("SELECT * FROM $table");
    $rowCount = $stmt->rowCount();

    if ($rowCount > 0) {
        $content .= "-- Dumping data for table `$table` --\n";
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = "NULL";
                } else {
                    $value = addslashes($value);
                    $value = str_replace("\n", "\\n", $value);
                    $values[] = "'$value'";
                }
            }
            $content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
        }
    }
}

$content .= "\nSET FOREIGN_KEY_CHECKS=1;";

// 5. LOG THE ACTION (Accountability)
try {
    $logger = new Logger($pdo);
    $logger->log($_SESSION['user_id'], 'SYSTEM_BACKUP', 'Admin downloaded full database backup.');
} catch (Exception $e) {
    // Continue even if logging fails
}

// 6. FORCE DOWNLOAD
header('Content-Type: application/octet-stream');
header("Content-Transfer-Encoding: Binary");
header("Content-disposition: attachment; filename=\"" . $backup_name . "\"");
echo $content;
exit;
?>