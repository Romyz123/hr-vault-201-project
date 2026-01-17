<?php
require '../config/db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$user]);
    $account = $stmt->fetch();

    if ($account && password_verify($pass, $account['password_hash'])) {
        // LOGIN SUCCESS
        $_SESSION['user_id'] = $account['id'];
        $_SESSION['username'] = $account['username']; // We will use this for uploads!
        $_SESSION['role'] = $account['role'];         // We will use this for permissions!

        header("Location: index.php");
        exit;
    } else {
        header("Location: login.php?error=Invalid Username or Password");
        exit;
    }
}
?>