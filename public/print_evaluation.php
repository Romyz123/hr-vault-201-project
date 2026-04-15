<?php
// --- START: UI REPAIR ---
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Access Denied");
}

// Fetch Evaluation + Employee Info
$sql = "SELECT ev.*, e.first_name, e.last_name, e.emp_id, e.dept, e.job_title, ev.employee_id 
        FROM hr_performance_reviews ev 
        JOIN employees e ON ev.employee_id = e.id 
        WHERE ev.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    $sql = "SELECT ev.*, e.first_name, e.last_name, e.emp_id, e.dept, e.job_title, ev.employee_id 
            FROM performance_evaluations ev 
            JOIN employees e ON ev.employee_id = e.id 
            WHERE ev.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$data) die("Evaluation record not found.");

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['ADMIN', 'HR', 'MANAGER'], true) && $data['employee_id'] !== $userId) {
    die("Access Denied");
}

// Logo Logic
$logo_paths = [
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $mime = pathinfo($p, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg';
        $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
} ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Performance Evaluation - <?php echo htmlspecialchars($data['last_name']); ?></title>
    <style>
        /* Force A4 Portrait */
        @page {
            size: A4 portrait;
            margin: 0;
            /* Margin handled by .page padding for better control */
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            background: #eee;
            margin: 0;
            padding: 0;
        }

        /* The Paper Container */
        .page {
            background: white;
            width: 210mm;
            height: 297mm;
            /* Forced height to ensure exactly one page */
            margin: 10px auto;
            padding: 15mm;
            /* Standard professional margin */
            box-sizing: border-box;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);

            /* Flexbox layout to distribute space */
            display: flex;
            flex-direction: column;
            overflow: hidden;
            /* Prevents accidental second page */
        }

        .header {
            border-bottom: 3px double #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .info-table td {
            padding: 6px;
            border-bottom: 1px solid #eee;
        }

        .label {
            font-weight: bold;
            width: 140px;
        }

        .rating-legend {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9pt;
        }

        .rating-legend th,
        .rating-legend td {
            border: 1px solid #ddd;
            padding: 4px;
            text-align: center;
        }

        .rating-legend th {
            background: #f8f9fa;
        }

        .score-box {
            border: 2px solid #000;
            padding: 12px;
            text-align: center;
            background: #fdfdfd;
            margin-bottom: 20px;
        }

        .section-title {
            font-weight: bold;
            border-bottom: 2px solid #000;
            padding-bottom: 3px;
            text-transform: uppercase;
            font-size: 10pt;
        }

        /* This container grows to fill the "free space" */
        .remarks-container {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            margin-bottom: 30px;
        }

        .content-box {
            flex-grow: 1;
            border: 1px solid #000;
            padding: 12px;
            margin-top: 8px;
            background: #fafafa;
            white-space: pre-wrap;
            line-height: 1.4;
        }

        .footer {
            margin-top: auto;
            /* Pushes signatures to the bottom */
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        .sig-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            margin-bottom: 5px;
            width: 90%;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: white;
                padding: 0;
            }

            .page {
                margin: 0;
                box-shadow: none;
                width: 100%;
                height: 100vh;
                /* Use viewport height for print */
            }
        }
    </style>
</head>

<body>

    <div class="no-print" style="text-align: center; background: #333; padding: 10px;">
        <button onclick="window.print()" style="padding: 10px 20px; font-weight: bold; cursor: pointer; background: #28a745; color: #fff; border: none; border-radius: 4px;">🖨️ Print A4 Portrait Report</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-weight: bold; cursor: pointer; background: #6c757d; color: #fff; border: none; border-radius: 4px; margin-left: 10px;">Close</button>

    </div>

    <div class="page">
        <div class="header">
            <img src="<?php echo $logo_src; ?>" style="max-height: 55px; margin-bottom: 5px;">
            <div style="font-size: 16pt; font-weight: bold; letter-spacing: 1px;">TES PHILIPPINES, INC.</div>
            <div style="font-size: 11pt; font-weight: bold; text-transform: uppercase;">Performance Evaluation Record</div>
        </div>

        <table class="info-table">
            <tr>
                <td class="label">Employee Name:</td>
                <td><?php echo htmlspecialchars($data['last_name'] . ', ' . $data['first_name']); ?></td>
                <td class="label">Employee ID:</td>
                <td><?php echo htmlspecialchars($data['emp_id']); ?></td>
            </tr>
            <tr>
                <td class="label">Department:</td>
                <td><?php echo htmlspecialchars($data['dept']); ?></td>
                <td class="label">Job Title:</td>
                <td><?php echo htmlspecialchars($data['job_title']); ?></td>
            </tr>
        </table>

        <div style="font-size: 8.5pt; font-weight: bold; margin-bottom: 4px;">RATING SCALE REFERENCE:</div>
        <table class="rating-legend">
            <thead>
                <tr>
                    <th>Score Range</th>
                    <th>90 - 100</th>
                    <th>80 - 89</th>
                    <th>70 - 79</th>
                    <th>Below 70</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Adjective Rating</strong></td>
                    <td>Excellent</td>
                    <td>Very Satisfactory</td>
                    <td>Satisfactory</td>
                    <td>Needs Improvement</td>
                </tr>
            </tbody>
        </table>

        <div class="score-box">
            <div style="font-size: 8.5pt; letter-spacing: 1px; font-weight: bold;">OVERALL PERFORMANCE SCORE</div>
            <div style="font-size: 30pt; font-weight: bold; margin: 5px 0;"><?php echo $data['score']; ?> / 100</div>
            <div style="font-size: 12pt; font-weight: bold; border: 2px solid #000; display: inline-block; padding: 3px 20px; text-transform: uppercase;">
                <?php echo $data['rating']; ?>
            </div>
        </div>

        <div class="remarks-container">
            <div class="section-title">Evaluator's Remarks / Comments</div>
            <div class="content-box">
                <?php echo nl2br(htmlspecialchars($data['remarks'] ?? 'No comments provided.')); ?>
            </div>
        </div>

        <div class="footer">
            <table style="width: 100%; border: none;">
                <tr>
                    <td style="width: 50%; vertical-align: top;">
                        <div style="font-weight: bold; font-size: 10pt;">Evaluated by:</div>
                        <div class="sig-line"></div>
                        <div style="text-transform: uppercase; font-weight: bold; font-size: 10pt;"><?php echo htmlspecialchars($data['evaluator']); ?></div>
                        <div style="font-size: 9pt; color: #555;">Immediate Supervisor / Evaluator</div>
                    </td>
                    <td style="width: 50%; vertical-align: top;">
                        <div style="font-weight: bold; font-size: 10pt;">Acknowledged by:</div>
                        <div class="sig-line"></div>
                        <div style="text-transform: uppercase; font-weight: bold; font-size: 10pt;"><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></div>
                        <div style="font-size: 9pt; color: #555;">Employee Signature / Date</div>
                    </td>
                </tr>
            </table>
            <div style="text-align: center; font-size: 8pt; color: #aaa; margin-top: 15px;">
                HR Vault 201 System - Printed on <?php echo date('F j, Y, g:i A'); ?>
            </div>
        </div>
    </div>

</body>

</html>