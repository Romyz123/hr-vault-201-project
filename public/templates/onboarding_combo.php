<?php
/*
|--------------------------------------------------------------------------
| ONBOARDING COMBO PACKET — Sequential Multi-Document Template
|--------------------------------------------------------------------------
| Bundles onboarding documents into a single seamless print job.
|
| COMBO TYPES (set $comboType before including this file):
|   'combo_project'       → Project Contract + NDA + Data Consent + Whistleblower
|   'combo_probationary'  → Probationary Contract + NDA + Data Consent
|   (default)             → NDA + Data Consent + Whistleblower (legacy)
|
| Each document starts on a fresh page via CSS page-break-after.
| CSS rules are scoped per-document using unique wrapper IDs to prevent
| cross-contamination of styles (e.g. position:absolute bleeding).
|
| VARIABLES EXPECTED:
|   $emp              – full employee record from database
|   $comboType        – which combo to render (see above)
|   $docFontSize      – legal text font size (optional)
|   $global_logo_src  – base64 / path of company logo (optional)
|   $companyPresident – name of company president for signatures (optional)
|--------------------------------------------------------------------------
*/

if (!isset($emp) || !is_array($emp)) {
    $emp = [];
}

// ── CSS Scoping helpers ──────────────────────────────────────────────────
// These prefix every CSS selector with a wrapper ID so styles from one
// sub-template cannot leak into another.

if (!function_exists('comboScopePlainRules')) {
    /**
     * Prefix every plain CSS rule's selector(s) with #scopeId.
     * Handles comma-separated selectors, html/body/:root remapping,
     * and the universal * selector.
     */
    function comboScopePlainRules($css, $scopeId)
    {
        return preg_replace_callback(
            '/([^{}@]+)\{([^}]*)\}/s',
            function ($m) use ($scopeId) {
                $selectorGroup = trim($m[1]);
                $body = $m[2];
                if ($selectorGroup === '' || strpos($selectorGroup, '@') === 0) {
                    return $m[0];
                }
                $selectors = explode(',', $selectorGroup);
                $prefixed  = [];
                foreach ($selectors as $sel) {
                    $sel = trim($sel);
                    if ($sel === '') continue;
                    if (preg_match('/^(html|body|:root)$/i', $sel)) {
                        $prefixed[] = '#' . $scopeId;
                    } elseif (preg_match('/^(html|body|:root)\s+/i', $sel)) {
                        $remainder  = preg_replace('/^(html|body|:root)\s+/i', '', $sel);
                        $prefixed[] = '#' . $scopeId . ' ' . $remainder;
                    } elseif ($sel === '*') {
                        $prefixed[] = '#' . $scopeId . ' *';
                    } else {
                        $prefixed[] = '#' . $scopeId . ' ' . $sel;
                    }
                }
                return implode(",\n", $prefixed) . ' {' . $body . '}';
            },
            $css
        );
    }
}

if (!function_exists('comboScopeCssBlock')) {
    /**
     * Scope an entire CSS text block.  Strips @page (handled globally),
     * scopes selectors inside @media blocks, then scopes remaining rules.
     */
    function comboScopeCssBlock($cssText, $scopeId)
    {
        // Comments before selectors otherwise prevent special selectors like :root from being remapped.
        $cssText = preg_replace('!/\*.*?\*/!s', '', $cssText);

        // Strip @page rules — we only need them once globally
        $cssText = preg_replace('/@page\s*\{[^}]*\}/s', '', $cssText);

        // 1. Handle @media blocks: prefix selectors inside them
        $cssText = preg_replace_callback(
            '/@media\s*([^{]+)\{((?:[^{}]*|\{[^}]*\})*)\}/s',
            function ($m) use ($scopeId) {
                $mediaQuery = $m[1];
                $innerCss   = comboScopePlainRules($m[2], $scopeId);
                return '@media ' . $mediaQuery . '{' . $innerCss . '}';
            },
            $cssText
        );

        // 2. Extract already-scoped @media blocks
        preg_match_all('/@media\s*[^{]+\{(?:[^{}]*|\{[^}]*\})*\}/s', $cssText, $mediaBlocks);

        // 3. Scope remaining plain rules (those outside @media)
        $withoutMedia = preg_replace('/@media\s*[^{]+\{(?:[^{}]*|\{[^}]*\})*\}/s', '', $cssText);
        $scopedPlain  = comboScopePlainRules($withoutMedia, $scopeId);

        return $scopedPlain . "\n" . implode("\n", $mediaBlocks[0] ?? []);
    }
}

// ── Determine which documents to include ─────────────────────────────────

$comboType = $comboType ?? ($type ?? 'onboarding_combo');

switch ($comboType) {
    case 'combo_project':
        $comboTemplates = [
            ['file' => __DIR__ . '/contract_project.php',          'label' => 'Project Employment Contract'],
            ['file' => __DIR__ . '/confidentiality_agreement.php', 'label' => 'Confidentiality Agreement (NDA)'],
            ['file' => __DIR__ . '/data_consent.php',              'label' => 'Employee Data Consent Statement'],
            ['file' => __DIR__ . '/whistleblowing.php',            'label' => 'MHI Whistleblower Consent Form'],
        ];
        break;

    case 'combo_probationary':
        $comboTemplates = [
            ['file' => __DIR__ . '/contract_probationary.php',     'label' => 'Probationary Employment Contract'],
            ['file' => __DIR__ . '/confidentiality_agreement.php', 'label' => 'Confidentiality Agreement (NDA)'],
            ['file' => __DIR__ . '/data_consent.php',              'label' => 'Employee Data Consent Statement'],
        ];
        break;

    default: // legacy 'onboarding_combo'
        $comboTemplates = [
            ['file' => __DIR__ . '/confidentiality_agreement.php', 'label' => 'Confidentiality Agreement (NDA)'],
            ['file' => __DIR__ . '/data_consent.php',              'label' => 'Employee Data Consent Statement'],
            ['file' => __DIR__ . '/whistleblowing.php',            'label' => 'MHI Whistleblower Consent Form'],
        ];
        break;
}

// ── Global page rule (output once) ───────────────────────────────────────
if (!defined('COMBO_PAGE_STYLE_EMITTED')) {
    define('COMBO_PAGE_STYLE_EMITTED', true);
    echo '<style>
        @page { size: A4 portrait; margin: 0; }
        @media print {
            table { border-collapse: collapse !important; }
            .header-container, .header-wrapper { border-bottom: none !important; }
            .print-content { border-top: 1px solid #333 !important; }
            .signature-table, .signature-table td,
            .sig-table, .sig-table td,
            .contract-details, .contract-details td {
                border: none !important;
            }
        }
    </style>' . "\n";
}

// ── Render each sub-template with scoped CSS ─────────────────────────────

static $comboEmployeeCounter = 0;
$comboEmployeeCounter++;

$totalDocs = count($comboTemplates);

foreach ($comboTemplates as $idx => $docDef) {
    $filePath = $docDef['file'];
    $isLast   = ($idx === $totalDocs - 1);
    $scopeId  = 'combo-emp-' . $comboEmployeeCounter . '-doc-' . $idx;

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

    // Extract all <style> blocks (with or without attributes)
    preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $rawOutput, $styleMatches);

    // Build scoped CSS from extracted styles
    $scopedCss = '';
    if (!empty($styleMatches[1])) {
        foreach ($styleMatches[1] as $rawCss) {
            $scopedCss .= comboScopeCssBlock($rawCss, $scopeId) . "\n";
        }
    }

    // Extract the body by offsets; regex backtracking can fail on embedded base64 logos.
    $bodyOpen = stripos($rawOutput, '<body');
    $bodyOpenEnd = $bodyOpen === false ? false : strpos($rawOutput, '>', $bodyOpen);
    $bodyClose = $bodyOpenEnd === false ? false : stripos($rawOutput, '</body>', $bodyOpenEnd + 1);
    $bodyHtml = ($bodyOpenEnd !== false && $bodyClose !== false)
        ? substr($rawOutput, $bodyOpenEnd + 1, $bodyClose - $bodyOpenEnd - 1)
        : $rawOutput;

    // Hard page break after each document except the very last one
    $breakStyle = $isLast ? '' : 'page-break-after: always; break-after: page;';

    // Output scoped styles
    if ($scopedCss !== '') {
        echo '<style>' . "\n" . $scopedCss . "\n" . '</style>' . "\n";
    }

    if (basename($filePath) === 'contract_probationary.php') {
        echo '<style>
            @media print {
                #' . $scopeId . ' {
                    box-sizing: border-box !important;
                    width: 210mm !important;
                    max-width: 210mm !important;
                    margin: 0 auto !important;
                    padding: 0 12.7mm !important;
                }
                #' . $scopeId . ' .report-container {
                    width: 100% !important;
                    table-layout: fixed !important;
                }
                #' . $scopeId . ' .report-header,
                #' . $scopeId . ' .report-header > tr > td,
                #' . $scopeId . ' .header-wrapper,
                #' . $scopeId . ' .header-wrapper table,
                #' . $scopeId . ' .header-wrapper tr,
                #' . $scopeId . ' .header-wrapper td {
                    border: 0 none transparent !important;
                    outline: 0 !important;
                    box-shadow: none !important;
                }
                #' . $scopeId . ' .header-wrapper {
                    width: 100% !important;
                    box-sizing: border-box !important;
                    padding-top: 12.7mm !important;
                }
            }
        </style>' . "\n";
    }

    // Output document body inside the scoped wrapper
    echo '<div id="' . $scopeId . '" class="combo-doc-item" style="' . $breakStyle . '">';
    echo $bodyHtml;
    echo '</div>' . "\n";
}
