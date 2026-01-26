<?php
// ======================================================
// [FILE] public/generate_document.php
// [STATUS] DOCUMENT ENGINE: Handles Contract & NDA Generation
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Admin/HR/Staff
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['ADMIN', 'HR', 'STAFF'])) {
    die("ACCESS DENIED");
}

// 2. FETCH EMPLOYEE
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

if ($id <= 0 || empty($type)) die("Invalid Request");

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) die("Employee not found.");

// 3. PREPARE VARIABLES (Auto-Fill Logic)
$full_name = strtoupper($emp['first_name'] . ' ' . (empty($emp['middle_name']) ? '' : $emp['middle_name'][0] . '.') . ' ' . $emp['last_name']);
$address   = strtoupper($emp['present_address']);
$position  = strtoupper($emp['job_title']);
$section   = strtoupper($emp['section']);

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

// [LOGGING] Record document generation
$logger = new Logger($pdo);
$logger->log($_SESSION['user_id'], 'GENERATE_DOC', "Generated $type for {$emp['first_name']} {$emp['last_name']} ({$emp['emp_id']})");

// 4. LOAD TEMPLATE
// We wrap the template in a clean HTML container for printing
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Document: <?php echo htmlspecialchars($type); ?></title>
    <style>
        body { font-family: "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.5; color: #000; background: #eee; }
        .page { background: white; width: 8in; min-height: 11in; padding: 0.5in; /* [PREVIEW CONTROL] Keep this same as print margin */ margin: 20px auto; box-shadow: 0 0 10px rgba(0,0,0,0.1); position: relative; }
        .no-print { position: fixed; top: 20px; right: 20px; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .text-uppercase { text-transform: uppercase; }
        .signature-line { border-top: 1px solid #000; width: 200px; display: inline-block; margin-top: 30px; }
        .justify { text-align: justify; }
        
        @media print {
            body { background: white; }
            .page { box-shadow: none; margin: 0; width: 100%; }
            .no-print { display: none; }
            @page { margin: 0.5in; } /* Minimal margins for printer */
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px;">🖨️ Print / Save as PDF</button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; cursor: pointer; font-weight: bold; border-radius: 5px; margin-left: 10px;">Close</button>
    </div>

    <div class="page">
        <?php if ($type !== 'probationary_lms' && $type !== 'confidentiality'): ?>
        <table style="width: 100%; margin-bottom: 10px;">
            <tr>
                <td style="width: 100px; text-align: center; vertical-align: middle;">
                    <img src="uploads/<?php echo rawurlencode('tesp logo 1.png'); ?>" 
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
        <hr style="border: 1px solid black; margin-top: 5px; margin-bottom: 30px;">
        <?php endif; ?>

        <?php 
        $templateFile = '';
        if ($type === 'probationary_lms' || $type === 'probationary') {
            $templateFile = __DIR__ . '/templates/contract_probationary_lms.php';
            // Fallback: If the new LMS file doesn't exist yet, use the old one
            if (!file_exists($templateFile)) {
                $templateFile = __DIR__ . '/templates/contract_probationary.php';
            }
        } elseif ($type === 'confidentiality') {
            $templateFile = __DIR__ . '/templates/confidentiality_agreement.php';
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