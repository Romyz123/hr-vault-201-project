<?php
// --- START: UI REPAIR ---
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/FileService.php';
// [FIX] Include global helper functions
if (!function_exists('h')) {
    require_once __DIR__ . '/../src/helpers.php';
}
session_start();

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// Helper to return JSON if AJAX
function sendResponse($status, $message, $emp_id = null) // [FIX] h() is not used here, but it's good practice to have it available
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

    // [SECURITY] 1. CSRF Token Validation (MUST be first)
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        // If POST is empty, it's likely a file size issue, not CSRF. Give a better error.
        if (empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
            sendResponse('error', "Upload failed: File is too large (Server Limit: " . ini_get('post_max_size') . "). Please upload smaller files.");
        }
        sendResponse('error', "Security token expired or invalid. Please refresh and try again.");
    }

    // [SECURITY] 2. Check for upload errors after CSRF
    if (!isset($_FILES['document']) || (is_array($_FILES['document']['name']) && empty($_FILES['document']['name'][0])) || (!is_array($_FILES['document']['name']) && empty($_FILES['document']['name']))) {
        sendResponse('error', "No file was selected for upload. Please choose a file.");
    }

    // 3. GATHER INPUTS
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
    if (!empty($customName)) {
        if (strlen($customName) > 50) sendResponse('error', "Custom filename is too long (Max 50 chars).", $emp_id);
        if (!preg_match('/^[a-zA-Z0-9\-_ \.]+$/', $customName)) {
            sendResponse('error', "Custom filename contains invalid characters.", $emp_id);
        }
        if (strpos($customName, '..') !== false) {
            sendResponse('error', "Custom filename cannot contain consecutive dots.", $emp_id);
        }
    }

    // --- "OTHERS" CATEGORY LOGIC (NEW) ---
    $category = $_POST['category'] ?? '';
    if (empty($category)) {
        sendResponse('error', "Error: You must select a document category.", $emp_id);
    }

    if ($category === 'Others') {
        // Use the specific text they typed instead
        $other_cat = trim($_POST['other_category'] ?? '');
        if (!empty($other_cat)) {
            // [SECURITY] Validation for Custom Category
            if (strlen($other_cat) > 50) sendResponse('error', "Custom category is too long (Max 50 chars).", $emp_id);
            if (!preg_match('/^[a-zA-Z0-9\-_ ]+$/', $other_cat)) sendResponse('error', "Custom category contains invalid characters.", $emp_id);

            // Capitalize nicely (e.g., "gym membership" -> "Gym Membership")
            $category = ucwords(strtolower($other_cat));
        } else {
            sendResponse('error', "Error: You selected 'Others' but did not specify the document type.", $emp_id);
        }
    }
    // -------------------------------------

    // [NEW] Fetch employee status for naming convention (Resigned, Terminated, AWOL)
    $stmtStatus = $pdo->prepare("SELECT status FROM employees WHERE emp_id = ? AND deleted_at IS NULL");
    $stmtStatus->execute([$emp_id]);
    $rowStatus = $stmtStatus->fetch();
    $empStatus = $rowStatus ? $rowStatus['status'] : 'Active';
    $statusLabel = ($empStatus !== 'Active') ? " ($empStatus)" : "";

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

    // [SECURITY] Vault Size Quota Check
    $vaultLimitGB = 1; // Default 1GB
    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'vault_size_limit_gb'");
        $val = $stmt->fetchColumn();
        if ($val !== false) $vaultLimitGB = (float)$val;
    } catch (Exception $e) {
        error_log("Failed to fetch vault_size_limit_gb setting: " . $e->getMessage());
    }
    if ($vaultLimitGB > 0) {
        $currentVaultSize = 0;
        if (is_dir($vaultPath)) {
            $iterator = new FileSystemIterator($vaultPath, FileSystemIterator::SKIP_DOTS);
            foreach ($iterator as $f) {
                if ($f->isFile()) $currentVaultSize += $f->getSize();
            }
        }
        $incomingSize = 0;
        foreach ($uploadedFiles as $f) {
            if ($f['error'] === UPLOAD_ERR_OK) $incomingSize += $f['size'];
        }
        $vaultLimitBytes = $vaultLimitGB * 1024 * 1024 * 1024;
        if (($currentVaultSize + $incomingSize) > $vaultLimitBytes) {
            sendResponse('error', "Upload rejected: Vault size limit exceeded. (Limit: {$vaultLimitGB} GB)", $emp_id);
        }
    }

    foreach ($uploadedFiles as $idx => $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'];
            if ($errorCode !== UPLOAD_ERR_NO_FILE) {
                $errors[] = "File " . ($idx + 1) . " error code: " . $errorCode;
            }
            continue;
        }

        // [SECURITY] Verify the file was uploaded via HTTP POST
        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = "File " . ($idx + 1) . ": Invalid upload. The file was not uploaded via a legitimate HTTP POST.";
            continue;
        }

        $rawName = basename($file['name']);
        $ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];

        if (!in_array($ext, $allowed)) {
            $errors[] = "File " . ($idx + 1) . ": Invalid file extension ($ext). Strictly allow only PDF, JPG, and PNG.";
            continue;
        }

        // [SECURITY] Use finfo_open and finfo_file to check the actual mathematical MIME type signature
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMime)) {
            $errors[] = "File " . ($idx + 1) . ": Invalid MIME type ($mimeType)";
            continue;
        }

        // [SECURITY] Deep Signature Scan (Anti-Polyglot & Executable Check)
        $handle = @fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            $errors[] = "File " . ($idx + 1) . ": Unable to read file for security scanning.";
            continue;
        }

        $fileSize = $file['size'];
        $header = fread($handle, min(2048, $fileSize));

        // 1. Block Disguised Executables & Scripts (Windows PE, Linux ELF, PHP)
        if (strpos($header, 'MZ') === 0 || strpos($header, "\x7FELF") === 0 || stripos($header, '<?php') !== false) {
            fclose($handle);
            $errors[] = "File " . ($idx + 1) . ": Rejected. Suspicious executable or script signature detected.";
            continue;
        }

        // 2. PDF Specific Strict Checks
        if ($ext === 'pdf') {
            fseek($handle, -min(1024, $fileSize), SEEK_END);
            $footer = fread($handle, min(1024, $fileSize));
            fclose($handle);

            // Strictly enforce that %PDF- is at index 0 (prevents embedded headers)
            if (strpos($header, '%PDF-') !== 0 || strpos($footer, '%%EOF') === false) {
                $errors[] = "File " . ($idx + 1) . ": The PDF file appears to be corrupted or incomplete.";
                continue;
            }
        } else {
            fclose($handle);
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

        // [NEW] Auto-increment filename logic (-001, -002, etc.)
        // This prevents naming collisions and helps distinguish multiple versions of the same file. [FIX] Use empStatus for naming
        $baseNameOnly = pathinfo($displayName, PATHINFO_FILENAME);
        $extOnly = pathinfo($displayName, PATHINFO_EXTENSION);

        // Inject Status into the filename if employee is not Active
        if ($empStatus !== 'Active') {
            $baseNameOnly .= $statusLabel;
        }

        // [FIX] Sanitize base name before collision check to ensure we match what's actually in the DB
        $baseNameOnly = preg_replace('/[^a-zA-Z0-9\s\-\.\(\)_]/', '', $baseNameOnly);

        // Fetch all existing names for this employee to check for collisions
        $checkStmt = $pdo->prepare("SELECT original_name FROM documents WHERE employee_id = ? AND deleted_at IS NULL");
        $checkStmt->execute([$emp_id]);
        $existingInDB = $checkStmt->fetchAll(PDO::FETCH_COLUMN);

        $counter = 1;
        $displayName = $baseNameOnly . '.' . $extOnly;
        while (true) {
            if (!in_array($displayName, $existingInDB)) {
                break;
            }
            $displayName = $baseNameOnly . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT) . '.' . $extOnly;
            $counter++;
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
                error_log("Upload DB error: " . $e->getMessage());
                // Cleanup uploaded file if database insert fails
                $vaultFile = $vaultPath . $storedName;
                if (!empty($storedName) && file_exists($vaultFile)) {
                    if (!@unlink($vaultFile)) {
                        error_log("Failed to delete orphaned vault file: " . $vaultFile);
                    }
                }
                $errors[] = "System Error: Unable to save document. Please try again.";
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
