<?php
// ======================================================
// [FILE] public/view_doc.php
// [STATUS] Secure Document Viewer & Streamer
// ======================================================

// ---------- 1) CONFIGURATION & SECURITY ----------
ob_start();

// Disable display errors for production security (Set to 1 for debugging)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require '../config/db.php';
require '../src/Security.php';
require '../src/FileService.php';
session_start();

// Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// 1. SECURITY: Check Login
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Access Denied: Please log in.");
}

// Security Headers: Allow embedding in iframes for the modal viewer
if (isset($_GET['embed']) && $_GET['embed'] == '1') {
    header('X-Frame-Options: SAMEORIGIN', true);
}

// 2. VALIDATE INPUT
$file_uuid = $_GET['id'] ?? '';
$embed     = isset($_GET['embed']);     // ?embed=1 (Raw stream for img/iframe)
$download  = isset($_GET['download']);  // ?download=1 (Force download)

if (!preg_match('/^[a-zA-Z0-9-]+$/', $file_uuid)) {
    http_response_code(400);
    die("Invalid File ID format.");
}

// 3. FETCH FILE INFO
try {
    // Search by 'id' first; fall back to 'file_uuid' if column exists
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? LIMIT 1");
    $stmt->execute([$file_uuid]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        // Fallback for schemas using 'file_uuid' column
        try {
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE file_uuid = ? LIMIT 1");
            $stmt->execute([$file_uuid]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Column file_uuid doesn't exist, ignore
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("Database query error: " . htmlspecialchars($e->getMessage()));
}

if (!$file) {
    http_response_code(404);
    die("File entry not found in database.");
}

if (($file['deleted_at'] ?? null) !== null && !in_array($_SESSION['role'] ?? '', ['ADMIN', 'HR'])) {
    http_response_code(403);
    die("Access Denied: This file has been deleted.");
}

// [SECURITY] Enforce Ownership and Role Policy for viewing/downloading documents
$authorized = false;
$userRole = strtoupper(trim((string)($_SESSION['role'] ?? '')));

// 1. Roles with global access to all document records
$unrestrictedRoles = ['ADMIN', 'HR', 'MANAGER', 'SUPERADMIN', 'EMPLOYEE_VIEWER', 'STAFF', 'EMPLOYEE'];

if (in_array($userRole, $unrestrictedRoles, true)) {
    $authorized = true;
} else {
    // 2. Fallback Employee Ownership Access Check
    try {
        $stmtEmp = $pdo->prepare("SELECT id, emp_id, employee_number FROM employees WHERE user_id = ? LIMIT 1");
        $stmtEmp->execute([$_SESSION['user_id']]);
        $userEmp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

        $docOwner = $file['employee_id'] ?? $file['emp_id'] ?? $file['user_id'] ?? null;

        // Grant access if the user owns the document or if it's unassigned
        if ($docOwner === null || $docOwner === '') {
            $authorized = true;
        } elseif ($userEmp) {
            $validKeys = [
                (string)($userEmp['id'] ?? ''),
                (string)($userEmp['emp_id'] ?? ''),
                (string)($userEmp['employee_number'] ?? ''),
                (string)($_SESSION['user_id'] ?? '')
            ];

            if (in_array((string)$docOwner, $validKeys, true)) {
                $authorized = true;
            }
        } elseif (isset($file['user_id']) && (string)$file['user_id'] === (string)$_SESSION['user_id']) {
            $authorized = true;
        }
    } catch (Throwable $e) {
        error_log("view_doc staff authorization error: " . $e->getMessage());
        if (isset($file['user_id']) && (string)$file['user_id'] === (string)$_SESSION['user_id']) {
            $authorized = true;
        }
    }
}

if (!$authorized) {
    http_response_code(403);
    die("<h1>Access Denied</h1><p>You do not have permission to view or download this document.</p>");
}

// 4. LOCATE FILE & PATH RESOLUTION
$filePath = $file['file_path'] ?? '';
$uploadDir = $vaultPath;
$fullPath = $vaultPath . $filePath;
$isVaultFile = true;

if (!file_exists($fullPath)) {
    $possibleAltPaths = [
        __DIR__ . '/uploads/' . basename($filePath),
        __DIR__ . '/uploads/' . $filePath,
        dirname(__DIR__) . '/uploads/' . basename($filePath),
        dirname(__DIR__) . '/uploads/' . $filePath,
        $filePath
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

// 5. DETERMINE CONTENT TYPE (MIME)
$originalName = (string)($file['original_name'] ?? $file['file_name'] ?? 'document');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext === '' && is_string($filePath)) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
}
if ($ext === '' && is_string($fullPath)) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
}

$mime_type = 'application/octet-stream';

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

// 6. UI WRAPPER (If not embedding or downloading)
if (!$embed && !$download) {
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($originalName); ?></title>
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
                    <div class="fw-bold"><?php echo htmlspecialchars($originalName); ?></div>
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

// 7. STREAM THE FILE (Download or Embed)
ini_set('memory_limit', '512M');

if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', 'Off');
}

while (ob_get_level()) {
    ob_end_clean();
}

session_write_close();

$content = false;
$debugPaths = [];

if ($isVaultFile) {
    try {
        if (class_exists('FileService')) {
            $fileService = new FileService($vaultPath);
            $content = $fileService->getFileContent($filePath);
        }
    } catch (Throwable $e) {
        error_log('view_doc vault error: ' . $e->getMessage());
    }
}

if ($content === false) {
    $possiblePaths = [
        $fullPath,
        $vaultPath . basename($filePath),
        __DIR__ . '/uploads/' . basename($filePath),
        __DIR__ . '/uploads/' . $filePath,
        dirname(__DIR__) . '/uploads/' . basename($filePath),
        dirname(__DIR__) . '/uploads/' . $filePath,
        $filePath
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

// Prepare Headers
$realName = basename($originalName);
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