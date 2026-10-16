<?php
/*
|--------------------------------------------------------------------------
| ONBOARDING COMBO PACKET — Sequential Multi-Document Template
|--------------------------------------------------------------------------
| Bundles three onboarding documents into a single seamless print job:
|   1. Confidentiality Agreement (NDA)
|   2. Employee Data Consent Statement
|   3. MHI Whistleblower Policy / Consent Form
|
| Each document starts on a fresh page via CSS page-break-after.
| The $emp array is fetched once by the caller (generate_document.php or
| bulk_contract.php) and shared uniformly across all sub-templates.
|
| VARIABLES EXPECTED:
|   $emp              – full employee record from database
|   $docFontSize      – legal text font size (optional)
|   $global_logo_src  – base64 / path of company logo (optional)
|   $companyPresident – name of company president for signatures (optional)
|--------------------------------------------------------------------------
*/

if (!isset($emp) || !is_array($emp)) {
    $emp = [];
}

$comboTemplates = [
    [
        'file'  => __DIR__ . '/confidentiality_agreement.php',
        'label' => 'Confidentiality Agreement (NDA)',
    ],
    [
        'file'  => __DIR__ . '/data_consent.php',
        'label' => 'Employee Data Consent Statement',
    ],
    [
        'file'  => __DIR__ . '/whistleblowing.php',
        'label' => 'MHI Whistleblower Consent Form',
    ],
];

$totalDocs = count($comboTemplates);

foreach ($comboTemplates as $idx => $docDef) {
    $filePath = $docDef['file'];
    $isLast   = ($idx === $totalDocs - 1);

    if (!file_exists($filePath)) {
        echo '<div style="padding:20px;color:red;font-family:sans-serif;">'
           . '<strong>Template Missing:</strong> '
           . htmlspecialchars($docDef['label'])
           . ' (' . htmlspecialchars(basename($filePath)) . ')'
           . '</div>';
        continue;
    }

    // Render the individual sub-template using output buffering
    ob_start();
    include $filePath;
    $rawOutput = ob_get_clean();

    // Extract styles if any are present inside <style> tags
    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $rawOutput, $styleMatches);
    $docStyles = !empty($styleMatches[0]) ? implode("\n", $styleMatches[0]) : '';

    // Extract body content or strip outer html wrappers so documents nest cleanly
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $rawOutput, $bodyMatch)) {
        $bodyHtml = $bodyMatch[1];
    } else {
        $bodyHtml = $rawOutput;
    }

    // Hard page break after each document except the very last one
    $breakStyle = $isLast ? '' : 'page-break-after: always; break-after: page;';

    if (!empty($docStyles)) {
        echo $docStyles;
    }

    echo '<div class="combo-doc-item" style="' . $breakStyle . '">';
    echo $bodyHtml;
    echo '</div>';
}

