<?php
// 1. DATA PREPARATION
if (!isset($emp)) {
    die("Access Denied");
}

// Date Logic (If not passed, default to 1 year)
if (!isset($contract_period)) {
    $start_date_obj = new DateTime($emp['hire_date']);
    $end_date_obj   = clone $start_date_obj;
    $end_date_obj->modify('+1 year');

    $start_date_str = $start_date_obj->format('F d, Y');
    $end_date_str   = $end_date_obj->format('F d, Y');
    $contract_period = "$start_date_str up to $end_date_str";
}

// Name Formatting
$mi = !empty($emp['middle_name']) ? substr($emp['middle_name'], 0, 1) . '.' : '';
$emp_name_formal = strtoupper($emp['first_name'] . ' ' . $mi . ' ' . $emp['last_name']);
$address  = strtoupper($emp['present_address']);
$position = strtoupper($emp['job_title']);

// [FIX] SECTION TRANSLATOR (Full Legal Names)
$section_map = [
    'LMS'    => 'LIGHT MAINTENANCE SECTION',
    'SQP'    => 'SAFETY QUALITY PLANNING',
    'SIGCOM' => 'SIGNALING & COMMUNICATION',
    'PSS'    => 'POWER SUPPLY SECTION',
    'OCS'    => 'OVERHEAD CATENARY SYSTEMS',
    'HMS'    => 'HEAVY MAINTENANCE SECTION',
    'RAS'    => 'ROOT CAUSE ANALYSIS',
    'TRS'    => 'TECHNICAL RESEARCH SECTION',
    'DOS'    => 'DEPARTMENT OPERATIONS SECTION',
    'CTS'    => 'CIVIL TRACKS SECTION',
    'ADMIN'  => 'ADMINISTRATION SECTION',
    'BFS'    => 'BUILDING FACILITIES',
    'WHS'    => 'WAREHOUSE SECTION'
];
$raw_dept = strtoupper($emp['dept']);
$section_display = $section_map[$raw_dept] ?? strtoupper($emp['section']);

$day_now   = date('jS');
$month_now = date('F');
$year_now  = date('Y');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Regular Contract - <?php echo htmlspecialchars($emp_name_formal); ?></title>
    <style>
        /* 1. PAGE SETUP */
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

        /* 2. HEADER LAYOUT */
        table.report-container {
            width: 100%;
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
            border-bottom: none;
            margin-bottom: 15px;
            padding-bottom: 5px;
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
            font-size: 13pt;
            margin: 10px 0 15px 0;
            text-transform: uppercase;
        }

        .salutation {
            font-weight: bold;
            font-size: 11pt;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .witnesseth {
            text-align: center;
            font-weight: bold;
            font-size: 12pt;
            margin: 15px 0 10px 0;
            letter-spacing: 2px;
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

        /* 4. LISTS */
        ul.functions {
            margin: 0 0 10px 0;
            padding-left: 0.5in;
            text-align: justify;
        }

        ul.functions li {
            margin-bottom: 3px;
            padding-left: 5px;
        }

        /* 5. VERTICAL SIGNATURE STYLES */
        .sig-section {
            margin-top: 20px;
            /* [ADJUST HERE] Signature Spacing: Controls gap above signatures */
            page-break-inside: avoid;
        }

        .certify-text {
            text-align: justify;
            margin: 30px 0;
            font-style: italic;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 300px;
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
                        <table class="header-content-table">
                            <tr>
                                <td style="padding-right: 15px;">
                                    <img src="uploads/<?php echo rawurlencode('tesp logo 1.png'); ?>" style="width: 80px; height: auto; display: block; margin: 0 auto;" alt="TESP Logo">
                                </td>
                                <td style="text-align: center;">
                                    <div class="co-name">TES PHILIPPINES, INC.</div>
                                    <div class="co-sub">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                                    <div class="co-addr">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                                    <div class="co-addr">Telephone Number: 8929-5347 local 4404</div>
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
                    <div class="doc-title">REGULAR EMPLOYMENT CONTRACT</div>

                    <div class="salutation">KNOW ALL MEN BY THESE PRESENTS:</div>

                    <p class="justify">This EMPLOYMENT CONTRACT made and entered into by and between:</p>

                    <p class="justify indent">
                        <span class="bold">TES PHILIPPINES INC.</span>, a corporation duly organized and existing by virtue of Philippine law, with business address at Room 207, 2nd Flr., Meriton One Bldg., Quezon Avenue, Quezon City, represented in this act by its President, hereinafter referred to as <span class="bold">TESP</span> or <span class="bold">“Company”</span>
                    </p>

                    <div class="center bold" style="margin: 10px 0;">-and-</div>

                    <p class="justify indent">
                        <span class="bold uppercase"><?php echo $emp_name_formal; ?></span>, of legal age, Filipino and resident of <span class="bold uppercase"><?php echo $address; ?></span>, hereinafter referred to as the <span class="bold">“Employee”</span>.
                    </p>

                    <div class="witnesseth">WITNESSETH</div>

                    <p class="justify">
                        That the EMPLOYEE is being engaged as a <span class="bold">REGULAR EMPLOYEE</span> with the position of <span class="bold uppercase"><?php echo $position; ?></span> effective <span class="bold"><?php echo $start_date_str; ?></span> and will be assigned to the <span class="bold uppercase" style="text-decoration: underline;"><?php echo $section_display; ?></span>.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall render work primarily at the MRT 3 premises, in the MRT 3 Depot and/or MRT 3 Mainline.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall perform the duties and responsibilities appurtenant to the position and such other duties as may be assigned by the Company from time to time.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall comply with all Policies, Rules and Regulations of the Company.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall receive remuneration and other benefits as stated in the Salary Package (Annex ”A”).
                    </p>

                    <p class="justify">
                        That the EMPLOYEE agrees to adhere to the Confidentiality Agreement (Annex “B”). The EMPLOYEE agrees that any breach of confidentiality will constitute sufficient ground for termination of employment and/or civil and criminal liability.
                    </p>

                    <p class="justify">
                        That in case the EMPLOYEE intends to resign from the Company, the EMPLOYEE is required to notify the Company at least thirty (30) days prior to the effectivity of resignation, otherwise, failure on the part of EMPLOYEE to do so will render him/her liable for damages suffered by the Company.
                    </p>

                    <br>

                    <p class="justify indent">
                        IN WITNESS WHEREOF, the parties hereto have set their hands this <span class="bold">______</span> day of <span class="bold">_____________</span> at <span class="bold">_______________________</span>.
                    </p>

                    <div class="sig-section">

                        <div style="margin-bottom: 40px;">
                            <div style="margin-bottom: 20px;">Truly yours,</div>
                            <div class="bold">GAKU KONDO</div>
                            <div>President</div>
                        </div>

                        <div class="certify-text">
                            I hereby certify that I have read and fully understood the terms and conditions of the foregoing Regular Employment Contract and accept them accordingly.
                        </div>

                        <div style="margin-bottom: 20px;">
                            <div class="sig-line"></div>
                            <div class="bold uppercase"><?php echo $emp_name_formal; ?></div>
                            <div>Employee’s Name and Signature</div>
                        </div>

                        <div style="margin-top: 20px;">
                            Date: <span style="border-bottom: 1px solid black; display: inline-block; width: 200px;">&nbsp;</span>
                        </div>

                    </div>

                    <br><br>
                </td>
            </tr>
        </tbody>
    </table>

</body>

</html>