<?php
// [FILE] templates/contract_project.php
if (!isset($emp)) {
    die("Access Denied");
}

// 1. CAPTURE INPUTS (from generate_document.php)
$project_name = isset($_GET['project_name']) && !empty($_GET['project_name'])
    ? strtoupper(htmlspecialchars($_GET['project_name']))
    : "SECOND EXTENDED MAINTENANCE SERVICE";

$start_date = isset($_GET['start_date']) && !empty($_GET['start_date'])
    ? date('F d, Y', strtotime($_GET['start_date']))
    : "May 03, 2026";

$end_date   = isset($_GET['end_date']) && !empty($_GET['end_date'])
    ? date('F d, Y', strtotime($_GET['end_date']))
    : "December 31, 2026";

$contract_period = "$start_date to $end_date";

// Name & Address Formatting
$mi = !empty($emp['middle_name']) ? substr($emp['middle_name'], 0, 1) . '.' : '';
$emp_name_formal = strtoupper(htmlspecialchars(trim($emp['first_name'] . ' ' . $mi . ' ' . $emp['last_name'])));
$address  = !empty($emp['present_address']) ? strtoupper(htmlspecialchars($emp['present_address'])) : "__________________________________________________";
$position = !empty($emp['job_title']) ? strtoupper(htmlspecialchars($emp['job_title'])) : "TECHNICIAN";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Project Contract - <?php echo htmlspecialchars($emp_name_formal); ?></title>
    <style>
        /* 1. PAPER & PRINT LAYOUT */
        @page {
            size: A4;
            margin: 0.6in 0.8in;
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }

        /* 2. CONTAINER & HEADER */
        table.report-container {
            width: 100%;
            border-collapse: collapse;
        }

        thead.report-header {
            display: table-header-group;
        }

        .header-wrapper {
            width: 100%;
            margin-bottom: 20px;
            text-align: center;
        }

        .doc-title {
            width: 100%;
            font-weight: bold;
            font-size: 13pt;
            margin: 15px 0 20px 0;
            text-align: center;
            text-transform: uppercase;
        }

        /* 3. CONTENT TYPOGRAPHY */
        .justify {
            text-align: justify;
            text-justify: inter-word;
            margin-bottom: 12px;
        }

        .bold {
            font-weight: bold;
        }

        .uppercase {
            text-transform: uppercase;
        }

        ol.contract-list {
            margin-top: 5px;
            margin-bottom: 15px;
            padding-left: 20px;
        }

        ol.contract-list li {
            margin-bottom: 10px;
            text-align: justify;
            text-justify: inter-word;
        }

        /* 4. SIGNATURE SECTION (STACKED LAYOUT) */
        .sig-section {
            margin-top: 30px;
            page-break-inside: avoid;
        }

        .sig-block {
            margin-bottom: 30px;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 260px;
            margin-top: 45px;
            margin-bottom: 5px;
        }
    </style>
</head>

<body>
    <table class="report-container">
        <thead class="report-header">
            <tr>
                <td>
                    <div class="header-wrapper">
                        <table style="width: 100%; margin-bottom: 10px;">
                            <tr>
                                <td style="width: 110px; text-align: right; vertical-align: middle; padding-right: 15px;">
                                    <img src="<?php echo htmlspecialchars($global_logo_src ?? ''); ?>" width="75" height="75" style="width: 75px; height: 75px; border-radius: 50%; object-fit: cover;" alt="TESP Logo">
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <div style="font-weight: bold; font-size: 14pt !important; line-height: 1.2; white-space: nowrap;">TES PHILIPPINES, INC.</div>
                                    <div style="font-weight: bold; font-size: 10pt !important; line-height: 1.2; white-space: nowrap;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                                    <div style="font-size: 9.5pt !important; line-height: 1.2;">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                                    <div style="font-size: 9.5pt !important; line-height: 1.2;">Telephone Number: 8929-5347 local 4404</div>
                                </td>
                                <td style="width: 70px;"></td>
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

                    <div class="bold uppercase"><?php echo $emp_name_formal; ?></div>
                    <div class="uppercase" style="margin-bottom: 15px;"><?php echo $address; ?></div>

                    <p class="justify">
                        We are pleased to advise you of your Employment with <span class="bold">TES PHILIPPINES, INC.</span> (hereinafter called the “Company”) on a Project and Term Basis arrangement.
                    </p>

                    <ol class="contract-list">
                        <li>
                            <span class="bold">Name of Project:</span> <?php echo $project_name; ?>
                        </li>
                        <li>
                            <span class="bold">Duration of Project:</span> <?php echo $contract_period; ?>
                        </li>
                        <li>
                            <span class="bold">Compensation:</span> Annex A
                        </li>
                        <li>
                            <span class="bold">Position:</span> <?php echo $position; ?>
                        </li>
                        <li>
                            Your specific duties and responsibilities shall be discussed with you by your assigned Superior and shall be subject to change as the need of the Company arises in the pursuit of its objectives.
                        </li>
                        <li>
                            During your employment, you shall comply with all lawful instructions and observe and abide by the Company’s rules, regulations, and policies.
                        </li>
                        <li>
                            It is knowingly and willingly understood that this contract of employment shall be limited only for the period/term and <?php echo $project_name; ?> indicated above and shall automatically terminate on the date/term stated above without the need for any further notice to you unless earlier terminated by the Company for lawful or just cause such as, but not limited to, earlier completion of the work for which you are hired, non-compliance with Company rules and regulations or for any other justifiable reason.
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
                            <div class="sig-line"></div>
                            <div class="bold">JUNJI FURUYA</div>
                            <div>President</div>
                        </div>

                        <div class="sig-block" style="margin-top: 30px;">
                            <div class="justify" style="font-size: 10pt; line-height: 1.3;">
                                I hereby certify that I have read and fully understood the terms and conditions of the foregoing Project Employment Contract and accept them accordingly.
                            </div>
                            <div class="sig-line"></div>
                            <div class="bold uppercase"><?php echo $emp_name_formal; ?></div>
                            <div>Employee’s Name and Signature</div>
                            <div style="margin-top: 5px;">Date: ________________________</div>
                        </div>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>