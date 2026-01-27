<?php
// [FILE] templates/contract_project.php
if (!isset($emp)) {
    die("Access Denied");
}

// 1. CAPTURE INPUTS (from generate_document.php)
$project_name = isset($_GET['project_name']) ? strtoupper(htmlspecialchars($_GET['project_name'])) : "____________";
$start_date = isset($_GET['start_date']) ? date('F d, Y', strtotime($_GET['start_date'])) : "____________";
$end_date   = isset($_GET['end_date'])   ? date('F d, Y', strtotime($_GET['end_date']))   : "____________";
$contract_period = "$start_date to $end_date";

// Name Formatting
$mi = !empty($emp['middle_name']) ? substr($emp['middle_name'], 0, 1) . '.' : '';
$emp_name_formal = strtoupper(htmlspecialchars($emp['first_name'] . ' ' . $mi . ' ' . $emp['last_name']));
$address  = strtoupper(htmlspecialchars($emp['present_address']));
$position = strtoupper(htmlspecialchars($emp['job_title']));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Project Contract - <?php echo htmlspecialchars($emp_name_formal); ?></title>
    <style>
        /* 1. MAXIMIZE PAPER SPACE (Reduces Dead Space)  */
        @page {
            size: Letter;
            margin: 0.5in;
            /* [ADJUST HERE] Page Margins: Increase to 1in for narrower text, decrease to 0.25in for wider text */
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            line-height: 2.15;
            /* [ADJUST HERE] Line Spacing: 1.0 = Single, 1.5 = 1.5 Lines, 2.0 = Double Spacing */
            color: #000;
            background: white;
            margin: 0;
            padding: 0;
        }

        /* 2. HEADER LAYOUT (CENTERED BLOCK) */
        table.report-container {
            width: 90%;
            border-collapse: collapse;
        }

        thead.report-header {
            display: table-header-group;
        }

        tfoot.report-footer {
            display: table-footer-group;
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

        .header-content-table td {
            vertical-align: middle;
            padding: 0;
        }

        .co-name {
            font-weight: bold;
            font-size: 14pt;
            text-transform: uppercase;
            color: #000;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .co-sub {
            font-weight: bold;
            font-size: 10pt;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .co-addr {
            font-size: 9pt;
            margin-bottom: 0px;
        }

        /* 3. CONTENT TYPOGRAPHY */
        .doc-title {
            text-align: center;
            font-weight: bold;
            font-size: 15pt;
            margin: 10px 0 15px 0;
            text-transform: uppercase;
        }

        .salutation {
            font-weight: bold;
            font-size: 11pt;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .justify {
            text-align: justify;
            text-justify: inter-word;
            margin-bottom: 8px;
            /* [ADJUST HERE] Paragraph Spacing: Controls gap between paragraphs */
        }

        .indent {
            text-indent: 0.5in;
        }

        .bold {
            font-weight: bold;
        }

        .uppercase {
            text-transform: uppercase;
        }

        .center {
            text-align: center;
        }

        /* 5. SIGNATURES */
        .sig-section {
            margin-top: 100px;
            page-break-inside: avoid;
            /* [ADJUST HERE] Signature Spacing: Controls gap above signatures */
        }

        .sig-block {
            margin-bottom: 40px;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 250px;
            margin-bottom: 5px;
        }

        /* TABLE FOR DETAILS */
        .details-table {
            width: 100%;
            margin-bottom: 15px;
        }

        .details-table td {
            vertical-align: top;
            padding-bottom: 5px;
        }

        .label-col {
            width: 160px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <table class="report-container">
        <thead class="report-header">
            <tr>
                <td>
                    <div class="header-wrapper">
                        <table class="header-content-table">
                            <tr>
                                <td style="width: 15%; text-align: center;">
                                    <img src="uploads/<?php echo rawurlencode('tesp logo 1.png'); ?>" style="width: 80px;">
                                </td>
                                <td style="width: 70%; text-align: center;">
                                    <div class="co-name">TES PHILIPPINES, INC.</div>
                                    <div class="co-sub">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                                    <div class="co-addr">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </thead>

        <tbody>
            <tr>
                <td>
                    <div class="doc-title">PROJECT EMPLOYMENT CONTRACT</div>

                    <div class="bold uppercase" style="margin-bottom: 0;"><?php echo $emp_name_formal; ?></div>
                    <div class="uppercase" style="margin-bottom: 20px;"><?php echo $address; ?></div>

                    <p class="justify">
                        We are pleased to advise you of your Employment with <span class="bold">TES PHILIPPINES, INC.</span> (hereinafter called the “Company”) on a Project and Term Basis arrangement.
                    </p>

                    <ol>
                        <li>
                            <span class="bold">Name of Project:</span> <span class="bold uppercase"><?php echo $project_name; ?></span>
                        </li>
                        <li>
                            <span class="bold">Duration of Project:</span> <span class="bold"><?php echo $contract_period; ?></span>
                        </li>
                        <li>
                            <span class="bold">Compensation:</span> Annex A
                        </li>
                        <li>
                            <span class="bold">Position:</span> <span class="bold uppercase"><?php echo $position; ?></span>
                        </li>
                        <li>
                            Your specific duties and responsibilities shall be discussed with you by your assigned Superior and shall be subject to change as the need of the Company arises in the pursuit of its objectives.
                        </li>
                        <li>
                            During your employment, you shall comply with all lawful instructions and observe and abide by the Company’s rules, regulations, and policies.
                        </li>
                        <li>
                            It is knowingly and willingly understood that this contract of employment shall be limited only for the period/term and <span class="bold uppercase"><?php echo $project_name; ?></span> indicated above and shall automatically terminate on the date/term stated above without the need for any further notice to you unless earlier terminated by the Company for lawful or just cause such as, but not limited to, earlier completion of the work for which you are hired, non-compliance with Company rules and regulations or for any other justifiable reason.
                        </li>
                        <li>
                            Your employment herein is understood to be on an Extended Maintenance Agreement and Term Basis only, limited to and by the terms and conditions herein knowingly and willingly agreed upon by the Employee, and shall in no manner obligate the Company to extend the Rehabilitation Project Phase and term/period of this contract.
                        </li>
                        <li>
                            Your work schedule will be given to you by our work superiors. Work schedules are expected to be strictly followed.
                        </li>
                        <li>
                            For the duration of your employment, you agree to render overtime service or work on specified holidays and rest days, or specified work shifts if necessary to the completion of the project for which additional or premium compensation is paid by law.
                        </li>
                    </ol>

                    <p class="justify">
                        If you agree to the foregoing terms and conditions of your project employment, please sign in the space provided below.
                    </p>

                    <div class="sig-section">
                        <div class="sig-block">
                            <div>Truly yours,</div>
                            <br><br>
                            <div class="bold">GAKU KONDO</div>
                            <div>President</div>
                        </div>
                        <div class="sig-block">
                            <div class="justify" style="font-style: italic;">I hereby certify that I have read and fully understood the terms and conditions of the foregoing Project Employment Contract and accept them accordingly.</div>
                            <br>
                            <div class="sig-line"></div>
                            <div class="bold uppercase"><?php echo $emp_name_formal; ?></div>
                            <div>Employee's Name and Signature</div>
                            <div style="margin-top: 5px;">Date: _________________</div>
                        </div>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>
<?php echo $emp_name_formal; ?>
</div>
<div class="justify uppercase" style="margin-bottom: 20px;">
    <?php echo $address; ?>
</div>

<p class="justify">
    We are pleased to advise you of your Employment with <span class="bold">TES PHILIPPINES, INC.</span> (hereinafter called the “Company”) on a Project and Term Basis arrangement.
</p>
<center>
    <table class="details-table">
        <tr>
            <td class="label-col">Name of Project :</td>
            <td class="bold uppercase"><?php echo $project_name; ?></td>
        </tr>
        <tr>
            <td class="label-col">Duration of Project :</td>
            <td class="bold"><?php echo $contract_period; ?></td>
        </tr>
        <tr>
            <td class="label-col">Compensation :</td>
            <td>Annex A</td>
        </tr>
        <tr>
            <td class="label-col">Position :</td>
            <td class="bold uppercase"><?php echo $position; ?></td>
        </tr>
    </table>
    <center />

    <p class="justify">
        Your specific duties and responsibilities shall be discussed with you by your assigned Superior and shall be subject to change as the need of the Company arises in the pursuit of its objectives.
    </p>

    <p class="justify">
        During your employment, you shall comply with all lawful instructions and observe and abide by the Company’s rules, regulations, and policies.
    </p>

    <p class="justify">
        It is knowingly and willingly understood that this contract of employment shall be limited only for the period/term and <span class="bold uppercase"><?php echo $project_name; ?></span> indicated above and shall automatically terminate on the date/term stated above without the need for any further notice to you unless earlier terminated by the Company for lawful or just cause such as, but not limited to, earlier completion of the work for which you are hired, non-compliance with Company rules and regulations or for any other justifiable reason.
    </p>

    <p class="justify">
        Your employment herein is understood to be on an Extended Maintenance Agreement and Term Basis only, limited to and by the terms and conditions herein knowingly and willingly agreed upon by the Employee, and shall in no manner obligate the Company to extend the Rehabilitation Project Phase and term/period of this contract.
    </p>

    <p class="justify">
        Your work schedule will be given to you by our work superiors. Work schedules are expected to be strictly followed.
    </p>

    <p class="justify">
        For the duration of your employment, you agree to render overtime service or work on specified holidays and rest days, or specified work shifts if necessary to the completion of the project for which additional or premium compensation is paid by law.
    </p>

    <p class="justify">
        If you agree to the foregoing terms and conditions of your project employment, please sign in the space provided below.
    </p>

    <br>

    <div style="margin-bottom: 10px;">Truly yours,</div>

    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="bold">GAKU KONDO</div>
            <div>President</div>
        </div>

        <div class="sig-block">
            <div style="margin-bottom: 15px; font-size: 10pt; font-style: italic;">
                I hereby certify that I have read and fully understood the terms and conditions of the foregoing Project Employment Contract and accept them accordingly.
            </div>
            <div class="sig-line"></div>
            <div class="bold uppercase"><?php echo $emp_name_formal; ?></div>
            <div>Employee Signature</div>
            <div style="margin-top: 5px;">Date: _________________</div>
        </div>
    </div>
    </td>
    </tr>
    </tbody>
    </table>

    </body>

    </html>