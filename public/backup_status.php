<?php
require '../config/db.php';

session_start();

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || strtoupper(trim($_SESSION['role'] ?? '')) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$runId = trim((string)($_GET['run_id'] ?? ''));
if (!preg_match('/^[a-f0-9-]{16,64}$/i', $runId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid backup run ID is required.']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('backup_run_id', 'backup_last_status', 'backup_run_started_at')");
    $state = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $state[$row['setting_key']] = $row['setting_value'];
    }

    if (!hash_equals((string)($state['backup_run_id'] ?? ''), $runId)) {
        echo json_encode(['status' => 'success', 'backup_status' => 'STALE', 'message' => 'This backup run is no longer the active run.']);
        exit;
    }

    $status = strtoupper((string)($state['backup_last_status'] ?? 'UNKNOWN'));

    if (!in_array($status, ['RUNNING', 'OK', 'FAILED'], true)) {
        $status = 'UNKNOWN';
    }

    echo json_encode(['status' => 'success', 'backup_status' => $status, 'started_at' => $state['backup_run_started_at'] ?? null]);
} catch (Throwable $e) {
    error_log('Backup status check failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to read backup status.']);
}
