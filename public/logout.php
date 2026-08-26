<?php
// ======================================================
// [FILE] public/logout.php
// [PURPOSE] Secure Session Termination & Audit Log Tracking
// ======================================================

require '../config/db.php';
require '../src/Logger.php';
session_start(); // 1. Access the current session

// [LOGGING] Record logout before destroying session
if (isset($_SESSION['user_id'])) {
    try {
        $logger = new Logger($pdo);
        $reason = isset($_GET['msg']) ? $_GET['msg'] : 'Manual Logout';
        $logger->log($_SESSION['user_id'], 'LOGOUT', "User logged out ($reason)");
    } catch (Exception $e) {
        // [FIX] Record the failure to PHP error log instead of silently swallowing it
        error_log("Logout Logging Failed: " . $e->getMessage());
    }
}

// 2. Clear all session variables
$_SESSION = [];

// 3. Destroy the session cookie (if it exists)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. Destroy the session storage on the server
session_destroy();

// 5. Redirect back to Login Page
header("Location: login.php");
exit;
