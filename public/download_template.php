<?php
// public/download_template.php
if (ob_get_level()) ob_end_clean(); // [FIX] Clean buffer to prevent whitespace corruption in CSV
session_start();

// Security check
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

$type = $_GET['type'] ?? 'TESP';
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
    fputcsv($output, ['Employee ID', 'Full Name', 'Job Title', 'Department', 'Date Hired']);
    fputcsv($output, ['CUST-001', 'Doe, John', 'Manager', 'IT', '2023-01-01']);
} else {
    // TESP, GUNJIN, OTHERS (Standard Format)
    fputcsv($output, ['NO', 'ID', 'PIC', 'NAME', 'SECTION', 'CONTACT', 'BDAY', 'HIRED', 'SSS', 'TIN', 'PAGIBIG', 'PHILHEALTH']);
    fputcsv($output, ['1', 'TESP-001', '', 'Doe, John', 'SQP', '09123456789', '1990-01-01', '2023-01-01', '', '', '', '']);
}

fclose($output);
exit;
