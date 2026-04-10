<?php
// ======================================================
// [FILE] public/generate_document.php
// [STATUS] DOCUMENT ENGINE: Handles Contract & NDA Generation
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/FileService.php';
session_start();

// 1. SECURITY: Admin/Manager/HR/Staff
$userRole = isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : '';
if (!in_array($userRole, ['ADMIN', 'MANAGER', 'HR', 'STAFF'])) {
    die("ACCESS DENIED");
}

$logger = new Logger($pdo);

// 2. FETCH EMPLOYEE
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'html'; // 'html' or 'word'

if ($id <= 0 || empty($type)) die("Invalid Request");

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) die("Employee not found.");

// 2.5 FETCH SETTINGS
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log("Failed to load system_settings: " . $e->getMessage());
}
$docFontSize = $settings['document_font_size'] ?? '11';

// Validate font size to prevent CSS injection
if (!preg_match('/^\d+(?:\.\d+)?$/', trim($docFontSize))) {
    $docFontSize = '11';
}
$docFontSize = floatval($docFontSize);
if ($docFontSize < 8) {
    $docFontSize = 8;
} elseif ($docFontSize > 24) {
    $docFontSize = 24;
}

// 3. PREPARE VARIABLES (Auto-Fill Logic)
$full_name = strtoupper($emp['first_name'] . ' ' . (empty($emp['middle_name']) ? '' : $emp['middle_name'][0] . '.') . ' ' . $emp['last_name']);
$address   = strtoupper($emp['present_address']);
$position  = strtoupper($emp['job_title']);
$section   = strtoupper($emp['section']);

// [NEW] Capture Custom Duties
$custom_duties = isset($_GET['custom_duties']) ? trim($_GET['custom_duties']) : '';

// [NEW] Custom Date Logic from Modal
$start_input = $_GET['start_date'] ?? $emp['hire_date'];
$end_input   = $_GET['end_date'] ?? '';

// [FIX] Safe Date Parsing to prevent Fatal Error crashes on missing hire dates
try {
    $start_date_obj = new DateTime($start_input ?: 'now');
    $start_date_str = $start_date_obj->format('F j, Y');
} catch (Exception $e) {
    $start_date_obj = new DateTime('now');
    $start_date_str = $start_date_obj->format('F j, Y');
}

if (!empty($end_input)) {
    try {
        $end_date_obj = new DateTime($end_input);
        $end_date_str = $end_date_obj->format('F j, Y');
        $contract_period = "$start_date_str up to $end_date_str"; // Default format
    } catch (Exception $e) {
        // Fallback if end date is garbage
        $end_date_obj = clone $start_date_obj;
        $end_date_obj->modify('+6 months');
        $end_date_str = $end_date_obj->format('F j, Y');
        $contract_period = "$start_date_str up to $end_date_str";
    }
} else {
    // Fallback if no end date provided (Default 6 months)
    $end_date_obj = clone $start_date_obj;
    $end_date_obj->modify('+6 months');
    $end_date_str = $end_date_obj->format('F j, Y');
    $contract_period = "$start_date_str up to $end_date_str";
}

// Current Date for Signatures
$current_day  = date('jS'); // 24th
$current_month = date('F'); // January
$current_year = date('Y');
$current_full_date = date('F j, Y');

// [FIX] Handle Array inputs from Multi-Select dropdowns for Templates
$violation = $_GET['violation'] ?? '';
if (is_array($violation)) {
    $violation = implode(', ', $violation);
}

$rule_violated = $_GET['rule_violated'] ?? '';
if (is_array($rule_violated)) {
    $rule_violated = implode(', ', $rule_violated);
}

// Shared variables for templates
$incident_date = $_GET['incident_date'] ?? '';
$incident_place = $_GET['incident_place'] ?? '';
$allegation = $_GET['allegation'] ?? '';
$decision = $_GET['decision'] ?? '';

// [NEW] Automatically Record Disciplinary Case if generating NTE or NOD
if (in_array($type, ['notice_to_explain', 'notice_of_decision'])) {
    try {
        $vDate = trim($_GET['incident_date'] ?? '');
        $vType = $violation; // Use the processed string variable
        $vRule = $rule_violated; // Use the processed string variable
        $vAction = trim(($type === 'notice_to_explain') ? 'Written Explanation Required' : ($_GET['decision'] ?? ''));
        $vDesc = trim(($type === 'notice_to_explain') ? ($_GET['allegation'] ?? '') : ($_GET['decision'] ?? ''));

        // Skip recording if core fields are empty (prevents recording blank templates/samples)
        if (!empty($vDate) && !empty($vDesc)) {
            // Format datetime-local from NTE to Y-m-d
            if (strpos($vDate, 'T') !== false) $vDate = explode('T', $vDate)[0];

            if ($vType === '') $vType = ($type === 'notice_to_explain') ? 'NTE Issued' : 'NOD Issued';
            if ($vAction === '') $vAction = ($type === 'notice_to_explain') ? 'Written Explanation Required' : 'Sanction Issued';

            // Simple De-duplication: Check if an identical case was filed in the last hour
            $chk = $pdo->prepare("SELECT id FROM disciplinary_cases WHERE employee_id = ? AND violation_type = ? AND incident_date = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $chk->execute([$emp['emp_id'], $vType, $vDate]);

            if (!$chk->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO disciplinary_cases (employee_id, violation_type, rule_violated, incident_date, action_taken, description, status) 
                                   VALUES (?, ?, ?, ?, ?, ?, 'Open')");
                $stmt->execute([$emp['emp_id'], $vType, $vRule, $vDate, $vAction, $vDesc]);
                $logger->log($_SESSION['user_id'], 'AUTO_CASE_FILE', "Auto-recorded disciplinary case ($type) for " . $emp['emp_id']);
            }
        }
    } catch (Exception $e) {
        error_log("Failed to auto-record disciplinary case in generate_document.php: " . $e->getMessage());
    }
}

$logo_paths = [
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$global_logo_src = '';
$mime = 'image/png';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($p) ?: 'image/png';
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($p) ?: 'image/png';
        } else {
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png');
        }
        $global_logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
}

// [LOGGING] Record document generation
$logger->log($_SESSION['user_id'], 'GENERATE_DOC', "Generated $type for {$emp['first_name']} {$emp['last_name']} ({$emp['emp_id']})");

// [NEW] Move Template Selection Logic Up
$templateFile = '';
if ($type === 'probationary') {
    $templateFile = __DIR__ . '/templates/contract_probationary.php';
} elseif ($type === 'confidentiality') {
    $templateFile = __DIR__ . '/templates/confidentiality_agreement.php';
} elseif ($type === 'project') {
    $templateFile = __DIR__ . '/templates/contract_project.php';
} elseif ($type === 'coe') {
    $templateFile = __DIR__ . '/templates/coe.php';
} elseif ($type === 'notice_to_explain') {
    $templateFile = __DIR__ . '/templates/notice_to_explain.php';
} elseif ($type === 'notice_of_decision') {
    $templateFile = __DIR__ . '/templates/notice_of_decision.php';
} elseif ($type === 'employee_pledge') {
    $templateFile = __DIR__ . '/templates/employee_pledge.php';
} elseif ($type === 'whistleblowing') {
    $templateFile = __DIR__ . '/templates/whistleblowing.php';
} elseif ($type === 'data_consent') {
    $templateFile = __DIR__ . '/templates/data_consent.php';
}

// [NEW] Start Output Buffering to capture the document for auto-attachment
ob_start();

// [NEW] WORD EXPORT HEADER
if ($format === 'word') {
    header("Content-type: application/vnd.ms-word");
    header("Content-Disposition: attachment;Filename=Contract_{$emp['last_name']}.doc");
    // No HTML wrapper for Word doc download, just the template content
    if ($templateFile && file_exists($templateFile)) {
        include $templateFile;
    }
} else {

    // 4. LOAD TEMPLATE
    // We wrap the template in a clean HTML container for printing
?>
    <!DOCTYPE html>
    <html lang="en" data-bs-theme="light">

    <head>
        <meta charset="UTF-8">
        <title>Document: <?php echo htmlspecialchars($type); ?></title>
        <?php if (!empty($global_logo_src)): ?>
            <link rel="icon" href="<?php echo $global_logo_src; ?>" type="<?php echo htmlspecialchars($mime); ?>">
        <?php endif; ?>
        <style>
            body {
                font-family: "Times New Roman", Times, serif;
                font-size: 12pt;
                line-height: 1.5;
                color: #000;
                background: #eee;
            }

            .page {
                background: white;
                width: 8in;
                min-height: 11in;
                padding: 0.5in;
                /* [PREVIEW CONTROL] Keep this same as print margin */
                margin: 20px auto;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                position: relative;
                box-sizing: border-box;
                /* Forces padding to stay inside the 8in width */
            }

            .no-print {
                position: fixed;
                top: 20px;
                right: 20px;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .text-center {
                text-align: center;
            }

            .fw-bold {
                font-weight: bold;
            }

            .text-uppercase {
                text-transform: uppercase;
            }

            .signature-line {
                border-top: 1px solid #000;
                width: 200px;
                display: inline-block;
                margin-top: 30px;
            }

            .justify {
                text-align: justify;
            }

            @media print {
                body {
                    background: white;
                }

                .page {
                    box-shadow: none;
                    margin: 0;
                    width: 100%;
                }

                .no-print {
                    display: none !important;
                }

                @page {
                    margin: 0.5in;
                }

                /* Minimal margins for printer */
            }

            /* Dynamic Font Size Override */
            .page p,
            .page li,
            .page .justify,
            .page td {
                font-size: <?php echo htmlspecialchars($docFontSize); ?>pt !important;
            }
        </style>
    </head>

    <body>

        <div class="no-print">
            <button onclick="window.print()" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px; width: 100%;">🖨️ Print / Save as PDF</button>
            <a href="<?php echo $_SERVER['REQUEST_URI'] . '&format=word'; ?>" style="padding: 10px 20px; background: #2a5298; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px; text-decoration: none; text-align: center; width: 100%; box-sizing: border-box;">📄 Download as Word</a>
            <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px; width: 100%;">Close</button>
        </div>

        <div class="page">
            <?php if ($type !== 'probationary' && $type !== 'probationary_lms' && $type !== 'confidentiality' && $type !== 'project' && $type !== 'consultant' && $type !== 'notice_to_explain' && $type !== 'notice_of_decision' && $type !== 'employee_pledge' && $type !== 'whistleblowing' && $type !== 'regular' && $type !== 'data_consent' && $type !== 'coe'): ?>
                <table style="width: 100%; margin-bottom: 10px;">
                    <tr>
                        <td style="width: 130px; text-align: right; vertical-align: middle; padding-right: 15px;">
                            <img src="<?php echo $global_logo_src ?: 'https://via.placeholder.com/80?text=LOGO'; ?>"
                                alt="TESP Logo"
                                style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;"
                                onerror="this.onerror=null; this.src='https://via.placeholder.com/80?text=LOGO';">
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <div style="font-weight: bold; font-size: 15pt !important; line-height: 1.2; white-space: nowrap;">TES PHILIPPINES, INC.</div>
                            <div style="font-weight: bold; font-size: 11pt !important; line-height: 1.2; white-space: nowrap;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                            <div style="font-size: 11pt !important; line-height: 1.2;">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                            <div style="font-size: 11pt !important; line-height: 1.2;">Telephone Number: 8929-5347 local 4404</div>
                        </td>
                        <td style="width: 70px;"></td> <!-- Spacer for shifting text right -->
                    </tr>
                </table>
            <?php endif; ?>

            <?php
            if ($templateFile && file_exists($templateFile)) {
                include $templateFile;
            } else {
                echo "<div style='color: red; border: 2px solid red; padding: 20px; background: #ffe6e6;'>
                <h3>❌ Template Error</h3>
                <p>Could not find the template file.</p>
                <p><strong>Expected Path:</strong> " . htmlspecialchars($templateFile) . "</p>
                <p>Please ensure the file exists inside the <code>public/templates/</code> folder.</p>
              </div>";
            }
            ?>
        </div>

    </body>

    </html>
<?php
} // End Format Check

// --- AUTO-ATTACH LOGIC ---
$renderedContent = ob_get_contents();
ob_end_flush(); // Send to browser for printing

if ($format === 'html' && !empty($renderedContent)) {
    try {
        $config = require '../config/config.php';
        $vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
        if (!$vaultPath) {
            throw new Exception('Vault path not configured or does not exist');
        }
        $fileService = new FileService($vaultPath);
        // 1. Create a descriptive filename
        $cleanType = str_replace('_', ' ', ucwords($type));
        $originalName = $cleanType . " (" . date('M d Y') . ").html";

        // 2. De-duplication Check: Don't attach if generated in the last 2 minutes (prevents refresh spam)
        $chk = $pdo->prepare("SELECT id FROM documents WHERE employee_id = ? AND original_name = ? AND uploaded_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
        $chk->execute([$emp['emp_id'], $originalName]);

        if (!$chk->fetch()) {
            // 3. Save to temporary file first
            $tmp = tempnam(sys_get_temp_dir(), 'gen_doc');
            file_put_contents($tmp, $renderedContent);

            // 4. Encrypt and save to Vault
            $storedName = $fileService->saveFile($tmp, $originalName);

            if ($storedName) {
                // 5. Link to 201 File
                $category = (strpos($type, 'notice') !== false) ? 'Disciplinary' : 'Contract';
                $docStmt = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by) VALUES (UUID(), ?, ?, ?, ?, ?)");
                $docStmt->execute([$emp['emp_id'], $originalName, $storedName, $category, $_SESSION['user_id']]);
            }

            unlink($tmp);
        }
    } catch (Exception $e) {
        error_log("Auto-attach failed: " . $e->getMessage());
    }
}
?>