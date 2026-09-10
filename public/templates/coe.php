<?php
// Fallback initialization for static analysis / Intelephense
if (!isset($emp)) {
    $emp = [];
}

// 1. EXTRACT EMPLOYEE DATA
$firstName  = $emp['first_name'] ?? '';
$middleName = $emp['middle_name'] ?? '';
$lastName   = $emp['last_name'] ?? '';
$gender     = strtolower($emp['gender'] ?? 'male');

// 2. SALUTATION CHOICE (Mr. / Ms. / Mrs.)
$defaultSalutation = ($gender === 'female') ? 'Ms.' : 'Mr.';
$salutation = $_REQUEST['salutation'] ?? $defaultSalutation;

// Pronouns based on chosen salutation
$isFemale = in_array($salutation, ['Ms.', 'Mrs.']);
$pronounSubject    = $isFemale ? 'She' : 'He';
$pronounPossessive = $isFemale ? 'her' : 'his';
$pronounObjective  = $isFemale ? 'her' : 'him';

// Name Formatting (e.g., Mr. Darwin E. Cumar)
$mi = !empty($middleName) ? substr($middleName, 0, 1) . '.' : '';
$formalName = trim($salutation . ' ' . $firstName . ' ' . ($mi ? $mi . ' ' : '') . $lastName);

// 3. POSITION, SECTION & DEPARTMENT
$jobTitle = $emp['job_title'] ?? 'Staff';
$section  = !empty($emp['section']) ? $emp['section'] : '';
$dept     = !empty($emp['dept']) ? $emp['dept'] : '';

// Build position string: "position of [Job Title] of [Section] - [Department]"
$positionFull = $jobTitle;
if (!empty($section) && !empty($dept)) {
    $positionFull .= " of " . $section . " - " . $dept;
} elseif (!empty($dept)) {
    $positionFull .= " - " . $dept;
}

// 4. EMPLOYMENT PERIOD & END DATE HANDLING
$startDate = !empty($_REQUEST['start_date'])
    ? date('F d, Y', strtotime($_REQUEST['start_date']))
    : (!empty($emp['hire_date']) ? date('F d, Y', strtotime($emp['hire_date'])) : date('F d, Y'));

// FIX: Capture end date from form POST or fallback to 'present'
$rawEndDate = $_REQUEST['end_date'] ?? ($_REQUEST['employment_end'] ?? '');

if (!empty($rawEndDate) && $rawEndDate !== 'present') {
    $endDate = date('F d, Y', strtotime($rawEndDate));
} else {
    $endDate = 'present';
}

// 5. SALARY & ALLOWANCE MANUAL INPUTS
$rawBasicPay  = $_REQUEST['basic_pay'] ?? ($emp['basic_pay'] ?? 0);
$rawAllowance = $_REQUEST['allowance'] ?? ($emp['allowance'] ?? 0);

$basicPay  = is_numeric($rawBasicPay) ? (float)$rawBasicPay : 0;
$allowance = is_numeric($rawAllowance) ? (float)$rawAllowance : 0;
$totalPay  = $basicPay + $allowance;

// SALARY DISPLAY TOGGLE
if (isset($_REQUEST['salary'])) {
    $includeSalary = ($_REQUEST['salary'] === '1');
} else {
    $includeSalary = ($totalPay > 0);
}

// 6. SIGNATORY MANAGER & TITLE
$managerName  = !empty($_REQUEST['manager_name'])  ? strtoupper($_REQUEST['manager_name'])  : 'GARLAN A. CASIMERO';
$managerTitle = !empty($_REQUEST['manager_title']) ? $_REQUEST['manager_title'] : 'Manager - Administration';

// Date Issued
$issueDate = !empty($_REQUEST['notice_date']) ? date('F d, Y', strtotime($_REQUEST['notice_date'])) : date('F d, Y');
?>

<style>
    /* Document Container Setup */
    .coe-container {
        width: 100% !important;
        border-collapse: collapse;
        font-family: "Times New Roman", Times, serif;
    }

    .coe-header-table {
        width: 100%;
        margin-bottom: 25px;
        border-collapse: collapse;
    }

    .coe-title {
        text-align: center;
        font-weight: bold;
        font-size: 14pt;
        margin-top: 15px;
        margin-bottom: 25px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .coe-salutation {
        font-weight: bold;
        font-size: 11pt;
        margin-bottom: 20px;
    }

    .coe-text {
        text-align: justify;
        text-justify: inter-word;
        font-size: 11pt;
        line-height: 1.6;
        margin-bottom: 15px;
    }

    /* Salary Breakdown Table */
    .salary-table {
        margin: 15px 0 20px 40px;
        border-collapse: collapse;
        font-size: 11pt;
    }

    .salary-table td {
        padding: 2px 10px;
        vertical-align: middle;
    }

    /* Signature Section */
    .coe-sig-block {
        margin-top: 50px;
        page-break-inside: avoid;
    }

    .coe-sig-name {
        font-weight: bold;
        font-size: 11pt;
        text-transform: uppercase;
        margin-top: 40px;
    }

    .coe-sig-title {
        font-size: 10.5pt;
    }
</style>

<table class="coe-container">
    <tbody>
        <tr>
            <td>
                <!-- Company Header & Logo -->
                <table class="coe-header-table">
                    <tr>
                        <td style="width: 120px; text-align: right; vertical-align: middle; padding-right: 15px;">
                            <?php $safe_logo = !empty($global_logo_src) ? htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8') : 'assets/images/tesp-logo-1.png'; ?>
                            <img src="<?php echo $safe_logo; ?>" width="80" height="80" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;" alt="TESP Logo">
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <div style="font-weight: bold; font-size: 14pt; line-height: 1.2;">TES PHILIPPINES, INC.</div>
                            <div style="font-size: 10pt; line-height: 1.2; font-weight: bold;">METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT</div>
                            <div style="font-size: 9.5pt; line-height: 1.2;">Meriton One Building, 1668 Quezon Avenue, Quezon City</div>
                            <div style="font-size: 9.5pt; line-height: 1.2;">Telephone Number: 8929-5347 local 4404</div>
                        </td>
                        <td style="width: 60px;"></td>
                    </tr>
                </table>

                <!-- Document Title -->
                <div class="coe-title">CERTIFICATE OF EMPLOYMENT</div>

                <!-- Salutation -->
                <div class="coe-salutation">TO WHOM IT MAY CONCERN:</div>

                <!-- Sentence 1: Employment Period -->
                <p class="coe-text">
                    This is to certify that <strong><?php echo htmlspecialchars($formalName); ?></strong> is an employee of this Company from <strong><?php echo htmlspecialchars($startDate); ?></strong>, to <strong><?php echo htmlspecialchars($endDate); ?></strong>.
                </p>

                <!-- Sentence 2: Position, Section & Department -->
                <p class="coe-text">
                    <?php echo $pronounSubject; ?> currently holds the position of <strong><?php echo htmlspecialchars($positionFull); ?></strong>.
                </p>

                <!-- Salary Breakdown (Only included if Salary option is selected) -->
                <?php if ($includeSalary): ?>
                    <p class="coe-text">
                        Herewith is the breakdown of <?php echo $pronounPossessive; ?> monthly salary, to wit:
                    </p>

                    <table class="salary-table">
                        <tr>
                            <td style="width: 110px;">Basic pay</td>
                            <td style="width: 15px; text-align: center;">:</td>
                            <td>PHP <?php echo number_format($basicPay, 2); ?></td>
                        </tr>
                        <?php if ($allowance > 0): ?>
                            <tr>
                                <td>Allowance</td>
                                <td style="text-align: center;">:</td>
                                <td>PHP <?php echo number_format($allowance, 2); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Total</strong></td>
                            <td style="text-align: center;">:</td>
                            <td><strong>PHP <?php echo number_format($totalPay, 2); ?></strong></td>
                        </tr>
                    </table>
                <?php endif; ?>

                <!-- Sentence 3: Purpose Statement -->
                <p class="coe-text">
                    This certification is issued upon the request of <strong><?php echo htmlspecialchars($formalName); ?></strong> for whatever good purpose it may serve <?php echo $pronounObjective; ?>.
                </p>

                <!-- Sentence 4: Date Issued -->
                <p class="coe-text" style="margin-top: 25px;">
                    Issued on <strong><?php echo htmlspecialchars($issueDate); ?></strong> at Quezon City, Philippines.
                </p>

                <!-- Signature Block -->
                <div class="coe-sig-block">
                    <div style="margin-left: 220px;">
                        <div>Truly yours,</div>
                        <div class="coe-sig-name"><?php echo htmlspecialchars($managerName); ?></div>
                        <div class="coe-sig-title"><?php echo htmlspecialchars($managerTitle); ?></div>
                    </div>
                </div>

            </td>
        </tr>
    </tbody>
</table>