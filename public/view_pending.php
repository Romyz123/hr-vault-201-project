<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? __DIR__ . '/../vault/';

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
$fullPath = $vaultPath . $filename;

if (!file_exists($fullPath)) die("File missing from Vault.");

// Determine MIME Type
$ext = strtolower(pathinfo($data['original_name'], PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
elseif ($ext === 'png') $mime = 'image/png';

// Stream the file
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="PREVIEW_' . $data['original_name'] . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
?>