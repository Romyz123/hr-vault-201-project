<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>HR Vault 201 - Packet Printer Editor</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .editor-container {
            display: flex;
            height: calc(100vh - 80px);
            gap: 15px;
            padding: 15px;
        }

        .controls-panel {
            width: 320px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 6px;
            overflow-y: auto;
        }

        .preview-panel {
            flex-grow: 1;
            background: #525659;
            border-radius: 6px;
            padding: 15px;
            display: flex;
            justify-content: center;
        }

        iframe#printPreview {
            width: 100%;
            height: 100%;
            border: none;
            background: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        }

        .control-group {
            margin-bottom: 15px;
        }

        .control-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .control-group input,
        .control-group select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
    </style>
</head>

<body>

    <div class="editor-container">
        <!-- Controls Sidebar -->
        <div class="controls-panel">
            <h3>Typography & Layout</h3>

            <div class="control-group">
                <label for="fontFamily">Font Family</label>
                <select id="fontFamily" onchange="updatePreview()">
                    <option value="Arial">Arial</option>
                    <option value="Calibri">Calibri</option>
                    <option value="Times New Roman">Times New Roman</option>
                    <option value="Segoe UI">Segoe UI</option>
                </select>
            </div>

            <div class="control-group">
                <label for="fontSize">Font Size (<span id="fontSizeVal">10pt</span>)</label>
                <input type="range" id="fontSize" min="8" max="14" step="0.5" value="10" oninput="document.getElementById('fontSizeVal').innerText=this.value+'pt'; updatePreview();">
            </div>

            <div class="control-group">
                <label for="lineHeight">Line Spacing</label>
                <select id="lineHeight" onchange="updatePreview()">
                    <option value="1.1">Compact (1.1)</option>
                    <option value="1.3" selected>Normal (1.3)</option>
                    <option value="1.5">Relaxed (1.5)</option>
                </select>
            </div>

            <div class="control-group">
                <label for="margin">Margins</label>
                <select id="margin" onchange="updatePreview()">
                    <option value="0.5in">Narrow (0.5 in)</option>
                    <option value="1.0in">Standard (1.0 in)</option>
                </select>
            </div>

            <div class="control-group">
                <label>
                    <input type="checkbox" id="duplexSafety" onchange="updatePreview()" checked>
                    Duplex / Double-Sided Alignment Safety
                </label>
            </div>

            <button class="btn btn-primary" style="width: 100%; margin-top: 15px;" onclick="printPacket()">Print / Export PDF</button>
        </div>

        <!-- Live Preview Pane -->
        <div class="preview-panel">
            <iframe id="printPreview" src="about:blank"></iframe>
        </div>
    </div>

    <script>
        function updatePreview() {
            const params = new URLSearchParams({
                action: 'preview',
                preset_id: 1,
                emp_ids: '1,2',
                font_family: document.getElementById('fontFamily').value,
                font_size: document.getElementById('fontSize').value + 'pt',
                line_height: document.getElementById('lineHeight').value,
                margin: document.getElementById('margin').value,
                duplex: document.getElementById('duplexSafety').checked ? 1 : 0
            });

            document.getElementById('printPreview').src = 'generate_packet.php?' + params.toString();
        }

        function printPacket() {
            const iframe = document.getElementById('printPreview');
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }

        // Initial load
        document.addEventListener('DOMContentLoaded', updatePreview);
    </script>

</body>

</html>