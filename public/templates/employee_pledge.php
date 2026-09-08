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
?>

<style>
    /* Professional Document Container */
    .pledge-container {
        width: 100% !important;
        border-collapse: collapse;
    }

    /* Centered Header Table */
    .pledge-header-table {
        width: 100%;
        margin-bottom: 20px;
        border-collapse: collapse;
    }

    .pledge-title-box {
        text-align: center;
    }

    .pledge-title {
        font-weight: bold;
        font-size: 14pt;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .pledge-subtitle {
        font-size: 10pt;
        font-weight: bold;
        margin-top: 3px;
    }

    /* Professional Body & Typography Spacing */
    .pledge-body p {
        text-align: justify;
        text-justify: inter-word;
        font-size: 9.5pt;
        line-height: 1.45;
        margin-bottom: 10px;
    }

    .pledge-body ul {
        margin-top: 6px;
        margin-bottom: 12px;
        padding-left: 20px;
    }

    .pledge-body li {
        text-align: justify;
        text-justify: inter-word;
        font-size: 9pt;
        line-height: 1.4;
        margin-bottom: 5px;
    }

    .pledge-section-title {
        font-weight: bold;
        margin-top: 12px;
        margin-bottom: 6px;
    }

    /* Clean Signature Block */
    .pledge-sig-wrapper {
        margin-top: 35px;
        page-break-inside: avoid;
    }

    .pledge-sig-block {
        width: 260px;
        text-align: center;
    }

    .pledge-sig-line {
        border-top: 1px solid #000;
        margin-bottom: 4px;
    }
</style>

<table class="pledge-container">
    <tbody>
        <tr>
            <td class="pledge-body">
                <!-- Centered Header Section -->
                <table class="pledge-header-table">
                    <tr>
                        <td style="width: 100px; text-align: right; vertical-align: middle; padding-right: 15px;">
                            <?php $safe_logo = !empty($global_logo_src) ? htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8') : 'assets/images/tesp-logo-1.png'; ?>
                            <img src="<?php echo $safe_logo; ?>" width="75" height="75" style="width: 75px; height: 75px; object-fit: cover;" alt="TESP Logo">
                        </td>
                        <td style="vertical-align: middle; text-align: center;">
                            <div class="pledge-title-box">
                                <div class="pledge-title">EMPLOYEE’S SAFETY PLEDGE</div>
                                <div class="pledge-subtitle">10 Life Saving Rules and Point & Call Policy</div>
                            </div>
                        </td>
                        <td style="width: 100px;"></td> <!-- Balance spacer to keep text centered -->
                    </tr>
                </table>

                <!-- Opening Statement -->
                <p>Today, I <strong><?php echo htmlspecialchars($fullName); ?></strong>, do hereby pledge that I am committed to doing my part to instill a safety culture and promote the health and safety of all employees. I believe that safety and health are core values of our organization. I pledge to actively practice the following:</p>

                <!-- Lifesaving Rules List -->
                <div class="pledge-section-title">10 Lifesaving Rules (LSR):</div>
                <ul>
                    <li><strong>1.1 Buddy System:</strong> Always work in pair. Lone working is not allowed. All work should be executed in the presence of a Person In Charge (PIC).</li>
                    <li><strong>1.2 Competency:</strong> No Job to be undertaken unless you have been trained and assessed as competent for that task.</li>
                    <li><strong>1.3 Fall Protection:</strong> Always use fall protection device such as Full body harness unless other engineering controls are in place.</li>
                    <li><strong>1.4 Lock Out Tag Out:</strong> De-energize the electrical equipment, apply right LOTO devices before doing maintenance activities.</li>
                    <li><strong>1.5 Exclusion Zone:</strong> Never enter the exclusion zone in depot area without authorization or unless directed by the Yardmaster/PIC.</li>
                    <li><strong>1.6 Drug and Alcohol:</strong> Zero tolerance to drug and alcohol. Never work or drive under the influence.</li>
                    <li><strong>1.7 Permit to Work:</strong> All safety critical activities should be executed with appropriate Permit To Work authorization.</li>
                    <li><strong>1.8 Earthing Testing:</strong> Test all de-energized equipment, system, power rails, before touching them.</li>
                    <li><strong>1.9 Track Access:</strong> Never cross the tracks. Always use the designated safe passage or walkway.</li>
                    <li><strong>1.10 Equipment Guard:</strong> Do not remove guards or work on or near unprotected rotating equipment.</li>
                </ul>

                <!-- Policy Section -->
                <p><strong>11. Point and Call Policy:</strong> This policy is intended to encourage all personnel to imbibe a cautious work practice by closely following the rules on Point and Call policy every time, until it becomes a safe working habit.</p>

                <p>I therefore understand that violation of any of the Lifesaving rules will have corresponding disciplinary sanctions which may include dismissal from work.</p>

                <p>This is my pledge and commitment to helping ensure the safety of myself and my co-workers:</p>

                <!-- Signature Section -->
                <div class="pledge-sig-wrapper">
                    <div class="pledge-sig-block">
                        <div class="pledge-sig-line"></div>
                        <strong style="font-size: 9.5pt; text-transform: uppercase;"><?php echo htmlspecialchars($fullName); ?></strong><br>
                        <span style="font-size: 8.5pt;">Signature over Printed Name</span>
                    </div>
                </div>
            </td>
        </tr>
    </tbody>
</table>