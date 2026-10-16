<?php
// public/packet_builder.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load Database Connection
require_once __DIR__ . '/../config/db.php';

try {
    // 1. Fetch Employees (Concatenates first_name and last_name safely with fallback)
    try {
        $employees = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM employees ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback for tables using single 'name' or 'full_name' column
        $employees = $pdo->query("SELECT id, id AS name FROM employees ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. Fetch Packet Presets
    $presets = $pdo->query("SELECT id, preset_name FROM packet_presets ORDER BY preset_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Document Templates
    $templates = $pdo->query("SELECT id, title FROM document_templates ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div style='padding: 20px; color: red; font-family: sans-serif;'><strong>Database Initialization Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Document Packet Builder</title>
    <!-- CDN for Client-side PDF Generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f4f6f8;
        }

        .builder-layout {
            display: flex;
            gap: 20px;
            height: calc(100vh - 60px);
        }

        .control-panel {
            width: 420px;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .preview-panel {
            flex-grow: 1;
            background: #525659;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            justify-content: center;
        }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
        }

        select,
        input[type="range"],
        input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .slider-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .slider-group span {
            min-width: 45px;
            text-align: right;
            font-size: 13px;
            font-weight: bold;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #ddd;
            margin-bottom: 15px;
        }

        .tab-btn {
            padding: 8px 16px;
            cursor: pointer;
            border: none;
            background: none;
            font-weight: 600;
            color: #666;
        }

        .tab-btn.active {
            border-bottom: 3px solid #007bff;
            color: #007bff;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 15px;
        }

        .btn-submit:hover {
            background: #0056b3;
        }

        .btn-secondary {
            width: 100%;
            padding: 10px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>

<body>

    <div class="builder-layout">
        <!-- Control Sidebar -->
        <div class="control-panel">
            <h2>Packet Builder</h2>
            <form id="packetForm" action="compile_packet.php" method="POST" target="_blank">

                <!-- Employee & Preset Selection -->
                <div class="form-group">
                    <label for="employee_id">Select Employee:</label>
                    <select name="employee_id" id="employee_id" required>
                        <option value="">-- Choose Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name'] ?? 'Employee #' . $emp['id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="preset_id">Packet Preset:</label>
                    <select name="preset_id" id="preset_id" required>
                        <option value="">-- Choose Preset --</option>
                        <?php foreach ($presets as $preset): ?>
                            <option value="<?= $preset['id'] ?>"><?= htmlspecialchars($preset['preset_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">

                <!-- Tabs Navigation -->
                <div class="tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('layout')">Layout & Fonts</button>
                    <button type="button" class="tab-btn" onclick="switchTab('documents')">Documents</button>
                </div>

                <!-- Tab 1: Styling & Typography Controls -->
                <div id="tab-layout" class="tab-content active">
                    <div class="form-group">
                        <label for="font_family">Font Family:</label>
                        <select name="font_family" id="font_family">
                            <option value="Arial">Arial</option>
                            <option value="Helvetica">Helvetica</option>
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Courier">Courier</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Font Size (pt):</label>
                        <div class="slider-group">
                            <input type="range" name="font_size" id="font_size" min="8" max="18" value="11" step="0.5">
                            <span id="font_size_val">11pt</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Line Height:</label>
                        <div class="slider-group">
                            <input type="range" name="line_height" id="line_height" min="1.0" max="2.5" value="1.4" step="0.1">
                            <span id="line_height_val">1.4</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Page Margin (in):</label>
                        <div class="slider-group">
                            <input type="range" name="margin" id="margin" min="0.25" max="1.5" value="0.5" step="0.05">
                            <span id="margin_val">0.5in</span>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Document Info -->
                <div id="tab-documents" class="tab-content">
                    <label>Included Documents in Selected Preset:</label>
                    <p style="font-size: 12px; color: #666;">Selecting a preset automatically loads associated document templates in sequence.</p>
                    <div id="preset_items_list">
                        <em>Select a preset above to load documents into the preview.</em>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Generate PDF Packet (Print View)</button>
                <button type="button" class="btn-secondary" onclick="downloadPDF()">Download PDF (Direct)</button>
            </form>
        </div>

        <!-- Live Preview Frame -->
        <div class="preview-panel">
            <iframe id="previewFrame" name="previewFrame" src="about:blank"></iframe>
        </div>
    </div>

    <script>
        // Tab switching handler
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            event.currentTarget.classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
        }

        // Dynamic Live Preview Handler
        const form = document.getElementById('packetForm');
        const iframe = document.getElementById('previewFrame');

        function updatePreview() {
            // Sync slider visual text indicators
            document.getElementById('font_size_val').textContent = document.getElementById('font_size').value + 'pt';
            document.getElementById('line_height_val').textContent = document.getElementById('line_height').value;
            document.getElementById('margin_val').textContent = document.getElementById('margin').value + 'in';

            // Don't issue request if employee or preset is unselected
            if (!document.getElementById('employee_id').value || !document.getElementById('preset_id').value) {
                return;
            }

            // Trigger POST rendering to target iframe in preview mode
            const formData = new FormData(form);
            formData.append('preview', 'true');

            fetch('compile_packet.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    const doc = iframe.contentWindow.document;
                    doc.open();
                    doc.write(html);
                    doc.close();
                })
                .catch(err => console.error("Preview rendering failed:", err));
        }

        // Bind event listeners to form inputs for live preview
        form.querySelectorAll('input, select').forEach(element => {
            element.addEventListener('input', updatePreview);
            element.addEventListener('change', updatePreview);
        });

        // Client-side PDF Download via html2pdf.js
        function downloadPDF() {
            const element = iframe.contentWindow.document.body;
            if (!element || element.innerHTML.trim() === "") {
                alert("Please select an employee and a preset first to render the preview.");
                return;
            }
            const opt = {
                margin: parseFloat(document.getElementById('margin').value),
                filename: 'Document_Packet.pdf',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2
                },
                jsPDF: {
                    unit: 'in',
                    format: 'letter',
                    orientation: 'portrait'
                }
            };
            html2pdf().set(opt).from(element).save();
        }
    </script>

</body>

</html>