<?php
// public/missing_fields_report.php
require '../config/db.php';
session_start();

// 1. SECURITY: Admin & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER', 'HR'], true)) {
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

    // Check for missing or default Profile Picture
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

// 4. EXCEL / CSV EXPORT HANDLER
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "Incomplete_Profiles_Report_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM for proper Excel character encoding
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // CSV Headers
    fputcsv($output, ['Employee ID', 'Last Name', 'First Name', 'Department', 'Missing Requirements', 'Total Missing']);

    // CSV Data Rows
    foreach ($incompleteProfiles as $emp) {
        fputcsv($output, [
            $emp['emp_id'],
            $emp['last_name'],
            $emp['first_name'],
            $emp['dept'],
            implode(' | ', $emp['missing_fields']),
            count($emp['missing_fields'])
        ]);
    }
    fclose($output);
    exit;
}

// 5. EMBEDDED LOGO PREPARATION
$logo_paths = [
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/assets/tesp-logo.png',
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
}
if (empty($logo_src)) {
    $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Incomplete Profiles Report - TES Philippines</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        /* ======================================================
           PRINT STYLES: PROFESSIONAL LETTERHEAD REPORT
           ====================================================== */
        @media print {
            @page {
                size: A4 portrait;
                margin: 0.6in 0.6in;
            }

            body {
                background: #fff !important;
                color: #000 !important;
                font-family: "Times New Roman", Times, serif !important;
                font-size: 10.5pt;
                line-height: 1.3;
            }

            .no-print {
                display: none !important;
            }

            .card {
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }

            .print-only-header {
                display: block !important;
                margin-bottom: 20px;
            }

            .print-table {
                width: 100%;
                border-collapse: collapse !important;
            }

            .print-table thead {
                display: table-header-group;
                /* Repeats table header across pages */
            }

            .print-table th {
                background-color: #f2f2f2 !important;
                color: #000 !important;
                border: 1px solid #000 !important;
                padding: 6px 8px !important;
                font-weight: bold;
                text-transform: uppercase;
                font-size: 9.5pt;
            }

            .print-table td {
                border: 1px solid #666 !important;
                padding: 6px 8px !important;
                font-size: 10pt;
                vertical-align: top;
            }

            .print-badge {
                display: inline-block;
                border: 1px solid #333;
                background-color: #f8f9fa !important;
                color: #000 !important;
                padding: 2px 6px;
                margin: 1px;
                border-radius: 3px;
                font-size: 8.5pt;
                font-family: Arial, sans-serif;
            }

            .print-signature-section {
                display: block !important;
                margin-top: 40px;
                page-break-inside: avoid;
            }
        }

        .print-only-header,
        .print-signature-section {
            display: none;
        }

        /* Screen-only adjustments */
        [data-bs-theme="dark"] .table-dark-custom {
            background-color: #212529;
            color: #fff;
        }
    </style>
</head>

<body class="bg-body-tertiary">

    <div class="print-only-header">
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
            <tr>
                <td style="width: 90px; vertical-align: middle; padding-right: 15px;">
                    <img src="<?php echo $logo_src; ?>" style="width: 75px; height: 75px; object-fit: contain;" alt="TESP Logo">
                </td>
                <td style="text-align: left; vertical-align: middle;">
                    <div style="font-weight: bold; font-size: 13pt; line-height: 1.2;">TES PHILIPPINES, INC.</div>
                    <div style="font-weight: bold; font-size: 10pt; line-height: 1.2; color: #444;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                    <div style="font-size: 9pt; color: #555;">Meriton One Building, 1668 Quezon Avenue, Quezon City | Tel: 8929-5347 loc. 4404</div>
                </td>
                <td style="text-align: right; vertical-align: top; font-size: 9pt; color: #555;">
                    <div><strong>Date Generated:</strong> <?php echo date('F d, Y'); ?></div>
                    <div><strong>Total Pending:</strong> <?php echo count($incompleteProfiles); ?> Profiles</div>
                </td>
            </tr>
        </table>
        <hr style="border: none; border-top: 2px solid #000; margin: 10px 0 15px 0;">
        <div style="text-align: center; font-weight: bold; font-size: 12pt; text-transform: uppercase; letter-spacing: 0.5px;">
            HR 201 File Compliance - Incomplete Profiles Audit Report
        </div>
    </div>

    <nav class="navbar navbar-dark bg-dark mb-4 no-print">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center gap-2 w-100">
                <a class="navbar-brand fs-6" href="index.php"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <span class="navbar-text text-white me-auto"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> Incomplete Profiles Report</span>
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode"><i class="bi bi-moon-stars-fill" id="darkModeIcon"></i></button>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h4 class="mb-1 fw-bold">Audit Report: Missing Requirements</h4>
                <p class="text-muted mb-0">Found <strong><?php echo count($incompleteProfiles); ?></strong> active employees with incomplete 201 file records.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="missing_fields_report.php?export=excel" class="btn btn-outline-success shadow-sm fw-bold">
                    <i class="bi bi-file-earmark-excel-fill"></i> Export to Excel
                </a>
                <button onclick="window.print()" class="btn btn-dark shadow-sm fw-bold">
                    <i class="bi bi-printer-fill"></i> Print Report
                </button>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-5">
            <div class="card-body p-0">
                <table class="table table-hover print-table mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 8%;">ID</th>
                            <th style="width: 22%;">Employee Name</th>
                            <th style="width: 18%;">Department</th>
                            <th>Missing Fields / Requirements</th>
                            <th class="no-print text-center" style="width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incompleteProfiles)): ?>
                            <tr>
                                <td colspan="5" class="text-center p-5 text-muted">
                                    <i class="bi bi-check-circle fs-1 text-success"></i><br>
                                    <span class="fw-bold mt-2 d-block">All employee 201 profiles are complete!</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($incompleteProfiles as $emp): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                                    <td>
                                        <?php foreach ($emp['missing_fields'] as $field): ?>
                                            <span class="badge bg-danger mb-1 print-badge"><?php echo htmlspecialchars($field); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="no-print text-center">
                                        <a href="edit_employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn btn-sm btn-primary shadow-sm" target="_blank" title="Edit Employee Profile">
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

        <div class="print-signature-section">
            <table style="width: 100%; border-collapse: collapse; margin-top: 30px;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        <div>Prepared By:</div>
                        <div style="margin-top: 40px; border-top: 1px solid #000; width: 220px; font-weight: bold; padding-top: 4px;">
                            HR Administrative Staff
                        </div>
                        <div style="font-size: 8.5pt; color: #555;">Human Resources Department</div>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <div>Noted / Approved By:</div>
                        <div style="margin-top: 40px; border-top: 1px solid #000; width: 220px; font-weight: bold; padding-top: 4px;">
                            HR Department Manager
                        </div>
                        <div style="font-size: 8.5pt; color: #555;">TES Philippines, Inc.</div>
                    </td>
                </tr>
            </table>
        </div>

    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        // DARK MODE TOGGLE SCRIPT
        document.addEventListener("DOMContentLoaded", function() {
            const toggleBtn = document.getElementById('darkModeToggle');
            const icon = document.getElementById('darkModeIcon');

            function applyTheme(theme) {
                document.documentElement.setAttribute('data-bs-theme', theme);
                if (icon) {
                    icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
                }
            }

            const currentTheme = localStorage.getItem('theme') || 'light';
            applyTheme(currentTheme);

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    const activeTheme = document.documentElement.getAttribute('data-bs-theme');
                    const newTheme = activeTheme === 'dark' ? 'light' : 'dark';
                    localStorage.setItem('theme', newTheme);
                    applyTheme(newTheme);
                });
            }
        });
    </script>
</body>

</html>