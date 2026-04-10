<?php
$companyName = $settings['company_name'] ?? 'TES PHILIPPINES, INC.';
$companyAddress = $settings['company_address'] ?? 'Room 207, 2nd Flr., Meriton One Bldg., Quezon Avenue, Quezon City, Philippines';
$purpose = 'This certificate is issued upon request for employment verification, loan processing, visa application, or other lawful purposes.';

$hired = null;
$yearsOfService = '';
if (!empty($emp['hire_date'])) {
    try {
        $hired = new DateTime($emp['hire_date']);
        $now = new DateTime();
        $yearsOfService = $hired->diff($now)->y;
    } catch (Exception $e) {
        $hired = null;
    }
}

$employmentStart = $hired ? $hired->format('F j, Y') : 'N/A';
// Use the employmentEnd value already determined by generate_document.php
$employmentEnd = $_GET['employment_end_display'] ?? 'Present';

$salaryLabel = '';
if (!empty($emp['monthly_rate'])) {
    $salaryLabel = 'PHP ' . number_format((float)$emp['monthly_rate'], 2);
} elseif (!empty($emp['salary'])) {
    $salaryLabel = 'PHP ' . number_format((float)$emp['salary'], 2);
}

$jobDescription = trim($custom_duties ?: ($emp['job_description'] ?? ''));
?>
<div style="text-align: center; margin-bottom: 30px;">
    <table style="width: 100%; margin-bottom: 10px;">
        <tr>
            <td style="width: 130px; text-align: right; vertical-align: middle; padding-right: 15px;">
                <?php $safe_logo_src = !empty($global_logo_src) ? htmlspecialchars($global_logo_src, ENT_QUOTES, 'UTF-8') : 'assets/images/tesp-logo-1.png'; ?>
                <img src="<?php echo $safe_logo_src; ?>" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover;" alt="TESP Logo">
            </td>
            <td style="text-align: center; vertical-align: middle;">
                <div style="font-weight: bold; font-size: 15pt !important; line-height: 1.2; white-space: nowrap;">
                    <?php echo htmlspecialchars($companyName); ?>
                </div>
                <div style="font-weight: bold; font-size: 11pt !important; line-height: 1.2; white-space: nowrap;">
                    <?php echo htmlspecialchars($settings['default_project_name'] ?? 'METRO RAIL TRANSIT LINE 3 REHABILITATION PROJECT'); ?>
                </div>
                <div style="font-size: 11pt !important; line-height: 1.2;">
                    <?php echo htmlspecialchars($companyAddress); ?>
                </div>
                <div style="font-size: 11pt !important; line-height: 1.2;">
                    Telephone Number: 8929-5347 local 4404
                </div>
            </td>
            <td style="width: 70px;"></td> <!-- Spacer for shifting text right -->
        </tr>
    </table>
</div>

<div style="text-align: center; margin-bottom: 60px;">
    <h1 style="font-size: 24pt; text-decoration: underline; letter-spacing: 2px;">CERTIFICATE OF EMPLOYMENT</h1>
</div>

<div class="justify" style="margin-bottom: 30px;">
    <p>This is to certify that <strong><?php echo htmlspecialchars($full_name); ?></strong> is / was employed by <strong><?php echo htmlspecialchars($companyName); ?></strong> with the following details:</p>
</div>

<table style="width: 100%; margin-bottom: 40px; border-collapse: collapse;">
    <tr>
        <td style="width: 30%; padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">Full Name:</td>
        <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($full_name); ?></td>
    </tr>
    <tr>
        <td style="padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">Position Held:</td>
        <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($position); ?></td>
    </tr>
    <tr>
        <td style="padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">Department:</td>
        <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($emp['dept'] ?? 'N/A'); ?></td>
    </tr>
    <tr>
        <td style="padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">Employment Period:</td>
        <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($employmentStart); ?> to <?php echo htmlspecialchars($employmentEnd); ?></td>
    </tr>
    <tr>
        <td style="padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">Status:</td>
        <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($emp['status'] ?? 'N/A'); ?></td>
    </tr>
    <?php if ($salaryLabel): ?>
        <tr>
            <td style="padding: 10px; font-weight: bold; border-bottom: 1px solid #eee;">Salary:</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($salaryLabel); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($jobDescription): ?>
        <tr>
            <td style="padding: 10px; font-weight: bold; border-bottom: 1px solid #eee; vertical-align: top;">Job Description:</td>
            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo nl2br(htmlspecialchars($jobDescription)); ?></td>
        </tr>
    <?php endif; ?>
</table>

<div class="justify" style="margin-bottom: 30px;">
    <p><?php echo htmlspecialchars($purpose); ?></p>
    <p>This certificate is issued for whatever legal purpose it may serve.</p>
</div>

<div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 80px;">
    <div style="flex: 1;">
        <div style="font-size: 9pt; color: #888;">Date Issued:</div>
        <div style="font-size: 11pt; font-weight: bold; margin-top: 5px;"><?php echo htmlspecialchars($current_full_date); ?></div>
    </div>
    <div style="width: 250px; text-align: center;">
        <div style="border-top: 2px solid #000; padding-top: 10px; font-weight: bold; text-transform: uppercase;">

        </div>
        <div>Senior / General Manager / Manager</div>
    </div>
</div>

<div style="margin-top: 20px; font-size: 9pt; color: #888; font-style: italic;">Note: This document is valid only if it bears the official company seal.</div>