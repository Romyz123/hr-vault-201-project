<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? __DIR__ . '/../vault/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!isset($_SESSION['user_id'])) die("ACCESS DENIED");

    // 1. GATHER INPUTS
    $emp_id = $_POST['emp_id'];
    $description = trim($_POST['description']);
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
    $customName = trim($_POST['custom_filename']); // Get custom name

    // --- "OTHERS" CATEGORY LOGIC (NEW) ---
    $category = $_POST['category'];
    if (empty($category)) {
        die("Error: You must select a document category.");
    }

    if ($category === 'Others') {
        // Use the specific text they typed instead
        $other_cat = trim($_POST['other_category']);
        
        if (!empty($other_cat)) {
            // Capitalize nicely (e.g., "gym membership" -> "Gym Membership")
            $category = ucwords(strtolower($other_cat));
        } else {
            die("Error: You selected 'Others' but did not specify the document type.");
        }
    }
    // -------------------------------------

    // 2. HANDLE FILE UPLOAD
    if (isset($_FILES['document']) && $_FILES['document']['error'] === 0) {
        
        $file = $_FILES['document'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed)) {
            header("Location: upload_form.php?error=Invalid file type&emp_id=$emp_id");
            exit;
        }

        // --- RENAME LOGIC ---
        if (!empty($customName)) {
            // Clean the custom name (remove special chars)
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $customName);
            $finalName = $safeName . "." . $ext;
        } else {
            // Default Auto-Generate: ID_Category_Timestamp
            $cleanCategory = preg_replace('/[^a-zA-Z0-9]/', '', $category);
            $finalName = $emp_id . "_" . $cleanCategory . "_" . time() . "." . $ext;
        }

        // --- DUPLICATE CHECK ---
        $targetDir = $vaultPath;
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        $targetPath = $targetDir . $finalName;

        // If file exists, AUTO-RENAME (Add random number) to prevent overwrite failure
        if (file_exists($targetPath)) {
            $finalName = str_replace(".$ext", "_" . rand(100, 999) . ".$ext", $finalName);
            $targetPath = $targetDir . $finalName;
        }

        // --- MOVE FILE ---
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            
            // 3. PREPARE DATA
            $docData = [
                'employee_id' => $emp_id,
                'original_name' => $finalName, // Save the NEW name
                'file_path' => $finalName,
                'category' => $category,       // Saves "Gym Membership" instead of "Others"
                'expiry_date' => $expiry_date,
                'description' => $description,
                'uploaded_by' => $_SESSION['user_id']
            ];

            // 4. CHECK ROLE & ROUTE
            if ($_SESSION['role'] === 'STAFF') {
                $payload = json_encode($docData);
                $stmt = $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'UPLOAD_DOC', 0, ?)");
                $stmt->execute([$_SESSION['user_id'], $payload]);

                $logger = new Logger($pdo);
                $logger->log($_SESSION['user_id'], 'REQUEST_DOC', "Submitted document: " . $finalName);
                
                header("Location: index.php?msg=Document Submitted for Approval");
            } else {
                // ADMIN/HR
                $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, expiry_date, description, uploaded_by) 
                        VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$emp_id, $finalName, $finalName, $category, $expiry_date, $description, $_SESSION['user_id']]);

                $logger = new Logger($pdo);
                $logger->log($_SESSION['user_id'], 'UPLOAD_DOC', "Directly uploaded: " . $finalName);

                header("Location: index.php?msg=Upload Successful");
            }
            exit;

        } else {
            die("Failed to move file to uploads folder. Check permissions.");
        }
    } else {
        header("Location: upload_form.php?error=No file selected&emp_id=$emp_id");
        exit;
    }
}
?>