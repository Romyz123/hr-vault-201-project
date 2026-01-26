<?php
// ======================================================
// [FILE] public/add_employee.php
// [STATUS] FIXED: Javascript Syntax Error + PHP Logic
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// ------------ Security Headers ------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net;");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ---------------- Helpers ----------------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post($key, $default = '') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }
$old = $_POST ?? [];
function old($key, $default='') { global $old; return h($old[$key] ?? $default); }

$security = new Security($pdo);
$logger   = new Logger($pdo);

// Rate Limit
$security->checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 120, 60);

// [FIXED] Clean Department Map
// This list determines EXACTLY what appears in the "Section" dropdown.
// No acronyms or duplicates here. Only the official names.
$deptMap = [
    // SQP & Admin have real sub-sections
    "SQP"     => ["SAFETY", "QA", "PLANNING", "IT"], 
    "ADMIN"   => ["ADMIN","GAG", "TKG", "PCG", "ACG", "MED", "OP", "CLEANERS/HOUSE KEEPING"],
    
    // OPERATIONS - Combined into single official names
    "SIGCOM"  => ["SIGNALING & COMMUNICATION"], 
    "PSS"     => ["POWER SUPPLY SECTION"],
    "OCS"     => ["OVERHEAD CATENARY SYSTEM"],
    
    // MAINTENANCE
    "HMS"     => ["HEAVY MAINTENANCE SECTION"],
    "RAS"     => ["ROOT CAUSE ANALYSIS "],
    "TRS"     => ["TECHNICAL RESEARCH SECTION"],
    "LMS"     => ["LIGHT MAINTENANCE SECTION"],
    "DOS"     => ["DEPARTMENT OPERATIONS SECTION"],
    
    // FACILITIES
    "CTS"     => ["CIVIL TRACKS SECTION"],
    "BFS"     => ["BUILDING FACILITIES SECTION"],
    "WHS"     => ["WAREHOUSE SECTION"],
    "GUNJIN"  => ["EMT", "SECURITY"],
    
    // OTHERS
    "SUBCONS-OTHERS" => ["OTHERS"]
];
$agencies = ["TESP DIRECT", "GUNJIN", "JORATECH", "UNLISOLUTIONS", "OTHERS - SUBCONS"];
$allowedGenders = ['Male', 'Female'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// ---------------- Handle Form Submission ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // [FIX] Check if file upload exceeded server limits (causes empty POST)
    if (empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $errors[] = "The file you uploaded is too large. Please use a smaller image (Max " . ini_get('post_max_size') . ").";
    }

    // 1. CSRF Check
    $token = $_POST['csrf_token'] ?? '';
    // Only run CSRF check if we haven't already detected a file size error
    if (empty($errors) && (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token))) {
        $errors[] = "Security token mismatch. Please refresh and try again.";
    }

    // 2. Collect Inputs & Force Capitalization (FIXED LOGIC)
    $emp_id           = post('emp_id');
    // FIXED: Correctly assign the capitalized value back to variable
    $job_title        = ucwords(strtolower(post('job_title'))); 
    
    $dept             = post('dept');
    $section          = post('section');
    $input_selection  = post('employment_type');
    $company_name     = post('company_name', 'TES Philippines');
    $previous_company = post('previous_company');
    $hire_date        = post('hire_date');

    // Personal Info (FIXED LOGIC)
    $first_name       = ucwords(strtolower(post('first_name')));
    $middle_name      = ucwords(strtolower(post('middle_name')));
    $last_name        = ucwords(strtolower(post('last_name')));
    
    $gender           = post('gender');
    $birth_date       = post('birth_date');
    $contact_number   = post('contact_number');
    $email            = post('email');
    $present_address  = post('present_address');
    $permanent_address= post('permanent_address');

    // Govt IDs
    $sss_no           = post('sss_no');
    $tin_no           = post('tin_no');
    $pagibig_no       = post('pagibig_no');
    $philhealth_no    = post('philhealth_no');

    // Emergency
    $emergency_name   = ucwords(strtolower(post('emergency_name')));
    $emergency_contact= post('emergency_contact');
    $emergency_address= post('emergency_address');

    // 3. Logic: Map Employment Type
    if (!in_array($input_selection, $agencies, true)) {
        $errors[] = "Invalid Employment Type.";
        $employment_type = '';
        $agency_name = '';
    } else {
        if ($input_selection === 'TESP DIRECT') {
            $employment_type = 'TESP Direct';
            $agency_name     = 'TESP';
        } else {
            $employment_type = 'Agency';
            $agency_name     = $input_selection;
        }
    }

    // 4. Validation Rules
    $max = [
        'emp_id'            => 20,
        'job_title'         => 50,
        'company_name'      => 50,
        'previous_company'  => 100,
        'first_name'        => 50,
        'middle_name'       => 50,
        'last_name'         => 50,
        'contact_number'    => 20,
        'email'             => 100,
        'present_address'   => 200,
        'permanent_address' => 200,
        'sss_no'            => 20,
        'tin_no'            => 20,
        'pagibig_no'        => 20,
        'philhealth_no'     => 20,
        'emergency_name'    => 100,
        'emergency_contact' => 20,
        'emergency_address' => 200,
    ];

    foreach ($max as $k => $limit) {
        if (isset($$k) && mb_strlen((string)$$k, 'UTF-8') > $limit) {
            $errors[] = ucfirst(str_replace('_', ' ', $k)) . " exceeds maximum length of $limit characters.";
        }
    }

    if ($emp_id === '' || !preg_match('/^[A-Za-z0-9\-_]{1,20}$/', $emp_id)) {
        $errors[] = "Employee ID is required (letters, numbers, dash, underscore). Max 20 chars.";
    }
    if ($job_title === '') $errors[] = "Job Title is required.";
    if ($first_name === '' || $last_name === '') $errors[] = "First and Last Name are required.";
    if ($gender === '' || !in_array($gender, $allowedGenders, true)) $errors[] = "Gender is required.";

    // [SECURITY] Name Validation (Letters, spaces, dots, dashes only)
    if (!preg_match("/^[a-zA-Z\s\-\.]+$/", $first_name)) $errors[] = "First Name contains invalid characters.";
    if ($middle_name !== '' && !preg_match("/^[a-zA-Z\s\-\.]+$/", $middle_name)) $errors[] = "Middle Name contains invalid characters.";
    if (!preg_match("/^[a-zA-Z\s\-\.]+$/", $last_name)) $errors[] = "Last Name contains invalid characters.";

    if ($dept === '' || !array_key_exists($dept, $deptMap)) $errors[] = "Valid Department is required.";
    if ($section === '' || !($dept && in_array($section, $deptMap[$dept] ?? [], true))) $errors[] = "Valid Section is required for the selected Department.";
    if ($present_address === '') $errors[] = "Present Address is required.";
    if ($company_name === '') $errors[] = "Company is required.";

    // Date Validation
    $validHire  = DateTime::createFromFormat('Y-m-d', $hire_date) ?: false;
    $validBirth = DateTime::createFromFormat('Y-m-d', $birth_date) ?: false;
    $today      = new DateTime('today');

    if (!$validHire || $validHire->format('Y-m-d') !== $hire_date)  $errors[] = "Invalid Hire Date.";
    if (!$validBirth || $validBirth->format('Y-m-d') !== $birth_date) $errors[] = "Invalid Birth Date.";

    if ($validBirth && $validBirth > $today) {
        $errors[] = "Birth Date cannot be in the future.";
    }
    if ($validHire && $validHire > (new DateTime('now'))->modify('+1 day')) {
        $errors[] = "Hire Date cannot be in the future.";
    }
    if ($validBirth && $validHire && $validHire < $validBirth) {
        $errors[] = "Hire Date cannot be earlier than Birth Date.";
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    $phonePattern = '/^[0-9+\-\s()\/]{0,20}$/';
    if ($contact_number !== '' && !preg_match($phonePattern, $contact_number)) {
        $errors[] = "Contact Number contains invalid characters.";
    }
    if ($emergency_contact !== '' && !preg_match($phonePattern, $emergency_contact)) {
        $errors[] = "Emergency Contact contains invalid characters.";
    }

    // 5. Check Duplicate ID
    if (empty($errors)) {
        $checkStmt = $pdo->prepare("SELECT 1 FROM employees WHERE emp_id = ? LIMIT 1");
        $checkStmt->execute([$emp_id]);
        if ($checkStmt->fetchColumn()) {
            $errors[] = "Error: Employee ID '$emp_id' is already in use.";
        }
    }

    // 6. Avatar Upload
    $avatar_path = 'default.png';
    if (empty($errors) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Image upload error (code {$file['error']}).";
        } else {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            if (!array_key_exists($mime, $allowed)) {
                $errors[] = "Avatar must be JPG, PNG, or WEBP.";
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = "Avatar must be smaller than 2MB.";
            } else {
                $ext = $allowed[$mime];
                $newName = preg_replace('/[^A-Za-z0-9\-_]/', '_', $emp_id) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $dest = __DIR__ . '/uploads/avatars/' . $newName;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    $avatar_path = $newName;
                } else {
                    $errors[] = "Failed to save avatar file.";
                }
            }
        }
    }

    // 7. Save to Database
    if (empty($errors)) {
        $empData = [
            'emp_id'            => $emp_id,
            'first_name'        => $first_name,
            'last_name'         => $last_name,
            'middle_name'       => $middle_name,
            'gender'            => $gender,
            'birth_date'        => $birth_date,
            'dept'              => $dept,
            'section'           => $section,
            'job_title'         => $job_title,
            'employment_type'   => $employment_type,
            'agency_name'       => $agency_name,
            'company_name'      => $company_name,
            'hire_date'         => $hire_date,
            'previous_company'  => $previous_company,
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
            if (in_array($_SESSION['role'] ?? '', ['ADMIN', 'HR'], true)) {
                $columns = implode(", ", array_keys($empData));
                $placeholders = implode(", ", array_map(fn($k) => ":$k", array_keys($empData)));

                $sql = "INSERT INTO employees ($columns) VALUES ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($empData);

                // [NEW] Send Welcome Email
                if (!empty($email)) {
                    $subject = "Welcome to TES Philippines!";
                    $body    = "<h3>Hi " . h($first_name) . ",</h3>";
                    $body   .= "<p>Welcome to the team! We are excited to have you on board as our new <strong>" . h($job_title) . "</strong>.</p>";
                    $body   .= "<p><strong>Employee ID:</strong> " . h($emp_id) . "</p>";
                    $body   .= "<p>Please coordinate with your department head for your initial schedule.</p>";
                    $body   .= "<br><p>Best Regards,<br>Human Resources</p>";

                    $headers  = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: HR System <no-reply@hrsystem.com>" . "\r\n";

                    @mail($email, $subject, $body, $headers);
                }

                $logger->log($_SESSION['user_id'], 'ADD_EMPLOYEE', "Added $first_name $last_name ($emp_id)");
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header("Location: index.php?msg=" . urlencode("✅ Employee Added Successfully"));
                exit;
            } else {
                $empData['request_note'] = post('request_note');
                $payload = json_encode($empData, JSON_UNESCAPED_UNICODE);
                $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'ADD_EMPLOYEE', 0, ?)")
                    ->execute([$_SESSION['user_id'], $payload]);

                $logger->log($_SESSION['user_id'], 'REQUEST_HIRE', "Requested hire: $first_name $last_name");
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                
                header("Location: index.php?msg=" . urlencode("📝 Request Submitted for Approval"));
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Employee</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .card-header { font-weight: bold; letter-spacing: 0.5px; }
        .section-header { border-bottom: 2px solid #e9ecef; margin-bottom: 1rem; padding-bottom: 0.5rem; color: #495057; font-weight: 600; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-5 mb-5">
    <div class="card shadow">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <span class="fs-5">➕ Add New Employee</span>
            <a href="index.php" class="btn btn-sm btn-light text-success fw-bold">Back to Dashboard</a>
        </div>
        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo h($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                <h5 class="section-header">🏢 Work Information</h5>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                        <input type="text" name="emp_id" class="form-control" required maxlength="20"
                               placeholder="e.g. 2026-001" value="<?php echo old('emp_id'); ?>"
                               autocomplete="off" pattern="[A-Za-z0-9\-_]{1,20}">
                        <div class="form-text extra-small">Allowed: Letters, Numbers, - and _</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Job Title <span class="text-danger">*</span></label>
                        <input type="text" name="job_title" class="form-control" required maxlength="50"
                               placeholder="e.g. Accountant" value="<?php echo old('job_title'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department <span class="text-danger">*</span></label>
                        <select name="dept" id="dept" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($deptMap as $d => $secs): ?>
                                <option value="<?php echo h($d); ?>" <?php echo (old('dept') == $d) ? 'selected' : ''; ?>>
                                    <?php echo h($d); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Section <span class="text-danger">*</span></label>
                        <select name="section" id="section" class="form-select" required>
                            <option value="">-- Select --</option>
                        </select>
                    </div>


                    
                    <div class="col-md-6">
                        <label class="form-label">Employment Type <span class="text-danger">*</span></label>
                        <select name="employment_type" class="form-select" required>
                            <option value="" disabled selected>-- Select --</option>
                            <?php foreach($agencies as $a): ?>
                                <option value="<?php echo h($a); ?>" <?php echo (old('employment_type')==$a)?'selected':''; ?>>
                                    <?php echo h($a); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hire Date <span class="text-danger">*</span></label>
                        <input type="date" name="hire_date" class="form-control" required
                               value="<?php echo old('hire_date'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Company</label>
                        <input type="text" name="company_name" class="form-control" maxlength="50"
                               placeholder="e.g. TES Philippines" value="<?php echo old('company_name','TES Philippines'); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Previous Company</label>
                        <input type="text" name="previous_company" class="form-control" maxlength="100"
                               placeholder="e.g. ABC Manufacturing Inc." value="<?php echo old('previous_company'); ?>">
                    </div>
                </div>

                <h5 class="section-header">👤 Personal Details</h5>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" required maxlength="50"
                               placeholder="e.g. Juan" value="<?php echo old('first_name'); ?>" autocomplete="given-name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" maxlength="50"
                               placeholder="e.g. Santos" value="<?php echo old('middle_name'); ?>" autocomplete="additional-name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" required maxlength="50"
                               placeholder="e.g. Dela Cruz" value="<?php echo old('last_name'); ?>" autocomplete="family-name">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                        <select name="gender" class="form-select" required>
                            <option value="">-- Select --</option>
                            <option value="Male"   <?php echo (old('gender')=='Male')?'selected':''; ?>>Male</option>
                            <option value="Female" <?php echo (old('gender')=='Female')?'selected':''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                        <input type="date" name="birth_date" class="form-control" required value="<?php echo old('birth_date'); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" maxlength="20"
                               placeholder="e.g. 0912-345-6789"
                               value="<?php echo old('contact_number'); ?>"
                               inputmode="tel" pattern="[0-9+\-\s()\/]{0,20}">
                        <div class="form-text extra-small">Allowed: Numbers, +, -, /, ( )</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="100"
                               placeholder="juan@example.com" value="<?php echo old('email'); ?>" autocomplete="email">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Present Address <span class="text-danger">*</span></label>
                        <input type="text" name="present_address" class="form-control" required maxlength="200"
                               placeholder="House No, Street, Barangay, City, Province, ZIP"
                               value="<?php echo old('present_address'); ?>" autocomplete="street-address">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Permanent Address</label>
                        <input type="text" name="permanent_address" class="form-control" maxlength="200"
                               placeholder="If different from present address"
                               value="<?php echo old('permanent_address'); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Photo (Optional)</label>
                        <input type="file" name="avatar" class="form-control" accept=".jpg,.png,.webp">
                    </div>
                </div>

                <h5 class="section-header">🆔 Government Numbers</h5>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label">SSS</label>
                        <input type="text" name="sss_no" class="form-control" maxlength="20"
                               placeholder="00-0000000-0" value="<?php echo old('sss_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">TIN</label>
                        <input type="text" name="tin_no" class="form-control" maxlength="20"
                               placeholder="000-000-000-000" value="<?php echo old('tin_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PhilHealth</label>
                        <input type="text" name="philhealth_no" class="form-control" maxlength="20"
                               placeholder="e.g. 12-345678901-2" value="<?php echo old('philhealth_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Pag-IBIG</label>
                        <input type="text" name="pagibig_no" class="form-control" maxlength="20"
                               placeholder="e.g. 1234-5678-9012" value="<?php echo old('pagibig_no'); ?>">
                    </div>
                </div>

                <h5 class="section-header">🚨 Emergency Contact</h5>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="emergency_name" class="form-control" maxlength="100"
                               placeholder="Full Name" value="<?php echo old('emergency_name'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="emergency_contact" class="form-control" maxlength="20"
                               placeholder="Mobile/Landline" value="<?php echo old('emergency_contact'); ?>"
                               inputmode="tel" pattern="[0-9+\-\s()\/]{0,20}">
                        <div class="form-text extra-small">Allowed: Numbers, +, -, /, ( )</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="emergency_address" class="form-control" maxlength="200"
                               placeholder="Full Address" value="<?php echo old('emergency_address'); ?>">
                    </div>
                </div>

                <?php if (($_SESSION['role'] ?? '') === 'STAFF'): ?>
                <div class="alert alert-warning">
                    <label class="form-label fw-bold"><i class="bi bi-chat-text"></i> Note for Admin</label>
                    <textarea name="request_note" class="form-control" rows="2" maxlength="250"
                              placeholder="Add any details for the admin..."><?php echo old('request_note'); ?></textarea>
                </div>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">
                        <?php echo (($_SESSION['role'] ?? '')==='STAFF') ? 'Submit Request' : 'Save Employee'; ?>
                    </button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 1. SAFE DATA LOADING
const deptMap = <?php echo json_encode($deptMap, JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
const oldSection = "<?php echo htmlspecialchars($old['section'] ?? '', ENT_QUOTES); ?>";

// 2. ELEMENT SELECTORS
const deptSelect = document.getElementById('dept');
const sectionSelect = document.getElementById('section');

// 3. DYNAMIC DROPDOWN LOGIC
function updateSections() {
    const dept = deptSelect.value;
    sectionSelect.innerHTML = '<option value="">-- Select --</option>';
    
    if (dept && deptMap[dept]) {
        deptMap[dept].forEach(sec => {
            const opt = document.createElement('option');
            opt.value = sec;
            opt.textContent = sec;
            if (sec === oldSection) { opt.selected = true; }
            sectionSelect.appendChild(opt);
        });
    }
}

// 4. AUTO-CAPITALIZE LOGIC
function capitalize(input) {
    let words = input.value.split(' ');
    for (let i = 0; i < words.length; i++) {
        if (words[i].length > 0) {
            words[i] = words[i].charAt(0).toUpperCase() + words[i].slice(1).toLowerCase();
        }
    }
    input.value = words.join(' ');
}

// 5. ATTACH EVENTS
if (deptSelect && sectionSelect) {
    deptSelect.addEventListener('change', updateSections);
    updateSections(); // Run once on load
}

// 6. ATTACH CAPITALIZATION
document.addEventListener("DOMContentLoaded", function() {
    const fieldsToCap = ['first_name', 'middle_name', 'last_name', 'job_title', 'emergency_name'];
    fieldsToCap.forEach(name => {
        const input = document.querySelector(`[name="${name}"]`);
        if (input) {
            input.addEventListener('input', function() { capitalize(this); });
        }
    });

    // 7. AUTO-SAVE DRAFT (Protects against Session Timeout)
    const form = document.querySelector('form');
    const draftKey = 'hr_add_emp_draft';

    // A. Restore on Load
    const savedDraft = localStorage.getItem(draftKey);
    const firstInput = document.querySelector('input[name="first_name"]');
    
    // Only restore if the form is empty (don't overwrite PHP validation errors)
    if (savedDraft && firstInput && !firstInput.value) {
        try {
            const data = JSON.parse(savedDraft);
            Object.keys(data).forEach(key => {
                const el = document.querySelector(`[name="${key}"]`);
                if (el && el.type !== 'file' && el.type !== 'hidden') el.value = data[key];
            });
            // Notify user
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-info alert-dismissible fade show mt-3';
            alertDiv.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> <strong>Draft Restored:</strong> We recovered your unsaved work from the last session. <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            document.querySelector('.card-body').prepend(alertDiv);
        } catch(e) {}
    }

    // B. Save on Typing
    form.addEventListener('input', () => {
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (key !== 'csrf_token' && key !== 'avatar') data[key] = value;
        });
        localStorage.setItem(draftKey, JSON.stringify(data));
    });

    // C. Clear on Submit (Optional: You can leave it to persist until manually cleared)
    // form.addEventListener('submit', () => localStorage.removeItem(draftKey));
});
</script>
</body>
</html>