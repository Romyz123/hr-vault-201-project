<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch Evaluation + Employee Info
$sql = "SELECT ev.*, e.first_name, e.last_name, e.emp_id, e.dept, e.job_title 
        FROM performance_evaluations ev 
        JOIN employees e ON ev.employee_id = e.id 
        WHERE ev.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Evaluation record not found.");

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
            size: Letter;
            margin: 0.5in;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            color: #000;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }

        .logo {
            width: 80px;
            display: block;
            margin: 0 auto 10px auto;
        }

        .title {
            font-weight: bold;
            font-size: 16pt;
            text-transform: uppercase;
        }

        .subtitle {
            font-size: 10pt;
        }

        .info-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 5px;
            vertical-align: top;
        }

        .label {
            font-weight: bold;
            width: 150px;
        }

        .score-box {
            border: 2px solid #000;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
            background: #f9f9f9;
        }

        .score-val {
            font-size: 24pt;
            font-weight: bold;
        }

        .rating-val {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .section-title {
            font-weight: bold;
            border-bottom: 1px solid #000;
            margin-bottom: 10px;
            padding-bottom: 3px;
            text-transform: uppercase;
        }

        .remarks-box {
            text-align: justify;
            /* THE REQUESTED FIX */
            border: 1px solid #ccc;
            padding: 15px;
            min-height: 100px;
            white-space: pre-wrap;
            /* Preserve line breaks */
        }

        .footer {
            margin-top: 50px;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 250px;
            margin-top: 40px;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; font-weight: bold; cursor: pointer;">🖨️ Print Report</button>
    </div>

    <div class="header">
        <?php if ($logo_src): ?><img src="<?php echo $logo_src; ?>" class="logo"><?php endif; ?>
        <div class="title">Performance Evaluation Report</div>
        <div class="subtitle">TES PHILIPPINES, INC.</div>
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
        <tr>
            <td class="label">Evaluation Date:</td>
            <td><?php echo date('F d, Y', strtotime($data['eval_date'])); ?></td>
            <td class="label">Evaluator:</td>
            <td><?php echo htmlspecialchars($data['evaluator']); ?></td>
        </tr>
    </table>

    <div class="score-box">
        <div>OVERALL SCORE</div>
        <div class="score-val"><?php echo $data['score']; ?> / 100</div>
        <div class="rating-val"><?php echo htmlspecialchars($data['rating']); ?></div>
    </div>

    <div class="section-title">EVALUATOR'S REMARKS / COMMENTS</div>
    <div class="remarks-box"><?php echo htmlspecialchars($data['remarks']); ?></div>

    <div class="footer">
        <table style="width: 100%;">
            <tr>
                <td style="width: 50%;">
                    <div>Evaluated by:</div>
                    <div class="sig-line"></div>
                    <strong><?php echo htmlspecialchars($data['evaluator']); ?></strong>
                </td>
                <td style="width: 50%;">
                    <div>Acknowledged by:</div>
                    <div class="sig-line"></div>
                    <strong><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></strong>
                </td>
            </tr>
        </table>
    </div>

    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>

</html>