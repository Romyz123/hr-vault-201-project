<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/FileService.php';
require 'options.php';

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
session_start();

// 1. SECURITY: Admin, Manager & HR Only
$userRole = isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || !in_array($userRole, ['ADMIN', 'MANAGER', 'HR'])) {
    die("ACCESS DENIED");
}

$logger = new Logger($pdo);

// [NEW] Fetch System Settings for Defaults
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
}

$defProject = $settings['default_project_name'] ?? '';
$defPlace   = $settings['default_notice_place'] ?? '';
$marginL    = $settings['bulk_margin_left'] ?? '30';
$marginR    = $settings['bulk_margin_right'] ?? '20';
$docFontSize = $settings['document_font_size'] ?? '11';

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. HANDLE GENERATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bulk'])) {
    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF Token");
    }

    $ids = $_POST['employee_ids'] ?? [];
    $type = $_POST['doc_type'];

    // [NEW] Load Config to ensure VAULT_PATH is available for auto-saving copies
    $configEnv = require '../config/config.php';
    $vaultPath = $configEnv['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

    // [NEW] Capture & Save Settings on the Fly
    $marginL = min(500, max(0, (int)($_POST['margin_left'] ?? $marginL)));
    $marginR = min(500, max(0, (int)($_POST['margin_right'] ?? $marginR)));
    $docFontSize = min(24, max(8, (float)($_POST['font_size'] ?? $docFontSize)));
    $proj    = $_POST['project_name'] ?? '';
    $place   = $_POST['notice_place'] ?? '';

    if (isset($_POST['save_defaults'])) {
        $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES 
                ('default_project_name', ?), ('default_notice_place', ?), 
                ('bulk_margin_left', ?), ('bulk_margin_right', ?), ('document_font_size', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $pdo->prepare($sql)->execute([$proj, $place, $marginL, $marginR, $docFontSize]);
    }

    // Set Global Params for Templates
    $_GET['project_name'] = $_POST['project_name'] ?? '';
    $_GET['start_date']   = $_POST['start_date'] ?? '';
    $_GET['end_date']     = $_POST['end_date'] ?? '';
    $_GET['duration']     = $_POST['duration'] ?? '';
    $_GET['notice_date']  = $_POST['notice_date'] ?? '';
    $_GET['notice_place'] = $place;

    // [FIX] Initialize custom_duties to empty for bulk generation (Forces template to use Role-based duties)
    $custom_duties = '';
    $companyPresident = $settings['company_president'] ?? 'JUNJI FURUYA';

    // Map Type to Template
    $templateMap = [
        'probationary' => 'templates/contract_probationary.php',
        'project'      => 'templates/contract_project.php',
        'data_consent' => 'templates/data_consent.php',
        'coe'          => 'templates/coe.php',
        'confidentiality' => 'templates/confidentiality_agreement.php',
        'notice_to_explain' => 'templates/notice_to_explain.php',
        'notice_of_decision' => 'templates/notice_of_decision.php',
        'employee_pledge' => 'templates/employee_pledge.php',
        'whistleblowing' => 'templates/whistleblowing.php'
    ];

    // [NEW] Friendly Document Titles for Auto-Save Naming
    $docTitles = [
        'probationary'      => 'Probationary Employment Contract',
        'project'           => 'Project Employment Contract',
        'data_consent'      => 'Data Privacy Consent Form',
        'confidentiality'   => 'Confidentiality Agreement',
        'notice_to_explain' => 'Notice to Explain',
        'notice_of_decision' => 'Notice of Decision',
        'coe'               => 'Certificate of Employment',
        'employee_pledge'   => 'Employee Safety Pledge',
        'whistleblowing'    => 'Whistle Blowing Consent Form'
    ];
    $friendlyTitle = $docTitles[$type] ?? 'Document';

    $templateFile = $templateMap[$type] ?? '';

    $logo_paths = [
        __DIR__ . '/assets/images/tesp-logo-1.png',
        __DIR__ . '/uploads/tesp-logo.png',
        __DIR__ . '/uploads/tesp logo 1.png',
        __DIR__ . '/../uploads/tesp-logo.png',
        __DIR__ . '/../uploads/tesp logo 1.png'
    ];
    $global_logo_src = '';
    $global_logo_mime = 'image/png';
    foreach ($logo_paths as $p) {
        if (file_exists($p)) {
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $global_logo_mime = $finfo->file($p) ?: 'image/png';
            } elseif (function_exists('mime_content_type')) {
                $global_logo_mime = mime_content_type($p) ?: 'image/png';
            } else {
                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                $global_logo_mime = ($ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png'));
            }
            $global_logo_src = 'data:' . $global_logo_mime . ';base64,' . base64_encode(file_get_contents($p));
            break;
        }
    }

    if ($templateFile && file_exists($templateFile) && !empty($ids)) {
        // [NEW] Set cookie to tell the frontend to close the loading spinner
        setcookie("downloadToken", $_POST['csrf_token'] ?? '1', time() + 300, "/");

        // Start Output
        $faviconTag = '<link rel="icon" href="uploads/tesp-logo.png?v=3" type="image/png">';
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Bulk Contracts</title>' . $faviconTag;
        echo '<style>
            @page { size: A4; margin: 0.5in; }
            @media print { 
                .page-break { page-break-after: always; height: 0; display: block; visibility: hidden; } 
                .no-print { display: none !important; }
                body { margin: 0; padding: 0; background: white; }
                .document-container { margin: 0 !important; padding: 0 !important; box-shadow: none !important; border: none !important; width: 100% !important; max-width: none !important; overflow: visible !important; }

                /* [FIX] Compact layout for bulk printing to prevent extra pages */
                .sig-section { margin-top: 30px !important; }
                
                /* [FIX] Body Text: Justified & Line Height 1.5 */
                p, .justify, li { 
                    margin-bottom: 3px !important; 
                    line-height: 2.00 !important; 
                    text-align: justify !important; 
                    text-justify: inter-word !important;
                    font-size: ' . $docFontSize . 'pt !important;
                }
                td {
                    font-size: ' . $docFontSize . 'pt !important;
                }

                /* [FIX] Header Restored to 100% */
                .doc-title { 
                    width: 100% !important; 
                    margin-top: 10px !important; 
                    margin-bottom: 10px !important;
                    text-align: center !important; 
                }

                .header-wrapper { 
                     width: 100% !important; 
                }

                /* [FIX] Shift body content using ADJUSTABLE Settings (Removed .doc-title from here) */
                p, ol, ul, .justify, .salutation, .witnesseth, .sig-section { 
                    width: auto !important; /* Prevents text from bleeding off the page */
                    margin-left: ' . $marginL . 'px !important; 
                    margin-right: ' . $marginR . 'px !important; 
                }
                /* [FIX] Probationary Contract Specific Overrides */
                ' . ($type === 'probationary' ? '
                .sig-table { width: 100% !important; margin-top: 50px !important; page-break-inside: avoid !important; }
                .sig-table td { width: 50% !important; vertical-align: top !important; }
                .sig-line { border-top: 1px solid #000 !important; width: 250px !important; margin-bottom: 0px !important; }
                .sig-name { font-weight: bold !important; text-transform: uppercase !important; }
                p:nth-last-of-type(3) { margin-top: 0px !important; margin-bottom: 0px !important; }
                ' : '') . '

                /* [FIX] Project Contract Specific Overrides - Push last paragraphs down */
                ' . ($type === 'project' ? '
                p.justify:nth-last-of-type(2) { margin-top: 40px !important; }
                ' : '') . '
            }
            body { background: #555; font-family: sans-serif; }
            .document-container { background: white; margin: 100px auto; padding: 20px <?php echo $marginR; ?>px 20px <?php echo $marginL; ?>px; box-sizing: border-box; max-width: 8.5in; box-shadow: 0 0 10px rgba(0,0,0,0.5); }
            .toolbar { position: fixed; top: 0; left: 0; width: 100%; background: #333; color: white; padding: 10px; text-align: center; z-index: 1000; }
        </style>';
        echo '</head><body>';

        echo '<div class="toolbar no-print">
                <strong>Bulk Preview:</strong> ' . count($ids) . ' Documents 
                <button onclick="window.print()" style="padding: 5px 15px; margin-left: 20px; cursor: pointer; font-weight: bold;">🖨️ Print All</button>
                <button onclick="window.close()" style="padding: 5px 15px; margin-left: 10px; cursor: pointer;">Close</button>
              </div><div style="height: 50px;" class="no-print"></div>';

        foreach ($ids as $id) {
            // Fetch Employee Data
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($emp) {
                // [FIX] Prepare dynamic variables required by the contract templates
                $firstName  = $emp['first_name'] ?? '';
                $middleName = $emp['middle_name'] ?? '';
                $lastName   = $emp['last_name'] ?? '';
                $full_name = strtoupper(trim($firstName . ' ' . ($middleName !== '' ? ($middleName[0] . '.') : '') . ' ' . $lastName));
                $address   = strtoupper($emp['present_address'] ?? '');
                $position  = strtoupper($emp['job_title'] ?? '');
                $section   = strtoupper($emp['section'] ?? '');

                $start_input = !empty($_GET['start_date']) ? $_GET['start_date'] : ($emp['hire_date'] ?? '');
                $end_input   = $_GET['end_date'] ?? '';

                $start_date_str = '';
                $end_date_str = '';
                $contract_period = '';

                try {
                    $start_date_obj = new DateTime($start_input ?: 'now');
                    $start_date_str = $start_date_obj->format('F j, Y');
                } catch (Exception $e) {
                    $logger->log($_SESSION['user_id'], 'BULK_CONTRACT_DATE_ERROR', "Invalid start date '$start_input' for employee ID $id: " . $e->getMessage());
                    $start_date_obj = new DateTime('now');
                    $start_date_str = $start_date_obj->format('F j, Y');
                }

                if (!empty($end_input)) {
                    try {
                        $end_date_obj = new DateTime($end_input);
                        $end_date_str = $end_date_obj->format('F j, Y');
                    } catch (Exception $e) {
                        $logger->log($_SESSION['user_id'], 'BULK_CONTRACT_DATE_ERROR', "Invalid end date '$end_input' for employee ID $id: " . $e->getMessage());
                        $end_date_obj = clone $start_date_obj;
                        $end_date_obj->modify('+6 months');
                        $end_date_str = $end_date_obj->format('F j, Y');
                    }
                } else {
                    $end_date_obj = clone $start_date_obj;
                    $end_date_obj->modify('+6 months');
                    $end_date_str = $end_date_obj->format('F j, Y');
                }

                if ($start_date_str !== '' && $end_date_str !== '') {
                    $contract_period = "$start_date_str up to $end_date_str";
                }

                $current_day  = date('jS');
                $current_month = date('F');
                $current_year = date('Y');
                $current_full_date = date('F j, Y');

                // Capture Template Output
                ob_start();
                include $templateFile;
                $content = ob_get_clean();

                // [NEW] Logic to Save Unsigned Copy to Digital 201 File
                if (isset($_POST['auto_save_copy'])) {
                    // 1. Construct File Name per requested format
                    $baseName = "Unsigned Digital Copy of the Document ($friendlyTitle)";
                    $fileExt = "html";
                    $category = (strpos($type, 'notice') !== false) ? 'Disciplinary' : 'Contract';

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

                    // 3. Save Securely to Vault (Using FileService for Encryption)
                    $fileService = new FileService($vaultPath);
                    $tmp = tempnam(sys_get_temp_dir(), 'bulk_gen_');
                    $standaloneHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' .
                        'body { font-family: sans-serif; padding: 20mm; } ' .
                        'p { text-align: justify; line-height: 1.5; }' .
                        '</style></head><body>' . $content . '</body></html>';
                    file_put_contents($tmp, $standaloneHtml);

                    $storedName = $fileService->saveFile($tmp, $finalDisplayName);
                    if ($storedName) {
                        // 4. Register in Documents Table
                        $ins = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by, description) 
                                              VALUES (UUID(), ?, ?, ?, ?, ?, 'Auto-generated unsigned copy.')");
                        $ins->execute([$emp['emp_id'], $finalDisplayName, $storedName, $category, $_SESSION['user_id']]);
                        $logger->log($_SESSION['user_id'], 'AUTO_SAVE_COPY', "Auto-saved unsigned copy: $finalDisplayName for employee {$emp['emp_id']}");
                    }
                    @unlink($tmp);
                }

                // [FIX] Strip outer HTML tags to prevent layout breakage in bulk mode
                // 1. Extract Styles
                preg_match_all('/<style>(.*?)<\/style>/is', $content, $matches);
                $styles = implode("\n", $matches[0]);

                // 2. Extract Body Content
                preg_match('/<body[^>]*>(.*?)<\/body>/is', $content, $bodyMatch);
                $bodyContent = $bodyMatch[1] ?? $content;
                $bodyContent = trim($bodyContent); // Remove extra whitespace

                echo $styles; // Output styles for this segment
                echo '<div class="document-container">';
                echo $bodyContent;
                echo '</div>';
                echo '<div class="page-break"></div>';
            }
        }
        echo '</body></html>';

        $logger->log($_SESSION['user_id'], 'BULK_PRINT', "Generated $type contracts for " . count($ids) . " employees.");
        exit;
    } else {
        $error = "Invalid template or no employees selected.";
    }
}

// 3. FETCH EMPLOYEES
$filter_dept   = $_GET['dept'] ?? '';
$filter_type   = isset($_GET['type']) ? $_GET['type'] : 'TESP Direct';
$search_query  = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_query = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $search_query);

// [NEW] Hire Date Filters
$filter_hired_from = $_GET['hired_from'] ?? '';
$filter_hired_to   = $_GET['hired_to'] ?? '';

$where  = ["status = 'Active'"];
$params = [];

if ($filter_dept !== '') {
    $where[]  = 'dept LIKE ?';
    $params[] = "%{$filter_dept}%";
}
if ($filter_type !== '') {
    $where[] = '(employment_type = ? OR agency_name = ?)';
    $params[] = $filter_type;
    $params[] = $filter_type;
}
if ($search_query !== '') {
    $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $term    = "%{$search_query}%";
    array_push($params, $term, $term, $term);
}
// [NEW] Apply Hire Date Filter
if ($filter_hired_from !== '') {
    $where[] = 'hire_date >= ?';
    $params[] = $filter_hired_from;
}
if ($filter_hired_to !== '') {
    $where[] = 'hire_date <= ?';
    $params[] = $filter_hired_to;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$empSql = "SELECT id, emp_id, first_name, last_name, job_title, dept, system_role, employment_type, agency_name FROM employees {$whereSql} ORDER BY last_name ASC";
$empStmt = $pdo->prepare($empSql);
$empStmt->execute($params);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

$allDepts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE dept != '' ORDER BY dept ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Bulk Contract Generator</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-printer-fill"></i> Bulk Contract Generator</span>
            </div>
        </div>
    </nav>

    <div class="container">

        <!-- FILTERS -->
        <div class="card shadow-sm mb-4">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-2">
                        <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Filter by Department...</option>
                            <?php foreach ($allDepts as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($filter_dept === $d) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Agencies</option>
                            <?php
                            foreach ($agencies as $val) {
                                $sel = ($filter_type === $val) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($val) . "' $sel>" . htmlspecialchars($val) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Name..." value="<?php echo htmlspecialchars($search_query); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ,]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Commas">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="hired_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_hired_from); ?>" title="Hired From">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="hired_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_hired_to); ?>" title="Hired To">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <form method="POST" target="_blank">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <!-- SETTINGS PANEL -->
            <div class="card shadow-sm mb-4 border-primary">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-sliders"></i> Contract Settings (Applied to All Selected)
                </div>
                <div class="card-body bg-white">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Document Type</label>
                            <select name="doc_type" id="docType" class="form-select" required onchange="toggleFields()">
                                <option value="project">Project Contract</option>
                                <option value="probationary">Probationary Contract</option>
                                <option value="coe">Certificate of Employment (COE)</option>
                                <option value="data_consent">Data Privacy Consent Form</option>
                                <option value="confidentiality">Confidentiality (NDA)</option>
                                <option value="notice_to_explain">Notice to Explain (NTE)</option>
                                <option value="notice_of_decision">Notice of Decision (NOD)</option>
                                <option value="employee_pledge">Employee Safety Pledge</option>
                                <option value="whistleblowing">Whistle Blowing Consent</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-center">
                            <!-- [NEW] Auto-Save Copy Toggle -->
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="auto_save_copy" id="autoSaveCopy" value="1">
                                <label class="form-check-label small fw-bold text-primary" for="autoSaveCopy">
                                    <i class="bi bi-cloud-arrow-up-fill"></i> Auto-save Copies
                                </label>
                            </div>
                        </div>
                        <div class="col-md-2 project-field">
                            <label class="form-label small fw-bold">Project Name</label>
                            <input type="text" name="project_name" class="form-control" placeholder="e.g. MRT-3 Rehab" value="<?php echo htmlspecialchars($defProject); ?>"
                                maxlength="100" pattern="[a-zA-Z0-9\s\-\.\(\)]+" title="Allowed: Letters, Numbers, Spaces, - . ( )"
                                oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\(\)]/g, '')">
                        </div>
                        <div class="col-md-2 date-field">
                            <label class="form-label small fw-bold">Start Date</label>
                            <input type="date" name="start_date" id="startDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="calcEndDate()">
                        </div>
                        <div class="col-md-2 date-field">
                            <label class="form-label small fw-bold">Duration</label>
                            <div class="input-group input-group-sm">
                                <select id="durationSelect" class="form-select" onchange="updateDuration()">
                                    <option value="6">6 Mos</option>
                                    <option value="12">1 Yr</option>
                                    <option value="3">3 Mos</option>
                                    <option value="custom">Custom</option>
                                </select>
                                <input type="text" inputmode="numeric" name="duration" id="durationInput" class="form-control" value="6" oninput="validateDuration(this); calcEndDate()" readonly style="max-width: 60px;" maxlength="2">
                            </div>
                        </div>
                        <div class="col-md-2 date-field">
                            <label class="form-label small fw-bold">End Date</label>
                            <input type="date" name="end_date" id="endDate" class="form-control">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="generate_bulk" class="btn btn-success w-100 fw-bold">
                                <i class="bi bi-printer"></i> Generate
                            </button>
                        </div>

                        <!-- [NEW] Advanced Print Settings Row -->
                        <div class="col-12 mt-3 pt-3 border-top">
                            <div class="row g-3 align-items-center">

                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1">Left Margin (px)</label>
                                    <input type="number" name="margin_left" class="form-control form-control-sm" value="<?php echo htmlspecialchars($marginL); ?>" min="3" max="500" oninput="validateMargin(this)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1">Right Margin (px)</label>
                                    <input type="number" name="margin_right" class="form-control form-control-sm" value="<?php echo htmlspecialchars($marginR); ?>" min="3" max="500" oninput="validateMargin(this)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1">Font Size (pt)</label>
                                    <input type="number" step="0.5" name="font_size" class="form-control form-control-sm" value="<?php echo htmlspecialchars($docFontSize); ?>" min="8" max="24" oninput="validateFontSize(this)">
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" name="save_defaults" id="saveDef">
                                        <label class="form-check-label small text-muted" for="saveDef">Save as Default Settings</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">&nbsp;</label>
                                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="resetMargins()" title="Reset Margins and Font Size">Reset Default</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- EMPLOYEE TABLE -->
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 50px;"><input type="checkbox" class="form-check-input" id="select_all"></th>
                                    <th>Employee <span id="selection-count" class="badge bg-primary ms-1" style="display:none">0</span></th>
                                    <th>Job Title</th>
                                    <th>Department</th>
                                    <th>Role</th>
                                    <th>Agency</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center p-4 text-muted">No employees found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employees as $emp): ?>
                                        <tr>
                                            <td class="text-center"><input type="checkbox" class="form-check-input emp-checkbox" name="employee_ids[]" value="<?php echo $emp['id']; ?>"></td>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($emp['emp_id']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($emp['job_title']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($emp['system_role'] ?? 'N/A'); ?></span></td>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($emp['agency_name'] ?: $emp['employment_type']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function updateCount() {
            const count = document.querySelectorAll('.emp-checkbox:checked').length;
            const badge = document.getElementById('selection-count');
            if (badge) {
                badge.innerText = count;
                badge.style.display = count > 0 ? 'inline-block' : 'none';
            }
        }

        document.getElementById('select_all').addEventListener('change', function(e) {
            document.querySelectorAll('.emp-checkbox').forEach(checkbox => {
                // [FIX] Only select visible rows (handles pagination/filtering)
                if (checkbox.offsetParent !== null) {
                    checkbox.checked = e.target.checked;
                }
            });
            updateCount();
        });

        function toggleFields() {
            const type = document.getElementById('docType').value;
            const projFields = document.querySelectorAll('.project-field');

            if (type === 'project') {
                projFields.forEach(el => el.style.display = 'block');
            } else {
                projFields.forEach(el => el.style.display = 'none');
            }
        }

        function updateDuration() {
            const select = document.getElementById('durationSelect');
            const input = document.getElementById('durationInput');
            if (select.value !== 'custom') {
                input.value = select.value;
                input.readOnly = true;
                calcEndDate();
            } else {
                input.readOnly = false;
                input.focus();
            }
        }

        function validateDuration(input) {
            // [NEW] Strict Validation: Numbers only, max 2 digits, max value 99
            input.value = input.value.replace(/[^0-9]/g, '');
            if (input.value.length > 2) input.value = input.value.slice(0, 2);
            if (parseInt(input.value) > 99) input.value = '99';
        }

        function validateMargin(input) {
            // [NEW] Strict Validation: Numbers only, max 500
            input.value = input.value.replace(/[^0-9]/g, '');
            if (input.value.length > 3) input.value = input.value.slice(0, 3);
            if (input.value !== '' && parseInt(input.value) > 500) input.value = '500';
        }

        function validateFontSize(input) {
            input.value = input.value.replace(/[^0-9\.]/g, '');
            if ((input.value.match(/\./g) || []).length > 1) input.value = input.value.replace(/\.$/, '');
            if (input.value.length > 4) input.value = input.value.slice(0, 4);
            if (parseFloat(input.value) > 24) input.value = '24';
        }

        function resetMargins() {
            document.querySelector('input[name="margin_left"]').value = '30';
            document.querySelector('input[name="margin_right"]').value = '20';
            document.querySelector('input[name="font_size"]').value = '11';
        }

        function calcEndDate() {
            const startVal = document.getElementById('startDate').value;
            const duration = document.getElementById('durationInput').value;
            const endInput = document.getElementById('endDate');

            if (!startVal || !duration) return;

            const date = new Date(startVal);
            date.setMonth(date.getMonth() + parseInt(duration));
            const yyyy = date.getFullYear();
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const dd = String(date.getDate()).padStart(2, '0');
            endInput.value = `${yyyy}-${mm}-${dd}`;
        }

        // [NEW] Confirmation & Safety Check for Bulk Print
        document.querySelector('button[name="generate_bulk"]').addEventListener('click', function(e) {
            e.preventDefault(); // Stop default submit to show alert

            const deptSelect = document.querySelector('select[name="dept"]');
            // Check if "All Departments" is selected in the filter (value is empty)
            // Note: The select box reflects the current filter state.
            const deptVal = deptSelect ? deptSelect.value : "";
            const checkedCount = document.querySelectorAll('.emp-checkbox:checked').length;

            if (checkedCount === 0) {
                Swal.fire('No Selection', 'Please select at least one employee.', 'warning');
                return;
            }

            // If Dept is empty (All) AND count is high (e.g. > 20)
            if (deptVal === "" && checkedCount > 20) {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Prohibited',
                    text: 'Printing "All Departments" at once is prohibited to prevent system overload and duplication. Please filter by a specific Department first.'
                });
                return;
            }

            // Confirmation Dialog
            Swal.fire({
                title: 'Generate Contracts?',
                text: `You are about to generate ${checkedCount} document(s).`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Generate',
                confirmButtonColor: '#198754'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create hidden input to simulate button click (since preventDefault killed it)
                    const form = this.closest('form');
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'generate_bulk';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);

                    // Show Loader
                    Swal.fire({
                        title: 'Generating Documents...',
                        html: `
                            <p class="text-muted small mb-3">Compiling data and formatting pages. Please wait...</p>
                            <div class="progress mb-3" style="height: 25px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 100%"></div>
                            </div>
                            <span class="text-danger fw-bold small">This may take a few moments. Do not close this window!</span>
                        `,
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false
                    });

                    const csrf = form.querySelector('[name="csrf_token"]').value;
                    let attempts = 0;
                    const maxAttempts = 300; // 5 minutes
                    const checkCookie = setInterval(() => {
                        attempts++;
                        if (document.cookie.includes('downloadToken=' + csrf)) {
                            clearInterval(checkCookie);
                            Swal.close();
                            document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                            return;
                        }
                        if (attempts >= maxAttempts) {
                            clearInterval(checkCookie);
                            Swal.close();
                            document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                            Swal.fire({
                                icon: 'error',
                                title: 'Timeout',
                                text: 'The download did not start within a few minutes. Please try again or check your browser settings.'
                            });
                        }
                    }, 1000);

                    form.submit();
                }
            });
        });

        toggleFields(); // Init
        calcEndDate(); // Run on load

        // Attach listener to individual checkboxes
        document.querySelectorAll('.emp-checkbox').forEach(cb => {
            cb.addEventListener('change', updateCount);
        });
    </script>
    <script src="dark_mode.js"></script>
</body>

</html>