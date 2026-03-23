<?php
// public/templates/employee_pledge.php
if (!isset($emp)) {
    die("Access Denied");
}

$logo_path = __DIR__ . '/../assets/images/tesp-logo-1.png';
$logo_src = 'assets/images/tesp-logo-1.png';
if (file_exists($logo_path)) {
    $logo_binary = file_get_contents($logo_path);
    $logo_src = 'data:image/png;base64,' . base64_encode($logo_binary);
}
?>
<!DOCTYPE html>
<html>

<head>
    <style>
        @page {
            size: A4;
            margin: 0.2in;
        }

        body {
            text-align: justify;
            font-family: "Times New Roman", serif;
            font-size: 10pt;
            line-height: 1.90;
            padding-left: 0in;
            margin: 0;
            padding-right: 1in;
        }

        .header {
            text-align: left;
            font-weight: bold;
            line-height: 1.3;
            padding-top: 0in;
        }

        p {
            text-align: justify;
            margin-bottom: 0px;
        }

        ul.rules-list {
            margin: 0in 0;
            padding-left: 0in;


        }

        ul.rules-list li {
            margin-bottom: 0px;
            text-align: justify;
        }

        .header-table {
            margin: 0 auto 0px auto;
            border-collapse: collapse;
        }
    </style>
</head>

<body>
    <table class="header-table">
        <tr>
            <td style="padding-right: 15px; vertical-align: middle;">
                <img src="<?php echo $logo_src; ?>" style="width: 70px; height: auto; display: block;" alt="TESP Logo">
            </td>
            <td style="vertical-align: middle;">
                <div class="header">
                    <div style="font-size: 14pt;">EMPLOYEE’S SAFETY PLEDGE</div>
                    <div style="font-size: 11pt;">10 Life Saving Rules and Point & Call Policy</div>
                </div>
            </td>
        </tr>
    </table>

    <p>Today, I <strong><?php echo strtoupper($emp['first_name'] . ' ' . $emp['last_name']); ?></strong>, do hereby pledge that I am committed to doing my part to instill a safety culture and promote the health and safety of all employees. I believe that safety and health are core values of our organization. I pledge to actively practice the following:</p>

    <p style="margin-top: 10px;"><strong>10 Lifesaving Rules (LSR):</strong></p>
    <ul class="rules-list">
        <li><strong>I.1 Buddy System:</strong> Always work in pair. Lone working is not allowed. All work should be executed in the presence of a Person In Charge (PIC).</li>
        <li><strong>I.2 Competency:</strong> No Job to be undertaken unless you have been trained and assessed as competent for that task.</li>
        <li><strong>I.3 Fall Protection:</strong> Always use fall protection device such as Full body harness unless other engineering controls are in place.</li>
        <li><strong>I.4 Lock Out Tag Out:</strong> De-energize the electrical equipment, apply right LOTO devices before doing maintenance activities.</li>
        <li><strong>I.5 Exclusion Zone:</strong> Never enter the exclusion zone in depot area without authorization or unless directed by the Yardmaster/PIC.</li>
        <li><strong>I.6 Drug and Alcohol:</strong> Zero tolerance to drug and alcohol. Never work or drive under the influence.</li>
        <li><strong>I.7 Permit to Work:</strong> All safety critical activities should be executed with appropriate Permit To Work authorization.</li>
        <li><strong>I.8 Earthing Testing:</strong> Test all de-energized equipment, system, power rails, before touching them.</li>
        <li><strong>I.9 Track Access:</strong> Never cross the tracks. Always use the designated safe passage or walkway.</li>
        <li><strong>I.10 Equipment Guard:</strong> Do not remove guards or work on or near unprotected rotating equipment.</li>
    </ul>

    <p><strong>II. Point and Call Policy:</strong> This policy is intended to encourage all personnel to imbibe a cautious work practice by closely following the rules on Point and Call policy every time, until it becomes a safe working habit.</p>

    <p style="margin-top: 10px;">I therefore understand that violation of any of the Lifesaving rules will have corresponding disciplinary sanctions which may include dismissal from work.</p>

    <p style="margin-top: 10px;">This is my pledge and commitment to helping ensure the safety of myself and my co-workers:</p>

    <br>
    <div style="border-top: 1px solid black; width: 300px; text-align: center; padding-top: 5px; margin-top: 20px;">
        <strong><?php echo strtoupper($emp['first_name'] . ' ' . $emp['last_name']); ?></strong><br>
        Signature over Printed Name
    </div>
</body>

</html>