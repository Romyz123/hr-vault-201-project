<?php
// Fallback initialization to satisfy VS Code Intelephense static analysis
if (!isset($emp)) {
    $emp = [
        'first_name' => '',
        'last_name'  => ''
    ];
}

$firstName = strtoupper($emp['first_name'] ?? '');
$lastName  = strtoupper($emp['last_name'] ?? '');
$fullName  = trim($firstName . ' ' . $lastName);
?>
<style>
    /* Allow bulk_contract.php / generate_document.php margins and font settings to take effect */
    table.report-container {
        width: 100% !important;
        border-collapse: collapse;
    }

    .header-wrapper {
        text-align: center;
        margin-bottom: 10px;
        padding-bottom: 5px;
    }

    .title {
        font-weight: bold;
        font-size: 1.3em;
        text-decoration: underline;
        margin-top: 5px;
    }

    /* Paragraph & list styling - [FIX] Removed !important so settings panel slider works */
    p,
    li {
        text-align: justify;
        text-justify: inter-word;
        font-family: "Times New Roman", serif;
        margin-bottom: 4px;
    }

    /* List hierarchy styling */
    ol.level-1 {
        list-style-type: decimal;
        padding-left: 18px;
        margin-top: 5px;
        margin-bottom: 5px;
    }

    ol.level-2 {
        list-style-type: lower-alpha;
        padding-left: 18px;
        margin-top: 3px;
        margin-bottom: 3px;
    }

    ol.level-3 {
        list-style-type: lower-roman;
        padding-left: 18px;
        margin-top: 3px;
        margin-bottom: 3px;
    }

    /* Lock signature block sizing to keep it proportional and on 1 page when possible */
    .signature-section {
        margin-top: 15px !important;
        border-top: 1px solid #000 !important;
        width: 250px !important;
        max-width: 250px !important;
        padding-top: 4px !important;
        page-break-inside: avoid !important;
    }

    .signature-section strong {
        text-transform: uppercase !important;
    }
</style>

<table class="report-container">
    <tbody>
        <tr>
            <td>
                <div class="header-wrapper">
                    <div class="title">Employee Personal Data Consent Statement</div>
                </div>

                <ol class="level-1">
                    <li>Without limiting the rights of <strong>TES Philippines, Inc.</strong> (“Company") under and subject to applicable law, by signing this Employee Personal Data Consent Statement ("Consent Statement") , I consent to the Company, any Affiliate and any third party acting on its or their behalf:
                        <ol class="level-2">
                            <li>To Process my Personal Data for any purpose directly or indirectly connected with:
                                <ol class="level-3">
                                    <li>managing or terminating my employment including but not limited to background and reference checks; job suitability assessments ; assessment of medical conditions; visa, work pass and travel document applications; management and human resource operations ; staff directory information; compensation decisions; payroll, benefits and entitlements; insurance coverage and claims; staff welfare; professional and/or club memberships; relocation and accommodation arrangements ; administering reimbursement of expenses; tax returns and/or related filings with governmental authorities; manpower planning, secondments and transfers; training programmes; performance appraisals, performance management, promotion and career development activities; corporate team building programmes and/or activities; communicating with family members in relation to death, illness, injury or emergency in connection with work; monitoring compliance with the Company's internal rules and policies; communications with and/or response to government agencies; investigatory or disciplinary matters; employment decisions including end of employment, end of employment procedures, exit interviews or surveys, post-termination administration and procedures; providing references to third parties; consideration for any other or future job position(s) in the Company or any Affiliate; and contractual or statutory obligations;</li>
                                    <li>the administration , management and/or operation of the business of the Company and/or its Affiliates including but not limited to company events; facility, security, health and safety management; internal technical and operational support; corporate security measures; internal auditing, compliance and risk management; company newsletters; marketing initiatives of the Company and any Affiliates via various media including but not limited to newspapers, magazines, Company website and social media platforms, such as pitches, proposals, contributions to publications, marketing, ranking tables, biographies; providing details to customers or other business associates or third parties for contact and/or access and security purposes; statistical information for internal and external purposes; business continuity management and disaster recovery preparedness;</li>
                                    <li>any business asset transaction including but not limited to facilitating the reorganisation or sale of all or part of the Company 's business and/or assets; and/or</li>
                                    <li>compliance with applicable laws and regulations and/or legal proceedings.</li>
                                </ol>
                                <p style="margin-top: 3px; margin-bottom: 3px;">(collectively the "Purposes"); and</p>
                            </li>
                            <li>To process including to transfer my Personal Data outside the Philippines for such Purposes, during and for a reasonable period after termination of my employment in accordance with Company policy and to the extent permitted by law.</li>
                        </ol>
                    </li>

                    <li>I agree that Processing my Personal Data is necessary and is a condition of my employment and the Company is unable to proceed with and/or continue my employment if I do not agree to provide my Personal Data or to Processing my Personal Data as set out in this Consent Statement.</li>

                    <li>I agree to provide to the Company , any Affiliate and any third party acting on its or their behalf Personal Data relating to third parties including but not limited to my family members ("Third Parties") to be Processed for any of the Purposes as and when required by the Company. I confirm that I have obtained and/or will have obtained the consent of such Third Parties to the Processing of their Personal Data on or before providing their Personal Data as required.</li>

                    <li>I understand that I may request access to and/or correction of my Personal Data in accordance with and subject to law and Company policy by contacting the Data Protection Officer of the Company.</li>

                    <li>I will inform and update the Company any changes to my Personal Data as soon as practicable and in any case not later than two (2) weeks following such changes.</li>

                    <li>For the purposes of this Consent Statement:
                        <p style="margin-top: 3px; margin-bottom: 3px;"><strong>"Affiliate"</strong> means corporation, entity or other organisation which:</p>
                        <ol class="level-3">
                            <li>is directly or indirectly controlled by the Company;</li>
                            <li>directly or indirectly controls the Company; or</li>
                            <li>is directly or indirectly controlled by a third party who also directly or indirectly controls the Company,</li>
                        </ol>
                        <p style="margin-top: 3px; margin-bottom: 3px;">and where the term "control" when used with respect to any organisation means the ownership of fifteen per cent (15%) of issued shares or more.</p>

                        <p style="margin-top: 3px; margin-bottom: 3px;"><strong>“Business asset transaction"</strong> has the meaning as defined under the Data Privacy Act of 2012” & ”Republic Act No. 10173.</p>

                        <p style="margin-top: 3px; margin-bottom: 3px;"><strong>"Personal Data"</strong> refers to data, whether true or not, about an individual who can be identified from that data, or from that data in combination with other information to which the organisation may have access.</p>

                        <p style="margin-top: 3px; margin-bottom: 3px;"><strong>"Processing”</strong> includes recording, holding, organisation, adaptation or alteration, retrieval, combination, transmission, erasure, obtaining, copying, amending, adding, deleting, extracting, storing, disclosing, Transferring or destruction of Personal Data.</p>

                        <p style="margin-top: 3px; margin-bottom: 3px;"><strong>"Transferring"</strong> refers to transferring Personal Data outside of the Philippines, whether by electronic (e.g. cloud storage, email, etc.) or physical means (e.g. shipping physical files).</p>
                    </li>
                </ol>

                <div style="margin-left: 18px; margin-top: 15px;">
                    <div class="signature-section">
                        <strong><?php echo htmlspecialchars($fullName); ?></strong><br>
                        <span style="font-size: 0.9em; font-weight: normal;">Name & Signature</span>
                    </div>
                </div>
            </td>
        </tr>
    </tbody>
</table>