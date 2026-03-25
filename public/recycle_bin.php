<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Admin/HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

// 2. FETCH DELETED FILES (Last 30 Days)
// We can also implement an auto-cleanup cron job later to actually delete files older than 30 days.
$sql = "SELECT d.*, e.first_name, e.last_name, e.emp_id AS real_emp_id 
        FROM documents d
        JOIN employees e ON d.employee_id = e.emp_id
        WHERE d.deleted_at IS NOT NULL 
        ORDER BY d.deleted_at DESC";
$stmt = $pdo->query($sql);
$deletedDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Recycle Bin</title>
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0 ms-auto" title="Toggle Dark Mode">
                <i class="bi bi-moon-stars-fill"></i>
            </button>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="text-danger"><i class="bi bi-trash3-fill"></i> Recycle Bin</h3>
            <div>
                <?php if (!empty($deletedDocs)): ?>
                    <form action="delete_document.php" method="POST" class="d-inline" onsubmit="confirmForm(event, 'Are you sure you want to restore ALL files to their original locations?')">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="restore_all">
                        <button type="submit" class="btn btn-success me-2"><i class="bi bi-arrow-counterclockwise"></i> Restore All</button>
                    </form>
                    <form action="delete_document.php" method="POST" class="d-inline" onsubmit="confirmForm(event, 'WARNING: This will permanently delete ALL files in the Recycle Bin. This cannot be undone. Proceed?')">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="empty_bin">
                        <button type="submit" class="btn btn-danger me-2"><i class="bi bi-fire"></i> Empty Bin</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Deleted On</th>
                            <th>File Name</th>
                            <th>Employee</th>
                            <th>Category</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deletedDocs)): ?>
                            <tr>
                                <td colspan="5" class="text-center p-5 text-muted">Recycle Bin is empty.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($deletedDocs as $doc):
                                $delDate = date('M d, Y h:i A', strtotime($doc['deleted_at']));
                            ?>
                                <tr>
                                    <td class="text-muted small"><?php echo $delDate; ?></td>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-1"></i>
                                        <?php echo htmlspecialchars($doc['original_name']); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($doc['last_name'] . ', ' . $doc['first_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($doc['real_emp_id']); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($doc['category']); ?></span></td>
                                    <td class="text-end">
                                        <!-- RESTORE -->
                                        <form action="delete_document.php" method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="file_uuid" value="<?php echo $doc['file_uuid']; ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <button type="submit" class="btn btn-sm btn-success" title="Restore">
                                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                                            </button>
                                        </form>

                                        <!-- PERMANENT DELETE -->
                                        <form action="delete_document.php" method="POST" class="d-inline" onsubmit="confirmForm(event, 'Permanently delete this file? This cannot be undone.')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="file_uuid" value="<?php echo $doc['file_uuid']; ?>">
                                            <input type="hidden" name="action" value="permanent_delete">
                                            <button type="submit" class="btn btn-sm btn-outline-danger ms-1" title="Delete Forever">
                                                <i class="bi bi-x-lg"></i>
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

        <div class="alert alert-info mt-3 small">
            <i class="bi bi-info-circle-fill me-2"></i> Files in the Recycle Bin are automatically permanently deleted after 30 days.
        </div>
    </div>

    <script>
    </script>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
    <script src="main.js"></script>
</body>

</html>