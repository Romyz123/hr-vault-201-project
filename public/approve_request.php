<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

$security = new Security($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Security Check
    if (!isset($_POST['csrf_token'])) $security->checkCSRF($_POST['csrf_token']);
    
    $req_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approve' or 'reject'

    // Fetch the request details
    $stmt = $pdo->prepare("SELECT * FROM pending_requests WHERE id = ?");
    $stmt->execute([$req_id]);
    $request = $stmt->fetch();

    if (!$request) die("Request not found.");

    if ($action === 'reject') {
        // If rejected, just mark status as rejected (Keep file in vault but orphaned, or delete it)
        // For security, we usually keep the log but maybe delete the physical file.
        $pdo->prepare("UPDATE pending_requests SET status = 'REJECTED' WHERE id = ?")->execute([$req_id]);
        
        // Optional: Delete the physical file from vault if rejected
        $data = json_decode($request['json_payload'], true);
        if (isset($data['file_path'])) {
            @unlink($_ENV['VAULT_PATH'] . $data['file_path']);
        }
        
        header("Location: admin_approval.php?msg=Rejected");
        exit;
    }

    // --- APPROVAL LOGIC ---
    
    $data = json_decode($request['json_payload'], true);
    
    try {
        $pdo->beginTransaction();

        if ($request['request_type'] == 'UPLOAD_DOC') {
            // 1. Insert into Real Documents Table
            $ins = $pdo->prepare("INSERT INTO documents (employee_id, file_uuid, original_name, category, file_path) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([
                $request['emp_id'],
                $data['file_uuid'],
                $data['original_name'],
                $data['category'],
                $data['file_path']
            ]);

            // 2. Intelligent Status Updates (Automated Triggers)
            // If the document is a "Violation", flag the user? 
            // If "Resignation", change status to Resigned?
            /* Example Logic:
            if ($data['category'] === 'Resignation') {
                $upd = $pdo->prepare("UPDATE employees SET status = 'Resigned' WHERE emp_id = ?");
                $upd->execute([$request['emp_id']]);
            }
            */
        }

        // 3. Mark Request as Approved
        $pdo->prepare("UPDATE pending_requests SET status = 'APPROVED' WHERE id = ?")->execute([$req_id]);

        $pdo->commit();
        header("Location: admin_approval.php?msg=Approved");

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error processing approval: " . $e->getMessage());
    }
}
?>