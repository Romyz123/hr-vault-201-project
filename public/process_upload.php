<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/FileService.php';
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// Helper to return JSON if AJAX
function sendResponse($status, $message, $emp_id = null)
{
    $param = $status === 'success' ? 'msg' : 'error';
    $url = "upload_form.php?$param=" . urlencode($message);
    if ($emp_id) {
        $url .= "&emp_id=" . urlencode($emp_id);
    }
    header("Location: $url");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_SESSION['user_id'])) die("ACCESS DENIED");

    if (empty($_POST)) {
        sendResponse('error', "Upload failed: File is too large (Server Limit: " . ini_get('post_max_size') . ") or request was empty.");
    }

    // CSRF Token Validation (Check AFTER size check)
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        // Debugging: Log the mismatch to help troubleshoot
        $postedToken = $_POST['csrf_token'] ?? 'MISSING';
        $sessionToken = $_SESSION['csrf_token'] ?? 'MISSING';
        error_log("CSRF Mismatch in process_upload.php. POST: $postedToken, SESSION: $sessionToken");

        sendResponse('error', "Security token expired or invalid. Please refresh and try again.");
    }

    // 1. GATHER INPUTS
    // Validate and sanitize emp_id
    $emp_id = isset($_POST['emp_id']) ? trim($_POST['emp_id']) : '';
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $emp_id) || empty($emp_id)) {
        sendResponse('error', "Invalid employee ID format.", $emp_id);
    }

    $description = trim($_POST['description'] ?? '');
    if (strlen($description) > 255) sendResponse('error', "Description is too long (Max 255 chars).", $emp_id);

    // Validate and sanitize expiry_date
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : NULL;
    if ($expiry_date) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $expiry_date);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $expiry_date) {
            sendResponse('error', "Invalid expiry date format. Use YYYY-MM-DD.", $emp_id);
        }
    }

    $customName = trim($_POST['custom_filename'] ?? ''); // Get custom name

    // --- "OTHERS" CATEGORY LOGIC (NEW) ---
    $category = $_POST['category'];
    if (empty($category)) {
        sendResponse('error', "Error: You must select a document category.", $emp_id);
    }

    if ($category === 'Others') {
        // Use the specific text they typed instead
        $other_cat = trim($_POST['other_category']);

        if (!empty($other_cat)) {
            // Capitalize nicely (e.g., "gym membership" -> "Gym Membership")
            $category = ucwords(strtolower($other_cat));
        } else {
            sendResponse('error', "Error: You selected 'Others' but did not specify the document type.", $emp_id);
        }
    }
    // -------------------------------------

    // 2. HANDLE FILE UPLOAD
    // Normalize $_FILES structure for multiple uploads
    $uploadedFiles = [];
    if (isset($_FILES['document'])) {
        if (is_array($_FILES['document']['name'])) {
            $count = count($_FILES['document']['name']);
            for ($i = 0; $i < $count; $i++) {
                $uploadedFiles[] = [
                    'name' => $_FILES['document']['name'][$i],
                    'type' => $_FILES['document']['type'][$i],
                    'tmp_name' => $_FILES['document']['tmp_name'][$i],
                    'error' => $_FILES['document']['error'][$i],
                    'size' => $_FILES['document']['size'][$i]
                ];
            }
        } else {
            $uploadedFiles[] = $_FILES['document'];
        }
    }

    $successCount = 0;
    $errors = [];

    // Initialize FileService once
    $fileService = new FileService($vaultPath);

    foreach ($uploadedFiles as $idx => $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'];
            if ($errorCode !== UPLOAD_ERR_NO_FILE) {
                $errors[] = "File " . ($idx + 1) . " error code: " . $errorCode;
            }
            continue;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];

        if (!in_array($ext, $allowed)) {
            $errors[] = "File " . ($idx + 1) . ": Invalid file type ($ext)";
            continue;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowedMime)) {
            $errors[] = "File " . ($idx + 1) . ": Invalid MIME type ($mimeType)";
            continue;
        }

        // --- SAVE TO VAULT (Operation Vault Security) ---
        // Use custom name if provided, otherwise original filename
        if (!empty($customName)) {
            // Remove extension if user typed it manually to avoid double extension (e.g. file.pdf.pdf)
            $cleanCustom = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $customName);

            // If multiple files, append index to keep names unique
            if (count($uploadedFiles) > 1) {
                $displayName = $cleanCustom . " (" . ($idx + 1) . ")." . $ext;
            } else {
                $displayName = $cleanCustom . "." . $ext;
            }
        } else {
            $displayName = $file['name'];
        }

        $storedName = $fileService->saveFile($file['tmp_name'], $displayName);

        if ($storedName) {
            // 3. PREPARE DATA
            $docData = [
                'employee_id' => $emp_id,
                'original_name' => $displayName, // Use original/custom filename for display
                'file_path' => $storedName,  // Use obfuscated stored filename
                'category' => $category,
                'expiry_date' => $expiry_date,
                'description' => $description,
                'uploaded_by' => $_SESSION['user_id']
            ];

            try {
                // 4. CHECK ROLE & ROUTE
                if ($_SESSION['role'] === 'STAFF') {
                    $payload = json_encode($docData);
                    $stmt = $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'UPLOAD_DOC', 0, ?)");
                    $stmt->execute([$_SESSION['user_id'], $payload]);
                    $logger = new Logger($pdo);
                    $logger->log($_SESSION['user_id'], 'REQUEST_DOC', "Submitted document: " . $displayName);
                } else {
                    // ADMIN/HR
                    $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, expiry_date, description, uploaded_by) 
                            VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$emp_id, $displayName, $storedName, $category, $expiry_date, $description, $_SESSION['user_id']]);
                    $logger = new Logger($pdo);
                    $logger->log($_SESSION['user_id'], 'UPLOAD_DOC', "Directly uploaded: " . $displayName);
                }
                $successCount++;
            } catch (Exception $e) {
                $errors[] = "System Error: " . $e->getMessage();
            }
        } else {
            $errors[] = "Failed to save file to vault. Check permissions.";
        }
    }

    if ($successCount > 0) {
        // Success - redirect with success message
        $msg = "Successfully uploaded $successCount document(s).";
        if (!empty($errors)) {
            $msg .= " However, some files failed: " . implode(", ", $errors);
        }
        sendResponse('success', $msg, $emp_id);
    } else {
        // No files succeeded - redirect with error
        $errorCode = is_array($_FILES['document']['error'] ?? null)
            ? ($_FILES['document']['error'][0] ?? UPLOAD_ERR_NO_FILE)
            : ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE);
        $errorMsg = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => "File exceeds upload_max_filesize in php.ini",
            UPLOAD_ERR_FORM_SIZE => "File exceeds MAX_FILE_SIZE in HTML form",
            UPLOAD_ERR_PARTIAL => "File was only partially uploaded",
            UPLOAD_ERR_NO_FILE => "No file was selected",
            UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder on server",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
            default => !empty($errors) ? implode(", ", $errors) : "Unknown upload error"
        };
        sendResponse('error', $errorMsg, $emp_id);
    }
}
