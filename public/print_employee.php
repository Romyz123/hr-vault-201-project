<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

if (!isset($_GET['id'])) {
    die("Invalid ID");
}
$id = $_GET['id'];

// Fetch Employee
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp) die("Employee not found");

// Fetch Documents
$docStmt = $pdo->prepare("SELECT * FROM documents WHERE employee_id = ?");
$docStmt->execute([$emp['emp_id']]);
$docs = $docStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Print Profile - <?php echo htmlspecialchars($emp['last_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* 1. Force Browser to recognize A4 paper */
        @page {
            size: A4;
            margin: 0;
            /* Important: Removes default browser header/footer urls */
        }

        /* 2. Print Specifics */
        @media print {

            body,
            html {
                width: 210mm;
                height: 297mm;
                background: white;
                margin: 0;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .page {
                box-shadow: none !important;
                margin: 0 !important;
                border: none !important;
                width: 100% !important;
                page-break-after: always;
                /* Ensures multi-page profiles print cleanly */
            }
        }

        /* 3. Screen Preview Styles */
        body {
            background: #555;
        }

        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 15mm 20mm;
            /* Top/Bottom: 15mm, Left/Right: 20mm */
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            box-sizing: border-box;
            /* Ensures padding doesn't expand width */
        }

        /* 4. Content Styling */
        .profile-img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border: 1px solid #333;
        }

        .section-title {
            border-bottom: 2px solid #333;
            padding-bottom: 5px;
            margin-top: 25px;
            margin-bottom: 15px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 1.1rem;
        }

        .data-label {
            font-weight: 600;
            color: #555;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .data-value {
            font-weight: bold;
            font-size: 1rem;
            border-bottom: 1px dotted #ccc;
            padding-bottom: 2px;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>

    <div class="container text-center py-3 no-print">
        <button onclick="window.print()" class="btn btn-warning btn-lg fw-bold"><i class="bi bi-printer"></i> Print / Save as PDF</button>
        <a href="index.php" class="btn btn-light ms-2">Back to Dashboard</a>
    </div>

    <div class="page">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">

                <img src="uploads/avatars/<?php echo htmlspecialchars($emp['avatar_path'] ?: 'default.png'); ?>"
                    alt="Profile Photo"
                    style="width: 150px; height: 150px; object-fit: cover; border: 1px solid #000;"
                    onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">


                <div>
                    <h1 class="m-0 fw-bold"><?php echo strtoupper($emp['last_name'] . ', ' . $emp['first_name']); ?></h1>
                    <p class="mb-0 fs-5"><?php echo $emp['job_title']; ?></p>
                    <div class="mt-2">
                        <span class="badge rounded-pill"><?php echo $emp['emp_id']; ?></span>
                        <span class="badge rounded-pill"><?php echo $emp['dept']; ?></span>
                        <span class="badge rounded-pill"><?php echo $emp['status']; ?></span>
                    </div>
                </div>
            </div>
            <div class="text-end">
                <h5 class="fw-bold mb-0">TES PHILIPPINES</h5>
                <small>Human Resources Department</small><br>
                <small>201 Employee File</small>
            </div>
        </div>

        <div class="section-title">Personal Information</div>
        <div class="row g-3">
            <div class="col-4">
                <div class="data-label">Date of Birth</div>
                <div class="data-value"><?php echo $emp['birth_date']; ?></div>
            </div>
            <div class="col-4">
                <div class="data-label">Contact Number</div>
                <div class="data-value"><?php echo $emp['contact_number']; ?></div>
            </div>
            <div class="col-4">
                <div class="data-label">Email</div>
                <div class="data-value"><?php echo $emp['email']; ?></div>
            </div>
            <div class="col-12">
                <div class="data-label">Present Address</div>
                <div class="data-value"><?php echo $emp['present_address']; ?></div>
            </div>
            <div class="col-12">
                <div class="data-label">Permanent Address</div>
                <div class="data-value"><?php echo $emp['permanent_address']; ?></div>
            </div>
        </div>

        <div class="section-title">Employment Details</div>
        <div class="row g-3">
            <div class="col-6">
                <div class="data-label">Employment Type</div>
                <div class="data-value"><?php echo $emp['employment_type']; ?></div>
            </div>
            <div class="col-6">
                <div class="data-label">Agency (If Applicable)</div>
                <div class="data-value"><?php echo $emp['agency_name']; ?></div>
            </div>
            <div class="col-4">
                <div class="data-label">Date Hired</div>
                <div class="data-value"><?php echo $emp['hire_date']; ?></div>
            </div>
            <div class="col-4">
                <div class="data-label">Department</div>
                <div class="data-value"><?php echo $emp['dept']; ?></div>
            </div>
            <div class="col-4">
                <div class="data-label">Section</div>
                <div class="data-value"><?php echo $emp['section']; ?></div>
            </div>
        </div>

        <div class="section-title">Government Contributions</div>
        <div class="row g-3">
            <div class="col-3">
                <div class="data-label">SSS Number</div>
                <div class="data-value"><?php echo $emp['sss_no']; ?></div>
            </div>
            <div class="col-3">
                <div class="data-label">TIN Number</div>
                <div class="data-value"><?php echo $emp['tin_no']; ?></div>
            </div>
            <div class="col-3">
                <div class="data-label">PhilHealth</div>
                <div class="data-value"><?php echo $emp['philhealth_no']; ?></div>
            </div>
            <div class="col-3">
                <div class="data-label">Pag-IBIG</div>
                <div class="data-value"><?php echo $emp['pagibig_no']; ?></div>
            </div>
        </div>

        <div class="section-title">In Case of Emergency</div>
        <div class="row g-3">
            <div class="col-6">
                <div class="data-label">Contact Person</div>
                <div class="data-value"><?php echo $emp['emergency_name']; ?></div>
            </div>
            <div class="col-6">
                <div class="data-label">Contact Number</div>
                <div class="data-value"><?php echo $emp['emergency_contact']; ?></div>
            </div>
            <div class="col-12">
                <div class="data-label">Address</div>
                <div class="data-value"><?php echo $emp['emergency_address']; ?></div>
            </div>
        </div>

        <div class="section-title">Qualifications & Educational Background</div>
        <div class="row g-3">
            <div class="col-12">
                <div class="data-label">Education</div>
                <div class="data-value"><?php echo nl2br(htmlspecialchars($emp['education'] ?? '')); ?></div>
            </div>
            <div class="col-12">
                <div class="data-label">Experience</div>
                <div class="data-value"><?php echo nl2br(htmlspecialchars($emp['experience'] ?? '')); ?></div>
            </div>
            <div class="col-6">
                <div class="data-label">Skills</div>
                <div class="data-value"><?php echo nl2br(htmlspecialchars($emp['skills'] ?? '')); ?></div>
            </div>
            <div class="col-6">
                <div class="data-label">Licenses</div>
                <div class="data-value"><?php echo nl2br(htmlspecialchars($emp['licenses'] ?? '')); ?></div>
            </div>
        </div>

        <div class="section-title">Submitted Documents</div>
        <table class="table table-sm table-bordered mt-2" style="font-size: 0.85rem;">
            <thead class="table-light">
                <tr>
                    <th>Document Name</th>
                    <th>Category</th>
                    <th>Date Uploaded</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($docs) > 0): foreach ($docs as $doc): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($doc['original_name']); ?></td>
                            <td><?php echo htmlspecialchars($doc['category']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></td>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted">No documents on file.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="mt-5 pt-5 text-center">
            <div style="border-top: 1px solid #000; width: 200px; margin: 0 auto;"></div>
            <small>HR Verified Signature</small>
        </div>

    </div>

</body>

</html>