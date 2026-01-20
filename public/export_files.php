<?php
// public/export_files.php

// 1. CLEAR BUFFER (Fixes corrupt ZIPs)
if (ob_get_level()) ob_end_clean();

require '../config/db.php';
session_start();

// 2. SECURITY CHECK
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'HR'])) {
    header("Location: index.php");
    exit;
}

// 3. SETTINGS
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 300);

// 4. GET INPUTS
$dept     = trim($_POST['dept'] ?? '');
$section  = trim($_POST['section'] ?? '');
$agency   = trim($_POST['employment_type'] ?? '');
$status   = trim($_POST['status'] ?? '');
$category = trim($_POST['category'] ?? '');
$search   = trim($_POST['search'] ?? '');

// 5. VALIDATION
if (empty($dept) && empty($search)) {
    $_SESSION['error'] = "You must select a Department OR type a Search Name.";
    header("Location: index.php");
    exit;
}

// 6. BUILD QUERY
$sql = "SELECT 
            e.emp_id, e.first_name, e.last_name, e.dept, e.section,
            d.file_path, d.original_name, d.category
        FROM documents d
        JOIN employees e ON d.employee_id = e.emp_id
        WHERE 1=1";

$params = [];

// Apply Search
if (!empty($search)) {
    $cleanSearch = preg_replace('/[^a-zA-Z0-9 \-_]/', '', $search);
    $sql .= " AND (e.emp_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $term = "%{$cleanSearch}%";
    array_push($params, $term, $term, $term);
}

// Apply Filters
if ($dept !== 'ALL' && !empty($dept)) { 
    $sql .= " AND e.dept = ?"; 
    $params[] = $dept; 
}
if (!empty($section)) { $sql .= " AND e.section = ?"; $params[] = $section; }
if (!empty($agency)) { $sql .= " AND e.employment_type = ?"; $params[] = $agency; }
if (!empty($status)) { $sql .= " AND e.status = ?"; $params[] = $status; }
if (!empty($category)) { $sql .= " AND d.category = ?"; $params[] = $category; }

// 7. EXECUTE
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($files) === 0) {
    // REDIRECT BACK TO DASHBOARD WITH ERROR
    $_SESSION['error'] = "No files found matching your criteria.";
    header("Location: index.php");
    exit;
}

// 8. CREATE ZIP
$zip = new ZipArchive();
$zipFilename = "HR_Export_" . date('Y-m-d_H-i-s') . ".zip";
$tempZipPath = sys_get_temp_dir() . "/" . $zipFilename;

if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    $_SESSION['error'] = "Server Error: Could not create ZIP file.";
    header("Location: index.php");
    exit;
}

function cleanName($str) { return preg_replace('/[^a-zA-Z0-9 \-_,\.]/', '', $str); }

$filesAdded = 0;
foreach ($files as $row) {
    $realPath = "uploads/" . $row['file_path']; 
    if (file_exists($realPath)) {
        $folderName = cleanName($row['last_name']) . ", " . cleanName($row['first_name']) . " - " . cleanName($row['dept']);
        if(!empty($row['section'])) $folderName .= " - " . cleanName($row['section']);
        
        $zip->addFile($realPath, $folderName . "/" . $row['original_name']);
        $filesAdded++;
    }
}
$zip->close();

if ($filesAdded === 0) {
    $_SESSION['error'] = "Database records found, but physical files are missing from the server.";
    header("Location: index.php");
    exit;
}

// 9. DOWNLOAD
if (file_exists($tempZipPath)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($tempZipPath));
    readfile($tempZipPath);
    unlink($tempZipPath);
    exit;
}
?>