<?php
// ======================================================
// [FILE] public/utils/fix_dates.php
// [GOAL] Find employees with "Ghost" Hire Dates (0000-00-00 or NULL)
// ======================================================

require '../../config/db.php';
require '../../src/Security.php';
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Admin/HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    die("ACCESS DENIED");
}

// 2. FIND THE BROKEN RECORDS
// We look for NULL, empty strings, or the default SQL zero date
$sql = "SELECT id, emp_id, first_name, last_name, dept, job_title, hire_date 
        FROM employees 
        WHERE status = 'Active' 
        AND (hire_date IS NULL OR hire_date = '' OR hire_date = '0000-00-00')
        ORDER BY last_name ASC";

$stmt = $pdo->query($sql);
$ghosts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Fix Missing Dates</title>
    <link rel="icon" href="uploads/tesp-logo.png?v=3" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-calendar-x-fill text-danger"></i> Missing Dates</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="card shadow border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar-x-fill me-2"></i> Missing Hire Dates</h5>
            </div>
            <div class="card-body">

                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Why this matters:</strong> Employees listed below are <strong>invisible</strong> in the Analytics "Hiring Trend" chart because the system doesn't know when they started.
                </div>

                <?php if (count($ghosts) > 0): ?>
                    <h6 class="mb-3">Found <strong><?php echo count($ghosts); ?></strong> records to fix:</h6>

                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Current Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ghosts as $emp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                                    <td class="fw-bold">
                                        <?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                                    <td class="text-danger font-monospace">
                                        <?php echo empty($emp['hire_date']) || $emp['hire_date'] == '0000-00-00' ? 'MISSING' : $emp['hire_date']; ?>
                                    </td>
                                    <td>
                                        <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-pencil-square"></i> Set Date
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        <h4 class="mt-3 text-success">All Clear!</h4>
                        <p class="text-muted">Every active employee has a valid hire date.</p>
                        <a href="analytics.php" class="btn btn-primary">Go to Analytics</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
</body>

</html>