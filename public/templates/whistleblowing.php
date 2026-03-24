<?php
// public/templates/whistleblowing.php
if (!isset($emp)) {
    die("Access Denied");
}
?>
<!DOCTYPE html>
<html>

<head>
    <style>
        @page {
            size: A4;
            margin: 0.5in;
        }

        body {
            text-align: justify;
            font-family: "Times New Roman", serif;
            font-size: 11pt;
            line-height: 1.5;
            padding-left: 0in;
            margin: 0;
            padding-right: 1in;
        }

        .header {
            text-align: center;
            font-weight: bold;
            line-height: 1.2;
        }

        p {
            text-align: justify;
            margin-bottom: 0px;
        }

        ul.rules-list {
            margin: 10px 0;
            padding-left: 0px;
        }

        ul.rules-list li {
            margin-bottom: 0px;
            text-align: justify;
        }

        .header-table {
            margin: 0 auto 20px auto;
            border-collapse: collapse;
        }
    </style>
</head>

<body>
    <?php $safe_global_logo_src = !empty($global_logo_src) ? htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8') : 'assets/images/tesp-logo.png'; ?>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="width: 80px; vertical-align: middle;">
                <img src="<?php echo $safe_global_logo_src; ?>" style="width: 70px; height: auto; display: block;" alt="TESP Logo">
            </td>
            <td style="vertical-align: middle; text-align: center;">
                <div class="header" style="font-size: 13pt;">
                    Mitsubishi Heavy Industries, Ltd. (“MHI”) Consent Form<br>
                    <span style="font-size: 11pt; font-weight: normal;">Global Whistle-Blowing Program</span>
                </div>
            </td>
        </tr>
    </table>

    <ol>
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
            <ol type="a" style="margin-top: 5px;">
                <li>the retention, storage, and destruction policies of MHI concerning my data.</li>
                <li>my rights, to the extent recognized by Republic Act No.10173, otherwise known as the Data Privacy Act of 2012, its Implementing Rules and Regulations, and other applicable laws, which shall be respected by MHI; and</li>
                <li>that MHI shall adopt necessary physical, organizational, and technical security measures required under the relevant laws, rules, and regulations.</li>
            </ol>
        </li>
        <li>
            I understand that for any questions or complaints regarding MHI’s handling of my data or MHI’s compliance with the DPA, I may contact its data privacy officer through:<br><br>
            Email: honesto.domingo.f2@mhi.com<br>
            [URL] https://www.dial-soudan.ip/et/mhi-sarch-rinri_en/
        </li>
        <li>
            I represent and warrant that all the personal data provided by me to MHI shall be true and correct, and that I shall update or correct any personal data I have provided when necessary.
        </li>
    </ol>

    <p>I have read and understood all the above provisions.</p>

    <br><br>
    <div style="border-top: 1px solid black; width: 300px; text-align: center; padding-top: 5px;">
        <strong><?php echo strtoupper($emp['first_name'] . ' ' . $emp['last_name']); ?></strong><br>
        Signature above Printed Name and Date
    </div>
</body>

</html>