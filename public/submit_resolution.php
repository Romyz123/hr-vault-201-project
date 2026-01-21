<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. Security Check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 2. Collect Data
    $doc_id = $_POST['doc_id'] ?? '';
    $note   = trim($_POST['resolution_note'] ?? '');
    $user_id = $_SESSION['user_id'];
    $role    = $_SESSION['role'] ?? '';

    // 3. Validation
    if (empty($doc_id) || empty($note)) {
        $_SESSION['error'] = "Error: Missing document ID or resolution note.";
        header("Location: index.php");
        exit;
    }

    try {
        $logger = new Logger($pdo);

        // === SCENARIO A: STAFF (SEND REQUEST) ===
        if ($role === 'STAFF') {
            
            // Package the data so Admin can see it later
            $payload = json_encode([
                'doc_id' => $doc_id,
                'note'   => $note
            ]);

            // Save to 'requests' table (This makes it show up in your Resolutions Tab)
            $stmt = $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'RESOLVE_ALERT', ?, ?)");
            $stmt->execute([$user_id, $doc_id, $payload]);

            $logger->log($user_id, 'REQUEST_RESOLUTION', "Submitted resolution request for Doc ID: $doc_id");

            // Success Message
            $_SESSION['success'] = "Resolution report submitted for Admin approval.";
            header("Location: index.php?msg=" . urlencode("Report submitted. Pending Approval."));
            exit;

        } 
        // === SCENARIO B: ADMIN/HR (FIX IMMEDIATELY) ===
        else {
            
            // Update the document table directly
            $stmt = $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ? WHERE id = ?");
            $stmt->execute([$note, $doc_id]);

            $logger->log($user_id, 'RESOLVED_ALERT', "Instantly resolved alert for Doc ID: $doc_id");

            // Success Message
            $_SESSION['success'] = "Document alert resolved successfully.";
            header("Location: index.php?msg=" . urlencode("Alert Resolved."));
            exit;
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Database Error: " . $e->getMessage();
        header("Location: index.php");
        exit;
    }
}