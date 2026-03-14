<?php
// =========================================================================
// [FILE] public/bulk_import_vault.php
// [PURPOSE] Web-Based Batch Tool to securely import 10GB+ of legacy PDFs without CLI
// =========================================================================

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../src/FileService.php';
require __DIR__ . '/../src/Security.php';
session_start();
checkSessionTimeout($pdo);

// 1. SECURITY: Admin Only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    die("Access Denied: Admin privileges required.");
}

$config = require __DIR__ . '/../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
$sourceDir = realpath(__DIR__ . '/../temp_import');

// ======================================================
// AJAX HANDLER (Processes ONE folder at a time)
// ======================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');

    if (!$sourceDir || !is_dir($sourceDir)) {
        echo json_encode(['status' => 'error', 'message' => 'temp_import directory not found.']);
        exit;
    }

    $fileService = new FileService($vaultPath);

    // Get Document Requirements
    $requirements = [];
    try {
        $stmt = $pdo->query("SELECT name, keywords FROM document_requirements");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $requirements[$row['name']] = array_map('trim', explode(',', $row['keywords']));
        }
    } catch (Exception $e) {
        $requirements = ['Medical' => ['medical', 'fit to work'], 'Contract' => ['contract', 'agreement']];
    }

    // Find all directories
    $empDirs = array_values(array_filter(scandir($sourceDir), function ($item) use ($sourceDir) {
        return $item !== '.' && $item !== '..' && is_dir($sourceDir . DIRECTORY_SEPARATOR . $item);
    }));

    if (empty($empDirs)) {
        echo json_encode(['status' => 'complete']);
        exit;
    }

    // PROCESS ONLY THE FIRST FOLDER
    $empIdDir = $empDirs[0];
    $empDirPath = $sourceDir . DIRECTORY_SEPARATOR . $empIdDir;
    $empId = trim($empIdDir);
    $filesProcessed = 0;
    $logs = [];

    // Verify Employee Exists
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
    $stmt->execute([$empId]);
    if (!$stmt->fetchColumn()) {
        // Employee doesn't exist. Delete folder to prevent infinite loop.
        array_map('unlink', glob("$empDirPath/*.*"));
        @rmdir($empDirPath);
        echo json_encode(['status' => 'progress', 'emp_id' => $empId, 'message' => 'Skipped: ID not found in DB', 'remaining' => count($empDirs) - 1]);
        exit;
    }

    // Process files in this folder
    $files = array_diff(scandir($empDirPath), ['.', '..']);
    if (!empty($files)) {
        foreach ($files as $file) {
            $filePath = $empDirPath . DIRECTORY_SEPARATOR . $file;
            if (is_file($filePath)) {
                $category = 'Others';
                foreach ($requirements as $catName => $keywords) {
                    foreach ($keywords as $k) {
                        if (stripos($file, $k) !== false) {
                            $category = $catName;
                            break 2;
                        }
                    }
                }

                $storedName = $fileService->saveFile($filePath, $file);

                if ($storedName) {
                    try {
                        $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, description, uploaded_by, uploaded_at) 
                                VALUES (UUID(), ?, ?, ?, ?, 'Bulk Legacy Import', ?, NOW())";
                        $pdo->prepare($sql)->execute([$empId, $file, $storedName, $category, $_SESSION['user_id']]);
                        @unlink($filePath);
                        $filesProcessed++;
                    } catch (Exception $e) {
                        $logs[] = "DB Error on $file: " . $e->getMessage();
                    }
                }
            }
        }
    }

    @rmdir($empDirPath);

    echo json_encode([
        'status' => 'progress',
        'emp_id' => $empId,
        'files_processed' => $filesProcessed,
        'remaining' => count($empDirs) - 1,
        'logs' => $logs
    ]);
    exit;
}

// ======================================================
// UI RENDERING
// ======================================================
$foldersFound = 0;
if ($sourceDir && is_dir($sourceDir)) {
    $foldersFound = count(array_filter(scandir($sourceDir), function ($item) use ($sourceDir) {
        return $item !== '.' && $item !== '..' && is_dir($sourceDir . DIRECTORY_SEPARATOR . $item);
    }));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bulk Vault Importer</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">
    <div class="container mt-5" style="max-width: 700px;">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-hdd-network-fill"></i> Legacy Data Migration</h5>
                <a href="index.php" class="btn btn-sm btn-light">Exit</a>
            </div>
            <div class="card-body">
                <p>This tool securely encrypts and imports massive amounts of legacy PDFs from the <code>temp_import</code> directory into the active Vault.</p>

                <div class="alert alert-warning">
                    <strong>Status:</strong> Found <strong><span id="folderCount"><?php echo $foldersFound; ?></span></strong> employee folders pending import.
                </div>

                <?php if ($foldersFound > 0): ?>
                    <div id="progressUI" style="display: none;">
                        <label class="fw-bold mb-1">Migration Progress</label>
                        <div class="progress mb-2" style="height: 25px;">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 0%;">0%</div>
                        </div>
                        <div class="small text-muted mb-3" id="progressText">Initializing...</div>

                        <div class="bg-dark text-success p-2 rounded small font-monospace" id="terminalLog" style="height: 150px; overflow-y: auto;"></div>
                    </div>

                    <button id="startBtn" class="btn btn-success w-100 fw-bold py-2 mt-3" onclick="startMigration()">
                        <i class="bi bi-play-fill"></i> Start Migration Process
                    </button>
                <?php else: ?>
                    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> No pending files found. Migration is complete or directory is empty.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let totalFolders = <?php echo $foldersFound; ?>;
        let processed = 0;

        function logToTerminal(msg) {
            const term = document.getElementById('terminalLog');
            term.innerHTML += `<div>> ${msg}</div>`;
            term.scrollTop = term.scrollHeight;
        }

        function processNextBatch() {
            fetch('bulk_import_vault.php?ajax=1')
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'complete') {
                        document.getElementById('progressBar').style.width = '100%';
                        document.getElementById('progressBar').innerText = '100%';
                        document.getElementById('progressText').innerText = 'All files migrated successfully!';
                        document.getElementById('progressBar').classList.remove('progress-bar-animated');
                        logToTerminal('<span class="text-white">Migration Completed! You can close this page.</span>');
                        return;
                    }

                    if (data.status === 'progress') {
                        processed++;
                        let pct = Math.round((processed / totalFolders) * 100);

                        document.getElementById('progressBar').style.width = pct + '%';
                        document.getElementById('progressBar').innerText = pct + '%';
                        document.getElementById('folderCount').innerText = data.remaining;
                        document.getElementById('progressText').innerText = `Processing folder ${processed} of ${totalFolders}...`;

                        if (data.message) {
                            logToTerminal(data.message);
                        } else {
                            logToTerminal(`Imported [${data.emp_id}]: ${data.files_processed} file(s) encrypted & saved.`);
                        }

                        // Trigger next batch immediately
                        processNextBatch();
                    }
                })
                .catch(err => {
                    logToTerminal('<span class="text-danger">Error communicating with server. Retrying in 3 seconds...</span>');
                    setTimeout(processNextBatch, 3000);
                });
        }

        function startMigration() {
            document.getElementById('startBtn').style.display = 'none';
            document.getElementById('progressUI').style.display = 'block';
            logToTerminal('Starting automated migration protocol...');
            processNextBatch();
        }
    </script>
</body>

</html>