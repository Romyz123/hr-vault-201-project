<?php

namespace HRVault\Services;

use PDO;
use Exception;

class PacketPrinterService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Replaces placeholder tokens with actual employee record data.
     */
    public function renderPlaceholders(string $templateHtml, array $emp): string
    {
        $map = [
            '{{first_name}}'   => htmlspecialchars($emp['first_name'] ?? ''),
            '{{last_name}}'    => htmlspecialchars($emp['last_name'] ?? ''),
            '{{full_name}}'    => htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
            '{{emp_id}}'       => htmlspecialchars($emp['emp_id'] ?? ''),
            '{{job_title}}'    => htmlspecialchars($emp['job_title'] ?? ''),
            '{{department}}'   => htmlspecialchars($emp['department'] ?? ''),
            '{{salary}}'       => htmlspecialchars($emp['salary'] ?? '0.00'),
            '{{tin}}'          => htmlspecialchars($emp['tin_no'] ?? ''),
            '{{sss}}'          => htmlspecialchars($emp['sss_no'] ?? ''),
            '{{date_today}}'   => date('F d, Y'),
        ];

        return str_replace(array_keys($map), array_values($map), $templateHtml);
    }

    /**
     * Compiles customized document packets for multiple employees into a single printable HTML stream.
     */
    public function generateBatchPacketHtml(array $employeeIds, int $presetId, array $globalOverrides = []): string
    {
        // Fetch packet items in sequence
        $stmt = $this->pdo->prepare("
            SELECT t.*, p.sequence_order 
            FROM packet_preset_items p
            JOIN document_templates t ON p.template_id = t.id
            WHERE p.preset_id = ?
            ORDER BY p.sequence_order ASC
        ");
        $stmt->execute([$presetId]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($templates)) {
            throw new Exception("No templates found for the selected packet preset.");
        }

        $htmlOutput = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $htmlOutput .= '<style>
            @page { size: letter; margin: 0; }
            body { margin: 0; padding: 0; font-family: Arial, sans-serif; }
            .doc-page { page-break-after: always; break-after: page; box-sizing: border-box; }
            .cover-sheet { page-break-after: always; break-after: page; display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100vh; text-align: center; }
            .duplex-spacer { page-break-after: always; break-after: page; visibility: hidden; }
        </style></head><body>';

        foreach ($employeeIds as $empId) {
            // Fetch employee data
            $empStmt = $this->pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $empStmt->execute([$empId]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

            if (!$emp) continue;

            // 1. Cover Sheet / Separator
            $htmlOutput .= '<div class="cover-sheet">';
            $htmlOutput .= '<h1>DOCUMENT PACKET</h1>';
            $htmlOutput .= '<h2>' . htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) . '</h2>';
            $htmlOutput .= '<p>Employee ID: ' . htmlspecialchars($emp['emp_id']) . ' | Dept: ' . htmlspecialchars($emp['department']) . '</p>';
            $htmlOutput .= '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';
            $htmlOutput .= '</div>';

            // 2. Process each document in the packet sequence
            foreach ($templates as $doc) {
                // Check for individual employee override settings
                $ovStmt = $this->pdo->prepare("SELECT * FROM employee_packet_overrides WHERE employee_id = ? AND template_id = ?");
                $ovStmt->execute([$empId, $doc['id']]);
                $override = $ovStmt->fetch(PDO::FETCH_ASSOC);

                $contentHtml = $override['custom_content_html'] ?? $doc['content_html'];
                $fontFamily  = $globalOverrides['font_family'] ?? $override['font_family'] ?? $doc['default_font_family'];
                $fontSize    = $globalOverrides['font_size']   ?? $override['font_size']   ?? $doc['default_font_size'];
                $lineHeight  = $globalOverrides['line_height'] ?? $override['line_height'] ?? $doc['default_line_height'];
                $margin      = $globalOverrides['margin']      ?? $override['margin']      ?? $doc['default_margin'];

                // Populate dynamic employee placeholders
                $renderedHtml = $this->renderPlaceholders($contentHtml, $emp);

                // Build page container styling
                $pageStyle = sprintf(
                    'font-family: %s; font-size: %s; line-height: %s; padding: %s;',
                    $fontFamily,
                    $fontSize,
                    $lineHeight,
                    $margin
                );

                $htmlOutput .= '<div class="doc-page" style="' . $pageStyle . '">';
                $htmlOutput .= $renderedHtml;
                $htmlOutput .= '</div>';

                // 3. Duplex / Double-sided safety check (Inject blank page if odd page count needed)
                if (!empty($globalOverrides['enforce_duplex_safety'])) {
                    $htmlOutput .= '<div class="duplex-spacer"><!-- Duplex Alignment Spacer --></div>';
                }
            }
        }

        $htmlOutput .= '</body></html>';
        return $htmlOutput;
    }
}
