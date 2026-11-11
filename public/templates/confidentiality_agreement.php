<?php
/*
|--------------------------------------------------------------------------
| TWO-PAGE CONFIDENTIALITY AGREEMENT
|--------------------------------------------------------------------------
| Fixed distribution:
|   Page 1: Introduction, Section 1, Section 2
|   Page 2: Section 3, Section 4, acknowledgment, signatures
|
| IMPORTANT:
| - Save this as a real .php file.
| - Do not add HTML line break tags between PHP statements.
| - Keep PHP opening tags unescaped.
*/

if (!isset($emp) || !is_array($emp)) {
    $emp = [];
}

$firstName  = trim($emp['first_name'] ?? '');
$middleName = trim($emp['middle_name'] ?? '');
$lastName   = trim($emp['last_name'] ?? '');

if (!isset($emp_name_formal) || trim((string) $emp_name_formal) === '') {
    // Prefer the employee's complete name exactly as stored by the system.
    $systemFullName = trim(
        $emp['full_name']
            ?? $emp['employee_name']
            ?? $emp['name']
            ?? ''
    );

    if ($systemFullName !== '') {
        $emp_name_formal = strtoupper($systemFullName);
    } else {
        // Fallback when the system stores the name in separate fields.
        $middleInitial = $middleName !== ''
            ? substr($middleName, 0, 1) . '.'
            : '';

        $suffix = trim($emp['suffix'] ?? '');

        $emp_name_formal = strtoupper(
            trim(
                $firstName . ' ' .
                    $middleInitial . ' ' .
                    $lastName . ' ' .
                    $suffix
            )
        );
    }
}

if (!isset($address) || trim((string) $address) === '') {
    $address = strtoupper(trim($emp['present_address'] ?? ''));
}

if (!isset($position) || trim((string) $position) === '') {
    $position = strtoupper(trim($emp['job_title'] ?? ''));
}

$docFontSize = isset($docFontSize) && is_numeric($docFontSize)
    ? (float) $docFontSize
    : 8;

$safeLogo = !empty($global_logo_src)
    ? htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8')
    : '';

if (!function_exists('agreementEscape')) {
    function agreementEscape($value)
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
    <title>Confidentiality Agreement</title>

    <style>
        /*
        |------------------------------------------------------------------
        | Fixed A4 canvas
        |------------------------------------------------------------------
        | Word margins are built into each page as padding:
        | top/bottom 0.7cm, left/right 2cm.
        */
        @page {
            size: A4 portrait;
            margin: 0;
        }

        :root {
            --legal-body-font: Arial, Helvetica, sans-serif;
            --legal-heading-font: Arial, Helvetica, sans-serif;
            --legal-body-size: <?php echo $docFontSize; ?>pt;
            --legal-line-spacing: 1.15;
            --legal-paragraph-after: 6pt;
            --legal-list-left-indent: 0.5in;
            --legal-list-hanging-indent: 0.25in;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: var(--legal-body-font);
        }

        .document-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            padding: 0.7cm 2cm;
            overflow: hidden;
            background: #fff;
            font-family: var(--legal-body-font);
            font-size: <?php echo $docFontSize; ?>pt;
            color: #000;
        }

        .page-one {
            page-break-after: always;
            break-after: page;
        }

        /*
        |------------------------------------------------------------------
        | Header repeated manually on both fixed pages
        |------------------------------------------------------------------
        */
        .document-header {
            width: 100%;
            margin: 0;
            padding: 0.05cm 0 0;
            text-align: center;
        }

        .company-line {
            height: 31px;
            line-height: 31px;
            text-align: center;
            white-space: nowrap;
        }

        .company-logo {
            width: 30px;
            height: 30px;
            margin-right: 5px;
            border: 0;
            border-radius: 50%;
            object-fit: contain;
            vertical-align: middle;
        }

        .company-name {
            display: inline-block;
            font-size: 9pt;
            font-weight: 700;
            line-height: 1;
            color: #6e6e6e;
            vertical-align: middle;
        }

        .project-name {
            margin: 1pt 0 0;
            padding: 0;
            font-family: "Times New Roman", Times, serif;
            font-size: 7pt;
            font-weight: 700;
            line-height: 1;
            color: #6e6e6e;
            text-align: center;
        }

        .company-address {
            margin: 1pt 0 0;
            padding: 0;
            font-family: "Times New Roman", Times, serif;
            font-size: 6pt;
            font-weight: 400;
            line-height: 1;
            color: #6e6e6e;
            text-align: center;
        }

        /* Screenshot-matched rhythm:
           header to title: 20pt; title to first paragraph: 25pt. */
        .doc-title {
            margin: 0;
            padding: 20pt 0 25pt;
            font-size: 9pt;
            font-weight: 700;
            line-height: 1.15;
            text-align: center;
            text-transform: uppercase;
        }

        /* LEGAL DOCUMENT ALIGNMENT MODEL:
           1) Main body: block paragraphs, fully justified, no first-line indent.
           2) Section number: separate 0.72cm column, right aligned.
           3) Section title: Arial bold, left aligned.
           4) Subclauses: alphanumeric outline with 0.5in left indent and
              0.25in hanging indent.
           5) Wrapped list lines align under the first word, not under a./b./c.
           6) Acknowledgment and signature blocks remain left aligned. */

        /* FORMAT CONTRACT:
           - Body, clauses, and list items: 1.15 line spacing.
           - Paragraphs and list blocks: 6pt after.
           - Individual list items: 0pt after, preventing page overflow.
           - Sections 1-2 remain on Page 1; Sections 3-4/signatures on Page 2. */

        /*
        |------------------------------------------------------------------
        | Paragraph spacing classes based on the supplied structure
        |------------------------------------------------------------------
        */
        p {
            margin: 0;
            padding: 0;
        }

        .paragraph-1-0 {
            margin: 0 0 var(--legal-paragraph-after);
            font-size: var(--legal-body-size);
            line-height: var(--legal-line-spacing);
            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        /* First employee preamble only: no paragraph spacing and single line spacing. */
        .employee-preamble-no-spacing {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 9pt !important;
            margin-left: 0 !important;
            padding: 0 !important;

            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            line-height: 1.15 !important;

            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;

            text-indent: 0;
        }

        /* Second preamble paragraph: 1.15 lines, no extra space before,
           and a controlled 9pt gap before Section 1. */
        .paragraph-1-15 {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 9pt !important;
            margin-left: 0 !important;
            padding: 0 !important;

            font-size: var(--legal-body-size);
            line-height: 1.15 !important;
            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        .bold {
            font-weight: 700;
        }

        .uppercase {
            text-transform: uppercase;
        }

        /*
        |------------------------------------------------------------------
        | Section headings
        |------------------------------------------------------------------
        */
        .section-heading {
            display: grid;
            grid-template-columns: 0.72cm 1fr;
            width: 100%;
            margin: 0 0 var(--legal-paragraph-after);
            padding: 0;
            font-family: var(--legal-heading-font);
            font-size: var(--legal-body-size);
            font-weight: 700;
            line-height: var(--legal-line-spacing);
            text-align: left;
        }

        .section-heading.with-space-before {
            margin-top: 8pt;
        }

        .section-number {
            padding-right: 6pt;
            text-align: right;
            white-space: nowrap;
        }

        .section-title {
            text-align: left;
        }

        .section-introduction {
            margin: 0 0 var(--legal-paragraph-after) 0.72cm;
            font-size: var(--legal-body-size);
            line-height: var(--legal-line-spacing);
            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        /*
        |------------------------------------------------------------------
        | Word-style lettered lists
        | Left indent: 0.5in
        | Hanging indent: 0.25in
        |------------------------------------------------------------------
        */
        .alpha-list {
            list-style: none;
            counter-reset: alpha-item;
            margin: 0 0 var(--legal-paragraph-after) 0;
            padding: 0;
        }

        .alpha-list li {
            position: relative;
            counter-increment: alpha-item;
            margin: 0 0 0 var(--legal-list-left-indent);
            padding: 0;
            font-size: var(--legal-body-size);
            line-height: var(--legal-line-spacing);
            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        .alpha-list li::before {
            content: counter(alpha-item, lower-alpha) ".";
            position: absolute;
            left: calc(-1 * var(--legal-list-hanging-indent));
            width: 0.20in;
            font-weight: 400;
            text-align: left;
        }

        /* Screenshot-matched Section 1 list:
           compact a-p outline, 1.15 lines, zero paragraph-after per item. */
        .section-one-list {
            margin-top: 0;
            margin-right: 0;
            margin-bottom: 6pt;
            margin-left: 0;
            padding: 0;
        }

        .section-one-list li {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0.5in !important;
            padding-top: 0 !important;
            padding-right: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;
            line-height: 1 !important;
            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }


        .section-one-list li+li {
            margin-top: 0 !important;
        }

        .section-one-list li::before {
            line-height: 1 !important;
        }

        /* Screenshot-matched Section 2 list:
           same hanging indent, slight 2pt separation between legal clauses. */
        .section-two-list {
            margin-top: 0;
            margin-right: 0;
            margin-bottom: 0;
            margin-left: 0;
            padding: 0;
        }

        .section-two-list li {
            margin-top: 0;
            margin-right: 0;
            margin-bottom: 0.5pt;
            margin-left: 0.5in;
            padding: 0;
            line-height: 1.15;
            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        .section-one-list li::before,
        .section-two-list li::before {
            left: -0.25in;
            width: 0.20in;
            text-align: left;
        }

        /* The note under item p aligns with the clause text, not the marker. */
        .section-one-list .list-note {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
            text-align: left;
        }

        .alpha-list.list-1-15 li {
            line-height: 1.15;
            margin-bottom: 0;
        }

        .list-note {
            display: block;
            margin: 1pt 0 0;
            font-size: <?php echo max($docFontSize - 1, 6); ?>pt;
            font-style: italic;
            line-height: 1.15;
            text-align: left;
        }

        /*
        |------------------------------------------------------------------
        | Page 2 distribution
        |------------------------------------------------------------------
        */
        .page-two .first-section {
            margin-top: 12pt;
        }

        .page-two .enforcement-text {
            margin-left: 0.72cm;
            margin-bottom: 12pt;
            line-height: 1.15;
        }

        .page-two .general-list li {
            margin-bottom: 2pt;
            line-height: 1.15;
        }

        .agreement-confirmation {
            margin: 14pt 0 23pt;
            font-size: <?php echo $docFontSize; ?>pt;
            font-weight: 400;
            line-height: 1.15;
            text-align: left;
            white-space: nowrap;
        }

        /*
        |------------------------------------------------------------------
        | Signature area
        |------------------------------------------------------------------
        */
        .signature-section {
            width: 170pt;
            margin: 0;
            padding: 0;
            font-size: <?php echo $docFontSize; ?>pt;
            line-height: 1;
        }

        .signature-block {
            width: 155pt;
            margin: 0 0 27pt;
            padding: 0;
        }

        .signature-line {
            width: 145pt;
            height: 14pt;
            margin: 0 0 4pt;
            border-bottom: 0.75pt solid #000;
        }

        .signature-caption {
            width: 145pt;
            font-size: <?php echo max($docFontSize - 1, 6); ?>pt;
            text-align: center;
        }

        .signature-date {
            margin-top: 18pt;
            white-space: nowrap;
        }

        .company-signature-block {
            margin-top: 18pt;
            margin-bottom: 0;
        }

        .company-signature-title {
            margin: 0 0 9pt;
        }

        .president-position {
            margin: 0 0 17pt;
        }

        .president-name {
            width: 145pt;
            font-weight: 700;
            text-align: center;
        }

        /* SECTION 1 INTRODUCTION:
           Single line spacing, with a controlled 6pt gap before item a. */
        .section-one-introduction-no-spacing {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 6pt !important;
            margin-left: 0.72cm !important;
            padding: 0 !important;

            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            line-height: 1 !important;

            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
            text-indent: 0 !important;
        }

        /* FINAL SECTION 1 OVERRIDE: a-p behave as one compact legal block. */
        ol.alpha-list.section-one-list {
            display: block;
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 6pt !important;
            margin-left: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
        }

        ol.alpha-list.section-one-list>li {
            display: block;
            position: relative;
            min-height: 0 !important;

            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0.5in !important;

            padding-top: 0 !important;
            padding-right: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;

            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            line-height: 1 !important;

            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        ol.alpha-list.section-one-list>li+li {
            margin-top: 0 !important;
        }

        ol.alpha-list.section-one-list>li::before {
            position: absolute;
            left: -0.25in !important;
            width: 0.20in !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
            text-align: left;
        }

        ol.alpha-list.section-one-list .list-note {
            display: block;
            margin: 0 !important;
            padding: 0 !important;
            font-size: <?php echo max($docFontSize - 1, 6); ?>pt;
            font-style: italic;
            line-height: 1 !important;
            text-align: left;
        }

        /* SECTION 2 HEADING AND LIST:
           6pt space below the heading, then a compact a-e legal block. */
        .section-heading.with-space-before {
            margin-top: 8pt !important;
            margin-right: 0 !important;
            margin-bottom: 6pt !important;
            margin-left: 0 !important;
            padding: 0 !important;
        }

        ol.alpha-list.section-two-list {
            display: block;
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
        }

        ol.alpha-list.section-two-list>li {
            display: block;
            position: relative;
            min-height: 0 !important;

            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0.5in !important;

            padding-top: 0 !important;
            padding-right: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;

            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            line-height: 1 !important;

            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        ol.alpha-list.section-two-list>li+li {
            margin-top: 0 !important;
        }

        ol.alpha-list.section-two-list>li::before {
            position: absolute;
            left: -0.25in !important;
            width: 0.20in !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
            text-align: left;
        }

        /* PAGE 2 COMPACT LAYOUT:
           6pt below headings; no extra spacing inside the green-marked blocks. */
        .page-two .section-heading {
            margin-top: 8pt !important;
            margin-right: 0 !important;
            margin-bottom: 6pt !important;
            margin-left: 0 !important;
            padding: 0 !important;
        }

        .page-two .first-section {
            margin-top: 12pt !important;
            margin-bottom: 6pt !important;
        }

        .page-two .enforcement-text {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 8pt !important;
            margin-left: 0.72cm !important;
            padding: 0 !important;

            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            line-height: 1 !important;

            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
            text-indent: 0 !important;
        }

        .page-two ol.alpha-list.general-list {
            display: block;
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 8pt !important;
            margin-left: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
        }

        .page-two ol.alpha-list.general-list>li {
            display: block;
            position: relative;
            min-height: 0 !important;

            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0.5in !important;

            padding-top: 0 !important;
            padding-right: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;

            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            line-height: 1 !important;

            text-align: justify;
            text-align-last: left;
            -webkit-text-align-last: left;
            text-justify: inter-word;
        }

        .page-two ol.alpha-list.general-list>li+li {
            margin-top: 0 !important;
        }

        .page-two ol.alpha-list.general-list>li::before {
            position: absolute;
            left: -0.25in !important;
            width: 0.20in !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1 !important;
            text-align: left;
        }

        /* SECTION 4: add spacing after each complete a/b/c clause,
           while keeping wrapped lines inside each clause single-spaced. */
        .page-two ol.alpha-list.general-list>li {
            margin-bottom: 6pt !important;
            line-height: 1 !important;
        }

        .page-two ol.alpha-list.general-list>li:last-child {
            margin-bottom: 8pt !important;
        }

        /* FINAL HEADER MATCH:
           Larger logo, bold black company name, centered project line,
           underlined address, dashed separator, and gray document title. */
        .document-header {
            width: 100%;
            margin: 0;
            padding: 0;
            text-align: center;
        }

        .company-line {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            height: auto;
            min-height: 40px;
            line-height: normal;
            margin: 0;
            padding: 0;
            white-space: nowrap;
        }

        .company-logo {
            display: block;
            flex: 0 0 50px;
            width: 50px;
            height: 50px;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 50%;
            object-fit: contain;
            vertical-align: middle;
        }

        .company-name {
            display: block;
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13pt;
            font-weight: 700;
            line-height: 1;
            color: #000;
            text-align: left;
            vertical-align: middle;
        }

        .project-name {
            margin: 5pt 0 0;
            padding: 0;
            font-family: "Times New Roman", Times, serif;
            font-size: 10.5pt;
            font-weight: 700;
            line-height: 1;
            color: #000;
            text-align: center;
            text-transform: uppercase;
        }

        .company-address {
            display: inline-block;
            margin: 1pt auto 0;
            padding: 0;
            font-family: "Times New Roman", Times, serif;
            font-size: 8pt;
            font-weight: 400;
            line-height: 1;
            color: #000;
            text-align: center;
            text-decoration: none;
        }

        .document-header::after {
            content: none;
            display: none;
            border: 0;
        }

        .doc-title {
            margin: 0;
            padding: 18pt 0 22pt;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1;
            color: #808080;
            text-align: center;
            text-transform: uppercase;
        }

        /* SECTION 2 FINAL SPACING:
           Add space after each complete a-e clause while keeping wrapped
           lines inside the same clause single-spaced. */
        .page-one ol.alpha-list.section-two-list>li {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 6pt !important;
            margin-left: 0.5in !important;
            padding: 0 !important;
            line-height: 1 !important;
        }

        .page-one ol.alpha-list.section-two-list>li:last-child {
            margin-bottom: 0 !important;
        }

        /* PAGE 2 HEADING SPACING:
           Two paragraph-space units (12pt) below Section 3 and Section 4 headings. */
        .page-two .section-heading {
            margin-bottom: 12pt !important;
        }

        .page-two .first-section {
            margin-bottom: 12pt !important;
        }

        /* FINAL CLOSING DISTRIBUTION:
           Two spacing units after General item c before acknowledgment. */
        .page-two ol.alpha-list.general-list>li:last-child {
            margin-bottom: 12pt !important;
        }

        .agreement-confirmation {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 24pt !important;
            margin-left: 0 !important;
        }

        /* Employee full name appears below the employee signature line. */
        .employee-signature-name {
            width: 145pt;
            margin: 0;
            padding: 0;
            font-family: var(--legal-body-font);
            font-size: var(--legal-body-size);
            font-weight: 700;
            line-height: 1;
            text-align: center;
            text-transform: uppercase;
        }

        .employee-signature-caption {
            width: 145pt;
            margin-top: 2pt;
            padding: 0;
            font-family: var(--legal-body-font);
            font-size: <?php echo max($docFontSize - 1, 6); ?>pt;
            font-weight: 400;
            line-height: 1;
            text-align: center;
        }

        /* FINAL RED-MARKED SPACING AND SECTION 1 LINE SPACING */

        /* Red mark 1: two spacing units below Section 1 heading. */
        .page-one .section-heading:first-of-type {
            margin-bottom: 12pt !important;
        }

        /* Section 1 items a-p: 1.5 line spacing per item. */
        .page-one ol.alpha-list.section-one-list>li {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0.5in !important;
            padding: 0 !important;
            line-height: 1.5 !important;
        }

        .page-one ol.alpha-list.section-one-list>li::before {
            line-height: 1.5 !important;
        }

        .page-one ol.alpha-list.section-one-list .list-note {
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.5 !important;
        }

        /* Red mark 2: two spacing units after item p and its note. */
        .page-one ol.alpha-list.section-one-list {
            margin-bottom: 12pt !important;
        }

        /* Red mark 3: two spacing units below Section 2 heading. */
        .page-one .section-heading.with-space-before {
            margin-top: 0 !important;
            margin-bottom: 12pt !important;
        }

        /* Screen preview resembling separate Word pages. */
        @media screen {
            body {
                background: #d8d8d8;
            }

            .document-page {
                margin: 12px auto;
                box-shadow: 0 0 6px rgba(0, 0, 0, 0.35);
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
                padding: 0.7cm 2cm;
                overflow: hidden;
                box-shadow: none !important;
            }

            .page-one {
                page-break-after: always;
                break-after: page;
            }
        }

        /* Add this AFTER all existing template CSS rules. */

        /* Red line 1: two spaces after the Therefore paragraph. */
        .page-one .paragraph-1-15 {
            margin-bottom: 12pt !important;
        }

        /* Red line 2: two spaces after Section 1 heading. */
        .page-one .section-heading:not(.with-space-before) {
            margin-bottom: 12pt !important;
        }

        /* Keep the Section 1 introduction compact internally. */
        .page-one .section-one-introduction-no-spacing {
            line-height: 1 !important;
        }

        /* Section 1 items a-p use 1.5 line spacing with no extra item margins. */
        .page-one ol.alpha-list.section-one-list>li {
            margin-top: 0 !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 0.5in !important;
            padding: 0 !important;
            line-height: 1.5 !important;
        }

        .page-one ol.alpha-list.section-one-list>li::before {
            line-height: 1.5 !important;
        }

        .page-one ol.alpha-list.section-one-list .list-note {
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.5 !important;
        }

        /* Red line 3: two spaces after item p and its note. */
        .page-one ol.alpha-list.section-one-list {
            margin-bottom: 12pt !important;
        }

        /* Preserve the existing two-space gap below Section 2 heading. */
        .page-one .section-heading.with-space-before {
            margin-top: 0 !important;
            margin-bottom: 12pt !important;
        }

        /* Add this at the very end of the existing <style> block. */

        /* Section 3, Enforcement paragraph: 1.5 line spacing. */
        .page-two .enforcement-text {
            line-height: 1.5 !important;
        }

        /* Section 4, General clauses a-c: 1.5 line spacing inside each clause. */
        .page-two ol.alpha-list.general-list>li,
        .page-two ol.alpha-list.general-list>li::before {
            line-height: 1.5 !important;
        }

        /* Keep a small, controlled gap between complete clauses. */
        .page-two ol.alpha-list.general-list>li {
            margin-bottom: 4pt !important;
        }

        .page-two ol.alpha-list.general-list>li:last-child {
            margin-bottom: 12pt !important;
        }
    </style>
</head>

<body>

    <!-- ================================================================ -->
    <!-- PAGE 1: INTRODUCTION, SECTION 1, SECTION 2                       -->
    <!-- ================================================================ -->
    <section class="document-page page-one">

        <header class="document-header">
            <div class="company-line">
                <?php if ($safeLogo !== ''): ?>
                    <img
                        src="<?php echo $safeLogo; ?>"
                        class="company-logo"
                        alt="TESP Logo">
                <?php endif; ?>

                <span class="company-name">TES PHILIPPINES, INC.</span>
            </div>

            <div class="project-name">
                METRO RAIL TRANSIT LINE 3 MAINTENANCE & REHABILITATION PROJECT
            </div>

            <div class="company-address">
                Meriton One Building, 1668 Quezon Avenue, Quezon City
            </div>
        </header>

        <div class="doc-title">CONFIDENTIALITY AGREEMENT</div>

        <!-- No extra paragraph spacing, single line spacing. -->
        <p class="employee-preamble-no-spacing">
            I,
            <span class="bold uppercase"><?php echo agreementEscape($emp_name_formal); ?></span>,
            of legal age, residing at
            <span class="bold uppercase"><?php echo agreementEscape($address); ?></span>,
            recognize that TES Philippines, Inc. (“TESP”), my current employer, is engaged in a highly competitive
            industry, and that it is important for TESP to protect its trade secrets, confidential information and other
            proprietary information and related rights acquired through TESP’s expenditure of time, effort, and money.
        </p>

        <p class="paragraph-1-15">
            Therefore, because in my position, I keep, receive and/or contribute to TESP’s Confidential Information,
            and in consideration of the remuneration I receive from TESP, I agree to be bound by the following terms
            and conditions which are described below.
        </p>

        <div class="section-heading">
            <span class="section-number">1.</span>
            <span class="section-title">Definition of Confidential Information</span>
        </div>

        <p class="section-one-introduction-no-spacing">
            In this agreement, “Confidential Information” includes confidential and proprietary information and various
            trade secrets including but not limited to:
        </p>

        <ol class="alpha-list section-one-list">
            <li>scientific, engineering, and technical know-how,</li>
            <li>processes and systems,</li>
            <li>computer software and related documentation owned by TESP or its partners,</li>
            <li>business strategies,</li>
            <li>customer requirements,</li>
            <li>supplier information,</li>
            <li>accounting documents and records,</li>
            <li>employees’ compensation and other employee records,</li>
            <li>methods of doing business,</li>
            <li>the financial affairs of TESP,</li>
            <li>manuals, procedures, and practices,</li>
            <li>contracts and agreements,</li>
            <li>bank records,</li>
            <li>property records,</li>
            <!-- Item o -->
            <li>
                and any other confidential, business, proprietary and/or trade secrets and/or other information in
                whatever form which belongs to or has been made known to the receiver of such information by TESP
                or its partners.
            </li>
            <!-- Item p -->
            <li>
                Related regulation “Data Privacy Act of 2012” and “Republic Act No. 10173”, which is prescribed for
                protecting personal information.
                <span class="list-note">
                    (As specified in Employee Personal Data Consent Statement)
                </span>
            </li>
        </ol>

        <div class="section-heading with-space-before">
            <span class="section-number">2.</span>
            <span class="section-title">Non-Disclosure of Confidential Information</span>
        </div>

        <ol class="alpha-list list-1-15 section-two-list">
            <li>
                I agree to keep the “confidential information” confidential and not publish or otherwise disclose such
                information to any other person.
            </li>
            <li>
                I agree that I will not use any Confidential Information for my own purposes or for purposes other than
                those of TESP that I have acquired in relation to the business of TESP, its partners, its affiliates, or either.
            </li>
            <li>
                I agree to observe, exercise, and execute extreme care in protecting the confidentiality of any
                Confidential Information as described above.
            </li>
            <li>
                I agree and acknowledge that the obligation to NOT disclose such confidential information to anyone
                and the obligation NOT to use such confidential information for my own purposes or for purposes other
                than those of TESP and the other obligations related to non-disclosure of confidential information shall
                continue in effect during my employment and even after or following the termination of my employment
                with TESP for whatever reason.
            </li>
            <li>
                I agree that upon request of TESP and in any event upon termination of my employment with TESP, all
                the confidential information obtained by me in whatever form or material, including but not limited to
                copies of such confidential information, shall be returned immediately to TESP.
            </li>
        </ol>

    </section>

    <!-- ================================================================ -->
    <!-- PAGE 2: SECTION 3, SECTION 4, ACKNOWLEDGMENT, SIGNATURES         -->
    <!-- ================================================================ -->
    <section class="document-page page-two">

        <header class="document-header">
            <div class="company-line">
                <?php if ($safeLogo !== ''): ?>
                    <img
                        src="<?php echo $safeLogo; ?>"
                        class="company-logo"
                        alt="TESP Logo">
                <?php endif; ?>

                <span class="company-name">TES PHILIPPINES, INC.</span>
            </div>

            <div class="project-name">
                METRO RAIL TRANSIT LINE 3 MAINTENANCE & REHABILITATION PROJECT
            </div>

            <div class="company-address">
                Meriton One Building, 1668 Quezon Avenue, Quezon City
            </div>
        </header>

        <div class="section-heading first-section">
            <span class="section-number">3.</span>
            <span class="section-title">Enforcement</span>
        </div>

        <p class="paragraph-1-0 enforcement-text">
            I agree that in case I violate or breach the terms contained in this confidentiality agreement, TESP shall,
            in addition to all available remedies under the law, have the absolute right to obtain relief by way of a
            temporary or permanent injunction to enforce the obligations of the contract, protect the confidentiality of
            Confidential Information, prevent further disclosure of Confidential Information, and protect the interests of TESP.
        </p>

        <div class="section-heading">
            <span class="section-number">4.</span>
            <span class="section-title">General</span>
        </div>

        <ol class="alpha-list general-list">
            <li>
                This agreement shall be governed by the laws of the Philippines. If any provision of this agreement is
                wholly or partially unenforceable for any reason, such unenforceable provision, or part thereof shall be
                deemed to be omitted from this agreement without in any way invalidating or impairing the other
                provisions of this agreement.
            </li>
            <li>
                This confidentiality agreement constitutes the entire agreement between the herein parties with respect
                to the protection by TESP of its proprietary rights and cancels and supersedes any prior understandings
                and agreements between the parties related thereto. There are no representations, warranties, forms,
                conditions, undertakings, or collateral agreements, express, implied, or statutory between the parties
                other than as expressly set forth in this agreement.
            </li>
            <li>
                The rights and obligations under this agreement shall survive the termination of my service to TESP and
                shall inure to the benefit of and shall be binding upon (i) my heirs and personal representatives and
                (ii) the successors and assigns of TESP.
            </li>
        </ol>

        <p class="agreement-confirmation">
            I HAVE READ THIS AGREEMENT, UNDERSTAND IT, AND AGREE TO ITS TERMS.
        </p>

        <div class="signature-section">
            <div class="signature-block employee-signature-block">
                <div class="signature-line"></div>

                <div class="employee-signature-name">
                    <?php echo agreementEscape($emp_name_formal); ?>
                </div>

                <div class="employee-signature-caption">
                    Name and Signature
                </div>

                <div class="signature-date">Date: ______________________</div>
            </div>

            <div class="signature-block company-signature-block">
                <div class="company-signature-title">For TES Philippines, Inc.</div>
                <div class="president-position">President</div>
                <div class="signature-line"></div>
                <div class="president-name">JUNJI FURUYA</div>
                <div class="signature-date">Date: ______________________</div>
            </div>
        </div>

    </section>

</body>

</html>