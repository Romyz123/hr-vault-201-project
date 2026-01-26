<?php
// ======================================================
// [FILE] public/disciplinary.php
// [STATUS] FINAL: Standard UUIDs + Auto-Repair Logic
// ======================================================

require '../config/db.php';
require '../src/Logger.php';
require '../src/Security.php';
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    $_SESSION['error'] = "Access Denied.";
    header("Location: index.php");
    exit;
}

$logger = new Logger($pdo);
$alertType = "";
$alertMsg = "";

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_case') {
    $emp_id     = $_POST['employee_id'];
    $type       = $_POST['violation_type'];
    $date       = $_POST['incident_date'];
    $action     = $_POST['action_taken'];
    $desc       = trim($_POST['description']);
    
    $dbFilePath = null; 
    $syncStatus = "Skipped (No File)";
    $isValid    = true; // Flag to control execution

    // --- FILE UPLOAD LOGIC ---
    if (!empty($_FILES['attachment']['name'])) {
        
        $targetDir = "uploads/"; 
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true); // [FIX] Ensure folder exists
        $originalName = basename($_FILES['attachment']['name']);

        // [SECURITY] Validate File Type (Allow only PDF & Images)
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $alertType = 'error';
            $alertMsg = "❌ <strong>Upload Failed:</strong> Only PDF, JPG, or PNG files are allowed.";
            $isValid = false; // Stop the process
        }
        
        // Clean filename to prevent issues
        $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
        $fileName  = "DISCIPLINARY_" . time() . "_" . $cleanName;
        $targetFile = $targetDir . $fileName;
        
        if ($isValid && move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) {
            $dbFilePath = $fileName;

         // [FIX] STANDARD UUID GENERATION (MD5)
          $file_uuid = md5(uniqid(rand(), true));

            try {
                // Check columns to prevent crash
                $cols = $pdo->query("SHOW COLUMNS FROM documents LIKE 'uploaded_by'")->fetchAll();
                $hasUploader = count($cols) > 0;
                
                $cols2 = $pdo->query("SHOW COLUMNS FROM documents LIKE 'file_uuid'")->fetchAll();
                $hasUUID = count($cols2) > 0;

                // INSERT
                if ($hasUploader && $hasUUID) {
                    $docStmt = $pdo->prepare("INSERT INTO documents (employee_id, original_name, file_path, category, uploaded_by, file_uuid) VALUES (?, ?, ?, 'Disciplinary', ?, ?)");
                    $docStmt->execute([$emp_id, $originalName, $dbFilePath, $_SESSION['user_id'], $file_uuid]);
                } elseif ($hasUploader) {
                    // Fallback
                    $docStmt = $pdo->prepare("INSERT INTO documents (employee_id, original_name, file_path, category, uploaded_by) VALUES (?, ?, ?, 'Disciplinary', ?)");
                    $docStmt->execute([$emp_id, $originalName, $dbFilePath, $_SESSION['user_id']]);
                } else {
                    // Old Fallback
                    $docStmt = $pdo->prepare("INSERT INTO documents (employee_id, original_name, file_path, category) VALUES (?, ?, ?, 'Disciplinary')");
                    $docStmt->execute([$emp_id, $originalName, $dbFilePath]);
                }
                $syncStatus = "✅ SUCCESS (Synced to Documents)";
            } catch (PDOException $e) {
                $syncStatus = "❌ FAILED: " . $e->getMessage();
            }
        } else {
            $syncStatus = "❌ FAILED (File Permission Error)";
        }
    }

    // INSERT CASE RECORD
    if ($isValid) {
    try {
        $stmt = $pdo->prepare("INSERT INTO disciplinary_cases (employee_id, violation_type, incident_date, action_taken, description, attachment_path) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$emp_id, $type, $date, $action, $desc, $dbFilePath])) {
            $logger->log($_SESSION['user_id'], 'CASE_ADD', "Filed case: $type");
            $alertType = 'success';
            $alertMsg = "<strong>Case Filed Successfully!</strong><br>File Sync Status: <b>$syncStatus</b>";
        }
    } catch (PDOException $e) {
        $alertType = 'error';
        $alertMsg = "Database Error: " . $e->getMessage();
    }
    }
}

// 3. CLOSE CASE
if (isset($_GET['close_id'])) {
    $pdo->prepare("UPDATE disciplinary_cases SET status = 'Closed' WHERE id = ?")->execute([$_GET['close_id']]);
    header("Location: disciplinary.php");
    exit;
}

// 4. DELETE CASE (Cleanup)
if (isset($_GET['delete_id'])) {
    $delId = $_GET['delete_id'];
    
    // Fetch info to delete file
    $stmt = $pdo->prepare("SELECT attachment_path FROM disciplinary_cases WHERE id = ?");
    $stmt->execute([$delId]);
    $case = $stmt->fetch();

    if ($case) {
        // A. Delete Physical File
        if (!empty($case['attachment_path'])) {
            $filePath = __DIR__ . "/uploads/" . $case['attachment_path'];
            if (file_exists($filePath)) unlink($filePath);
            
            // B. Remove from Documents Sync (if it exists there)
            $pdo->prepare("DELETE FROM documents WHERE file_path = ? AND category = 'Disciplinary'")->execute([$case['attachment_path']]);
        }

        // C. Delete Record
        $pdo->prepare("DELETE FROM disciplinary_cases WHERE id = ?")->execute([$delId]);
        
        header("Location: disciplinary.php?msg=Deleted Successfully");
        exit;
    }
}

// 4. FETCH DATA
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
// [SECURITY] Limit & Sanitize Search
if (strlen($search) > 50) $search = substr($search, 0, 50);
$search = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $search);

$sql = "SELECT d.*, e.first_name, e.last_name, e.dept FROM disciplinary_cases d JOIN employees e ON d.employee_id = e.emp_id WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (e.last_name LIKE ? OR d.violation_type LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term];
}
$sql .= " ORDER BY d.incident_date DESC";
$cases = $pdo->prepare($sql);
$cases->execute($params);
$cases = $cases->fetchAll(PDO::FETCH_ASSOC);

$emps = $pdo->query("SELECT emp_id, last_name, first_name FROM employees ORDER BY last_name ASC")->fetchAll();

// Capture Success Message
if (isset($_GET['msg'])) {
    $alertType = 'success';
    $alertMsg = htmlspecialchars($_GET['msg']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disciplinary Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .status-Open { background-color: #ffeeba; color: #856404; }
        .status-Closed { background-color: #d4edda; color: #155724; }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container-fluid px-4">
    <a class="navbar-brand" href="index.php">⬅ Back</a>
    <span class="navbar-text text-white">Disciplinary Console</span>
  </div>
</nav>

<div class="container">
    <div class="row mb-4">
        <div class="col-md-12 text-end">
            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addCaseModal">
                <i class="bi bi-file-earmark-medical"></i> File New Case
            </button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Violation</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cases as $c): ?>
                    <tr>
                        <td><?php echo date('M d', strtotime($c['incident_date'])); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($c['last_name']); ?></strong>, <?php echo htmlspecialchars($c['first_name']); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($c['dept']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($c['violation_type']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo $c['action_taken']; ?></span></td>
                        <td><span class="badge status-<?php echo $c['status']; ?>"><?php echo $c['status']; ?></span></td>
                        <td>
                            <?php if ($c['attachment_path']): ?>
                                <a href="uploads/<?php echo $c['attachment_path']; ?>" target="_blank" class="btn btn-sm btn-primary">View PDF</a>
                            <?php else: ?> - <?php endif; ?>
                            <?php if ($c['status'] == 'Open'): ?>
                                <a href="disciplinary.php?close_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-success">Close</a>
                            <?php endif; ?>
                            <a href="disciplinary.php?delete_id=<?php echo $c['id']; ?>" 
                               class="btn btn-sm btn-outline-danger ms-1" 
                               onclick="return confirm('Permanently delete this case and file?');">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addCaseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">File Case</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_case">
                    <div class="mb-3">
                        <label>Select Employee</label>
                        <select name="employee_id" class="form-select" required>
                            <?php foreach ($emps as $e): ?>
                                <option value="<?php echo $e['emp_id']; ?>">
                                    <?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Violation</label>
                        <input type="text" name="violation_type" class="form-control" required placeholder="Tardiness">
                    </div>
                    <div class="mb-3">
                        <label>Date</label>
                        <input type="date" name="incident_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label>Action</label>
                        <select name="action_taken" class="form-select">
                            <option>Pending</option>
                            <option>Written Warning</option>
                            <option>Suspension</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>
                    <div class="mb-3 border p-2 bg-warning bg-opacity-10">
                        <label class="fw-bold">Attach Evidence</label>
                        <input type="file" name="attachment" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if ($alertMsg): ?>
Swal.fire({
    icon: '<?php echo $alertType; ?>',
    html: <?php echo json_encode($alertMsg); ?>
});
<?php endif; ?>
</script>
</body>
</html>