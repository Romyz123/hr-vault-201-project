<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require_once '../src/helpers.php';
session_start();
if (isset($_SESSION['user_id'])) checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userRole = strtoupper(trim($_SESSION['role'] ?? 'STAFF'));
if (!in_array($userRole, ['ADMIN', 'MANAGER', 'HR'])) {
    die("Access denied. You do not have permission to merge records.");
}

$logger = new Logger($pdo);

// Handle Merge POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_records'])) {
    $primaryId = (int)$_POST['primary_id'];
    $secondaryId = (int)$_POST['secondary_id'];

    if ($primaryId && $secondaryId && $primaryId !== $secondaryId) {
        try {
            $pdo->beginTransaction();

            // 1. Build the UPDATE query for the Primary Record based on selected radios
            $updateFields = [];
            $params = [];
            
            // Loop through POST data to find selected fields
            $allowedFields = ['emp_id', 'first_name', 'middle_name', 'last_name', 'job_title', 'system_role', 'dept', 'section', 'group_name', 'manager_id', 'employment_type', 'agency_name', 'company_name', 'previous_company', 'hire_date', 'gender', 'birth_date', 'contact_number', 'email', 'present_address', 'permanent_address', 'sss_no', 'tin_no', 'pagibig_no', 'philhealth_no', 'emergency_name', 'emergency_contact', 'emergency_address', 'education', 'experience', 'skills', 'licenses', 'college_degree', 'college_course', 'college_year'];

            foreach ($allowedFields as $field) {
                if (isset($_POST["keep_$field"])) {
                    $sourceId = (int)$_POST["keep_$field"];
                    
                    // Fetch the value from the selected source
                    $stmt = $pdo->prepare("SELECT `$field` FROM employees WHERE id = ?");
                    $stmt->execute([$sourceId]);
                    $val = $stmt->fetchColumn();
                    
                    if ($val !== false) {
                        $updateFields[] = "`$field` = ?";
                        $params[] = $val;
                    }
                }
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = NOW()";
                $sql = "UPDATE employees SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $params[] = $primaryId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            // 2. Reassign Documents
            $pdo->prepare("UPDATE documents SET employee_id = (SELECT emp_id FROM employees WHERE id = ?) WHERE employee_id = (SELECT emp_id FROM employees WHERE id = ?)")->execute([$primaryId, $secondaryId]);

            // 3. Reassign Activity Logs
            $pdo->prepare("UPDATE activity_logs SET user_id = ? WHERE user_id = ?")->execute([$primaryId, $secondaryId]);

            // 4. Soft Delete Secondary Record
            $pdo->prepare("UPDATE employees SET deleted_at = NOW(), status = 'Merged' WHERE id = ?")->execute([$secondaryId]);

            $logger->log($_SESSION['user_id'], 'MERGE_DUPLICATE', "Merged duplicate employee ID $secondaryId into $primaryId.");
            $pdo->commit();

            $_SESSION['msg'] = "Records merged successfully.";
            header("Location: merge_duplicates.php");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Merge failed: " . $e->getMessage();
        }
    } else {
        $error = "Invalid records selected for merging.";
    }
}

// Fetch all active employees
$stmt = $pdo->query("SELECT * FROM employees WHERE deleted_at IS NULL ORDER BY last_name ASC");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Detect duplicates by Full Name (case-insensitive) or emp_id
$duplicatesMap = [];
$nameMap = [];
$empIdMap = [];

foreach ($employees as $e) {
    $nameKey = strtolower(trim($e['first_name'] . '|' . $e['last_name']));
    $idKey = trim($e['emp_id']);
    
    if (!isset($nameMap[$nameKey])) $nameMap[$nameKey] = [];
    $nameMap[$nameKey][] = $e;

    if (!empty($idKey)) {
        if (!isset($empIdMap[$idKey])) $empIdMap[$idKey] = [];
        $empIdMap[$idKey][] = $e;
    }
}

// Aggregate actual duplicate sets
$duplicateSets = [];
foreach ($nameMap as $key => $group) {
    if (count($group) > 1) {
        $duplicateSets[] = [
            'type' => 'Name',
            'key' => ucwords(str_replace('|', ' ', $key)),
            'records' => $group
        ];
    }
}
foreach ($empIdMap as $key => $group) {
    if (count($group) > 1) {
        $duplicateSets[] = [
            'type' => 'Employee ID',
            'key' => $key,
            'records' => $group
        ];
    }
}

// Select a specific set to resolve if requested
$resolveType = $_GET['resolve_type'] ?? null;
$resolveKey = $_GET['resolve_key'] ?? null;

$activeSet = null;
if ($resolveType && $resolveKey) {
    foreach ($duplicateSets as $set) {
        if ($set['type'] === $resolveType && $set['key'] === $resolveKey) {
            $activeSet = $set;
            break;
        }
    }
}

?>
<?php require 'header.php'; ?>

<div class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-tools text-danger me-2"></i> Advanced Merge Tool</h2>
        <a href="bulk_update_roles.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Bulk Update</a>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['msg'])): ?>
        <div class="alert alert-success fw-bold"><i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($_SESSION['msg']); unset($_SESSION['msg']); ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Sidebar: List of Duplicates -->
        <div class="col-md-3 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white fw-bold">
                    <i class="bi bi-exclamation-circle-fill me-1"></i> Detected Duplicates (<?php echo count($duplicateSets); ?>)
                </div>
                <div class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($duplicateSets)): ?>
                        <div class="list-group-item text-muted text-center py-4">No duplicates found!</div>
                    <?php else: ?>
                        <?php foreach ($duplicateSets as $set): 
                            $isActive = ($activeSet && $activeSet['type'] === $set['type'] && $activeSet['key'] === $set['key']);
                        ?>
                            <a href="?resolve_type=<?php echo urlencode($set['type']); ?>&resolve_key=<?php echo urlencode($set['key']); ?>" 
                               class="list-group-item list-group-item-action <?php echo $isActive ? 'active bg-danger border-danger' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo htmlspecialchars($set['key']); ?></strong>
                                    <span class="badge bg-secondary rounded-pill"><?php echo count($set['records']); ?></span>
                                </div>
                                <small class="d-block <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>">Matched by <?php echo $set['type']; ?></small>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Workspace: Side-by-Side Comparison -->
        <div class="col-md-9">
            <?php if (!$activeSet): ?>
                <div class="card shadow-sm h-100 border-0 bg-light">
                    <div class="card-body d-flex flex-column justify-content-center align-items-center text-muted">
                        <i class="bi bi-intersect display-1 text-secondary mb-3"></i>
                        <h4>Select a duplicate record from the list to start merging</h4>
                        <p>You will be able to compare fields side-by-side and choose which data to keep.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php 
                    // For MVP, just compare the first two records in the set
                    $recA = $activeSet['records'][0];
                    $recB = $activeSet['records'][1];
                    
                    // List of fields to compare
                    $fieldsToCompare = [
                        'First Name' => 'first_name',
                        'Last Name' => 'last_name',
                        'Employee ID' => 'emp_id',
                        'Job Title' => 'job_title',
                        'Department' => 'dept',
                        'Date Hired' => 'hire_date',
                        'Contact Number' => 'contact_number',
                        'Email' => 'email',
                        'SSS' => 'sss_no',
                        'TIN' => 'tin_no',
                        'Present Address' => 'present_address'
                    ];
                ?>
                
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to merge these records? The secondary record will be soft-deleted.');">
                    <input type="hidden" name="merge_records" value="1">
                    
                    <div class="card shadow-sm border-danger mb-3">
                        <div class="card-header bg-danger text-white fw-bold d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-layout-split me-2"></i> Side-by-Side Merge</span>
                            <button type="submit" class="btn btn-sm btn-light text-danger fw-bold shadow-sm">
                                <i class="bi bi-check2-circle"></i> Confirm Merge
                            </button>
                        </div>
                        
                        <div class="card-body p-0">
                            <table class="table table-bordered mb-0 align-middle">
                                <thead class="table-light text-center">
                                    <tr>
                                        <th style="width: 20%;">Field</th>
                                        <th style="width: 40%;" class="border-danger border-2">
                                            <div class="form-check d-inline-block text-start mb-2">
                                                <input class="form-check-input" type="radio" name="primary_id" id="primA" value="<?php echo $recA['id']; ?>" checked onchange="document.getElementById('secB').checked = true; updateStyles();">
                                                <label class="form-check-label fw-bold text-primary" for="primA">Make Record A Primary</label>
                                            </div>
                                            <div class="small text-muted fw-normal">ID: <?php echo $recA['id']; ?></div>
                                            <!-- Hidden corresponding secondary input -->
                                            <input type="radio" name="secondary_id" id="secA" value="<?php echo $recA['id']; ?>" class="d-none">
                                        </th>
                                        <th style="width: 40%;" class="border-secondary">
                                            <div class="form-check d-inline-block text-start mb-2">
                                                <input class="form-check-input" type="radio" name="primary_id" id="primB" value="<?php echo $recB['id']; ?>" onchange="document.getElementById('secA').checked = true; updateStyles();">
                                                <label class="form-check-label fw-bold text-primary" for="primB">Make Record B Primary</label>
                                            </div>
                                            <div class="small text-muted fw-normal">ID: <?php echo $recB['id']; ?></div>
                                            <!-- Hidden corresponding secondary input -->
                                            <input type="radio" name="secondary_id" id="secB" value="<?php echo $recB['id']; ?>" class="d-none" checked>
                                        </th>
                                    </tr>
                                    <tr class="table-secondary small fw-bold">
                                        <td>Quick Select</td>
                                        <td><button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="selectAll('A')">Keep All from A</button></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="selectAll('B')">Keep All from B</button></td>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fieldsToCompare as $label => $key): 
                                        $valA = trim($recA[$key] ?? '');
                                        $valB = trim($recB[$key] ?? '');
                                        
                                        // Auto-select logic: if one is empty and the other isn't, pick the non-empty one.
                                        $checkA = '';
                                        $checkB = '';
                                        if (empty($valA) && !empty($valB)) {
                                            $checkB = 'checked';
                                        } elseif (!empty($valA) && empty($valB)) {
                                            $checkA = 'checked';
                                        } else {
                                            $checkA = 'checked'; // default to A
                                        }
                                        
                                        $isConflict = (!empty($valA) && !empty($valB) && strtolower($valA) !== strtolower($valB));
                                    ?>
                                        <tr class="<?php echo $isConflict ? 'table-warning' : ''; ?>">
                                            <td class="fw-bold bg-light"><?php echo $label; ?>
                                                <?php if ($isConflict): ?>
                                                    <span class="badge bg-danger ms-1 float-end" title="Conflict"><i class="bi bi-exclamation-triangle"></i></span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <!-- Record A Cell -->
                                            <td class="text-center col-A <?php echo $isConflict ? 'border-warning border-2' : ''; ?>">
                                                <div class="d-flex flex-column align-items-center">
                                                    <span class="mb-2 text-wrap" style="max-width: 100%; word-break: break-all;"><?php echo htmlspecialchars($valA) ?: '<i class="text-muted">(empty)</i>'; ?></span>
                                                    <input class="form-check-input field-radio-A" type="radio" name="keep_<?php echo $key; ?>" value="<?php echo $recA['id']; ?>" <?php echo $checkA; ?>>
                                                </div>
                                            </td>
                                            
                                            <!-- Record B Cell -->
                                            <td class="text-center col-B <?php echo $isConflict ? 'border-warning border-2' : ''; ?>">
                                                <div class="d-flex flex-column align-items-center">
                                                    <span class="mb-2 text-wrap" style="max-width: 100%; word-break: break-all;"><?php echo htmlspecialchars($valB) ?: '<i class="text-muted">(empty)</i>'; ?></span>
                                                    <input class="form-check-input field-radio-B" type="radio" name="keep_<?php echo $key; ?>" value="<?php echo $recB['id']; ?>" <?php echo $checkB; ?>>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-light text-muted small text-center">
                            <i class="bi bi-info-circle me-1"></i> Documents, attachments, and logs from the secondary record will automatically be merged into the primary record.
                        </div>
                    </div>
                </form>
                
                <script>
                    function selectAll(side) {
                        const radios = document.querySelectorAll('.field-radio-' + side);
                        radios.forEach(r => r.checked = true);
                    }
                    
                    function updateStyles() {
                        const isAPrimary = document.getElementById('primA').checked;
                        // Add some visual highlighting to the primary column (optional enhancement)
                    }
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
