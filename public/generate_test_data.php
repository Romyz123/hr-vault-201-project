<?php
// ======================================================
// [FILE] public/generate_test_data.php
// [PURPOSE] Developer Tool to generate realistic dummy records
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/FileService.php';
session_start();

// 1. SECURITY: Admin Only (Strict Guard)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    die("Access Denied: Admin privileges required.");
}

$security = new Security($pdo);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = "";
$error = "";

// Realistic Dummy Data Banks
$firstNames = ['Juan', 'Maria', 'Jose', 'Pedro', 'Ana', 'Luis', 'Carlos', 'Miguel', 'Rosa', 'John', 'Jane', 'Mark', 'Paul', 'Diana', 'Chris'];
$lastNames = ['Dela Cruz', 'Santos', 'Reyes', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Aquino', 'Cruz', 'Navarro', 'Ramos', 'Perez', 'Flores'];
$depts = ['ADMIN', 'SQP', 'SIGCOM', 'LMS', 'HMS', 'DOS', 'CTS', 'PSS', 'OCS', 'GUNJIN'];
$jobs = ['Technician', 'Engineer', 'Staff', 'Supervisor', 'Manager', 'Officer', 'Driver', 'Inspector', 'Clerk', 'Foreman'];
$agencies = ['TESP DIRECT', 'GUNJIN', 'JORATECH', 'UNLISOLUTIONS', 'OTHERS - SUBCONS'];

// Generate Employees
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_emp'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch.";
    } else {
        $count = (int)$_POST['count'];
        if ($count > 0 && $count <= 500) {
            // NEW: Include FileService
            $config = require '../config/config.php';
            $vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');
            $fileService = new FileService($vaultPath);

            // NEW: Dummy doc data
            $docTypes = [
                'Employment Contract.pdf' => 'Contract',
                'SSS_ID.jpg' => 'Government IDs',
                'TIN_ID.jpg' => 'Government IDs',
                'Resume_CV.pdf' => '201 Files',
                'Medical_Certificate.pdf' => 'Medical',
                'NDA_Agreement.pdf' => 'Contract',
                'Birth_Certificate.pdf' => '201 Files'
            ];
            $docKeys = array_keys($docTypes);

            $statuses = ['Active', 'Active', 'Active', 'Active', 'Active', 'Resigned', 'Terminated', 'AWOL']; // Weighted towards Active
            $stmt = $pdo->prepare("INSERT INTO employees (emp_id, first_name, middle_name, last_name, dept, section, job_title, employment_type, agency_name, status, gender, hire_date, birth_date, contact_number, email, present_address, system_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            // NEW: Doc statement
            $docStmt = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by, uploaded_at) VALUES (UUID(), ?, ?, ?, ?, ?, NOW())");

            $pdo->beginTransaction();
            for ($i = 0; $i < $count; $i++) {
                $fName = $firstNames[array_rand($firstNames)];
                $lName = $lastNames[array_rand($lastNames)];
                $mName = substr($lastNames[array_rand($lastNames)], 0, 1) . '.';
                $empId = 'TST-' . date('y') . '-' . rand(1000, 9999) . $i;
                $dept = $depts[array_rand($depts)];
                $job = $jobs[array_rand($jobs)];
                $agency = $agencies[array_rand($agencies)];
                $empType = ($agency === 'TESP DIRECT') ? 'TESP Direct' : 'Agency';
                $status = $statuses[array_rand($statuses)];
                $gender = (rand(0, 1) == 0) ? 'Male' : 'Female';
                $contact = '09' . rand(100000000, 999999999);
                $email = strtolower($fName . '.' . $lName . rand(10, 99)) . '@test.com';
                $address = '123 Main St, Metro Manila';
                $sysRole = 'Staff';

                $hireTs = time() - rand(0, 5 * 365 * 86400); // Hired within last 5 years
                $birthTs = time() - rand(20 * 365 * 86400, 50 * 365 * 86400); // Born 20-50 years ago

                $stmt->execute([
                    $empId,
                    $fName,
                    $mName,
                    $lName,
                    $dept,
                    'General',
                    $job,
                    $empType,
                    ($agency === 'TESP DIRECT' ? 'TESP' : $agency),
                    $status,
                    $gender,
                    date('Y-m-d', $hireTs),
                    date('Y-m-d', $birthTs),
                    $contact,
                    $email,
                    $address,
                    $sysRole
                ]);

                // NEW: Generate 2 to 5 documents for this employee
                $numDocs = rand(2, 5);
                for ($j = 0; $j < $numDocs; $j++) {
                    $originalName = $docKeys[array_rand($docKeys)];
                    $category = $docTypes[$originalName];

                    // Create a dummy file to be encrypted
                    $dummyContent = "This is a test file for {$empId} named {$originalName}.";
                    $tmpFile = tempnam(sys_get_temp_dir(), 'test_doc');
                    file_put_contents($tmpFile, $dummyContent);

                    // Save to vault
                    $storedName = $fileService->saveFile($tmpFile, $originalName);

                    // Insert DB record
                    if ($storedName) {
                        $docStmt->execute([$empId, $originalName, $storedName, $category, $_SESSION['user_id']]);
                    }
                    unlink($tmpFile);
                }
            }
            $pdo->commit();
            $msg = "✅ Successfully generated $count random employees with documents!";
        } else {
            $error = "Count must be between 1 and 500.";
        }
    }
}

// Generate Candidates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_cand'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch.";
    } else {
        $count = (int)$_POST['count'];
        if ($count > 0 && $count <= 500) {
            $cStatuses = ['New Applicant', 'Screening', 'Interviewed', 'Hired', 'Rejected'];
            $stmt = $pdo->prepare("INSERT INTO candidates (first_name, last_name, position_applied, email, phone_number, application_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)");

            $pdo->beginTransaction();
            for ($i = 0; $i < $count; $i++) {
                $fName = $firstNames[array_rand($firstNames)];
                $lName = $lastNames[array_rand($lastNames)];
                $job = $jobs[array_rand($jobs)];
                $cStatus = $cStatuses[array_rand($cStatuses)];
                $appTs = time() - rand(0, 180 * 86400); // Applied within last 6 months

                $stmt->execute([
                    $fName,
                    $lName,
                    $job,
                    strtolower($fName) . rand(10, 99) . '@test.com',
                    '09' . rand(10000000, 99999999),
                    date('Y-m-d', $appTs),
                    $cStatus
                ]);
            }
            $pdo->commit();
            $msg = "✅ Successfully generated $count random candidates for the Recruitment ATS!";
        } else {
            $error = "Count must be between 1 and 500.";
        }
    }
}

// Clear Test Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_data'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch.";
    } else {
        try {
            $pdo->beginTransaction();

            // NEW: Cleanup documents for test employees
            $config = require '../config/config.php';
            $vaultPath = $config['VAULT_PATH'] ?? realpath(__DIR__ . '/../vault');

            // 1. Get all test employee IDs
            $testEmpIds = $pdo->query("SELECT emp_id FROM employees WHERE emp_id LIKE 'TST-%'")->fetchAll(PDO::FETCH_COLUMN);
            $docsDeleted = 0;

            if (!empty($testEmpIds)) {
                // 2. Get all file paths for these employees
                $placeholders = implode(',', array_fill(0, count($testEmpIds), '?'));
                $docStmt = $pdo->prepare("SELECT file_path FROM documents WHERE employee_id IN ($placeholders)");
                $docStmt->execute($testEmpIds);
                $filesToDelete = $docStmt->fetchAll(PDO::FETCH_COLUMN);

                // 3. Delete physical files from vault
                foreach ($filesToDelete as $file) {
                    $fullPath = $vaultPath . DIRECTORY_SEPARATOR . basename($file); // basename for security
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }

                // 4. Delete document records from DB
                $delDocStmt = $pdo->prepare("DELETE FROM documents WHERE employee_id IN ($placeholders)");
                $delDocStmt->execute($testEmpIds);
                $docsDeleted = $delDocStmt->rowCount();
            }

            // Delete Test Employees
            $stmtEmp = $pdo->prepare("DELETE FROM employees WHERE emp_id LIKE 'TST-%'");
            $stmtEmp->execute();
            $empDeleted = $stmtEmp->rowCount();
            // Delete Test Candidates
            $stmtCand = $pdo->prepare("DELETE FROM candidates WHERE email LIKE '%@test.com'");
            $stmtCand->execute();
            $candDeleted = $stmtCand->rowCount();

            $pdo->commit();
            $msg = "🧹 Successfully cleared $empDeleted test employees, $docsDeleted documents, and $candDeleted test candidates!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to clear data: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Test Data Generator</title>
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-danger mb-4 shadow">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-cone-striped"></i> Test Data Generator</span>
            </div>
        </div>
    </nav>

    <div class="container" style="max-width: 800px;">
        <?php if ($msg): ?><div class="alert alert-success shadow-sm fw-bold"><?php echo $msg; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger shadow-sm fw-bold"><?php echo $error; ?></div><?php endif; ?>

        <div class="alert alert-warning shadow-sm border-warning border-3 mb-4">
            <h5 class="alert-heading fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> Developer Tool</h5>
            <p class="mb-0">This tool instantly populates the database with realistic dummy records so you can test the Analytics dashboard, bulk updates, and paginations without manually typing in data.</p>
        </div>

        <div class="row">
            <!-- Employee Generator -->
            <div class="col-md-6 mb-4">
                <form method="POST" class="card shadow-sm h-100 border-primary">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="card-header bg-primary text-white fw-bold"><i class="bi bi-people-fill"></i> Employee Directory</div>
                    <div class="card-body">
                        <p class="small text-muted">Generates employees with random ages, departments, agencies, and hire dates to populate the Workforce Analytics charts.</p>
                        <label class="form-label fw-bold">Number to Generate (Max 500)</label>
                        <input type="number" name="count" class="form-control mb-3" value="50" min="1" max="500" required>
                        <button type="submit" name="generate_emp" class="btn btn-primary w-100 fw-bold"><i class="bi bi-lightning-charge-fill"></i> Generate Employees</button>
                    </div>
                </form>
            </div>

            <!-- Recruitment ATS Generator -->
            <div class="col-md-6 mb-4">
                <form method="POST" class="card shadow-sm h-100 border-success">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="card-header bg-success text-white fw-bold"><i class="bi bi-person-lines-fill"></i> Recruitment ATS</div>
                    <div class="card-body">
                        <p class="small text-muted">Generates job applicants scattered across different pipeline phases (Screening, Interviewed, Rejected) to test the doughnut charts.</p>
                        <label class="form-label fw-bold">Number to Generate (Max 500)</label>
                        <input type="number" name="count" class="form-control mb-3" value="50" min="1" max="500" required>
                        <button type="submit" name="generate_cand" class="btn btn-success w-100 fw-bold"><i class="bi bi-lightning-charge-fill"></i> Generate Candidates</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Clear Test Data -->
        <div class="card shadow-sm border-danger mt-1 mb-5">
            <div class="card-header bg-danger text-white fw-bold"><i class="bi bi-trash-fill"></i> Cleanup Test Data</div>
            <div class="card-body d-flex justify-content-between align-items-center">
                <p class="small text-muted mb-0 me-3">Finished testing? Click here to instantly delete all dummy employees (IDs starting with <code>TST-</code>) and candidates (emails ending in <code>@test.com</code>) that this tool generated.</p>
                <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete all test records?');" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" name="clear_data" class="btn btn-danger fw-bold text-nowrap"><i class="bi bi-eraser-fill"></i> Clear Test Data</button>
                </form>
            </div>
        </div>
    </div>
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
</body>

</html>