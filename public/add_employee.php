
<?php
// ======================================================
// Add Employee - Secure/Refactored
// ======================================================
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ---------------- Helpers ----------------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post($key, $default = '') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }

// Keep old inputs after validation errors
$old = $_POST ?? [];
function old($key, $default='') {
    global $old;
    return h($old[$key] ?? $default);
}

$security = new Security($pdo);
$logger   = new Logger($pdo);

// Optional: light rate limit for this endpoint
$security->checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 120, 60); // 120 req/min

// Departments & Sections (server-side source of truth)
$deptMap = [
    "ADMIN"   => ["GAG", "TKG", "PCG", "ACG", "MED", "OP", "CLEANERS/HOUSE KEEPING"],
    "HMS"     => ["HEAVY MAINTENANCE SECTION"],
    "RAS"     => ["ROOT CAUSE ANALYSIS SECTION"],
    "TRS"     => ["TECHNICAL RESEARCH SECTION"],
    "LMS"     => ["LIGHT MAINTENANCE SECTION"],
    "DOS"     => ["DEPARTMENT OPERATIONS SECTION"],
    "SQP"     => ["SAFETY", "QA", "PLANNING", "IT"],
    "CTS"     => ["CIVIL TRACKS SECTION"],
    "SIGCOM"  => ["SIGNALING COMMUNICATION"],
    "PSS"     => ["POWER SUPPLY SECTION"],
    "OCS"     => ["OVERHEAD CANERARY SECTION"],
    "BFS"     => ["BUILDING FACILITIES SECTION"],
    "WHS"     => ["WAREHOUSE"],
    "GUNJIN"  => ["EMT", "SECURITY PERSONNEL"],
    "SUBCONS-OTHERS" => ["OTHERS"]
];
// Allowed agencies
$agencies = ["Unisolutions", "Maximum", "M8 Manpower"];

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Storage for alerts
$errors = [];

// ---------------- Handle Form Submit ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Security token mismatch. Please refresh and try again.";
    }

    // Collect & normalize inputs
    $emp_id           = post('emp_id');
    $job_title        = post('job_title');
    $dept             = post('dept');
    $section          = post('section');
    $employment_type  = post('employment_type', 'TESP Direct');
    $agency_name_in   = post('agency_name');
    $company_name     = post('company_name', 'TES Philippines');
    $previous_company = post('previous_company');
    $hire_date        = post('hire_date');
    $first_name       = post('first_name');
    $middle_name      = post('middle_name');
    $last_name        = post('last_name');
    $birthdate        = post('birthdate');
    $contact_number   = post('contact_number');
    $email            = post('email');
    $present_address  = post('present_address');
    $permanent_address= post('permanent_address');
    $sss_no           = post('sss_no');
    $tin_no           = post('tin_no');
    $pagibig_no       = post('pagibig_no');
    $philhealth_no    = post('philhealth_no');
    $emergency_name   = post('emergency_name');
    $emergency_contact= post('emergency_contact');
    $emergency_address= post('emergency_address');

    // Server-side validation
    if ($emp_id === '' || !preg_match('/^[A-Za-z0-9\-_]{1,50}$/', $emp_id)) {
        $errors[] = "Employee ID is required and may only contain letters, numbers, dashes or underscores (max 50).";
    }

    if ($job_title === '' || mb_strlen($job_title) > 100) {
        $errors[] = "Job Title is required (max 100 chars).";
    }

    if (!array_key_exists($dept, $deptMap)) {
        $errors[] = "Please select a valid Department.";
    } else {
        if (!in_array($section, $deptMap[$dept], true)) {
            $errors[] = "Please select a valid Section for the chosen Department.";
        }
    }

    $employment_type = in_array($employment_type, ['TESP Direct','Agency'], true) ? $employment_type : 'TESP Direct';
    $agency_name = ($employment_type === 'Agency')
        ? (in_array($agency_name_in, $agencies, true) ? $agency_name_in : $agencies[0])
        : 'TESP';

    // Validate dates (Y-m-d)
    $validHire = DateTime::createFromFormat('Y-m-d', $hire_date);
    $validBirth= DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$validHire)  $errors[] = "Invalid Hire Date.";
    if (!$validBirth) $errors[] = "Invalid Birthdate.";

    if ($first_name === '' || $last_name === '') {
        $errors[] = "First Name and Last Name are required.";
    }
    if ($present_address === '') {
        $errors[] = "Present Address is required.";
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid Email address.";
    }
    if ($contact_number !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $contact_number)) {
        $errors[] = "Contact Number should be 7-20 characters (digits, +, -, space).";
    }
    if ($emergency_name === '' || $emergency_contact === '') {
        $errors[] = "Emergency Contact Name and Number are required.";
    }

    // Duplicate employee ID check
    if (empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT first_name, last_name, status FROM employees WHERE emp_id = ?");
        $checkStmt->execute([$emp_id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $errors[] = "❌ Error: Employee ID '".h($emp_id)."' is already taken by "
                      . h($existing['first_name']) . " (" . h($existing['status']) . ").";
        }
    }

    // Avatar upload (optional)
    $avatar_path = 'default.png';
    if (empty($errors) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading avatar (code: ".$file['error'].").";
        } else {
            $maxSize = 2 * 1024 * 1024; // 2MB
            if ($file['size'] > $maxSize) {
                $errors[] = "Avatar exceeds maximum size of 2MB.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];
                if (!array_key_exists($mime, $allowed)) {
                    $errors[] = "Avatar must be a JPG, PNG, or WEBP image.";
                } else {
                    $ext = $allowed[$mime];
                    $safeBase = preg_replace('/[^A-Za-z0-9\-_]/', '_', $emp_id);
                    $newName = $safeBase . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                    $uploadDirFs = __DIR__ . '/uploads/avatars';
                    $uploadDirWeb = 'uploads/avatars/';
                    if (!is_dir($uploadDirFs)) {
                        @mkdir($uploadDirFs, 0755, true);
                    }
                    $dest = $uploadDirFs . '/' . $newName;
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $errors[] = "Failed to save uploaded avatar.";
                    } else {
                        $avatar_path = $newName; // store only filename (folder fixed in web path)
                    }
                }
            }
        }
    }

    // If no validation errors, proceed
    if (empty($errors)) {
        $empData = [
            'emp_id'            => $emp_id,
            'job_title'         => $job_title,
            'dept'              => $dept,
            'section'           => $section,
            'employment_type'   => $employment_type,
            'agency_name'       => $agency_name,
            'company_name'      => $company_name,
            'previous_company'  => $previous_company,
            'hire_date'         => $hire_date,
            'first_name'        => $first_name,
            'middle_name'       => $middle_name,
            'last_name'         => $last_name,
            'birthdate'         => $birthdate,
            'contact_number'    => $contact_number,
            'email'             => $email,
            'present_address'   => $present_address,
            'permanent_address' => $permanent_address,
            'sss_no'            => $sss_no,
            'tin_no'            => $tin_no,
            'pagibig_no'        => $pagibig_no,
            'philhealth_no'     => $philhealth_no,
            'emergency_name'    => $emergency_name,
            'emergency_contact' => $emergency_contact,
            'emergency_address' => $emergency_address,
            'status'            => 'Active',
            'avatar_path'       => $avatar_path
        ];

        try {
            if (($_SESSION['role'] ?? '') === 'STAFF') {
                // Create a request instead of direct insert
                $payload = json_encode($empData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $reqSql = "INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'ADD_EMPLOYEE', 0, ?)";
                $pdo->prepare($reqSql)->execute([$_SESSION['user_id'], $payload]);

                $logger->log($_SESSION['user_id'], 'REQUEST_HIRE', "Submitted request for new employee: {$first_name} {$last_name}");
                header("Location: index.php?msg=" . urlencode("Request Submitted for Approval"));
                exit;
            } else {
                // Direct save (ADMIN / HR)
                $columns = array_keys($empData);
                $placeholders = array_map(fn($k) => ':' . $k, $columns);
                $sql = "INSERT INTO employees (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                foreach ($empData as $k => $v) {
                    $stmt->bindValue(':' . $k, $v);
                }
                $stmt->execute();

                $logger->log($_SESSION['user_id'], 'ADD_EMPLOYEE', "Added new employee: {$first_name} {$last_name}");
                header("Location: index.php?msg=" . urlencode("Employee Added Successfully"));
                exit;
            }
        } catch (PDOException $e) {
            // Duplicate key or general DB error
            if ($e->getCode() === '23000') {
                $errors[] = "The Employee ID already exists. Please use a unique ID.";
            } else {
                $errors[] = "A database error occurred while saving. Please try again.";
            }
            // Log detailed error for admins
            $logger->log($_SESSION['user_id'], 'ERROR', 'Add employee failed: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Employee</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .card { border-radius: .75rem; }
        .card-header { border-top-left-radius: .75rem; border-top-right-radius: .75rem; }
        .section-title { color: #6c757d; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; }
    </style>
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <div class="card shadow">
        <div class="card-header bg-success text-white d-flex align-items-center justify-content-between">
            <h4 class="mb-0">➕ Add New Employee</h4>
            <a href="index.php" class="btn btn-light btn-sm">Back to Dashboard</a>
        </div>
        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <div class="fw-bold mb-1">Please fix the following:</div>
                    <ul class="mb-0">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo h($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                <h5 class="section-title border-bottom pb-2">Work Information</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Employee ID</label>
                        <input type="text" name="emp_id" class="form-control" required value="<?php echo old('emp_id'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Job Title</label>
                        <input type="text" name="job_title" class="form-control" required value="<?php echo old('job_title'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select name="dept" id="dept" class="form-select" required>
                            <option value="">-- Select Group --</option>
                            <?php foreach ($deptMap as $d => $_): ?>
                                <option value="<?php echo h($d); ?>" <?php echo (old('dept')===$d)?'selected':''; ?>>
                                    <?php echo h($d); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Section</label>
                        <select name="section" id="section" class="form-select" required>
                            <option value="">-- Select Section --</option>
                            <!-- Populated by JS based on dept (and pre-selected via script) -->
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="employment_type" id="employment_type" class="form-select">
                            <option value="TESP Direct" <?php echo (old('employment_type','TESP Direct')==='TESP Direct')?'selected':''; ?>>TESP Direct</option>
                            <option value="Agency" <?php echo (old('employment_type')==='Agency')?'selected':''; ?>>Agency</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="agencyField" style="display:none;">
                        <label class="form-label">Agency Name</label>
                        <select name="agency_name" class="form-select">
                            <?php foreach ($agencies as $a): ?>
                                <option value="<?php echo h($a); ?>" <?php echo (old('agency_name')===$a)?'selected':''; ?>>
                                    <?php echo h($a); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hire Date</label>
                        <input type="date" name="hire_date" class="form-control" required value="<?php echo old('hire_date'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Company</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo old('company_name', 'TES Philippines'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Previous Company</label>
                        <input type="text" name="previous_company" class="form-control" value="<?php echo old('previous_company'); ?>">
                    </div>
                </div>

                <h5 class="section-title border-bottom pb-2 mt-4">Personal Details</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" required value="<?php echo old('first_name'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?php echo old('middle_name'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" required value="<?php echo old('last_name'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Birthdate</label>
                        <input type="date" name="birthdate" class="form-control" required value="<?php echo old('birthdate'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contact</label>
                        <input type="text" name="contact_number" class="form-control" value="<?php echo old('contact_number'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo old('email'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Present Address</label>
                        <input type="text" name="present_address" class="form-control" required value="<?php echo old('present_address'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Permanent Address</label>
                        <input type="text" name="permanent_address" class="form-control" value="<?php echo old('permanent_address'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Photo (JPG/PNG/WEBP, max 2MB)</label>
                        <input type="file" name="avatar" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    </div>
                </div>

                <h5 class="section-title border-bottom pb-2 mt-4">Government IDs</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">SSS</label>
                        <input type="text" name="sss_no" class="form-control" value="<?php echo old('sss_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">TIN</label>
                        <input type="text" name="tin_no" class="form-control" value="<?php echo old('tin_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PhilHealth</label>
                        <input type="text" name="philhealth_no" class="form-control" value="<?php echo old('philhealth_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Pag-IBIG</label>
                        <input type="text" name="pagibig_no" class="form-control" value="<?php echo old('pagibig_no'); ?>">
                    </div>
                </div>

                <h5 class="section-title border-bottom pb-2 mt-4">Emergency Contact</h5>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="emergency_name" class="form-control" required value="<?php echo old('emergency_name'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Number</label>
                        <input type="text" name="emergency_contact" class="form-control" required value="<?php echo old('emergency_contact'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="emergency_address" class="form-control" value="<?php echo old('emergency_address'); ?>">
                    </div>
                </div>

                <div class="mt-4 d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">
                        <?php echo (($_SESSION['role'] ?? '') === 'STAFF') ? 'Submit for Approval' : 'Save Record'; ?>
                    </button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// --- Depts/Sections from PHP (server truth -> client) ---
const deptMap = <?php echo json_encode($deptMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const oldDept = <?php echo json_encode($old['dept'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
const oldSection = <?php echo json_encode($old['section'] ?? '', JSON_UNESCAPED_UNICODE); ?>;

const deptSelect = document.getElementById('dept');
const sectionSelect = document.getElementById('section');

function populateSections(dept, preselect = '') {
    sectionSelect.innerHTML = '<option value="">-- Select Section --</option>';
    if (dept && deptMap[dept]) {
        deptMap[dept].forEach(sec => {
            const opt = document.createElement('option');
            opt.value = sec;
            opt.textContent = sec;
            if (preselect && preselect === sec) opt.selected = true;
            sectionSelect.appendChild(opt);
        });
    }
}

deptSelect.addEventListener('change', function() {
    populateSections(this.value, '');
});

// initialize from old values
if (oldDept) {
    populateSections(oldDept, oldSection);
}

// --- Agency toggle ---
const empTypeSel = document.getElementById('employment_type');
const agencyField = document.getElementById('agencyField');
function toggleAgency(val) {
    agencyField.style.display = (val === 'Agency') ? 'block' : 'none';
}
empTypeSel.addEventListener('change', function() { toggleAgency(this.value); });
toggleAgency(empTypeSel.value); // initial

</script>
</body>
</html>
