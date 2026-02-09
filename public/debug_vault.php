<?php
// public/debug_vault.php
// [PURPOSE] Verify that files are being obfuscated correctly

require '../config/db.php';
require '../src/FileService.php';
session_start();

// Security: Admin Only
if (($_SESSION['role'] ?? '') !== 'ADMIN') die('Access Denied');

$vaultPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Vault Debugger</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
</head>

<body class="p-4 bg-light">
    <div class="container bg-white p-4 shadow rounded">
        <h3>🔐 Vault Obfuscation Check</h3>
        <hr>

        <div class="row">
            <div class="col-md-6">
                <h5 class="text-danger">📂 Physical Files (On Disk)</h5>
                <p class="text-muted small">These should be random codes (e.g. <code>a1b2c3d4.pdf</code>)</p>
                <ul class="list-group font-monospace small">
                    <?php
                    $files = scandir($vaultPath);
                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..') continue;
                        echo "<li class='list-group-item'>$f</li>";
                    }
                    ?>
                </ul>
            </div>
            <div class="col-md-6">
                <h5 class="text-primary">🗄️ Database Records (Logical)</h5>
                <p class="text-muted small">These map the random code back to the real name.</p>
                <table class="table table-sm table-bordered small">
                    <thead class="table-light">
                        <tr>
                            <th>Original Name</th>
                            <th>Stored Path</th>
                        </tr>
                    </thead>
                    <?php
                    $stmt = $pdo->query("SELECT original_name, file_path FROM documents ORDER BY id DESC LIMIT 10");
                    while ($row = $stmt->fetch()) {
                        echo "<tr><td>" . htmlspecialchars($row['original_name']) . "</td><td class='font-monospace'>" . htmlspecialchars($row['file_path']) . "</td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <h5 class="text-success">📜 Vault Manifest (Last 10 Entries)</h5>
                <div class="bg-dark text-white p-3 rounded font-monospace small" style="max-height: 200px; overflow-y: auto;">
                    <?php
                    $manifestPath = $vaultPath . 'manifest_DO_NOT_DELETE.txt';
                    $fileService = new FileService($vaultPath);

                    if (file_exists($manifestPath)) {
                        $lines = $fileService->readManifest();
                        $lastLines = array_slice($lines, -10);
                        foreach ($lastLines as $line) {
                            echo htmlspecialchars($line) . "<br>";
                        }
                    } else {
                        echo "Manifest file not found yet.";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</body>

</html>