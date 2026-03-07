<?php
// public/missing_fields_report.php
require '../config/db.php';
session_start();

// 1. SECURITY: Admin & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    die("ACCESS DENIED");
}

// 2. FETCH ACTIVE EMPLOYEES
$sql = "SELECT * FROM employees WHERE status = 'Active' ORDER BY last_name ASC";
$stmt = $pdo->query($sql);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. DEFINE REQUIRED FIELDS
$requiredFields = [
    'sss_no' => 'SSS Number',
    'tin_no' => 'TIN Number',
    'philhealth_no' => 'PhilHealth',
    'pagibig_no' => 'Pag-IBIG',
    'contact_number' => 'Contact Number',
    'present_address' => 'Present Address',
    'emergency_name' => 'Emergency Contact Name',
    'emergency_contact' => 'Emergency Contact Number'
];

$incompleteProfiles = [];

foreach ($employees as $emp) {
    $missing = [];
    foreach ($requiredFields as $field => $label) {
        if (empty($emp[$field])) {
            $missing[] = $label;
        }
    }

    if (!empty($missing)) {
        $emp['missing_fields'] = $missing;
        $incompleteProfiles[] = $emp;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Missing Fields Report</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            .card {
                border: none !important;
                box-shadow: none !important;
            }

            .badge {
                border: 1px solid #000;
                color: #000 !important;
            }
        }
    </style>
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4 no-print">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-exclamation-triangle-fill text-warning"></i> Incomplete Profiles Report</span>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <p class="text-muted mb-0">Found <strong><?php echo count($incompleteProfiles); ?></strong> employees with missing information.</p>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-dark shadow-sm"><i class="bi bi-printer-fill"></i> Print List</button>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Employee</th>
                            <th>ID</th>
                            <th>Department</th>
                            <th>Missing Fields</th>
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incompleteProfiles)): ?>
                            <tr>
                                <td colspan="5" class="text-center p-5 text-muted">
                                    <i class="bi bi-check-circle fs-1 text-success"></i><br>
                                    <span class="fw-bold mt-2 d-block">All profiles are complete!</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($incompleteProfiles as $emp): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                                    <td>
                                        <?php foreach ($emp['missing_fields'] as $field): ?>
                                            <span class="badge bg-danger mb-1"><?php echo htmlspecialchars($field); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="no-print">
                                        <a href="edit_employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn btn-sm btn-primary shadow-sm" target="_blank">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>