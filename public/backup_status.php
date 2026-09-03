<?php
require '../config/db.php';

session_start();

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || strtoupper(trim($_SESSION['role'] ?? '')) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_last_status' LIMIT 1");
    $stmt->execute();
    $status = strtoupper((string)($stmt->fetchColumn() ?: 'UNKNOWN'));

    if (!in_array($status, ['RUNNING', 'OK', 'FAILED'], true)) {
        $status = 'UNKNOWN';
    }

    echo json_encode(['status' => 'success', 'backup_status' => $status]);
} catch (Throwable $e) {
    error_log('Backup status check failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to read backup status.']);
}
