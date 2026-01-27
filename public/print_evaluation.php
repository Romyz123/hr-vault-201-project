<?php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) { die("Access Denied"); }

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("
    SELECT ev.*, e.first_name, e.last_name, e.emp_id, e.dept, e.job_title 
    FROM performance_evaluations ev 
    JOIN employees e ON ev.employee_id = e.id 
    WHERE ev.id = ?
");
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Evaluation not found.");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Performance Evaluation - <?php echo htmlspecialchars($data['last_name']); ?></title>
    <style>
        body { font-family: "Times New Roman", serif; padding: 40px; max-width: 800px; margin: 0 auto; color: #000; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 24px; font-weight: bold; text-transform: uppercase; }
        .sub-header { font-size: 14px; }
        .title { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 30px; text-transform: uppercase; text-decoration: underline; }
        
        .grid { display: table; width: 100%; margin-bottom: 20px; }
        .row { display: table-row; }
        .cell { display: table-cell; width: 50%; padding-bottom: 10px; vertical-align: top; }
        
        .label { font-weight: bold; font-size: 12px; text-transform: uppercase; display: block; color: #444; }
        .value { font-size: 14px; border-bottom: 1px dotted #999; display: inline-block; width: 90%; padding-bottom: 2px; }
        
        .score-box { text-align: center; border: 2px solid #000; padding: 20px; margin: 30px 0; background: #f8f9fa; }
        .score-val { font-size: 42px; font-weight: bold; }
        .rating-val { font-size: 20px; font-weight: bold; text-transform: uppercase; margin-top: 5px; }
        
        .remarks-section { margin-top: 20px; }
        .remarks-box { border: 1px solid #000; padding: 15px; min-height: 120px; margin-top: 5px; font-size: 14px; line-height: 1.5; }
        
        .footer { margin-top: 60px; display: table; width: 100%; }
        .sig-block { display: table-cell; width: 50%; vertical-align: top; padding-right: 20px; }
        .sig-line { border-top: 1px solid #000; width: 80%; margin-top: 40px; }
        .sig-label { font-size: 12px; font-weight: bold; margin-top: 5px; }
        
        @media print {
            body { padding: 0; margin: 0; }
            .no-print { display: none; }
            .score-box { background: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #333; color: white; border: none;">Print Report</button>
    </div>

    <div class="header">
        <div class="logo">TES Philippines, Inc.</div>
        <div class="sub-header">Human Resources Department</div>
    </div>

    <div class="title">Performance Evaluation Report</div>

    <div class="grid">
        <div class="row">
            <div class="cell">
                <span class="label">Employee Name</span>
                <span class="value"><?php echo htmlspecialchars($data['last_name'] . ', ' . $data['first_name']); ?></span>
            </div>
            <div class="cell">
                <span class="label">Employee ID</span>
                <span class="value"><?php echo htmlspecialchars($data['emp_id']); ?></span>
            </div>
        </div>
        <div class="row">
            <div class="cell">
                <span class="label">Department</span>
                <span class="value"><?php echo htmlspecialchars($data['dept']); ?></span>
            </div>
            <div class="cell">
                <span class="label">Job Title</span>
                <span class="value"><?php echo htmlspecialchars($data['job_title']); ?></span>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="row">
            <div class="cell">
                <span class="label">Evaluation Date</span>
                <span class="value"><?php echo date('F d, Y', strtotime($data['eval_date'])); ?></span>
            </div>
            <div class="cell">
                <span class="label">Evaluated By</span>
                <span class="value"><?php echo htmlspecialchars($data['evaluator']); ?></span>
            </div>
        </div>
    </div>

    <div class="score-box">
        <div class="label" style="margin-bottom: 10px;">Overall Performance Rating</div>
        <div class="score-val"><?php echo $data['score']; ?>%</div>
        <div class="rating-val"><?php echo htmlspecialchars($data['rating']); ?></div>
    </div>

    <div class="remarks-section">
        <div class="label">Evaluator's Remarks / Comments:</div>
        <div class="remarks-box">
            <?php echo nl2br(htmlspecialchars($data['remarks'] ?? '')); ?>
        </div>
    </div>

    <div class="footer">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Evaluator Signature</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Employee Acknowledgment</div>
            <div style="font-size: 10px; color: #666; margin-top: 5px;">I acknowledge that this evaluation has been discussed with me.</div>
        </div>
    </div>
    
    <script>
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>