<?php
session_start(); // 1. Access the current session

// 2. Clear all session variables
$_SESSION = [];

// 3. Destroy the session cookie (if it exists)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy the session storage on the server
session_destroy();

// 5. Redirect back to Login Page
header("Location: login.php");
exit;
?>