<?php
// [FILE] templates/whistleblowing_consent_form_ORIGINAL_MATCH_COMPLETE.php

if (!isset($emp) || !is_array($emp)) {
    $emp = [];
}

if (!function_exists('wbEscape')) {
    function wbEscape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

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

$employeeFullName = strtoupper(
    $systemFullName !== '' ? $systemFullName : 'SAMPLE SAMPLE'
);

$dpoEmail = 'honesto.domingo.f2@mhi.com';
$whistleblowerUrl = 'https://www.dial-soudan.jp/et/mhi-sarch-rinri_en/';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MHI Global Whistle-Blowing Program Consent Form</title>

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
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
        }

        .wb-page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
            padding: 18mm 25.4mm 20mm 25.4mm;
            overflow: hidden;
            background: #fff;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.2;
        }

        .wb-header {
            margin: 0 0 16pt 17px;
            text-align: left;
        }

        .wb-title,
        .wb-subtitle {
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            font-weight: 700;
            line-height: 1.05;
        }

        .wb-subtitle {
            margin-top: 1pt;
        }

        .wb-provision {
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr);
            column-gap: 8px;
            margin: 0 0 14pt;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.2;
        }

        .wb-number {
            text-align: right;
        }

        .wb-text {
            text-align: justify;
            text-align-last: left;
            text-justify: inter-word;
        }

        .wb-number,
        .wb-text,
        .wb-sublist,
        .wb-subclause,
        .wb-submark {
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.2;
        }

        .wb-sublist {
            margin: 3pt 0 0;
        }

        .wb-subclause {
            display: grid;
            grid-template-columns: 24px minmax(0, 1fr);
            column-gap: 4px;
            margin-bottom: 2pt;
        }

        .wb-submark {
            text-align: right;
        }

        .wb-contact {
            margin: 12pt 0 0;
            font-size: 9.5pt;
            line-height: 1.2;
        }

        .wb-contact-row {
            margin-bottom: 13pt;
            font-size: 9.5pt;
            line-height: 1.2;
        }

        .wb-link {
            color: #0066cc;
            text-decoration: underline;
        }

        .wb-provision.provision-six {
            margin-top: 28pt;
        }

        .wb-confirmation {
            margin: 13pt 0 0 30px;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.2;
        }

        .wb-signature {
            width: 380px;
            margin: 48pt 0 0 30px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .wb-signature-line {
            width: 380px;
            border-top: 0.75pt solid #000;
            margin-bottom: 5pt;
        }

        .wb-employee-name {
            display: block;
            margin: 0 0 2pt;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9.5pt;
            font-weight: 700;
            line-height: 1.05;
            text-transform: uppercase;
        }

        .wb-signature-caption {
            display: block;
            margin: 0;
            font-family: Calibri, "Segoe UI", Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.05;
        }

        /* FINAL REQUESTED SPACING */
        /* Provisions 1, 2, and 3 use 1.5 line spacing. */
        .wb-spacing-1-5,
        .wb-spacing-1-5 .wb-number,
        .wb-spacing-1-5 .wb-text {
            line-height: 1.5 !important;
        }

        /* Keep the acknowledgment the same size and aligned with clause text. */
        .wb-confirmation {
            margin-top: 13pt !important;
            margin-right: 0 !important;
            margin-bottom: 0 !important;
            margin-left: 30px !important;
            padding: 0 !important;
            font-family: Calibri, "Segoe UI", Arial, sans-serif !important;
            font-size: 9.5pt !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
            text-align: left !important;
        }

        /* Preserve original emphasis inside legal paragraphs. */
        .wb-text strong,
        .wb-text strong em {
            font-weight: 700 !important;
        }

        .wb-text em,
        .wb-text strong em {
            font-style: italic !important;
        }

        @media screen {
            body {
                background: #ddd;
            }

            .wb-page {
                margin: 12px auto;
                box-shadow: 0 0 5px rgba(0, 0, 0, 0.25);
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

            .wb-page {
                margin: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body>
    <section class="wb-page">
        <header class="wb-header">
            <div class="wb-title">Mitsubishi Heavy Industries, Ltd. (“MHI”) Consent Form</div>
            <div class="wb-subtitle">Global Whistle-Blowing Program</div>
        </header>

        <div class="wb-provision wb-spacing-1-5">
            <div class="wb-number">1.</div>
            <div class="wb-text">
                MHI is currently implementing its Global Whistle Blowing Program as provided in its Notification: <em>Establishment of a whistle-blowing helpline system</em> (the <strong><em>“Helpline System”</em></strong>), which I have read and understood. I understand that the Helpline System allows officers, directors, and employees of MHI’s group of companies in the Southeast Asia Region (<strong><em>“MHI Asia Companies”</em></strong>), which includes TES Philippines, Inc. (<strong><em>“MHI’s group companies in the Philippines”</em></strong>) to make reports on other officers, directors, and employees of MHI Asia Companies alleged to have committed the acts and matters provided in the Helpline System.
            </div>
        </div>

        <div class="wb-provision wb-spacing-1-5">
            <div class="wb-number">2.</div>
            <div class="wb-text">
                I hereby agree and consent for MHI to collect, use, and process my data as necessary and provided under the Helpline System either as the person (a) making a report against an alleged wrongdoer, or (b) alleged to have committed an act or matter subject of a report of another person.
            </div>
        </div>

        <div class="wb-provision wb-spacing-1-5">
            <div class="wb-number">3.</div>
            <div class="wb-text">
                I likewise agree and consent to MHI’s disclosure of my data collected to be shared or transferred (a) within the MHI Asia Companies including MHI’s Group of companies in the Philippines for purposes of completing a report or investigation according to the Helpline System; (b) to government agencies and external auditors whether within or outside of the Philippines to comply with Philippine and foreign legal requirements; (c) to third parties who will participate in the investigation or conduct a review including, but not limited to, attorneys, accountants, and consultants; and (d) to third parties including, but not limited to, Dial Service Co., Ltd. and similar third-party service providers to complete the procedures and processes under the Helpline System as well as for storage, information technology, and information security purposes.
            </div>
        </div>

        <div class="wb-provision">
            <div class="wb-number">4.</div>
            <div class="wb-text">
                I note MHI’s applicable policies concerning my data and understand:

                <div class="wb-sublist">
                    <div class="wb-subclause">
                        <div class="wb-submark">(a)</div>
                        <div>the retention, storage, and destruction policies of MHI concerning my data.</div>
                    </div>

                    <div class="wb-subclause">
                        <div class="wb-submark">(b)</div>
                        <div>my rights, to the extent recognized by Republic Act No. 10173, otherwise known as the Data Privacy Act of 2012, its Implementing Rules and Regulations, and other applicable laws, which shall be respected by MHI; and</div>
                    </div>

                    <div class="wb-subclause">
                        <div class="wb-submark">(c)</div>
                        <div>that MHI shall adopt necessary physical, organizational, and technical security measures required under the relevant laws, rules, and regulations.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wb-provision">
            <div class="wb-number">5.</div>
            <div class="wb-text">
                I understand that for any questions or complaints regarding MHI’s handling of my data or MHI’s compliance with the DPA, I may contact its data privacy officer through:

                <div class="wb-contact">
                    <div class="wb-contact-row">
                        Email:
                        <a class="wb-link" href="mailto:<?php echo wbEscape($dpoEmail); ?>"><?php echo wbEscape($dpoEmail); ?></a>
                    </div>

                    <div class="wb-contact-row">
                        [URL]
                        <a class="wb-link" href="<?php echo wbEscape($whistleblowerUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo wbEscape($whistleblowerUrl); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="wb-provision provision-six">
            <div class="wb-number">6.</div>
            <div class="wb-text">
                I represent and warrant that all the personal data provided by me to MHI shall be true and correct, and that I shall update or correct any personal data I have provided when necessary.
            </div>
        </div>

        <p class="wb-confirmation">I have read and understood all the above provisions.</p>

        <div class="wb-signature">
            <div class="wb-signature-line"></div>
            <div class="wb-employee-name"><?php echo wbEscape($employeeFullName); ?></div>
            <div class="wb-signature-caption">Signature above Printed Name and Date</div>
        </div>
    </section>
</body>

</html>