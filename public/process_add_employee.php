<?php
// DEBUG: Load dependencies
require '../config/db.php';
require '../src/Security.php';
session_start();

// DEBUG: Ensure only POST requests are processed
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // DEBUG: Security Check
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
        die("ACCESS DENIED");
    }

    // 1. DATA PREPARATION
    // Handle the "Agency" logic
    $empType = $_POST['employment_type'];
    $agencyName = ($empType === 'TESP Direct') ? 'TESP' : $_POST['agency_name'];

    // Handle "Section" logic (If 'Main Unit' is selected, it stays 'Main Unit')
    $section = $_POST['section'];

    // 2. GATHER ALL INPUTS
    // We map these to the exact order of columns in the INSERT statement below
    $inputs = [
        // IDs & Work Info
        trim($_POST['emp_id']),
        trim($_POST['job_title']),
        $_POST['dept'],          // Group (e.g., SQP)
        $section,                // Section (e.g., IT)
        $empType,                // Direct/Agency
        $agencyName,             // TESP/Unisolutions
        trim($_POST['company_name']),
        trim($_POST['previous_company']),
        $_POST['hire_date'],

        // Personal Info
        trim($_POST['first_name']),
        trim($_POST['middle_name'] ?? ''),
        trim($_POST['last_name']),
        $_POST['birthdate'],
        trim($_POST['contact_number']),
        trim($_POST['email']),
        trim($_POST['present_address']),
        trim($_POST['permanent_address']),

        // Govt IDs
        trim($_POST['sss_no']),
        trim($_POST['tin_no']),
        trim($_POST['pagibig_no']),
        trim($_POST['philhealth_no']),

        // Emergency Contact
        trim($_POST['emergency_name']),
        trim($_POST['emergency_contact']),
        trim($_POST['emergency_address']),
        
        // Status (Default)
        'Active'
    ];

    // 3. DUPLICATE CHECK
    // Check if Employee ID (e.g., JAS-032) already exists
    $check = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
    $check->execute([$inputs[0]]); 
    if ($check->rowCount() > 0) {
        die("<h3>Error: Employee ID " . htmlspecialchars($inputs[0]) . " already exists.</h3><a href='add_employee.php'>Go Back</a>");
    }

    // 4. AVATAR UPLOAD HANDLING
    $avatar_path = 'default.png'; // Fallback image
    
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === 0) {
        $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
        $fileType = mime_content_type($_FILES['avatar']['tmp_name']);

        if (in_array($fileType, $allowed)) {
            $targetDir = "../public/uploads/avatars/";
            
            // Create folder if not exists
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

            // Generate Filename: ID_TIMESTAMP.ext (e.g., JAS-032_170000.jpg)
            $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $newName = $inputs[0] . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetDir . $newName)) {
                $avatar_path = $newName;
            }
        }
    }
    
    // Add avatar to the inputs array as the last item
    $inputs[] = $avatar_path; 

    // 5. DATABASE INSERTION
    try {
        // The placeholders (?) must match the count of $inputs exactly (26 items)
        $sql = "INSERT INTO employees (
            emp_id, job_title, dept, section, employment_type, agency_name, 
            company_name, previous_company, hire_date,
            first_name, middle_name, last_name, birthdate, contact_number, email, 
            present_address, permanent_address,
            sss_no, tin_no, pagibig_no, philhealth_no,
            emergency_name, emergency_contact, emergency_address,
            status, avatar_path
        ) VALUES (
            ?, ?, ?, ?, ?, ?, 
            ?, ?, ?,
            ?, ?, ?, ?, ?, ?, 
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($inputs);

        // Success! Redirect to Dashboard
        header("Location: index.php?msg=Employee Record Created Successfully");
        exit;

    } catch (PDOException $e) {
        // DEBUG: Database Error Output
        die("<h3>Database Error:</h3> " . $e->getMessage() . "<br><br>Please contact IT Support.");
    }
}
?>