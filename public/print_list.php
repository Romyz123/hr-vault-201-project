<?php
// ======================================================
// [FILE] public/print_list.php
// [STATUS] DESIGN: Original (Restored) | LOGIC: Fixed
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Validator.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 1. CAPTURE FILTERS
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_type   = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filter_dept   = isset($_GET['dept'])   ? trim($_GET['dept'])   : '';
$search_query  = Validator::sanitizeSearch($_GET['search'] ?? '');

// 2. BUILD QUERY
// [FIX 1] Added 'agency_name' to the SELECT list so we can display it.
$sql = "SELECT emp_id, last_name, first_name, job_title, dept, section, employment_type, agency_name, hire_date, status 
        FROM employees WHERE 1=1";
$params = [];

if (!empty($filter_status)) {
    $sql .= " AND status = ?";
    $params[] = $filter_status;
}

// [FIX 2] Updated Filter Logic to search BOTH Employment Type AND Agency Name
// This ensures "Joratech", "UnliSolutions", etc. work correctly.
if (!empty($filter_type)) {
    $sql .= " AND (employment_type = ? OR agency_name = ?)";
    $params[] = $filter_type;
    $params[] = $filter_type;
}

if (!empty($filter_dept)) {
    $sql .= " AND dept = ?";
    $params[] = $filter_dept;
}

if (!empty($search_query)) {
    $terms = preg_split('/[\s,]+/', $search_query, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $sql .= " AND (emp_id LIKE ? ESCAPE '\\' OR first_name LIKE ? ESCAPE '\\' OR last_name LIKE ? ESCAPE '\\')";
        $escaped = addcslashes($term, '%_');
        $t = "%$escaped%";
        array_push($params, $t, $t, $t);
    }
}
$sql .= " ORDER BY last_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$logo_paths = [
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
$logo_mime = 'image/png';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $size = filesize($p);
        if ($size === false || $size > 512 * 1024) { // Skip files > 512KB
            continue;
        }
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $logo_mime = $finfo->file($p) ?: 'image/png';
        } elseif (function_exists('mime_content_type')) {
            $logo_mime = mime_content_type($p) ?: 'image/png';
        } else {
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            $logo_mime = ($ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'gif' ? 'image/gif' : 'image/png')));
        }
        // Validate MIME is an image type
        if (strpos($logo_mime, 'image/') !== 0) {
            continue;
        }
        $content = file_get_contents($p);
        if ($content !== false) {
            $logo_src = 'data:' . $logo_mime . ';base64,' . base64_encode($content);
            break;
        }
    }
}
if (empty($logo_src)) {
    $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mO8WQ8AAn0BbYpM8nsAAAAASUVORK5CYII=';
    $logo_mime = 'image/png';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <title>Employee Master List</title>
    <?php if (!empty($logo_src)): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($logo_src); ?>" type="<?php echo htmlspecialchars($logo_mime); ?>">
    <?php endif; ?>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <style>
        /* 1. Force A4 Landscape */
        @page {
            size: A4 landscape;
            margin: 10mm;
            /* Small margin for printer limits */
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: white;
                -webkit-print-color-adjust: exact;
                /* For Chrome/Safari */
                print-color-adjust: exact;
            }

            .page {
                box-shadow: none;
                margin: 0;
                width: 100%;
            }

            /* Fix Table Borders for printing */
            .table-bordered th,
            .table-bordered td {
                border: 1px solid #000 !important;
            }
        }

        body {
            background: #eee;
        }

        .page {
            background: white;
            width: 297mm;
            /* A4 Landscape Width */
            min-height: 210mm;
            margin: 20px auto;
            padding: 10mm;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        }

        .table-sm {
            font-size: 0.8rem;
        }

        /* Slightly smaller text to fit everything */
    </style>
</head>

<body>

    <div class="text-center py-3 no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg fw-bold">🖨️ Print / Save as PDF</button>
        <button onclick="window.close()" class="btn btn-secondary btn-lg">Close</button>
    </div>

    <div class="page">
        <div class="d-flex justify-content-between align-items-end mb-4 border-bottom pb-2">
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($logo_src)): ?>
                    <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="TES Philippines Logo" style="height: 60px; object-fit: contain;">
                <?php endif; ?>
                <div>
                    <h2 class="fw-bold mb-0">TES PHILIPPINES</h2>
                    <h5 class="text-muted mb-0">Master Employee List</h5>
                </div>
            </div>
            <div class="text-end">
                <small class="text-muted">Generated on: <?php echo date('M d, Y'); ?></small><br>
                <small class="text-muted">Total Records: <strong><?php echo count($employees); ?></strong></small>
            </div>
        </div>

        <table class="table table-bordered table-striped table-sm">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Job Title</th>
                    <th>Dept</th>
                    <th>Section</th>
                    <th>Type</th>
                    <th>Hired Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></td>
                        <td><?php echo htmlspecialchars($emp['job_title']); ?></td>
                        <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                        <td><?php echo htmlspecialchars($emp['section']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($emp['agency_name'] ?: $emp['employment_type']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($emp['hire_date']); ?></td>
                        <td><?php echo htmlspecialchars($emp['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="mt-4 text-center no-print">
            <small class="text-muted">-- End of Report --</small>
        </div>
    </div>

</body>

</html>