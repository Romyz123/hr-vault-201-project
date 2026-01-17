<?php
require '../config/db.php';

// This list defines the passwords you asked about
$users = [
    ['admin', 'admin123', 'ADMIN'], 
    ['staff', 'staff123', 'STAFF']
];

foreach ($users as $u) {
    $user = $u[0];
    $pass = password_hash($u[1], PASSWORD_DEFAULT); // This encrypts 'admin123' into a secure hash
    $role = $u[2];

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->execute([$user, $pass, $role]);
        echo "Created user: <strong>$user</strong> with password: <strong>$u[1]</strong><br>";
    } catch (PDOException $e) {
        echo "User $user already exists (Password is likely already set).<br>";
    }
}
?>