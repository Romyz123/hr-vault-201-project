<?php
// ======================================================
// [FILE] public/view_doc.php
// [STATUS] Secure Document Viewer & Streamer
// ======================================================

// ---------- 1) CONFIGURATION & SECURITY ----------
ob_start();

require '../config/db.php';
require '../src/Security.php';
require '../src/FileService.php';
session_start();

// Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// 1. SECURITY: Check Login
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// Security Headers: Allow embedding in iframes for the modal viewer
if (isset($_GET['embed']) && $_GET['embed'] == '1') {
    header('X-Frame-Options: SAMEORIGIN', true);
}

// 2. VALIDATE INPUT
$file_uuid = $_GET['id'] ?? '';
$embed     = isset($_GET['embed']);     // ?embed=1 (Raw stream for img/iframe)
$download  = isset($_GET['download']);  // ?download=1 (Force download)

if (!preg_match('/^[a-zA-Z0-9-]+$/', $file_uuid) && !is_numeric($file_uuid)) {
    die("Invalid File ID");
}

// 3. FETCH FILE INFO
$where = is_numeric($file_uuid) ? "id = ?" : "file_uuid = ?";
$stmt = $pdo->prepare("SELECT * FROM documents WHERE $where");
$stmt->execute([$file_uuid]);
$file = $stmt->fetch();

if (!$file) die("File entry not found in database.");

if ($file['deleted_at'] !== null && !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    die("Access Denied: This file has been deleted.");
}

// [SECURITY] Enforce Ownership and Role Policy for viewing/downloading documents
$authorized = false;
$userRoles = $_SESSION['role'] ?? '';
$isAdmin = in_array($userRoles, ['ADMIN', 'MANAGER', 'HR']);

if ($isAdmin) {
    $authorized = true;
} else {
    $stmtEmp = $pdo->prepare("SELECT emp_id FROM employees WHERE user_id = ?");
    $stmtEmp->execute([$_SESSION['user_id']]);
    $userEmp = $stmtEmp->fetch();
    if ($userEmp && $userEmp['emp_id'] === $file['employee_id']) {
        $authorized = true;
    }
}

if (!$authorized) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view or download this document.");
}

// 4. LOCATE FILE & PATH RESOLUTION
$uploadDir = $vaultPath;
$fullPath = $vaultPath . $file['file_path'];
$isVaultFile = true;

// Check if physical vault file exists; if not, search unencrypted upload paths (e.g. generated HTML contracts)
if (!file_exists($fullPath)) {
    $possibleAltPaths = [
        __DIR__ . '/uploads/' . basename($file['file_path']),
        __DIR__ . '/uploads/' . $file['file_path'],
        dirname(__DIR__) . '/uploads/' . basename($file['file_path']),
        dirname(__DIR__) . '/uploads/' . $file['file_path'],
        $file['file_path']
    ];

    foreach ($possibleAltPaths as $p) {
        if ($p && file_exists($p) && !is_dir($p)) {
            $fullPath = $p;
            $uploadDir = dirname($p) . DIRECTORY_SEPARATOR;
            $isVaultFile = false;
            break;
        }
    }
}

// 5. VERIFY FILE EXISTS
if (!file_exists($fullPath) && $isVaultFile) {
    // Give FileService a chance if vault path uses virtual structures, otherwise fail
}

// 6. DETERMINE CONTENT TYPE (MIME)
$originalName = (string)($file['original_name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext === '' && is_string($file['file_path'] ?? '')) {
    $ext = strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION));
}
if ($ext === '' && is_string($fullPath)) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
}

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
    case 'html':
    case 'htm':
        $mime_type = 'text/html';
        break;
}

// 7. UI WRAPPER (If not embedding or downloading)
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
                background: white;
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
            <?php if ($ext === 'pdf' || $ext === 'html' || $ext === 'htm'): ?>
                <iframe src="?id=<?php echo htmlspecialchars($file_uuid, ENT_QUOTES); ?>&embed=1" title="Document Preview"></iframe>
            <?php else: ?>
                <img src="?id=<?php echo htmlspecialchars($file_uuid, ENT_QUOTES); ?>&embed=1" class="img-preview" alt="Document Preview">
            <?php endif; ?>
        </div>
    </body>

    </html>
<?php
    exit;
}

// 8. STREAM THE FILE (Download or Embed)
ini_set('memory_limit', '512M');

if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', 'Off');
}

error_reporting(0);

while (ob_get_level()) {
    ob_end_clean();
}

session_write_close();

// Retrieve content from vault or direct file read.
// This supports encrypted vault files, legacy plaintext files, and files moved between vault/upload folders.
$content = false;
$debugPaths = [];

if ($isVaultFile) {
    try {
        $fileService = new FileService($vaultPath);
        $content = $fileService->getFileContent($file['file_path']);
    } catch (Throwable $e) {
        error_log('view_doc vault error: ' . $e->getMessage());
    }
}

if ($content === false) {
    $possiblePaths = [
        $fullPath,
        $vaultPath . basename($file['file_path']),
        __DIR__ . '/uploads/' . basename($file['file_path']),
        __DIR__ . '/uploads/' . $file['file_path'],
        dirname(__DIR__) . '/uploads/' . basename($file['file_path']),
        dirname(__DIR__) . '/uploads/' . $file['file_path'],
        $file['file_path']
    ];

    foreach (array_unique($possiblePaths) as $p) {
        $debugPaths[] = $p;
        if ($p && file_exists($p) && !is_dir($p)) {
            $content = file_get_contents($p);
            if ($content !== false) {
                break;
            }
        }
    }
}

if ($content === false) {
    http_response_code(404);
    $pathList = implode("<br>", array_filter(array_map('htmlspecialchars', $debugPaths)));
    die("Error: Could not read file content.<br><small>Checked paths:<br>$pathList</small>");
}

if ($ext === 'pdf' && substr($content, 0, 4) !== '%PDF') {
    header_remove('Content-Disposition');
    header('Content-Type: text/html');
    http_response_code(500);
    die("<h1>❌ Decryption Failed</h1><p>The system could not unlock this file. It may be corrupted or the encryption key does not match.</p>");
}

if (($ext === 'html' || $ext === 'htm') && stripos((string)$content, '<html') === false && stripos((string)$content, '<body') === false) {
    header_remove('Content-Disposition');
    header('Content-Type: text/html');
    http_response_code(500);
    die("<h1>❌ Invalid HTML Document</h1><p>This file was not readable as a valid HTML document.</p>");
}

// Prepare Headers
$realName = basename($file['original_name']);
$safeName = str_replace('"', '', $realName);

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime_type);
header('Content-Encoding: none');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

if ($download) {
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeName . '"');
}

echo $content;
exit;
?>