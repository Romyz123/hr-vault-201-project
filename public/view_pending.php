<?php
// [FIX] Start buffering immediately to catch any whitespace/BOM from includes
ob_start();
require '../config/db.php';
require '../src/Security.php';
require '../src/FileService.php';
require '../src/Logger.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// [SECURITY] Require an authenticated user and explicit authorization before exposing pending documents.
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$userRole = strtoupper(trim((string)($_SESSION['role'] ?? '')));
if (!in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$req_id = $_GET['id'] ?? '';
if (!is_numeric($req_id)) {
    http_response_code(400);
    exit('Invalid Request ID');
}

// Fetch the pending request and validate request type
$stmt = $pdo->prepare("SELECT json_payload, request_type FROM requests WHERE id = ?");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req || $req['request_type'] !== 'UPLOAD_DOC') {
    http_response_code(404);
    exit('Request not found or invalid request type.');
}

$logger = new Logger($pdo);
$logger->log((int)$_SESSION['user_id'], 'VIEW_PENDING_DOCUMENT', 'Viewed pending request ' . (int)$req_id);

// Decode JSON to find the file path
$data = json_decode($req['json_payload'], true);
if (!is_array($data) || empty($data['file_path']) || empty($data['original_name'])) {
    http_response_code(500);
    exit('Invalid request payload.');
}
$filename = $data['file_path'];

// Determine MIME Type
$ext = strtolower(pathinfo($data['original_name'], PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($ext === 'pdf') $mime = 'application/pdf';
elseif ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
elseif ($ext === 'png') $mime = 'image/png';

// [FIX] Decrypt content instead of reading file directly
$fileService = new FileService($vaultPath);
$content = $fileService->getFileContent($filename);

if ($content === false) die("Error: File missing or corrupted in Vault.");

// [FIX] Increase memory limit for decryption operations
ini_set('memory_limit', '512M');

// [FIX] Turn off error reporting to prevent "Notices" from breaking the PDF
error_reporting(0);

// [FIX] Aggressively clean ALL output buffers (Loop until empty)
while (ob_get_level()) {
    ob_end_clean();
}

// Stream the file
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="PREVIEW_' . $data['original_name'] . '"');
// [FIX] Remove Content-Length to prevent mismatch if server compresses output
// header('Content-Length: ' . strlen($content));
header('Content-Encoding: none');
echo $content;
exit;
