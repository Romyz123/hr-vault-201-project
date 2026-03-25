<?php
// public/templates/notice_to_explain.php
if (!isset($emp)) {
    die("Access Denied");
}

// 1. Prepare Data
$date_created = (!empty($_GET['notice_date'])) ? date('F d, Y', strtotime($_GET['notice_date'])) : '___________________________';
$incident_date = (!empty($_GET['incident_date'])) ? date('F d, Y h:i A', strtotime($_GET['incident_date'])) : '___________________________';
$incident_place = (!empty($_GET['incident_place'])) ? htmlspecialchars($_GET['incident_place']) : '___________________________';
$allegation = nl2br(htmlspecialchars($_GET['allegation'] ?? ''));
$rule_violated = nl2br(htmlspecialchars($_GET['rule_violated'] ?? ''));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Notice to Explain - <?php echo htmlspecialchars($emp['last_name']); ?></title>
    <style>
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #000;
            vertical-align: top;
        }

        .container {
            width: 100%;
            max-width: 700px;

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
            <table style="width: 100%; margin-bottom: 10px;">
                <tr>
                    <td style="width: 130px; text-align: right; vertical-align: middle; padding-right: 15px;">
                        <?php if (!empty($global_logo_src)): ?>
                            <img src="<?php echo htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8'); ?>" class="logo" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;">
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align: middle; text-align: center;">
                        <div style="font-weight: bold; font-size: 15pt !important; line-height: 1.2; white-space: nowrap;">TES PHILIPPINES, INC.</div>
                        <div style="font-weight: bold; font-size: 11pt !important; line-height: 1.2; white-space: nowrap;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                        <div style="font-size: 11pt !important; line-height: 1.2;">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                        <div style="font-size: 11pt !important; line-height: 1.2;">Telephone Number: 8929-5347 local 4404</div>
                    </td>
                    <td style="width: 70px;"></td> <!-- Spacer for shifting text right -->
                </tr>
            </table>
        </div>

        <div class="title">NOTICE TO EXPLAIN</div>

        <table class="bordered-table">
            <tr>
                <td class="label">Name</td>
                <td style="width: 45%;"><?php echo strtoupper(htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name'])); ?></td>
                <td class="label">Date</td>
                <td><?php echo $date_created; ?></td>
            </tr>
            <tr>
                <td class="label">Code</td>
                <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>

            </tr>
            <tr>
                <td class="label">Section</td>
                <td colspan="3"><?php echo htmlspecialchars($emp['dept'] . ' / ' . $emp['section']); ?></td>
            </tr>
        </table>

        <p style="text-align: justify; margin: 20px 0;">
            You are hereby directed to submit a written explanation within <strong>three (3) days</strong> from receipt hereof why you should not be administratively charged and investigated for the following alleged violation of Company Rules and Regulations.
        </p>

        <table class="offense-grid">
            <tr>
                <td>Offenses Against Compliance Rules</td>
                <td>Offense Against Security</td>
                <td>Offenses Against Authority</td>
            </tr>
            <tr>
                <td>Offenses Against Safety</td>
                <td>Offenses Against Properties</td>
                <td>Offenses Against Attendance</td>
            </tr>
            <tr>
                <td>Offenses Against Office Decorum</td>
                <td>Offenses Against Timekeeping</td>
                <td>Offenses Against Company Interest</td>
            </tr>
        </table>

        <table class="bordered-table">
            <tr>
                <td class="label">Date/Time of Incident</td>
                <td><?php echo $incident_date; ?></td>
                <td class="label">Place</td>
                <td><?php echo $incident_place; ?></td>
            </tr>
            <tr>
                <td colspan="4" class="label">Nature of Allegation</td>
            </tr>
            <tr>
                <td colspan="4" style="height: 200px; vertical-align: top; text-align: justify; text-justify: inter-word;">
                    <?php echo $allegation ?: '(Please narrate in detail the event or situation that triggers this notice)'; ?>
                </td>
            </tr>
            <tr>
                <td colspan="4" class="label">Specific Company Rule & Regulation Violated</td>
            </tr>
            <tr>
                <td colspan="4" style="height: 100px; vertical-align: top; text-align: justify; text-justify: inter-word;">
                    <?php echo $rule_violated ?: '(To be determined based on the result of the investigation)'; ?>
                </td>
            </tr>
        </table>

        <div style="margin-top: 10px; font-weight: bold;">
            [ ] Please see Incident Report &nbsp;&nbsp;&nbsp;&nbsp; [ ] Please see Complaint
        </div>

        <div style="border: 2px solid black; padding: 10px; margin: 20px 0; background: #f9f9f9;">
            <strong>IMPORTANT:</strong> Failure to submit a Written Explanation shall be taken to mean that you are waiving your right to be heard, and the resolution of the case shall be based on documents/evidence at hand.
        </div>

        <table class="signatory-table">
            <tr>
                <td>__________________________<br><strong>Admin / HR Manager</strong></td>
                <td>__________________________<br><strong>Department Manager</strong></td>
            </tr>
            <tr>
                <td>__________________________<br><strong>Group / Section Head</strong></td>
                <td>__________________________<br><strong>Senior /General Manager</strong></td>
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