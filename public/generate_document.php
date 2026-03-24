<?php
// ======================================================
// [FILE] public/generate_document.php
// [STATUS] DOCUMENT ENGINE: Handles Contract & NDA Generation
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Admin/Manager/HR/Staff
$userRole = isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : '';
if (!in_array($userRole, ['ADMIN', 'MANAGER', 'HR', 'STAFF'])) {
    die("ACCESS DENIED");
}

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

$start_date_obj = new DateTime($start_input);
$start_date_str = $start_date_obj->format('F j, Y');

if (!empty($end_input)) {
    $end_date_obj = new DateTime($end_input);
    $end_date_str = $end_date_obj->format('F j, Y');
    $contract_period = "$start_date_str up to $end_date_str"; // Default format
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
$logger = new Logger($pdo);
$logger->log($_SESSION['user_id'], 'GENERATE_DOC', "Generated $type for {$emp['first_name']} {$emp['last_name']} ({$emp['emp_id']})");

// [NEW] WORD EXPORT HEADER
if ($format === 'word') {
    header("Content-type: application/vnd.ms-word");
    header("Content-Disposition: attachment;Filename=Contract_{$emp['last_name']}.doc");
    // No HTML wrapper for Word doc download, just the template content
} else {

    // 4. LOAD TEMPLATE
    // We wrap the template in a clean HTML container for printing
?>
    <!DOCTYPE html>
    <html lang="en">

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
            }

            .no-print {
                position: fixed;
                top: 20px;
                right: 20px;
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

        <div class="no-print" style="display: flex; flex-direction: column; gap: 10px; width: 250px; margin: 20px auto;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px;">🖨️ Print / Save as PDF</button>
            <a href="<?php echo $_SERVER['REQUEST_URI'] . '&format=word'; ?>" style="padding: 10px 20px; background: #2a5298; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px; text-decoration: none; text-align: center;">📄 Download as Word</a>
            <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px;">Close</button>
        </div>

        <div class="page">
            <?php if ($type !== 'probationary' && $type !== 'probationary_lms' && $type !== 'confidentiality' && $type !== 'project' && $type !== 'consultant' && $type !== 'notice_to_explain' && $type !== 'notice_of_decision' && $type !== 'employee_pledge' && $type !== 'whistleblowing' && $type !== 'regular' && $type !== 'data_consent'): ?>
                <table style="width: 100%; margin-bottom: 10px;">
                    <tr>
                        <!-- Logo logic is inside templates now for some, but kept here for fallback -->
                        <td style="width: 100px; text-align: right; vertical-align: middle;">
                            <img src="<?php echo $global_logo_src ?: 'https://via.placeholder.com/80?text=LOGO'; ?>"
                                alt="TESP Logo"
                                style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;"
                                onerror="this.onerror=null; this.src='https://via.placeholder.com/80?text=LOGO';">
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <div style="font-weight: bold; font-size: 14pt; line-height: 1.2;">TES PHILIPPINES, INC.</div>
                            <div style="font-weight: bold; font-size: 10pt; line-height: 1.2;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                            <div style="font-size: 10pt; line-height: 1.2;">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                            <div style="font-size: 10pt; line-height: 1.2;">Telephone Number: 8929-5347 local 4404</div>
                        </td>
                        <td style="width: 100px;"></td> <!-- Spacer for centering -->
                    </tr>
                </table>
            <?php endif; ?>

        <?php } // End HTML wrapper check 
        ?>
        <?php
        $templateFile = '';
        if ($type === 'probationary') {
            $templateFile = __DIR__ . '/templates/contract_probationary.php';
        } elseif ($type === 'confidentiality') {
            $templateFile = __DIR__ . '/templates/confidentiality_agreement.php';
        } elseif ($type === 'project') {
            $templateFile = __DIR__ . '/templates/contract_project.php';
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