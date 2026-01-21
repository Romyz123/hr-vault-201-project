<?php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("<h2>❌ You are logged out. Please log in first.</h2>");
}

// Fetch fresh data from database
$stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h1>🕵️ Role Debugger</h1>";
echo "<h3>1. What is in your Session (The Website's Memory)?</h3>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

echo "<h3>2. What is in your Database ( The Real Data)?</h3>";
echo "<pre>" . print_r($user, true) . "</pre>";

echo "<h3>3. Diagnosis:</h3>";
$role = $user['role'];
echo "Your Role is: <strong>'$role'</strong> (Length: " . strlen($role) . ")<br>";

if ($role === 'HR') {
    echo "✅ Exact Match: 'HR' (Uppercase). Perfect.<br>";
} elseif ($role === 'hr') {
    echo "⚠️ Warning: Your role is 'hr' (lowercase). The code expects 'HR' (Uppercase).<br>";
} elseif ($role === 'Hr') {
    echo "⚠️ Warning: Your role is 'Hr' (Mixed case). The code expects 'HR' (Uppercase).<br>";
} else {
    echo "ℹ️ Your role is not strictly 'HR'.<br>";
}
?>