<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? __DIR__ . '/../vault/';

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
$stmt = $pdo->prepare("SELECT file_path, original_name, deleted_at FROM documents WHERE file_uuid = ?");
$stmt->execute([$file_uuid]);
$file = $stmt->fetch();

if (!$file) die("File entry not found in database.");

if ($file['deleted_at'] !== null && !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    die("Access Denied: This file has been deleted.");
}

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
    case 'pdf': $mime_type = 'application/pdf'; break;
    case 'jpg': 
    case 'jpeg': $mime_type = 'image/jpeg'; break;
    case 'png': $mime_type = 'image/png'; break;
}

// 8. UI WRAPPER (If not embedding or downloading)
if (!$embed && !$download) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($file['original_name']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
        <style>
            body { margin: 0; height: 100vh; display: flex; flex-direction: column; background: #333; overflow: hidden; }
            .toolbar { background: #212529; color: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 10; }
            .viewer-container { flex-grow: 1; position: relative; background: #555; display: flex; justify-content: center; align-items: center; overflow: auto; }
            iframe { width: 100%; height: 100%; border: none; }
            .img-preview { max-width: 100%; max-height: 100%; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
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
                <a href="?id=<?php echo $file_uuid; ?>&download=1" class="btn btn-primary btn-sm">
                    <i class="bi bi-download"></i> Download
                </a>
                <button onclick="window.close()" class="btn btn-danger btn-sm">
                    <i class="bi bi-x-lg"></i> Exit
                </button>
            </div>
        </div>
        <div class="viewer-container">
            <?php if ($ext === 'pdf'): ?>
                <iframe src="?id=<?php echo $file_uuid; ?>&embed=1"></iframe>
            <?php else: ?>
                <img src="?id=<?php echo $file_uuid; ?>&embed=1" class="img-preview">
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 9. STREAM THE FILE (Download or Embed)
header('Content-Type: ' . $mime_type);

if ($download) {
    header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
} else {
    header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
}

header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;