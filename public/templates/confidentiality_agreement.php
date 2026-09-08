<?php
// Ensure variables exist
if (!isset($emp)) {
    $emp = [];
}

// Fallback initialization for static analysis / Intelephense
$firstName  = $emp['first_name'] ?? '';
$middleName = $emp['middle_name'] ?? '';
$lastName   = $emp['last_name'] ?? '';

if (!isset($emp_name_formal)) {
    $mi = !empty($middleName) ? substr($middleName, 0, 1) . '.' : '';
    $emp_name_formal = strtoupper(trim($firstName . ' ' . $mi . ' ' . $lastName));
}
if (!isset($address)) {
    $address = strtoupper($emp['present_address'] ?? '');
}
if (!isset($position)) {
    $position = strtoupper($emp['job_title'] ?? '');
}

$year_now = date('Y');
?>

<style>
    /* Allow parent container & settings panel to drive margins */
    table.report-container {
        width: 100% !important;
        border-collapse: collapse;
    }

    thead.report-header {
        display: table-header-group;
    }

    tfoot.report-footer {
        display: table-footer-group;
    }

    .header-wrapper {
        width: 100% !important;
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

    /* Content Typography */
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

    .section-header {
        font-weight: bold;
        margin-top: 15px;
        margin-bottom: 5px;
    }

    ol.alpha-list {
        margin-top: 5px;
        margin-bottom: 15px;
        padding-left: 20px;
    }

    ol.alpha-list li {
        margin-bottom: 5px;
        text-align: justify;
    }

    /* Signatures */
    .sig-section {
        margin-top: 60px;
        page-break-inside: avoid;
    }

    .sig-block {
        margin-bottom: 40px;
    }

    .sig-line {
        border-top: 1px solid #000;
        width: 250px;
        margin-bottom: 5px;
    }
</style>

<table class="report-container">
    <thead class="report-header">
        <tr>
            <td>
                <div class="header-wrapper">
                    <table style="width: 100%; margin-bottom: 10px;">
                        <tr>
                            <td style="width: 130px; text-align: right; vertical-align: middle; padding-right: 15px;">
                                <?php $safe_logo = !empty($global_logo_src) ? htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8') : ''; ?>
                                <img src="<?php echo $safe_logo; ?>" width="80" height="80" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;" alt="TESP Logo">
                            </td>

                            <td style="text-align: center; vertical-align: middle;">
                                <div style="font-weight: bold; font-size: 15pt !important; line-height: 1.2; white-space: nowrap;">TES PHILIPPINES, INC.</div>
                                <div style="font-weight: bold; font-size: 11pt !important; line-height: 1.2; white-space: nowrap;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                                <div style="font-size: 11pt !important; line-height: 1.2;">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                                <div style="font-size: 11pt !important; line-height: 1.2;">Telephone Number: 8929-5347 local 4404</div>
                            </td>
                            <td style="width: 70px;"></td> <!-- Spacer for shifting text right -->
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                <div class="doc-title">CONFIDENTIALITY AGREEMENT</div>

                <p class="justify">
                    I, <span class="bold uppercase"><?php echo htmlspecialchars($emp_name_formal); ?></span>, of legal age, residing at <span class="bold uppercase"><?php echo htmlspecialchars($address); ?></span>, recognize that TES Philippines, Inc. (“TESP”), my current employer, is engaged in a highly competitive industry, and that it is important for TESP to protect its trade secrets, confidential information and other proprietary information and related rights acquired through TESP’s expenditure of time, effort and money.
                </p>

                <p class="justify">
                    Therefore, because in my position, I keep, receive and/or contribute to TESP’s Confidential Information, and in consideration of the remuneration I receive from TESP, I agree to be bound by the following terms and conditions which are so described below.
                </p>

                <div class="section-header">1. Definition of Confidential Information</div>
                <p class="justify">In this agreement, “Confidential Information” includes confidential and proprietary information and various trade secrets including but not limited to:</p>
                <ol class="alpha-list">
                    <li>scientific, engineering and technical know-how,</li>
                    <li>processes and systems</li>
                    <li>computer software and related documentation owned by TESP or its partners,</li>
                    <li>business strategies,</li>
                    <li>customer requirements,</li>
                    <li>supplier information</li>
                    <li>accounting documents and records,</li>
                    <li>employees’ compensation and other employee records</li>
                    <li>methods of doing business,</li>
                    <li>the financial affairs of TESP,</li>
                    <li>manuals, procedures and practices</li>
                    <li>contracts and agreements</li>
                    <li>bank records</li>
                    <li>property records</li>
                    <li>and any other confidential, business, proprietary and/or trade secrets and/or other information in whatever form which belongs to or has been made known to the receiver of such information by TESP or its partners.</li>
                    <li>Related regulation “Data Privacy Act of 2012” & ”Republic Act No. 10173”, which is prescribed for protecting personal information.<br>(As specified in Employee Personal Data Consent Statement)</li>
                </ol>

                <div class="section-header">2. Non-Disclosure of Confidential Information</div>
                <ol class="alpha-list">
                    <li>I agree to keep the “confidential information” confidential and not publish or otherwise disclose such information to any other person.</li>
                    <li>I agree that I will not use any Confidential Information for my own purposes or for purposes other than those of TESP that I have acquired in relation to the business of TESP, its partners its affiliates or either.</li>
                    <li>I agree to observe, exercise and execute extreme care in protecting the confidentiality of any Confidential Information as described above.</li>
                    <li>I agree and acknowledge that the obligation to NOT disclose such confidential information to anyone and the obligation NOT to use such confidential information for my own or purposes other than those of TESP and the other obligations related to non-disclosure of confidential information shall continue in effect during my employment and even after or following the termination of my employment with TESP for whatever reason.</li>
                    <li>I agree that upon request of TESP and in any event upon termination of my employment with TESP, all the confidential information obtained by me in whatever form or material including but not limited to copies of such confidential information shall be returned immediately to TESP.</li>
                </ol>

                <div class="section-header">3. Enforcement</div>
                <p class="justify">
                    I agree that in case I violate or breach the terms contained in this confidentiality agreement, TESP shall, in addition to any and all available remedies under the law, have absolute right to obtain relief by way of a temporary or permanent injunction to enforce the obligations contract, protect the confidentiality of confidential information, prevent further disclosure of confidential information and protect the interests of the TESP.
                </p>

                <div class="section-header">4. General</div>
                <ol class="alpha-list">
                    <li>This agreement shall be governed by the laws of the Philippines. If any provision of this agreement is wholly or partially unenforceable for any reason, such unenforceable provision or part thereof shall be deemed to be omitted from this agreement without in any way invalidating or impairing the other provisions of this agreement.</li>
                    <li>This confidentiality agreement constitutes the entire agreement between the herein parties with respect to the protection by TESP of its proprietary rights and cancels and supersedes any prior understandings and agreements between the parties related thereto. There are no representations, warranties, forms, conditions, undertakings or collateral agreements, express, implied, or statutory between the parties other than as expressly set forth in this agreement.</li>
                    <li>The rights and obligations under this agreement shall survive the termination of my service to TESP and shall inure to the benefit of and shall be binding upon (i) my heirs and personal representatives and (ii) the successors and assigns of TESP.</li>
                </ol>

                <p class="center bold" style="margin-top: 30px; margin-bottom: 30px;">
                    I HAVE READ THIS AGREEMENT, UNDERSTAND IT, AND AGREE TO ITS TERMS.
                </p>

                <div class="sig-section">
                    <div class="sig-block">
                        <div class="sig-line"></div>
                        <div class="bold uppercase"><?php echo htmlspecialchars($emp_name_formal); ?></div>
                        <div>Name and Signature</div>
                        <div style="margin-top: 5px;">Date: ______________________</div>
                    </div>

                    <div class="sig-block">
                        <div class="bold">For TES Philippines, Inc.</div>
                        <div>President</div>

                        <div class="sig-line" style="margin-top: 40px;"></div>
                        <div class="bold">JUNJI FURUYA</div>
                        <div style="margin-top: 5px;">Date: ______________________</div>
                    </div>
                </div>
            </td>
        </tr>
    </tbody>
</table>