<?php
// ======================================================
// [FILE] public/import_employees.php
// [STATUS] Reverted to User's working version + Custom Import
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require 'options.php'; // Fetch dynamic options for agencies
session_start();

// 1. SECURITY: Admin, Manager & HR Only
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    die("ACCESS DENIED");
}

$security = new Security($pdo);
$logger   = new Logger($pdo);
$msg = "";
$error = "";

// ensure rollback table exists for import undo support
// NOTE: this table creation should be handled by a one-time migration
// (see schema/migrations/2026-03-07-add-import-rollbacks.sql) rather than
// running DDL on every request. The migration also adds indexes on
// employee_id and import_batch to speed up undo lookups.
//
// $pdo->exec("CREATE TABLE IF NOT EXISTS import_rollbacks ( ... )");



// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------------- CONFIGURATION ----------------
// [IMPORTANT] Order matters! Specific departments (SQP, SIGCOM) come first
// to prevent generic keywords (like "IT" or "Safety") from being grabbed by Admin.
$deptMap = [
    // 0. Office of the President (Top Priority)
    "OP" => [
        "OFFICE OF THE PRESIDENT",
        "OP"
    ],

    // 1. SQP (Safety, Quality, Planning) - High Priority
    "SQP" => [
        "SQP",
        "SAFETY, QUALITY AND PLANNING",
        "SAFETY QUALITY PLANNING",
        "SQP-PLANNING GROUP",
        "SQP -QUALITY ASSURANCE GROUP",
        "SQP-SAFETY GROUP",
        "SQP -IT GROUP",
        "IT", // <--- Moved here. Now ALL "IT" staff will go to SQP.
        "SQP-SAFETY HEAD",
        "SAFETY",
        "QA",
        "PLANNING",
        "QUALITY ASSURANCE"
    ],

    // 2. SIGCOM (Signaling & Communication) - High Priority
    "SIGCOM" => [
        "SIGCOM",
        "SIG",
        "SIGNALING",
        "COMMUNICATION",
        "SIGNAL",
        "SIGNAL & COMMUNICATION",
        "SIGNALING AND COMMUNICATIOMN" // Handles the typo
    ],

    // 3. Other Technical Depts
    "HMS"     => ["HEAVY MAINTENANCE", "HMS"],
    "RAS"     => ["ROOT CAUSE", "RAS"],
    "TRS"     => ["TECHNICAL RESEARCH", "TRS"],
    "LMS"     => ["LIGHT MAINTENANCE", "LMS"],
    "DOS"     => ["DEPARTMENT OPERATIONS", "DOS"],
    "CTS"     => ["CIVIL TRACKS", "CTS"],
    "PSS"     => ["POWER SUPPLY", "PSS"],
    "OCS"     => ["OVERHEAD", "OCS", "CATENARY"],
    "BFS"     => ["BUILDING FACILITIES", "BFS"],
    "WHS"     => ["WAREHOUSE", "WHS"],

    // 4. Security / Gunjin
    "GUNJIN"  => ["EMT", "SECURITY", "GUNJIN"],

    // 5. Admin (Low Priority)
    "ADMIN"   => [
        "ADMIN",
        "GAG",
        "TKG",
        "PCG",
        "ACG",
        "MED",
        "CLEANERS"
        // Removed "IT" from here because you moved it to SQP
    ],

    "SUBCONS-OTHERS" => ["OTHERS"]
];

function findDept($section, $map)
{
    $section = strtoupper(trim($section));

    // 1. Exact Key Match (e.g. if Section is literally "SQP")
    if (array_key_exists($section, $map)) return $section;

    // 2. Keyword Search
    foreach ($map as $dept => $keywords) {
        foreach ($keywords as $k) {
            // Check if keyword exists inside the section name
            if (strpos($section, $k) !== false) {
                return $dept;
            }
        }
    }
    return "SUBCONS-OTHERS";
}

// [FIX] Upgraded Date Parser to properly handle MS Forms (M/d/yyyy)
function parseDate($dateStr)
{
    $dateStr = trim($dateStr);
    if (empty($dateStr)) return NULL;

    // Handle formats like M/D/YYYY or MM/DD/YYYY outputted by Forms
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dateStr, $matches)) {
        $p1 = (int)$matches[1];
        $p2 = (int)$matches[2];
        $y = $matches[3];

        if ($p1 > 12) {
            return sprintf('%04d-%02d-%02d', $y, $p2, $p1); // Must be DD/MM/YYYY
        } elseif ($p2 > 12) {
            return sprintf('%04d-%02d-%02d', $y, $p1, $p2); // Must be MM/DD/YYYY
        } else {
            // Ambiguous (e.g. 05/06/2024), favor US MM/DD/YYYY per user request
            return sprintf('%04d-%02d-%02d', $y, $p1, $p2);
        }
    }

    $timestamp = strtotime($dateStr);
    if ($timestamp !== false && $timestamp > 0) return date('Y-m-d', $timestamp);
    return NULL;
}

// ======================================================
// 2. HANDLE UNDO ACTION
// ======================================================
if (isset($_POST['undo_batch'])) {
    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    $batch_to_delete = $_POST['undo_batch'];

    // [NEW] 1. Check Time Limit (30 Minutes = 1800 Seconds)
    $stmt = $pdo->prepare("SELECT MAX(created_at) FROM employees WHERE import_batch = ?");
    $stmt->execute([$batch_to_delete]);
    $batchTimeStr = $stmt->fetchColumn();

    // If batch exists AND is older than 7 hours (25200 seconds)
    if ($batchTimeStr && (time() - strtotime($batchTimeStr) > 25200)) {
        $error = "❌ Undo Failed: The 7-hour time limit for this batch has expired.";
    }
    // [EXISTING LOGIC] Proceed if valid
    elseif (strpos($batch_to_delete, 'BATCH_') === 0) {
        try {
            // [NEW] 1. Cleanup associated documents (Physical + DB) to prevent Ghost Files
            // Get all emp_ids in this batch
            $stmt = $pdo->prepare("SELECT emp_id, avatar_path FROM employees WHERE import_batch = ?");
            $stmt->execute([$batch_to_delete]);
            $batchData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $empIds = array_column($batchData, 'emp_id');

            if (!empty($empIds)) {
                $placeholders = implode(',', array_fill(0, count($empIds), '?'));

                // A. Get files to delete
                $docStmt = $pdo->prepare("SELECT file_path FROM documents WHERE employee_id IN ($placeholders)");
                $docStmt->execute($empIds);
                $filesToDelete = $docStmt->fetchAll(PDO::FETCH_COLUMN);

                $vaultPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

                foreach ($filesToDelete as $filePath) {
                    // Sanitize to prevent path traversal
                    $filePath = str_replace(['../', '..\\'], '', $filePath);
                    $path = $vaultPath . $filePath;
                    // Check uploads for disciplinary fallback
                    if (!file_exists($path)) {
                        $altPath = __DIR__ . '/uploads/' . $filePath;
                        if (file_exists($altPath)) $path = $altPath;
                    }

                    // Verify resolved path is within allowed directories
                    $realPath = realpath($path);
                    $allowedVault = realpath($vaultPath);
                    $allowedUploads = realpath(__DIR__ . '/uploads/');

                    if ($realPath && (
                        ($allowedVault && strpos($realPath, $allowedVault) === 0) ||
                        ($allowedUploads && strpos($realPath, $allowedUploads) === 0)
                    )) {
                        if (!unlink($realPath)) {
                            error_log("Failed to delete file: $realPath");
                        }
                    }
                }

                // B. Delete document records
                $pdo->prepare("DELETE FROM documents WHERE employee_id IN ($placeholders)")->execute($empIds);

                // C. Delete Disciplinary Cases & Files
                $discStmt = $pdo->prepare("SELECT attachment_path FROM disciplinary_cases WHERE employee_id IN ($placeholders)");
                $discStmt->execute($empIds);
                $discFiles = $discStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($discFiles as $df) {
                    if (empty($df)) continue;
                    $df = str_replace(['../', '..\\'], '', $df);
                    $fullPath = realpath(__DIR__ . '/uploads/' . $df);
                    $uploadsDir = realpath(__DIR__ . '/uploads/');
                    if ($fullPath && $uploadsDir && strpos($fullPath, $uploadsDir) === 0 && file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
                $pdo->prepare("DELETE FROM disciplinary_cases WHERE employee_id IN ($placeholders)")->execute($empIds);

                // D. Delete Avatars
                foreach ($batchData as $row) {
                    $av = $row['avatar_path'];
                    if ($av && basename($av) !== 'default.png') {
                        $av = basename($av); // Only allow filename, no directory components
                        $avPath = __DIR__ . '/uploads/avatars/' . $av;
                        if (file_exists($avPath) && !unlink($avPath)) {
                            error_log("Failed to delete avatar: $avPath");
                        }
                    }
                }
            }

            // ==============================================
            // Restore updated rows (if any) using rollbacks
            // ==============================================
            $rollStmt = $pdo->prepare("SELECT * FROM import_rollbacks WHERE import_batch = ?");
            $rollStmt->execute([$batch_to_delete]);
            $rollbacks = $rollStmt->fetchAll(PDO::FETCH_ASSOC);
            // prepare allowed column list for safety
            $allowedCols = [
                'emp_id',
                'first_name',
                'middle_name',
                'last_name',
                'dept',
                'section',
                'employment_type',
                'agency_name',
                'job_title',
                'status',
                'gender',
                'birth_date',
                'hire_date',
                'contact_number',
                'present_address',
                'permanent_address',
                'avatar_path',
                'import_batch',
                'sss_no',
                'tin_no',
                'pagibig_no',
                'philhealth_no',
                'email',
                'emergency_name',
                'emergency_contact',
                'emergency_address',
                'education',
                'experience',
                'licenses',
                'updated_at'
            ];

            $restored_count = 0;
            foreach ($rollbacks as $rb) {
                $old = json_decode($rb['old_data'], true);
                if ($old) {
                    // build update statement to restore original data (whitelist columns)
                    $cols = [];
                    $params = [];
                    foreach ($old as $col => $val) {
                        if ($col === 'id' || !in_array($col, $allowedCols, true)) {
                            if (!in_array($col, $allowedCols, true)) {
                                error_log("Skipped unknown column during rollback: $col");
                            }
                            continue;
                        }
                        $cols[] = "`$col` = ?";
                        $params[] = $val;
                    }
                    if (!empty($cols)) {
                        $params[] = $rb['employee_id'];
                        $updSql = "UPDATE employees SET " . implode(", ", $cols) . " WHERE id = ?";
                        $updStmt = $pdo->prepare($updSql);
                        $updStmt->execute($params);
                        if ($updStmt->rowCount() > 0) {
                            $restored_count++;
                        }
                    }
                }
            }

            // ==============================================
            // Delete newly inserted rows (not restored above)
            // ==============================================
            $del = $pdo->prepare("DELETE FROM employees WHERE import_batch = ? AND id NOT IN (SELECT employee_id FROM import_rollbacks WHERE import_batch = ?)");
            $del->execute([$batch_to_delete, $batch_to_delete]);
            $deleted_count = $del->rowCount();

            // After restoring/cleaning employees, log and report
            $logger->log($_SESSION['user_id'], 'IMPORT_UNDO', "Reverted import batch $batch_to_delete: restored $restored_count, deleted $deleted_count");
            $msg = "✅ Undo Completed! Restored $restored_count rows, removed $deleted_count new rows.";
        } catch (Exception $e) {
            $error = "Undo Failed: " . htmlspecialchars($e->getMessage());
        }
    }
}


// ======================================================
// 3. HANDLE IMPORT ACTION
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['undo_batch'])) {

    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    $format = $_POST['agency_select'] ?? '';
    $target_agency = $_POST['target_agency'] ?? '';

    if ($format === "") {
        $error = "Please select an Import Format first.";
    } elseif (empty($target_agency)) {
        $error = "Please assign a Target Agency.";
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error. Please try again.";
    } else {
        $file = $_FILES['csv_file']['tmp_name'] ?? '';
        if (empty($file) || !is_uploaded_file($file)) {
            $error = "Invalid uploaded file.";
        } else {
            $handle = @fopen($file, "r");
            if ($handle === false) {
                error_log("Import failed: could not open uploaded file $file");
                $error = "Unable to read uploaded file.";
            } else {
                // detect delimiter based on first line sample
                $success_count = 0;
                $updated_count = 0; // [NEW] Track updates
                $firstChunk = fread($handle, 4096);
                rewind($handle);

                $delimComma = substr_count($firstChunk, ',');
                $delimSemi  = substr_count($firstChunk, ';');
                $delimTab   = substr_count($firstChunk, "\t");

                $delimiter = ',';
                if ($delimSemi > $delimComma && $delimSemi > $delimTab) $delimiter = ';';
                if ($delimTab > $delimComma && $delimTab > $delimSemi) $delimiter = "\t";

                $batch_id = "BATCH_" . date('Ymd_His');
                $success_count = 0;

                // Capture header for Custom mapping
                $headerRow = fgetcsv($handle, 0, $delimiter);
                if (isset($headerRow[0])) { // Remove BOM if present
                    $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]);
                }

                while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                    if (empty($data) || (count($data) === 1 && empty($data[0]))) continue; // Skip empty rows

                    // DEFAULT VARIABLES
                    $emp_id = "";
                    $first_name = "";
                    $middle_name = "";
                    $last_name = "";
                    $section_raw = "";
                    $dept_raw = "";
                    $contact_raw = "";
                    $birth_raw = "";
                    $hire_raw = "";
                    $sss_raw = "";
                    $tin_raw = "";
                    $pagibig_raw = "";
                    $phil_raw = "";
                    $job_title = "Staff";
                    $email = "";
                    $gender = "Male";
                    $present_addr = "To be updated";
                    $permanent_addr = "";
                    $emg_name = "";
                    $emg_contact = "";
                    $emg_addr = "";

                    // ----------------------------------------------------
                    // SWITCH LOGIC: MAP COLUMNS BASED ON AGENCY
                    // ----------------------------------------------------

                    if ($format === 'JORATECH') {
                        // [JORATECH FORMAT]
                        // 0:NO | 1:SECTION | 2:POSITION | 3:HIRED | 4:NUM(Ignore) | 5:PIC(Ignore) | 6:NAME | 7:CODE | 8:CONTRACT

                        $emp_id      = trim($data[7] ?? ''); // CODE (Col H)
                        $section_raw = $data[1] ?? '';       // SECTION (Col B)
                        $job_title   = ucwords(strtolower(trim($data[2] ?? 'Staff'))); // POSITION (Col C)
                        $hire_raw    = $data[3] ?? '';       // DATE HIRED (Col D)

                        // NAME (Col 6 / G)
                        $full_name = trim($data[6] ?? '');
                        if (strpos($full_name, ',') !== false) {
                            $parts = explode(',', $full_name);
                            $last_name = ucwords(strtolower(trim($parts[0])));
                            $first_name = ucwords(strtolower(trim($parts[1] ?? '')));
                        } else {
                            // no comma, attempt to split on whitespace
                            $words = preg_split('/\s+/', trim($full_name));
                            if (count($words) > 1) {
                                $first_name = ucwords(strtolower(array_shift($words)));
                                $last_name = ucwords(strtolower(implode(' ', $words)));
                            } else {
                                $last_name = ucwords(strtolower($full_name));
                                $first_name = '';
                            }
                        }
                    } elseif ($format === 'UNLISOLUTIONS') {
                        // [UNLISOLUTIONS FORMAT]
                        $emp_id = trim($data[1] ?? '');

                        $full_name = trim($data[3] ?? '');
                        $parts = explode(',', $full_name);
                        if (count($parts) >= 2) {
                            $last_name = ucwords(strtolower(trim($parts[0])));
                            $first_name = ucwords(strtolower(trim($parts[1])));
                        } else {
                            $words = preg_split('/\s+/', trim($full_name));
                            if (count($words) > 1) {
                                $first_name = ucwords(strtolower(array_shift($words)));
                                $last_name = ucwords(strtolower(implode(' ', $words)));
                            } else {
                                $last_name = ucwords(strtolower($full_name));
                                $first_name = '';
                            }
                        }

                        $job_title   = ucwords(strtolower(trim($data[4] ?? 'Staff')));
                        $section_raw = $data[5] ?? '';
                        $contact_raw = $data[6] ?? '';
                        $birth_raw   = $data[7] ?? '';
                        $hire_raw    = $data[8] ?? '';
                        $sss_raw     = $data[9] ?? '';
                        $tin_raw     = $data[10] ?? '';
                        $pagibig_raw = $data[11] ?? '';
                        $phil_raw    = $data[12] ?? '';
                        $email       = strtolower(trim($data[14] ?? ''));
                    } elseif ($format === 'CUSTOM') {
                        // [NEW] Dynamic Header Mapping
                        $h = array_map(function ($val) {
                            // Clean weird Excel BOM or invisible characters
                            return strtoupper(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $val)));
                        }, $headerRow);

                        $getVal = function ($keys) use ($h, $data) {
                            $ignoreHeaders = ['ID', 'START TIME', 'COMPLETION TIME', 'EMAIL', 'NAME', 'LAST MODIFIED TIME'];

                            // Pass 1: Strict Exact Match
                            foreach ($h as $index => $headerName) {
                                if (in_array($headerName, $ignoreHeaders, true)) continue;
                                // Automatically strip trailing numbers generated by MS Forms
                                $cleanHeader = trim(preg_replace('/[0-9]+$/', '', $headerName));
                                foreach ($keys as $k) {
                                    if ($cleanHeader === strtoupper($k) && !empty(trim($data[$index] ?? ''))) {
                                        return trim($data[$index]);
                                    }
                                }
                            }

                            // Pass 2: Fuzzy / Partial Match
                            foreach ($h as $index => $headerName) {
                                if (in_array($headerName, $ignoreHeaders, true)) continue;
                                $cleanHeader = trim(preg_replace('/[0-9]+$/', '', $headerName));
                                foreach ($keys as $k) {
                                    // Soft match: Check if the required key is anywhere in the header
                                    if (strpos($cleanHeader, strtoupper($k)) !== false && !empty(trim($data[$index] ?? ''))) {
                                        // [FIX] Prevent "EMAIL ADDRESS" from being grabbed when searching for physical "ADDRESS"
                                        if (strtoupper($k) === 'ADDRESS' && strpos($cleanHeader, 'EMAIL') !== false) {
                                            continue;
                                        }
                                        return trim($data[$index]);
                                    }
                                }
                            }
                            return '';
                        };

                        $emp_id         = $getVal(['EMPLOYEE ID NUMBER', 'EMPLOYEE ID', 'EMP_ID', 'CODE']);
                        $first_name     = $getVal(['FIRST NAME']);
                        $middle_name    = $getVal(['MIDDLE NAME']);
                        $last_name      = $getVal(['LAST NAME']);
                        $full_name      = $getVal(['FULL NAME', 'EMPLOYEE NAME']);

                        $job_title      = $getVal(['POSITION / JOB TITLE', 'POSITION', 'JOB TITLE', 'ROLE']);
                        if (empty($job_title)) $job_title = 'Staff'; // fallback

                        $dept_raw       = $getVal(['DEPARTMENT', 'DEPT']);
                        $section_raw    = $getVal(['SECTION']);
                        $hire_raw       = $getVal(['DATE HIRED', 'HIRED', 'JOIN DATE']);
                        $birth_raw      = $getVal(['DATE OF BIRTH', 'BIRTH DATE', 'BDAY']);
                        $gender_raw     = $getVal(['GENDER', 'SEX']);
                        $contact_raw    = $getVal(['MOBILE NUMBER', 'CONTACT NUMBER', 'PHONE']);
                        $email          = strtolower($getVal(['PERSONAL EMAIL ADDRESS', 'EMAIL']));

                        $present_addr   = $getVal(['COMPLETE PRESENT ADDRESS', 'PRESENT ADDRESS', 'ADDRESS']);
                        if (empty($present_addr)) $present_addr = 'To be updated'; // fallback

                        $permanent_addr = $getVal(['COMPLETE PERMANENT ADDRESS', 'PERMANENT ADDRESS']);
                        $sss_raw        = $getVal(['SSS NUMBER', 'SSS']);
                        $pagibig_raw    = $getVal(['PAG-IBIG (HDMF) NUMBER', 'PAG-IBIG', 'HDMF']);
                        $phil_raw       = $getVal(['PHILHEALTH NUMBER', 'PHILHEALTH']);
                        $tin_raw        = $getVal(['TIN (TAX IDENTIFICATION NUMBER)', 'TIN']);
                        $emg_name       = ucwords(strtolower($getVal(['EMERGENCY CONTACT NAME', 'EMERGENCY CONTACT'])));
                        $emg_contact    = $getVal(['EMERGENCY CONTACT NUMBER']);
                        $emg_addr       = $getVal(['EMERGENCY CONTACT ADDRESS']);
                        $education      = $getVal(['EDUCATION ATTAINMENT', 'EDUCATION', 'EDUCATIONAL ATTAINMENT', 'DEGREE']);
                        $experience     = $getVal(['EXPERIENCE', 'WORK EXPERIENCE']);
                        $licenses       = $getVal(['LICENSES / CERTIFICATIONS', 'LICENSES', 'CERTIFICATIONS']);

                        if (!empty($gender_raw)) {
                            $g = strtolower($gender_raw);
                            if ($g === 'woman' || $g === 'female') $gender = 'Female';
                            elseif ($g === 'man' || $g === 'male') $gender = 'Male';
                        }

                        if (!empty($first_name) || !empty($last_name)) {
                            $first_name = ucwords(strtolower($first_name));
                            $last_name = ucwords(strtolower($last_name));
                            $middle_name = ucwords(strtolower($middle_name));
                        } else {
                            $full_name = trim($full_name);
                            if (strpos($full_name, ',') !== false) {
                                $parts = explode(',', $full_name);
                                $last_name = ucwords(strtolower(trim($parts[0] ?? '')));
                                $first_name = ucwords(strtolower(trim($parts[1] ?? '')));
                            } else {
                                $words = preg_split('/\s+/', trim($full_name));
                                if (count($words) > 1) {
                                    $first_name = ucwords(strtolower(array_shift($words)));
                                    $last_name = ucwords(strtolower(implode(' ', $words)));
                                } else {
                                    $last_name = ucwords(strtolower($full_name));
                                    $first_name = '';
                                }
                            }
                        }
                    } else {
                        // [TESP / STANDARD FORMAT]
                        $emp_id = trim($data[1] ?? '');

                        $full_name = trim($data[3] ?? '');
                        $parts = explode(',', $full_name);
                        if (count($parts) >= 2) {
                            $last_name = ucwords(strtolower(trim($parts[0])));
                            $first_name = ucwords(strtolower(trim($parts[1])));
                        } else {
                            $words = preg_split('/\s+/', trim($full_name));
                            if (count($words) > 1) {
                                $first_name = ucwords(strtolower(array_shift($words)));
                                $last_name = ucwords(strtolower(implode(' ', $words)));
                            } else {
                                $last_name = ucwords(strtolower($full_name));
                            }
                        }

                        $section_raw = $data[4] ?? '';
                        $contact_raw = $data[5] ?? '';
                        $birth_raw   = $data[6] ?? '';
                        $hire_raw    = $data[7] ?? '';
                        $sss_raw     = $data[8] ?? '';
                        $tin_raw     = $data[9] ?? '';
                        $pagibig_raw = $data[10] ?? '';
                        $phil_raw    = $data[11] ?? '';
                    }

                    // --- PROCESSING ---
                    $section = strtoupper(trim($section_raw));

                    // [FIX] Favor Form Dept over Section Map if provided
                    if (!empty($dept_raw)) {
                        $dStr = strtoupper(trim($dept_raw));
                        if (strpos($dStr, 'OP') !== false) $dept = 'OP';
                        elseif (strpos($dStr, 'SUBCONS') !== false) $dept = 'SUBCONS-OTHERS';
                        else $dept = array_key_exists($dStr, $deptMap) ? $dStr : (findDept($section, $deptMap) ?: $dStr);
                    } else {
                        $dept = findDept($section, $deptMap);
                    }

                    $birth_date = parseDate(trim($birth_raw));
                    $hire_date  = parseDate(trim($hire_raw));

                    // [SECURITY] Enforce Limits & Whitelist (Match Add/Edit Rules)
                    $emp_id     = substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', $emp_id), 0, 20);

                    // [FIX] Auto-Generate Employee ID for New Hires if left blank in the CSV
                    if ($emp_id === '') {
                        $emp_id = "NEW-" . date('ym') . "-" . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                    }

                    // [FIX] Prevent Database Crashes if names are missing
                    if (empty($first_name)) $first_name = "-";
                    if (empty($last_name)) $last_name = "Unknown Applicant";

                    $first_name = substr(preg_replace('/[^a-zA-Z0-9\s\-\.\(\)]/', '', $first_name), 0, 50);
                    $last_name  = substr(preg_replace('/[^a-zA-Z0-9\s\-\.\(\)]/', '', $last_name), 0, 50);
                    $job_title  = substr(preg_replace('/[^a-zA-Z0-9\s\-\.\,\(\)]/', '', $job_title), 0, 50);

                    $education  = substr(preg_replace('/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/', '', $education ?? ''), 0, 1000);
                    $experience = substr(preg_replace('/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/', '', $experience ?? ''), 0, 1000);
                    $licenses   = substr(preg_replace('/[^a-zA-Z0-9\s\.,\-\(\)\/\':]/', '', $licenses ?? ''), 0, 1000);

                    // Defaults
                    $photo  = "default.png";
                    $status = "Active";

                    // Target Agency Assignment
                    $actual_agency = $target_agency;
                    $empType = (stripos($actual_agency, 'TESP') !== false) ? 'TESP Direct' : 'Agency';

                    if ($emp_id != '') {
                        // [NEW] Check if ID exists
                        $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
                        $checkStmt->execute([$emp_id]);
                        $existingId = $checkStmt->fetchColumn();

                        $shouldUpdate = isset($_POST['update_existing']);

                        // [SECURITY] Require Admin/Manager for direct database insertion. HR goes to Approval Center.
                        $requiresApproval = !in_array($_SESSION['role'], ['ADMIN', 'MANAGER']);

                        try {
                            if ($existingId && $shouldUpdate) {
                                if ($requiresApproval) {
                                    // [WORKFLOW] Create an Edit Request for existing employee
                                    $updateData = [
                                        'first_name' => $first_name,
                                        'middle_name' => $middle_name,
                                        'last_name' => $last_name,
                                        'dept' => $dept,
                                        'section' => $section,
                                        'employment_type' => $empType,
                                        'agency_name' => $actual_agency,
                                        'job_title' => $job_title,
                                        'gender' => $gender,
                                        'birth_date' => $birth_date,
                                        'hire_date' => $hire_date,
                                        'contact_number' => trim($contact_raw),
                                        'present_address' => $present_addr,
                                        'permanent_address' => $permanent_addr,
                                        'sss_no' => trim($sss_raw),
                                        'tin_no' => trim($tin_raw),
                                        'pagibig_no' => trim($pagibig_raw),
                                        'philhealth_no' => trim($phil_raw),
                                        'email' => $email,
                                        'emergency_name' => $emg_name,
                                        'emergency_contact' => $emg_contact,
                                        'emergency_address' => $emg_addr,
                                        'education' => $education,
                                        'experience' => $experience,
                                        'licenses' => $licenses,
                                        'request_note' => 'Bulk Import Update'
                                    ];
                                    $payload = json_encode($updateData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                                    if ($payload === false) {
                                        error_log("Failed to encode request payload for emp_id $emp_id: " . json_last_error_msg());
                                        continue;
                                    }
                                    $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'EDIT_PROFILE', ?, ?)")->execute([$_SESSION['user_id'], $existingId, $payload]);
                                    $updated_count++;
                                } else {
                                    // before updating, capture original state for undo
                                    $origStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                                    $origStmt->execute([$existingId]);
                                    $originalRow = $origStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($originalRow) {
                                        $backupStmt = $pdo->prepare("INSERT INTO import_rollbacks (employee_id, import_batch, old_data) VALUES (?, ?, ?)");
                                        $backupStmt->execute([$existingId, $batch_id, json_encode($originalRow)]);
                                    }

                                    // UPDATE EXISTING RECORD, also tag with current batch
                                    $sql = "UPDATE employees SET 
                                        first_name=?, middle_name=?, last_name=?, dept=?, section=?, 
                                        employment_type=?, agency_name=?, job_title=?, 
                                        gender=?, birth_date=?, hire_date=?, contact_number=?, 
                                        present_address=?, permanent_address=?, sss_no=?, tin_no=?, pagibig_no=?, philhealth_no=?, email=?,
                                        emergency_name=?, emergency_contact=?, emergency_address=?,
                                        education=?, experience=?, licenses=?,
                                        import_batch=?, updated_at=NOW()
                                        WHERE id=?";
                                    $stmt = $pdo->prepare($sql);
                                    $stmt->execute([
                                        $first_name,
                                        $middle_name,
                                        $last_name,
                                        $dept,
                                        $section,
                                        $empType,
                                        $actual_agency,
                                        $job_title,
                                        $gender,
                                        $birth_date,
                                        $hire_date,
                                        trim($contact_raw),
                                        $present_addr,
                                        $permanent_addr,
                                        trim($sss_raw),
                                        trim($tin_raw),
                                        trim($pagibig_raw),
                                        trim($phil_raw),
                                        $email,
                                        $emg_name,
                                        $emg_contact,
                                        $emg_addr,
                                        $education,
                                        $experience,
                                        $licenses,
                                        $batch_id,
                                        $existingId
                                    ]);
                                    $updated_count++;
                                }
                            } elseif (!$existingId) {
                                if ($requiresApproval) {
                                    // [WORKFLOW] Create an Add Request for a new employee
                                    $insertData = [
                                        'emp_id' => $emp_id,
                                        'first_name' => $first_name,
                                        'middle_name' => $middle_name,
                                        'last_name' => $last_name,
                                        'job_title' => $job_title,
                                        'system_role' => 'Staff',
                                        'dept' => $dept,
                                        'section' => $section,
                                        'employment_type' => $empType,
                                        'agency_name' => $actual_agency,
                                        'company_name' => 'TES Philippines',
                                        'previous_company' => '',
                                        'hire_date' => $hire_date,
                                        'gender' => $gender,
                                        'birth_date' => $birth_date,
                                        'contact_number' => trim($contact_raw),
                                        'email' => $email,
                                        'present_address' => $present_addr,
                                        'permanent_address' => $permanent_addr,
                                        'sss_no' => trim($sss_raw),
                                        'tin_no' => trim($tin_raw),
                                        'pagibig_no' => trim($pagibig_raw),
                                        'philhealth_no' => trim($phil_raw),
                                        'emergency_name' => $emg_name,
                                        'emergency_contact' => $emg_contact,
                                        'emergency_address' => $emg_addr,
                                        'education' => $education,
                                        'experience' => $experience,
                                        'skills' => '',
                                        'licenses' => $licenses,
                                        'status' => $status,
                                        'avatar_path' => $photo,
                                        'request_note' => 'Bulk Import New Hire'
                                    ];
                                    $payload = json_encode($insertData, JSON_UNESCAPED_UNICODE);
                                    $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'ADD_EMPLOYEE', 0, ?)")->execute([$_SESSION['user_id'], $payload]);
                                    $success_count++;
                                } else {
                                    // INSERT NEW RECORD
                                    $sql = "INSERT INTO employees 
                                    (emp_id, first_name, middle_name, last_name, dept, section, 
                                    employment_type, agency_name, job_title, status, 
                                    gender, birth_date, hire_date, contact_number, 
                                    present_address, permanent_address, avatar_path, import_batch,
                                    sss_no, tin_no, pagibig_no, philhealth_no, email,
                                    emergency_name, emergency_contact, emergency_address,
                                    education, experience, licenses) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                                    $stmt = $pdo->prepare($sql);
                                    $stmt->execute([
                                        $emp_id,
                                        $first_name,
                                        $middle_name,
                                        $last_name,
                                        $dept,
                                        $section,
                                        $empType,
                                        $actual_agency,
                                        $job_title,
                                        $status,
                                        $gender,
                                        $birth_date,
                                        $hire_date,
                                        trim($contact_raw),
                                        $present_addr,
                                        $permanent_addr,
                                        $photo,
                                        $batch_id,
                                        trim($sss_raw),
                                        trim($tin_raw),
                                        trim($pagibig_raw),
                                        trim($phil_raw),
                                        $email,
                                        $emg_name,
                                        $emg_contact,
                                        $emg_addr,
                                        $education,
                                        $experience,
                                        $licenses
                                    ]);
                                    $success_count++;
                                }
                            }
                        } catch (Exception $e) {
                            // Log error but continue processing
                            $errCode = $e instanceof PDOException ? $e->errorInfo[1] ?? 0 : 0;
                            // 1062 = MySQL duplicate entry
                            if ($errCode !== 1062) {
                                error_log("Import error for emp_id $emp_id: " . $e->getMessage());
                            }
                        }
                    }
                }
                fclose($handle);
            }

            if ($success_count > 0 || $updated_count > 0) {
                if (isset($requiresApproval) && $requiresApproval) {
                    $logger->log($_SESSION['user_id'], 'IMPORT_REQUEST', "Requested import of $success_count new, $updated_count updates (Format: $format)");
                    $msg = "📝 Success! $success_count new additions and $updated_count updates were sent to the Admin Approval Center.";
                } else {
                    $logger->log($_SESSION['user_id'], 'IMPORT_SUCCESS', "Imported $success_count, Updated $updated_count (Format: $format)");
                    $msg = "✅ Success! Added $success_count new, Updated $updated_count existing employees.";
                }
            } else {
                $error = "No valid records found or all were duplicates.";
            }
        }
    }
}

// Fetch history for the table below
$history = $pdo->query("SELECT import_batch, MAX(agency_name) as agency_name, COUNT(*) as count, MAX(created_at) as time FROM employees WHERE import_batch IS NOT NULL GROUP BY import_batch ORDER BY time DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Import Employees</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <style>
        .format-box {
            display: none;
        }

        /* Custom scrollbar to make horizontal scrolling obvious and professional */
        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }
    </style>
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-file-spreadsheet"></i> Bulk Import</span>
            </div>
        </div>
    </nav>

    <div class="container">

        <div id="instr_tesp" class="alert alert-info shadow-sm mb-4 format-box">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Standard Format (TESP / GUNJIN)</h6>
                <a href="download_template.php?type=TESP" class="btn btn-sm btn-info fw-bold text-dark"><i class="bi bi-download"></i> Download Template</a>
            </div>
            <div class="table-responsive scroll-horizontal rounded border shadow-sm">
                <table class="table table-sm small table-bordered mb-0" style="white-space: nowrap;">
                    <thead class="table-light">
                        <tr>
                            <th>NO.</th>
                            <th>EMPLOYEE CODE</th>
                            <th>PICTURE</th>
                            <th>NAME</th>
                            <th>SECTION</th>
                            <th>CONTACT DETAILS:</th>
                            <th>BIRTHDAY</th>
                            <th>DATE OF HIRED</th>
                            <th>SSS</th>
                            <th>TIN</th>
                            <th>PAG-IBIG</th>
                            <th>PHILHEALTH</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted fst-italic">
                            <td>1</td>
                            <td>TESP-001</td>
                            <td></td>
                            <td>Doe, John</td>
                            <td>SQP</td>
                            <td>09123456789</td>
                            <td>1990-01-01</td>
                            <td>2023-01-01</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="instr_unli" class="alert alert-warning shadow-sm mb-4 format-box">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">UnliSolutions Format</h6>
                <a href="download_template.php?type=UNLISOLUTIONS" class="btn btn-sm btn-warning fw-bold text-dark"><i class="bi bi-download"></i> Download Template</a>
            </div>
            <div class="table-responsive scroll-horizontal rounded border shadow-sm">
                <table class="table table-sm small table-bordered mb-0" style="white-space: nowrap;">
                    <thead class="table-light">
                        <tr>
                            <th>NO</th>
                            <th>ID</th>
                            <th>PIC</th>
                            <th>NAME</th>
                            <th>POSITION</th>
                            <th>SECTION</th>
                            <th>CONTACT</th>
                            <th>BDAY</th>
                            <th>HIRED</th>
                            <th>SSS</th>
                            <th>TIN</th>
                            <th>PAGIBIG</th>
                            <th>PHILHEALTH</th>
                            <th>ADDRESS</th>
                            <th>EMAIL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted fst-italic">
                            <td>1</td>
                            <td>UNLI-001</td>
                            <td></td>
                            <td>Doe, John</td>
                            <td>Staff</td>
                            <td>ADMIN</td>
                            <td>09123456789</td>
                            <td>1990-01-01</td>
                            <td>2023-01-01</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td>john@example.com</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="instr_jora" class="alert alert-success shadow-sm mb-4 format-box">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Joratech Format (Special)</h6>
                <a href="download_template.php?type=JORATECH" class="btn btn-sm btn-success fw-bold text-white"><i class="bi bi-download"></i> Download Template</a>
            </div>
            <div class="table-responsive scroll-horizontal rounded border shadow-sm">
                <table class="table table-sm small table-bordered mb-0" style="white-space: nowrap;">
                    <thead class="table-light">
                        <tr>
                            <th>NO</th>
                            <th>SECTION</th>
                            <th>POSITION</th>
                            <th>DATE HIRED</th>
                            <th>NUM</th>
                            <th>PIC</th>
                            <th>NAME</th>
                            <th>CODE</th>
                            <th>CONTRACT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-muted fst-italic">
                            <td>1</td>
                            <td>MAINTENANCE</td>
                            <td>Technician</td>
                            <td>2023-01-15</td>
                            <td></td>
                            <td></td>
                            <td>Doe, John</td>
                            <td>JOR-001</td>
                            <td>Project</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="instr_custom" class="alert alert-secondary shadow-sm mb-4 format-box">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">Custom Format (Microsoft/Google Forms)</h6>
                <a href="download_template.php?type=CUSTOM" class="btn btn-sm btn-dark fw-bold text-white"><i class="bi bi-file-earmark-excel"></i> Download Excel Template</a>
            </div>
            <p class="small mb-2">The system will perfectly map 25 fields. Ensure these standard headers are present in Row 1:</p>
            <div class="table-responsive scroll-horizontal rounded border shadow-sm pb-1">
                <table class="table table-sm small table-bordered mb-0" style="white-space: nowrap;">
                    <thead class="table-secondary text-center align-middle">
                        <tr class="table-dark text-white">
                            <th colspan="4">Employee Name</th>
                            <th colspan="3">Demographics</th>
                            <th colspan="4">Contact & Address</th>
                            <th colspan="4">Government IDs</th>
                            <th colspan="3">Emergency Contact</th>
                            <th colspan="4">Job Details</th>
                            <th colspan="3">Qualifications</th>
                        </tr>
                        <tr>
                            <th>First Name</th>
                            <th>Middle Name</th>
                            <th>Last Name</th>
                            <th>Suffix</th>
                            <th>Date of Birth</th>
                            <th>Gender</th>
                            <th>Civil Status</th>
                            <th>Mobile Number</th>
                            <th>Personal Email Address</th>
                            <th>Complete Present Address</th>
                            <th>Complete Permanent Address</th>
                            <th>SSS Number</th>
                            <th>Pag-IBIG (HDMF) Number</th>
                            <th>PhilHealth Number</th>
                            <th>TIN (Tax Identification Number)</th>
                            <th>Emergency Contact Name</th>
                            <th>Emergency Contact Number</th>
                            <th>Emergency Contact Address</th>
                            <th>Employee ID Number</th>
                            <th>Department</th>
                            <th>Position / Job Title</th>
                            <th>Date Hired</th>
                            <th>Education Attainment</th>
                            <th>JobExperience</th>
                            <th>Licenses / Certifications</th>
                        </tr>
                    </thead>
                    <tbody class="text-center">
                        <tr class="text-muted fst-italic">
                            <td>Juan</td>
                            <td>Dela</td>
                            <td>Cruz</td>
                            <td></td>
                            <td>1/15/1990</td>
                            <td>Man</td>
                            <td>Single</td>
                            <td>09123456789</td>
                            <td>juan.delacruz@example.com</td>
                            <td>123 Main St, Quezon City</td>
                            <td>Same as present</td>
                            <td>12-3456789-0</td>
                            <td>1234-5678-9012</td>
                            <td>12-345678901-2</td>
                            <td>123-456-789-000</td>
                            <td>Maria Cruz</td>
                            <td>09987654321</td>
                            <td>123 Main St, Quezon City</td>
                            <td>CUST-001</td>
                            <td>ADMIN</td>
                            <td>Staff</td>
                            <td>5/1/2024</td>
                            <td>BS Computer Science</td>
                            <td>Jolibee Crew</td>
                            <td>Civil Service Professional</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Bulk Import</h5>
            </div>
            <div class="card-body">

                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Select CSV Layout (Format)</label>
                            <select name="agency_select" id="agency_select" class="form-select border-success" onchange="toggleFormat()" required>
                                <option value="">-- Choose Format --</option>
                                <option value="STANDARD">Standard Format (TESP / Others)</option>
                                <option value="UNLISOLUTIONS">UnliSolutions Format</option>
                                <option value="JORATECH">Joratech Format</option>
                                <option value="CUSTOM">Custom Form (Detect Headers)</option>
                            </select>
                        </div>

                        <div class="col-md-6" id="target_agency_container">
                            <label class="form-label fw-bold text-primary">Assign to Agency <span class="text-danger">*</span></label>
                            <select name="target_agency" id="target_agency" class="form-select border-primary" required>
                                <option value="">-- Select Agency --</option>
                                <?php foreach ($agencies as $a): ?>
                                    <option value="<?php echo htmlspecialchars($a); ?>"><?php echo htmlspecialchars($a); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small">All employees in this import will be assigned to this agency.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Upload CSV</label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="update_existing" id="updateCheck" value="1">
                                <label class="form-check-label text-primary fw-bold" for="updateCheck">
                                    <i class="bi bi-arrow-repeat"></i> Update existing employees?
                                </label>
                                <div class="form-text small">If checked, employees with matching IDs will be updated with the new info from the CSV. If unchecked, they will be skipped.</div>
                            </div>
                        </div>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <button type="submit" class="btn btn-success btn-lg">Upload & Import</button>
                        <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if (count($history) > 0): ?>
            <div class="card shadow border-danger">
                <div class="card-header bg-danger text-white">
                    <h6 class="mb-0">Undo Recent Imports</h6>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Agency</th>
                                <th>Count</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h):
                                // Calculate Time Remaining for Undo
                                $importTime = strtotime($h['time']);
                                $elapsed = time() - $importTime;
                                $limit = 7 * 60 * 60; // 7 hours in seconds
                                $canUndo = $elapsed < $limit;
                                $remMinutes = ceil(($limit - $elapsed) / 60);
                                $remHours = floor($remMinutes / 60);
                                $remMins = $remMinutes % 60;
                                $timeLeftStr = $remHours > 0 ? "{$remHours}h {$remMins}m" : "{$remMins}m";
                            ?>
                                <tr>
                                    <td><?php echo date('M d, h:i A', $importTime); ?></td>
                                    <td><?php echo htmlspecialchars($h['agency_name']); ?></td>
                                    <td><?php echo $h['count']; ?></td>
                                    <td>
                                        <?php if ($canUndo && in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])): ?>
                                            <!-- ACTIVE BUTTON -->
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="undo_batch" value="<?php echo $h['import_batch']; ?>">
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmUndo(this)">Undo</button>
                                            </form>
                                            <div class="text-success small fw-bold mt-1">
                                                <i class="bi bi-clock-history"></i> <?php echo $timeLeftStr; ?> left
                                            </div>
                                        <?php else: ?>
                                            <!-- LOCKED BUTTON -->
                                            <button class="btn btn-sm btn-secondary disabled" disabled>
                                                <i class="bi bi-lock-fill"></i> Locked
                                            </button>
                                            <div class="text-muted small mt-1">Time limit exceeded</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function toggleFormat() {
            const format = document.getElementById('agency_select').value;
            const targetAgencyBox = document.getElementById('target_agency_container');
            const targetAgencySelect = document.getElementById('target_agency');

            document.querySelectorAll('.format-box').forEach(el => el.style.display = 'none');

            if (format === 'JORATECH') {
                document.getElementById('instr_jora').style.display = 'block';
            } else if (format === 'UNLISOLUTIONS') {
                document.getElementById('instr_unli').style.display = 'block';
            } else if (format === 'CUSTOM') {
                document.getElementById('instr_custom').style.display = 'block';
            } else if (format !== '') {
                document.getElementById('instr_tesp').style.display = 'block';
            }
        }

        // SweetAlert2 Logic
        document.getElementById('importForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const selectEl = document.getElementById('agency_select');
            const formatText = selectEl.options[selectEl.selectedIndex].text;
            const fileInput = document.querySelector('input[name="csv_file"]');
            const file = fileInput.files[0];

            if (!file) {
                form.submit();
                return;
            }

            const reader = new FileReader();
            const blob = file; // Read entire file to show all rows

            reader.onload = function(e) {
                let text = e.target.result;

                // [FIX] Neutralize newlines inside quoted strings so the table doesn't break!
                let inQuote = false;
                let cleanText = "";
                for (let i = 0; i < text.length; i++) {
                    let char = text[i];
                    if (char === '"') inQuote = !inQuote;
                    if (inQuote && (char === '\n' || char === '\r')) {
                        cleanText += ' ';
                    } else {
                        cleanText += char;
                    }
                }

                const rows = cleanText.split(/\r\n|\n|\r/).filter(r => r.trim() !== '');
                const previewRows = rows; // Show all rows
                const employeeCount = Math.max(0, rows.length - 1); // Exclude header row

                let tableHtml = '<div class="scroll-horizontal scroll-vertical" style="text-align:left;"><table class="table table-sm table-bordered table-striped" style="font-size:0.75rem; white-space: nowrap;">';

                const firstLine = rows[0] || '';
                const delimComma = (firstLine.match(/,/g) || []).length;
                const delimSemi = (firstLine.match(/;/g) || []).length;
                const delimTab = (firstLine.match(/\t/g) || []).length;

                let delimiter = ',';
                if (delimSemi > delimComma && delimSemi > delimTab) delimiter = ';';
                if (delimTab > delimComma && delimTab > delimSemi) delimiter = '\t';

                const splitRegex = (delimiter === '\t') ? /\t/ : new RegExp(`${delimiter}(?=(?:(?:[^"]*"){2})*[^"]*$)`);

                previewRows.forEach((row, index) => {
                    const cols = row.split(splitRegex);
                    tableHtml += '<tr>';
                    cols.forEach(col => {
                        let clean = col.trim().replace(/^"|"$/g, ''); // Remove quotes
                        tableHtml += (index === 0) ? `<th class="table-secondary sticky-top" style="z-index: 1;">${clean}</th>` : `<td>${clean}</td>`;
                    });
                    tableHtml += '</tr>';
                });
                tableHtml += '</table></div>';

                Swal.fire({
                    title: 'Confirm Import',
                    html: `<p>Importing via <strong>${formatText}</strong>. Check the preview below:</p>${tableHtml}<div class="alert alert-success mt-3 py-2 fw-bold text-center border-success"><i class="bi bi-people-fill"></i> Total Employees to Import: ${employeeCount}</div>`,
                    icon: 'info',
                    width: '800px',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    confirmButtonText: 'Yes, Import Data'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Disable button and show spinner
                        const submitBtn = form.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span> Processing...';
                        }
                        // Show un-closable loading alert
                        Swal.fire({
                            title: 'Importing Data...',
                            html: 'Please wait while we process the records.<br><br><span class="text-danger fw-bold small">Do not close or refresh this window!</span>',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        form.submit();
                    }
                });
            };

            reader.readAsText(blob);
        });

        function confirmUndo(btn) {
            Swal.fire({
                title: 'Undo Import?',
                text: "This will delete all employees from this batch. This cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Reverting Import...',
                        html: 'Please wait while we remove the records.<br><br><span class="text-danger fw-bold small">Do not close or refresh this window!</span>',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    btn.form.submit();
                }
            });
        }

        <?php if ($msg): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: <?php echo json_encode($msg); ?>,
                confirmButtonColor: '#198754'
            });
        <?php endif; ?>
        <?php if ($error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?php echo json_encode($error); ?>,
                confirmButtonColor: '#dc3545'
            });
        <?php endif; ?>
    </script>
    <script src="dark_mode.js"></script>
</body>

</html>