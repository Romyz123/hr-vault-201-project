<?php
// public/templates/notice_of_decision.php
if (!isset($emp)) {
    die("ACCESS DENIED");
}

// 1. Prepare Data
$date_created = (!empty($_GET['notice_date'])) ? date('F d, Y', strtotime($_GET['notice_date'])) : '___________________________';
$incident_date = (!empty($_GET['incident_date'])) ? date('F d, Y h:i A', strtotime($_GET['incident_date'])) : '___________________________';
$violation = nl2br(htmlspecialchars($_GET['violation'] ?? ''));
$decision = nl2br(htmlspecialchars($_GET['decision'] ?? ''));
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

        .title {
            text-align: center;
            font-weight: bold;
            font-size: 14pt;
            margin: 20px 0;
            text-decoration: underline;
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
            width: 20%;
            background: #eee;
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
                        <?php if (isset($global_logo_src) && $global_logo_src !== ''): ?>
                            <img src="<?php echo htmlspecialchars($global_logo_src); ?>" alt="Company Logo" class="logo" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;">
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

        <div class="title">NOTICE OF DECISION</div>

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
            After careful review and investigation of the incident, the Management has reached a decision regarding the disciplinary case filed against you.
        </p>

        <table class="bordered-table">
            <tr>
                <td class="label">Date of Incident</td>
                <td colspan="3"><?php echo $incident_date; ?></td>
            </tr>
            <tr>
                <td colspan="4" class="label">Violation / Offense</td>
            </tr>
            <tr>
                <td colspan="4" style="height: 100px; vertical-align: top; text-align: justify; text-justify: inter-word;">
                    <?php echo $violation ?: '(No violation recorded)'; ?>
                </td>
            </tr>
            <tr>
                <td colspan="4" class="label">Decision / Sanction</td>
            </tr>
            <tr>
                <td colspan="4" style="height: 150px; vertical-align: top; text-align: justify; text-justify: inter-word;">
                    <?php echo $decision ?: '(No decision recorded)'; ?>
                </td>
            </tr>
        </table>

        <div style="border: 2px solid black; padding: 10px; margin: 20px 0; background: #f9f9f9; text-align: justify;">
            <strong>WARNING:</strong> You are strictly advised to adhere to the company's rules and regulations. Repetition of the same or similar offense will be dealt with more severe disciplinary action, which may include termination of employment.
        </div>

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