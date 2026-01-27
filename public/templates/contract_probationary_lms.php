<?php
// 1. DATA PREPARATION
if (!isset($emp)) {
    die("Access Denied");
}

// CAPTURE DURATION (Months) from the Popup (Defaults to 6 if missing)
$duration = isset($_GET['duration']) ? htmlspecialchars($_GET['duration']) : 6;

// Date Logic: Default if not passed from controller
if (!isset($contract_period)) {
    $start_date_obj = new DateTime($emp['hire_date']);
    $end_date_obj   = clone $start_date_obj;
    $end_date_obj->modify('+' . $duration . ' months'); // Use the dynamic duration

    // Format: "January 24, 2026"
    $start_date_str = $start_date_obj->format('F d, Y');
    $end_date_str   = $end_date_obj->format('F d, Y');
    $contract_period = "$start_date_str up to $end_date_str";
}

// Name Formatting: JUAN A. DELA CRUZ
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

// Current Date for Signatures (Bottom)
$day_now   = date('jS');
$month_now = date('F');
$year_now  = date('Y');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Contract - <?php echo htmlspecialchars($emp_name_formal); ?></title>
    <style>
        /* 1. PAGE SETUP - MAXIMIZED BUT SAFE */
        @page {
            size: Letter;
            margin: 0.5in;
            /* [ADJUST HERE] Page Margins: Increase to 1in for narrower text, decrease to 0.25in for wider text */
        }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            /* Standard legal size */
            line-height: 2.15;
            /* [ADJUST HERE] Line Spacing: 1.0 = Single, 1.5 = 1.5 Lines, 2.0 = Double Spacing */
            color: #000;
            background: white;
            margin: 0;
            padding: 0;
        }

        /* 2. REPEATING HEADER ENGINE */
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
            border-bottom: none;
            margin-bottom: 15px;
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

        /* 4. BODY TYPOGRAPHY */
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

        /* Standard legal indent */

        .bold {
            font-weight: bold;
        }

        .uppercase {
            text-transform: uppercase;
        }

        .center {
            text-align: center;
        }

        /* 5. LISTS */
        ul.functions {
            margin: 0 0 15px 0;
            padding-left: 0.5in;
            text-align: justify;
        }

        ul.functions li {
            margin-bottom: 5px;
            padding-left: 5px;
        }

        /* 6. SIGNATURES (UPDATED: SIDE BY SIDE) */
        .sig-table {
            width: 100%;
            margin-top: 50px;
            page-break-inside: avoid;
            /* [ADJUST HERE] Signature Spacing: Controls gap above signatures */
        }

        .sig-table td {
            width: 50%;
            vertical-align: top;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 250px;
            margin-bottom: 5px;
        }

        .sig-name {
            font-weight: bold;
            text-transform: uppercase;
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

                    <div class="doc-title">PROBATIONARY EMPLOYMENT CONTRACT</div>

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
                        That the EMPLOYEE is being engaged for a Probationary Appointment as <span class="bold uppercase"><?php echo $position; ?></span> for a maximum period of <span class="bold"><?php echo $duration; ?> Months</span> taking effect on <span class="bold"><?php echo $contract_period; ?></span> and will be assigned to the <span class="bold uppercase" style="text-decoration: underline;"><?php echo $section_display; ?></span>.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall render work primarily at the MRT 3 premises, in the MRT 3 Depot and/or MRT 3 Mainline.
                    </p>

                    <p class="justify">
                        This shall be without prejudice to the Company assigning him to perform tasks in such other places deemed necessary by the Management.
                    </p>

                    <p class="justify">
                        The EMPLOYEE agrees to be assigned or deployed at any work site or location as the business operations require.
                    </p>

                    <p class="justify">
                        That as a <span class="bold uppercase"><?php echo $position; ?></span>, the EMPLOYEE is expected to perform the following functions:
                    </p>

                    <ul class="functions">
                        <?php
                        // --- DYNAMIC DUTIES LOGIC ---
                        // Define duties based on Section/Department

                        // 1. LMS TECHNICIAN DUTIES
                        if (strpos(strtolower($section), 'light maintenance') !== false || strpos(strtolower($position), 'technician') !== false) {
                            $duties = [
                                "Checks and validates the cause of train failures as needed to fix the problem.",
                                "Fixes the identified train failures based on troubleshooting standard.",
                                "Documents and encodes the actions taken on the Maintenance Monitoring System.",
                                "Monitors if there are another rework to be done as needed.",
                                "Checks the quality of newly delivered spare parts if in good condition and with quality standard.",
                                "Assists on the orientation of the new Technicians to guide on their tasks."
                            ];
                        }
                        // 2. SQP / OFFICE / ADMIN DUTIES
                        elseif (strpos(strtolower($section), 'sqp') !== false || strpos(strtolower($section), 'admin') !== false) {
                            $duties = [
                                "Assists in the preparation and monitoring of departmental reports and documentation.",
                                "Coordinates with other sections regarding Safety and Quality Planning requirements.",
                                "Maintains organized filing systems for easy retrieval of documents.",
                                "Ensures compliance with company policies and safety regulations.",
                                "Performs administrative tasks as assigned by the Section Head."
                            ];
                        }
                        // 3. GENERIC FALLBACK (If section is unknown)
                        else {
                            $duties = [
                                "Performs the duties and responsibilities assigned by the immediate superior.",
                                "Complies with company rules, regulations, and safety standards.",
                                "Maintains good housekeeping in the work area.",
                                "Reports any abnormalities or issues to the supervisor immediately."
                            ];
                        }

                        // Loop through the selected duties and print them
                        foreach ($duties as $task) {
                            echo "<li>$task</li>";
                        }
                        ?>
                    </ul>

                    <p class="justify">
                        The above functions shall be subject to change as the need of the Company arises in the pursuit of its objectives.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall comply with all Policies, Rules and Regulations of the Company.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall comply with the Work schedule assigned to him based on TESP Official work Schedule.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE’s performance shall be reviewed and evaluated on the 2nd and 4th month in accordance with the Company’s standards and procedures.
                    </p>

                    <p class="justify">
                        These two performance evaluation will determine whether the “Employee” will be recommended for regular employment or not.
                    </p>

                    <p class="justify">
                        Probationary employment may be terminated by the Company at any time and even before the expiration of the probationary period or performance review period for any just or authorized cause or for failure or inability to meet the prescribed performance standards.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE shall receive remuneration and other benefits as stated in the Salary Package (Annex ”A”).
                    </p>

                    <p class="justify">
                        Salaries and benefits shall be subject to withholding taxes and mandatory deductions such as contributions for SSS, Philhealth and HDMF (Pag-ibig) in accordance with the applicable laws and regulations.
                    </p>

                    <p class="justify">
                        That the EMPLOYEE agrees to adhere to the Confidentiality Agreement (Annex “B”). The EMPLOYEE agrees that any breach of confidentiality will constitute sufficient ground for termination of employment and/or civil and criminal liability.
                    </p>

                    <p class="justify">
                        That in case the EMPLOYEE intends to resign from the Company, the EMPLOYEE is required to notify the Company at least thirty (30) days prior to the effectivity of resignation, otherwise, failure on the part of EMPLOYEE to do so will render him/her liable for damages suffered by the Company.
                    </p>

                    <p class="justify">
                        That the PARTIES voluntarily and knowingly executed this Contract with full understanding of its terms, conditions and legal consequences and implications.
                    </p>

                    <p class="justify">
                        The failures of any party to insist upon a strict performance of compliance of any of the terms, conditions and covenants in this Contract shall not be deemed a relinquishment or waiver of any right or remedy that either party may have under the Contract.
                    </p>

                    <p class="justify">
                        The EMPLOYEE shall read, understand and abide by the policies, procedures, rules and regulations, guidelines, standards and codes of conduct, memoranda and instructions issued by the Company.
                    </p>

                    <p class="justify">
                        The EMPLOYEE confirms that there are no promises or understandings made by himself and the COMPANY or any other person acting on behalf of the Company other than those stated therein.
                    </p>

                    <br>

                    <p class="justify indent">
                        IN WITNESS WHEREOF, the parties hereto have set their hands this <span class="bold">______</span> day of <span class="bold">_____________</span> at <span class="bold">_______________________</span>.
                    </p>

                    <table class="sig-table">
                        <tr>
                            <td>
                                <div class="sig-line"></div>
                                <div class="sig-name">GAKU KONDO</div>
                                <div>EMPLOYER</div>
                            </td>

                            <td>
                                <div class="sig-line"></div>
                                <div class="sig-name"><?php echo $emp_name_formal; ?></div>
                                <div>EMPLOYEE</div>
                            </td>
                        </tr>
                    </table>

                    <br><br>
                </td>
            </tr>
        </tbody>
    </table>

</body>

</html>