<?php
// public/test_vault.php
// [PURPOSE] Test the FileService obfuscation logic directly

require '../src/FileService.php';

// 1. Setup Test Environment
$vaultDir = __DIR__ . '/../vault_test/';
if (!is_dir($vaultDir)) mkdir($vaultDir, 0755, true);

$service = new FileService($vaultDir);

// Create a dummy file to "upload"
$dummyFile = sys_get_temp_dir() . '/test_upload_' . time() . '.txt';
file_put_contents($dummyFile, "This is a secure test file content.");

$originalName = "Confidential_Report.txt";

echo "<h2>🔐 Vault Security Test</h2>";
echo "<pre>";

// 2. Run Save
echo "Attempting to save '$originalName'...\n";
$storedName = $service->saveFile($dummyFile, $originalName);

if ($storedName) {
    echo "✅ Success! File saved.\n";
    echo "   Original Name: $originalName\n";
    echo "   Stored Name:   $storedName\n";

    // 3. Verify Obfuscation
    if ($storedName !== $originalName) {
        echo "✅ Obfuscation Verified: Filename was randomized.\n";
    } else {
        echo "❌ FAILED: Filename was NOT randomized.\n";
    }

    // 4. Verify Physical File
    if (file_exists($vaultDir . $storedName)) {
        echo "✅ Physical File Exists in Vault.\n";

        // Verify Encryption
        $rawContent = file_get_contents($vaultDir . $storedName);
        if (strpos($rawContent, "This is a secure test file content.") === false) {
            echo "✅ Encryption Verified: Content is scrambled on disk.\n";
        }
    } else {
        echo "❌ FAILED: File not found on disk.\n";
    }

    // 5. Verify Manifest
    $manifest = $vaultDir . 'manifest_DO_NOT_DELETE.txt';
    if (file_exists($manifest)) {
        $lines = $service->readManifest();
        if (!empty($lines) && strpos(end($lines), $storedName) !== false) {
            echo "✅ Manifest Log Updated Correctly.\n";
        } else {
            echo "❌ FAILED: Manifest missing entry.\n";
        }
    } else {
        echo "❌ FAILED: Manifest file not created.\n";
    }

    // Cleanup
    unlink($vaultDir . $storedName);
    // unlink($manifest); // Keep manifest to inspect if needed
    // rmdir($vaultDir);
} else {
    echo "❌ Error: saveFile() returned false.\n";
    if (file_exists($dummyFile)) unlink($dummyFile);
}
echo "</pre>";
