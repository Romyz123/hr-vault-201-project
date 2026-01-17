
<?php
// ======================================================
// Edit Employee Profile - Secure + Photo Replace/Remove
// ======================================================
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$security = new Security($pdo);
$logger   = new Logger($pdo);
// Soft rate limit for this endpoint
$security->checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 120, 60);

// ---------- Helpers ----------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

// ---------- Fetch Current Employee ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    http_response_code(404);
    die("Employee not found.");
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// ---------- Department / Section (server truth) ----------
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
$agencies = ["Unisolutions", "Maximum", "M8 Manpower"];

// Helper to render form values (prefer POST on validation error)
function val($key, $fallback = '') {
    global $emp;
    return h($_POST[$key] ?? $emp[$key] ?? $fallback);
}
function raw($key, $fallback = '') {
    global $emp;
    return (string)($_POST[$key] ?? $emp[$key] ?? $fallback);
}

// ---------- Handle Submit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verify
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $errors[] = "Security token mismatch. Please refresh the page and try again.";
    }

    // Collect new values (fall back to existing)
    $new_emp_id        = post('emp_id', $emp['emp_id']);
    $job_title         = post('job_title', $emp['job_title']);
    $dept              = post('dept', $emp['dept']);
    $section           = post('section', $emp['section']);
    $employment_type   = post('employment_type', $emp['employment_type']);
    $agency_name_in    = post('agency_name', $emp['agency_name']);
    $company_name      = post('company_name', $emp['company_name']);
    $previous_company  = post('previous_company', $emp['previous_company']);
    $hire_date         = post('hire_date', $emp['hire_date']);

    $first_name        = post('first_name', $emp['first_name']);
    $middle_name       = post('middle_name', $emp['middle_name']);
    $last_name         = post('last_name', $emp['last_name']);
    $birthdate         = post('birthdate', $emp['birthdate']);
    $contact_number    = post('contact_number', $emp['contact_number']);
    $email             = post('email', $emp['email']);
    $present_address   = post('present_address', $emp['present_address']);
    $permanent_address = post('permanent_address', $emp['permanent_address']);

    $sss_no            = post('sss_no', $emp['sss_no']);
    $tin_no            = post('tin_no', $emp['tin_no']);
    $philhealth_no     = post('philhealth_no', $emp['philhealth_no']);
    $pagibig_no        = post('pagibig_no', $emp['pagibig_no']);

    $emergency_name    = post('emergency_name', $emp['emergency_name']);
    $emergency_contact = post('emergency_contact', $emp['emergency_contact']);
    $emergency_address = post('emergency_address', $emp['emergency_address']);

    $status            = post('status', $emp['status']);
    $remove_photo      = isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1';

    // ---------- Validation ----------
    if ($new_emp_id === '' || !preg_match('/^[A-Za-z0-9\-_]{1,50}$/', $new_emp_id)) {
        $errors[] = "Employee ID may only contain letters, numbers, dashes or underscores (max 50).";
    }

    if ($job_title === '' || mb_strlen($job_title) > 100) {
        $errors[] = "Job Title is required (max 100 chars).";
    }

    if (!array_key_exists($dept, $deptMap)) {
        $errors[] = "Please select a valid Department.";
    } else {
        if (!in_array($section, $deptMap[$dept], true)) {
            $errors[] = "Please select a valid Section for the selected Department.";
        }
    }

    $employment_type = in_array($employment_type, ['TESP Direct', 'Agency'], true) ? $employment_type : 'TESP Direct';
    $agency_name     = ($employment_type === 'Agency')
        ? (in_array($agency_name_in, $agencies, true) ? $agency_name_in : $agencies[0])
        : 'TESP';

    // Dates
    $validHire = DateTime::createFromFormat('Y-m-d', $hire_date);
    $validBirth= DateTime::createFromFormat('Y-m-d', $birthdate);
    if ($hire_date !== '' && !$validHire)  $errors[] = "Invalid Hire Date.";
    if ($birthdate !== '' && !$validBirth) $errors[] = "Invalid Birthdate.";

    // Email
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid Email address.";
    }

    if ($first_name === '' || $last_name === '') {
        $errors[] = "First Name and Last Name are required.";
    }

    // Duplicate employee ID check if changed
    if ($new_emp_id !== $emp['emp_id']) {
        $checkStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE emp_id = ? AND id != ?");
        $checkStmt->execute([$new_emp_id, $id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $errors[] = "❌ Error: The ID \"".h($new_emp_id)."\" is already taken by ".h($existing['first_name']).".";
        }
    }

    // ---------- Avatar Handling ----------
    // Start with existing
    $final_avatar_path = $emp['avatar_path'] ?: 'default.png';
    $new_avatar_uploaded = false;
    $new_avatar_name = null;

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading photo (code: {$file['error']}).";
        } else {
            $maxSize = 2 * 1024 * 1024; // 2MB
            if ($file['size'] > $maxSize) {
                $errors[] = "Photo exceeds maximum size of 2MB.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];
                if (!array_key_exists($mime, $allowed)) {
                    $errors[] = "Photo must be a JPG, PNG, or WEBP image.";
                } else {
                    $ext = $allowed[$mime];
                    $safeBase = preg_replace('/[^A-Za-z0-9\-_]/', '_', $new_emp_id);
                    $newName = $safeBase . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                    $uploadDirFs = __DIR__ . '/uploads/avatars';
                    if (!is_dir($uploadDirFs)) {
                        @mkdir($uploadDirFs, 0755, true);
                    }
                    $dest = $uploadDirFs . '/' . $newName;
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $errors[] = "Failed to save uploaded photo.";
                    } else {
                        $new_avatar_uploaded = true;
                        $new_avatar_name = $newName;
                        $final_avatar_path = $newName; // candidate
                    }
                }
            }
        }
    } elseif ($remove_photo) {
        // only set to default if no new file uploaded
        $final_avatar_path = 'default.png';
    }

    // ---------- Prepare Update Payload ----------
    $updateData = [
        'emp_id'            => $new_emp_id,
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
        'philhealth_no'     => $philhealth_no,
        'pagibig_no'        => $pagibig_no,
        'emergency_name'    => $emergency_name,
        'emergency_contact' => $emergency_contact,
        'emergency_address' => $emergency_address,
        'status'            => $status,
        'avatar_path'       => $final_avatar_path
    ];

    // ---------- Persist ----------
    if (empty($errors)) {
        try {
            if (($_SESSION['role'] ?? '') === 'STAFF') {
                // Create an approval request (include avatar_path if changed)
                $payload = json_encode($updateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $reqStmt = $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'EDIT_PROFILE', ?, ?)");
                $reqStmt->execute([$_SESSION['user_id'], $id, $payload]);

                $logger->log($_SESSION['user_id'], 'REQUEST_EDIT', "Submitted profile edit request for ID: {$id}");

                // Note: We already moved the uploaded file to final folder so preview works when approved.
                header("Location: index.php?msg=" . urlencode("Edit Request Submitted for Approval"));
                exit;
            } else {
                // ADMIN / HR: Direct update
                $setParts = [];
                $values   = [];
                foreach ($updateData as $k => $v) {
                    $setParts[] = "{$k} = ?";
                    $values[]   = $v;
                }
                $values[] = $id;

                $sql = "UPDATE employees SET " . implode(', ', $setParts) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($values);

                // If employee_id changed, re-point documents
                if ($new_emp_id !== $emp['emp_id']) {
                    $upd = $pdo->prepare("UPDATE documents SET employee_id = ? WHERE employee_id = ?");
                    $upd->execute([$new_emp_id, $emp['emp_id']]);
                }

                // If photo changed or removed, delete old file (if not default)
                if ($final_avatar_path !== ($emp['avatar_path'] ?? 'default.png')) {
                    $old = $emp['avatar_path'] ?? 'default.png';
                    if ($old !== 'default.png') {
                        $oldFs = __DIR__ . '/uploads/avatars/' . $old;
                        if (is_file($oldFs)) { @unlink($oldFs); }
                    }
                }

                $logDetails = [];
                if ($new_emp_id !== $emp['emp_id']) { $logDetails[] = "ID: {$emp['emp_id']} → {$new_emp_id}"; }
                if ($final_avatar_path !== ($emp['avatar_path'] ?? 'default.png')) {
                    $logDetails[] = "Photo updated";
                }
                $logger->log($_SESSION['user_id'], 'EDIT_PROFILE', $logDetails ? implode('; ', $logDetails) : 'Updated profile details');

                header("Location: index.php?msg=" . urlencode("Profile Updated Successfully"));
                exit;
            }
        } catch (PDOException $e) {
            // Handle unique constraint or other DB issues gracefully
            if ($e->getCode() === '23000') {
                $errors[] = "The Employee ID already exists. Please use a unique ID.";
            } else {
                $errors[] = "A database error occurred while saving. Please try again.";
            }
            $logger->log($_SESSION['user_id'], 'ERROR', 'Edit employee failed: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .card { border-radius: .75rem; }
        .card-header { border-top-left-radius: .75rem; border-top-right-radius: .75rem; }
        .avatar-preview { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 6px 12px rgba(0,0,0,.12); background: #fff; }
        .section-title { color: #6c757d; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-5 mb-5">
    <div class="card shadow">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
            <h4 class="mb-0">✏️ Edit: <?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h4>
            <a href="index.php" class="btn btn-sm btn-dark">Back to Dashboard</a>
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

            <!-- Current Photo -->
            <div class="mb-3 d-flex align-items-center gap-3">
                <img
                    src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>"
                    class="avatar-preview"
                    onerror="this.src='../assets/default_avatar.png'"
                    alt="Current photo">
                <div class="text-muted small">
                    <div><strong>Employee:</strong> <?php echo h($emp['emp_id']); ?></div>
                    <div><strong>Status:</strong> <?php echo h($emp['status']); ?></div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                <h5 class="text-secondary border-bottom pb-2 mb-3">Employment Details</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Employee ID</label>
                        <input type="text" name="emp_id" class="form-control"
                               value="<?php echo val('emp_id'); ?>"
                            <?php echo (($_SESSION['role'] ?? '') === 'STAFF') ? 'readonly style="background-color:#e9ecef;"' : ''; ?>
                               required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Job Title</label>
                        <input type="text" name="job_title" class="form-control" value="<?php echo val('job_title'); ?>" required>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select name="employment_type" id="employment_type" class="form-select"
                            <?php echo (($_SESSION['role'] ?? '') === 'STAFF') ? 'style="pointer-events:none; background-color:#e9ecef;"' : ''; ?>>
                            <option value="TESP Direct" <?php echo (raw('employment_type', $emp['employment_type'])==='TESP Direct')?'selected':''; ?>>TESP Direct</option>
                            <option value="Agency" <?php echo (raw('employment_type', $emp['employment_type'])==='Agency')?'selected':''; ?>>Agency</option>
                        </select>
                    </div>

                    <div class="col-md-3" id="agencyField" style="display: <?php echo (raw('employment_type', $emp['employment_type'])==='Agency')?'block':'none'; ?>;">
                        <label class="form-label">Agency Name</label>
                        <select name="agency_name" class="form-select"
                            <?php echo (($_SESSION['role'] ?? '') === 'STAFF') ? 'style="pointer-events:none; background-color:#e9ecef;"' : ''; ?>>
                            <?php foreach ($agencies as $a): ?>
                                <option value="<?php echo h($a); ?>" <?php echo (raw('agency_name', $emp['agency_name'])===$a)?'selected':''; ?>>
                                    <?php echo h($a); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php
                            $statuses = ['Active','Resigned','Terminated','AWOL'];
                            foreach ($statuses as $s) {
                                $sel = (raw('status', $emp['status']) === $s) ? 'selected' : '';
                                echo "<option value=\"".h($s)."\" {$sel}>".h($s)."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Department / Group</label>
                        <select name="dept" id="dept" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($deptMap as $d => $_): ?>
                                <option value="<?php echo h($d); ?>" <?php echo (raw('dept', $emp['dept'])===$d)?'selected':''; ?>>
                                    <?php echo h($d); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Section</label>
                        <select name="section" id="section" class="form-select" required>
                            <option value="">-- Select --</option>
                            <!-- Populated by JS -->
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Hire Date</label>
                        <input type="date" name="hire_date" class="form-control" value="<?php echo h(raw('hire_date', $emp['hire_date'])); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Company</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo val('company_name'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Previous Company</label>
                        <input type="text" name="previous_company" class="form-control" value="<?php echo val('previous_company'); ?>">
                    </div>
                </div>

                <h5 class="text-secondary border-bottom pb-2 mb-3 mt-4">Personal Information</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo val('first_name'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?php echo val('middle_name'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo val('last_name'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Birthdate</label>
                        <input type="date" name="birthdate" class="form-control" value="<?php echo h(raw('birthdate', $emp['birthdate'])); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contact #</label>
                        <input type="text" name="contact_number" class="form-control" value="<?php echo val('contact_number'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo val('email'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Present Address</label>
                        <input type="text" name="present_address" class="form-control" value="<?php echo val('present_address'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Permanent Address</label>
                        <input type="text" name="permanent_address" class="form-control" value="<?php echo val('permanent_address'); ?>">
                    </div>
                </div>

                <h5 class="text-secondary border-bottom pb-2 mb-3 mt-4">Government Numbers</h5>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">SSS</label>
                        <input type="text" name="sss_no" class="form-control" value="<?php echo val('sss_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">TIN</label>
                        <input type="text" name="tin_no" class="form-control" value="<?php echo val('tin_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PhilHealth</label>
                        <input type="text" name="philhealth_no" class="form-control" value="<?php echo val('philhealth_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Pag-IBIG</label>
                        <input type="text" name="pagibig_no" class="form-control" value="<?php echo val('pagibig_no'); ?>">
                    </div>
                </div>

                <h5 class="text-secondary border-bottom pb-2 mb-3 mt-4">Emergency Contact</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="emergency_name" class="form-control" value="<?php echo val('emergency_name'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Number</label>
                        <input type="text" name="emergency_contact" class="form-control" value="<?php echo val('emergency_contact'); ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="emergency_address" class="form-control" value="<?php echo val('emergency_address'); ?>">
                    </div>
                </div>

                <h5 class="text-secondary border-bottom pb-2 mb-3 mt-4">Photo</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Replace Photo (JPG/PNG/WEBP, ≤2MB)</label>
                        <input type="file" name="avatar" class="form-control"
                               accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div class="form-text">If you upload a new photo, it will replace the current one.</div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="remove_photo" name="remove_photo">
                            <label class="form-check-label" for="remove_photo">
                                Remove current photo (revert to default)
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-grid gap-2">
                    <button type="submit" class="btn btn-warning btn-lg">
                        <?php echo (($_SESSION['role'] ?? '') === 'STAFF') ? 'Submit Edit Request' : 'Save Changes'; ?>
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
// Dept/Section map from server
const deptMap = <?php echo json_encode($deptMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const deptSelect = document.getElementById('dept');
const sectionSelect = document.getElementById('section');
const currentDept = <?php echo json_encode(raw('dept', $emp['dept']), JSON_UNESCAPED_UNICODE); ?>;
const currentSection = <?php echo json_encode(raw('section', $emp['section']), JSON_UNESCAPED_UNICODE); ?>;

function populateSections(dept, preselect = '') {
    sectionSelect.innerHTML = '<option value="">-- Select --</option>';
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
// Initialize
populateSections(currentDept, currentSection);

// Agency toggle
const empTypeSel = document.getElementById('employment_type');
const agencyField = document.getElementById('agencyField');
function toggleAgency(val) {
    agencyField.style.display = (val === 'Agency') ? 'block' : 'none';
}
empTypeSel.addEventListener('change', () => toggleAgency(empTypeSel.value));
// Initial state
toggleAgency(empTypeSel.value);

// Note: If both "Remove photo" and "Upload new photo" are used,
// the new upload takes precedence; removing is ignored in that case.
</script>
</body>
</html>
