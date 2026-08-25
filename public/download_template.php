<?php
// public/download_template.php
if (ob_get_level()) ob_end_clean(); // [FIX] Clean buffer to prevent whitespace corruption in CSV
session_start();

// Security check
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

$allowedTypes = ['TESP', 'GUNJIN', 'OTHERS', 'JORATECH', 'UNLISOLUTIONS', 'CUSTOM'];
$type = $_GET['type'] ?? 'TESP';

if (!in_array($type, $allowedTypes, true)) {
    $type = 'TESP'; // Fallback to default
}

$filename = "Template_" . $type . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');

// Add BOM for Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

if ($type === 'JORATECH') {
    fputcsv($output, ['NO', 'SECTION', 'POSITION', 'DATE HIRED', 'NUM', 'PIC', 'NAME', 'CODE', 'CONTRACT']);
    fputcsv($output, ['1', 'MAINTENANCE', 'Technician', '2023-01-15', '', '', 'Doe, John', 'JOR-001', 'Project']);
} elseif ($type === 'UNLISOLUTIONS') {
    fputcsv($output, ['NO', 'ID', 'PIC', 'NAME', 'POSITION', 'SECTION', 'CONTACT', 'BDAY', 'HIRED', 'SSS', 'TIN', 'PAGIBIG', 'PHILHEALTH', 'ADDRESS', 'EMAIL']);
    fputcsv($output, ['1', 'UNLI-001', '', 'Doe, John', 'Staff', 'ADMIN', '09123456789', '1990-01-01', '2023-01-01', '', '', '', '', '', 'john@example.com']);
} elseif ($type === 'CUSTOM') {
    // [UPDATED] Full 22-Question Microsoft/Google Forms Format
    fputcsv($output, [
        'First Name',
        'Middle Name',
        'Last Name',
        'Suffix',
        'Date of Birth',
        'Gender',
        'Civil Status',
        'Mobile Number',
        'Personal Email Address',
        'Complete Present Address',
        'Complete Permanent Address',
        'SSS Number',
        'Pag-IBIG (HDMF) Number',
        'PhilHealth Number',
        'TIN (Tax Identification Number)',
        'Emergency Contact Name',
        'Emergency Contact Number',
        'Emergency Contact Address',
        'Employee ID Number',
        'Department',
        'Position / Job Title',
        'Date Hired',
        'Education Attainment',
        'JobExperience',
        'Licenses / Certifications',
        'College Degree',
        'College Course',
        'Year Finished'
    ]);
    fputcsv($output, ['Juan', 'Dela', 'Cruz', '', '1/15/1990', 'Man', 'Single', '09123456789', 'juan.delacruz@example.com', '123 Main St, Quezon City', 'Same as present', '12-3456789-0', '1234-5678-9012', '12-345678901-2', '123-456-789-000', 'Maria Cruz', '09987654321', '123 Main St, Quezon City', 'CUST-001', 'ADMIN', 'Staff', '5/1/2024', 'College Graduate', '3 Years Experience', 'N/A', "Bachelor's Degree", 'BS Computer Science', '2020']);
} else {
    // TESP, GUNJIN, OTHERS (Standard Format)
    fputcsv($output, ['NO.', 'EMPLOYEE CODE', 'PICTURE', 'NAME', 'SECTION', 'CONTACT DETAILS:', 'BIRTHDAY', 'DATE OF HIRED', 'SSS', 'TIN', 'PAG-IBIG', 'PHILHEALTH']);
    fputcsv($output, ['1', 'TESP-001', '', 'Doe, John', 'SQP', '09123456789', '1990-01-01', '2023-01-01', '', '', '', '']);
}

fclose($output);
exit;
