<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// In a real app, ensure ONLY Admins can access this!
// if ($_SESSION['role'] !== 'ADMIN') die("Access Denied");

$req_id = $_GET['id'] ?? '';

if (!is_numeric($req_id)) die("Invalid Request ID");

// Fetch the pending request
$stmt = $pdo->prepare("SELECT json_payload FROM pending_requests WHERE id = ?");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) die("Request not found.");

// Decode JSON to find the file path
$data = json_decode($req['json_payload'], true);
$filename = $data['file_path'];
$fullPath = $_ENV['VAULT_PATH'] . $filename;

if (!file_exists($fullPath)) die("File missing from Vault.");

// Stream the file
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="PREVIEW_' . $data['original_name'] . '"');
readfile($fullPath);
exit;
?>