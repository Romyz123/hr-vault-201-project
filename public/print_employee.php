<?php
// --- START: UI REPAIR ---
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

// Fetch Disciplinary Records
$discStmt = $pdo->prepare("SELECT * FROM disciplinary_cases WHERE employee_id = ? ORDER BY incident_date DESC");
$discStmt->execute([$emp['emp_id']]);
$disciplinaryCases = $discStmt->fetchAll();

// Fetch Performance Evaluations (Tries new table first, falls back to legacy)
$performanceReviews = [];
try {
    $perfStmt = $pdo->prepare("
        SELECT p.review_date, p.rating, p.strengths as remarks,
               COALESCE(p.custom_reviewer, u.account_owner, u.username) as reviewer,
               'new' as source, NULL as score
        FROM hr_performance_reviews p
        LEFT JOIN users u ON p.reviewer_id = u.id
        WHERE p.employee_id = ?
        ORDER BY p.review_date DESC
    ");
    $perfStmt->execute([$emp['id']]);
    $performanceReviews = $perfStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("print_employee.php (performance review) error: " . $e->getMessage());
    try {
        $perfStmt = $pdo->prepare("SELECT eval_date as review_date, rating, remarks, evaluator as reviewer, 'legacy' as source, score FROM performance_evaluations WHERE employee_id = ? ORDER BY eval_date DESC");
        $perfStmt->execute([$emp['id']]);
        $performanceReviews = $perfStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        error_log("print_employee.php (performance review legacy) error: " . $e2->getMessage());
    }
}

// [NEW] Fetch Employment History
$history = [];
try {
    $histStmt = $pdo->prepare("SELECT * FROM employment_history WHERE employee_id = ? ORDER BY event_date DESC");
    $histStmt->execute([$id]);
    $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("print_employee.php (employment history) error: " . $e->getMessage());
}
// [NEW] Handle Word Export Logic
$isWordExport = isset($_GET['export']) && $_GET['export'] === 'word';
if ($isWordExport) {
    header("Content-type: application/vnd.ms-word");
    header("Content-Disposition: attachment;Filename=Employee_Profile_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $emp['emp_id']) . ".doc");

    // Base64 encode the avatar so it works offline in MS Word
    $avatarFile = basename($emp['avatar_path'] ?: 'default.png');
    $avatarDir = __DIR__ . '/uploads/avatars/';
    $avatarPath = $avatarDir . $avatarFile;
    $avatarSrc = '';
    $avatarDirReal = realpath($avatarDir);
    $avatarReal = realpath($avatarPath);
    if ($avatarDirReal && $avatarReal && strncmp($avatarReal, rtrim($avatarDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($avatarDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) === 0 && file_exists($avatarReal)) {
        $avatarSrc = 'data:image/' . pathinfo($avatarReal, PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($avatarReal));
    }
} else {
    $avatarFile = basename($emp['avatar_path'] ?: 'default.png');
    $avatarDir = __DIR__ . '/uploads/avatars/';
    $avatarPath = $avatarDir . $avatarFile;
    $avatarDirReal = realpath($avatarDir);
    $avatarReal = realpath($avatarPath);
    if ($avatarDirReal && $avatarReal && strncmp($avatarReal, rtrim($avatarDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($avatarDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) === 0 && file_exists($avatarReal)) {
        $avatarSrc = 'uploads/avatars/' . basename($avatarReal);
    } else {
        $avatarSrc = 'uploads/avatars/default.png';
    }
}

// [FIX] Use a more robust method to find the logo, checking multiple common paths.
$logo_paths = [
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $mime = pathinfo($p, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg';
        $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
}

// Fallback to a simple placeholder if no logo found
if (empty($logo_src)) {
    $logo_src = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><text y="30" font-size="14" fill="#333">TES</text></svg>');
} ?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <title>Print Profile - <?php echo htmlspecialchars($emp['last_name']); ?></title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/tesp-logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/tesp-logo.png">
    <link rel="apple-touch-icon" href="assets/tesp-logo.png">
    <style>
        /* 1. Force Browser to recognize A4 paper */
        @page {
            size: A4;
            margin: 0.5in;
        }

        /* 2. Print Specifics */
        @media print {

            body,
            html {
                background: white !important;
                color: black !important;
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print {
                display: none !important;
            }

            /* // --- START: PRINT FIX --- */
            @media print {
                .print-logo {
                    max-height: 70px !important;
                    width: auto !important;
                    display: block !important;
                    margin: 0 auto 10px auto !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                }

                .print-header {
                    text-align: center !important;
                    border-bottom: 2px solid #000 !important;
                    margin-bottom: 20px !important;
                    padding-bottom: 10px !important;
                    display: block !important;
                }
            }

            /* // --- END: PRINT FIX --- */

            .print-title-area {
                text-align: center;
                flex-grow: 1;
            }

            .page {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0.5in !important;
                /* Adds professional margin inside the printed page */
                border: none !important;
                width: 100% !important;
            }

            /* Logo print styling */
            .page img {
                max-width: 100%;
                height: auto !important;
            }

            /* Disable animations on print */
            * {
                animation: none !important;
                transition: none !important;
            }

            .page-break {
                page-break-before: always;
            }

            .avoid-break {
                page-break-inside: avoid;
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
            padding: 0.5in;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            box-sizing: border-box;
            /* Ensures padding doesn't expand width */
        }

        /* // --- START: PRINT FIX --- */
        .print-logo {
            max-height: 70px;
        }

        .print-header {
            text-align: center;
            border-bottom: 2px solid #000;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }

        /* // --- END: PRINT FIX --- */

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

        <?php if ($isWordExport): ?>

        /* Specific overrides to force MS Word to render tables nicely */
        body {
            background: white !important;
            font-family: "Times New Roman", Times, serif !important;
            color: #000 !important;
        }

        .page {
            width: 100% !important;
            margin: 0 !important;
            padding: 0.5in !important;
            /* Professional margin for MS Word export */
            box-shadow: none !important;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
        }

        th {
            background-color: #f0f0f0;
        }

        <?php endif; ?>
    </style>
</head>

<body>

    <?php if (!$isWordExport): ?>
        <div class="container py-3 no-print">
            <div class="p-3 bg-light border rounded shadow-sm d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h6 class="fw-bold mb-2 text-primary"><i class="bi bi-ui-checks"></i> Sections to Include:</h6>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-personal" value="sec-personal" checked>
                        <label class="form-check-label small" for="chk-personal">Personal Info</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-employment" value="sec-employment" checked>
                        <label class="form-check-label small" for="chk-employment">Employment</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-govt" value="sec-govt" checked>
                        <label class="form-check-label small" for="chk-govt">Gov't IDs</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-docs" value="sec-docs" checked>
                        <label class="form-check-label small" for="chk-docs">Documents</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-disc" value="sec-disc" checked>
                        <label class="form-check-label small" for="chk-disc">Disciplinary</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-perf" value="sec-perf" checked>
                        <label class="form-check-label small" for="chk-perf">Performance</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input section-toggle" type="checkbox" id="chk-emergency" value="sec-emergency" checked>
                        <label class="form-check-label small" for="chk-emergency">Emergency</label>
                    </div>
                </div>
                <div class="text-end text-nowrap">
                    <button onclick="window.print()" class="btn btn-warning fw-bold shadow-sm"><i class="bi bi-printer"></i> Print Profile</button>
                    <a href="?id=<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>&export=word" class="btn btn-primary fw-bold shadow-sm ms-1"><i class="bi bi-file-word"></i> Export to Word</a> <a href="index.php" class="btn btn-secondary shadow-sm ms-1">Back</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="page">

        <!-- Professional Company Header -->
        <!-- // --- START: PRINT FIX --- -->
        <div class="print-header">
            <img src="<?php echo $logo_src; ?>" alt="TESP Logo" class="print-logo">
            <div style="font-size: 16pt; font-weight: bold; text-transform: uppercase;">TES Philippines, Inc.</div>
            <div style="font-size: 14pt; font-weight: bold; text-transform: uppercase;">Employee 201 File</div>
        </div>
        <!-- // --- END: PRINT FIX --- -->

        <!-- Employee Summary -->
        <div class="d-flex align-items-center mb-4 avoid-break">
            <img src="<?php echo $avatarSrc; ?>"
                alt="Profile Photo"
                style="width: 130px; height: 130px; object-fit: cover; border: 2px solid #333; margin-right: 25px;"
                onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">

            <div>
                <h2 style="margin: 0; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars(strtoupper($emp['last_name'] . ', ' . $emp['first_name'])); ?></h2>
                <div style="font-size: 1.25rem; color: #555; margin-bottom: 8px;"><?php echo htmlspecialchars($emp['job_title']); ?></div>
            </div>
        </div>

        <div id="sec-personal" class="avoid-break">
            <div class="section-title">Personal Information</div>
            <div class="row g-3">
                <div class="col-4">
                    <div class="data-label">Date of Birth</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['birth_date']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Contact Number</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['contact_number']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Email</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['email']); ?></div>
                </div>
                <div class="col-12">
                    <div class="data-label">Present Address</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['present_address']); ?></div>
                </div>
                <div class="col-12">
                    <div class="data-label">Permanent Address</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['permanent_address']); ?></div>
                </div>
            </div>
        </div>

        <div id="sec-employment" class="avoid-break mt-4">
            <div class="section-title">Employment Details</div>
            <div class="row g-3">
                <div class="col-4">
                    <div class="data-label">Employee ID</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['emp_id']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Employment Type</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['employment_type']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Agency (If Applicable)</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['agency_name']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Date Hired</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['hire_date']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Department</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['dept']); ?></div>
                </div>
                <div class="col-4">
                    <div class="data-label">Section</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['section']); ?></div>
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
        </div>
        <div id="sec-govt" class="avoid-break mt-4">
            <div class="section-title">Government Contributions</div>
            <div class="row g-3">
                <div class="col-3">
                    <div class="data-label">SSS Number</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['sss_no']); ?></div>
                </div>
                <div class="col-3">
                    <div class="data-label">TIN Number</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['tin_no']); ?></div>
                </div>
                <div class="col-3">
                    <div class="data-label">PhilHealth</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['philhealth_no']); ?></div>
                </div>
                <div class="col-3">
                    <div class="data-label">Pag-IBIG</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['pagibig_no']); ?></div>
                </div>
            </div>
        </div>
        <div id="sec-emergency" class="avoid-break mt-4">
            <div class="section-title">In Case of Emergency</div>
            <div class="row g-3">
                <div class="col-6">
                    <div class="data-label">Contact Person</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['emergency_name']); ?></div>
                </div>
                <div class="col-6">
                    <div class="data-label">Contact Number</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['emergency_contact']); ?></div>
                </div>
                <div class="col-12">
                    <div class="data-label">Address</div>
                    <div class="data-value"><?php echo htmlspecialchars($emp['emergency_address']); ?></div>
                </div>
            </div>
        </div>
        <div id="sec-docs" class="page-break avoid-break mt-4">
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
        </div>

        <div id="sec-disc" class="avoid-break mt-4">
            <div class="section-title">Disciplinary Records</div>
            <table class="table table-sm table-bordered mt-2" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Incident Date</th>
                        <th>Violation / Offense</th>
                        <th>Action Taken</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($disciplinaryCases) > 0): foreach ($disciplinaryCases as $case): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($case['incident_date'])); ?></td>
                                <td><?php echo htmlspecialchars($case['violation_type']); ?></td>
                                <td><?php echo htmlspecialchars($case['action_taken']); ?></td>
                                <td><?php echo htmlspecialchars($case['status']); ?></td>
                            </tr>
                        <?php endforeach;
                    else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No disciplinary records on file.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="sec-history" class="avoid-break mt-4">
            <div class="section-title">Employment History</div>
            <table class="table table-sm table-bordered mt-2" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Event Date</th>
                        <th>Title / Position</th>
                        <th>Department</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($history)): foreach ($history as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($event['event_date']))); ?></td>
                                <td><?php echo htmlspecialchars($event['event_title']); ?></td>
                                <td><?php echo htmlspecialchars($event['department'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($event['notes'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach;
                    else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No employment history records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="sec-perf" class="avoid-break mt-4">
            <div class="section-title">Performance Evaluations</div>
            <table class="table table-sm table-bordered mt-2" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Review Date</th>
                        <th>Rating</th>
                        <th>Evaluator</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($performanceReviews) > 0): foreach ($performanceReviews as $rev): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($rev['review_date'])); ?></td>
                                <td>
                                    <?php
                                    if ($rev['source'] === 'new') {
                                        echo htmlspecialchars($rev['rating']) . ' / 5 Stars';
                                    } else {
                                        echo htmlspecialchars($rev['score']) . '% (' . htmlspecialchars($rev['rating']) . ')';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($rev['reviewer']); ?></td>
                            </tr>
                        <?php endforeach;
                    else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">No performance reviews on file.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-5 pt-5 text-center avoid-break">
            <div style="border-top: 1px solid #000; width: 200px; margin: 0 auto;"></div>
            <small>HR Verified Signature</small>
        </div>
    </div>

    <?php if (!$isWordExport): ?>
        <script>
            // Handle section toggles
            document.querySelectorAll('.section-toggle').forEach(function(chk) {
                chk.addEventListener('change', function() {
                    var target = document.getElementById(this.value);
                    if (target) {
                        target.style.display = this.checked ? 'block' : 'none';
                    }
                });
            });
        </script>
    <?php endif; ?>
</body>

</html>