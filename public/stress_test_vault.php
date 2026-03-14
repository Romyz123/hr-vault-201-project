                  <?php
                    // ======================================================
                    // [FILE] public/stress_test_vault.php
                    // [PURPOSE] Inject 1,000 dummy PDFs into the Vault to test encryption & disk speed
                    // ======================================================

                    require '../config/db.php';
                    require '../src/FileService.php';
                    session_start();

                    // 1. SECURITY: Admin Only
                    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
                        die("Access Denied: Admin privileges required.");
                    }

                    echo "<h3>Vault Volume & Encryption Stress Test</h3>";

                    $config = require '../config/config.php';
                    $vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');

                    if (!$vaultPath || !is_dir($vaultPath)) {
                        die("<p style='color:red;'>❌ Vault directory not found. Please ensure it exists.</p>");
                    }

                    $fileService = new FileService($vaultPath);
                    $records_to_insert = 1000;
                    $emp_id = "STRESS-999";

                    echo "<p>Starting injection of " . number_format($records_to_insert) . " encrypted PDFs...</p>";
                    ob_flush();
                    flush(); // Force output to browser immediately

                    $start_time = microtime(true);

                    // 2. Ensure Dummy Employee Exists
                    $chk = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
                    $chk->execute([$emp_id]);
                    if (!$chk->fetchColumn()) {
                        $pdo->prepare("INSERT INTO employees (emp_id, first_name, last_name, status, dept, job_title) VALUES (?, 'Stress', 'Test', 'Active', 'IT', 'Tester')")->execute([$emp_id]);
                    }

                    // A minimal valid PDF structure that passes standard MIME checks
                    $minimalPdf = "%PDF-1.4\n%StressTest\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000015 00000 n\n0000000060 00000 n\n0000000111 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%EOF";

                    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dummy_stress.pdf';

                    $successCount = 0;
                    $failCount = 0;

                    $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, description, uploaded_by, uploaded_at) VALUES (UUID(), ?, ?, ?, 'Others', 'Stress Test Payload', ?, NOW())";
                    $stmt = $pdo->prepare($sql);

                    $pdo->beginTransaction(); // Speed up DB inserts

                    for ($i = 1; $i <= $records_to_insert; $i++) {
                        // Re-create the tmp file every loop because FileService->saveFile() unlinks it after saving!
                        file_put_contents($tmpFile, $minimalPdf);

                        $originalName = "Stress_Doc_" . str_pad($i, 4, '0', STR_PAD_LEFT) . ".pdf";

                        // This triggers AES-256 encryption & writes to disk
                        $storedName = $fileService->saveFile($tmpFile, $originalName);

                        if ($storedName) {
                            $stmt->execute([$emp_id, $originalName, $storedName, $_SESSION['user_id']]);
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                    }

                    $pdo->commit();
                    @unlink($tmpFile); // Cleanup

                    $end_time = microtime(true);
                    $time_taken = round($end_time - $start_time, 2);

                    echo "<p style='color:green; font-weight:bold;'>✅ Successfully generated, encrypted, and linked $successCount PDFs in {$time_taken} seconds.</p>";
                    if ($failCount > 0) echo "<p style='color:red;'>❌ Failed to process $failCount files.</p>";
                    echo "<p><strong>Cleanup Instructions:</strong> Go to the Dashboard, search for <strong>$emp_id</strong>, and delete the employee. Then go to <em>System Recovery -> Deleted Employees</em> and permanently delete the record to instantly sweep all 1,000 files off the disk.</p>";
