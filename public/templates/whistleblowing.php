<?php
// Fallback initialization to satisfy static analysis / Intelephense
if (!isset($emp)) {
    $emp = [
        'first_name' => '',
        'last_name'  => ''
    ];
}

$firstName = strtoupper($emp['first_name'] ?? '');
$lastName  = strtoupper($emp['last_name'] ?? '');
$fullName  = trim($firstName . ' ' . $lastName);
if (empty($fullName)) {
    $fullName = 'SAMPLE SAMPLE';
}

$whistleblower_url = 'https://www.dial-soudan.jp/et/mhi-sarch-rinri_en/';
?>

<style>
    /* Document Container Setup */
    .wb-container {
        width: 100% !important;
        border-collapse: collapse;
    }

    /* Centered Header Title */
    .wb-header-table {
        width: 100%;
        margin-bottom: 20px;
        border-collapse: collapse;
    }

    .wb-title-box {
        text-align: center;
    }

    .wb-title {
        font-weight: bold;
        font-size: 13.5pt;
        line-height: 1.3;
        font-family: "Times New Roman", Times, serif;
    }

    .wb-subtitle {
        font-size: 11pt;
        font-weight: normal;
    }

    /* Executive Grade Typography */
    .wb-body {
        text-align: justify;
        text-justify: inter-word;
        font-family: "Times New Roman", Times, serif;
        color: #000;
    }

    .wb-body p {
        text-align: justify;
        text-justify: inter-word;
        font-size: 9.5pt;
        line-height: 1.42;
        margin-bottom: 10px;
    }

    .wb-body ol.main-list {
        margin-top: 8px;
        margin-bottom: 10px;
        padding-left: 22px;
    }

    .wb-body ol.main-list>li {
        text-align: justify;
        text-justify: inter-word;
        font-size: 9.5pt;
        line-height: 1.42;
        margin-bottom: 8px;
    }

    .wb-body ol.sub-list {
        margin-top: 5px;
        margin-bottom: 5px;
        padding-left: 20px;
    }

    .wb-body ol.sub-list>li {
        font-size: 9.5pt;
        line-height: 1.38;
        margin-bottom: 4px;
    }

    .contact-box {
        margin-top: 5px;
        margin-bottom: 5px;
        padding-left: 18px;
        line-height: 1.4;
    }

    /* Bright Blue Hyperlink Styling */
    .wb-link {
        color: #0066CC !important;
        text-decoration: underline !important;
        word-break: break-all;
    }

    /* Professional Signature Section */
    .wb-sig-wrapper {
        margin-top: 30px;
        page-break-inside: avoid;
    }

    .wb-sig-block {
        width: 290px;
        text-align: center;
    }

    .wb-sig-space {
        height: 45px;
        /* Creates explicit whitespace for wet/digital signatures */
    }

    .wb-sig-line {
        border-top: 1px solid #000;
        margin-bottom: 6px;
    }
</style>

<table class="wb-container">
    <tbody>
        <tr>
            <td class="wb-body">
                <!-- Header -->
                <table class="wb-header-table">
                    <tr>
                        <td style="vertical-align: middle; text-align: center;">
                            <div class="wb-title-box">
                                <div class="wb-title">
                                    Mitsubishi Heavy Industries, Ltd. (“MHI”) Consent Form<br>
                                    <span class="wb-subtitle">Global Whistle-Blowing Program</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- Main Provisions -->
                <ol class="main-list">
                    <li>
                        MHI is currently implementing its Global Whistle Blowing Program as provided in its Notification: Establishment of a whistle-blowing helpline system (the “Helpline System”), which I have read and understood. I understand that the Helpline System allows officers, directors, and employees of MHI’s group of companies in the Southeast Asia Region (“MHI Asia Companies”), which includes TES Philippines, Inc. (“MHI’s group companies in the Philippines”) to make reports on other officers, directors, and employees of MHI Asia Companies alleged to have committed the acts and matters provided in the Helpline System.
                    </li>
                    <li>
                        I hereby agree and consent for MHI to collect, use, and process my data as necessary and provided under the Helpline System either as the person (a) making a report against an alleged wrongdoer, or (b) alleged to have committed an act or matter subject of a report of another person.
                    </li>
                    <li>
                        I likewise agree and consent to MHI’s disclosure of my data collected to be shared or transferred (a) within the MHI Asia Companies including MHI’s Group of companies in the Philippines for purposes of completing a report or investigation according to the Helpline System; (b) to government agencies and external auditors whether within or outside of the Philippines to comply with Philippine and foreign legal requirements; (c) to third parties who will participate in the investigation or conduct a review including, but not limited to, attorneys, accountants, and consultants; and (d) to third parties including, but not limited to, Dial Service Co., Ltd. and similar third-party service providers to complete the procedures and processes under the Helpline System as well as for storage, information technology, and information security purposes.
                    </li>
                    <li>
                        I note MHI’s applicable policies concerning my data and understand:
                        <ol type="a" class="sub-list">
                            <li>the retention, storage, and destruction policies of MHI concerning my data.</li>
                            <li>my rights, to the extent recognized by Republic Act No.10173, otherwise known as the Data Privacy Act of 2012, its Implementing Rules and Regulations, and other applicable laws, which shall be respected by MHI; and</li>
                            <li>that MHI shall adopt necessary physical, organizational, and technical security measures required under the relevant laws, rules, and regulations.</li>
                        </ol>
                    </li>
                    <li>
                        I understand that for any questions or complaints regarding MHI’s handling of my data or MHI’s compliance with the DPA, I may contact its data privacy officer through:
                        <div class="contact-box">
                            Email: honesto.domingo.f2@mhi.com<br>
                            [URL] <a href="<?php echo htmlspecialchars($whistleblower_url); ?>" target="_blank" class="wb-link"><?php echo htmlspecialchars($whistleblower_url); ?></a>
                        </div>
                    </li>
                    <li>
                        I represent and warrant that all the personal data provided by me to MHI shall be true and correct, and that I shall update or correct any personal data I have provided when necessary.
                    </li>
                </ol>

                <p style="margin-top: 12px; margin-bottom: 5px;">I have read and understood all the above provisions.</p>

                <!-- Signature Section -->
                <div class="wb-sig-wrapper">
                    <div class="wb-sig-block">
                        <div class="wb-sig-space"></div>
                        <div class="wb-sig-line"></div>
                        <strong style="font-size: 9.5pt; text-transform: uppercase;"><?php echo htmlspecialchars($fullName); ?></strong><br>
                        <span style="font-size: 8.5pt;">Signature above Printed Name and Date</span>
                    </div>
                </div>
            </td>
        </tr>
    </tbody>
</table>