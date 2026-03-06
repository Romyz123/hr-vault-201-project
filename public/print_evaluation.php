<?php
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
        FROM performance_evaluations ev 
        JOIN employees e ON ev.employee_id = e.id 
        WHERE ev.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Evaluation record not found.");

// permission check: only owner or admin/hr/manager can view
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['ADMIN', 'HR', 'MANAGER'], true) && $data['employee_id'] !== $userId) {
    die("Access Denied");
}

// Logo
$logo_path = __DIR__ . '/uploads/tesp logo 1.png';
$logo_src = '';
if (file_exists($logo_path)) {
    $logo_binary = file_get_contents($logo_path);
    $logo_src = 'data:image/png;base64,' . base64_encode($logo_binary);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Performance Evaluation - <?php echo htmlspecialchars($data['last_name']); ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            line-height: 1.3;
            color: #000;
            background: #eee;
            margin: 0;
            padding: 20px 0;
        }

        .page {
            background: white;
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto;
            padding: 10mm;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
            box-sizing: border-box;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 3px double #000;
            padding-bottom: 20px;
        }

        .logo {
            width: 100px;
            display: block;
            margin: 0 auto 10px auto;
        }

        .title {
            font-weight: bold;
            font-size: 18pt;
            text-transform: uppercase;
            margin-top: 10px;
        }

        .subtitle {
            font-size: 11pt;
            font-weight: bold;
        }

        .info-table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 8px 5px;
            vertical-align: top;
            border-bottom: 1px solid #ddd;
        }

        .label {
            font-weight: bold;
            width: 160px;
            color: #333;
        }

        .score-box {
            border: 1px solid #000;
            padding: 20px;
            text-align: center;
            margin-bottom: 30px;
            background: #fff;
            box-shadow: 3px 3px 0px #eee;
        }

        .score-val {
            font-size: 32pt;
            font-weight: bold;
            margin: 10px 0;
        }

        .rating-val {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 15px;
            border: 2px solid #000;
            display: inline-block;
        }

        .section-title {
            font-weight: bold;
            border-bottom: 2px solid #000;
            margin-bottom: 10px;
            padding-bottom: 3px;
            text-transform: uppercase;
            font-size: 11pt;
            margin-top: 20px;
        }

        .remarks-box {
            text-align: justify;
            border: 1px solid #000;
            padding: 20px;
            min-height: 150px;
            white-space: pre-wrap;
            font-family: Arial, sans-serif;
            /* Easier to read for long text */
            font-size: 11pt;
        }

        .footer {
            margin-top: 60px;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 80%;
            margin-top: 50px;
            margin-bottom: 5px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                background: white;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .page {
                width: 100%;
                height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border: none;
                page-break-after: auto;
            }

            .score-box {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: center; background: #f0f0f0; padding: 15px;">
        <button onclick="window.print()" style="padding: 10px 20px; font-weight: bold; cursor: pointer; background: #000; color: #fff; border: none;">🖨️ Print Report</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-weight: bold; cursor: pointer; background: #ccc; border: none; margin-left: 10px;">Close</button>
    </div>

    <div class="page">
        <div class="header">
            <?php if ($logo_src): ?><img src="<?php echo $logo_src; ?>" class="logo"><?php endif; ?>
            <div class="subtitle">TES PHILIPPINES, INC.</div>
            <div class="title">Performance Evaluation Report</div>
        </div>

        <table class="info-table">
            <tr>
                <td class="label">Employee Name:</td>
                <td style="width: 35%;"><?php echo htmlspecialchars($data['last_name'] . ', ' . $data['first_name']); ?></td>
                <td class="label">Employee ID:</td>
                <td><?php echo htmlspecialchars($data['emp_id']); ?></td>
            </tr>
            <tr>
                <td class="label">Department:</td>
                <td><?php echo htmlspecialchars($data['dept']); ?></td>
                <td class="label">Job Title:</td>
                <td><?php echo htmlspecialchars($data['job_title']); ?></td>
            </tr>
            <tr>
                <td class="label">Evaluation Date:</td>
                <td><?php echo date('F d, Y', strtotime($data['eval_date'])); ?></td>
                <td class="label">Evaluator:</td>
                <td><?php echo htmlspecialchars($data['evaluator']); ?></td>
            </tr>
        </table>

        <div class="score-box">
            <div style="font-size: 10pt; text-transform: uppercase; letter-spacing: 2px;">Overall Performance Score</div>
            <div class="score-val"><?php echo htmlspecialchars($data['score']); ?> / 100</div>
            <div class="rating-val"><?php echo htmlspecialchars($data['rating']); ?></div>
        </div>

        <div class="section-title">EVALUATOR'S REMARKS / COMMENTS</div>
        <div class="remarks-box"><?php echo htmlspecialchars($data['remarks']); ?></div>

        <div class="footer">
            <table style="width: 100%;">
                <tr>
                    <td style="width: 50%; padding-right: 20px;">
                        <div style="font-weight: bold;">Evaluated by:</div>
                        <div class="sig-line"></div>
                        <div style="font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($data['evaluator']); ?></div>
                        <div style="font-size: 9pt;">Evaluator / Supervisor</div>
                    </td>
                    <td style="width: 50%; padding-left: 20px;">
                        <div style="font-weight: bold;">Acknowledged by:</div>
                        <div class="sig-line"></div>
                        <div style="font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></div>
                        <div style="font-size: 9pt;">Employee Signature</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <script>
        // Optional: Auto-print removed to allow preview
    </script>
</body>

</html>