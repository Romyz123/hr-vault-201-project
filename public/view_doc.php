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

// 1. SECURITY: Check Login
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 2. VALIDATE INPUT
$file_uuid = $_GET['id'] ?? '';
$embed     = isset($_GET['embed']);     // ?embed=1 (Raw stream for img/iframe)
$download  = isset($_GET['download']);  // ?download=1 (Force download)

if (!preg_match('/^[a-zA-Z0-9-]+$/', $file_uuid)) {
    die("Invalid File ID");
}

// 3. FETCH FILE INFO
$stmt = $pdo->prepare("SELECT employee_id, file_path, original_name, deleted_at, uploaded_by, is_private FROM documents WHERE file_uuid = ?");
$stmt->execute([$file_uuid]);
$file = $stmt->fetch();

if (!$file) die("File entry not found in database.");

$security = new Security($pdo);
$logger   = new Logger($pdo);
$userRole = strtoupper($_SESSION['role'] ?? '');

// [SECURITY] Access Control Check
if ($file['deleted_at'] !== null && !in_array($userRole, ['ADMIN', 'HR'])) {
    die("Access Denied: This file has been deleted.");
}

// [SECURITY] Private Document Access Control
if (!empty($file['is_private']) && (int)$_SESSION['user_id'] !== (int)$file['uploaded_by'] && $userRole !== 'ADMIN') {
    $logger->log($_SESSION['user_id'], 'AUTH_FAIL', "Unauthorized attempt to view private doc: " . $file['original_name'] . " (ID: $file_uuid)");
    die("Access Denied: This document is marked as Private.");
}

if ($userRole !== 'HR' && !$security->canViewEmployee($_SESSION['user_id'], $file['employee_id'])) {
    $logger->log($_SESSION['user_id'], 'AUTH_FAIL', "Unauthorized access attempt to doc: " . $file['original_name'] . " (Employee: " . $file['employee_id'] . ")");
    die("Access Denied: You do not have permission to view this document.");
}

// [AUDIT] Log the view event
$logger->log($_SESSION['user_id'], 'VIEW_DOC', "Viewed document: " . $file['original_name'] . " (ID: $file_uuid)");

// 4. LOCATE FILE (Relative to this script)
$uploadDir = $vaultPath;
$fullPath = $uploadDir . $file['file_path'];

// [FIX] Support Disciplinary files stored in public/uploads/
if (!file_exists($fullPath)) {
    $altPath = __DIR__ . '/uploads/' . $file['file_path'];
    if (file_exists($altPath)) {
        $fullPath = $altPath;
        $uploadDir = __DIR__ . '/uploads/'; // Allow access to this dir
    }
}

// 5. VERIFY FILE EXISTS
if (!file_exists($fullPath)) {
    die("Error: Physical file not found on server.");
}

// 6. SECURITY: Directory Traversal Check
$realPath = realpath($fullPath);
if ($realPath === false || strpos($realPath, realpath($uploadDir)) !== 0) {
    die("Security Violation: File Access Denied.");
}

// 7. DETERMINE CONTENT TYPE (MIME)
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime_type = 'application/octet-stream'; // Default

switch ($ext) {
    case 'pdf':
        $mime_type = 'application/pdf';
        break;
    case 'jpg':
    case 'jpeg':
        $mime_type = 'image/jpeg';
        break;
    case 'png':
        $mime_type = 'image/png';
        break;
}

// 8. UI WRAPPER (If not embedding or downloading)
if (!$embed && !$download) {
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($file['original_name']); ?></title>
        <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
        <link href="assets/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
        <style>
            body {
                margin: 0;
                height: 100vh;
                display: flex;
                flex-direction: column;
                background: #333;
                overflow: hidden;
            }

            .toolbar {
                background: #212529;
                color: white;
                padding: 10px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
                z-index: 10;
            }

            .viewer-container {
                flex-grow: 1;
                position: relative;
                background: #555;
                display: flex;
                justify-content: center;
                align-items: center;
                overflow: auto;
            }

            iframe {
                width: 100%;
                height: 100%;
                border: none;
            }

            .img-preview {
                max-width: 100%;
                max-height: 100%;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
            }
        </style>
    </head>

    <body>
        <div class="toolbar">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-text fs-4"></i>
                <div>
                    <div class="fw-bold"><?php echo htmlspecialchars($file['original_name']); ?></div>
                    <small class="text-muted" style="font-size: 0.75rem;">Secure Viewer</small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="?id=<?php echo htmlspecialchars($file_uuid, ENT_QUOTES); ?>&download=1" class="btn btn-primary btn-sm">
                    <i class="bi bi-download"></i> Download
                </a>
                <button onclick="window.close()" class="btn btn-danger btn-sm">
                    <i class="bi bi-x-lg"></i> Exit
                </button>
            </div>
        </div>
        <div class="viewer-container">
            <?php if ($ext === 'pdf'): ?>
                <iframe src="?id=<?php echo htmlspecialchars($file_uuid, ENT_QUOTES); ?>&embed=1"></iframe>
            <?php else: ?>
                <img src="?id=<?php echo htmlspecialchars($file_uuid, ENT_QUOTES); ?>&embed=1" class="img-preview">
            <?php endif; ?>
        </div>
    </body>

    </html>
<?php
    exit;
}

// 9. STREAM THE FILE (Download or Embed)
// [FIX] Increase memory limit for decryption operations
ini_set('memory_limit', '512M');

// [FIX] Disable compression to prevent "Corrupted" errors on download
if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', 'Off');
}

// [FIX] Turn off error reporting to prevent "Notices" from breaking the PDF
error_reporting(0);

// [FIX] Aggressively clean ALL output buffers (Loop until empty)
while (ob_get_level()) {
    ob_end_clean();
}

// [SECURITY] Decrypt file content
$fileService = new FileService($vaultPath);
$content = $fileService->getFileContent($file['file_path']);

// [FIX] Fallback for unencrypted Disciplinary files in uploads/
if ($content === false) {
    $altPath = __DIR__ . '/uploads/' . $file['file_path'];
    if (file_exists($altPath)) {
        $content = file_get_contents($altPath);
    }
}

if ($content === false) {
    http_response_code(404);
    die("Error: Could not read file content.");
}

// [FIX] Decryption Check
// If the file is supposed to be a PDF but doesn't start with %PDF, decryption failed.
// We stop here and show an HTML error instead of sending a corrupted download.
if ($ext === 'pdf' && substr($content, 0, 4) !== '%PDF') {
    // Clear headers so it doesn't try to download
    header_remove('Content-Disposition');
    header('Content-Type: text/html');
    http_response_code(500);
    die("<h1>❌ Decryption Failed</h1><p>The system could not unlock this file. It may be corrupted or the encryption key does not match.</p><p><strong>Action:</strong> Please try re-uploading the document.</p>");
}

// Prepare Headers
$fileSize = strlen($content);
$realName = basename($file['original_name']);

// [FIX] Sanitize filename for headers (Remove quotes to prevent header injection)
$safeName = str_replace(["\r", "\n", '"'], '', $realName);

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime_type);
// [FIX] Remove Content-Length to prevent mismatch if server compresses output
// header('Content-Length: ' . $fileSize);
header('Content-Encoding: none');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

if ($download) {
    // Force Download
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
    // Inline View
    header('Content-Disposition: inline; filename="' . $safeName . '"');
}

// Send the Clean Data
echo $content;
exit;
