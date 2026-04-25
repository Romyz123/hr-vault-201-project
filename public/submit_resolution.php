<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {

    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        die("Security Error: Invalid CSRF Token.");
    }

    if (!isset($_POST['doc_id'], $_POST['resolution_note'])) {
        header("Location: index.php?error=Missing required fields");
        exit;
    }

    $doc_id = filter_var($_POST['doc_id'], FILTER_VALIDATE_INT);
    if ($doc_id === false) {
        header("Location: index.php?error=Invalid document ID");
        exit;
    }

    $note = trim($_POST['resolution_note']);

    // [NEW] Fetch Document Name for Context
    $stmt = $pdo->prepare("SELECT id, original_name FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        header("Location: index.php?error=Document not found");
        exit;
    }

    $docName = $doc['original_name'] ?: 'Unknown File';

    if (empty($note)) {
        header("Location: index.php?error=Resolution note is required");
        exit;
    }
    if (strlen($note) > 1000) {
        header("Location: index.php?error=Resolution note is too long (Max 1000 chars)");
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $data = [
        'doc_name' => $docName,
        'doc_id'   => $doc_id,
        'note'     => $note,
        'resolved_by' => $user_id
    ];
    $payload = json_encode($data);

    // [FIX] Normalize role and check system settings for direct approval
    $userRole = strtoupper(trim($_SESSION['role'] ?? 'STAFF'));

    $staffDirect = false;
    try {
        $stmtSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'staff_direct_approval'");
        $staffDirect = ($stmtSetting && $stmtSetting->fetchColumn() === '1');
    } catch (Exception $e) {
        // Fallback to false if settings table is missing
    }

    // Determine if resolution should be immediate or require approval
    if ($userRole === 'STAFF' && !$staffDirect) {
        // Create Request (Ticket)
        $stmt = $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'RESOLVE_ALERT', ?, ?)");
        $stmt->execute([$user_id, $doc_id, $payload]);

        $logger = new Logger($pdo);
        $logger->log($user_id, 'REQUEST_RESOLVE', "Submitted resolution report for Doc ID: $doc_id");

        header("Location: index.php?msg=Resolution Report Submitted for Approval");
    } elseif (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true) || ($userRole === 'STAFF' && $staffDirect)) {
        // ADMIN/HR: Resolve Immediately
        $stmt = $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ? WHERE id = ?");
        $stmt->execute([$note, $doc_id]);

        $logger = new Logger($pdo);
        $logger->log($user_id, 'RESOLVED_ALERT', "Marked alert as resolved: $note");

        header("Location: index.php?msg=Alert Resolved Successfully");
    } else {
        header("Location: index.php?error=Unauthorized action");
    }
    exit;
}
