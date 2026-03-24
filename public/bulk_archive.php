<?php
// ======================================================
// [FILE] public/bulk_archive.php
// [PURPOSE] Bulk archive inactive/terminated employees
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Admin, Manager & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

$logger = new Logger($pdo);
$msg = "";
$error = "";

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. HANDLE BULK ARCHIVE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_employees'])) {
    // CSRF Check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: Invalid Token.");
    }

    $ids = $_POST['employee_ids'] ?? [];
    // Validate $ids: filter for numeric values and cast to int
    $ids = array_filter($ids, 'is_numeric');
    $ids = array_map('intval', $ids);

    if (empty($ids)) {
        $error = "❌ No employees selected.";
    } else {
        try {
            $pdo->beginTransaction();

            // Soft delete employees
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE employees SET deleted_at = NOW(), updated_at = NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            $count = $stmt->rowCount();

            $pdo->commit();
            $logger->log($_SESSION['user_id'], 'BULK_ARCHIVE', "Moved $count inactive employees to the Recycle Bin.");

            header("Location: bulk_archive.php?msg=" . urlencode("✅ Successfully archived $count employees."));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Bulk archive error: ' . $e->getMessage());
            $error = "An internal error occurred while archiving employees. Please try again later.";
        }
    }
}

// Capture message from URL
if (isset($_GET['msg'])) $msg = $_GET['msg'];
if (isset($_GET['error'])) $error = $_GET['error'];

// 3. FETCH INACTIVE EMPLOYEES
$sql = "SELECT id, emp_id, first_name, last_name, job_title, dept, status, exit_date, exit_reason 
        FROM employees 
        WHERE status != 'Active' AND deleted_at IS NULL 
        ORDER BY last_name ASC";
$stmt = $pdo->query($sql);
$inactiveEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bulk Archive Employees</title>
    <link rel="icon" href="../uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-archive-fill"></i> Bulk Archive Tool</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="alert alert-info shadow-sm mb-4">
            <i class="bi bi-info-circle-fill me-2"></i>
            <strong>How this works:</strong> This tool shows all employees whose status is marked as <em>Resigned, Terminated, AWOL, or Retired</em>. Archiving them will move their records and documents into the <strong>Recycle Bin</strong>, keeping your main active directory clean.
        </div>

        <form method="POST" id="bulkArchiveForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="archive_employees" value="1">

            <div class="card shadow-sm border-danger mb-4">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-person-x-fill"></i> Inactive Employees Pending Archive</h6>
                    <button type="button" id="archiveBtn" class="btn btn-light text-danger btn-sm fw-bold shadow-sm">
                        <i class="bi bi-archive"></i> Archive Selected
                    </button>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;" class="text-center"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.emp-check').forEach(c => c.checked = this.checked)"></th>
                                <th>Employee</th>
                                <th>Dept / Job</th>
                                <th>Status</th>
                                <th>Exit Date</th>
                                <th>Exit Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inactiveEmployees)): ?>
                                <tr>
                                    <td colspan="6" class="text-center p-5 text-muted">
                                        <i class="bi bi-check-circle-fill text-success fs-1"></i><br>
                                        <span class="fw-bold mt-2 d-block">No inactive employees found!</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inactiveEmployees as $e): ?>
                                    <tr>
                                        <td class="text-center"><input type="checkbox" name="employee_ids[]" value="<?php echo htmlspecialchars($e['id']); ?>" class="form-check-input emp-check"></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($e['emp_id']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($e['dept'] . ' / ' . $e['job_title']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($e['status']); ?></span></td>
                                        <td class="text-danger fw-bold"><?php echo htmlspecialchars($e['exit_date'] ?: 'Not specified'); ?></td>
                                        <td class="small text-muted text-wrap" style="max-width: 250px;"><?php echo htmlspecialchars($e['exit_reason'] ?: 'None provided'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
    <script>
        <?php if ($msg): ?> Swal.fire('Success', <?php echo json_encode($msg); ?>, 'success').then(() => {
                if (window.history.replaceState) window.history.replaceState(null, null, window.location.pathname);
            });
        <?php endif; ?>
        <?php if ($error): ?> Swal.fire('Error', <?php echo json_encode($error); ?>, 'error').then(() => {
                if (window.history.replaceState) window.history.replaceState(null, null, window.location.pathname);
            });
        <?php endif; ?>

        document.getElementById('archiveBtn').addEventListener('click', function(e) {
            e.preventDefault();
            const checkboxes = document.querySelectorAll('input[name="employee_ids[]"]:checked');
            if (checkboxes.length === 0) return Swal.fire('No Selection', 'Please select at least one employee to archive.', 'warning');
            Swal.fire({
                title: `Archive ${checkboxes.length} Employees?`,
                text: "They will be moved to the Recycle Bin.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, Archive'
            }).then((result) => {
                if (result.isConfirmed) document.getElementById('bulkArchiveForm').submit();
            });
        });
    </script>
</body>

</html>