<?php
// ======================================================
// [FILE] public/add_employee.php
// [STATUS] FIXED: Javascript Syntax Error + PHP Logic
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/EmployeeService.php'; // [NEW]
require '../src/Validator.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// ------------ Security Headers ------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// [SECURITY] Check Maintenance Mode
if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $chkMaint = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
    if ($chkMaint === '1') {
        header("Location: login.php?msg=" . urlencode("🛠️ System is under maintenance."));
        exit;
    }
}

// ---------------- Helpers ----------------
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}
$old = $_POST ?: $_GET; // [FIX] Allow GET for pre-filling from Recruitment
function old($key, $default = '')
{
    global $old;
    return h($old[$key] ?? $default);
}

$security = new Security($pdo);
$logger   = new Logger($pdo);
$empService = new EmployeeService($pdo, $logger); // [NEW]

// [NEW] Load Centralized Options
require __DIR__ . '/options.php';

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
    $emp_id           = post('emp_id', ''); // Only accept from POST, not GET
    // FIXED: Correctly assign the capitalized value back to variable
    $job_title        = ucwords(strtolower(post('job_title')));
    $system_role      = post('system_role', 'Staff'); // [NEW] System Role

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
    $permanent_address = post('permanent_address');

    // Govt IDs
    $sss_no           = post('sss_no');
    $tin_no           = post('tin_no');
    $pagibig_no       = post('pagibig_no');
    $philhealth_no    = post('philhealth_no');

    // Emergency
    $emergency_name   = ucwords(strtolower(post('emergency_name')));
    $emergency_contact = post('emergency_contact');
    $emergency_address = post('emergency_address');

    // Qualifications
    $education  = post('education');
    $experience = post('experience');
    $skills     = post('skills');
    $licenses   = post('licenses');

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

    // [SECURITY] Enforce Character Limits & Patterns (Server-Side)
    $rules = [
        ['val' => $emp_id, 'name' => 'Employee ID', 'max' => 20, 'pattern' => '/^[A-Za-z0-9\-_]+$/'],
        ['val' => $job_title, 'name' => 'Job Title', 'max' => 50, 'pattern' => "/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/"],
        ['val' => $system_role, 'name' => 'System Role', 'max' => 50],
        ['val' => $company_name, 'name' => 'Company Name', 'max' => 50, 'pattern' => "/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/"],
        ['val' => $previous_company, 'name' => 'Previous Company', 'max' => 100, 'pattern' => "/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/"],
        ['val' => $first_name, 'name' => 'First Name', 'max' => 50, 'pattern' => "/^[a-zA-Z\s\-\.\']+$/"],
        ['val' => $middle_name, 'name' => 'Middle Name', 'max' => 50, 'pattern' => "/^[a-zA-Z\s\-\.\']+$/"],
        ['val' => $last_name, 'name' => 'Last Name', 'max' => 50, 'pattern' => "/^[a-zA-Z\s\-\.\']+$/"],
        ['val' => $contact_number, 'name' => 'Contact Number', 'max' => 25, 'pattern' => '/^[0-9+\-\s()\/]{0,25}$/'],
        ['val' => $email, 'name' => 'Email', 'max' => 100, 'type' => 'email'],
        ['val' => $present_address, 'name' => 'Present Address', 'max' => 150, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\/#\(\)\']+$/"],
        ['val' => $permanent_address, 'name' => 'Permanent Address', 'max' => 150, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\/#\(\)\']+$/"],
        ['val' => $sss_no, 'name' => 'SSS No', 'max' => 20, 'pattern' => "/^[0-9\-]+$/"],
        ['val' => $tin_no, 'name' => 'TIN No', 'max' => 20, 'pattern' => "/^[0-9\-]+$/"],
        ['val' => $pagibig_no, 'name' => 'Pag-IBIG No', 'max' => 20, 'pattern' => "/^[0-9\-]+$/"],
        ['val' => $philhealth_no, 'name' => 'PhilHealth No', 'max' => 20, 'pattern' => "/^[0-9\-]+$/"],
        ['val' => $emergency_name, 'name' => 'Emergency Name', 'max' => 100, 'pattern' => "/^[a-zA-Z\s\-\.\']+$/"],
        ['val' => $emergency_contact, 'name' => 'Emergency Contact', 'max' => 25, 'pattern' => '/^[0-9+\-\s()\/]{0,25}$/'],
        ['val' => $emergency_address, 'name' => 'Emergency Address', 'max' => 150, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\/#\(\)\']+$/"],
        ['val' => $education, 'name' => 'Education', 'max' => 1000, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\(\)\/\':]*$/"],
        ['val' => $experience, 'name' => 'Experience', 'max' => 1000, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\(\)\/\':]*$/"],
        ['val' => $skills, 'name' => 'Skills', 'max' => 1000, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\(\)\/\':]*$/"],
        ['val' => $licenses, 'name' => 'Licenses', 'max' => 1000, 'pattern' => "/^[a-zA-Z0-9\s\.,\-\(\)\/\':]*$/"],
        ['val' => $dept, 'name' => 'Department', 'max' => 50],
        ['val' => $section, 'name' => 'Section', 'max' => 100],
        ['val' => post('request_note'), 'name' => 'Request Note', 'max' => 500],
    ];

    foreach ($rules as $r) {
        if (isset($r['max']) && $err = Validator::check($r['val'], 'max', $r['max'])) {
            $errors[] = $r['name'] . ": " . $err;
        }
        if (!empty($r['val'])) {
            if (isset($r['pattern']) && $err = Validator::check($r['val'], 'pattern', $r['pattern'])) {
                $errors[] = $r['name'] . " contains invalid characters.";
            }
            if (isset($r['type']) && $r['type'] === 'email' && $err = Validator::check($r['val'], 'email')) {
                $errors[] = $r['name'] . ": " . $err;
            }
        }
    }

    // Date Logic
    if ($birth_date && $birth_date > date('Y-m-d')) $errors[] = "Birth Date cannot be in the future.";
    if ($hire_date && $birth_date && $hire_date < $birth_date) $errors[] = "Hire Date cannot be earlier than Birth Date.";

    // 4. Prepare Data Array
    $empData = [
        'emp_id' => $emp_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'middle_name' => $middle_name,
        'job_title' => $job_title,
        'system_role' => $system_role,
        'dept' => $dept,
        'section' => $section,
        'employment_type' => $employment_type,
        'agency_name' => $agency_name,
        'company_name' => $company_name,
        'previous_company' => $previous_company,
        'hire_date' => $hire_date,
        'gender' => $gender,
        'birth_date' => $birth_date,
        'contact_number' => $contact_number,
        'email' => $email,
        'present_address' => $present_address,
        'permanent_address' => $permanent_address,
        'sss_no' => $sss_no,
        'tin_no' => $tin_no,
        'pagibig_no' => $pagibig_no,
        'philhealth_no' => $philhealth_no,
        'emergency_name' => $emergency_name,
        'emergency_contact' => $emergency_contact,
        'emergency_address' => $emergency_address,
        'education' => $education,
        'experience' => $experience,
        'skills' => $skills,
        'licenses' => $licenses,
        'status' => 'Active'
    ];

    // 5. Use Service for Validation
    // Note: You can move the detailed regex checks into EmployeeService::validate() to clean this up further.
    // For now, we'll use the service's basic validation + duplicate check.
    $serviceErrors = $empService->validate($empData);
    $errors = array_merge($errors, $serviceErrors);

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
                // Validate $emp_id against strict pattern to prevent path traversal
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $emp_id)) {
                    $errors[] = "Invalid employee ID format for avatar upload.";
                } else {
                    $newName = $emp_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = __DIR__ . '/uploads/avatars/' . $newName;
                    if (!is_dir(dirname($dest))) {
                        mkdir(dirname($dest), 0755, true);
                    }

                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        @chmod($dest, 0644);
                        $avatar_path = $newName;
                    } else {
                        $errors[] = "Failed to save avatar file.";
                    }
                }
            }
        }
    }

    // 7. Save to Database
    if (empty($errors)) {
        $empData['avatar_path'] = $avatar_path;

        try {
            if (in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER', 'HR'], true)) {
                // Use Service to Create
                $newId = $empService->create($empData, $_SESSION['user_id']);

                // [NEW] Send Welcome Email
                $emailStatus = " (Email Skipped)";
                if (!empty($email)) {
                    $subject = "Welcome to TES Philippines! - Onboarding Information";

                    // [CUSTOMIZED TEMPLATE]
                    $body = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; }
                            .header { background-color: #198754; color: white; padding: 15px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { padding: 20px; background-color: white; }
                            .footer { font-size: 12px; color: #777; text-align: center; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px; }
                            .highlight { color: #198754; font-weight: bold; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2 style='margin:0;'>Welcome Aboard!</h2>
                            </div>
                            <div class='content'>
                                <p>Dear <strong>" . h($first_name) . "</strong>,</p>
                                <p>We are thrilled to welcome you to <strong>TES Philippines, Inc.</strong>! We are excited to have you join our team as our new <span class='highlight'>" . h($job_title) . "</span>.</p>
                                <p><strong>Employee ID:</strong> " . h($emp_id) . "</p>
                                <p>Please report to the <strong>" . h($dept) . " Department</strong> and coordinate with your department head regarding your initial schedule and orientation.</p>
                                <p>Best Regards,<br><strong>Human Resources Team</strong></p>
                            </div>
                            <div class='footer'>&copy; " . date('Y') . " TES Philippines, Inc.</div>
                        </div>
                    </body>
                    </html>";

                    $headers  = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: HR System <no-reply@hrsystem.com>" . "\r\n";

                    if (mail($email, $subject, $body, $headers)) {
                        $emailStatus = " & 📧 Welcome Email Sent";
                    } else {
                        $err = error_get_last()['message'] ?? 'Unknown error';
                        error_log("Mail Error: $err");
                        $emailStatus = " but ❌ Email Failed";
                    }
                }

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // [FIX] Stay on Add Employee page to allow adding another
                header("Location: add_employee.php?msg=" . urlencode("✅ Employee Added Successfully" . $emailStatus));
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
            // Log full exception server-side
            error_log('Database Error in add_employee.php: ' . $e->getMessage() . '. Stack: ' . $e->getTraceAsString());
            // Show generic message to user
            $errors[] = "A database error occurred. Please contact support if the problem persists.";
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
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        body {
            background: #f4f6f9;
        }

        .card-header {
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .section-header {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            color: #495057;
            font-weight: 600;
        }

        /* SweetAlert2 Brand Customization */
        .swal2-popup {
            border-top: 5px solid #198754;
            /* Brand Green */
            border-radius: 15px;
        }

        .swal2-confirm {
            background-color: #198754 !important;
            /* Brand Green */
            box-shadow: 0 0 0 3px rgba(25, 135, 84, 0.2) !important;
        }

        .swal2-cancel {
            background-color: #6c757d !important;
            /* Grey */
        }
    </style>
</head>

<body class="bg-body-tertiary">

    <div class="container mt-5 mb-5">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span class="fs-5">➕ Add New Employee</span>
                <div class="d-flex align-items-center gap-2">
                    <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                        <i class="bi bi-moon-stars-fill"></i>
                    </button>
                    <a href="index.php" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
                </div>
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

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <small class="text-muted fst-italic" id="autoSaveIndicator"></small>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clearFormBtnTop"><i class="bi bi-eraser"></i> Clear Form</button>
                </div>

                <form method="POST" enctype="multipart/form-data" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                    <h5 class="section-header">🏢 Work Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                            <input type="text" name="emp_id" class="form-control" required maxlength="20"
                                placeholder="e.g. 2026-001" value="<?php echo old('emp_id', $_GET['emp_id'] ?? ''); ?>"
                                autocomplete="off" pattern="[A-Za-z0-9\-_]+" title="Allowed: Letters, Numbers, - and _" oninput="this.value = this.value.replace(/[^A-Za-z0-9\-_]/g, '')">
                            <div class="form-text extra-small">Allowed: Letters, Numbers, - and _</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Job Title <span class="text-danger">*</span></label>
                            <input type="text" name="job_title" class="form-control" required maxlength="50"
                                placeholder="e.g. Accountant" value="<?php echo old('job_title'); ?>"
                                pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Contract Category (Role) <span class="text-danger">*</span></label>
                            <select name="system_role" class="form-select" required>
                                <option value="" disabled <?php echo (old('system_role') == '') ? 'selected' : ''; ?>>Select Role...</option>
                                <?php foreach ($system_roles as $role): ?>
                                    <option value="<?php echo h($role); ?>" <?php echo (old('system_role') == $role) ? 'selected' : ''; ?>><?php echo h($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text extra-small">Determines contract duties.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Department(s) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="dept" id="dept" class="form-control bg-white" required readonly placeholder="Select below..." value="<?php echo old('dept'); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('dept').value = ''; updateSections();" title="Clear"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <select id="deptPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addDept(this.value)">
                                <option value="">+ Add Department...</option>
                                <?php foreach ($deptMap as $d => $secs): ?>
                                    <option value="<?php echo h($d); ?>"><?php echo h($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Section(s) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="section" id="section" class="form-control bg-white" required readonly placeholder="Select below..." value="<?php echo old('section'); ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('section').value = ''" title="Clear"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <select id="sectionPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addSection(this.value)">
                                <option value="">+ Add Section...</option>
                            </select>
                        </div>



                        <div class="col-md-6">
                            <label class="form-label">Employment Type <span class="text-danger">*</span></label>
                            <select name="employment_type" class="form-select" required>
                                <option value="" disabled selected>-- Select --</option>
                                <?php foreach ($agencies as $a): ?>
                                    <option value="<?php echo h($a); ?>" <?php echo (old('employment_type') == $a) ? 'selected' : ''; ?>>
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
                                placeholder="e.g. TES Philippines" value="<?php echo old('company_name', 'TES Philippines'); ?>"
                                pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Previous Company</label>
                            <input type="text" name="previous_company" class="form-control" maxlength="100"
                                placeholder="e.g. ABC Manufacturing Inc." value="<?php echo old('previous_company'); ?>"
                                pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')">
                        </div>
                    </div>

                    <h5 class="section-header">👤 Personal Details</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required maxlength="50"
                                placeholder="e.g. Juan" value="<?php echo old('first_name'); ?>" autocomplete="given-name"
                                pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control" maxlength="50"
                                placeholder="e.g. Santos" value="<?php echo old('middle_name'); ?>" autocomplete="additional-name"
                                pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required maxlength="50"
                                placeholder="e.g. Dela Cruz" value="<?php echo old('last_name'); ?>" autocomplete="family-name"
                                pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="">-- Select --</option>
                                <option value="Male" <?php echo (old('gender') == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (old('gender') == 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                            <input type="date" name="birth_date" class="form-control" required value="<?php echo old('birth_date'); ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control" maxlength="25"
                                placeholder="e.g. 0912-345-6789"
                                value="<?php echo old('contact_number'); ?>"
                                inputmode="tel" pattern="[0-9+\-\s()\/]+" title="Allowed: Numbers, +, -, /, ( )" oninput="this.value = this.value.replace(/[^0-9+\-\s()\/]/g, '')">
                            <div class="form-text extra-small">Max 25 chars. Allowed: Numbers, +, -, /, ( )</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" maxlength="100"
                                placeholder="juan@example.com" value="<?php echo old('email'); ?>" autocomplete="email">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Present Address <span class="text-danger">*</span></label>
                            <input type="text" name="present_address" class="form-control" required maxlength="150"
                                placeholder="House No, Street, Barangay, City, Province, ZIP"
                                value="<?php echo old('present_address'); ?>" autocomplete="street-address"
                                pattern="[a-zA-Z0-9\s\.,\-\/#\(\)\']+" title="Allowed: A-Z, 0-9, . , - / # ( ) '" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\/#\(\)\']/g, '')">
                            <div class="form-text extra-small">Max 150 chars. Allowed: A-Z, 0-9, . , - / #</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Permanent Address</label>
                            <input type="text" name="permanent_address" class="form-control" maxlength="150"
                                placeholder="If different from present address"
                                value="<?php echo old('permanent_address'); ?>"
                                pattern="[a-zA-Z0-9\s\.,\-\/#\(\)\']+" title="Allowed: A-Z, 0-9, . , - / # ( ) '" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\/#\(\)\']/g, '')">
                            <div class="form-text extra-small">Max 150 chars. No special symbols.</div>
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
                                placeholder="00-0000000-0" value="<?php echo old('sss_no'); ?>"
                                pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">TIN</label>
                            <input type="text" name="tin_no" class="form-control" maxlength="20"
                                placeholder="000-000-000-000" value="<?php echo old('tin_no'); ?>"
                                pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">PhilHealth</label>
                            <input type="text" name="philhealth_no" class="form-control" maxlength="20"
                                placeholder="e.g. 12-345678901-2" value="<?php echo old('philhealth_no'); ?>"
                                pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Pag-IBIG</label>
                            <input type="text" name="pagibig_no" class="form-control" maxlength="20"
                                placeholder="e.g. 1234-5678-9012" value="<?php echo old('pagibig_no'); ?>"
                                pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')">
                        </div>
                    </div>

                    <h5 class="section-header">🚨 Emergency Contact</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="emergency_name" class="form-control" maxlength="100"
                                placeholder="Full Name" value="<?php echo old('emergency_name'); ?>"
                                pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, ''); capitalize(this)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Number</label>
                            <input type="text" name="emergency_contact" class="form-control" maxlength="25"
                                placeholder="Mobile/Landline" value="<?php echo old('emergency_contact'); ?>"
                                inputmode="tel" pattern="[0-9+\-\s()\/]+" title="Allowed: Numbers, +, -, /, ( )" oninput="this.value = this.value.replace(/[^0-9+\-\s()\/]/g, '')">
                            <div class="form-text extra-small">Max 25 chars. Allowed: Numbers, +, -, /, ( )</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <input type="text" name="emergency_address" class="form-control" maxlength="150"
                                placeholder="Full Address" value="<?php echo old('emergency_address'); ?>"
                                pattern="[a-zA-Z0-9\s\.,\-\/#\(\)\']+" title="Allowed: A-Z, 0-9, . , - / # ( ) '" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\/#\(\)\']/g, '')">
                            <div class="form-text extra-small">Max 150 chars.</div>
                        </div>
                    </div>

                    <h5 class="section-header mt-4">🎓 Qualifications & Educational Background</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">Education <small class="text-muted">(Degrees, Certifications)</small></label>
                            <textarea name="education" class="form-control" rows="2" maxlength="1000" placeholder="e.g. BS Computer Science, Certified CPA" spellcheck="true" lang="en" style="text-align: justify; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo old('education'); ?></textarea>
                            <div class="form-text extra-small text-center">Max 1000 chars. Text auto-wraps. Press Enter for new lines.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Experience <small class="text-muted">(Relevant Work History)</small></label>
                            <textarea name="experience" class="form-control" rows="2" maxlength="1000" placeholder="e.g. 5 years as Senior Dev at Tech Corp" spellcheck="true" lang="en" style="text-align: justify; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo old('experience'); ?></textarea>
                            <div class="form-text extra-small text-center">Max 1000 chars. Text auto-wraps. Press Enter for new lines.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Skills <small class="text-muted">(Technical & Soft Skills)</small></label>
                            <textarea name="skills" class="form-control" rows="2" maxlength="1000" placeholder="e.g. PHP, Leadership, Communication" spellcheck="true" lang="en" style="text-align: justify; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo old('skills'); ?></textarea>
                            <div class="form-text extra-small text-center">Max 1000 chars. Press Enter for new lines.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Licenses / Certifications</label>
                            <textarea name="licenses" class="form-control" rows="2" maxlength="1000" placeholder="e.g. Driver's License, PRC License" spellcheck="true" lang="en" style="text-align: justify; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo old('licenses'); ?></textarea>
                            <div class="form-text extra-small text-center">Max 1000 chars. Type N/A if not applicable. Press Enter for new lines.</div>
                        </div>
                    </div>

                    <?php if (($_SESSION['role'] ?? '') === 'STAFF'): ?>
                        <div class="alert alert-warning">
                            <label class="form-label fw-bold"><i class="bi bi-chat-text"></i> Note for Admin</label>
                            <textarea name="request_note" class="form-control" rows="2" maxlength="250"
                                placeholder="Add any details for the admin..." spellcheck="true" lang="en" style="text-align: justify; white-space: pre-wrap; word-wrap: break-word;"><?php echo old('request_note'); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <?php echo (($_SESSION['role'] ?? '') === 'STAFF') ? 'Submit Request' : 'Save Employee'; ?>
                            </button>
                            <button type="button" class="btn btn-outline-primary w-50" id="saveDraftBtn">Save Draft</button>
                            <a href="index.php" class="btn btn-secondary w-50">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/sweetalert2.all.min.js"></script>
    <script src="dark_mode.js"></script>
    <script>
        // 1. FRIENDLY NAME MAPPING
        // This maps the Database Value (val) to the Dropdown Text (text)
        const sectionMap = <?php echo json_encode($sectionFriendlyMap); ?>;

        // Fallback for departments not in the list above (uses PHP data)
        const rawDeptMap = <?php echo json_encode($deptMap, JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
        const oldSection = "<?php echo htmlspecialchars($old['section'] ?? '', ENT_QUOTES); ?>";

        // 2. ELEMENT SELECTORS
        const deptInput = document.getElementById('dept');
        const sectionSelect = document.getElementById('sectionPicker');

        // [NEW] Multi-Department Logic
        function addDept(val) {
            if (!val) return;
            let current = deptInput.value;
            if (current) {
                if (!current.includes(val)) deptInput.value = current + ', ' + val;
            } else {
                deptInput.value = val;
            }
            document.getElementById('deptPicker').value = "";
            updateSections();
        }

        // 3. DYNAMIC DROPDOWN LOGIC
        function updateSections() {
            const depts = deptInput.value.split(',').map(s => s.trim()).filter(s => s !== '');
            sectionSelect.innerHTML = '<option value="">+ Add Section...</option>';

            let options = [];

            // Loop through ALL selected departments
            depts.forEach(dept => {
                let currentOptions = [];
                if (sectionMap[dept]) {
                    currentOptions = sectionMap[dept];
                } else if (rawDeptMap[dept]) {
                    currentOptions = rawDeptMap[dept].map(s => ({
                        val: s,
                        text: s
                    }));
                }

                if (currentOptions.length > 0) {
                    const group = document.createElement('optgroup');
                    group.label = dept;
                    currentOptions.forEach(data => {
                        const optEl = document.createElement('option');
                        optEl.value = data.val;
                        optEl.textContent = data.text;
                        group.appendChild(optEl);
                    });
                    sectionSelect.appendChild(group);
                }
            });

            // [NEW] Add Custom Option
            const otherOpt = document.createElement('option');
            otherOpt.value = 'custom';
            otherOpt.text = '-- Other (Type Custom) --';
            otherOpt.style.color = '#dc3545'; // Red to distinguish
            sectionSelect.appendChild(otherOpt);
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

        // [NEW] Multi-Section Logic
        function addSection(val) {
            const picker = document.getElementById('sectionPicker');

            if (val === 'custom') {
                Swal.fire({
                    title: 'Enter Custom Section',
                    input: 'text',
                    inputPlaceholder: 'e.g. Special Projects',
                    showCancelButton: true,
                    confirmButtonText: 'Add',
                    inputAttributes: {
                        maxlength: 50,
                        pattern: '[a-zA-Z0-9\\s\\-\\.]+'
                    },
                    inputValidator: (value) => {
                        if (!value) return 'Please enter a section name';
                        if (value.length > 50) return 'Section name too long (Max 50 chars)';
                        if (!/^[a-zA-Z0-9\s\-\.]+$/.test(value)) return 'Invalid characters allowed: Letters, Numbers, Spaces, Dots, Dashes';
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        appendSectionValue(result.value.toUpperCase());
                    }
                    picker.value = "";
                });
                return;
            }

            if (val) appendSectionValue(val);
            picker.value = "";
        }

        function appendSectionValue(text) {
            const input = document.getElementById('section');
            let current = input.value;
            if (current) {
                if (!current.includes(text)) input.value = current + ', ' + text;
            } else {
                input.value = text;
            }
        }

        // 5. ATTACH EVENTS
        if (deptInput && sectionSelect) {
            deptInput.addEventListener('change', updateSections);
            updateSections(); // Run once on load
        }

        // 6. ATTACH CAPITALIZATION
        document.addEventListener("DOMContentLoaded", function() {
            const fieldsToCap = ['first_name', 'middle_name', 'last_name', 'job_title', 'emergency_name'];
            fieldsToCap.forEach(name => {
                const input = document.querySelector(`[name="${name}"]`);
                if (input) {
                    input.addEventListener('input', function() {
                        capitalize(this);
                    });
                }
            });

            // 7. AUTO-SAVE DRAFT (Protects against Session Timeout)
            const form = document.querySelector('form');
            const indicator = document.getElementById('autoSaveIndicator');

            function updateAutoSaveIndicator(timestamp) {
                if (indicator && timestamp) {
                    const date = new Date(timestamp);
                    indicator.innerHTML = '<i class="bi bi-cloud-check"></i> Draft saved: ' + date.toLocaleTimeString();
                }
            }

            // A. Restore on Load
            const savedDraft = localStorage.getItem(draftKey);
            const firstInput = document.querySelector('input[name="first_name"]');

            // [FIX] Ask before restoring draft to prevent confusion/conflicts
            if (savedDraft && firstInput && !firstInput.value) {
                try {
                    const data = JSON.parse(savedDraft);

                    // Check Expiration (24 Hours)
                    const now = Date.now();
                    const savedAt = data._savedAt || 0;
                    const oneDayMs = 24 * 60 * 60 * 1000;

                    if (savedAt && (now - savedAt > oneDayMs)) {
                        localStorage.removeItem(draftKey); // Expired
                    } else {
                        Swal.fire({
                            title: 'Unsaved Draft Found',
                            text: 'Do you want to restore your previous unsaved work?',
                            icon: 'info',
                            showCancelButton: true,
                            confirmButtonText: 'Yes, Restore',
                            cancelButtonText: 'No, Discard'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                Object.keys(data).forEach(key => {
                                    if (key === '_savedAt') return; // Skip metadata
                                    const el = document.querySelector(`[name="${key}"]`);
                                    if (el && el.type !== 'file' && el.type !== 'hidden') el.value = data[key];
                                });
                                if (data._savedAt) updateAutoSaveIndicator(data._savedAt);
                            } else {
                                localStorage.removeItem(draftKey); // User chose to discard
                            }
                        });
                    }
                } catch (e) {}
            }

            // B. Save on Typing
            form.addEventListener('input', () => {
                const formData = new FormData(form);
                const data = {};
                formData.forEach((value, key) => {
                    if (key !== 'csrf_token' && key !== 'avatar') data[key] = value;
                });
                data._savedAt = Date.now(); // Add timestamp
                localStorage.setItem(draftKey, JSON.stringify(data));
                updateAutoSaveIndicator(data._savedAt);
            });

            // C. Clear on Submit (Optional: You can leave it to persist until manually cleared)
            // form.addEventListener('submit', () => localStorage.removeItem(draftKey));

            // [NEW] Save Draft Button Logic
            const saveDraftBtn = document.getElementById('saveDraftBtn');
            if (saveDraftBtn) {
                saveDraftBtn.addEventListener('click', () => {
                    const formData = new FormData(form);
                    const data = {};
                    formData.forEach((value, key) => {
                        if (key !== 'csrf_token' && key !== 'avatar') data[key] = value;
                    });
                    data._savedAt = Date.now(); // Add timestamp
                    localStorage.setItem(draftKey, JSON.stringify(data));
                    updateAutoSaveIndicator(data._savedAt);

                    const toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true
                    });
                    toast.fire({
                        icon: 'success',
                        title: 'Draft Saved Successfully'
                    });
                });
            }
        });

        // [NEW] Draft key for localStorage - keeps this in module scope shared by multiple handlers
        const draftKey = 'hr_add_emp_draft';

        // [NEW] Clear Form Button Logic
        const clearBtn = document.getElementById('clearFormBtnTop');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                Swal.fire({
                    title: 'Clear Form?',
                    text: "This will remove all data and the saved draft.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Yes, clear it'
                }).then((result) => {
                    if (result.isConfirmed) {
                        localStorage.removeItem(draftKey);
                        window.location.href = window.location.pathname;
                    }
                });
            });
        }

        // 8. CLIENT-SIDE VALIDATION & HIGHLIGHTING
        document.querySelector('form').addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = this.querySelectorAll('[required]');
            let firstError = null;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid'); // Bootstrap red border
                    if (!firstError) firstError = field;
                } else {
                    field.classList.remove('is-invalid');
                    field.classList.add('is-valid');
                }
            });

            if (!isValid) {
                e.preventDefault(); // Stop submission

                // Scroll to first error
                if (firstError) {
                    firstError.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    firstError.focus();
                }

                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Form',
                    text: 'Please fill out all required fields highlighted in red.',
                    confirmButtonText: 'OK, I will fix it'
                });
            } else {
                // [FIX] Clear draft on valid submission so next visit is clean
                localStorage.removeItem('hr_add_emp_draft');

                // [NEW] Prevent Double Submission (Fixes "Security Token Mismatch" on double-click)
                const btn = this.querySelector('button[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
                }
            }
        });

        // Remove red border when user types
        document.querySelectorAll('.form-control, .form-select').forEach(input => {
            input.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                }
            });
        });

        // [NEW] Handle URL Messages (Success/Error) on Page Load
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('msg')) {
                // Ensure draft is cleared
                localStorage.removeItem('hr_add_emp_draft');

                const msgText = urlParams.get('msg');
                const isPartial = msgText.includes('failed') || msgText.includes('Failed');

                Swal.fire({
                    icon: isPartial ? 'warning' : 'success',
                    title: isPartial ? 'Upload Completed' : 'Success',
                    text: msgText,
                    timer: isPartial ? undefined : 2500,
                    showConfirmButton: isPartial
                });
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    window.history.replaceState(null, null, url.toString());
                }
            }
            if (urlParams.has('error')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: urlParams.get('error')
                });
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('error');
                    window.history.replaceState(null, null, url.toString());
                }
            }
        });

        // [NEW] Auto-Resize Textareas (On Input and On Load)
        document.addEventListener('input', function(e) {
            if (e.target.tagName.toLowerCase() === 'textarea') {
                autoResize(e.target);
            }
        });

        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('textarea').forEach(autoResize);
        });

        function autoResize(el) {
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        }
    </script>
</body>

</html>