<?php
// public/compile_packet.php

require_once __DIR__ . '/../config/db.php';

// 1. Gather Input Data (Support both array and single string)
$rawEmployeeIds = $_POST['employee_ids'] ?? $_POST['employee_id'] ?? [];
$employee_ids   = is_array($rawEmployeeIds) ? array_filter($rawEmployeeIds) : [$rawEmployeeIds];
$preset_id      = $_POST['preset_id'] ?? null;
$isPreview      = isset($_POST['preview']) && $_POST['preview'] === 'true';

// Layout CSS inputs
$fontFamily  = $_POST['font_family'] ?? 'Arial';
$fontSize    = ($_POST['font_size'] ?? '11') . 'pt';
$lineHeight  = $_POST['line_height'] ?? '1.4';
$margin      = ($_POST['margin'] ?? '0.5') . 'in';

if (empty($employee_ids) || !$preset_id) {
    echo "<div style='padding:20px; font-family:sans-serif; color: #666;'>Please select at least one employee and a packet preset.</div>";
    exit;
}

// Limit bulk rendering during iframe preview for speed
if ($isPreview && count($employee_ids) > 1) {
    $employee_ids = array_slice($employee_ids, 0, 1); // Preview 1st employee
}

// 2. Fetch Ordered Templates for Selected Preset
$stmt = $pdo->prepare("
    SELECT t.id, t.title, t.template_file 
    FROM packet_preset_items ppi
    JOIN document_templates t ON ppi.template_id = t.id
    WHERE ppi.preset_id = ?
    ORDER BY ppi.print_sequence ASC
");
$stmt->execute([$preset_id]);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Compile Master Bulk HTML
$bulkHtml = "";
$totalEmployees = count($employee_ids);

foreach ($employee_ids as $empIndex => $empId) {
    // Retrieve Employee Record
    $stmtEmp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmtEmp->execute([$empId]);
    $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if (!$employee) continue;

    // Resolve Name Concatenation
    $empName = $employee['name'] ?? trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));

    // Employee Placeholders
    $placeholders = [
        '{{EMPLOYEE_NAME}}'  => htmlspecialchars($empName),
        '{{EMPLOYEE_EMAIL}}' => htmlspecialchars($employee['email'] ?? ''),
        '{{CURRENT_DATE}}'   => date('F j, Y'),
    ];

    $templateCount = count($templates);

    foreach ($templates as $tplIndex => $tpl) {
        $filePath = __DIR__ . '/templates/' . $tpl['template_file'];

        if (file_exists($filePath)) {
            ob_start();
            include $filePath;
            $content = ob_get_clean();

            // Inject Placeholders
            $content = str_replace(array_keys($placeholders), array_values($placeholders), $content);

            // Determine Page Break (Break between documents & between employees)
            $isLastDocInLastEmp = ($empIndex === $totalEmployees - 1) && ($tplIndex === $templateCount - 1);
            $pageBreakStyle = $isLastDocInLastEmp ? '' : 'page-break-after: always; break-after: page;';

            $bulkHtml .= "<div class=\"document-section\" style=\"{$pageBreakStyle}\">{$content}</div>";
        }
    }
}

// 4. Wrap with Dynamic CSS Setup
$fullDocument = "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <title>Bulk Packet Output (" . count($employee_ids) . " Personnel)</title>
    <style>
        @page { size: letter portrait; margin: {$margin}; }
        body {
            font-family: {$fontFamily}, sans-serif;
            font-size: {$fontSize};
            line-height: {$lineHeight};
            color: #111111;
            margin: 0; padding: 0;
        }
        .document-section { width: 100%; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    " . (!$isPreview ? "<div class=\"no-print\" style=\"background:#333; padding:10px; text-align:right;\"><button onclick=\"window.print()\" style=\"padding:8px 16px; font-weight:bold; cursor:pointer;\">Print All Packets</button></div>" : "") . "
    {$bulkHtml}
    " . (!$isPreview ? "<script>window.onload = function() { window.print(); };</script>" : "") . "
</body>
</html>";

echo $fullDocument;
