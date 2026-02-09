<?php
// public/test_zip_password.php
require '../config/db.php';
session_start();

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if (empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a password to test.']);
        exit;
    }

    if (strlen($password) < 8) {
        echo json_encode(['status' => 'error', 'message' => 'Password is too short (Min 8 characters).']);
        exit;
    }

    if (!class_exists('ZipArchive')) {
        echo json_encode(['status' => 'error', 'message' => 'ZipArchive extension is missing on this server.']);
        exit;
    }

    $zip = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_zip');

    // Try to create a ZIP
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $zip->addFromString('test_encryption.txt', 'This is a test file to verify password encryption.');

        // Attempt encryption
        if (!$zip->setEncryptionName('test_encryption.txt', ZipArchive::EM_TRAD_PKWARE, $password)) {
            $zip->close();
            @unlink($tmpFile);
            echo json_encode(['status' => 'error', 'message' => 'Encryption failed. The password might contain unsupported characters.']);
            exit;
        }

        $zip->close();

        if (file_exists($tmpFile)) {
            $size = filesize($tmpFile);
            @unlink($tmpFile); // Clean up

            if ($size > 0) {
                echo json_encode(['status' => 'success', 'message' => '✅ Password is valid! ZIP encryption test passed.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'ZIP file was created but is empty.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to write ZIP file to disk.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Could not initialize ZIP archive.']);
    }
}
