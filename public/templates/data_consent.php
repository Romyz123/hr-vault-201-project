<?php
/* ================================================================
   EMPLOYEE PERSONAL DATA CONSENT STATEMENT
   Complete single-file PHP template

   WORD PAGE SETTINGS
   - A4 portrait
   - Header from top edge: 2.08 cm
   - Main content from top edge: 3.63 cm
   - Left margin: 2.00 cm
   - Right margin: 1.50 cm
   - Bottom margin: 0.49 cm
   ================================================================ */

if (!isset($emp) || !is_array($emp)) {
    $emp = [];
}

/* Prefer the complete employee name stored by the system. */
$systemFullName = trim(
    $emp['full_name']
        ?? $emp['employee_name']
        ?? $emp['name']
        ?? ''
);

/* Fallback when the system stores separate name fields. */
if ($systemFullName === '') {
    $firstName  = trim($emp['first_name'] ?? '');
    $middleName = trim($emp['middle_name'] ?? '');
    $lastName   = trim($emp['last_name'] ?? '');
    $suffix     = trim($emp['suffix'] ?? '');

    $middleInitial = $middleName !== ''
        ? substr($middleName, 0, 1) . '.'
        : '';

    $systemFullName = trim(
        $firstName . ' ' .
            $middleInitial . ' ' .
            $lastName . ' ' .
            $suffix
    );
}

$fullName = strtoupper($systemFullName);

/* Company logo. You may set $global_logo_src before including this file. */
$logoPath = $logoPath
    ?? 'C:\\xampp\\htdocs\\hr 201\\uploads\\tesp logo 1.png';

$logoSrc = isset($global_logo_src)
    ? trim((string) $global_logo_src)
    : '';

if ($logoSrc === '' && is_file($logoPath)) {
    $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));

    if ($extension === 'jpg' || $extension === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($extension === 'svg') {
        $mime = 'image/svg+xml';
    } else {
        $mime = 'image/' . $extension;
    }

    $logoSrc = 'data:' . $mime . ';base64,' .
        base64_encode(file_get_contents($logoPath));
}

if ($logoSrc === '') {
    $logoSrc = '/hr 201/uploads/tesp logo 1.png';
}

if (!function_exists('consentEscape')) {
    function consentEscape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Personal Data Consent Statement</title>

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
            width: 100%;
            margin: 0;
            padding: 0;
            color: #000;
            background: #fff;
            font-family: "Times New Roman", Times, serif;
        }

        /* ------------------------------------------------------------
           FIXED A4 PAGE
           Header and main content are positioned independently.
           ------------------------------------------------------------ */
        .document-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            padding: 0;
            overflow: hidden;
            background: #fff;
            font-family: "Times New Roman", Times, serif;
            font-size: 10.5pt;
            line-height: 1.12;
        }

        .page-one {
            page-break-after: always;
            break-after: page;
        }

        /* ------------------------------------------------------------
           WORD HEADER POSITION: 2.08 CM FROM TOP EDGE
           LEFT: 1 CM, RIGHT: 1 CM
           ------------------------------------------------------------ */
        .document-header {
            position: absolute;
            top: 20.8mm;
            left: 10mm;
            right: 10mm;
            width: auto;
            margin: 0;
            padding: 0;
            text-align: center;
            z-index: 2;
        }

        .company-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            height: 31px;
            margin: 0;
            padding: 0;
        }

        .company-logo {
            display: block;
            width: 25px;
            height: 25px;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 50%;
            object-fit: contain;
        }

        .company-name {
            margin: 0;
            padding: 0;
            color: #777;
            font-family: "Times New Roman", Times, serif;
            font-size: 15pt;
            font-weight: 400;
            line-height: 1;
            white-space: nowrap;
        }

        .company-regulation {
            margin-top: 5pt;
            color: #777;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.5pt;
            font-weight: 400;
            line-height: 1;
            text-align: center;
        }

        /* ------------------------------------------------------------
           MAIN CONTENT AREA
           TOP: 3.63 CM, LEFT: 1 CM, RIGHT: 1 CM, BOTTOM: 0.49 CM
           ------------------------------------------------------------ */
        .page-body {
            position: absolute;
            top: 36.3mm;
            left: 10mm;
            right: 10mm;
            bottom: 4.9mm;
            width: auto;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        .document-title {
            margin: 0 0 22pt;
            padding: 0;
            font-size: 11pt;
            font-weight: 400;
            line-height: 1;
            text-align: center;
            text-decoration: underline;
        }

        /* ------------------------------------------------------------
           PRIMARY NUMBERED CLAUSES
           ------------------------------------------------------------ */
        .main-clause {
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr);
            column-gap: 3px;
            width: 100%;
            margin: 0 0 9pt;
            padding: 0;
        }

        .main-number {
            padding-right: 4px;
            text-align: right;
            white-space: nowrap;
        }

        .main-text {
            min-width: 0;
            text-align: left;
        }

        /* Page 2 has more visible space between complete main clauses. */
        .page-two .main-clause {
            margin-bottom: 16pt;
        }

        /* ------------------------------------------------------------
           LETTERED CLAUSES: (a), (b)
           ------------------------------------------------------------ */
        .letter-clause {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            column-gap: 4px;
            width: calc(100% - 18px);
            margin: 0 0 9pt 18px;
            padding: 0;
        }

        .letter-marker {
            text-align: right;
            white-space: nowrap;
        }

        .letter-text {
            min-width: 0;
            text-align: left;
        }

        /* ------------------------------------------------------------
           ROMAN NUMERAL CLAUSES: (i), (ii), (iii), (iv)
           ------------------------------------------------------------ */
        .roman-clause {
            display: grid;
            grid-template-columns: 45px minmax(0, 1fr);
            column-gap: 5px;
            width: calc(100% - 45px);
            margin: 0 0 9pt 45px;
            padding: 0;
        }

        .roman-marker {
            text-align: right;
            white-space: nowrap;
        }

        .roman-text {
            min-width: 0;
            text-align: left;
        }

        .purposes-line {
            margin: 18pt 0 10pt 74px;
            padding: 0;
        }

        .continuation-line {
            margin: 0 0 9pt 74px;
            padding: 0;
        }

        /* ------------------------------------------------------------
           PAGE 2 DEFINITIONS
           ------------------------------------------------------------ */
        .section-six {
            margin-bottom: 12pt !important;
        }

        .definition-block {
            margin: 0 15px 12pt 65px;
            padding: 0;
            text-align: left;
        }

        .definition-block strong {
            font-weight: 700;
        }

        .definition-romans {
            margin: 14pt 0 14pt 65px;
            padding: 0;
        }

        .definition-romans .roman-clause {
            width: calc(100% - 10px);
            margin: 0 0 1pt 0;
        }

        /* ------------------------------------------------------------
           SIGNATURE SECTION
           ------------------------------------------------------------ */
        .signature-section {
            width: calc(100% - 80px);
            margin: 24pt 0 0 55px;
            padding: 0;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .signature-table td {
            vertical-align: top;
            font-family: "Times New Roman", Times, serif;
            font-size: 10.5pt;
        }

        .employee-signature-cell {
            width: 62%;
            padding-right: 42px;
        }

        .date-signature-cell {
            width: 38%;
            padding-left: 12px;
        }

        .signature-line {
            height: 5pt;
            border-top: 0.75pt solid #000;
        }

        .employee-system-name {
            margin-bottom: 2pt;
            font-weight: 700;
            line-height: 1;
            text-align: left;
        }

        .signature-label {
            line-height: 1;
            text-align: left;
        }

        /* FINAL TYPOGRAPHY: Times New Roman, 10.5pt legal body text. */
        .page-body,
        .main-clause,
        .main-number,
        .main-text,
        .letter-clause,
        .letter-marker,
        .letter-text,
        .roman-clause,
        .roman-marker,
        .roman-text,
        .purposes-line,
        .continuation-line,
        .definition-block,
        .definition-romans,
        .signature-section,
        .signature-table,
        .signature-table td,
        .employee-system-name,
        .signature-label {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 10.5pt !important;
        }

        .main-clause,
        .letter-clause,
        .roman-clause,
        .purposes-line,
        .continuation-line,
        .definition-block,
        .definition-romans,
        .signature-table td,
        .employee-system-name,
        .signature-label {
            line-height: 1.08 !important;
        }

        /* Header hierarchy remains larger than the 7pt legal body. */
        .company-name {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 15pt !important;
        }

        .company-regulation {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 10.5pt !important;
        }

        .document-title {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 11pt !important;
        }

        /* CLAUSE 1: force the opening recital into approximately three lines. */
        .page-one .opening-clause {
            width: 100% !important;
            grid-template-columns: 22px minmax(0, 1fr) !important;
        }

        .page-one .opening-clause .main-text {
            width: 100% !important;
            max-width: none !important;
            line-height: 1.08 !important;
            white-space: normal;
        }

        /* FINAL SCREENSHOT-BASED ALIGNMENT */
        .page-body,
        .main-clause,
        .main-number,
        .main-text,
        .letter-clause,
        .letter-marker,
        .letter-text,
        .roman-clause,
        .roman-marker,
        .roman-text,
        .purposes-line,
        .continuation-line,
        .definition-block,
        .definition-romans,
        .signature-section,
        .signature-table,
        .signature-table td,
        .employee-system-name,
        .signature-label {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 10.5pt !important;
        }

        .main-clause,
        .letter-clause,
        .roman-clause,
        .purposes-line,
        .continuation-line,
        .definition-block,
        .definition-romans,
        .signature-table td,
        .employee-system-name,
        .signature-label {
            line-height: 1.12 !important;
        }

        /* Header and Page 2 body placement based on the supplied screenshots. */
        .document-header {
            top: 23.8mm !important;
        }

        .page-two .page-body {
            top: 42mm !important;
        }

        .document-title {
            margin-bottom: 26pt !important;
        }

        /* Roman numeral blocks sit farther right in the Word reference. */
        .roman-clause {
            margin-left: 60px !important;
            width: calc(100% - 60px) !important;
        }

        /* Definition blocks use a deeper left indent on Page 2. */
        .definition-block,
        .definition-romans {
            margin-left: 72px !important;
        }

        /* Preserve compact definition subclauses. */
        .definition-romans .roman-clause {
            margin-left: 0 !important;
            width: calc(100% - 10px) !important;
        }

        .signature-section {
            margin-top: 26pt !important;
        }

        /* FINAL COMPACT HEADER: use the marked empty top area. */
        .document-header {
            top: 9mm !important;
        }

        /* Bring Page 1 body upward while keeping clear separation from header. */
        .page-one .page-body {
            top: 27mm !important;
        }

        /* Page 2 uses the same compact header treatment. */
        .page-two .page-body {
            top: 27mm !important;
        }

        /* Larger and more prominent document title. */
        .document-title {
            margin-top: 0 !important;
            margin-bottom: 22pt !important;
            font-family: "Times New Roman", Times, serif !important;
            font-size: 15pt !important;
            font-weight: 700 !important;
            line-height: 1.05 !important;
            text-align: center !important;
            text-decoration: underline !important;
        }

        /* Keep the company header compact and centered. */
        .company-row {
            height: 27px !important;
        }

        .company-logo {
            width: 24px !important;
            height: 24px !important;
        }

        .company-name {
            font-size: 15pt !important;
            line-height: 1 !important;
        }

        .company-regulation {
            margin-top: 3pt !important;
            font-size: 9pt !important;
            line-height: 1 !important;
        }

        /* FINAL FORMAL SPACING:
           Two spacing units after Clause 1, before Clause (a). */
        .page-one .opening-clause {
            margin-bottom: 12pt !important;
        }

        /* Formal legal-document typography. */
        .page-body,
        .main-clause,
        .main-number,
        .main-text,
        .letter-clause,
        .letter-marker,
        .letter-text,
        .roman-clause,
        .roman-marker,
        .roman-text,
        .purposes-line,
        .continuation-line,
        .definition-block,
        .definition-romans,
        .signature-section,
        .signature-table td,
        .employee-system-name,
        .signature-label {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 10.5pt !important;
            line-height: 1.12 !important;
        }

        /* A 14pt title is formal and proportionate to 10.5pt body text. */
        .document-title {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 14pt !important;
            font-weight: 700 !important;
            line-height: 1.05 !important;
            text-align: center !important;
            text-decoration: underline !important;
        }

        /* FINAL READABILITY UPDATE: formal Times New Roman 11pt. */
        .page-body,
        .main-clause,
        .main-number,
        .main-text,
        .letter-clause,
        .letter-marker,
        .letter-text,
        .roman-clause,
        .roman-marker,
        .roman-text,
        .purposes-line,
        .continuation-line,
        .definition-block,
        .definition-romans,
        .signature-section,
        .signature-table td,
        .employee-system-name,
        .signature-label {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 11pt !important;
            line-height: 1.08 !important;
        }

        /* Keep complete clauses separated without wasting page height. */
        .main-clause {
            margin-bottom: 8pt !important;
        }

        .page-two .main-clause {
            margin-bottom: 9pt !important;
        }

        .letter-clause,
        .roman-clause {
            margin-bottom: 7pt !important;
        }

        /* Preserve the requested two-space gap between Clause 1 and (a). */
        .page-one .opening-clause {
            margin-bottom: 12pt !important;
        }

        .definition-block {
            margin-bottom: 9pt !important;
        }

        .definition-romans {
            margin-top: 9pt !important;
            margin-bottom: 9pt !important;
        }

        /* Formal heading hierarchy. */
        .document-title {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 15pt !important;
            font-weight: 700 !important;
            line-height: 1.05 !important;
            text-align: center !important;
            text-decoration: underline !important;
        }

        .company-name {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 16pt !important;
        }

        .company-regulation {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 9pt !important;
        }

        /* Keep the execution block visible on Page 2 at 11pt. */
        .signature-section {
            margin-top: 18pt !important;
        }

        /* FINAL RED-MARKED SPACING: two spacing units (12pt). */

        /* Page 1: space after Clause 1, Clause (a), and each Roman clause. */
        .page-one .opening-clause,
        .page-one .letter-clause,
        .page-one .roman-clause {
            margin-bottom: 12pt !important;
        }

        /* Page 1: space before and after the collective Purposes line. */
        .page-one .purposes-line {
            margin-top: 12pt !important;
            margin-bottom: 12pt !important;
        }

        /* Page 1: space after the continuation under Clause (b). */
        .page-one .continuation-line {
            margin-top: 12pt !important;
            margin-bottom: 12pt !important;
        }

        /* Page 2: space after each complete numbered clause. */
        .page-two .main-clause {
            margin-bottom: 12pt !important;
        }

        /* Page 2: space after Affiliate introduction and each definition. */
        .page-two .definition-block {
            margin-bottom: 12pt !important;
        }

        /* Page 2: keep the three Affiliate subclauses compact internally,
           then add two spaces after the complete Roman-numeral block. */
        .page-two .definition-romans {
            margin-top: 12pt !important;
            margin-bottom: 12pt !important;
        }

        .page-two .definition-romans .roman-clause {
            margin-bottom: 0 !important;
        }

        /* Keep signature block separated from the last definition. */
        .page-two .signature-section {
            margin-top: 12pt !important;
        }

        /* FINAL HEADER AND SIGNATURE ADJUSTMENTS */

        /* Slightly larger corporate header. */
        .company-row {
            height: 34px !important;
            gap: 7px !important;
        }

        .company-logo {
            width: 30px !important;
            height: 30px !important;
        }

        .company-name {
            font-family: "Times New Roman", Times, serif !important;
            font-size: 18pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
        }

        .company-regulation {
            margin-top: 4pt !important;
            font-family: "Times New Roman", Times, serif !important;
            font-size: 10pt !important;
            line-height: 1 !important;
        }

        /* Keep the enlarged header clear of the body. */
        .page-one .page-body,
        .page-two .page-body {
            top: 31mm !important;
        }

        /* Three line-space units before the signature lines. */
        .page-two .signature-section {
            margin-top: 33pt !important;
        }

        /* Keep labels close to their signature lines. */
        .signature-line {
            height: 6pt !important;
        }

        .employee-system-name,
        .signature-label {
            margin-top: 2pt !important;
        }

        /* FINAL PAGE MARGINS AND TITLE SPACING */

        /* Requested horizontal margins: 2cm left and 1.5cm right. */
        .document-header,
        .page-body {
            left: 20mm !important;
            right: 15mm !important;
        }

        /* Three 11pt line spaces below the title before Clause 1. */
        .page-one .document-title {
            margin-bottom: 33pt !important;
        }

        /* Preserve the existing three-line opening recital within the narrower body. */
        .page-one .opening-clause .main-text {
            width: 100% !important;
            max-width: none !important;
            line-height: 1.08 !important;
        }

        /* Screen-only page preview. */
        @media screen {
            body {
                background: #ddd;
            }

            .document-page {
                margin: 12px auto;
                box-shadow: 0 0 5px rgba(0, 0, 0, 0.3);
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
                width: 210mm;
                height: 297mm;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body>

    <!-- ============================================================= -->
    <!-- PAGE 1                                                        -->
    <!-- ============================================================= -->
    <section class="document-page page-one">
        <header class="document-header">
            <div class="company-row">
                <img
                    src="<?php echo consentEscape($logoSrc); ?>"
                    class="company-logo"
                    alt="TES Logo">
                <div class="company-name">TES Philippines, Inc.</div>
            </div>
            <div class="company-regulation">COMPANY REGULATION</div>
        </header>

        <div class="page-body">
            <div class="document-title">
                Employee Personal Data Consent Statement
            </div>

            <div class="main-clause opening-clause">
                <div class="main-number">1.</div>
                <div class="main-text">
                    Without limiting the rights of TES Philippines, Inc. (“Company”) under and subject to applicable law, by<br>
                    signing this Employee Personal Data Consent Statement (“Consent Statement”), I consent to the<br>
                    Company, any Affiliate and any third party acting on its or their behalf:
                </div>
            </div>

            <div class="letter-clause">
                <div class="letter-marker">(a)</div>
                <div class="letter-text">
                    To Process my Personal Data for any purpose directly or indirectly
                    connected with:
                </div>
            </div>

            <div class="roman-clause">
                <div class="roman-marker">(i)</div>
                <div class="roman-text">
                    managing or terminating my employment including but not limited to
                    background and reference checks; job suitability assessments;
                    assessment of medical conditions; visa, work pass and travel document
                    applications; management and human resource operations; staff directory
                    information; compensation decisions; payroll, benefits and entitlements;
                    insurance coverage and claims; staff welfare; professional and/or club
                    memberships; relocation and accommodation arrangements; administering
                    reimbursement of expenses; tax returns and/or related filings with
                    governmental authorities; manpower planning, secondments and transfers;
                    training programmes; performance appraisals, performance management,
                    promotion and career development activities; corporate team building
                    programmes and/or activities; communicating with family members in
                    relation to death, illness, injury or emergency in connection with work;
                    monitoring compliance with the Company’s internal rules and policies;
                    communications with and/or response to government agencies; investigatory
                    or disciplinary matters; employment decisions including end of employment,
                    end of employment procedures, exit interviews or surveys, post-termination
                    administration and procedures; providing references to third parties;
                    consideration for any other or future job position(s) in the Company or
                    any Affiliate; and contractual or statutory obligations;
                </div>
            </div>

            <div class="roman-clause">
                <div class="roman-marker">(ii)</div>
                <div class="roman-text">
                    the administration, management and/or operation of the business of the
                    Company and/or its Affiliates including but not limited to company events;
                    facility, security, health and safety management; internal technical and
                    operational support; corporate security measures; internal auditing,
                    compliance and risk management; company newsletters; marketing initiatives
                    of the Company and any Affiliates via various media including but not
                    limited to newspapers, magazines, Company website and social media platforms,
                    such as pitches, proposals, contributions to publications, marketing,
                    ranking tables, biographies; providing details to customers or other
                    business associates or third parties for contact and/or access and security
                    purposes; statistical information for internal and external purposes;
                    business continuity management and disaster recovery preparedness;
                </div>
            </div>

            <div class="roman-clause">
                <div class="roman-marker">(iii)</div>
                <div class="roman-text">
                    any business asset transaction including but not limited to facilitating
                    the reorganisation or sale of all or part of the Company’s business and/or
                    assets; and/or
                </div>
            </div>

            <div class="roman-clause">
                <div class="roman-marker">(iv)</div>
                <div class="roman-text">
                    compliance with applicable laws and regulations and/or legal proceedings.
                </div>
            </div>

            <div class="purposes-line">
                (collectively the “Purposes”); and
            </div>

            <div class="letter-clause">
                <div class="letter-marker">(b)</div>
                <div class="letter-text">
                    To process including to transfer my Personal Data outside the Philippines
                    for such Purposes,
                </div>
            </div>

            <div class="continuation-line">
                during and for a reasonable period after termination of my employment in
                accordance with Company policy and to the extent permitted by law.
            </div>
        </div>
    </section>

    <!-- ============================================================= -->
    <!-- PAGE 2                                                        -->
    <!-- ============================================================= -->
    <section class="document-page page-two">
        <header class="document-header">
            <div class="company-row">
                <img
                    src="<?php echo consentEscape($logoSrc); ?>"
                    class="company-logo"
                    alt="TES Logo">
                <div class="company-name">TES Philippines, Inc.</div>
            </div>
            <div class="company-regulation">COMPANY REGULATION</div>
        </header>

        <div class="page-body">
            <div class="main-clause">
                <div class="main-number">2.</div>
                <div class="main-text">
                    I agree that Processing my Personal Data is necessary and is a condition
                    of my employment and the Company is unable to proceed with and/or continue
                    my employment if I do not agree to provide my Personal Data or to Processing
                    my Personal Data as set out in this Consent Statement.
                </div>
            </div>

            <div class="main-clause">
                <div class="main-number">3.</div>
                <div class="main-text">
                    I agree to provide to the Company, any Affiliate and any third party acting
                    on its or their behalf Personal Data relating to third parties including
                    but not limited to my family members (“Third Parties”) to be Processed for
                    any of the Purposes as and when required by the Company. I confirm that I
                    have obtained and/or will have obtained the consent of such Third Parties
                    to the Processing of their Personal Data on or before providing their
                    Personal Data as required.
                </div>
            </div>

            <div class="main-clause">
                <div class="main-number">4.</div>
                <div class="main-text">
                    I understand that I may request access to and/or correction of my Personal
                    Data in accordance with and subject to law and Company policy by contacting
                    the Data Protection Officer of the Company.
                </div>
            </div>

            <div class="main-clause">
                <div class="main-number">5.</div>
                <div class="main-text">
                    I will inform and update the Company of any changes to my Personal Data as
                    soon as practicable and in any case not later than two (2) weeks following
                    such changes.
                </div>
            </div>

            <div class="main-clause section-six">
                <div class="main-number">6.</div>
                <div class="main-text">
                    For the purposes of this Consent Statement:
                </div>
            </div>

            <div class="definition-block">
                <strong>“Affiliate”</strong> means a corporation, entity or other organisation
                which:
            </div>

            <div class="definition-romans">
                <div class="roman-clause">
                    <div class="roman-marker">(i)</div>
                    <div class="roman-text">
                        is directly or indirectly controlled by the Company;
                    </div>
                </div>

                <div class="roman-clause">
                    <div class="roman-marker">(ii)</div>
                    <div class="roman-text">
                        directly or indirectly controls the Company; or
                    </div>
                </div>

                <div class="roman-clause">
                    <div class="roman-marker">(iii)</div>
                    <div class="roman-text">
                        is directly or indirectly controlled by a third party who also directly
                        or indirectly controls the Company,
                    </div>
                </div>
            </div>

            <div class="definition-block">
                and where the term “control” when used with respect to any organisation means
                the ownership of fifteen per cent (15%) of issued shares or more.
            </div>

            <div class="definition-block">
                <strong>“Business asset transaction”</strong> has the meaning defined under the
                Data Privacy Act of 2012 and Republic Act No. 10173.
            </div>

            <div class="definition-block">
                <strong>“Personal Data”</strong> refers to data, whether true or not, about an
                individual who can be identified from that data, or from that data in
                combination with other information to which the organisation may have access.
            </div>

            <div class="definition-block">
                <strong>“Processing”</strong> includes recording, holding, organisation,
                adaptation or alteration, retrieval, combination, transmission, erasure,
                obtaining, copying, amending, adding, deleting, extracting, storing,
                disclosing, transferring or destruction of Personal Data.
            </div>

            <div class="definition-block">
                <strong>“Transferring”</strong> refers to transferring Personal Data outside
                of the Philippines, whether by electronic means, such as cloud storage or
                email, or physical means, such as shipping physical files.
            </div>

            <div class="signature-section">
                <table class="signature-table">
                    <tr>
                        <td class="employee-signature-cell">
                            <div class="signature-line"></div>

                            <?php if ($fullName !== ''): ?>
                                <div class="employee-system-name">
                                    <?php echo consentEscape($fullName); ?>
                                </div>
                            <?php endif; ?>

                            <div class="signature-label">
                                Name &amp; Signature
                            </div>
                        </td>

                        <td class="date-signature-cell">
                            <div class="signature-line"></div>
                            <div class="signature-label">Date</div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </section>

</body>

</html>