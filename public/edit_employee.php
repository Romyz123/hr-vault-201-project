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



// [SECURITY] Check Maintenance Mode
if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $chkMaint = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
    if ($chkMaint === '1') {
        header("Location: login.php?msg=" . urlencode("🛠️ System is under maintenance."));
        exit;
    }
}

$security = new Security($pdo);
$logger   = new Logger($pdo);

// [FIX] Initialize $dynamicCats early so it always exists for rendering.
// This prevents "Undefined variable" warnings in the HTML dropdowns if the later DB query fails.
$dynamicCats = [];


// [NEW] Fetch dynamic requirements for document categorization check
$REQUIRED_DOCS = [];
try {
    $reqStmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY id ASC");
    $reqList = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    $REQUIRED_DOCS = [
        '201 Files' => ['201', 'PDS', 'Data Sheet', 'Resume'],
        'Valid ID'  => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
        'Contract'  => ['Contract', 'Appointment', 'Offer'],
        'Medical'   => ['Medical', 'Fit to Work', 'Exam'],
        'Clearance' => ['NBI', 'Police', 'Barangay']
    ];
}

// 2. FETCH EMPLOYEE
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

// [FIX] Initialize $dynamicCats early so it always exists for rendering.
// This prevents "Undefined variable" warnings in the HTML dropdowns if the later DB query fails.
$dynamicCats = [];

if (!$emp) die("Employee not found.");

// [NEW] Fetch Documents for Digital 201 File Tab
$docStmt = $pdo->prepare("
    SELECT d.*, u.username as updater_name 
    FROM documents d 
    LEFT JOIN users u ON d.updated_by = u.id 
    WHERE d.employee_id = ? AND d.deleted_at IS NULL 
    ORDER BY d.uploaded_at DESC");
$docStmt->execute([$emp['emp_id']]);
$myDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// [NEW] Fetch Evaluations (Legacy)
$evals = [];
try {
    $evalStmt = $pdo->prepare("SELECT * FROM performance_evaluations WHERE employee_id = ? ORDER BY eval_date DESC");
    $evalStmt->execute([$id]);
    $evals = $evalStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

// [NEW] Fetch Employment History
$history = [];
try {
    $histStmt = $pdo->prepare("SELECT * FROM employment_history WHERE employee_id = ? ORDER BY event_date DESC");
    $histStmt->execute([$id]);
    $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

// [NEW] Fetch Dynamic Categories for the Edit Document Modal
try {
    // [FIX] Check if table exists before querying to prevent PDOExceptions
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'document_requirements'");
    if ($tableCheck->fetch()) {
        $stmt = $pdo->query("SELECT DISTINCT name FROM document_requirements ORDER BY name ASC");
        $dynamicCats = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $dynamicCats = array_filter($dynamicCats, function ($cat) {
            return strcasecmp(trim($cat), 'Others') !== 0;
        });
    } else {
        throw new Exception("Table 'document_requirements' not found.");
    }
} catch (Exception $e) {
    $dynamicCats = ['201 Files', 'Contract', 'Government IDs', 'Medical', 'Memo / DA', 'Evaluation', 'Certificate', 'Training Record'];
}

// 3. CONFIGURATION

// [NEW] Load Centralized Options
require __DIR__ . '/options.php';
$emp_options = $agencies;

// Helpers
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function post($k, $d = '')
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
}
function val($key)
{
    global $emp;
    return h($_POST[$key] ?? $emp[$key] ?? '');
}
function raw($key)
{
    global $emp;
    return (string)($_POST[$key] ?? $emp[$key] ?? '');
}

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 4. HANDLE SUBMIT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ Security Error: Invalid Session Token. Please refresh the page and try again.");
    }

    // [NEW] Handle Add Evaluation
    if (isset($_POST['action']) && $_POST['action'] === 'add_eval') {
        $eval_date = $_POST['eval_date'];
        $score = (int)$_POST['score'];
        $remarks = trim($_POST['remarks']);
        $evaluator = trim($_POST['evaluator']);

        // [SECURITY] Validation
        if ($score < 1 || $score > 100) {
            header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Score must be between 1 and 100."));
            exit;
        }
        if (strlen($evaluator) > 100) {
            header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Evaluator name is too long (Max 100 chars)."));
            exit;
        }
        if (!preg_match('/^[a-zA-Z\s\-\.\,]+$/', $evaluator)) {
            header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Evaluator name contains invalid characters (Letters only)."));
            exit;
        }
        if (strlen($remarks) > 1000) {
            header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Remarks are too long (Max 1000 chars)."));
            exit;
        }

        // Auto-Rating
        $rating = 'Poor';
        if ($score >= 90) $rating = 'Excellent';
        elseif ($score >= 80) $rating = 'Very Good';
        elseif ($score >= 70) $rating = 'Satisfactory';
        elseif ($score >= 60) $rating = 'Needs Improvement';

        $stmt = $pdo->prepare("INSERT INTO performance_evaluations (employee_id, eval_date, score, rating, remarks, evaluator) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $eval_date, $score, $rating, $remarks, $evaluator]);
        header("Location: edit_employee.php?id=$id&tab=eval&msg=" . urlencode("✅ Evaluation Added"));
        exit;
    }

    // [NEW] Handle Edit Evaluation
    if (isset($_POST['action']) && $_POST['action'] === 'edit_eval') {
        $eval_id = (int)$_POST['eval_id'];
        $eval_date = $_POST['eval_date'];
        $score = (int)$_POST['score'];
        $remarks = trim($_POST['remarks']);
        $evaluator = trim($_POST['evaluator']);

        // [SECURITY] Validation
        if ($score < 1 || $score > 100) {
            header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Score must be between 1 and 100."));
            exit;
        }
        if (!preg_match('/^[a-zA-Z\s\-\.\,]+$/', $evaluator)) {
            header("Location: edit_employee.php?id=$id&tab=eval&error=" . urlencode("❌ Evaluator name contains invalid characters (Letters only)."));
            exit;
        }

        // Auto-Rating
        $rating = 'Poor';
        if ($score >= 90) $rating = 'Excellent';
        elseif ($score >= 80) $rating = 'Very Good';
        elseif ($score >= 70) $rating = 'Satisfactory';
        elseif ($score >= 60) $rating = 'Needs Improvement';

        $stmt = $pdo->prepare("UPDATE performance_evaluations SET eval_date = ?, score = ?, rating = ?, remarks = ?, evaluator = ? WHERE id = ?");
        $stmt->execute([$eval_date, $score, $rating, $remarks, $evaluator, $eval_id]);
        header("Location: edit_employee.php?id=$id&tab=eval&msg=" . urlencode("✅ Evaluation Updated"));
        exit;
    }

    // [NEW] Handle Delete Evaluation
    if (isset($_POST['action']) && $_POST['action'] === 'delete_eval') {
        if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
            $delEvalId = (int)$_POST['eval_id'];
            $pdo->prepare("DELETE FROM performance_evaluations WHERE id = ?")->execute([$delEvalId]);
            header("Location: edit_employee.php?id=$id&tab=eval&msg=" . urlencode("✅ Evaluation Deleted"));
            exit;
        }
    }

    // [NEW] Handle Add History Event
    if (isset($_POST['action']) && $_POST['action'] === 'add_history') {
        if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
            $title = trim($_POST['event_title']);
            $date  = $_POST['event_date'];
            $dept  = trim($_POST['department']);
            $notes = trim($_POST['notes']);

            if (empty($title) || empty($date)) {
                header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Title and Date are required."));
                exit;
            }

            // Validate field lengths
            if (strlen($title) > 100) {
                header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Event title is too long (Max 100 chars)."));
                exit;
            }
            if (strlen($dept) > 100) {
                header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Department is too long (Max 100 chars)."));
                exit;
            }
            if (strlen($notes) > 1000) {
                header("Location: edit_employee.php?id=$id&tab=history&error=" . urlencode("❌ Notes are too long (Max 1000 chars)."));
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO employment_history (employee_id, event_title, event_date, department, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $title, $date, $dept, $notes]);

            $logger->log($_SESSION['user_id'], 'ADD_HISTORY', "Added history event for {$emp['emp_id']}: $title");
            header("Location: edit_employee.php?id=$id&tab=history&msg=" . urlencode("✅ History event added."));
            exit;
        }
    }
    // [NEW] Handle Delete History Event
    if (isset($_POST['action']) && $_POST['action'] === 'delete_history') {
        if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
            $histId = (int)$_POST['history_id'];
            $pdo->prepare("DELETE FROM employment_history WHERE id = ?")->execute([$histId]);
            header("Location: edit_employee.php?id=$id&tab=history&msg=" . urlencode("✅ Event deleted."));
            exit;
        }
    }

    // [NEW] Handle Delete All Documents
    if (isset($_POST['action']) && $_POST['action'] === 'delete_all_docs') {
        if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
            $stmt = $pdo->prepare("UPDATE documents SET deleted_at = NOW() WHERE employee_id = ? AND deleted_at IS NULL");
            $stmt->execute([$emp['emp_id']]);
            $logger->log($_SESSION['user_id'], 'DELETE_ALL_DOCS', "Deleted all documents for {$emp['emp_id']}");
            header("Location: edit_employee.php?id=$id&tab=docs&msg=" . urlencode("✅ All documents moved to Recycle Bin"));
            exit;
        }
    }

    // [REVISED] Handle Edit Document Details -> Creates a request for approval
    if (isset($_POST['action']) && $_POST['action'] === 'edit_doc') {
        if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR', 'STAFF'])) {
            $docId = (int)$_POST['doc_id'];
            $newName = trim($_POST['file_name']);
            $newCat = trim($_POST['category']);
            $moveToEmpId = trim($_POST['move_to_emp_id'] ?? '');
            $newExpiry = NULL;
            if (!empty($_POST['expiry_date'])) {
                $parsedExpiry = DateTime::createFromFormat('Y-m-d', $_POST['expiry_date']);
                if ($parsedExpiry && $parsedExpiry->format('Y-m-d') === $_POST['expiry_date']) {
                    $newExpiry = $_POST['expiry_date'];
                } else {
                    header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode("❌ Invalid expiry date format."));
                    exit;
                }
            }
            $isValid = true;
            $errorMsg = '';

            if (strlen($newName) > 100) {
                header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode("❌ File name too long (Max 100 chars)."));
                exit;
            }

            // [SECURITY] Validate Filename Characters
            if (!preg_match('/^[a-zA-Z0-9\s\-\.\(\)_]+$/', $newName)) {
                header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode("❌ Invalid filename. Allowed: Alphanumeric, Spaces, Dots, Dashes, Underscores, Parentheses."));
                exit;
            }

            // Handle "Others" Category
            if ($newCat === 'Others') {
                $otherCat = trim($_POST['other_category'] ?? '');
                if (empty($otherCat)) {
                    $isValid = false;
                    $errorMsg = "❌ Please specify the document type when 'Others' is selected.";
                } else {
                    if (strlen($otherCat) > 50) {
                        $isValid = false;
                        $errorMsg = "❌ Document type too long (Max 50 chars).";
                    }
                    $newCat = ucwords(strtolower($otherCat));
                }
            }

            if ($isValid) {
                // 1. Get original document details for logging/comparison
                $origDocStmt = $pdo->prepare("SELECT original_name, category, employee_id FROM documents WHERE id = ?");
                $origDocStmt->execute([$docId]);
                $origDoc = $origDocStmt->fetch();

                // Verify document ownership - must belong to current employee
                if (!$origDoc || $origDoc['employee_id'] != $emp['emp_id']) {
                    header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode("❌ You cannot edit documents for other employees."));
                    exit;
                }

                // [FIX] Preserve file extension to ensure format isn't lost
                $info = pathinfo($origDoc['original_name']);
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                if ($ext !== '' && (strlen($newName) < strlen($ext) || substr_compare($newName, $ext, -strlen($ext), strlen($ext), true) !== 0)) {
                    $newName .= $ext;
                }

                // 2a. STAFF: Submit Request
                if ($_SESSION['role'] === 'STAFF') {
                    // Check for existing pending request to prevent spam
                    $chkReq = $pdo->prepare("SELECT id FROM requests WHERE request_type = 'EDIT_DOC' AND target_id = ? AND status = 'PENDING'");
                    $chkReq->execute([$docId]);

                    if ($chkReq->fetch()) {
                        header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode("⚠️ Pending edit request already exists for this document."));
                        exit;
                    }

                    $payload = [
                        'new_name' => $newName,
                        'new_category' => $newCat,
                        'new_expiry_date' => $newExpiry,
                        'move_to_emp_id' => $moveToEmpId,
                        'original_details' => $origDoc
                    ];
                    $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'EDIT_DOC', ?, ?)")
                        ->execute([$_SESSION['user_id'], $docId, json_encode($payload)]);

                    header("Location: edit_employee.php?id=$id&tab=docs&msg=" . urlencode("📝 Document Edit Request Submitted"));
                    exit;
                }

                // 2. DIRECT UPDATE (Admins/Managers/HR are trusted)
                $updateSql = "UPDATE documents SET original_name = ?, category = ?, expiry_date = ?, updated_at = NOW(), updated_by = ?";
                $updateParams = [$newName, $newCat, $newExpiry, $_SESSION['user_id']];

                if (!empty($moveToEmpId)) {
                    // [FIX] Validate target employee exists
                    $chkTarget = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ? AND deleted_at IS NULL");
                    $chkTarget->execute([$moveToEmpId]);
                    if (!$chkTarget->fetch()) {
                        header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode("❌ Target Employee ID not found."));
                        exit;
                    }
                    $updateSql .= ", employee_id = ?";
                    $updateParams[] = $moveToEmpId;
                }

                $updateSql .= " WHERE id = ?";
                $updateParams[] = $docId;

                $stmt = $pdo->prepare($updateSql);
                $stmt->execute($updateParams);

                $logger->log($_SESSION['user_id'], 'EDIT_DOC', "Updated Doc ID $docId ($newName)");
                header("Location: edit_employee.php?id=$id&tab=docs&msg=" . urlencode("✅ Document details updated successfully."));
                exit;
            } else {
                header("Location: edit_employee.php?id=$id&tab=docs&error=" . urlencode($errorMsg));
                exit;
            }
        }
    }

    // [NEW] Handle Delete Employee (Full Cleanup)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_employee') {
        if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
            // [FIX] Soft Delete instead of Hard Delete
            $pdo->prepare("UPDATE employees SET deleted_at = NOW() WHERE id = ?")->execute([$id]);

            $logger->log($_SESSION['user_id'], 'SOFT_DELETE_EMPLOYEE', "Moved employee {$emp['emp_id']} to Recycle Bin");

            header("Location: index.php?msg=" . urlencode("🗑️ Employee moved to Recovery Console."));
            exit;
        }
    }

    $new_emp_id = post('emp_id', $emp['emp_id']);
    $first_name = ucwords(strtolower(post('first_name', $emp['first_name'])));
    $middle_name = ucwords(strtolower(post('middle_name', $emp['middle_name'])));
    $last_name = ucwords(strtolower(post('last_name', $emp['last_name'])));
    $job_title = ucwords(strtolower(post('job_title', $emp['job_title'])));
    $system_role = post('system_role', $emp['system_role'] ?? 'Staff'); // [NEW]
    $dept = post('dept', $emp['dept']);
    $section = post('section', $emp['section']);
    $company_name = post('company_name', $emp['company_name']);
    $previous_company = post('previous_company', $emp['previous_company']);
    $hire_date = post('hire_date', $emp['hire_date']);
    $gender = post('gender', $emp['gender']);
    $birth_date = post('birth_date', $emp['birth_date']);
    $contact_number = post('contact_number', $emp['contact_number']);
    $email = post('email', $emp['email']);
    $present_address = post('present_address', $emp['present_address']);
    $permanent_address = post('permanent_address', $emp['permanent_address']);
    $sss_no = post('sss_no', $emp['sss_no']);
    $tin_no = post('tin_no', $emp['tin_no']);
    $philhealth_no = post('philhealth_no', $emp['philhealth_no']);
    $pagibig_no = post('pagibig_no', $emp['pagibig_no']);
    $status = post('status', $emp['status']);
    $exit_date = post('exit_date', $emp['exit_date']);
    $exit_reason = post('exit_reason', $emp['exit_reason']);
    $emergency_name = ucwords(strtolower(post('emergency_name', $emp['emergency_name'])));

    $emergency_contact = post('emergency_contact', $emp['emergency_contact']);
    $emergency_address = post('emergency_address', $emp['emergency_address']);

    $education  = post('education', $emp['education'] ?? '');
    $experience = post('experience', $emp['experience'] ?? '');
    $skills     = post('skills', $emp['skills'] ?? '');
    $licenses   = post('licenses', $emp['licenses'] ?? '');

    // [NEW] Capture Request Note for Validation
    $request_note = post('request_note', '');

    // Employment Type Logic
    $input_selection = post('employment_type', '');
    if ($input_selection === 'TESP DIRECT') {
        $employment_type = 'TESP Direct';
        $agency_name = 'TESP';
    } else {
        $employment_type = 'Agency';
        $agency_name = $input_selection ?: ($emp['agency_name'] ?? '');
    }

    $errors = [];

    // [SECURITY] 1. Enforce Character Limits (Server-Side)
    $max = [
        'new_emp_id'        => 20,
        'job_title'         => 50,
        'system_role'       => 50,
        'company_name'      => 50,
        'previous_company'  => 100,
        'first_name'        => 50,
        'middle_name'       => 50,
        'last_name'         => 50,
        'contact_number'    => 25,
        'email'             => 100,
        'present_address'   => 150, // [FIX] Reduced to 150
        'permanent_address' => 150,
        'sss_no'            => 20,
        'tin_no'            => 20,
        'pagibig_no'        => 20,
        'philhealth_no'     => 20,
        'emergency_name'    => 100,
        'emergency_contact' => 25,
        'emergency_address' => 150,
        'education'         => 1000,
        'experience'        => 1000,
        'skills'            => 1000,
        'licenses'          => 1000,
        'exit_reason'       => 100,
        'dept'              => 50,
        'section'           => 100,
        'request_note'      => 500,
    ];

    foreach ($max as $k => $limit) {
        if (isset($$k) && mb_strlen((string)$$k, 'UTF-8') > $limit) {
            $errors[] = ucfirst(str_replace(['_', 'new '], ' ', $k)) . " exceeds maximum length of $limit characters.";
        }
    }

    if ($new_emp_id === '') $errors[] = "Employee ID is required.";
    if (!preg_match('/^[A-Za-z0-9\-_]{1,20}$/', $new_emp_id)) {
        $errors[] = "Employee ID contains invalid characters (letters, numbers, dash, underscore only).";
    }
    if ($new_emp_id !== $emp['emp_id']) {
        $chk = $pdo->prepare("SELECT 1 FROM employees WHERE emp_id = ? AND id != ?");
        $chk->execute([$new_emp_id, $id]);
        if ($chk->fetch()) $errors[] = "ID $new_emp_id is already in use.";
    }

    // [SECURITY] Name Validation
    if (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $first_name)) $errors[] = "First Name contains invalid characters. Allowed: Letters, spaces, dots, dashes, apostrophes.";
    if ($middle_name !== '' && !preg_match("/^[a-zA-Z\s\-\.\']+$/", $middle_name)) $errors[] = "Middle Name contains invalid characters.";
    if (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $last_name)) $errors[] = "Last Name contains invalid characters.";

    // [SECURITY] Job & Company Validation
    $textRegex = "/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/";
    if (!preg_match($textRegex, $job_title)) $errors[] = "Job Title contains invalid characters.";
    if ($company_name !== '' && !preg_match($textRegex, $company_name)) $errors[] = "Company Name contains invalid characters.";
    if ($previous_company !== '' && !preg_match($textRegex, $previous_company)) $errors[] = "Previous Company contains invalid characters.";

    // [SECURITY] Address Validation
    $addrRegex = "/^[a-zA-Z0-9\s\.,\-\/#\(\)\']+$/";
    if ($present_address !== '' && !preg_match($addrRegex, $present_address)) $errors[] = "Present Address contains invalid characters.";
    if ($permanent_address !== '' && !preg_match($addrRegex, $permanent_address)) $errors[] = "Permanent Address contains invalid characters.";
    if ($emergency_address !== '' && !preg_match($addrRegex, $emergency_address)) $errors[] = "Emergency Address contains invalid characters.";

    // [SECURITY] Contact & Email Validation
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    $phonePattern = '/^[0-9+\-\s()\/]{0,25}$/';
    if ($contact_number !== '' && !preg_match($phonePattern, $contact_number)) {
        $errors[] = "Contact Number contains invalid characters.";
    }
    if ($emergency_contact !== '' && !preg_match($phonePattern, $emergency_contact)) {
        $errors[] = "Emergency Contact contains invalid characters.";
    }

    // [SECURITY] Government IDs & Emergency Name
    $idRegex = "/^[0-9\-]+$/";
    if ($sss_no !== '' && !preg_match($idRegex, $sss_no)) $errors[] = "SSS No contains invalid characters.";
    if ($tin_no !== '' && !preg_match($idRegex, $tin_no)) $errors[] = "TIN No contains invalid characters.";
    if ($pagibig_no !== '' && !preg_match($idRegex, $pagibig_no)) $errors[] = "Pag-IBIG No contains invalid characters.";
    if ($philhealth_no !== '' && !preg_match($idRegex, $philhealth_no)) $errors[] = "PhilHealth No contains invalid characters.";
    if ($emergency_name !== '' && !preg_match("/^[a-zA-Z\s\-\.\']+$/", $emergency_name)) $errors[] = "Emergency Contact Name contains invalid characters.";

    // [SECURITY] Qualifications Validation
    $qualRegex = "/^[a-zA-Z0-9\s\.,\-\(\)\/\':]*$/";
    if (!preg_match($qualRegex, $education))  $errors[] = "Education contains invalid characters.";
    if (!preg_match($qualRegex, $experience)) $errors[] = "Experience contains invalid characters.";
    if (!preg_match($qualRegex, $skills))     $errors[] = "Skills contains invalid characters.";
    if (!preg_match($qualRegex, $licenses))   $errors[] = "Licenses contains invalid characters.";

    // [SECURITY] Date Validation
    $validHire  = DateTime::createFromFormat('Y-m-d', $hire_date) ?: false;
    $validBirth = DateTime::createFromFormat('Y-m-d', $birth_date) ?: false;
    $today      = new DateTime('today');

    if ($hire_date && (!$validHire || $validHire->format('Y-m-d') !== $hire_date))  $errors[] = "Invalid Hire Date.";
    if ($birth_date && (!$validBirth || $validBirth->format('Y-m-d') !== $birth_date)) $errors[] = "Invalid Birth Date.";

    if ($validBirth && $validBirth > $today) {
        $errors[] = "Birth Date cannot be in the future.";
    }
    if ($validHire && $validHire > (new DateTime('now'))->modify('+1 day')) {
        $errors[] = "Hire Date cannot be in the future.";
    }
    if ($validBirth && $validHire && $validHire < $validBirth) {
        $errors[] = "Hire Date cannot be earlier than Birth Date.";
    }

    // Avatar Logic
    $final_avatar_path = $emp['avatar_path'];
    $remove_avatar = $_POST['remove_avatar'] ?? '0';

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['avatar'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (array_key_exists($mime, $allowed)) {
            $ext = $allowed[$mime];
            $newName = preg_replace('/[^A-Za-z0-9\-_]/', '_', $new_emp_id) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

            $dest = __DIR__ . '/uploads/avatars/' . $newName;
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $final_avatar_path = $newName;
            }
        }
    } elseif ($remove_avatar === '1') {
        // [NEW] Remove the existing picture if the user clicked the trash icon
        $final_avatar_path = 'default.png';

        // Optional: Delete the old file from the hard drive to save space
        if ($emp['avatar_path'] && $emp['avatar_path'] !== 'default.png') {
            $oldPath = __DIR__ . '/uploads/avatars/' . basename($emp['avatar_path']);
            if (file_exists($oldPath)) @unlink($oldPath);
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
            'philhealth_no' => $philhealth_no,
            'pagibig_no' => $pagibig_no,
            'emergency_name' => $emergency_name,
            'emergency_contact' => $emergency_contact,
            'emergency_address' => $emergency_address,
            'education' => $education,
            'experience' => $experience,
            'skills' => $skills,
            'licenses' => $licenses,
            'status' => $status,
            'exit_date' => $exit_date,
            'exit_reason' => $exit_reason, // <--- SAVING THE REASON
            'avatar_path' => $final_avatar_path
        ];

        // LOGIC FIX: STAFF REQUEST vs ADMIN UPDATE
        if (($_SESSION['role'] ?? '') === 'STAFF') {
            // [FIX] Check for existing pending request to prevent spam
            $chkReq = $pdo->prepare("SELECT id FROM requests WHERE request_type = 'EDIT_PROFILE' AND target_id = ? AND status = 'PENDING'");
            $chkReq->execute([$id]);

            if ($chkReq->fetch()) {
                $errors[] = "⚠️ You already have a pending edit request for this employee. Please wait for approval.";
            } else {
                // Staff: Include the note in the request payload
                $updateData['request_note'] = post('request_note');
                $payload = json_encode([
                    'new_data' => $updateData,
                    'old_data' => $emp // Snapshot of current state
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'EDIT_PROFILE', ?, ?)")
                    ->execute([$_SESSION['user_id'], $id, $payload]);

                header("Location: index.php?msg=" . urlencode("📝 Edit Request Submitted"));
                exit;
            }
        } else {
            // Admin: Direct Update
            $setParts = [];
            $values = [];
            foreach ($updateData as $k => $v) {
                $setParts[] = "$k = ?";
                $values[] = $v;
            }
            // [NEW] Track when the profile was last updated
            $setParts[] = "updated_at = NOW()";
            $values[] = $id;

            try {
                // [FIX] Check if emp_id changed, and cascade update if so
                if ($new_emp_id !== $emp['emp_id']) {
                    $pdo->beginTransaction();

                    // Update Employee
                    $sql = "UPDATE employees SET " . implode(', ', $setParts) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($values);

                    // Cascade to Documents
                    $pdo->prepare("UPDATE documents SET employee_id = ? WHERE employee_id = ?")->execute([$new_emp_id, $emp['emp_id']]);

                    // Cascade to Disciplinary Cases
                    $pdo->prepare("UPDATE disciplinary_cases SET employee_id = ? WHERE employee_id = ?")->execute([$new_emp_id, $emp['emp_id']]);

                    // Cascade to Maintenance Logs
                    $pdo->prepare("UPDATE maintenance_logs SET employee_id = ? WHERE employee_id = ?")->execute([$new_emp_id, $emp['emp_id']]);

                    // [FIX] Ensure Exemptions are moved
                    $pdo->prepare("UPDATE document_exemptions SET employee_id = ? WHERE employee_id = ?")->execute([$new_emp_id, $emp['emp_id']]);

                    $pdo->commit();
                } else {
                    $sql = "UPDATE employees SET " . implode(', ', $setParts) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($values);
                }

                // [NEW] Auto-History: Detect Job/Dept Changes
                $histEvents = [];
                if ($emp['job_title'] !== $job_title) {
                    $histEvents[] = [
                        'title' => "Position Change",
                        'notes' => "Changed from '{$emp['job_title']}' to '$job_title'"
                    ];
                }
                if ($emp['dept'] !== $dept || $emp['section'] !== $section) {
                    $oldD = $emp['dept'] . ($emp['section'] ? " / {$emp['section']}" : "");
                    $newD = $dept . ($section ? " / $section" : "");
                    $histEvents[] = [
                        'title' => "Department Transfer",
                        'notes' => "Moved from '$oldD' to '$newD'"
                    ];
                }
                if (!empty($histEvents)) {
                    $hStmt = $pdo->prepare("INSERT INTO employment_history (employee_id, event_title, event_date, department, notes) VALUES (?, ?, CURDATE(), ?, ?)");
                    foreach ($histEvents as $he) {
                        $hStmt->execute([$id, $he['title'], $dept, $he['notes']]);
                    }
                }

                $logger->log($_SESSION['user_id'], 'EDIT_PROFILE', "Updated $new_emp_id");

                // [SECURITY] Regenerate CSRF token after successful update
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $action = $_POST['save_action'] ?? 'stay';
                if ($action === 'close') {
                    header("Location: index.php?msg=" . urlencode("✅ Saved Successfully"));
                } else {
                    header("Location: edit_employee.php?id=$id&msg=" . urlencode("✅ Saved Successfully"));
                }
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Log the full error server-side for debugging
                error_log('Database Error in edit_employee.php: ' . $e->getMessage() . '. Stack: ' . $e->getTraceAsString());
                // Show generic error to user
                $errors[] = "A database error occurred. Please contact support if the problem persists.";
            }
        }
    }
}
?>
<?php require 'header.php'; ?>
<style>
    .avatar-preview {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid #fff;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        background: #fff;
    }

    #cameraVideo,
    #cameraPreviewImage {
        transform: scaleX(-1);
        /* Selfie mirror effect */
    }
</style>

<div class="container">
    <div class="card shadow">
        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
                </div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-3 mb-4">
                <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>"
                    data-original-src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: 'default.png'); ?>"
                    class="avatar-preview"
                    alt="Profile Photo"
                    onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">
                <div>
                    <div class="fw-bold"><?php echo h($emp['emp_id']); ?></div>
                    <div class="text-muted small"><?php echo h($emp['job_title']); ?></div>
                    <div class="text-muted small"><?php echo h($emp['dept'] . ' / ' . $emp['section']); ?></div>
                </div>
            </div>

            <!-- TABS NAVIGATION -->
            <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active fw-bold" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button"><i class="bi bi-person-vcard"></i> Personal Details</button></li>
                <li class="nav-item"><button class="nav-link fw-bold" id="docs-tab" data-bs-toggle="tab" data-bs-target="#docs" type="button"><i class="bi bi-folder2-open"></i> Digital 201 File <span class="badge bg-secondary rounded-pill ms-1"><?php echo count($myDocs); ?></span></button></li>
                <li class="nav-item"><button class="nav-link fw-bold" id="eval-tab" data-bs-toggle="tab" data-bs-target="#eval" type="button"><i class="bi bi-graph-up-arrow"></i> Evaluation</button></li>
                <li class="nav-item"><button class="nav-link fw-bold" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button"><i class="bi bi-clock-history"></i> History</button></li>
            </ul>

            <div class="tab-content" id="profileTabsContent">
                <!-- TAB 1: PERSONAL DETAILS -->
                <div class="tab-pane fade show active" id="details" role="tabpanel">
                    <form id="editEmployeeForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="remove_avatar" id="removeAvatarFlag" value="0">

                        <h6 class="text-secondary border-bottom pb-2 mb-3">Work Information</h6>
                        <div class="row g-3">


                            <div class="col-md-3">
                                <label class="form-label">Employee ID</label>
                                <input type="text" name="emp_id" class="form-control" value="<?php echo val('emp_id'); ?>" required oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9\-_]/g, '')" pattern="[A-Z0-9\-_]+" title="Allowed: Letters, Numbers, - and _" maxlength="20">
                                <div class="form-text small">Allowed: Letters, Numbers, - and _</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Job Title</label>
                                <input type="text" name="job_title" class="form-control" value="<?php echo val('job_title'); ?>" required oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, ''); capitalize(this)" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Allowed: Alphanumeric and basic punctuation" maxlength="50">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Contract Role</label>
                                <select name="system_role" class="form-select">
                                    <?php foreach ($system_roles as $role): ?>
                                        <option value="<?php echo h($role); ?>" <?php echo ($emp['system_role'] == $role) ? 'selected' : ''; ?>><?php echo h($role); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Department(s)</label>
                                <div class="input-group">
                                    <input type="text" name="dept" id="dept" class="form-control bg-white" required readonly value="<?php echo val('dept'); ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('dept').value = ''; updateSections();" title="Clear"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <select id="deptPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addDept(this.value)">
                                    <option value="">+ Add Department...</option>
                                    <?php foreach ($deptMap as $d => $s): ?>
                                        <option value="<?php echo h($d); ?>"><?php echo h($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Section(s)</label>
                                <div class="input-group">
                                    <input type="text" name="section" id="section" class="form-control bg-white" required readonly value="<?php echo val('section'); ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('section').value = ''" title="Clear"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <select id="sectionPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addSection(this.value)"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Employment Type</label>
                                <select name="employment_type" class="form-select">
                                    <?php foreach ($emp_options as $opt): ?>
                                        <option value="<?php echo h($opt); ?>"
                                            <?php echo ($emp['agency_name'] == $opt || ($opt == 'TESP DIRECT' && $emp['employment_type'] == 'TESP Direct')) ? 'selected' : ''; ?>>
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
                                <input type="text" name="company_name" class="form-control" value="<?php echo val('company_name'); ?>" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')" maxlength="50">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Previous Company</label>
                                <input type="text" name="previous_company" class="form-control" value="<?php echo val('previous_company'); ?>" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')" maxlength="100">
                            </div>
                        </div>

                        <div class="row mb-3 mt-4 p-3 bg-light border rounded">

                            <div class="col-md-3">
                                <label class="form-label fw-bold">Current Status</label>
                                <select name="status" id="statusSelect" class="form-select border-primary fw-bold" onchange="toggleExitFields()">
                                    <option value="Active" <?php if ($emp['status'] == 'Active') echo 'selected'; ?>>Active</option>
                                    <option value="Resigned" <?php if ($emp['status'] == 'Resigned') echo 'selected'; ?>>Resigned</option>
                                    <option value="Terminated" <?php if ($emp['status'] == 'Terminated') echo 'selected'; ?>>Terminated</option>
                                    <option value="AWOL" <?php if ($emp['status'] == 'AWOL') echo 'selected'; ?>>AWOL</option>
                                    <option value="Retired" <?php if ($emp['status'] == 'Retired') echo 'selected'; ?>>Retired</option>
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
                                    value="<?php echo htmlspecialchars($emp['exit_reason'] ?? ''); ?>"
                                    maxlength="100"
                                    pattern="[a-zA-Z0-9\s.,'-]+"
                                    title="Only letters, numbers, spaces, and basic punctuation (.,'-) are allowed."
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s.,'-]/g, '')">
                                <div class="form-text text-danger small" style="font-size: 0.75rem;">
                                    * Max 100 chars. No special symbols allowed.
                                </div>
                            </div>
                        </div>
                        <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Personal Details</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" value="<?php echo val('first_name'); ?>" required oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, ''); capitalize(this)" pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" maxlength="50">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" value="<?php echo val('middle_name'); ?>" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, ''); capitalize(this)" pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" maxlength="50">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" value="<?php echo val('last_name'); ?>" required oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, ''); capitalize(this)" pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" maxlength="50">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="Male" <?php echo (raw('gender') == 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (raw('gender') == 'Female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Birth Date</label>
                                <input type="date" name="birth_date" class="form-control" value="<?php echo val('birth_date'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="contact_number" class="form-control" maxlength="25" value="<?php echo val('contact_number'); ?>" oninput="this.value = this.value.replace(/[^0-9+\-\s()\/]/g, '')" pattern="[0-9+\-\s()\/]+" title="Allowed: Numbers, +, -, /, ( )">
                                <div class="form-text small">Max 25 chars. Allowed: Numbers, +, -, /, ( )</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo val('email'); ?>" maxlength="100">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Present Address</label>
                                <input type="text" name="present_address" class="form-control" value="<?php echo val('present_address'); ?>" maxlength="150" pattern="[a-zA-Z0-9\s\.,\-\/#\(\)\']+" title="Allowed: A-Z, 0-9, . , - / # ( ) '" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\/#\(\)\']/g, '')">
                                <div class="form-text small">Max 150 chars. Allowed: A-Z, 0-9, . , - / #</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Permanent Address</label>
                                <input type="text" name="permanent_address" class="form-control" value="<?php echo val('permanent_address'); ?>" maxlength="150" pattern="[a-zA-Z0-9\s\.,\-\/#\(\)\']+" title="Allowed: A-Z, 0-9, . , - / # ( ) '" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\/#\(\)\']/g, '')">
                                <div class="form-text small">Max 150 chars.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Change Photo</label>
                                <div class="input-group">
                                    <input type="file" name="avatar" id="avatarInput" class="form-control" accept="image/*" onchange="previewAvatar(this)">
                                    <button type="button" class="btn btn-outline-danger" onclick="clearAvatar()" title="Remove Photo"><i class="bi bi-trash"></i></button>
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cameraModal" onclick="startCamera()"><i class="bi bi-camera"></i> Take Photo</button>
                                </div>
                            </div>
                        </div>

                        <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Government IDs</h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">SSS No</label>
                                <input type="text" name="sss_no" class="form-control" value="<?php echo val('sss_no'); ?>" pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')" maxlength="20">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">TIN No</label>
                                <input type="text" name="tin_no" class="form-control" value="<?php echo val('tin_no'); ?>" pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')" maxlength="20">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">PhilHealth</label>
                                <input type="text" name="philhealth_no" class="form-control" value="<?php echo val('philhealth_no'); ?>" pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')" maxlength="20">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Pag-IBIG</label>
                                <input type="text" name="pagibig_no" class="form-control" value="<?php echo val('pagibig_no'); ?>" pattern="[0-9\-]+" title="Allowed: Numbers and dashes" oninput="this.value = this.value.replace(/[^0-9\-]/g, '')" maxlength="20">
                            </div>
                        </div>

                        <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Emergency Contact</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" name="emergency_name" class="form-control" value="<?php echo val('emergency_name'); ?>" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, ''); capitalize(this)" pattern="[a-zA-Z\s\-\.\']+" title="Allowed: Letters, spaces, dots, dashes, apostrophes" maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact No</label>
                                <input type="text" name="emergency_contact" class="form-control" maxlength="25" value="<?php echo val('emergency_contact'); ?>" oninput="this.value = this.value.replace(/[^0-9+\-\s()\/]/g, '')" pattern="[0-9+\-\s()\/]" title="Allowed: Numbers, +, -, /, ( )">
                                <div class="form-text small">Max 25 chars. Allowed: Numbers, +, -, /, ( )</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <input type="text" name="emergency_address" class="form-control" value="<?php echo val('emergency_address'); ?>" maxlength="150" pattern="[a-zA-Z0-9\s\.,\-\/#\(\)\']+" title="Allowed: A-Z, 0-9, . , - / # ( ) '" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\/#\(\)\']/g, '')">
                                <div class="form-text small">Max 150 chars.</div>
                            </div>
                        </div>

                        <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4">Qualifications & Educational Background</h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Education</label>
                                <textarea name="education" class="form-control" rows="2" maxlength="1000" spellcheck="true" lang="en" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo val('education'); ?></textarea>
                                <div class="form-text small text-center">Max 1000 chars. Text auto-wraps. Press Enter for new lines.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Experience</label>
                                <textarea name="experience" class="form-control" rows="2" maxlength="1000" spellcheck="true" lang="en" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo val('experience'); ?></textarea>
                                <div class="form-text small text-center">Max 1000 chars. Text auto-wraps. Press Enter for new lines.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Skills</label>
                                <textarea name="skills" class="form-control" rows="2" maxlength="1000" spellcheck="true" lang="en" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo val('skills'); ?></textarea>
                                <div class="form-text small text-center">Max 1000 chars. Text auto-wraps. Press Enter for new lines.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Licenses / Certifications</label>
                                <textarea name="licenses" class="form-control" rows="2" maxlength="1000" spellcheck="true" lang="en" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/g, '')"><?php echo val('licenses'); ?></textarea>
                                <div class="form-text small">Max 1000 chars. Type N/A if not applicable. Press Enter for new lines.</div>
                            </div>
                        </div>

                        <?php if (($_SESSION['role'] ?? '') === 'STAFF'): ?>
                            <div class="alert alert-warning mt-3">
                                <label class="form-label fw-bold">Note for Admin</label>
                                <textarea name="request_note" class="form-control" rows="2" placeholder="Reason for changes..." style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" maxlength="500"></textarea>
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 d-flex gap-2">
                            <button type="submit" name="save_action" value="stay" class="btn btn-success btn-lg flex-grow-1">Save Changes</button>
                            <a href="index.php" class="btn btn-secondary btn-lg">Cancel</a>
                        </div>
                    </form>

                    <?php if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR', 'STAFF']) && ($emp['employment_type'] === 'TESP Direct' || $emp['agency_name'] === 'TESP')): ?>
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

                    <?php if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])): ?>
                        <hr class="my-5">
                        <div class="card border-danger shadow-sm">
                            <div class="card-body d-flex justify-content-between align-items-center">
                                <div class="text-danger fw-bold">⚠️ Danger Zone</div>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $id; ?>)">Delete Employee</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 2: DIGITAL 201 FILE -->
                <div class="tab-pane fade" id="docs" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-primary mb-0">📂 Uploaded Documents</h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-dark me-2" data-bs-toggle="modal" data-bs-target="#downloadAllModal">
                                <i class="bi bi-file-earmark-zip-fill"></i> Download All
                            </button>
                            <?php if (!empty($myDocs) && in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete ALL documents for this employee? They will be moved to the Recycle Bin.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete_all_docs">
                                    <button type="submit" class="btn btn-sm btn-outline-danger me-2"><i class="bi bi-trash3-fill"></i> Delete All</button>
                                </form>
                            <?php endif; ?>
                            <a href="upload_form.php?emp_id=<?php echo h($emp['emp_id']); ?>" class="btn btn-sm btn-success"><i class="bi bi-cloud-upload-fill"></i> Upload New</a>
                        </div>
                    </div>

                    <?php if (empty($myDocs)): ?>
                        <div class="alert alert-light text-center border border-dashed p-5">
                            <i class="bi bi-folder-x fs-1 text-muted"></i>
                            <p class="text-muted mt-2">No documents found in this Digital 201 File.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($myDocs as $d): ?>
                                <?php
                                // [NEW] Check if this specific file is uncategorized
                                $isThisDocUncategorized = true;
                                $fCat = trim($d['category'] ?? '');
                                $fName = $d['original_name'];
                                foreach ($REQUIRED_DOCS as $reqName => $keywords) {
                                    if (strcasecmp($fCat, $reqName) === 0) {
                                        $isThisDocUncategorized = false;
                                        break;
                                    }
                                    foreach ($keywords as $k) {
                                        if ($k !== '' && (stripos($fName, $k) !== false || stripos($fCat, $k) !== false)) {
                                            $isThisDocUncategorized = false;
                                            break 2;
                                        }
                                    }
                                }
                                ?>
                                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-bold text-dark">
                                            <i class="bi bi-file-earmark-text me-2 text-secondary"></i>
                                            <a href="view_doc.php?id=<?php echo $d['file_uuid']; ?>" target="_blank" class="text-decoration-none text-dark stretched-link">
                                                <?php echo h($d['original_name']); ?>
                                                <?php if ($isThisDocUncategorized): ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" style="font-size: 0.65rem;"><i class="bi bi-tag-fill"></i> Needs Categorization</span>
                                                <?php endif; ?>
                                            </a>
                                        </div>
                                        <small class="text-muted">
                                            <span class="badge bg-light text-dark border"><?php echo h($d['category']); ?></span>
                                            • Uploaded <?php echo date('M d, Y', strtotime($d['uploaded_at'])); ?>
                                            <?php if (!empty($d['updated_at'])): ?>
                                                • <span class="text-primary fw-bold" title="Modified by <?php echo h($d['updater_name'] ?? 'Unknown'); ?>">
                                                    Modified <?php echo date('M d, Y', strtotime($d['updated_at'])); ?>
                                                    by <?php echo h($d['updater_name'] ?? 'Admin'); ?>
                                                </span>
                                                <?php if (strtotime($d['updated_at']) > strtotime('-24 hours')): ?>
                                                    <span class="badge bg-info text-dark ms-1">NEW</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($d['is_resolved']) && !empty($d['resolution_note'])): ?>
                                                <div class="alert alert-success p-1 mt-1 mb-0 border-success" style="font-size: 0.75rem; display:inline-block;">
                                                    <strong><i class="bi bi-check-circle-fill"></i> Resolved:</strong> <?php echo h($d['resolution_note']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <a href="view_doc.php?id=<?php echo $d['file_uuid']; ?>" target="_blank" class="btn btn-sm btn-outline-primary position-relative z-2">View</a>

                                    <?php if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR', 'STAFF'], true)): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning position-relative z-2 ms-1"
                                            onclick='openEditDocModal(<?php echo (int)$d['id']; ?>, <?php echo json_encode($d['original_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo json_encode($d['category'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo json_encode($d['expiry_date']); ?>)'
                                            title="Edit Details">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 3: EVALUATION -->
                <div class="tab-pane fade" id="eval" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-primary mb-0">📊 Evaluation History</h6>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addEvalModal"><i class="bi bi-plus-circle"></i> Add Evaluation</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Score</th>
                                    <th>Rating</th>
                                    <th>Evaluator</th>
                                    <th>Remarks</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evals as $ev):
                                    $badge = 'bg-secondary';
                                    if ($ev['score'] >= 90) $badge = 'bg-success';
                                    elseif ($ev['score'] >= 75) $badge = 'bg-info';
                                    elseif ($ev['score'] >= 60) $badge = 'bg-warning';
                                    else $badge = 'bg-danger';
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($ev['eval_date']))); ?></td>
                                        <td><span class="badge <?php echo $badge; ?>"><?php echo $ev['score']; ?>%</span></td>
                                        <td><?php echo htmlspecialchars($ev['rating']); ?></td>
                                        <td><?php echo htmlspecialchars($ev['evaluator']); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($ev['remarks']); ?></td>
                                        <td>
                                            <a href="print_evaluation.php?id=<?php echo $ev['id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Print"><i class="bi bi-printer"></i></a>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick='openEditEvalModal(<?php echo $ev['id']; ?>, <?php echo json_encode($ev['eval_date']); ?>, <?php echo $ev['score']; ?>, <?php echo json_encode($ev['evaluator'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo json_encode($ev['remarks'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="confirmDeleteEval(event, this)">
                                                <input type="hidden" name="action" value="delete_eval">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="eval_id" value="<?php echo $ev['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 4: HISTORY TIMELINE -->
                <div class="tab-pane fade" id="history" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 class="fw-bold text-primary mb-0">📅 Employment History</h6>
                        <div>
                            <a href="print_history.php?id=<?php echo $id; ?>" target="_blank" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-printer"></i> Print History</a>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addHistoryModal"><i class="bi bi-plus-circle"></i> Add Event</button>
                        </div>
                    </div>

                    <div class="timeline">
                        <?php if (empty($history)): ?>
                            <div class="text-muted fst-italic ps-4">No history events recorded yet.</div>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker"></div>
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($h['event_title']); ?></h6>
                                                <div class="small text-muted mb-2">
                                                    <i class="bi bi-calendar-event me-1"></i> <?php echo date('F d, Y', strtotime($h['event_date'])); ?>
                                                    <?php if (!empty($h['department'])): ?>
                                                        <span class="mx-1">•</span> <?php echo htmlspecialchars($h['department']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])): ?>
                                                <form method="POST" onsubmit="return confirm('Delete this event?');">
                                                    <input type="hidden" name="action" value="delete_history">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="history_id" value="<?php echo $h['id']; ?>">
                                                    <button class="btn btn-sm btn-link text-danger p-0 border-0"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($h['notes'])): ?>
                                            <div class="bg-light p-2 rounded border small text-secondary">
                                                <?php echo nl2br(htmlspecialchars($h['notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Start Node -->
                        <div class="timeline-item mb-0">
                            <div class="timeline-marker bg-secondary"></div><span class="text-muted small">Joined Company</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete_employee">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
</form>

<!-- EDIT DOCUMENT MODAL -->
<div class="modal fade" id="editDocModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_doc">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="doc_id" id="edit_doc_id">

                <div class="mb-3">
                    <label class="form-label fw-bold">File Name</label>
                    <input type="text" name="file_name" id="edit_file_name" class="form-control" required
                        maxlength="100" pattern="[a-zA-Z0-9\.\-_ \(\)]+" title="Allowed: Letters, Numbers, Dots, Dashes, Underscores, Spaces, Parentheses"
                        oninput="this.value = this.value.replace(/[^a-zA-Z0-9\.\-_ \(\)]/g, '')">
                    <div class="form-text">Tip: Keep the file extension (e.g. .pdf) to ensure it opens correctly.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Category</label>
                    <select name="category" id="edit_category" class="form-select" required onchange="toggleEditOther()">
                        <?php foreach ($dynamicCats as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                        <option disabled>──────────</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Expiration Date (Optional)</label>
                    <input type="date" name="expiry_date" id="edit_expiry_date" class="form-control">
                    <div class="form-text">Leave blank if the document does not expire.</div>
                </div>
                <div class="mb-3" id="edit_other_cat_div" style="display:none;">
                    <label class="form-label fw-bold text-primary">Specify Document Type</label>
                    <input type="text" name="other_category" id="edit_other_category" class="form-control" placeholder="e.g. Gym Membership"
                        maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores"
                        oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ ]/g, '')">
                </div>

                <hr>
                <h6 class="text-secondary fw-bold">Move Document (Optional)</h6>
                <div class="mb-3 position-relative">
                    <label class="form-label">Move to another employee</label>
                    <input type="hidden" name="move_to_emp_id" id="edit_move_to_emp_id">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="edit_employeeSearch" class="form-control" placeholder="Search by Name or ID..." autocomplete="off"
                            maxlength="50" pattern="[a-zA-Z0-9\-_ \(\)]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Parentheses"
                            oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ \(\)]/g, '')">
                    </div>
                    <div id="edit_suggestionBox" class="list-group position-absolute w-100 shadow" style="z-index: 1056; display: none;"></div>
                    <div class="form-text">Leave blank to keep the document with the current employee.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD EVALUATION MODAL -->
<div class="modal fade" id="addEvalModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add Evaluation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_eval">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold">Evaluation Date</label>
                    <input type="date" name="eval_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Evaluator Name</label>
                    <input type="text" name="evaluator" class="form-control" value="<?php echo h($_SESSION['username'] ?? ''); ?>" required maxlength="100"
                        pattern="[a-zA-Z\s\-\.\,]+" title="Allowed: Letters, spaces, dots, dashes, commas"
                        oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\,]/g, '')">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Score (1-100)</label>
                    <input type="number" name="score" class="form-control" min="1" max="100" placeholder="e.g. 85" required
                        oninput="this.value = this.value.slice(0, 3); if(this.value > 100) this.value = 100;"
                        onkeypress="return event.charCode >= 48 && event.charCode <= 57">
                    <div class="mt-2 p-2 bg-light border rounded small">
                        <h6 class="fw-bold mb-1 text-primary">🎯 Promotion & Qualification Guide</h6>
                        <ul class="list-unstyled mb-0 ps-1">
                            <li><span class="badge bg-success">90 - 100</span> <strong>Excellent</strong> (Ready for Promotion)</li>
                            <li><span class="badge bg-primary">80 - 89</span> <strong>Very Good</strong> (Qualified for Regularization)</li>
                            <li><span class="badge bg-info text-dark">70 - 79</span> <strong>Satisfactory</strong> (Retain)</li>
                            <li><span class="badge bg-warning text-dark">60 - 69</span> <strong>Needs Improvement</strong> (PIP Required)</li>
                            <li><span class="badge bg-danger">0 - 59</span> <strong>Poor</strong> (Risk of Termination)</li>
                        </ul>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Remarks / Comments</label>
                    <textarea name="remarks" class="form-control" rows="3" maxlength="1000" placeholder="Strengths, weaknesses, areas for improvement..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD HISTORY MODAL -->
<div class="modal fade" id="addHistoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Add History Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_history">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="mb-3">
                    <label class="form-label fw-bold">Event Title</label>
                    <input type="text" name="event_title" class="form-control" placeholder="e.g. Promoted to Senior Staff" required maxlength="100">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label">Date</label><input type="date" name="event_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="col-6"><label class="form-label">Department (Optional)</label><input type="text" name="department" class="form-control" placeholder="e.g. IT Dept" value="<?php echo htmlspecialchars($emp['dept']); ?>"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes / Details</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional details..."></textarea>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add Event</button></div>
        </form>
    </div>
</div>

<!-- EDIT EVALUATION MODAL -->
<div class="modal fade" id="editEvalModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Edit Evaluation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_eval">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="eval_id" id="edit_eval_id">
                <div class="mb-3">
                    <label class="form-label fw-bold">Evaluation Date</label>
                    <input type="date" name="eval_date" id="edit_eval_date" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Evaluator Name</label>
                    <input type="text" name="evaluator" id="edit_evaluator" class="form-control" required maxlength="100" pattern="[a-zA-Z\s\-\.\,]+" title="Allowed: Letters, spaces, dots, dashes, commas" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\,]/g, '')">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Score (1-100)</label>
                    <input type="number" name="score" id="edit_score" class="form-control" min="1" max="100" required oninput="this.value = this.value.slice(0, 3); if(this.value > 100) this.value = 100;">
                </div>
                <div class="mb-3">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" id="edit_remarks" class="form-control" rows="3" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- DOWNLOAD ALL MODAL -->
<div class="modal fade" id="downloadAllModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="export_files.php" method="POST" class="modal-content" onsubmit="showDownloadLoader(this)">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-zip-fill"></i> Download 201 File</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will download a ZIP file containing:</p>
                <ul>
                    <li><strong>Employee Profile (HTML)</strong></li>
                    <li><strong><?php echo count($myDocs); ?> Uploaded Documents</strong></li>
                </ul>
                <input type="hidden" name="search" value="<?php echo h($emp['emp_id']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                <div class="mb-3">
                    <label class="form-label fw-bold text-danger">Set ZIP Password (Optional)</label>
                    <input type="password" name="zip_password" class="form-control" placeholder="Enter password to secure files" maxlength="50">
                    <div class="form-text">If set, you will need this password to extract the files.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Download ZIP</button>
            </div>
        </form>
    </div>
</div>

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
                        <!-- [SMART FILTER STEP 1] The "Filter" Dropdown -->
                        <!-- This dropdown triggers the filterDocuments() function when changed. -->
                        <!-- It acts as the "Parent" that controls the options available in the next dropdown. -->
                        <label class="form-label fw-bold text-success">1. Select Employee Category</label>
                        <select class="form-select" id="jobCategory" onchange="filterDocuments()">
                            <option value="" selected disabled>-- Choose Role --</option>
                            <option value="lms_tech">Technical / Operations</option>
                            <option value="office">Office Staff / Admin</option>
                            <option value="general">General (All Employees)</option>
                        </select>
                        <div class="form-text">This filters which documents are available below.</div>
                    </div>

                    <div class="mb-3">
                        <!-- [SMART FILTER STEP 2] The "Result" Dropdown -->
                        <!-- This is initially disabled. It gets populated by JavaScript based on Step 1. -->
                        <label class="form-label fw-bold">2. Select Document Template</label>
                        <select class="form-select" name="type" id="docType" onchange="toggleDateFields()" disabled>
                            <option value="" selected>-- Select Category First --</option>
                        </select>
                        <div class="form-text text-muted" id="docHelp"></div>
                    </div>

                    <!-- [NEW] COE Specific Fields -->
                    <div id="coeFields" style="display:none;" class="mb-3 p-3 bg-light border rounded">
                        <h6 class="text-primary fw-bold"><i class="bi bi-calendar-event"></i> Employment Period Options</h6>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="manual_end_date_override" id="manualEndDateOverride">
                            <label class="form-check-label small fw-bold" for="manualEndDateOverride">Override End Date (Default: Present)</label>
                        </div>
                        <input type="date" name="manual_end_date_value" id="manualEndDateValue" class="form-control form-control-sm" style="display:none;">
                        <div class="form-text extra-small">If "Override" is checked and date is empty, it will still show "Present".</div>
                    </div>

                    <!-- [NEW] NTE Fields -->
                    <div id="nteFields" style="display:none;" class="mb-3 p-3 bg-danger-subtle border border-danger rounded">
                        <h6 class="text-danger fw-bold"><i class="bi bi-exclamation-triangle"></i> Incident Details</h6>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Date of Notice</label>
                            <input type="date" name="notice_date" class="form-control form-control-sm">
                            <div class="form-text text-muted" style="font-size: 0.7rem;">Leave empty to hand-write date.</div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Violation(s) (Multi-Select)</label>
                            <select name="violation[]" class="form-select form-select-sm" multiple required style="height: 100px;">
                                <?php foreach ($violation_options as $group => $items): ?>
                                    <optgroup label="<?php echo h($group); ?>">
                                        <?php foreach ($items as $v): ?><option value="<?php echo h($v); ?>"><?php echo h($v); ?></option><?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Date of Incident</label>
                                <input type="datetime-local" name="incident_date" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Place of Incident</label>
                                <input type="text" name="incident_place" class="form-control form-control-sm" placeholder="e.g. Workshop Area" maxlength="100">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Nature of Allegation</label>
                            <textarea name="allegation" class="form-control form-control-sm" rows="4" maxlength="2000" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" placeholder="Describe exactly what happened..." required spellcheck="true" lang="en"></textarea>
                            <div class="form-text extra-small">Press Enter for new lines.</div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold">Rule(s) Violated</label>
                            <select name="rule_violated[]" class="form-select form-select-sm" multiple style="height: 80px;">
                                <?php foreach ($rule_options as $r): ?><option value="<?php echo h($r); ?>"><?php echo h($r); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- [NEW] NOD Fields -->
                    <div id="nodFields" style="display:none;" class="mb-3 p-3 bg-warning-subtle border border-warning rounded">
                        <h6 class="text-dark fw-bold"><i class="bi bi-gavel"></i> Decision Details</h6>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Date of Notice</label>
                            <input type="date" name="notice_date" class="form-control form-control-sm">
                            <div class="form-text text-muted" style="font-size: 0.7rem;">Leave empty to hand-write date.</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Date of Incident</label>
                            <input type="date" name="incident_date" class="form-control form-control-sm">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Violation / Offense (Multi-Select)</label>
                            <select name="violation[]" class="form-select form-select-sm" multiple required style="height: 100px;">
                                <?php foreach ($violation_options as $group => $items): ?>
                                    <optgroup label="<?php echo h($group); ?>">
                                        <?php foreach ($items as $v): ?><option value="<?php echo h($v); ?>"><?php echo h($v); ?></option><?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Rule(s) Violated</label>
                            <select name="rule_violated[]" class="form-select form-select-sm" multiple style="height: 80px;">
                                <?php foreach ($rule_options as $r): ?><option value="<?php echo h($r); ?>"><?php echo h($r); ?></option><?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold">Decision / Sanction</label>
                            <textarea name="decision" class="form-control form-control-sm" rows="4" maxlength="2000" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" placeholder="e.g. 3 Days Suspension" required spellcheck="true" lang="en"></textarea>
                            <div class="form-text extra-small">Press Enter for new lines.</div>
                        </div>
                    </div>

                    <!-- [NEW] Project Name Field -->
                    <div id="projectFields" style="display:none;" class="mb-3">
                        <label class="form-label fw-bold">Project Name</label>
                        <input type="text" name="project_name" id="projectNameInput" class="form-control" placeholder="e.g. MRT-3 Rehabilitation Project" maxlength="100">
                    </div>

                    <!-- [NEW] Custom Duties Field -->
                    <div id="customDutiesField" style="display:none;" class="mb-3">
                        <label class="form-label fw-bold text-primary">Custom Duties (Optional Override)</label>
                        <textarea name="custom_duties" class="form-control" rows="5" maxlength="3000" style="text-align: center; white-space: pre-wrap; word-wrap: break-word;" placeholder="Type here to override the default role duties..." spellcheck="true" lang="en"></textarea>
                        <div class="form-text extra-small">Press Enter for new lines.</div>
                    </div>

                    <!-- Date Selection (Hidden for NDA) -->
                    <div id="dateFields" class="p-3 bg-light border rounded mb-3" style="display:none;">
                        <h6 class="text-primary fw-bold mb-3">Contract Validity (Longevity)</h6>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Effectivity Date (Start)</label>
                            <input type="date" name="start_date" id="startDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" onchange="calcEndDate()">
                        </div>

                        <div class="mb-2">
                            <div class="row g-2">
                                <div class="col-8">
                                    <label class="form-label small fw-bold">Duration Preset</label>
                                    <select id="durationSelect" class="form-select form-select-sm" onchange="updateDuration()">
                                        <option value="6">6 Months (Probationary)</option>
                                        <option value="3">3 Months (Probationary)</option>
                                        <option value="custom">Custom / Manual Edit</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label small fw-bold">Months</label>
                                    <input type="number" name="duration" id="durationInput" class="form-control form-control-sm" value="06" min="1" max="99" oninput="validateDuration(this); calcEndDate()" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold">Validity Until (End)</label>
                            <input type="date" name="end_date" id="endDate" class="form-control">
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-success btn-lg" id="generateBtn" disabled onclick="return validateDocForm()">Generate PDF</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- CAMERA MODAL -->
<div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-camera"></i> Take Photo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="stopCamera()"></button>
            </div>
            <div class="modal-body text-center position-relative overflow-hidden p-0 bg-dark">
                <video id="cameraVideo" width="100%" autoplay playsinline style="background: #000; min-height: 350px; max-height: 450px; object-fit: contain;"></video>
                <img id="cameraPreviewImage" style="display:none; width: 100%; min-height: 350px; max-height: 450px; object-fit: contain; background: #000;">
                <canvas id="cameraCanvas" style="display:none;"></canvas>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopCamera()">Cancel</button>
                <div id="cameraControls">
                    <button type="button" class="btn btn-success fw-bold" onclick="capturePhotoPreview()"><i class="bi bi-circle-fill text-danger"></i> Capture</button>
                </div>
                <div id="previewControls" style="display:none;">
                    <button type="button" class="btn btn-warning fw-bold" onclick="retakePhoto()"><i class="bi bi-arrow-counterclockwise"></i> Retake</button>
                    <button type="button" class="btn btn-primary fw-bold" onclick="confirmPhoto()"><i class="bi bi-check-lg"></i> Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap.bundle.min.js"></script>
<script>
    // Logic for Sections and Auto-Capitalize
    const sectionMap = <?php echo json_encode($sectionFriendlyMap); ?>;
    const rawDeptMap = <?php echo json_encode($deptMap); ?>;
    const currentSection = "<?php echo h($emp['section']); ?>";
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

    function updateSections() {
        const depts = deptInput.value.split(',').map(s => s.trim()).filter(s => s !== '');
        sectionSelect.innerHTML = '<option value="">+ Add Section...</option>';

        depts.forEach(dept => {
            let options = [];
            if (sectionMap[dept]) {
                options = sectionMap[dept];
            } else if (rawDeptMap[dept]) {
                options = rawDeptMap[dept].map(s => ({
                    val: s,
                    text: s
                }));
            }

            if (options.length > 0) {
                const group = document.createElement('optgroup');
                group.label = dept;
                options.forEach(data => {
                    const optEl = document.createElement('option');
                    optEl.value = data.val;
                    optEl.textContent = data.text;
                    group.appendChild(optEl);
                });
                sectionSelect.appendChild(group);
            }
        });
    }

    function capitalize(input) {
        let words = input.value.split(' ');
        for (let i = 0; i < words.length; i++) {
            if (words[i].length > 0) words[i] = words[i].charAt(0).toUpperCase() + words[i].slice(1).toLowerCase();
        }
        input.value = words.join(' ');
    }

    // [NEW] Multi-Section Logic
    function addSection(val) {
        const picker = document.getElementById('sectionPicker');

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

    function confirmDelete(id) {
        // [NEW] Check for existing documents
        const docCount = <?php echo htmlspecialchars(count($myDocs)); ?>;
        let warningText = "This action cannot be undone.";

        if (docCount > 0) {
            warningText = `⚠️ WARNING: This employee has ${docCount} document(s). Deleting the employee will ORPHAN these files (they will remain on the server but be unlinked). Please delete the documents first!`;
        }

        Swal.fire({
            title: 'Are you sure?',
            text: warningText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.getElementById('deleteForm');
                if (form) form.submit();
            }
        });
    }

    function confirmDeleteEval(e, form) {
        e.preventDefault();
        Swal.fire({
            title: 'Delete Evaluation?',
            text: "This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    function openEditEvalModal(id, date, score, evaluator, remarks) {
        document.getElementById('edit_eval_id').value = id;
        document.getElementById('edit_eval_date').value = date;
        document.getElementById('edit_score').value = score;
        document.getElementById('edit_evaluator').value = evaluator;
        document.getElementById('edit_remarks').value = remarks;
        new bootstrap.Modal(document.getElementById('editEvalModal')).show();
    }

    // [NEW] Tab Persistence Logic
    document.addEventListener("DOMContentLoaded", () => {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) {
            const tabTrigger = document.querySelector(`#profileTabs button[data-bs-target="#${activeTab}"]`);
            if (tabTrigger) {
                const tab = new bootstrap.Tab(tabTrigger);
                tab.show();
            }
        }
    });

    function confirmLink(e, msg) {
        e.preventDefault();
        const url = e.currentTarget.href;
        Swal.fire({
            title: 'Are you sure?',
            text: msg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) window.location.href = url;
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

    // [FIX] Centralized document list to remove redundancy.
    const standardDocs = [{
            val: 'probationary',
            text: '📄 Probationary Employment Contract'
        },
        {
            val: 'confidentiality',
            text: '🔒 Confidentiality Agreement (NDA)'
        },
        {
            val: 'coe',
            text: '📜 Certificate of Employment (COE)'
        },
        {
            val: 'project',
            text: '📄 Project Employment Contract'
        },
        {
            val: 'data_consent',
            text: '🛡️ Data Privacy Consent Form'
        },
        {
            val: 'notice_to_explain',
            text: '⚠️ Notice to Explain (NTE)'
        },
        {
            val: 'notice_of_decision',
            text: '⚖️ Notice of Decision (NOD)'
        },
        {
            val: 'employee_pledge',
            text: '⛑️ Employee Safety Pledge (LSR)'
        },
        {
            val: 'whistleblowing',
            text: '📢 Whistle Blowing Consent Form'
        }
    ];

    const docLibrary = {
        'lms_tech': standardDocs,
        'office': standardDocs,
        'general': standardDocs
    };

    // [SMART FILTER LOGIC]
    // This function runs whenever the Category dropdown changes.
    function filterDocuments() {
        const category = document.getElementById('jobCategory').value;
        const docSelect = document.getElementById('docType');
        const btn = document.getElementById('generateBtn');

        // 1. Reset the second dropdown (Clear old options)
        docSelect.innerHTML = '<option value="" selected disabled>-- Select Document --</option>';

        if (category && docLibrary[category]) {
            // 3. Enable the dropdown
            docSelect.disabled = false;

            // 4. Loop through the allowed documents and create <option> tags
            docLibrary[category].forEach(doc => {
                const option = document.createElement('option');
                option.value = doc.val;
                option.text = doc.text;
                docSelect.appendChild(option);
            });
        } else {
            // Disable if no category
            docSelect.disabled = true;
        }

        // 5. Reset the date fields visibility since the document selection changed
        toggleDateFields();
    }

    function setInputsDisabled(id, disabled) {
        const div = document.getElementById(id);
        if (!div) return;
        const inputs = div.querySelectorAll('input, select, textarea');
        inputs.forEach(el => el.disabled = disabled);
    }

    // DOCUMENT MODAL LOGIC
    function toggleDateFields() {
        const type = document.getElementById('docType').value;
        const dateDiv = document.getElementById('dateFields');
        const dutiesDiv = document.getElementById('customDutiesField');
        const nteDiv = document.getElementById('nteFields');
        const nodDiv = document.getElementById('nodFields');
        const coeDiv = document.getElementById('coeFields');
        const manualEndDateValue = document.getElementById('manualEndDateValue');
        const projectDiv = document.getElementById('projectFields');
        const help = document.getElementById('docHelp');
        const btn = document.getElementById('generateBtn');

        if (type && type !== "") {
            btn.disabled = false;
        } else {
            btn.disabled = true;
        }

        // Reset all
        dateDiv.style.display = 'none';
        if (nteDiv) nteDiv.style.display = 'none';
        if (nodDiv) nodDiv.style.display = 'none';
        if (dutiesDiv) dutiesDiv.style.display = 'none';
        if (coeDiv) coeDiv.style.display = 'none';
        if (manualEndDateValue) manualEndDateValue.style.display = 'none';
        if (projectDiv) projectDiv.style.display = 'none';

        // Disable hidden inputs to avoid conflicts
        setInputsDisabled('nteFields', true);
        setInputsDisabled('nodFields', true);
        setInputsDisabled('coeFields', true);
        help.innerText = "";

        if (type === 'notice_to_explain') {
            if (nteDiv) nteDiv.style.display = 'block';
            setInputsDisabled('nteFields', false);
            help.innerText = "Generates a formal disciplinary notice requiring written explanation.";
        }
        // Show Dates ONLY for Contracts
        else if (type.includes('probationary') || type.includes('contract') || type.includes('project') || type === 'consultant' || type === 'regular') {
            dateDiv.style.display = 'block';
            if (dutiesDiv && (type.includes('probationary') || type === 'regular' || type === 'consultant')) dutiesDiv.style.display = 'block'; // Show custom duties
            if (type === 'probationary') {
                document.getElementById('durationSelect').value = '6';
                document.getElementById('durationInput').value = '6';
                document.getElementById('durationInput').readOnly = true;
                help.innerText = "Standard 6-month probationary contract.";
            }
            calcEndDate();
        } else if (type === 'notice_of_decision') {
            if (nodDiv) nodDiv.style.display = 'block';
            setInputsDisabled('nodFields', false);
            help.innerText = "Generates a formal Notice of Decision / Sanction.";
        } else if (type === 'coe') {
            if (coeDiv) coeDiv.style.display = 'block';
            setInputsDisabled('coeFields', false);
            help.innerText = "Generate a Certificate of Employment. You can override the end date.";
            document.getElementById('manualEndDateOverride').addEventListener('change', function() {
                manualEndDateValue.style.display = this.checked ? 'block' : 'none';
                manualEndDateValue.required = this.checked;
            });
            manualEndDateValue.required = document.getElementById('manualEndDateOverride').checked;
        } else {
            // Reset to default state if hidden
            document.getElementById('durationInput').readOnly = true;
        }

        // Show Project Name field only for project contract
        if (type === 'project') {
            projectDiv.style.display = 'block';
        } else {
            projectDiv.style.display = 'none';
        }
    }

    function validateDocForm() {
        const type = document.getElementById('docType').value;
        const projInput = document.getElementById('projectNameInput');

        if (type === 'project' && projInput.value.trim() === '') {
            alert('Please enter a Project Name.');
            projInput.focus();
            return false; // Prevent submission
        }
        return true;
    }

    function updateDuration() {
        const select = document.getElementById('durationSelect');
        const input = document.getElementById('durationInput');
        if (select.value !== 'custom') {
            input.value = select.value;
            input.readOnly = true;
            calcEndDate();
        } else {
            input.readOnly = false;
        }
    }

    function validateDuration(input) {
        // Allow any 2 digit number
        if (input.value > 99) input.value = 99;
        if (input.value !== '' && input.value < 1) input.value = 1;
        // Pad with zero if single digit for display consistency (optional)
    }

    function calcEndDate() {
        const startVal = document.getElementById('startDate').value;
        const duration = document.getElementById('durationInput').value;
        const endInput = document.getElementById('endDate');

        if (!startVal || !duration) return;

        const date = new Date(startVal);
        // Add months
        date.setMonth(date.getMonth() + parseInt(duration));
        // Format YYYY-MM-DD
        const yyyy = date.getFullYear();
        const mm = String(date.getMonth() + 1).padStart(2, '0');
        const dd = String(date.getDate()).padStart(2, '0');
        endInput.value = `${yyyy}-${mm}-${dd}`;
    }

    document.addEventListener("DOMContentLoaded", () => {
        toggleExitFields();
        updateSections(); // [FIX] Initialize sections on load

        // [NEW] Handle URL Messages (Success/Error)
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.has('msg')) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: urlParams.get('msg'),
                timer: 2500,
                showConfirmButton: true
            });
            localStorage.removeItem('hr_add_emp_draft');
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $id; ?>");
            }
        }

        if (urlParams.has('error')) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: urlParams.get('error')
            });
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname + "?id=<?php echo $id; ?>");
            }
        }

        // AUTO-DETECT EMPLOYEE ROLE ON LOAD
        // 1. Get Employee Data from PHP
        const section = "<?php echo strtolower($emp['section']); ?>";
        const job = "<?php echo strtolower($emp['job_title']); ?>";
        const categorySelect = document.getElementById('jobCategory');

        // 2. Logic to pick the Category
        let autoCategory = 'general'; // Default fallback

        // 1. LMS / TECHNICAL GROUP
        // Includes: LMS, HMS, RAS, TRS, CTS, PSS, OCS, SIGCOM, BFS, WHS
        if (
            section.includes('light maintenance') || section.includes('lms') ||
            section.includes('heavy maintenance') || section.includes('hms') ||
            section.includes('root cause') || section.includes('ras') ||
            section.includes('technical research') || section.includes('trs') ||
            section.includes('civil tracks') || section.includes('cts') ||
            section.includes('power supply') || section.includes('pss') ||
            section.includes('overhead catenary') || section.includes('ocs') ||
            section.includes('signaling') || section.includes('sigcom') ||
            section.includes('building facilities') || section.includes('bfs') ||
            section.includes('warehouse') || section.includes('whs') ||
            job.includes('technician')
        ) {
            autoCategory = 'lms_tech';
        }
        // 2. OFFICE / ADMIN GROUP
        // Includes: SQP, ADMIN, DOS, Finance, HR
        else if (section.includes('sqp') || section.includes('admin') || section.includes('finance') || section.includes('dos') || section.includes('department operations')) {
            autoCategory = 'office';
        }

        // 3. Set the Dropdown & Trigger Filter
        if (categorySelect) {
            categorySelect.value = autoCategory;
            // [STRICT MODE] Lock the category so they can't switch to wrong contracts
            // categorySelect.disabled = true;
            filterDocuments(); // This updates the document list immediately
        }

        // 4. Also calculate dates
        calcEndDate();
    });

    // [NEW] Auto-Resize Textareas (On Input, On Load, On Modal Show)
    document.addEventListener('input', function(e) {
        if (e.target.tagName.toLowerCase() === 'textarea') {
            autoResize(e.target);
        }
    });

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll('textarea').forEach(autoResize);

        // Also resize when modals open (since hidden elements have 0 height)
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('shown.bs.modal', () => {
                modal.querySelectorAll('textarea').forEach(autoResize);
            });
        });
    });

    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    function toggleEditOther() {
        const val = document.getElementById('edit_category').value;
        const div = document.getElementById('edit_other_cat_div');
        const input = document.getElementById('edit_other_category');
        if (val === 'Others') {
            div.style.display = 'block';
            input.required = true;
        } else {
            div.style.display = 'none';
            input.required = false;
        }
    }

    function openEditDocModal(id, name, category, expiryDate) {
        document.getElementById('edit_doc_id').value = id;
        document.getElementById('edit_file_name').value = name;
        document.getElementById('edit_expiry_date').value = expiryDate || '';

        const select = document.getElementById('edit_category');
        const otherInput = document.getElementById('edit_other_category');

        // [FIX] Reset Move Fields
        document.getElementById('edit_employeeSearch').value = '';
        document.getElementById('edit_move_to_emp_id').value = '';
        const form = document.querySelector('#editDocModal form');
        if (form) form.classList.remove('was-validated');

        // Check if category is in the standard list
        let isStandard = false;
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === category && category !== 'Others') {
                isStandard = true;
                break;
            }
        }

        if (isStandard) {
            select.value = category;
            otherInput.value = '';
        } else {
            select.value = 'Others';
            otherInput.value = category === 'Others' ? '' : category;
        }
        toggleEditOther();

        new bootstrap.Modal(document.getElementById('editDocModal')).show();
    }

    // [NEW] Modal Form Validation Styling
    const editDocForm = document.querySelector('#editDocModal form');
    if (editDocForm) {
        editDocForm.addEventListener('submit', function(event) {
            if (!this.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                this.classList.add('was-validated');
                return;
            }

            // [FIX] Validate Move Employee Selection (Prevent silent failure)
            const moveSearch = document.getElementById('edit_employeeSearch');
            const moveIdInput = document.getElementById('edit_move_to_emp_id');

            if (moveSearch.value.trim() !== "" && moveIdInput.value === "") {
                event.preventDefault();
                event.stopPropagation();
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Selection',
                    text: 'You typed a name in the "Move to" box but didn\'t select an employee from the list (or you edited the name after selecting). Please click a name from the suggestions and do not edit it.'
                });
                return;
            }

            // [NEW] Move Confirmation
            const moveIdVal = moveIdInput.value;
            const moveName = moveSearch.value;

            if (moveIdVal && moveIdVal.trim() !== "") {
                event.preventDefault();
                Swal.fire({
                    title: 'Transfer Document?',
                    html: `You are moving this file to:<br><strong class="text-primary">${moveName}</strong><br><br>This will change the document owner. Continue?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Transfer'
                }).then((result) => {
                    if (result.isConfirmed) this.submit();
                });
            }

            this.classList.add('was-validated');
        });
    }

    function showDownloadLoader(form) {
        Swal.fire({
            title: 'Compiling Data...',
            html: `
                    <p class="text-muted small mb-3">Scanning files and building the ZIP archive. Please wait...</p>
                    <div class="progress mb-3" style="height: 25px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 100%"></div>
                    </div>
                    <span class="text-danger fw-bold small">This may take a few minutes. Do not close this window!</span>
                `,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false
        });

        const csrf = form.querySelector('[name="csrf_token"]').value;
        const checkCookie = setInterval(() => {
            if (document.cookie.includes('downloadToken=' + csrf)) {
                clearInterval(checkCookie);
                Swal.close();
                document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";

                const modalEl = document.getElementById('downloadAllModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        }, 1000);
    }

    // [NEW] Employee Search Logic for "Move Document"
    document.addEventListener("DOMContentLoaded", () => {
        const searchInput = document.getElementById('edit_employeeSearch');
        const suggestionBox = document.getElementById('edit_suggestionBox');
        const hiddenIdInput = document.getElementById('edit_move_to_emp_id');

        if (searchInput && suggestionBox && hiddenIdInput) {
            let debounceTimer = null;

            searchInput.addEventListener('input', function() {
                const q = this.value.trim();
                hiddenIdInput.value = ''; // Clear ID if user types something new

                if (q.length < 2) {
                    suggestionBox.innerHTML = '';
                    suggestionBox.style.display = 'none';
                    return;
                }

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
                        .then(r => r.json())
                        .then(data => {
                            suggestionBox.innerHTML = '';
                            if (Array.isArray(data) && data.length > 0) {
                                suggestionBox.style.display = 'block';
                                data.slice(0, 5).forEach(emp => {
                                    const item = document.createElement('a');
                                    item.className = 'list-group-item list-group-item-action';
                                    item.style.cursor = 'pointer';
                                    const strong = document.createElement('strong');
                                    strong.textContent = `${emp.first_name} ${emp.last_name}`;
                                    const small = document.createElement('small');
                                    small.className = 'text-muted';
                                    small.textContent = ` ${emp.emp_id}`;
                                    item.appendChild(strong);
                                    item.appendChild(small);
                                    // [FIX] Use mousedown for better responsiveness and change format to remove parentheses
                                    item.onmousedown = () => {
                                        searchInput.value = `${emp.first_name} ${emp.last_name} - ${emp.emp_id}`;
                                        hiddenIdInput.value = emp.emp_id;
                                        suggestionBox.style.display = 'none';
                                    };
                                    suggestionBox.appendChild(item);
                                });
                            } else {
                                suggestionBox.style.display = 'none';
                            }
                        })
                        .catch(e => console.error("Search error:", e));
                }, 250);
            });

            // Hide suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
                    suggestionBox.style.display = 'none';
                }
            });
        }

        // [NEW] Edit Confirmation Alert
        const editForm = document.getElementById('editEmployeeForm');
        let clickedButtonValue = null;

        if (editForm) {
            // Track which button was clicked
            editForm.querySelectorAll('button[type="submit"]').forEach(btn => {
                btn.addEventListener('click', function() {
                    clickedButtonValue = this.value;
                });
            });

            editForm.addEventListener('submit', function(e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Save Changes?',
                    text: "Are you sure you want to update this profile?",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Save'
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (clickedButtonValue) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'save_action';
                            input.value = clickedButtonValue;
                            this.appendChild(input);
                        }
                        this.submit();
                    }
                });
            });
        }
    });

    // --- AVATAR PREVIEW & CAMERA LOGIC ---
    function previewAvatar(input) {
        // Un-flag removal if they select a new picture
        document.getElementById('removeAvatarFlag').value = '0';
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.querySelector('.avatar-preview');
                if (preview) preview.src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function clearAvatar() {
        document.getElementById('avatarInput').value = '';
        document.getElementById('removeAvatarFlag').value = '1';
        const preview = document.querySelector('.avatar-preview');
        if (preview) {
            preview.src = 'uploads/avatars/default.png';
        }
    }

    let videoStream = null;
    let capturedBlob = null;

    async function startCamera() {
        const video = document.getElementById('cameraVideo');
        stopCamera(); // Ensure previous stream is killed
        retakePhoto(); // Reset UI

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error("Camera API not available.");
            Swal.fire({
                icon: 'error',
                title: 'HTTPS Required',
                html: 'Modern browsers strictly block camera access on unsecure (HTTP) networks.<br><br>Please access this system via <b>HTTPS</b> or <b>localhost</b> to use the camera.',
                confirmButtonColor: '#dc3545'
            });
            const modalEl = document.getElementById('cameraModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            return;
        }

        try {
            // Request camera (prioritizes front-facing/webcam)
            videoStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: {
                        ideal: "user"
                    }
                }
            });
            video.srcObject = videoStream;
            video.play().catch(e => console.error("Play error:", e));
        } catch (err) {
            console.error("Camera error:", err);
            Swal.fire('Error', 'Unable to access camera. Please check permissions or ensure you are using HTTPS.', 'error');
            const modalEl = document.getElementById('cameraModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        }
    }

    function stopCamera() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop()); // Turn off webcam light
            videoStream = null;
        }
    }

    function capturePhotoPreview() {
        const video = document.getElementById('cameraVideo');
        const canvas = document.getElementById('cameraCanvas');
        if (!videoStream) return;

        // --- VISUAL SHUTTER FLASH ---
        const modalBody = video.closest('.modal-body');
        if (modalBody) {
            const flash = document.createElement('div');
            flash.style.position = 'absolute';
            flash.style.inset = '0';
            flash.style.backgroundColor = '#ffffff';
            flash.style.zIndex = '9999';
            flash.style.transition = 'opacity 0.25s ease-out';
            modalBody.appendChild(flash);
            setTimeout(() => {
                flash.style.opacity = '0';
            }, 10);
            setTimeout(() => {
                flash.remove();
            }, 300);
        }

        // [FIX] Remove crop and downscale to max 800px width/height while keeping aspect ratio
        const MAX_DIM = 800;
        let outWidth = video.videoWidth;
        let outHeight = video.videoHeight;

        // [FIX] Fallback if video metadata isn't loaded yet to prevent 0x0 blank images
        if (outWidth === 0 || outHeight === 0) {
            outWidth = video.clientWidth || 640;
            outHeight = video.clientHeight || 480;
        }

        if (outWidth > MAX_DIM || outHeight > MAX_DIM) {
            if (outWidth > outHeight) {
                outHeight = Math.floor(outHeight * (MAX_DIM / outWidth));
                outWidth = MAX_DIM;
            } else {
                outWidth = Math.floor(outWidth * (MAX_DIM / outHeight));
                outHeight = MAX_DIM;
            }
        }

        canvas.width = outWidth;
        canvas.height = outHeight;
        const ctx = canvas.getContext('2d');

        ctx.drawImage(video, 0, 0, outWidth, outHeight);

        // [FIX] Use Data URL for 100% reliable instant preview on all mobile browsers
        const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
        const previewImg = document.getElementById('cameraPreviewImage');
        if (previewImg) {
            previewImg.src = dataUrl;
            previewImg.style.display = 'block';
        }
        if (video) video.style.display = 'none';
        const overlay = document.getElementById('cameraOverlay');
        if (overlay) overlay.style.display = 'none';
        document.getElementById('cameraControls').style.display = 'none';
        document.getElementById('previewControls').style.display = 'block';

        // Disable confirm button until blob is ready
        const confirmBtn = document.querySelector('#previewControls button.btn-primary');
        if (confirmBtn) confirmBtn.disabled = true;

        canvas.toBlob(blob => {
            if (!blob) {
                Swal.fire('Error', 'Failed to capture image. Please try again.', 'error');
                retakePhoto();
                return;
            }
            capturedBlob = blob;
            if (confirmBtn) confirmBtn.disabled = false;
        }, 'image/jpeg', 0.85);
    }

    function retakePhoto() {
        capturedBlob = null;
        const previewImg = document.getElementById('cameraPreviewImage');
        const video = document.getElementById('cameraVideo');
        const overlay = document.getElementById('cameraOverlay');
        const cameraControls = document.getElementById('cameraControls');
        const previewControls = document.getElementById('previewControls');

        if (previewImg) previewImg.style.display = 'none';
        if (video) {
            video.style.display = 'block';
            if (video.paused && typeof videoStream !== 'undefined' && videoStream) {
                video.play().catch(e => console.error("Play error:", e));
            }
        }
        if (overlay) overlay.style.display = 'flex';
        if (cameraControls) cameraControls.style.display = 'block';
        if (previewControls) previewControls.style.display = 'none';
    }

    function confirmPhoto() {
        if (!capturedBlob) return;
        const file = new File([capturedBlob], "profile_capture_" + Date.now() + ".jpg", {
            type: "image/jpeg"
        });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        const input = document.getElementById('avatarInput');
        input.files = dataTransfer.files;
        previewAvatar(input);

        const modal = bootstrap.Modal.getInstance(document.getElementById('cameraModal'));
        if (modal) modal.hide();
        stopCamera();

        Swal.fire({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            icon: 'success',
            title: 'Photo attached! Click "Save Changes" to upload.'
        });
    }
</script>
</body>

</html>