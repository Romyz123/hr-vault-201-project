<?php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) die("Access Denied");

$emp_id = $_GET['emp_id'] ?? '';
if (!$emp_id) die("Invalid ID");

// Fetch files
$stmt = $pdo->prepare("SELECT * FROM documents WHERE employee_id = ?");
$stmt->execute([$emp_id]);
$files = $stmt->fetchAll();

if (!$files) die("No files found for this employee.");

// Create ZIP
$zipname = "Documents_" . $emp_id . ".zip";
$zip = new ZipArchive;
$tmp_file = tempnam(sys_get_temp_dir(), 'zip');

if ($zip->open($tmp_file, ZipArchive::CREATE) === TRUE) {
    foreach ($files as $file) {
        $path = $_ENV['VAULT_PATH'] . $file['file_path'];
        if (file_exists($path)) {
            $zip->addFile($path, $file['original_name']);
        }
    }
    $zip->close();
    
    // Serve file
    header('Content-Type: application/zip');
    header('Content-disposition: attachment; filename='.$zipname);
    header('Content-Length: ' . filesize($tmp_file));
    readfile($tmp_file);
    unlink($tmp_file); // Clean up
} else {
    echo "Failed to create ZIP.";
}
?>