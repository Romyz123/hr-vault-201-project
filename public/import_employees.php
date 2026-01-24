<?php
// ======================================================
// [FILE] public/import_employees.php
// [STATUS] FINAL: Smart Priority Order + Syntax Fixes
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Admin & HR Only
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    die("ACCESS DENIED");
}

$security = new Security($pdo);
$logger   = new Logger($pdo);
$msg = "";
$error = "";

// ---------------- CONFIGURATION ----------------
// [IMPORTANT] Order matters! Specific departments (SQP, SIGCOM) come first
// to prevent generic keywords (like "IT" or "Safety") from being grabbed by Admin.
$deptMap = [
    // 1. SQP (Safety, Quality, Planning) - High Priority
    "SQP" => [
        "SQP", 
        "SAFETY, QUALITY AND PLANNING", 
        "SAFETY QUALITY PLANNING",
        "SQP-PLANNING GROUP",
        "SQP -QUALITY ASSURANCE GROUP",
        "SQP-SAFETY GROUP",
        "SQP -IT GROUP",  
        "IT", // <--- Moved here. Now ALL "IT" staff will go to SQP.
        "SQP-SAFETY HEAD",
        "SAFETY", 
        "QA", 
        "PLANNING",
        "QUALITY ASSURANCE"
    ],

    // 2. SIGCOM (Signaling & Communication) - High Priority
    "SIGCOM" => [
        "SIGCOM", 
        "SIG", 
        "SIGNALING", 
        "COMMUNICATION", 
        "SIGNAL", 
        "SIGNAL & COMMUNICATION", 
        "SIGNALING AND COMMUNICATIOMN" // Handles the typo
    ],

    // 3. Other Technical Depts
    "HMS"     => ["HEAVY MAINTENANCE", "HMS"],
    "RAS"     => ["ROOT CAUSE", "RAS"],
    "TRS"     => ["TECHNICAL RESEARCH", "TRS"],
    "LMS"     => ["LIGHT MAINTENANCE", "LMS"],
    "DOS"     => ["DEPARTMENT OPERATIONS", "DOS"],
    "CTS"     => ["CIVIL TRACKS", "CTS"],
    "PSS"     => ["POWER SUPPLY", "PSS"],
    "OCS"     => ["OVERHEAD", "OCS", "CATENARY"],
    "BFS"     => ["BUILDING FACILITIES", "BFS"],
    "WHS"     => ["WAREHOUSE", "WHS"],
    
    // 4. Security / Gunjin
    "GUNJIN"  => ["EMT", "SECURITY", "GUNJIN"],

    // 5. Admin (Low Priority)
    "ADMIN"   => [
        "ADMIN", 
        "GAG", 
        "TKG", 
        "PCG", 
        "ACG", 
        "MED", 
        "OP", 
        "CLEANERS/HOUSE KEEPING" 
        // Removed "IT" from here because you moved it to SQP
    ],

    "SUBCONS-OTHERS" => ["OTHERS"]
];

function findDept($section, $map) {
    $section = strtoupper(trim($section));
    
    // 1. Exact Key Match (e.g. if Section is literally "SQP")
    if (array_key_exists($section, $map)) return $section;
    
    // 2. Keyword Search
    foreach ($map as $dept => $keywords) {
        foreach ($keywords as $k) {
            // Check if keyword exists inside the section name
            if (strpos($section, $k) !== false) {
                return $dept;
            }
        }
    }
    return "SUBCONS-OTHERS"; 
}

function parseDate($dateStr) {
    if (empty($dateStr)) return NULL;
    $timestamp = strtotime($dateStr);
    if ($timestamp === false || $timestamp < 0) {
        // Excel dd/mm/yyyy fix
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        return NULL;
    }
    return date('Y-m-d', $timestamp);
}

// ======================================================
// 2. HANDLE UNDO ACTION
// ======================================================
if (isset($_POST['undo_batch'])) {
    $batch_to_delete = $_POST['undo_batch'];
    if (strpos($batch_to_delete, 'BATCH_') === 0) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE import_batch = ?");
            $stmt->execute([$batch_to_delete]);
            $count = $stmt->fetchColumn();

            $del = $pdo->prepare("DELETE FROM employees WHERE import_batch = ?");
            $del->execute([$batch_to_delete]);

            $logger->log($_SESSION['user_id'], 'IMPORT_UNDO', "Undid batch $batch_to_delete");
            $msg = "✅ Undo Successful! Removed $count employees.";
        } catch (PDOException $e) {
            $error = "Undo Failed: " . $e->getMessage();
        }
    }
}

// ======================================================
// 3. HANDLE IMPORT ACTION
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    
    $agency = $_POST['agency_select'] ?? '';
    
    if ($agency == "") {
        $error = "Please select an Agency first.";
    } elseif ($_FILES['csv_file']['error'] == 0) {
        
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        $batch_id = "BATCH_" . date('Ymd_His');
        $success_count = 0;
        
        // Skip Header Row?
        fgetcsv($handle); 

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            
            // DEFAULT VARIABLES
            $emp_id = ""; $first_name = "."; $last_name = "";
            $section_raw = ""; $contact_raw = ""; $birth_raw = ""; $hire_raw = "";
            $sss_raw = ""; $tin_raw = ""; $pagibig_raw = ""; $phil_raw = "";
            $job_title = "Staff"; $email = "";
            
            // ----------------------------------------------------
            // SWITCH LOGIC: MAP COLUMNS BASED ON AGENCY
            // ----------------------------------------------------
            
            if ($agency === 'JORATECH') {
                // [JORATECH FORMAT]
                // 0:NO | 1:SECTION | 2:POSITION | 3:HIRED | 4:NUM(Ignore) | 5:PIC(Ignore) | 6:NAME | 7:CODE | 8:CONTRACT
                
                $emp_id      = trim($data[7] ?? ''); // CODE (Col H)
                $section_raw = $data[1] ?? '';       // SECTION (Col B)
                $job_title   = ucwords(strtolower(trim($data[2] ?? 'Staff'))); // POSITION (Col C)
                $hire_raw    = $data[3] ?? '';       // DATE HIRED (Col D)
                
                // NAME (Col 6 / G)
                $full_name = trim($data[6] ?? ''); 
                if (strpos($full_name, ',') !== false) {
                    $parts = explode(',', $full_name);
                    $last_name = ucwords(strtolower(trim($parts[0])));
                    $first_name = ucwords(strtolower(trim($parts[1] ?? '')));
                } else {
                    $last_name = ucwords(strtolower($full_name));
                    $first_name = ".";
                }

            } elseif ($agency === 'UNLISOLUTIONS') {
                // [UNLISOLUTIONS FORMAT]
                $emp_id = trim($data[1] ?? '');
                
                $full_name = trim($data[3] ?? ''); 
                $parts = explode(',', $full_name);
                if (count($parts) >= 2) {
                    $last_name = ucwords(strtolower(trim($parts[0])));
                    $first_name = ucwords(strtolower(trim($parts[1])));
                } else {
                    $last_name = ucwords(strtolower($full_name));
                }

                $job_title   = ucwords(strtolower(trim($data[4] ?? 'Staff')));
                $section_raw = $data[5] ?? '';
                $contact_raw = $data[6] ?? '';
                $birth_raw   = $data[7] ?? '';
                $hire_raw    = $data[8] ?? '';
                $sss_raw     = $data[9] ?? '';
                $tin_raw     = $data[10] ?? '';
                $pagibig_raw = $data[11] ?? '';
                $phil_raw    = $data[12] ?? '';
                $email       = strtolower(trim($data[14] ?? ''));

            } else {
                // [TESP / STANDARD FORMAT]
                $emp_id = trim($data[1] ?? '');
                
                $full_name = trim($data[3] ?? ''); 
                $parts = explode(',', $full_name);
                if (count($parts) >= 2) {
                    $last_name = ucwords(strtolower(trim($parts[0])));
                    $first_name = ucwords(strtolower(trim($parts[1])));
                } else {
                    $last_name = ucwords(strtolower($full_name));
                }

                $section_raw = $data[4] ?? '';
                $contact_raw = $data[5] ?? '';
                $birth_raw   = $data[6] ?? '';
                $hire_raw    = $data[7] ?? '';
                $sss_raw     = $data[8] ?? '';
                $tin_raw     = $data[9] ?? '';
                $pagibig_raw = $data[10] ?? '';
                $phil_raw    = $data[11] ?? '';
            }

            // --- PROCESSING ---
            $section = strtoupper(trim($section_raw));
            $dept    = findDept($section, $deptMap);

            // [NORMALIZATION] Convert Keywords -> Official Section Names
            if ($dept === 'SIGCOM') $section = "SIGNALING & COMMUNICATION";
            if ($dept === 'PSS')    $section = "POWER SUPPLY";
            if ($dept === 'OCS')    $section = "OVERHEAD CATENARY";
            if ($dept === 'HMS')    $section = "HEAVY MAINTENANCE";
            if ($dept === 'RAS')    $section = "ROOT CAUSE ANALYSIS";
            if ($dept === 'TRS')    $section = "TECHNICAL RESEARCH";
            if ($dept === 'LMS')    $section = "LIGHT MAINTENANCE";
            if ($dept === 'DOS')    $section = "DEPARTMENT OPERATIONS";
            if ($dept === 'CTS')    $section = "CIVIL TRACKS";
            if ($dept === 'BFS')    $section = "BUILDING FACILITIES";
            if ($dept === 'WHS')    $section = "WAREHOUSE";
            
            $birth_date = parseDate(trim($birth_raw));
            $hire_date  = parseDate(trim($hire_raw));
            
            // [SECURITY] Enforce Limits & Whitelist (Match Add/Edit Rules)
            $emp_id     = substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', $emp_id), 0, 20);
            $first_name = substr(preg_replace('/[^a-zA-Z0-9\s\-\.]/', '', $first_name), 0, 50);
            $last_name  = substr(preg_replace('/[^a-zA-Z0-9\s\-\.]/', '', $last_name), 0, 50);
            $job_title  = substr(preg_replace('/[^a-zA-Z0-9\s\-\.]/', '', $job_title), 0, 50);

            // Defaults
            $gender = "Male"; 
            $photo  = "default.png";
            $status = "Active";
            $address = "To be updated";
            $empType = ($agency === 'TESP') ? 'TESP Direct' : 'Agency';

            if ($emp_id != '') {
                try {
                    $sql = "INSERT INTO employees 
                    (emp_id, first_name, last_name, dept, section, 
                     employment_type, agency_name, job_title, status, 
                     gender, birth_date, hire_date, contact_number, 
                     present_address, avatar_path, import_batch,
                     sss_no, tin_no, pagibig_no, philhealth_no, email) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $emp_id, $first_name, $last_name, $dept, $section, 
                        $empType, $agency, $job_title, $status,
                        $gender, $birth_date, $hire_date, trim($contact_raw), 
                        $address, $photo, $batch_id,
                        trim($sss_raw), trim($tin_raw), trim($pagibig_raw), trim($phil_raw), $email
                    ]);
                    $success_count++;
                } catch (Exception $e) {
                    // Skip duplicates
                }
            }
        }
        fclose($handle);
        
        if ($success_count > 0) {
            $logger->log($_SESSION['user_id'], 'IMPORT_SUCCESS', "Imported $success_count ($agency)");
            $msg = "✅ Success! Imported $success_count employees into $agency.";
        } else {
            $error = "No valid records found or all were duplicates.";
        }
    } else {
        $error = "File upload failed.";
    }
}

$history = $pdo->query("SELECT import_batch, agency_name, COUNT(*) as count, MAX(created_at) as time FROM employees WHERE import_batch IS NOT NULL GROUP BY import_batch ORDER BY time DESC LIMIT 5")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Employees</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .format-box { display: none; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-5">

    <div id="instr_tesp" class="alert alert-info shadow-sm mb-4 format-box" style="display:block;">
        <h6 class="fw-bold">Standard Format (TESP / GUNJIN)</h6>
        <div class="table-responsive"><table class="table table-sm small table-bordered mb-0 bg-white"><tr><th>NO</th><th>ID</th><th>PIC</th><th>NAME</th><th>SECTION</th><th>CONTACT</th><th>BDAY</th><th>HIRED</th><th>SSS</th></tr></table></div>
    </div>

    <div id="instr_unli" class="alert alert-warning shadow-sm mb-4 format-box">
        <h6 class="fw-bold">UnliSolutions Format</h6>
        <div class="table-responsive"><table class="table table-sm small table-bordered mb-0 bg-white"><tr><th>NO</th><th>ID</th><th>PIC</th><th>NAME</th><th class="text-danger">POSITION</th><th>SECTION</th><th>...</th><th class="text-danger">EMAIL</th></tr></table></div>
    </div>

    <div id="instr_jora" class="alert alert-success shadow-sm mb-4 format-box">
        <h6 class="fw-bold">Joratech Format (Special)</h6>
        <div class="table-responsive"><table class="table table-sm small table-bordered mb-0 bg-white"><tr><th>NO</th><th>SECTION</th><th class="text-danger">POSITION</th><th>HIRED</th><th>NUM</th><th>PIC</th><th>NAME</th><th class="text-danger">CODE</th></tr></table></div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">Bulk Import</h5>
        </div>
        <div class="card-body">
            
            <form method="POST" enctype="multipart/form-data" id="importForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Select Agency</label>
                        <select name="agency_select" id="agency_select" class="form-select border-success" onchange="toggleFormat()" required>
                            <option value="">-- Choose Agency --</option>
                            <option value="TESP">TESP Direct</option>
                            <option value="UNLISOLUTIONS">UnliSolutions</option>
                            <option value="JORATECH">Joratech</option>
                            <option value="GUNJIN">Gunjin</option>
                            <option value="OTHERS">Others</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Upload CSV</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="d-grid gap-2 mt-3">
                    <button type="submit" class="btn btn-success btn-lg">Upload & Import</button>
                    <a href="fix_dates.php" class="btn btn-warning fw-bold">
                   <i class="bi bi-bandaid-fill"></i> Fix Missing Dates / Data
                     </a>
                    <a href="index.php" class="btn btn-outline-secondary">Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (count($history) > 0): ?>
    <div class="card shadow border-danger">
        <div class="card-header bg-danger text-white">
            <h6 class="mb-0">Undo Recent Imports</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead><tr><th>Date</th><th>Agency</th><th>Count</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo date('M d, h:i A', strtotime($h['time'])); ?></td>
                        <td><?php echo htmlspecialchars($h['agency_name']); ?></td>
                        <td><?php echo $h['count']; ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="undo_batch" value="<?php echo $h['import_batch']; ?>">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmUndo(this)">Undo</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function toggleFormat() {
    const agency = document.getElementById('agency_select').value;
    document.querySelectorAll('.format-box').forEach(el => el.style.display = 'none');
    
    if (agency === 'JORATECH') {
        document.getElementById('instr_jora').style.display = 'block';
    } else if (agency === 'UNLISOLUTIONS') {
        document.getElementById('instr_unli').style.display = 'block';
    } else {
        document.getElementById('instr_tesp').style.display = 'block';
    }
}

// SweetAlert2 Logic
document.getElementById('importForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const agency = document.getElementById('agency_select').value;
    const fileInput = document.querySelector('input[name="csv_file"]');
    const file = fileInput.files[0];

    if (!file) { form.submit(); return; }

    // Read first 10KB for preview (avoids freezing on large files)
    const reader = new FileReader();
    const blob = file.slice(0, 1024 * 10); 
    
    reader.onload = function(e) {
        const text = e.target.result;
        const rows = text.split(/\r\n|\n/).filter(r => r.trim() !== '');
        const previewRows = rows.slice(0, 6); // Header + 5 Data

        let tableHtml = '<div class="table-responsive" style="max-height:300px; text-align:left;"><table class="table table-sm table-bordered table-striped" style="font-size:0.75rem;">';
        
        previewRows.forEach((row, index) => {
            // Split CSV by comma (ignoring commas inside quotes)
            const cols = row.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);
            tableHtml += '<tr>';
            cols.forEach(col => {
                let clean = col.trim().replace(/^"|"$/g, ''); // Remove quotes
                tableHtml += (index === 0) ? `<th class="bg-light">${clean}</th>` : `<td>${clean}</td>`;
            });
            tableHtml += '</tr>';
        });
        tableHtml += '</table></div>';
        if (rows.length > 6) tableHtml += `<div class="text-muted small mt-1 text-center">... and more rows</div>`;

        Swal.fire({
            title: 'Confirm Import',
            html: `<p>Importing into <strong>${agency}</strong>. Check the preview below:</p>${tableHtml}`,
            icon: 'info',
            width: '800px',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            confirmButtonText: 'Yes, Import Data'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    };
    
    reader.readAsText(blob);
});

function confirmUndo(btn) {
    Swal.fire({
        title: 'Undo Import?',
        text: "This will delete all employees from this batch. This cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            btn.form.submit();
        }
    });
}

<?php if ($msg): ?>
Swal.fire({ icon: 'success', title: 'Success', text: <?php echo json_encode($msg); ?>, confirmButtonColor: '#198754' });
<?php endif; ?>
<?php if ($error): ?>
Swal.fire({ icon: 'error', title: 'Error', text: <?php echo json_encode($error); ?>, confirmButtonColor: '#dc3545' });
<?php endif; ?>
</script>
</body>
</html>