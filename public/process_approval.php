<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

$security = new Security($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Security Check
    if (!isset($_POST['csrf_token'])) $security->checkCSRF($_POST['csrf_token']);
    
    $req_id = $_POST['request_id'];
    $action = $_POST['action'];

    // Fetch the request details to get the filename
    $stmt = $pdo->prepare("SELECT * FROM pending_requests WHERE id = ?");
    $stmt->execute([$req_id]);
    $request = $stmt->fetch();

    if (!$request) die("Request not found.");

    $data = json_decode($request['json_payload'], true);
    $vaultPath = $_ENV['VAULT_PATH']; 
    $fullFilePath = $vaultPath . ($data['file_path'] ?? '');

    // --- REJECTION LOGIC (AUTO-DELETE) ---
    if ($action === 'reject') {
        
        // 1. Delete the physical file from the Vault
        if (file_exists($fullFilePath)) {
            unlink($fullFilePath); // The delete command
        }

        // 2. Mark database record as REJECTED
        $pdo->prepare("UPDATE pending_requests SET status = 'REJECTED' WHERE id = ?")->execute([$req_id]);
        
        header("Location: admin_approval.php?msg=Request Rejected and File Deleted");
        exit;
    }

    // --- APPROVAL LOGIC ---
    try {
        $pdo->beginTransaction();

        if ($request['request_type'] == 'UPLOAD_DOC') {
            // Move from Staging to Live Data
            $ins = $pdo->prepare("INSERT INTO documents (employee_id, file_uuid, original_name, category, file_path) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([
                $request['emp_id'],
                $data['file_uuid'],
                $data['original_name'],
                $data['category'],
                $data['file_path']
            ]);
        }

        // Mark as Approved
        $pdo->prepare("UPDATE pending_requests SET status = 'APPROVED' WHERE id = ?")->execute([$req_id]);

        $pdo->commit();
        header("Location: admin_approval.php?msg=Approved Successfully");

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error processing approval: " . $e->getMessage());
    }
}
?>