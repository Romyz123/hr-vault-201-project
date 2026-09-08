<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ======================================================
// [FILE] public/generate_document.php
// [STATUS] DOCUMENT ENGINE: Handles Contract & NDA Generation
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/FileService.php';
session_start();

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Admin/Manager/HR/Staff
$userRole = isset($_SESSION['role']) ? strtoupper(trim((string)$_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || !in_array($userRole, ['ADMIN', 'MANAGER', 'HR', 'STAFF'])) {
    die("ACCESS DENIED");
}

// [FIX] Robust Config Loading for Auto-Save Feature
$configEnv = require '../config/config.php';
$vaultPath = $configEnv['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
$fileService = new FileService($vaultPath);

$logger = new Logger($pdo);

// 2. FETCH EMPLOYEE
$ids_raw = (isset($_GET['ids']) && !is_array($_GET['ids'])) ? $_GET['ids'] : ((isset($_GET['id']) && !is_array($_GET['id'])) ? $_GET['id'] : '');
$ids = array_filter(explode(',', (string)$ids_raw), 'is_numeric');
$type = isset($_GET['type']) ? $_GET['type'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'html'; // 'html' or 'word'
$agency = isset($_GET['agency']) ? trim((string)$_GET['agency']) : '';

if ((empty($ids) && empty($agency)) || empty($type)) {
    die("Invalid Request: You must provide employee IDs or an agency name.");
}

// [NEW] If agency is provided, fetch all active employee IDs for that agency
if (!empty($agency)) {
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE agency_name = ? AND status = 'Active'");
    $stmt->execute([$agency]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($ids)) {
        die("No active employees found for agency: " . htmlspecialchars($agency));
    }
}

// Fetch all requested employees
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id IN ($placeholders)");
$stmt->execute($ids);
$all_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($all_employees)) die("Employees not found.");

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
if (!is_numeric($docFontSize)) {
    $docFontSize = '11';
}
$docFontSize = floatval($docFontSize);
if ($docFontSize < 8) {
    $docFontSize = 8;
} elseif ($docFontSize > 24) {
    $docFontSize = 24;
}

// [FIX] Fetch Company President from settings to make it dynamic
$companyPresident = !empty($settings['company_president']) ? $settings['company_president'] : 'JUNJI FURUYA';
// [NEW] Fetch other defaults for consistency with bulk generator
$defaultProjectName = $settings['default_project_name'] ?? '';
$defaultNoticePlace = $settings['default_notice_place'] ?? '';

// 3. PREPARE SHARED INPUTS
$custom_duties = (isset($_GET['custom_duties']) && !is_array($_GET['custom_duties'])) ? trim($_GET['custom_duties']) : '';
$start_override = $_GET['start_date'] ?? '';
$end_input   = $_GET['end_date'] ?? '';

$_GET['project_name'] = $_GET['project_name'] ?? $defaultProjectName;
$_GET['notice_place'] = $_GET['notice_place'] ?? $defaultNoticePlace;

$manual_end_date_override = isset($_GET['manual_end_date_override']) && $_GET['manual_end_date_override'] === 'on';
$manual_end_date_value = $_GET['manual_end_date_value'] ?? '';

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
$incident_date = (isset($_GET['incident_date']) && !is_array($_GET['incident_date'])) ? trim($_GET['incident_date']) : '';
$incident_place = (isset($_GET['incident_place']) && !is_array($_GET['incident_place'])) ? trim($_GET['incident_place']) : '';
$allegation = (isset($_GET['allegation']) && !is_array($_GET['allegation'])) ? trim($_GET['allegation']) : '';
$decision = (isset($_GET['decision']) && !is_array($_GET['decision'])) ? trim($_GET['decision']) : '';

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

// [NEW] Move Template Selection Logic Up
$templateMap = [
    'probationary' => __DIR__ . '/templates/contract_probationary.php',
    'confidentiality' => __DIR__ . '/templates/confidentiality_agreement.php',
    'project' => __DIR__ . '/templates/contract_project.php',
    'coe' => __DIR__ . '/templates/coe.php',
    'notice_to_explain' => __DIR__ . '/templates/notice_to_explain.php',
    'notice_of_decision' => __DIR__ . '/templates/notice_of_decision.php',
    'employee_pledge' => __DIR__ . '/templates/employee_pledge.php',
    'whistleblowing' => __DIR__ . '/templates/whistleblowing.php',
    'data_consent' => __DIR__ . '/templates/data_consent.php',
];
$templateFile = $templateMap[$type] ?? '';

// [NEW] Friendly Document Titles for Auto-Save Naming
$docTitles = [
    'probationary'      => 'Probationary Employment Contract',
    'project'           => 'Project Employment Contract',
    'data_consent'      => 'Data Privacy Consent Form',
    'confidentiality'   => 'Confidentiality Agreement',
    'notice_to_explain' => 'Notice to Explain',
    'notice_of_decision' => 'Notice of Decision',
    'employee_pledge'   => 'Employee Safety Pledge',
    'whistleblowing'    => 'Whistle Blowing Consent Form',
    'coe'               => 'Certificate of Employment'
];
$friendlyTitle = $docTitles[$type] ?? 'Document';

// [REFACTOR] Consolidate Word and HTML generation loops
$isWordFormat = ($format === 'word');

if ($isWordFormat) {
    $firstEmp = $all_employees[0] ?? ['last_name' => 'Documents'];
    header("Content-type: application/vnd.ms-word");
    header("Content-Disposition: attachment;Filename=Contract_{$firstEmp['last_name']}.doc");
} else {
    // HTML wrapper for screen preview
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
                page-break-after: always;
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
    <?php
}

foreach ($all_employees as $emp) {
    // [NEW] Capture content per employee for individual auto-saving
    ob_start();

    // Re-initialize per-employee variables for the current employee in the loop
    $full_name = strtoupper($emp['first_name'] . ' ' . (empty($emp['middle_name']) ? '' : $emp['middle_name'][0] . '.') . ' ' . $emp['last_name']);
    $position  = strtoupper($emp['job_title']);
    $address   = strtoupper($emp['present_address']);
    $section   = strtoupper($emp['section']);
    $start_input = !empty($start_override) ? $start_override : ($emp['hire_date'] ?? '');
    try {
        $start_date_obj = new DateTime($start_input ?: 'now');
        $start_date_str = $start_date_obj->format('F j, Y');
    } catch (Exception $e) {
        $start_date_str = date('F j, Y');
    }

    if ($type === 'coe' && $manual_end_date_override) {
        $employmentEnd = !empty($manual_end_date_value) ? date('F j, Y', strtotime($manual_end_date_value)) : 'Present';
    } else {
        $currentStatus = strtolower(trim($emp['status'] ?? ''));
        if (in_array($currentStatus, ['resigned', 'inactive', 'terminated'])) {
            $employmentEnd = !empty($end_input) ? date('F j, Y', strtotime($end_input)) : 'Present';
        } else {
            $employmentEnd = 'Present';
        }
    }
    $_GET['employment_end_display'] = $employmentEnd;

    // Auto-case filing
    if (in_array($type, ['notice_to_explain', 'notice_of_decision'])) {
        try {
            $vDate = $incident_date;
            $vType = $violation;
            $vRule = $rule_violated;
            $vAction = trim((string)(($type === 'notice_to_explain') ? 'Written Explanation Required' : ($decision ?: '')));
            $vDesc = trim((string)(($type === 'notice_to_explain') ? ($allegation ?: '') : ($decision ?: '')));

            if (!empty($vDate) && !empty($vDesc)) {
                if (strpos($vDate, 'T') !== false) $vDate = explode('T', $vDate)[0];
                if ($vType === '') $vType = ($type === 'notice_to_explain') ? 'NTE Issued' : 'NOD Issued';
                if ($vAction === '') $vAction = ($type === 'notice_to_explain') ? 'Written Explanation Required' : 'Sanction Issued';

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
            error_log("Auto-case file failed: " . $e->getMessage());
        }
    }

    // [LOGGING] Record document generation for each
    $logger->log($_SESSION['user_id'], 'GENERATE_DOC', "Generated $type for {$emp['first_name']} {$emp['last_name']} ({$emp['emp_id']})");
    ?>
        <div class="document-container">
            <?php
            if ($templateFile && file_exists($templateFile)) {
                include $templateFile;
            } else {
                echo "Template Error";
            }
            ?>
        </div>
    <?php
    $renderedContent = ob_get_clean();

    // --- [NEW] PER-EMPLOYEE AUTO-ATTACH LOGIC ---
    if (isset($_GET['auto_save_copy']) && !empty($renderedContent)) {
        try {
            // 1. requested formal naming
            $baseName = "Unsigned Digital Copy of the Document ($friendlyTitle)";
            $fileExt = "html";

            // [NEW] Append Status to Filename for Non-Active Employees
            $empStatus = $emp['status'] ?? 'Active';
            if ($empStatus !== 'Active') $baseName .= " ($empStatus)";

            // 2. Collision Detection & Auto-Numbering (-0001)
            $checkStmt = $pdo->prepare("SELECT original_name FROM documents WHERE employee_id = ? AND deleted_at IS NULL");
            $checkStmt->execute([$emp['emp_id']]);
            $existingInDB = $checkStmt->fetchAll(PDO::FETCH_COLUMN);

            $counter = 1;
            $finalDisplayName = $baseName . "." . $fileExt;
            while (in_array($finalDisplayName, $existingInDB)) {
                $finalDisplayName = $baseName . "-" . str_pad($counter, 3, '0', STR_PAD_LEFT) . "." . $fileExt;
                $counter++;
            }

            // 3. Save and Link
            $tmp = tempnam(sys_get_temp_dir(), 'gen_doc');
            $standaloneHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:sans-serif;padding:20mm;}p{text-align:justify;line-height:1.5;}</style></head><body>' . $renderedContent . '</body></html>';
            file_put_contents($tmp, $standaloneHtml);

            $storedName = $fileService->saveFile($tmp, $finalDisplayName);
            if ($storedName) {
                $category = (strpos($type, 'notice') !== false) ? 'Disciplinary' : 'Contract';
                $docStmt = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by, description) VALUES (UUID(), ?, ?, ?, ?, ?, 'Auto-generated unsigned copy.')");
                $docStmt->execute([$emp['emp_id'], $finalDisplayName, $storedName, $category, $_SESSION['user_id']]);
                $logger->log($_SESSION['user_id'], 'AUTO_SAVE_COPY', "Auto-saved unsigned copy: $finalDisplayName for employee {$emp['emp_id']}");
            }
            @unlink($tmp);
        } catch (Exception $e) {
            error_log("Individual auto-attach failed: " . $e->getMessage());
        }
    }

    // Output the captured content
    if ($isWordFormat) {
        echo $renderedContent;
        echo '<br style="page-break-before: always; clear: both;">';
    } else {
        echo '<div class="page">' . $renderedContent . '</div>';
    }
}

if (!$isWordFormat) {
    echo '</body></html>';
}
exit;
    ?>