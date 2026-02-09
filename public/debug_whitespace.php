<?php
// public/debug_whitespace.php
// [PURPOSE] Detect "Invisible Trash" (spaces/newlines) leaking from included files

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Start capturing output immediately
ob_start();

// 2. Include the core files used by view_doc.php
require '../config/db.php';
require '../src/Security.php';
require '../src/FileService.php';
$config = require '../config/config.php';

// 3. Stop capturing and analyze
$content = ob_get_contents();
ob_end_clean();

echo "<h1>🔍 Invisible Trash Detector</h1>";

if (strlen($content) > 0) {
    echo "<h2 style='color:red'>⚠️ DETECTED " . strlen($content) . " BYTES OF TRASH</h2>";
    echo "<p>These characters are corrupting your downloads:</p>";
    echo "<div style='background:#eee; border:1px solid #ccc; padding:10px; font-family:monospace;'>";
    echo "<strong>Raw Content (between brackets):</strong> [" . htmlspecialchars($content) . "]<br>";
    echo "<strong>Hex Dump:</strong> " . bin2hex($content);
    echo "</div>";
    echo "<p><strong>Fix:</strong> Check the files listed in this script. Remove any spaces/newlines before <code>&lt;?php</code> or after <code>?&gt;</code>.</p>";
} else {
    echo "<h2 style='color:green'>✅ CLEAN! No Trash Detected.</h2>";
    echo "<p>Your included files are clean. The <code>ob_end_clean()</code> we added to <code>view_doc.php</code> is acting as a safety net.</p>";
}
