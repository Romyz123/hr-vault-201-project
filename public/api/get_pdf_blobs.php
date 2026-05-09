<?php
// public/api/get_pdf_blobs.php
// [PURPOSE] Securely fetch encrypted PDF contents (base64 encoded) for client-side PDF-Lib processing.

require '../../config/db.php';
require '../../src/Security.php';
require '../../src/FileService.php';
session_start();

header('Content-Type: application/json');

// 1. SECURITY: Admin/Manager/HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

// 2. VALIDATION
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['doc_uuids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Request']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF Failed']);
    exit;
}

$docUuids = $_POST['doc_uuids'];
if (!is_array($docUuids)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid document UUIDs format']);
    exit;
}

// Filter and sanitize UUIDs to prevent SQL injection and ensure valid format
$docUuids = array_filter($docUuids, function ($uuid) {
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid);
});

if (empty($docUuids)) {
    echo json_encode(['error' => 'No valid document UUIDs provided']);
    exit;
}

// 3. SETUP VAULT & FILESERVICE
$config = require '../../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
$fileService = new FileService($vaultPath);

$responseFiles = [];

try {
    $placeholders = implode(',', array_fill(0, count($docUuids), '?'));
    $stmt = $pdo->prepare("SELECT file_path, original_name, file_uuid FROM documents WHERE file_uuid IN ($placeholders) AND deleted_at IS NULL");
    $stmt->execute($docUuids);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        $content = $fileService->getFileContent($f['file_path']);
        if ($content !== false) {
            // Base64 encode the content to send over JSON
            $responseFiles[] = [
                'uuid' => $f['file_uuid'],
                'filename' => $f['original_name'],
                'content' => base64_encode($content)
            ];
        } else {
            error_log("Failed to decrypt file: " . $f['file_path']);
        }
    }

    echo json_encode(['status' => 'success', 'files' => $responseFiles]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
