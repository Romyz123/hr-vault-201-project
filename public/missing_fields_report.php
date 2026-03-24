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

    // [NEW] Check for missing or default Profile Picture
    $avatar = $emp['avatar_path'] ?? '';
    $avatarFile = basename($avatar);
    if (empty($avatarFile) || $avatarFile === 'default.png' || !file_exists(__DIR__ . '/uploads/avatars/' . $avatarFile)) {
        $missing[] = 'Profile Picture';
    }

    if (!empty($missing)) {
        $emp['missing_fields'] = $missing;
        $incompleteProfiles[] = $emp;
    }
}

$logo_paths = [
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $mime = $mimeTypes[$ext] ?? 'image/png';
        $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
} // Fallback to a 1x1 transparent PNG if no logo found
if (empty($logo_src)) {
    $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
} // Fallback to a 1x1 transparent PNG if no logo found
if (empty($logo_src)) {
    $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
} ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Missing Fields Report</title>
    <link rel="icon" href="<?php echo $logo_src; ?>" type="image/png">
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

            .print-only-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #666;
                padding-bottom: 10px;
            }

            .print-only-header img {
                height: 60px;
                margin-bottom: 10px;
            }

            .print-only-header h2 {
                font-size: 14pt;
                font-weight: bold;
                margin: 0;
            }
        }

        .print-only-header {
            display: none;
        }
    </style>
</head>

<body class="bg-light">
    <div class="print-only-header">
        <img src="<?php echo $logo_src; ?>" alt="TESP Logo">
        <h2>Incomplete Profiles Report</h2>
    </div>

    <nav class="navbar navbar-dark bg-dark mb-4 no-print">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center gap-2 w-100">
                <a class="navbar-brand" href="index.php">Back to Dashboard</a>
                <span class="navbar-text text-white me-auto"><i class="bi bi-exclamation-triangle-fill text-warning"></i> Incomplete Profiles Report</span>
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode"><i class="bi bi-moon-stars-fill"></i></button>
            </div>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('darkModeToggle');
            if (!toggle) return;

            toggle.addEventListener('click', function() {
                document.body.classList.toggle('bg-dark');
                document.body.classList.toggle('text-white');
                document.body.classList.toggle('bg-light');
                const nav = document.querySelector('nav.navbar');
                if (nav) {
                    nav.classList.toggle('navbar-dark');
                    nav.classList.toggle('navbar-light');
                    nav.classList.toggle('bg-dark');
                }
            });
        });
    </script>
</body>

</html>