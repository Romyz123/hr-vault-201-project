<?php
// ======================================================
// [FILE] public/download_assets.php
// [PURPOSE] Auto-download missing CSS/JS for Offline Mode
// ======================================================

// Increase time limit for slow connections
set_time_limit(300);

// Basic access control - restrict to authenticated admins or CLI
if (php_sapi_name() !== 'cli') {
    session_start();
    // Check against the project's standard session variables
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        die('Access denied. Admin authentication required.');
    }
}

$assetsDir = __DIR__ . '/assets';
$iconsDir  = $assetsDir . '/icons';
$fontsDir  = $iconsDir . '/fonts';

// 1. Create Directories
if (!is_dir($assetsDir)) {
    if (!mkdir($assetsDir, 0755, true)) {
        $lastError = error_get_last();
        error_log('Failed to create assets directory: ' . ($lastError['message'] ?? 'Unknown error'));
        die('Error: Could not create assets directory. Check permissions.');
    }
}
if (!is_dir($iconsDir)) {
    if (!mkdir($iconsDir, 0755, true)) {
        $lastError = error_get_last();
        error_log('Failed to create icons directory: ' . ($lastError['message'] ?? 'Unknown error'));
        die('Error: Could not create icons directory. Check permissions.');
    }
}
if (!is_dir($fontsDir)) {
    if (!mkdir($fontsDir, 0755, true)) {
        $lastError = error_get_last();
        error_log('Failed to create fonts directory: ' . ($lastError['message'] ?? 'Unknown error'));
        die('Error: Could not create fonts directory. Check permissions.');
    }
}

// 2. List of Files to Download
$files = [
    // Target File Path => Source URL

    // Bootstrap CSS
    'assets/bootstrap.min.css' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',

    // Bootstrap JS
    'assets/bootstrap.bundle.min.js' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',

    // SweetAlert2
    'assets/sweetalert2.all.min.js' => 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js',

    // Chart.js
    'assets/chart.min.js' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',

    // Bootstrap Icons CSS
    'assets/icons/bootstrap-icons.css' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',

    // Bootstrap Icons Fonts (Required for icons to show)
    'assets/icons/fonts/bootstrap-icons.woff' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
    'assets/icons/fonts/bootstrap-icons.woff2' => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Asset Downloader</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            line-height: 1.5;
        }

        .success {
            color: green;
        }

        .error {
            color: red;
        }
    </style>
</head>

<body>
    <h2>📦 Downloading Assets...</h2>
    <ul>
        <?php
        $allSuccess = true;
        foreach ($files as $localPath => $url) {
            $target = __DIR__ . '/' . $localPath;

            echo "<li>Fetching <strong>$localPath</strong>... ";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'ignore_errors' => false,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $content = @file_get_contents($url, false, $context);

            if ($content !== false) {
                // Validate HTTP response headers and content
                $responseValid = true;
                
                // Check for error pages (HTML error responses instead of CSS/JS)
                if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
                    // Likely an HTML error page from CDN
                    if (stripos($url, '.css') === false && stripos($url, '.js') === false && stripos($url, '.woff') === false) {
                        $responseValid = false;
                    }
                }
                
                // Check that content is not empty
                if (empty($content)) {
                    $responseValid = false;
                }
                
                // Additional header validation via stream_get_meta_data
                $metadata = stream_get_meta_data($context);
                if (isset($metadata['wrapper_data'])) {
                    $httpCode = 200;
                    foreach ($metadata['wrapper_data'] as $header) {
                        if (preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $header, $matches)) {
                            $httpCode = (int)$matches[1];
                            break;
                        }
                    }
                    if ($httpCode !== 200) {
                        $responseValid = false;
                    }
                }
                
                if (!$responseValid) {
                    echo "<span class='error'>❌ Invalid Response (possibly CDN error)</span>";
                    $allSuccess = false;
                } elseif (file_put_contents($target, $content)) {
                    echo "<span class='success'>✅ OK</span>";
                } else {
                    echo "<span class='error'>❌ Write Failed (Permission?)</span>";
                    $allSuccess = false;
                }
            } else {
                echo "<span class='error'>❌ Download Failed (Network Blocked?)</span>";
                $allSuccess = false;
            }
            echo "</li>";
        }
        ?>
    </ul>

    <?php if ($allSuccess): ?>
        <h3 class="success">🎉 All files downloaded successfully!</h3>
        <p>Your system is now ready for Offline Mode.</p>
        <a href="test_system.php">Run System Health Check</a>
    <?php else: ?>
        <h3 class="error">⚠️ Some files failed to download.</h3>
        <p>This usually happens if your office network blocks "cdn.jsdelivr.net".</p>
        <p><strong>Manual Fix:</strong> You must download the missing files on a different computer (like at home) and copy them to the <code>public/assets</code> folder using a USB drive.</p>
    <?php endif; ?>
</body>

</html>