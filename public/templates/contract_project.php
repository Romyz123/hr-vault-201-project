<?php
// [FILE] templates/project_employment_contract_COMPLETE_RED2_GREEN3.php
if (!isset($emp) || !is_array($emp)) {
    die('Access Denied');
}

if (!function_exists('contractEscape')) {
    function contractEscape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('contractDate')) {
    function contractDate($value, $fallback)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $fallback : date('F j, Y', $timestamp);
    }
}

$projectNameRaw = trim((string) ($_GET['project_name'] ?? ''));
$projectName = strtoupper($projectNameRaw !== '' ? $projectNameRaw : 'SECOND EXTENDED MAINTENANCE SERVICE');
$projectNameDisplay = ucwords(strtolower($projectName));
$projectNameClause = strtolower($projectName);
$startDate = contractDate($_GET['start_date'] ?? '', 'October 1, 2026');
$endDate = contractDate($_GET['end_date'] ?? '', 'December 31, 2026');
$contractPeriod = $startDate . ' to ' . $endDate;

$systemFullName = trim((string) (
    $emp['full_name']
    ?? $emp['employee_name']
    ?? $emp['name']
    ?? ''
));

if ($systemFullName === '') {
    $firstName = trim((string) ($emp['first_name'] ?? ''));
    $middleName = trim((string) ($emp['middle_name'] ?? ''));
    $lastName = trim((string) ($emp['last_name'] ?? ''));
    $suffix = trim((string) ($emp['suffix'] ?? ''));
    $middleInitial = $middleName !== '' ? substr($middleName, 0, 1) . '.' : '';
    $systemFullName = trim($firstName . ' ' . $middleInitial . ' ' . $lastName . ' ' . $suffix);
}

$employeeName = strtoupper($systemFullName !== '' ? $systemFullName : 'SAMPLE SAMPLE');
$address = trim((string) ($emp['present_address'] ?? ''));
$address = $address !== '' ? $address : '__________________________________________________';
$position = trim((string) ($emp['job_title'] ?? ''));
$position = $position !== '' ? ucwords(strtolower($position)) : 'Technician';

$logoSrc = trim((string) ($global_logo_src ?? ''));
$logoPath = $logoPath ?? 'C:\\xampp\\htdocs\\hr 201\\uploads\\tesp logo 1.png';

if ($logoSrc === '' && is_file($logoPath)) {
    $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
    if ($extension === 'jpg' || $extension === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($extension === 'svg') {
        $mime = 'image/svg+xml';
    } else {
        $mime = 'image/' . $extension;
    }
    $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
}

if ($logoSrc === '') {
    $logoSrc = '/hr 201/uploads/tesp logo 1.png';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Employment Contract - <?php echo contractEscape($employeeName); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            color: #000;
            background: #fff;
            font-family: "Times New Roman", Times, serif;
        }

        .document-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            overflow: hidden;
            background: #fff;
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.05;
        }

        .page-one {
            page-break-after: always;
            break-after: page;
        }

        .document-header {
            position: absolute;
            top: 15mm;
            left: 19mm;
            right: 16.3mm;
            height: 18mm;
            text-align: center;
        }

        .company-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            height: 36px;
        }

        .company-logo {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: contain;
        }

        .company-name {
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13pt;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .project-header {
            margin-top: 2pt;
            color: #111;
            font-size: 8.5pt;
            font-weight: 700;
            line-height: 1;
            text-transform: uppercase;
        }

        .company-address {
            margin-top: 1pt;
            color: #333;
            font-size: 7.5pt;
            line-height: 1;
        }

        .page-body {
            position: absolute;
            top: 38mm;
            left: 19mm;
            right: 16.3mm;
            bottom: 8mm;
            overflow: hidden;
        }

        .page-two .page-body {
            top: 35mm;
            bottom: 12.5mm;
        }

        .document-title {
            margin: 0 0 36pt;
            text-align: center;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1;
            text-decoration: underline;
            text-transform: uppercase;
        }

        .employee-block {
            margin: 0 0 24pt;
        }

        .employee-name {
            font-size: 12pt;
            font-weight: 700;
            line-height: 1.05;
            text-transform: uppercase;
        }

        .employee-address {
            margin-top: 1pt;
            font-size: 12pt;
            line-height: 1.05;
        }

        .opening-paragraph {
            margin: 0 0 18pt;
            font-size: 12pt;
            line-height: 1.15;
            text-align: left;
        }

        .contract-details {
            width: calc(100% - 28px);
            margin: 0 0 18pt 28px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .contract-details td {
            padding: 0;
            vertical-align: top;
            font-size: 12pt;
            line-height: 1.5;
        }

        .contract-details .number {
            width: 30px;
            padding-right: 10px;
            text-align: right;
        }

        .contract-details .label {
            width: 185px;
            padding-left: 2px;
            white-space: nowrap;
        }

        .contract-details .colon {
            width: 42px;
            padding-right: 12px;
            text-align: center;
        }

        .contract-details .value {
            padding-left: 8px;
            font-style: italic;
        }

        .numbered-clause {
            display: grid;
            grid-template-columns: 30px minmax(0, 1fr);
            column-gap: 10px;
            margin: 0 0 8pt;
            font-size: 12pt;
            line-height: 1.5;
        }

        .clause-number,
        .clause-text {
            line-height: 1.5;
        }

        .clause-number {
            text-align: right;
        }

        .clause-text {
            text-align: justify;
            text-align-last: left;
            text-justify: inter-word;
        }

        /* Red marks: two 12pt line spaces after Clauses 5, 6, and 7. */
        .page-one .numbered-clause.gap-red {
            margin-bottom: 24pt !important;
        }

        /* Green mark: three 12pt line spaces after Clause 8. */
        .page-one .numbered-clause.gap-green {
            margin-bottom: 36pt !important;
        }

        /* Clause 9 remains the final Page 1 clause. */
        .page-one .numbered-clause:last-child {
            margin-bottom: 0 !important;
        }

        .closing-paragraph {
            margin: 18pt 0 36pt;
            font-size: 12pt;
            line-height: 1.15;
            text-align: left;
        }

        .management-block {
            margin: 0 0 66pt;
            font-size: 12pt;
            line-height: 1.05;
        }

        .management-space {
            height: 60pt;
        }

        .management-name {
            margin-bottom: 8pt;
            font-weight: 700;
        }

        .acknowledgment {
            margin: 0 0 72pt;
            font-size: 12pt;
            line-height: 1.15;
            text-align: left;
        }

        .employee-signature-block {
            width: 330px;
            font-size: 12pt;
            line-height: 1.05;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .signature-line {
            width: 252px;
            border-top: .75pt solid #000;
            margin-bottom: 6pt;
        }

        .employee-signed-name {
            margin: 0 0 4pt;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1.05;
            text-transform: uppercase;
        }

        .signature-caption {
            margin-bottom: 42pt;
        }

        .date-row {
            display: flex;
            align-items: flex-end;
            gap: 7px;
        }

        .date-line {
            width: 215px;
            height: 12pt;
            border-bottom: .75pt solid #000;
        }

        /* ==========================================================
           FINAL PAGE 2 SCREENSHOT MATCH
           ========================================================== */
        .page-two .page-body {
            top: 35mm !important;
            left: 16.5mm !important;
            right: 16.5mm !important;
            bottom: 12.5mm !important;
        }

        /* Clause 10 begins directly below the repeated header. */
        .page-two .numbered-clause {
            grid-template-columns: 30px minmax(0, 1fr) !important;
            column-gap: 10px !important;
            margin: 0 0 27pt 0 !important;
            font-size: 12pt !important;
            line-height: 1.5 !important;
        }

        .page-two .numbered-clause .clause-number,
        .page-two .numbered-clause .clause-text {
            line-height: 1.5 !important;
        }

        /* Closing paragraph wraps into two lines like the reference. */
        .page-two .closing-paragraph {
            margin: 0 0 6pt 10px !important;
            max-width: 650px !important;
            font-size: 12pt !important;
            line-height: 1.75 !important;
            text-align: left !important;
        }

        /* Truly yours starts immediately after the closing paragraph. */
        .page-two .management-block {
            margin: 0 0 73pt 0 !important;
            font-size: 12pt !important;
            line-height: 1.15 !important;
        }

        /* Large signing gap below Truly yours. */
        .page-two .management-space {
            height: 77pt !important;
        }

        .page-two .management-name {
            margin: 0 0 8pt 0 !important;
            font-weight: 700 !important;
        }

        /* Acknowledgment sits lower and uses the full text width. */
        .page-two .acknowledgment {
            margin: 0 0 14pt 10px !important;
            max-width: 650px !important;
            font-size: 12pt !important;
            line-height: 1.75 !important;
            text-align: left !important;
        }

        /* Employee signature block follows directly below acknowledgment. */
        .page-two .employee-signature-block {
            width: 330px !important;
            margin-left: 0 !important;
            font-size: 12pt !important;
            line-height: 1.05 !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .page-two .signature-line {
            width: 252px !important;
            margin-bottom: 6pt !important;
        }

        .page-two .employee-signed-name {
            margin: 0 0 4pt 0 !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            line-height: 1.05 !important;
            text-transform: uppercase !important;
        }

        .page-two .signature-caption {
            margin-bottom: 42pt !important;
        }

        .page-two .date-row {
            gap: 10px !important;
        }

        .page-two .date-line {
            width: 215px !important;
        }

        /* ==========================================================
           FINAL WORD SIGNATURE AND SPACING MATCH
           ========================================================== */
        /* Word document has a large signing gap after acknowledgment. */
        .page-two .acknowledgment {
            margin: 0 0 72pt 10px !important;
            max-width: 650px !important;
            font-size: 12pt !important;
            line-height: 1.5 !important;
            text-align: left !important;
        }

        /* Align the execution block with the acknowledgment text. */
        .page-two .employee-signature-block {
            width: 330px !important;
            margin-left: 10px !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        /* Word signature line is approximately 280px long. */
        .page-two .signature-line {
            width: 280px !important;
            border-top: 0.75pt solid #000 !important;
            margin: 0 0 6pt 0 !important;
        }

        /* Preserve the system-generated employee full name. */
        .page-two .employee-signed-name {
            display: block !important;
            width: 280px !important;
            margin: 0 0 4pt 0 !important;
            font-family: "Times New Roman", Times, serif !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            line-height: 1.05 !important;
            text-transform: uppercase !important;
        }

        /* Caption sits directly below the employee name. */
        .page-two .signature-caption {
            margin: 0 0 42pt 0 !important;
            font-size: 12pt !important;
            line-height: 1.05 !important;
        }

        /* Match the Word date row and underline length. */
        .page-two .date-row {
            display: flex !important;
            align-items: flex-end !important;
            gap: 8px !important;
            font-size: 12pt !important;
        }

        .page-two .date-line {
            width: 240px !important;
            height: 12pt !important;
            border-bottom: 0.75pt solid #000 !important;
        }

        /* ==========================================================
           FINAL WORD DOCUMENT SPACING CORRECTIONS
           ========================================================== */

        /* PAGE 1 RED MARK:
           Remove the blank gap between employee address and opening paragraph. */
        .page-one .employee-block {
            margin-bottom: 0 !important;
        }

        .page-one .employee-address {
            margin-bottom: 0 !important;
        }

        .page-one .opening-paragraph {
            margin-top: 0 !important;
            padding-top: 0 !important;
        }

        /* PAGE 2 WORD MATCH:
           Add the Word-style separation before “Truly yours,”. */
        .page-two .closing-paragraph {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 34pt !important;
            margin-left: 10px !important;
            max-width: 650px !important;
            font-size: 12pt !important;
            line-height: 1.5 !important;
            text-align: left !important;
        }

        /* “Truly yours,” and management signature block. */
        .page-two .management-block {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 72pt !important;
            margin-left: 10px !important;
            font-size: 12pt !important;
            line-height: 1.15 !important;
        }

        /* Word document signing area below “Truly yours,”. */
        .page-two .management-space {
            height: 72pt !important;
        }

        .page-two .management-name {
            margin: 0 0 8pt 0 !important;
            font-weight: 700 !important;
        }

        /* Keep acknowledgment aligned with the closing paragraph. */
        .page-two .acknowledgment {
            margin: 0 0 72pt 10px !important;
            max-width: 650px !important;
            font-size: 12pt !important;
            line-height: 1.5 !important;
            text-align: left !important;
        }

        /* Word-style employee execution block. */
        .page-two .employee-signature-block {
            width: 330px !important;
            margin-left: 10px !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .page-two .signature-line {
            width: 280px !important;
            border-top: 0.75pt solid #000 !important;
            margin: 0 0 6pt 0 !important;
        }

        .page-two .employee-signed-name {
            display: block !important;
            width: 280px !important;
            margin: 0 0 4pt 0 !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            line-height: 1.05 !important;
            text-transform: uppercase !important;
        }

        .page-two .signature-caption {
            margin: 0 0 42pt 0 !important;
            font-size: 12pt !important;
            line-height: 1.05 !important;
        }

        .page-two .date-row {
            display: flex !important;
            align-items: flex-end !important;
            gap: 8px !important;
            font-size: 12pt !important;
        }

        .page-two .date-line {
            width: 240px !important;
            height: 12pt !important;
            border-bottom: 0.75pt solid #000 !important;
        }

        /* ==========================================================
           FINAL PAGE 1 GREEN AND RED SPACING CORRECTION
           ========================================================== */

        /* GREEN MARK:
           Add 1.5 lines of space after the employee address.
           At 12pt body text, 1.5 lines equals 18pt. */
        .page-one .employee-block {
            margin-bottom: 18pt !important;
        }

        .page-one .employee-address {
            margin-top: 1pt !important;
            margin-bottom: 0 !important;
        }

        /* RED MARK:
           Remove the extra space after the opening paragraph so
           Project Detail 1 follows immediately after the paragraph. */
        .page-one .opening-paragraph {
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }

        .page-one .contract-details {
            margin-top: 0 !important;
        }

        /* ==========================================================
           FINAL OPENING PARAGRAPH SPACING
           ========================================================== */

        /* Highlighted paragraph: single, 1.0 line spacing. */
        .page-one .opening-paragraph {
            margin-top: 0 !important;
            margin-bottom: 24pt !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            font-size: 12pt !important;
            line-height: 1.0 !important;
            text-align: left !important;
        }

        /* Red mark: two 12pt spaces before Project Detail 1. */
        .page-one .contract-details {
            margin-top: 0 !important;
        }

        @media screen {
            body {
                background: #ddd;
            }

            .document-page {
                margin: 12px auto;
                box-shadow: 0 0 5px rgba(0, 0, 0, .25);
            }
        }

        @media print {

            html,
            body {
                width: 210mm;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }

            .document-page {
                margin: 0 !important;
                box-shadow: none !important;
            }
        }

        .page-two .employee-signature-block>.signature-line {
            height: 0 !important;
            border: 0 !important;
            border-bottom: .75pt solid #000 !important;
        }
    </style>
</head>

<body>
    <section class="document-page page-one">
        <header class="document-header">
            <div class="company-row">
                <img src="<?php echo contractEscape($logoSrc); ?>" class="company-logo" alt="TESP Logo">
                <div class="company-name">TES PHILIPPINES, INC.</div>
            </div>
            <div class="project-header">METRO RAIL TRANSIT LINE 3 MAINTENANCE & REHABILITATION PROJECT</div>
            <div class="company-address">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
        </header>

        <main class="page-body">
            <div class="document-title">PROJECT EMPLOYMENT CONTRACT</div>
            <div class="employee-block">
                <div class="employee-name"><?php echo contractEscape($employeeName); ?></div>
                <div class="employee-address"><?php echo contractEscape($address); ?></div>
            </div>

            <p class="opening-paragraph">
                We are pleased to advise you of your Employment with <strong>TES Philippines, INC.</strong>
                (hereinafter called the “Company”) on a <strong>Project and Term Basis arrangement.</strong>
            </p>

            <table class="contract-details">
                <tr>
                    <td class="number">1.</td>
                    <td class="label">Name of Project</td>
                    <td class="colon">:</td>
                    <td class="value"><?php echo contractEscape($projectNameDisplay); ?></td>
                </tr>
                <tr>
                    <td class="number">2.</td>
                    <td class="label">Duration of Project</td>
                    <td class="colon">:</td>
                    <td class="value"><u><?php echo contractEscape($contractPeriod); ?></u></td>
                </tr>
                <tr>
                    <td class="number">3.</td>
                    <td class="label">Compensation</td>
                    <td class="colon">:</td>
                    <td class="value">Annex A</td>
                </tr>
                <tr>
                    <td class="number">4.</td>
                    <td class="label">Position</td>
                    <td class="colon">:</td>
                    <td class="value"><?php echo contractEscape($position); ?></td>
                </tr>
            </table>

            <div class="numbered-clause gap-red">
                <div class="clause-number">5.</div>
                <div class="clause-text">Your specific duties and responsibilities shall be discussed with you by your assigned Superior and shall be subject to change as the need of the Company arises in the pursuit of its objectives.</div>
            </div>

            <div class="numbered-clause gap-red">
                <div class="clause-number">6.</div>
                <div class="clause-text">During your employment, you shall comply with all lawful instructions and observe and abide by the Company’s rules, regulations, and policies.</div>
            </div>

            <div class="numbered-clause gap-red">
                <div class="clause-number">7.</div>
                <div class="clause-text">It is knowingly and willingly understood that this contract of employment shall be limited only for the period/term and <strong><?php echo contractEscape($projectNameClause); ?></strong> indicated above and shall automatically terminate on the date/term stated above without the need for any further notice to you unless earlier terminated by the Company for lawful or just cause such as, but not limited to, earlier completion of the work for which you are hired, non-compliance with Company rules and regulations or for any other justifiable reason.</div>
            </div>

            <div class="numbered-clause gap-green">
                <div class="clause-number">8.</div>
                <div class="clause-text">Your employment herein is understood to be on an Extended Maintenance Agreement and Term Basis only, limited to and by the terms and conditions herein knowingly and willingly agreed upon by the Employee, and shall in no manner obligate the Company to extend the <?php echo contractEscape($projectNameDisplay); ?> Phase and term/period of this contract.</div>
            </div>

            <div class="numbered-clause">
                <div class="clause-number">9.</div>
                <div class="clause-text">Your work schedule will be given to you by our work superiors. Work schedules are expected to be strictly followed.</div>
            </div>
        </main>
    </section>

    <section class="document-page page-two">
        <header class="document-header">
            <div class="company-row">
                <img src="<?php echo contractEscape($logoSrc); ?>" class="company-logo" alt="TESP Logo">
                <div class="company-name">TES PHILIPPINES, INC.</div>
            </div>
            <div class="project-header">METRO RAIL TRANSIT LINE 3 MAINTENANCE & REHABILITATION PROJECT</div>
            <div class="company-address">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
        </header>

        <main class="page-body">
            <div class="numbered-clause">
                <div class="clause-number">10.</div>
                <div class="clause-text">For the duration of your employment, you agree to render overtime service or work on specified holidays and rest days, or specified work shifts if necessary to the completion of the project for which additional or premium compensation is paid by law.</div>
            </div>

            <p class="closing-paragraph">If you agree to the foregoing terms and conditions of your project employment, please sign in the space provided below.</p>

            <div class="management-block">
                <div>Truly yours,</div>
                <div class="management-space"></div>
                <div class="management-name">JUNJI FURUYA</div>
                <div>President</div>
            </div>

            <p class="acknowledgment">I hereby certify that I have read and fully understood the terms and conditions of the foregoing Project Employment Contract and accept them accordingly.</p>

            <div class="employee-signature-block">
                <div class="signature-line"></div>
                <div class="employee-signed-name"><?php echo contractEscape($employeeName); ?></div>
                <div class="signature-caption">Employee’s Name and Signature</div>
                <div class="date-row"><span>Date:</span><span class="date-line"></span></div>
            </div>
        </main>
    </section>
</body>

</html>