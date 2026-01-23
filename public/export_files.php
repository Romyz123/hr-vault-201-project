<?php
// ======================================================
// [FILE] public/export_files.php
// [STATUS] FINAL: No External Libraries (MHI Safe)
// ======================================================

if (ob_get_level()) ob_end_clean();
require '../src/Logger.php'; 
require '../config/db.php';
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'HR', 'STAFF'])) {
    header("Location: index.php"); exit;
}

// 2. SETTINGS
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 600);

// 3. GET INPUTS
$dept     = trim($_POST['dept'] ?? '');
$section  = trim($_POST['section'] ?? '');
$agency   = trim($_POST['employment_type'] ?? '');
$status   = trim($_POST['status'] ?? '');
$category = trim($_POST['category'] ?? '');
$search   = trim($_POST['search'] ?? '');

// 4. VALIDATION
if (empty($dept) && empty($search)) {
    $_SESSION['error'] = "Export Failed: Select a Department OR type a Search Name.";
    header("Location: index.php"); exit;
}

// ======================================================
// STEP 1: FETCH DATA
// ======================================================
$sql = "SELECT * FROM employees e WHERE 1=1";
$params = [];

// Apply Filters
if (!empty($search)) {
    $cleanSearch = preg_replace('/[^a-zA-Z0-9 \-_]/', '', $search);
    $sql .= " AND (e.emp_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $term = "%{$cleanSearch}%";
    array_push($params, $term, $term, $term);
}
if ($dept !== 'ALL' && !empty($dept)) { $sql .= " AND e.dept = ?"; $params[] = $dept; }
if (!empty($section)) { $sql .= " AND e.section = ?"; $params[] = $section; }
if (!empty($agency)) { $sql .= " AND (e.employment_type = ? OR e.agency_name = ?)"; $params[] = $agency; $params[] = $agency; }
if (!empty($status)) { $sql .= " AND e.status = ?"; $params[] = $status; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($employees) === 0) {
    $_SESSION['error'] = "No employees found matching your criteria.";
    header("Location: index.php"); exit;
}

// Fetch Documents
$empIds = array_column($employees, 'emp_id');
$docsByEmp = [];
if (!empty($empIds)) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $docParams = $empIds;
    $catSql = "";
    if (!empty($category)) { $catSql = " AND category = ?"; $docParams[] = $category; }
    
    $docStmt = $pdo->prepare("SELECT * FROM documents WHERE employee_id IN ($placeholders) $catSql");
    $docStmt->execute($docParams);
    $allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allDocs as $d) { $docsByEmp[$d['employee_id']][] = $d; }
}

// ======================================================
// STEP 2: CREATE ZIP
// ======================================================
$zip = new ZipArchive();
$zipFilename = "HR_Export_" . date('Y-m-d_Hi') . ".zip";
$tempZipPath = sys_get_temp_dir() . "/" . $zipFilename;

if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    $_SESSION['error'] = "Server Error: Could not create ZIP file.";
    header("Location: index.php"); exit;
}

function cleanName($str) { return preg_replace('/[^a-zA-Z0-9 \-_,\.]/', '', trim($str ?? '')); }
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }

// Helper to get image as base64
function getBase64Image($path) {
    if (file_exists($path)) {
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
    return ''; // Return empty if missing
}

$filesAdded = 0;

foreach ($employees as $emp) {
    $empId = $emp['emp_id'];
    $folderName = cleanName($emp['last_name']) . ", " . cleanName($emp['first_name']) . " - " . cleanName($emp['emp_id']);

    // --- A. PREPARE AVATAR (Embedded) ---
    $avatarFile = "uploads/avatars/" . ($emp['avatar_path'] ?: 'default.png');
    $avatarData = getBase64Image($avatarFile);
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
                body { background: none; padding: 0; }
                .page { box-shadow: none; margin: 0; width: 100%; }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="header">
                <img src="'.$avatarData.'" class="avatar">
                <div class="header-info">
                    <h1>'.h($emp['last_name']).', '.h($emp['first_name']).'</h1>
                    <h3>'.h($emp['job_title']).'</h3>
                    <div class="tags">
                        <span>'.h($emp['emp_id']).'</span>
                        <span>'.h($emp['dept']).'</span>
                        <span>'.h($emp['status']).'</span>
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
                    <div class="cell"><span class="label">Date of Birth</span><span class="value">'.h($emp['birth_date']).'</span></div>
                    <div class="cell"><span class="label">Gender</span><span class="value">'.h($emp['gender']).'</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">Contact Number</span><span class="value">'.h($emp['contact_number']).'</span></div>
                    <div class="cell"><span class="label">Email</span><span class="value">'.h($emp['email']).'</span></div>
                </div>
                <div class="row">
                    <div class="cell-full"><span class="label">Present Address</span><span class="value">'.h($emp['present_address']).'</span></div>
                </div>
            </div>

            <div class="section-title">EMPLOYMENT DETAILS</div>
            <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">Employment Type</span><span class="value">'.h($emp['employment_type']).'</span></div>
                    <div class="cell"><span class="label">Agency</span><span class="value">'.h($emp['agency_name']).'</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">Date Hired</span><span class="value">'.h($emp['hire_date']).'</span></div>
                    <div class="cell"><span class="label">Section</span><span class="value">'.h($emp['section']).'</span></div>
                </div>
            </div>

            <div class="section-title">GOVERNMENT CONTRIBUTIONS</div>
            <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">SSS Number</span><span class="value">'.h($emp['sss_no']).'</span></div>
                    <div class="cell"><span class="label">TIN Number</span><span class="value">'.h($emp['tin_no']).'</span></div>
                </div>
                <div class="row">
                    <div class="cell"><span class="label">PhilHealth</span><span class="value">'.h($emp['philhealth_no']).'</span></div>
                    <div class="cell"><span class="label">Pag-IBIG</span><span class="value">'.h($emp['pagibig_no']).'</span></div>
                </div>
            </div>

            <div class="section-title">IN CASE OF EMERGENCY</div>
             <div class="grid">
                <div class="row">
                    <div class="cell"><span class="label">Name</span><span class="value">'.h($emp['emergency_name']).'</span></div>
                    <div class="cell"><span class="label">Contact</span><span class="value">'.h($emp['emergency_contact']).'</span></div>
                </div>
                <div class="row">
                    <div class="cell-full"><span class="label">Address</span><span class="value">'.h($emp['emergency_address']).'</span></div>
                </div>
            </div>
        </div>
    </body>
    </html>';

    // Add HTML Profile to ZIP
    $zip->addFromString($folderName . "/Employee_Profile.html", $htmlContent);
    $filesAdded++; // Count this as a "file" so empty folders still export

    // --- C. ADD DOCUMENTS ---
    if (isset($docsByEmp[$empId])) {
        foreach ($docsByEmp[$empId] as $doc) {
            $realPath = "uploads/" . $doc['file_path'];
            if (file_exists($realPath)) {
                $zip->addFile($realPath, $folderName . "/" . $doc['original_name']);
            }
        }
    }
}

// --- D. MASTER LIST CSV ---
$csvContent = "ID,Last Name,First Name,Dept,Section,Job Title,Agency,Status\n";
foreach ($employees as $emp) {
    $line = [
        $emp['emp_id'], $emp['last_name'], $emp['first_name'], 
        $emp['dept'], $emp['section'], $emp['job_title'], 
        $emp['agency_name'] ?: $emp['employment_type'], $emp['status']
    ];
    $csvContent .= implode(",", $line) . "\n";
}
$zip->addFromString('Master_Employee_List.csv', $csvContent);
$zip->addFromString('README.txt', "NOTE: Open the 'Employee_Profile.html' file inside each folder to view the Printable Data Sheet.");

$zip->close();

// 4. DOWNLOAD
if (file_exists($tempZipPath)) {
    try {
        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->execute([$_SESSION['user_id'], 'EXPORT_ZIP', "Exported " . count($employees) . " folders.", $_SERVER['REMOTE_ADDR']]);
    } catch (Exception $e) {}

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($tempZipPath));
    readfile($tempZipPath);
    unlink($tempZipPath);
    exit;
}
?>