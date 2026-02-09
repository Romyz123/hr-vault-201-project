<?php
// ======================================================
// [FILE] public/system_diagnostics.php
// [PURPOSE] Integrated System Health Check (Admin Only)
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/EmployeeService.php';
session_start();

// 1. SECURITY: Admin Only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

// Mock Classes for Logic Tests (Isolated from Real DB)
class MockPDO
{
    public function prepare($sql)
    {
        return new MockStmt();
    }
    public function lastInsertId()
    {
        return 1;
    }
}
class MockStmt
{
    public function execute($params = [])
    {
        return true;
    }
    public function fetch()
    {
        return false;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Diagnostics</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-activity"></i> System Diagnostics</span>
        </div>
    </nav>

    <div class="container">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="bi bi-terminal"></i> Diagnostic Report
            </div>
            <div class="card-body bg-dark text-light font-monospace p-4">
                <?php
                // Handle Test Email Action
                if (isset($_POST['action']) && $_POST['action'] === 'test_email') {
                    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $userEmail = $stmt->fetchColumn();

                    if ($userEmail) {
                        if (@mail($userEmail, "Test Email from HR System", "This is a test email to verify PHP mail() configuration.")) {
                            echo "<div class='alert alert-success mb-3'>✅ Test email sent to $userEmail. Check your inbox.</div>";
                        } else {
                            $err = error_get_last()['message'] ?? 'Unknown error';
                            echo "<div class='alert alert-danger mb-3'>❌ Failed to send email. Error: $err</div>";
                        }
                    } else {
                        echo "<div class='alert alert-warning mb-3'>⚠️ Your account has no email address.</div>";
                    }
                }
                ?>
                <?php
                echo "<h5 class='text-info'>[Logic Tests]</h5>";

                // TEST 1
                echo "Test 1: Security Input Sanitization... ";
                $sec = new Security(new MockPDO());
                $input = ['name' => '<b>Bold</b>'];
                $clean = $sec->sanitizeInput($input);
                if ($clean['name'] === '&lt;b&gt;Bold&lt;/b&gt;') echo "<span class='text-success'>✅ PASSED</span><br>";
                else echo "<span class='text-danger'>❌ FAILED</span><br>";

                // TEST 2
                echo "Test 2: Employee Validation (Missing Fields)... ";
                $svc = new EmployeeService(new MockPDO());
                $errors = $svc->validate(['emp_id' => '', 'first_name' => 'John']);
                if (in_array("Employee ID is required.", $errors)) echo "<span class='text-success'>✅ PASSED</span><br>";
                else echo "<span class='text-danger'>❌ FAILED</span><br>";

                // TEST 3
                echo "Test 3: Employee Validation (Invalid Email)... ";
                $errors = $svc->validate(['emp_id' => '123', 'first_name' => 'John', 'last_name' => 'Doe', 'job_title' => 'Dev', 'email' => 'bad-email']);
                if (in_array("Invalid email format.", $errors)) echo "<span class='text-success'>✅ PASSED</span><br>";
                else echo "<span class='text-danger'>❌ FAILED</span><br>";

                // TEST 4
                echo "Test 4: Logger Execution... ";
                $logger = new Logger(new MockPDO());
                try {
                    $logger->log(1, 'TEST_ACTION', 'Test Details');
                    echo "<span class='text-success'>✅ PASSED</span><br>";
                } catch (Exception $e) {
                    echo "<span class='text-danger'>❌ FAILED: " . $e->getMessage() . "</span><br>";
                }

                // TEST 5
                echo "Test 5: CSRF Token Generation... ";
                // Note: We don't clear session here to avoid logging out the admin
                $token = $sec->generateCSRF();
                if (!empty($token)) echo "<span class='text-success'>✅ PASSED</span><br>";
                else echo "<span class='text-danger'>❌ FAILED</span><br>";

                // TEST 6
                echo "Test 6: Employee Creation Logic... ";
                $svc = new EmployeeService(new MockPDO(), new Logger(new MockPDO()));
                $empData = [
                    'emp_id' => 'TEST-001',
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'job_title' => 'Tester',
                    'request_note' => 'Should be removed'
                ];
                try {
                    $id = $svc->create($empData, 1);
                    if ($id === 1) echo "<span class='text-success'>✅ PASSED</span><br>";
                    else echo "<span class='text-danger'>❌ FAILED (ID mismatch)</span><br>";
                } catch (Exception $e) {
                    echo "<span class='text-danger'>❌ FAILED: " . $e->getMessage() . "</span><br>";
                }

                echo "<br><h5 class='text-info'>[Environment Checks]</h5>";

                // TEST 7
                echo "Test 7: Real Database Connection... ";
                if (isset($pdo)) echo "<span class='text-success'>✅ PASSED</span><br>";
                else echo "<span class='text-danger'>❌ FAILED</span><br>";

                // TEST 8
                echo "Test 8: Vault Directory Writable... ";
                $vaultPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault';
                if (is_dir($vaultPath) && is_writable($vaultPath)) echo "<span class='text-success'>✅ PASSED</span><br>";
                else echo "<span class='text-danger'>❌ FAILED (Check permissions for /vault)</span><br>";

                // TEST 8.5
                echo "Test 8.5: Vault Security (.htaccess)... ";
                $htaccess = $vaultPath . '/.htaccess';
                if (file_exists($htaccess) && strpos(file_get_contents($htaccess), 'Deny from all') !== false) {
                    echo "<span class='text-success'>✅ PASSED</span><br>";
                } else {
                    echo "<span class='text-danger'>❌ FAILED (Vault is exposed! Missing .htaccess)</span><br>";
                }

                // TEST 8.6
                echo "Test 8.6: Vault Manifest... ";
                if (file_exists($vaultPath . '/manifest_DO_NOT_DELETE.txt')) {
                    echo "<span class='text-success'>✅ PASSED (Found)</span><br>";
                } else {
                    echo "<span class='text-warning'>⚠️ PENDING (Will be created on first upload)</span><br>";
                }

                // TEST 9
                echo "Test 9: Mail Configuration... ";
                if (function_exists('mail')) {
                    $smtp = ini_get('SMTP');
                    $port = ini_get('smtp_port');
                    $sendmail = ini_get('sendmail_path');

                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        if ($smtp && $port) echo "<span class='text-success'>✅ PASSED (SMTP: $smtp:$port)</span><br>";
                        else echo "<span class='text-warning'>⚠️ WARNING (SMTP not configured in php.ini)</span><br>";
                    } else {
                        if ($sendmail) echo "<span class='text-success'>✅ PASSED (Sendmail: $sendmail)</span><br>";
                        else echo "<span class='text-warning'>⚠️ WARNING (sendmail_path not set)</span><br>";
                    }
                } else {
                    echo "<span class='text-danger'>❌ FAILED (mail() function disabled)</span><br>";
                }

                echo "<hr style='border-color: #555;'>";
                echo "<span class='text-success fw-bold'>DIAGNOSTICS COMPLETE</span>";
                ?>
                <hr class="border-secondary">
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="test_email">
                    <button type="submit" class="btn btn-sm btn-outline-light"><i class="bi bi-envelope"></i> Send Test Email to Me</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>