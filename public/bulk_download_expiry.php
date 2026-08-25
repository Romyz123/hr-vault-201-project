<?php
// [NEW FILE] public/api/bulk_download_expiry.php
ob_start();
require '../../config/db.php';
require '../../src/Security.php';
require '../../src/FileService.php';
require '../../src/Logger.php';
session_start();

// 1. SECURITY: Admin/HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    die("Access Denied");
}

// 2. VALIDATION
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['doc_ids'])) {
    die("Invalid Request");
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die("CSRF Failed");
}

$docIds = $_POST['doc_ids'];
if (!is_array($docIds)) $docIds = [];
$docIds = array_filter($docIds, 'is_numeric');

if (empty($docIds)) die("No documents selected.");

// 3. SETUP VAULT & ZIP
$config = require '../../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
$fileService = new FileService($vaultPath);

$zip = new ZipArchive();
$tmpFile = tempnam(sys_get_temp_dir(), 'expiry_dl_');

if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    $stmt = $pdo->prepare("SELECT d.file_path, d.original_name, e.last_name, e.first_name 
                           FROM documents d 
                           JOIN employees e ON d.employee_id = e.emp_id 
                           WHERE d.id IN ($placeholders)");
    $stmt->execute($docIds);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        $content = $fileService->getFileContent($f['file_path']);
        if ($content !== false) {
            // Organize inside ZIP: LastName, FirstName/DocumentName
            $folder = $f['last_name'] . ', ' . $f['first_name'];
            $zip->addFromString($folder . '/' . $f['original_name'], $content);
        }
    }
    $zip->close();

    // 4. LOGGING
    $logger = new Logger($pdo);
    $logger->log($_SESSION['user_id'], 'BULK_DOWNLOAD_EXPIRY', "Downloaded " . count($files) . " expired documents in ZIP.");

    // 5. STREAM DOWNLOAD
    while (ob_get_level()) ob_end_clean();
    if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Expired_Documents_Export_' . date('Ymd') . '.zip"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Pragma: no-cache');

    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
} else {
    die("Failed to create ZIP archive.");
}
