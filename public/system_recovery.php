<?php
// ======================================================
// [FILE] public/system_recovery.php
// [PURPOSE] Advanced tools to recover lost data/files
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: ADMIN ONLY
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? __DIR__ . '/../vault/';
$msg = "";

// 2. HANDLE ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- RECOVER ORPHANED FILE ---
    if (isset($_POST['recover_file'])) {
        $filename = basename($_POST['filename']);
        $realPath = $vaultPath . $filename;

        if (file_exists($realPath)) {
            // Create a new DB record for this file
            // We assign it to a placeholder ID so Admin can re-assign it later
            try {
                $stmt = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by, description, created_at) 
                                       VALUES (UUID(), 'RECOVERED', ?, ?, 'Recovered', ?, 'Recovered from Orphaned Files', NOW())");
                $stmt->execute([$filename, $filename, $_SESSION['user_id']]);

                $msg = "✅ File '$filename' recovered! Look for it under Employee ID: 'RECOVERED' in the database.";

                // Log it
                $logger = new Logger($pdo);
                $logger->log($_SESSION['user_id'], 'FILE_RECOVERY', "Recovered orphan file: $filename");
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ File not found on disk.";
        }
    }
}

// 3. SCAN FOR ORPHANED FILES
// Files that exist in /vault/ but NOT in the database
$dbFiles = $pdo->query("SELECT file_path FROM documents")->fetchAll(PDO::FETCH_COLUMN);
$diskFiles = array_diff(scandir($vaultPath), ['.', '..']);
$orphans = [];

foreach ($diskFiles as $f) {
    if (!in_array($f, $dbFiles)) {
        $orphans[] = [
            'name' => $f,
            'size' => round(filesize($vaultPath . $f) / 1024, 2) . ' KB',
            'date' => date('Y-m-d H:i', filemtime($vaultPath . $f))
        ];
    }
}

// 4. SCAN LOGS FOR DELETED EMPLOYEES
$delLogs = $pdo->query("SELECT * FROM activity_logs WHERE action = 'DELETE_EMPLOYEE' ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Recovery Console</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-danger mb-4">
        <div class="container">
            <a class="navbar-brand" href="manager_user.php">⬅ Back to User Manager</a>
            <span class="navbar-text text-white fw-bold"><i class="bi bi-tools"></i> System Recovery Console</span>
        </div>
    </nav>

    <div class="container">

        <?php if ($msg): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4" id="recoveryTabs">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#orphans">👻 Orphaned Files (<?php echo count($orphans); ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#deleted">🗑️ Deleted Employees</button></li>
        </ul>

        <div class="tab-content">

            <!-- ORPHANED FILES TAB -->
            <div class="tab-pane fade show active" id="orphans">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <i class="bi bi-file-earmark-x"></i> <strong>Orphaned Files</strong>
                        <small class="d-block text-muted">These files exist on the server but are missing from the database (likely due to a DB restore).</small>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Filename</th>
                                    <th>Size</th>
                                    <th>Date Modified</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orphans)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center p-4 text-muted">✅ No orphaned files found. System is in sync.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orphans as $o): ?>
                                        <tr>
                                            <td class="font-monospace small"><?php echo htmlspecialchars($o['name']); ?></td>
                                            <td><?php echo $o['size']; ?></td>
                                            <td><?php echo $o['date']; ?></td>
                                            <td>
                                                <form method="POST">
                                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($o['name']); ?>">
                                                    <button type="submit" name="recover_file" class="btn btn-sm btn-success">
                                                        <i class="bi bi-recycle"></i> Recover to DB
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DELETED EMPLOYEES TAB -->
            <div class="tab-pane fade" id="deleted">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-person-x"></i> <strong>Deleted Employee History</strong>
                        <small class="d-block text-light">Use this data to manually re-add employees if needed.</small>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Date Deleted</th>
                                    <th>Details / ID</th>
                                    <th>Deleted By (User ID)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($delLogs as $log): ?>
                                    <tr>
                                        <td><?php echo $log['created_at']; ?></td>
                                        <td><?php echo htmlspecialchars($log['details']); ?></td>
                                        <td><?php echo htmlspecialchars($log['user_id']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>