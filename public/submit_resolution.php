<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    
    $doc_id = $_POST['doc_id'];
    $note   = trim($_POST['resolution_note']);
    $user_id = $_SESSION['user_id'];
    
    if (empty($note)) {
        header("Location: index.php?error=Resolution note is required");
        exit;
    }

    // Prepare Payload
    $data = [
        'doc_id' => $doc_id,
        'note'   => $note,
        'resolved_by' => $user_id
    ];
    $payload = json_encode($data);

    // CHECK ROLE
    if ($_SESSION['role'] === 'STAFF') {
        // Create Request (Ticket)
        $stmt = $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'RESOLVE_ALERT', ?, ?)");
        $stmt->execute([$user_id, $doc_id, $payload]);

        $logger = new Logger($pdo);
        $logger->log($user_id, 'REQUEST_RESOLVE', "Submitted resolution report for Doc ID: $doc_id");

        header("Location: index.php?msg=Resolution Report Submitted for Approval");
    } 
    else {
        // ADMIN/HR: Resolve Immediately
        $stmt = $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ? WHERE id = ?");
        $stmt->execute([$note, $doc_id]);
        
        $logger = new Logger($pdo);
        $logger->log($user_id, 'RESOLVED_ALERT', "Marked alert as resolved: $note");
        
        header("Location: index.php?msg=Alert Resolved Successfully");
    }
    exit;
}
?>