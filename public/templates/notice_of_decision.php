<?php
// public/templates/notice_of_decision.php
if (!isset($emp)) {
    die("Access Denied");
}

// 1. Prepare Data
$date_created = (!empty($_GET['notice_date'])) ? date('F d, Y', strtotime($_GET['notice_date'])) : '___________________________';
$violation = (!empty($_GET['violation'])) ? nl2br(htmlspecialchars($_GET['violation'])) : '__________________________________________________________________';
$decision = (!empty($_GET['decision'])) ? nl2br(htmlspecialchars($_GET['decision'])) : '<br><br><br>'; // Empty space for handwriting
$incident_date = (!empty($_GET['incident_date'])) ? date('F d, Y', strtotime($_GET['incident_date'])) : '_________________';

// 2. Base64 Logo
$logo_path = __DIR__ . '/../uploads/tesp logo 1.png';
$logo_src = 'uploads/' . rawurlencode('tesp logo 1.png');
if (file_exists($logo_path)) {
    $logo_binary = file_get_contents($logo_path);
    $logo_src = 'data:image/png;base64,' . base64_encode($logo_binary);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Notice of Decision - <?php echo htmlspecialchars($emp['last_name']); ?></title>
    <style>
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #000;
            vertical-align: top;
        }

        .container {
            width: 90%;
            max-width: 900px;

        }



        .header-wrapper {
            width: 100%;
            border-bottom: 2px solid black;
            margin-bottom: 20px;
            padding-bottom: 10px;
            text-align: center;
        }

        .header-content-table {
            width: auto;
            margin: 0 auto;
            border-collapse: collapse;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        th,
        td {
            padding: 5px;
            vertical-align: top;
        }

        .bordered-table,
        .bordered-table th,
        .bordered-table td {
            border: 1px solid black;
        }

        .label {
            font-weight: bold;
            width: 15%;
            background: #eee;
        }

        .offense-grid {
            font-size: 10pt;
            margin-bottom: 20px;
        }

        .offense-grid td {
            width: 33%;
            border: 1px solid #999;
            padding: 4px;
            text-align: center;
        }

        .signatory-table {
            margin-top: 40px;
        }

        .signatory-table td {
            width: 50%;
            padding-bottom: 40px;
        }
    </style>
</head>

<body>

    <div class="container">

        <div class="header-wrapper">
            <table class="header-content-table">
                <tr>
                    <td style="padding-right: 15px; vertical-align: middle;">
                        <img src="<?php echo $logo_src; ?>" class="logo" style="width: 80px; display: block;">
                    </td>
                    <td style="vertical-align: middle; text-align: center;">
                        <strong style="font-size: 14pt;">TES PHILIPPINES INC.</strong><br>
                        <span style="font-size: 11pt;"> General Affairs Group (GAG) Human Resources </span><br>
                        <small>Disciplinary Action Notice</small>
                    </td>
                </tr>
            </table>
        </div>

        <div class="title">NOTICE OF DECISION</div>

        <table class="bordered-table" style="width: 100%; margin-bottom: 20px;">
            <tr>
                <td class="label">Name</td>
                <td style="width: 45%;"><?php echo strtoupper(htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name'])); ?></td>
                <td class="label">Date</td>
                <td><?php echo $date_created; ?></td>
            </tr>
            <tr>
                <td class="label">Code</td>
                <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                <td class="label">Section</td>
                <td><?php echo htmlspecialchars($emp['dept'] . ' / ' . $emp['section']); ?></td>
            </tr>
        </table>

        <p style="text-align: justify; margin: 20px 0;">
            This refers to the incident report dated <strong><?php echo $incident_date; ?></strong> regarding your alleged violation:<br><br><strong><?php echo $violation; ?></strong>.
        </p>

        <p style="text-align: justify; margin: 20px 0;">
            After a thorough investigation and review of the explanation you provided (or failure to provide one within the prescribed period), the Management has found substantial evidence to support the finding of guilt.
        </p>

        <div style="border: 2px solid black; padding: 15px; margin: 20px 0; background: #f9f9f9;">
            <strong>DECISION / SANCTION:</strong><br><br>
            <span style="font-size: 14pt; font-weight: bold;"><?php echo $decision; ?></span>
        </div>

        <p style="text-align: justify; margin: 20px 0;">
            This decision is effective immediately. A copy of this notice will be placed in your 201 File.
            You are expected to strictly comply with Company Rules and Regulations moving forward. Future infractions will be dealt with more severely.
        </p>

        <table class="signatory-table">
            <tr>
                <td>__________________________<br><strong>Admin / HR Manager</strong></td>
                <td>__________________________<br><strong>Department Manager</strong></td>
            </tr>
            <tr>
                <td>__________________________<br><strong>Group / Section Head</strong></td>
                <td>__________________________<br><strong>Senior / General Manager</strong></td>
            </tr>
        </table>

        <div style="margin-top: 30px; border-top: 1px dashed black; padding-top: 10px;">
            <strong>Received by:</strong><br><br>
            <table style="width: 100%;">
                <tr>
                    <td style="width: 60%; border-bottom: 1px solid black;">Name & Signature:</td>
                    <td style="width: 5%;"></td>
                    <td style="width: 35%; border-bottom: 1px solid black;">Date & Time:</td>
                </tr>
            </table>
        </div>
    </div>
</body>

</html>