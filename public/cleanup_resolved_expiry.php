<?php
// public/cleanup_resolved_expiry.php
// [PURPOSE] Separate maintenance script for deleting resolved document expiry alerts from documents table.
// Usage: php cleanup_resolved_expiry.php or via secured HTTP as admin.

chdir(__DIR__);

define('CLI_MODE', php_sapi_name() === 'cli');

if (!CLI_MODE) {
    session_start();
    if (($_SESSION['role'] ?? '') !== 'ADMIN') {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access Denied';
        exit;
    }
}

require '../config/db.php';
require '../src/Logger.php';

try {
    $pdo->beginTransaction();
    $cleanupStmt = $pdo->prepare("UPDATE documents SET expiry_date = NULL, is_resolved = 0, resolution_note = NULL WHERE is_resolved = 1 AND expiry_date < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $cleanupStmt->execute();
    $cleanedCount = $cleanupStmt->rowCount();
    $pdo->commit();

    $message = "[cleanup_resolved_expiry] cleaned up {$cleanedCount} old resolved expiry alerts";
    if (CLI_MODE) {
        echo $message . "\n";
    }
    error_log($message);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMsg = '[cleanup_resolved_expiry] Failure: ' . $e->getMessage();
    if (CLI_MODE) {
        echo $errorMsg . "\n";
    }
    error_log($errorMsg . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if (!CLI_MODE) {
        echo 'Maintenance cleanup failed. Please check error logs.';
    }
}
