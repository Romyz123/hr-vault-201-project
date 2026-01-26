<?php
// 1. DATA PREPARATION
if (!isset($emp)) { die("Access Denied"); }

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
        @page { size: Letter; margin: 0.5in; }
        body { font-family: "Times New Roman", Times, serif; font-size: 11pt; line-height: 1.15; color: #000; background: white; margin: 0; padding: 0; }

        /* 2. HEADER LAYOUT */
        table.report-container { width: 100%; border-collapse: collapse; }
        thead.report-header { display: table-header-group; } 
        tfoot.report-footer { display: table-footer-group; } 
        
        .header-wrapper { width: 100%; border-bottom: none; margin-bottom: 15px; padding-bottom: 5px; text-align: center; }
        .header-content-table { width: auto; margin: 0 auto; border-collapse: collapse; }
        .header-content-table td { vertical-align: middle; padding: 0; }
        
        .co-name { font-weight: bold; font-size: 14pt; text-transform: uppercase; color: #000; letter-spacing: 0.5px; margin-bottom: 2px; }
        .co-sub  { font-weight: bold; font-size: 10pt; text-transform: uppercase; margin-bottom: 2px; }
        .co-addr { font-size: 9pt; margin-bottom: 0px; }

        /* 3. CONTENT TYPOGRAPHY */
        .doc-title { text-align: center; font-weight: bold; font-size: 13pt; margin: 15px 0; text-transform: uppercase; }
        .salutation { font-weight: bold; font-size: 11pt; text-transform: uppercase; margin-bottom: 10px; }
        .witnesseth { text-align: center; font-weight: bold; font-size: 12pt; margin: 15px 0 10px 0; letter-spacing: 2px; }
        
        .justify { text-align: justify; text-justify: inter-word; margin-bottom: 8px; }
        .indent  { text-indent: 0.5in; } 
        .bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .center { text-align: center; }

        /* 4. LISTS & SIGNATURES */
        ul.functions { margin: 0 0 10px 0; padding-left: 0.5in; text-align: justify; }
        ul.functions li { margin-bottom: 3px; padding-left: 5px; }
        .sig-table { width: 100%; margin-top: 30px; page-break-inside: avoid; }
        .sig-table td { width: 50%; vertical-align: top; padding: 0 10px; }
        .sig-line { border-top: 1px solid #000; margin: 0 auto 5px auto; width: 90%; }
        .sig-name { font-weight: bold; text-transform: uppercase; }
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
                <!-- Content similar to probationary but for Regular -->
                <p class="justify">This EMPLOYMENT CONTRACT made and entered into by and between...</p>
                <!-- ... -->
            </td>
        </tr>
    </tbody>
</table>
</body>
</html>