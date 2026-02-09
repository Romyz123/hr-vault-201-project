<?php
// public/test_email_alert.php
require '../config/db.php';
session_start();

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter an email address.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
        exit;
    }

    $subject = "Test Alert - HR System Backup";
    $message = "This is a test email to verify your Backup Alert settings.\n\nIf you received this, your email configuration is working correctly.\n\nTime: " . date('Y-m-d H:i:s');
    $headers = "From: HR System <no-reply@hrsystem.com>";

    // Attempt to send
    if (@mail($email, $subject, $message, $headers)) {
        echo json_encode(['status' => 'success', 'message' => '✅ Test email sent successfully! Check your inbox.']);
    } else {
        $error = error_get_last()['message'] ?? 'Unknown error';
        echo json_encode(['status' => 'error', 'message' => '❌ Failed to send email. Server Error: ' . $error]);
    }
}
