<?php
require '../config/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
        die("ACCESS DENIED");
    }

    $id = $_POST['id'];
    $new_emp_id = trim($_POST['emp_id']);
    $doc_action = $_POST['doc_action']; 

    // 1. DUPLICATE CHECK
    $check = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ? AND id != ?");
    $check->execute([$new_emp_id, $id]);
    if ($check->rowCount() > 0) {
        header("Location: edit_employee.php?id=$id&error=Error: ID '$new_emp_id' is already taken.");
        exit;
    }

    // Input Gathering
    $empType = $_POST['employment_type'];
    $agencyName = ($empType === 'TESP Direct') ? 'TESP' : $_POST['agency_name'];
    $section = $_POST['section'];
    $avatar_path = $_POST['current_avatar']; 
    
    // Avatar Logic
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === 0) {
        $targetDir = "../public/uploads/avatars/";
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $newName = $new_emp_id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetDir . $newName)) {
            $avatar_path = $newName;
        }
    }

    try {
        $pdo->beginTransaction();

        // 2. FILE DELETION LOGIC (CRITICAL FIX)
        if ($doc_action === 'delete') {
            // A. Get the CURRENT (OLD) emp_id from the database
            $stmt = $pdo->prepare("SELECT emp_id FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            $current_emp_id = $stmt->fetchColumn();

            // B. Fetch all file paths associated with this ID
            $get = $pdo->prepare("SELECT file_path FROM documents WHERE employee_id = ?");
            $get->execute([$current_emp_id]);
            $files = $get->fetchAll();
            
            // C. Delete the physical files
            foreach($files as $f) {
                // IMPORTANT: Ensure VAULT_PATH ends with a slash in your config, or add it here
                // We assume stored path is relative like "folder/file.pdf"
                $fullPath = $_ENV['VAULT_PATH'] . $f['file_path'];
                
                // Debugging check (optional log, but good for safety)
                if (file_exists($fullPath)) {
                    unlink($fullPath); 
                }
            }

            // D. Delete the database records
            $del = $pdo->prepare("DELETE FROM documents WHERE employee_id = ?");
            $del->execute([$current_emp_id]);
        }

        // 3. UPDATE ALL FIELDS (Added missing columns here)
        $sql = "UPDATE employees SET 
                emp_id=?, job_title=?, dept=?, section=?, employment_type=?, agency_name=?, 
                company_name=?, previous_company=?, hire_date=?,
                first_name=?, middle_name=?, last_name=?, birthdate=?, contact_number=?, email=?, 
                present_address=?, permanent_address=?, 
                sss_no=?, tin_no=?, pagibig_no=?, philhealth_no=?,
                emergency_name=?, emergency_contact=?, emergency_address=?, 
                status=?, avatar_path=?
                WHERE id=?";
        
        $params = [
            $new_emp_id, trim($_POST['job_title']), $_POST['dept'], $section, $empType, $agencyName, 
            trim($_POST['company_name']), trim($_POST['previous_company']), $_POST['hire_date'],
            trim($_POST['first_name']), trim($_POST['middle_name']), trim($_POST['last_name']), $_POST['birthdate'], trim($_POST['contact_number']), trim($_POST['email']), 
            trim($_POST['present_address']), trim($_POST['permanent_address']), 
            trim($_POST['sss_no']), trim($_POST['tin_no']), trim($_POST['pagibig_no']), trim($_POST['philhealth_no']),
            trim($_POST['emergency_name']), trim($_POST['emergency_contact']), trim($_POST['emergency_address']),
            $_POST['status'], $avatar_path,
            $id
        ];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();
        header("Location: index.php?msg=Update Successful");

    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Database Error: " . $e->getMessage());
    }
}
?>