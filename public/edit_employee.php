<?php
// ======================================================
// [FILE] public/edit_employee.php
// [STATUS] FULL VERSION: Status + Exit Date + Exit Reason
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. REQUIRE LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$security = new Security($pdo);
$logger   = new Logger($pdo);

// 2. FETCH EMPLOYEE
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) die("Employee not found.");

// 3. CONFIGURATION

$deptMap = [
    "SQP"     => ["SAFETY", "QA", "PLANNING", "IT"], 
    "SIGCOM"  => ["SIGNALING", "COMMUNICATION", "SIG"],
    "PSS"     => ["POWER SUPPLY", "PSS"],
    "OCS"     => ["OVERHEAD", "CATENARY", "OCS"],
    "ADMIN"   => ["ADMIN","GAG", "TKG", "PCG", "ACG", "MED", "OP", "CLEANERS/HOUSE KEEPING"],
    "HMS"     => ["HEAVY MAINTENANCE", "HMS"],
    "RAS"     => ["ROOT CAUSE", "RAS"],
    "TRS"     => ["TECHNICAL RESEARCH", "TRS"],
    "LMS"     => ["LIGHT MAINTENANCE", "LMS"],
    "DOS"     => ["DEPARTMENT OPERATIONS", "DOS"],
    "CTS"     => ["CIVIL TRACKS", "CTS"],
    "BFS"     => ["BUILDING FACILITIES", "BFS"],
    "WHS"     => ["WAREHOUSE", "WHS"],
    "GUNJIN"  => ["EMT", "SECURITY", "GUNJIN"],
    "SUBCONS-OTHERS" => ["OTHERS"]
];

$emp_options    = ["TESP DIRECT", "GUNJIN", "JORATECH", "UNLISOLUTIONS", "OTHERS - SUBCONS"];

// Helpers
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function post($k, $d='') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function val($key) { global $emp; return h($_POST[$key] ?? $emp[$key] ?? ''); }
function raw($key) { global $emp; return (string)($_POST[$key] ?? $emp[$key] ?? ''); }

// 4. HANDLE SUBMIT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- COLLECTION OF ALL FIELDS ---
    $new_emp_id = post('emp_id', $emp['emp_id']);
    
    // Auto-Capitalize Names
    $first_name  = ucwords(strtolower(post('first_name', $emp['first_name'])));
    $middle_name = ucwords(strtolower(post('middle_name', $emp['middle_name'])));
    $last_name   = ucwords(strtolower(post('last_name', $emp['last_name'])));
    $job_title   = ucwords(strtolower(post('job_title', $emp['job_title'])));

    // Work Details
    $dept              = post('dept', $emp['dept']);
    $section           = post('section', $emp['section']);
    $company_name      = post('company_name', $emp['company_name']);
    $previous_company  = post('previous_company', $emp['previous_company']);
    $hire_date         = post('hire_date', $emp['hire_date']);
    
    // Status & Exit (Updated)
    $status            = post('status', $emp['status']);
    $exit_date         = post('exit_date', $emp['exit_date']); // Logic handled by JS/Display
    if ($exit_date === '') $exit_date = NULL; // Ensure NULL if empty
    $exit_reason       = post('exit_reason', $emp['exit_reason']); // <--- NEW FIELD

    // Personal Details
    $gender            = post('gender', $emp['gender']);
    $birth_date        = post('birth_date', $emp['birth_date']);
    $contact_number    = post('contact_number', $emp['contact_number']);
    $email             = post('email', $emp['email']);
    $present_address   = post('present_address', $emp['present_address']);
    $permanent_address = post('permanent_address', $emp['permanent_address']);

    // Government IDs
    $sss_no            = post('sss_no', $emp['sss_no']);
    $tin_no            = post('tin_no', $emp['tin_no']);
    $philhealth_no     = post('philhealth_no', $emp['philhealth_no']);
    $pagibig_no        = post('pagibig_no', $emp['pagibig_no']);
    
    // Emergency Contact
    $emergency_name    = ucwords(strtolower(post('emergency_name', $emp['emergency_name'])));
    $emergency_contact = post('emergency_contact', $emp['emergency_contact']);
    $emergency_address = post('emergency_address', $emp['emergency_address']);

    // Employment Type Logic
    $input_selection = post('employment_type', '');
    if ($input_selection === 'TESP DIRECT') {
        $employment_type = 'TESP Direct'; $agency_name = 'TESP';
    } else {
        $employment_type = 'Agency'; $agency_name = $input_selection ?: ($emp['agency_name'] ?? '');
    }

    $errors = [];
    if ($new_emp_id === '') $errors[] = "Employee ID is required.";
    if ($new_emp_id !== $emp['emp_id']) {
        $chk = $pdo->prepare("SELECT 1 FROM employees WHERE emp_id = ? AND id != ?");
        $chk->execute([$new_emp_id, $id]);
        if ($chk->fetch()) $errors[] = "ID $new_emp_id is already in use.";
    }

    // [SECURITY] Name Validation
    if (!preg_match("/^[a-zA-Z\s\-\.]+$/", $first_name)) $errors[] = "First Name contains invalid characters.";
    if ($middle_name !== '' && !preg_match("/^[a-zA-Z\s\-\.]+$/", $middle_name)) $errors[] = "Middle Name contains invalid characters.";
    if (!preg_match("/^[a-zA-Z\s\-\.]+$/", $last_name)) $errors[] = "Last Name contains invalid characters.";

    // Avatar Logic
    $final_avatar_path = $emp['avatar_path'];
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (array_key_exists($mime, $allowed)) {
            $ext = $allowed[$mime];
            $newName = preg_replace('/[^A-Za-z0-9\-_]/', '_', $new_emp_id) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/avatars/' . $newName)) {
                $final_avatar_path = $newName;
            }
        }
    }

    // 5. UPDATE DATABASE
    if (empty($errors)) {
        
        $updateData = [
            'emp_id' => $new_emp_id, 
            'first_name' => $first_name, 
            'middle_name' => $middle_name,
            'last_name' => $last_name, 
            'job_title' => $job_title, 
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
            'philhealth_no' => $philhealth_no, 
            'pagibig_no' => $pagibig_no,
            'emergency_name' => $emergency_name, 
            'emergency_contact' => $emergency_contact, 
            'emergency_address' => $emergency_address,
            'status' => $status, 
            'exit_date' => $exit_date,
            'exit_reason' => $exit_reason, // <--- SAVING THE REASON
            'avatar_path' => $final_avatar_path
        ];

        // LOGIC FIX: STAFF REQUEST vs ADMIN UPDATE
        if (($_SESSION['role'] ?? '') === 'STAFF') {
            // Staff: Include the note in the request payload
            $updateData['request_note'] = post('request_note');
            $payload = json_encode($updateData, JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'EDIT_PROFILE', ?, ?)")
                ->execute([$_SESSION['user_id'], $id, $payload]);
            
            header("Location: index.php?msg=" . urlencode("📝 Edit Request Submitted"));
            exit;
        } else {
            // Admin: Direct Update
            $setParts = [];
            $values = [];
            foreach ($updateData as $k => $v) {
                $setParts[] = "$k = ?";
                $values[] = $v;
            }
            $values[] = $id;

            try {
                $sql = "UPDATE employees SET " . implode(', ', $setParts) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($values);
                $logger->log($_SESSION['user_id'], 'EDIT_PROFILE', "Updated $new_emp_id");
                header("Location: index.php?msg=" . urlencode("✅ Saved Successfully"));
                exit;
            } catch (PDOException $e) {
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Employee</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f4f6f9; }
        .avatar-preview { width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 4px solid #fff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); background: #fff; }
        .exit-field { display: none; } /* Default hidden */
    </style>
</head>
<body class="bg-light">

<div class="container mt-5 mb-5">
    <!-- DEBUG: Force Show Document Buttons -->
    <div class="alert alert-warning text-center shadow-sm">
        <strong>🛠️ DEBUG TOOLS:</strong> 
        <a href="generate_document.php?id=<?php echo $id; ?>&type=probationary_lms" target="_blank" class="btn btn-sm btn-dark ms-2">Test Probationary Contract</a>
        <a href="generate_document.php?id=<?php echo $id; ?>&type=confidentiality" target="_blank" class="btn btn-sm btn-dark ms-2">Test NDA</a>
    </div>

    <div class="card shadow">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
            <h5 class="mb-0">✏️ Edit: <?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h5>
            <a href="index.php" class="btn btn-sm btn-dark">Back</a>
        </div>
        <div class="card-body">
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
                </div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-3 mb-4">
             <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>" 
                 class="avatar-preview" 
                 alt="Profile Photo"
                 onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">
                <div>
                    <div class="fw-bold"><?php echo h($emp['emp_id']); ?></div>
                    <div class="text-muted small"><?php echo h($emp['job_title']); ?></div>
                    <div class="text-muted small"><?php echo h($emp['dept'] . ' / ' . $emp['section']); ?></div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                
                <h6 class="text-secondary border-bottom pb-2 mb-3">Work Information</h6>
                <div class="row g-3">

                
                    <div class="col-md-3">
                        <label class="form-label">Employee ID</label>
                        <input type="text" name="emp_id" class="form-control" value="<?php echo val('emp_id'); ?>" required oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Job Title</label>
                        <input type="text" name="job_title" class="form-control" value="<?php echo val('job_title'); ?>" required oninput="capitalize(this)">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select name="dept" id="dept" class="form-select" required>
                            <?php foreach ($deptMap as $d => $s): ?>
                                <option value="<?php echo h($d); ?>" <?php echo ($emp['dept'] == $d) ? 'selected' : ''; ?>><?php echo h($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Section</label>
                        <select name="section" id="section" class="form-select" required></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Employment Type</label>
                        <select name="employment_type" class="form-select">
                            <?php foreach ($emp_options as $opt): ?>
                                <option value="<?php echo h($opt); ?>" 
                                    <?php echo ($emp['agency_name'] == $opt || ($opt=='TESP DIRECT' && $emp['employment_type']=='TESP Direct')) ? 'selected' : ''; ?>>
                                    <?php echo h($opt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hire Date</label>
                        <input type="date" name="hire_date" class="form-control" value="<?php echo val('hire_date'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo val('company_name'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Previous Company</label>
                        <input type="text" name="previous_company" class="form-control" value="<?php echo val('previous_company'); ?>">
                    </div>
                </div>

                <div class="row mb-3 mt-4 p-3 bg-light border rounded">
                    
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Current Status</label>
                        <select name="status" id="statusSelect" class="form-select border-primary fw-bold" onchange="toggleExitFields()">
                            <option value="Active" <?php if($emp['status']=='Active') echo 'selected'; ?>>Active</option>
                            <option value="Resigned" <?php if($emp['status']=='Resigned') echo 'selected'; ?>>Resigned</option>
                            <option value="Terminated" <?php if($emp['status']=='Terminated') echo 'selected'; ?>>Terminated</option>
                            <option value="AWOL" <?php if($emp['status']=='AWOL') echo 'selected'; ?>>AWOL</option>
                            <option value="Retired" <?php if($emp['status']=='Retired') echo 'selected'; ?>>Retired</option>
                        </select>
                    </div>

                    <div class="col-md-3 exit-field">
                        <label class="form-label fw-bold text-danger">Date of Exit</label>
                        <input type="date" name="exit_date" class="form-control border-danger text-danger fw-bold" 
                               value="<?php echo $emp['exit_date'] ?? ''; ?>">
                    </div>

                    <div class="col-md-6 exit-field">
                        <label class="form-label fw-bold text-danger">Reason for Leaving</label>
                        <input type="text" name="exit_reason" class="form-control border-danger" 
                               placeholder="e.g. Found better opportunity, Family reasons..." 
                               value="<?php echo htmlspecialchars($emp['exit_reason'] ?? ''); ?>">
                    </div>
                </div>
                <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Personal Details</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo val('first_name'); ?>" required oninput="capitalize(this)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?php echo val('middle_name'); ?>" oninput="capitalize(this)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo val('last_name'); ?>" required oninput="capitalize(this)">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="Male" <?php echo (raw('gender')=='Male')?'selected':''; ?>>Male</option>
                            <option value="Female" <?php echo (raw('gender')=='Female')?'selected':''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Birth Date</label>
                        <input type="date" name="birth_date" class="form-control" value="<?php echo val('birth_date'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?php echo val('contact_number'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo val('email'); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Present Address</label>
                        <input type="text" name="present_address" class="form-control" value="<?php echo val('present_address'); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Permanent Address</label>
                        <input type="text" name="permanent_address" class="form-control" value="<?php echo val('permanent_address'); ?>">
                    </div>
                    <div class="col-12">
                         <label class="form-label">Change Photo</label>
                         <input type="file" name="avatar" class="form-control" accept="image/*">
                    </div>
                </div>

                <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Government IDs</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">SSS No</label>
                        <input type="text" name="sss_no" class="form-control" value="<?php echo val('sss_no'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">TIN No</label>
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

                <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Emergency Contact</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="emergency_name" class="form-control" value="<?php echo val('emergency_name'); ?>" oninput="capitalize(this)">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact No</label>
                        <input type="text" name="emergency_contact" class="form-control" value="<?php echo val('emergency_contact'); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="emergency_address" class="form-control" value="<?php echo val('emergency_address'); ?>">
                    </div>
                </div>

                <?php if (($_SESSION['role'] ?? '') === 'STAFF'): ?>
                <div class="alert alert-warning mt-3">
                    <label class="form-label fw-bold">Note for Admin</label>
                    <textarea name="request_note" class="form-control" rows="2" placeholder="Reason for changes..."></textarea>
                </div>
                <?php endif; ?>

                <div class="mt-4 d-grid gap-2">
                    <button type="submit" class="btn btn-warning btn-lg">Save Changes</button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
            </form>
            
            <?php if (in_array($_SESSION['role'], ['ADMIN', 'HR'])): ?>
                <hr class="my-4">
                <div class="card border-primary shadow-sm">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold text-primary mb-1">📄 Document Generator</h6>
                            <small class="text-muted">Create legal PDFs for this employee instantly.</small>
                        </div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#docModal">
                            <i class="bi bi-file-earmark-pdf-fill"></i> Generate Document
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (in_array($_SESSION['role'], ['ADMIN', 'HR'])): ?>
                <hr class="my-5">
                <div class="card border-danger shadow-sm">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div class="text-danger fw-bold">⚠️ Danger Zone</div>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $id; ?>)">Delete Employee</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<form id="deleteForm" action="delete_employee.php" method="POST" style="display:none;"><input type="hidden" name="id" id="deleteId"></form>

<!-- DOCUMENT GENERATION MODAL -->
<div class="modal fade" id="docModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-printer"></i> Generate Document</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="docForm" target="_blank" action="generate_document.php" method="GET">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          
          <div class="mb-3">
            <label class="form-label fw-bold">Document Type</label>
            <select name="type" id="docType" class="form-select" onchange="toggleDateFields()">
              <option value="probationary_lms">📄 Probationary Contract (LMS Technician ONLY)</option>
              <option value="regular">📄 Regular Employment Contract</option>
              <option value="confidentiality">🔒 Confidentiality Agreement</option>
            </select>
            <div class="form-text text-muted" id="docHelp">Standard 6-month probationary contract.</div>
          </div>

          <!-- Date Selection (Hidden for NDA) -->
          <div id="dateFields" class="p-3 bg-light border rounded mb-3">
            <h6 class="text-primary fw-bold mb-3">Contract Validity (Longevity)</h6>
            
            <div class="mb-2">
                <label class="form-label small fw-bold">Effectivity Date (Start)</label>
                <input type="date" name="start_date" id="startDate" class="form-control" value="<?php echo $emp['hire_date']; ?>" onchange="calcEndDate()">
            </div>
            
            <div class="mb-2">
                <label class="form-label small fw-bold">Duration Preset</label>
                <select id="durationSelect" class="form-select form-select-sm" onchange="calcEndDate()" onkeyup="calcEndDate()">
                    <option value="6">6 Months (Probationary)</option>
                    <option value="12">1 Year (Regular)</option>
                    <option value="custom">Custom / Manual Edit</option>
                </select>
            </div>

            <div class="mb-0">
                <label class="form-label small fw-bold">Validity Until (End)</label>
                <input type="date" name="end_date" id="endDate" class="form-control">
            </div>
          </div>
          
          <div class="d-grid">
            <button type="submit" class="btn btn-success btn-lg">Generate PDF</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Logic for Sections and Auto-Capitalize
const deptMap = <?php echo json_encode($deptMap); ?>;
const currentSection = "<?php echo h($emp['section']); ?>";
const deptSelect = document.getElementById('dept');
const sectionSelect = document.getElementById('section');

function updateSections() {
    const d = deptSelect.value;
    sectionSelect.innerHTML = '<option value="">-- Select --</option>';
    if (deptMap[d]) {
        deptMap[d].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s;
            if (s === currentSection) opt.selected = true;
            sectionSelect.appendChild(opt);
        });
    }
}
function capitalize(input) {
    let words = input.value.split(' ');
    for (let i = 0; i < words.length; i++) {
        if (words[i].length > 0) words[i] = words[i].charAt(0).toUpperCase() + words[i].slice(1).toLowerCase();
    }
    input.value = words.join(' ');
}
function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This action cannot be undone. All related documents will be unlinked.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    });
}

// TOGGLE EXIT FIELDS LOGIC
function toggleExitFields() {
    const statusSelect = document.getElementById('statusSelect');
    if (!statusSelect) return; // Guard clause

    const status = statusSelect.value;
    const fields = document.querySelectorAll('.exit-field'); 

    // If status is ANYTHING other than 'Active', show the exit fields
    if (status !== 'Active') {
        fields.forEach(field => field.style.display = 'block');
    } else {
        fields.forEach(field => field.style.display = 'none');
    }
}

// DOCUMENT MODAL LOGIC
function toggleDateFields() {
    const type = document.getElementById('docType').value;
    const dateDiv = document.getElementById('dateFields');
    const help = document.getElementById('docHelp');

    if (type === 'confidentiality') {
        dateDiv.style.display = 'none';
        help.innerText = "Standard Non-Disclosure Agreement.";
    } else {
        dateDiv.style.display = 'block';
        if (type === 'regular') {
            document.getElementById('durationSelect').value = '12';
            help.innerText = "Contract for Regularized Employees.";
        } else if (type === 'probationary_lms') {
            document.getElementById('durationSelect').value = '6';
            help.innerText = "Standard 6-month probationary contract for LMS Technicians.";
        }
        calcEndDate();
    }
}

function calcEndDate() {
    const startVal = document.getElementById('startDate').value;
    const duration = document.getElementById('durationSelect').value;
    const endInput = document.getElementById('endDate');

    if (!startVal || duration === 'custom') return;

    const date = new Date(startVal);
    // Add months
    date.setMonth(date.getMonth() + parseInt(duration));
    // Format YYYY-MM-DD
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    endInput.value = `${yyyy}-${mm}-${dd}`;
}

// Run functions on load
if(deptSelect) { 
    deptSelect.addEventListener('change', updateSections); 
    updateSections(); 
}
document.addEventListener("DOMContentLoaded", toggleExitFields);
document.addEventListener("DOMContentLoaded", toggleDateFields); // Init Modal State
</script>
</body>
</html>