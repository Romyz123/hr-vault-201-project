<?php
// ======================================================
// [FILE] public/utils/fix_dates.php
// [GOAL] Find employees with "Ghost" Hire Dates (0000-00-00 or NULL)
// ======================================================

require '../../config/db.php';
require '../../src/Security.php';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>

<body class="bg-light">

    <div class="container mt-5">
        <div class="card shadow border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar-x-fill me-2"></i> Missing Hire Dates</h5>
                <a href="../index.php" class="btn btn-sm btn-light text-danger fw-bold">Back to Dashboard</a>
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
                                        <a href="../edit_employee.php?id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-primary">
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
                        <a href="../analytics.php" class="btn btn-primary">Go to Analytics</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</body>

</html>