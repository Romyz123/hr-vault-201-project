<?php
// ======================================================
// [FILE] public/export_files.php
// [STATUS] FINAL: No External Libraries (MHI Safe)
// ======================================================

// [FIX] Start buffering immediately to catch any whitespace/BOM from includes
ob_start();
require '../src/Logger.php';
require '../config/db.php';
require '../src/Security.php';
require '../src/FileService.php';
require '../src/Validator.php';
session_start();

// [DOWNLOAD HANDLER] Stream split export parts (GET) without requiring CSRF
if (isset($_GET['download_part'])) {
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER', 'HR'])) {
        http_response_code(403);
        exit;
    }

    $requested = basename($_GET['download_part']);
    // Allow only safe export names (no quotes/newlines/etc.) to prevent header injection
    if (!preg_match('/^HR_Export_[A-Za-z0-9_-]+_Part\\d+\\.zip$/', $requested)) {
        http_response_code(404);
        exit;
    }

    $exportDir = __DIR__ . '/../backups/exports';
    $exportDirReal = realpath($exportDir);
    $filePath = realpath($exportDir . DIRECTORY_SEPARATOR . $requested);

    $exportDirPrefix = rtrim($exportDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!$exportDirReal || !$filePath || strncmp($filePath, $exportDirPrefix, strlen($exportDirPrefix)) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit;
    }

    while (ob_get_level()) ob_end_clean();
    if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');

    ignore_user_abort(true);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $requested . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// [FIX] Load Config to ensure VAULT_PATH is available
$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

// 2. SETTINGS
ini_set('memory_limit', '2G'); // [FIX] Allow large ZIP generation with bounded memory
set_time_limit(0); // [FIX] Unlimited execution time
ignore_user_abort(true); // [FIX] Continue building ZIP even if browser connection drops
// [SECURITY] Verify CSRF Token
$security = new Security($pdo);
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    die("Security Error: Invalid CSRF Token.");
}

// 3. GET INPUTS
$dept     = trim($_POST['dept'] ?? '');
$section  = trim($_POST['section'] ?? '');
$agency   = trim($_POST['employment_type'] ?? '');
$status   = trim($_POST['status'] ?? '');
$category = trim($_POST['category'] ?? '');
$search   = Validator::sanitizeSearch($_POST['search'] ?? '');
$zipPassword = trim($_POST['zip_password'] ?? '');

// 4. VALIDATION
if (strlen($dept) > 50 || strlen($section) > 100 || strlen($agency) > 50 || strlen($status) > 20 || strlen($category) > 100 || strlen($search) > 100 || strlen($zipPassword) > 50) {
    $_SESSION['error'] = "Export Failed: One or more input parameters exceed maximum length limits.";
    header("Location: index.php");
    exit;
}
if ($dept === 'ALL') {
    $_SESSION['error'] = "Export Failed: Entire database export is disabled. Please filter by a specific Department.";
    header("Location: index.php");
    exit;
}
if (empty($dept) && empty($search)) {
    $_SESSION['error'] = "Export Failed: Select a Department OR type a Search Name.";
    header("Location: index.php");
    exit;
}

// ======================================================
// STEP 1: FETCH DATA
// ======================================================
$sql = "SELECT * FROM employees e WHERE 1=1";
$params = [];

// Apply Filters
if (!empty($search)) {
    $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $sql .= " AND (e.emp_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
        $t = "%{$term}%";
        array_push($params, $t, $t, $t);
    }
}
if (!empty($dept)) {
    $sql .= " AND e.dept = ?";
    $params[] = $dept;
}
if (!empty($section)) {
    $sql .= " AND e.section = ?";
    $params[] = $section;
}
if (!empty($agency)) {
    $sql .= " AND (e.employment_type = ? OR e.agency_name = ?)";
    $params[] = $agency;
    $params[] = $agency;
}
if (!empty($status)) {
    $sql .= " AND e.status = ?";
    $params[] = $status;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($employees) === 0) {
    $_SESSION['error'] = "No employees found matching your criteria.";
    header("Location: index.php");
    exit;
}

// Fetch Documents
$empIds = array_column($employees, 'emp_id');
$docsByEmp = [];
if (!empty($empIds)) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $docParams = $empIds;
    $catSql = "";
    if (!empty($category)) {
        $catSql = " AND category = ?";
        $docParams[] = $category;
    }

    $docStmt = $pdo->prepare("SELECT * FROM documents WHERE employee_id IN ($placeholders) $catSql");
    $docStmt->execute($docParams);
    $allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allDocs as $d) {
        $docsByEmp[$d['employee_id']][] = $d;
    }
}

// ======================================================
// STEP 2: CREATE ZIP
// ======================================================

// [MULTI-VOLUME LOGIC] Fetch DB Limit
$maxSizeGB = 1.9;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_max_size_gb'");
    if ($val = $stmt->fetchColumn()) $maxSizeGB = (float)$val;
} catch (Exception $e) {
}
$maxSizeBytes = $maxSizeGB * 1024 * 1024 * 1024;

$exportDir = __DIR__ . '/../backups/exports';
if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);

// Cleanup old exports (older than 24 hours) to save server space
foreach (glob($exportDir . '/*.zip') as $oldFile) {
    if (filemtime($oldFile) < time() - 86400) @unlink($oldFile);
}

$baseFilename = "HR_Export_" . date('Y-m-d_Hi');
$partNumber = 1;
$currentBytes = 0;
$generatedZips = [];
$pendingUnlink = [];
$zip = null;

$cleanupExportFiles = function ($message) use (&$generatedZips, &$pendingUnlink) {
    foreach ($generatedZips as $f) {
        if (file_exists($f)) @unlink($f);
    }
    foreach ($pendingUnlink as $f) {
        if (file_exists($f)) @unlink($f);
    }
    exit($message);
};

$startNewZip = function () use (&$zip, &$generatedZips, $exportDir, $baseFilename, &$partNumber, &$currentBytes, &$pendingUnlink, $cleanupExportFiles) {
    if ($zip !== null) {
        $zip->close();
        foreach ($pendingUnlink as $f) @unlink($f);
        $pendingUnlink = [];
    }
    $path = $exportDir . DIRECTORY_SEPARATOR . $baseFilename . "_Part{$partNumber}.zip";
    $generatedZips[] = $path;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        $cleanupExportFiles("Server Error: Could not create split ZIP file.");
    }
    $currentBytes = 0;
};

$startNewZip();

function cleanName($str)
{
    // Remove invalid chars, then TRIM spaces and DOTS from start/end (Windows Fix)
    $clean = preg_replace('/[^a-zA-Z0-9 \-_,\.]/', '', $str ?? '');
    return trim($clean, " .");
}
function h($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES);
}

// Helper to get image as base64
function getBase64Image($path)
{
    if (file_exists($path)) {
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
    return ''; // Return empty if missing
}

$filesAdded = 0;

$fileService = new FileService($vaultPath);

foreach ($employees as $emp) {
    $empId = $emp['emp_id'];

    // [SMART FOLDER NAMING] Fix Redundancy
    $sectDisplay = $emp['section'];
    if (!in_array(strtoupper($emp['dept']), ['ADMIN', 'SQP'])) {
        $sectDisplay = ''; // Hide section for OCS, PSS, etc.
    }

    $folderName = cleanName($emp['last_name']) . ", " . cleanName($emp['first_name']) . " - " . cleanName($emp['emp_id']) . " - " . cleanName($emp['dept']);
    if (!empty($sectDisplay)) $folderName .= " - " . cleanName($sectDisplay);

    // --- A. PREPARE AVATAR (Embedded) ---
    $avatarBasePath = __DIR__ . '/uploads/avatars/';
    $avatarFile = $avatarBasePath . basename($emp['avatar_path'] ?: 'default.png');
    $avatarRealPath = realpath($avatarFile);
    $avatarData = '';
    if ($avatarRealPath && strpos($avatarRealPath, realpath($avatarBasePath)) === 0) {
        $avatarData = getBase64Image($avatarRealPath);
    }
    if (!$avatarData) $avatarData = 'https://via.placeholder.com/150'; // Fallback
    // --- B. GENERATE HTML (Matches "Print Employee" Design) ---
    // We use inline CSS so it looks perfect offline
    $htmlContent = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Employee Profile</title>
        <style>
            .print-note { display: block; background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; margin: 20px; text-align: center; font-family: sans-serif; border-radius: 5px; }
            body { font-family: "Arial", sans-serif; background: #555; margin: 0; padding: 40px; }
            .page { background: white; width: 210mm; min-height: 297mm; margin: 0 auto; padding: 40px; box-sizing: border-box; box-shadow: 0 0 15px rgba(0,0,0,0.3); }
            .header { display: flex; border-bottom: 3px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
            .avatar { width: 120px; height: 120px; object-fit: cover; border: 1px solid #ccc; margin-right: 25px; }
            .header-info { flex-grow: 1; }
            .header-info h1 { margin: 0 0 5px 0; font-size: 28px; text-transform: uppercase; color: #000; }
            .header-info h3 { margin: 0 0 10px 0; font-size: 16px; font-weight: normal; color: #555; }
            .tags span { background: #eee; padding: 4px 8px; font-size: 11px; font-weight: bold; margin-right: 5px; color: #333; }
            .company-info { text-align: right; font-size: 12px; color: #444; line-height: 1.4; }
            .company-info strong { font-size: 14px; color: #000; text-transform: uppercase; }
            
            .section-title { font-size: 12px; font-weight: bold; color: #555; text-transform: uppercase; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 25px; margin-bottom: 15px; }
            
            .grid { display: table; width: 100%; border-spacing: 0 10px; }
            .row { display: table-row; }
            .cell { display: table-cell; width: 50%; vertical-align: top; padding-right: 20px; }
            .cell-full { display: table-cell; width: 100%; vertical-align: top; }
            
            .label { display: block; font-size: 10px; color: #888; text-transform: uppercase; margin-bottom: 2px; }
            .value { display: block; font-size: 13px; font-weight: bold; color: #000; border-bottom: 1px dotted #eee; padding-bottom: 2px; }
            
            @media print {
                .print-note { display: none !important; }
                body { background: none; padding: 0; }
                .page { box-shadow: none; margin: 0; width: 100%; }
            }
        </style>
    </head>
    <body>
        <div class="print-note">
            <strong>How to Print:</strong> Use your browser\'s Print function (Ctrl+P or Cmd+P) and select "Save as PDF" to create a PDF file.
        </div>
        <div class="page">
            <div class="header">
                <img src="' . $avatarData . '" class="avatar">
                <div class="header-info">
                    <h1>' . h($emp['last_name']) . ', ' . h($emp['first_name']) . '</h1>
                    <h3>' . h($emp['job_title']) . '</h3>
                    <div class="tags">
                        <span>' . h($emp['emp_id']) . '</span>
                        <span>' . h($emp['dept']) . '</span>
                        <span>' . h($emp['status']) . '</span>
                    </div>
                </div>
                <div class="company-info">
                    <strong>TES PHILIPPINES</strong><br>
                    Human Resources Department<br>
                    201 Employee File
                </div>
            </div>

            <div class="section-title">PERSONAL INFORMATION</div>
            <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">Date of Birth</span><span class="value">' . h($emp['birth_date']) . '</span></div>
                    <div class="cell"><span class="label">Gender</span><span class="value">' . h($emp['gender']) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">Contact Number</span><span class="value">' . h($emp['contact_number']) . '</span></div>
                    <div class="cell"><span class="label">Email</span><span class="value">' . h($emp['email']) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell-full"><span class="label">Present Address</span><span class="value">' . h($emp['present_address']) . '</span></div>
                </div>
            </div>

            <div class="section-title">EMPLOYMENT DETAILS</div>
            <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">Employment Type</span><span class="value">' . h($emp['employment_type']) . '</span></div>
                    <div class="cell"><span class="label">Agency</span><span class="value">' . h($emp['agency_name']) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">Date Hired</span><span class="value">' . h($emp['hire_date']) . '</span></div>
                    <div class="cell"><span class="label">Section</span><span class="value">' . h($emp['section']) . '</span></div>
                </div>
            </div>

            <div class="section-title">GOVERNMENT CONTRIBUTIONS</div>
            <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">SSS Number</span><span class="value">' . h($emp['sss_no']) . '</span></div>
                    <div class="cell"><span class="label">TIN Number</span><span class="value">' . h($emp['tin_no']) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">PhilHealth</span><span class="value">' . h($emp['philhealth_no']) . '</span></div>
                    <div class="cell"><span class="label">Pag-IBIG</span><span class="value">' . h($emp['pagibig_no']) . '</span></div>
                </div>
            </div>

            <div class="section-title">IN CASE OF EMERGENCY</div>
             <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">Name</span><span class="value">' . h($emp['emergency_name']) . '</span></div>
                    <div class="cell"><span class="label">Contact</span><span class="value">' . h($emp['emergency_contact']) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell-full"><span class="label">Address</span><span class="value">' . h($emp['emergency_address']) . '</span></div>
                </div>
            </div>

            <div class="section-title">QUALIFICATIONS & EDUCATIONAL BACKGROUND</div>
            <div class="grid">
                <div class="row">
                    <div class="cell-full"><span class="label">Education</span><span class="value">' . nl2br(h($emp['education'] ?? '')) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell-full"><span class="label">Experience</span><span class="value">' . nl2br(h($emp['experience'] ?? '')) . '</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">Skills</span><span class="value">' . nl2br(h($emp['skills'] ?? '')) . '</span></div>
                    <div class="cell"><span class="label">Licenses / Certifications</span><span class="value">' . nl2br(h($emp['licenses'] ?? '')) . '</span></div>
                </div>
            </div>

        </div>
    </body>
    </html>';

    // Add HTML Profile to ZIP
    $htmlSize = strlen($htmlContent);
    if ($currentBytes > 0 && ($currentBytes + $htmlSize > $maxSizeBytes)) {
        $partNumber++;
        $startNewZip();
    }
    $profilePath = $folderName . "/Employee_Profile.html";
    /** @var ZipArchive $zip */
    $zip->addFromString($profilePath, $htmlContent);
    if ($zipPassword) {
        $zip->setEncryptionName($profilePath, ZipArchive::EM_AES_256, $zipPassword);
    }
    $currentBytes += $htmlSize;
    $filesAdded++; // Count this as a "file" so empty folders still export

    // --- C. ADD DOCUMENTS ---
    if (isset($docsByEmp[$empId])) {
        foreach ($docsByEmp[$empId] as $doc) {
            $docPathInZip = $folderName . "/" . $doc['original_name'];

            // [FIX] Decrypt content
            $content = $fileService->getFileContent($doc['file_path']);

            // Fallback for unencrypted Disciplinary files
            if ($content === false && file_exists(__DIR__ . '/uploads/' . $doc['file_path'])) {
                $content = file_get_contents(__DIR__ . '/uploads/' . $doc['file_path']);
            }

            if ($content !== false) {
                $docSize = strlen($content);
                if ($currentBytes > 0 && ($currentBytes + $docSize > $maxSizeBytes)) {
                    $partNumber++;
                    $startNewZip();
                }

                // [FIX] Create temp file with error handling
                $tmpDoc = tempnam(sys_get_temp_dir(), 'exp_doc_');
                if ($tmpDoc === false) {
                    error_log("Failed to create temp file for export document: " . $doc['original_name']);
                    continue;
                }

                $written = file_put_contents($tmpDoc, $content);
                if ($written === false || $written !== $docSize) {
                    @unlink($tmpDoc);
                    error_log("Failed to write export document to temp file: " . $doc['original_name']);
                    continue;
                }

                $pendingUnlink[] = $tmpDoc;

                /** @var ZipArchive $zip */
                $zip->addFile($tmpDoc, $docPathInZip);
                if ($zipPassword) {
                    if (!$zip->setEncryptionName($docPathInZip, ZipArchive::EM_AES_256, $zipPassword)) {
                        error_log("Failed to apply ZIP encryption to document: " . $doc['original_name']);
                    }
                }
                $currentBytes += $docSize;
            }
        }
    }
}

// --- D. MASTER LIST CSV ---
$csvContent = "ID,Last Name,First Name,Dept,Section,Job Title,Agency,Status,Education,Experience,Licenses\n";
foreach ($employees as $emp) {
    $line = [
        $emp['emp_id'],
        $emp['last_name'],
        $emp['first_name'],
        $emp['dept'],
        $emp['section'],
        $emp['job_title'],
        $emp['agency_name'] ?: $emp['employment_type'],
        $emp['status'],
        '"' . str_replace('"', '""', $emp['education'] ?? '') . '"',
        '"' . str_replace('"', '""', $emp['experience'] ?? '') . '"',
        '"' . str_replace('"', '""', $emp['licenses'] ?? '') . '"'
    ];
    $csvContent .= implode(",", $line) . "\n";
}

$csvSize = strlen($csvContent);
if ($currentBytes > 0 && ($currentBytes + $csvSize > $maxSizeBytes)) {
    $partNumber++;
    $startNewZip();
}
/** @var ZipArchive $zip */
$zip->addFromString('Master_Employee_List.csv', $csvContent);
if ($zipPassword) {
    $zip->setEncryptionName('Master_Employee_List.csv', ZipArchive::EM_AES_256, $zipPassword);
}
$currentBytes += $csvSize;

$readmeContent = "NOTE: Open the 'Employee_Profile.html' file inside each folder to view the Printable Data Sheet.";
$readmeSize = strlen($readmeContent);
if ($currentBytes > 0 && ($currentBytes + $readmeSize > $maxSizeBytes)) {
    $partNumber++;
    $startNewZip();
}
/** @var ZipArchive $zip */
$zip->addFromString('README.txt', $readmeContent);
if ($zipPassword) {
    $zip->setEncryptionName('README.txt', ZipArchive::EM_AES_256, $zipPassword);
}
$currentBytes += $readmeSize;

if ($zip instanceof ZipArchive) {
    $zip->close();
}
foreach ($pendingUnlink as $f) @unlink($f);
$pendingUnlink = [];

// 4. DOWNLOAD
$logger = new Logger($pdo);
setcookie("downloadToken", bin2hex(random_bytes(8)), time() + 300, "/");
// [NEW] Set cookie to tell the frontend to close the loading spinner
setcookie("downloadToken", $_POST['csrf_token'] ?? '1', time() + 300, "/");

if (count($generatedZips) === 1) {
    // SINGLE FILE DOWNLOAD
    while (ob_get_level()) ob_end_clean();
    if (ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($generatedZips[0]) . '"');
    header('Content-Length: ' . filesize($generatedZips[0]));
    readfile($generatedZips[0]);
    exit;
} else {
    // MULTI-PART UI & AUTO-DOWNLOADER
    $downloadLinks = [];
    foreach ($generatedZips as $path) {
        $downloadLinks[] = 'export_files.php?download_part=' . urlencode(basename($path));
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <title>Massive Export Complete</title>
        <link href="assets/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    </head>

    <body class="bg-light d-flex align-items-center justify-content-center vh-100">
        <div class="card shadow-sm p-4 text-center" style="max-width: 500px;">
            <h4 class="text-success mb-3"><i class="bi bi-check-circle-fill"></i> Export Complete</h4>
            <p>Your export was massive and has been automatically split into <strong><?php echo count($generatedZips); ?></strong> parts to prevent timeouts.</p>

            <div id="statusText" class="text-primary mb-3 fw-bold"><span class="spinner-border spinner-border-sm"></span> Downloading Part 1...</div>

            <div class="d-grid gap-2 mb-3">
                <?php foreach ($downloadLinks as $i => $link): ?>
                    <a href="<?php echo $link; ?>" class="btn btn-outline-dark" target="_blank"><i class="bi bi-file-zip"></i> Download Part <?php echo $i + 1; ?></a>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mb-0">If the automatic downloads do not start or your browser blocks multiple popups, please click the buttons above.</p>
            <a href="index.php" class="btn btn-link mt-2">Return to Dashboard</a>
        </div>
        <script>
            const files = <?php echo json_encode($downloadLinks); ?>;
            let i = 0;

            function dl() {
                if (i < files.length) {
                    document.getElementById('statusText').innerHTML = `<span class="spinner-border spinner-border-sm"></span> Downloading Part ${i+1}...`;
                    let a = document.createElement('a');
                    a.href = files[i];
                    a.download = '';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    i++;
                    setTimeout(dl, 3000); // 3 second delay to prevent browser anti-spam blocks
                } else {
                    document.getElementById('statusText').innerText = "All parts downloaded!";
                    document.getElementById('statusText').classList.replace('text-primary', 'text-success');
                }
            }
            setTimeout(dl, 1500); // Wait 1.5 seconds before starting first download
        </script>
    </body>

    </html>
<?php
    exit;
}
